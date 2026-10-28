# MODEL: what PR #63 builds, and where a register would sit under it (2026-10-26)

*For Brigidt. A reading of PR #63 (`claude/serene-bell-c8vjn6`, head `e976c6f`, 18 commits on top of `main`
`46eb8c3`: 85 files, +6010 −161). Paths are relative to the repository root and carry the line numbers at
that head. OBSERVED means read in the code; JUDGED means the reader's view, each with the one check that
would prove it wrong. Nothing on this branch changes code; this file is the only thing it carries (see §6).*

## 1 · The map: what #63 builds (OBSERVED)

Three features landed first (import, events, the database stack), then seven more commits joined the
branch on the same day (desk → board, offer notifications, agreed hours, username privacy, hidden finished
items). All of it is here; §1.5 lists the later seven so a reviewer is not surprised.

### 1.1 Components

| Part | Where | What it does |
|---|---|---|
| Import API | `lib/Controller/ImportController.php` | Six routes, all `#[NoAdminRequired]` at the framework level and admin-checked in the service: `POST /api/import/preview` (:38), `POST /api/import/stage` (:49), `GET /api/import/runs` (:63), `GET /api/import/runs/{id}` (:81), `POST /api/import/runs/{id}/finalize` (:92), `POST /api/import/runs/{id}/cancel` (:103). The password is read untrimmed and never logged (:113-128). |
| Import service | `lib/Import/ImportService.php` | `preview` writes nothing (:70-75). `stage` refuses a second staged import with the same label (:86-90), then writes one `timebank_imports` row and one `timebank_import_staged` row per held row in a single transaction (:107-122), then logs `import.staged` with totals only (:124). `finalize` refuses the admin who staged (:139-141), expires at 14 days (:43, :142-145), writes requests, offers and claims in one transaction and deletes the held rows (:151-222), logs `import.finalized` (:224-228). `cancel` (:238-243) and `expireStale` (:250-257) close the import and delete its held rows (:300-316). |
| Planner | `lib/Import/ImportPlanner.php` | Decides before anything is written. Source references are `<label-slug>:r:<id>`, `:v:`, `:e:` (:70, :117, :148); the slug is the label reduced to `[a-z0-9-]` (:220-224). Rows are left out for: person not found here, request has no title, category not found here (:80-85); offer's request not imported, unknown offer status (:126-129); claim rejected in the old system (:158-163). |
| Source | `lib/Import/DatabaseSource.php` | PDO to PostgreSQL, MySQL or a SQLite file (:22). Read-only is asked of the database itself: `PRAGMA query_only = ON` (:52), `SET default_transaction_read_only = on` (:72), `SET SESSION TRANSACTION READ ONLY` (:75). A SQLite file must resolve inside Nextcloud's data folder (:144-155). Prefix is `[A-Za-z0-9_]{0,20}` and may not end in `timebank` (:40-45). Reads `timebank_requests`, `timebank_volunteers`, `timebank_earnings`, `timebank_categories` with `SELECT * ... ORDER BY id` (:86-103, :124). The password is scrubbed from error text (:173-179). |
| Connector | `lib/Import/SourceConnector.php:26-28` | Hands `DatabaseSource::connect` Nextcloud's `datadirectory`; exists so tests can pass a fake source. |
| Expiry job | `lib/BackgroundJob/ExpireImports.php:24` | Hourly; registered at `appinfo/info.xml:41`. |
| Ref lookup | `lib/Db/SourceRefs.php:20-24` | `source_ref LIKE '<prefix>:%'` on each of the three tables. |
| Import page | `src/views/ImportView.vue`, route in `src/router/index.ts`, menu in `src/App.vue` | Preview, stage, finalize-or-cancel; Finalize is unavailable to the admin who staged. |
| System events | `lib/Service/SystemEventRecorder.php` | At app boot (`lib/AppInfo/Application.php:26-34`, failures only warn). Reads `installed_version` (:43) and the database provider (:47); compares with two app-config keys `logged_version` / `logged_database` (:29-30); writes `system.recorded`, `system.app_updated`, `system.database_changed` as actor `system` (:28, :56-67), then updates the two keys (:77-78). Version and database kind only, never a host or name (:19-20). Two concurrent first requests can each write the line (:23-24, by design). |
| Governance log | `lib/Service/GovernanceLog.php:25-33` | The sink for every event above and for `import.*` and `desk_row.put_on_board`; `timebank_governance_log`; readable by every member (`GET /api/governance`). Wording for the new lines in `src/views/GovernanceLog.vue`. |
| Database stack | `docker/data/docker-compose.yml` | Project `nexttime-data` (:13). One service `db`, `postgres:15-alpine` (:17, pinned: a 15 volume will not start under 16). Volume `pgdata` is external, named `${NEXTTIME_DB_VOLUME:-nexttime_pgdata}` (:40-42). Network `nexttime_data` (:44-46); aliases `nexttime-db` and `db` (:30-32, the old name an earlier `config.php` holds). **No port is published** (:25-27). Healthcheck `pg_isready` (:33-37). |
| App stack | `docker-compose.yml` | `redis:7-alpine` (:9); `nextcloud` built from `Dockerfile` (`FROM nextcloud:35-apache`, Dockerfile:10), published at `${NEXTCLOUD_PORT:-8080}:80` (:19), this folder mounted read-only at `custom_apps/timebank` (:22). `POSTGRES_HOST=${NEXTTIME_DB_HOST:-nexttime-db}` (:24), `POSTGRES_DB=${NEXTTIME_DB_NAME:-${POSTGRES_DB:-nextcloud}}` (:25). Volume `nextcloud_data` external, `${NEXTTIME_FILES_VOLUME:-nexttime_files}` (:51-53). Joins `nexttime_data` as an external network (:58-61). No `depends_on` the database: the order comes from `make`. |
| Make | `Makefile` | `docker-data-init` creates the two volumes if absent, never deletes (:136-146); `docker-data-up` = `docker compose --env-file .env -f docker/data/docker-compose.yml up -d --wait` (:131, :148-149); `docker-up` runs the data stack first (:158-162); `docker-down` stops the app only, "never add -v" (:165-167); `docker-backup` → `scripts/backup.sh` (:170-171). |
| Backup | `scripts/backup.sh` | `BACKUP_DIR` required, must exist, be writable and lie outside the repo (:50-54); dumps every database or `BACKUP_DATABASES` (:68-69); writes `nexttime-db-<time>-<name>.dump`, a files archive, an info file and `nexttime-<time>.sha256` (:4-8, :83); maintenance mode on and restored (:90-108). Logic tested against a fake `docker` in `scripts/backup-test.sh` (its header says what it does and does not prove). |
| Env | `.env.example:13-18` | New knobs: `NEXTTIME_DB_VOLUME`, `NEXTTIME_FILES_VOLUME`, `NEXTTIME_DB_HOST`, `NEXTTIME_DB_NAME`. |

