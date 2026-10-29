#!/usr/bin/env python3
"""Ізольовані ревізії feature; запускається з довіреного checkout інструментів."""
from __future__ import annotations

import argparse
import contextlib
import io
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import sys
import tarfile
import uuid
from datetime import datetime, timezone

sys.path.insert(0, str(Path(__file__).resolve().parent / 'ci'))
import pre_merge

REPO = 'ValtonUA/telmanfit'
ROOT = Path(__file__).resolve().parents[1]
VOLUMES = ('database', 'database-secrets', 'local-state', 'epoch', 'storage')
SERVICES = ('setup', 'database', 'app', 'worker', 'scheduler', 'web')


def run(args, cwd=None, env=None):
    return subprocess.check_output(args, cwd=cwd, env=env, text=True, encoding='utf-8').strip()


def needs_e2e(paths):
    exact = {'compose.yaml', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json',
             'client/package.json', 'client/package-lock.json', 'tests/feature-env-e2e.py',
             '.github/workflows/feature-environments.yml'}
    prefixes = ('docker/local/', 'database/migrations/', 'database/sql/', 'scripts/feature',
                'scripts/ci/pre_merge.py', 'scripts/ci/publish_feature_gate.py', 'scripts/ci/feature_rules.py', 'scripts/ci/record_review.py',
                'tests/ci/test_feature')
    return any(path in exact or path.startswith(prefixes) for path in paths)


def validate_feature(feature):
    if not re.fullmatch(r'feature/[A-Za-z0-9][A-Za-z0-9._/-]*', feature) or '..' in feature or feature.endswith('/'):
        raise ValueError('Потрібна гілка feature/<назва>, без .. та shell-опцій.')
    return feature


def project_name(value):
    if not re.fullmatch(r'telmanfit-feature-[a-f0-9]{24}', value):
        raise ValueError('Невідома ідентичність feature stack.')
    return value


def compose(project, port):
    project_name(project)
    if not 20000 <= port <= 59999:
        raise ValueError('Feature port поза 20000..59999.')
    environment = {'TELMANFIT_LOCAL_RECIPE_EDITOR': 'true'}
    mounts = ['local-state:/tmp/telmanfit-auth4-compose', 'epoch:/run/telmanfit-epoch:ro', 'storage:/var/www/html/storage']
    app = {'image': f'{project}-app', 'environment': environment, 'volumes': mounts, 'networks': ['local']}
    def build(target):
        return {'context': './source', 'dockerfile': 'docker/local/Dockerfile', 'target': target, 'labels': {'io.telmanfit.feature.project': project}}
    services = {
        'setup': {**app, 'user': 'root', 'entrypoint': ['php', 'docker/local/setup.php'],
                  'environment': {**environment, 'TELMANFIT_FEATURE_PROJECT': project, 'TELMANFIT_FEATURE_PORT': str(port)},
                  'volumes': ['local-state:/tmp/telmanfit-auth4-compose', 'epoch:/run/telmanfit-epoch', 'database-secrets:/run/database-secrets']},
        'database': {'image': f'{project}-database', 'build': build('database'),
                     'environment': {'POSTGRES_PASSWORD_FILE': '/run/database-secrets/password'},
                     'volumes': ['database:/var/lib/postgresql', 'database-secrets:/run/database-secrets:ro'],
                     'depends_on': {'setup': {'condition': 'service_completed_successfully'}},
                     'healthcheck': {'test': ['CMD-SHELL', 'pg_isready -U postgres -h 127.0.0.1 && test -f /var/lib/postgresql/local-cluster-ready'], 'interval': '5s', 'timeout': '3s', 'retries': 30}, 'networks': ['local']},
        'app': {**app, 'build': build('app'), 'depends_on': {'database': {'condition': 'service_healthy'}},
                'healthcheck': {'test': ['CMD', 'php', 'docker/local/health.php'], 'interval': '5s', 'timeout': '5s', 'retries': 60}},
        'web': {'image': f'{project}-web', 'build': build('web'), 'ports': [f'127.0.0.1:{port}:80'],
                'depends_on': {'app': {'condition': 'service_healthy'}},
                'healthcheck': {'test': ['CMD-SHELL', 'curl -fsS http://127.0.0.1/up >/dev/null'], 'interval': '5s', 'timeout': '5s', 'retries': 30}, 'networks': ['local']},
    }
    for service, command in [('worker', ['php', 'artisan', 'queue:work', '--sleep=3', '--tries=3', '--timeout=60']), ('scheduler', ['php', 'artisan', 'schedule:work'])]:
        services[service] = {**app, 'command': command, 'depends_on': {'app': {'condition': 'service_healthy'}}}
    return {'name': project, 'services': services, 'volumes': {v: {} for v in VOLUMES}, 'networks': {'local': {}}}


