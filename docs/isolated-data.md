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

## Backing up

```bash
BACKUP_DIR=/e/nexttime-backups make docker-backup
# without make:
BACKUP_DIR=/e/nexttime-backups scripts/backup.sh
```

`BACKUP_DIR` must be a folder outside this repo; on Windows in Git Bash a drive is `/e/...`. The folder is never
created for you, so a drive that is not plugged in is an error, not a backup into the wrong place. Each run
writes four files that belong together:

- `nexttime-db-<time>.dump`: the database (`pg_dump -Fc`)
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
     pg_restore -U nextcloud -d nextcloud --clean --if-exists --no-owner < /e/nexttime-backups/nexttime-db-<time>.dump
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
