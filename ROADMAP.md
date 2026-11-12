# React frontend and responsive redesign

## Outcome

Create a modern, minimalist, polished event-management experience that works
equally well for participants registering on phones and staff managing sessions
and attendance on larger screens. Retain the PHP/SQLite backend and Conda-based
developer workflow. Migrate in small, verifiable steps while retaining the PHP
handlers and reports still used by the React interface.

## Implementation update — React interface

Implemented: the React/TypeScript/Vite shell, responsive visual system, overview,
event/session views and forms, participant search/status filters, attendance
views, CSV exports, and Makim install/dev/check/test/build tasks. Existing list
routes now provide read-only JSON; writes still submit to the existing PHP
handlers. A development-only preview, frontend regression tests, relative build
assets, and hash navigation are available. The legacy management menu has been
retired: old menu URLs redirect to React after authentication, and missing or
invalid builds return an explicit 503 with deployment instructions. Required PHP
forms/results, detailed reports, and QR routes remain intact. See
[frontend/README.md](frontend/README.md).

The milestones below remain release gates: this first frontend increment does
not claim completion of test isolation, PHP runtime alignment, mutation APIs,
production integration tests, or rollout.

## Implementation update — versioned build for FTP deployment

The complete public Vite build under `src/static/app/`, including its hidden
manifest and hashed assets, is now versioned in Git. Build and commit it alongside
frontend/shared-asset/version changes on the development server, push to GitHub,
pull on the operator's local machine, then upload code/assets via FTP. No build
step is required on the operator's machine or production. Formatters leave the
generated output untouched. Public bundled branding is separate from ignored
private attendee QR images, configuration, databases and test/backup/session state.
Upload new assets first and the manifest last; retain old assets during transfer.
This is a deployment packaging change, not automatic publishing or a new release.

## Implementation update — shared PHP presentation

The remaining PHP forms, review/results and detailed reports now share the React
dashboard's visual system through `AppLayout`: sidebar/navigation, topbar, local
Manrope typography, wine/paper colors, cards and versioned footer. Setup/preparation,
the lab and email diagnostic use the same shell; login, live/lab QR and technical
error/confirmation screens retain a compact variant. `static/theme.css` is shared
with React; `static/ui.css` adapts existing PHP/Bootstrap and DataTables markup.
The old remote banner is no longer used. Core legacy form/table labels are Spanish.

This is presentation reuse, not JSON mutation migration or a second management
dashboard. Handlers, staff reviews, auth/CSRF, admission branches, CSV/mail separation
and report export/print plugins remain in PHP. No-JS navigation and manual controls
remain usable; the mobile enhancement never posts forms. PHP screens do not depend
on Vite availability, while the React entry's explicit missing-build 503 is retained.
`makim dev.ui-tests` provides disposable controller/layout checks; frontend tests
exercise mobile-menu behavior. Browser-size/screenshots and HTTP security coverage
remain required on a host that supports those checks. Deploy the shared static
styles/fonts/script along with PHP and rebuilt frontend assets.

## Implementation update — configured asset URLs

`HOST_ADDRESS` is now explicit per-installation configuration, not a hardcoded
production fallback. QR generation normalizes it once per action. Website QR
images and the report API rebase the stored filename to the current configured
origin/base path, without database/image writes. The participant email logo uses
`{{HOST_ADDRESS}}/static/images/logo-head.png`; both participant mail paths embed
private QR bytes via CID, while keeping legacy triggers/CC and scoped-send behavior.
Third-party map, WhatsApp and developer links remain external.

Changing HOST_ADDRESS invalidates pending event-email reviews. Old saved QR URL
origins produce a visible, auditable `qr_base_changed` warning, not an automatic
repair or email. Image URL rebasing cannot update a destination encoded inside an
existing QR: regenerating old codes remains a separate explicit operation.
`makim dev.url-tests` covers fake-transport rendering, local/prod paths and
read-only rebasing; event-email tests cover base changes and reviewed continuation.

