# NextTime (Nextcloud app id: `timebank`)

Community time-banking and mutual-aid coordination app for Nextcloud 29–35 (each of 29.0.16, 30.0.17, 31.0.14, 32.0.15, 33.0.10, 34.0.5 and 35.0.2 passes unit, smoke, desk and UI tests; all on SQLite and PHP 8.3).
Members post service requests, volunteer for each other, and earn hours that
are approved by an admin or by a 3-vote community process; every movement of
hours lands in a public ledger.

Design principle: this is anti-hierarchy infrastructure. Prefer transparency
(public ledger, visible votes) and community oversight over admin-only power
when making product choices; flag changes that concentrate control.

## Layout

```
appinfo/info.xml        app id, version, NC version range, settings, repair steps
appinfo/routes.php      intentionally EMPTY - all routes are PHP attributes
lib/Controller/*        HTTP API. #[FrontpageRoute] attributes define URLs
lib/Service/*           business logic (balances, earnings/voting, requests, comments, categories, adjustments, GovernanceLog)
lib/Db/*                Entities + QBMappers (tables prefixed oc_timebank_)
lib/Migration/          schema migration + SeedCategories repair step
lib/Schema/             organizer-desk spec (SchemaSpec), visibility rules (FieldPolicy), row validation
lib/Geo/                coordinate parsing and rounding for map output
lib/Settings/           admin settings page (display-only, see Known gaps)
src/                    Vue 3 + Pinia + vue-router (hash history) + MapLibre GL
  stores/               API calls via @nextcloud/axios + generateUrl()
  views/ components/    pages and widgets
templates/index.php     mounts the Vue app, loads js/timebank-main.mjs
js/ css/                BUILD OUTPUT (gitignored) - must exist or the page is blank
tests/unit/             PHPUnit 10 (needs a Nextcloud server checkout)
scripts/                dev-native.sh (Docker-free env), smoke-test.sh (E2E API)
demo/index.html         standalone browser demo, not wired to the backend
docs/                   data-and-access.md (where data lives, who can read it); proposals/ui-gaps.md (plan)
```

## Commands

Toolchain: PHP 8.3 (info.xml allows 8.3–8.5; 8.5 run via the `nextcloud:35-apache` image with the API smoke test. `composer.json` still pins 8.1 for tooling), Composer 2, Node >= 20 (`.nvmrc`), npm >= 10.

```bash
composer install && npm ci      # deps (as root: export COMPOSER_ALLOW_SUPERUSER=1)
npm run build                   # production build -> js/, css/
npm run watch                   # rebuild on change (dev mode)

# Fast checks - run after every change
composer lint                   # php -l over the tree
composer cs:check               # php-cs-fixer (cs:fix to apply)
npm run lint                    # eslint src (Vue 3 + TS preset)
npm run stylelint

# Tests (need a server checkout - scripts/dev-native.sh setup creates ../nc-server)
NEXTCLOUD_ROOT=../nc-server composer test:unit

# Running app + end-to-end API test
scripts/dev-native.sh setup     # once: clone NC stable31, SQLite install, enable app, users
scripts/dev-native.sh start     # http://127.0.0.1:8081  (stop / serve / reset / occ ...)
scripts/dev-native.sh upgrade   # after an info.xml version bump or new migration
scripts/smoke-test.sh           # 30 checks across the full lifecycle; exit code = failures
scripts/desk-smoke.sh           # organizer desk + PII rules over HTTP; re-runnable
node scripts/ui-smoke.cjs       # browser test of the real forms and map (needs Playwright)
```

Logins on the dev server: `admin/admin`, `alice/alice-pass-123`,
`bob/bob-pass-123`, `carol/carol-pass-123`. App URL:
`http://127.0.0.1:8081/index.php/apps/timebank/`. Server log:
`../nc-server/data/nextcloud.log` (JSON lines; debug + loglevel 0 are on).

Docker alternative: `make dev` / `make docker-up` (Postgres 15 + Redis 7 +
`nextcloud:31-apache`, PHP 8.3, port 8080, auto-installs from `.env`; the database is its own stack on external
volumes, see `docs/isolated-data.md`; `make docker-backup` needs `BACKUP_DIR`), then
`make docker-install-app` and `make docker-seed-users`. The image's password
policy rejects `admin`, so scripts read the admin password from `ADMIN_PASS`:
`ADMIN_PASS=... scripts/smoke-test.sh http://localhost:8080` (passes on this stack).
Not `nextcloud:29-apache`: it ships PHP 8.2. Demo data to click through:
`scripts/demo-data.sh [BASE_URL]` (`make demo`), idempotent.

