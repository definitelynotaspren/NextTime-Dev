# Outstanding work

Everything planned for NextTime that is not built yet, in one place, written 2026-10-25 ahead of moving the repository
to the InputLoop organization. Detail lives in the documents linked from each section; this page is the index.

Size: S under a day, M a few days, L a week or more. **Decision** means a person has to choose before it is built.

| Source | What it holds |
| --- | --- |
| [security-plan-progress.md](security-plan-progress.md) | The security and permission plan of 2026-10-25: what each finished phase built |
| [next-steps.md](next-steps.md) | The product backlog (T, R and P items) |
| [ui-gaps.md](ui-gaps.md) | The earlier interface plan; its open items are carried into next-steps.md |
| README, "Known limitations" | What users should know about today's behaviour |

## 1. Security and permission plan: remaining phases

The plan itself ("NextTime: Security Testing Plan & Permission Review", 2026-10-25) is not in the repository, so its
remaining phases are restated here in full. Its seven decisions were confirmed on 2026-10-25; Decision 2 may be
revisited after the first communities use it. Phases 1, 2 and 6 are done.

Roles, as built in Phase 1: **Member** (any logged-in user), **chapter member** (in a state group such as "Oregon"),
**state facilitator** (in "Oregon Facilitators"), **national organizer** (in "National Organizers"). Each has everything
the one before it has.

### Phase 3: posting, voting and moderation gates (M; needs Phase 2, done)

