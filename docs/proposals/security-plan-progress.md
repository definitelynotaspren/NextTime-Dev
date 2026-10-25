# Security and permission plan: progress

Tracks the six-phase edit plan from "NextTime: Security Testing Plan & Permission Review" (Oct 25, 2026; all seven
decisions confirmed). Each phase is a separate pull request that leaves the app working. Update this file as phases land,
so the next session can pick up from here.

## Status

| Phase | State | Branch / PR |
| --- | --- | --- |
| 1. One role model | Done | PR #49 |
| 2. Request geography and location privacy | Done | PR #49 (part 1), `claude/youthful-davinci-6pmld6` (part 2) |
| 3. Posting, voting and moderation gates | Not started (needs 2) | |
| 4. Adjustments by facilitators | Not started (needs 1) | |
| 5. Remote requests with Nextcloud files | Not started (needs 2) | |
| 6. Hardening carried over | Done | PR #49 |

## Phase 1: what landed

- `lib/Chapter/NationalOrganizers.php`: the one place that answers "is this person a national organizer". Group
  "National Organizers" by id or display name (setting `national_organizer_group`). The legacy `timebank-organizer` and
  `GSUS` (setting `fallback_approver_group`) groups still count; the **Chapters** page lists who is only in them.
- `Viewer` has `nationalOrganizer`, `facilitator`, `facilitatorOf` (chapter slugs) and `chapterMember`. Every logged-in
  user is a member, so that flag only appears in `/api/roles`.
- `ApproverPolicy::capacity()`: admin, then facilitator of a chapter the member is in, then national organizer
  (`as: national`) for anyone but themselves. Notifications: facilitators, else national organizers, else admins.
- `/api/roles`: `member`, `chapterMember`, `facilitatorOf`, `nationalOrganizer`, `facilitator`, `moderator`, `admin`
  (and `organizer` as an alias). `fallbackApprover` was removed.
- Tests: `ApproverPolicyTest` (national organizers, legacy group, notification order), `scripts/approver-smoke.sh`
  (national organizer over HTTP, legacy GSUS, check page).

Not done in Phase 1, on purpose: the national group name is set with occ, like the other settings, not in a form. The
admin settings page stays display-only.

## Phase 6: what landed

- `Service\Input::limit()` / `offset()`: every list endpoint (requests, ledgers, balances, governance) takes 1-100 rows
  and an offset of 0 or more. `smoke-test.sh` checks `limit=100000` and `limit=-1`.
- `#[UserRateLimit]` per 10 minutes: create request 50, claim 50, comment 100, offer 100, vote 100. Nextcloud stores
  the counters in `oc_ratelimit_entries` when no memcache is set up. Checked by hand: 55 posts gave 50 accepted, then
  429. Not in a smoke script, since tripping it locks that user out for 10 minutes. To reset on the dev server:
  `php -r '(new PDO("sqlite:../nc-server/data/owncloud.db"))->exec("delete from oc_ratelimit_entries");'`.
  The frontend shows a plain message for 429.
- `EarningService::submitClaim()` refuses an `evidenceFileId` the claimant cannot read
  (`IRootFolder::getUserFolder()->getById()`). Psalm needed `tests/stubs/oc_hooks_emitter.php` for `IRootFolder`.
- `desk-smoke.sh` and `ui-smoke.cjs` now use "National Organizers" and remove alice again at exit; before, she stayed
  in `timebank-organizer`, which since Phase 1 is a national organizer, and `smoke-test.sh` failed when run after them.

## Phase 2: what landed (part 1)

- Migration `Version000008Date20261028000001` (app 0.9.0): requests get `zip`, `chapter`, `chapter_override`, `mode`
  (default `in_person`), `resource_file_id`, `review_status` (default `published`); desk rows get `chapter_override`.
  Only `zip` and `chapter` are used so far; the rest wait for the reassign endpoint (Phase 2), Phase 3 and Phase 5.
- `RequestService::createRequest()` requires a ZIP (5 digits or ZIP+4, of a US state, via `ZipCenters::normalize()`)
  and sets `chapter`; an edit of an old ZIP-less request asks for one. `mode` is always `in_person` until Phase 5.
- `RequestPresenter` (tested row by row in `RequestPresenterTest`): exact for the requester, facilitators of the
  request's state (`chapter_override` first, else the ZIP's chapter, computed live) and volunteers whose offer is
  `accepted` now. Not `completed` offers: once the job is done the address is no longer shared. Used by list, detail,
  my requests, and every create/update/complete/cancel response. `Request::jsonSerialize()` no longer has the location.
- `Geo\ZipCenters`: Census 2023 ZCTA centers in `lib/Geo/data/zip-centers.txt` (fixed-width, binary-searched on disk,
  33,791 ZIPs). `/api/map/requests` uses ZIP centers for everyone; the old ~1 km rounding is gone for requests (desk
  feeds still use `Coordinates::toPublic()`).
- Frontend: ZIP field on the posting/edit form, ZIP on cards, "exact location shared once your offer is accepted" on
  the detail page, map falls back to `zipCenter`. Chapters page counts active requests without a ZIP.
- Tests: `smoke-test.sh` "Location privacy" (bad ZIPs, ZIP+4, member/accepted/withdrawn, map feed),
  `desk-smoke.sh` (Oregon facilitator exact, Washington facilitator ZIP only), `ui-smoke.cjs` (form ZIP, another
  member gets no point).

## Phase 2: what landed (part 2)

- `Service\StateReassignment`: `moveRequest()` / `moveDeskRow()` set `chapter_override` (null clears it), for
  facilitators of the old or new state, national organizers and admins; refused moves change nothing; every move is
  `request.state_changed` / `desk_row.state_changed` in the governance log. Routes `POST /api/requests/{id}/state`
  and `POST /api/layers/{layer}/rows/{id}/state`; the request detail carries `stateChoices`. `LayerService::chapterOf()`
  honours the override. Tests: `StateReassignmentTest`, `desk-smoke.sh` "Moving a request or desk row".
- Pin-first posting: `GET /api/geo/zip-centers` serves the table; `src/geo/nearestZip.ts` picks the ZIP whose
  center is nearest in units of that area's radius (the table now has a radius column, 31-byte lines), within
  1.5 radii + 10 km, else none. `NewRequestForm` fills the ZIP from a pin unless one was typed; "Also save this exact
  location" is off by default and gates `location`. `ui-smoke.cjs` checks the ZIP fills and nothing sent holds the pin.
- Fixed on the way: emptying `location` on edit now clears the point too.

Not automated: notifications never carry the address (claim notices have no request data today; re-check when
request notifications are added).

The plan's remaining phases (3, 4, 5), its testing checklist and its policy steps are restated in full in
[outstanding-work.md](outstanding-work.md), since the plan document itself is not in the repository.

## Next run: suggested order

1. ~~Phase 6~~ done.
2. ~~Finish Phase 2~~ done.
3. **Phase 4** can run in parallel with 2 (it only needs Phase 1): `AdjustmentService::propose()` scope, a `voting`
   status, an adjustment-vote table with a unique (adjustment, voter) index, and `listFor()` scoped to the viewer's
   states.
4. Phases 3 and 5 after 2.

Every phase: tests before the change, then `composer lint && composer cs:check && composer psalm`,
`composer test:unit`, `npm run lint`, `npm run build`, and against `scripts/dev-native.sh start`:
`smoke-test.sh`, `approver-smoke.sh`, `desk-smoke.sh`, `node scripts/ui-smoke.cjs` (needs `occ app:enable forms`).
