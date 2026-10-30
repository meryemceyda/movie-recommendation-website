<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/database.php';
require dirname(__DIR__) . '/app/auth-throttle.php';
$db = database();
file_put_contents($argv[1].'.ready.'.getmypid(),'ready');
$deadline = microtime(true) + 10;
while (!is_file($argv[1])) { if (microtime(true)>$deadline) exit(2); usleep(10000); }
echo reserve_auth_attempt($db,'login','victim@example.com','192.0.2.1',1000) === 0 ? 'allowed' : 'blocked';
