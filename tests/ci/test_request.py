"""Trigger rejects unsettled CI and changed heads before mutating labels."""
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts/ci"))
import request_full
from test_gate import green


class RequestTests(unittest.TestCase):
    def exercise(self, snapshot, current=None):
        current = current or dict(snapshot["pr"], labels=[{"name": "ci:full"}])
        with patch.object(request_full, "collect", return_value=snapshot), patch.object(request_full, "api", return_value=[current]), patch.object(request_full.subprocess, "run") as mutate:
            result = request_full.request("ValtonUA/telmanfit", 42)
            self.assertTrue(result["requested"])
            self.assertEqual(mutate.call_count, 2)
            self.assertIn("DELETE", mutate.call_args_list[0].args[0])
            self.assertIn("POST", mutate.call_args_list[1].args[0])

    def test_settled_head_can_request_new_full(self):
        self.exercise(green())

    def test_failed_full_can_be_requested_again(self):
        s = green()
        s["runs"]["full.yml"][0]["conclusion"] = "failure"
        self.exercise(s)

    def test_missing_pending_and_failed_fast_prevent_label_mutation(self):
        for case in ("pending-full", "missing-full", "failed-fast", "new-head"):
            s = green()
            if case == "pending-full":
                s["runs"]["full.yml"][0]["status"] = "queued"
            elif case == "missing-full":
                s["runs"]["full.yml"] = []
            elif case == "failed-fast":
                s["runs"]["fast.yml"][0]["conclusion"] = "failure"
            else:
                s["pr"]["head"]["sha"] = "d" * 40
            with self.subTest(case=case), patch.object(request_full, "collect", return_value=s), patch.object(request_full.subprocess, "run") as mutate:
                with self.assertRaises(ValueError):
                    request_full.request("ValtonUA/telmanfit", 42)
                mutate.assert_not_called()

    def test_changed_head_during_request_never_posts_label(self):
        s = green()
        current = dict(s["pr"], head=dict(s["pr"]["head"], sha="e" * 40))
        with patch.object(request_full, "collect", return_value=s), patch.object(request_full, "api", return_value=[current]), patch.object(request_full.subprocess, "run") as mutate:
            with self.assertRaises(ValueError):
                request_full.request("ValtonUA/telmanfit", 42)
            mutate.assert_not_called()
