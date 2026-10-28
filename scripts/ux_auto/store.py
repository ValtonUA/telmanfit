"""Durable GitHub Contents CAS, lease, pause, fencing і консервативний spend ledger."""
import base64
from copy import deepcopy
import datetime
import json
import time
import uuid

from scripts.ux_auto.coordinator import Blocked, initial, digest
from scripts.ux_auto.live_config import reservation


class Session:
    def __init__(self, api, config, clock=time.time, maintenance=False):
        self.api, self.config, self.clock = api, config, clock
        self.maintenance = maintenance
        self.owner = uuid.uuid4().hex
        self.revision = None
        self.value = None
        self.objects = {}
        self.path = 'coordinator.json'

    def read(self):
        document = self.api.content(self.path, self.config['state_branch'])
        value = json.loads(base64.b64decode(document['content']))
        for name in ('operations', 'stages', 'reports'):
            for key, pointer in value[name].items():
                if isinstance(pointer, dict) and set(pointer) == {'object'}:
                    ref = pointer['object']
                    if ref not in self.objects:
                        obj = self.api.content(f'objects/{ref}.json', self.config['state_branch'])
                        data = json.loads(base64.b64decode(obj['content']))
                        if digest(data) != ref:
                            raise Blocked('Corrupt durable artifact')
                        self.objects[ref] = data
                    value[name][key] = deepcopy(self.objects[ref])
        if value['config_sha'] != self.config['identity']:
            raise Blocked('Durable state прив’язаний до іншого config')
        return document['sha'], value

    def save(self, value):
        # Contents API sha — optimistic CAS. На конфлікті не переотримуємо lease автоматично.
        wire = deepcopy(value)
        for name in ('operations', 'stages', 'reports'):
            for key, data in value[name].items():
                ref = digest(data)
                if ref not in self.objects:
                    if len(json.dumps(data).encode()) > 700000:
                        raise Blocked('Durable artifact size budget exceeded')
                    try:
                        self.api.put(f'objects/{ref}.json', self.config['state_branch'], data, None)
                    except Blocked:
                        obj = self.api.content(f'objects/{ref}.json', self.config['state_branch'])
                        if digest(json.loads(base64.b64decode(obj['content']))) != ref:
                            raise
                    self.objects[ref] = deepcopy(data)
                wire[name][key] = {'object': ref}
        revision = self.api.put(self.path, self.config['state_branch'], wire, self.revision)
        self.revision, self.value = revision, deepcopy(value)

    def acquire(self):
        self.revision, value = self.read()
        lease = value.get('lease', {})
        if lease.get('expires', 0) > self.clock():
            raise Blocked('Lease уже зайнято іншим controller')
        if value.get('paused') and not self.maintenance:
            raise Blocked('Конвеєр на паузі')
        value['lease'] = {'owner': self.owner, 'expires': self.clock() + self.config['limits']['invocation_seconds'] + 120}
        self.save(value)

    def guard(self):
        revision, value = self.read()
        lease = value.get('lease', {})
        if (revision != self.revision or lease.get('owner') != self.owner
                or lease.get('expires', 0) <= self.clock() or (value.get('paused') and not self.maintenance)):
            raise Blocked('Lease втрачено, state змінено або пауза; side effect заборонено')
        # Продовжуємо тільки власний lease, CAS перед кожним effect.
        value['lease']['expires'] = self.clock() + self.config['limits']['invocation_seconds'] + 120
        self.save(value)

    def update(self, change):
        self.guard()
        value = deepcopy(self.value)
        change(value)
        self.save(value)

    def operation(self, key, update):
        self.update(lambda value: value['operations'].setdefault(key, {}).update(update))

    def reserve(self, key):
        day = datetime.datetime.fromtimestamp(self.clock(), datetime.timezone.utc).date().isoformat()
        cost = reservation(self.config)
        def mutate(value):
            if key in value['spend']['reservations']:
                raise Blocked('Invocation уже зарезервовано; після crash не повторювати API наосліп')
            spend = value['spend']
            daily = spend['days'].get(day, 0)
            if (spend['total'] + cost > self.config['limits']['total_micro_usd']
                    or daily + cost > self.config['limits']['daily_micro_usd']):
                raise Blocked('API spend budget вичерпано')
            spend['total'] += cost
            spend['days'][day] = daily + cost
            spend['reservations'][key] = {'micro_usd': cost, 'day': day}
        self.update(mutate)

    def release(self):
        self.guard()
        value = deepcopy(self.value)
        value['lease'] = {}
        self.save(value)


def seed(config):
    state = initial(config['spec_sha'])
    state['mode'] = 'live'
    return {'config_sha': config['identity'], 'lease': {}, 'paused': True,
            'activation': None, 'sandbox': None, 'checkpoint': state,
            'operations': {}, 'stages': {}, 'reports': {},
            'spend': {'total': 0, 'days': {}, 'reservations': {}}}
