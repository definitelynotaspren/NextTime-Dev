# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- **Lists are capped at 100 rows** (Phase 6 of the security and permission plan). `limit` is clamped to 1 to 100
  and `offset` to 0 or more on the request list, ledgers, balances and governance log (which allowed 200), so a
  single call cannot read everything at once; a negative or non-numeric value falls back to the default.
- **Posting is rate-limited** per person over 10 minutes: 50 new requests and 50 claims, 100 comments, offers and
  votes. Beyond that Nextcloud answers 429 and the app says to wait a few minutes.
- **A claim's evidence file must be one the claimant can read.** A file id is just a number, so before, a claim
  could point at anyone's file.

### Changed

- **One national organizer role** replaces the separate `timebank-organizer` and `GSUS` fallback groups
  (Phase 1 of the security and permission plan). Everyone in the group "National Organizers" (setting
  `national_organizer_group`) may decide claims and adjustments for any member but themselves, works the
  organizer desk everywhere and moderates comments. Members whose state has no facilitator, or who have no
  state, are decided by national organizers. Members of the two old groups keep the role until an admin moves
  them; the **Chapters** page lists them. Because the role is nationwide, former `GSUS` members can now also
  decide for members whose chapter has a facilitator.
- `GET /api/roles` reports `member`, `chapterMember`, `facilitatorOf` (chapter slugs) and `nationalOrganizer`.
  `fallbackApprover` is gone; `organizer` remains as an alias of `nationalOrganizer`. A claim's `decidingAs`
  is now `national` where it was `fallback`.

### Fixed

- **NextTime could not be installed on the official Nextcloud 33, 34 or 35 Docker images**, which ship PHP 8.4
  and 8.5: info.xml allowed PHP 8.3 only, so Nextcloud refused with "PHP with a version lower than 8.3 is
  required". It now allows 8.3 to 8.5. On `nextcloud:35-apache` (PHP 8.5.12) the API smoke test passes and the
  log shows no NextTime warnings; 8.4 is not run yet. CI lints the code on 8.3, 8.4 and 8.5.
- **The Docker development stack could not run NextTime.** Its `nextcloud:29-apache` base image ships PHP 8.2,
  and NextTime needs 8.3. It now uses `nextcloud:31-apache` (PHP 8.3), drops build steps that failed and were
  not needed (the base image already has the PHP extensions), and the Makefile uses `docker compose`
  instead of the retired `docker-compose`. The API smoke test passes on it, the first run on PostgreSQL.
- **On Nextcloud 29, NextTime could break every user's notification list.** Its notifier refused other
  apps' notifications with `UnknownNotificationException`, a class Nextcloud 29 does not have; the
  resulting error was not caught. It now falls back to `\InvalidArgumentException` there.
- A request body with a list where text was expected could cause a server error: for example a
  `deadline` sent as a list made posting a request fail with HTTP 500. Values of the wrong type are now
  ignored or refused with a plain message.
- CI: static analysis (psalm) runs on PHP 8.3 and passes with no findings against every Nextcloud API
  from 29 to the current one. The ~500 earlier findings were fixed, or, where Nextcloud calls the code
  itself (controllers, migrations, jobs), configured with a stated reason. Nine unused methods were removed.
- Missing spaces in two places: the Governance Log ran the action into the name ("an adjustment foralice",
  "created categoryGardening") and Withdrawn help on a request ran the volunteer into the hours ("carol3 h").
- The README is rewritten to match the app: working curl examples (they need `/index.php` and the `OCS-APIRequest`
  header), the real install and dev steps, the right Make targets and table list, a Roles section that no longer sat
  under the wrong heading, and limitations that were out of date.
- README: part of the API and security sections had been duplicated by an earlier edit; removed.
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

- App settings are read and written through `IAppConfig` instead of the deprecated `IConfig` app-value
  calls. Stored values and `occ config:app:set` work as before.
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

- `scripts/demo-data.sh` (`make demo`) fills a dev server with a small community to click through: requests with
  map pins, offers, a job in progress, a claim waiting for an admin and one in a community vote.
- The smoke test and the demo script take the admin password from `ADMIN_PASS` (default `admin`), for the
  Docker stack, whose password policy rejects `admin`.
