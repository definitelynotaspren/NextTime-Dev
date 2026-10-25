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
| 6. Hardening carried over | Not started (independent, small) | |

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

## Next run: suggested order

1. **Phase 6** first if the budget is small: clamp `limit` to 1-100 and `offset` to >= 0 (request, ledger, balance
   controllers), `#[UserRateLimit]` on create request/comment/offer/claim/vote (`OCP\AppFramework\Http\Attribute\UserRateLimit`
   exists from Nextcloud 27, so it is safe for 29), and check a claim's `evidenceFileId` is readable by the claimant via
   `IRootFolder::getUserFolder($uid)->getById()`. No migration. Add smoke checks for `limit=100000` and `limit=-1`.
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