- [ ] **Posting** (Decision 1). Chapter members publish directly. A Member's request saves as `review_status =
  awaiting_review`, visible only to them and national organizers, until a national organizer publishes or declines it.
  Add publish and decline endpoints for national organizers (logged). The column exists (migration `Version000008`).
- [ ] **Request list.** Members and chapter members see open requests plus their own; facilitators and national
  organizers also see in-progress ones in their scope. Today everyone sees every status.
- [ ] **Voting** (Decision 2). `EarningService::validateVoterEligibility()` requires chapter membership and the same
  state as the claimant. Claims from members with no state go to a vote among national organizers.
- [ ] **Comment moderation.** `CommentService::isModerator()` takes the request: facilitators of the request's state
  plus national organizers. Today any national organizer or admin moderates everywhere.
- [ ] Frontend: hide Post, Vote and Moderate controls for people without the power (the server still enforces it); a
  review queue page for national organizers.

### Phase 4: adjustments by facilitators (M; needs Phase 1, done; can start now)

Decision 3, as agreed:

1. A facilitator proposes an adjustment for a member of their own state, with hours (at most 100 either way) and a
   written reason (10+ characters). National organizers may propose for anyone.
2. At or under the threshold (`adjustment_threshold`, 5 hours by default, set by an admin): a second facilitator of that
   state or a national organizer approves or rejects it. No vote.
3. Over the threshold: a 14-day vote of that state's chapter members. It passes with at least 3 votes and more
   approvals than rejections; fewer than 3 votes by day 14 means it expires.
4. The proposer and the member adjusted cannot vote or approve. For members with no state, national organizers vote.
5. Every step goes to the public governance log; the ledger entry names the proposer and the deciders.
6. Nextcloud admins keep today's direct power as a break-glass, still logged, still unable to approve their own.

Build:

- [ ] `AdjustmentService::propose()` scoped as above.
- [ ] A `voting` status and an adjustment-vote table with a unique (adjustment, voter) index (new migration); resolve
  and expire as above.
- [ ] `AdjustmentService::listFor()` scoped to the viewer's states; national organizers see all. Today any facilitator
  sees every state's adjustments.
- [ ] Adjustments page: propose form, vote buttons, countdown to expiry.

### Phase 5: remote requests with Nextcloud files (M; needs Phase 2, done)

Decision 6: read-only share to the accepted volunteer; the requester can raise it to edit per request.

- [ ] Posting form gets an "In person / Remote" toggle; remote hides the location fields and shows Nextcloud's file
  picker. Columns `mode` and `resource_file_id` exist. Today `mode` is always `in_person`.
- [ ] On save, confirm the requester owns or may reshare the chosen file or folder.
- [ ] On accepting a volunteer, create a read-only user share to them; remove it on complete, cancel or withdraw
  (`VolunteerService`, `RequestService`).
- [ ] **Decision:** which state a remote request belongs to (it has no ZIP): the poster's, or national?

### The plan's testing checklist, not yet automated

Run on a local Nextcloud with the two-state test world from the plan (nina, ana, otto, ben, fern, wes, nora):

- [ ] Section 1 (roles) items that depend on Phases 3 and 4: nina's post awaiting review, voting by state, moderation
  by state, facilitator adjustments and their vote, wes seeing no Oregon adjustments.
- [ ] Section 2: notifications and comment emails never include the address (no request notifications exist yet;
  re-check when P3 adds them). Remote requests and shares (Phase 5).
- [ ] Section 3: "send the same vote twice at the same moment" (the database index enforces it; no concurrent test);
  a request body with `review_status` or `nationalOrganizer: true` is ignored; an OWASP ZAP baseline scan.
- [ ] Section 4: a request whose category or requester account was deleted still loads; a fresh install with no data
  shows empty states; no console errors on every page.

### The plan's data-protection steps that are policy, not code

- [ ] Only admins create groups; nobody who should not mint a facilitator role is a group sub-admin.
- [ ] Each community confirms its members know the ledger, histories and balances are public by design. **Decision:**
  show a display name or member number instead of the Nextcloud user id.
- [ ] Server: HTTPS with Nextcloud's security scan clean; two-factor login enforced for facilitator, national
  organizer and admin groups; encrypted, test-restored database backups weekly; patches and Dependabot merges kept up;
  a monthly community review of the governance log.

## 2. Decisions raised while building (2026-10-25)

| # | Question | Today |
| --- | --- | --- |
| D1 | When should the legacy `timebank-organizer` and `GSUS` groups stop counting as national organizers? | Both still count; the Chapters page lists who is only in them. Former `GSUS` members gained nationwide decide power with Phase 1. |
| D2 | Should Puerto Rico, Guam, other territories and military (APO/FPO) ZIPs be accepted? | Refused: the ZIP-to-state table covers the 50 states and DC. |
| D3 | Should a volunteer keep seeing the address after the job is completed? | No: only while their offer is `accepted`. |
| D4 | Are the rate limits right? | Fixed per 10 minutes: 50 requests, 50 claims, 100 comments, offers and votes. Not settings. |
| D5 | Requests posted before ZIPs were required have no state and no map pin for others until edited. Contact the posters? | Counted on the Chapters page only. |
| D6 | Should the national organizer group name be editable in Admin settings? | Set with `occ config:app:set timebank national_organizer_group`. |

## 3. Product backlog

From [next-steps.md](next-steps.md), which has the notes. Status as of 2026-10-25.

| # | Item | Size | Status |
| --- | --- | --- | --- |
| T2 | Link a claim to a request; show co-volunteers' claims to the approver | M | Not started |
| T3 | Promote a desk row to a board request, linked both ways | M | Not started |
| T4 | Tell the person who submitted (no Nextcloud account) | M | **Decision:** consent and channel |
| T5 | Suggested matches between needs and offers | M | Not started |
| T6 | Where hours are spent | M-L | **Decision:** design of spending |
| T7 | Personal dashboard (claims, hours by category, my jobs) | S-M | Not started |
| R1 | Screen to assign people to chapters and facilitator groups | M | Not started |
| R2 | Committee chapters (Education, ...) | S | Works; **Decision:** may committee facilitators approve claims? |
| R3 | Settings screen for city chapter ZIP prefixes | S | Not started (JSON setting today) |
| R5b | Contact details for the person working a desk row | S | **Decision:** who may read requester contact details |
| P1 | WireGuard / VPN for sensitive data | M | Skipped by choice; design in data-and-access.md |
| P2 | PostgreSQL and MySQL runs | M | API smoke test passes on PostgreSQL (Docker); MySQL/MariaDB untested; desk/UI tests SQLite only |
| P3 | Notifications for other events (cancelled request, hidden comment, accepted offer) | S-M | Only claim notices exist |
| P4 | Public, anonymised map feed and organizer export | M | **Decision:** consent |
| P5 | Lazy-load the map (1.6 MB bundle) | S | Not started |
| P6 | Optional volunteer cap per request; comment retract window | S | Open questions |

Not planned yet (README "Roadmap"): mobile app, calendar integration, CSV/PDF export, a NextTime-hosted public form,
translations.

## 4. Technical debt

- [ ] No JavaScript unit tests: `src/geo/nearestZip.ts` and the stores are covered only by `scripts/ui-smoke.cjs`.
  Adding Vitest would let the pin lookup be tested without a browser.
- [ ] The ZIP tables are snapshots: `lib/Geo/ZipStates.php` (October 2026) and `lib/Geo/data/zip-centers.txt` (Census
  2023 ZCTA). Rebuild the latter with `scripts/generate-zip-centers.php` when the Census publishes a new gazetteer.
- [ ] `composer.json` still pins PHP 8.1 for tooling while the app needs 8.3; the lock file reports it is out of date.
- [ ] The pin-to-ZIP guess uses centers and sizes, not ZIP borders; bundling simplified ZCTA shapes would make it exact
  at the cost of a larger download.
- [ ] Rate-limit counters live in `oc_ratelimit_entries` when no memcache is configured; production should use Redis.
- [ ] Organizer-desk public feeds still round points to about 1 km (`Coordinates::toPublic()`); requests now use ZIP
  centers. Consider the same for desk rows.

## 5. Moving the repository to InputLoop

GitHub redirects the old URLs after a transfer, so nothing breaks on the day; update these right after the move.

- [ ] `appinfo/info.xml` lines 22 and 26-28: `author` homepage, `website`, `bugs`, `repository`. These show on the
  Nextcloud app store, so decide what author name the organization wants.
- [ ] `README.md` line 62 (clone URL) and the Issues link near the end.
- [ ] `DEPLOYMENT_GUIDE.md` lines 74, 120 (clone URLs) and 523 (Issues link).
- [ ] Repository secrets do not move with a transfer: re-add `COMMAND_BOT_PAT` (used by `npm-audit-fix.yml`). The
  other workflows use the built-in `GITHUB_TOKEN`.
- [ ] Re-check organization settings that the workflows rely on: Actions allowed, Dependabot enabled, and workflow
  permissions able to open pull requests (`update-nextcloud-ocp-matrix.yml`, `npm-audit-fix.yml`).
- [ ] Re-install or re-authorize GitHub Apps used on the repository (the Claude GitHub App, if it is used there).
- [ ] Branch protection and required checks are per repository; confirm they survived the transfer.
- [ ] Local clones: `git remote set-url origin https://github.com/InputLoop/<repo>.git`.
- [ ] CLAUDE.md names Brigidt as the person who approves plans; update if the organization has another process.

## 6. Suggested order

1. **Phase 4** (adjustments by facilitators): self-contained, needs only Phase 1.
2. **Phase 3** (posting, voting and moderation gates): changes what members see, so announce it to the communities.
3. **Phase 5** (remote requests), after answering its open state question.
4. The decisions in section 2, then the backlog in the order next-steps.md suggests (R5b, R3, R1, T3, T2, T7, T4).
5. P1 and P2 before real people's data goes in.
