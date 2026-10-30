<?php
declare(strict_types=1);
putenv('APP_DB_PATH=:memory:');
require dirname(__DIR__) . '/app/bootstrap.php';
function demand(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$db = database(); initialize_database($db);
$db->exec("INSERT INTO users (name,email,password_hash) VALUES ('Existing','keep@example.com','unchanged-password-hash')");
$db->exec("INSERT INTO user_movies (user_id,movie_id,list) VALUES (1,1,'watchlist'),(1,2,'watched')");
$protected = static function () use ($db): array {
    $out = [];
    foreach (['movies','users','user_movies'] as $table) $out[$table] = $db->query('SELECT * FROM ' . $table)->fetchAll();
    return $out;
};
$before = $protected();
$config = ['images' => ['secure_base_url' => 'https://image.tmdb.org/t/p/', 'poster_sizes' => ['w500','w780']]];
foreach (require dirname(__DIR__) . '/app/poster-identities.php' as $identity) {
    $movie = ['title' => $identity['title'], 'year' => $identity['year']];
    $detail = ['id' => $identity['tmdb_id'], 'original_title' => $identity['titles'][0], 'title' => $identity['titles'][0], 'release_date' => $identity['year'] . '-01-01', 'poster_path' => '/fixturePoster.jpg',
        'credits' => ['cast' => array_map(fn($name) => ['name' => $name], $identity['cast']), 'crew' => [['name' => $identity['director'], 'job' => 'Director']]]];
    if (isset($identity['premiere_country'])) {
        $detail['release_date'] = '2018-01-25';
        $detail['release_dates'] = ['results' => [['iso_3166_1' => 'US', 'release_dates' => [['type' => 1, 'release_date' => '2016-10-15T00:00:00Z']]]]];
    }
    $request = static function ($path) use ($detail, $identity): array {
        demand($path === 'movie/' . $identity['tmdb_id'], 'Reviewed identity should bypass search, not invent another ID.');
        return $detail;
    };
    $result = resolve_tmdb_poster($movie, $request);
    demand($result['status'] === 'matched' && $result['tmdb_id'] === $identity['tmdb_id'], 'Reviewed identity failed.');
    foreach (['id','title','cast','year'] as $fault) {
        $bad = $detail;
        if ($fault === 'id') $bad['id']++;
        if ($fault === 'title') $bad['title'] = $bad['original_title'] = 'Wrong film';
        if ($fault === 'cast') $bad['credits']['cast'] = [];
        if ($fault === 'year') { $bad['release_date'] = '2024-01-01'; unset($bad['release_dates']); }
        demand(resolve_tmdb_poster($movie, fn() => $bad)['status'] === 'not_found', 'Wrong identity/year/credits was accepted.');
    }
}
// Reproduce 54 matched + 5 ambiguous + 4 not_found. Successes are deliberately expired.
$insert = $db->prepare('INSERT INTO movie_posters (movie_id,source_key,status,next_check,tmdb_id,poster_path) VALUES (?,?,?,?,?,?)');
foreach ($db->query('SELECT * FROM movies')->fetchAll() as $movie) {
    $status = $movie['id'] <= 54 ? 'matched' : ($movie['id'] <= 59 ? 'ambiguous' : 'not_found');
    $insert->execute([$movie['id'],poster_source_key($movie),$status, $status === 'matched' ? 1 : 999999,10000+$movie['id'],'/existingPoster.jpg']);
}
$successBefore = $db->query("SELECT * FROM movie_posters WHERE status = 'matched' ORDER BY movie_id")->fetchAll();
$called = [];
foreach ($db->query('SELECT * FROM movies')->fetchAll() as $movie) {
    $identity = reviewed_poster_identity($movie);
    $detail = ['id' => $identity['tmdb_id'] ?? 10000+$movie['id'], 'title' => $identity['titles'][0] ?? $movie['title'], 'original_title' => $identity['titles'][0] ?? $movie['title'], 'release_date' => $movie['year'].'-01-01', 'poster_path' => '/fixturePoster.jpg'];
    if ($identity) $detail['credits'] = ['cast' => array_map(fn($name) => ['name' => $name], $identity['cast']), 'crew' => [['name' => $identity['director'],'job'=>'Director']]];
    $request = static function ($path) use (&$called,$movie,$detail,$config): array {
        $called[$movie['id']] = true;
        return $path === 'search/movie' ? ['results'=>[$detail]] : ($path === 'configuration' ? $config : $detail);
    };
    demand(movie_poster($db,$movie,$request,1000,false,true)['status'] === 'matched', 'Unmatched-only recovery failed.');
}
demand(count($called) === 9, 'Expected only nine unmatched movies to be requested.');
demand($db->query('SELECT * FROM movie_posters WHERE movie_id <= 54 ORDER BY movie_id')->fetchAll() === $successBefore, 'Existing 54 matches changed.');
demand($protected() === $before, 'Movies, accounts/passwords or personal lists changed.');
echo "Reviewed poster tests passed (offline fixtures: 3 identity checks and 54 protected / 9 retried cache rows).\n";
