"""Twenty native PHP processes must share exactly ten atomic login reservations."""
import os
from pathlib import Path
import subprocess
import tempfile
import time
ROOT=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory() as directory:
    env=dict(os.environ,APP_DB_PATH=str(Path(directory)/'race.sqlite'))
    subprocess.run(['php','bin/init-db.php'],cwd=ROOT,env=env,check=True,capture_output=True)
    gate=Path(directory)/'start'
    workers=[subprocess.Popen(['php','tests/throttle_worker.php',str(gate)],cwd=ROOT,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for _ in range(20)]
    try:
        for _ in range(200):
            if len(list(Path(directory).glob('start.ready.*')))==20: break
            time.sleep(.05)
        assert len(list(Path(directory).glob('start.ready.*')))==20, 'Workers did not reach the shared start barrier'
        gate.touch()
        results=[]
        for worker in workers:
            out,err=worker.communicate(timeout=20)
            assert worker.returncode==0,(out,err)
            results.append(out)
        assert results.count('allowed')==10 and results.count('blocked')==10,results
    finally:
        for worker in workers:
            if worker.poll() is None: worker.kill()
print('Concurrent authentication reservations passed (20 processes: 10 allowed, 10 blocked).')
