#!/usr/bin/env python3
"""Select focused PostgreSQL/browser groups conservatively from the PR diff."""
from __future__ import annotations

import os
from pathlib import PurePosixPath
import re
import subprocess
import sys

ALL = ("recipe", "baseline", "b5", "b3")
SECURITY_PREFIXES = (
    "app/Services/Auth/",
    "app/Http/Middleware/",
    "app/Providers/Filament/",
    "tests/pg/auth",
    "tests/browser/auth",
    "experiments/auth",
)
RECIPE_PREFIXES = (
    "tests/pg/cooking",
    "app/Services/Cooking/",
    "tests/Postgres/CookingTemplatesTest.php",
    "app/Services/Catalog/",
    "app/Console/Commands/MatchR2Images.php",
    "config/r2.php",
    "tests/Postgres/R2ImageMatcherTest.php",
    "app/Services/Imports/",
    "app/Console/Commands/ImportNutritionXlsx.php",
    "resources/import/",
    "tests/Postgres/NutritionImportTest.php",
    "config/recipes.php",
    "app/Filament/Resources/MealPlans/",
    "app/Filament/Resources/Clients/Pages/ManageMealPlanAssignments.php",
    "app/Services/MealPlans/",
    "tests/MealPlans/",
    "tests/pg/meal-plan",
    "tests/browser/meal-plan",
    "tests/browser/recipe",
)


def select_groups(paths: list[str]) -> tuple[str, ...]:
    selected = set()  # Do not provision PostgreSQL/Chromium without a relevant diff.
    for path in paths:
        normalized = PurePosixPath(path).as_posix()
        if (normalized.startswith(SECURITY_PREFIXES)
                or normalized in {"app/Models/User.php", "routes/auth.php", "config/auth.php"}):
            selected.update(("baseline", "b5", "b3"))
        if normalized.startswith(RECIPE_PREFIXES):
            selected.add("recipe")
        if normalized.startswith("database/"):
            selected.update(ALL)
        if normalized.startswith(("tests/pg/meal-plan", "tests/pg/recipe", "tests/pg/cooking")):
            selected.add("recipe")
        elif normalized.startswith("tests/pg/auth"):
            selected.update(("baseline", "b5", "b3"))
        elif normalized.startswith(("tests/pg/",)):
            selected.update(ALL)
        elif normalized == "tests/browser/test_readiness.py":
            selected.add("baseline")
        elif normalized.startswith(("tests/browser/auth",)):
            selected.update(("baseline", "b5", "b3"))
        elif (normalized in {"tests/browser/food_catalog.py", "tests/browser/meal_plans.py"}
              or normalized.startswith(("tests/browser/meal-plan", "tests/browser/recipe"))):
            selected.add("recipe")
        elif normalized.startswith("tests/browser/"):
            selected.update(ALL)
        if normalized in {"composer.json", "composer.lock", "package-lock.json",
                          "client/package-lock.json", "Dockerfile", "compose.yaml",
                          "docker-compose.yml"}:
            selected.update(("baseline", "b5", "b3"))
    return tuple(group for group in ALL if group in selected)


def main() -> int:
    base = os.environ.get("BASE_SHA", "")
    head = os.environ.get("HEAD_SHA", "")
    if not re.fullmatch(r"[0-9a-f]{40}", base) or not re.fullmatch(r"[0-9a-f]{40}", head):
        print("BASE_SHA and HEAD_SHA must be full commit IDs", file=sys.stderr)
        return 2
    try:
        raw = subprocess.check_output(
            ["git", "diff", "--name-only", "--diff-filter=ACDMRT", f"{base}...{head}"],
            text=True,
        )
    except subprocess.CalledProcessError as error:
        print(f"Cannot read PR diff: {error}", file=sys.stderr)
        return 2
    groups = select_groups(raw.splitlines())
    output = os.environ.get("GITHUB_OUTPUT")
    if not output:
        print("GITHUB_OUTPUT is required", file=sys.stderr)
        return 2
    with open(output, "a", encoding="utf-8") as handle:
        handle.write(f"groups={','.join(groups)}\n")
    print(f"Selected PostgreSQL/browser groups: {','.join(groups)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
