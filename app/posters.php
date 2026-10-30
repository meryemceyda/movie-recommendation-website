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
    if (($images['secure_base_url'] ?? '') !== 'https://image.tmdb.org/t/p/') throw new TmdbFailure(kind: 'invalid_configuration', httpStatus: 200, message: 'TMDB configuration has an unsupported secure image base.', endpoint: 'configuration');
    $sizes = $images['poster_sizes'] ?? [];
    if (!is_array($sizes)) throw new TmdbFailure(kind: 'invalid_configuration', httpStatus: 200, message: 'TMDB configuration has no poster sizes array.', endpoint: 'configuration');
    $card = in_array('w500', $sizes, true) ? 'w500' : (in_array('original', $sizes, true) ? 'original' : null);
    $detail = in_array('w780', $sizes, true) ? 'w780' : $card;
    if ($card === null) throw new TmdbFailure(kind: 'invalid_configuration', httpStatus: 200, message: 'TMDB configuration has no supported poster size.', endpoint: 'configuration');
    $config = ['image_base' => $images['secure_base_url'], 'card_size' => $card, 'detail_size' => $detail];
    poster_service_set($db, 'configuration', json_encode($config, JSON_THROW_ON_ERROR), $now + 86400);
    return $config;
}

/** Lazy lookup; --retry-errors only bypasses failure-cache TTL, never a rate limit. */
function movie_poster(PDO $db, array $movie, ?callable $request = null, ?int $clock = null, bool $retryErrors = false): array
{
    $now = $clock ?? time();
    $id = (int) $movie['id'];
    $key = poster_source_key($movie);
    $cached = poster_cached($db, $id);
    $sameSource = $cached && hash_equals($cached['source_key'], $key);
    $retryFailure = $retryErrors && $cached && ($cached['status'] === 'error' || !empty($cached['last_error']));
    if ($sameSource && (int) $cached['next_check'] > $now && !$retryFailure) return $cached;
    $fallback = $sameSource ? $cached : ['status' => 'pending'];
    if ($request === null && !tmdb_configured()) return $sameSource ? $cached : ['status' => 'unconfigured'];
    $cooldown = poster_service_get($db, 'cooldown', $now);
    if ($cooldown === '1' && $retryErrors) {
        // Legacy sentinel contains no cause/Retry-After; explicit recovery probes once.
        $db->exec("DELETE FROM poster_service WHERE name = 'cooldown'");
        $cooldown = null;
    }
    if ($cooldown !== null) {
        $state = json_decode($cooldown, true);
        if (is_array($state) && ($state['diagnostic']['kind'] ?? '') === 'authentication_error' && ($state['credential_hash'] ?? '') !== tmdb_credential_hash()) {
            $db->exec("DELETE FROM poster_service WHERE name = 'cooldown'");
        } else {
            $diagnostic = is_array($state) ? ($state['diagnostic'] ?? []) : ['kind' => 'legacy_cooldown', 'message' => 'Previous version did not retain the cooldown cause; use --retry-errors to probe again.'];
            $fallback['last_error'] = json_encode($diagnostic, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            $fallback['deferred'] = true;
            if (($fallback['status'] ?? '') !== 'matched') $fallback['status'] = 'error';
            $fallback['next_check'] = is_array($state) ? ($state['retry_at'] ?? $now + 60) : $now + 60;
            return $fallback;
        }
    }
    $request ??= 'tmdb_request';
    $db->prepare('INSERT OR IGNORE INTO movie_posters (movie_id) VALUES (?)')->execute([$id]);
    $leaseUntil = $now + 900;
    // No database transaction is held during API requests/backoff.
    $lease = $db->prepare('UPDATE movie_posters SET lease_until = ? WHERE movie_id = ? AND lease_until <= ? AND (source_key <> ? OR next_check <= ? OR CAST(? AS INTEGER) = 1)');
    $lease->execute([$leaseUntil, $id, $now, $key, $now, (int) $retryFailure]);
    if ($lease->rowCount() !== 1) return $fallback;
    try {
        $resolved = resolve_tmdb_poster($movie, $request);
        $config = $resolved['status'] === 'matched' ? poster_image_config($db, $request, $now) : [];
        $finishedAt = $clock ?? time();
        $next = $finishedAt + ($resolved['status'] === 'matched' ? 7 * 86400 : 86400);
        $db->prepare('UPDATE movie_posters SET source_key = ?, tmdb_id = ?, poster_path = ?, status = ?, next_check = ?, checked_at = ?, lease_until = 0, image_base = ?, card_size = ?, detail_size = ?, last_error = NULL, match_reason = ? WHERE movie_id = ? AND lease_until = ?')
            ->execute([$key, $resolved['tmdb_id'] ?? null, $resolved['poster_path'] ?? null, $resolved['status'], $next, $finishedAt, $config['image_base'] ?? null, $config['card_size'] ?? null, $config['detail_size'] ?? null, $resolved['reason'] ?? null, $id, $leaseUntil]);
    } catch (Throwable $error) {
        $failure = $error instanceof TmdbFailure ? $error : new TmdbFailure(kind: 'internal_error', message: get_class($error) . ': ' . $error->getMessage());
        $retry = $failure->retryAfter;
        $failedAt = $clock ?? time();
        $diagnostic = json_encode($failure->diagnostic(), JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        if ($failure->global) {
            poster_service_set($db, 'cooldown', json_encode(['diagnostic' => $failure->diagnostic(), 'retry_at' => $failedAt + $retry, 'credential_hash' => tmdb_credential_hash()], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), $failedAt + $retry);
        }
        if ($sameSource && $cached['status'] === 'matched') {
            $db->prepare('UPDATE movie_posters SET next_check = ?, lease_until = 0, last_error = ? WHERE movie_id = ? AND lease_until = ?')->execute([$failedAt + $retry, $diagnostic, $id, $leaseUntil]);
        } else {
            $db->prepare("UPDATE movie_posters SET source_key = ?, status = 'error', tmdb_id = NULL, poster_path = NULL, next_check = ?, checked_at = ?, lease_until = 0, last_error = ?, match_reason = NULL WHERE movie_id = ? AND lease_until = ?")->execute([$key, $failedAt + $retry, $failedAt, $diagnostic, $id, $leaseUntil]);
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