def safe_env():
    # Ні .env, ні Compose override, ні віддалений Docker не успадковуються.
    env = {k: v for k, v in os.environ.items() if not k.startswith(('COMPOSE_', 'DOCKER_')) or k == 'DOCKER_CONFIG'}
    env['COMPOSE_DISABLE_ENV_FILE'] = '1'
    return env


def local_docker():
    endpoint = json.loads(run(['docker', 'context', 'inspect', '--format', '{{json .Endpoints.docker.Host}}']))
    if not (endpoint.startswith('unix:///') or endpoint.startswith('npipe:////./pipe/')):
        raise ValueError('Feature manager дозволяє лише локальний Docker Desktop/socket.')
    args = ['docker', '--host', endpoint]
    if run([*args, 'info', '--format', '{{.OSType}}'], env=safe_env()) != 'linux':
        raise ValueError('Потрібні Linux containers.')
    return args


def prepared(feature):
    prs = pre_merge.api(f'repos/{REPO}/pulls?state=open&base=master&head=ValtonUA:{feature}&per_page=100')[0]
    if len(prs) != 1:
        raise ValueError('Потрібен рівно один відкритий PR цієї feature до master.')
    snapshot = pre_merge.collect(REPO, prs[0]['number'])
    result = pre_merge.evaluate(snapshot, 'preview')
    if not result['eligible']:
        raise ValueError('Feature не підготовлено: ' + '; '.join(result['errors']))
    return result


def export_source(sha, destination):
    # Окремий checkout без .git, host .env, storage, vendor та особистих файлів.
    if run(['git', 'remote', 'get-url', 'origin'], cwd=ROOT) not in (
            f'https://github.com/{REPO}.git', f'https://github.com/{REPO}', f'git@github.com:{REPO}.git'):
        raise ValueError('Origin не є TelmanFit.')
    run(['git', 'fetch', 'origin', sha], cwd=ROOT)
    data = subprocess.check_output(['git', 'archive', '--format=tar', sha], cwd=ROOT)
    with tarfile.open(fileobj=io.BytesIO(data)) as archive:
        for member in archive.getmembers():
            path = Path(member.name)
            if path.is_absolute() or '..' in path.parts or not (member.isfile() or member.isdir()):
                raise ValueError('Archive має небезпечний шлях або symlink.')
            if member.isfile():
                target = destination / path
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(archive.extractfile(member).read())
                target.chmod(member.mode & 0o777)


@contextlib.contextmanager
def lock(root):
    root.mkdir(parents=True, exist_ok=True)
    path = root / 'manager.lock'
    # OS lock автоматично звільняється після crash; lockfile не видаляється.
    with path.open('a+b') as handle:
        if os.name == 'nt':
            import msvcrt
            handle.seek(0)
            handle.write(b'0')
            handle.flush()
            handle.seek(0)
            msvcrt.locking(handle.fileno(), msvcrt.LK_NBLCK, 1)
        else:
            import fcntl
            fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        yield


