#!/usr/bin/env bash
# End-to-end API smoke test for NextTime against a running Nextcloud.
#
# Walks the core lifecycle using the same URLs the Vue frontend calls
# (/index.php/apps/timebank/api/...):
#   categories -> alice posts a request -> bob volunteers -> alice accepts
#   -> alice completes -> bob claims hours -> admin approves -> balances
#   -> carol claims hours -> admin sends to vote -> 3 votes resolve it
#   -> ledger shows the transactions
#
# Usage:  scripts/smoke-test.sh [BASE_URL]
#   BASE_URL defaults to http://127.0.0.1:8081 (scripts/dev-native.sh).
#   For the Docker stack use http://localhost:8080.
# Expects users admin/admin, alice, bob, carol (created by dev-native.sh setup;
# for Docker run: make docker-seed-users, or create them with occ user:add).
#
# Exit code is the number of failed checks (0 = all good).

set -uo pipefail

BASE="${1:-${BASE_URL:-http://127.0.0.1:8081}}"
API="$BASE/index.php/apps/timebank/api"
FAILS=0
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

command -v jq >/dev/null || { echo "jq is required (apt install jq / brew install jq)"; exit 99; }

pass() { printf '  \033[32mPASS\033[0m %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; FAILS=$((FAILS + 1)); }

# call USER METHOD PATH [JSON_BODY] -> body in $TMP/body, status in $STATUS
call() {
	local user="$1" method="$2" path="$3" body="${4:-}"
	local pass
	case "$user" in admin) pass="admin" ;; *) pass="${user}-pass-123" ;; esac
	# Nextcloud < 30 ignores the OCS-APIRequest header for CSRF, so keep a cookie session
	# per user and send its request token. Newer versions accept it too.
	local jar="$TMP/jar-$user"
	if [ ! -s "$TMP/token-$user" ]; then
		curl -s -c "$jar" -u "$user:$pass" -H 'OCS-APIRequest: true' "$BASE/index.php/csrftoken" | jq -r '.token // empty' >"$TMP/token-$user"
	fi
	local args=(-s -o "$TMP/body" -w '%{http_code}' -u "$user:$pass" -b "$jar" -c "$jar"
		-H "requesttoken: $(cat "$TMP/token-$user")"
		-H 'OCS-APIRequest: true' -H 'Accept: application/json' -X "$method")
	[ -n "$body" ] && args+=(-H 'Content-Type: application/json' -d "$body")
	STATUS="$(curl "${args[@]}" "$API$path")"
	BODY="$(cat "$TMP/body")"
}

expect() { # expect LABEL CODE
	if [ "$STATUS" = "$2" ]; then pass "$1 ($STATUS)"; else fail "$1 - expected $2, got $STATUS: $(head -c 300 <<<"$BODY")"; fi
}

json() { jq -r "$1" <<<"$BODY" 2>/dev/null; }

echo "NextTime smoke test against $BASE"

echo "- Reachability"
code="$(curl -s -o /dev/null -w '%{http_code}' "$BASE/status.php")"
[ "$code" = 200 ] && pass "status.php ($code)" || { fail "status.php returned $code - is the server running?"; exit "$FAILS"; }

echo "- Categories"
call alice GET /categories
expect "list categories" 200
CAT_ID="$(json '.[0].id')"
[ -n "$CAT_ID" ] && [ "$CAT_ID" != null ] && pass "categories are seeded (first id $CAT_ID)" || fail "no categories seeded - run occ maintenance:repair"

echo "- Request lifecycle"
call alice POST /requests "{\"title\":\"Smoke test: fix porch step\",\"description\":\"Loose board on the porch step needs a few screws.\",\"categoryId\":$CAT_ID,\"hoursBudget\":2,\"priority\":\"normal\"}"
expect "alice creates request" 201
REQ_ID="$(json '.id')"

call alice GET /requests
expect "list requests" 200

call alice GET "/requests/$REQ_ID"
expect "show request $REQ_ID" 200

call alice GET /requests/my
expect "alice's own requests (/requests/my, must not be captured by /requests/{id})" 200

call bob POST "/requests/$REQ_ID/volunteer" '{"proposedHours":2,"message":"I have a drill, happy to help."}'
expect "bob volunteers" 201
VOL_ID="$(json '.id')"

call bob GET /volunteers/my
expect "bob's offers" 200

call alice POST "/requests/$REQ_ID/comments" '{"comment":"Thanks Bob! Saturday works."}'
expect "alice comments" 201

call alice POST "/volunteers/$VOL_ID/accept"
expect "alice accepts bob's offer" 200

call alice POST "/requests/$REQ_ID/complete" "{\"volunteerId\":$VOL_ID}"
expect "alice completes request" 200

echo "- Earnings: admin approval path"
call bob POST /earnings/claim "{\"categoryId\":$CAT_ID,\"hoursClaimed\":2,\"description\":\"Fixed alice's porch step (smoke test).\"}"
expect "bob claims 2 hours" 201
EARN_BOB="$(json '.id')"

call bob GET /earnings/my
expect "bob's claims" 200

call bob GET /earnings/pending
[ "$STATUS" = 403 ] && pass "non-admin blocked from pending queue (403)" || fail "non-admin pending queue returned $STATUS (expected 403)"

call admin GET /earnings/pending
expect "admin pending queue" 200

call admin POST "/earnings/$EARN_BOB/approve"
expect "admin approves bob's claim" 200

call bob GET /balance/my
expect "bob's balance" 200
BAL="$(json '.balance')"
awk "BEGIN{exit !($BAL > 0)}" 2>/dev/null && pass "bob's balance is positive ($BAL)" || fail "bob's balance not credited ($BODY)"

echo "- Earnings: community vote path"
call carol POST /earnings/claim "{\"categoryId\":$CAT_ID,\"hoursClaimed\":1,\"description\":\"Drove a neighbour to the clinic (smoke test).\"}"
expect "carol claims 1 hour" 201
EARN_CAROL="$(json '.id')"

call admin POST "/earnings/$EARN_CAROL/send-to-vote"
expect "admin sends carol's claim to vote" 200

call alice GET /earnings/voting
expect "voting queue" 200

for voter in alice bob admin; do
	call "$voter" POST "/earnings/$EARN_CAROL/vote" '{"vote":"approve","comment":"Seen it happen."}'
	expect "$voter votes approve" 200
done

call carol GET /earnings/my
STATE="$(jq -r --argjson id "$EARN_CAROL" '.[] | select(.id==$id) | .status' <<<"$BODY" 2>/dev/null)"
[ "$STATE" = approved ] && pass "3 votes resolved carol's claim (status=approved)" || fail "carol's claim status after 3 votes is '$STATE'"

echo "- Ledger"
call alice GET /ledger
expect "public ledger" 200
N="$(json 'length')"
[ "${N:-0}" -ge 2 ] 2>/dev/null && pass "ledger has $N transactions" || fail "ledger has ${N:-?} transactions (expected >= 2)"

call bob GET /ledger/my
expect "bob's transactions" 200

echo "- Frontend page"
code="$(curl -s -o "$TMP/page" -w '%{http_code}' -u alice:alice-pass-123 "$BASE/index.php/apps/timebank/")"
[ "$code" = 200 ] && grep -q 'timebank-main' "$TMP/page" && pass "app page loads and references built JS" || fail "app page: $code (is js/ built?)"

echo
if [ "$FAILS" -eq 0 ]; then echo "All checks passed."; else echo "$FAILS check(s) failed."; fi
exit "$FAILS"