### 1.2 Data flow of an import

```
 old NextTime DB ──SELECT only──▶ DatabaseSource ──rows──▶ ImportPlanner ──ImportPlan──┐
 (pg / mysql / sqlite in data/)   (read-only session)      (match people by uid,       │
                                                            categories by name,         │
                                                            skip refs already here)     │
                                                                                        ▼
  admin A: preview ──▶ counts + report, nothing written
  admin A: stage   ──▶ timebank_imports (1 row: status=staged, counts, report)
                       timebank_import_staged (N rows: kind, source_ref, data as JSON)
                       governance log: import.staged  {requests, offers, claims, ... , source: kind}
  admin B: finalize ─▶ timebank_requests / timebank_volunteers / timebank_earnings  (source_ref set,
                       claims pending + approved_before_at, notified_stage=2)
                       staged rows deleted; import row status=finalized, decided_by=B
                       governance log: import.finalized {written..., skipped, stagedBy: A}
  either:  cancel   ─▶ staged rows deleted; status=cancelled; log import.cancelled
  hourly:  expire   ─▶ same, status=expired, actor system, after 14 days
```

Where it writes: only the four `timebank_*` tables above plus the log; never a file. What validates:
the planner (pre-write) and `stillValid` again at finalize time, because a person may have left or a
category been archived in between (`lib/Import/ImportService.php:344-353`).

