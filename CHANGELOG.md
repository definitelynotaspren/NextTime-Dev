# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- Moving from one request to another in the address bar kept showing the first request; the page now reloads
  when the id changes.
- **Primary and destructive buttons were unstyled everywhere.** `@nextcloud/vue` 9 uses
  `variant="primary"` / `variant="error"`; the app used the old `type=` prop, so Post Request, Volunteer,
  Submit Claim, Approve and Reject all looked like plain buttons.
- **Completing a request trusted any volunteer id.** A requester could complete their own request
  with an offer from someone else's request, marking that offer completed and counting its stats.
  Volunteers are now looked up through the request being completed.
- **Request states could run backwards.** A completed request could be cancelled and a cancelled
  one completed again, counting a volunteer's stats each time. Completed and cancelled are now final.
- **One offer locked a request.** The first offer moved it to in progress, and offers were only
  accepted while open, so a second volunteer was always refused.
- `scripts/dev-native.sh upgrade` (new): `occ upgrade` failed on the stable31 checkout because the
  always-enabled `viewer` app lives in its own repo and is missing from a git checkout. Setup and
  `upgrade` now clone it, and `upgrade` switches maintenance mode off afterwards.
- **API routes were still unreachable**: controllers used `#[ApiRoute]`, which
  registers OCS routes under `/ocs/v2.php/...`, while the frontend calls
  `/index.php/apps/timebank/api/...`. Switched to `#[FrontpageRoute]`.
- `GET /api/requests/my` was captured by `/api/requests/{id}`; numeric
  `requirements` added to every `{id}`/`{requestId}` route.
- Every entity read with a date column threw a TypeError (HTTP 500): `?\DateTime`
  properties lacked `addType(..., 'datetime')`. Fixed in all entities.
- Default categories weren't seeded on a fresh `occ app:enable`;
  `SeedCategories` now also runs as an `<install>` repair step.
- ESLint used the Vue 2 / JS preset and couldn't parse the TypeScript sources;
  now uses `@nextcloud/eslint-config/vue3`.
- **Posting a need, submitting earnings and the board filters sent bad data.**
  `NcSelect` returns the whole option object, but the forms treated it as an id, so
  requests and claims went out with `categoryId: null` (HTTP 400) and filters with
  `status=[object Object]`. All selects now use `:reduce`. The priority list offered
  "Flexible", which the backend rejects; it now matches low/normal/high/urgent, and the
  status filter offers every status the backend sets (open, in progress, completed, cancelled).
- **The map never loaded.** Nextcloud's Content-Security-Policy only allows connections
  to itself, so the basemap style request was refused and no markers were drawn. The
  map host is now allowed in the page policy (including `blob:` web workers).
- Page titles were clipped by the sidebar toggle on every screen; content is now offset.
- Status badges were pale on pale; they now use the darker theme colours, and
  completed/cancelled requests have badges too.
- `appinfo/info.xml` now validates against the app store schema (element order only, no behaviour
  change), which the `lint-info-xml` CI job checks.
- `scripts/smoke-test.sh` failed on Nextcloud 29 (`CSRF check failed`): versions before
  30 ignore the `OCS-APIRequest` header, so it now keeps a session and sends a token.

### Changed

