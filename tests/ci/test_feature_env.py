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

    def test_bootstrap_expected_head_fails_before_resource_creation(self):
        with tempfile.TemporaryDirectory() as root:
            manager = feature.Manager(Path(root), ['docker'])
            with patch.object(feature, 'prepared', return_value={'head_sha': 'b' * 40}):
                with self.assertRaisesRegex(ValueError, 'head'):
                    manager.start('feature/example', expected_head='a' * 40)
            self.assertEqual(list(Path(root).iterdir()), [])

    def harness(self, root):
        manager = feature.Manager(Path(root), ['docker'])
        states, calls = {}, []
        def cli(record, *args):
            project = record['project']
            calls.append((project, args))
            if args[0] == 'ps':
                return json.dumps([{'State': 'running'}] if states.get(project) else [])
            if args[0] == 'up': states[project] = True
            if args[0] == 'down': states[project] = False
            return ''
        return manager, states, calls, cli

    def test_repeat_update_reuses_data_and_fresh_parks_other_features(self):
        proof = {'head_sha': 'a' * 40, 'base_sha': 'b' * 40, 'pr': 42}
        with tempfile.TemporaryDirectory() as root:
            manager, states, calls, cli = self.harness(root)
            with patch.object(feature, 'prepared', return_value=proof), patch.object(feature, 'export_source'), patch.object(manager, 'assert_config'), patch.object(manager, 'image_ids', return_value={'app': 'sha256:owned'}), patch.dict(os.environ, {'CODEX_PROXY_CERT': ''}), patch.object(manager, 'cli', side_effect=cli):
                old = manager.start('feature/example')
                marker = manager.path(old['project']) / 'synthetic-data'
                marker.write_text('keep my input')
                same = manager.start('feature/example')
                self.assertEqual(same['project'], old['project'])
                self.assertEqual(sum(args[0] == 'build' for _, args in calls), 1)
                proof['head_sha'] = 'c' * 40
                before = list(calls)
                with self.assertRaisesRegex(ValueError, 'fresh'): manager.start('feature/example')
                self.assertEqual(calls, before)  # Ніяких Docker змін до явного fresh.
                new = manager.start('feature/other', fresh=True)
                self.assertFalse(states[old['project']])
                self.assertTrue(states[new['project']])
                manager.resume(manager.records('feature/example')[0])
                self.assertTrue(states[old['project']])
                self.assertFalse(states[new['project']])
                self.assertEqual(marker.read_text(), 'keep my input')
                self.assertEqual(sum(states.values()), 1)
                self.assertFalse(any('--volumes' in args or '--rmi' in args for _, args in calls))
                manager.stop(old)
                self.assertEqual(manager.runtime(old)['state'], 'stopped')
                self.assertIn(old['project'] + '.localhost', manager.runtime(old)['url'])

    def test_failed_fresh_restores_previous_preview_without_removing_data(self):
        proof = {'head_sha': 'a' * 40, 'base_sha': 'b' * 40, 'pr': 42}
        with tempfile.TemporaryDirectory() as root:
            manager, states, calls, cli = self.harness(root)
            with patch.object(feature, 'prepared', return_value=proof), patch.object(feature, 'export_source'), patch.object(manager, 'assert_config'), patch.object(manager, 'image_ids', return_value={'app': 'sha256:owned'}), patch.dict(os.environ, {'CODEX_PROXY_CERT': ''}), patch.object(manager, 'cli', side_effect=cli):
                old = manager.start('feature/example')
                def fail_build(record, *args):
                    if args[0] == 'build': raise subprocess.CalledProcessError(1, ['docker'])
                    return cli(record, *args)
                with patch.object(manager, 'cli', side_effect=fail_build):
                    with self.assertRaises(subprocess.CalledProcessError): manager.start('feature/example', fresh=True)
                self.assertTrue(states[old['project']])
                self.assertEqual(sum(states.values()), 1)
                self.assertEqual(sorted(r['status'] for r in manager.records()), ['active', 'failed'])

    def test_changed_review_after_build_never_starts_candidate(self):
        proof = {'head_sha': 'a' * 40, 'base_sha': 'b' * 40, 'pr': 42}
        with tempfile.TemporaryDirectory() as root:
            manager, states, calls, cli = self.harness(root)
            with patch.object(feature, 'prepared', side_effect=[proof, ValueError('review dismissed')]), patch.object(feature, 'export_source'), patch.object(manager, 'assert_config'), patch.object(manager, 'image_ids', return_value={'app': 'sha256:owned'}), patch.dict(os.environ, {'CODEX_PROXY_CERT': ''}), patch.object(manager, 'cli', side_effect=cli):
                with self.assertRaises(ValueError): manager.start('feature/example')
                self.assertFalse(any(args[0] == 'up' for _, args in calls))
                self.assertEqual(manager.records()[0]['status'], 'failed')

    def test_failed_resume_is_retryable_and_restores_other_active_preview(self):
        proof = {'head_sha': 'a' * 40, 'base_sha': 'b' * 40, 'pr': 42}
        with tempfile.TemporaryDirectory() as root:
            manager, states, calls, cli = self.harness(root)
            with patch.object(feature, 'prepared', return_value=proof), patch.object(feature, 'export_source'), patch.object(manager, 'assert_config'), patch.object(manager, 'image_ids', return_value={'app': 'sha256:owned'}), patch.dict(os.environ, {'CODEX_PROXY_CERT': ''}), patch.object(manager, 'cli', side_effect=cli):
                old = manager.start('feature/old')
                current = manager.start('feature/current')
                old = manager.records('feature/old')[0]
                def fail_up(record, *args):
                    if record['project'] == old['project'] and args[0] == 'up':
                        raise subprocess.CalledProcessError(1, ['synthetic-port-collision'])
                    return cli(record, *args)
                with patch.object(manager, 'cli', side_effect=fail_up):
                    with self.assertRaises(subprocess.CalledProcessError): manager.resume(old)
                self.assertTrue(states[current['project']])
                self.assertEqual(manager.records('feature/old')[0]['status'], 'stopped')
                manager.resume(manager.records('feature/old')[0])
                self.assertTrue(states[old['project']])
                self.assertFalse(states[current['project']])

    def test_modified_image_and_unknown_peer_block_before_stopping(self):
        with tempfile.TemporaryDirectory() as root:
            manager = feature.Manager(Path(root), ['docker'])
            record = {'project': PROJECT, 'status': 'stopped', 'images': {'app': 'old'}}
            with patch.object(manager, 'assert_config'), patch.object(manager, 'image_ids', return_value={'app': 'unreviewed'}), patch.object(manager, 'cli') as cli:
                with self.assertRaisesRegex(ValueError, 'Immutable'): manager.resume(record)
                cli.assert_not_called()
            target = {**record, 'project': PROJECT.replace('a' * 24, 'b' * 24)}
            with patch.object(manager, 'records', return_value=[record]), patch.object(manager, 'runtime', side_effect=ValueError('ownership')), patch.object(manager, 'cli') as cli:
                with self.assertRaises(ValueError): manager.park_previews(target)
                cli.assert_not_called()

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
