# Proposal: closing the UI gaps

Status: **proposed, not started.** Per `CLAUDE.md`, nothing here is built until you give the go-ahead.
Evidence: code read, plus live checks against the Nextcloud 31 dev server (marked **verified**).

## Summary

Eleven things can't be done through the UI today (README, "Known Limitations"). The API for most
of them exists. Before wiring buttons to it I checked what the backend actually allows, and it
cannot support the intended flow and has integrity bugs. So the proposal is four steps:

| Step | What | Size | Needs your decision |
|------|------|------|---------------------|
| **0** | Fix backend rules (no UI). Recommended immediately, even if the UI waits. | S–M | D1, D2 |
| **A** | Close the loop: accept/decline a volunteer, mark a request complete. | M | D1, D6 |
| **B** | Self-service: edit/cancel a request, withdraw an offer, see my offers, delete my comment, per-user ledger. | M | D3 |
| **C** | Admin tools: categories, manual balance adjustment; Admin Settings page decision. | M–L | D4, D5 |

## The gaps

| Gap | API exists | UI today |
|-----|-----------|----------|
| Accept / decline a volunteer | `POST /volunteers/{id}/accept`, `/decline` | none |
| Mark a request complete | `POST /requests/{id}/complete` | none |
| Edit a request | `PUT /requests/{id}` | none |
| Cancel a request | `POST /requests/{id}/cancel` | none |
| Withdraw an offer | `DELETE /volunteers/{id}` | none |
| See my offers | `GET /volunteers/my` | none |
| Delete my comment | `DELETE /comments/{id}` | none |
| Another user's ledger | `GET /ledger/user/{userId}` | none |
| Category management | `POST/PUT/DELETE /categories` | none |
| Manual balance adjustment | `POST /balance/adjust` | none |
| Admin Settings | page only; toggles are not saved or read | non-functional |

## What I found underneath

### Verified live

1. **One offer locks a request.** `VolunteerService::offer()` sets the request to `in_progress` on
   the *first* offer, but only accepts offers while it is `open`. A second volunteer gets
   `400 "This request is not accepting volunteers"`. Request 8: bob's offer is still `offered`
   (never accepted), the request is already `in_progress`, and carol cannot offer. So choosing
   between volunteers (and the experience sort in `getRequestDetails`) cannot happen today.
2. **`complete` trusts any volunteer id.** Alice completed her own request using carol's offer on
   *bob's* request. Bob's request's offer flipped to `completed`, and carol's stats are incremented.
   There is no check that the offer belongs to the request, is accepted, or that the request is in
   a state that can be completed.
3. **State can run backwards.** A completed request can be cancelled, and a cancelled request can
   be completed again. Each `complete` increments the volunteer's stats again.

### Read in code (I will confirm each with a test before changing it)

4. `accept` / `decline` don't check the request's state, don't limit to one accepted offer, and
   `accept` doesn't move the request forward.
5. `withdraw` deletes an accepted offer, and if the last offer is withdrawn the request stays
   `in_progress` forever.
6. Deleting a category isn't guarded when requests, claims or stats reference it.
7. `balance/adjust` doesn't require a `reason`, doesn't check the target user exists, and changes
   the balance *before* recording the ledger entry (not atomic). The entry itself is public, which
   is good.
8. Only a comment's author can delete it; there is no moderation path.
9. **`complete` does not credit hours.** Hours move through an earning claim, approved by an admin or
   by 3 community votes. That is a separate flow, so the UI has to say so.

## Proposed rules

```
Request:  open ──accept an offer──▶ in_progress ──complete──▶ completed
            │                            │
            └────────── cancel ──────────┴──▶ cancelled        (completed and cancelled are final)

Offer:    offered ──▶ accepted | declined
          offered | accepted ──(volunteer withdraws)──▶ withdrawn
          accepted ──(request completed)──▶ completed
```

| Action | Who | Allowed when |
|--------|-----|--------------|
| Offer | anyone but the requester | request is `open`, no earlier offer from them (several offers allowed) |
| Accept | requester | request `open`, offer `offered` |
| Decline | requester | offer `offered` |
| Complete | requester | request `in_progress`; the offer must belong to it and be `accepted` |
| Cancel | requester | request `open` or `in_progress` |
| Withdraw | the volunteer | offer `offered` or `accepted` (see D2) |
| Edit | requester | request `open` |

## Step 0: backend rules (no UI)

