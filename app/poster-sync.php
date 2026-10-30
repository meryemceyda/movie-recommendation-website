<?php
declare(strict_types=1);

/** Wait for server-directed rate-limit cooldown within a total CLI wait budget. */
function sync_one_poster(PDO $db, array $movie, bool $retryErrors, int &$waitBudget, ?callable $request = null, ?callable $clock = null, ?callable $sleep = null, ?callable $progress = null, bool $retryUnmatched = false): array
{
    $sleep ??= static fn(int $seconds) => sleep($seconds);
    for ($round = 0; $round < 4; $round++) {
        $result = movie_poster($db, $movie, $request, $clock ? (int) $clock() : null, $retryErrors, $retryUnmatched);
        $report = poster_report($movie, $result);
        if (($report['error']['kind'] ?? '') !== 'rate_limited') return $result;
        $now = $clock ? (int) $clock() : time();
        $wait = max(0, (int) ($result['next_check'] ?? $now) - $now);
        if ($wait <= 0 || $wait > $waitBudget || $round === 3) return $result;
        if ($progress) $progress('TMDB rate limit: waiting ' . $wait . ' seconds (Retry-After).');
        $waitBudget -= $wait;
        // Small chunks keep progress/cancellation responsive; never retry early.
        for ($remaining = $wait; $remaining > 0; $remaining -= min(10, $remaining)) $sleep(min(10, $remaining));
        $retryErrors = true;
    }
    throw new LogicException('Unreachable synchronization state.');
}
