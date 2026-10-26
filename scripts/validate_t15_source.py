#!/usr/bin/env python3
"""Read-only validation for the owner's unnormalized T1.5 data, not a database seeder."""
from __future__ import annotations

import json
import sys
from pathlib import Path

SOURCE = Path(__file__).resolve().parents[1] / "docs/reference-data/t1-5-source.json"
SHEETS = {
    "ingredient_categories": (["ingredient_category_id", "name", "description"], 13, 0x78A049ED),
    "recipe_categories": (["dish_category_id", "name", "description"], 5, 0xE60D87FB),
    "uoms": (["uom_id", "symbol", "name_1", "name_2_4", "name_5", "name_frac"], 19, 0xE962AD51),
    "kitchen_equipment": (["equipment_id", "name", "image_url", "category", "default_uom_id"], 38, 0x7BF936C4),
    "meal_types": (["meal_type_id", "name", "order_number"], 9, 0x2D5139E8),
}
EQUIPMENT_CATEGORIES = {"cookware", "prepware", "device", "tool", "consumable"}


def digest(rows: list[list[object]]) -> int:
    s = "\n".join("\x1f".join(str(value) if value is not None else "" for value in row) for row in rows)
    h = 2166136261
    for ch in s:
        h = ((h ^ ord(ch)) * 16777619) & 0xFFFFFFFF
    return h


def validate(data: dict) -> list[str]:
    problems = []
    sheets = data.get("sheets", {})
    if set(sheets) != set(SHEETS):
        problems.append(f"sheet names mismatch: expected {sorted(SHEETS)}, got {sorted(sheets)}")
    for name, (columns, expected_count, expected_digest) in SHEETS.items():
        sheet = sheets.get(name)
        if not isinstance(sheet, dict):
            continue
        rows = sheet.get("rows", [])
        if sheet.get("columns") != columns:
            problems.append(f"{name}: source column names/order changed")
        if len(rows) != expected_count:
            problems.append(f"{name}: expected {expected_count} rows, got {len(rows)}")
        if any(len(row) != len(columns) for row in rows):
            problems.append(f"{name}: malformed row length")
            continue
        if digest(rows) != expected_digest:
            problems.append(f"{name}: source data differs from reviewed uploaded Excel")
        ids = [row[0] for row in rows]
        if len(ids) != len(set(ids)):
            problems.append(f"{name}: duplicate source IDs")
        if any(not str(row[0]).strip() or not str(row[1]).strip() for row in rows):
            problems.append(f"{name}: missing source key/name")
    units = {row[0] for row in sheets.get("uoms", {}).get("rows", [])}
    items = sheets.get("kitchen_equipment", {}).get("rows", [])
    for equipment in items:
        if len(equipment) != 5:
            continue
        key, _, link, category, default_uom = equipment
        if category not in EQUIPMENT_CATEGORIES:
            problems.append(f"kitchen_equipment[{key}]: unknown category: {category}")
        if default_uom not in units:
            problems.append(f"kitchen_equipment[{key}]: unknown default_uom_id: {default_uom}")
        if link != f"https://assets.telmanfit.com.ua/equipment/{key}.webp":
            problems.append(f"kitchen_equipment[{key}]: unexpected image URL")
    return problems


def main() -> int:
    data = json.loads(SOURCE.read_text(encoding="utf-8"))
    problems = validate(data)
    if problems:
        for problem in problems:
            print("T1.5 ERROR:", problem, file=sys.stderr)
        return 1
    print("T1.5 raw source: VALID; 5 worksheets, 84 records; original cell content preserved.")
    print("NOT a seeder. Await owner decisions on UOM 'gram' vs 'g', missing 'serving'/kind,")
    print("declensions, category slugs/descriptions, equipment image/default UOM and category names.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
