"""Unit checks for read-only imported T1.5 reference source validation."""
from __future__ import annotations

import copy
import json
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))
import validate_t15_source as v


class T15SourceValidationTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.data = json.loads(v.SOURCE.read_text(encoding="utf-8"))

    def test_original_source_matches_workbook(self) -> None:
        self.assertEqual(v.validate(self.data), [])

    def test_modified_cell_is_detected(self) -> None:
        changed = copy.deepcopy(self.data)
        changed["sheets"]["ingredient_categories"]["rows"][0][1] = "changed"
        self.assertTrue(any("differs from reviewed uploaded Excel" in x for x in v.validate(changed)))

    def test_unknown_equipment_uom_rejected(self) -> None:
        changed = copy.deepcopy(self.data)
        changed["sheets"]["kitchen_equipment"]["rows"][0][4] = "unknown_uom"
        self.assertTrue(any("unknown default_uom_id" in x for x in v.validate(changed)))


if __name__ == "__main__":
    unittest.main()
