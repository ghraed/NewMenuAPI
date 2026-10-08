# Disposable QA baseline (roadmap Task 0)

Run from `Menu_API`, with `Menu_React` beside it:

```sh
python3 scripts/qa/run.py --launch --react-root ../Menu_React --run-id QA_20261007_example --evidence /tmp/menu-evidence-QA_20261007_example
```

Choose a new run ID and evidence directory each time. Evidence directories cannot already exist. No normal `.env` file is edited. The runner records both branches, commits and working trees before execution. A dirty working tree is recorded, never discarded.

Prerequisites: Linux, Python 3, MySQL 8 `mysqld` and `mysql`, PHP 8.4 with the Composer-required extensions, Composer 2, Node >=22.12/npm, Google Chrome (`google-chrome`), Poppler (`pdftotext`) and fonts supporting Arabic (e.g. `fonts-noto-core`). Chrome also needs its usual Linux shared libraries. Install the locked project dependencies with `composer install --no-interaction --prefer-dist` and `npm ci` in the API, and `npm ci` in the frontend. Do not run `composer setup` (it migrates the default database). Composer's install hooks perform package discovery, not migrations.

The runner requires no Docker daemon or DB administrator access. It initializes a **new MySQL data directory**, ignoring host MySQL option files, and binds a new server to an available loopback port. It creates a restricted synthetic user with access only to:

- `menu_test_QA_RUN_<run_id>_suite`
- `menu_test_QA_RUN_<run_id>_browser`

The empty database password is confined to this disposable loopback instance; it is never a credential for the normal database. The private socket and runtime directory are owner-only. No existing MySQL process, schema or Compose stack is used. `restaurantdb`, production Compose files, live domains and `.env.production` are excluded from execution.

Before migrations, the runner boots the QA preflight and records effective `APP_ENV=testing`, URL, MySQL host/port/schema, isolated storage and safe transport drivers. The guarded QA HTTP entrypoint uses a file cache under this run's isolated storage so POS idempotency can be verified across real requests; fixture cleanup flushes only that owned cache. PHPUnit retains its safe array-cache default. Credentials inherited from the shell are discarded. Known mail/payment/SMS/AI/push/storage credentials are blanked, the testing safety provider remains active, and QA HTTP requests cannot make stray Laravel HTTP calls. The frontend is built with `VITE_API_URL=/api`, synthetic loopback realtime keys in launch mode (blank otherwise), a synthetic guest slug and a proxy to only this run's API. It serves the resulting build, not an existing developer server.

Actual commands executed: `php artisan migrate:fresh --env=testing --force`, `php artisan test --log-junit ...`, API `npm run build`, `npm run test:tooling`, `composer validate --no-check-publish`, `vendor/bin/pint --test`, `composer audit --format=json`, and `npm audit --json`. With the frontend selected: `npm run lint`, `npm run test:unit` with JUnit reporting, `npm run build -- --mode qa --outDir ...`, `npm run preview` with loopback/strict port, and `npm run test:e2e`. No dependency versions are changed.

`--browser-only --react-root ../Menu_React` runs frontend lint/unit/build/browser checks without repeating the API suite. Omitting `--react-root` runs the API gates only. Direct Playwright execution now requires the explicit runner environment and rejects absent or remote targets before creating a browser or fixture.

Every browser scenario verifies the QA marker through both API and frontend origins. The guarded fixture entrypoint then migrates its own browser schema and creates `QA_RUN_<run_id>` synthetic restaurant/admin records. Features match the audit baseline: core commerce/finance/reservations enabled; AI, AR/3D, push and custom domain disabled. The exact flags are saved in `browser-fixtures.json`. Scenarios run with one worker and independent browser contexts. Schema and uploaded QA storage are cleared after each scenario, including failures. Existing API tests keep their independent factory fixtures and assertions inside the separate disposable suite schema; they do not use browser accounts.

Each scenario has its own synthetic account suffix and password. External browser requests are blocked; only the optional Google Fonts CSS is fulfilled with an empty stylesheet so local fallback fonts can render without a remote request. API behavior is not mocked by this network guard. Successful dashboard navigation is asserted with Playwright's final-URL expectation: the existing login implementation issues overlapping redirects, and waiting on the first document's load can fail with `ERR_ABORTED` even when the final dashboard loads. Response statuses, persistence, totals, guest network assertions and all other scenario expectations remain intact.

