"""GitHub skipped placeholders must never reduce measured CI consumption."""
import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location("measure", Path(__file__).resolve().parents[2] / "scripts/ci/measure.py")
measure = importlib.util.module_from_spec(spec)
spec.loader.exec_module(measure)


class MeasurementTests(unittest.TestCase):
    def test_real_execution_and_skipped_placeholder(self):
        self.assertEqual(measure.seconds("2026-10-24T15:00:00Z", "2026-10-24T15:01:03Z"), 63)
        self.assertEqual(measure.seconds("2026-10-24T15:00:01Z", "2026-10-24T15:00:00Z"), 0)
        self.assertIsNone(measure.seconds("2026-10-24T15:00:00Z", None))
