# Installing NextTime (Time Bank)

The release tarball (`timebank.tar.gz`) ships with **prebuilt frontend assets** —
no Node.js, npm, or Composer is needed on the server. Installation is: extract,
enable, done.

> **Why the app folder is named `timebank`:** the folder name must match the
> app ID in `appinfo/info.xml`. Renaming the folder (e.g. to `nexttime`) will
> break the install.

---

## Option A: Nextcloud All-in-One (AIO)

AIO runs Nextcloud inside the `nextcloud-aio-nextcloud` container. Community
apps go in `/var/www/html/custom_apps` inside that container (persisted in the
`nextcloud_aio_nextcloud` volume, so it survives container updates).

From the host machine where AIO is running:

```bash
# 1. Download and extract the release tarball
tar xzf timebank.tar.gz          # produces a folder named "timebank"

# 2. Copy it into the AIO Nextcloud container
docker cp timebank nextcloud-aio-nextcloud:/var/www/html/custom_apps/

# 3. Fix ownership so the web server can read it
docker exec nextcloud-aio-nextcloud chown -R www-data:www-data /var/www/html/custom_apps/timebank

# 4. Enable the app (this also runs the database migrations)
docker exec --user www-data nextcloud-aio-nextcloud php occ app:enable timebank
```

Then open Nextcloud — **NextTime** appears in the top app navigation.

**Upgrading:** repeat steps 1–3 with the new tarball (overwrite the folder),
then run `occ upgrade` or simply `occ app:enable timebank` again; pending
migrations run automatically.

**Uninstalling:**

```bash
docker exec --user www-data nextcloud-aio-nextcloud php occ app:disable timebank
docker exec nextcloud-aio-nextcloud rm -rf /var/www/html/custom_apps/timebank
```

(App tables are prefixed `timebank_` if you want to drop them manually later.)

---

## Option B: Standard Nextcloud (bare metal / snap / other Docker images)

```bash
# 1. Extract into the apps directory (or custom_apps if you use one)
cd /path/to/nextcloud/apps
tar xzf /path/to/timebank.tar.gz

# 2. Fix ownership (user varies: www-data on Debian/Ubuntu, apache on RHEL)
chown -R www-data:www-data timebank

# 3. Enable
sudo -u www-data php /path/to/nextcloud/occ app:enable timebank
```

Or skip the CLI entirely: after extracting the folder, go to
**Settings → Apps → Disabled apps** in the web UI and click **Enable** next to
NextTime.

---

## Option C: Development environment (this repo)

Requires Docker, Node.js ≥ 20, npm.

```bash
cp .env.example .env    # edit passwords first!
make dev                # installs deps, builds frontend, starts containers
# Nextcloud at http://localhost:8080 — finish setup wizard, then:
make docker-install-app
```

`make watch` rebuilds the frontend on file changes during development.

---

## Requirements

| Component  | Version                          |
|------------|----------------------------------|
| Nextcloud  | 29 – 33                          |
| PHP        | 8.1+                             |
| Database   | PostgreSQL 12+, MySQL 8+, or SQLite (small installs only) |

## Building the tarball yourself

```bash
make appstore           # produces build/timebank.tar.gz
```

Or push a git tag like `v0.2.0` — the `release.yml` GitHub Actions workflow
builds and attaches the tarball to the release automatically.

## Troubleshooting

- **Blank page after enabling** → the `js/` folder is missing (you installed
  from a raw source checkout instead of a release tarball). Run `make build`
  in the app folder, or use the tarball.
- **"App does not comply with app store policies" / signature warning** →
  expected for locally installed apps; you can allow unsigned local apps with
  `occ config:system:set appcodechecker --value=false --type=boolean` on older
  versions, or just confirm the integrity warning for a custom app.
- **API calls return 404 or 405** → make sure you're on v0.2.0+; earlier
  builds registered API routes under the wrong URL prefix.
- **Migrations didn't run** → `occ app:enable timebank` again, or
  `occ migrations:migrate timebank`.
