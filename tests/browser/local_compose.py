"""Лише новий Compose volume set; повторно використовує штатний browser flow PR #31.
Запустити після docker compose up -d --wait, до ручного enrollment/реєстрації.
Тест виконує compose down/up без видалення volumes.
"""
import json
import os
import runpy
import subprocess
from pathlib import Path

# При втраті bootstrap-маркера непорожня БД має бути відхилена, без міграцій/очищення.
probe = subprocess.run(['docker', 'compose', 'exec', '-T', 'app', 'sh', '-c',
    'set -eu; marker=/tmp/telmanfit-auth4-compose/database-ready; '
    'mv "$marker" "$marker.saved"; '
    "trap 'mv \"$marker.saved\" \"$marker\"' EXIT; "
    'php docker/local/bootstrap.php'], capture_output=True, text=True)
assert probe.returncode != 0 and 'Непорожня БД' in probe.stdout + probe.stderr
print('Nonempty database refused without initial marker: PASS', flush=True)
access = json.loads(subprocess.check_output(['docker', 'compose', 'exec', '-T', 'app', 'cat', '/tmp/telmanfit-auth4-compose/access.json']))
os.environ.update(TELMANFIT_COMPOSE_BROWSER='1', DEMO_PASSWORD=access['password'],
                  DEMO_INVITE=access['invitation'].split('#invite=')[1], TELMANFIT_LOCAL_EPOCH_FILE='/run/telmanfit-epoch/epoch')
runpy.run_path(str(Path(__file__).with_name('local_demo.py')), run_name='__main__')
