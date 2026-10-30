"""Run the actual CLI parser/reporter against a populated, isolated cache."""
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory() as temp:
    secret = 'fixture-only-token-never-print-abcdef123456789'
    environment = dict(os.environ, APP_DB_PATH=str(Path(temp) / 'cli.sqlite'), TMDB_READ_ACCESS_TOKEN=secret)
    environment.pop('TMDB_API_KEY', None)
    setup = r'''
require $argv[1] . '/app/database.php';
require $argv[1] . '/app/posters.php';
$db = database(); initialize_database($db);
$db->exec("INSERT INTO users (name,email,password_hash) VALUES ('Existing', 'existing@example.com', 'unchanged-password-hash')");
$db->exec("INSERT INTO user_movies (user_id,movie_id,list) VALUES (1,1,'watchlist'),(1,2,'watched'),(1,3,'favorites')");
$error = new TmdbFailure(3600, true, 'authentication_error', 401, 7, 0, 'Invalid Bearer ' . getenv('TMDB_READ_ACCESS_TOKEN'), 'search/movie');
$insert = $db->prepare('INSERT INTO movie_posters (movie_id,source_key,status,next_check,checked_at,last_error) VALUES (?,?,?,?,?,?)');
foreach ($db->query('SELECT * FROM movies')->fetchAll() as $movie) {
    $failed = $movie['id'] > 16;
    $insert->execute([$movie['id'], poster_source_key($movie), $failed ? 'error' : 'matched', time()+86400, time(), $failed && $movie['id'] != 18 ? json_encode($error->diagnostic()) : null]);
}
'''
    subprocess.run(['php', '-r', setup, str(ROOT)], env=environment, check=True, capture_output=True, text=True)
    def snapshot():
        with sqlite3.connect(environment['APP_DB_PATH']) as db:
            return {table: db.execute('SELECT * FROM ' + table).fetchall() for table in ['movies', 'users', 'user_movies', 'movie_posters']}
    before = snapshot()
    backed_up = subprocess.run(['php','bin/backup-db.php'],cwd=ROOT,env=environment,capture_output=True,text=True,check=True)
    backup = Path(backed_up.stdout.split('Consistent SQLite backup created: ',1)[1].splitlines()[0])
    assert backup.parent.resolve()==(ROOT/'var'/'backups').resolve()
    try:
        with sqlite3.connect(backup) as copy:
            saved={table:copy.execute('SELECT * FROM '+table).fetchall() for table in before}
        assert saved==before, 'Consistent backup did not preserve original data'
    finally:
        backup.unlink()
    def cli(*args):
        return subprocess.run(['php', 'bin/sync-posters.php', *args], cwd=ROOT, env=environment, capture_output=True, text=True, timeout=15)
    result = cli('--json', '--max-wait=0')
    assert result.returncode == 1, result.stderr
    report = json.loads(result.stdout)
    assert report['counts'] == {'matched': 16, 'error': 47}, report['counts']
    assert report['movies'][16]['error']['http_status'] == 401
    assert report['movies'][16]['error']['api_code'] == 7
    assert report['movies'][17]['error']['kind'] == 'legacy_error'
    assert secret not in result.stdout + result.stderr
    text = cli('--movie=17')
    assert text.returncode == 1 and 'http=401 tmdb=7' in text.stdout
    assert secret not in text.stdout + text.stderr
    single = cli('--movie=1', '--json')
    assert single.returncode == 0 and json.loads(single.stdout)['counts'] == {'matched': 1}
    assert cli('--movie=0').returncode == 2
    assert cli('--max-wait=601').returncode == 2
    assert cli('--help').returncode == 0
    environment.pop('TMDB_READ_ACCESS_TOKEN', None)
    unresolved = cli('--unresolved', '--json')
    assert unresolved.returncode == 1  # Existing API errors are still reported.
    pending = json.loads(unresolved.stdout)
    assert len(pending['movies']) == 47 and all(row['status'] != 'matched' for row in pending['movies'])
    assert snapshot() == before, 'CLI changed existing data while reading fresh cache'
print('Actual sync CLI tests passed (16/47 cached report, HTTP diagnostics, JSON, redaction, options and data preservation).')
