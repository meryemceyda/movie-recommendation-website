<?php
declare(strict_types=1);
require_once __DIR__ . '/tmdb.php';

function poster_source_key(array $movie): string
{
    return hash('sha256', json_encode([poster_queries($movie), (int) $movie['year'], 1], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function poster_cached(PDO $db, int $movieId): ?array
{
    $statement = $db->prepare('SELECT * FROM movie_posters WHERE movie_id = ?');
    $statement->execute([$movieId]);
    return $statement->fetch() ?: null;
}

function poster_service_get(PDO $db, string $name, int $now): ?string
{
    $statement = $db->prepare('SELECT value FROM poster_service WHERE name = ? AND expires_at > ?');
    $statement->execute([$name, $now]);
    $value = $statement->fetchColumn();
    return $value === false ? null : $value;
}

function poster_service_set(PDO $db, string $name, string $value, int $expires): void
{
    $db->prepare('INSERT INTO poster_service (name, value, expires_at) VALUES (?, ?, ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value, expires_at = excluded.expires_at')->execute([$name, $value, $expires]);
}

function poster_image_config(PDO $db, callable $request, int $now): array
{
    $cached = poster_service_get($db, 'configuration', $now);
    if ($cached !== null) return json_decode($cached, true, 512, JSON_THROW_ON_ERROR);
    $images = $request('configuration', [])['images'] ?? [];
    // Only TMDB's documented HTTPS CDN is trusted, even if upstream data changes.
    if (($images['secure_base_url'] ?? '') !== 'https://image.tmdb.org/t/p/') throw new TmdbFailure();
    $sizes = $images['poster_sizes'] ?? [];
    if (!is_array($sizes)) throw new TmdbFailure();
    $card = in_array('w500', $sizes, true) ? 'w500' : (in_array('original', $sizes, true) ? 'original' : null);
    $detail = in_array('w780', $sizes, true) ? 'w780' : $card;
    if ($card === null) throw new TmdbFailure();
    $config = ['image_base' => $images['secure_base_url'], 'card_size' => $card, 'detail_size' => $detail];
    poster_service_set($db, 'configuration', json_encode($config, JSON_THROW_ON_ERROR), $now + 86400);
    return $config;
}

/** Lazy, per-movie lookup. Injectable transport/clock keep tests offline. */
function movie_poster(PDO $db, array $movie, ?callable $request = null, ?int $clock = null): array
{
    $now = $clock ?? time();
    $id = (int) $movie['id'];
    $key = poster_source_key($movie);
    $cached = poster_cached($db, $id);
    $sameSource = $cached && hash_equals($cached['source_key'], $key);
    if ($sameSource && (int) $cached['next_check'] > $now) return $cached;
    $fallback = $sameSource ? $cached : ['status' => 'pending'];
    if ($request === null && !tmdb_configured()) return $sameSource ? $cached : ['status' => 'unconfigured'];
    if (poster_service_get($db, 'cooldown', $now) !== null) return $fallback;
    $request ??= 'tmdb_request';
    $db->prepare('INSERT OR IGNORE INTO movie_posters (movie_id) VALUES (?)')->execute([$id]);
    // Never keep a SQLite transaction open while waiting for the network.
    $lease = $db->prepare('UPDATE movie_posters SET lease_until = ? WHERE movie_id = ? AND lease_until <= ? AND (source_key <> ? OR next_check <= ?)');
    $lease->execute([$now + 120, $id, $now, $key, $now]);
    if ($lease->rowCount() !== 1) return $fallback;
    try {
        $resolved = resolve_tmdb_poster($movie, $request);
        $config = $resolved['status'] === 'matched' ? poster_image_config($db, $request, $now) : [];
        $next = $now + ($resolved['status'] === 'matched' ? 7 * 86400 : 86400);
        $db->prepare('UPDATE movie_posters SET source_key = ?, tmdb_id = ?, poster_path = ?, status = ?, next_check = ?, checked_at = ?, lease_until = 0, image_base = ?, card_size = ?, detail_size = ? WHERE movie_id = ? AND lease_until = ?')
            ->execute([$key, $resolved['tmdb_id'] ?? null, $resolved['poster_path'] ?? null, $resolved['status'], $next, $now, $config['image_base'] ?? null, $config['card_size'] ?? null, $config['detail_size'] ?? null, $id, $now + 120]);
    } catch (Throwable $error) {
        $retry = $error instanceof TmdbFailure ? $error->retryAfter : 3600;
        if ($error instanceof TmdbFailure && $error->global) poster_service_set($db, 'cooldown', '1', $now + $retry);
        // Serve last known good posters during temporary outages, but never after a title/year change.
        if ($sameSource && $cached['status'] === 'matched') {
            $db->prepare('UPDATE movie_posters SET next_check = ?, lease_until = 0 WHERE movie_id = ? AND lease_until = ?')->execute([$now + $retry, $id, $now + 120]);
        } else {
            $db->prepare("UPDATE movie_posters SET source_key = ?, status = 'error', tmdb_id = NULL, poster_path = NULL, next_check = ?, checked_at = ?, lease_until = 0 WHERE movie_id = ? AND lease_until = ?")->execute([$key, $now + $retry, $now, $id, $now + 120]);
        }
    }
    return poster_cached($db, $id) ?? $fallback;
}

function poster_redirect_url(array $poster, string $size): ?string
{
    if (($poster['status'] ?? '') !== 'matched' || !valid_poster_path($poster['poster_path'] ?? null) || ($poster['image_base'] ?? '') !== 'https://image.tmdb.org/t/p/') return null;
    $variant = $poster[$size === 'detail' ? 'detail_size' : 'card_size'] ?? '';
    if (!in_array($variant, ['w500', 'w780', 'original'], true)) return null;
    return $poster['image_base'] . $variant . $poster['poster_path'];
}

function render_poster(array $movie, bool $detail = false): void
{
    $id = (int) $movie['id'];
    $source = 'poster.php?id=' . $id . '&size=' . ($detail ? 'detail' : 'card');
    $srcset = 'poster.php?id=' . $id . '&size=card 500w, poster.php?id=' . $id . '&size=detail 780w';
    $sizes = $detail ? '(max-width: 520px) 90vw, 420px' : '(max-width: 600px) 90vw, (max-width: 900px) 45vw, 340px';
    echo '<img class="movie-poster ' . ($detail ? 'movie-poster-detail' : 'movie-poster-card') . '" src="' . e($source) . '" srcset="' . e($srcset) . '" sizes="' . e($sizes) . '" width="500" height="750" alt="' . e($movie['title']) . ' afişi" decoding="async" ' . ($detail ? 'fetchpriority="high"' : 'loading="lazy"') . ' data-poster-fallback="assets/poster-placeholder.svg">';
}
