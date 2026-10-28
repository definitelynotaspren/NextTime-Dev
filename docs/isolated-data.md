# Keeping the data apart from the app (Docker)

The goal: wiping the app folder, `custom_apps`, the containers, or this whole repo must not touch the
community's data. This page says where the data lives, how to set it up, how to move an existing stack
over without losing anything, and how to back it up.

## Where to type the commands

In a terminal on your own computer, in the repo folder, **not** in Docker Desktop's *Exec* tab. Exec opens a shell
*inside* one container: it has no `docker` command, no repo and no backup drive.

- **Windows:** Git Bash (right-click the repo folder, *Open Git Bash here*) or a WSL terminal. For WSL, turn on
  *Docker Desktop, Settings, Resources, WSL integration* for your distribution, or `docker` will not be found
  there. Not PowerShell for the backup (see below).
- **`make` is not part of Git for Windows.** Check with `make --version`. If it is missing, use the plain
  commands shown beside each `make` target in this page, or install make in WSL (`sudo apt install make`).
- Check Docker answers from the terminal you chose: `docker version` shows both a Client and a Server. Images,
  containers and volumes are three different lists: `docker images`, `docker ps -a` and `docker volume ls`. A
  Nextcloud *image* never appears among the volumes.

## Where everything lives

| What | Where | Survives |
|------|-------|----------|
| Every request, claim, vote, ledger entry, the governance log, the app's settings, users, groups | Postgres, in the Docker volume `nexttime_pgdata` | wiping the repo, `docker compose down`, `down -v`, rebuilding images |
| `config.php` (the instance's secrets and the database password), uploaded files such as claim evidence | Docker volume `nexttime_files` (Nextcloud's folder) | the same |
| The code | this repo, mounted into the container at `custom_apps/timebank` | nothing needs to: it is rebuilt with `git pull` and `npm run build` |

NextTime writes no files of its own and its uninstall removes no tables, so deleting the app folder leaves the
database as it was. When the folder comes back at the same or a newer version, Nextcloud sees the migrations
are already applied and carries on. (Use `occ app:remove --keep-data timebank` rather than plain
`app:remove`, so a future version with uninstall steps cannot drop tables.)

Both volumes are declared `external: true`. Docker Compose never creates or deletes an external volume, so
`docker compose down -v` leaves them alone. They are created once, by `make docker-data-init`.

**The database has its own stack** (`docker/data/docker-compose.yml`, project `nexttime-data`). The app stack
(`docker-compose.yml`) joins it over a network called `nexttime_data`. No database port is published to your
computer. The two are started and stopped separately: `make docker-down` stops the app and leaves the
database running.

## Still able to lose it

- Deleting the volumes by hand (`docker volume rm`, `docker volume prune`, Docker Desktop's *Clean / Purge
  data*, its factory reset, or uninstalling it). External volumes protect against Compose, not against you.
- A failed disk. Docker Desktop on Windows keeps its volumes in one virtual disk file on that machine.
- Changing the Postgres image to a different major version: a volume made by 15 will not start under 16.

This is what the backups are for.

## First time on a new machine

```bash
cp .env.example .env     # then change the passwords
make dev                 # dependencies, frontend build, volumes, database, app
make docker-install-app
```

`make docker-up` on its own does the volumes, the database, then the app. Without `make`, the same steps are
(the first two build the frontend, which is not stored in git; without it NextTime opens as an empty page):

```bash
npm ci
npm run build
docker volume create nexttime_pgdata
docker volume create nexttime_files
docker compose --env-file .env -f docker/data/docker-compose.yml up -d --wait
docker compose up -d
```

## Moving a stack you already have, keeping its data

If you have been running the older single compose file, your data is in two volumes that Compose named after
the folder, usually `nexttime-dev_db_data` and `nexttime-dev_nextcloud_data`. They can be used in place: no
copying. Do these in order, and do the first two **before** you pull this change.

1. **Back up.** With the old stack still running, from the repo folder in Git Bash:
   ```bash
   docker compose exec -T db pg_dump -U nextcloud -d nextcloud -Fc > /e/before-split.dump
   ```
   Use your own `POSTGRES_USER` and `POSTGRES_DB` if they differ, and a folder on the external drive. Check the
   file is not empty.
2. **Find the volume names.** `docker volume ls` and look for names ending `_db_data` and `_nextcloud_data`.
3. **Stop the old stack, without `-v`:**
   ```bash
   docker compose down
   ```
   This removes containers and keeps volumes.
