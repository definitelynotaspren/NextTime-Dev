# Proposal: closing the UI gaps

Status: **Step 0 is built and verified (app v0.4.0). Steps A, B and C are proposed; your decisions
D1–D6 are recorded below and shaped them.** Per `CLAUDE.md`, nothing in A–C is built until you
give the go-ahead.

## Summary

| Step | What | State |
|------|------|-------|
| **0** | Fix backend rules (no UI). | **Done** (D1, D2) |
| **A** | Close the loop: accept/decline volunteers, mark a request complete. | Ready to build |
| **B** | Self-service: edit/cancel, withdraw, my offers, comment flagging, per-user ledger. | Ready to build (D3 designed below) |
| **C** | Admin tools: categories, second-approval balance adjustments, settings log. | Designed below (D4, D5); needs the questions at the end |
| **Later** | Notify facilitators of pending claims. | Flow mapped below (D6) |

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

## Step A: close the loop

On the request detail page:

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
- Size: M. No migration.

## Step B: self-service and comment moderation

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

## Step C: admin tools

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

## Notifying facilitators of pending claims (D6): flow, not built

The claim and approval flow stays as it is. This adds notice to the people who can act on it.

```
Volunteer submits an earning claim          (EarningService::claim, status pending)
        │
        ▼
Who is told?
  1. Facilitators of the claimant's chapter(s): members of the Nextcloud group chapter-<slug> for each
     chapter the claimant belongs to.
  2. If there are none (no chapter, or the chapter has no facilitators): the timebank-facilitator group.
  3. If that is empty too: Nextcloud admins.
  The claimant is never notified about their own claim, and a facilitator never gets or acts on their own.
        │
        ▼
In-app Nextcloud notification (INotificationManager), optional email per user
  "New claim from <user>: <hours> h, <category>"  → link to #/admin/approvals
  It shows who, hours and category only, never the description (which may contain personal details).
        │
        ├─ claim approved, rejected or sent to a vote → the notification is cleared for everyone
        └─ still pending after 3 days → one reminder; after 7 days → escalate to the next group in the list above
```

Build plan: `lib/Notification/Notifier.php` registered in `Application::register()`, a hook in
`EarningService`, a daily `TimedJob` for reminders and escalation (registered in `info.xml`), a per-user
opt-out setting, and tests that check who gets notified.

## Questions I still need answered

1. **Volunteers needed (D1).** Do you want an optional per-request cap, or is "any number" enough?
2. **Author retract (D3).** Should authors be able to retract their own comment for a short window
   (for example 10 minutes, to fix a typo), or never?
3. **Archive vs delete (D4).** Is archiving (with a true delete only when nothing references the
   category) acceptable? It needs a migration.
4. **Adjustment threshold (D5).** Is 5 hours a sensible default for "large", and 14 days a sensible expiry?
5. **Who can act on claims (D6).** Today only admins can approve, reject or send a claim to a vote.
   Notifying facilitators who can't act would frustrate them. Should chapter facilitators be allowed to
   approve or send to a vote for their own chapter? That would spread control and fits your
   anti-hierarchy principle, but it is a permissions change.

## Out of scope

Email templates beyond a plain notice, geocoding free-text addresses, and mobile layouts.

## How each step is verified

`composer lint`, `composer cs:check`, ESLint staying at its baseline, PHPUnit, `smoke-test.sh`,
`desk-smoke.sh`, `ui-smoke.cjs`, then the full run across Nextcloud 29–35.
