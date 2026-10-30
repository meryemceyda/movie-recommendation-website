<?php
declare(strict_types=1);

function poster_report(array $movie, array $poster, bool $cached = false): array
{
    $error = null;
    if (!empty($poster['last_error'])) $error = json_decode($poster['last_error'], true);
    if (!is_array($error) && ($poster['status'] ?? '') === 'error') {
        $error = ['kind' => 'legacy_error', 'message' => 'Previous version did not store error details. Run with --retry-errors to capture the actual failure.'];
    }
    if (is_array($error)) {
        $error = array_intersect_key($error, array_flip(['kind', 'http_status', 'api_code', 'curl_code', 'message', 'endpoint', 'attempts', 'retryable']));
        foreach ($error as $key => $value) {
            if (is_string($value)) $error[$key] = tmdb_safe_message($value);
        }
    }
    return ['id' => (int) $movie['id'], 'title' => tmdb_safe_message($movie['title']), 'year' => (int) $movie['year'],
        'status' => $poster['status'], 'reason' => isset($poster['match_reason']) ? tmdb_safe_message($poster['match_reason']) : null,
        'tmdb_id' => isset($poster['tmdb_id']) ? (int) $poster['tmdb_id'] : null,
        'cached' => $cached, 'deferred' => !empty($poster['deferred']),
        'checked_at' => isset($poster['checked_at']) ? gmdate('c', (int) $poster['checked_at']) : null,
        'retry_at' => isset($poster['next_check']) ? gmdate('c', (int) $poster['next_check']) : null,
        'error' => $error];
}

function poster_report_line(array $report): string
{
    $line = sprintf('%d | %s (%d) | %s | %s', $report['id'], $report['title'], $report['year'], $report['status'], $report['cached'] ? 'cache' : 'lookup');
    if ($report['reason']) $line .= ' | reason=' . $report['reason'];
    if ($report['error']) {
        $e = $report['error'];
        $line .= sprintf(' | %s http=%d tmdb=%d curl=%d attempts=%d endpoint=%s | %s', $e['kind'] ?? 'error', $e['http_status'] ?? 0, $e['api_code'] ?? 0, $e['curl_code'] ?? 0, $e['attempts'] ?? 0, $e['endpoint'] ?? '-', $e['message'] ?? '');
        if ($report['retry_at']) $line .= ' | retry_at=' . $report['retry_at'];
        if ($report['deferred']) $line .= ' | deferred_by_service_cooldown';
    }
    return $line;
}