### 1.3 What the schema gains (OBSERVED, three migrations)

| Migration | Adds | Lines |
|---|---|---|
| `lib/Migration/Version000010Date20261030000001.php` | `source_ref` (string 190, nullable, **unique**) on `timebank_requests`, `timebank_volunteers`, `timebank_earnings` | :29-35 |
| same | `approved_before_at` (datetime, nullable) on `timebank_earnings` | :37-40 |
| same | table `timebank_imports`: `id, status, source_kind, source_label, staged_by, staged_at, expires_at, decided_by, decided_at, counts (JSON text), report (JSON text, admins only)`; index on `status` | :42-62 |
| same | table `timebank_import_staged`: `id, import_id, kind, source_ref, data (JSON text)`; index on `import_id` | :64-74 |
| `lib/Migration/Version000011Date20261031000001.php` | `show_username` (boolean, default false) on `timebank_requests` | :23-25 |
| `lib/Migration/Version000012Date20261031000002.php` | `agreed_hours` (decimal 5,2, nullable) on `timebank_volunteers` | :23-25 |

App version `0.12.0` (`appinfo/info.xml:20`).

### 1.4 Events: what emits, what consumes, where stored

| Action string | Emitted by | Stored | Read by |
|---|---|---|---|
| `system.recorded`, `system.app_updated`, `system.database_changed` | `SystemEventRecorder::check` at boot | `timebank_governance_log`, actor `system` | every member, `GET /api/governance`; wording in `src/views/GovernanceLog.vue` |
| `import.staged`, `import.finalized`, `import.cancelled`, `import.expired` | `ImportService` (:124, :224, :242, :143/:253) | same | same |
| `desk_row.put_on_board` | `lib/Service/DeskBridge.php:89` | same | same |
| dedupe state for system events | `SystemEventRecorder` | Nextcloud app config, keys `logged_version`, `logged_database` | the recorder only |

### 1.5 The seven later commits (OBSERVED, so the review list is complete)

`5497cea` hide finished items in My activity; Adjustments lists open items. `d198ee0` `show_username`
flag, `RequestPresenter` decides who sees `requesterId` (migration 11). `e64561e` requester settles
hours on accept, `agreed_hours` (migration 12). `3ecf1f5` `lib/Notification/OfferNotifier.php`:
notify requester of offers, volunteer of acceptance. `7a598d1` `lib/Service/DeskBridge.php`: a
facilitator puts a desk need on the board; the request keeps `desk:<rowId>` as its `source_ref`
(:18, :22), so the same column now has two writers with two grammars. `33190da`, `e976c6f` layout and
a mapper fix.

### 1.6 What runs where

- Dev with Docker: database in `nexttime-data` (no host port), Nextcloud at `localhost:8080`, Redis beside
  it, code mounted from the checkout. Backups to a folder outside the repo.
- Dev native: `scripts/dev-native.sh` at `127.0.0.1:8081` (`PORT`, :28); `scripts/import-smoke.sh` is
  written for this one, because it needs `scripts/dev-native.sh occ` to make `admin2` and find the data
  folder (:56-59).
- Background: `ExpireImports` hourly, beside `ClaimReminders` and `FormsIntakeJob` (`appinfo/info.xml:39-41`).

## 2 · The seams: where a k/v REGISTER would hold the same thing (JUDGED)

The register plan reads each SQL table as a stem `r/<key>/<col>` with `c/<col>/<value>` members, seated
under one root per group, so that swapping the root moves the whole state and a frozen copy can be
diffed row by row. These rows show the seat for what #63 adds, **without changing a line now**. The
falsifier for the whole table is one query #63 makes that needs a join the stem cannot answer with
`ls`, `sort` and `grep`; none was found in `lib/Import/*` or `SystemEventRecorder.php`, which read by
primary key, by `source_ref` prefix, or by status.

