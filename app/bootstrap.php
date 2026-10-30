<?php
declare(strict_types=1);
require __DIR__ . '/database.php';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('ncfilm_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || getenv('APP_HTTPS') === '1',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
if (isset($_SESSION['last_active']) && time() - $_SESSION['last_active'] > 7200) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['last_active'] = time();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' https://image.tmdb.org; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

set_exception_handler(function (Throwable $e): void {
    error_log((string) $e);
    if (PHP_SAPI === 'cli') exit(1);
    http_response_code(500);
    echo 'Uygulama şu anda kullanılamıyor. Kurulum ve veritabanı ayarlarını kontrol edin.';
});

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function input(array $source, string $key): string
{
    return isset($source[$key]) && is_string($source[$key]) ? $source[$key] : '';
}

function redirect(string $query = ''): never
{
    header('Location: index.php' . ($query === '' ? '' : '?' . $query), true, 303);
    exit;
}

function csrf_field(): void
{
    echo '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}

function current_user(PDO $db): ?array
{
    if (empty($_SESSION['user_id'])) return null;
    $statement = $db->prepare('SELECT id, name, email FROM users WHERE id = ?');
    $statement->execute([$_SESSION['user_id']]);
    return $statement->fetch() ?: null;
}

function flash(string $message): void
{
    $_SESSION['flash'] = $message;
}

const LISTS = ['watchlist' => 'İzlenecekler', 'watched' => 'İzlendi', 'favorites' => 'Favoriler'];

require __DIR__ . '/auth.php';
require __DIR__ . '/movies.php';
require __DIR__ . '/posters.php';
