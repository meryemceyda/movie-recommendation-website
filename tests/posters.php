<?php
declare(strict_types=1);
putenv('APP_DB_PATH=:memory:');
putenv('TMDB_READ_ACCESS_TOKEN');
putenv('TMDB_API_KEY');
require dirname(__DIR__) . '/app/bootstrap.php';
function verify(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
$db = database();
initialize_database($db);
$db->exec("INSERT INTO users (name, email, password_hash) VALUES ('Existing user', 'existing@example.com', 'existing-hash')");
$db->exec("INSERT INTO user_movies (user_id, movie_id, list) VALUES (1, 1, 'watchlist'), (1, 2, 'watched'), (1, 3, 'favorites')");
$snapshot = static function () use ($db): array {
    $rows = [];
    foreach (['movies', 'users', 'user_movies', 'genres', 'movie_genres'] as $table) $rows[$table] = $db->query('SELECT * FROM ' . $table)->fetchAll();
    return $rows;
};
$before = $snapshot();
// Simulate upgrading a populated pre-poster installation, then upgrading again.
$db->exec('DROP TABLE movie_posters; DROP TABLE poster_service');
$migration = file_get_contents(dirname(__DIR__) . '/app/poster-schema.sql');
$db->exec($migration);
$db->exec($migration);
verify($snapshot() === $before, 'Migration changed existing accounts/movies/lists.');

$movie = $db->query('SELECT * FROM movies WHERE id = 1')->fetch();
$candidate = ['id' => 603, 'title' => 'Matrix', 'original_title' => 'The Matrix', 'release_date' => '1999-03-31', 'original_language' => 'en', 'adult' => false];
$detail = $candidate + ['poster_path' => '/defaultPoster.jpg', 'images' => ['posters' => [
    ['file_path' => '/turkishPoster.jpg', 'iso_639_1' => 'tr', 'width' => 2000, 'vote_average' => 10],
    ['file_path' => '/originalPoster.jpg', 'iso_639_1' => 'en', 'width' => 1000, 'vote_average' => 5],
]]];
$configuration = ['images' => ['secure_base_url' => 'https://image.tmdb.org/t/p/', 'poster_sizes' => ['w500', 'w780', 'original']]];
$calls = 0;
$transport = static function (string $path, array $query) use (&$calls, $candidate, $detail, $configuration): array {
    $calls++;
    if ($path === 'configuration') return $configuration;
    if ($path === 'search/movie') {
        verify((int) $query['primary_release_year'] === 1999, 'Release year was not sent.');
        return ['results' => [$candidate], 'total_pages' => 1];
    }
    return $detail;
};
$poster = movie_poster($db, $movie, $transport, 1000);
verify($poster['status'] === 'matched' && (int) $poster['tmdb_id'] === 603, 'Correct title/year did not resolve.');
verify($poster['poster_path'] === '/originalPoster.jpg', 'Original-language poster should be preferred.');
verify(poster_redirect_url($poster, 'card') === 'https://image.tmdb.org/t/p/w500/originalPoster.jpg', 'Card URL incorrect.');
verify(poster_redirect_url($poster, 'detail') === 'https://image.tmdb.org/t/p/w780/originalPoster.jpg', 'Detail URL incorrect.');
$previousCalls = $calls;
movie_poster($db, $movie, $transport, 1001);
verify($calls === $previousCalls, 'Fresh cache must not call the API again.');
verify(movie_poster($db, $movie, null, 1002)['status'] === 'matched', 'Cached poster should work without credentials.');

$wrongYear = $candidate;
$wrongYear['release_date'] = '2021-01-01';
$wrongName = $candidate;
$wrongName['title'] = 'Unrelated movie';
$wrongName['original_title'] = 'Unrelated movie';
foreach ([$wrongYear, $wrongName] as $bad) {
    verify(resolve_tmdb_poster($movie, fn() => ['results' => [$bad]])['status'] === 'not_found', 'Wrong title/year matched.');
}
$duplicate = $candidate;
$duplicate['id'] = 604;
verify(resolve_tmdb_poster($movie, fn() => ['results' => [$candidate, $duplicate]])['status'] === 'ambiguous', 'Ambiguous movies must not match.');
verify(resolve_tmdb_poster($movie, fn() => ['results' => [$candidate], 'total_pages' => 2])['status'] === 'ambiguous', 'Truncated results must not be accepted.');
verify(resolve_tmdb_poster($movie, fn($path) => $path === 'search/movie' ? ['results' => [$candidate]] : $wrongYear)['status'] === 'mismatch', 'Details must recheck year.');
$noPoster = $candidate + ['poster_path' => null, 'images' => ['posters' => []]];
verify(resolve_tmdb_poster($movie, fn($path) => $path === 'search/movie' ? ['results' => [$candidate]] : $noPoster)['status'] === 'no_poster', 'Missing poster must not generate a CDN URL.');
verify(!valid_poster_path('//evil.example/x.jpg') && !valid_poster_path('/../../secret.jpg'), 'Unsafe path accepted.');
verify(poster_redirect_url(array_merge($poster, ['image_base' => 'https://evil.example/']), 'card') === null, 'Untrusted host accepted.');
verify(poster_redirect_url(['status' => 'error'], 'card') === null, 'Failure needs fallback.');

// A temporary upstream failure preserves a previously verified poster.
$errorCalls = 0;
$failure = static function () use (&$errorCalls): array { $errorCalls++; throw new TmdbFailure(); };
$staleTime = 1000 + 7 * 86400 + 1;
verify(movie_poster($db, $movie, $failure, $staleTime)['status'] === 'matched', 'Outage discarded last known good poster.');
movie_poster($db, $movie, $failure, $staleTime + 1);
verify($errorCalls === 1, 'Outage retry cooldown failed.');
$changed = array_merge($movie, ['year' => 2021]);
verify(poster_redirect_url(movie_poster($db, $changed, null, 2000), 'card') === null, 'Metadata change reused the old poster.');
verify(movie_poster($db, $changed, fn() => ['results' => []], 2001)['status'] === 'not_found', 'Changed metadata must invalidate cached match.');

// Cold failures, 429/invalid credential circuit breaker, and an in-flight lease.
$db->exec('DELETE FROM movie_posters; DELETE FROM poster_service');
$limitedCalls = 0;
$limited = static function () use (&$limitedCalls): array { $limitedCalls++; throw new TmdbFailure(120, true); };
verify(movie_poster($db, $movie, $limited, 3000)['status'] === 'error', 'Failure must be persisted.');
$second = $db->query('SELECT * FROM movies WHERE id = 2')->fetch();
movie_poster($db, $second, $limited, 3001);
verify($limitedCalls === 1, 'Global cooldown must stop other movie lookups.');
movie_poster($db, $second, $limited, 3121);
verify($limitedCalls === 2, 'Rate-limit cooldown must expire.');
$db->exec('DELETE FROM movie_posters; DELETE FROM poster_service');
$db->exec('INSERT INTO movie_posters (movie_id, lease_until) VALUES (1, 99999)');
movie_poster($db, $movie, $failure, 4000);
verify($errorCalls === 1, 'Concurrent request bypassed lease.');
$db->exec('DELETE FROM movie_posters; DELETE FROM poster_service');
verify(movie_poster($db, $movie, null, 4001)['status'] === 'unconfigured', 'Missing credentials should be graceful.');

// Exercise every catalog row with offline fixtures; not a claim of live TMDB coverage.
foreach ($db->query('SELECT * FROM movies ORDER BY id')->fetchAll() as $catalogMovie) {
    $fake = ['id' => 10000 + $catalogMovie['id'], 'title' => $catalogMovie['title'], 'original_title' => $catalogMovie['title'], 'release_date' => $catalogMovie['year'] . '-01-01', 'poster_path' => '/fixturePoster.jpg', 'original_language' => 'tr'];
    $fixture = static fn($path) => $path === 'search/movie' ? ['results' => [$fake]] : ($path === 'configuration' ? $configuration : $fake);
    verify(movie_poster($db, $catalogMovie, $fixture, 5000)['status'] === 'matched', 'Catalog row failed poster pipeline.');
}
verify((int) $db->query('SELECT COUNT(*) FROM movie_posters')->fetchColumn() === 63, '63 movies should be supported.');
verify($snapshot() === $before, 'Poster resolution changed account/list/catalog data.');
echo "Poster tests passed (offline fixtures; 63 catalog rows, additive migration, matching, cache, failures and isolation).\n";
