#!/usr/bin/env bash
# Who may decide on a claim: chapter facilitators (both groups), the fallback group, admins.
# Also checks the in-app notification. Uses occ to set up groups, so it needs the dev server.
#
# Dev users as roles: bob is a member of mt-hood; carol is member and facilitator (both groups);
# alice has only the facilitator group and no chapter; admin is an admin. Groups are removed again at the end.
#
# Usage:  scripts/approver-smoke.sh [BASE_URL]
# Exit code is the number of failed checks (0 = all good).

set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
BASE="${1:-${BASE_URL:-http://127.0.0.1:8081}}"
API="$BASE/index.php/apps/timebank/api"
FAILS=0
TMP="$(mktemp -d)"
cleanup() {
	for g in "chapter-mt-hood bob" "chapter-mt-hood carol" "chapter-mt-hood-facilitator carol" "chapter-mt-hood-facilitator alice" "GSUS bob" "GSUS alice"; do
		scripts/dev-native.sh occ group:removeuser $g >/dev/null 2>&1 || true
	done
	rm -rf "$TMP"
}
trap cleanup EXIT

command -v jq >/dev/null || { echo "jq is required"; exit 99; }

pass() { printf '  \033[32mPASS\033[0m %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; FAILS=$((FAILS + 1)); }
occ() { scripts/dev-native.sh occ "$@" >/dev/null 2>&1 || true; }

call() {
	local user="$1" method="$2" path="$3" body="${4:-}"
	local pw; case "$user" in admin) pw="admin" ;; *) pw="${user}-pass-123" ;; esac
	local jar="$TMP/jar-$user"
	if [ ! -s "$TMP/token-$user" ]; then
		curl -s -c "$jar" -u "$user:$pw" -H 'OCS-APIRequest: true' "$BASE/index.php/csrftoken" | jq -r '.token // empty' >"$TMP/token-$user"
	fi
	local args=(-s -o "$TMP/body" -w '%{http_code}' -u "$user:$pw" -b "$jar" -c "$jar"
		-H "requesttoken: $(cat "$TMP/token-$user")" -H 'OCS-APIRequest: true' -H 'Accept: application/json' -X "$method")
	[ -n "$body" ] && args+=(-H 'Content-Type: application/json' -d "$body")
	STATUS="$(curl "${args[@]}" "$API$path")"
	BODY="$(cat "$TMP/body")"
}
expect() { if [ "$STATUS" = "$2" ]; then pass "$1 ($STATUS)"; else fail "$1 - expected $2, got $STATUS: $(head -c 300 <<<"$BODY")"; fi; }
ocs() { # ocs USER PATH -> OCS notifications, JSON
	local user="$1" pw; case "$user" in admin) pw="admin" ;; *) pw="${user}-pass-123" ;; esac
	curl -s -u "$user:$pw" -H 'OCS-APIRequest: true' -H 'Accept: application/json' "$BASE/ocs/v2.php/apps/notifications/api/v2/notifications"
}

echo "NextTime approver smoke test against $BASE"

echo "- Roles"
for g in chapter-mt-hood chapter-mt-hood-facilitator GSUS; do occ group:add "$g"; done
# bob: member of mt-hood. carol: member and facilitator. alice: facilitator group only.
occ group:adduser chapter-mt-hood bob
occ group:adduser chapter-mt-hood carol
occ group:adduser chapter-mt-hood-facilitator carol
occ group:adduser chapter-mt-hood-facilitator alice

call alice GET /categories; CAT_ID="$(jq -r '.[0].id' <<<"$BODY")"

echo "- A member of a chapter with a facilitator"
call bob POST /earnings/claim "{\"categoryId\":$CAT_ID,\"hoursClaimed\":1,\"description\":\"Approver smoke: bob's claim\"}"
expect "bob submits a claim" 201
BOB_CLAIM="$(jq -r '.id' <<<"$BODY")"