- Basemap: Carto → [OpenFreeMap](https://openfreemap.org) (OpenStreetMap data, no API key).
  Viewers' browsers contact `tiles.openfreemap.org`; see README → Map to change it.
- Nextcloud support is now 29–35 (`max-version` 33 → 35), and only versions that were run
  are claimed: unit tests, `smoke-test.sh`, `desk-smoke.sh` and `ui-smoke.cjs` all pass on
  29.0.16, 30.0.17, 31.0.14, 32.0.15, 33.0.10, 34.0.5 and 35.0.2, each a fresh install on SQLite with PHP 8.3. PostgreSQL, MySQL and PHP 8.1/8.2
  were not run here (Nextcloud 35 itself requires PHP 8.3). `nextcloud/ocp` stays at
  `dev-stable31`, matching `composer.lock`.
- `scripts/dev-native.sh start`/`serve` now run PHP with 4 workers (`PHP_WORKERS`), and
  `stop` ends the whole process group; worker processes otherwise outlive the parent.
- App version 0.2.0 → 0.4.0 so the new migrations run on upgrade.
- **Request lifecycle rules** (Step 0 of `docs/proposals/ui-gaps.md`). A request can have several
  volunteers: offers are accepted while it is open or in progress, the requester can accept as many
  as needed, nobody is auto-declined, and completing credits every accepted volunteer for the hours
  they proposed (hour for hour, not split). The first acceptance, not the first offer, moves a request
  to in progress. Accepting, declining, withdrawing, completing, cancelling and editing now check the
  request's and offer's state, and offered hours must be above 0 and at most 1000.
- Errors from these actions are now typed: 404 for a missing record, 403 when you may not do it,
  400 with a plain message for a move the rules refuse (it used to be 403 for everything).
- Withdrawing an accepted offer asks for a reason, writes it to a new log of withdrawn help
  (`timebank_withdrawals`), and reopens the request if no accepted volunteers remain.
- Declared PHP is now 8.3 only (`info.xml`, README, INSTALL, DEPLOYMENT_GUIDE), because 8.1 and
  8.2 were never run. The `composer.json` and `psalm.xml` 8.1 pins are tooling settings and
  were left alone. Stale Nextcloud ranges in INSTALL and DEPLOYMENT_GUIDE (29–33, 27–32) now
  say 29–35.

### Added

- **Self-service and comment moderation** (Step B of `docs/proposals/ui-gaps.md`).
  - Requesters can edit their request while it is open (title, description, category, hours, priority,
    deadline, location) and cancel it from a confirmation that names any accepted helpers. Edits keep the
    limits of posting.
  - "My Activity" now has tabs for My requests, My offers (with a withdraw button) and Withdrawn help.
  - Names link to that member's ledger (`#/ledger/user/<name>`).
  - **Comments:** any member can flag a comment that is not theirs, once. Flag counts are public; reasons are
    for moderators. At 3 distinct flags a comment is hidden from members, not deleted. Nextcloud admins and
    the `timebank-organizer` group see a Flagged Comments queue, and can remove a comment or keep it (dismiss
    the flags). Removal is soft and names the moderator. Authors can no longer delete their own comments.
    New table `timebank_comment_flags` and two columns on `timebank_comments` (migration, version 0.5.0).
  - `GET /api/roles` also reports `moderator`; the request response carries `canModerate`.
- `CLAUDE.md` and `.claude/` (settings, PHP lint hook, `/check` and `/smoke` commands).
- `scripts/dev-native.sh`: Docker-free dev server (Nextcloud stable31 on SQLite).
- `scripts/smoke-test.sh`: end-to-end API test of the full request/earning/vote lifecycle.
- `tests/unit/Db/EntityTypesTest.php`; test bootstrap finds the server via `NEXTCLOUD_ROOT`.
- **Request page** (Step A of `docs/proposals/ui-gaps.md`): requesters accept or decline each offer, see
  who is helping in a status banner, and complete the request from a confirmation that lists every accepted
  volunteer and their hours and says that hours are claimed separately. Volunteers see their own offer's
  status, can withdraw (a reason is required once accepted), and get a link to claim hours when completed.
  Requesters also see the reasons people gave for withdrawing. The offer form shows only to someone who can
  actually offer, and errors from these actions are shown in plain words.
- `docs/data-and-access.md`: where the data lives, how it is protected and where it is not, and how to read
  the raw data outside NextTime.
- Unit tests for the lifecycle rules (`tests/unit/Service/`, mocked mappers) and lifecycle sections in
  `scripts/smoke-test.sh` covering several volunteers, the integrity cases and the withdrawal log.
- Requests store `latitude` and `longitude`. Explicit values are validated;
  otherwise they are read from `"lat, lng"` location text. A repair step backfills
  existing rows.
- `GET /api/map/requests`: GeoJSON of open requests, with points rounded to about
  1 km and only non-contact fields.
- Organizer desk: `GET /api/schema`, `GET|POST /api/layers/{layer}/rows`,
  `POST|DELETE /api/layers/{layer}/rows/{id}/assign`,
  `POST /api/layers/{layer}/rows/{id}/status`, `GET /api/assignments`, and
  `GET /api/layers/{layer}/feed`.
- Field visibility (public, facilitator, chapter, organizer) enforced server-side
  on every read. Undeclared fields and unknown visibility levels fail closed.
  Survey rows are aggregate-only and never returned by any endpoint.
- Roles come from the Nextcloud groups `timebank-organizer`,
  `timebank-facilitator`, and `chapter-<slug>`.
- `GET /api/withdrawals/my`: the caller's own log of withdrawn help.
- `GET /api/roles`: the caller's own organizer/facilitator flags. The Organizer Desk and
  Data Schema links now show only to organizers and facilitators.
- `scripts/desk-smoke.sh`: organizer-desk and PII checks over HTTP (re-runnable).
- `scripts/ui-smoke.cjs`: browser test that fills the real forms (needs Playwright). It
  fails on the old select bug, which the API smoke test could not see.

### Removed

- Stale app-template `ApiTest` that referenced non-existent classes.

## [0.2.0] - 2026-07-05

### Fixed

- **API routing**: all API controllers extended `OCSController`, which registers
  routes under `/ocs/v2.php/apps/timebank/...` and wraps responses in an OCS
  envelope — but the frontend called `/index.php/apps/timebank/api/...` and
  expected plain JSON. Every API call 404'd. Controllers now extend
  `Controller` so routes and response shapes match the frontend.
- **info.xml**: removed the `<external-app>` block (only valid for AppAPI
  ExApps, not regular PHP apps), replaced the invalid `SPREN` category with
  `organization`/`social`, and raised the minimum Nextcloud version to 29
  (the `#[ApiRoute]`/`#[FrontpageRoute]` attributes used throughout do not
  exist before 29).
- Emptied `appinfo/routes.php` to avoid duplicate route-name registration with
  the `#[FrontpageRoute]` attribute on `PageController`.
- Relaxed the Node.js engine constraint from `^24` to `>=20` so the frontend
  builds on current LTS releases.

### Removed

- `.github/workflows/openapi.yml` and `openapi.json`. `nextcloud/openapi-extractor`
  only auto-documents routes on `OCSController`-derived controllers; now that
  the API controllers correctly extend `Controller` (see above), every route
  is treated as undocumented and the extractor hard-errors with no way to
  produce a spec. Re-add spec generation later if the API controllers gain
  explicit `#[OpenAPI(scope: ...)]` attributes.

### Added

- `INSTALL.md` with drag-and-drop install instructions for Nextcloud AIO,
  standard installs, and the dev environment.
- `release.yml` GitHub Actions workflow: pushing a `v*` tag builds the
  frontend and attaches a ready-to-install `timebank.tar.gz` to the release.
- `make appstore` now depends on the frontend build and fails loudly if
  compiled assets are missing, so a source-only (broken) package can no longer
  be produced.

## [0.1.0]

### Added

- First release
