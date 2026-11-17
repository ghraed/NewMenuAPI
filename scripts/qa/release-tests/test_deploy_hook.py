"""Exercise the versioned deployment hook against disposable Git repositories."""

import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[3]
HOOK = ROOT / 'deploy/post-receive-api.sh'


class DeployHookTest(unittest.TestCase):
    def test_main_push_keeps_checkout_head_index_and_files_aligned(self):
        with tempfile.TemporaryDirectory(prefix='menu-hook-qa-') as folder:
            root = Path(folder)
            mirror = root / 'mirror.git'
            source = root / 'source'
            live = root / 'live'
            bin_dir = root / 'bin'
            bin_dir.mkdir()
            log = root / 'docker-calls'

            def git(*args, cwd=None, env=None):
                return subprocess.run(
                    ['git', *map(str, args)], cwd=cwd, env=env,
                    capture_output=True, text=True, check=True,
                ).stdout.strip()

            git('init', '-q', '--bare', mirror)
            git('init', '-q', source)
            git('config', 'user.name', 'QA RUN', cwd=source)
            git('config', 'user.email', 'qa-run@example.invalid', cwd=source)
            (source / 'application.txt').write_text('QA_RUN_old\n')
            git('add', 'application.txt', cwd=source)
            git('commit', '-qm', 'QA RUN baseline', cwd=source)
            git('branch', '-M', 'main', cwd=source)
            git('remote', 'add', 'mirror', mirror, cwd=source)
            git('push', '-q', 'mirror', 'main', cwd=source)
            git('--git-dir', mirror, 'symbolic-ref', 'HEAD', 'refs/heads/main')
            git('clone', '-q', mirror, live)

            hook = mirror / 'hooks/post-receive'
            shutil.copyfile(HOOK, hook)
            hook.chmod(0o755)
            docker = bin_dir / 'docker'
            docker.write_text('#!/bin/sh\nprintf "%s\\n" "$*" >> "$MENU_DEPLOY_DOCKER_LOG"\n')
            docker.chmod(0o755)
            env = os.environ.copy()
            env.update({
                'PATH': str(bin_dir) + os.pathsep + env.get('PATH', os.defpath),
                'MENU_DEPLOY_GIT_DIR': str(mirror),
                'MENU_DEPLOY_WORK_TREE': str(live),
                'MENU_DEPLOY_DOCKER_LOG': str(log),
            })

            (source / 'application.txt').write_text('QA_RUN_new\n')
            git('commit', '-qam', 'QA RUN update', cwd=source)
            expected = git('rev-parse', 'HEAD', cwd=source)
            git('push', '-q', 'mirror', 'main', cwd=source, env=env)

            self.assertEqual(expected, git('rev-parse', 'HEAD', cwd=live))
            self.assertEqual('main', git('branch', '--show-current', cwd=live))
            self.assertEqual('QA_RUN_new\n', (live / 'application.txt').read_text())
            self.assertEqual('', git('status', '--porcelain', cwd=live))
            self.assertEqual('compose -f docker-compose.prod.yml up -d --build\n', log.read_text())

            git('push', '-q', 'mirror', 'HEAD:refs/heads/qa', cwd=source, env=env)
            self.assertEqual('compose -f docker-compose.prod.yml up -d --build\n', log.read_text())


if __name__ == '__main__':
    unittest.main()
