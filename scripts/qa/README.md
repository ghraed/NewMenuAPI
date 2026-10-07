# Disposable QA baseline (roadmap Task 0)

Run from `Menu_API`, with `Menu_React` beside it:

```sh
python3 scripts/qa/run.py --react-root ../Menu_React --run-id QA_20261007_example --evidence /tmp/menu-evidence-QA_20261007_example
```

Choose a new run ID and evidence directory each time. Evidence directories cannot already exist. No normal `.env` file is edited. The runner records both branches, commits and working trees before execution. A dirty working tree is recorded, never discarded.

Prerequisites: Linux, Python 3, MySQL 8 `mysqld` and `mysql`, PHP 8.4 with the Composer-required extensions, Composer 2, Node 22/npm, Google Chrome (`google-chrome`), Poppler (`pdftotext`) and fonts supporting Arabic (e.g. `fonts-noto-core`). Chrome also needs its usual Linux shared libraries. Install the locked project dependencies with `composer install --no-interaction --prefer-dist` and `npm ci` in the API, and `npm ci` in the frontend. Do not run `composer setup` (it migrates the default database). Composer's install hooks perform package discovery, not migrations.

The runner requires no Docker daemon or DB administrator access. It initializes a **new MySQL data directory**, ignoring host MySQL option files, and binds a new server to an available loopback port. It creates a restricted synthetic user with access only to:

- `menu_test_QA_RUN_<run_id>_suite`
- `menu_test_QA_RUN_<run_id>_browser`

The empty database password is confined to this disposable loopback instance; it is never a credential for the normal database. The private socket and runtime directory are owner-only. No existing MySQL process, schema or Compose stack is used. `restaurantdb`, production Compose files, live domains and `.env.production` are excluded from execution.

Before migrations, the runner boots the QA preflight and records effective `APP_ENV=testing`, URL, MySQL host/port/schema, isolated storage and safe transport drivers. Credentials inherited from the shell are discarded. Known mail/payment/SMS/AI/push/storage credentials are blanked, the testing safety provider remains active, and QA HTTP requests cannot make stray Laravel HTTP calls. The frontend is built with `VITE_API_URL=/api`, blank realtime keys, a synthetic guest slug and a proxy to only this run's API. It serves the resulting build, not an existing developer server.

Actual commands executed: `php artisan migrate:fresh --env=testing --force`, `php artisan test --log-junit ...`, API `npm run build`, `composer validate --no-check-publish`, `vendor/bin/pint --test`, `composer audit --format=json`, and `npm audit --json`. With the frontend selected: `npm run lint`, `npm run test:unit` with JUnit reporting, `npm run build -- --mode qa --outDir ...`, `npm run preview` with loopback/strict port, and `npm run test:e2e`. No dependency versions are changed.

`--browser-only --react-root ../Menu_React` runs frontend lint/unit/build/browser checks without repeating the API suite. Omitting `--react-root` runs the API gates only. Direct Playwright execution now requires the explicit runner environment and rejects absent or remote targets before creating a browser or fixture.

Every browser scenario verifies the QA marker through both API and frontend origins. The guarded fixture entrypoint then migrates its own browser schema and creates `QA_RUN_<run_id>` synthetic restaurant/admin records. Features match the audit baseline: core commerce/finance/reservations enabled; AI, AR/3D, push and custom domain disabled. The exact flags are saved in `browser-fixtures.json`. Scenarios run with one worker and independent browser contexts. Schema and uploaded QA storage are cleared after each scenario, including failures. Existing API tests keep their independent factory fixtures and assertions inside the separate disposable suite schema; they do not use browser accounts.

Each scenario has its own synthetic account suffix and password. External browser requests are blocked; only the optional Google Fonts CSS is fulfilled with an empty stylesheet so local fallback fonts can render without a remote request. API behavior is not mocked by this network guard. Successful dashboard navigation is asserted with Playwright's final-URL expectation: the existing login implementation issues overlapping redirects, and waiting on the first document's load can fail with `ERR_ABORTED` even when the final dashboard loads. Response statuses, persistence, totals, guest network assertions and all other scenario expectations remain intact.

Evidence includes `repository-environment.json`, effective environment logs, API/frontend/browser JUnit, build/lint/audit logs, `checks.json`, browser HTML report and failure screenshots/traces. Synthetic credentials and bearer tokens are redacted from retained text and trace archives. The original mocked guest lifecycle remains a fast UI regression; five other browser scenarios use the real QA API. Full guest-to-kitchen-to-invoice coverage belongs to Task 8.

The runner stops owned API/frontend processes, shuts MySQL down through **its private socket**, and removes only its runtime directory, databases and storage. This also handles ordinary errors and Ctrl+C. If shutdown fails it exits nonzero, records a blocker and retains the owned runtime for diagnosis instead of removing active data. Never stop another MySQL instance to resolve a cleanup issue. SIGKILL or host power loss cannot guarantee automatic cleanup; use the retained owner file and recorded private socket to identify this run before manual cleanup.

Required test/build failures stop dependent execution and exit nonzero. Skipped JUnit cases cannot satisfy the baseline gate. Audit/style failures are recorded as FAIL and surfaced in CI summaries; these checks are deliberately observational until Task 7 resolves the known baseline. A green functional job is not a clean security audit or launch approval.

## CI and paired rollout

GitHub Actions is used because both repositories' origins are on GitHub and neither had an existing pipeline. API CI runs fresh migrations, full PHP suite, asset build, Composer validation and paired frontend/browser gates with retained evidence. Frontend CI runs lint, unit tests, build and a separate isolated browser job. Artifacts are retained for 14 days even after failures.

Integrate the API QA tooling first, then the frontend changes, or use a reviewed paired API ref for the initial frontend branch. The frontend browser job uses workflow input `api_ref`, then repository variable `MENU_QA_API_REF`, then API `main`; the resolved commit is recorded in evidence. For a private companion repository, configure a read-only `MENU_QA_READ_TOKEN` accessible to trusted runs. The current repository token cannot read a different private repository. Fork runs without that access must be reported blocked, not counted as browser passes. No token is passed to the QA application runtime or persisted by checkout. Review the companion ref when testing paired contract changes.

API CI similarly uses workflow input `react_ref`, repository variable `MENU_QA_REACT_REF`, then frontend `main`. For initial paired rollout, configure both reviewed companion refs or run against local branches until both halves have been integrated. An old frontend without the guarded fixture/config changes cannot satisfy the new paired QA contract. This affects QA tooling only, not old/new production API clients.

After reviewing actual remote executions, configure repository branch protection for `API QA / api`, `Frontend QA / frontend` and `Frontend QA / browser`. This local task does not change GitHub settings, push branches, merge or trigger a remote workflow. YAML parsing locally is not proof of a hosted Actions run.

Rollback: revert only the paired Task 0 tooling changes after review. No application routes, money rules, deployed migrations, settled invoices, tenant permissions or dependency locks change. Remove the QA CI checks from branch protection first if intentionally withdrawing the tooling. Production Docker secret/PDF packaging remains Tasks 6/7 and is not verified by these host-based QA checks.

Workflow semantics and artifact retention follow the [GitHub Actions documentation](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax) and [upload-artifact documentation](https://github.com/actions/upload-artifact).
