<?php
declare(strict_types=1);
putenv('APP_DB_PATH=:memory:');
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/recommendation-evaluation.php';
function verify_rec(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
$movies = [
    ['id'=>1,'title'=>'Favorite drama','genres'=>['Dram'],'featured'=>0],
    ['id'=>2,'title'=>'Watched comedy','genres'=>['Komedi'],'featured'=>0],
    ['id'=>3,'title'=>'Drama candidate','genres'=>['Dram'],'featured'=>0],
    ['id'=>4,'title'=>'Comedy candidate','genres'=>['Komedi'],'featured'=>0],
    ['id'=>5,'title'=>'Mixed','genres'=>['Dram','Komedi'],'featured'=>0],
    ['id'=>6,'title'=>'Discovery','genres'=>['Korku'],'featured'=>1],
    ['id'=>7,'title'=>'No metadata','genres'=>[],'featured'=>0],
    ['id'=>8,'title'=>'Drama tie','genres'=>['Dram'],'featured'=>0],
];
$events = [['movie_id'=>1,'list'=>'favorites'],['movie_id'=>2,'list'=>'watched']];
$ranked = rank_recommendations($movies,$events,6);
$ids = array_column($ranked,'id');
verify_rec($ids[0] === 3 && $ids[1] === 8 && !in_array(1,$ids,true) && !in_array(2,$ids,true),'Weights, stable tie-break, or exclusions failed.');
verify_rec(abs($ranked[0]['recommendation_score']-3/sqrt(10))<0.000001,'Cosine score differs from expected value.');
verify_rec(str_contains($ranked[0]['recommendation_reason'],'Dram'),'Genre explanation missing.');
verify_rec($ranked === rank_recommendations(array_reverse($movies),array_reverse($events),6),'Input order changed recommendations.');
verify_rec($ranked === rank_recommendations($movies,array_merge($events,$events),6),'Duplicated events changed scores.');
verify_rec(rank_recommendations([],[],6) === [] && rank_recommendations($movies,$events,0) === [],'Empty catalog/limit failed.');
$cold = rank_recommendations($movies,[],3);
verify_rec(count($cold)===3 && count(array_unique(array_merge(...array_column($cold,'genres'))))>=3,'Cold start lacks genre diversity.');
verify_rec(rank_recommendations($movies,[['movie_id'=>1,'list'=>'watchlist']],3)===$cold,'Watchlist incorrectly treated as a preference.');
$allWatched = array_map(fn($m)=>['movie_id'=>$m['id'],'list'=>'watched'],$movies);
verify_rec(rank_recommendations($movies,$allWatched)===[],'All-watched case must be empty.');
$withoutGenres = rank_recommendations([['id'=>1,'genres'=>[]],['id'=>2,'genres'=>[]]],[['movie_id'=>1,'list'=>'favorites']],6);
verify_rec(count($withoutGenres)===1 && $withoutGenres[0]['id']===2 && is_finite($withoutGenres[0]['recommendation_score']),'Missing genre handling failed.');
// Repository reads only this account's events and never writes existing data.
$db=database(); initialize_database($db);
$db->exec("INSERT INTO users(name,email,password_hash) VALUES ('A','a@example.com','keep-a'),('B','b@example.com','keep-b')");
$db->exec("INSERT INTO user_movies(user_id,movie_id,list) VALUES (1,1,'watched'),(1,12,'favorites'),(2,30,'favorites')");
$snapshot = static function () use($db): array {
    $data=[];foreach(['movies','users','user_movies','genres','movie_genres','movie_posters'] as $table) $data[$table]=$db->query('SELECT * FROM '.$table)->fetchAll();return $data;
};
$before=$snapshot();
$a=recommendations_for_user($db,1);$b=recommendations_for_user($db,2);
verify_rec(!in_array(1,array_column($a,'id'),true) && !in_array(12,array_column($a,'id'),true),'Repository recommended known films.');
verify_rec($a!==$b,'Account-specific profiles were not isolated.');
verify_rec($before===$snapshot(),'Recommendations modified stored data.');
$emptyEvaluation=evaluate_recommendations($movies,[]);
verify_rec($emptyEvaluation['cases']===0 && $emptyEvaluation['hit_rate_at_k']===null,'Missing data must not produce a fabricated metric.');
$toy=[];foreach(range(1,4) as $id) $toy[]=['id'=>$id,'genres'=>['Dram']];
$metrics=evaluate_recommendations($toy,[[['movie_id'=>1,'list'=>'watched'],['movie_id'=>2,'list'=>'favorites'],['movie_id'=>3,'list'=>'watched']]],1);
verify_rec($metrics['cases']===3 && $metrics['hit_rate_at_k']===1.0 && $metrics['mrr_at_k']===1.0,'Known leave-one-film-out metric or leakage handling failed.');
echo "Recommendation tests passed (numeric scores, weights, exclusions, stable ties, cold start, edge cases and account isolation).\n";
