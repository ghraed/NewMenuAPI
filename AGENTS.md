# Restaurant Menu Project — QA Rules

## Environment safety

- Never run automated or destructive tests against production.
- Never use a production database, live payment credentials, live SMS credentials,
  or real customer information.
- Before testing, verify the application URL, database name, environment,
  branch, and commit.
- Stop and report a blocker if the environment appears to be production.
- Do not print secrets or complete access tokens in reports.

## Testing workflow

- The first QA pass is test-and-report only.
- Do not modify source code during the first pass.
- Inspect composer.json, package.json, Docker configuration, CI files,
  existing test folders, seeders, and README files before selecting commands.
- Use the project's actual scripts. Do not invent test command names.
- Run relevant existing unit, integration, feature, API, browser, lint,
  static-analysis, security-audit, and production-build checks.
- Do not count skipped, blocked, disabled, or unexecuted tests as passed.

## Test data

- Use only the disposable test database.
- Generate unique test data for each scenario.
- Prefix generated records with QA*RUN* and the run identifier.
- Reset or clean generated data between incompatible scenarios.
- Give parallel agents different users, tables, reservations, and orders.
- Do not let parallel agents modify the same active order or reservation.

## Feature flags

- Discover which features are enabled for the test restaurant.
- Verify both enabled and disabled behavior where applicable.
- Before reporting a missing page as a product bug, confirm:
  - feature flag configuration;
  - current user's permissions;
  - route registration;
  - seed data;
  - restaurant subscription/plan;
  - environment configuration.

## Evidence

Every reported defect must include:

- severity;
- affected role;
- exact reproduction steps;
- expected behavior;
- actual behavior;
- URL or API endpoint;
- relevant request/response status;
- console or server error when available;
- screenshot or trace for browser failures;
- likely files or modules;
- whether the failure is deterministic or intermittent.

## Severity

- P0: tenant breach, data corruption, security compromise, wrong financial
  transaction, or complete production outage.
- P1: core ordering, kitchen, invoice, reservation, or login workflow unusable.
- P2: important feature failure with a reasonable workaround.
- P3: minor visual, usability, validation, or low-impact issue.

## Completion rules

- Never say "everything passed" when part of the system was not tested.
- Separate PASS, FAIL, BLOCKED, SKIPPED, and NOT IMPLEMENTED.
- A successful build does not prove the runtime workflow works.
- A successful API test does not prove the browser interface works.
- A successful browser test does not prove tenant isolation or authorization.
