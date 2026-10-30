<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/database.php';
require dirname(__DIR__) . '/app/posters.php';
if (!tmdb_configured()) {
    fwrite(STDERR, "TMDB_READ_ACCESS_TOKEN (recommended) or TMDB_API_KEY is required.\n");
    exit(1);
}
if (!extension_loaded('curl')) {
    fwrite(STDERR, "PHP curl extension is required.\n");
    exit(1);
}
$db = database();
$counts = [];
$errors = false;
foreach ($db->query('SELECT id, title, year FROM movies ORDER BY id')->fetchAll() as $movie) {
    $poster = movie_poster($db, $movie);
    $status = $poster['status'];
    $counts[$status] = ($counts[$status] ?? 0) + 1;
    $errors = $errors || in_array($status, ['error', 'pending', 'unconfigured'], true);
    printf("%d | %s (%d) | %s\n", $movie['id'], $movie['title'], $movie['year'], $status);
    usleep(150000);
}
echo json_encode($counts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
exit($errors ? 1 : 0);
