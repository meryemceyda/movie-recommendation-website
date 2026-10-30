<?php
declare(strict_types=1);

function database(): PDO
{
    static $db = null;
    if ($db instanceof PDO) {
        return $db;
    }
    $path = getenv('APP_DB_PATH') ?: dirname(__DIR__) . '/var/app.sqlite';
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Database directory could not be created.');
    }
    $db = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');
    // Safe for already populated databases: add poster cache tables only.
    $db->exec(file_get_contents(__DIR__ . '/poster-schema.sql'));
    return $db;
}

function initialize_database(PDO $db): void
{
    $db->beginTransaction();
    try {
        $db->exec(file_get_contents(__DIR__ . '/schema.sql'));
        if ((int) $db->query('SELECT COUNT(*) FROM movies')->fetchColumn() === 0) {
            import_movies($db);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function import_movies(PDO $db): void
{
    $source = dirname(__DIR__) . '/movie-recommendation-website/';
    $catalog = file($source . 'filmler.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $details = file($source . 'detaylar.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($catalog === false || $details === false) {
        throw new RuntimeException('Movie seed files could not be read.');
    }
    $primaryGenres = [];
    foreach ($catalog as $line) {
        [$genre, $id] = array_map('trim', explode('|', $line));
        $primaryGenres[(int) $id] = $genre;
    }
    $movie = $db->prepare('INSERT INTO movies (id, title, year, cast_names, synopsis, featured) VALUES (?, ?, ?, ?, ?, ?)');
    $genreInsert = $db->prepare('INSERT OR IGNORE INTO genres (name) VALUES (?)');
    $genreFind = $db->prepare('SELECT id FROM genres WHERE name = ?');
    $link = $db->prepare('INSERT OR IGNORE INTO movie_genres (movie_id, genre_id) VALUES (?, ?)');
    foreach ($details as $line) {
        $parts = array_map('trim', explode('|', $line, 6));
        if (count($parts) !== 6 || !ctype_digit($parts[0]) || !ctype_digit($parts[2])) {
            throw new RuntimeException('Invalid movie seed row.');
        }
        [$id, $title, $year, $genres, $cast, $synopsis] = $parts;
        $id = (int) $id;
        $movie->execute([$id, $title, (int) $year, $cast, $synopsis, (int) in_array($id, [1, 11, 16, 17, 54, 47], true)]);
        // Keep existing catalog categories and also support every detail-page genre.
        $names = array_unique(array_merge(explode('/', $genres), [$primaryGenres[$id] ?? '']));
        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '') continue;
            $genreInsert->execute([$name]);
            $genreFind->execute([$name]);
            $link->execute([$id, (int) $genreFind->fetchColumn()]);
        }
    }
}
