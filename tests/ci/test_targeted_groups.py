import importlib.util
from pathlib import Path
import unittest
import os
import subprocess
import sys
import tempfile

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
                     "app/Services/Cooking/CookingTemplates.php",
                     "app/Filament/Pages/CookingTemplates.php",
                     "resources/views/filament/cooking/editor.blade.php",
                     "tests/browser/cooking.py",
                     "tests/browser/cooking-fixture.php",
                     "tests/Postgres/CookingTemplatesTest.php",
                     "tests/pg/cooking-concurrency.php",
                     "app/Console/Commands/MatchR2Images.php",
                     "config/r2.php", "tests/Postgres/R2ImageMatcherTest.php",
                     "tests/Postgres/R2ImageMatcherIsolatedTest.php"):
            with self.subTest(path=path):
                self.assertIn("recipe", select_groups([path]))
                if "Cooking" in path or "cooking" in path or path.startswith("app/Services/Catalog/"):
                    self.assertIn("baseline", select_groups([path]))

    def test_unrelated_changes_skip_the_heavy_postgresql_browser_runner(self):
        for paths in ([], ["README.md"], [".github/workflows/targeted.yml"],
                      ["scripts/ci/pre_merge.py"], ["tests/ci/test_targeted_groups.py"]):
            with self.subTest(paths=paths):
                self.assertEqual(select_groups(paths), ())

    def test_recipe_and_auth_changes_expand_only_the_related_groups(self):
        self.assertEqual(select_groups(["app/Services/MealPlans/MealPlanAssigner.php"]),
                         ("assignment",))
        self.assertEqual(select_groups(["app/Services/Auth/LoginService.php"]), ALL)
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

    def test_day_editor_paths_select_real_day_group(self):
        for path in targeted.DAY_PATHS:
            with self.subTest(path=path):
                self.assertEqual(select_groups([path]), ("day",))
        self.assertEqual(select_groups(["tests/browser/day_editor.py", "app/Services/Auth/LoginService.php"]), ALL)
        self.assertEqual(select_groups(["tests/browser/day_editor.py", "app/Unknown.php"]), ALL)
        self.assertIn("day", select_groups(["resources/css/filament/admin/theme.css"]))
        for path in ("app/Services/MealPlans/MealItemWriter.php", "app/Services/MealPlans/MealPlanStructure.php", "app/Services/MealPlans/MealPlanPublisher.php"):
            self.assertIn("recipe", select_groups([path]))
            self.assertIn("baseline", select_groups([path]))
        for path in targeted.ASSIGNMENT_PATHS:
            self.assertEqual(select_groups([path]), ("assignment",))
        self.assertEqual(select_groups(["tests/browser/day_editor.py", "app/Services/MealPlans/MealPlanAssigner.php"]), ("day", "assignment"))
        for path in ("routes/api.php", "app/Http/Middleware/Unknown.php", "client/package.json", "phpunit.pgsql.xml", "scripts/check-php-runtime.php"):
            self.assertEqual(select_groups([path]), ALL)

    def test_real_git_diff_and_invalid_sha_fail_closed(self):
        with tempfile.TemporaryDirectory() as tmp:
            def git(*args):
                return subprocess.check_output(['git', *args], cwd=tmp, text=True).strip()
            git('init', '-q')
            git('config', 'user.name', 'Synthetic CI')
            git('config', 'user.email', 'synthetic@example.invalid')
            Path(tmp, 'README.md').write_text('base')
            git('add', '.')
            git('commit', '-qm', 'base')
            base = git('rev-parse', 'HEAD')
            changed = Path(tmp, 'resources/views/filament/meal-plans/day.blade.php')
            changed.parent.mkdir(parents=True)
            changed.write_text('synthetic')
            git('add', '.')
            git('commit', '-qm', 'day')
            head = git('rev-parse', 'HEAD')
            output = Path(tmp, 'output')
            env = dict(os.environ, BASE_SHA=base, HEAD_SHA=head, GITHUB_OUTPUT=str(output))
            command = [sys.executable, str(ROOT / 'scripts/ci/targeted_groups.py')]
            subprocess.run(command, cwd=tmp, env=env, check=True, capture_output=True)
            self.assertEqual(output.read_text(), 'groups=day\n')
            git('mv', str(changed.relative_to(tmp)), 'moved.md')
            git('commit', '-qm', 'move')
            env['BASE_SHA'] = head
            env['HEAD_SHA'] = git('rev-parse', 'HEAD')
            output.write_text('')
            subprocess.run(command, cwd=tmp, env=env, check=True, capture_output=True)
            self.assertEqual(output.read_text(), 'groups=day\n')
            env['HEAD_SHA'] = head
            self.assertNotEqual(subprocess.run(command, cwd=tmp, env=env, capture_output=True).returncode, 0)
            env['HEAD_SHA'] = git('rev-parse', 'HEAD')
            env['BASE_SHA'] = '0' * 40
            self.assertNotEqual(subprocess.run(command, cwd=tmp, env=env, capture_output=True).returncode, 0)
            env['HEAD_SHA'] = 'bad'
            self.assertNotEqual(subprocess.run(command, cwd=tmp, env=env, capture_output=True).returncode, 0)

    def test_assignment_slice_keeps_copy_history_and_client_read_dependencies(self):
        runner = (ROOT / 'tests/pg/run-stage1.sh').read_text()
        mode = runner.split('if [[ ${MEAL_PLAN_ASSIGNMENT_ONLY:-0} == 1 ]]; then', 1)[1].split('\nfi', 1)[0]
        for required in ('MealPlanAssignmentTest', 'MealPlanCopyTest', 'ClientMealPlanTest',
                         'meal-plan-assignment-concurrency.php', 'meal-plan-assignment-in-context.sh'):
            self.assertIn(required, mode)

    def test_workflow_requires_selected_groups_and_schema_for_broad_scope(self):
        workflow = (ROOT / '.github/workflows/targeted.yml').read_text()
        stage = (ROOT / '.github/workflows/stage1.yml').read_text()
        self.assertIn("assert result == 'success'", workflow)
        self.assertIn("assert result == 'skipped'", workflow)
        self.assertIn('"' + ','.join(ALL) + '"', stage)
        self.assertIn('bash tests/pg/run-isolated-pg.sh', stage)
        self.assertIn('$(git rev-parse HEAD)', stage)

    def test_ci_workflow_changes_keep_scope_small_but_dependencies_cover_auth(self):
        self.assertEqual(select_groups([".github/workflows/targeted.yml"]), ())
        self.assertEqual(select_groups(["scripts/ci/pre_merge.py"]), ())
        self.assertEqual(select_groups(["composer.lock"]), ALL)


if __name__ == "__main__":
    unittest.main()