class Manager:
    def __init__(self, root, docker):
        self.root = root.resolve()
        self.docker = docker
        self.env = safe_env()

    def path(self, project):
        path = self.root / project_name(project)
        if path.is_symlink():
            raise ValueError('Symlink у registry заборонений.')
        return path

    def save(self, record):
        path = self.path(record['project']) / 'record.json'
        temp = path.with_suffix('.tmp')
        temp.write_text(json.dumps(record, ensure_ascii=False, indent=2), encoding='utf-8')
        temp.replace(path)

    def records(self, feature=None):
        result = []
        for path in sorted(self.root.glob('telmanfit-feature-*/record.json')):
            record = json.loads(path.read_text(encoding='utf-8'))
            if self.path(record['project']) != path.parent:
                raise ValueError('Registry path mismatch.')
            if feature is None or record['feature'] == feature:
                result.append(record)
        return result

    def cli(self, record, *args):
        path = self.path(record['project'])
        return run([*self.docker, 'compose', '--project-name', record['project'], '--project-directory', str(path),
                    '--file', str(path / 'compose.json'), *args], env=self.env)

    def verify_resources(self, record):
        project = project_name(record['project'])
        # Усі існуючі об'єкти з іменами, які може змінити Compose, мають ownership.
        objects = [('container', f'{project}-{s}-1') for s in SERVICES]
        objects += [('volume', f'{project}_{v}') for v in VOLUMES]
        objects += [('network', f'{project}_local')]
        for kind, name in objects:
            names = run([*self.docker, kind, 'ls', '--format', '{{.Name}}' if kind != 'container' else '{{.Names}}', *(['--all'] if kind == 'container' else [])], env=self.env).splitlines()
            if name in names:
                obj = json.loads(run([*self.docker, kind, 'inspect', name], env=self.env))[0]
                labels = obj['Config'].get('Labels', {}) if kind == 'container' else obj.get('Labels', {})
                if (labels or {}).get('com.docker.compose.project') != project:
                    raise ValueError('Docker ownership mismatch: ' + name)
        for image in ('app', 'web', 'database'):
            tag = f'{project}-{image}'
            if run([*self.docker, 'image', 'ls', '-q', tag], env=self.env):
                obj = json.loads(run([*self.docker, 'image', 'inspect', tag], env=self.env))[0]
                if (obj['Config'].get('Labels') or {}).get('io.telmanfit.feature.project') != project:
                    raise ValueError('Image ownership mismatch: ' + tag)

    def assert_config(self, record):
        # Перегенерована довіреним manager конфігурація; редаговані overrides не виконуються.
        path = self.path(record['project']) / 'compose.json'
        expected = compose(record['project'], record['port'])
        if json.loads(path.read_text(encoding='utf-8')) != expected:
            raise ValueError('Feature Compose змінено; операцію заблоковано.')
        actual = json.loads(self.cli(record, 'config', '--format', 'json'))
        if actual['name'] != record['project']:
            raise ValueError('Compose project mismatch.')
        for service in actual['services'].values():
            if any(v['type'] != 'volume' for v in service.get('volumes', [])):
                raise ValueError('Host mounts заборонені.')
        self.verify_resources(record)

    def runtime(self, record):
        self.assert_config(record)
        raw = self.cli(record, 'ps', '--all', '--format', 'json')
        items = []
        decoder = json.JSONDecoder()
        while raw.strip():
            part, end = decoder.raw_decode(raw.lstrip())
            items.extend(part if isinstance(part, list) else [part])
            raw = raw.lstrip()[end:]
        running = [item for item in items if item.get('State') in ('running', 'restarting', 'paused')]
        state = 'active' if running else ('partial' if items else 'stopped')
        return {'state': state, 'containers': len(items), 'url': self.url(record)}

    @staticmethod
    def url(record):
        return f"http://{record['project']}.localhost:{record['port']}/admin/login"

    def image_ids(self, record):
        return {kind: json.loads(run([*self.docker, 'image', 'inspect', f"{record['project']}-{kind}"], env=self.env))[0]['Id']
                for kind in ('app', 'web', 'database')}

    def verify_images(self, record):
        actual = self.image_ids(record)
        if not record.get('images'):
            # Одноразове оновлення metadata старого manager: лише вже належні
            # цьому registry images; жодного rebuild або зміни БД.
            if record['status'] not in ('ready', 'active', 'stopped'):
                raise ValueError('Незавершену ревізію не можна resume; потрібен fresh.')
            record['images'] = actual
            self.save(record)
        if actual != record['images']:
            raise ValueError('Immutable images ревізії змінено; resume заблоковано.')

    def stop(self, record):
        self.assert_config(record)
        record['status'] = 'stopping'
        self.save(record)
        # Down звільняє rootfs контейнерів; volumes, images й секрети збережені.
        self.cli(record, 'down', '--remove-orphans')
        record['status'] = 'stopped'
        self.save(record)

    def park_previews(self, target):
        peers = [r for r in self.records() if r['project'] != target['project']]
        # Перевірити всіх peers до першої зміни, включно з іншими feature.
        states = [(r, self.runtime(r)) for r in peers]
        active = [r for r, state in states if state['state'] == 'active']
        for record in active:
            self.verify_images(record)
        for record, state in states:
            if state['containers']:
                self.stop(record)
        return sorted(active, key=lambda r: r.get('created_at', ''))[-1:]

    def activate(self, record):
        self.assert_config(record)
        self.verify_images(record)
        self.cli(record, 'up', '--detach', '--wait', '--wait-timeout', '360', '--no-build')
        record['status'] = 'active'
        self.save(record)
        return record

    def recover(self, record, previous, retryable=False):
        # Спочатку прибрати лише невдалий runtime, не його БД/образи.
        # Якщо це неможливо, не запускати ще один stack у невідомому стані.
        self.stop(record)
        record['status'] = 'stopped' if retryable else 'failed'
        self.save(record)
        for old in previous:
            self.activate(old)

    def resume(self, record):
        self.assert_config(record)
        self.verify_images(record)
        if record['status'] not in ('active', 'ready', 'stopped'):
            raise ValueError('Невдалу або незавершену ревізію не можна resume; потрібен fresh.')
        previous = self.park_previews(record)
        try:
            return self.activate(record)
        except BaseException:
            self.recover(record, previous, retryable=True)
            raise

    def start(self, feature, fresh=False, expected_head=None):
        proof = prepared(feature)
        if expected_head is not None and proof['head_sha'] != expected_head:
            raise ValueError('PR head змінився: потрібна нова reviewed bootstrap команда; ресурсів не створено.')
        existing = self.records(feature)
        matching = [r for r in existing if r['head'] == proof['head_sha'] and r['status'] in ('active', 'ready', 'stopped')]
        if not fresh and matching:
            record = sorted(matching, key=lambda r: r.get('created_at', ''))[-1]
            return self.resume(record)
        if existing and not fresh:
            raise ValueError('Новий SHA не переносить попередню БД. Використайте -Action fresh для чистої ревізії; '
                             '-Action list та -Action resume -Project <id> відновлюють старі дані без міграцій.')
        project = 'telmanfit-feature-' + uuid.uuid4().hex[:24]
        path = self.path(project)
        path.mkdir(mode=0o700)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        if not 20000 <= port <= 59999:
            port = 20000 + int(uuid.uuid4().hex[:8], 16) % 40000
        record = {'project': project, 'feature': feature, 'head': proof['head_sha'], 'base': proof['base_sha'],
                  'pr': proof['pr'], 'port': port, 'status': 'creating',
                  'created_at': datetime.now(timezone.utc).isoformat(), 'admission': proof}
        self.save(record)
        previous = []
        parked = False
        try:
            (path / 'compose.json').write_text(json.dumps(compose(project, port), indent=2), encoding='utf-8')
            export_source(record['head'], path / 'source')
            self.assert_config(record)
            # До build звільнити старі rootfs, не видаляючи тестові дані.
            previous = self.park_previews(record)
            parked = True
            if os.environ.get('CODEX_PROXY_CERT'):
                for service in ('app', 'web', 'database'):
                    run([*self.docker, 'build', '--secret', 'id=proxy_ca,src=' + os.environ['CODEX_PROXY_CERT'],
                         '--label', 'io.telmanfit.feature.project=' + project, '--target', service, '-f', str(path / 'source/docker/local/Dockerfile'),
                         '-t', f'{project}-{service}', str(path / 'source')], env=self.env)
            else:
                self.cli(record, 'build', '--pull')
            record['images'] = self.image_ids(record)
            self.save(record)
            if prepared(feature) != proof:
                raise ValueError('PR змінився протягом збірки; повторіть запуск.')
            return self.activate(record)
        except BaseException:
            if parked:
                self.recover(record, previous)
            else:
                record['status'] = 'failed'
                self.save(record)
            raise

    def remove(self, record):
        self.assert_config(record)
        self.cli(record, 'down', '--volumes', '--remove-orphans', '--rmi', 'all')
        # Лише конкретний registry entry; source/логи не лежать у stable checkout.
        shutil.rmtree(self.path(record['project']))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('feature', type=validate_feature)
    parser.add_argument('--action', choices=('start', 'update', 'fresh', 'stop', 'resume', 'list', 'remove', 'access', 'mail', 'logs'), default='start')
    parser.add_argument('--project', help='Точний project з list; обов’язковий для stop/resume/remove/access/mail/logs')
    parser.add_argument('--expected-head', help='Bootstrap pin: відмова, якщо поточний PR head змінився')
    args = parser.parse_args()
    root = Path(os.environ.get('LOCALAPPDATA', str(Path.home() / '.local/share'))) / 'TelmanFit/features'
    with lock(root):
        manager = Manager(root, local_docker())
        if args.action in ('start', 'update', 'fresh'):
            record = manager.start(args.feature, fresh=args.action == 'fresh', expected_head=args.expected_head)
            print(json.dumps(record, ensure_ascii=False, indent=2))
            print(f"http://{record['project']}.localhost:{record['port']}/admin/login")
            print('Одне активне preview. Дані старих ревізій збережено; list/stop/resume керують станом.')
        else:
            records = manager.records(args.feature)
            if args.action == 'list':
                print(json.dumps([{**r, 'runtime': manager.runtime(r)} for r in records], ensure_ascii=False, indent=2))
                return
            selected = [r for r in records if r['project'] == args.project]
            if len(selected) != 1:
                raise ValueError('Передайте точний -Project із list.')
            record = selected[0]
            if args.action == 'stop':
                manager.stop(record)
            elif args.action == 'resume':
                manager.resume(record)
                print(manager.url(record))
            elif args.action == 'remove':
                manager.remove(record)
            elif args.action == 'logs':
                manager.assert_config(record)
                print(manager.cli(record, 'logs', '--no-color', '--tail', '100'))
            else:
                manager.assert_config(record)
                print(manager.cli(record, 'exec', '-T', 'app', 'php', f'docker/local/{args.action}.php'))


if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, subprocess.CalledProcessError) as error:
        print(f'Feature manager: {error}', file=sys.stderr)
        sys.exit(1)
