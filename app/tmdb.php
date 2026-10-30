<?php
declare(strict_types=1);
require_once __DIR__ . "/tmdb-http.php";

function poster_normalize(string $title): string
{
    $title = strtr($title, ['İ'=>'i', 'ı'=>'i', 'Ş'=>'s', 'ş'=>'s', 'Ğ'=>'g', 'ğ'=>'g', 'Ü'=>'u', 'ü'=>'u', 'Ö'=>'o', 'ö'=>'o', 'Ç'=>'c', 'ç'=>'c', 'é'=>'e', 'É'=>'e']);
    return preg_replace('/[^\p{L}\p{N}]/u', '', function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title)) ?? '';
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
    foreach ($candidate['alternative_titles']['titles'] ?? [] as $alternative) {
        if (is_array($alternative)) $titles[] = $alternative['title'] ?? '';
    }
    $accepted = array_map('poster_normalize', $queries);
    foreach ($titles as $title) {
        if (is_string($title) && poster_normalize($title) !== '' && in_array(poster_normalize($title), $accepted, true)) return true;
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
    $truncated = false;
    foreach ($queries as $query) {
        foreach (['tr-TR', 'en-US'] as $language) {
            for ($page = 1; $page <= 3; $page++) {
                $data = $request('search/movie', ['query' => $query, 'primary_release_year' => $movie['year'], 'language' => $language, 'include_adult' => 'false', 'page' => $page]);
                if (!isset($data['results']) || !is_array($data['results'])) {
                    throw new TmdbFailure(kind: 'invalid_response', httpStatus: 200, message: 'Search response has no results array.', endpoint: 'search/movie');
                }
                foreach ($data['results'] as $candidate) {
                    if (!is_array($candidate) || !isset($candidate['id'])) continue;
                    if ((int) substr($candidate['release_date'] ?? '', 0, 4) !== (int) $movie['year'] || !empty($candidate['adult'])) continue;
                    // A translated/alternative search hit may not expose the queried title here.
                    // Keep it until its details/alternative_titles have been verified.
                    if (filter_var($candidate['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) $candidates[(int) $candidate['id']] = $candidate;
                }
                $total = (int) ($data['total_pages'] ?? 1);
                if ($total <= $page) break;
                if ($page === 3) $truncated = true;
            }
        }
    }
    if ($truncated || count($candidates) > 8) return ['status' => 'ambiguous', 'reason' => 'candidate_limit'];
    if ($candidates === []) return ['status' => 'not_found', 'reason' => 'no_same_year_candidates'];
    $verified = [];
    foreach ($candidates as $id => $candidate) {
        try {
            $details = $request('movie/' . $id, ['language' => 'tr-TR', 'append_to_response' => 'alternative_titles,images', 'include_image_language' => implode(',', array_unique([$candidate['original_language'] ?? 'en', 'tr', 'en', 'null']))]);
        } catch (TmdbFailure $failure) {
            // A removed movie is not a connection/API outage. Other HTTP failures remain errors.
            if ($failure->httpStatus === 404) continue;
            throw $failure;
        }
        if (!isset($details['id'], $details['release_date']) || (!isset($details['title']) && !isset($details['original_title']))) {
            throw new TmdbFailure(kind: 'invalid_response', httpStatus: 200, message: 'Movie details are missing required identity fields.', endpoint: 'movie/' . $id);
        }
        if ((isset($details['alternative_titles']) && !is_array($details['alternative_titles'])) || !is_array($details['alternative_titles']['titles'] ?? []) || (isset($details['images']) && !is_array($details['images'])) || !is_array($details['images']['posters'] ?? [])) {
            throw new TmdbFailure(kind: 'invalid_response', httpStatus: 200, message: 'Movie details have malformed alternative titles or images.', endpoint: 'movie/' . $id);
        }
        if ((int) $details['id'] !== $id || (int) substr($details['release_date'], 0, 4) !== (int) $movie['year'] || !empty($details['adult'])) continue;
        if (!poster_title_matches($details, $queries)) continue;
        $verified[$id] = $details;
    }
    if ($verified === []) return ['status' => 'not_found', 'reason' => 'title_or_year_not_verified'];
    if (count($verified) > 1) return ['status' => 'ambiguous', 'reason' => 'multiple_verified_movies'];
    $id = (int) array_key_first($verified);
    $path = choose_original_poster($verified[$id]);
    return ['status' => $path === null ? 'no_poster' : 'matched', 'reason' => $path === null ? 'no_usable_poster' : 'title_and_year_verified', 'tmdb_id' => $id, 'poster_path' => $path];
}
