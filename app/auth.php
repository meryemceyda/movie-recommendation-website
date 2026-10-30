<?php
declare(strict_types=1);

function authenticate(PDO $db, string $action, string $name, string $email, string $password): ?string
{
    $email = strtolower(trim($email));
    $name = trim($name);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        return 'Geçerli bir e-posta adresi girin.';
    }
    // Limit both an address and a source IP, including attempts from new sessions.
    $buckets = [hash('sha256', 'email:' . $email), hash('sha256', 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'cli'))];
    $now = time();
    $db->prepare('DELETE FROM auth_attempts WHERE expires_at <= ?')->execute([$now]);
    $attempt = $db->prepare('SELECT attempts FROM auth_attempts WHERE bucket = ?');
    foreach ($buckets as $bucket) {
        $attempt->execute([$bucket]);
        if ((int) $attempt->fetchColumn() >= 10) {
            http_response_code(429);
            return 'Çok fazla deneme yapıldı. 15 dakika sonra tekrar deneyin.';
        }
    }
    $increment = $db->prepare('INSERT INTO auth_attempts (bucket, attempts, expires_at) VALUES (?, 1, ?) ON CONFLICT(bucket) DO UPDATE SET attempts = attempts + 1');
    foreach ($buckets as $bucket) $increment->execute([$bucket, $now + 900]);

    if ($action === 'register') {
        if ($name === '' || strlen($name) > 100) return 'Adınız 1–100 bayt uzunluğunda olmalı.';
        if (strlen($password) < 10 || strlen($password) > 72 || str_contains($password, "\0")) {
            return 'Parolanız 10–72 bayt uzunluğunda olmalı.';
        }
        try {
            $statement = $db->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
            $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') throw $e;
            return 'Bu e-posta adresiyle kayıt oluşturulamıyor. Giriş yapmayı deneyin.';
        }
        $userId = (int) $db->lastInsertId();
    } else {
        $statement = $db->prepare('SELECT id, password_hash FROM users WHERE email = ?');
        $statement->execute([$email]);
        $user = $statement->fetch();
        // Use a valid dummy hash to avoid a fast exit for unknown accounts.
        $hash = $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $valid = strlen($password) <= 72 && !str_contains($password, "\0") && password_verify($password, $hash);
        if (!$valid || !$user) return 'E-posta veya parola hatalı.';
        $userId = (int) $user['id'];
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
        }
    }
    session_regenerate_id(true);
    $_SESSION = ['user_id' => $userId, 'csrf' => bin2hex(random_bytes(32)), 'last_active' => time()];
    return null;
}

function logout(): never
{
    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600, 'path' => $params['path'],
        'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_destroy();
    redirect();
}
