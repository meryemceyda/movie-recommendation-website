"""Configuration checks, not a test of actual TLS or a production web server."""
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import urllib.request
import urllib.error
ROOT=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory() as temp:
    env=dict(os.environ, APP_DB_PATH=str(Path(temp)/'test.sqlite'), APP_ENV='production')
    subprocess.run(['php','bin/init-db.php'],cwd=ROOT,env=env,check=True,capture_output=True)
    forbidden=ROOT/'movie-recommendation-website'/('forbidden-'+Path(temp).name+'.sqlite')
    bad_env=dict(env,APP_DB_PATH=str(forbidden))
    rejected=subprocess.run(['php','bin/init-db.php'],cwd=ROOT,env=bad_env,capture_output=True)
    assert rejected.returncode!=0 and not forbidden.exists(), 'SQLite could be created in the public root'
    for proxy in [False,True]:
        env['APP_HTTPS']='1' if proxy else '0'
        with socket.socket() as sock:
            sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        process=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','movie-recommendation-website'],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        try:
            for _ in range(100):
                try:
                    req=urllib.request.Request(f'http://127.0.0.1:{port}/index.php',headers={'X-Forwarded-Proto':'https'})
                    try: response=urllib.request.urlopen(req,timeout=3)
                    except urllib.error.HTTPError as e: response=e
                    break
                except urllib.error.URLError: time.sleep(.05)
            if proxy:
                assert response.status==200
                assert 'Secure' in response.headers.get('Set-Cookie','') or 'secure' in response.headers.get('Set-Cookie','')
                assert response.headers.get('Strict-Transport-Security')=='max-age=31536000'
            else:
                assert response.status==426 and response.headers.get('Set-Cookie') is None
        finally:
            process.terminate();process.wait(timeout=10)
print('Production configuration tests passed (HTTPS rejection, untrusted forwarded header, trusted proxy cookie/HSTS).')