Evidence includes `repository-environment.json`, effective environment logs, API/frontend/browser JUnit, build/lint/audit logs, `checks.json`, browser HTML report and failure screenshots/traces. Synthetic credentials and bearer tokens are redacted from retained text and trace archives. The original mocked guest lifecycle remains a fast UI regression; five other browser scenarios use the real QA API. The additional Task 8 scenario uses real guest PIN, staff/chef transitions, finalization, PDF and finance with separate users and two tenants. Use `--launch` for paired browser runs; the new release scenario fails closed without its owned realtime runtime.

The runner stops owned API/frontend processes, shuts MySQL down through **its private socket**, and removes only its runtime directory, databases and storage. This also handles ordinary errors and Ctrl+C. If shutdown fails it exits nonzero, records a blocker and retains the owned runtime for diagnosis instead of removing active data. Never stop another MySQL instance to resolve a cleanup issue. SIGKILL or host power loss cannot guarantee automatic cleanup; use the retained owner file and recorded private socket to identify this run before manual cleanup.

Required test/build failures stop dependent execution and exit nonzero. Skipped JUnit cases cannot satisfy the baseline gate. After Task 7, audit/style checks are required gates: nonzero results stop execution, and unreachable advisory services are BLOCKED. A green functional job is not launch approval.

## CI and paired rollout

GitHub Actions is used because both repositories' origins are on GitHub and neither had an existing pipeline. API CI runs fresh migrations, full PHP suite, asset build, Composer validation and paired frontend/browser gates with retained evidence. Frontend CI runs lint, unit tests, build and a separate isolated browser job. Artifacts are retained for 14 days even after failures.

Integrate the API QA tooling first, then the frontend changes, or use a reviewed paired API ref for the initial frontend branch. The frontend browser job uses workflow input `api_ref`, then repository variable `MENU_QA_API_REF`, then API `main`; the resolved commit is recorded in evidence. For a private companion repository, configure a read-only `MENU_QA_READ_TOKEN` accessible to trusted runs. The current repository token cannot read a different private repository. Fork runs without that access must be reported blocked, not counted as browser passes. No token is passed to the QA application runtime or persisted by checkout. Review the companion ref when testing paired contract changes.

API CI similarly uses workflow input `react_ref`, repository variable `MENU_QA_REACT_REF`, then frontend `main`. For initial paired rollout, configure both reviewed companion refs or run against local branches until both halves have been integrated. An old frontend without the guarded fixture/config changes cannot satisfy the new paired QA contract. This affects QA tooling only, not old/new production API clients.

After reviewing actual remote executions, configure repository branch protection for `api` (API repository), `frontend` and `browser` (frontend repository). This local task does not change GitHub settings, push branches, merge or trigger a remote workflow. YAML parsing locally is not proof of a hosted Actions run.

Rollback: revert only the paired Task 0 tooling changes after review. No application routes, money rules, deployed migrations, settled invoices, tenant permissions or dependency locks change. Remove the QA CI checks from branch protection first if intentionally withdrawing the tooling. Production Docker secret/PDF packaging remains Tasks 6/7 and is not verified by these host-based QA checks.

