<?php
declare(strict_types=1);

function request_is_https(): bool
{
    // Forwarded headers are untrusted. A TLS-terminating proxy sets APP_HTTPS=1 server-side.
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || getenv('APP_HTTPS') === '1';
}

function session_expired(array $state, int $now): bool
{
    return (isset($state['last_active']) && $now - (int) $state['last_active'] >= 7200)
        || (isset($state['auth_started_at']) && $now - (int) $state['auth_started_at'] >= 28800);
}

function safe_exception_event(Throwable $error, string $reference): string
{
    // No exception message, stack arguments, URL, request body or credential is logged.
    return json_encode(['event' => 'application_error', 'reference' => $reference, 'type' => get_class($error)], JSON_THROW_ON_ERROR);
}

function assert_private_database_directory(string $directory): void
{
    $public = realpath(dirname(__DIR__) . '/movie-recommendation-website');
    $resolved = realpath($directory);
    if ($public === false || $resolved === false) throw new RuntimeException('Database path could not be validated.');
    $public = strtolower(str_replace('\\', '/', $public));
    $resolved = strtolower(str_replace('\\', '/', $resolved));
    if ($resolved === $public || str_starts_with($resolved . '/', $public . '/')) {
        throw new RuntimeException('SQLite database must be outside the public document root.');
    }
}
