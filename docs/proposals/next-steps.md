# To do next

Written 2026-10-24, after chapters began to be discovered from Nextcloud groups. Size: S under a day, M a few
days, L a week or more. "Decision" means I need your answer before building.

## Needed to run the three flows end to end

| # | Item | Size | Notes |
|---|------|------|-------|
| T1 | **Phone form that creates desk rows** (`source=form`) | M-L | **Decision:** (a) NextTime reads submissions from the Nextcloud *Forms* app on a schedule (Forms exposes submissions through its API with an app password; I could not confirm a "form submitted" webhook), or (b) NextTime serves its own public mobile form. (a) reuses a tool the organization may already use; (b) gives full control over fields, ZIP and consent wording and needs no extra app, but is a public page, so it needs rate limiting, a hidden-field trap and a consent checkbox. I lean to (b), because mapping free-form questions to the schema is the hard part either way and the schema's rules (ZIP, lookups, PII) live here. Either way the row starts unpublished, and personal details land only in organizer-only fields. |
| T2 | **Link a claim to a request** and show the co-volunteers' claims side by side to the approver | M | Add `request_id` to earnings (migration), offer "claim for this job" from the completed request page (pre-fills hours and category), and show what others on the job claimed. Optional: each volunteer confirms the others' hours. |
| T3 | **Promote a desk row to a board request** (and link them) | M | One button for a facilitator; the request keeps a pointer to the row, and closing one updates the other. |
| T4 | **Tell the person who submitted** (status updates) | M | Needs a way to reach someone who has no Nextcloud account: email or SMS. Decision on consent and which channel. |
| T5 | **Suggested matches** between needs and offers (same category, ZIP or chapter) | M | A list on the desk; nothing automatic. |
| T6 | **Decision: where do hours come from?** Approved claims create hours; requesters are never charged | S to decide, M-L to build | Keep as "recognize contributions", or move to a closed time bank (debit the requester, enforce limits). Changes the ledger and the balance rules. |
| T7 | **Personal dashboard**: claims waiting or rejected, hours by category, my jobs | S-M | The balance and ledger exist; add the rest to My Balance. |

## Roles and set-up

| # | Item | Size | Notes |
|---|------|------|-------|
| R1 | **Screen to assign people to chapters and facilitator groups** | M | Today an admin edits Nextcloud groups by hand. Chapters show on the new Chapters page, but cannot be edited there. |
| R2 | **Chapters for committees** (Accessibility, Education, ...) | S | Already works: add "Education Facilitators" and they become a chapter with ZIP-less rows. Decision: should a committee facilitator approve claims for committee members? Today yes. If not, an exclusion list. |
| R3 | **ZIP coverage for city chapters** | S | New York City has default prefixes. Other city chapters need prefixes set in the `chapter_zip_prefixes` setting (JSON). A small settings screen would do. |
| R5 | **Let chapter facilitators assign and work their chapter's rows** | S-M | Today only organizers can assign, set status and publish; facilitators can only read their chapter's rows. **Decision:** is that what you want (organizers as the hands, facilitators as the reviewers), or should facilitators work their own chapter's rows? The second spreads work and control, in line with the anti-hierarchy aim. |
| R4 | **Facilitator groups that list no state** | S | The Chapters page already lists groups it ignored (such as "Chapter Facilitators") so spelling mistakes can be spotted. |

## Before wider use

| # | Item | Size | Notes |
|---|------|------|-------|
| P1 | The WireGuard / VPN requirement for sensitive data | M | Skipped by choice for now. Design in `data-and-access.md`. |
| P2 | PostgreSQL and MySQL runs | M | Everything so far ran on SQLite. |
| P3 | Notify on other events (cancelled request, hidden comment, accepted offer) | S-M | Only claim notices exist. |
| P4 | Public, anonymised map feed and export for organizers | M | Needs a consent decision. |
| P5 | Lazy-load the map (1.6 MB bundle) | S | |
| P6 | Optional per-request volunteer cap, author retract window for comments | S | Open questions from the first plan. |

## Suggested order

R-items R3 and R1 (so a new chapter needs no developer), then T1 (the phone form, the missing front door), T3 and
T2 (connect the desk, the board and hours), T7, then decisions T6 and T4. P1 and P2 before real people's data goes in.
