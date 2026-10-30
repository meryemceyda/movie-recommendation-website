<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/database.php';
require dirname(__DIR__) . '/app/posters.php';
require dirname(__DIR__) . '/app/poster-report.php';
require dirname(__DIR__) . '/app/poster-sync.php';

$json = false;
$retryErrors = false;
$movieId = null;
$waitBudget = 120;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help') {
        echo "Usage: php bin/sync-posters.php [--retry-errors] [--movie=ID] [--json] [--max-wait=0..600]\n";
        echo "Retry failed poster lookups without deleting movie/account/list data. Rate-limit cooldowns remain active.\n";
        exit(0);
    }
    if ($argument === '--json') $json = true;
    elseif ($argument === '--retry-errors') $retryErrors = true;
    elseif (preg_match('/^--max-wait=([0-9]{1,3})$/D', $argument, $match) && (int) $match[1] <= 600) $waitBudget = (int) $match[1];
    elseif (preg_match('/^--movie=([1-9][0-9]*)$/D', $argument, $match) && filter_var($match[1], FILTER_VALIDATE_INT)) $movieId = (int) $match[1];
    else { fwrite(STDERR, "Invalid option. Use --help.\n"); exit(2); }
}
if (!tmdb_configured() || !extension_loaded('curl')) {
    fwrite(STDERR, "TMDB_READ_ACCESS_TOKEN (or TMDB_API_KEY) and PHP curl extension are required.\n");
    exit(1);
}
try {
    $db = database();
    $statement = $db->prepare('SELECT id, title, year FROM movies' . ($movieId === null ? '' : ' WHERE id = ?') . ' ORDER BY id');
    $statement->execute($movieId === null ? [] : [$movieId]);
    $movies = $statement->fetchAll();
    if ($movies === []) { fwrite(STDERR, "No matching database movies. The database has not been seeded or modified.\n"); exit(2); }
    $counts = [];
    $reports = [];
    $errors = false;
    foreach ($movies as $movie) {
        $before = poster_cached($db, (int) $movie['id']);
        $poster = sync_one_poster($db, $movie, $retryErrors, $waitBudget, progress: static fn($message) => fwrite(STDERR, $message . "\n"));
        $report = poster_report($movie, $poster, $before === $poster);
        $counts[$report['status']] = ($counts[$report['status']] ?? 0) + 1;
        $errors = $errors || $report['error'] !== null || in_array($report['status'], ['error', 'pending', 'unconfigured'], true);
        $reports[] = $report;
        if (!$json) echo poster_report_line($report) . "\n";
    }
    echo json_encode($json ? ['counts' => $counts, 'movies' => $reports] : $counts, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
    exit($errors ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, tmdb_safe_message(get_class($error) . ': ' . $error->getMessage()) . "\n");
    exit(1);
}