4. **Pull this change** (`git pull origin main`, then `npm ci && npm run build` if the frontend changed).
5. **Point `.env` at the existing volumes:**
   ```
   NEXTTIME_DB_VOLUME=nexttime-dev_db_data
   NEXTTIME_FILES_VOLUME=nexttime-dev_nextcloud_data
   ```
   Keep the same `POSTGRES_USER`, `POSTGRES_PASSWORD` and `POSTGRES_DB` as before: those variables only apply
   when a database is first created, and the volume already has its own.
6. **Start:** `make docker-up`. The old install remembers its database host as `db`; the database stack answers
   to that name as well, so it starts as it was.
7. **Make the new name permanent:**
   ```bash
   docker compose exec -u www-data nextcloud php occ config:system:set dbhost --value=nexttime-db
   ```
8. **Check it.** Open the app and look at your data. Or count rows:
   ```bash
   docker compose --env-file .env -f docker/data/docker-compose.yml exec db psql -U nextcloud -d nextcloud -c "select count(*) from oc_timebank_requests"
   ```
9. **Take a first backup** (below).

If anything is wrong, run `make docker-down` and `make docker-data-down`; your volumes are unchanged. You can
go back to the previous commit and its compose file and start again from the same volumes.

## Giving a Nextcloud instance its database inside nexttime-db

Nextcloud, not NextTime, owns the database connection: it reads it from the instance's `config.php` on every
request. NextTime has no database setting; whatever database the instance points at is where its tables are. So
"NextTime's data is in `nexttime-db`" means *the Nextcloud instance that runs NextTime uses a database on
`nexttime-db`*. Two rules:

- **One database per Nextcloud instance** (for example `nexttime35`), created inside the server. Never point a
  second instance at a database another one has used: Nextcloud only upgrades one major version at a time, so it
  refuses a database last used by an older release, and trying can damage the older instance.
- **A change is written to the public governance log.** When NextTime is first seen, when its version changes (an
  update that finished its migrations) and when the kind of database under it changes (SQLite to PostgreSQL), it adds
  a line as "system": the version and the kind of database, never the host, name or password.

### A new instance on PostgreSQL from the start (recommended)

No conversion, and no hand-made steps. It also replaces an instance that runs on SQLite: Nextcloud installs itself
only into an **empty** `/var/www/html`, and an old volume already holds a `config.php` that says "installed, SQLite",
so installing "over" it does nothing. Use a new volume, keep the old container until the new one works, then delete it.

From the repo folder, with the data stack running (`docker compose --env-file .env -f docker/data/docker-compose.yml ps`
says healthy):

1. **Park the old container** (nothing is deleted yet): `docker stop NextTime-Dev` if it runs, then
   `docker rename NextTime-Dev NextTime-Dev-sqlite-old`. Note its volumes for later:
   `docker inspect NextTime-Dev-sqlite-old --format "{{json .Mounts}}"`.
2. **Update the code** (this also moves the image to Nextcloud 35) and rebuild the frontend: `git pull`, `npm run build`.
3. **Edit `.env`** (set a strong `NEXTCLOUD_ADMIN_PASSWORD`; the image rejects weak ones such as `admin`):
   ```
   NEXTTIME_DB_NAME=nexttime35
   NEXTTIME_FILES_VOLUME=nexttime35_files
   ```
   Leave `POSTGRES_DB` and `NEXTTIME_DB_VOLUME` alone: the database server and its volume are not changing, only
   which database this instance uses. (`NEXTTIME_FILES_VOLUME` must be new: the default `nexttime_files` may hold an
   older Nextcloud, and Nextcloud only upgrades one major version at a time.)
4. **Create the database and the volume** (use your own `POSTGRES_USER` if `.env` sets another):
   ```
   docker compose --env-file .env -f docker/data/docker-compose.yml exec db psql -U nextcloud -c "CREATE DATABASE nexttime35 OWNER nextcloud"
   docker volume create nexttime35_files
   ```
5. **Start it:** `docker compose up -d --build`. The first time it downloads Nextcloud 35 and builds the image.
6. **Watch the install:** `docker compose logs -f nextcloud` (stop watching with `Ctrl+C`). You want
   `Installing with PostgreSQL database`, then `Nextcloud was successfully installed`. Then
   `http://localhost:8080/status.php` shows `"installed": true`.
7. **Check it is on PostgreSQL:** `docker compose exec -u www-data nextcloud php occ config:system:get dbtype` prints
   `pgsql`, and `... get dbname` prints `nexttime35`.
8. **Turn NextTime on.** The repo folder is mounted into the container, so nothing is copied:
   `docker compose exec -u www-data nextcloud php occ app:enable timebank`. The Governance log then shows a line from
   "system": "first recorded the running setup", with `database: postgres`.
