<?php
declare(strict_types=1);

function migrate_poster_diagnostics(PDO $db): void
{
    $additions = ['last_error' => 'TEXT', 'match_reason' => 'TEXT'];
    $columns = array_column($db->query('PRAGMA table_info(movie_posters)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (array_diff(array_keys($additions), $columns) === []) return;
    // Re-check after acquiring the write lock: another PHP worker may have migrated.
    $db->exec('BEGIN IMMEDIATE');
    try {
        $columns = array_column($db->query('PRAGMA table_info(movie_posters)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        foreach ($additions as $name => $type) {
            if (!in_array($name, $columns, true)) $db->exec('ALTER TABLE movie_posters ADD COLUMN ' . $name . ' ' . $type);
        }
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}
