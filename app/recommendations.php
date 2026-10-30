<?php
declare(strict_types=1);

/** Pure ranking: no database, clock, random state or external service. */
function rank_recommendations(array $movies, array $interactions, int $limit = 6): array
{
    if ($limit <= 0) return [];
    $catalog = [];
    foreach ($movies as $movie) {
        $movie['genres'] = array_values(array_unique(array_filter($movie['genres'] ?? [], static fn($g) => is_string($g) && $g !== '')));
        sort($movie['genres'], SORT_STRING);
        $catalog[(int) $movie['id']] = $movie;
    }
    ksort($catalog, SORT_NUMERIC);
    $signals = [];
    $excluded = [];
    foreach ($interactions as $interaction) {
        $id = (int) $interaction['movie_id'];
        $list = $interaction['list'];
        if (!in_array($list, ['favorites','watched'], true)) continue;
        $excluded[$id] = true;
        // Idempotent even if a caller supplies duplicated interaction rows.
        $signals[$id][$list] = $list === 'favorites' ? 3 : 1;
    }
    $profile = [];
    ksort($signals, SORT_NUMERIC);
    foreach ($signals as $id => $events) {
        $genres = $catalog[$id]['genres'] ?? [];
        if ($genres === []) continue;
        $weight = array_sum($events) / count($genres);
        foreach ($genres as $genre) $profile[$genre] = ($profile[$genre] ?? 0.0) + $weight;
    }
    ksort($profile, SORT_STRING);
    $norm = sqrt(array_sum(array_map(static fn($w) => $w * $w, $profile)));
    $positive = [];
    $fallback = [];
    foreach ($catalog as $id => $movie) {
        if (isset($excluded[$id])) continue;
        $contributions = [];
        foreach ($movie['genres'] as $genre) if (($profile[$genre] ?? 0) > 0) $contributions[$genre] = $profile[$genre];
        $score = $norm > 0 && $movie['genres'] !== [] ? array_sum($contributions) / ($norm * sqrt(count($movie['genres']))) : 0.0;
        $movie['recommendation_score'] = round(min(1.0, max(0.0, $score)), 6);
        if ($score > 0) {
            uksort($contributions, static fn($a,$b) => ($contributions[$b] <=> $contributions[$a]) ?: strcmp($a,$b));
            $movie['recommendation_reason'] = implode(' ve ', array_slice(array_keys($contributions),0,2)) . ' türlerindeki favori ve izleme tercihlerine benziyor.';
            $movie['recommendation_mode'] = 'personalized';
            $positive[] = $movie;
        } else {
            $movie['recommendation_mode'] = 'discovery';
            $movie['recommendation_reason'] = $norm > 0 ? 'Farklı türler keşfetmen için başlangıç önerisi.' : 'Henüz yeterli tercih verisi yok; farklı türlerden başlangıç önerisi.';
            $fallback[$id] = $movie;
        }
    }
    usort($positive, static fn($a,$b) => ($b['recommendation_score'] <=> $a['recommendation_score']) ?: ((int)$a['id'] <=> (int)$b['id']));
    $result = array_slice($positive,0,$limit);
    $covered = [];
    foreach ($result as $movie) foreach ($movie['genres'] as $genre) $covered[$genre] = true;
    // Greedy genre coverage; featured and ID are explicit, deterministic tie-breaks.
    while (count($result) < $limit && $fallback !== []) {
        $bestId = null;
        $bestKey = null;
        foreach ($fallback as $id => $movie) {
            $key = [count(array_diff($movie['genres'],array_keys($covered))), (int)($movie['featured'] ?? 0), -$id];
            if ($bestKey === null || $key > $bestKey) { $bestKey = $key; $bestId = $id; }
        }
        $chosen = $fallback[$bestId]; unset($fallback[$bestId]);
        $result[] = $chosen;
        foreach ($chosen['genres'] as $genre) $covered[$genre] = true;
    }
    return $result;
}