## Implementation update — explicit attendee QR regeneration

React global/event participant rows display the existing QR thumbnail, a protected
full-size image link and **Regenerar QR** together. Both roster JSON responses add
PHP-evaluated `qr_preview` metadata using HOST_ADDRESS, without exposing the saved
origin as an image URL. Missing/unsafe images, configuration and load failures
show clear messages; old-base warnings remain visible. Viewing/failing a preview
does not generate QR, send mail or write data. Explicit preview mode loads no live
QR files. URL/frontend tests cover this presentation contract; rebuild assets.

Event/global participant lists now offer **Regenerar QR**, including the classic
list. PHP reviews the existing subscription, identity, optional event membership,
current HOST_ADDRESS and advisory inactive/old-base warnings. A ten-minute,
single-use CSRF-protected confirmation claims an audited attempt before bounded
external generation, then rechecks state before updating the QR reference.
GET/review/cancel have no generation, schema or mail side effects.

New images use exclusive random filenames; old images/codes are retained, not
revoked. Only the existing subscription QR changes, shared across events: no mail,
activation, attendance or QR admission-event changes. Staff send the template in
a separate phase. Structured warnings, shared account, confirmation, optional notes
and UTC outcomes are audited; failures never automatically retry. New PHP routes
reuse AppLayout, not a new React mutation API. Preview disables the buttons.
`makim dev.qr-regeneration-tests` exercises synthetic domain/HTTP cases with fake
QR/mail; explicit CLI mode is available without sockets, not HTTP/browser coverage.

## Implementation update — visible application version

The React and shared PHP footers display the application version, starting at
**2.0.0**, from `APP_VERSION` in `src/lib/version.php`. Vite embeds that same value
at build time; deploy rebuilt frontend assets with the matching PHP version.
AGENTS.md documents manual SemVer/Conventional Commit rules. No automated release,
tagging or publishing workflow is installed.

## Implementation update — event-centered registration

Event cards now open a detail page with event participants, CSV import, sessions,
and attendance; editing is separate. Home focuses on events/agenda, not latest
global subscribers. PHP provides protected read-only event detail JSON, filtered
by event ID. Event detail loading is independent of global list failures.

An additive `event_registration` relationship links existing global subscriptions
without rewriting or assigning historical data. Staff can link an existing
registration after review or import a CSV into a named event. Duplicate memberships
are prevented; exact existing identities retain their QR/status and are not emailed
again. New CSV registrations generate QR without sending email. Inactive-state/payment
warnings are advisory, reviewed server-side and audited on confirmed continuation.
Event-bound review tokens prevent stale/retargeted confirmation; FK/unique
constraints preserve identity and event integrity. General subscription state,
QR event selection and legacy report/API contracts are unchanged.

`makim dev.event-registration-migrate` is an optional additive deployment step;
setup and first confirmed scoped writes also add the schema if needed. No schema
writes occur on GET or review. `makim dev.event-tests` covers disposable domain
and HTTP fixtures; explicit CLI mode does not replace multipart/HTTP coverage.
Per-event QR routing, per-event activation, JSON mutation/result screens, and
replacement of remaining hardcoded legacy attendance shortcuts are not implemented
by this increment.

## Implementation update — separate event email phase

CSV import (both scoped and general) never sends participant email, regardless of
SEND_EMAIL. It still saves registrations, generates new QR images and audits rows.
The event roster now offers selection across pages/filters, **Enviar a seleccionados**,
**Enviar a todos** (the whole event, not the current filter), and email history.
Both send actions open an authenticated PHP review of the current fixed template,
recipient list and inactive/prior-attempt warnings. Cancellation is read-only.
Expiring single-use confirmation rechecks event membership, identity, state,
template/configuration, QR hashes and previous attempts under the write lock.

