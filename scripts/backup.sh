#!/usr/bin/env bash
# Back up everything NextTime keeps, from the Docker stack (docs/isolated-data.md):
#
#   nexttime-db-<time>.dump      the database (pg_dump custom format; restore with pg_restore)
#   nexttime-files-<time>.tgz    Nextcloud's folder: config.php, uploaded files such as claim evidence
#   nexttime-<time>.info         app version and when, so a restore can pick matching code
#   nexttime-<time>.sha256       checksums of the three files above
#
# The database dump alone is not a backup: without config.php the restored instance cannot use
# its own passwords and secrets, and claim evidence files would be gone.
#
# Usage:  BACKUP_DIR=/e/nexttime-backups scripts/backup.sh
#   BACKUP_DIR  required: a folder OUTSIDE this repo (the external drive). Never created for you,
#               so a drive that is not plugged in is an error, not a backup into the wrong place.
#   KEEP        how many backup sets to keep (default 7); older ones are deleted only after a new
#               set has been written and checked
#   ENV_FILE    the compose .env (default .env)
#
# Nextcloud is put in maintenance mode while the files are copied, so the database and the files
# match, and put back as it was, even if something fails. Nothing is ever restored by this script.

set -euo pipefail

# Git Bash on Windows rewrites arguments that look like paths (/data becomes C:/Program Files/Git/data)
# before they reach docker; these switch that off and do nothing anywhere else.
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'

cd "$(dirname "${BASH_SOURCE[0]}")/.."
REPO="$(pwd -P)"

ENV_FILE="${ENV_FILE:-.env}"
KEEP="${KEEP:-7}"
TOOL_IMAGE="${TOOL_IMAGE:-postgres:15-alpine}" # already pulled for the database; has tar and gzip

die() { echo "backup: $*" >&2; exit 1; }

# envval KEY DEFAULT: the value from the .env file (CRLF and quotes tolerated), else the default
envval() {
	local v=""
	if [ -f "$ENV_FILE" ]; then
		v="$(sed -n "s/^$1=//p" "$ENV_FILE" | tail -n 1 | tr -d '\r')"
		v="${v%\"}"; v="${v#\"}"; v="${v%\'}"; v="${v#\'}"
	fi
	printf '%s' "${v:-$2}"
}

