<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/database.php';
initialize_database(database());
echo 'Database ready: ' . database()->query('SELECT COUNT(*) FROM movies')->fetchColumn() . " movies.\n";
