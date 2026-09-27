# React frontend and responsive redesign

## Outcome

Create a modern, minimalist, polished event-management experience that works
equally well for participants registering on phones and staff managing sessions
and attendance on larger screens. Retain the PHP/SQLite backend and Conda-based
developer workflow. Migrate in small, verifiable steps with a usable legacy UI
throughout the transition.

All checklist items below are planned work, not completed implementation.

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
  No React build, frontend test suite, or isolated API test harness exists yet.

## 1. Make development and testing reproducible

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

- [ ] Establish a dedicated `frontend/` application using React. Proposed tooling
  is TypeScript and Vite; confirm compatible versions at implementation time and
  commit the selected package-manager lockfile.
- [ ] Use Node.js from Conda for frontend tooling; keep production serving of
  compiled static assets compatible with PHP hosting, without requiring a Node
  application server.
- [ ] Add Makim targets for frontend development, build, lint/type checks, and
  tests. Document their actual names once implemented.
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

For each group, retain legacy access until behavior, visual review, and tests
pass. Remove duplicated jQuery/Bootstrap/DataTables dependencies from migrated
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
