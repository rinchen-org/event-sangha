# Working in rinchen.org

## Scope and direction

This repository is a PHP/SQLite application for Sakya Rinchen Ling event
registration, subscriptions, sessions, and attendance. Preserve those workflows
while following the incremental React frontend plan in [ROADMAP.md](ROADMAP.md).
The roadmap describes future work, not features already implemented.

## Staff-led event policies

Only staff use the application. Attendees carry a QR code and present it to
staff at the door; they do not log in or make admission decisions.


The product direction is flexibility: monks and organizers may change event
rules at any time, and staff—not hardcoded application policies—make the final
operational decision. Prefer advisory warnings over blocking business rules.
This direction supersedes older guidance to preserve strict policy enforcement,
including roadmap wording where it conflicts. It is a target for future changes;
this documentation update does not remove existing runtime restrictions.

- Treat event-policy deviations (for example attendance time windows,
  previous-session prerequisites, or inactive subscription eligibility) as
  visible warnings, not automatic rejection. Inventory existing checks and
  hardcoded exceptions when migrating each workflow.
- Explain each deviation in clear Spanish before committing the action, with
  explicit choices to cancel or continue anyway. Staff must be able to proceed
  without editing code or using an obscure separate bypass workflow. Do not
  silently ignore warnings or require repeated confirmations for the same action.
- Keep policy evaluation in PHP and return structured warnings for React and
  legacy screens. A UI-only override must not leave the backend blocking the
  same operation. Re-evaluate the requested action server-side on confirmation;
  do not trust client claims about which warnings apply.
- Make changing event policies configurable where practical rather than adding
  more event-specific exceptions. Do not invent new mandatory approval chains
  or mandatory justification fields for ordinary staff decisions.
- As these flows are implemented, record the action, applicable warnings,
  staff confirmation, timestamp, and available actor identity for later review.
  Allow optional notes. The current shared admin account cannot identify an
  individual staff member; do not claim individual accountability until unique
  accounts or another explicit attribution mechanism exist.
- Flexibility applies to operational policy, not security or data integrity.
  Authentication, authorization, CSRF protection, safe database operations,
  required valid identifiers, and referential integrity remain mandatory.
  Prevent accidental duplicate writes; distinguish correcting an attendance
  record from creating a duplicate. Technical failures must not appear as
  overridable policy warnings or successful actions.
- Add regression tests for ordinary actions, warning display, cancellation,
  confirmed continuation, and retained security/integrity failures. Preserve
  existing records and integrations while replacing policy gates incrementally.


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

The main interface is now React + TypeScript in `frontend/`, built by Vite into
`src/static/app/`. `src/templates/index.php` reads its build manifest and falls
back to `menu.php` when assets are absent or `?legacy=1` is requested. Existing
PHP action/result pages retain Bootstrap, jQuery, and DataTables. React forms
submit to those handlers; JSON mutation/result migration remains future work.

Existing list routes expose read-only `?format=json` responses through
`src/lib/frontend.php`, preserving their route-level access boundaries. Retain
those protections in deployments. The helper joins subscriptions by `person_id`
without using the legacy library's assumption that person/subscription IDs match.
See [frontend/README.md](frontend/README.md) for the exact migration boundary.

Use `makim frontend.install`, `makim frontend.dev`, `makim frontend.check`,
`makim frontend.tests`, and `makim frontend.build`. Build assets before deployment;
they are ignored by Git. The explicit development `?preview=1` mode uses synthetic
data and must never become an automatic fallback for unavailable production data.

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
- Keep domain validation and advisory policy evaluation in PHP. React should
  render warnings and orchestrate staff decisions, not duplicate attendance
  eligibility, activation, or session rules.
- Preserve `America/La_Paz` time semantics and UTC conversions, subscription
  status data, CSV mappings, and recorded attendance. Migrate eligibility and
  previous-session gates to the staff-led warning/confirmation approach above;
  retain override capabilities without requiring a separate forced-action path.
  Characterize hardcoded exceptions before replacing them with flexible policy.
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

