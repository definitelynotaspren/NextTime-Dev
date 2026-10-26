# Data sources: importing from an older NextTime (proposal, nothing built)

Status: **built** (app 0.11.0): preview, staging, the second-admin finalize, cancel, expiry, the admin page and
`scripts/import-smoke.sh`, for an older NextTime in PostgreSQL, MySQL or a SQLite file. Phase 1 (the isolated database
stack and backups) is in `docs/isolated-data.md`. Where the build differs from the plan below: rejected claims are left
out (the plan did not say); imported claims are written as already notified so the reminder job stays quiet; the
`source_ref` is the source's name plus the old id, so one name must be used per source; and the open questions
below keep the defaults they were given. Not built: spreadsheets, other systems, matching people by email.

## What it is for

An admin can bring data from somewhere else into NextTime: first, **an older NextTime database**; later a
spreadsheet or another SQL database. It is an import, not a second place where NextTime keeps its data. Each
import is a separate, logged, repeatable action, available whenever an admin needs it.

## Decisions already made

| Question | Decision |
|----------|----------|
| What does "give NextTime access to a database" mean? | **Import** from a source. NextTime does not store its own tables in a second database. |
| How do imported hour reports land? | As **pending claims**. They go through the normal approval or 3-vote process; nobody decides on their own. |
| Where does the live database live? | A Docker named volume in its own stack (built); backups go to the external drive. |
| First source? | An older NextTime database. |
| Claims that were already approved in the old system | Imported as **pending**, marked **"approved before"**, so approvers can see the history. |
| Who finishes an import? | **A second admin.** The admin who runs an import only stages it; a different admin must finalize it before anything goes live. |
| How are imported hours stored? | **As they were**: the old hour numbers are copied, not recalculated with today's earn rates. Adjustments and exchange ratios (the 1:1.3 idea) come later. |

**Why not a second runtime database.** Nextcloud gives apps one database connection. A second one means private
`OC\DB\*` classes that differ between Nextcloud 29 and 35 and would fail the psalm gate, and hours and users
could no longer share a transaction. The separate Postgres stack gives the isolation without that.

## The flow (mirrors the Forms intake page)

