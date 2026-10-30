<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/tmdb-http.php';
require dirname(__DIR__) . '/app/poster-report.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function execute_sequence(array $responses, ?TmdbFailure &$failure, array &$waits, int &$calls, bool $cli = true): ?array
{
    $time = 1000.0;
    $waits = [];
    $calls = 0;
    $failure = null;
    $send = static function () use (&$responses, &$calls): array {
        $calls++;
        if ($responses === []) throw new RuntimeException('Unexpected extra request.');
        return array_shift($responses);
    };
    $sleep = static function (float $seconds) use (&$time, &$waits): void { $waits[] = $seconds; $time += $seconds; };
    $clock = static function () use (&$time): float { return $time; };
    try {
        return tmdb_request('search/movie', ['query' => 'Matrix'], $send, $sleep, $clock, $cli);
    } catch (TmdbFailure $error) { $failure = $error; return null; }
}

$success = ['http' => 200, 'body' => '{"results":[]}'];
$error = null; $waits = []; $calls = 0;
expect(execute_sequence([$success], $error, $waits, $calls) === ['results' => []], 'Empty search must be a successful response.');
expect($error === null && $calls === 1, 'Empty search is not an API error.');
foreach ([500, 502, 503, 504] as $http) {
    execute_sequence([['http' => $http, 'body' => '{"status_message":"Temporary upstream failure"}'], $success], $error, $waits, $calls);
    expect($error === null && $calls === 2 && $waits === [1.0], 'Transient HTTP failure should recover.');
}
$timeout = ['http' => 0, 'body' => '', 'curl_code' => 28, 'curl_message' => 'Operation timed out'];
execute_sequence([$timeout, $timeout, $success], $error, $waits, $calls);
expect($error === null && $waits === [1.0, 2.0] && $calls === 3, 'Timeout retry backoff failed.');
execute_sequence([$timeout, $timeout, $timeout], $error, $waits, $calls);
expect($error->kind === 'timeout' && $error->curlCode === 28 && $error->attempts === 3, 'Exhausted timeouts lost diagnostics.');
execute_sequence([['http' => 0, 'curl_code' => 60, 'curl_message' => 'SSL certificate problem']], $error, $waits, $calls);
expect($calls === 1 && !$error->retryable && $error->curlCode === 60, 'Certificate errors should be diagnosed, never bypassed/retried blindly.');

foreach ([401, 403, 404, 422] as $http) {
    execute_sequence([['http' => $http, 'body' => '{"status_code":7,"status_message":"Rejected by API"}']], $error, $waits, $calls);
    expect($error->httpStatus === $http && $error->apiCode === 7 && $calls === 1, 'Permanent HTTP failure misclassified or retried.');
}
$limited = ['http' => 429, 'body' => '{"status_code":25,"status_message":"Rate limit"}', 'retry_after' => '2'];
execute_sequence([$limited, $success], $error, $waits, $calls);
expect($error === null && $waits === [2.0] && $calls === 2, 'Retry-After delay not honored.');
$limited['retry_after'] = '120';
execute_sequence([$limited], $error, $waits, $calls);
expect($error->kind === 'rate_limited' && $error->retryAfter === 120 && $calls === 1 && $waits === [], 'Long Retry-After must defer without an early retry.');
expect(tmdb_retry_after(gmdate('D, d M Y H:i:s \G\M\T', 1120), 1000) === 120, 'HTTP-date Retry-After failed.');
execute_sequence([['http' => 200, 'body' => 'not json'], $success], $error, $waits, $calls);
expect($error === null && $calls === 2, 'Temporary invalid JSON should retry.');
execute_sequence(array_fill(0, 3, ['http' => 200, 'body' => '<html>gateway</html>']), $error, $waits, $calls);
expect($error->kind === 'invalid_json' && $error->httpStatus === 200, 'Invalid JSON should remain error, not not_found.');
execute_sequence([['http' => 200, 'body' => '{}', 'oversized' => true]], $error, $waits, $calls);
expect($error->kind === 'response_too_large' && $calls === 1, 'Response size guard failed.');

// Credentials echoed by an upstream response/cURL must never reach text or JSON logs.
$token = 'test-read-token-do-not-log-123456789';
$key = 'test-v3-key-do-not-log-987654321';
putenv('TMDB_READ_ACCESS_TOKEN=' . $token);
putenv('TMDB_API_KEY=' . $key);
$message = 'Invalid Bearer ' . $token . ' api_key=' . $key . ' https://api.themoviedb.org/3/movie/1?api_key=' . $key . "\n";
execute_sequence([['http' => 401, 'body' => json_encode(['status_code' => 7, 'status_message' => $message])]], $error, $waits, $calls);
$output = json_encode($error->diagnostic()) . $error->getMessage();
expect(!str_contains($output, $token) && !str_contains($output, $key), 'Token/key leaked.');
expect(str_contains($output, 'redacted') && $error->httpStatus === 401 && $error->apiCode === 7, 'Redaction erased useful diagnostics.');
$report = poster_report(['id' => 1, 'title' => 'Matrix', 'year' => 1999], ['status' => 'error', 'last_error' => json_encode($error->diagnostic()), 'next_check' => 2000]);
$printed = json_encode($report) . poster_report_line($report);
expect(!str_contains($printed, $token) && !str_contains($printed, $key) && str_contains($printed, 'http=401'), 'CLI text/JSON must retain HTTP diagnosis without secrets.');
putenv('TMDB_READ_ACCESS_TOKEN'); putenv('TMDB_API_KEY');
echo "TMDB HTTP tests passed (classification, retries, Retry-After, timeouts, invalid JSON and credential redaction).\n";
