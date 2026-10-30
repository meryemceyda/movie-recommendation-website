<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/database.php';
$directory=dirname(__DIR__).'/var/backups';
if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new RuntimeException('Backup directory could not be created.');
assert_private_database_directory($directory);
$destination=$directory.'/ncfilm-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sqlite';
$mask=umask(0077);
try {
    $statement=database()->prepare('VACUUM INTO ?');
    $statement->execute([$destination]);
} finally { umask($mask); }
echo "Consistent SQLite backup created: ".$destination."\nKeep this file private; it contains account data and password hashes.\n";
