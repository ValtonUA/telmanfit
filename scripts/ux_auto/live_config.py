"""Числові ліміти та довірений config; секрети тут не зберігаються."""
import json
import re
from pathlib import Path

from scripts.ux_auto.coordinator import Blocked, REPOSITORY, digest, trusted_spec_sha


def sha(value):
    if not isinstance(value, str) or not re.fullmatch(r'[0-9a-f]{40}', value):
        raise Blocked('Потрібен повний commit SHA')
    return value


def load(path):
    config = json.loads(Path(path).read_text())
    if config.get('repository') != REPOSITORY or config.get('state_branch') != 'ux-auto-state':
        raise Blocked('Неправильний repository/state branch')
    sha(config['trusted_ref'])
    for name in ('installation_id', 'repository_id', 'sandbox_repository_id'):
        if type(config.get(name)) is not int or config[name] <= 0:
            raise Blocked(f'Потрібен реальний {name}')
    for field in ('worker_image', 'proxy_image', 'postgres_image'):
        if not re.fullmatch(r'[a-zA-Z0-9./:_-]+@sha256:[0-9a-f]{64}', config[field]):
            raise Blocked(f'Потрібен digest-pinned {field}')
    for name, maximum in {'requests': 20, 'input_bytes': 500000, 'output_tokens': 32000,
                          'ci_wait_seconds': 3600, 'invocation_seconds': 1800, 'run_seconds': 21600,
                          'total_micro_usd': 100000000, 'daily_micro_usd': 100000000,
                          'input_micro_usd_per_million': 1000000000,
                          'output_micro_usd_per_million': 1000000000}.items():
        n = config['limits'].get(name)
        if type(n) is not int or not 0 < n <= maximum:
            raise Blocked(f'Некоректний числовий ліміт {name}')
    if not re.fullmatch(r'[a-zA-Z0-9._-]{1,80}', config['model']):
        raise Blocked('Некоректний model')
    if config.get('spec_sha') != trusted_spec_sha():
        raise Blocked('Spec digest не збігається')
    if config.get('sandbox_repository') == REPOSITORY or not re.fullmatch(
            r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', config.get('sandbox_repository', '')):
        raise Blocked('Потрібен окремий ізольований sandbox repository')
    return config


def identity(config):
    return digest({k: v for k, v in config.items() if k not in {'enabled', 'identity'}})


def reservation(config):
    limits = config['limits']
    # Верхня межа: один UTF-8 byte вважаємо одним input token; cached discount не враховуємо.
    per_call = (limits['input_bytes'] * limits['input_micro_usd_per_million']
                + limits['output_tokens'] * limits['output_micro_usd_per_million'] + 999999) // 1000000
    return limits['requests'] * per_call


def authorize(config, record):
    if config.get('enabled') is not True or record.get('activation') != identity(config):
        raise Blocked('LIVE_DISABLED: немає окремої активації для цього config')
    receipt = record.get('sandbox') or {}
    if (receipt.get('config_sha') != identity(config) or receipt.get('status') != 'success'
            or receipt.get('repository') != config['sandbox_repository']
            or not receipt.get('ci') or not receipt.get('review') or not receipt.get('browser')
            or not receipt.get('merge_sha')):
        raise Blocked('LIVE_DISABLED: немає підтвердженого sandbox vertical slice')
