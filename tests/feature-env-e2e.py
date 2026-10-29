#!/usr/bin/env python3
"""Локальний disposable Docker acceptance: synthetic gate fixture, без real/stable БД.

Запуск: python3 tests/feature-env-e2e.py. Не використовує GitHub CI/review як
фальшивий доказ: зовнішній admission замінено fixture лише в цьому test harness.
Реальні Docker build/bootstrap/PG18/health/cleanup виконуються manager.
"""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
from unittest.mock import patch
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('feature_env', ROOT / 'scripts/feature_env.py')
feature = importlib.util.module_from_spec(spec)
spec.loader.exec_module(feature)


def source_fixture(sha, destination):
    for name in subprocess.check_output(['git', 'ls-files'], cwd=ROOT, text=True).splitlines():
        source = ROOT / name
        if source.is_file():
            target = destination / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, target)


def stable_snapshot(docker):
    result = {}
    for kind in ('container', 'volume', 'network', 'image'):
        fields = '{{.Repository}}:{{.Tag}} {{.ID}}' if kind == 'image' else ('{{.Names}} {{.ID}}' if kind == 'container' else '{{.Name}}')
        args = [*docker, kind, 'ls', '--format', fields]
        if kind == 'container': args.append('--all')
        result[kind] = sorted(line for line in feature.run(args).splitlines() if line.startswith('telmanfit-local'))
    return result


def browser_probe(record, state, expected):
    if os.environ.get('TELMANFIT_FEATURE_BROWSER') != '1':
        return state
    from playwright.sync_api import sync_playwright
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True, args=['--no-sandbox'])
        context = browser.new_context(storage_state=state)
        if state is None:
            context.add_cookies([{'name': 'telmanfit_admin_session', 'value': 'stable-canary', 'domain': '127.0.0.1', 'path': '/admin'}])
        page = context.new_page()
        url = f"http://{record['project']}.localhost:{record['port']}/admin/login"
        page.goto(url, wait_until='networkidle')
        assert page.locator('input[type=email]').is_visible()
        cookies = context.cookies()
        for revision in expected:
            assert any(c['name'] == 'telmanfit_admin_session' and c['domain'] == revision['project'] + '.localhost' for c in cookies)
        assert any(c['domain'] == '127.0.0.1' and c['value'] == 'stable-canary' for c in cookies)
        state = context.storage_state()
        browser.close()
    print('PASS: Chromium feature hostname/login; separate cookie domains; stable canary unchanged', flush=True)
    return state


def main():
    docker = feature.local_docker()
    before = stable_snapshot(docker)
    proof = {'head_sha': 'a' * 40, 'base_sha': 'b' * 40, 'pr': 1}
    with tempfile.TemporaryDirectory(prefix='telmanfit-feature-e2e-') as directory:
        manager = feature.Manager(Path(directory), docker)
        snapshot = Path(directory) / 'fixture'
        source_fixture(proof['head_sha'], snapshot)
        try:
            with patch.object(feature, 'prepared', return_value=proof), patch.object(feature, 'export_source', side_effect=lambda sha, destination: shutil.copytree(snapshot, destination)):
                print('START: clean PG18/bootstrap/health', flush=True)
                first = manager.start('feature/synthetic-e2e')
                print('PASS: first stack healthy', flush=True)
                access = json.loads(manager.cli(first, 'exec', '-T', 'app', 'php', '-r', "echo file_get_contents('/tmp/telmanfit-auth4-compose/access.json');"))
                origin = f"http://{first['project']}.localhost:{first['port']}"
                assert access['invitation'].startswith(origin + '/app/register#invite=')
                request = urllib.request.Request(f"http://127.0.0.1:{first['port']}/admin/login", headers={'Host': f"{first['project']}.localhost:{first['port']}"})
                with urllib.request.build_opener(urllib.request.ProxyHandler({})).open(request) as response:
                    assert response.status == 200
                    cookies = response.headers.get_all('Set-Cookie') or []
                    assert any(c.startswith('telmanfit_admin_session=') for c in cookies)
                    assert not any('domain=' in c.lower() for c in cookies)
                    assert b'type="email"' in response.read()
                print('PASS: HTTP login, isolated session cookie and invitation origin', flush=True)
                browser_state = browser_probe(first, None, [first])
                manager.cli(first, 'down')  # Зберегти volumes/images; звільнити vfs container rootfs.
                print('UPDATE: new revision; retain first volumes/images (sequential cloud vfs test)', flush=True)
                second = manager.start('feature/synthetic-e2e')
                assert first['project'] != second['project']
                assert len(manager.records('feature/synthetic-e2e')) == 2
                assert access['password'] != json.loads(manager.cli(second, 'exec', '-T', 'app', 'php', '-r', "echo file_get_contents('/tmp/telmanfit-auth4-compose/access.json');"))['password']
                print('PASS: update preserves original registry/volumes/images; secrets independent', flush=True)
                browser_state = browser_probe(second, browser_state, [first, second])
                # У cloud vfs 32GB лише один runtime одночасно; volumes/images не видаляються.
                manager.cli(second, 'down')
                manager.cli(first, 'up', '--detach', '--wait', '--wait-timeout', '360', '--no-build')
                manager.cli(first, 'exec', '-T', 'app', 'php', 'docker/local/health.php')
                assert access == json.loads(manager.cli(first, 'exec', '-T', 'app', 'php', '-r', "echo file_get_contents('/tmp/telmanfit-auth4-compose/access.json');"))
                print('PASS: original revision resumes with preserved DB/secrets', flush=True)
                # Pending migration навмисно лише у disposable контейнері, не source/робочій БД.
                manager.cli(first, 'exec', '-T', 'app', 'php', '-r', "file_put_contents('database/migrations/9999_01_01_000000_pending_probe.php', '<?php');")
                try:
                    manager.cli(first, 'exec', '-T', 'app', 'php', 'docker/local/bootstrap.php')
                    raise AssertionError('Pending migration was not blocked')
                except subprocess.CalledProcessError:
                    pass
                print('PASS: pending migration blocks restart (no migrate/wipe)', flush=True)
                # Частковий initial bootstrap на вже populated synthetic schema має відмовити.
                manager.cli(first, 'exec', '-T', 'app', 'php', '-r', "unlink('/tmp/telmanfit-auth4-compose/database-ready');")
                try:
                    manager.cli(first, 'exec', '-T', 'app', 'php', 'docker/local/bootstrap.php')
                    raise AssertionError('Nonempty schema was not blocked')
                except subprocess.CalledProcessError:
                    pass
                print('PASS: populated schema blocks initial migrations', flush=True)
                manager.remove(first)
                manager.cli(second, 'up', '--detach', '--wait', '--wait-timeout', '360', '--no-build')
                manager.cli(second, 'exec', '-T', 'app', 'php', 'docker/local/health.php')
                print('PASS: cleanup of first does not affect second', flush=True)
        except BaseException:
            for record in manager.records('feature/synthetic-e2e'):
                try:
                    logs = manager.cli(record, 'logs', '--no-color', '--tail', '100', 'database')
                    # PG diagnostics без STATEMENT/SQL/credentials.
                    for line in logs.splitlines():
                        if any(marker in line for marker in ('initdb:', 'FATAL:', 'ERROR:', 'No space', 'Permission denied')):
                            print(line, flush=True)
                except subprocess.CalledProcessError:
                    pass
            raise
        finally:
            for record in manager.records('feature/synthetic-e2e'):
                manager.remove(record)
        assert stable_snapshot(docker) == before
        print('PASS: own resources cleaned; stable Docker inventory unchanged', flush=True)


if __name__ == '__main__':
    main()
