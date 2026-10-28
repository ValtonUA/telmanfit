"""Owner CLI: preflight, ізольований sandbox і керований live через наявний tick."""
import argparse
from copy import deepcopy
import datetime
import json
import os
import signal
from pathlib import Path
import subprocess
import time

from scripts.ux_auto.app_token import AppToken
from scripts.ux_auto.continuation import arm, claim, disarm, eligible
from scripts.ux_auto.backend import Backend
from scripts.ux_auto.coordinator import Blocked, Waiting, check_evidence, tick, validate, summary
from scripts.ux_auto.github import GitHub, clean_env
from scripts.ux_auto.live_config import load, identity, authorize
from scripts.ux_auto.store import Session, seed
from scripts.ux_auto.worker import ROOT, Workers, run


def preflight(config, api):
    repo = api.call('GET', f'repos/{config["repository"]}/')
    master = api.master()
    comparison = api.call('GET', f'repos/{config["repository"]}/compare/{config["trusted_ref"]}...{master}')
    blockers = []
    if not repo.get('permissions', {}).get('push'):
        blockers.append('GitHub token не має Contents write')
    if comparison['status'] not in {'ahead', 'identical'}:
        blockers.append('Trusted ref не є предком master; merge bootstrap/adapters перед live')
    if not os.environ.get('UX_AUTO_OPENAI_API_KEY'):
        blockers.append('Відсутній окремий Codex API credential')
    if not os.environ.get('UX_AUTO_SANDBOX_GH_TOKEN') and not os.environ.get('UX_AUTO_APP_PRIVATE_KEY'):
        blockers.append('Відсутній installation token для окремого sandbox repository')
    for workflow in ('fast.yml', 'docs-health.yml', 'targeted.yml'):
        data = api.call('GET', f'repos/{config["repository"]}/actions/workflows/{workflow}')
        if data['state'] != 'active':
            blockers.append(f'Workflow {workflow} не active')
    return {'status': 'PARTIAL' if blockers else 'READY_FOR_SANDBOX', 'master': master,
            'blockers': blockers, 'live_feasibility': 'PARTIAL — потрібен реальний sandbox receipt'}


