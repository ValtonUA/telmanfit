#!/usr/bin/env python3
"""Select focused PostgreSQL/browser groups conservatively from the PR diff."""
from __future__ import annotations

import os
from pathlib import PurePosixPath
import re
import subprocess
import sys

ALL = ("day", "assignment", "recipe", "baseline", "b5", "b3")
SHARED_BROWSER_PATHS = {
    "tests/browser/meal_plans.py",
    "tests/browser/recipe-batch-in-context.sh",
}
DAY_PATHS = {
    "tests/browser/day_editor.py",
    "tests/browser/ux3-only.py",
    "tests/pg/day-bootstrap.php",
    "resources/views/filament/meal-plans/day.blade.php",
    "resources/views/filament/meal-plans/week.blade.php",
    "app/Filament/Resources/MealPlans/Pages/EditMealPlanDay.php",
    "tests/Postgres/MealPlanDayEditorTest.php",
    "tests/pg/meal-plan-day-concurrency.php",
}
ASSIGNMENT_PATHS = {
    "app/Services/MealPlans/MealPlanAssigner.php",
    "app/Filament/Resources/Clients/Pages/ManageMealPlanAssignments.php",
    "tests/Postgres/MealPlanAssignmentTest.php",
    "tests/pg/meal-plan-assignment-concurrency.php",
    "tests/browser/meal_plan_assignments.py",
    "tests/browser/meal-plan-assignment-in-context.sh",
}
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
    "app/Filament/Pages/CookingTemplates.php",
    "resources/views/filament/cooking/",
    "tests/Postgres/CookingTemplatesTest.php",
    "tests/Auth4/ClientCookingTest.php",
    "app/Services/Catalog/",
    "app/Console/Commands/MatchR2Images.php",
    "config/r2.php",
    "tests/Postgres/R2ImageMatcherTest.php",
    "tests/Postgres/R2ImageMatcherIsolatedTest.php",
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
        prior = selected
        selected = set()
        normalized = PurePosixPath(path).as_posix()
        if (normalized.startswith(("app/Http/Middleware/", "app/Providers/Filament/", "app/Services/Auth/"))
                or normalized in {"app/Models/User.php", "routes/auth.php", "config/auth.php"}):
            prior.update(ALL)
            selected = prior
            continue
        if normalized.startswith(("tests/ci/", "scripts/ci/")) or normalized == "scripts/docs_guard.py":
            selected = prior
            continue
        if (("/" not in normalized and not normalized.lower().endswith(".md")
             and normalized not in {"LICENSE", ".gitignore", ".gitattributes"})
                or normalized.startswith("scripts/")):
            prior.update(ALL)
            selected = prior
            continue
        if normalized in ASSIGNMENT_PATHS:
            prior.add("assignment")
            selected = prior
            continue
        if normalized in SHARED_BROWSER_PATHS:
            prior.update(("day", "recipe"))
            selected = prior
            continue
        if normalized in DAY_PATHS:
            selected.add("day")
            prior.update(selected)
            selected = prior
            continue
        if normalized == "resources/css/filament/admin/theme.css":
            selected.update(("day", "recipe", "baseline"))
            prior.update(selected)
            selected = prior
            continue
        if (normalized.startswith(SECURITY_PREFIXES)
                or normalized in {"app/Models/User.php", "routes/auth.php", "config/auth.php"}):
            selected.update(("baseline", "b5", "b3"))
        if normalized.startswith(RECIPE_PREFIXES):
            selected.add("recipe")
        if (normalized.startswith(("app/Services/Cooking/", "app/Services/Catalog/", "tests/pg/cooking", "tests/browser/cooking", "tests/browser/recipe-client", "resources/views/filament/cooking/"))
                or normalized in {"app/Filament/Pages/CookingTemplates.php", "tests/Postgres/CookingTemplatesTest.php", "tests/Auth4/ClientCookingTest.php", "app/Services/MealPlans/MealItemWriter.php", "app/Services/MealPlans/MealPlanStructure.php", "app/Services/MealPlans/MealPlanPublisher.php"}):
            selected.add("baseline")
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
              or normalized.startswith(("tests/browser/meal-plan", "tests/browser/recipe", "tests/browser/cooking"))):
            selected.add("recipe")
        elif normalized.startswith("tests/browser/"):
            selected.update(ALL)
        if normalized in {"composer.json", "composer.lock", "package.json", "client/package.json", "package-lock.json",
                          "client/package-lock.json", "Dockerfile", "compose.yaml",
                          "docker-compose.yml"}:
            selected.update(ALL)
        if normalized.startswith(("app/", "resources/", "config/", "tests/", "routes/", "client/", "public/")) and not selected:
            selected.update(ALL)
        prior.update(selected)
        selected = prior
    return tuple(group for group in ALL if group in selected)


def main() -> int:
    base = os.environ.get("BASE_SHA", "")
    head = os.environ.get("HEAD_SHA", "")
    if not re.fullmatch(r"[0-9a-f]{40}", base) or not re.fullmatch(r"[0-9a-f]{40}", head):
        print("BASE_SHA and HEAD_SHA must be full commit IDs", file=sys.stderr)
        return 2
    try:
        checked_out = subprocess.check_output(["git", "rev-parse", "HEAD"], text=True).strip()
        if checked_out != head:
            print("Checked out HEAD does not match HEAD_SHA", file=sys.stderr)
            return 2
        raw = subprocess.check_output(
            ["git", "diff", "--no-renames", "--name-only", "--diff-filter=ACDMRT", f"{base}...{head}"],
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
