<?php
declare(strict_types=1);

function auth_pair_bucket(string $email, string $ip): string
{
    return hash('sha256', 'v2:login-pair:' . $ip . ':' . strtolower(trim($email)));
}

/** Reserve before expensive password work; SQLite serializes the whole decision. */
function reserve_auth_attempt(PDO $db, string $action, string $email, string $ip, ?int $clock = null): int
{
    $now = $clock ?? time();
    $buckets = [[hash('sha256', 'v2:' . $action . '-ip:' . $ip), $action === 'register' ? 20 : 120]];
    if ($action === 'login') $buckets[] = [auth_pair_bucket($email, $ip), 10];
    $db->exec('BEGIN IMMEDIATE');
    try {
        $find = $db->prepare('SELECT attempts, expires_at FROM auth_attempts WHERE bucket = ?');
        $next = [];
        foreach ($buckets as [$bucket, $limit]) {
            $find->execute([$bucket]);
            $row = $find->fetch();
            if ($row && (int) $row['expires_at'] > $now && (int) $row['attempts'] >= $limit) {
                $db->exec('ROLLBACK');
                return (int) $row['expires_at'] - $now;
            }
            $fresh = !$row || (int) $row['expires_at'] <= $now;
            $next[] = [$bucket, $fresh ? 1 : (int) $row['attempts'] + 1, $fresh ? $now + 900 : (int) $row['expires_at']];
        }
        $write = $db->prepare('INSERT INTO auth_attempts (bucket, attempts, expires_at) VALUES (?, ?, ?) ON CONFLICT(bucket) DO UPDATE SET attempts = excluded.attempts, expires_at = excluded.expires_at');
        foreach ($next as $values) $write->execute($values);
        $db->prepare('DELETE FROM auth_attempts WHERE expires_at <= ?')->execute([$now]);
        $db->exec('COMMIT');
        return 0;
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}