The additive `event_email_attempt` table is created only by confirmed sending.
All recipient claims commit before transport; per-recipient UTC/account/warnings,
confirmation, notes, template hash and accepted/unconfirmed results are retained.
Interrupted pending claims remain visible. No automatic retries or delivery claims
are made; a deliberate resend needs a fresh review. No CC/BCC is added. The
existing private QR is embedded as an inline MIME image without network generation
or requiring participant login. The fixed Ngöndro 2026 template is previewed, not
automatically adapted to arbitrary events. Legacy individual registration/resend
behavior remains unchanged and is not backfilled into this event-mail history.

`makim dev.event-email-tests` uses synthetic QR and fake sendmail in disposable
copies. Explicit `RINCHEN_TEST_SERVER=cli` covers controllers without sockets,
not HTTP headers/cookies. Frontend tests cover selection, event binding and
disabled preview actions. Rebuild frontend assets before deployment.

## Implementation update — authentication

Implemented: temporary shared admin login, server-side protection on business
pages/API/QR images, CSRF-protected POST actions and logout, session rotation and
expiry, per-IP login throttling, React session handling, authenticated Python
reports, and isolated HTTP regression tests. QR check-in requires staff login
and a protected POST (automatic only for clear in-period scans). Apache/Nginx private-file rules and a safe local router are
provided; actual hosting configuration must be verified before release.

Login now lasts 8 idle hours / 24 hours total. Private application-owned file
sessions and aligned garbage collection avoid the shared PHP storage's shorter
cleanup defaults. Logout, browser-session cookies, CSRF, and short-lived action
tokens are unchanged. Isolated no-socket session/storage tests complement the
HTTP suite; changing storage requires one login again on deployment.

- [ ] Replace the shared account with individual accounts and roles.
- [ ] Verify HTTPS, session storage, private-file rules, and protected QR/report
  workflows on the actual server; confirm its PHP version before runtime changes.
- [ ] Keep registration/check-in staff-operated: attendees present QR codes
  but do not access the application or decide admission.

## Implementation update — staff QR admission

The app is staff-only; attendees present their QR at the door. QR session times
now guide selection rather than gate admission. A single active interval with
no warnings/denial permits automatic check-in through a separate protected POST;
GET remains read-only. Outside the intervals, the nearest session is preselected
and staff confirm a choice. Missing previous attendance, overlaps, and prior
denials require manual review, never a silent override or automatic rejection.
Selection refreshes warnings without recording attendance; staff can choose
another session of the configured event. Existing attendance is not duplicated.
Attendance and audit commit together, and server-held reviews prevent replay and
stale/concurrent decisions. Version-2 audit JSON distinguishes automation from
staff confirmation without rewriting old snapshots or changing the schema.

The clear automatic path now shows progress followed by a saved/already-recorded
result, without the normal session selector or confirmation controls. Manual
review remains for warnings/outside periods; a collapsed fallback handles
unavailable automation without pretending that attendance was saved. Live and
test-lab QR screens share these presentation states and security checks.

Deploy the additive migration using `makim dev.qr-migrate` after backing up
the database; `makim dev.qr-tests` runs deterministic in-memory SQLite checks
and isolated HTTP tests. Browser-script tests run with the frontend suite.
This does not yet replace policy gates in other legacy/manual routes. QR uses
the event selected by staff in web setup, falling back to event ID 1 until configured.

## Implementation update — opt-in QR laboratory

Implemented: a staff-only **Pruebas · Laboratorio QR** menu gated server-side by
`TEST_ENV=1` in `src/.env`. A separate private persistent SQLite database holds
one test event, daily 50-minute sessions/10-minute breaks, UUID participants and
subscriptions, test attendance and audits. Setup is additive/idempotent;
production events, data, and reports are unchanged. Ready/missing-attendance
profiles exercise the shared QR admission workflow, with scope-bound tokens
separating test and live databases. QR image creation and configured-alias emails
are explicit opt-in actions, not effects of generating records or opening a
screen. The lab is unavailable when disabled, including previously issued links.

