#!/usr/bin/env python3
"""Завантажує тільки pinned PHP-файли до нового приватного тимчасового каталогу."""
import base64
import hashlib
import json
from pathlib import Path
import sys
import urllib.request

root = Path(sys.argv[1])
root.mkdir(mode=0o700, parents=True, exist_ok=False)
for package in json.loads(Path(__file__).with_name('sources.json').read_text()):
    for item in package['paths']:
        url = f"https://api.github.com/repos/{package['repo']}/git/blobs/{item['sha']}"
        request = urllib.request.Request(url, headers={'Accept': 'application/vnd.github+json', 'User-Agent': 'TelmanFit-AUTH1-spike'})
        with urllib.request.urlopen(request, timeout=25) as response:
            blob = json.load(response)
        content = base64.b64decode(blob['content'])
        actual = hashlib.sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest()
        if actual != item['sha']:
            raise RuntimeError(f"Git blob integrity mismatch: {item['path']}")
        path = root / package['repo'].split('/')[1] / item['path']
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(content)
print('Pinned upstream sources: OK')
