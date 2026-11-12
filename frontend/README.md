# Rinchen React frontend

React + TypeScript + Vite, with bundled Manrope fonts, Lucide icons, and custom
responsive CSS. Node.js remains part of the `rinchen` Conda environment; PHP
and Composer remain external to Conda.

## Development

```bash
conda activate rinchen
makim frontend.install
makim frontend.dev
```

Open <http://localhost:5173>. Vite forwards `/templates/`, `/scripts/`, and `/static/` requests to the PHP
application at `http://127.0.0.1:8000`. Start PHP using your existing runtime or
container. To use another origin:

```bash
PHP_ORIGIN=http://127.0.0.1:8081 makim frontend.dev
```

The development PHP server must expose `src/` as its document root. If PHP is
installed separately, `makim dev.serve-local` uses the protected development router for local use
with the development database. If the database is missing, the home-page
**Preparar evento 2026** shortcut remains available even when list loading fails;
it opens the protected PHP readiness/confirmation screen. Do not use the PHP
development server for production. No migrations run automatically.

For design review without PHP, open
<http://localhost:5173/?preview=1>. This explicitly labeled, development-only
preview uses synthetic data. Writes are disabled or intercepted, and no fake
success is shown. The preview module is excluded from production builds.

## Build and serve

```bash
makim frontend.build
makim frontend.tests
```

The build type-checks TypeScript and writes hashed assets, fonts, and a manifest
to `src/static/app/`. **The complete public build is tracked by Git**, including
the hidden `.vite/manifest.json`, so it travels with the source through GitHub.
Rebuild on the development server and commit the generated additions/deletions
with changes to frontend source, dependencies/configuration, shared theme/assets,
or `APP_VERSION`. Do not manually edit/format generated files; the end-of-file
fixer excludes the build. node_modules and private attendee QR images stay ignored.
PHP reads the committed manifest in `src/templates/index.php`.
No Node server or build step is needed on production or the operator's machine.
The existing `src/index.php` redirect
opens this page automatically. Relative assets and hash navigation support both
root hosting and `/event-retiro` with the authentication file-protection rules described in [containers/README.md](../containers/README.md).

### GitHub → local pull → FTP

1. On the development server, run the frontend checks/tests/build and include
   `src/static/app/` in the same commit as the corresponding source changes.
   Push that commit to GitHub. GitHub CI checks/builds the frontend but does not
   deploy or commit files for you.
2. Pull that commit onto the machine used for FTP. No local compilation is needed.
3. Upload changed application code/public assets from `src/` to the existing
   website document root. Local `src/static/app/` maps to remote `static/app/`;
   do not create an extra `src/` level or replace `templates/index.php` with the
   generated index.html. Include `.vite/manifest.json` even if the FTP client
   hides dot-directories. Its existing HTTP protection must remain enabled.
4. Upload the new `static/app/assets/` files before uploading
   `static/app/.vite/manifest.json` last. Keep old hashed assets during the upload
   so requests using the old manifest still work; do not delete the live build
   directory first. Retire old assets only later, allowing cached/open pages to
   finish using them.
5. Hard-refresh and verify the deployed participant list, QR previews and
   **Regenerar QR** button. Deployment still needs the production HTTP/browser
   checks below; a successful local build is not a production deployment.

Never mirror the entire local data directory over production. Preserve its
`src/.env` equivalent, SQLite databases/journals, `static/qr/`, sessions/throttle
state, `.test-lab/` and `.backups/`. Private QR outputs are ignored separately
from the public compiled frontend. This workflow adds no automatic FTP upload,
release tagging, version bump or publishing service.

React is the canonical management interface. Authenticated requests to the old
`templates/menu.php` and `templates/index.php?legacy=1` URLs redirect to
`templates/index.php`; there is no classic-menu or missing-build fallback.
A missing/malformed manifest or missing entry JS/CSS returns HTTP 503 with
Spanish instructions to run `makim frontend.build` and deploy the complete build.
Without JavaScript, the entry page asks staff to enable it. PHP result pages
still link back to React, and the PHP handlers/reports used by React are retained.
Issued QR URLs and the Python report API remain compatible, but now require
an authenticated session. QR attendance always uses a protected POST: automatic
for a single clear in-period session, otherwise explicitly confirmed by staff.
The standalone PHP QR screen uses `src/static/qr-checkin.js`; GET never records
attendance and session selection/policy evaluation remain server-side.

## Migration boundary

PHP forms, review/results and reports now share a matching shell through
`src/lib/layout.php`, `static/ui.css` and `static/ui.js`: sidebar links point to
React's canonical routes, while PHP continues to own the action and its state.
Setup, lab and email diagnostic use that shell. Login, QR and errors use the same
theme in a compact layout. The visual update does not replace PHP with React or
introduce JSON mutations. Bootstrap utilities and DataTables report exports remain.

