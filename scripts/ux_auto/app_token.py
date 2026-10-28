"""Автоматичне поновлення repository-scoped installation token; private key лише controller."""
import base64
import datetime
import json
import os
from pathlib import Path
import re
import ssl
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

from scripts.ux_auto.coordinator import Blocked
from scripts.ux_auto.github import clean_env


def b64(raw):
    return base64.urlsafe_b64encode(raw).decode().rstrip('=')


class AppToken:
    def __init__(self, app_id, installation_id, repository_id, private_key, clock=time.time):
        if not all(type(n) is int and n > 0 for n in (app_id, installation_id, repository_id)) or not private_key:
            raise Blocked('Потрібні App ID, installation ID, repository ID та private key')
        self.app_id, self.installation_id, self.repository_id = app_id, installation_id, repository_id
        self.private_key, self.clock = private_key, clock
        self.cached, self.expires = None, 0

    def __call__(self):
        if self.cached and self.clock() < self.expires - 120:
            return self.cached
        now = int(self.clock())
        payload = b64(json.dumps({'iat': now - 30, 'exp': now + 540, 'iss': str(self.app_id)}).encode())
        message = b64(b'{"alg":"RS256","typ":"JWT"}') + '.' + payload
        with tempfile.TemporaryDirectory(prefix='ux-auto-app-') as directory:
            file = Path(directory) / 'key.pem'
            file.write_text(self.private_key)
            file.chmod(0o600)
            result = subprocess.run(['openssl', 'dgst', '-sha256', '-sign', str(file)],
                                    input=message.encode(), capture_output=True, timeout=10, env=clean_env())
            if result.returncode:
                raise Blocked('Не вдалося підписати App JWT')
            jwt = message + '.' + b64(result.stdout)
        request = urllib.request.Request(
            f'https://api.github.com/app/installations/{self.installation_id}/access_tokens',
            data=json.dumps({'repository_ids': [self.repository_id], 'permissions': {
                'contents': 'write', 'pull_requests': 'write', 'issues': 'write',
                'actions': 'read', 'metadata': 'read'}}).encode(),
            headers={'Authorization': 'Bearer ' + jwt, 'Accept': 'application/vnd.github+json',
                     'Content-Type': 'application/json', 'User-Agent': 'TelmanFit-UX-AUTO'})
        try:
            with urllib.request.urlopen(request, timeout=30, context=ssl.create_default_context()) as response:
                data = json.load(response)
        except (urllib.error.URLError, OSError, ValueError) as exc:
            raise Blocked('App installation token refresh failed; жодного fallback credential') from exc
        self.cached = data['token']
        self.expires = datetime.datetime.fromisoformat(data['expires_at'].replace('Z', '+00:00')).timestamp()
        if self.expires <= self.clock() + 120:
            raise Blocked('Installation token має надто короткий lifetime')
        return self.cached
