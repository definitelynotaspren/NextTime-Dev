#!/usr/bin/env bash
# End-to-end check of importing from another NextTime database, against the dev server
# (scripts/dev-native.sh): preview, stage, the two-admin rule, finalize, what gets written, and cancel.
#
# It builds a small SQLite "old NextTime" in Nextcloud's data folder, creates a second admin
# (admin2, deleted again at the end), and asserts on the rows it creates, so it is safe to re-run.
#   alice  member with an account      bob    member with an account
#   ghost  named in the source, no account here
# Usage:  scripts/import-smoke.sh [BASE_URL]
#   BASE_URL defaults to http://127.0.0.1:8081. ADMIN_PASS is the admin's password (default admin).
# Exit code is the number of failed checks (0 = all good).

set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
BASE="${1:-${BASE_URL:-http://127.0.0.1:8081}}"
API="$BASE/index.php/apps/timebank/api"
FAILS=0
TMP="$(mktemp -d)"
STAMP="$(date +%s)"
SECOND_ADMIN_PASS='Second-admin-2026!'
FIXTURE="import-smoke-$STAMP.db"
DATA_DIR=""

cleanup() {
	scripts/dev-native.sh occ user:delete admin2 >/dev/null 2>&1
	[ -n "$DATA_DIR" ] && rm -f "$DATA_DIR/$FIXTURE"
	rm -rf "$TMP"
}
trap cleanup EXIT

command -v jq >/dev/null || { echo "jq is required (apt install jq / brew install jq)"; exit 99; }

pass() { printf '  \033[32mPASS\033[0m %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; FAILS=$((FAILS + 1)); }
pw() { case "$1" in admin) echo "${ADMIN_PASS:-admin}" ;; admin2) echo "$SECOND_ADMIN_PASS" ;; *) echo "$1-pass-123" ;; esac; }

# call USER METHOD PATH [JSON_BODY] -> body in $TMP/body, status in $STATUS
call() {
	local user="$1" method="$2" path="$3" body="${4:-}"
	local jar="$TMP/jar-$user"
	if [ ! -s "$TMP/token-$user" ]; then
		curl -s -c "$jar" -u "$user:$(pw "$user")" -H 'OCS-APIRequest: true' "$BASE/index.php/csrftoken" | jq -r '.token // empty' >"$TMP/token-$user"
	fi
	local args=(-s -o "$TMP/body" -w '%{http_code}' -u "$user:$(pw "$user")" -b "$jar" -c "$jar"
		-H "requesttoken: $(cat "$TMP/token-$user")" -H 'OCS-APIRequest: true' -H 'Accept: application/json' -X "$method")
	[ -n "$body" ] && args+=(-H 'Content-Type: application/json' -d "$body")
	STATUS="$(curl "${args[@]}" "$API$path")"
}
eq() { if [ "$STATUS" = "$2" ]; then pass "$1 ($STATUS)"; else fail "$1 - expected $2, got $STATUS: $(head -c 240 "$TMP/body")"; fi; }
has() { local label="$1" filter="$2"; shift 2; if jq -e "$@" "$filter" "$TMP/body" >/dev/null 2>&1; then pass "$label"; else fail "$label :: $(head -c 300 "$TMP/body")"; fi; }

echo "NextTime import smoke test against $BASE"

