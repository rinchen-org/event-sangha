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
The current manifest installs Makim through pip and pins containers-sugar 1.9.0;
PHP remains external. Keep tool upgrades deliberate and update their
configuration together (`.makim.yaml` now uses the `tasks` format).

On Ubuntu >=22.04, `bash scripts/install-php-composer.sh --dry-run` previews the
system-runtime setup; omit `--dry-run` to install PHP CLI, SQLite/cURL/XML/mbstring/
ZIP extensions, Composer, CA certificates, and unzip through configured APT
repositories (including universe). `makim dev.install-php` invokes the same
script. Only APT uses sudo when needed; `--yes` accepts its package prompt.
The script verifies `/usr/bin/php` (>=8.1, <9.0), extensions, and Composer without
changing Conda, project dependencies, configuration, or data. It does not add
PPAs or configure mail/production hosting. `makim dev.installer-tests` runs
mocked installer tests without sudo, APT changes, PHP, or network access.

With a separately installed PHP and Composer available on `PATH`:

```bash
php --version
php -m
composer install
composer check-platform-reqs
composer validate --strict
```

The current `composer.json` requests PHPStan `~2.1.32`, but `composer.lock` has
`1.10.41`. This mismatch needs a separately reviewed dependency change; the
runtime installer intentionally does not fix it or run Composer installation.
Use `/usr/bin/php /usr/bin/composer` if an active Conda environment shadows the
system executables, and correct `PATH` before using PHP-based Makim tasks.

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
  updates. `scripts/install-php-composer.sh` installs only the Ubuntu system
  runtime. The legacy `scripts/install-php-deps.sh` runs `composer require` and
  is not a neutral replacement for dependency installation.
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
check-in is read-only; eligible automatic admission uses a separate protected
browser POST, never a GET write. Other cases require staff confirmation. Libraries
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

Implemented in `src/templates/attendance/log.php`, `src/lib/qr_admission.php`,
and `src/static/qr-checkin.js`. Session times are illustrative, not admission
restrictions. Within exactly one period, with no previous-attendance warnings,
prior denial, or existing attendance, the initial QR GET renders an eligible
automatic form. The visible browser submits it once by authenticated POST with
CSRF and a server-issued single-use token; PHP rechecks eligibility under the
transaction lock. No staff confirmation click is needed in this clear case.

Outside all periods, the dropdown preselects the nearest interval (distance to
start/end, preferring the upcoming session on a tie, then start time/ID). Any
session of the configured QR event can be chosen and explicitly allowed or
denied, regardless of time. Overlaps, missing previous attendance (including
session ID 1), and prior denials also require review, not automatic rejection.
Structured Spanish warnings are evaluated in PHP, displayed, and audited.

Changing the dropdown refreshes the review via a read-only, CSRF-protected POST;
without JavaScript, use “Revisar sesión seleccionada”. Submitting a different
session with an old review cannot record attendance: staff must see the updated
warnings first. Notes are optional. A denial is session-specific and can be
explicitly revised. Existing attendance is never duplicated or deleted here.
The browser never auto-submits a POST response, reload/history navigation, or
after staff interaction; no ambiguous failure is automatically retried. Manual
buttons remain usable without JavaScript. GET/HEAD never write attendance/schema.

QR uses `app_setting.qr_event_id`, falling back to event ID 1. Tokens bind the
person, event, session, reviewed state, and eligibility for automation. Changed
events, warnings, decisions, schedules, or interval boundaries require a fresh
review; ordinary clock ticks do not. The injected PHP clock enables deterministic
boundary tests. These changes do not remove other legacy/manual attendance gates.

Attendance and decisions are written atomically to the existing tables. New
`qr_admission_decision.warnings_json` entries are version-2 objects containing
structured warnings, `missing_sessions`, the selected session snapshot,
`mode` (`automatic`/`staff`), `staff_confirmed`, and optional notes. Historical
list-only snapshots are left intact; readers must handle both versions. No new
schema migration is needed for this JSON evolution. UTC time and the authenticated
account remain in their existing columns. Automatic actions must not claim a
human confirmation; the shared `admin` account is not an individual identity.
Technical failures fail closed and cannot be overridden as policy warnings.

Before deploying this flow, back up the database and run `makim dev.qr-migrate`
with the external PHP SQLite runtime. This runs only the additive
`0006_qr_admission.php` migration; do not rerun `migrations/all.php` on existing
data (older migrations can destroy attendance). No migration runs on a scan.
Use `makim dev.qr-tests` for deterministic in-memory SQLite checks followed by
isolated HTTP regression tests; it requires PHP's sqlite3 extension and does not
touch working data/configuration. `php tests/qr/test_selection.php` runs only
the no-socket domain checks, not HTTP security coverage. `makim frontend.tests`
also checks the auto-submit/selection script using a synthetic DOM.

## Opt-in QR test laboratory

