# Proposal: closing the UI gaps

Status: **Steps 0 and A are built and verified (app v0.4.0). Steps B, C and D are proposed; your
decisions D1–D6 are recorded below and shaped them.** Per `CLAUDE.md`, nothing in B–D is built until
you give the go-ahead.

## Summary

| Step | What | State |
|------|------|-------|
| **0** | Fix backend rules (no UI). | **Done** (D1, D2) |
| **A** | Close the loop: accept/decline volunteers, mark a request complete. | **Done** |
| **B** | Self-service: edit/cancel, withdraw, my offers, comment flagging, per-user ledger. | Ready to build (D3 designed below) |
| **C** | Admin tools: categories, second-approval balance adjustments, settings log. | Designed below (D4, D5); needs the questions at the end |
| **D** | Let chapter facilitators approve claims for their own chapter, then notify them. | Designed below (D6) |

## Decisions (recorded 2026-10-23)

1. **Several volunteers, hour for hour.** A request can accept several volunteers. Each accepted
   volunteer earns the hours they proposed; hours are not split between them. Nobody is declined
   automatically. *Built.* I read "tasks capable of supporting multiple volunteers" as every request.
   If you want a "volunteers needed" cap per request, that is a small optional field; say so.
2. **Withdraw and reopen, with a reason, logged.** *Built.* Withdrawing accepted help asks for a
   reason (3+ characters), writes it to a log of withdrawn help, and reopens the request if no
   accepted volunteers remain. A volunteer who has only offered (not been accepted) can step back
   without a reason, and that is not logged. The volunteer can read their own log
   (`GET /api/withdrawals/my`); the requester will see reasons for their own request in Step A.
3. **Comments: community flagging, moderators remove, no author-delete by default.** Designed in Step B.
4. **Categories: block deletion while in active use**, where unfilled and cancelled requests do not
   count as use. Designed in Step C.
5. **Large manual balance adjustments need a second approval** (a peer facilitator from the same area,
   or an admin), and **settings changes are logged publicly.** Designed in Step C.
6. **Keep the claim and approval flow**, and notify chapter facilitators (or timebank facilitators)
   of pending claims. Flow mapped at the end; not built.
   **6a. Chapter facilitators can approve claims for their own chapter** (decided 2026-10-23). Designed
   in Step D.

## What I found underneath (all verified on the dev server, now fixed in Step 0)

1. **One offer locked a request.** The first offer moved it to `in_progress`, but offers were only
   accepted while `open`, so a second volunteer was refused (`400 "not accepting volunteers"`).
2. **`complete` trusted any volunteer id.** Alice completed her own request using carol's offer on
   *bob's* request: bob's request's offer flipped to `completed` and carol's stats were incremented.
3. **State could run backwards.** A completed request could be cancelled and a cancelled one
   completed again, counting the volunteer's stats each time.

Still open, read in the code and to be confirmed by tests when each is built: category deletion is
not guarded; `balance/adjust` doesn't require a reason, doesn't check the user exists, and changes the
balance before writing the ledger entry (not atomic); comment deletion is author-only with no
moderation path. **`complete` does not credit hours.** Hours move through an earning claim,
approved by an admin or by 3 community votes, so the UI must say so.

## Rules as built (Step 0)

```
Request:  open ──first offer accepted──▶ in_progress ──complete──▶ completed
            │                                │
            └────────── cancel ──────────────┴──▶ cancelled        (completed and cancelled are final)
            ◀── last accepted volunteer withdraws ──┘

Offer:    offered ──▶ accepted | declined
          offered | accepted ──(volunteer withdraws; row removed, accepted ones logged)
          accepted ──(request completed)──▶ completed
```

| Action | Who | Allowed when |
|--------|-----|--------------|
| Offer | anyone but the requester | request `open` or `in_progress`; one offer per person; hours above 0 and at most 1000 |
| Accept | requester | request `open` or `in_progress`; offer `offered`. Several may be accepted |
| Decline | requester | request `open` or `in_progress`; offer `offered` |
| Complete | requester | request `open` or `in_progress` with at least one accepted offer. Completes **every** accepted offer, crediting each its proposed hours. `volunteerId` is optional and, if given, must be an accepted offer of this request |
| Cancel | requester | request `open` or `in_progress` |
| Withdraw | the volunteer | offer `offered` or `accepted`, request not finished. Accepted: reason required and logged |
| Edit | requester | request `open` |

