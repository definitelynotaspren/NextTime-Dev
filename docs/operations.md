# Running NextTime: install, existing data, updates, and the database service

Four jobs, each for **Nextcloud in Docker** and for **Nextcloud on a server you manage** (called "non-Docker" below:
Nextcloud installed from a package or tarball, behind Apache or nginx, with PostgreSQL on the same machine or another).
Background and the reasoning are in [isolated-data.md](isolated-data.md); this page is the checklist.

1. [A fresh install, no earlier database](#1-a-fresh-install-with-no-earlier-database)
2. [A fresh install that uses data you already have](#2-a-fresh-install-that-uses-data-you-already-have)
3. [Updating NextTime, and repairing a broken data source](#3-updating-nexttime-and-repairing-a-broken-data-source)
4. [Checking the database is running, and taking it offline safely](#4-checking-the-database-is-running-and-taking-it-offline-safely)

## Read this first

- **Nextcloud, not NextTime, owns the database connection.** It reads the connection (type, host, name, user,
  password) from the instance's `config.php` on every request. NextTime has no database setting of its own: the
  tables are wherever that instance points. "Connecting NextTime to a database" always means "pointing the Nextcloud
  instance at it".
- **Everything lives in one database**: NextTime's tables (`oc_timebank_*`) sit beside Nextcloud's own (users, groups,
  settings). A database with NextTime data in it also holds the people who can log in.
- **Back up before every change on this page.** Docker: `BACKUP_DIR=/e/nexttime-backups make docker-backup`
  (details in [isolated-data.md](isolated-data.md#backing-up)). Non-Docker: the commands in
  [the backup box](#non-docker-backup-by-hand).
- **One database per Nextcloud instance, one major Nextcloud version at a time.** Never point a second instance at a
  database another has used, and never point an older Nextcloud at a database a newer one has upgraded.

### Commands used below

Type them in a terminal on the machine that runs Docker (Windows: Git Bash or WSL, not Docker Desktop's *Exec* tab),
in the repo folder. To keep the commands short, paste these two lines once per terminal:

```bash
# Docker
data() { docker compose --env-file .env -f docker/data/docker-compose.yml "$@"; }   # the database stack
occ()  { docker compose exec -u www-data nextcloud php occ "$@"; }                  # Nextcloud's command line
```

On a non-Docker server, define `occ` as below instead (adjust the path to your Nextcloud folder; the web server's user
is `www-data` on Debian and Ubuntu, `apache` or `nginx` elsewhere), and run commands as a user who may use `sudo`:

```bash
# Non-Docker
occ() { sudo -u www-data php /var/www/nextcloud/occ "$@"; }
```

### Non-Docker: backup by hand

```bash
occ maintenance:mode --on
sudo -u postgres pg_dump -Fc nexttime > /mnt/backup/nexttime-$(date +%F-%H%M).dump        # your database name
sudo tar czf /mnt/backup/nexttime-files-$(date +%F-%H%M).tgz -C /var/www nextcloud/config nextcloud/data nextcloud/custom_apps
occ maintenance:mode --off
ls -l /mnt/backup/        # both files exist and are not empty
```

`config/config.php` is the file you cannot recreate (it holds the instance's secrets); the dump holds the data. The
backup folder must be on another disk. These files contain everything in plain text: protect them like the server.

---

## 1. A fresh install with no earlier database

### Docker

Needs: Docker, Git, Node 20 or newer (the frontend is built on your computer, not in the container).

```bash
git clone <this repository> NextTime-Dev && cd NextTime-Dev
cp .env.example .env
```

Edit `.env`: change `POSTGRES_PASSWORD`, `REDIS_PASSWORD` and `NEXTCLOUD_ADMIN_PASSWORD` (the image refuses weak admin
passwords such as `admin`). Then:

```bash
npm ci && npm run build          # the frontend; without it NextTime opens as an empty page
make dev                         # creates the volumes, starts the database, then Nextcloud
# without make:
#   docker volume create nexttime_pgdata && docker volume create nexttime_files
#   data up -d --wait && docker compose up -d
```

Wait for the install (the first start downloads Nextcloud and installs it; it takes a few minutes):

```bash
docker compose logs -f nextcloud        # Ctrl+C to stop watching; wait for "Nextcloud was successfully installed"
curl http://localhost:8080/status.php   # "installed":true
```

Then turn NextTime on and check it:

```bash
occ app:enable timebank                 # also: make docker-install-app
occ config:system:get dbtype            # pgsql
occ config:system:get dbhost            # nexttime-db
```

Open `http://localhost:8080`, log in as `NEXTCLOUD_ADMIN_USER`, open **NextTime**. The **Governance log** shows a line from
"system": *first recorded the running setup*, with `database: postgres`.

**The database must be up and healthy before Nextcloud starts the first time.** The official image only installs itself
into an empty volume, once. If it started first, see "installed: false" in [isolated-data.md](isolated-data.md#if-something-does-not-work).

**A second instance on the same database server** (for example Nextcloud 35 next to an older one): give it its own
database and files volume. In `.env` set `NEXTTIME_DB_NAME=nexttime35` and `NEXTTIME_FILES_VOLUME=nexttime35_files`, then:

```bash
data exec db psql -U nextcloud -c "CREATE DATABASE nexttime35 OWNER nextcloud"
docker volume create nexttime35_files
docker compose up -d --build
```

### Non-Docker

Needs: a Nextcloud 29 to 35 server on PHP 8.3 or newer (with the `pgsql` and `pdo_pgsql` PHP extensions), PostgreSQL 15 or
newer, Git, Node 20 or newer. Skip the first two steps if Nextcloud is already installed and you are only adding NextTime
(but then **create a new database only if you are installing Nextcloud itself**; an existing instance keeps its database).

1. **Create the database and its owner** (pick your own password; keep it out of shell history if you can):
   ```bash
   sudo -u postgres psql -c "CREATE ROLE nexttime LOGIN PASSWORD 'choose-a-strong-password'"
   sudo -u postgres psql -c "CREATE DATABASE nexttime OWNER nexttime"
   ```
2. **Install Nextcloud into it** (once, into an empty Nextcloud folder):
   ```bash
   sudo -u www-data php /var/www/nextcloud/occ maintenance:install \
     --database pgsql --database-host 127.0.0.1 --database-name nexttime \
     --database-user nexttime --database-pass 'choose-a-strong-password' \
     --admin-user admin --admin-pass 'a-strong-admin-password'
   occ config:system:get dbtype        # pgsql
   ```
   Add your domain to `trusted_domains` as usual, and set up Nextcloud's cron job.
3. **Put NextTime in the apps folder.** The folder must be named `timebank`:
   ```bash
   cd /var/www/nextcloud/custom_apps         # the writable apps folder from config.php's apps_paths
   sudo git clone <this repository> timebank
   cd timebank && npm ci && npm run build    # without js/ the page is blank
   sudo chown -R www-data:www-data /var/www/nextcloud/custom_apps/timebank
   ```
   (Or unpack a release tarball that already contains `js/` and `css/`.)
4. **Turn it on and check:**
   ```bash
   occ app:enable timebank
   occ app:list | grep timebank
   ```
   Open **NextTime** and look for the "system" line in the **Governance log**.

A Docker-free development copy on SQLite is `scripts/dev-native.sh setup` (see the README); it is not meant for a community's data.

---

## 2. A fresh install that uses data you already have

Pick the row that matches what you have. **Keep the old database, volume and container until you have checked the result.**

| You have | Use | Why |
|----------|-----|-----|
| The old instance still running (any database), and you want a clean new Nextcloud | **A. Import** | Safest. The new instance is clean, the old data is only read. |
| A backup set (database dump + files), or the old files volume/folder with `config.php` | **B. Restore** | Brings back the same instance, users and settings included. |
| A live database that the *same* Nextcloud (same `config.php`) should simply reconnect to | **C. Reconnect** | Same instance, new place to run it. |

### A. Import into a new instance

Install fresh first (section 1), then follow [isolated-data.md, "Bringing an older instance's data across"](isolated-data.md#bringing-an-older-instances-data-across)
(same steps for both Docker and non-Docker; only the command prefix differs). In short:

1. Create the accounts the old data names (`occ user:add`): the import matches people by identical user name and creates none.
2. Make a **second admin**: one admin stages an import, a different one finalizes.
3. As the first admin open **Import**, enter the *old database's own* user and password (not a Nextcloud login; for the Docker stack
   `POSTGRES_USER` and `POSTGRES_PASSWORD` from `.env`), prefix `oc_`. **Preview**, then **Stage**.
4. As the second admin, **Finalize**. Claims arrive **pending**; check the **Governance log**.

For a non-Docker source, the host is the database server's name or address and the port is `5432` (PostgreSQL) or `3306` (MySQL).
A read-only user is the safest login: `CREATE ROLE importer LOGIN PASSWORD '...'; GRANT CONNECT ON DATABASE <old> TO importer;`
then `GRANT SELECT ON ALL TABLES IN SCHEMA public TO importer;` while connected to that database.

### B. Restore a backup (the same instance, rebuilt)

**Rehearse on a throwaway copy first**: restoring replaces what is in the database. The files part matters as much as the dump:
the backup's `config/config.php` holds the instance's `secret`, `passwordsalt` and `instanceid`. A *new* Nextcloud with a
*restored database but its own new config.php* will not match them, so use the backed-up `config.php`, not a freshly generated one.

**Docker** (the step-by-step with every command is in [isolated-data.md, "Restoring"](isolated-data.md#restoring)):

1. Create empty volumes (`make docker-data-init`) and start only the database: `data up -d --wait`.
2. Load the dump: `data exec -T db pg_restore -U nextcloud -d <database> --clean --if-exists --no-owner < <dump file>`.
3. Unpack the files archive into the files volume (the `docker run ... tar xzf` line in that page).
4. Start the app (`docker compose up -d`). If the backup's version is older than your code: `occ upgrade`.
5. `occ config:system:get dbhost` and `dbname` should name the database you restored into.
   (Restoring into a differently named database than the backup came from? Change `dbname` in `config.php` to match.)

**Non-Docker:**

```bash
occ maintenance:mode --on                                   # if an instance is running
sudo -u postgres psql -c "CREATE ROLE nexttime LOGIN PASSWORD '...'"          # only if the role does not exist
sudo -u postgres psql -c "CREATE DATABASE nexttime OWNER nexttime"            # empty, new
sudo -u postgres pg_restore -d nexttime --no-owner --role=nexttime /mnt/backup/nexttime-<time>.dump
sudo tar xzf /mnt/backup/nexttime-files-<time>.tgz -C /var/www       # restores config/, data/ and custom_apps/
sudo chown -R www-data:www-data /var/www/nextcloud
# in config.php check dbhost, dbname, dbuser, dbpassword match the database above
occ upgrade                                                  # only if your code is newer than the backup
occ maintenance:mode --off
```

Check: log in, open **NextTime**, and compare a few numbers (for example the request count) with what you remember or wrote down:
`sudo -u postgres psql -d nexttime -c "select count(*) from oc_timebank_requests"`.

### C. Reconnect a Nextcloud to a database that already has the data

Only when the instance's `config.php` and `data/` folder come along (same `secret`, `instanceid`), and the Nextcloud major version
is the same or newer by one step. If you do not have the old `config.php`, use A or B instead.

**Docker.** The database stack is separate, so the data volume can simply be used again:

```bash
docker volume ls                          # nexttime_pgdata (the database) and your files volume are listed
data up -d --wait                         # the database comes up with its data
data ps                                   # State: healthy
```

Set `NEXTTIME_FILES_VOLUME` in `.env` to the existing files volume (it holds `config.php`), then `docker compose up -d`. Nextcloud sees an
installed instance and starts as it was. If the files volume is **gone** but the database is intact, do **not** start a new Nextcloud on
that database: the official image would try to install into a database that already has tables. Restore the files from a backup
(B) or create a new database and import (A).

**Non-Docker:** edit the connection in the instance's `config.php` (or use `occ config:system:set dbhost --value=<host>`; with
PostgreSQL to PostgreSQL this is safe), then `occ maintenance:mode --off` and `occ status`. `installed: true` and a working login mean it is connected.

### After any of A, B or C

- `occ app:list | grep timebank` shows the version; **Governance log** shows the "system" line (the first time on this setup, or *updated NextTime* / *changed the kind of database*).
- Run `scripts/smoke-test.sh` against it only on a throwaway instance: it creates test requests.

---

## 3. Updating NextTime and repairing a broken data source

### Update NextTime (Nextcloud itself stays on the same major version)

1. **Back up** (Docker: `make docker-backup`; non-Docker: the backup box above).
2. **Get the new code** and rebuild the frontend:
   ```bash
   cd <repo or custom_apps/timebank>
   git pull
   npm ci && npm run build            # needed whenever src/ changed; reload the browser with Ctrl+F5 afterwards
   ```
   Docker: the repo is mounted into the container, so there is nothing to copy. Non-Docker: fix the owner afterwards
   (`sudo chown -R www-data:www-data custom_apps/timebank`).
3. **Run the upgrade.** It applies new migrations to the database (new migrations only ever add; none edits earlier ones):
   ```bash
   occ upgrade
   occ app:list | grep timebank       # the new version number
   ```
   Docker: only rebuild the image (`docker compose up -d --build`) when `Dockerfile` or the Nextcloud image tag changed.
4. **Check:** open NextTime; the **Governance log** has a new "system" line, *updated NextTime*, with the old and new version.
   If there is none, the migrations did not finish: see below.

Moving to a **new Nextcloud major version** is a separate job: Nextcloud upgrades one major version at a time (31, then 32, ...),
with a backup before each. Do not skip versions, and do not point an older Nextcloud at a database a newer one used.

### The data source is broken: find which part

Symptoms: "Internal Server Error", a maintenance-mode page, "Can't connect to the database", NextTime's page opens but is empty or full of errors,
`status.php` shows `"installed": false` or `"needsDbUpgrade": true`.

Work from the bottom up and stop at the first thing that is wrong.

**1. Is the database running?** See [section 4](#4-checking-the-database-is-running-and-taking-it-offline-safely). If not, start it (Docker: `data up -d --wait`;
non-Docker: `sudo systemctl start postgresql`) and reload.

**2. What does Nextcloud think the database is?**
```bash
occ config:system:get dbtype ; occ config:system:get dbhost ; occ config:system:get dbname ; occ config:system:get dbuser
```
If `occ` itself cannot run because of the database, read the same four values in `config/config.php`
(Docker: `docker compose exec nextcloud grep -E "dbtype|dbhost|dbname|dbuser" config/config.php`).

**3. Can that host be reached with that name and password?**
- Docker: the app container must be on the database network, and the name must resolve:
  ```bash
  docker network inspect nexttime_data --format '{{range .Containers}}{{.Name}} {{end}}'     # lists the database and the app
  docker compose exec nextcloud getent hosts nexttime-db                                    # prints an address
  docker network connect nexttime_data <app container name>                                 # if the app is missing from the list
  ```
- Non-Docker: `PGPASSWORD='...' psql -h <dbhost> -U <dbuser> -d <dbname> -c "select 1"`. A refusal means the password, `pg_hba.conf`, or the server's `listen_addresses` is wrong.

**4. Point Nextcloud at the right place** if the host, name or password changed (PostgreSQL to PostgreSQL only; for SQLite to PostgreSQL see
[isolated-data.md](isolated-data.md#an-instance-that-already-has-data-on-sqlite)):
```bash
occ config:system:set dbhost --value=nexttime-db        # Docker; the host name or address on a server
occ config:system:set dbname --value=nexttime35
occ config:system:set dbpassword --value='the-password'
```
Docker: then `docker compose restart nextcloud`. Non-Docker: restart PHP-FPM or Apache (`sudo systemctl restart php8.3-fpm apache2`, or your equivalent).

**5. Is the schema complete?** (a half-finished update, or an older backup)
```bash
occ migrations:status timebank           # lists applied and pending migrations
occ migrations:migrate timebank          # applies any pending ones
occ upgrade
occ maintenance:repair
```
Then **Governance log** should show a "system" line for whatever changed.

**6. Still stuck:** read the log, newest last (Docker: `docker compose logs --tail 100 nextcloud`, or `occ log:file`; non-Docker:
`/var/www/nextcloud/data/nextcloud.log`, one JSON object per line), and restore from the backup you took in step 1
(section 2 B) rather than editing tables by hand.

---

## 4. Checking the database is running, and taking it offline safely

### Is it running?

**Docker**

```bash
data ps                                                            # State "running", Health "healthy"
data exec db pg_isready -U nextcloud -d nextcloud                  # "accepting connections"
docker volume ls                                                   # nexttime_pgdata and your files volume are listed
docker network inspect nexttime_data --format '{{range .Containers}}{{.Name}} {{end}}'   # the database and the app
occ status                                                         # installed: true, maintenance: false
curl -s http://localhost:8080/status.php                           # "installed":true,"maintenance":false
```

`healthy` is the check that matters; `starting` means wait a minute. The volume being listed does not mean the database is up: they are separate.
The `psql` list of what is inside is: `data exec db psql -U nextcloud -c "\l"`.

**Non-Docker**

```bash
sudo systemctl status postgresql                                   # active (running); on some systems postgresql@15-main
pg_isready -h 127.0.0.1 -p 5432                                    # accepting connections
sudo -u postgres psql -c "\l"                                      # your database is listed
occ status                                                         # installed: true, maintenance: false
occ config:system:get dbhost                                       # where Nextcloud is looking
df -h /var/lib/postgresql                                          # disk space; a full disk stops the database
```

A remote database server: run `pg_isready -h <host> -p 5432` from the Nextcloud machine, and check there on the database machine.

### Taking it offline safely

The order is always: **stop the writers (Nextcloud), back up, stop the database, and check nothing was lost.** Starting is the reverse.

**Docker**

```bash
# 1. Back up, with the database still running
BACKUP_DIR=/e/nexttime-backups make docker-backup
# 2. Stop Nextcloud (keeps volumes; do not add -v)
occ maintenance:mode --on                  # optional: ends open sessions' writes first
docker compose down                        # or: make docker-down
# 3. Check no one else is connected
data exec db psql -U nextcloud -c "select datname, state, count(*) from pg_stat_activity where datname is not null and pid <> pg_backend_pid() group by 1,2"
#    (no rows, or only idle ones: fine)
# 4. Stop the database (it shuts down cleanly and finishes writing; a longer wait is allowed with -t)
data down -t 60                            # or: make docker-data-down
# 5. Confirm the data is still there
docker volume ls | grep nexttime_pgdata
```

Starting again: `data up -d --wait`, then `docker compose up -d`, then `occ maintenance:mode --off` if you turned it on.

**Never**, for a database you want to keep: `docker volume rm`, `docker volume prune`, `docker system prune --volumes`, `docker compose down -v` on
any stack, Docker Desktop's *Clean / Purge data* or *Reset to factory defaults*, or uninstalling Docker Desktop. The volumes are marked
external so Compose will not delete them, but these commands are not Compose. Do not change `postgres:15-alpine` to another major version
over the same volume: a volume made by 15 will not start under 16.

**Non-Docker**

```bash
# 1. Back up (the backup box above), with the database running
# 2. Stop the writers
occ maintenance:mode --on
sudo systemctl stop apache2 php8.3-fpm     # or nginx; use your web server and PHP service names
sudo systemctl stop cron                   # or disable Nextcloud's cron entry; its jobs write to the database
# 3. Check no one else is connected
sudo -u postgres psql -c "select datname, state, count(*) from pg_stat_activity where datname is not null and pid <> pg_backend_pid() group by 1,2"
# 4. Stop the database; systemd asks for a clean ("fast") shutdown, which finishes writing and ends open sessions
sudo systemctl stop postgresql
sudo systemctl status postgresql           # inactive (dead)
```

Starting again: `sudo systemctl start postgresql`, `pg_isready`, then the web server and PHP, then `occ maintenance:mode --off` (and `cron` if you stopped it).
Do not kill the `postgres` process or switch the machine off while it runs; a crash is recovered from on the next start, but
that is a recovery, not a shutdown. A clean stop, with the backup taken first, is what this section is for.

For maintenance of the machine itself (moving disks, resizing a VM), take the backup, run the stop sequence, and check the dump file is not
empty before you touch anything.

---

## What has and has not been tested

| Part | Status |
|------|--------|
| Fresh install on PostgreSQL and enabling NextTime | Run natively (Nextcloud 35.0.2, PostgreSQL 16); on Docker by the maintainer (Nextcloud 35, PostgreSQL 15) |
| Import (A), updating with `occ upgrade`, the "system" governance lines | Run natively; the import page used on Docker by the maintainer |
| Restore (B), reconnecting (C), the non-Docker install and restore commands, the offline sequences | **Written from the tools' documented behavior, not yet run end to end.** Rehearse them on a throwaway copy before you depend on them |

If a command here does not behave as written, please say which step: this page should get corrected, not worked around.
