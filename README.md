# Rinchen event management

The main interface now uses React with a responsive, minimalist layout. PHP and
SQLite continue to handle event, subscription, and attendance workflows.

```bash
conda activate rinchen
makim frontend.install
makim frontend.build
```

Run the existing PHP application to open the built interface. For frontend
work, use `makim frontend.dev`; see [frontend/README.md](frontend/README.md) for
PHP proxy setup, the sample-data preview, deployment, and checks. The classic
menu remains available at `templates/index.php?legacy=1`.

See [AGENTS.md](AGENTS.md) for repository guidance and [ROADMAP.md](ROADMAP.md)
for the remaining migration milestones.

Welcome to the Rinchen repository! This document provides guidelines on how
to contribute to this project and set up your local development environment.

## Login and protected access

All application pages, JSON endpoints, and generated QR images require login.
Use the temporary shared `admin` account and the password supplied for this
deployment. Only its hash is stored in PHP; credentials are not in the React
bundle. Replace this shared account with individual accounts before broader use.

Sessions expire after 30 minutes of inactivity or 12 hours total. Forms and
logout use CSRF protection; login is limited to five failed attempts per IP per
15 minutes. Issued QR links still work, but staff must log in and confirm
admission. QR images in emails cannot load anonymously; do not distribute the
admin credentials to participants.

### Staff QR admission

Only staff use the app; attendees present their QR at the door. The scan shows
the attendee and session, with warnings for missed previous sessions.
**Permitir participación** records attendance; **Denegar participación** saves a
denial for that session without recording attendance. Rescans show the denial;
staff can explicitly change it to allow participation. Existing attendance is
not duplicated. Decisions record the account, time, and warning snapshot.

Before deploying this workflow, back up the SQLite database, then run:

```bash
makim dev.qr-migrate
makim dev.qr-tests
```

The migration adds only the decision audit table/index; do not rerun the older
migration bundle against existing data. Tests use disposable synthetic SQLite
databases and require PHP's sqlite3 extension. QR uses the event selected in web setup (event ID 1 until configured)
and still selects a session within its active time window.

### Set up the November event without console access

Upload the updated application, then open `/scripts/setup.php` relative to the
application URL (for example `/event-retiro/scripts/setup.php`). The repository
file is `src/scripts/setup.php`; the repository-root `scripts/` directory is
not the web root. Log in as staff first. This endpoint uses the application's
shared libraries, so deploy those changes too, not just the endpoint file.

The form proposes November **12–15, 2026**, with **09:00–12:00** and
**14:00–18:00** sessions on November 12 and 15, with afternoon only on
November 13 and 14 in **America/La_Paz**. Change the name,
description, year, and hours before saving. These hours are placeholders, not
confirmed event policy.

- Saving creates a missing SQLite database and the current schema, including
  QR decisions. No separate console migration is needed for a successful setup.
- By default it creates or updates the setup-managed November event for that
  year. To adopt an existing event, explicitly select its ID. Matching sessions
  at exactly the requested start times are reused; other sessions are retained.
- Repeat saves update the six linked sessions in place, preserving their IDs,
  attendance, and decisions. A different year creates a separate event by default.
- Re-saving an older eight-session setup removes the managed morning sessions
  on November 13 and 14 only when they have no attendance or admission decisions.
  If they have history, the update stops without changing records.
- Existing databases are backed up using SQLite's backup API to `src/.backups/`
  before saving. This private, ignored directory must remain blocked by the
  web-server rules. Keep independent off-server backups too.
- Old incompatible attendance schemas are refused without deleting records.
  Do not use the historical migration bundle as a workaround.
- “Usar este evento para QR” selects the event for staff QR check-in. Legacy
  manual/forced-attendance shortcuts still use event ID 1 and are not changed
  by this setting.

GET only shows the form; writes require authenticated POST, CSRF, and a one-use
form token. PHP needs the sqlite3 extension and write access to `src/`, the
database, and the private backup directory. Remove or restrict this maintenance
endpoint after setup if it is no longer needed. Use `makim dev.setup-tests`
for disposable regression tests.

For local PHP development (external PHP, Conda tools):

```bash
makim dev.serve-local
makim dev.auth-tests
```

Do not use a bare PHP development server without `scripts/dev-router.php`:
it would expose private files. The auth tests use disposable code copies and
synthetic files, not the working database or configuration.

**Deployment requires web-server rules as well as PHP authentication.**
See [containers/README.md](containers/README.md) for HTTPS, private-file blocking,
session storage, and release checks. Rebuild frontend assets with
`makim frontend.build` and rebuild the container when using Sugar.