Refused moves return HTTP 400 with a plain message, acting on someone else's request or offer returns
403, and a missing record returns 404.

### Where Step 0 differs from the first proposal

- Offers are accepted while a request is `in_progress` too (needed for several volunteers).
- Withdrawing removes the offer row, as before, and logs accepted withdrawals, rather than keeping a
  `withdrawn` status. That lets the same person offer again later, which the smoke test checks.
- Hours are validated when offering (above 0, at most 1000), matching the README.

### Built and verified

Code: `lib/Service/VolunteerService.php`, `RequestService.php`, the two controllers, the
`ServiceErrors` trait, two exception classes, `Withdrawal` and `WithdrawalMapper`, migration
`Version000003Date20261023000001`, app version 0.4.0. Tests: 30 new unit tests with mocked mappers
(each of the three bugs above has a regression test that fails on the old code) and three new
lifecycle sections in `scripts/smoke-test.sh` that run against a real server.

## Step A: close the loop (built)

Built on the request detail page, and exercised end to end by `scripts/ui-smoke.cjs` (accept two
volunteers, decline a third, one accepted volunteer withdraws with a reason, the requester completes):

- **Requester:** each offer card gets *Accept* and *Decline* while the request is active. Accepted cards
  show "Accepted" and a banner lists everyone helping. A *Mark complete* button opens a confirmation
  listing **every accepted volunteer and their hours**, and says: "Hours are not credited automatically.
  Each volunteer can claim theirs under Submit Earnings; an admin or 3 community votes approve."
- **Requester also sees** the reasons given by anyone who withdrew from their request (a small
  backend addition: include them in the request details for the requester only).
- **Volunteer:** sees the status of their own offer, a *Withdraw* button that asks for a reason when
  accepted, and a link to Submit Earnings once completed.
- Files: `src/views/RequestDetail.vue`, new `src/components/VolunteerCard.vue`,
  `src/stores/requestStore.ts` (move the inline API calls into store actions); current user from
  `@nextcloud/auth`. Tests: `scripts/ui-smoke.cjs` gains accept two volunteers → complete → both see it.
- Size: M. No migration. Also fixed on the way: `NcButton` primary and error styling (the app used the old
  `type=` prop, so no primary button was styled), and the offer form now shows only to someone who can
  actually offer (not the requester, not someone who already did).

## Step B: self-service and comment moderation (built in v0.5.0)

- **Edit** (while open): reuse `NewRequestForm.vue` in an edit mode. **Cancel** with a confirmation that
  mentions any accepted helpers.
- **My offers:** `MyRequests.vue` gets tabs "My requests" and "My offers" (from `GET /volunteers/my`),
  with *Withdraw* and the log of withdrawn help.
- **Per-user ledger:** names link to a `UserLedger` view reusing the public ledger table.
- **Comments (D3):**
  - Any member can flag a comment (not their own), once, with an optional reason. New table
    `timebank_comment_flags` (comment, user, reason, time; unique per comment and user).
  - At **3 distinct flags** (the same number as community votes) the comment is hidden from members
    behind "hidden by community flags", but not deleted.
  - **Administrators and project leads** (Nextcloud admins and the `timebank-organizer` group) see a
    moderation list of flagged comments with the reasons. They can **remove** a comment or **dismiss** the
    flags to restore it. Removal is soft (`removed_at`, `removed_by` on the comment), so there is an
    audit trail; members see "removed by a moderator".
  - `DELETE /api/comments/{id}` becomes moderator-only; authors no longer delete by default.
  - Needs one migration (flags table plus two comment columns).
- Size: M–L.

## Step C: admin tools (built in v0.6.0; "nobody decides on their own claim" decided 2026-10-25)

- **Categories (D4):**
  - A category is **in active use**, and cannot be deleted, while any request using it is `in_progress`
    or is `open` with at least one pending or accepted offer, or any earning claim using it is pending
    or in a vote. **Unfilled** open requests (no offers) and **cancelled** requests do not block.
  - Completed requests and approved claims are history. Deleting a category that history points at
    would leave dangling references, so I recommend **archive instead of delete**: an `archived_at`
    column hides it from pickers and keeps history readable, and a true delete is allowed only when
    nothing references it. This needs a migration; see question 3.
