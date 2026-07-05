# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