| What #63 writes today | Where (path:line) | What the register would hold instead | Note |
|---|---|---|---|
| `timebank_imports` row | `ImportService.php:97-109` | `r/import/<id>/{status,source_kind,source_label,staged_by,staged_at,expires_at,decided_by,decided_at,counts}` and `r/import/<id>/report` | `report` is admins-only today (`ImportService.php:274-279`): in the register it is the one *sealed* value on the key; names stay readable. |
| `timebank_import_staged` rows | `ImportService.php:110-117` | `r/import/<id>/staged/<kind>/<source_ref>` → the JSON `data` | Finalize/cancel/expire delete by `import_id` (`:217`, `:307`): one directory removed. |
| `source_ref` unique column, three tables | migration 10 `:29-35`; lookup `lib/Db/SourceRefs.php:20-24` | The ref **is the key**: `r/request/<slug>:r:<id>/…` | Uniqueness is the filesystem's. `LIKE '<prefix>:%'` is `ls r/request/<prefix>:*`. Two grammars share the column (`<slug>:r:<id>` from import, `desk:<rowId>` from `DeskBridge.php:22`); in a register they are two stems, `r/request/` keyed by origin. |
| `approved_before_at` on claims | migration 10 `:37-40`; set at `ImportService.php:424` | column `approved_before` on the claim key | Already the shape M1 named: a row admits. |
| governance log lines | `GovernanceLog.php:25-33` | `r/log/<seq>/{actor,action,subject,details,time}`, append-only | `GET /api/governance` is `ls r/log \| sort -n \| tail`. |
| system-event dedupe keys | `SystemEventRecorder.php:29-30, :77-78` (app config) | `r/system/{version,database}` | The event *is* the DRIFT between the frozen and the live stem: `frozen-sample.sh --check` names the changed atom; the recorder's two writes become one `cp`. |
| `show_username`, `agreed_hours` | migrations 11, 12 | columns on the request / offer key | `RequestPresenter` deciding who sees `requesterId` is "visibility is a column read by FRAME", the plan's own sentence. |
| connection details | `ImportController.php:117-128`, `DatabaseSource.php:15` — never stored | nothing | In a register, "import from another NextTime" is a stem copy with the ref prefix applied; the two-admin rule stays as it is here, a policy row (`group.create`-shaped), not a table. |
| the Postgres volume + files volume | `docker/data/docker-compose.yml:40-42`, `docker-compose.yml:51-53` | the seat root itself | `make docker-backup` becomes `tar` of a directory plus the `.sha256` it already writes. |

Loser kept: SQLite-per-group as the "small SQL" (portable, but opaque to `ls` and to a frozen diff, and the
encryption story collapses back to "encrypt the file"). The plan's §3.2 says why.

## 3 · What a reviewer runs to accept #63 (commands)

```sh
# the branch
git fetch origin pull/63/head:pr-63 && git checkout pr-63      # head e976c6f on 2026-10-26

# PHP: 29 test files / 242 test methods at this head (main had 23 / 183)
composer install
make test                                                      # composer test:unit

# frontend builds on this head
npm ci && npm run build

# the backup script's logic, no Docker needed (exit code = failed checks)
scripts/backup-test.sh

# the two compose files parse with the .env they will be given
cp -n .env.example .env
docker compose config -q
docker compose --env-file .env -f docker/data/docker-compose.yml config -q

# the stack, data first; then the app; then a user set and the smoke tests
make docker-up                                                 # = docker-data-init, docker-data-up --wait, compose up
make docker-install-app
make docker-seed-users
make smoke BASE_URL=http://localhost:8080                      # scripts/smoke-test.sh (gains agreed-hours and username checks)
scripts/desk-smoke.sh http://localhost:8080
node scripts/ui-smoke.cjs http://localhost:8080                # map pins inside the visible area; needs the dev users

# the import end-to-end: written for the native dev server (it calls scripts/dev-native.sh occ)
scripts/dev-native.sh serve &  scripts/import-smoke.sh         # 127.0.0.1:8081; exit code = failed checks

# the first real backup and a restore rehearsal (docs/isolated-data.md:363-370 says these have not been run)
BACKUP_DIR=/path/outside/the/repo make docker-backup
sha256sum -c /path/outside/the/repo/nexttime-*.sha256

# down, app first; the database and both volumes stay
make docker-down
make docker-data-down
```