`TEST_ENV=1` in `src/.env` enables the **Pruebas · Laboratorio QR** link in React
and the classic menu. Missing/other values disable all lab routes server-side.
`src/lib/test_mode.php` reads the flag without creating an absent `.env`;
`load_env(false)` is the read-only configuration mode. The session JSON bootstrap
adds boolean `testMode`, independent of dashboard DB availability; preview never
links to the lab. Do not expose recipient addresses or other configuration there.

`src/templates/testing/index.php` offers explicit, CSRF-protected, single-use
POST actions for day setup, generation, QR images, and optional batch email.
`src/lib/test_lab.php` owns the isolated persistent `src/.test-lab/lab.sqlite`
(directory 0700, file 0600). Existing dotfile/database hosting rules must protect
it. No path comes from HTTP; reject symlinks/hardlinks and unknown DB identities.
Never substitute this DB for `get_db()` or adopt production data. Only explicit
setup initializes the additive schema, never GET, a flag change, or a scan.

One permanent test event has daily Bolivia schedules: 50-minute sessions plus
10-minute breaks, configurable range, complete intervals only. Repeated ranges
are idempotent; changed ranges add missing intervals and retain earlier sessions
and history, potentially introducing advisory overlaps. New attendee batches
contain 1–100 records (default 10), UUID-v4 names, synthetic phones, subscriptions,
and `TEST_EMAIL_TO` aliases with the same UUID. Validate the entire configured
recipient list, selected address, and final alias length. Do not accept arbitrary
mail recipients or silently strip existing address tags. Generation is atomic,
replay-protected, and has no QR-network or mail side effects.

The `ready` profile seeds earlier attendance relative to the suggested session
at generation time; `missing` does not. Audit seeded session IDs and the shared
account. Neither bypasses actual QR policy, and future scans may need review.
`testing/check-in.php` selects lab context only in server code and reuses
`attendance/log.php`/`QrAdmission`; review tokens additionally bind `scope`
(`live`/`lab`) and use separate session token buckets to prevent collisions
between overlapping DB IDs or eviction of live reviews by test scans. Test GET links
identify a lab UUID, not an arbitrary event/person ID; live links remain unchanged.

`test_lab_qr.php` contacts the existing external QR service only on an explicit
image-generation POST. The URL contains a UUID, not names/emails. Require an
explicit valid `HOST_ADDRESS`, bound transport time/size, validate PNGs, and store
them inside the private test DB. `testing/qr.php` serves them only with staff
authentication and TEST_ENV=1. A changed base URL requires regeneration.

Optional mail sends only configured UUID aliases, never production EMAIL_CC.
It uses the existing mail transport and a protected test-card link, not an
anonymous QR attachment. Check SEND_EMAIL and revalidate the whole batch against
current TEST_EMAIL_TO before any attempt. Claim each attempt durably before mail,
record outcome, never automatically retry an uncertain attempt, and report
acceptance separately from delivery. Throttle sends per session. Disabling the
lab blocks pending actions and QR links without deleting its audit/data.

`makim dev.lab-tests` runs `tests/lab/test_lab.php` (in-memory domain tests) and
`tests/auth/test_lab.py` (disposable HTTP tests with fake QR and sendmail). Explicit
`RINCHEN_TEST_SERVER=cli` runs the latter through CLI controllers when sockets are
unavailable; it does not cover actual HTTP headers/cookies. Never use the working
`.env`, SQLite, mail transport, or external QR service for automated validation.

## Authenticated browser event setup

The dashboard/classic-menu **Preparar evento 2026** shortcut opens
`scripts/setup.php?mode=prepare2026`; its protected view/controller is
`src/templates/event/prepare-2026.php`. It remains available when dashboard data
fails to load and is disabled in frontend preview. GET inspects schema and
`setup_event`/`setup_session` references without writing. `pending_setup_schema()`
derives the expected schema from the additive initializer in an in-memory DB.
`EventSetup::preparationStatus()` validates mappings and plans only missing steps;
`prepare2026()` rechecks the server-held review under `BEGIN IMMEDIATE` before
applying those steps transactionally. Do not trust client completion flags.

Completed preparation is a no-op (no DB writes or backups). Existing edited
event/session fields, extra/obsolete sessions, history, and QR selection are
preserved. No QR setting is implicitly added; absent a setting, event #1 remains
the fallback. Unlinked events require explicit selection or an explicit new-event
choice. Adoption links only unambiguous exact-start matches without modifying
them; broken links or unrecognized edited sessions fail for data-integrity review.
New sessions use the existing proposed 2026 defaults. Keep the original editable
setup available for deliberate schedule/QR changes, not automatic redeployment.

Expiring, single-use preparation tokens bind the chosen event and reviewed state.
Stale pending changes require another review; a now-complete state can be skipped.
Existing DBs are backed up from a separate read-only connection while the writer
lock is held, before any schema/data write (including WAL data). Never run the
historical migration bundle. `makim dev.setup-tests` now runs
`tests/setup/test_preparation.php` (isolated SQLite, no HTTP sockets or Composer)
before the HTTP suite. Database-only checks do not replace HTTP security coverage.

The original editable setup remains distinct:

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
