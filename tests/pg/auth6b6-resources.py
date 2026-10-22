import json
import re
from pathlib import Path
import sys

text = re.sub(r'\x1b\[[0-9;]*[A-Za-z]', '', Path(sys.argv[1]).read_text())
samples = [json.loads(s) for s in text.splitlines() if s.strip()]
assert samples, 'Missing PostgreSQL resource samples'

def size(text):
    for unit, multiplier in [('GiB', 2**30), ('MiB', 2**20), ('KiB', 2**10), ('GB', 10**9), ('MB', 10**6), ('kB', 1000), ('B', 1)]:
        if text.endswith(unit):
            return float(text[:-len(unit)]) * multiplier
    raise ValueError('Unknown resource size')

result = {'synthetic_only': True, 'measurement': 'Docker stats samples during entire B6 suite/concurrency/HTTP, not production sizing',
          'samples': len(samples), 'postgres_cpu_percent_max_sampled': max(float(s['CPUPerc'].rstrip('%')) for s in samples),
          'postgres_cgroup_memory_bytes_max_sampled': max(size(s['MemUsage'].split('/')[0].strip()) for s in samples),
          'block_io_last_sample': samples[-1]['BlockIO']}
path = Path('docs/evidence/auth6b-b6/resources.json')
path.write_text(json.dumps(result, indent=2) + '\n')
path.chmod(0o644)