## Authentication

All business PHP pages, JSON endpoints, and QR media require the shared admin
session. Login is public; CSS/JS/fonts/branding remain public. Credentials are
verified against a server-side bcrypt hash in `src/lib/auth.php`; never place
them in frontend code. Individual accounts remain future work.

Include `auth_require()` before output or domain work on every new route.
Mutations must use POST and CSRF validation, including React forms. GET QR
check-in now renders confirmation rather than recording attendance. Libraries
reject direct HTTP access; migrations are CLI-only. Preserve these boundaries.

Use `makim dev.serve-local`, not a bare PHP server: its router also protects
private static files. `makim dev.auth-tests` runs isolated HTTP tests using
temporary code copies and synthetic files; unlike the legacy PHPUnit suite it
does not touch working data. Optional Apache coverage:
`RINCHEN_TEST_SERVER=apache python tests/auth/test_auth.py` (Debian-style Apache
module paths and mod_php required).

Deployment must install the Apache/Nginx protections documented in
[containers/README.md](containers/README.md). Rebuild the frontend and container.
Sessions expire after 30 idle minutes / 12 hours total, and failed login attempts
are limited per IP. Keep session and throttle storage outside the document root;
`RINCHEN_AUTH_STATE_DIR` and `RINCHEN_HTTPS` are PHP process environment
settings, not settings read from `src/.env`. Reports authenticate using their
own session; protected QR images no longer load in anonymous email clients.

## Staff QR admission workflow

Implemented in `src/templates/attendance/log.php` and `src/lib/qr_admission.php`:
a scan is read-only and shows the person, current session, missing previous
attendance, and any existing denial. Staff explicitly permit or deny entry.
Permitting records attendance; denying records only a session-specific decision.
Rescans show the denial, which staff can explicitly revise to allow entry.
An existing attendance is not duplicated or deleted through this screen.

Missing previous sessions are advisory, including the formerly exempt session
ID 1. This QR-specific change does not remove other legacy attendance gates.
QR selects the event from `app_setting.qr_event_id` when configured through web
setup, otherwise it falls back to event ID 1. Its active time-window selection
is unchanged; time-window flexibility remains separate work.

Decisions and missing-session snapshots are appended to
`qr_admission_decision` with UTC timestamps and the authenticated account.
The shared `admin` account identifies the account, not an individual staff member.
Server-side, expiring single-use review tokens bind the person, session, and
reviewed state; changed warnings/decisions require another review. Attendance and
the decision audit are written in one transaction. Technical failures fail closed.

Before deploying this flow, back up the database and run `makim dev.qr-migrate`
with the external PHP SQLite runtime. This runs only the additive
`0006_qr_admission.php` migration; do not rerun `migrations/all.php` on existing
data (older migrations can destroy attendance). No migration runs on a scan.
Use `makim dev.qr-tests` for isolated SQLite/HTTP regression tests; it requires
PHP's sqlite3 extension and does not touch the working database or configuration.

## Authenticated browser event setup

`src/scripts/setup.php` is the staff-only web endpoint `/scripts/setup.php`
under the deployment base path. It is not a public migration endpoint. GET is
read-only; POST requires authentication, CSRF, and an expiring single-use token.
It defaults to November 12–15, 2026, 09:00–12:00 and 14:00–18:00 on days 12
and 15, afternoon only on days 13 and 14, in Bolivia;
these are editable proposed values, not fixed event policy.

`src/lib/event_setup.php` creates/updates the event and six sessions in one
transaction. `setup_event` maps a November/year key to an event; `setup_session`
maps each daily slot to its stable session ID. Update retained sessions in
place and preserve attendance and decision references. Existing
unlinked sessions are adopted only on unambiguous exact start-time matches;
unrelated sessions remain untouched. Obsolete setup-managed morning slots on
November 13 and 14 are removed only if they have no attendance or admission
decisions. If either has history, the whole update rolls back for explicit
historical-data review; never delete that history to force the new schedule.
Explicit event selection is required to
adopt an existing event; a new year defaults to a separate event.

