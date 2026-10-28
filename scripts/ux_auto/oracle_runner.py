"""Два різні контейнери: candidate HTTP app і pinned oracle. Немає спільного output/PID."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import uuid

from scripts.ux_auto.coordinator import Blocked, acceptance


def fingerprint():
    root = Path(__file__).parent
    files = [root / 'oracle_runner.py', root / 'coordinator.py', *sorted((root / 'oracle').glob('*.*'))]
    h = hashlib.sha256()
    for path in files:
        h.update(path.name.encode())
        h.update(path.read_bytes())
    # Fixtures також з trusted ref, не candidate. Зміна ref вже змінює config identity.
    return h.hexdigest()


def verify(report, head):
    if report.get('head') != head or report.get('oracle_mode') != 'external' or report.get('oracle_sha') != fingerprint():
        raise Blocked('Missing/stale external trusted oracle evidence')
    if not report.get('observations'):
        raise Blocked('Trusted oracle не виконав DOM/HTTP/SQL assertions')


def prepare_fixtures(source, directory):
    # Тільки tracked files із довіреного controller, без .env/cache/ignored fixtures.
    names = subprocess.check_output(['git', '-C', str(source), 'ls-tree', '-r', '--name-only', 'HEAD', '--', 'tests'], text=True).splitlines()
    for name in names:
        path = directory / name
        path.parent.mkdir(parents=True, exist_ok=True)
        raw = subprocess.check_output(['git', '-C', str(source), 'show', f'HEAD:{name}'])
        path.write_bytes(raw)
    bootstrap = directory / 'tests/pg/auth2-bootstrap.php'
    bootstrap.write_text('''<?php
$loader = require '/workspace/vendor/autoload.php';
$loader->setPsr4('Tests\\\\', '/fixture/tests');
require_once '/fixture/tests/Recipes/BatchFixture.php';
require_once '/fixture/tests/MealPlans/PlanFixture.php';
require_once '/fixture/tests/MealPlans/ClientMenuFixture.php';
$app = require '/workspace/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
require __DIR__.'/auth2-guard.php';
return $app;
''')


def browser(workers, workspace, output, stage, head):
    from scripts.ux_auto.worker import ROOT, docker, read_report, validate_browser_files
    cfg = workers.config
    tag = 'ux-auto-oracle-' + uuid.uuid4().hex
    network, pg, app, oracle = (tag + x for x in ('-net', '-pg', '-app', '-oracle'))
    owned = []
    with tempfile.TemporaryDirectory(prefix='/tmp/telmanfit-stage1.') as directory:
        work = Path(directory)
        socket = work / 'socket'
        socket.mkdir(mode=0o777)
        socket.chmod(0o777)
        fixtures = work / 'fixture'
        prepare_fixtures(ROOT, fixtures)
        hba = work / 'hba'
        hba.write_text('local all telmanfit_test_runner trust\nlocal all all reject\n')
        hba.chmod(0o644)
        db_socket = '/tmp/telmanfit-stage1.' + uuid.uuid4().hex + '/socket'
        env = {'APP_ENV': 'testing', 'TELMANFIT_PG_TEST': '1', 'DB_CONNECTION': 'pgsql',
               'DB_HOST': db_socket, 'DB_DATABASE': 'telmanfit_stage1_test',
               'DB_USERNAME': 'telmanfit_test_runner', 'DB_PASSWORD': '', 'DB_PORT': '5432', 'DB_URL': '',
               'APP_KEY': 'base64:dGVsbWFuZml0LXBocHVuaXQtdGVzdC1vbmx5ISEhISE=',
               'APP_URL': 'http://app:8080', 'APP_DEBUG': 'false', 'VIEW_COMPILED_PATH': '/tmp/views',
               'SESSION_DRIVER': 'database', 'CACHE_STORE': 'database', 'QUEUE_CONNECTION': 'database',
               'MAIL_MAILER': 'auth4_synthetic', 'AUTH4_SYNTHETIC_MAILBOX': '/tmp/oracle-mailbox',
               'AUTH3_CONTROLLED': 'true', 'AUTH4_CONTROLLED': 'true', 'AUTH5_CONTROLLED': 'true',
               'AUTH3_FLOW_SECONDS': '600', 'AUTH3_SESSION_SECONDS': '3600', 'AUTH3_ATTEMPTS': '100',
               'AUTH3_TOTP_WINDOW': '1', 'AUTH3_RECOVERY_COUNT': '3', 'AUTH3_KEY_VERSION': 'test_v1',
               'AUTH4_OTP_KEY_VERSION': 'test_v1', 'AUTH4_OTP_KEY': 'synthetic-browser-only-hmac-key-32minimum',
               'AUTH4_RATE_KEY': 'synthetic-browser-only-rate-key-32minimum',
               'HTTP_PROXY': '', 'HTTPS_PROXY': '', 'ALL_PROXY': '', 'http_proxy': '', 'https_proxy': '',
               'all_proxy': '', 'no_proxy': '*', 'NO_PROXY': '*', 'HOME': '/tmp/home', 'PYTHONPATH': '/control:/control/oracle'}
        env_args = [item for key, value in env.items() for item in ('--env', key + '=' + value)]
        common = ['--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
                  '--user', f'{os.getuid()}:{os.getgid()}', '--memory', '4g', '--cpus', '2', '--pids-limit', '256',
                  '--tmpfs', '/tmp:rw,nosuid,size=1g', '--network', network, *env_args,
                  '--mount', f'type=bind,src={socket},dst={db_socket}',
                  '--mount', f'type=bind,src={ROOT / "scripts/ux_auto"},dst=/control,readonly']
        try:
            workers.execute(docker('network', 'create', '--internal', '--label', tag, network))
            owned.append(('network', network))
            workers.execute(docker('create', '--name', pg, '--label', tag, '--network', 'none',
                '--tmpfs', '/var/lib/postgresql:rw,nosuid,size=1g', '--memory', '1g', '--pids-limit', '128',
                '--mount', f'type=bind,src={socket},dst=/run/oracle-pg', '--env', 'POSTGRES_HOST_AUTH_METHOD=trust',
                cfg['postgres_image'], 'postgres', '-c', 'listen_addresses=', '-c',
                'unix_socket_directories=/var/run/postgresql,/run/oracle-pg'))
            owned.append(('container', pg))
            workers.execute(docker('start', pg))
            for _ in range(60):
                try:
                    if 'PostgreSQL init process complete; ready for start up.' not in workers.execute(docker('logs', pg)):
                        time.sleep(1)
                        continue
                    version = workers.execute(docker('exec', pg, 'psql', '-X', '-U', 'postgres', '-Atc', 'SHOW server_version_num'))
                    if 180000 <= int(version.strip()) < 190000:
                        break
                except (Blocked, ValueError):
                    pass
                time.sleep(1)
            else:
                raise Blocked('Oracle disposable PG18 not ready')
            workers.execute(docker('exec', pg, 'psql', '-X', '-U', 'postgres', '-v', 'ON_ERROR_STOP=1',
                '-c', 'CREATE ROLE telmanfit_test_runner LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS',
                '-c', "CREATE DATABASE telmanfit_stage1_test OWNER telmanfit_test_runner TEMPLATE template0 ENCODING 'UTF8' LOCALE_PROVIDER icu ICU_LOCALE 'uk-UA'"))
            workers.execute(docker('cp', str(hba), pg + ':/run/oracle-hba'))
            workers.execute(docker('exec', pg, 'psql', '-X', '-U', 'postgres', '-v', 'ON_ERROR_STOP=1',
                '-c', "ALTER SYSTEM SET hba_file = '/run/oracle-hba'", '-c', 'SELECT pg_reload_conf()'))
            # Candidate app не має /out oracle, Docker socket, write-token або API key.
            workers.execute(docker('create', '--name', app, '--label', tag, '--network-alias', 'app', *common,
                '--workdir', '/workspace', '--mount', f'type=bind,src={workspace},dst=/workspace',
                '--mount', f'type=bind,src={fixtures},dst=/fixture,readonly', cfg['worker_image'],
                'python3', '-B', '/control/oracle/app.py'))
            owned.append(('container', app))
            workers.execute(docker('start', app))
            # Oracle не монтує candidate source і не виконує candidate Python/tests/imports.
            workers.execute(docker('create', '--name', oracle, '--label', tag, *common,
                '--mount', f'type=bind,src={output},dst=/out', cfg['oracle_image'],
                'python3', '-B', '/control/oracle/assertions.py', '--stage', stage, '--head', head, '--oracle-sha', fingerprint(), '--checks-json', json.dumps(acceptance(stage))))
            owned.append(('container', oracle))
            workers.execute(docker('start', '--attach', oracle), timeout=cfg['limits']['invocation_seconds'])
            report = read_report(Path(output) / 'browser.json')
            verify(report, head)
            validate_browser_files(output, report, stage, head)
            return report
        finally:
            failed = []
            for kind, name in reversed(owned):
                try:
                    workers.execute(docker('rm', '--force', name) if kind == 'container' else docker('network', 'rm', name))
                except Blocked:
                    failed.append(name)
            # PG socket files належать тільки щойно створеному disposable stack.
            for path in socket.iterdir():
                if path.name in {'.s.PGSQL.5432', '.s.PGSQL.5432.lock'}:
                    path.unlink()
            if failed:
                raise Blocked('Oracle cleanup failed: ' + ','.join(failed))