- **Manual balance adjustments (D5):**
  - Up to a threshold (default 5 hours, changeable by admins) one admin can adjust, with a required
    reason of 10+ characters, in a single database transaction.
  - Above the threshold the adjustment becomes a **proposal** that needs a second person who is not
    the proposer: an admin, or a facilitator of a chapter the recipient belongs to (Nextcloud groups
    `chapter-<slug>`). If the recipient has no chapter, an admin must approve.
  - Pending proposals are visible to facilitators, expire after 14 days, and the ledger entry names the
    proposer, the approver and the reason. New table `timebank_adjustment_requests`.
- **Settings (D5):**
  - Every settings change is written to a **public governance log** (who, what, old and new value,
    reason), readable by all members.
  - Limits so settings can't concentrate control: community voting can never be switched off and the
    vote count has a floor (3). I recommend wiring the toggles only once these rules exist. Until then,
    replace the non-functional toggles with a read-only "How decisions are made" panel.
- Size: L. Migrations for archive, adjustment proposals and the settings log.

## Step D: facilitators approve claims for their own state, and are notified (D6, D6a)

The claim and approval flow stays as it is, with two changes: more people may act on a claim, and the
people who may act are told when one is waiting. Decided 2026-10-25: **a state role and a separate
state-facilitator role must both be present**, and the group **`GSUS`** (shown as "GSUS (Everyone)")
decides for people who have no facilitator.

### Roles (Nextcloud groups)

| Group | Meaning |
|-------|---------|
| `chapter-<slug>` | Member of that state or chapter. It says where a person belongs. It gives no powers. |
| `chapter-<slug>-facilitator` | Facilitator role for that state. **Powerless alone.** |
| `GSUS` | Fallback approvers, used only for people whose state has no facilitator (or who have no state). The group name is the setting `fallback_approver_group`. |
| `timebank-organizer` | Organizer desk and comment moderation, unchanged. |
| `timebank-facilitator` | Unchanged for the organizer desk's non-chapter fields. It no longer lets anyone approve claims by itself. |

Before this step, being in `chapter-<slug>` made someone both a member and a facilitator. That is why
the roles are now split: facilitating a state means being in **both** `chapter-<slug>` and
`chapter-<slug>-facilitator`. This also tightens the organizer desk, which used the old single group.

### Who may act (D6a)

For a claim, or a large balance adjustment, about user U, a person P may decide when P is not U and one of
these holds:

1. P is a Nextcloud admin (kept so the community can never be locked out, and logged by the ledger).
2. For some state S that U belongs to, P is in **both** `chapter-S` and `chapter-S-facilitator`.
3. **None** of U's states has a facilitator (or U has no state), and P is in `GSUS`.

Rule 3 applies only while rule 2 has nobody to offer, so GSUS cannot override a state's own facilitators.
Approvers can approve, reject (with a reason) or send to a community vote. The pending list shows each
person only the claims they may decide, with the reason ("as facilitator of mt-hood", "as GSUS"). Nobody
acts on their own claim.

### Who is notified

1. Facilitators (both groups) of the claimant's states; if there are none,
2. `GSUS` members; if there are none,
3. Nextcloud admins.

The claimant is never notified of their own claim. The in-app notification shows who, hours and category,
never the description, and links to the approvals page. It is cleared for everyone as soon as the claim is
approved, rejected or sent to a vote. After 3 days still pending, the same people get one reminder; after 7
days the admins are told too.

Build: `ApproverPolicy` (used for claims and adjustments), a notifier registered in `Application`, hooks in
`EarningService`, an hourly `TimedJob` for reminders (one column, `earnings.notified_stage`), tests for who may
act and who is told. Per-user opt-out is left to Nextcloud's own notification settings.

## Questions I still need answered

1. **Volunteers needed (D1).** Do you want an optional per-request cap, or is "any number" enough?
2. **Author retract (D3).** Should authors be able to retract their own comment for a short window
   (for example 10 minutes, to fix a typo), or never?
3. **Archive vs delete (D4).** Is archiving (with a true delete only when nothing references the
   category) acceptable? It needs a migration.
4. **Adjustment threshold (D5).** Is 5 hours a sensible default for "large", and 14 days a sensible expiry?
   (Built with those; the threshold is changeable.)
5. ~~Claims about yourself (D6a).~~ Decided: nobody approves their own claim. Built in Step C.

## Out of scope

Email templates beyond a plain notice, geocoding free-text addresses, and mobile layouts.

## How each step is verified

`composer lint`, `composer cs:check`, ESLint staying at its baseline, PHPUnit, `smoke-test.sh`,
`desk-smoke.sh`, `ui-smoke.cjs`, then the full run across Nextcloud 29–35.
