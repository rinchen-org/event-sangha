# Working in rinchen.org

## Scope and direction

This repository is a PHP/SQLite application for Sakya Rinchen Ling event
registration, subscriptions, sessions, and attendance. Preserve those workflows
while following the incremental React frontend plan in [ROADMAP.md](ROADMAP.md).
The roadmap describes future work, not features already implemented.

Keep Conda as the development environment for Python tooling, Makim, Sugar,
and Node.js. PHP has been unreliable inside Conda: use a separately provisioned
PHP/Composer runtime or a compatible container, invoked from the Conda workflow.
Do not replace Conda or rewrite the backend in Python as part of the frontend
migration. The README's Laravel/Django ideas are historical suggestions.

## Repository map

| Location | Responsibility |
| --- | --- |
| `src/index.php` | Redirects to the current PHP UI |
| `src/templates/` | Pages, form handling, shared layout, and email template |
| `src/lib/` | People, subscriptions, events/sessions, attendance, SQLite, settings, QR, and email |
| `src/api/subscription/list.php` | Active subscriptions JSON endpoint, also used by Python reports |
| `src/attendance_log.php/index.php` | Compatibility redirect for QR attendance URLs |
| `src/static/` | CSS, images, and generated QR files |
| `src/migrations/` | Schema changes and separate production/test migration entry points |
| `tests/` | PHPUnit tests and CSV import fixture |
| `scripts/reports/` | Python/Jinja2 report generation and LaTeX PDF build |
| `conda/dev.yaml` | `rinchen` environment and tool dependencies |
| `.makim.yaml` | Developer commands |
| `.containers-sugar.yaml`, `containers/` | Sugar configuration, Compose service, and PHP image |
| `.github/workflows/` | Conda + external PHP CI, Composer validation, tests, and pre-commit |

The current frontend uses PHP templates, Bootstrap, jQuery, DataTables, and
custom CSS. There is no React application or frontend package manifest yet.
Business logic and request handling are partly mixed into templates; inspect
both templates and libraries before moving a workflow.

## Environment and commands

Run commands from the repository root unless a script documents otherwise.

```bash
conda env create -f conda/dev.yaml
conda activate rinchen
```

For an existing environment, use `conda env update -f conda/dev.yaml`.
The README's `mamba env create -n conda/dev.yaml` example is incorrect; use `-f`.
The manifest currently pins Makim 1.8.3 and containers-sugar 1.9.0, and comments
out PHP. Keep tool upgrades deliberate and update their configuration together.

With a separately installed PHP and Composer available on `PATH`:

```bash
php --version
php -m
composer install
composer check-platform-reqs
composer validate --strict
```

The locked PHPUnit 10 dependencies require PHP >=8.1, although `composer.json`
declares PHP `^8.0`. CI currently provisions PHP 8.1. Check Composer's platform
requirements and application extensions, especially `sqlite3` and `curl`, plus
the XML/DOM and `mbstring` extensions used by the test tooling. Do not use
`--ignore-platform-reqs` to conceal runtime incompatibilities.

```bash
makim dev.runserver
```

This target runs `sugar compose down`, `sugar compose build`, and
`sugar compose-ext restart`; it stops the existing stack. Docker/Compose must
be available. The Compose service expects `UID`, `GID`, and `HOST_PORT` and
mounts only `src/` at `/app`; it does not mount `tests/` or `vendor/`.
The Dockerfile currently uses PHP 7.4, incompatible with the declared PHP
requirement and locked test dependencies. Treat runtime alignment as unfinished
work, not a working container test setup.

Sugar here is [sugar-org/sugar](https://github.com/sugar-org/sugar), packaged as
`containers-sugar`. This repo uses its older configuration format. Current
upstream examples use `.sugar.yaml` and profiles; do not copy those examples
over `.containers-sugar.yaml` without a coordinated version migration.

## Configuration and data precautions

- `src/lib/dotenv.php` reads `src/.env` directly; shell environment exports alone
  do not configure application settings. Its parser expects plain `KEY=value`
  lines and does not implement general dotenv comment/quoting behavior.
- Settings include `HOST_ADDRESS`, `SEND_EMAIL`, `EMAIL_FROM`, `EMAIL_REPLY_TO`,
  and `EMAIL_CC`. The default address is the deployed `/event-retiro` path and
  email is enabled by default. Use a local address for development.
- `get_db()` hardcodes `src/db.sqlite`. Keep existing databases, local `.env`
  files, credentials, generated QR files, and participant data out of commits.
- QR generation calls an external service using an attendance URL containing
  participant details, then writes to `src/static/qr/`. Use synthetic data and
  explicitly account for network/filesystem effects when exercising it.
- `SEND_EMAIL=0` suppresses mail but returns `false`; subscription email handling
  can turn that into an exception. Do not assume it provides a successful mock.
- Inspect migration entry points before execution. Production records, test
  seed data, and unit-test schema setup have different entry points.

## Tests and validation

**Use a disposable checkout/copy for tests until test isolation is implemented.**
`tests/conftest.php::clean_db()` deletes `src/db.sqlite`; `SettingsTest.php` and
`EmailTest.php` overwrite `src/.env`. Do not run the suite against a working
application database or valuable local configuration, or in parallel against
the same checkout. Tests that create people/subscriptions may also generate QR
images through the network.

In that isolated workspace, with dependencies and writable fixture directories:

```bash
makim dev.tests
# Focus a change when appropriate:
./vendor/bin/phpunit tests/AttendanceTest.php
./vendor/bin/phpstan analyse
pre-commit run --all-files
```

`makim dev.tests` runs Settings, Person, Subscription, Event, EventSession, and
Attendance tests in separate PHPUnit processes. `EmailTest.php` is intentionally
commented out in that target and invokes real PHP `mail()`. A bare
`./vendor/bin/phpunit` discovers it via `phpunit.xml`; do not use that as an
equivalent safe default. Introduce a mail fake before including it in routine CI.

PHPStan is configured at level 6 for `src/` and `tests/`; pre-commit runs PHPStan
and an end-of-file fixer. Report actual checks and failures, distinguishing
existing issues and missing runtime dependencies from regressions. Documentation
changes generally need path/command review and `git diff --check`, not execution
of the data-mutating PHP suite.

## Implementation conventions

- Read the working tree diff first and preserve unrelated user edits. Keep
  changes focused; follow surrounding PHP style without mass reformatting.
- Keep domain validation in PHP. React should render and orchestrate requests,
  not duplicate attendance eligibility, activation, or session rules.
- Preserve `America/La_Paz` time semantics and UTC conversions, subscription
  status behavior, CSV mappings, forced attendance, and previous-session checks.
  Characterize hardcoded exceptions before changing them.
- Preserve existing QR links (including `/attendance_log.php/` and query-string
  handling), deployment under `/event-retiro`, and the subscription API consumed
  by reports. Add compatibility adapters when routes or responses change.
- Keep Spanish-facing content and Rinchen branding; existing English labels
  should be normalized deliberately during the redesign.
- Add regression tests for changed domain behavior and API contracts. Isolate
  database, time, mail, and network dependencies as the test harness evolves.
- Use `composer install` with the lockfile for setup; avoid incidental dependency
  updates. The legacy Composer installer embeds an old checksum, and
  `scripts/install-php-deps.sh` runs `composer require`; neither is a neutral
  replacement for dependency installation.
- Expose new recurring tasks through Makim and keep container orchestration in
  Sugar. Update this file and the roadmap when actual commands or architecture
  change; do not document proposed tasks as available commands.