## Definition of done for a change

1. `composer lint && composer cs:check` clean; `npm run lint` no new errors.
2. `NEXTCLOUD_ROOT=../nc-server composer test:unit` green.
3. Backend/route change -> `scripts/smoke-test.sh` all green against a running server.
4. Frontend change -> `npm run build`, then check the page in a browser.
5. New user-facing behaviour or limitation -> update README "Known Limitations"
   and CHANGELOG.md (Keep a Changelog format).
6. Conventional commit messages (`fix:`, `feat:`, `docs:`...) - CI blocks others.

## Gotchas (learned the hard way)

- **Route attributes decide the URL prefix.** `#[ApiRoute]` registers OCS routes
  under `/ocs/v2.php/apps/timebank/...`; `#[FrontpageRoute]` registers under
  `/index.php/apps/timebank/...`. The frontend calls the latter, so API
  controllers extend `Controller` and use `#[FrontpageRoute]`. Verify with
  `scripts/dev-native.sh occ route:list timebank`.
- **Static paths vs `{id}`.** Routes match in declaration order; give every
  `{id}`/`{requestId}` a `requirements: ['id' => '\d+']` so `/api/requests/my`
  isn't captured by `/api/requests/{id}`.
- **Entities:** every property typed `?\DateTime` needs
  `$this->addType('<prop>', 'datetime')` in the constructor, or reading a row
  throws a TypeError (HTTP 500). `tests/unit/Db/EntityTypesTest.php` enforces it.
  Hours are DECIMAL columns kept as strings in entities; cast with `(float)` in
  `jsonSerialize()`.
- **Repair steps:** `SeedCategories` must be listed under both `<install>` and
  `<post-migration>` in info.xml - `occ app:enable` on a fresh install only runs
  `<install>`. It is idempotent (skips if any category exists).
- **Create endpoints return 201**, everything else 200; errors are
  `{"error": "..."}` with 400/403/404. Services throw `InvalidStateException` (400, a move the
  lifecycle refuses) or `NotAuthorizedException` (403); controllers use the `ServiceErrors` trait
  to map them, and `DoesNotExistException` becomes 404.
- **Request lifecycle:** open → in_progress (first accepted offer) → completed | cancelled, both
  final. Several volunteers may be accepted; each is credited their own proposed hours. Complete
  does not credit hours (the claim flow does). See `docs/proposals/ui-gaps.md`.
- **Comments are moderated, not author-deleted.** 3 distinct flags hide a comment from members;
  moderators (NC admins and national organizers) remove or dismiss. Removal is soft (`removed_at`/`removed_by`).
  `RequestController::show` swaps in `CommentService::getForViewer` so hidden text never leaves the server.
- **Forms intake** (`lib/Intake/`): reads Nextcloud Forms' own `forms_v2_*` tables (nothing is written to Forms), maps
  questions to desk fields by name or text, imports each submission once as an unpublished row `NO-F<form>-<id>`
  (hand-typed rows are `NO-M...`). `scripts/intake-smoke.sh` needs the Forms app enabled (dev server: `occ app:enable forms`).
- **Chapters come from group names** (`lib/Chapter/ChapterDirectory.php`): "<Name>" plus "<Name> Facilitator(s)".
  The facilitator group alone gives the power. Membership and roles are checked by group id, never cached across
  requests. `ApproverPolicy` decides who may act for a member (admin, chapter facilitator, or a national organizer).
  National organizers are one group, "National Organizers" (`lib/Chapter/NationalOrganizers.php`, setting
  `national_organizer_group`); the legacy `timebank-organizer` and `GSUS` groups still count until their members move.
  Ask "is this person a national organizer" only through that class or `Viewer::$nationalOrganizer`. Desk rows get their chapter from the ZIP (`lib/Geo/ZipStates.php`). Tests need
  group names with spaces; `scripts/approver-smoke.sh` and `desk-smoke.sh` create "Oregon" groups with occ.