echo "- Set up: a second admin and a small old NextTime database"
scripts/dev-native.sh occ user:delete admin2 >/dev/null 2>&1
OC_PASS="$SECOND_ADMIN_PASS" scripts/dev-native.sh occ user:add --password-from-env --display-name "Second admin" admin2 >/dev/null 2>&1
scripts/dev-native.sh occ group:adduser admin admin2 >/dev/null 2>&1
DATA_DIR="$(scripts/dev-native.sh occ config:system:get datadirectory 2>/dev/null | tail -n 1)"
[ -d "$DATA_DIR" ] || { echo "cannot find Nextcloud's data folder ($DATA_DIR)"; exit 99; }
call admin GET /categories
CATEGORY="$(jq -r '[.[] | select(.archived == false)][0].name' "$TMP/body")"
[ -n "$CATEGORY" ] && [ "$CATEGORY" != null ] || { echo "no category to use"; exit 99; }
export FIXTURE_PATH="$DATA_DIR/$FIXTURE" CATEGORY STAMP
php -r '
$db = new PDO("sqlite:" . getenv("FIXTURE_PATH"));
$db->exec("CREATE TABLE oc_timebank_categories (id INTEGER PRIMARY KEY, name TEXT)");
$db->exec("CREATE TABLE oc_timebank_requests (id INTEGER PRIMARY KEY, requester_id TEXT, title TEXT, description TEXT, category_id INTEGER, hours_budget TEXT, status TEXT, priority TEXT, zip TEXT, created_at TEXT)");
$db->exec("CREATE TABLE oc_timebank_volunteers (id INTEGER PRIMARY KEY, request_id INTEGER, volunteer_id TEXT, proposed_hours TEXT, message TEXT, status TEXT, created_at TEXT)");
$db->exec("CREATE TABLE oc_timebank_earnings (id INTEGER PRIMARY KEY, user_id TEXT, category_id INTEGER, hours_claimed TEXT, actual_hours_earned TEXT, description TEXT, status TEXT, created_at TEXT, approved_at TEXT)");
$c = $db->prepare("INSERT INTO oc_timebank_categories VALUES (1, ?)"); $c->execute([getenv("CATEGORY")]);
$s = getenv("STAMP");
$now = gmdate("Y-m-d H:i:s"); // today, so the requests sort to the top of a busy board
$r = $db->prepare("INSERT INTO oc_timebank_requests VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?)");
$r->execute([1, "alice", "Import smoke A $s", "Needs a hand with the garden.", "2.00", "open", "normal", "97055", $now]);
$r->execute([2, "bob", "Import smoke B $s", "A ride to the clinic on Friday.", "1.50", "completed", "normal", "97030", $now]);
$r->execute([3, "ghost", "Import smoke C $s", "Posted by someone with no account here.", "1.00", "open", "normal", "97214", $now]);
$v = $db->prepare("INSERT INTO oc_timebank_volunteers VALUES (?, ?, ?, ?, ?, ?, ?)");
$v->execute([1, 1, "bob", "3.00", "I can help", "accepted", "2026-10-01 10:00:00"]);
$v->execute([2, 3, "bob", "1.00", "On the request of someone with no account", "offered", "2026-10-03 10:00:00"]);
$e = $db->prepare("INSERT INTO oc_timebank_earnings VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?)");
$e->execute([1, "bob", "3.00", "3.00", "Import smoke approved claim $s", "approved", "2026-10-03 09:00:00", "2026-10-04 09:00:00"]);
$e->execute([2, "alice", "1.00", "1.50", "Import smoke pending claim $s", "pending", "2026-10-05 09:00:00", null]);
$e->execute([3, "bob", "2.00", "2.00", "Import smoke rejected claim $s", "rejected", "2026-10-06 09:00:00", null]);
'
SRC="{\"label\":\"Import smoke $STAMP\",\"kind\":\"sqlite\",\"file\":\"$FIXTURE\"}"

echo "- Only admins"
call alice POST /import/preview "$SRC"; eq "a member cannot preview" 403
call alice POST /import/stage "$SRC"; eq "a member cannot stage" 403
call alice GET /import/runs; eq "a member cannot list imports" 403

echo "- Preview writes nothing"
call admin POST /import/preview "$SRC"; eq "an admin can preview" 200
has "two requests, one offer and two claims would come in" '.counts.requests == 2 and .counts.offers == 1 and .counts.claims == 2'
has "one claim is approved before" '.counts.claimsApprovedBefore == 1'
has "the rejected claim, ghost's request and its offer are left out" '.counts.leftOut == {requests: 1, offers: 1, claims: 1}'
has "ghost has no account here and is named in the report" '.report.people.ghost.matched == false and .report.people.alice.matched == true'
call admin GET /import/runs; has "no import exists yet" '[.imports[] | select(.sourceLabel == "Import smoke '"$STAMP"'")] | length == 0'

echo "- Source details are checked"
call admin POST /import/preview '{"label":"x","kind":"sqlite","file":"../config/config.php"}'; eq "a path outside the data folder is refused" 400
call admin POST /import/preview '{"label":"x","kind":"sqlite","file":"nope-'"$STAMP"'.db"}'; eq "a file that is not there is refused" 400
call admin POST /import/preview '{"label":"x","kind":"pgsql","host":"db;evil","database":"x"}'; eq "a host with odd characters is refused" 400
call admin POST /import/preview '{"label":"","kind":"sqlite","file":"'"$FIXTURE"'"}'; eq "a source with no name is refused" 400

