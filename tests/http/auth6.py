import concurrent.futures
import http.client
import json
import os
from pathlib import Path
import subprocess
import time

work = Path(os.environ['AUTH6_WORK'])
baseline = os.environ.get('AUTH6_BASELINE') == '1'

def fixture(action, data):
    result = subprocess.run(['php', 'tests/http/auth6-fixture.php', action], input=json.dumps(data), capture_output=True, text=True)
    assert result.returncode == 0, result.stderr
    return json.loads(result.stdout)

def request(port, state, path='/api/me', data=None, barrier=None, probe=None, phase='after'):
    conn = http.client.HTTPConnection('127.0.0.1', port, timeout=15)
    headers = {'Cookie': state['cookie'], 'Accept': 'text/html' if path.startswith('/admin') else 'application/json', 'X-CSRF-TOKEN': state['token'], 'Content-Type': 'application/json'}
    if barrier:
        headers.update({'X-Auth6-Barrier': barrier, 'X-Auth6-Phase': phase})
    if probe:
        headers['X-Auth6-Probe'] = probe
    conn.request('GET' if data is None else 'POST', path, body=None if data is None else json.dumps(data), headers=headers)
    response = conn.getresponse()
    result = {'status': response.status, 'body': response.read().decode(), 'headers': response.getheaders()}
    conn.close()
    return result

def arrived(key):
    deadline = time.monotonic() + 8
    while not (work / (key + '.arrived')).exists():
        assert not first.done(), 'Response before barrier: ' + str(first.result()['status'])
        assert time.monotonic() < deadline, 'Barrier not reached: ' + key
        time.sleep(.02)

def waiting():
    deadline = time.monotonic() + 4
    while fixture('waiting', {}) < 1:
        assert time.monotonic() < deadline, 'No real PG session lock waiter'
        time.sleep(.02)

def release(key):
    (work / (key + '.release')).touch()

def cookie(state, response):
    for name, value in response['headers']:
        if name.lower() == 'set-cookie' and value.startswith(('telmanfit_client_session=', 'telmanfit_admin_session=')):
            state = dict(state, cookie=value.split(';')[0])
    return state

for _ in range(100):
    try:
        c = http.client.HTTPConnection('127.0.0.1', 8096, timeout=1)
        c.connect(); c.close(); break
    except OSError:
        time.sleep(.05)

