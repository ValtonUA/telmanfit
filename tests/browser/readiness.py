"""Actual render readiness; networkidle не є доказом завершення DOM morph."""
import time
from urllib.parse import urlsplit

metrics = {'calls': 0, 'seconds': 0.0}

TRACKER = """document.addEventListener('livewire:init', () => {
    const state = {ready: true, pending: 0, started: 0, rendered: 0, failed: false};
    window.__ciLivewire = state;
    Livewire.hook('commit', ({succeed, fail}) => {
        state.pending++;
        state.started++;
        let finished = false;
        const finish = () => {
            if (finished) return;
            finished = true;
            queueMicrotask(() => { state.pending--; });
        };
        // У pinned Livewire4.4.8 succeed викликається після onRender.
        succeed(() => { state.rendered++; finish(); });
        fail(() => { state.failed = true; finish(); });
    });
});"""


def livewire_ready(page):
    started = time.perf_counter()
    # Відсутній hook або невдалий commit не можуть стати зеленим fallback.
    page.wait_for_function("""() => window.__ciLivewire?.ready === true
        && window.__ciLivewire.pending === 0 && !window.__ciLivewire.failed""")
    metrics['calls'] += 1
    metrics['seconds'] += time.perf_counter() - started


def livewire_click(page, button, method):
    """Чекати commit натиснутої дії, а не фоновий HTTP notifications."""
    livewire_ready(page)

    def action_response(response):
        request = response.request
        if request.method != 'POST' or not urlsplit(response.url).path.endswith('/livewire/update'):
            return False
        data = request.post_data_json
        return any(call.get('method') == method
                   for component in data.get('components', [])
                   for call in component.get('calls', []))

    with page.expect_response(action_response) as response:
        button.click()
    assert response.value.status == 200
    # succeed/onRender у tracker доводить завершення body та DOM morph.
    livewire_ready(page)