[ -n "${BACKUP_DIR:-}" ] || die "set BACKUP_DIR to a folder outside this repo, e.g. BACKUP_DIR=/e/nexttime-backups"
[ -d "$BACKUP_DIR" ] || die "$BACKUP_DIR does not exist (is the drive connected?)"
[ -w "$BACKUP_DIR" ] || die "$BACKUP_DIR is not writable"
DEST="$(cd "$BACKUP_DIR" && pwd -P)"
case "$DEST/" in "$REPO"/*) die "BACKUP_DIR is inside the repo; a backup next to the code is lost with it" ;; esac
case "$KEEP" in '' | *[!0-9]* | 0) die "KEEP must be a whole number of at least 1" ;; esac

PGUSER="$(envval POSTGRES_USER nextcloud)"
PGDB="$(envval POSTGRES_DB nextcloud)"
FILES_VOLUME="$(envval NEXTTIME_FILES_VOLUME nexttime_files)"
DATA=(docker compose --env-file "$ENV_FILE" -f docker/data/docker-compose.yml)
APP=(docker compose)

db_running() { "${DATA[@]}" ps --status running --services 2>/dev/null | grep -qx db; }
app_running() { "${APP[@]}" ps --status running --services 2>/dev/null | grep -qx nextcloud; }

db_running || die "the database is not running (start it with: make docker-data-up)"

TS="$(date -u +%Y%m%dT%H%M%SZ)"
DUMP="nexttime-db-$TS.dump"
FILES="nexttime-files-$TS.tgz"
INFO="nexttime-$TS.info"
SUMS="nexttime-$TS.sha256"

TURNED_ON=0
cleanup() {
	local rc=$?
	rm -f "$DEST/$DUMP.partial" "$DEST/$FILES.partial"
	if [ "$TURNED_ON" = 1 ]; then
		"${APP[@]}" exec -T -u www-data nextcloud php occ maintenance:mode --off >/dev/null 2>&1 \
			|| echo "backup: COULD NOT turn maintenance mode off; run: docker compose exec -u www-data nextcloud php occ maintenance:mode --off" >&2
	fi
	if [ "$rc" != 0 ]; then
		rm -f "$DEST/$DUMP" "$DEST/$FILES" "$DEST/$INFO" "$DEST/$SUMS"
		echo "backup: FAILED, no new backup set was kept (older sets are untouched)" >&2
	fi
}
trap cleanup EXIT

# Maintenance mode, unless Nextcloud is not running or an admin already turned it on
if app_running; then
	state="$("${APP[@]}" exec -T -u www-data nextcloud php occ maintenance:mode 2>/dev/null || true)"
	case "$state" in
		*"currently disabled"*)
			"${APP[@]}" exec -T -u www-data nextcloud php occ maintenance:mode --on >/dev/null
			TURNED_ON=1
			;;
		*"currently enabled"*) echo "backup: maintenance mode was already on; leaving it on" ;;
		*) die "could not read Nextcloud's maintenance mode (got: ${state:-nothing})" ;;
	esac
else
	echo "backup: Nextcloud is not running; the files are copied without pausing it"
fi

echo "backup: database -> $DEST/$DUMP"
"${DATA[@]}" exec -T db pg_dump -U "$PGUSER" -d "$PGDB" -Fc >"$DEST/$DUMP.partial"
[ -s "$DEST/$DUMP.partial" ] || die "the database dump is empty"
[ "$(head -c 5 "$DEST/$DUMP.partial")" = "PGDMP" ] || die "the database dump is not a pg_dump file"

echo "backup: files   -> $DEST/$FILES (volume $FILES_VOLUME)"
docker volume inspect "$FILES_VOLUME" >/dev/null 2>&1 || die "volume $FILES_VOLUME does not exist"
docker run --rm -v "$FILES_VOLUME":/data:ro --entrypoint tar "$TOOL_IMAGE" czf - -C /data . >"$DEST/$FILES.partial"
[ -s "$DEST/$FILES.partial" ] || die "the files archive is empty"
gzip -t "$DEST/$FILES.partial" || die "the files archive is damaged"
tar tzf "$DEST/$FILES.partial" >/dev/null || die "the files archive cannot be read back"

mv "$DEST/$DUMP.partial" "$DEST/$DUMP"
mv "$DEST/$FILES.partial" "$DEST/$FILES"

{
	echo "taken: $TS"
	echo "app_version: $(sed -n 's/.*<version>\(.*\)<\/version>.*/\1/p' appinfo/info.xml | head -n 1)"
	echo "git_commit: $(git rev-parse --short HEAD 2>/dev/null || echo unknown)"
	echo "database: $PGDB (user $PGUSER)"
	echo "files_volume: $FILES_VOLUME"
} >"$DEST/$INFO"

if command -v sha256sum >/dev/null 2>&1; then
	(cd "$DEST" && sha256sum "$DUMP" "$FILES" "$INFO" >"$SUMS")
else
	(cd "$DEST" && shasum -a 256 "$DUMP" "$FILES" "$INFO" >"$SUMS")
fi

# Retention: only after the new set is complete, drop the sets beyond KEEP (newest first by name)
while IFS= read -r sums; do
	[ -n "$sums" ] || continue
	stamp="${sums##*/nexttime-}"
	stamp="${stamp%.sha256}"
	rm -f "$DEST/nexttime-db-$stamp.dump" "$DEST/nexttime-files-$stamp.tgz" "$DEST/nexttime-$stamp.info" "$sums"
	echo "backup: removed old set $stamp"
done < <(ls -1 "$DEST"/nexttime-*.sha256 2>/dev/null | sort -r | tail -n +$((KEEP + 1)) || true)

echo "backup: done. $TS kept in $DEST (the $KEEP newest sets are kept)"
