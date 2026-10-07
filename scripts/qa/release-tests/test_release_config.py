"""Task 6 regressions: only synthetic configuration is ever copied or printed."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[3]


class ReleaseConfigTest(unittest.TestCase):
    def test_entrypoint_requires_runtime_key_without_creating_or_rotating_one(self):
        source = (ROOT / 'docker/entrypoint.sh').read_text()
        with tempfile.TemporaryDirectory(prefix='menu-entrypoint-test-') as folder:
            root = Path(folder)
            (root / '.env.production').write_text('APP_KEY=QA_RUN_secret_canary\n')
            (root / '.env.example').write_text('APP_KEY=\n')
            (root / 'storage').mkdir()
            (root / 'bootstrap/cache').mkdir(parents=True)
            # Relocate every application path; no real configuration is loaded.
            entry = root / 'entrypoint.sh'
            entry.write_text(source.replace('/var/www', folder))
            php = root / 'php'
            php.write_text('#!/bin/sh\necho "$*" >> "$QA_CALLS"\n')
            php.chmod(0o755)
            env = {'PATH': folder + ':' + os.defpath, 'QA_CALLS': str(root / 'calls'),
                   'RUN_MIGRATIONS': 'false', 'RUN_STORAGE_LINK': 'false', 'RUN_CONFIG_CACHE': 'false'}
            missing = subprocess.run(['sh', str(entry), 'true'], env=env, capture_output=True)
            self.assertNotEqual(0, missing.returncode, 'Missing APP_KEY must stop startup')
            self.assertFalse((root / '.env').exists(), 'Startup must not write config')
            self.assertFalse((root / 'calls').exists(), 'Missing key must fail before Artisan')
            env['APP_KEY'] = 'base64:UVFBUlVOX3N5bnRoZXRpY19rZXlfMzJfYnl0ZXMhISE='
            ready = subprocess.run(['sh', str(entry), 'true'], env=env, capture_output=True)
            self.assertEqual(0, ready.returncode, ready.stderr.decode())
            self.assertFalse((root / '.env').exists())
            self.assertFalse((root / 'calls').exists())
            self.assertEqual('APP_KEY=QA_RUN_secret_canary\n', (root / '.env.production').read_text())

    def test_docker_context_excludes_secret_variants_and_cached_configuration(self):
        with tempfile.TemporaryDirectory(prefix='menu-context-test-') as folder:
            root = Path(folder)
            context = root / 'context'
            context.mkdir()
            (context / '.dockerignore').write_bytes((ROOT / '.dockerignore').read_bytes())
            (context / 'Dockerfile').write_text('FROM scratch\nCOPY . /\n')
            secret_paths = ['.env', '.env.production', '.env.testing', '.env.backup',
                            '.env.example', 'nested/.env.production', 'docker/db.env',
                            'nested/service.env', 'auth.json', 'nested/auth.json', 'bootstrap/cache/config.php']
            for name in secret_paths:
                target = context / name
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_text('QA_RUN_synthetic_secret_canary')
            (context / 'application.txt').write_text('QA_RUN_nonsecret')
            result = subprocess.run(['docker', 'build', '--quiet', '--output',
                                     'type=local,dest=' + str(root / 'export'), str(context)],
                                    capture_output=True)
            self.assertEqual(0, result.returncode, result.stderr.decode())
            self.assertTrue((root / 'export/application.txt').is_file())
            leaked = [name for name in secret_paths if (root / 'export' / name).exists()]
            self.assertEqual([], leaked, 'Secret-bearing filenames reached Docker COPY')

    def test_docker_recipe_installs_browser_and_arabic_fonts(self):
        source = (ROOT / 'Dockerfile').read_text()
        self.assertIn('chromium', source)
        self.assertIn('fonts-noto-core', source)


if __name__ == '__main__':
    unittest.main()
