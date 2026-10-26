#!/usr/bin/env python3
"""Дві незалежні PG/Chromium групи на одному runner, без нових billed jobs."""
import argparse
import datetime as dt
import json
import os
from pathlib import Path
import re
import signal
import subprocess
import sys
import tempfile
import time
import uuid

ROOT = Path(__file__).resolve().parents[2]
GROUPS = {
    'recipe': {'RECIPE_BATCH_ONLY': '1', 'RECIPE_BATCH_BROWSER': '1'},
    'baseline': {'AUTH3_BROWSER': '1', 'AUTH4_BROWSER': '1', 'AUTH5_BROWSER': '1'},
    'b5': {'AUTH6B5_ONLY': '1'},
    'b3': {'AUTH6B_ONLY': '1'},
}
PHASES = (('recipe', 'baseline'), ('b5', 'b3'))
SELECTORS = set().union(*GROUPS.values()) | {
    'AUTH6B6_ONLY', 'AUTH6B4_ONLY', 'AUTH6B_CASES_ONLY', 'AUTH6_ONLY',
    'AUTH5_ONLY', 'AUTH4_ONLY', 'CLIENT_PROFILES_ONLY',
}
CANCELLED = 0


def utc():
    return dt.datetime.now(dt.timezone.utc).isoformat()


def require_local_docker():
    if os.environ.get('DOCKER_HOST') or os.environ.get('DOCKER_CONTEXT'):
        raise RuntimeError('Stage1 requires local Docker without endpoint overrides')
    endpoint = subprocess.check_output(['docker', 'context', 'inspect', '--format',
                                       '{{.Endpoints.docker.Host}}'], text=True, timeout=5).strip()
    if not endpoint.startswith('unix://'):
        raise RuntimeError('Stage1 requires a local Unix Docker socket')


def stop(processes):
    # Власна session/process group кожного worker включає його HTTP servers.
    for process in processes:
        try:
            os.killpg(process.pid, signal.SIGTERM)
        except ProcessLookupError:
            pass
    deadline = time.monotonic() + 5
    while any(p.poll() is None for p in processes) and time.monotonic() < deadline:
        time.sleep(.05)
    for process in processes:
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        process.wait()


def run_phase(commands, environments, logs, results):
    processes = {}
    handles = []
    try:
        for name, command in commands.items():
            if CANCELLED:
                raise InterruptedError(f'Stage1 interrupted by signal {CANCELLED}')
            handle = logs[name].open('w')
            handles.append(handle)
            start = time.monotonic()
            process = subprocess.Popen(command, cwd=ROOT, env=environments[name],
                                       stdout=handle, stderr=subprocess.STDOUT,
                                       start_new_session=True)
            processes[name] = (process, start)
            results[name] = {'started_at': utc(), 'status': 'running', 'pid': process.pid}
        pending = set(processes)
        while pending:
            if CANCELLED:
                raise InterruptedError(f'Stage1 interrupted by signal {CANCELLED}')
            for name in sorted(pending):
                process, start = processes[name]
                code = process.poll()
                if code is None:
                    continue
                results[name].update(completed_at=utc(), seconds=time.monotonic() - start,
                                     exit_code=code, status='success' if code == 0 else 'failure')
                pending.remove(name)
                print(f"Stage1 group {name}: {results[name]['status']} ({results[name]['seconds']:.2f}s)", flush=True)
                if code:
                    raise RuntimeError(f'Stage1 group {name} failed: {code}')
            if pending:
                time.sleep(.05)
    finally:
        stop([p for p, _ in processes.values()])
        for name, (process, start) in processes.items():
            if results[name]['status'] == 'running':
                results[name].update(completed_at=utc(), seconds=time.monotonic() - start,
                                     exit_code=process.returncode, status='cancelled')
        for handle in handles:
            handle.close()
    if CANCELLED:
        raise InterruptedError(f'Stage1 interrupted by signal {CANCELLED}')


def cleanup_containers(group):
    if not re.fullmatch('[a-f0-9]{32}', group):
        raise ValueError('Invalid private CI group label')
    ids = subprocess.check_output(['docker', 'ps', '-aq', '--no-trunc',
                                   '--filter', f'label=telmanfit.ci-group={group}'], text=True, timeout=5).split()
    for container in ids:
        if not re.fullmatch('[a-f0-9]{64}', container):
            raise ValueError('Invalid container ID')
        label = subprocess.check_output(['docker', 'inspect', '--format',
                    '{{index .Config.Labels "telmanfit.ci-group"}}', container], text=True, timeout=5).strip()
        if label != group:
            raise ValueError('Refusing to remove a foreign container')
        subprocess.run(['docker', 'rm', '-f', container], check=True, stdout=subprocess.DEVNULL, timeout=5)
    return ids


