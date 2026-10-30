<?php
declare(strict_types=1);
ini_set('display_errors', '0');
// Public images do not need a user session and must not acquire its file lock.
require dirname(__DIR__) . '/app/database.php';
require dirname(__DIR__) . '/app/movies.php';
require dirname(__DIR__) . '/app/posters.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
$fallback = 'assets/poster-placeholder.svg';
$id = isset($_GET['id']) && is_string($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
$size = isset($_GET['size']) && $_GET['size'] === 'detail' ? 'detail' : 'card';
try {
    $movie = $id ? find_movie(database(), $id) : null;
    $url = $movie ? poster_redirect_url(movie_poster(database(), $movie), $size) : null;
} catch (Throwable $error) {
    // A failed lookup/storage service must not break the catalog or expose secrets.
    $url = null;
}
// Short fallback TTL allows automatic recovery after configuration/network repair.
header('Cache-Control: public, max-age=' . ($url ? '3600' : '30'));
header('Location: ' . ($url ?? $fallback), true, 302);