What this reader did **not** run: PHPUnit, `npm ci`/`npm run build` on this head, any Docker, any smoke
script. "Merges clean" (the closure sheet) is a git fact, not a test result.

## 4 · JUDGED: three things worth a look, each with its falsifier

1. **Imported requests skip review.** `newRequest` sets `reviewStatus` to `published` and `mode` to
   `in_person` whatever the source said (`lib/Import/ImportService.php:383-384`). On an instance that uses
   the posting review queue, finalizing puts old requests on the board unreviewed. Falsifier: stage and
   finalize the smoke fixture on an instance with review on; if the imported requests sit in the queue,
   this is wrong.
2. **One column, two grammars.** `source_ref` is written by the import as `<slug>:r:<id>` and by the desk as
   `desk:<rowId>`; the import's "already here" lookup is `LIKE '<slug>:%'`. A label whose slug is `desk`
   scans the desk's refs too. Harmless today because the match that follows is exact on the whole ref
   (`ImportService.php:172, :185`); a cost if it ever becomes a prefix match. Falsifier:
   `SELECT source_ref FROM oc_timebank_requests WHERE source_ref LIKE 'desk:%'` returns a ref that also
   parses as `<slug>:r:<id>`.
3. **Order of the two stacks is `make`'s, not Compose's.** `docker-compose.yml` has no `depends_on` the
   database (it is in another project), so `docker compose up -d` typed alone with the data stack down
   starts Nextcloud against nothing. The guide says data first; `make docker-up` does it. Falsifier:
   `make docker-data-down && docker compose up -d` leaves a healthy Nextcloud.

## 5 · Open questions for Brigidt (one line each; what settles it)

- Q1 Are imported requests meant to bypass the posting review queue (§4.1)? *Settled by:* yes, or the
  `reviewStatus` the import should write.
- Q2 Do the seven later commits (§1.5) stay in #63, or become their own PR? *Settled by:* your word; the
  review list in §3 is the same either way, the merge is not.
- Q3 Eighteen commits are authored `Claude <noreply@anthropic.com>`; keep, or `--reset-author` before merge
  (the closure sheet's note)? *Settled by:* your call.
- Q4 Which Postgres major runs where the real data will live? `docker/data` pins 15, and a volume from another
  major will not start under it. *Settled by:* `psql -c 'select version()'` there, or the volume's origin.
- Q5 Has `scripts/import-smoke.sh` been run since `4fa3623` fixed the date fixture, and `backup.sh` against
  real Docker? *Settled by:* one pasted run of each with its exit code, and one `.sha256` line.
- Q6 From the register plan: may a slice read your migrations and name your tables in a public advice file;
  and is a register option welcome as a branch of NextTime-Dev or as a sibling app that reads your SQL first?
  *Settled by:* yes/no, and which.

## 6 · The catch-up rule

This branch is `model/c4tm-import-events`. It sits on your #63 head and carries **one file, this one**.
Whenever you push to `claude/serene-bell-c8vjn6`, it is rebased onto the new head:

```sh
git rebase --onto <your new head> <the old head> model/c4tm-import-events
```

Because it adds a file your branch does not have and changes none of yours, the rebase cannot conflict.
If #63 merges, the branch is rebased onto `main` and opened against it; if you fold this file into #63
yourself, the branch is deleted. Line numbers in this file are read at `e976c6f`; a rebase that moves them
gets a re-read, dated.

## 7 · Not checked is not clean

PHPUnit, the frontend build on this head, Docker and every smoke script: not run here. The PR's seven later
commits: counted and read by message and stat, their code not reviewed line by line beyond `DeskBridge.php`
and the two migrations. The Nextcloud framework's tolerance for a non-SQL store (the register option) is the
plan's open question, not answered here.

Proposals by Mentat (for Corwin, claude-mythos-5-1); nothing merged, nothing pushed to your repository.

Copyright (C) 2026 Corwin Brust <corwin@bru.st>, Bryant Frankford and Contributors. SPDX-License-Identifier: CC-BY-SA-4.0