def trusted_checkout(config):
    current = subprocess.check_output(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'], text=True).strip()
    dirty = subprocess.check_output(['git', '-C', str(ROOT), 'status', '--porcelain'], text=True).strip()
    if current != config['trusted_ref'] or dirty:
        raise Blocked('Controller має виконуватися з чистого pinned trusted checkout')


def sandbox(config, session, token, api_key):
    api = GitHub(config['sandbox_repository'], token)
    workers = Workers(config, ROOT, api_key)
    backend = Backend(api, session, workers, config, sandbox=True)
    # Тільки окремий репозиторій й harmless probe. Ніякого tick/UX stage або Issue #106 writes.
    pr = backend.publish('SANDBOX', 1, 'sandbox:code:' + config['identity'], None)
    proof = backend.evidence('SANDBOX')
    check_evidence('SANDBOX', pr, proof, config['spec_sha'], repository=config['sandbox_repository'])
    receipt = backend.merge('SANDBOX', pr['head'], pr['base_sha'], 'sandbox:merge:' + pr['head'])
    if not backend.confirm('SANDBOX', receipt):
        raise Blocked('Sandbox merge не підтверджено')
    merged_at = api.pr(pr['number'])['merged_at']
    tested_at = datetime.datetime.fromisoformat(merged_at.replace('Z', '+00:00')).timestamp()
    record = {'status': 'success', 'config_sha': config['identity'], 'repository': config['sandbox_repository'],
              'pr': pr['number'], 'head': pr['head'], 'base': pr['base_sha'], 'merge_sha': receipt['merge_sha'],
              'ci': proof['ci'], 'review': proof['review'], 'browser': proof['browser'], 'time': tested_at}
    session.update(lambda state: state.__setitem__('sandbox', record))
    return {'status': 'success', 'sandbox_pr': pr['url'], 'live_enabled': False}


def activate(config, session, sandbox_api):
    record = session.value.get('sandbox') or {}
    raw = sandbox_api.pr(record.get('pr', 0))
    if (time.time() - record.get('time', 0) > 7 * 86400
            or raw.get('merged') is not True or raw['head']['sha'] != record.get('head')
            or raw.get('merge_commit_sha') != record.get('merge_sha')):
        raise Blocked('Sandbox receipt відсутній, застарілий або змінився')
    candidate = deepcopy(session.value)
    candidate['activation'] = config['identity']
    authorize(config, candidate)
    def enable(state):
        state.update(activation=config['identity'], paused=False)
        state['checkpoint']['live_authorized'] = True
    session.update(enable)


def advance(config, session, workers, api, backend=None, monotonic=time.monotonic, sleeper=time.sleep):
    authorize(config, session.value)
    backend = backend or Backend(api, session, workers, config)
    start = monotonic()
    for _ in range(1000):
        # Запас до hard job timeout: не починати нову invocation на межі slice.
        if monotonic() - start >= max(1, min(config['limits']['run_seconds'], 270 * 60) - config['limits']['invocation_seconds'] - 120):
            return {'status': 'yielded', 'continuation': True}
        checkpoint = session.value['checkpoint']
        validate(checkpoint, config['spec_sha'])
        result = tick(checkpoint, backend, config['spec_sha'])
        session.update(lambda state: state.__setitem__('checkpoint', result))
        blocked = next((s for s in result['stages'] if s['state'] == 'blocked'), None)
        if blocked:
            disarm(session, blocked['reason'])
            backend.write_status(blocked['id'], 'blocked', blocked.get('pr'), reason=blocked['reason'])
            return summary(result)
        if summary(result)['complete']:
            disarm(session, 'complete')
            backend.write_status('UX-6', 'complete', result['stages'][-1]['pr'], result['stages'][-1]['merge_sha'])
            return summary(result)
        if checkpoint['stages'] != result['stages'] and any(
                before['state'] in {'running', 'fixing', 'verifying'} and before != after
                for before, after in zip(checkpoint['stages'], result['stages'])):
            return {'status': 'yielded', 'continuation': True}
        if result == checkpoint:
            # Bounded poll з lease/pause перевіркою; no busy loop і no coder retry для waiting CI.
            sleeper(15)
    return {'status': 'yielded', 'continuation': True}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=('preflight', 'seed', 'sandbox', 'activate', 'run', 'continue', 'pause', 'resume', 'status'))
    parser.add_argument('--config', required=True)
    args = parser.parse_args()
    def interrupt(*_):
        raise KeyboardInterrupt
    signal.signal(signal.SIGTERM, interrupt)
    try:
        config = load(args.config)
        config['identity'] = identity(config)
        # identity — derived field, не частина digest під час повторної перевірки.
        trusted_checkout(config)
        if os.environ.get('UX_AUTO_APP_PRIVATE_KEY'):
            app_id = int(os.environ.get('UX_AUTO_APP_ID', '0'))
            token = AppToken(app_id, config['installation_id'], config['repository_id'], os.environ['UX_AUTO_APP_PRIVATE_KEY'])
            sandbox_token = AppToken(app_id, config['installation_id'], config['sandbox_repository_id'], os.environ['UX_AUTO_APP_PRIVATE_KEY'])
        else:
            token, sandbox_token = os.environ.get('UX_AUTO_GH_TOKEN'), os.environ.get('UX_AUTO_SANDBOX_GH_TOKEN')
            if args.command in {'sandbox', 'activate', 'run', 'continue', 'resume'}:
                raise Blocked('Для тривалого live потрібен App token provider із автоматичним refresh')
        if args.command in {'sandbox', 'run', 'continue'} and not os.environ.get('UX_AUTO_OPENAI_API_KEY'):
            raise Blocked('Відсутній UX_AUTO_OPENAI_API_KEY; invocation заборонено')
        api = GitHub(config['repository'], token)
        if args.command == 'preflight':
            report = preflight(config, api)
        elif args.command == 'seed':
            api.call('POST', f'repos/{config["repository"]}/git/refs',
                     {'ref': 'refs/heads/' + config['state_branch'], 'sha': api.master()})
            api.put('coordinator.json', config['state_branch'], seed(config), None)
            report = {'status': 'seeded', 'paused': True, 'live_enabled': False}
        elif args.command == 'pause':
            session = Session(api, config, maintenance=True)
            session.revision, value = session.read()
            value['paused'] = True
            value.setdefault('continuation', {})['armed'] = False
            session.save(value)  # CAS без захоплення lease: власник може зупинити активний controller.
            report = {'paused': True}
        elif args.command == 'status':
            _, state = Session(api, config, maintenance=True).read()
            report = {'paused': state['paused'], 'spend': state['spend'], 'checkpoint': summary(state['checkpoint']), 'continuation': state.get('continuation')}
        else:
            session = Session(api, config, maintenance=args.command not in {'run', 'continue'})
            if args.command == 'continue':
                _, saved = session.read()
                if not eligible(config, saved) or saved.get('lease', {}).get('expires', 0) > time.time():
                    print(json.dumps({'status': 'idle'}))
                    return 0
            session.acquire()
            try:
                if args.command == 'pause':
                    session.update(lambda state: state.__setitem__('paused', True))
                    report = {'paused': True}
                elif args.command == 'resume':
                    from scripts.ux_auto.coordinator import resume
                    authorize(config, session.value)
                    checkpoint = resume(session.value['checkpoint'], config['spec_sha'])
                    session.update(lambda state: state.update(paused=False, checkpoint=checkpoint))
                    arm(session)
                    report = {'paused': False, 'started': False}
                elif args.command == 'sandbox':
                    if preflight(config, api)['status'] != 'READY_FOR_SANDBOX':
                        raise Blocked('Preflight не пройдено; sandbox заборонено')
                    report = sandbox(config, session, sandbox_token,
                                     os.environ.get('UX_AUTO_OPENAI_API_KEY', ''))
                elif args.command == 'activate':
                    activate(config, session, GitHub(config['sandbox_repository'], sandbox_token))
                    report = {'activated': True, 'started': False}
                else:
                    if args.command == 'run':
                        arm(session)
                    claim(session)
                    report = advance(config, session, Workers(config, ROOT, os.environ.get('UX_AUTO_OPENAI_API_KEY', '')), api)
            finally:
                try:
                    session.release()
                except Blocked:
                    pass  # Втрачений lease не можна відновлювати чи перезаписувати.
        print(json.dumps(report, ensure_ascii=False, indent=2))
        return 0
    except Waiting:
        print(json.dumps({'status': 'waiting', 'action': 'Повторити ту саму команду; спробу Codex не дублювати'}, ensure_ascii=False))
        return 2
    except (Blocked, KeyError, ValueError, OSError) as exc:
        print(json.dumps({'status': 'blocked', 'reason': str(exc)}, ensure_ascii=False))
        return 2


if __name__ == '__main__':
    raise SystemExit(main())