9. **Delete the old instance** once you are happy: `docker rm -v NextTime-Dev-sqlite-old` (`-v` also removes its
   anonymous volumes). If step 1 showed a *named* volume for it, remove that with `docker volume rm <name>`.

What this leaves alone: `nexttime_pgdata` (the server's files, with the new database in it), and the older instance's
`nextcloud` database and `nexttime_files` volume. They hold a different instance's data (not merged into the new one;
the planned import is for that, [proposals/data-sources.md](proposals/data-sources.md)); delete them yourself, on
purpose, when you no longer want them.

Without the repo's compose file, a plain `docker run` does the same, with the app copied in as described in the install
steps:

```
docker run -d --name NextTime-Dev --restart unless-stopped --network nexttime_data -p 8080:80 -v nexttime35_files:/var/www/html --env-file .env -e POSTGRES_HOST=nexttime-db -e POSTGRES_DB=nexttime35 nextcloud:35-apache
```

(`-e` after `--env-file` wins. Pin the image to a major version, not `latest`.) The installer makes its own database
role for the instance (`oc_admin`, numbered if one exists) and accepts a database you created first. The PostgreSQL
install itself was run natively; this Docker route is not yet run on real Docker.

### An instance that already has data on SQLite

`occ db:convert-type` copies an instance into PostgreSQL, but on Nextcloud 35.0.2 it **stops at its last step**
(`SELECT MAX() FROM`, a similar report: nextcloud/server#26465) *after* all tables are copied, before it saves the
new database settings. That was reproduced on a native test instance (35.0.2, PostgreSQL 16): the row counts of
every NextTime table and of users matched afterwards, the API smoke test passed on the result, and the governance
log showed `sqlite → postgres`. It has not been run on real Docker or PostgreSQL 15. Take a copy first:

1. **Copy the SQLite file out** (the converter never changes it, so it is also your way back):
   `docker cp NextTime-Dev:/var/www/html/data/owncloud.db .\owncloud-before.db`
2. **Create the empty database and join the network:**
   ```
   docker compose --env-file .env -f docker/data/docker-compose.yml exec db psql -U nextcloud -c "CREATE DATABASE nexttime35 OWNER nextcloud"
   docker network connect nexttime_data NextTime-Dev
   ```
3. **Convert** (it asks for the database password, and puts Nextcloud in maintenance mode while it works):
   ```
   docker exec -it -u www-data NextTime-Dev php occ db:convert-type --all-apps --clear-schema pgsql nextcloud nexttime-db nexttime35
   ```
   If it finishes and prints no error, you are done: skip to step 5.
4. **If it stops with `SELECT MAX() FROM`,** the copy is complete but two steps are left. Do them with these files, in this order.
   The counters (`scripts\pg-resync-sequences.sql`) so new rows do not reuse old ids:
   ```
   docker compose --env-file .env -f docker/data/docker-compose.yml exec -T db psql -v ON_ERROR_STOP=1 -U nextcloud -d nexttime35 < scripts\pg-resync-sequences.sql
   ```
   Then the settings, all in one write (`occ config:system:set` cannot do this: with SQLite, `dbname` is the name of
   the database *file*, so changing it first leaves Nextcloud looking at an empty file):
   ```
   docker cp scripts\nextcloud-switch-to-pgsql.php NextTime-Dev:/tmp/
   set NEXTTIME_DB_PASSWORD=the-database-password
   docker exec -u www-data -e NEXTTIME_DB_PASSWORD NextTime-Dev php /tmp/nextcloud-switch-to-pgsql.php /var/www/html/config/config.php nexttime-db nexttime35 nextcloud
   ```
   It keeps `config.php.before-pgsql` next to `config.php` and refuses to run twice.
5. **Check it:** open the app. In the Governance log there should be a line "changed the kind of database NextTime runs on:
   database: sqlite → postgres". Count rows and compare with what the board showed:
   ```
   docker compose --env-file .env -f docker/data/docker-compose.yml exec db psql -U nextcloud -d nexttime35 -c "select count(*) from oc_timebank_requests"
   ```
6. **To undo,** copy `config.php.before-pgsql` back over `config.php` inside the container. Nothing was deleted from SQLite.

The `nextcloud` database already on the server (from an older stack) is a different instance's data and is not merged
into the new one. Bringing its requests across is what the planned import is for
([proposals/data-sources.md](proposals/data-sources.md)).

## Bringing an older instance's data across

The import (**Import** in NextTime's menu, admins only) reads an older NextTime's database and brings its requests,
offers and hour claims into the running instance. Read [the README section](../README.md#importing-from-another-nexttime)
for what it does and does not carry. **Keep the old database, volume and container until you have checked the result:**
the import only reads them.

Before you start, in the repo folder (these are the Docker commands; `nextcloud` is the compose service):

1. **Create the accounts the old data names.** The import matches people by identical user name and creates none. List them
   (for the old `nextcloud` database on `nexttime-db`, with your own database user):
   ```
   docker compose --env-file .env -f docker/data/docker-compose.yml exec db psql -U nextcloud -d nextcloud -c "select uid from oc_users"
   ```
   and add each one that does not exist here (one command each; choose the password):
   ```
   docker compose exec -u www-data -e OC_PASS=ChooseAPassword1! nextcloud php occ user:add --password-from-env cupcaketrainer
   ```
2. **Make a second admin.** An import is staged by one admin and finalized by another:
   ```
   docker compose exec -u www-data -e OC_PASS=ChooseAnotherPassword2! nextcloud php occ user:add --password-from-env --display-name "Second admin" admin2
   docker compose exec -u www-data nextcloud php occ group:adduser admin admin2
   ```

Then, as the first admin, open **Import**:

**From the old PostgreSQL database** (for example the `nextcloud` database an earlier stack left on the server): kind
*PostgreSQL*, host `nexttime-db`, port blank, database `nextcloud`, your `POSTGRES_USER` and `POSTGRES_PASSWORD`, prefix `oc_`.
The user and password are the **database's own**, not a Nextcloud login: for the Docker stack they are `POSTGRES_USER` and
`POSTGRES_PASSWORD` in your `.env` file, and the table prefix is `oc_` (the text before `timebank_requests` in the table
names), not `oc_timebank`. The app container is on the same network as the database, so the host name resolves. Press
**Preview**, read it, then **Stage**.

**No password to hand, or you would rather give the import read-only access?** Make a user inside the database's own
container; that needs no password (use your own `POSTGRES_USER` for `-U`):
```
docker compose --env-file .env -f docker/data/docker-compose.yml exec db psql -U nextcloud -c "CREATE ROLE importer LOGIN PASSWORD 'choose-a-password'"
docker compose --env-file .env -f docker/data/docker-compose.yml exec db psql -U nextcloud -c "GRANT CONNECT ON DATABASE nextcloud TO importer"
docker compose --env-file .env -f docker/data/docker-compose.yml exec db psql -U nextcloud -d nextcloud -c "GRANT SELECT ON ALL TABLES IN SCHEMA public TO importer"
```
Then in the form use user `importer` and that password. It can read the old tables and nothing else: any write is refused.
When you are done, remove it:
```
docker compose --env-file .env -f docker/data/docker-compose.yml exec db psql -U nextcloud -d nextcloud -c "DROP OWNED BY importer"
docker compose --env-file .env -f docker/data/docker-compose.yml exec db psql -U nextcloud -c "DROP ROLE importer"
```

**From an old SQLite instance** (the `NextTime-Dev-sqlite-old` container): copy its database file into this instance's
data folder, then give the form only the file name.
```
docker cp NextTime-Dev-sqlite-old:/var/www/html/data/owncloud.db .\old-sqlite.db
docker compose cp .\old-sqlite.db nextcloud:/var/www/html/data/old-sqlite.db
docker compose exec nextcloud chown www-data:www-data /var/www/html/data/old-sqlite.db
```
Kind *SQLite file*, file name `old-sqlite.db`, prefix `oc_`. Delete the copy when you are done
(`docker compose exec nextcloud rm /var/www/html/data/old-sqlite.db`).

Give each source its **own, steady name** ("Old NC31 database", "Old SQLite"): the name is how rows already imported are
recognised, so the same name each time means nothing is added twice.

Then sign in as `admin2`, open **Import**, and press **Finalize**. The claims arrive **pending**; the approvers decide on
them in **Approvals & Votes**, where the ones the old system had approved say so. Check the **Governance log**: it shows
who staged and who finalized, with totals.

Tested on a native instance (SQLite and PostgreSQL sources, two admins, the expiry job); the Docker commands above are
not yet run on real Docker.

## Backing up

```bash
BACKUP_DIR=/e/nexttime-backups make docker-backup
# without make:
BACKUP_DIR=/e/nexttime-backups scripts/backup.sh
```

`BACKUP_DIR` must be a folder outside this repo; on Windows in Git Bash a drive is `/e/...`. The folder is never
created for you, so a drive that is not plugged in is an error, not a backup into the wrong place. Each run
writes four files that belong together:

- `nexttime-db-<time>-<database>.dump`: one per database on the server (`pg_dump -Fc`), so a second
  instance's database is never left out (`BACKUP_DATABASES="a b"` limits it)
- `nexttime-files-<time>.tgz`: Nextcloud's folder, including `config.php` and claim evidence
- `nexttime-<time>.info`: the app version and commit, so you know which code matches
- `nexttime-<time>.sha256`: checksums, checked with `sha256sum -c`

Nextcloud is put in maintenance mode while it runs so the two match, and put back as it was, even on failure.
The newest 7 sets are kept (`KEEP=14` for more); older ones are deleted only after a new set is complete.
A failed run keeps nothing new and touches no older set.

Run it before every update. The backups hold everything in plain text, including contact details and exact
locations (see [data-and-access.md](data-and-access.md)): treat the drive like the server.

**On a schedule (Windows).** Task Scheduler can run it daily through Git Bash. Docker Desktop must be running
and the drive connected at that time:

```
schtasks /Create /SC DAILY /ST 03:00 /TN "NextTime backup" /TR "\"C:\Program Files\Git\bin\bash.exe\" -lc \"cd /c/path/to/NextTime-Dev && BACKUP_DIR=/e/nexttime-backups scripts/backup.sh\""
```

Use Git Bash or WSL, not PowerShell: PowerShell rewrites the binary dump when it redirects output.

## Restoring

Restoring replaces what is in the database, so **rehearse it on a throwaway copy first** and do not point
it at the stack you are using. For a rehearsal, use different volume names and port in a copy of `.env`
(`NEXTTIME_DB_VOLUME=rehearsal_pgdata`, `NEXTTIME_FILES_VOLUME=rehearsal_files`, `NEXTCLOUD_PORT=8090`).

1. `make docker-data-init` to create the (empty) volumes, then start only the database:
   `docker compose --env-file .env -f docker/data/docker-compose.yml up -d --wait`
2. Load the dump into it:
   ```bash
   docker compose --env-file .env -f docker/data/docker-compose.yml exec -T db \
     pg_restore -U nextcloud -d <database> --clean --if-exists --no-owner < /e/nexttime-backups/nexttime-db-<time>-<database>.dump
   ```
3. Unpack the files into the files volume:
   ```bash
   docker run --rm -i -v <files volume>:/data --entrypoint tar postgres:15-alpine xzf - -C /data < /e/nexttime-backups/nexttime-files-<time>.tgz
   ```
4. Start the app (`docker compose up -d`). Use code at the version in the `.info` file; if it is older than the code
   you run, finish with `docker compose exec -u www-data nextcloud php occ upgrade`.

## If something does not work

- **NextTime is enabled but its page is empty** (Nextcloud's top bar and background, nothing else). The
  frontend has not been built: `dir js` (or `ls js`) should list `timebank-main.mjs`. If it does not, run
  `npm ci` then `npm run build` in the repo folder, with Node 20 or newer, and reload with Ctrl+F5. The app
  folder is mounted into the container, so no restart is needed. If `npm ci` complains that `package.json` and
  the lock file disagree, `git stash push package.json package-lock.json` sets your local changes aside
  (`git stash pop` brings them back). Still empty with the file present: open the browser's developer tools
  (F12), reload, and read the Console and Network tabs for red entries.
- **`status.php` says `"installed": false` after the first start.** Nextcloud could not reach the database when
  it first started. Check that the database stack is running and healthy before starting the app
  (`docker compose --env-file .env -f docker/data/docker-compose.yml ps`), and that the app container is on the
  `nexttime_data` network (`docker network inspect nexttime_data`). The official image only installs itself the
  first time it fills its volume, so after fixing the database finish the install in the browser at
  `http://localhost:8080` (PostgreSQL, host `nexttime-db`), or start over with a new, empty files volume.
- **`docker compose exec` says a service is not running.** The service name is `nextcloud`, not the folder name,
  and the app stack must be up (`docker compose ps`).

## What has and has not been tested

- The compose files parse (`docker compose config`) and the backup script's logic is tested against a fake
  `docker` (`scripts/backup-test.sh`: what it writes, pruning, and that a failure keeps older sets and turns
  maintenance mode back off). The tests were checked to fail when the script is broken.
- **Not yet run against real Docker:** the volume copy, the real `pg_dump`, moving an existing stack over, the
  restore steps and the Windows scheduled task. The first backup and a restore rehearsal on a throwaway stack
  are what turn this from "written" into "proven"; do them before relying on it.

## Later

Copies of these backups on another server, a read-only Postgres standby reached over WireGuard, and importing
data from another database are planned: see [proposals/data-sources.md](proposals/data-sources.md). The
database stack publishes no port today; a standby would publish one on the tunnel's address only.
