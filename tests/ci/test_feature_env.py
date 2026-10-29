"""Синтетичні перевірки ізоляції та fail-closed lifecycle без реальних БД."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('feature_env', ROOT / 'scripts/feature_env.py')
feature = importlib.util.module_from_spec(spec)
spec.loader.exec_module(feature)
PROJECT = 'telmanfit-feature-' + 'a' * 24


class FeatureTests(unittest.TestCase):
    def test_e2e_scope_covers_runtime_and_skips_unrelated_features(self):
        for path in ('docker/local/setup.php', 'scripts/feature_env.py', 'scripts/ci/pre_merge.py',
                     'database/migrations/new.php', 'compose.yaml', 'tests/feature-env-e2e.py',
                     '.github/workflows/feature-environments.yml'):
            self.assertTrue(feature.needs_e2e([path]))
        self.assertFalse(feature.needs_e2e(['docs/feature-environments.md', 'app/Services/Cooking/Writer.php']))

    def test_disjoint_resources_no_host_mounts_or_database_ports(self):
        first = feature.compose(PROJECT, 23456)
        second = feature.compose('telmanfit-feature-' + 'b' * 24, 23457)
        self.assertNotEqual(first['name'], second['name'])
        self.assertNotIn('telmanfit-local', json.dumps(first))
        self.assertNotIn('ports', first['services']['database'])
        self.assertEqual(first['services']['web']['ports'], ['127.0.0.1:23456:80'])
        for service in first['services'].values():
            self.assertNotIn('restart', service)  # Не повторювати partial initial migrate.
            for mount in service.get('volumes', []):
                self.assertIn(mount.split(':')[0], feature.VOLUMES)
        self.assertNotEqual(first['services']['app']['image'], second['services']['app']['image'])

    def test_reject_stable_project_bad_branch_and_stable_port(self):
        for project in ('telmanfit-local', '../telmanfit-feature-' + 'a' * 24, 'telmanfit-feature-a'):
            with self.assertRaises(ValueError): feature.compose(project, 23456)
        for port in (8098, 0, 60000):
            with self.assertRaises(ValueError): feature.compose(PROJECT, port)
        for branch in ('dev', 'master', 'feature/../master', '--help', 'feature/;evil'):
            with self.assertRaises(ValueError): feature.validate_feature(branch)

    def test_environment_does_not_inherit_compose_or_docker_redirects(self):
        with patch.dict(os.environ, {'COMPOSE_FILE': 'stable.yaml', 'COMPOSE_PROJECT_NAME': 'telmanfit-local', 'DOCKER_HOST': 'tcp://prod:2375', 'DOCKER_CONFIG': 'credentials-path'}):
            env = feature.safe_env()
            self.assertNotIn('COMPOSE_FILE', env)
            self.assertNotIn('DOCKER_HOST', env)
            self.assertEqual(env['DOCKER_CONFIG'], 'credentials-path')
            self.assertEqual(env['COMPOSE_DISABLE_ENV_FILE'], '1')

    def test_manager_lock_rejects_parallel_operation(self):
        with tempfile.TemporaryDirectory() as directory:
            with feature.lock(Path(directory)):
                with self.assertRaises(OSError):
                    with feature.lock(Path(directory)):
                        self.fail("Parallel manager was admitted")
            with feature.lock(Path(directory)):
                pass  # Crash/exit не залишає постійного lock.

    def test_symlink_registry_entry_rejected(self):
        if os.name == 'nt':
            self.skipTest('Windows symlink потребує окремого OS privilege; path ownership перевіряється іншими тестами')
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / PROJECT).symlink_to(ROOT, target_is_directory=True)
            with self.assertRaises(ValueError):
                feature.Manager(root, ['docker']).path(PROJECT)

    def test_remote_docker_rejected_before_daemon_call(self):
        with patch.object(feature, 'run', return_value='"tcp://prod:2375"') as run:
            with self.assertRaises(ValueError): feature.local_docker()
            self.assertEqual(run.call_count, 1)

    def test_unprepared_feature_cannot_create_any_resource(self):
        with tempfile.TemporaryDirectory() as root:
            manager = feature.Manager(Path(root), ['docker'])
            with patch.object(feature, 'prepared', side_effect=ValueError('review missing')):
                with self.assertRaises(ValueError): manager.start('feature/example')
            self.assertEqual(list(Path(root).iterdir()), [])

    def test_update_preserves_old_revision_and_failed_attempt_is_recorded(self):
        proof = {'head_sha': 'a' * 40, 'base_sha': 'b' * 40, 'pr': 42}
        with tempfile.TemporaryDirectory() as root:
            manager = feature.Manager(Path(root), ['docker'])
            calls = []
            with patch.object(feature, 'prepared', return_value=proof), patch.object(feature, 'export_source'), patch.object(manager, 'assert_config'), patch.dict(os.environ, {'CODEX_PROXY_CERT': ''}), patch.object(manager, 'cli', side_effect=lambda record, *args: calls.append((record['project'], args))):
                old = manager.start('feature/example')
                new = manager.start('feature/example')
                self.assertNotEqual(old['project'], new['project'])
                self.assertEqual(len(manager.records('feature/example')), 2)
                self.assertFalse(any('down' in args for _, args in calls))
                with patch.object(manager, 'cli', side_effect=subprocess.CalledProcessError(1, ['docker'])):
                    with self.assertRaises(subprocess.CalledProcessError): manager.start('feature/example')
            self.assertEqual(sorted(r['status'] for r in manager.records('feature/example')), ['failed', 'ready', 'ready'])

    def test_changed_review_after_build_blocks_start(self):
        proof = {'head_sha': 'a' * 40, 'base_sha': 'b' * 40, 'pr': 42}
        with tempfile.TemporaryDirectory() as root:
            manager = feature.Manager(Path(root), ['docker'])
            with patch.object(feature, 'prepared', side_effect=[proof, ValueError('review dismissed')]), patch.object(feature, 'export_source'), patch.object(manager, 'assert_config'), patch.dict(os.environ, {'CODEX_PROXY_CERT': ''}), patch.object(manager, 'cli') as cli:
                with self.assertRaises(ValueError): manager.start('feature/example')
                self.assertEqual(cli.call_count, 1)  # Тільки build, жодного up/migrate.
            self.assertEqual(manager.records('feature/example')[0]['status'], 'failed')

    def test_tampered_compose_blocks_cleanup(self):
        with tempfile.TemporaryDirectory() as root:
            manager = feature.Manager(Path(root), ['docker'])
            path = manager.path(PROJECT)
            path.mkdir()
            (path / 'compose.json').write_text('{}')
            with patch.object(manager, 'cli') as cli:
                with self.assertRaises(ValueError): manager.remove({'project': PROJECT, 'port': 23456})
                cli.assert_not_called()
            self.assertTrue(path.exists())

    def test_foreign_owned_docker_object_blocks(self):
        with tempfile.TemporaryDirectory() as root:
            manager = feature.Manager(Path(root), ['docker'])
            with patch.object(feature, 'run', side_effect=[PROJECT + '-setup-1', json.dumps([{'Config': {'Labels': {'com.docker.compose.project': 'telmanfit-local'}}}])]):
                with self.assertRaisesRegex(ValueError, 'ownership'):
                    manager.verify_resources({'project': PROJECT, 'status': 'ready'})

    def test_archive_links_rejected(self):
        import io
        data = io.BytesIO()
        with tarfile.open(fileobj=data, mode='w') as archive:
            link = tarfile.TarInfo('escape')
            link.type = tarfile.SYMTYPE
            link.linkname = '/etc/passwd'
            archive.addfile(link)
        with tempfile.TemporaryDirectory() as root, patch.object(feature, 'run', side_effect=[f'https://github.com/{feature.REPO}.git', '']), patch.object(feature.subprocess, 'check_output', return_value=data.getvalue()):
            with self.assertRaises(ValueError): feature.export_source('a' * 40, Path(root))


if __name__ == '__main__':
    unittest.main()
