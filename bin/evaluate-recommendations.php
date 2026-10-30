<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/database.php';
require dirname(__DIR__).'/app/recommendation-repository.php';
require dirname(__DIR__).'/app/recommendation-evaluation.php';
$db=database();
$profiles=[];
foreach($db->query('SELECT user_id,movie_id,list FROM user_movies ORDER BY user_id,movie_id,list') as $row) $profiles[$row['user_id']][]=$row;
echo json_encode(evaluate_recommendations(recommendation_catalog($db),$profiles),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
echo "Positive-only offline leave-one-film-out evaluation; not an online relevance or performance benchmark.\n";