`makim dev.lab-tests` provides isolated domain and HTTP tests with fake services;
explicit CLI controller mode is available but does not replace HTTP deployment
verification. Frontend menu visibility is covered by the frontend suite.
This is a staff testing tool, not an automatic production-data seeder, a full
application sandbox, a destructive reset facility, or a replacement for regression tests.

## Implementation update — browser event setup

Authenticated `scripts/setup.php` supports creating a database and creating or
updating a November 12–15 event with six sessions. The year/hours are editable,
repeat runs preserve session IDs, and existing databases are backed up before
transactional changes. The endpoint can select the event used for QR admission;
legacy manual/forced shortcuts still need event-selection migration.
See README for deployment and `makim dev.setup-tests` for isolated tests.

The React dashboard offers **Preparar evento 2026**, a read-only
readiness review followed by authenticated, CSRF-protected confirmation at the
existing setup endpoint. It validates the real schema and event/session links,
skips completed work without writes or backups, and applies only missing steps
under a transaction with a private pre-write snapshot. Existing staff edits,
history, extra sessions, and QR selection are preserved; unlinked events require
explicit selection and ambiguous/broken references require review. The original
editable setup remains available for deliberate changes. Isolated SQLite checks
now run before the HTTP setup suite; frontend preview disables this shortcut.

## Implementation update — email diagnostic

The React staff home page provides “Test email sender”, using
`TEST_EMAIL_TO` (comma-separated recipients), authenticated POST and CSRF, and
the existing mail helper. Success reports transport acceptance, not delivery.
Errors are shown on screen. Isolated HTTP tests use fake sendmail and never
send real email; production-host delivery still requires manual verification.

## Implementation update — versioned CSV import

Historical Google Forms and 2026 participant rosters now have separate PHP
column rules, auto-detection, blank-row handling, phone formatting normalization,
and structured row errors. Both upload interfaces review before confirming;
legacy payment markers are advisory warnings with cancel/continue choices.
Single-use server-held reviews, transactional row/audit writes, duplicate
protection, and a mail-free import result are implemented. Email is now a separate
event-roster action as described above.
CSV now requires name and valid email while blank/unrecognized phones trigger
reviewable, audited warnings. Recognizable 2026 phones are normalized; uncertain
originals are preserved. Empty-phone QR links retain exact, unambiguous identity
matching, and ordinary manual phone validation remains unchanged. Activation
updates only status, atomically, preserving reviewed contact data. Optional local
roster checks exercise parsing and in-memory imports without mail/network; the
no-socket subset does not replace HTTP/cookie/upload regression coverage.
`makim dev.csv-tests` uses disposable SQLite/HTTP fixtures and fake QR/mail.
JSON mutation APIs, broader identity reconciliation, and migration of the other
legacy policy gates remain future work.

## Current baseline

- PHP templates mix markup, form submissions, session feedback, and library
  calls. Bootstrap, jQuery, and DataTables provide much of the existing UI.
- Workflows include registration, subscription filtering/activation, CSV import,
  email resend, event/session creation and editing, manual/QR/forced attendance,
  attendance listings, reports, and table exports.
- The active-subscriptions JSON endpoint feeds a Python/Jinja2/LaTeX report.
- PHPUnit covers settings, people, subscriptions, events, sessions, and
  attendance. The separate email test has real delivery side effects.
- Conda provides Python tools and Node.js; CI provisions PHP separately. The
  container still uses PHP 7.4 while CI uses 8.1 and PHPUnit requires >=8.1.
- Tests share the application database path and overwrite local configuration.
  React now has its own build and tests; an isolated PHP API test harness remains future work.

## 1. Make development and testing reproducible

