<?php
declare(strict_types=1);
putenv('APP_DB_PATH=:memory:');
require dirname(__DIR__) . '/app/bootstrap.php';
function ensure(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
$db = database(); initialize_database($db);
for ($i=0; $i<10; $i++) ensure(reserve_auth_attempt($db,'login','victim@example.com','192.0.2.1',1000) === 0, 'Allowed attempt was rejected.');
ensure(reserve_auth_attempt($db,'login','victim@example.com','192.0.2.1',1001) === 899, 'Pair limit failed.');
ensure(reserve_auth_attempt($db,'login','victim@example.com','192.0.2.2',1001) === 0, 'Attacker locked the victim from another IP.');
ensure(reserve_auth_attempt($db,'login','other@example.com','192.0.2.1',1001) === 0, 'Shared IP should not lock every account after ten attempts.');
ensure(reserve_auth_attempt($db,'login','victim@example.com','192.0.2.1',1900) === 0, 'Expired window did not reset.');
ensure(!session_expired(['last_active'=>100,'auth_started_at'=>100],101), 'Fresh session expired.');
ensure(session_expired(['last_active'=>100,'auth_started_at'=>100],7300), 'Idle expiration failed.');
ensure(session_expired(['last_active'=>28899,'auth_started_at'=>100],28900), 'Active session bypassed absolute timeout.');
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
unset($_SERVER['HTTPS']); putenv('APP_HTTPS');
ensure(!request_is_https(), 'Client-controlled forwarded header was trusted.');
putenv('APP_HTTPS=1'); ensure(request_is_https(), 'Trusted TLS proxy configuration ignored.'); putenv('APP_HTTPS');
try { assert_private_database_directory(dirname(__DIR__).'/movie-recommendation-website'); ensure(false,'Public database path accepted.'); }
catch (RuntimeException $e) { ensure(str_contains($e->getMessage(),'outside'),'Unexpected path failure.'); }
assert_private_database_directory(dirname(__DIR__).'/var');
$secret = 'fake-secret-never-log';
$event = safe_exception_event(new RuntimeException('Authorization: Bearer '.$secret.' password=secret'), 'test-reference');
ensure(!str_contains($event,$secret) && !str_contains($event,'password='), 'Exception log leaked raw message.');
// Existing hashes must still verify; successful authentication resets only its pair bucket.
$hash = password_hash('existing-password',PASSWORD_DEFAULT);
$db->prepare('INSERT INTO users (name,email,password_hash) VALUES (?,?,?)')->execute(['Existing','existing@example.com',$hash]);
$_SERVER['REMOTE_ADDR']='192.0.2.3';
for ($i=0;$i<12;$i++) ensure(authenticate($db,'login','','existing@example.com','existing-password') === null,'Successful logins should not consume the failed-pair budget.');
ensure(password_verify('existing-password',$db->query('SELECT password_hash FROM users')->fetchColumn()),'Existing password no longer works.');
echo "Security tests passed (throttle isolation, successful login, session limits, trusted TLS, DB path and safe logging).\n";
