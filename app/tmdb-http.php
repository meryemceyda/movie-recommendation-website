<?php
declare(strict_types=1);

function tmdb_safe_message(string $message): string
{
    foreach (['TMDB_READ_ACCESS_TOKEN', 'TMDB_API_KEY'] as $name) {
        $secret = trim((string) getenv($name));
        if ($secret !== '') $message = str_replace([$secret, rawurlencode($secret), urlencode($secret)], '[redacted]', $message);
    }
    $message = preg_replace('~https?://[^\s<>"\']+~i', '[url redacted]', $message);
    $message = preg_replace('/Bearer\s+[^\s,;"\']+/i', 'Bearer [redacted]', $message);
    $message = preg_replace('/(api_key|access_token|token|authorization)\s*[=:]\s*[^\s&,;"\']+/i', '$1=[redacted]', $message);
    $message = preg_replace('/[\x00-\x1f\x7f]/', ' ', strip_tags($message));
    return substr($message, 0, 300);
}

final class TmdbFailure extends RuntimeException
{
    public function __construct(
        public readonly int $retryAfter = 60,
        public readonly bool $global = false,
        public readonly string $kind = 'upstream_error',
        public readonly int $httpStatus = 0,
        public readonly int $apiCode = 0,
        public readonly int $curlCode = 0,
        string $message = 'TMDB request unavailable.',
        public readonly string $endpoint = '',
        public readonly bool $retryable = false,
        public readonly int $attempts = 1,
    ) {
        parent::__construct(tmdb_safe_message($message));
    }

    public function diagnostic(): array
    {
        return ['kind' => $this->kind, 'http_status' => $this->httpStatus,
            'api_code' => $this->apiCode, 'curl_code' => $this->curlCode,
            'message' => $this->getMessage(), 'endpoint' => $this->endpoint,
            'attempts' => $this->attempts, 'retryable' => $this->retryable];
    }
}

function tmdb_configured(): bool
{
    return trim((string) getenv('TMDB_READ_ACCESS_TOKEN')) !== '' || trim((string) getenv('TMDB_API_KEY')) !== '';
}

function tmdb_credential_hash(): string
{
    return hash('sha256', trim((string) getenv('TMDB_READ_ACCESS_TOKEN')) . ':' . trim((string) getenv('TMDB_API_KEY')));
}

function tmdb_retry_after(?string $value, float $now): int
{
    if ($value === null || trim($value) === '') return 2;
    $value = trim($value);
    if (ctype_digit($value)) return max(1, (int) $value);
    $date = strtotime($value);
    return $date === false ? 2 : max(1, (int) ceil($date - $now));
}

