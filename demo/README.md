# NextTime Standalone Demo

A single-file, self-contained demo of the NextTime (Time Bank) workflow that
runs **entirely outside Nextcloud** — no server, no PHP, no database, no
`occ app:enable`. It's a Vue app with realistic mock data held in memory.

## Running it

Any of these work:

- **Double-click `index.html`** and open it in a browser.
- Or serve it with any static file server, e.g. from this folder:
  ```bash
  python3 -m http.server 8000
  # then open http://localhost:8000
  ```

The only network access it uses is loading Vue, Vue Router, and MapLibre GL
JS from the unpkg.com CDN (and map tiles from a public demo tile server if
you switch a request board to Map view) — an internet connection is required
for that, but **no Nextcloud instance or backend of any kind is needed.**

Reloading the page resets all data back to the defaults below — there's no
persistence layer to worry about breaking.

## What this is for

This mirrors the real app's data model and business rules (categories with
earn-rate multipliers, requests → volunteer offers → accept → complete,
earning claims → admin approval or community voting → balance credit, a
public ledger) closely enough to explore the intended end-to-end workflow
without installing anything.

It intentionally goes a bit further than the real app's current UI does. A
recent audit (see the main [README's Known Limitations](../README.md#known-limitations))
found several backend endpoints in `lib/Controller/` with no frontend
coverage at all — accepting/declining a volunteer, marking a request
complete, editing/cancelling a request, withdrawing an offer, deleting a
comment, and admin category/balance management. This demo adds UI for all
of those so you can actually exercise the full workflow, including:

- A **Member / Admin** toggle in the sidebar — admin-only pages (Approvals,
  Manage Categories, Balance Adjustments, Admin Settings) only appear when
  toggled to Admin, unlike the real app's nav today.
- A working **Admin Settings** page — in the real app this page is
  currently display-only (see Known Limitations); here, toggling
  "require admin approval" off actually makes claims auto-credit instantly,
  and "required votes" actually changes how many votes resolve a claim in
  the Approval Dashboard.
- **Manage Categories** and **Balance Adjustments** admin pages, which don't
  exist anywhere in the real app's UI yet.
- Clicking any username (in the ledger, on a volunteer card) opens that
  user's transaction history, mirroring `GET /api/ledger/user/{userId}`.

One real backend behavior is deliberately preserved even though it's
surprising: **marking a request complete does not transfer any hours.**
`TransactionService::recordSpending()` exists in the backend but is never
called from anywhere — completing a request only updates status and
volunteer stats. The only way hours actually move is a separate Submit
Earning Claim. The demo's "Mark Complete" panel calls this out explicitly
rather than inventing a fix, since it's a real, current quirk worth knowing
about before you rely on it.

## Test users

Only `demo.user` (you) has a live, editable balance. Other usernames (alice,
bob, henry, irene, jane, karl, etc.) appear as requesters/volunteers/commenters
for realism, and clicking them still shows a transaction history, but you
can't act as them.