Both frontends use `src/static/theme.css` and local Manrope files in
`src/static/fonts/` (OFL license included). Deploy those static files and branding
images alongside PHP, in addition to the rebuilt `src/static/app/`. PHP screens
do not require the Vite manifest; React's missing-build 503 is unchanged. No-JS
navigation and form controls remain usable. `makim dev.ui-tests` checks layouts
and root/subdirectory paths with temporary data; frontend tests check mobile
navigation/focus without submitting forms. Real-browser checks are still needed.

The left-menu **Participantes** link opens the single attendee directory at
`#participantes`. An explicit event selector loads only that event's roster at
`#participantes/<id>`. Event cards use this route too; old `#eventos/<id>` and
`#eventos/<id>/participantes` bookmarks are replaced with the canonical hash.
Event editing remains separate; session/attendance tabs retain their
`#eventos/<id>/sesiones` / `asistencia` routes. Selecting another event resets
the roster/search/pagination and email selection, without writing membership or
retargeting QR admission. Event options load independently of unrelated global
list failures, and a selected roster still has its own protected data/error path.

`templates/subscription/list.php` HTML now only redirects to this directory after
authentication, preserving a valid optional event_id. Its `?format=json` remains
the global read-only contract. The explicit **Inscripciones generales (histórico)**
selector option (`#participantes/todos`) retains old/global record tools without
pretending those records belong to an event. CSV import is disabled there and the
directory no longer offers new unassigned general signup. Select an event to
import CSV or explicitly link an existing subscription. No historical links are
inferred; one person can still participate in multiple events.
Legacy activation/resend redirects to general-history mode. `templates/index.php`
passes its consumed session notice as HTML-escaped JSON; React displays it as
plain text, including an alert for failed actions. No new mutation API or
automatic send/retry is introduced by this compatibility feedback.

`templates/event/detail.php?format=json&event_id=<id>` is a protected, read-only
GET returning `{success:true,data:{event,subscriptions,sessions,attendance}}`.
PHP filters by event ID, not names or frontend assumptions. Missing membership
schema yields an empty roster without writes or inferred assignments; invalid
events return 404, technical failures 503, and authentication failures 401 for
JSON requests. Event loading is independent of unrelated global list failures.
No sample/global roster is substituted for an unavailable event response.

CSV forms send a destination `event_id` to the existing PHP review handler. The
event and reviewed backend state are bound to its expiring single-use token;
POST confirmation rechecks them and cannot move a pending import to another event.
The PHP web controller also requires a valid event: unscoped GET/HEAD redirects
to the selector and unscoped POST fails before parsing/writing. Existing-registration linking
uses `templates/event/register.php` with PHP-evaluated warnings, review and CSRF
POST confirmation. React does not evaluate inactive/payment eligibility. Preview
uses explicit synthetic event memberships, intercepts forms and disables linking.
New session forms preselect the current event. Historical attendance remains
visible even without a membership; membership does not retarget QR admission.

CSV imports never send email. Event rosters offer checkboxes (selection persists
across pages/filters), **Enviar a seleccionados**, **Enviar a todos** (the entire
event, regardless of filters), and **Historial de correos**. The form posts event
ID, mode and selected subscription IDs to `templates/event/email.php` for PHP
review; this first POST sends nothing. An expiring single-use confirmation binds
the server-held recipients/template/configuration and rechecks them before durable
per-recipient claims. PHP owns warnings and membership validation. Results/history
are PHP screens with the shared dashboard layout; preview disables sending,
selection and history links.
The fixed template is previewed before confirming and each private QR is embedded
in its individual email. Mail acceptance is not delivery; no automatic retry is made.

The table's **Participantes por página** selector offers 10/25/50/100/**Todos**,
resets page navigation when changed and retains selected email recipients. The
footer shows the displayed range and clamps pages when results shrink.
**Imprimir lista completa** is a new-tab GET link to
`templates/subscription/print.php?event_id=<id>` (or explicit `scope=general` for
the historical directory), not a snapshot of visible/selected/filtered rows.
The protected PHP view uses the shared complete membership query and existing
HOST_ADDRESS QR metadata, with a compact print shell and no write/transport effects.
Its public `participants-print.css`/`.js` provide paper layout and an explicit
print/PDF button once images load/fail/timeout. Errors remain on paper; no automatic
print, QR replacement, image retry or mail occurs. Preview disables the link.
Deploy these PHP/static files and commit the rebuilt Vite assets together.
`makim dev.participant-print-tests` covers isolated routes; frontend tests cover
pagination/scoping/readiness. Browser/PDF layout and QR scanning still need manual
verification on a capable host.

Absolute QR/image URLs come from per-installation `HOST_ADDRESS` in `src/.env`,
not a hardcoded production domain or an old saved hostname. The email logo uses
that base and the existing public `static/images/logo-head.png` asset. A changed
base requires a fresh email review. A saved QR from another installation produces
a visible warning: rebasing an image URL does not rewrite the link inside the
bitmap. No automatic regeneration/data repair/mail occurs. Local and production
must provide their own configured base (including the deployment path).

