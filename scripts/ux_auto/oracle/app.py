"""Trusted app lifecycle/fixtures. Не генерує acceptance report і не бачить oracle output."""
import http.server
import json
import os
from pathlib import Path
import subprocess
import threading


def php(*args, payload=None):
    return subprocess.check_output(['php', *args], cwd='/workspace', text=True, input=payload, timeout=180).strip()


class Fixtures(http.server.BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def do_GET(self):
        actions = {
            '/metadata': lambda: Path('/tmp/oracle-meta.json').read_text(),
            '/admin-totp': lambda: php('/fixture/tests/browser/auth5-fixture.php', 'totp', payload=json.dumps({'email': 'auth5-admin@example.invalid'})),
            '/client-mail': lambda: (php('artisan', 'queue:work', '--stop-when-empty', '--tries=1', '--sleep=0'),
                                    php('/fixture/tests/browser/auth5-fixture.php', 'mail', 't52-browser@example.invalid'))[-1],
            '/revoke': lambda: php('/control/oracle/fixture.php', 'revoke'),
            '/touch': lambda: php('/control/oracle/fixture.php', 'touch'),
        }
        if self.path not in actions:
            self.send_error(404)
            return
        try:
            result = actions[self.path]()
            self.send_response(200)
            self.end_headers()
            self.wfile.write(result.encode())
        except Exception:
            self.send_error(500, 'Synthetic fixture failure')


def main():
    if os.environ.get('TELMANFIT_PG_TEST') != '1' or not os.environ['DB_HOST'].startswith('/tmp/telmanfit-stage1.'):
        raise RuntimeError('Потрібний guarded disposable context')
    php('artisan', 'migrate', '--force', '--no-interaction')
    php('/control/oracle/fixture.php', 'runtime')
    php('/fixture/tests/browser/auth5-fixture.php', 'setup')
    php('/fixture/tests/browser/client-meal-plan-fixture.php', 'setup')
    metadata = php('/control/oracle/fixture.php', 'seed')
    Path('/tmp/oracle-meta.json').write_text(metadata)
    subprocess.run(['npm', 'run', 'build', '--prefix', 'client'], cwd='/workspace', check=True, timeout=180)
    php('artisan', 'filament:upgrade')
    server = http.server.ThreadingHTTPServer(('0.0.0.0', 8099), Fixtures)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    # Штатний PHP/Laravel HTTP kernel і middleware; без test routes / auth bypass.
    subprocess.run(['php', 'artisan', 'serve', '--host=0.0.0.0', '--port=8080', '--no-reload'],
                   cwd='/workspace', check=True)


if __name__ == '__main__':
    main()
