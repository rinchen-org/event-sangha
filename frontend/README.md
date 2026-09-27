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

Open <http://localhost:5173>. Vite forwards `/templates/` requests to the PHP
application at `http://127.0.0.1:8000`. Start PHP using your existing runtime or
container. To use another origin:

```bash
PHP_ORIGIN=http://127.0.0.1:8081 makim frontend.dev
```

The development PHP server must expose `src/` as its document root. If PHP is
installed separately, `php -S 127.0.0.1:8000 -t src` is sufficient for local use
with an already initialized development database. Do not use the PHP development
server for production. No migrations run automatically.

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
to `src/static/app/`. These generated files are ignored by Git: build them before
deploying and include the entire directory, including `.vite/manifest.json`, in
the deployment artifact. PHP reads that manifest in `src/templates/index.php`.
No Node server is needed in production. The existing `src/index.php` redirect
opens this page automatically. Relative assets and hash navigation support both
root hosting and `/event-retiro` without new server rewrite rules.

Before a build exists, the original menu remains available. After building, use
`templates/index.php?legacy=1` for the same fallback. PHP result pages include a
link back to the React interface. The original QR redirect and Python report API
are unchanged.

## Migration boundary

React provides the overview, event/session views, participant search and state
filtering, attendance filtering, CSV exports, and create/edit/import forms.
Forms submit their existing field names and IDs to the PHP handlers and navigate
to those handlers' results. Backend validation, QR generation, email, CSV import,
and attendance rules still run in PHP. Submission/result screens have not yet
been migrated to a JSON mutation API; the classic detailed report retains its
Excel/PDF/print features.

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
administrative list/action routes when deploying. The repository does not itself
implement authentication.

## Verification

```bash
makim frontend.check
makim frontend.tests
makim frontend.build
```

Tests cover data loading/retry, accessible dialogs, participant filters, form
field compatibility, person IDs, Bolivia date conversion, and CSV formula escaping.
They use synthetic fixtures and never touch the PHP database or `.env`.

For PHP integration verification, use an isolated copy of `src/`, initialize its
schema with `migrations/all_unittest.php` and `migrate_all()`, and populate
synthetic records. In particular, use unequal subscription/person IDs to verify
the join. Start PHP against that copy and check each JSON route, empty responses,
missing-database 503 responses, and POST 405 responses. Verify form submissions
with a controlled mail/QR setup before a production rollout. Do not run the
existing destructive PHPUnit suite against the working application's database.
