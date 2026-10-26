# Data sources: importing from an older NextTime (proposal, nothing built)

Status: proposal. Phase 1 (the isolated database stack and backups, `docs/isolated-data.md`) is built; this is
Phase 2. Per the working style, nothing here is built until it is agreed.

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
3. **Import.** Matched rows are copied in. Rows for people with no matching account are set aside and counted;
   **no accounts are created**. Run it again after the accounts exist and only the missing rows are added.
4. **One public governance-log entry** for the action: who ran it, source label (never the password or host
   credentials), requests and offers imported, hour reports imported as pending claims, matched and
   unmatched counts. The per-person detail (which accounts did not match) goes in an admin-only report,
   because the governance log is public to every member.

"Report the number of hour reports per matching existing user" is satisfied by the preview and the admin-only
report; the public log carries the totals only.

## How a run stays safe and repeatable

- **A source key on every imported row** (`source_ref`, unique), built from the source label, the source table
  and its row id. Running an import twice never duplicates a row. Same idea as the Forms intake's `NO-F<form>-<id>`.
- **Matching people:** by identical Nextcloud user id first, then by email. A person matched by email is shown in
  the preview so an admin can see it before anything is written.
- **Categories:** matched by name; a category the source has and this install lacks is reported, not created.
- **Claims:** inserted as `pending`, with the description noting where they came from. Imported claims do not send
  notices (an import of 400 claims must not send 400 notifications) and are not subject to the posting rate
  limit, which is for members typing, not for a server-side import.
- **Approval is the existing process, unchanged.** Imported claims belong to the members they are about, so
  "nobody decides on their own claim" protects those members, not the admin who ran the import: that admin could
  approve them like any other pending claim today. Whether the person who ran an import should be barred from
  approving what it created is an open question (7) because it would be a new rule.
- **Credentials are used for the run and not stored.** Not in settings, not in logs, not in the report.
- **Admin only.** An import can add many rows at once, so it is an admin power and is logged publicly, as the
  design principle requires. Putting hours in only as pending claims means the import itself credits nothing;
  hours still move only through approval or a vote, and every approval is on the public ledger.
- **Connections out from the server.** Letting an admin name a host means the server connects to it. That is
  acceptable for an admin who already controls the server, and is why the connection is read-only, time-limited
  and logged by label.

## What it touches

| Area | Change |
|------|--------|
| `lib/Import/` (new) | a `Source` interface; `NextTimeDatabaseSource` (PDO); the mapping and matching; the runner |
| `lib/Controller/ImportController.php` (new) | `POST /api/import/preview`, `POST /api/import/run`, `GET /api/import/runs` (admin) |
| `lib/Migration/Version000010...` (new) | `source_ref` (nullable, unique) on `timebank_requests`, `timebank_volunteers`, `timebank_earnings`; table `timebank_imports` (admin-only run reports); version bump 0.11.0 |
| `src/` | a "Data sources" admin page, laid out like the Forms intake page |
| `lib/Service/GovernanceLog` | one `import.run` action per run |
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

1. **Claims already approved in the old system.** "Pending claims" for everything means approvers redo work that
   was decided before. Options: import all as pending (safe, slow); import them as pending but marked "approved
   in the old system" so an approver can clear them in one action; or treat the old approval as final. The last
   is the fastest and gives the importing admin power over balances, so I would not choose it.
2. **Hours credited.** A claim's earned hours are its hours times the category's earn rate. Use the current rate,
   or copy the old number across?
3. **Requests that are old and closed.** Import everything, or only open ones plus a count of the rest?
4. **Per-person counts.** Admin-only (as above), or visible to the people concerned about their own rows?
5. **A dump instead of a live database.** Is restoring it into a scratch container first an acceptable step, or
   should NextTime read a `.sql` / `.dump` file directly (much harder; I would avoid it)?
6. **Spreadsheets.** The mapping app's workbook is the likely second source; its column layout is needed to design
   that mapper.
7. **Should the person who ran an import be unable to approve the claims it created?** It would stop one admin
   importing and approving a batch alone, at the cost of a new rule in `EarningService::requireDecidable`.

## Order of work

1. Migration, `Source` interface, `NextTimeDatabaseSource` with preview only. Unit tests on a SQLite fixture.
2. The run, the source keys, the governance entry, the admin-only report. Smoke test.
3. The admin page.
4. Other sources.

Separately, and not part of this feature: copying the backups to another server, or a read-only Postgres standby,
reached over a WireGuard tunnel. WireGuard limits which machines can connect and encrypts the link; Postgres
still needs its own roles and `pg_hba` rules. Two writable NextTime instances sharing live data is a different
product and is not proposed here.