- [x] Provide an Ubuntu system PHP/Composer installer with a no-write dry run,
  extension verification, and mocked tests (`makim dev.install-php` and
  `makim dev.installer-tests`); retain Conda for Python and Node.js. This does not
  align CI/container runtimes or resolve the existing PHPStan manifest/lock
  mismatch, and a real host installation still needs verification.
- [ ] Retain `conda/dev.yaml` as the development entry point; retain Makim and
  Sugar. Document external PHP/Composer setup so PHP need not come from Conda.
- [ ] Select and verify a supported PHP version against the lockfile and hosting
  constraints; align the image, CI, Composer requirements, and local instructions.
- [ ] Add a container test setup with the repository, tests, Composer dependencies,
  and required PHP extensions available; the current `src/`-only mount is
  insufficient. Wire the chosen runtime into Makim.
- [ ] Make database/configuration paths injectable and give each test run a
  temporary database and configuration. Fake QR/network and mail dependencies,
  and control time for attendance tests. Remove reliance on test ordering.
- [ ] Record the existing PHP test/static-analysis baseline. Make automated
  tests fail reliably when any individual test command fails.
- [ ] Correct setup documentation, provide a non-secret configuration example,
  document writable directories, and validate the pinned Sugar configuration.

**Exit criteria:** a fresh setup can start the application and run repeatable
tests through Makim without modifying local app data or sending mail/network
requests. Runtime versions and extension requirements agree across environments.

## 2. Inventory workflows and define the PHP API

- [ ] Map every page/action to its inputs, response states, access requirements,
  and existing tests. Capture synthetic-data screenshots for visual comparison.
- [ ] Separate request handling from PHP templates while reusing `src/lib/`
  domain logic. Add JSON endpoints incrementally for the React screens.
- [ ] Define request/response contracts, validation errors, status codes,
  filtering/pagination, CSV import results, and duplicate submission behavior.
- [ ] Characterize attendance time windows, previous-session prerequisites
  (including the hardcoded session exception), forced attendance, activation,
  and email failures before changing their presentation.
- [ ] Inventory deployment authentication, including any web-server protection;
  define and enforce server-side access control for administration and reports,
  and CSRF protection for session-authenticated mutations. UI visibility is not
  authorization. Keep public registration/check-in permissions explicit.
- [ ] Preserve the existing subscription API contract for Python reports or
  provide a compatibility adapter. Cover the contract with regression tests.
- [ ] Preserve issued QR URLs and the `/attendance_log.php/` redirect. Document
  the existing participant details in QR URLs; plan any future token-based
  replacement separately with a compatibility path for issued codes.

**Exit criteria:** each first-wave React workflow has a tested PHP contract,
documented permissions, and a compatibility plan for existing links and reports.

## 3. Establish the visual direction

- [ ] Design mobile and desktop examples of registration, subscriptions, and
  attendance check-in before expanding the component library.
- [ ] Use Rinchen's existing identity as the starting point: restrained brand
  accents, warm neutral surfaces, generous whitespace, clear typography, subtle
  borders/shadows, and a consistent spacing scale. Avoid decorative clutter.
- [ ] Define reusable tokens for colors, typography, spacing, widths, radii,
  focus indicators, and interaction states. Keep one prominent primary action
  per screen and a clear hierarchy between public and administrative pages.
- [ ] Design mobile-first layouts: single-column forms, navigation that fits
  small screens, fluid content widths, and grids that expand when space permits.
  Tables should prioritize key fields with accessible detail views or contained
  scrolling where comparison requires it.
- [ ] Specify loading, empty, error, success, disabled, and validation states;
  provide useful inline feedback without relying on color alone.
- [ ] Preserve Spanish content and meaningful brand imagery; consolidate mixed
  labels and remove event-specific hardcoded copy where appropriate.
- [ ] Include keyboard navigation, semantic labels, visible focus, readable
  contrast, reduced-motion behavior, and touch targets of at least 44×44 CSS px.

