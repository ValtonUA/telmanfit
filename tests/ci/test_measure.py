"""GitHub skipped placeholders must never reduce measured CI consumption."""
import importlib.util
import contextlib
import io
import json
from pathlib import Path
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("measure", Path(__file__).resolve().parents[2] / "scripts/ci/measure.py")
measure = importlib.util.module_from_spec(spec)
spec.loader.exec_module(measure)


class MeasurementTests(unittest.TestCase):
    def test_real_execution_and_skipped_placeholder(self):
        self.assertEqual(measure.seconds("2026-10-24T15:00:00Z", "2026-10-24T15:01:03Z"), 63)
        self.assertEqual(measure.seconds("2026-10-24T15:00:01Z", "2026-10-24T15:00:00Z"), 0)
        self.assertIsNone(measure.seconds("2026-10-24T15:00:00Z", None))

    def test_only_run_and_attempt_jobs_are_queried_never_billing(self):
        run = dict(run_attempt=2, html_url="https://github.com/run", name="Full PR",
                   event="pull_request", head_sha="a" * 40, conclusion="success")
        job = dict(started_at="2026-10-24T15:00:00Z", completed_at="2026-10-24T15:01:03Z", steps=[])
        output = io.StringIO()
        with patch.object(measure, "api", side_effect=[run, {"jobs": [job]}]) as api, \
             patch.object(measure.subprocess, "run", side_effect=AssertionError("No billing subprocess")), \
             patch("sys.argv", ["measure.py", "123"]), contextlib.redirect_stdout(output):
            measure.main()
        self.assertEqual([call.args[0] for call in api.call_args_list], [
            "repos/ValtonUA/telmanfit/actions/runs/123",
            "repos/ValtonUA/telmanfit/actions/runs/123/attempts/2/jobs?per_page=100&page=1",
        ])
        result = json.loads(output.getvalue())[0]
        self.assertFalse(result["billing"]["queried"])
        self.assertEqual(result["jobs"][0]["rounded_ubuntu_minutes_estimate"], 2)
