<?php
declare(strict_types=1);
require_once __DIR__ . '/recommendations.php';

/** Positive-only leave-one-film-out evaluation; no claims of production relevance. */
function evaluate_recommendations(array $catalog, array $profiles, int $k = 6): array
{
    if ($k < 1) throw new InvalidArgumentException('K must be positive.');
    $cases = $hits = $eligible = 0;
    $reciprocal = 0.0;
    $known = array_fill_keys(array_map('intval',array_column($catalog,'id')),true);
    foreach ($profiles as $events) {
        $signals = array_filter($events,static fn($e) => isset($known[(int)$e['movie_id']]) && in_array($e['list'],['watched','favorites'],true));
        $ids = array_unique(array_map('intval',array_column($signals,'movie_id')));
        sort($ids,SORT_NUMERIC);
        if (count($ids) < 3) continue;
        $eligible++;
        foreach ($ids as $target) {
            // Remove every event for the target to avoid held-out-item leakage.
            $training = array_filter($signals,static fn($event) => (int)$event['movie_id'] !== $target);
            $ranked = rank_recommendations($catalog,array_values($training),$k);
            $rank = array_search($target,array_map('intval',array_column($ranked,'id')),true);
            $cases++;
            if ($rank !== false) { $hits++; $reciprocal += 1/($rank+1); }
        }
    }
    return ['k'=>$k,'eligible_users'=>$eligible,'cases'=>$cases,'hits'=>$hits,
        'hit_rate_at_k'=>$cases ? round($hits/$cases,6) : null,
        'mrr_at_k'=>$cases ? round($reciprocal/$cases,6) : null];
}
