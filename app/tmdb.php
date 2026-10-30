<?php
declare(strict_types=1);

final class TmdbFailure extends RuntimeException
{
    public function __construct(public readonly int $retryAfter = 3600, public readonly bool $global = false)
    {
        // Never include a URL, response body, or credential in logs/exceptions.
        parent::__construct('TMDB request unavailable.');
    }
}

function tmdb_configured(): bool
{
    return trim((string) getenv('TMDB_READ_ACCESS_TOKEN')) !== '' || trim((string) getenv('TMDB_API_KEY')) !== '';
}

function tmdb_request(string $path, array $query = []): array
{
    if (!tmdb_configured() || !function_exists('curl_init')) throw new TmdbFailure();
    $token = trim((string) getenv('TMDB_READ_ACCESS_TOKEN'));
    if (preg_match('/[\r\n]/', $token)) throw new TmdbFailure(3600, true);
    $headers = ['Accept: application/json'];
    if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
    else $query['api_key'] = trim((string) getenv('TMDB_API_KEY'));
    $handle = curl_init('https://api.themoviedb.org/3/' . ltrim($path, '/') . '?' . http_build_query($query));
    $body = '';
    $retryAfter = 60;
    curl_setopt_array($handle, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'NCFilm/1.0 (movie poster lookup)',
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$retryAfter): int {
            if (preg_match('/^Retry-After:\s*(\d+)/i', $line, $match)) $retryAfter = max(60, min(86400, (int) $match[1]));
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 2_000_000) return 0;
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if ($status === 429) throw new TmdbFailure($retryAfter, true);
    if ($status === 401 || $status === 403) throw new TmdbFailure(3600, true);
    if ($ok === false || $status !== 200) throw new TmdbFailure();
    try {
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new TmdbFailure();
    }
    if (!is_array($data)) throw new TmdbFailure();
    return $data;
}

function poster_normalize(string $title): string
{
    $title = strtr($title, ['İ'=>'i', 'ı'=>'i', 'Ş'=>'s', 'ş'=>'s', 'Ğ'=>'g', 'ğ'=>'g', 'Ü'=>'u', 'ü'=>'u', 'Ö'=>'o', 'ö'=>'o', 'Ç'=>'c', 'ç'=>'c', 'é'=>'e', 'É'=>'e']);
    return preg_replace('/[^a-z0-9]/', '', strtolower($title));
}

function poster_queries(array $movie): array
{
    static $aliases;
    $aliases ??= require __DIR__ . '/poster-aliases.php';
    return array_values(array_unique(array_merge([$movie['title']], $aliases[$movie['title']] ?? [])));
}

function poster_title_matches(array $candidate, array $queries): bool
{
    $titles = [$candidate['title'] ?? '', $candidate['original_title'] ?? ''];
    foreach ($candidate['alternative_titles']['titles'] ?? [] as $alternative) $titles[] = $alternative['title'] ?? '';
    $accepted = array_map('poster_normalize', $queries);
    foreach ($titles as $title) {
        if (is_string($title) && $title !== '' && in_array(poster_normalize($title), $accepted, true)) return true;
    }
    return false;
}

function valid_poster_path(mixed $path): bool
{
    return is_string($path) && strlen($path) <= 200 && preg_match('~^/[A-Za-z0-9]+\.(jpg|png|webp)$~D', $path) === 1;
}

function choose_original_poster(array $details): ?string
{
    $posters = array_filter($details['images']['posters'] ?? [], static fn($p) =>
        is_array($p) && valid_poster_path($p['file_path'] ?? null) && (int) ($p['width'] ?? 0) >= 300);
    $languages = array_values(array_unique([$details['original_language'] ?? '', 'tr', 'en', null]));
    usort($posters, static function ($a, $b) use ($languages): int {
        $rank = static function ($p) use ($languages): int {
            $found = array_search($p['iso_639_1'] ?? null, $languages, true);
            return $found === false ? 99 : $found;
        };
        return ($rank($a) <=> $rank($b)) ?: (($b['vote_average'] ?? 0) <=> ($a['vote_average'] ?? 0)) ?: (($b['width'] ?? 0) <=> ($a['width'] ?? 0));
    });
    if ($posters !== []) return array_values($posters)[0]['file_path'];
    return valid_poster_path($details['poster_path'] ?? null) ? $details['poster_path'] : null;
}

function resolve_tmdb_poster(array $movie, callable $request): array
{
    $queries = poster_queries($movie);
    $candidates = [];
    // Query both translated and original aliases; never pick the most popular hit.
    foreach ($queries as $query) {
        $data = $request('search/movie', ['query' => $query, 'primary_release_year' => $movie['year'], 'language' => 'tr-TR', 'include_adult' => 'false']);
        if (!isset($data['results']) || !is_array($data['results'])) throw new TmdbFailure();
        foreach ($data['results'] as $candidate) {
            if (!is_array($candidate) || !isset($candidate['id'])) continue;
            if ((int) substr($candidate['release_date'] ?? '', 0, 4) !== (int) $movie['year']) continue;
            if (!empty($candidate['adult']) || !poster_title_matches($candidate, $queries)) continue;
            if (filter_var($candidate['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) $candidates[(int) $candidate['id']] = $candidate;
        }
        // A single page cannot establish uniqueness if results are truncated.
        if (($data['total_pages'] ?? 1) > 1) return ['status' => 'ambiguous'];
    }
    if (count($candidates) !== 1) return ['status' => $candidates === [] ? 'not_found' : 'ambiguous'];
    $id = (int) array_key_first($candidates);
    $details = $request('movie/' . $id, ['language' => 'tr-TR', 'append_to_response' => 'alternative_titles,images', 'include_image_language' => implode(',', array_unique([$candidates[$id]['original_language'] ?? 'en', 'tr', 'en', 'null']))]);
    if ((int) ($details['id'] ?? 0) !== $id || (int) substr($details['release_date'] ?? '', 0, 4) !== (int) $movie['year'] || !empty($details['adult']) || !poster_title_matches($details, $queries)) return ['status' => 'mismatch'];
    $path = choose_original_poster($details);
    return ['status' => $path === null ? 'no_poster' : 'matched', 'tmdb_id' => $id, 'poster_path' => $path];
}
