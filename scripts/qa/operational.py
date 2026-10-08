"""Required operational rehearsals inside run.py's owned disposable infrastructure."""
import hashlib
import json
import os
from pathlib import Path
import signal
import subprocess
import tarfile
import time
from datetime import datetime, timezone
import shutil
import urllib.request
import urllib.error

API = Path(__file__).resolve().parents[2]


def main():
    def interrupted(signum, frame):
        raise KeyboardInterrupt
    signal.signal(signal.SIGTERM, interrupted)
    env = dict(os.environ)
    runtime = Path(env['QA_RUNTIME_DIR']).resolve()
    evidence = Path(env['QA_EVIDENCE_DIR']).resolve()
    run = env['QA_RUN_ID']
    owner = json.loads((runtime/'owner.json').read_text())
    if (env.get('QA_OPERATIONAL') != '1' or env.get('APP_ENV') != 'testing'
        or env.get('DB_DATABASE') != f'menu_test_QA_RUN_{run}_browser'
        or env.get('DB_HOST') != '127.0.0.1' or owner['run_id'] != run
        or str(owner['db_port']) != env['DB_PORT']
        or env.get('DOMAIN_PROVISIONING_BRIDGE_DIR') != str(runtime/'bridge')):
        raise RuntimeError('Refusing an unowned operational target.')
    outcomes = []
    processes = []
    handles = []

    def command(name, args, parse=False):
        result = subprocess.run(args, cwd=API, env=env, capture_output=True, text=True, timeout=90)
        (evidence/f'operations-{name}.log').write_text(result.stdout + result.stderr)
        if result.returncode:
            raise RuntimeError(f'{name} failed; retained command log contains evidence.')
        if parse:
            try:
                return json.loads(result.stdout)
            except ValueError:
                raise RuntimeError(f'{name} did not return valid fixture JSON; see retained log.') from None
        return result.stdout

    def fixture(name):
        return command(name, ['php', 'scripts/qa/operational-fixtures.php', name], True)

    def background(name, args):
        log = (evidence/f'operations-{name}.log').open('w')
        handles.append(log)
        proc = subprocess.Popen(args, cwd=API, env=env, stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        processes.append(proc)
        return proc

    def stop(proc):
        if proc.poll() is None:
            os.killpg(proc.pid, signal.SIGTERM)
            try:
                proc.wait(timeout=10)
            except subprocess.TimeoutExpired:
                os.killpg(proc.pid, signal.SIGKILL)
                proc.wait(timeout=10)

    try:
        # Laravel's guarded effective configuration must pass before any database mutation.
        command('environment', ['php', 'scripts/qa/environment.php'])
        rejected_env = {**env, 'QA_RUNTIME_DIR': str(runtime/'not-owned')}
        rejected = subprocess.run(['php', 'scripts/qa/console.php', 'list'], cwd=API, env=rejected_env,
                                  capture_output=True, text=True, timeout=10)
        if rejected.returncode == 0 or 'Refusing an unverified QA runtime/database' not in rejected.stderr:
            raise RuntimeError('Operational console failed to reject an unowned runtime before boot.')
        outcomes.append({'check': 'unowned-operational-runtime-rejected', 'status': 'PASS', 'exit_nonzero': True})
        if not shutil.which('mysqldump'):
            raise RuntimeError('mysqldump is required for the restore release gate.')
        now = datetime.now(timezone.utc).replace(second=0, microsecond=0)
        env['QA_SCHEDULE_TIME'] = now.replace(minute=(now.minute//15)*15).strftime('%Y-%m-%d %H:%M:%S')
        seeded = fixture('seed')
        bridge = runtime/'bridge'
        (bridge/'requests').mkdir(parents=True)
        (bridge/'results').mkdir(parents=True)
        worker = background('worker', ['php', 'scripts/qa/console.php', 'queue:work', '--tries=3', '--timeout=300', '--sleep=1', '--stop-when-empty'])
        deadline = time.monotonic()+20
        responded = False
        while time.monotonic() < deadline:
            requests = list((bridge/'requests').glob('*.json'))
            if requests:
                req = json.loads(requests[0].read_text())
                if req['domain'] != 'operations.qa.invalid':
                    raise RuntimeError('Queue bridge attempted an unexpected domain.')
                # Explicit synthetic bridge boundary: no DNS, certificates, host config or internet calls.
                (bridge/'results'/requests[0].name).write_text(json.dumps({'success': True, 'output': 'QA_RUN isolated bridge acknowledgement; no DNS/TLS operations'}))
                responded = True
                break
            if worker.poll() is not None:
                raise RuntimeError('Worker exited without consuming the real queued job.')
            time.sleep(.1)
        if not responded or worker.wait(timeout=20) != 0:
            raise RuntimeError('Worker delivery did not complete.')
        outcomes.append({'check': 'queue-worker', 'status': 'PASS', **fixture('worker-check'), 'domain_bridge': 'synthetic; DNS/TLS provisioning not exercised'})

        # Boot the real schedule:work daemon, which spawns guarded schedule:run at the next minute.
        scheduler = background('scheduler', ['php', 'scripts/qa/console.php', 'schedule:work'])
        deadline = time.monotonic()+75
        while time.monotonic() < deadline:
            if scheduler.poll() is not None:
                raise RuntimeError('Scheduler daemon exited before a due command ran.')
            try:
                scheduled = fixture('scheduler-check')
                break
            except RuntimeError:
                time.sleep(1)
        else:
            raise RuntimeError('Scheduler daemon did not deliver the due reminder.')
        stop(scheduler)
        command('scheduler-repeat', ['php', 'scripts/qa/console.php', 'schedule:run'])
        if fixture('scheduler-check') != scheduled:
            raise RuntimeError('Duplicate scheduler invocation repeated reminder delivery.')
        outcomes.append({'check': 'scheduler-daemon-and-dedupe', 'status': 'PASS', **scheduled, 'due_time': env['QA_SCHEDULE_TIME']})

        def host_request(host):
            req = urllib.request.Request(env['QA_API_URL']+'/api/menu/table/1', headers={'Host': host, 'Accept': 'application/json'})
            try:
                with urllib.request.urlopen(req, timeout=5) as response:
                    return response.status, json.load(response)
            except urllib.error.HTTPError as error:
                return error.code, None
        status, body = host_request('operations.qa.invalid')
        (evidence/'operations-host-status.json').write_text(json.dumps({'host': 'operations.qa.invalid',
            'endpoint': '/api/menu/table/1', 'status': status, 'restaurant_id': body.get('restaurant', {}).get('id') if body else None}))
        if status != 200 or body['restaurant']['name'] != f'QA_RUN_{run}_operations':
            raise RuntimeError('Isolated custom-host HTTP routing selected the wrong tenant.')
        if host_request('unmapped.qa.invalid')[0] != 404:
            raise RuntimeError('Unknown custom host unexpectedly resolved.')
        outcomes.append({'check': 'isolated-custom-host-http', 'status': 'PASS', 'mapped_status': 200, 'unmapped_status': 404})

        def request_fixture(action):
            return command('request-'+action, ['php', 'scripts/qa/task9-operational-fixtures.php', action], True)
        request_seed = request_fixture('seed')
        before = fixture('fingerprint')
        db = env['DB_DATABASE']
        sql = runtime/'synthetic-backup.sql'
        args = ['--no-defaults', '--protocol=tcp', '--host=127.0.0.1', f"--port={env['DB_PORT']}", '--user=qa_runner']
        with sql.open('wb') as output:
            dump = subprocess.run(['mysqldump', *args, '--single-transaction', '--no-tablespaces', '--skip-comments', '--set-gtid-purged=OFF', db], cwd=API, env=env, stdout=output, stderr=subprocess.PIPE, timeout=60)
        if dump.returncode:
            raise RuntimeError('Synthetic database backup failed.')
        disks = runtime/'storage/framework/testing/disks'
        archive = runtime/'synthetic-storage.tar'
        file_hashes = {str(p.relative_to(disks)): hashlib.sha256(p.read_bytes()).hexdigest() for p in disks.rglob('*') if p.is_file()}
        with tarfile.open(archive, 'w') as backup:
            backup.add(disks, arcname='disks')
        key_hash = hashlib.sha256(env['APP_KEY'].encode()).hexdigest()
        command('migration-down', ['php', 'scripts/qa/console.php', 'migrate:rollback', '--step=1', '--force'])
        request_down = request_fixture('down')
        command('migration-up', ['php', 'scripts/qa/console.php', 'migrate', '--force'])
        request_up = request_fixture('up')
        # Preserve Task 8's exact rollback assertions against its named migration.
        finalization_path = 'database/migrations/2026_10_06_000200_add_finalized_invoice_to_table_sessions.php'
        command('task8-migration-down', ['php', 'scripts/qa/console.php', 'migrate:rollback',
                f"--batch={request_seed['task8_batch']}", f'--path={finalization_path}', '--force'])
        down = fixture('rollback-check')
        command('task8-migration-up', ['php', 'scripts/qa/console.php', 'migrate', f'--path={finalization_path}', '--force'])
        # Down/up is not a data restore: finalized_invoice_id values were dropped. Restore must recover them.
        command('wipe-owned-schema', ['php', 'scripts/qa/console.php', 'db:wipe', '--force'])
        shutil.rmtree(disks)
        # Bootstrapping the safety provider recreates empty disk directories.
        if fixture('fingerprint') or any(p.is_file() for p in disks.rglob('*')):
            raise RuntimeError('Recovery fault injection did not clear the owned data and storage.')
        with sql.open('rb') as backup:
            restored = subprocess.run(['mysql', *args, db], cwd=API, env=env, stdin=backup, capture_output=True, timeout=60)
        if restored.returncode:
            raise RuntimeError('Synthetic database restore failed.')
        with tarfile.open(archive) as backup:
            backup.extractall(disks.parent, filter='data')
        after = fixture('fingerprint')
        restored_hashes = {str(p.relative_to(disks)): hashlib.sha256(p.read_bytes()).hexdigest() for p in disks.rglob('*') if p.is_file()}
        if before != after or file_hashes != restored_hashes or hashlib.sha256(env['APP_KEY'].encode()).hexdigest() != key_hash:
            raise RuntimeError('Restored rows/storage/key do not match the synthetic backup.')
        request_restore = request_fixture('restore')
        receipt = fixture('restore-check')
        # Keep the invoice's paid state separate from the QA outcome status.
        receipt['invoice_status'] = receipt.pop('status')
        command('post-restore-migrate', ['php', 'scripts/qa/console.php', 'migrate', '--force'])
        if fixture('fingerprint') != before or host_request('operations.qa.invalid')[0] != 200:
            raise RuntimeError('Post-restore migration or real HTTP smoke changed/restored data incorrectly.')
        # Verify restored money through real authenticated HTTP, beyond SQL/file integrity.
        login = urllib.request.Request(env['QA_API_URL']+'/api/auth/login',
            data=json.dumps({'email': f'QA_RUN_{run}_operations@example.invalid',
                             'password': env['PLAYWRIGHT_PROFILE_PASSWORD']}).encode(),
            headers={'Accept': 'application/json', 'Content-Type': 'application/json'})
        with urllib.request.urlopen(login, timeout=5) as response:
            token = json.load(response)['token']
        def restored_api(endpoint):
            req = urllib.request.Request(env['QA_API_URL']+'/api'+endpoint,
                headers={'Accept': 'application/json', 'Authorization': 'Bearer '+token})
            with urllib.request.urlopen(req, timeout=5) as response:
                return json.load(response)
        invoice = restored_api(f"/admin/finance/invoices/{seeded['invoice_id']}")['invoice']
        finance = restored_api('/admin/finance/dashboard-metrics')['kpis']
        if invoice['total'] != '25.30' or invoice['status'] != 'paid' or finance['revenue']['value'] != 25.3 or finance['invoice_count']['value'] != 1:
            raise RuntimeError('Post-restore HTTP invoice/finance invariants failed.')
        outcomes.extend([
            {'check': 'latest-request-migration-down-up', 'status': 'PASS', **request_down, **request_up, **request_restore},
            {'check': 'task8-finalization-migration-down-up', 'status': 'PASS', **down, 'data_restore_required': 'finalized_invoice_id links are lost by down(); recovered from backup'},
            {'check': 'synthetic-backup-destruction-restore', 'status': 'PASS', 'table_fingerprints': after,
             'storage_sha256': restored_hashes, 'runtime_key_preserved': True, **receipt},
            {'check': 'post-restore-authenticated-finance-http', 'status': 'PASS', 'login_status': 200,
             'invoice_status': 200, 'finance_status': 200, 'paid_total': '25.30', 'revenue': 25.3, 'invoice_count': 1},
        ])
    except Exception as error:
        outcomes.append({'check': 'operational-recovery', 'status': 'FAIL' if isinstance(error, RuntimeError) else 'BLOCKED', 'reason': str(error) if isinstance(error, RuntimeError) else type(error).__name__})
        raise
    finally:
        for proc in reversed(processes):
            stop(proc)
        for log in handles:
            log.close()
        (evidence/'operational-checks.json').write_text(json.dumps(outcomes, indent=2))


if __name__ == '__main__':
    main()
