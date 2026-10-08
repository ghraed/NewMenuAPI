#!/usr/bin/env python3
"""Run the actual project scripts against a new, owned MySQL instance; no .env writes."""
import argparse
import getpass
import json
import os
from pathlib import Path
import re
import shutil
import signal
import socket
import subprocess
import tempfile
import time
import urllib.request
import uuid
import zipfile
import xml.etree.ElementTree as ET

API = Path(__file__).resolve().parents[2]


def free_port():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        return sock.getsockname()[1]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--react-root', type=Path, help='Also run frontend/browser gates')
    parser.add_argument('--api-filter', help='Focused PHPUnit filter; partial verification only')
    parser.add_argument('--browser-only', action='store_true', help='Only run frontend/browser gates')
    parser.add_argument('--browser-matrix', action='store_true', help='Also require Task 10 Firefox/WebKit accessibility checks; install the pinned browser runtimes first')
    parser.add_argument('--launch', action='store_true', help='Require real realtime, lifecycle and operational recovery gates')
    parser.add_argument('--e2e-spec', help='Run one existing browser spec for iteration; never a complete launch verification')
    parser.add_argument('--runtime-only', action='store_true', help='Focused iteration: omit lint/unit checks, still build owned runtime')
    parser.add_argument('--run-id', default=time.strftime('%Y%m%d')+'_'+uuid.uuid4().hex[:8])
    parser.add_argument('--evidence', type=Path, required=True, help='New evidence directory (must not exist)')
    args = parser.parse_args()
    if not re.fullmatch(r'[A-Za-z0-9_]{1,40}', args.run_id):
        parser.error('Run ID must contain 1-40 letters, digits or underscores.')
    if args.api_filter and args.launch:
        parser.error('--api-filter is partial verification and cannot be combined with --launch')
    if args.browser_matrix and not args.react_root:
        parser.error('--browser-matrix requires --react-root')
    if args.browser_only and not args.react_root:
        parser.error('--browser-only requires --react-root')
    if args.launch and not args.react_root:
        parser.error('--launch requires --react-root')
    if args.e2e_spec and (not args.react_root or not re.fullmatch(r'[a-z0-9-]+\.spec\.ts', args.e2e_spec)
                         or not (args.react_root/'tests/e2e'/args.e2e_spec).is_file()):
        parser.error('--e2e-spec requires an existing browser spec basename and --react-root')
    if args.runtime_only and (not args.browser_only or not args.e2e_spec):
        parser.error('--runtime-only requires --browser-only and --e2e-spec; not a release gate')
    react = args.react_root.resolve() if args.react_root else None
    evidence = args.evidence.resolve()
    ports = [free_port() for _ in range(4)]
    if len(set(ports)) != 4:
        raise RuntimeError('Port allocation collision; start another run.')
    evidence.mkdir(parents=True, exist_ok=False)
    runtime = Path(tempfile.mkdtemp(prefix='menu-qa-'))
    processes, handles, checks = [], [], []
    mysql, client = None, None
    db_port, api_port, web_port, reverb_port = ports
    api_url, web_url = f'http://127.0.0.1:{api_port}', f'http://127.0.0.1:{web_port}'

    # Keep only OS/toolchain settings. No inherited credentials, DB URLs or VITE values.
    keep = ['PATH', 'HOME', 'USER', 'LANG', 'LC_ALL', 'TZ', 'SYSTEMROOT', 'COMPOSER_HOME', 'COMPOSER_CACHE_DIR', 'npm_config_cache']
    env = {key: os.environ[key] for key in keep if key in os.environ}
    env.update({'APP_ENV': 'testing', 'APP_DEBUG': 'false', 'APP_URL': api_url,
                'APP_KEY': 'base64:'+__import__('base64').b64encode(os.urandom(32)).decode(),
                'DB_CONNECTION': 'mysql', 'DB_HOST': '127.0.0.1', 'DB_PORT': str(db_port),
                'DB_USERNAME': 'qa_runner', 'DB_PASSWORD': '', 'DB_URL': '',
                'MAIL_MAILER': 'array', 'MAIL_HOST': '127.0.0.1', 'MAIL_USERNAME': '', 'MAIL_PASSWORD': '',
                'QUEUE_CONNECTION': 'sync', 'BROADCAST_CONNECTION': 'null', 'CACHE_STORE': 'array',
                'SESSION_DRIVER': 'array', 'FILESYSTEM_DISK': 'local', 'REDIS_URL': '', 'REDIS_HOST': '127.0.0.1',
                'BCRYPT_ROUNDS': '4', 'QA_RUN_ID': args.run_id, 'QA_RUNTIME_DIR': str(runtime),
                'QA_EVIDENCE_DIR': str(evidence), 'QA_API_ROOT': str(API), 'QA_API_URL': api_url,
                'PLAYWRIGHT_BASE_URL': web_url, 'PLAYWRIGHT_CHROME_EXECUTABLE': shutil.which('google-chrome') or '',
                'PLAYWRIGHT_PROFILE_EMAIL': f'QA_RUN_{args.run_id}_admin@example.invalid',
                'PLAYWRIGHT_PROFILE_PASSWORD': 'QA_RUN_'+uuid.uuid4().hex,
                'VITE_API_URL': '/api', 'VITE_PROXY_TARGET': api_url,
                'VITE_GUEST_RESTAURANT_SLUG': 'qa-run-'+args.run_id.lower().replace('_', '-'),
                'FRONTEND_URL': web_url, 'VITE_REVERB_APP_KEY': '', 'VITE_PUSHER_APP_KEY': '',
                'LARAVEL_STORAGE_PATH': str(runtime/'storage'), 'TMPDIR': str(runtime/'tmp')})
    if args.browser_matrix:
        env['QA_BROWSER_MATRIX'] = '1'
        if os.environ.get('PLAYWRIGHT_BROWSERS_PATH'):
            env['PLAYWRIGHT_BROWSERS_PATH'] = os.environ['PLAYWRIGHT_BROWSERS_PATH']
    # Suppress every known credential even when .env.testing is loaded by PHPUnit's app.
    sources = [*API.glob('.env*'), *API.glob('config/*.php'), API/'app/Providers/TestingSafetyServiceProvider.php']
    for source in sources:
        if not source.is_file():
            continue
        content = source.read_text()
        keys = set(re.findall(r"(?:env\(|^)['\"]?([A-Z][A-Z0-9_]+)", content, re.M))
        keys.update(re.findall(r"'([A-Z][A-Z0-9_]+)'", content))
        for key in keys:
            if any(part in key for part in ['KEY', 'SECRET', 'TOKEN', 'PASSWORD', 'BUCKET', 'ENDPOINT', 'SERVICE_ACCOUNT', 'CLIENT_ID', 'APP_ID', 'MERCHANT_ID', 'VAPID', 'DEEPSEEK', 'STRIPE', 'PAYPAL', 'BRAINTREE', 'SQUARE', 'TWILIO', 'SMS', 'SLACK']):
                env.setdefault(key, '')
    for key, name in [('APP_CONFIG_CACHE', 'config'), ('APP_ROUTES_CACHE', 'routes'), ('APP_EVENTS_CACHE', 'events'),
                      ('APP_PACKAGES_CACHE', 'packages'), ('APP_SERVICES_CACHE', 'services')]:
        env[key] = str(runtime/f'{name}.php')
    for folder in ['tmp', 'storage/logs', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views']:
        (runtime/folder).mkdir(parents=True, exist_ok=True)
    (runtime/'owner.json').write_text(json.dumps({'run_id': args.run_id, 'db_port': db_port}))
    if args.launch:
        env.update({'QA_REVERB_PORT': str(reverb_port),
                    'VITE_REVERB_APP_KEY': 'qa-'+args.run_id, 'VITE_REVERB_HOST': '127.0.0.1',
                    'VITE_REVERB_PORT': str(reverb_port), 'VITE_REVERB_SCHEME': 'http',
                    'DOMAIN_PROVISIONING_BRIDGE_DIR': str(runtime/'bridge')})
        (runtime/'owner.json').write_text(json.dumps({'run_id': args.run_id, 'db_port': db_port, 'reverb_port': reverb_port}))
    (runtime/'.env').write_text('')

    def command(name, cmd, cwd=API, required=True):
        print(f'{name}: running', flush=True)
        with (evidence/f'{name}.log').open('w') as log:
            result = subprocess.Popen(cmd, cwd=cwd, env=env, stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
            processes.append(result)
            result.wait()
        status = 'PASS' if result.returncode == 0 else 'FAIL'
        if name.endswith('audit'):
            try:
                audit = json.loads((evidence/f'{name}.log').read_text())
                if audit.get('error'):
                    status = 'BLOCKED'
            except ValueError:
                status = 'BLOCKED'
        checks.append({'check': name, 'status': status, 'exit': result.returncode, 'required': required})
        print(f'{name}: {checks[-1]["status"]}', flush=True)
        if required and result.returncode:
            raise RuntimeError(f'{name} failed; see its retained log.')
        if name in ['api-environment', 'browser-environment']:
            try:
                target = json.loads((evidence/f'{name}.log').read_text())
                if (target['environment'] != 'testing' or target['run_id'] != args.run_id
                    or target['database'] != env['DB_DATABASE'] or target['host'] != '127.0.0.1'
                    or target['port'] != db_port):
                    raise ValueError('Effective target mismatch')
            except (ValueError, KeyError):
                checks[-1]['status'] = 'FAIL'
                raise RuntimeError(f'{name} did not return verified environment JSON.')
        junit = {'api-full': 'api-junit.xml', 'api-focused': 'api-junit.xml', 'react-unit': 'react-junit.xml', 'react-e2e': 'browser-junit.xml'}.get(name)
        if junit:
            report = ET.parse(evidence/junit).getroot()
            cases = list(report.iter('testcase'))
            checks[-1]['tests'] = len(cases)
            checks[-1]['skipped'] = sum(case.find('skipped') is not None for case in cases)
            if checks[-1]['skipped']:
                checks[-1]['status'] = 'SKIPPED'
                raise RuntimeError(f'{name} contains skipped tests; the complete baseline is unverified.')
            if args.browser_matrix and name == 'react-e2e' and not args.e2e_spec:
                required_ui = {
                    'login keyboard skips decoration and announces real authentication errors',
                    *[f'Arabic POS complaint and settled report remain localized in {theme}' for theme in ['light', 'dark']],
                    'room plan can be positioned without dragging and persists coordinates',
                    *[f'guest related-dish modal keeps keyboard focus and restores it in {theme}' for theme in ['light', 'dark']],
                    'paid bilingual receipt stays readable with toolbar hidden in print media',
                }
                for project in ['mobile-chrome', 'firefox', 'webkit', 'chromium']:
                    matrix_cases = [case for suite in report.iter('testsuite') if suite.get('hostname') == project
                                    for case in suite.iter('testcase') if 'task10-' in case.get('classname', '')
                                    and case.get('name') in required_ui]
                    if len(matrix_cases) != len(required_ui) or {case.get('name') for case in matrix_cases} != required_ui:
                        checks[-1]['status'] = 'FAIL'
                        raise RuntimeError(f'Required Task 10 {project} UI matrix did not execute; verify the paired frontend ref.')
                for spec, title in [('task10-performance.spec.ts', 'throttled phone measures guest catalog and admin deep links'),
                                    ('task10-runtime.spec.ts', 'finance deep link reload and demand-loaded spreadsheet export work from built assets')]:
                    if len([case for case in cases if case.get('classname', '').endswith(spec) and case.get('name') == title]) != 1:
                        checks[-1]['status'] = 'FAIL'
                        raise RuntimeError(f'Required Task 10 {spec} did not execute.')
            if args.launch and name == 'react-e2e' and not args.e2e_spec:
                lifecycle = [case for case in cases if case.get('name') ==
                             'real PIN, staff, kitchen, paid receipt and finance lifecycle isolates two tenants and denied roles'
                             and case.get('classname', '').endswith('real-order-lifecycle.spec.ts')]
                if len(lifecycle) != 1:
                    checks[-1]['status'] = 'FAIL'
                    raise RuntimeError('Required real lifecycle case did not execute; verify the paired frontend ref.')
                offline = [case for case in cases if case.get('classname', '').endswith('offline-recovery.spec.ts')]
                expected_offline = {
                    'service worker keeps public offline menu while token-sensitive responses bypass shared caches',
                    'lost acknowledgement survives reload, application upgrade and competing tabs with one server order',
                    'interrupted network submit before server commit survives reload and reconnect',
                    'reload during an unacknowledged committed submit recovers the queued intent instead of sending a new cart',
                    *[f'offline interrupted submit requires review after session {action}' for action in ['expire', 'close', 'disable']],
                }
                if len(offline) != len(expected_offline) or {case.get('name') for case in offline} != expected_offline:
                    checks[-1]['status'] = 'FAIL'
                    raise RuntimeError('Required Task 9 offline cases did not execute; verify the paired frontend ref.')


    def background(name, cmd, cwd=API):
        log = (evidence/f'{name}.log').open('w')
        handles.append(log)
        proc = subprocess.Popen(cmd, cwd=cwd, env=env, stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        processes.append(proc)
        return proc

    def wait_ready(proc, probe):
        deadline = time.monotonic()+60
        while time.monotonic() < deadline:
            if proc.poll() is not None:
                raise RuntimeError('QA server exited before readiness; see server log.')
            try:
                probe()
                return
            except (OSError, subprocess.CalledProcessError):
                time.sleep(0.25)
        raise RuntimeError('QA server readiness timed out.')

    def snapshot(root):
        return {key: subprocess.check_output(['git', *cmd], cwd=root, text=True).strip()
                for key, cmd in [('branch', ['branch', '--show-current']), ('commit', ['rev-parse', 'HEAD']), ('working_tree', ['status', '--short'])]}

    exit_code = 0
    try:
        for tool in ['mysqld', 'mysql', 'php', 'node', 'npm', 'composer', 'google-chrome', 'pdftotext']:
            if not shutil.which(tool):
                raise RuntimeError(f'Missing QA prerequisite: {tool}')
        if not (API/'vendor/autoload.php').is_file() or not (API/'node_modules').is_dir():
            raise RuntimeError('Install locked API dependencies before running QA.')
        if react and not (react/'node_modules').is_dir():
            raise RuntimeError('Install locked frontend dependencies before running QA.')
        state = {'run_id': args.run_id, 'api': snapshot(API), 'react': snapshot(react) if react else None,
                 'api_url': api_url, 'frontend_url': web_url if react else None, 'APP_ENV': 'testing',
                 'DB_HOST': '127.0.0.1', 'DB_PORT': db_port, 'runtime': str(runtime)}
        (evidence/'repository-environment.json').write_text(json.dumps(state, indent=2))
        data = runtime/'mysql'
        command('mysql-initialize', ['mysqld', '--no-defaults', '--initialize-insecure', f'--datadir={data}', f'--user={getpass.getuser()}'])
        mysql = background('mysql-server', ['mysqld', '--no-defaults', f'--datadir={data}', f'--socket={runtime}/mysql.sock',
                           f'--pid-file={runtime}/mysql.pid', '--bind-address=127.0.0.1', f'--port={db_port}',
                           '--mysqlx=0', f'--user={getpass.getuser()}', '--log-error-verbosity=2'])
        client = ['mysql', '--no-defaults', '--protocol=socket', f'--socket={runtime}/mysql.sock', '-uroot']
        wait_ready(mysql, lambda: subprocess.run([*client, '-e', 'SELECT 1'], env=env, capture_output=True, check=True))
        suite_db = f'menu_test_QA_RUN_{args.run_id}_suite'
        browser_db = f'menu_test_QA_RUN_{args.run_id}_browser'
        sql = f"CREATE DATABASE `{suite_db}`; CREATE DATABASE `{browser_db}`; CREATE USER 'qa_runner'@'127.0.0.1'; GRANT ALL ON `{suite_db}`.* TO 'qa_runner'@'127.0.0.1'; GRANT ALL ON `{browser_db}`.* TO 'qa_runner'@'127.0.0.1';"
        subprocess.run(client, input=sql, text=True, env=env, capture_output=True, check=True)
        env['DB_DATABASE'] = suite_db
        command('api-environment', ['php', 'scripts/qa/environment.php'])
        if not args.browser_only:
            command('api-migrations', ['php', 'artisan', 'migrate:fresh', '--env=testing', '--force'])
            command('api-focused' if args.api_filter else 'api-full', ['php', 'artisan', 'test', '--log-junit', str(evidence/'api-junit.xml'), *(['--filter', args.api_filter] if args.api_filter else [])])
            command('api-build', ['npm', 'run', 'build'])
            command('api-tooling', ['npm', 'run', 'test:tooling'])
            command('composer-validate', ['composer', 'validate', '--no-check-publish'])
            command('api-pint', ['vendor/bin/pint', '--test'])
            command('composer-audit', ['composer', 'audit', '--format=json'])
            command('api-npm-audit', ['npm', 'audit', '--json'])
        if args.api_filter:
            checks.append({'check': 'complete-api-suite', 'status': 'NOT EXECUTED', 'required': False, 'reason': 'Explicit focused PHPUnit filter.'})
        if react:
            env['DB_DATABASE'] = browser_db
            if args.launch:
                env['QA_OPERATIONAL'] = '1'
                reverb = background('reverb-server', ['php', 'scripts/qa/console.php', 'reverb:start', '--host=127.0.0.1', f'--port={reverb_port}'])
                def probe_reverb():
                    with socket.create_connection(('127.0.0.1', reverb_port), timeout=2):
                        pass
                wait_ready(reverb, probe_reverb)
            command('browser-environment', ['php', 'scripts/qa/environment.php'])
            command('browser-migrations', ['php', 'artisan', 'migrate:fresh', '--env=testing', '--force'])
            if not args.runtime_only:
                command('react-lint', ['npm', 'run', 'lint'], react)
                command('react-unit', ['npm', 'run', 'test:unit', '--', '--reporter=default', '--reporter=junit', f'--outputFile={evidence}/react-junit.xml'], react)
            else:
                checks.extend([{'check': name, 'status': 'NOT EXECUTED', 'required': False,
                                'reason': 'Explicit focused runtime iteration, not a release gate.'}
                               for name in ['react-lint', 'react-unit']])
            command('react-build', ['npm', 'run', 'build', '--', '--mode', 'qa', '--outDir', str(runtime/'frontend')], react)
            # Vite preview proxies only to this owned API; no reuse of existing dev servers.
            api = background('api-server', ['php', '-S', f'127.0.0.1:{api_port}', 'scripts/qa/router.php'])
            wait_ready(api, lambda: urllib.request.urlopen(api_url+'/api/__qa/environment', timeout=2).close())
            web = background('react-server', ['npm', 'run', 'preview', '--', '--mode', 'qa', '--host', '127.0.0.1', '--port', str(web_port), '--strictPort', '--outDir', str(runtime/'frontend')], react)
            wait_ready(web, lambda: urllib.request.urlopen(web_url+'/api/__qa/environment', timeout=2).close())
            if args.launch:
                command('operational-recovery', ['python3', 'scripts/qa/operational.py'])
            command('react-e2e', ['npm', 'run', 'test:e2e', *(['--', args.e2e_spec] if args.e2e_spec else [])], react)
            if args.e2e_spec:
                checks.append({'check': 'complete-browser-launch', 'status': 'NOT EXECUTED', 'required': False,
                               'reason': 'Focused iteration only; all other specs must run before launch.'})
            command('react-npm-audit', ['npm', 'audit', '--json'], react)
    except (Exception, KeyboardInterrupt) as error:
        exit_code = 1
        # Do not include command/environment dumps (which may contain credentials).
        message = str(error) if isinstance(error, RuntimeError) else type(error).__name__
        checks.append({'check': 'runner', 'status': 'BLOCKED', 'reason': message, 'required': True})
        print(f'QA stopped: {message}', flush=True)
    finally:
        cleanup_ok = True
        # Some host AppArmor profiles reject signals to mysqld even for its owner.
        # Shut down ONLY our instance through its private socket instead.
        if mysql is not None and mysql.poll() is None and client is not None:
            try:
                subprocess.run([*client, '-e', 'SHUTDOWN'], env=env, capture_output=True, check=True, timeout=10)
                mysql.wait(timeout=10)
            except (OSError, subprocess.SubprocessError):
                cleanup_ok = False
                exit_code = 1
                checks.append({'check': 'mysql-cleanup', 'status': 'BLOCKED', 'reason': 'Owned MySQL shutdown failed; runtime retained.', 'required': True})
        for proc in reversed(processes):
            if proc is not mysql and proc.poll() is None:
                try:
                    os.killpg(proc.pid, signal.SIGTERM)
                    proc.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    os.killpg(proc.pid, signal.SIGKILL)
                    proc.wait()
                except OSError:
                    cleanup_ok = False
                    exit_code = 1
                    checks.append({'check': 'server-cleanup', 'status': 'BLOCKED', 'reason': 'Owned server shutdown failed; runtime retained.', 'required': True})
        for handle in handles:
            handle.close()
        # Traces and HTML reports may include synthetic login fields or bearer tokens.
        # Keep their structure while redacting those values before artifact retention.
        def redact(content):
            for key in ['APP_KEY', 'PLAYWRIGHT_PROFILE_PASSWORD']:
                content = content.replace(env[key], '[REDACTED]')
            content = re.sub(r'Bearer [A-Za-z0-9_.|=+/-]+', 'Bearer [REDACTED]', content)
            content = re.sub(r'(QA_RUN_password_[0-9]+)', '[REDACTED]', content)
            content = re.sub(r'QA_RUN_[a-f0-9]{8}-[a-f0-9-]{27,}', '[REDACTED]', content)
            content = re.sub(r'(\d+\|[A-Za-z0-9]{30,})', '[REDACTED]', content)
            content = re.sub(r'(?<![A-Za-z0-9])[A-Za-z0-9]{80}(?![A-Za-z0-9])', '[REDACTED_GUEST_TOKEN]', content)
            content = re.sub(r'qa-[A-Za-z0-9_]+:[a-f0-9]{64}', '[REDACTED_CHANNEL_SIGNATURE]', content)
            return content

        for artifact in evidence.rglob('*'):
            if artifact.is_file() and artifact.suffix in ['.log', '.json', '.xml', '.md', '.html', '.txt']:
                artifact.write_text(redact(artifact.read_text()))
            elif artifact.is_file() and artifact.suffix == '.zip':
                cleaned = artifact.with_suffix('.cleaned.zip')
                with zipfile.ZipFile(artifact) as original, zipfile.ZipFile(cleaned, 'w', zipfile.ZIP_DEFLATED) as output:
                    for member in original.infolist():
                        content = original.read(member)
                        try:
                            content = redact(content.decode()).encode()
                        except UnicodeDecodeError:
                            pass
                        output.writestr(member, content)
                cleaned.replace(artifact)
        if cleanup_ok:
            shutil.rmtree(runtime)
            checks.append({'check': 'cleanup', 'status': 'PASS', 'required': True})
        (evidence/'checks.json').write_text(json.dumps(checks, indent=2))
        print(f'Evidence: {evidence}; runtime {"removed" if cleanup_ok else "retained"}.', flush=True)
    return exit_code


if __name__ == '__main__':
    raise SystemExit(main())
