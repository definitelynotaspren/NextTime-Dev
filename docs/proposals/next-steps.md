# To do next

Written 2026-10-24, after chapters began to be discovered from Nextcloud groups. Size: S under a day, M a few
days, L a week or more. "Decision" means I need your answer before building.

## Needed to run the three flows end to end

| # | Item | Size | Notes |
|---|------|------|-------|
| T1 | ~~Phone form that creates desk rows~~ | done | Built as Nextcloud Forms intake (decided 2026-10-24): a background job checks chosen forms every five minutes; IDs show the origin (`NO-F<form>-<id>` vs `NO-M...`). Reserve `NO-U...` for rows from a NextTime-hosted public form, if one is ever added. |
| T2 | **Link a claim to a request** and show the co-volunteers' claims side by side to the approver | M | Add `request_id` to earnings (migration), offer "claim for this job" from the completed request page (pre-fills hours and category), and show what others on the job claimed. Optional: each volunteer confirms the others' hours. |
| T3 | **Promote a desk row to a board request** (and link them) | M | One button for a facilitator; the request keeps a pointer to the row, and closing one updates the other. |
| T4 | **Tell the person who submitted** (status updates) | M | Needs a way to reach someone who has no Nextcloud account: email or SMS. Decision on consent and which channel. |
| T5 | **Suggested matches** between needs and offers (same category, ZIP or chapter) | M | A list on the desk; nothing automatic. |
| T6 | **Where hours are spent** | M-L | *Decided for now:* approving a claim creates hours. Spending comes later, expected to sit between a closed 1:1 system and purely counted earnings. Needs a design: what the person helped gives, limits, and what a balance means. |
| T7 | **Personal dashboard**: claims waiting or rejected, hours by category, my jobs | S-M | The balance and ledger exist; add the rest to My Balance. |

## Roles and set-up

| # | Item | Size | Notes |
|---|------|------|-------|
| R1 | **Screen to assign people to chapters and facilitator groups** | M | Today an admin edits Nextcloud groups by hand. Chapters show on the new Chapters page, but cannot be edited there. |
| R2 | **Chapters for committees** (Accessibility, Education, ...) | S | Already works: add "Education Facilitators" and they become a chapter with ZIP-less rows. Decision: should a committee facilitator approve claims for committee members? Today yes. If not, an exclusion list. |
| R3 | **ZIP coverage for city chapters** | S | New York City has default prefixes. Other city chapters need prefixes set in the `chapter_zip_prefixes` setting (JSON). A small settings screen would do. |
| R5 | ~~Facilitators work their chapter's rows~~ | done | Decided and built 2026-10-24: they add, assign, set progress and publish rows for their chapter. |
| R5b | **Contact details for the person working a row** | S | A facilitator working a need cannot see `requester_contact` (organizer-only), so they cannot reach the person. **Decision:** let the holder of a row see its organizer-only contact fields, or move the contact field to chapter level, or keep organizers as the only ones who contact people. Changes who can read personal details. |
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

R5b (a decision, quick), R3 and R1 (so a new chapter needs no developer), then T3 and T2 (connect the desk, the board and
hours), T7, then T4. T6 when you are ready to design spending. P1 and P2 before real people's data goes in.