call alice GET /earnings/pending
[ "$(jq -r --argjson c "$BOB_CLAIM" '[.[] | select(.id==$c)] | length' <<<"$BODY")" = 0 ] && pass "the facilitator group alone does not show bob's claim" || fail "alice sees bob's claim: $BODY"
call alice POST "/earnings/$BOB_CLAIM/approve"
expect "the facilitator group alone cannot approve" 403
call carol GET /earnings/pending
[ "$(jq -r --argjson c "$BOB_CLAIM" '[.[] | select(.id==$c)][0].decidingAs' <<<"$BODY")" = facilitator ] && pass "carol (both groups) sees it, as a facilitator" || fail "carol's list: $BODY"
call bob GET /earnings/pending
[ "$(jq -r '[.[] | select(.userId=="bob")] | length' <<<"$BODY")" = 0 ] && pass "bob sees none of his own claims to decide" || fail "bob's list: $BODY"
call bob POST "/earnings/$BOB_CLAIM/approve"
expect "bob cannot approve his own claim" 403

echo "- Notification"
N="$(ocs carol)"
jq -e --arg c "$BOB_CLAIM" '[.ocs.data[] | select(.object_type=="claim" and .object_id==$c)] | length == 1' <<<"$N" >/dev/null 2>&1 && pass "carol was told about bob's claim" || fail "no notification for carol: $(head -c 300 <<<"$N")"
jq -e --arg c "$BOB_CLAIM" '[.ocs.data[] | select(.object_id==$c) | .subject] | all(contains("Approver smoke") | not)' <<<"$N" >/dev/null 2>&1 && pass "the description is not in the notification" || fail "description leaked into the notification"
jq -e --arg c "$BOB_CLAIM" '[(.ocs.data // [])[] | select(.object_id==$c)] | length == 0' <<<"$(ocs bob)" >/dev/null 2>&1 && pass "bob is not told about his own claim" || fail "bob was notified"
jq -e --arg c "$BOB_CLAIM" '[(.ocs.data // [])[] | select(.object_id==$c)] | length == 0' <<<"$(ocs alice)" >/dev/null 2>&1 && pass "a facilitator-group-only member is not told" || fail "alice was notified"

call carol POST "/earnings/$BOB_CLAIM/approve"
expect "carol approves bob's claim" 200
jq -e --arg c "$BOB_CLAIM" '[(.ocs.data // [])[] | select(.object_id==$c)] | length == 0' <<<"$(ocs carol)" >/dev/null 2>&1 && pass "the notification is cleared once decided" || fail "notification still there"
call carol POST "/earnings/$BOB_CLAIM/approve"
expect "a decided claim cannot be decided again" 400

echo "- A member with no facilitator: the GSUS fallback"
call alice POST /earnings/claim "{\"categoryId\":$CAT_ID,\"hoursClaimed\":1,\"description\":\"Approver smoke: alice has no chapter\"}"
expect "alice (no chapter) submits a claim" 201
ALICE_CLAIM="$(jq -r '.id' <<<"$BODY")"
call carol POST "/earnings/$ALICE_CLAIM/approve"
expect "a facilitator of another member's chapter cannot decide for someone with no chapter" 403
call bob POST "/earnings/$ALICE_CLAIM/approve"
expect "a plain member cannot" 403
occ group:adduser GSUS bob
call bob GET /earnings/pending
[ "$(jq -r --argjson c "$ALICE_CLAIM" '[.[] | select(.id==$c)][0].decidingAs' <<<"$BODY")" = fallback ] && pass "once in GSUS, bob sees alice's claim, as the fallback" || fail "bob's list: $BODY"
call bob POST "/earnings/$ALICE_CLAIM/send-to-vote"
expect "a GSUS member can send it to a vote" 200

echo "- GSUS does not override a chapter that has facilitators"
call bob POST /earnings/claim "{\"categoryId\":$CAT_ID,\"hoursClaimed\":1,\"description\":\"Approver smoke: bob again\"}"
BOB2="$(jq -r '.id' <<<"$BODY")"
occ group:adduser GSUS alice
call alice POST "/earnings/$BOB2/approve"
expect "a GSUS member cannot decide for a member whose chapter has a facilitator" 403
call bob POST "/earnings/$BOB2/approve"
expect "and nobody decides on their own claim" 403
call admin POST "/earnings/$BOB2/approve"
expect "an admin still can" 200

echo
if [ "$FAILS" -eq 0 ]; then echo "All approver checks passed."; else echo "$FAILS check(s) failed."; fi
exit "$FAILS"