- **Add a form by its link** on the Form Intake page: paste the Forms share link (or the form's address) and the page
  looks the form up, says plainly which links work (a form on this server; not Google Forms or another Nextcloud), shows how
  each question maps, and adds it on a click. Only forms added this way are listed, so organizers no longer see the
  titles of every form on the server. `POST /api/intake/forms/lookup`.
- **Nextcloud Forms intake** (Form Intake page, background job every five minutes, `scripts/intake-smoke.sh`).
  New submissions of the forms you switch on become unpublished desk rows with `source = form` and an ID that shows
  the origin (`NO-F<form>-<submission>`; hand-typed rows are `NO-M...`). Rejections are logged with the reason.
  Migration `Version000007` (0.8.0) adds `timebank_intake_log`.
- **Facilitators work their own chapter's desk rows**: add rows for their ZIPs, assign to themselves, set progress,
  publish and unpublish (`POST .../rows/{id}/publish`). Losing the facilitator group ends it at once, and an
  organizer can release a row held by someone who lost access.

### Changed

- **Chapters are now discovered from Nextcloud groups** instead of two fixed ones. A group "Colorado" and a group
  "Colorado Facilitators" (or "Facilitator") make the Colorado chapter, with no setup. **The facilitator group alone
  gives the power**; the earlier rule that also required the `chapter-<slug>` member group is gone, and the old
  `chapter-mt-hood` / `chapter-gresham` groups are no longer special. Organizer-desk rows are matched to a chapter
  by ZIP code (a built-in ZIP-to-state table, plus ZIP prefixes for city chapters) each time they are read, so a
  facilitator who loses the role stops seeing the rows and stops being able to approve at once.
- New **Chapters** page (admins and organizers): chapters found, facilitators, states with no facilitator group,
  and facilitator groups that were ignored.
- `docs/flows.md` walks an offer, a need and a three-person job through the system; `docs/proposals/next-steps.md` is
  the to-do list.

### Added

- **Facilitators approve for their own state, and are notified** (Step D of `docs/proposals/ui-gaps.md`).
  - **Facilitating a chapter now takes two groups:** `chapter-<slug>` and `chapter-<slug>-facilitator`. Either
    alone does nothing. This also applies to the organizer desk, where `chapter-<slug>` used to be enough, so
    existing facilitators must be added to the new `-facilitator` group.
  - A person may approve, reject or send to a vote a claim by member U (never their own) if they are an admin,
    or facilitate a chapter U belongs to, or none of U's chapters has a facilitator and they are in `GSUS`
    (setting `fallback_approver_group`). The pending list shows only the claims you may decide, and why. Large
    balance adjustments use the same rule for their second approver.
  - **Notifications:** submitting a claim notifies the facilitators of the claimant's chapters (else `GSUS`, else
    admins) with who, hours and category only. It clears when the claim is decided. An hourly job reminds them
    after 3 days and tells the admins after 7. Migration `Version000006` (0.7.0) adds `notified_stage`.
  - `GET /api/roles` also reports `fallbackApprover`.
  - `scripts/approver-smoke.sh` checks all of it; `dev-native.sh` now installs the Notifications app.
- **Admin tools** (Step C of `docs/proposals/ui-gaps.md`).
  - **Nobody decides on their own claim.** Approve, reject, send to a vote and vote all refuse the claimant,
    admins included. A claim can be decided only once and votes are taken only while it is in a vote
    (before, a claim could be approved twice and credited twice).
  - **Categories** page for admins: create, edit (the earn rate is limited to 5), archive, restore and
    delete. A category in use (a request in progress, an open request with offers, a claim waiting) cannot be
    deleted, and neither can one that past work points at: archive it, which hides it from new requests and keeps
    history readable.
  - **Balance adjustments** page: one admin can adjust up to a threshold (default 5 hours, 1 to 20); larger
    adjustments need a second person who is not the proposer or the member (another admin, or a facilitator of
    one of the member's chapters), and expire after 14 days. Applying an adjustment and writing the ledger now
    happen in one database transaction, and a debit can never take a balance below zero. The ledger names
    both people. The old `POST /api/balance/adjust` is removed.
  - **Governance log**, readable by every member: category changes, adjustments, and the threshold, with the
    old and new values and the reason.
  - The Admin Settings page now explains how decisions are made instead of showing switches that did nothing.
  - "Approvals & Votes" no longer fails for members: the vote list shows for everyone, review of pending claims
    only for admins, and your own claims show no buttons. Errors from these buttons are shown.
  - Migration `Version000005` (version 0.6.0): `archived_at` on categories, `timebank_adjustments`,
    `timebank_governance_log`.
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
