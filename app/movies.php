<?php
declare(strict_types=1);

function find_movie(PDO $db, int $id): ?array
{
    $statement = $db->prepare('SELECT * FROM movies WHERE id = ?');
    $statement->execute([$id]);
    return $statement->fetch() ?: null;
}

function movie_genres(PDO $db, int $id): string
{
    $statement = $db->prepare('SELECT g.name FROM genres g JOIN movie_genres mg ON mg.genre_id = g.id WHERE mg.movie_id = ? ORDER BY g.name');
    $statement->execute([$id]);
    return implode(' / ', $statement->fetchAll(PDO::FETCH_COLUMN));
}

function save_movie(PDO $db, int $userId, int $movieId, string $list, string $operation): bool
{
    if (!isset(LISTS[$list]) || !in_array($operation, ['add', 'remove'], true) || !find_movie($db, $movieId)) return false;
    $db->beginTransaction();
    try {
        if ($operation === 'remove') {
            $db->prepare('DELETE FROM user_movies WHERE user_id = ? AND movie_id = ? AND list = ?')->execute([$userId, $movieId, $list]);
        } else {
            if ($list === 'watched' || $list === 'watchlist') {
                $other = $list === 'watched' ? 'watchlist' : 'watched';
                $db->prepare('DELETE FROM user_movies WHERE user_id = ? AND movie_id = ? AND list = ?')->execute([$userId, $movieId, $other]);
            }
            $db->prepare('INSERT OR IGNORE INTO user_movies (user_id, movie_id, list) VALUES (?, ?, ?)')->execute([$userId, $movieId, $list]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return true;
}

function list_movies(PDO $db, ?int $userId, string $list, string $genre, string $search, bool $featured): array
{
    $sql = 'SELECT m.* FROM movies m WHERE 1 = 1';
    $params = [];
    if ($list !== '') {
        $sql .= ' AND EXISTS (SELECT 1 FROM user_movies um WHERE um.movie_id = m.id AND um.user_id = ? AND um.list = ?)';
        array_push($params, $userId, $list);
    }
    if ($genre !== '') {
        $sql .= ' AND EXISTS (SELECT 1 FROM movie_genres mg JOIN genres g ON g.id = mg.genre_id WHERE mg.movie_id = m.id AND g.name = ?)';
        $params[] = $genre;
    }
    if ($search !== '') {
        $sql .= " AND m.title LIKE ? ESCAPE '\\'";
        $params[] = '%' . strtr($search, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
    }
    if ($featured) $sql .= ' AND m.featured = 1';
    $sql .= ' ORDER BY m.id';
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}