results = []
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
    for action in ['rotate', 'logout', 'revoke', 'logout_all', 'email', 'reauth']:
        for order in ['read-first', 'mutation-first']:
            state = fixture('prepare', {'action': action})
            op = state['operation']
            key = action + '-' + order
            if order == 'read-first':
                first = pool.submit(request, 8096, state, barrier=key, probe='a')
            else:
                first = pool.submit(request, 8096, state, op['path'], op['data'], key)
            arrived(key)
            if order == 'read-first':
                second = pool.submit(request, 8097, state, op['path'], op['data'])
            else:
                second = pool.submit(request, 8097, state, probe='b')
            time.sleep(.25)
            if baseline:
                other = second.result()
            else:
                waiting()
                assert not second.done(), 'Same-cookie request did not wait before session read'
            release(key)
            earlier = first.result()
            if not baseline:
                other = second.result()
            mutation = other if order == 'read-first' else earlier
            assert mutation['status'] in [200, 202, 204], (action, order, mutation['status'])
            if baseline:
                # Пізня відповідь старого GET перезаписує cookie ротації.
                browser = cookie(cookie(state, other), earlier)
            else:
                # Відповіді застосовуємо також у зворотному порядку.
                for responses in [(earlier, other), (other, earlier)]:
                    browser = state
                    for r in responses:
                        browser = cookie(browser, r)
                    status = request(8096, browser)['status']
                    expected = 200 if action in ['rotate', 'email', 'reauth'] else 401
                    assert status == expected, (action, order, status, expected)
                if action in ['rotate', 'logout', 'revoke', 'logout_all']:
                    assert other['status'] in ([200, 204] if order == 'read-first' else [401]), (action, order, other['status'])
                    if order == 'mutation-first':
                        assert not any(n.lower() == 'set-cookie' for n, _ in other['headers']), 'Stale response overwrites cookie'
            if baseline and action == 'rotate' and order == 'read-first':
                assert request(8096, browser)['status'] == 401
                print('CONFIRMED R1: late GET Set-Cookie replaces rotated cookie; next /me=401', flush=True)
                raise SystemExit(0)
            results.append(action + '/' + order)
            print('AUTH6 full parallel HTTP ' + results[-1] + ' PASS', flush=True)
    for action in ['rotate', 'reauth', 'email', 'revoke']:
        for order in ['action-first', 'logout-first']:
            state = fixture('prepare', {'action': action})
            action_op = state['operation']
            logout_op = {'path': '/api/auth/logout', 'data': {}}
            a, b = (action_op, logout_op) if order == 'action-first' else (logout_op, action_op)
            key = action + '-logout-' + order
            first = pool.submit(request, 8096, state, a['path'], a['data'], key)
            arrived(key)
            second = pool.submit(request, 8097, state, b['path'], b['data'])
            waiting()
            assert not second.done()
            release(key)
            earlier, other = first.result(), second.result()
            assert earlier['status'] in [200, 202, 204], (key, earlier['status'])
            invalidated = order == 'logout-first' or action in ['rotate', 'revoke']
            assert other['status'] == (401 if invalidated else 204), (key, other['status'])
            if invalidated:
                assert not any(n.lower() == 'set-cookie' for n, _ in other['headers'])
            for responses in [(earlier, other), (other, earlier)]:
                browser = state
                for r in responses:
                    browser = cookie(browser, r)
                expected = 200 if action == 'rotate' and order == 'action-first' else 401
                assert request(8096, browser)['status'] == expected, key
            results.append(key)
            print('AUTH6 full parallel HTTP ' + key + ' PASS', flush=True)
    for order in ['read-first', 'mutation-first']:
        state = fixture('prepare', {'action': 'admin_logout'})
        key = 'admin-' + order
        path = '/admin' if order == 'read-first' else '/admin/auth/logout'
        data = None if order == 'read-first' else {}
        first = pool.submit(request, 8096, state, path, data, key)
        arrived(key)
        path = '/admin/auth/logout' if order == 'read-first' else '/admin'
        data = {} if order == 'read-first' else None
        second = pool.submit(request, 8097, state, path, data)
        waiting()
        assert not second.done()
        release(key)
        earlier, other = first.result(), second.result()
        assert earlier['status'] == (200 if order == 'read-first' else 302), earlier['status']
        assert other['status'] == (302 if order == 'read-first' else 403), other['status']
        for responses in [(earlier, other), (other, earlier)]:
            browser = state
            for r in responses:
                browser = cookie(browser, r)
            assert request(8096, browser, '/admin')['status'] in [401, 403]
        results.append(key)
        print('AUTH6 full parallel HTTP ' + key + ' PASS', flush=True)
    state = fixture('prepare', {})
    first = pool.submit(request, 8096, state, barrier='timeout', phase='before')
    arrived('timeout')
    busy = pool.submit(request, 8097, state)
    waiting()
    busy = busy.result()
    assert busy['status'] == 503 and json.loads(busy['body'])['code'] == 'session_busy'
    assert not any(n.lower() == 'set-cookie' for n, _ in busy['headers'])
    release('timeout')
    assert first.result()['status'] == 200
    invalid = dict(state, token='synthetic-wrong-CSRF')
    assert request(8096, invalid, '/api/auth/logout', {})['status'] == 419
    assert request(8097, state)['status'] == 200
    results.extend(['bounded lock timeout', 'lock released after CSRF exception'])
    state = fixture('prepare', {})
    first = pool.submit(request, 8096, state, barrier='save', probe='a')
    arrived('save')
    second = pool.submit(request, 8097, state, probe='b')
    waiting()
    assert not second.done()
    release('save')
    assert first.result()['status'] == second.result()['status'] == 200
    assert fixture('inspect', {'id': state['id']}) == {'a': True, 'b': True}
    results.append('parallel session save preserves both writes')
report = {'result': 'PASS', 'parallel_http': results, 'transport': 'two independent HTTP servers and PG PIDs; same encrypted cookie; CSRF enabled', 'lock_wait': 'pg_stat_activity wait_event_type=Lock before barrier release', 'response_order': 'both application orders for Set-Cookie', 'production_enabled': False}
Path('docs/evidence/auth6').mkdir(parents=True, exist_ok=True)
Path('docs/evidence/auth6/http.json').write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n')
print(json.dumps(report, ensure_ascii=False))
