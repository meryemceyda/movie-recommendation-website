<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$commands=[];
foreach(['domain.php','security.php','recommendations.php','posters.php','tmdb-http.php','reviewed-posters.php'] as $test) $commands[]=[PHP_BINARY,'tests/'.$test];
$python=PHP_OS_FAMILY==='Windows'?'python':'python3';
foreach(['throttle_concurrency.py','sync_cli_test.py','http_test.py','deployment_test.py'] as $test) $commands[]=[$python,'tests/'.$test];
$commands[]=['node','tests/posters-js.cjs'];
foreach($commands as $command) {
    echo 'Running '.implode(' ',$command)."\n";
    $process=proc_open($command,[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes,$root);
    if(!is_resource($process)) exit(1);
    $status=proc_close($process);
    if($status!==0) exit($status);
}
echo "All application tests passed. Live TMDB/TLS, load and visual checks are separate.\n";
