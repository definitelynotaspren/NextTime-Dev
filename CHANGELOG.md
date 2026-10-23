# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

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

### Changed

- Declares support for Nextcloud 35 (`max-version` 33 → 35). Nextcloud 35
  requires PHP 8.3; the declared minimum stays 8.1 for Nextcloud 29–34. Only
  tested so far against the stable31 dev server. `nextcloud/ocp` stays at
  `dev-stable31`, matching `composer.lock`.
- App version 0.2.0 → 0.3.0 so the new migration runs on upgrade.

### Added

- `CLAUDE.md` and `.claude/` (settings, PHP lint hook, `/check` and `/smoke` commands).
- `scripts/dev-native.sh`: Docker-free dev server (Nextcloud stable31 on SQLite).
- `scripts/smoke-test.sh`: end-to-end API test of the full request/earning/vote lifecycle.
- `tests/unit/Db/EntityTypesTest.php`; test bootstrap finds the server via `NEXTCLOUD_ROOT`.
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
