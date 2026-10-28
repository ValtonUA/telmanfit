"""Owner-armed durable continuation: scheduler не є новою авторизацією."""
import time

from scripts.ux_auto.coordinator import Blocked, summary, validate
from scripts.ux_auto.live_config import authorize

MAX_SLICES = 128
MAX_AGE = 7 * 86400


def eligible(config, value, now=None):
    now = time.time() if now is None else now
    try:
        authorize(config, value)
        validate(value['checkpoint'], config['spec_sha'])
    except (Blocked, KeyError, TypeError):
        return False
    control = value.get('continuation') or {}
    if type(control.get('slices')) is not int or type(control.get('started')) not in {int, float}:
        return False
    return (control.get('armed') is True and not value.get('paused')
            and not summary(value['checkpoint'])['complete']
            and not any(s['state'] == 'blocked' for s in value['checkpoint']['stages'])
            and control.get('slices', MAX_SLICES) < MAX_SLICES
            and 0 <= now - control.get('started', 0) <= MAX_AGE)


def arm(session):
    authorize(session.config, session.value)
    if session.value.get('continuation', {}).get('armed'):
        return
    def change(value):
        prior = value.get('continuation') or {}
        # Explicit resume не обнуляє витрачені slices або загальний календарний budget.
        value['continuation'] = {'armed': True, 'slices': prior.get('slices', 0),
                                 'started': prior.get('started', session.clock())}
    session.update(change)


def claim(session):
    if not eligible(session.config, session.value, session.clock()):
        raise Blocked('Continuation не авторизовано, завершено/blocked або budget вичерпано')
    session.update(lambda value: value['continuation'].__setitem__('slices', value['continuation']['slices'] + 1))


def disarm(session, reason):
    def stop(value):
        value.setdefault('continuation', {})['armed'] = False
        value['continuation']['reason'] = reason
    session.update(stop)
