"""Процесний bytecode cache не переносить стан запитів і не приховує зміни PHP."""
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import unittest
from urllib.request import urlopen

ROOT = Path(__file__).resolve().parents[2]
SERVER = ROOT / "tests/browser/php-server.sh"


class PhpServerTests(unittest.TestCase):
    def test_refuses_non_test_context_and_non_loopback(self):
        env = dict(os.environ, APP_ENV="production", TELMANFIT_PG_TEST="1")
        self.assertNotEqual(subprocess.run(["bash", str(SERVER), "127.0.0.1:8090"], env=env).returncode, 0)
        env["APP_ENV"] = "testing"
        self.assertNotEqual(subprocess.run(["bash", str(SERVER), "0.0.0.0:8090"], env=env).returncode, 0)
        env["TELMANFIT_PG_TEST"] = "0"
        self.assertNotEqual(subprocess.run(["bash", str(SERVER), "127.0.0.1:8090"], env=env).returncode, 0)

    def test_cache_hits_fresh_request_state_revalidation_and_restart(self):
        env = dict(os.environ, APP_ENV="testing", TELMANFIT_PG_TEST="1")
        with tempfile.TemporaryDirectory() as directory:
            router = Path(directory) / "router.php"
            source = '''<?php
            $GLOBALS['request_count'] = ($GLOBALS['request_count'] ?? 0) + 1;
            echo json_encode(['value' => 'before', 'state' => $GLOBALS['request_count'],
                'pid' => getmypid(), 'cache' => opcache_get_status(false)]);
            '''
            router.write_text(source)
            with socket.socket() as listener:
                listener.bind(("127.0.0.1", 0))
                port = listener.getsockname()[1]
            def request():
                with urlopen(f"http://127.0.0.1:{port}", timeout=2) as response:
                    return json.load(response)
            def start():
                process = subprocess.Popen(["bash", str(SERVER), f"127.0.0.1:{port}", str(router)],
                                           env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                self.addCleanup(lambda: process.poll() is None and process.kill())
                for _ in range(100):
                    if process.poll() is not None:
                        self.fail("Synthetic PHP server failed to start")
                    try:
                        return process, request()
                    except OSError:
                        time.sleep(0.02)
                self.fail("Synthetic PHP server readiness timeout")
            process, first = start()
            try:
                second = request()
                self.assertEqual(second['pid'], first['pid'])
                self.assertEqual(second['state'], 1)
                self.assertTrue(second['cache']['opcache_enabled'])
                self.assertGreater(second['cache']['opcache_statistics']['hits'],
                                   first['cache']['opcache_statistics']['hits'])
                router.write_text(source.replace("'before'", "'after'"))
                # Детермінований mtime без секундного sleep або stale cache.
                timestamp = router.stat().st_mtime + 2
                os.utime(router, (timestamp, timestamp))
                self.assertEqual(request()['value'], 'after')
            finally:
                process.terminate()
                process.wait(timeout=5)
            restarted, fresh = start()
            try:
                self.assertNotEqual(fresh['pid'], first['pid'])
                self.assertEqual(fresh['state'], 1)
                self.assertEqual(fresh['value'], 'after')
                self.assertEqual(fresh['cache']['opcache_statistics']['hits'], 0)
            finally:
                restarted.terminate()
                restarted.wait(timeout=5)
