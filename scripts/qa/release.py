#!/usr/bin/env python3
"""Smoke a release image on a new internal Docker network and disposable MySQL."""
import argparse
import base64
import fnmatch
import hashlib
from email.parser import BytesParser
import json
import os
from pathlib import Path
import re
import subprocess
import tarfile
import tempfile
import time
import uuid

ROOT = Path(__file__).resolve().parents[2]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--image', required=True, help='Locally built image; no pulls/deployment')
    parser.add_argument('--run-id', required=True)
    parser.add_argument('--evidence', type=Path, required=True)
    args = parser.parse_args()
    if not re.fullmatch(r'[A-Za-z0-9_]{1,39}', args.run_id):
        parser.error('Invalid run identifier')
    evidence = args.evidence.resolve()
    evidence.mkdir(parents=True, exist_ok=False)
    prefix = 'menu-qa-release-' + args.run_id.lower() + '-' + uuid.uuid4().hex[:8]
    app, db, network = prefix + '-app', prefix + '-db', prefix + '-net'
    checks = []

    def docker(*cmd, check=True, input=None):
        result = subprocess.run(['docker', *cmd], capture_output=True, input=input)
        if check and result.returncode:
            # Runtime commands contain no secret arguments; still omit output on failure.
            raise RuntimeError('Docker operation failed: ' + cmd[0])
        return result

    def passed(name, **details):
        checks.append({'check': name, 'status': 'PASS', **details})
        print(name + ': PASS', flush=True)

    def request(path, token=None, data=None):
        headers = {'Accept': 'application/json'}
        if token:
            headers['Authorization'] = 'Bearer ' + token
        if data is not None:
            headers['Content-Type'] = 'application/json'
        # Curl speaks real HTTP to Apache on container loopback. Internal Docker
        # networks need not publish host ports, and credentials stay off argv/logs.
        config = ['url = ' + json.dumps(url + path), 'include', 'silent', 'show-error',
                  'max-time = 150', 'noproxy = "*"']
        config.extend('header = ' + json.dumps(key + ': ' + value) for key, value in headers.items())
        if data is not None:
            config.append('data-binary = ' + json.dumps(json.dumps(data)))
        raw = docker('exec', '--user', 'www-data', '-i', app, 'curl', '--config', '-',
                     input=('\n'.join(config) + '\n').encode()).stdout
        while True:
            head, delimiter, body = raw.partition(b'\r\n\r\n')
            if not delimiter:
                raise RuntimeError('Invalid HTTP response from container Apache')
            line, _, header_lines = head.partition(b'\r\n')
            status = int(line.split()[1])
            response_headers = BytesParser().parsebytes(header_lines + b'\r\n')
            if status >= 200:
                break
            raw = body
        role = 'tenant_a_admin' if token == tokens.get('a') else ('tenant_b_admin' if token else 'anonymous')
        checks.append({'check': path, 'role': role, 'status': 'PASS', 'http_status': status})
        if status >= 400:
            sanitized = body.decode(errors='replace')
            for value in [*tokens.values(), env['APP_KEY'], env['DB_PASSWORD']]:
                if value:
                    sanitized = sanitized.replace(value, '[REDACTED]')
            (evidence / f'http-response-{len(checks)}.json').write_text(json.dumps({
                'url': url + path, 'role': role, 'status': status,
                'request': data, 'response': sanitized}, indent=2))
        return status, response_headers, body

    def expect(status, actual):
        if status != actual:
            checks[-1]['status'] = 'FAIL'
            raise RuntimeError('Unexpected HTTP status; see checks.json')

    try:
        with tempfile.TemporaryDirectory(prefix=prefix) as folder:
            runtime = Path(folder)
            image_id = docker('image', 'inspect', '--format', '{{.Id}}', args.image).stdout.decode().strip()
            (evidence / 'image-id.txt').write_text(image_id + '\n')
            # Inspect every historical image layer, not just the final filesystem.
            archive = runtime / 'image.tar'
            docker('image', 'save', '-o', str(archive), args.image)
            leaks = []
            with tarfile.open(archive) as image:
                manifest = json.load(image.extractfile('manifest.json'))
                image_config = json.load(image.extractfile(manifest[0]['Config']))
                for assignment in image_config.get('config', {}).get('Env', []):
                    key, _, value = assignment.partition('=')
                    if key in ['APP_KEY', 'DB_PASSWORD', 'MYSQL_ROOT_PASSWORD', 'MAIL_PASSWORD'] and value:
                        raise RuntimeError('Runtime credential embedded in image ENV: ' + key)
                for layer in manifest[0]['Layers']:
                    with tarfile.open(fileobj=image.extractfile(layer)) as files:
                        for member in files:
                            name = member.name.lstrip('./')
                            if not member.isfile() or not name.startswith('var/www/'):
                                continue
                            relative = name[len('var/www/'):]
                            if relative.startswith('vendor/'):
                                continue  # Dependency examples contain no supplied application config.
                            if (any(part.startswith('.env') for part in relative.split('/'))
                                or relative.endswith('.env') or relative == 'auth.json'
                                or fnmatch.fnmatch(relative, 'bootstrap/cache/config.php')):
                                leaks.append(relative)
            if leaks:
                raise RuntimeError('Secret-bearing configuration paths present in image layers')
            passed('all-image-layers-exclude-runtime-config')
            archive.unlink()
            missing_key = docker('run', '--rm', '--pull=never', '--network', 'none',
                                 '-e', 'APP_ENV=testing', '-e', 'APP_URL=http://127.0.0.1',
                                 '-e', 'DB_CONNECTION=mysql', '-e', 'DB_HOST=qa-db',
                                 '-e', 'DB_DATABASE=menu_test_QA_RUN_' + args.run_id + '_release',
                                 '-e', 'RUN_MIGRATIONS=false', '-e', 'RUN_STORAGE_LINK=false',
                                 '-e', 'RUN_CONFIG_CACHE=false', args.image, 'true', check=False)
            if missing_key.returncode == 0 or b'APP_KEY must be supplied' not in missing_key.stderr:
                raise RuntimeError('Image must reject missing APP_KEY before starting')
            passed('image-rejects-missing-runtime-key')

            docker('network', 'create', '--internal', network)
            database = 'menu_test_QA_RUN_' + args.run_id + '_release'
            url = 'http://127.0.0.1'
            env = {'APP_ENV': 'testing', 'APP_DEBUG': 'false', 'APP_URL': url,
                   'APP_KEY': 'base64:' + base64.b64encode(os.urandom(32)).decode(),
                   'DB_CONNECTION': 'mysql', 'DB_HOST': 'qa-db', 'DB_PORT': '3306',
                   'DB_DATABASE': database, 'DB_USERNAME': 'qa_runner', 'DB_PASSWORD': 'QA_RUN_disposable',
                   'DB_URL': '', 'QA_RUN_ID': args.run_id,
                   'MAIL_MAILER': 'array', 'MAIL_HOST': '127.0.0.1', 'QUEUE_CONNECTION': 'sync',
                   'BROADCAST_CONNECTION': 'null', 'CACHE_STORE': 'array', 'SESSION_DRIVER': 'array',
                   'FILESYSTEM_DISK': 'local', 'REDIS_HOST': '127.0.0.1', 'REDIS_URL': '',
                   'RUN_MIGRATIONS': 'true', 'RUN_STORAGE_LINK': 'true', 'RUN_CONFIG_CACHE': 'true'}
            # Clear every configured transport credential; no host environment is forwarded.
            for source in [*ROOT.glob('config/*.php'), ROOT / 'app/Providers/TestingSafetyServiceProvider.php']:
                for key in re.findall(r"['\"]([A-Z][A-Z0-9_]+)['\"]", source.read_text()):
                    if any(part in key for part in ['KEY', 'SECRET', 'TOKEN', 'PASSWORD', 'BUCKET',
                                                   'ENDPOINT', 'SERVICE_ACCOUNT', 'CLIENT_ID', 'APP_ID',
                                                   'MERCHANT_ID', 'VAPID', 'DEEPSEEK', 'STRIPE', 'PAYPAL',
                                                   'BRAINTREE', 'SQUARE', 'TWILIO', 'SMS', 'SLACK']):
                        env.setdefault(key, '')
            env_file = runtime / 'app.env'
            env_file.write_text(''.join(k + '=' + v + '\n' for k, v in env.items()))
            env_file.chmod(0o600)
            db_env = runtime / 'db.env'
            db_env.write_text('MYSQL_DATABASE=' + database + '\nMYSQL_USER=qa_runner\n'
                              'MYSQL_PASSWORD=QA_RUN_disposable\nMYSQL_ROOT_PASSWORD=QA_RUN_root_disposable\n')
            db_env.chmod(0o600)
            docker('run', '-d', '--pull=never', '--name', db, '--network', network,
                   '--network-alias', 'qa-db', '--env-file', str(db_env), 'mysql:8.0')
            docker('run', '-d', '--pull=never', '--name', app, '--network', network,
                   '--env-file', str(env_file), args.image)
            deadline = time.monotonic() + 120
            while True:
                probe = docker('exec', '--user', 'www-data', app, 'curl', '--noproxy', '*',
                               '--silent', '--max-time', '2', '--output', '/dev/null',
                               '--write-out', '%{http_code}', url + '/up', check=False)
                if probe.returncode == 0 and probe.stdout == b'200':
                    break
                if time.monotonic() >= deadline:
                    raise RuntimeError('Release-image boot timed out')
                time.sleep(1)
            passed('fresh-entrypoint-migrations-and-apache-boot', url=url)
            # Fixtures are mounted/copy-in after the build, so the tested image need not ship test tools.
            # The script expects its checked-in path depth.
            docker('exec', app, 'mkdir', '-p', '/var/www/scripts/qa')
            docker('cp', str(ROOT / 'scripts/qa/release-fixtures.php'), app + ':/var/www/scripts/qa/release-fixtures.php')
            effective = json.loads(docker('exec', '--user', 'www-data', app, 'php',
                                          'scripts/qa/release-fixtures.php', '--environment').stdout)
            (evidence / 'effective-environment.json').write_text(json.dumps(effective, indent=2))
            passed('cached-config-runtime-key-and-www-data-storage')
            source_hashes = {}
            for source, target in [('app/Services/InvoicePdfService.php', '/var/www/app/Services/InvoicePdfService.php'),
                                   ('docker/entrypoint.sh', '/usr/local/bin/entrypoint')]:
                expected = hashlib.sha256((ROOT / source).read_bytes()).hexdigest()
                actual = docker('exec', app, 'sha256sum', target).stdout.decode().split()[0]
                if expected != actual:
                    raise RuntimeError('Image source differs from the reviewed workspace: ' + source)
                source_hashes[source] = actual
            (evidence / 'runtime-source-hashes.json').write_text(json.dumps(source_hashes, indent=2))
            passed('image-matches-final-application-source')
            tooling = docker('exec', '--user', 'www-data', app, 'sh', '-c',
                             'id; cat /etc/os-release; php --version; chromium --version; find /usr/share/fonts -iname "*Arabic*"').stdout
            (evidence / 'image-runtime.txt').write_bytes(tooling)
            fixtures = json.loads(docker('exec', '--user', 'www-data', app, 'php',
                                         'scripts/qa/release-fixtures.php').stdout)
            tokens = fixtures['tokens']
            (evidence / 'fixtures.json').write_text(json.dumps(fixtures['metadata'], indent=2))
            items = [{'name': 'QA_RUN_' + args.run_id + '_Coffee', 'quantity': 1, 'unit_price': '4.50'},
                     {'name': 'QA_RUN_' + args.run_id + '_Tea شاي', 'quantity': 2, 'unit_price': '2.25'}]
            items.extend({'name': f'QA_RUN_{args.run_id}_Line_{i:03}', 'quantity': 1,
                          'unit_price': '1.10'} for i in range(1, 141))
            status, _, body = request('/api/admin/finance/invoices', tokens['a'], {
                'invoice_date': '2026-10-07', 'status': 'issued', 'vat_rate': 10,
                'service_charge_rate': 5, 'discount_type': 'fixed', 'discount_value': 3,
                'currency': 'EUR', 'exchange_rate': 1.2345, 'items': items})
            expect(201, status)
            invoice = json.loads(body)['invoice']
            if invoice['currency'] != 'EUR' or invoice['exchange_rate'] != '1.2345':
                raise RuntimeError('Invoice currency metadata differs from the supported contract')
            # Existing contract applies VAT and service independently to the discounted subtotal.
            # 16300 - 300 + 800 + 1600 = 18400 cents; do not call the app calculator.
            expected_amounts = {'subtotal': '163.00', 'discount_amount': '3.00',
                                'taxable_subtotal': '160.00', 'service_charge_amount': '8.00',
                                'vat_amount': '16.00', 'total': '184.00'}
            for field, expected in expected_amounts.items():
                if str(invoice[field]) != expected:
                    raise RuntimeError('Invoice does not match independent cents expectation: ' + field)
            (evidence / 'expected-amounts.json').write_text(json.dumps(expected_amounts, indent=2))
            passed('large-invoice-independent-total', expected_cents=18400)
            path = '/api/admin/finance/invoices/' + str(invoice['id']) + '/pdf'
            status, headers, body = request(path, tokens['a'])
            expect(200, status)
            if not body.startswith(b'%PDF-') or 'application/pdf' not in headers.get('Content-Type', ''):
                raise RuntimeError('Response is not a PDF')
            pdf = evidence / 'mixed-language-large-invoice.pdf'
            pdf.write_bytes(body)
            result = subprocess.run(['pdftotext', '-layout', str(pdf), '-'], capture_output=True, check=True)
            text = result.stdout.decode()
            for required in ['مطعم', 'شاي', f'QA_RUN_{args.run_id}_Line_140', '184.00', 'EUR', '1.2345']:
                if required not in text:
                    raise RuntimeError('PDF missing expected content: ' + required)
            if not re.search(r'Total\s+184\.00', text):
                raise RuntimeError('Printed total does not match independent cents expectation')
            (evidence / 'invoice-text.txt').write_text(text)
            fonts = subprocess.run(['pdffonts', str(pdf)], capture_output=True, check=True).stdout.decode()
            (evidence / 'pdf-fonts.txt').write_text(fonts)
            if 'NotoNaskhArabic' not in fonts and 'NotoSansArabic' not in fonts:
                raise RuntimeError('PDF did not embed the packaged Arabic font')
            info = subprocess.run(['pdfinfo', str(pdf)], capture_output=True, check=True).stdout.decode()
            (evidence / 'pdf-info.txt').write_text(info)
            pages = re.search(r'^Pages:\s+(\d+)', info, re.M)
            if not pages or int(pages[1]) < 2:
                raise RuntimeError('Large invoice must exercise multiple pages')
            for page in [1, int(pages[1])]:
                subprocess.run(['pdftoppm', '-png', '-r', '90', '-f', str(page), '-l', str(page),
                                '-singlefile', str(pdf), str(evidence / f'invoice-page-{page}')],
                               capture_output=True, check=True)
            ownership = docker('exec', app, 'find',
                               '/var/www/storage/framework/testing/disks/local/invoices',
                               '-type', 'f', '-name', '*.pdf', '-printf', '%u %g %m %P\n').stdout.decode()
            (evidence / 'pdf-storage-owner.txt').write_text(ownership)
            if not ownership.strip() or any(not line.startswith('www-data www-data ')
                                            for line in ownership.splitlines()):
                raise RuntimeError('HTTP-generated private PDF must be owned by the Apache runtime user')
            public_pdfs = docker('exec', app, 'find',
                                 '/var/www/storage/framework/testing/disks/public',
                                 '-type', 'f', '-name', '*.pdf', '-print').stdout
            if public_pdfs.strip():
                raise RuntimeError('Invoice PDF appeared on the public disk')
            passed('private-arabic-english-multipage-pdf-and-total', bytes=len(body), pages=int(pages[1]),
                   runtime_user='www-data', storage='private-local-disk')
            status, _, _ = request(path, tokens['b'])
            expect(404, status)
            status, _, _ = request(path)
            expect(401, status)
            passed('foreign-tenant-and-anonymous-pdf-denial')
            status, _, cached = request(path, tokens['a'])
            expect(200, status)
            if cached != body:
                raise RuntimeError('Cached PDF changed')
            passed('cached-private-pdf-reuse')
            profiles = docker('exec', app, 'sh', '-c', 'find /tmp -maxdepth 1 -name "invoice-*-chrome"').stdout
            if profiles.strip():
                raise RuntimeError('Browser profiles leaked')
            passed('browser-profile-cleanup')
    except Exception as error:
        checks.append({'check': 'release-smoke', 'status': 'FAIL', 'error': str(error)})
        raise
    finally:
        # Logs may contain synthetic tokens: retain only after explicit redaction.
        result = docker('logs', app, check=False)
        application_log = docker('exec', app, 'sh', '-c',
                                 'cat storage/logs/laravel.log 2>/dev/null || true', check=False)
        logs = (result.stdout + result.stderr + application_log.stdout).decode(errors='replace')
        if 'env' in locals():
            for key, value in env.items():
                if value and any(marker in key for marker in ['KEY', 'PASSWORD', 'TOKEN', 'SECRET']):
                    logs = logs.replace(value, '[REDACTED]')
        if 'tokens' in locals():
            for value in tokens.values():
                logs = logs.replace(value, '[REDACTED]')
        (evidence / 'container.log').write_text(logs)
        for name in [app, db]:
            docker('rm', '-f', '-v', name, check=False)
        docker('network', 'rm', network, check=False)
        leftovers = docker('ps', '-a', '--filter', 'name=' + prefix, '--format', '{{.Names}}').stdout
        networks = docker('network', 'ls', '--filter', 'name=' + network, '--format', '{{.Name}}').stdout
        checks.append({'check': 'owned-runtime-cleanup', 'status': 'FAIL' if leftovers.strip() or networks.strip() else 'PASS'})
        (evidence / 'checks.json').write_text(json.dumps(checks, indent=2))


if __name__ == '__main__':
    main()
