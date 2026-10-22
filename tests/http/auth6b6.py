import http.cookiejar
import json
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request

jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

def send(port, path, data=None, csrf=True):
    headers = {'Accept': 'application/json', 'Origin': f'http://127.0.0.1:{port}'}
    token = next((c.value for c in jar if c.name == 'TELMANFIT-CLIENT-XSRF'), '')
    headers['X-XSRF-TOKEN'] = urllib.parse.unquote(token) if csrf else 'wrong'
    if data is not None:
        headers['Content-Type'] = 'application/json'
    request = urllib.request.Request(f'http://127.0.0.1:{port}{path}',
                                     data=json.dumps(data).encode() if data is not None else None, headers=headers)
    try:
        result = opener.open(request, timeout=10)
    except urllib.error.HTTPError as e:
        result = e
    body = result.read().decode()
    return result.status, json.loads(body) if body else None, result.headers

for _ in range(100):
    try:
        status, _, _ = send(8098, '/api/auth/csrf')
        if status == 204:
            break
    except urllib.error.URLError:
        time.sleep(.05)
else:
    raise RuntimeError('http_not_ready')

payload = {'purpose': 'login', 'email': 'b6-http-known@example.invalid'}
assert send(8098, '/api/auth/email-otp/request', payload, csrf=False)[0] == 419
known = send(8098, '/api/auth/email-otp/request', payload)
unknown = send(8099, '/api/auth/email-otp/request', {'purpose': 'login', 'email': 'b6-http-decoy@example.invalid'})
assert known[0] == unknown[0] == 202
assert sorted(known[1]) == sorted(unknown[1]) == ['challenge_id', 'expires_in', 'resend_after']
assert known[1]['expires_in'] == unknown[1]['expires_in'] == 300
subprocess.run(['php', 'tests/http/auth6b6-fixture.php', 'fault'], check=True)
try:
    fault = send(8098, '/api/auth/email-otp/request', payload)
    assert fault[0] == 503 and fault[1] == {'code': 'auth_unavailable'}
    assert 'canary' not in json.dumps(fault[1])
finally:
    subprocess.run(['php', 'tests/http/auth6b6-fixture.php', 'recover'], check=True)
for i in range(18):
    assert send(8098 + i % 2, '/api/auth/email-otp/request', payload)[0] == 202
limited = send(8099, '/api/auth/email-otp/request', payload)
assert limited[0] == 429 and limited[2]['Retry-After'] == '900'
assert limited[2]['Cache-Control'].find('no-store') >= 0
print('B6 real HTTP two servers: known/decoy 202, CSRF419, sanitized budget503, shared IP20/21→429, Retry-After PASS')