Workflow semantics and artifact retention follow the [GitHub Actions documentation](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax) and [upload-artifact documentation](https://github.com/actions/upload-artifact).

## Release image verification (roadmap Task 6)

Use the same `Dockerfile` as deployment, with no source bind mount:

```sh
python3 -m unittest discover -s scripts/qa/release-tests -v
docker build --progress=plain -t menu-api-qa-task6:local .
python3 scripts/qa/release.py --image menu-api-qa-task6:local --run-id task6_example --evidence /tmp/menu-release-task6-example
```

Requires a local Docker daemon, a previously available `mysql:8.0` image and host
Poppler tools (`pdftotext`, `pdffonts`, `pdfinfo`, `pdftoppm`). The runner never invokes the production/default Compose files, forwards
host credentials, publishes MySQL, or touches existing containers/volumes. It uses
unique container names and a new **internal** network (no runtime internet access),
a synthetic runtime env file, a new MySQL schema and ephemeral database volume.
The HTTP client runs as `www-data` inside the image and speaks real HTTP to Apache
on container loopback (`http://127.0.0.1:80`); no host port is published. All credentials
are discarded with the temporary directory. Every owned container, anonymous volume
and network is removed on completion/failure; evidence retains no keys or bearer tokens.

The context regression uses Docker's real COPY matcher against synthetic files only.
The release runner scans all image layers for application env files and cached config,
boots with APP_ENV=testing and the application's safety provider, executes fresh
migrations and config caching through the actual entrypoint, and verifies the supplied
key survives caching. It seeds two synthetic tenants without requiring development
Composer dependencies. Real authenticated HTTP requests verify a 142-item mixed
Arabic/English invoice, independent EUR 184.00 total, private PDF response, cached
reuse, foreign-tenant 404 and anonymous 401. Both tenants explicitly enable the registered
invoice routes' finance_dashboard, vat_invoices and expense_management flags. Fixtures, the HTTP client and Chrome execute as `www-data`; private PDF file
ownership also verifies the actual Apache user.
PHP/Chromium versions, font selection, effective config, sanitized HTTP statuses,
PDF and extracted text are retained. Application workers retain their established
entrypoint/command behavior; operational worker/scheduler delivery remains Task 8.

## Runtime configuration and rollback

All `.env*` files (including examples), `*.env`, Composer authentication files and
cached Laravel configuration are excluded from the build context. Build without
runtime keys/passwords; never use build arguments for secrets. Existing production
Compose `env_file: .env.production` / `docker/db.env` remains the configuration mechanism:
Compose reads these files on the deployment host and passes values at runtime, without
copying them into the image. Supply the **existing** APP_KEY to app, worker, scheduler
and realtime services. Missing APP_KEY stops startup before DB access; startup never
writes `.env` or generates/rotates a key. Mounted runtime `.env` alone is insufficient:
export APP_KEY through the orchestrator/env_file. Preserve the existing DB settings,
bridge/storage volumes and worker/scheduler commands. Recreate services after config
changes so each service regenerates/uses its own runtime config; do not reuse a baked
config.php. After runtime Artisan initialization, the root entrypoint restores storage/cache
ownership to Apache's `www-data` user, including newly populated named volumes.

Chromium and Noto Arabic fonts are installed by the Debian-based PHP image. Each PDF
uses a private temporary profile, cleaned even on browser failure, with writable XDG configuration/cache directories, and `/tmp` instead
of the container's small `/dev/shm`. The existing `--no-sandbox` behavior is retained;
this task does not introduce a sandbox policy change. `/tmp` and private storage must
be writable. Existing invoice private-storage paths and cache behavior are retained.

Before an authorized release, retain the approved, tested image digest and the previous
**known working, secret-free** image digest. Roll back by recreating the same services
with that retained image and the same runtime APP_KEY, DB configuration and storage
volumes; do not roll back data or rotate keys. There are no schema migrations in Task 6.
No previous deployed image was provided/inspected here; the audited image recipe is
not a safe rollback target. The locally verified digest is recorded in Task 6 evidence;
deployment approval, registry publication and fleet rollback rehearsal remain unexecuted.

Chrome PDF options: [official headless documentation](https://developer.chrome.com/docs/automation-and-testing/headless).

Task 7 security dependency decisions and scoped shell-quote override review: see
`Menu_React/docs/testing/task-7-2026-10-07/`. The override is restricted to
concurrently and can be removed when upstream pins a safe version and the audit
and argument/cleanup regressions pass. Frontend unit tooling requires Node >=22.12.


## Task 8 launch lifecycle and disposable operational staging

The required paired CI jobs now use `--launch` and retain failure artifacts for 14 days.
Run the same gate locally with a fresh ID/evidence directory:

```sh
python3 scripts/qa/run.py --launch --react-root ../Menu_React --run-id task8_example --evidence /tmp/menu-launch-task8-example
```

For iteration after a passing API suite, `--launch --browser-only --react-root ../Menu_React`
runs frontend checks, all browser scenarios and the operational/recovery gates. This is
not a replacement for the full paired release run. No skipped cases can satisfy either gate. Launch mode also requires the named real
lifecycle case in browser JUnit, so an older companion frontend cannot silently pass
the launch gate with only the 16 pre-existing scenarios.
The mocked guest regression is preserved unchanged. The added real browser scenario
requires launch mode, real PIN verification, separate guest/staff/chef/admin contexts,
two tenants, denied unassigned staff and finance roles, foreign session/order/invoice/PDF
and broadcast authorization, disabled ordering/chat/custom-host behavior, a paid receipt
and independently calculated USD 25.30 finance revenue (25.00 less 2.00 plus 2.30 VAT).

The existing safety provider remains unchanged. Guarded QA-only bootstrap code first
verifies testing configuration and the run-owned browser schema, storage, bridge and
Reverb port, then permits database queues and a synthetic Reverb application bound to
loopback. It re-registers the existing application channel policies on the local
Reverb driver after the safety provider initially registered them on the null driver. Reverb scaling is off; live notification credentials stay blank. No production
router includes these QA entrypoints. All API response assertions remain real. The
realtime test proxies genuine server frames, replays the same application event twice
through Reverb, interrupts/reconnects the transport, then holds it down while the real
8-second kitchen poll reconciles a served order. No API response or event is fabricated.

The operations runner creates synthetic fixtures only after browser fixture cleanup.
A separate actual queue worker must consume a pending application domain job and persist
its verified domain mapping with no failed jobs. The domain-provisioner bridge receives
an explicit synthetic acknowledgement; public DNS, TLS issuance and external provisioning
are NOT tested. HTTP Host headers for reserved `*.qa.invalid` names target only loopback;
unknown/disabled hosts must fail. Never substitute a public domain or edit host DNS here.
An actual `schedule:work` daemon must spawn the guarded `schedule:run` and persist the
T-1 reminder logs for due events only. The due-time clock is fixed only in schedule:run;
the worker daemon uses wall-clock time. A repeated invocation must preserve the same
12 role/channel logs, with no draft/future event deliveries. Push endpoints are disabled.

Recovery rehearsal backs up only the owned browser schema with `mysqldump`, plus the
synthetic private/public disk tree and its file checksums. Writers are quiesced first.
It runs the latest real migration down/up, checks the settled invoice survives, wipes
only that owned schema/disk tree, restores both backups, and compares every table's row
count/hash and every file hash. It checks paid invoice total, session finalization link,
private storage, decryption of backed-up encrypted content with the retained runtime key,
no pending migrations and real authenticated HTTP invoice/finance and host routing.
Raw backups and key remain in the disposable runtime and are deleted on cleanup;
evidence keeps hashes/outcomes, not raw credential-bearing SQL or storage archives.

The latest `add_finalized_invoice_to_table_sessions` down migration drops finalization
links. An up migration cannot recover those values. A data rollback therefore requires
a matching verified backup and a maintenance window with writers stopped. In a real
release, prefer rolling back application images while retaining compatible additive
schema and settled data. This synthetic rehearsal is not approval to restore production,
roll back all historical migrations, or claim an old deployed image is known working.
Task 8 introduces QA tooling only; application code, deployed schema and image behavior
are unchanged. Fleet image rollback, public TLS provisioning and hosted branch-protection
settings require separately verified infrastructure. The workflows fail the existing
API/frontend/browser jobs on any launch-gate failure. On 7 October 2026, the actual
GitHub check identifiers were read from successful runs on both current main heads,
and required branch protection was configured for API `api` and frontend `frontend`
plus `browser`, with strict up-to-date checks and administrator enforcement. The
Task 8 source changes still need paired integration and their own hosted execution;
local YAML validation and local tests do not prove that future hosted run.

Evidence: real-lifecycle-http.json, feature/role attachment, receipt PDF, finance screenshot,
browser JUnit/HTML/screenshots/traces; operational-checks.json; worker/scheduler/migration/
restore logs; effective environment and paired Git commits. No skipped or blocked work
is counted as passing. Use the runner's owned-process cleanup, never a global kill/wipe.

Focused iteration only: `--browser-only --runtime-only --e2e-spec real-order-lifecycle.spec.ts --launch --react-root ../Menu_React` omits lint/unit gates and other browser specs, reporting them NOT EXECUTED. CI never uses these focused options.
