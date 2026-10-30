#!/usr/bin/env bash
# Tests scripts/backup.sh against a fake `docker` command: no containers, no Docker needed.
# It checks the script's own logic: what it writes, that old sets are pruned only after a new one is
# complete, and that a failure keeps older backups and puts maintenance mode back. It does NOT prove
# that the real pg_dump and volume copy work; for that, run the script against a throwaway stack.
#
# Usage: scripts/backup-test.sh        (exit code = number of failed checks)

set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
REPO="$(pwd -P)"

FAILS=0
pass() { printf '  \033[32mPASS\033[0m %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; FAILS=$((FAILS + 1)); }
check() { if eval "$2"; then pass "$1"; else fail "$1"; fi; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/bin" "$TMP/files" "$TMP/state"
echo "evidence" >"$TMP/files/config.php"

# The fake docker. State lives in files so each call can read what the last one did.
cat >"$TMP/bin/docker" <<'SHIM'
#!/usr/bin/env bash
S="$FAKE_STATE"
echo "$*" >>"$S/calls"
case "$1" in
	volume) exit 0 ;;
	run) tar czf - -C "$FAKE_FILES" . ;;
	compose)
		case " $* " in
			*" ps "*) case " $* " in *"docker/data"*) echo db ;; *) [ -f "$S/app-down" ] || echo nextcloud ;; esac ;;
			*" psql "*) printf 'nextcloud\nnexttime35\n' ;;
			*" pg_dump "*) [ -f "$S/dump-fails" ] && exit 3; printf 'PGDMP\001\002fake database'; ;;
			*"maintenance:mode --on"*) echo on >"$S/maint"; echo "Maintenance mode enabled" ;;
			*"maintenance:mode --off"*) echo off >"$S/maint"; echo "Maintenance mode disabled" ;;
			*"maintenance:mode"*) [ "$(cat "$S/maint")" = on ] && echo "Maintenance mode is currently enabled" || echo "Maintenance mode is currently disabled" ;;
		esac ;;
esac
SHIM
chmod +x "$TMP/bin/docker"
export PATH="$TMP/bin:$PATH" FAKE_STATE="$TMP/state" FAKE_FILES="$TMP/files"

run() { BACKUP_DIR="$1" ENV_FILE="$TMP/none.env" "${@:2}" scripts/backup.sh >"$TMP/out" 2>&1; }
sets() { ls -1 "$1"/nexttime-*.sha256 2>/dev/null | wc -l | tr -d ' '; }

echo "- A normal backup"
echo off >"$TMP/state/maint"
mkdir "$TMP/drive one"
run "$TMP/drive one" env
check "it succeeds, even with a space in the folder name" '[ $? -eq 0 ] && grep -q "backup: done" "$TMP/out"'
check "it wrote a dump for each database, a files archive, an info file and checksums" '[ "$(ls -1 "$TMP/drive one" | wc -l | tr -d " ")" = 5 ]'
check "the dumps are named for their databases" 'ls "$TMP/drive one" | grep -q -- "-nextcloud.dump$" && ls "$TMP/drive one" | grep -q -- "-nexttime35.dump$"'
check "the checksums verify" '(cd "$TMP/drive one" && sha256sum -c nexttime-*.sha256 >/dev/null 2>&1)'
check "each dump starts like a pg_dump file" 'for f in "$TMP/drive one"/nexttime-db-*.dump; do [ "$(head -c 5 "$f")" = PGDMP ] || exit 1; done'
check "the files archive holds config.php" 'tar tzf "$TMP/drive one"/nexttime-files-*.tgz | grep -q config.php'
check "the info file names the app version" 'grep -q "^app_version: [0-9]" "$TMP/drive one"/nexttime-*.info'
check "maintenance mode is off again" '[ "$(cat "$TMP/state/maint")" = off ]'
check "no half-written files are left" '! ls "$TMP/drive one" | grep -q partial'

echo "- Retention keeps the newest sets"
for i in 1 2 3; do sleep 1; run "$TMP/drive one" env KEEP=2; done
check "four runs with KEEP=2 leave two sets" '[ "$(sets "$TMP/drive one")" = 2 ]'
check "and no orphaned files from the removed sets" '[ "$(ls -1 "$TMP/drive one" | wc -l | tr -d " ")" = 10 ]'

echo "- A failed dump keeps what was already there"
before="$(ls -1 "$TMP/drive one")"
touch "$TMP/state/dump-fails"
sleep 1
run "$TMP/drive one" env KEEP=1
check "it exits non-zero" '[ $? -ne 0 ] || grep -q FAILED "$TMP/out"'
check "it says no new set was kept" 'grep -q "no new backup set was kept" "$TMP/out"'
check "older sets are untouched (KEEP=1 did not prune on a failure)" '[ "$(ls -1 "$TMP/drive one")" = "$before" ]'
check "maintenance mode is back off" '[ "$(cat "$TMP/state/maint")" = off ]'
rm -f "$TMP/state/dump-fails"

echo "- Maintenance mode that an admin turned on stays on"
echo on >"$TMP/state/maint"
sleep 1
run "$TMP/drive one" env
check "the backup still works" 'grep -q "backup: done" "$TMP/out"'
check "and it left maintenance mode on" '[ "$(cat "$TMP/state/maint")" = on ]'
echo off >"$TMP/state/maint"

echo "- Choosing the databases"
mkdir "$TMP/drive two"
run "$TMP/drive two" env BACKUP_DATABASES=nexttime35
check "BACKUP_DATABASES limits the dumps to the one named" '[ "$(ls "$TMP/drive two"/nexttime-db-*.dump | wc -l | tr -d " ")" = 1 ] && ls "$TMP/drive two" | grep -q -- "-nexttime35.dump$"'
run "$TMP/drive two" env 'BACKUP_DATABASES=ok;rm -rf x'
check "a database name with odd characters is refused" 'grep -q "has characters this script does not handle" "$TMP/out"'

echo "- Refusals"
run "$TMP/not-there" env
check "a missing folder (drive unplugged) is refused" 'grep -q "does not exist" "$TMP/out"'
mkdir -p "$REPO/.backup-test-inside"
run "$REPO/.backup-test-inside" env
check "a folder inside the repo is refused" 'grep -q "inside the repo" "$TMP/out"'
rmdir "$REPO/.backup-test-inside"
BACKUP_DIR="" ENV_FILE="$TMP/none.env" scripts/backup.sh >"$TMP/out" 2>&1
check "no BACKUP_DIR is refused" 'grep -q "set BACKUP_DIR" "$TMP/out"'
run "$TMP/drive one" env KEEP=0
check "KEEP=0 is refused" 'grep -q "KEEP must be" "$TMP/out"'

echo
if [ "$FAILS" -eq 0 ]; then echo "All backup checks passed."; else echo "$FAILS check(s) failed."; fi
exit "$FAILS"
