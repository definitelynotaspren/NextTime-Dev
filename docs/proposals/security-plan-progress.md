# Security and permission plan: progress

Tracks the six-phase edit plan from "NextTime: Security Testing Plan & Permission Review" (Oct 25, 2026; all seven
decisions confirmed). Each phase is a separate pull request that leaves the app working. Update this file as phases land,
so the next session can pick up from here.

## Status

| Phase | State | Branch / PR |
| --- | --- | --- |
| 1. One role model | Done | `claude/youthful-davinci-6pmld6` |
| 2. Request geography and location privacy | Not started | |
| 3. Posting, voting and moderation gates | Not started (needs 2) | |
| 4. Adjustments by facilitators | Not started (needs 1) | |
| 5. Remote requests with Nextcloud files | Not started (needs 2) | |
| 6. Hardening carried over | Done | `claude/youthful-davinci-6pmld6` |

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

## Next run: suggested order

1. ~~Phase 6~~ done.
2. **Phase 2** next; it is the largest and everything in 3 and 5 depends on it. Start with the migration
   (`Version000008...`, columns in the plan) and `RequestService::present(Request, Viewer)`, with unit tests for every row
   of the Location visibility table written before the change. Then make `Request::jsonSerialize()` drop coordinates and
   address, and route list, detail, map and notifications through `present()`. Zip-center table and the pin-first form
   can be a second PR within Phase 2.
3. **Phase 4** can run in parallel with 2 (it only needs Phase 1): `AdjustmentService::propose()` scope, a `voting`
   status, an adjustment-vote table with a unique (adjustment, voter) index, and `listFor()` scoped to the viewer's
   states.
4. Phases 3 and 5 after 2.

Every phase: tests before the change, then `composer lint && composer cs:check && composer psalm`,
`composer test:unit`, `npm run lint`, `npm run build`, and against `scripts/dev-native.sh start`:
`smoke-test.sh`, `approver-smoke.sh`, `desk-smoke.sh`, `node scripts/ui-smoke.cjs` (needs `occ app:enable forms`).
