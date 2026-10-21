#!/usr/bin/env python3
"""Ізольований Composer SAT на зафіксованих upstream manifests + основному lock."""
import argparse
import json
import os
from pathlib import Path
import subprocess
import tempfile

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--online', action='store_true', help='Звичайний Packagist resolution (потребує мережі)')
args = parser.parse_args()
root = Path(__file__).resolve().parents[2]
lock = json.loads((root / 'composer.lock').read_text())
project_manifest = json.loads((root / 'composer.json').read_text())
requirements = {**project_manifest['require'], 'php': '^8.3', 'laravel/framework': '13.38.0', 'filament/filament': '5.10.2', 'laravel/sanctum': '4.3.3', 'laravel/fortify': '1.41.0'}
packages = lock['packages'] + lock['packages-dev']
for entry in json.loads(Path(__file__).with_name('manifests.json').read_text()):
    manifest = entry['manifest']
    package = {key: manifest[key] for key in ['name', 'require', 'conflict', 'replace', 'provide', 'suggest', 'license'] if key in manifest}
    package['version'] = entry['ref'].removeprefix('v')
    packages.append(package)
project = {'name': 'telmanfit/auth1-resolution', 'require': requirements, 'require-dev': project_manifest['require-dev'], 'version': '1.0.0', 'minimum-stability': 'stable', 'config': {'allow-plugins': False}}
if not args.online:
    project['repositories'] = [{'type': 'package', 'package': packages}, {'packagist.org': False}]
with tempfile.TemporaryDirectory(prefix='telmanfit-auth1-composer-') as path:
    Path(path, 'composer.json').write_text(json.dumps(project, indent=2) + '\n')
    result = subprocess.run(['composer', 'update', '--no-install', '--no-scripts', '--no-plugins', '--no-interaction', '--no-audit'], cwd=path, timeout=40, env={**os.environ, 'COMPOSER_CACHE_DIR': path + '/cache'})
    if result.returncode:
        raise SystemExit(result.returncode)
    resolved = json.loads(Path(path, 'composer.lock').read_text())
    selected = {p['name']: p['version'] for p in resolved['packages']}
    print(json.dumps({'mode': 'online' if args.online else 'offline-manifest-snapshot', 'packages': len(selected), 'packages_dev': len(resolved.get('packages-dev', [])), 'selected': {name: selected[name] for name in requirements if name != 'php'}}, indent=2))