- `offer()` stops changing the request's status. `accept()` does that.
- `accept()` / `decline()` / `complete()` / `cancel()` / `withdraw()` enforce the table above and
  answer `400` with a plain message when the move isn't allowed (matching the existing error style).
- `complete()` uses the accepted offer of *that* request; `volunteerId` becomes optional.
- On `withdraw`, an accepted offer is marked `withdrawn` and the request returns to `open`.
- Files: `lib/Service/VolunteerService.php`, `lib/Service/RequestService.php`, the two controllers.
- No migration (status is a string column), no new routes.
- Tests: PHPUnit service tests with mocked mappers, a regression test for each of items 1–3 above,
  and transition checks added to `scripts/smoke-test.sh`.

## Step A: close the loop

On the request detail page:

- **Requester:** each offer card gets *Accept* and *Decline* while the request is open. After
  accepting, the card shows "Accepted", the others show "Not selected", and a banner says
  "In progress with &lt;name&gt;". A *Mark complete* button opens a confirmation showing the helper and
  proposed hours, and says: "Hours are not credited automatically. &lt;name&gt; can claim them under
  Submit Earnings; an admin or 3 community votes approve."
- **Volunteer:** sees the status of their own offer, and a link to Submit Earnings once it is completed.
- Files: `src/views/RequestDetail.vue`, new `src/components/VolunteerCard.vue`,
  `src/stores/requestStore.ts` (move the inline API calls into store actions).
  Current user comes from `@nextcloud/auth`, already a dependency.
- Tests: `scripts/ui-smoke.cjs` gains "alice accepts bob, completes; bob sees it completed".
- Docs: README Known Limitations and CHANGELOG.

## Step B: self-service

- **Edit** (while open): reuse `NewRequestForm.vue` in an edit mode, so the selects and the map
  picker behave the same. **Cancel** gets a confirmation that mentions any accepted helper.
- **My offers:** `MyRequests.vue` gets two tabs, "My requests" and "My offers" (from
  `GET /volunteers/my`), with *Withdraw*.
- **Delete my comment:** a trash icon on your own comments.
- **Per-user ledger:** names link to a new `UserLedger` view reusing the public ledger table.
  This fits the transparency principle: anyone can see where hours came from.
- Files: `NewRequestForm.vue`, `MyRequests.vue`, `RequestDetail.vue`, new `views/UserLedger.vue`, `router/index.ts`.

## Step C: admin tools

- **Categories:** an admin-only view (create, edit name, description, icon, earn rate). The
  navigation link is gated by extending `GET /api/roles` with an `admin` flag. Deleting a category
  that is in use is blocked (D4).
- **Manual balance adjustment:** a form with a required reason (10+ characters) and a confirmation;
  the backend validates the user, requires the reason, and writes balance and ledger entry in one
  database transaction. The entry already shows in the public ledger with the admin's name.
- **Admin Settings page:** I recommend *not* wiring the toggles yet (D5). Today they are not
  saved or read, and they imply admins can switch off community voting. Interim option: replace
  them with a read-only "How decisions are made" panel that states the real rules (admin approval
  or 3 community votes; negative balances rejected), so the page stops promising controls it doesn't have.

## Decisions I need from you

- **D1. Several offers.** When the requester accepts one, do the others get declined automatically
  (recommended, simplest to understand), or can several helpers be accepted for a larger job
  (needs a design for splitting hours)?
- **D2. Withdrawing after being accepted.** Allowed, and the request reopens (recommended), or
  needs the requester's consent?
- **D3. Comments.** Author-only deletion (today), or the requester may also remove comments on their
  own request, or community flagging?
- **D4. Deleting a category.** Block it while in use (no migration), or archive it (needs a
  migration and a small UI for archived categories)?
- **D5. Concentrated control.** Should large manual balance adjustments need a second admin or a
  community vote? Should changes to settings be logged publicly, with limits (e.g. community voting
  can never be switched off, and the vote count has a floor)? This goes to your anti-hierarchy principle.
- **D6. Hours on completion.** Confirm hours should keep going through the separate claim and
  approval flow, not credit automatically when a request is completed.

## Out of scope

Notifications (email or Talk), geocoding free-text addresses, and mobile layouts.

## How each step will be verified

`composer lint`, `composer cs:check`, ESLint staying at its baseline, PHPUnit, `smoke-test.sh`,
`desk-smoke.sh`, `ui-smoke.cjs`, then the full run across Nextcloud 29–35 (about 25 minutes). Nothing
is pushed unless you ask.