- **Nobody decides on their own claim or adjustment.** `EarningService::requireDecidable` covers approve, reject,
  send-to-vote and vote; `AdjustmentService` refuses the proposer and the member for approve, reject and vote. Keep that rule in any new path.
- **Admin changes are logged publicly.** Call `GovernanceLog::record()` for any new admin power. Categories in
  active use cannot be deleted; archive instead. `GET /api/categories` includes archived ones, so pickers filter them.
- Schema changes go in a NEW migration class (`VersionXXXXXXDateYYYYMMDDHHMMSS`)
  and a version bump in info.xml - never edit the shipped migration.
- The app folder must be named `timebank` (matches the app id).
- Composer pins `nextcloud/ocp: dev-stable31` but the app supports NC 29: avoid OCP
  APIs newer than 29 (psalm in CI checks against the stable29 API).
- `vendor-bin/*` holds isolated tool installs (phpunit, psalm, cs-fixer,
  rector); `composer install` populates them via bamarni/composer-bin-plugin.
- Psalm (`composer psalm`, level 1) is a CI gate with zero findings and no baseline; keep it that way.
  CI runs it once per Nextcloud API from 29 on, and older APIs type less, so check a change against
  the oldest too (`composer require --dev nextcloud/ocp:dev-stable29 --ignore-platform-reqs`, then
  restore composer.json/lock). Request values are untyped: read text with the controllers'
  `textParam()`/`optionalTextParam()` and `Service\Input`, never pass `getParam()` straight on.
  Layer and field shapes are the `Layer`/`Field` psalm types in `SchemaSpec`. The suppressions in
  psalm.xml are for things Nextcloud calls itself; each says why.
- **Requests leave the server only through `RequestPresenter`** (`RequestService::present()`). `Request::jsonSerialize()`
  omits `location`, `latitude` and `longitude` on purpose; the presenter adds them for the requester, facilitators of the
  request's state and accepted volunteers. Any new endpoint, notification or export that includes a request must use it.
  Map pins for everyone else come from `Geo\ZipCenters` (bundled Census table, `scripts/generate-zip-centers.php`).
- **Smoke scripts must leave users in no groups.** alice/bob/carol are plain members unless a script adds them; a group
  left behind (for example `timebank-organizer`, now a national organizer) changes what the next suite sees.
  Remove memberships in an exit trap. Exception: `scripts/demo-data.sh` leaves them in "Oregon" on purpose (demo
  servers only). `smoke-test.sh` changes groups through Nextcloud's provisioning API, so it also runs on Docker.
- `PublicLedger.vue` renders `getTypeIcon()` via `v-html` - keep that function
  returning fixed strings only, never user data.

- **`NcButton` styles with `variant="primary"` / `variant="error"`** (`@nextcloud/vue` 9). The old
  `type="primary"` does nothing now.
- **`NcSelect` returns the option object, not its id.** Always pass
  `:reduce="(option) => option.id"`, or forms send `categoryId: null` / `[object Object]`.
  `scripts/ui-smoke.cjs` guards this; the API smoke test cannot.
- **The map host must be in the page's Content-Security-Policy.** Nextcloud refuses
  connections to unlisted hosts, so the style fetch fails silently and no markers show.
  `MAP_STYLE_URL` (useMap.ts) and `MAP_HOST` (PageController.php) change together.
- **Scripts and CSRF:** before Nextcloud 30 the `OCS-APIRequest` header does not satisfy
  CSRF, so the smoke scripts keep a cookie session and send a `requesttoken`.
- **Dev server workers:** PHP's built-in server is single-threaded and stalls browser
  tests on newer Nextcloud; run it with `PHP_CLI_SERVER_WORKERS=4`.

## Known gaps (as of v0.5.x - check README "Known Limitations")

- Roles are Nextcloud groups filled by hand (no assignment UI). Only claim notices exist; other events are not notified.
- Voting always resolves at exactly 3 votes and negative balances are always rejected; these are fixed rules, shown
  read-only in Admin Settings. Only the adjustment threshold is a setting (`adjustment_threshold`, logged).
- Bundle is a single ~1.6 MB chunk (MapLibre) - candidate for lazy-loading the map.

## Working style

Brigidt plans before building: for anything bigger than a bug fix, propose the
approach (files touched, data/route changes, migration needs) and wait for a
go-ahead. Explain trade-offs in plain language. Don't push, tag, or open PRs
unless asked.
