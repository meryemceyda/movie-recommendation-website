<?php
declare(strict_types=1);
require_once __DIR__ . '/recommendations.php';

function recommendation_catalog(PDO $db): array
{
    $movies = $db->query('SELECT * FROM movies ORDER BY id')->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    foreach ($movies as $id => &$movie) { $movie['id'] = (int)$id; $movie['genres'] = []; }
    unset($movie);
    foreach ($db->query('SELECT mg.movie_id, g.name FROM movie_genres mg JOIN genres g ON g.id = mg.genre_id ORDER BY mg.movie_id, g.name') as $row) {
        if (isset($movies[$row['movie_id']])) $movies[$row['movie_id']]['genres'][] = $row['name'];
    }
    return array_values($movies);
}

function recommendations_for_user(PDO $db, ?int $userId, int $limit = 6): array
{
    $movies = recommendation_catalog($db);
    $interactions = [];
    if ($userId !== null) {
        $statement = $db->prepare("SELECT movie_id, list FROM user_movies WHERE user_id = ? AND list IN ('favorites','watched') ORDER BY movie_id, list");
        $statement->execute([$userId]);
        $interactions = $statement->fetchAll();
    }
    return rank_recommendations($movies, $interactions, $limit);
}
