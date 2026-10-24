# Hosting authenticated Rinchen

Serve only `src/` as the document root. PHP authentication alone cannot protect
SQLite, configuration, or static QR images: install the server rules below.
Keep HTTPS enabled in production. PHP/runtime alignment remains unfinished;
this change does not upgrade the existing PHP 7.4 container image.

## Apache

Use Apache 2.4 with mod_rewrite and allow the directives in `src/.htaccess`
(e.g. `AllowOverride All` for this document root). It blocks dotfiles,
libraries/migrations, databases/backups and standalone HTML, disables directory
listing, and routes generated QR images through authenticated `media.php`.
Public CSS/JS/fonts and branding remain accessible for the login screen.

## Nginx / Sugar container

Nginx ignores `.htaccess`. The Dockerfile copies `auth-nginx.conf` into the
Webdevops vhost include directory. Rebuild the image through the existing Sugar
workflow to install it; restarting an old image is insufficient. For a separately
managed Nginx server, include these rules at server scope before generic PHP/static
handling. The rules assume a dedicated application vhost; review scope if sharing
one with unrelated applications. Root and `/event-retiro` QR URLs are supported.

The local Nginx configuration has not been exercised with an actual Nginx runtime.
Validate the effective configuration and the checks below on staging.

## Session and rate-limit storage

Configure PHP session storage outside the document root, writable only by the
runtime user. Login throttling defaults to a private application-specific
directory under the system temporary directory. For durable deployment storage,
set `RINCHEN_AUTH_STATE_DIR` to an existing private directory (mode 0700)
outside the document root, writable by PHP. Unavailable throttle storage fails
closed. Multi-instance hosting needs shared session/throttle storage.

Cookies are HttpOnly and SameSite=Lax, scoped to the application directory.
For TLS termination at a proxy, securely set server `HTTPS` or PHP process
environment `RINCHEN_HTTPS=1` so cookies are Secure. These are not `src/.env`
settings. Client-provided forwarded headers are deliberately not trusted.

## Release checks

Build `makim frontend.build` so all forms include current CSRF tokens. On the
actual host, test both root/subdirectory deployments as applicable:

- Unauthenticated business pages redirect to login; JSON endpoints return 401.
- The staff-only `scripts/setup.php` is protected; GET does not create a database.
- Direct requests to `.backups/` and its files return 403/404, even after login.
- Direct requests to `.test-lab/`, `lab.sqlite`, and SQLite journal/WAL files
  return 403/404, even after login. The test directory is 0700 and files 0600.
  These are covered by the existing dotfile/database rules, not PHP authentication
  alone. Never export this directory with public static assets.
- `TEST_ENV=0` (or missing) disables all `templates/testing/` routes, including
  old QR links and pending POSTs. With `TEST_ENV=1`, staff login and CSRF still
  apply. Test scans must never change live attendance or the selected live event.
- Direct requests to `.env`, `db.sqlite`, libraries, migrations, backups and
  `static/app/index.html` return 403/404, even after login.
- Existing `static/qr/<hash>.jpg` URLs require login and then serve the image.
- Login, POST logout, expiry, form CSRF rejection, automatic in-period QR POSTs,
  and explicit outside-period/warning confirmations work. GET scans never write.
  `static/qr-checkin.js` is deployed; failures are not auto-retried and existing
  attendance is not duplicated. Issued QR query strings survive the login redirect.
- Cookies have Secure on HTTPS, and sessions/throttle files are private.
- Authenticated Python reports can fetch API data and QR images.

Do not deploy until these checks pass. The shared admin account is temporary:
rotate/replace it before broader production use. QR images cannot be displayed
by unauthenticated email clients under this access policy.

## Local verification

`makim dev.serve-local` uses `scripts/dev-router.php` for equivalent local file
protection. Never expose the PHP development server publicly.
`makim dev.auth-tests` runs disposable HTTP tests without touching live data.
Optional Apache tests use `RINCHEN_TEST_SERVER=apache python tests/auth/test_auth.py`.