/** One HTTPS request. Never expose its URL/headers/body in diagnostics. */
function tmdb_send(string $path, array $query): array
{
    if (!tmdb_configured()) throw new TmdbFailure(kind: 'not_configured', message: 'TMDB credential is not configured.');
    if (!function_exists('curl_init')) throw new TmdbFailure(kind: 'missing_curl', message: 'PHP curl extension is not enabled.');
    $token = trim((string) getenv('TMDB_READ_ACCESS_TOKEN'));
    if (preg_match('/[\r\n]/', $token)) throw new TmdbFailure(3600, true, 'invalid_credential', message: 'Credential contains an invalid line break.');
    $headers = ['Accept: application/json'];
    if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
    else $query['api_key'] = trim((string) getenv('TMDB_API_KEY'));
    $handle = curl_init('https://api.themoviedb.org/3/' . $path . '?' . http_build_query($query));
    $body = '';
    $retryAfter = null;
    $oversized = false;
    curl_setopt_array($handle, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => PHP_SAPI === 'cli' ? 5 : 2,
        CURLOPT_TIMEOUT => PHP_SAPI === 'cli' ? 15 : 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'NCFilm/1.1 (movie poster lookup)',
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$retryAfter): int {
            if (stripos($line, 'Retry-After:') === 0) $retryAfter = trim(substr($line, 12));
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$oversized): int {
            if (strlen($body) + strlen($chunk) > 2_000_000) { $oversized = true; return 0; }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($handle);
    $response = ['http' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $body,
        'curl_code' => curl_errno($handle), 'curl_message' => curl_error($handle),
        'retry_after' => $retryAfter, 'oversized' => $oversized, 'ok' => $ok !== false];
    curl_close($handle);
    return $response;
}

/** Production and fixture transports share classification, pacing and retries. */
function tmdb_request(string $path, array $query = [], ?callable $send = null, ?callable $sleep = null, ?callable $clock = null, ?bool $cli = null): array
{
    if (!preg_match('~^(search/movie|configuration|movie/[1-9][0-9]*)$~D', $path)) throw new TmdbFailure(kind: 'invalid_endpoint', message: 'Invalid TMDB endpoint.');
    $production = $send === null;
    $send ??= 'tmdb_send';
    $sleep ??= static fn(float $seconds) => usleep((int) ($seconds * 1_000_000));
    $clock ??= static fn(): float => microtime(true);
    $cli ??= PHP_SAPI === 'cli';
    $budget = $cli ? 55 : 12;
    $started = $clock();
    static $nextSlot = 0.0;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        if ($production) {
            $wait = max(0, $nextSlot - $clock());
            if ($wait > 0) $sleep($wait);
            $nextSlot = $clock() + 0.25; // At most four requests/second per PHP process.
        }
        $response = $send($path, $query);
        $http = (int) ($response['http'] ?? 0);
        $curl = (int) ($response['curl_code'] ?? 0);
        $body = (string) ($response['body'] ?? '');
        $data = json_decode($body, true);
        $apiCode = is_array($data) ? (int) ($data['status_code'] ?? 0) : 0;
        $apiMessage = is_array($data) && is_string($data['status_message'] ?? null) ? $data['status_message'] : '';
        $retry = 60;
        $global = false;
        $retryable = false;
        if (!empty($response['oversized'])) {
            $kind = 'response_too_large'; $message = 'TMDB response exceeded the size limit.';
        } elseif ($curl !== 0 || ($response['ok'] ?? true) === false) {
            $kind = $curl === 28 ? 'timeout' : 'connection_error';
            $message = (string) ($response['curl_message'] ?? 'TMDB connection failed.');
            $retryable = in_array($curl, [5, 6, 7, 18, 28, 52, 55, 56], true);
        } elseif ($http === 429) {
            $kind = 'rate_limited'; $message = $apiMessage ?: 'TMDB request limit exceeded.';
            $retry = tmdb_retry_after($response['retry_after'] ?? null, $clock());
            $global = true; $retryable = true;
        } elseif ($http === 401 || $http === 403) {
            $kind = 'authentication_error'; $message = $apiMessage ?: 'TMDB rejected the credential or access.';
            $retry = 3600; $global = true;
        } elseif ($http !== 200 || (is_array($data) && ($data['success'] ?? true) === false)) {
            $kind = 'http_error'; $message = $apiMessage ?: 'TMDB returned an unsuccessful HTTP response.';
            $retryable = in_array($http, [408, 425, 500, 502, 503, 504], true);
        } elseif (!is_array($data)) {
            $kind = 'invalid_json'; $message = 'TMDB returned invalid JSON.'; $retryable = true;
        } else {
            return $data;
        }
        $failure = new TmdbFailure($retry, $global, $kind, $http, $apiCode, $curl, $message, $path, $retryable, $attempt);
        if (!$retryable || $attempt === 3) throw $failure;
        $wait = $http === 429 ? $retry : (2 ** ($attempt - 1)) + ($production ? random_int(0, 250) / 1000 : 0);
        // Never shorten Retry-After to fit the execution budget. Defer instead.
        if ($clock() - $started + $wait + ($cli ? 15 : 5) > $budget) throw $failure;
        $sleep($wait);
    }
    throw new LogicException('Unreachable retry state.');
}
