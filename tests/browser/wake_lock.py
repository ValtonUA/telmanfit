"""Регресія відкладених Screen Wake Lock promises у справжньому React UI."""
from playwright.sync_api import expect

WAKE_LOCK_PROBE = """(() => {
    const probe = window.__wakeProbe = { calls: 0, active: 0, maxActive: 0, visible: true, pending: [], deferRelease: false, releases: [] };
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => probe.visible ? 'visible' : 'hidden' });
    Object.defineProperty(navigator, 'wakeLock', { configurable: true, value: {
        request: () => { probe.calls++; return new Promise(resolve => probe.pending.push(resolve)); }
    }});
    probe.resolve = () => {
        const resolve = probe.pending.shift();
        if (!resolve) throw new Error('Відсутній pending wake request');
        const listeners = [];
        const lock = { released: false, addEventListener: (name, listener) => listeners.push(listener),
            release: () => new Promise(done => {
                const finish = () => { if (!lock.released) { lock.released = true; probe.active--; listeners.forEach(listener => listener()); } done(); };
                if (probe.deferRelease) probe.releases.push(finish); else finish();
            })
        };
        probe.active++; probe.maxActive = Math.max(probe.maxActive, probe.active); resolve(lock);
    };
    probe.finishRelease = () => probe.releases.shift()();
    probe.visibility = visible => { probe.visible = visible; document.dispatchEvent(new Event('visibilitychange')); };
})()"""


def verify_wake_lock(page):
    wake = page.get_by_role('checkbox', name='Не вимикати екран')
    wake.check()
    page.wait_for_function('() => window.__wakeProbe.calls === 1')
    page.evaluate("document.dispatchEvent(new Event('visibilitychange')); document.dispatchEvent(new Event('visibilitychange')); window.dispatchEvent(new Event('focus'))")
    assert page.evaluate('window.__wakeProbe.calls') == 1, 'Паралельні запити Wake Lock'
    wake.uncheck()
    page.evaluate('window.__wakeProbe.resolve()')
    page.wait_for_function('() => window.__wakeProbe.active === 0')
    expect(wake).not_to_be_checked()

    wake.check()
    page.wait_for_function('() => window.__wakeProbe.calls === 2')
    page.evaluate('window.__wakeProbe.resolve()')
    page.wait_for_function('() => window.__wakeProbe.active === 1')
    page.evaluate("document.dispatchEvent(new Event('visibilitychange')); window.dispatchEvent(new Event('focus'))")
    assert page.evaluate('window.__wakeProbe.calls') == 2
    page.evaluate('window.__wakeProbe.deferRelease = true; window.__wakeProbe.visibility(false); window.__wakeProbe.visibility(true)')
    assert page.evaluate('window.__wakeProbe.calls === 2 && window.__wakeProbe.active === 1')
    page.evaluate('window.__wakeProbe.finishRelease(); window.__wakeProbe.deferRelease = false')
    page.wait_for_function('() => window.__wakeProbe.calls === 3 && window.__wakeProbe.active === 0')
    page.evaluate('window.__wakeProbe.resolve()')
    page.wait_for_function('() => window.__wakeProbe.active === 1')
    wake.uncheck()
    page.wait_for_function('() => window.__wakeProbe.active === 0')

    wake.check()
    page.wait_for_function('() => window.__wakeProbe.calls === 4')
    page.evaluate('window.__wakeProbe.visibility(false); window.__wakeProbe.visibility(true)')
    assert page.evaluate('window.__wakeProbe.calls') == 4
    page.evaluate('window.__wakeProbe.resolve()')
    page.wait_for_function('() => window.__wakeProbe.calls === 5 && window.__wakeProbe.active === 0')
    page.evaluate('window.__wakeProbe.resolve()')
    page.wait_for_function('() => window.__wakeProbe.active === 1')
    wake.uncheck()
    page.wait_for_function('() => window.__wakeProbe.active === 0')

    wake.check()
    page.wait_for_function('() => window.__wakeProbe.calls === 6')
    page.get_by_role('button', name='← До вибору сесій').click()
    page.evaluate('window.__wakeProbe.resolve()')
    page.wait_for_function('() => window.__wakeProbe.active === 0')
    assert page.evaluate('window.__wakeProbe.maxActive') == 1
    page.locator('.cook-session-choice').first.click()
    expect(page.get_by_role('button', name='Почати', exact=True)).to_be_visible()
