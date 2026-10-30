<?php
declare(strict_types=1);

function reviewed_poster_identity(array $movie): ?array
{
    foreach (require __DIR__ . '/poster-identities.php' as $identity) {
        $titles = array_merge([$identity['title']], $identity['source_titles'] ?? []);
        if ((int) $movie['year'] === $identity['year'] && in_array(poster_normalize($movie['title']), array_map('poster_normalize', $titles), true)) return $identity;
    }
    return null;
}

function resolve_reviewed_poster(array $movie, array $identity, callable $request): array
{
    $id = $identity['tmdb_id'];
    try {
        $details = $request('movie/' . $id, ['language' => 'en-US', 'append_to_response' => 'alternative_titles,images,credits,release_dates', 'include_image_language' => 'en,tr,null']);
    } catch (TmdbFailure $error) {
        if ($error->httpStatus === 404) return ['status' => 'not_found', 'reason' => 'reviewed_id_removed'];
        throw $error;
    }
    if (!isset($details['id'], $details['release_date'], $details['credits']['cast'], $details['credits']['crew']) || !is_array($details['credits']['cast']) || !is_array($details['credits']['crew'])) {
        throw new TmdbFailure(kind: 'invalid_response', httpStatus: 200, message: 'Reviewed movie is missing identity/credits fields.', endpoint: 'movie/' . $id);
    }
    $valid = (int) $details['id'] === $id && empty($details['adult']) && poster_title_matches($details, $identity['titles']);
    $cast = array_map(static fn($person) => poster_normalize((string) ($person['name'] ?? '')), $details['credits']['cast']);
    foreach ($identity['cast'] as $name) $valid = $valid && in_array(poster_normalize($name), $cast, true);
    $directors = array_filter($details['credits']['crew'], static fn($person) => ($person['job'] ?? '') === 'Director');
    $valid = $valid && in_array(poster_normalize($identity['director']), array_map(static fn($person) => poster_normalize((string) ($person['name'] ?? '')), $directors), true);
    $yearVerified = (int) substr($details['release_date'], 0, 4) === (int) $movie['year'];
    if (!$yearVerified && isset($identity['premiere_country'])) {
        // Only this reviewed film may use its documented festival/premiere year.
        foreach ($details['release_dates']['results'] ?? [] as $country) {
            if (($country['iso_3166_1'] ?? '') !== $identity['premiere_country']) continue;
            foreach ($country['release_dates'] ?? [] as $release) {
                if ((int) ($release['type'] ?? 0) === 1 && (int) substr($release['release_date'] ?? '', 0, 4) === (int) $movie['year']) $yearVerified = true;
            }
        }
    }
    if (!$valid || !$yearVerified) return ['status' => 'not_found', 'reason' => 'reviewed_identity_not_verified'];
    $path = choose_original_poster($details);
    return ['status' => $path ? 'matched' : 'no_poster', 'tmdb_id' => $id, 'poster_path' => $path,
        'reason' => $path ? 'reviewed_id_title_year_credits_verified' : 'no_usable_poster'];
}
