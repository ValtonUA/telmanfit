"""Справжній Chromium probe на synthetic HTML; це НЕ UX acceptance застосунку."""
import argparse
import json
import shutil
import http.server
import functools
import threading
from pathlib import Path
from playwright.sync_api import sync_playwright


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--head', required=True)
    parser.add_argument('--directory', default='/out')
    args = parser.parse_args()
    out = Path(args.directory)
    out.mkdir(parents=True, exist_ok=True)
    page_file = out / 'probe.html'
    page_file.write_text('''<!doctype html><meta name="viewport" content="width=device-width"><title>UX-AUTO synthetic probe</title>
<style>body{background:#fafafa;color:#151515} @media(prefers-color-scheme:dark){body{background:#151515;color:#fafafa}}</style>
<h1>UX-AUTO synthetic probe</h1><button onclick="history.pushState({},'', '#next');document.querySelector('output').textContent='next'">Next</button>
<output>home</output><script>window.documentIdentity=crypto.randomUUID();onpopstate=()=>document.querySelector('output').textContent=location.hash?'next':'home';</script>''')
    screenshots = []
    matrix = []
    handler = functools.partial(http.server.SimpleHTTPRequestHandler, directory=str(out))
    server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True, executable_path=shutil.which('chromium'))
        for width in (1440, 390, 320):
            for theme in ('light', 'dark'):
                page = browser.new_page(viewport={'width': width, 'height': 800}, color_scheme=theme)
                page.goto(f'http://127.0.0.1:{server.server_port}/probe.html')
                identity = page.evaluate('window.documentIdentity')
                page.get_by_role('button', name='Next').click()
                assert page.locator('output').inner_text() == 'next'
                assert page.evaluate('window.documentIdentity') == identity
                page.go_back()
                page.wait_for_function("document.querySelector('output').textContent === 'home'")
                assert page.evaluate('window.documentIdentity') == identity
                expected = 'rgb(21, 21, 21)' if theme == 'dark' else 'rgb(250, 250, 250)'
                assert page.evaluate('getComputedStyle(document.body).backgroundColor') == expected
                assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
                name = f'{width}-{theme}.png'
                page.screenshot(path=str(out / name))
                screenshots.append(name)
                matrix.append(f'{width}/{theme}')
                page.close()
        browser.close()
    server.shutdown()
    server.server_close()
    checks = ['probe_artifacts', 'probe_navigation', 'probe_theme']
    (out / 'browser.json').write_text(json.dumps({'head': args.head, 'status': 'success', 'checks': checks,
        'assertions': {check: 'passed' for check in checks}, 'matrix': matrix, 'screenshots': screenshots}))


if __name__ == '__main__':
    main()