General activation remains global, so event participant rows show it as
**Estado general** and omit the older global/hardcoded-event row actions. Detailed
reports and the global subscription API are unchanged. See README for the
additive schema, explicit historical linking, and deployment/test commands.

Both event/global rosters offer a per-row **Regenerar QR** POST form, bound to the
subscription ID, CSRF token and (when scoped) event ID. It opens PHP's
`templates/subscription/regenerate_qr.php` review/confirmation screen, not an
immediate generation or mail send. PHP owns identity/membership checks, inactive
warnings, HOST_ADDRESS, expiry/replay protection, transport, file publication and
audit. Preview disables regeneration. Event rows still omit unrelated global
activation and hardcoded-event attendance shortcuts. Regeneration does not create
event-specific codes or change the configured QR admission event. Rebuild assets;
use `makim dev.qr-regeneration-tests` for isolated backend regression coverage.

Those rows also show the existing QR thumbnail and **Abrir QR**, linking to its
protected full-size image in a new tab. Global subscription JSON and event-detail
subscription rows add `qr_preview: { url, error, warnings }`; `url`/`error` can be
null, and warnings have server-evaluated `code`/`message`. PHP resolves the filename
against HOST_ADDRESS, inspects bounded local file metadata and removes the raw
saved QR URL from these presentation responses. The reports API remains unchanged.
React displays missing/configuration/load errors and keeps **Regenerar QR** usable;
image failures never trigger retries, generation or mail. A new regenerated URL
replaces the failed preview. Explicit preview mode never loads live QR assets.
Use `makim dev.url-tests` for this read-only contract and the frontend tests for
images, warnings, error handling and retained protected regeneration forms.

The session bootstrap exposes a server-computed `testMode` boolean. With
`TEST_ENV=1` in `src/.env`, the sidebar links to **Pruebas · Laboratorio QR** at
`templates/testing/index.php`, even if production list loading fails. Missing or
false flags and explicit preview mode hide the link. PHP independently gates
every test route; frontend visibility is not authorization. Rebuild assets after
deploying this menu change. The test laboratory is a protected PHP screen with
its own persistent database, not a replacement for the main application's data.

React provides the overview, event/session views, participant search and state
filtering, attendance filtering, CSV exports, and create/edit/import forms.
Forms submit their existing field names and IDs to the PHP handlers and navigate
to those handlers' results. Backend validation, QR generation, email, CSV import,
and attendance rules still run in PHP. Submission/result screens have not yet
been migrated to a JSON mutation API; the classic detailed report retains its
Excel/PDF/print features.

**Preparar evento 2026** links to `scripts/setup.php?mode=prepare2026`, independent
of dashboard loading. PHP checks schema and event/session mappings and confirms
only pending work; React does not evaluate migration status. Already-prepared
records and staff edits are preserved. Preview disables the link. The original
editable PHP setup remains available.

Each existing list route accepts `?format=json` through `src/lib/frontend.php`:

- `templates/event/list.php`: events.
- `templates/event-session/list.php`: sessions with event names.
- `templates/subscription/list.php`: subscriptions joined by `person_id`.
- `templates/attendance/list.php`: attendance with person, session, and event names.

The response is `{ "success": true, "data": [...] }`, with UTC database timestamps.
Dates render in `America/La_Paz`. Reads open the existing SQLite file read-only;
missing databases or schema failures return HTTP 503, unsupported methods return
405. The helper never creates tables, writes QR files, or sends email. No new
public API route is introduced; retain the existing access controls on all
administrative list/action routes when deploying. PHP session authentication now protects these routes. React loads
`templates/session.php` before private data and includes its CSRF token in POST
forms. HTTP 401 displays a login link. Rebuild assets when deploying authentication
so stale forms do not fail CSRF checks. Logout is a CSRF-protected POST.

## Verification

```bash
makim dev.auth-tests
makim dev.event-tests
makim frontend.check
makim frontend.tests
makim frontend.build
```

Tests cover event navigation/scoping, missing/error/empty event states, bound CSV
forms, session defaults, data loading/retry, accessible dialogs, participant filters, form
field compatibility, person IDs, Bolivia date conversion, and CSV formula escaping.
They use synthetic fixtures and never touch the PHP database or `.env`.

For PHP integration verification, use an isolated copy of `src/`, initialize its
schema with `migrations/all_unittest.php` and `migrate_all()`, and populate
synthetic records. In particular, use unequal subscription/person IDs to verify
the join. Start PHP against that copy and check each JSON route, empty responses,
missing-database 503 responses, and POST 405 responses. Verify form submissions
with a controlled mail/QR setup before a production rollout. Do not run the
existing destructive PHPUnit suite against the working application's database.