1. **Add a source** (admin only). For a database: type (Postgres, MySQL/MariaDB or a SQLite file), host, port,
   database name, table prefix (Nextcloud's default is `oc_`), and a **read-only** user and password. NextTime
   opens a plain PDO connection (the extensions Nextcloud already needs), sends only `SELECT`, and sets a short
   timeout. A dump is restored into a scratch database first, then added the same way.
2. **Preview, writes nothing.** Shows which source tables and columns were recognised, how many requests,
   offers and hour reports it found, and how many belong to people who match an existing Nextcloud user.
3. **Stage.** The admin who ran the preview stages the import. Matched rows are saved, unchanged, in a holding
   table that only admins can read; nothing is live yet. Rows for people with no matching account are set aside
   and counted; **no accounts are created**.
4. **A second admin finalizes.** A different Nextcloud admin looks at the staged counts and the admin-only report
   and finalizes or cancels it. Only finalizing writes the rows into the real tables. The same person can never
   do both steps. The credentials are not needed for this step, which is why the staged rows are kept.
   A staged import that nobody finalizes expires after 14 days and its held rows are deleted.
5. **Public governance-log entries** for each step: `import.staged` (who, source label, counts),
   `import.finalized` (the second admin, the counts that went live) and `import.cancelled` or `import.expired`.
   The label never carries a password or host credentials. The per-person detail (which accounts did not
   match) stays in the admin-only report, because the governance log is public to every member.

"Report the number of hour reports per matching existing user" is satisfied by the preview and the admin-only
report; the public log carries the totals only.

## How a run stays safe and repeatable

- **A source key on every imported row** (`source_ref`, unique), built from the source label, the source table
  and its row id. Running an import twice never duplicates a row. Same idea as the Forms intake's `NO-F<form>-<id>`.
- **Matching people:** by identical Nextcloud user id first, then by email. A person matched by email is shown in
  the preview so an admin can see it before anything is written.
- **Categories:** matched by name; a category the source has and this install lacks is reported, not created.
- **Claims:** inserted as `pending`. One that was approved in the old system carries an **"approved before"**
  marker (and the old approval date), shown beside it in **Approvals & Votes**; the rest are plain pending.
  **Hours are copied as they were** (`hours_claimed` and `actual_hours_earned` unchanged); the category's earn
  rate is not applied again. Imported claims do not send notices (an import of 400 claims must not send 400
  notifications) and are not subject to the posting rate limit, which is for members typing, not for a
  server-side import.
- **Two people for every import.** The second-admin step is what stops one admin from loading a batch alone.
  Approving the resulting claims is the existing process, unchanged: imported claims belong to the members they
  are about, so "nobody decides on their own claim" protects those members, not the admin who ran the import.
  Whether the person who ran an import should also be barred from approving its claims is left open (7).
- **Credentials are used for the run and not stored.** Not in settings, not in logs, not in the report.
- **Admin only, and two admins.** An import can add many rows at once, so it is an admin power, logged publicly at
  each step, as the design principle requires. A community with a single admin cannot finalize an import
  until a second admin exists. Putting hours in only as pending claims means the import itself credits nothing;
  hours still move only through approval or a vote, and every approval is on the public ledger.
- **Connections out from the server.** Letting an admin name a host means the server connects to it. That is
  acceptable for an admin who already controls the server, and is why the connection is read-only, time-limited
  and logged by label.

## What it touches

| Area | Change |
|------|--------|
| `lib/Import/` (new) | a `Source` interface; `NextTimeDatabaseSource` (PDO); the mapping and matching; the runner |
| `lib/Controller/ImportController.php` (new) | `POST /api/import/preview`, `POST /api/import/stage`, `POST /api/import/{id}/finalize`, `POST /api/import/{id}/cancel`, `GET /api/import/runs` (admin; finalize refuses the person who staged it) |
| `lib/Migration/Version000010...` (new) | `source_ref` (nullable, unique) on `timebank_requests`, `timebank_volunteers`, `timebank_earnings`; `approved_before_at` (nullable) on `timebank_earnings`; tables `timebank_imports` (run records and admin-only reports) and `timebank_import_staged` (the held rows, deleted on finalize, cancel or expiry); version bump 0.11.0 |
| `src/` | a "Data sources" admin page, laid out like the Forms intake page |
| `lib/Service/GovernanceLog` | `import.staged`, `import.finalized`, `import.cancelled`, `import.expired` |
| Tests | PHPUnit on mapping and matching using a SQLite fixture; `scripts/import-smoke.sh` end to end from a SQLite file |
| Docs | README Known Limitations, CHANGELOG, `docs/data-and-access.md` (the new table and who can read it) |

The existing admin settings page is display-only today, so the new page is a Vue page like Intake, not a form on
that one.

## Reading an older NextTime

Older installs have fewer columns (a request before 0.9 has no ZIP, chapter or review status; before 0.10 there
are no adjustment votes). The reader asks the source which columns exist and imports what is there, leaving
the rest empty, the way `BackfillRequestCoordinates` already treats old rows. Requests with no ZIP belong to no
state until their poster adds one, the same as for requests posted before 0.9.

## Open questions

Answered: claims already approved (imported as pending, marked "approved before"), who finishes an import (a
second admin), and the hours (copied as they were). Still open, with what I would do unless told otherwise:

1. **"Imported directly" for hours** is read here as "copy the old numbers, do not recalculate", with the
   claims still pending. If it meant crediting balances at once, that is a different design (it would skip the
   pending step entirely) and needs a separate decision.
2. **Requests that are old and closed.** Default: import everything, including closed ones, so history is kept.
3. **Per-person counts.** Default: admin-only.
4. **A dump instead of a live database.** Default: restore it into a scratch container first. Reading a `.sql` or
   `.dump` file directly is much harder.
5. **Spreadsheets.** The mapping app's workbook is the likely second source; its column layout is needed to design
   that mapper.
6. **Only one admin.** With the second-admin rule, finalizing needs two. Should a national organizer ever be
   allowed to be the second person, or admins only (the current wording)?
7. **Should the person who staged an import also be barred from approving the claims it created?** The second-admin
   step covers the import itself; this would be an additional rule in `EarningService::requireDecidable`.

## Order of work

1. Migration, `Source` interface, `NextTimeDatabaseSource` with preview only. Unit tests on a SQLite fixture.
2. The run, the source keys, the governance entry, the admin-only report. Smoke test.
3. The admin page.
4. Other sources.

Separately, and not part of this feature: copying the backups to another server, or a read-only Postgres standby,
reached over a WireGuard tunnel. WireGuard limits which machines can connect and encrypts the link; Postgres
still needs its own roles and `pg_hba` rules. Two writable NextTime instances sharing live data is a different
product and is not proposed here.