`src/lib/schema.php` provides additive current-schema initialization and the
shared QR decision schema used by the CLI migration. Never call the historical
migration bundle from setup: it can destroy attendance. Incompatible old
attendance layouts fail closed and need a separately reviewed migration.

Existing DBs are snapshotted via SQLite backup to private `src/.backups/`
before setup writes. Keep it ignored, inaccessible over HTTP, and mode 0700
(files 0600); do not serve backups via a download endpoint. No mail, QR generation,
or participant seeding occurs. No schema changes happen merely by opening setup.
A successful setup includes the QR decision table, so console access is not needed.

Optional selection for QR writes `app_setting.qr_event_id`; review confirmation
must still reject a stale QR session when the selected event changes. This does
not change the older manual/forced-attendance shortcuts, which still hardcode
event ID 1. Use `makim dev.setup-tests` for isolated HTTP/SQLite tests.

## Staff email diagnostic

The home-page “Test email sender” button posts to `templates/test-email.php`
with session authentication and CSRF. It works independently of dashboard data
loading and has a classic-menu form fallback. The endpoint uses
`src/lib/test_email.php` and the existing `send_email()` mail transport.

Use `TEST_EMAIL_TO` from `src/.env` for comma-separated, trimmed, deduplicated
test recipients. Validate the whole list before sending; use `EMAIL_FROM` and
`EMAIL_REPLY_TO` (fallback to sender), but never add production `EMAIL_CC`.
`SEND_EMAIL=0` produces an explicit disabled error, not a successful mock.
Report transport acceptance separately from delivery; never claim inbox arrival.
Do not automatically retry ambiguous failures. Preview disables sending; the
server allows one attempt per minute per session and never accepts arbitrary
recipient/header values from the HTTP request.

`makim dev.email-tests` uses disposable configuration and fake sendmail, with
no real delivery. Keep the real-mail legacy `tests/EmailTest.php` excluded from
routine testing. No diagnostic action should create participant/attendance data.

## Versioned CSV imports

`src/lib/csv_rules/legacy.php` retains the historical Google Forms mapping;
`2026.php` maps the six-column participant roster. `SubscriptionCsv` detects the
profile by normalized headers, skips empty records (including before headers),
trims fields, and returns structured row errors and warnings without side effects.
The 2026 phone adapter removes formatting but never guesses misplaced plus signs.
Keep the local real-participant CSV ignored; commit only synthetic test inputs.

Both React and the classic upload route first review the CSV. Confirmation uses
an expiring single-use session token and re-parses the original server-held CSV;
never trust client-submitted rows or warnings. Historical `valido != 1` is now an
advisory payment warning with cancel/continue choices, not an automatic skip.
The 2026 profile does not invent payment eligibility. Technical/data errors are
not overridable; valid rows can be imported while invalid rows are reported.

`SubscriptionCsvImport` uses prepared statements and per-row transactions for
person, subscription, and the additive `csv_import_audit` table. Audits include
profile, row, warnings, confirmation, UTC time, available account, and optional
notes. Exact existing subscriptions are skipped without QR/email side effects;
there is no automatic identity merging. Email is attempted only after commit;
failure must report a saved subscription with unconfirmed mail, not a failed
registration to retry. Production imports still email participants.

`makim dev.csv-tests` runs disposable SQLite/HTTP tests with synthetic identities,
fake QR generation, and fake sendmail, plus a read-only optional check of the
local roster. It never delivers mail or calls the external QR service. The
legacy PHPUnit CSV test now only parses its fixture. See README for CSV columns
and limits; use external PHP with sqlite3, not the Conda PHP runtime.