echo "- Two admins"
call admin POST /import/stage "$SRC"; eq "an admin stages it" 201
ID="$(jq -r '.id' "$TMP/body")"
call admin GET /import/runs; has "it is listed as staged and this admin may not finalize it" '.imports[] | select(.id == ($id | tonumber)) | .status == "staged" and .canFinalize == false' --arg id "$ID"
call admin POST "/import/runs/$ID/finalize"; eq "the admin who staged it cannot finalize it" 403
call admin POST /import/stage "$SRC"; eq "staging the same source again while it waits is refused" 400
call alice POST "/import/runs/$ID/finalize"; eq "a member cannot finalize" 403
call admin2 GET /import/runs; has "the second admin may finalize it" '.imports[] | select(.id == ($id | tonumber)) | .canFinalize == true' --arg id "$ID"
call admin2 GET "/import/runs/$ID"; has "the second admin can read the report" '.report.people.ghost.matched == false'
call admin2 POST "/import/runs/$ID/finalize"; eq "a different admin finalizes it" 200
has "it is finalized by the second admin and says what was added" '.status == "finalized" and .decidedBy == "admin2" and .counts.written.requests == 2 and .counts.written.offers == 1 and .counts.written.claims == 2'
call admin2 POST "/import/runs/$ID/finalize"; eq "it cannot be finalized twice" 400
call admin POST "/import/runs/$ID/cancel"; eq "a finalized import cannot be cancelled" 400

echo "- What was written"
call admin GET "/requests?limit=100"
has "alice's and bob's requests are on the board, ghost's is not" '[.requests[] | select(.title | endswith($s))] | length == 2 and all(.[]; .title | test("Import smoke [AB] "))' --arg s "$STAMP"
call admin GET /earnings/pending
has "the approved claim is pending, marked approved before" '[.[] | select(.description == "Import smoke approved claim " + $s)] | length == 1 and .[0].status == "pending" and .[0].approvedBefore == "2026-10-04 09:00:00" and .[0].approverId == null' --arg s "$STAMP"
has "the pending claim has no approved-before mark and keeps its hours" '[.[] | select(.description == "Import smoke pending claim " + $s)][0] | .approvedBefore == null and .hoursClaimed == 1 and .actualHoursEarned == 1.5' --arg s "$STAMP"
has "the rejected claim was not imported" '[.[] | select(.description == "Import smoke rejected claim " + $s)] | length == 0' --arg s "$STAMP"

echo "- Nothing is added twice"
call admin POST /import/preview "$SRC"; has "everything is now recognised as already imported" '.counts.alreadyImported == {requests: 2, offers: 1, claims: 2}'
call admin POST /import/stage "$SRC"; eq "staging it again finds nothing to import" 400

echo "- The public log"
call carol GET "/governance?limit=100"
has "any member sees who staged it" '[.entries[] | select(.action == "import.staged" and .subject == $l and .actorId == "admin")] | length == 1' --arg l "Import smoke $STAMP"
has "and who finalized it, with totals only" '[.entries[] | select(.action == "import.finalized" and .subject == $l and .actorId == "admin2" and .details.requests == 2 and .details.claims == 2)] | length == 1' --arg l "Import smoke $STAMP"
has "the log never holds a connection detail or a file name" '[.entries[] | select(.action | startswith("import.")) | .details | tostring | select(contains($f) or contains("password") or contains("host"))] | length == 0' --arg f "$FIXTURE"

echo "- Cancelling"
CANCEL="{\"label\":\"Import smoke cancel $STAMP\",\"kind\":\"sqlite\",\"file\":\"$FIXTURE\"}"
call admin POST /import/stage "$CANCEL"; eq "a source under another name stages again" 201
CID="$(jq -r '.id' "$TMP/body")"
call admin POST "/import/runs/$CID/cancel"; eq "the admin who staged it can take it back" 200
has "it is cancelled" '.status == "cancelled"'
call admin2 POST "/import/runs/$CID/finalize"; eq "a cancelled import cannot be finalized" 400
call admin GET "/requests?limit=100"
has "cancelling added nothing" '[.requests[] | select(.title | endswith($s))] | length == 2' --arg s "$STAMP"

echo
if [ "$FAILS" -eq 0 ]; then echo "All import checks passed."; else echo "$FAILS check(s) failed."; fi
exit "$FAILS"
