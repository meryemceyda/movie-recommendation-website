<?php
declare(strict_types=1);
// Run against an isolated in-memory database; never touch developer accounts.
putenv('APP_DB_PATH=:memory:');
require dirname(__DIR__) . '/app/bootstrap.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
$db = database();
initialize_database($db);
initialize_database($db);
check((int) $db->query('SELECT COUNT(*) FROM movies')->fetchColumn() === 63, 'Seed must be complete and idempotent.');
check(count(list_movies($db, null, '', '', '', true)) === 6, 'Six featured movies expected.');
check(count(list_movies($db, null, '', 'Bilim Kurgu', '', false)) > 9, 'Secondary genres should be searchable.');
check(count(list_movies($db, null, '', '', "' OR 1=1 --", false)) === 0, 'Search must treat SQL as data.');
check(count(list_movies($db, null, '', '', '%', false)) === 0, 'Search must escape wildcards.');

check(authenticate($db, 'register', 'Alice', 'ALICE@example.com', 'a-long-password') === null, 'Registration failed.');
$alice = (int) $_SESSION['user_id'];
$stored = $db->query('SELECT password_hash FROM users WHERE id = ' . $alice)->fetchColumn();
check($stored !== 'a-long-password' && password_verify('a-long-password', $stored), 'Passwords must be hashed.');
check(authenticate($db, 'register', 'Duplicate', 'alice@example.com', 'a-long-password') !== null, 'Duplicate email accepted.');
check(authenticate($db, 'register', 'Short', 'short@example.com', 'abc') !== null, 'Short password accepted.');
check(authenticate($db, 'register', 'Bob', 'bob@example.com', 'another-password') === null, 'Second registration failed.');
$bob = (int) $_SESSION['user_id'];

check(save_movie($db, $alice, 1, 'watchlist', 'add'), 'Save failed.');
check(save_movie($db, $alice, 1, 'watchlist', 'add'), 'Repeated save failed.');
check(count(list_movies($db, $alice, 'watchlist', '', '', false)) === 1, 'Save must be idempotent.');
check(count(list_movies($db, $bob, 'watchlist', '', '', false)) === 0, 'User lists leaked.');
save_movie($db, $bob, 1, 'favorites', 'add');
save_movie($db, $alice, 1, 'favorites', 'add');
save_movie($db, $alice, 1, 'watched', 'add');
check(count(list_movies($db, $alice, 'watchlist', '', '', false)) === 0, 'Watched must replace watchlist.');
check(count(list_movies($db, $alice, 'favorites', '', '', false)) === 1, 'Favorites must remain independent.');
save_movie($db, $alice, 1, 'watchlist', 'add');
check(count(list_movies($db, $alice, 'watched', '', '', false)) === 0, 'Watchlist must replace watched.');
save_movie($db, $alice, 1, 'favorites', 'remove');
check(count(list_movies($db, $bob, 'favorites', '', '', false)) === 1, 'Removal crossed ownership boundary.');
check(!save_movie($db, $alice, 99999, 'watchlist', 'add'), 'Unknown movie accepted.');
check(!save_movie($db, $alice, 1, 'admin', 'add'), 'Unknown list accepted.');
check(authenticate($db, 'login', '', 'alice@example.com', 'incorrect') !== null, 'Bad password accepted.');
check(authenticate($db, 'login', '', 'alice@example.com', 'a-long-password') === null, 'Login failed.');
check((int) $_SESSION['user_id'] === $alice, 'Wrong account signed in.');
check(e('<script>') === '&lt;script&gt;', 'HTML output must be escaped.');
// Rate limit persists in the database rather than only in the browser session.
$db->exec('DELETE FROM auth_attempts');
for ($i = 0; $i < 10; $i++) authenticate($db, 'login', '', 'alice@example.com', 'wrong');
check(str_contains(authenticate($db, 'login', '', 'alice@example.com', 'a-long-password') ?? '', '15 dakika'), 'Rate limit not enforced.');
echo "Domain tests passed.\n";