def require_complete(results, expected_groups=None):
    expected_groups = GROUPS if expected_groups is None else expected_groups
    if set(results) != set(expected_groups) or any(
            item.get('status') != 'success' or item.get('exit_code') != 0
            or not item.get('completed_at') for item in results.values()):
        raise RuntimeError('Missing, failed or incomplete Stage1 group')


def worker_environment(name, worker, group):
    env = {k: v for k, v in os.environ.items()
           if k not in SELECTORS | {'GH_TOKEN', 'GITHUB_TOKEN'}}
    env.update(GROUPS[name], VIEW_COMPILED_PATH=str(worker / 'views'),
               STAGE1_PHPUNIT_CACHE=str(worker / 'phpunit'), STAGE1_CI_GROUP=group)
    return env


def interrupted(signum, _frame):
    global CANCELLED
    # Не кидаємо exception між Popen та реєстрацією PID: cleanup має знати всіх.
    CANCELLED = signum
    # Наступний TERM не перериває вже розпочатий bounded cleanup.
    signal.signal(signal.SIGTERM, signal.SIG_IGN)
    signal.signal(signal.SIGINT, signal.SIG_IGN)


def select_groups(value):
    names = list(GROUPS) if value == 'all' else value.split(',')
    if (not names or len(set(names)) != len(names)
            or any(name not in GROUPS for name in names)):
        raise ValueError('--groups must be all or a comma-separated list of known groups')
    selected = {name: GROUPS[name] for name in names}
    phases = tuple(tuple(name for name in phase if name in selected)
                   for phase in PHASES if any(name in selected for name in phase))
    return selected, phases


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--groups', default='all',
                        help='Comma-separated Stage1 groups, or all (default)')
    args = parser.parse_args()
    try:
        selected, phases = select_groups(args.groups)
    except ValueError as error:
        parser.error(str(error))
    selected_groups = list(selected)
    os.chdir(ROOT)
    os.umask(0o077)
    try:
        require_local_docker()
    except (RuntimeError, OSError, subprocess.SubprocessError) as error:
        print(str(error), file=sys.stderr)
        return 1
    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGINT, interrupted)
    out = ROOT / 'tests/pg/out'
    out.mkdir(parents=True, exist_ok=True)
    names = {'recipe': 'recipe', 'baseline': 'stage1', 'b5': 'auth6b5', 'b3': 'auth6b'}
    logs = {name: out / f'{filename}.out' for name, filename in names.items()}
    for path in logs.values():
        path.write_text('')
    group = uuid.uuid4().hex
    summary = {'head_sha': subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip(),
               'started_at': utc(), 'max_workers': 2,
               'ownership_label': group, 'selected_groups': selected_groups,
               'groups': {name: {'status': 'not_started'} for name in selected}, 'status': 'failure'}
    started = time.monotonic()
    code = 1
    with tempfile.TemporaryDirectory(prefix='telmanfit-stage1-ci.', dir='/tmp') as directory:
        summary['private_work_root'] = directory
        (out / 'groups.json').write_text(json.dumps(summary, indent=2) + '\n')
        environments = {}
        for name in selected:
            worker = Path(directory) / name
            for child in ['views', 'phpunit']:
                (worker / child).mkdir(parents=True)
            environments[name] = worker_environment(name, worker, group)
        try:
            for phase in phases:
                run_phase({name: ['bash', 'tests/pg/run-stage1.sh'] for name in phase},
                          environments, logs, summary['groups'])
            require_complete(summary['groups'], selected)
            summary['status'] = 'success'
            code = 0
        except (RuntimeError, OSError, InterruptedError) as error:
            summary['error'] = str(error)
            print(str(error), file=sys.stderr)
        finally:
            try:
                leftovers = cleanup_containers(group)
                summary['recovered_containers'] = leftovers
                if leftovers:
                    summary['status'] = 'failure'
                    summary['error'] = 'Worker left containers; recovered only this private group'
                    code = 1
            except (OSError, ValueError, subprocess.SubprocessError) as error:
                summary.update(status='failure', cleanup_error=str(error))
                code = 1
            summary.update(completed_at=utc(), seconds=time.monotonic() - started)
            if CANCELLED:
                summary.update(status='failure', error=f'Interrupted by signal {CANCELLED}')
                code = 1
            (out / 'groups.json').write_text(json.dumps(summary, indent=2) + '\n')
            for name, path in logs.items():
                if path.exists():
                    print(f'--- Stage1 {name}: {path.name} ---')
                    lines = path.read_text(errors='replace').splitlines()
                    for line in lines:
                        clean = re.sub(r'\x1b\[[0-9;]*m', '', line).strip()
                        if clean.startswith(('OK (', 'Tests:', 'SQL:', '{"tool":"phpunit"',
                                             'Recipe editor browser PASS', 'CI_BROWSER_READINESS')):
                            print(clean)
                    print('\n'.join(lines[-12:]))
    return code


if __name__ == '__main__':
    sys.exit(main())