Python subscription reports now authenticate using a cookie session. They prompt
for a password, or accept `RINCHEN_REPORT_PASSWORD`, with optional
`RINCHEN_REPORT_USER` (default `admin`) and `RINCHEN_REPORT_BASE_URL`
(default `http://localhost:8081`). Use HTTPS outside localhost; never commit
passwords or store them in shell history.

## Test the email sender

The home page (React and classic menu) has a **Test email sender** button.
Configure the existing `src/.env` settings using plain, unquoted values:

```dotenv
SEND_EMAIL=1
TEST_EMAIL_TO=first@example.org,second@example.org
EMAIL_FROM=sender@example.org
EMAIL_REPLY_TO=reply@example.org
```

The addresses above are examples; replace them with your own. Use plain email
addresses, not display names. Whitespace around recipients is trimmed and
duplicate recipients are removed. Invalid or empty list items prevent the entire
send. An empty `EMAIL_REPLY_TO` falls back to `EMAIL_FROM`.

Tests send only to `TEST_EMAIL_TO`; production `EMAIL_CC` is deliberately not
included. The endpoint uses the same PHP `mail()` helper as production messages,
so the host's mail/sendmail service must be configured. Errors appear on screen.
Success means the mail system accepted the message, **not** that it reached every
inbox; check inbox/spam folders. There is no automatic retry, and requests are
limited to one attempt per minute per staff session. Preview mode never sends.

`makim dev.email-tests` tests this endpoint in a temporary copy using a fake
sendmail program; it never delivers real email or modifies working configuration.
The old `tests/EmailTest.php` still sends real mail and is not part of this task.

## CSV participant imports

Use **Importar CSV** in React or `templates/subscription/upload.php` in the
classic interface. Uploading only reviews the file: no database writes, QR
requests, or email. Confirm the reviewed rows to import, or cancel. The review
expires after ten minutes and cannot be submitted twice.

Versioned rules live in `src/lib/csv_rules/` and are detected from column names,
not the filename or current date:

| Profile | Required columns |
| --- | --- |
| Historical Google Forms (`legacy.php`) | `Nombre`, `Apellidos`, `Correo electrónico`, `Número de celular`, `valido` |
| Participants 2026 (`2026.php`) | `No.`, `NOMBRE`, `APELLIDOS`, `CORREO ELECTRONICO`, `No. CELULAR`, `CIUDAD` |

Column order, surrounding whitespace, case, and accents in headers are ignored.
CSV must be comma-separated UTF-8 (optional BOM), at most 5 MB and within the
host's PHP upload limits. Empty lines and comma-only/whitespace-only rows are
ignored, including before the header. Extra unrelated columns are allowed;
missing, duplicate named, or ambiguous headers are rejected. `No.` and `CIUDAD`
identify the 2026 format but are not stored; names, email, and phone use the
existing person/subscription schema. Registration timestamps remain the time
of import, not the historical form timestamp.

The 2026 adapter removes phone formatting such as spaces, parentheses, and
hyphens; it does not guess a misplaced `+`, country code, or missing digits.
Invalid rows are listed by physical CSV line number and omitted while valid
rows can be confirmed. The supplied local roster has 73 importable rows and a
phone requiring correction on line 70 (`591+…`). The roster itself is ignored
by Git; do not commit participant details.

Historical `valido` values other than 1 are now **warnings**, not automatic
payment-based rejection. Staff see them before confirming **Continuar de todos
modos e importar**, or may cancel. The 2026 roster has no payment marker; no
payment eligibility is inferred. Each imported person/subscription and its
`csv_import_audit` record are saved in one transaction. The audit stores the
profile, source row, warnings, staff confirmation, UTC timestamp, account, and
optional note; shared `admin` does not identify an individual. The additive audit
table is created on a confirmed import, not on GET/review. Back up the database
before deployment; no historical migrations are needed for this change.

Existing exact name/email/phone subscriptions are skipped without another QR
request or email. No fuzzy matching or automatic identity merging occurs.
Multiple existing people with identical identifying data require data review;
the importer does not choose one arbitrarily and issue an ambiguous QR.
Confirmed imports **do attempt participant confirmation emails** through the
existing transport and configured `EMAIL_CC`. QR/database failures do not save
the row. Mail failure after saving is reported separately: the subscription
remains, and no automatic retry occurs. `SEND_EMAIL=0` is reported as disabled/
unsent, not successful delivery. Review before using the existing email resend
action after any ambiguous transport failure.

Subscription retrieval uses the stored `person_id` (not an assumption that the
subscription/person IDs match); activation safely preserves quoted names.
Newly generated QR attendance links URL-encode
identity values, preserving `+` in international phone numbers and email tags,
and `&` in names; the existing attendance path and query keys are unchanged.