**Exit criteria:** representative designs at 360, 768, and 1440 CSS px show a
coherent, attractive interface with all important states and no page overflow.
Review the designs with the maintainer before broad screen migration.

## 4. Introduce the React application

- [x] Establish `frontend/` using React, TypeScript, and Vite with an npm lockfile.
- [ ] Use Node.js from Conda for frontend tooling; keep production serving of
  compiled static assets compatible with PHP hosting, without requiring a Node
  application server.
- [x] Add Makim frontend install, dev, build, check (TypeScript), and tests targets.
- [ ] Build the application shell, navigation, design tokens, reusable form and
  feedback components, and one shared API client.
- [ ] Configure development API proxying and production asset/API base paths for
  both root hosting and `/event-retiro`. Verify direct navigation and refreshes;
  keep PHP/API/static/QR routes outside any frontend fallback routing.
- [ ] Prove one end-to-end slice, such as the event list, while retaining a route
  back to the corresponding legacy page.

**Exit criteria:** the React shell and first real-data screen build reproducibly,
run alongside the PHP application, and work under the deployed subdirectory.

## 5. Migrate workflows incrementally

For each group, retain necessary PHP workflow routes until behavior, visual
review, and tests pass; this does not restore the retired legacy menu.
Remove duplicated jQuery/Bootstrap/DataTables dependencies from migrated
pages as equivalent React behavior becomes available.

| Order | Screens/actions | Acceptance focus |
| --- | --- | --- |
| 1 | Event and session lists/forms | Create/edit validation, relationships, dates and timezone correctness |
| 2 | Registration and subscription list | Mobile form completion, feedback, filters, activation, email resend |
| 3 | CSV subscription import | Existing column mappings, useful row errors, duplicate handling, counts |
| 4 | Manual, QR, and forced attendance | Fast phone use, existing QR links, eligibility errors, authorized overrides |
| 5 | Attendance lists/reports and exports | Filtering, data parity, existing export formats, Python report compatibility |

- [ ] Complete events and sessions.
- [ ] Complete registration and subscription administration.
- [ ] Complete CSV import.
- [ ] Complete attendance and check-in.
- [ ] Complete reporting and exports.

**Exit criteria:** every inventoried workflow has equivalent or explicitly agreed
behavior in React, with no lost links, reports, or operational actions.

## 6. Validate quality and release safely

- [ ] Keep PHP regression tests and PHPStan in CI; add frontend type/lint/build
  checks, focused component tests, API integration tests, and browser tests for
  registration, subscription changes, CSV import, and attendance outcomes.
- [ ] Test at 360, 390, 768, 1024, and 1440 CSS px, plus a real touch device.
  Verify forms, navigation, long content, tables, 200% zoom, keyboard-only use,
  and current Chrome, Firefox, and Safari behavior.
- [ ] Review screenshots for typography, alignment, spacing, consistent states,
  and brand quality. Run accessibility checks plus manual focus/label checks.
- [ ] Test slow connections and API failures; avoid duplicate submissions, keep
  feedback understandable, and ensure representative datasets stay responsive.
- [ ] Validate production builds on staging, including subdirectory assets,
  direct links, old QR codes, protected pages, and Python-generated reports.
- [ ] Back up SQLite and persistent assets before any release migration, document
  restoration, and retain the previous UI/build for rollback. Switch routes in
  stages; retire legacy templates only after parity is verified.
- [ ] Update README, AGENTS.md, deployment instructions, and this roadmap with
  implemented commands and verified outcomes.

**Exit criteria:** CI and staging checks pass; visual and workflow review is
complete; deployment and rollback are documented and rehearsed.

## Scope boundaries

The frontend migration does not require replacing PHP/SQLite, adopting Laravel
or Django, rebuilding the main WordPress site referenced by existing assets,
or redesigning email/PDF output beyond compatibility fixes. Hosting constraints,
administrative access rules, exact branding choices, and required export formats
should be resolved during inventory and design, before their dependent work.
