<?php
declare(strict_types=1);
putenv('APP_DB_PATH=:memory:');
putenv('TMDB_READ_ACCESS_TOKEN');
putenv('TMDB_API_KEY');
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/poster-report.php';
require dirname(__DIR__) . '/app/poster-sync.php';
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
    verify(resolve_tmdb_poster($movie, fn($path) => $path === 'search/movie' ? ['results' => [$bad]] : $bad)['status'] === 'not_found', 'Wrong title/year matched.');
}
$duplicate = $candidate;
$duplicate['id'] = 604;
verify(resolve_tmdb_poster($movie, fn($path) => $path === 'search/movie' ? ['results' => [$candidate, $duplicate]] : ($path === 'movie/603' ? $detail : array_merge($detail, ['id' => 604])))['status'] === 'ambiguous', 'Ambiguous movies must not match.');
verify(resolve_tmdb_poster($movie, fn() => ['results' => [$candidate], 'total_pages' => 4])['status'] === 'ambiguous', 'Truncated results must not be accepted.');
verify(resolve_tmdb_poster($movie, fn($path) => $path === 'search/movie' ? ['results' => [$candidate]] : $wrongYear)['status'] === 'not_found', 'Details must recheck year.');
$noPoster = $candidate + ['poster_path' => null, 'images' => ['posters' => []]];
verify(resolve_tmdb_poster($movie, fn($path) => $path === 'search/movie' ? ['results' => [$candidate]] : $noPoster)['status'] === 'no_poster', 'Missing poster must not generate a CDN URL.');
// Search may return a different visible/original title for an exact alternative-title hit.
$alternative = array_merge($detail, ['title' => 'Different localized name', 'original_title' => 'Different original name', 'alternative_titles' => ['titles' => [['title' => 'Matrix']]]]);
$alternativeHit = array_intersect_key($alternative, array_flip(['id', 'title', 'original_title', 'release_date']));
verify(resolve_tmdb_poster($movie, fn($path) => $path === 'search/movie' ? ['results' => [$alternativeHit]] : $alternative)['status'] === 'matched', 'Alternative-title candidate was discarded before verification.');
$languages = [];
$paged = static function ($path, $query) use (&$languages, $detail, $candidate): array {
    if ($path !== 'search/movie') return $detail;
    $languages[$query['language']] = true;
    return ['results' => $query['page'] === 1 ? [] : [$candidate], 'total_pages' => 2];
};
verify(resolve_tmdb_poster($movie, $paged)['status'] === 'matched' && count($languages) === 2, 'Pagination and bilingual search failed.');
try {
    resolve_tmdb_poster($movie, fn() => ['malformed' => true]);
    verify(false, 'Malformed search must not become not_found.');
} catch (TmdbFailure $error) { verify($error->kind === 'invalid_response', 'Malformed response diagnostic lost.'); }
$removed = static function ($path) use ($candidate): array {
    if ($path === 'search/movie') return ['results' => [$candidate]];
    throw new TmdbFailure(kind: 'http_error', httpStatus: 404, endpoint: $path);
};
verify(resolve_tmdb_poster($movie, $removed)['status'] === 'not_found', 'Removed movie should not be a connection error.');
try {
    resolve_tmdb_poster($movie, fn($path) => $path === 'search/movie' ? ['results' => [$candidate]] : array_merge($detail, ['alternative_titles' => 'bad-shape']));
    verify(false, 'Malformed details must remain an API error.');
} catch (TmdbFailure $error) { verify($error->kind === 'invalid_response' && $error->httpStatus === 200, 'Malformed details diagnostic lost.'); }
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
// Reproduce a previously cached 16 matched / 47 error batch in an isolated DB.
$db->exec("UPDATE movie_posters SET status = 'error', poster_path = NULL, next_check = 99999, last_error = NULL WHERE movie_id > 16");
$legacyReport = poster_report(['id' => 17, 'title' => 'Test', 'year' => 1999], poster_cached($db, 17), true);
verify($legacyReport['error']['kind'] === 'legacy_error', 'Unrecorded historical causes must not be invented.');
// Simulate an actual old cache schema; migration must retain every original column/value.
$db->exec('ALTER TABLE movie_posters DROP COLUMN last_error');
$db->exec('ALTER TABLE movie_posters DROP COLUMN match_reason');
$oldColumns = array_column($db->query('PRAGMA table_info(movie_posters)')->fetchAll(), 'name');
$cacheBefore = $db->query('SELECT ' . implode(',', $oldColumns) . ' FROM movie_posters ORDER BY movie_id')->fetchAll();
migrate_poster_diagnostics($db);
migrate_poster_diagnostics($db);
verify($db->query('SELECT ' . implode(',', $oldColumns) . ' FROM movie_posters ORDER BY movie_id')->fetchAll() === $cacheBefore, 'Diagnostic migration modified cached posters.');
$recovered = 0;
$apiMovies = [];
foreach ($db->query('SELECT * FROM movies ORDER BY id')->fetchAll() as $catalogMovie) {
    $fake = ['id' => 10000 + $catalogMovie['id'], 'title' => $catalogMovie['title'], 'original_title' => $catalogMovie['title'], 'release_date' => $catalogMovie['year'] . '-01-01', 'poster_path' => '/fixturePoster.jpg'];
    $fixture = static function ($path) use ($fake, $configuration, &$apiMovies, $catalogMovie): array {
        $apiMovies[$catalogMovie['id']] = true;
        return $path === 'search/movie' ? ['results' => [$fake]] : ($path === 'configuration' ? $configuration : $fake);
    };
    $result = movie_poster($db, $catalogMovie, $fixture, 5001, true);
    if ($result['status'] === 'matched') $recovered++;
}
verify($recovered === 63 && count($apiMovies) === 47, 'Retry-errors must recover errors without re-requesting 16 cached matches.');
// Persist diagnostics and honor service-wide cooldown even when retry-errors is requested.
$db->exec('DELETE FROM movie_posters; DELETE FROM poster_service');
$badApi = static function (): array { throw new TmdbFailure(120, true, 'rate_limited', 429, 25, 0, 'Too many requests', 'search/movie', true, 3); };
$failed = movie_poster($db, $movie, $badApi, 8000);
$diagnostic = poster_report($movie, $failed);
verify($diagnostic['error']['http_status'] === 429 && $diagnostic['error']['attempts'] === 3, 'HTTP diagnostics not persisted.');
$deferred = movie_poster($db, $second, $badApi, 8001, true);
verify($deferred['status'] === 'error' && $deferred['deferred'] && $deferred['next_check'] === 8120, 'Retry-errors bypassed Retry-After.');
$time = 8001;
$budget = 120;
$slept = 0;
$clock = static function () use (&$time): int { return $time; };
$sleep = static function ($seconds) use (&$time, &$slept): void { $time += $seconds; $slept += $seconds; };
$recovery = static fn() => ['results' => []];
$resumed = sync_one_poster($db, $second, true, $budget, $recovery, $clock, $sleep);
verify($resumed['status'] === 'not_found' && $slept === 119 && $budget === 1, 'Batch sync did not wait and resume after a rate limit.');
$db->exec('DELETE FROM movie_posters; DELETE FROM poster_service');
movie_poster($db, $movie, $badApi, 9000);
$time = 9001; $budget = 0; $slept = 0;
$deferred = sync_one_poster($db, $second, true, $budget, $recovery, $clock, $sleep);
verify($deferred['status'] === 'error' && $deferred['deferred'] && $slept === 0, 'Batch wait budget must be respected.');
verify($snapshot() === $before, 'Poster resolution changed account/list/catalog data.');
echo "Poster tests passed (offline fixtures; 63 catalog rows, additive migration, matching, cache, failures and isolation).\n";