Run `makim dev.csv-tests` with the external PHP SQLite extension. It uses
disposable code/databases/configuration, synthetic participants, fake QR
generation, and fake sendmail: **no real emails or external QR requests**.
When the supplied local roster exists, a read-only parsing check also runs with
mail and cURL entry points disabled; CI uses synthetic equivalents and skips
that optional private-file check. Do not run the legacy real-mail test as a
substitute.

## Table of Contents

- [Contributing](#contributing)
  - [Getting Started](#getting-started)
  - [Making Changes](#making-changes)
  - [Creating a Pull Request](#creating-a-pull-request)
- [Setting Up the Development Environment](#setting-up-the-development-environment)
  - [Prerequisites](#prerequisites)
  - [Installing Dependencies](#installing-dependencies)
  - [Enabling PHP Extensions](#enabling-php-extensions)

## Contributing

We welcome contributions from the community! Follow the steps below to
contribute to this project.

### Getting Started

1. Fork the repository by clicking the "Fork" button at the top right of this
   page.

2. Clone your forked repository to your local machine:

   ```bash
   git clone https://github.com/your-username/rinchen.org.git
   ```

3. Change directory to the project:

   ```bash
   cd rinchen.org
   ```

4. Add the original repository as a remote:

   ```bash
   git remote add upstream https://github.com/xmnlab/rinchen.org.git
   ```

### Making Changes

1. Create a new branch for your feature or bug fix:

   ```bash
   git checkout -b my-feature-branch
   ```

2. Make your changes and commit them:

   ```bash
   git add .
   git commit -m "Description of changes"
   ```

3. Push your changes to your forked repository:

   ```bash
   git push origin my-feature-branch
   ```

### Creating a Pull Request

1. Visit your forked repository on GitHub.

2. Click on the "New Pull Request" button.

3. Set the base repository to the original repository and the base branch to the
   branch you want to merge your changes into.

4. Review your changes and click "Create Pull Request."

## Setting Up the Development Environment

To work on this project locally, follow these instructions to set up your
development environment.

### Prerequisites

- PHP (version >= 8.1)
- Composer

### Installing Dependencies

1. Install Composer (if not already installed):
   [Composer Installation Guide](https://getcomposer.org/download/)

2. Clone the repository to your local machine (if you haven't already):

   ```bash
   git clone https://github.com/your-username/rinchen.org.git
   ```

3. Change directory to the project:

   ```bash
   cd rinchen.org
   ```

4. Install project dependencies using Composer:

   ```bash
   composer install
   ```

### Enabling PHP Extensions

Ensure that the following PHP extensions are enabled in your php.ini
configuration file:

- `mbstring`
- `dom`

You can enable these extensions by modifying your php.ini file:

- For Ubuntu/Debian:

  ```bash
  sudo nano /etc/php/8.2/cli/php.ini
  ```

- For CentOS/RHEL:

  ```bash
  sudo nano /etc/php.ini
  ```

- For macOS with Homebrew:

  ```bash
  sudo nano /usr/local/etc/php/8.2/php.ini
  ```

Uncomment the lines for `mbstring` and `dom` extensions by removing the
semicolon (`;`) at the beginning of the lines:

```ini
extension=mbstring
extension=dom
```

Save the changes and restart your web server or PHP-FPM service:

- For Apache:

  ```bash
  sudo service apache2 restart
  ```

- For Nginx:

  ```bash
  sudo service nginx restart
  ```

- For PHP-FPM:

  ```bash
  sudo service php8.2-fpm restart
  ```

### Conda Environment

To set up the Conda environment for this project, use the following commands:

```bash
# Create the Conda environment from the provided YAML file
mamba env create -f conda/dev.yaml

# Activate the Conda environment
conda activate rinchen
```

### Makim Automation

We use Makim for automation tasks. Here are some common tasks:

#### Run the Server Application

To run the server application, use the following Makim command:

```bash
makim dev.runserver
```

This command will start the PHP server with the appropriate environment
variables.

#### Run Tests

To run the PHPUnit tests, use the following Makim command:

```bash
makim dev.tests
```

This command will execute PHPUnit tests located in the specified test file.

## Usage

TBD

## License

This project is licensed under the MIT - see the
[LICENSE.md](LICENSE.md) file for details.


## Next steps

It would be nice to have it using Lavarel: https://laravel.com/docs/10.x
But it would require some work on the server side as well.

Or, if it would be possible, it would be better to have it using Python with Django.
But it would also depends on the server infrastructure.
