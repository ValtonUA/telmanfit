"""Закритий Responses relay для одного Codex invocation; ключ не потрапляє в worker."""
import http.server
import json
from pathlib import Path
import ssl
import sys
import threading
import urllib.error
import urllib.request


class Budget:
    def __init__(self, config):
        self.config, self.used, self.lock = config, 0, threading.Lock()

    def request(self, raw):
        cfg = self.config
        if len(raw) > cfg['limits']['input_bytes']:
            raise ValueError('input budget')
        body = json.loads(raw)
        if body.get('model') != cfg['model']:
            raise ValueError('model')
        # Кожний HTTP request рахується, включно з retry CLI та failed upstream.
        with self.lock:
            if self.used >= cfg['limits']['requests']:
                raise ValueError('request budget')
            self.used += 1
        def bounded(value):
            if isinstance(value, dict):
                if value.get('file_id') or value.get('type') == 'compaction':
                    raise ValueError('Unbounded server-side content')
                if value.get('image_url') and not str(value['image_url']).startswith('data:'):
                    raise ValueError('Remote image token cost')
                for item in value.values():
                    bounded(item)
            elif isinstance(value, list):
                for item in value:
                    bounded(item)
        if body.get('prompt'):
            raise ValueError('Stored server-side prompt')
        bounded(body)
        body['max_output_tokens'] = cfg['limits']['output_tokens']
        body['store'] = False
        body['service_tier'] = 'default'
        if body.get('previous_response_id') or body.get('conversation'):
            raise ValueError('Unbounded server-side context')
        # Ніяких server-side tools/network; локальні tool calls CLI обробляє в sandbox.
        if any(t.get('type') not in {'function', 'custom'} for t in body.get('tools', [])):
            raise ValueError('server tool')
        return json.dumps(body).encode()


class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def do_GET(self):
        if self.path != '/health':
            self.send_error(404)
            return
        self.send_response(200)
        self.end_headers()

    def do_POST(self):
        if self.path != '/v1/responses' or self.headers.get('Transfer-Encoding'):
            self.send_error(403)
            return
        try:
            size = int(self.headers.get('Content-Length', '0'))
            if not 0 < size <= self.server.budget.config['limits']['input_bytes']:
                raise ValueError('size')
            raw = self.server.budget.request(self.rfile.read(size))
            request = urllib.request.Request('https://api.openai.com/v1/responses', data=raw,
                                             headers={'Authorization': 'Bearer ' + self.server.key,
                                                      'Content-Type': 'application/json'})
            with urllib.request.urlopen(request, timeout=180, context=ssl.create_default_context()) as upstream:
                self.send_response(upstream.status)
                self.send_header('Content-Type', upstream.headers.get('Content-Type', 'application/json'))
                self.send_header('Connection', 'close')
                self.end_headers()
                # Streaming до CLI без накопичення/логування даних або ключа.
                while chunk := upstream.read1(65536):
                    self.wfile.write(chunk)
                    self.wfile.flush()
        except (ValueError, KeyError, TypeError, urllib.error.URLError, OSError):
            self.close_connection = True
            # Не віддзеркалюємо upstream error body/credential.
            if not self.wfile.closed:
                try:
                    self.send_error(502, 'API relay rejected request')
                except OSError:
                    pass


def main():
    config = json.loads(Path('/run/config.json').read_text())
    server = http.server.ThreadingHTTPServer(('0.0.0.0', 8080), Handler)
    server.key = Path('/run/api-key').read_text().strip()
    server.budget = Budget(config)
    server.serve_forever()


if __name__ == '__main__':
    main()
