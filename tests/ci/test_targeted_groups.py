import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location(
    "targeted_groups", ROOT / "scripts/ci/targeted_groups.py"
)
targeted = importlib.util.module_from_spec(spec)
spec.loader.exec_module(targeted)
ALL = targeted.ALL
select_groups = targeted.select_groups


class TargetedGroupTests(unittest.TestCase):
    def test_r2_changes_run_catalog_postgresql_regressions(self):
        for path in ("app/Services/Catalog/R2ObjectLister.php",
                     "app/Console/Commands/MatchR2Images.php",
                     "config/r2.php", "tests/Postgres/R2ImageMatcherTest.php",
                     "tests/Postgres/R2ImageMatcherIsolatedTest.php"):
            with self.subTest(path=path):
                self.assertEqual(select_groups([path]), ("recipe",))

    def test_unrelated_changes_skip_the_heavy_postgresql_browser_runner(self):
        for paths in ([], ["README.md"], [".github/workflows/targeted.yml"],
                      ["scripts/ci/pre_merge.py"],
                      ["app/Http/Controllers/HealthController.php"]):
            with self.subTest(paths=paths):
                self.assertEqual(select_groups(paths), ())

    def test_recipe_and_auth_changes_expand_only_the_related_groups(self):
        self.assertEqual(select_groups(["app/Services/MealPlans/MealPlanAssigner.php"]),
                         ("recipe",))
        self.assertEqual(select_groups(["app/Services/Auth/LoginService.php"]),
                         ("baseline", "b5", "b3"))
        self.assertEqual(select_groups(["tests/pg/meal-plan-assignment.php"]),
                         ("recipe",))
        for path in ("tests/browser/meal_plans.py", "tests/browser/food_catalog.py"):
            with self.subTest(path=path):
                self.assertEqual(select_groups([path]), ("recipe",))

    def test_critical_schema_and_unclassified_harness_changes_fail_closed_to_all(self):
        self.assertEqual(select_groups(["database/migrations/2026_create.php"]), ALL)
        self.assertEqual(select_groups(["tests/pg/new_suite.sql"]), ALL)
        self.assertEqual(select_groups(["tests/browser/new_feature.py"]), ALL)
        self.assertEqual(select_groups(["tests/browser/test_readiness.py"]), ("baseline",))
        self.assertEqual(select_groups(["tests/browser/auth/login.py"]),
                         ("baseline", "b5", "b3"))

    def test_ci_workflow_changes_keep_scope_small_but_dependencies_cover_auth(self):
        self.assertEqual(select_groups([".github/workflows/targeted.yml"]), ())
        self.assertEqual(select_groups(["scripts/ci/pre_merge.py"]), ())
        self.assertEqual(select_groups(["composer.lock"]), ("baseline", "b5", "b3"))


if __name__ == "__main__":
    unittest.main()
