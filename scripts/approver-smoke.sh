#!/usr/bin/env bash
# Who may decide on a claim: chapter facilitators (both groups), national organizers, admins.
# Also checks the in-app notification. Uses occ to set up groups, so it needs the dev server.
#
# Dev users as roles: bob is a member of the Oregon group; carol is in "Oregon Facilitators" only (the
# facilitator group on its own is enough); alice is in no group at all; admin is an admin. Chapters are
# found from the group names. Groups are removed again at the end.
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
	for pair in "Oregon|bob" "Oregon Facilitators|carol" "National Organizers|bob" "National Organizers|alice" "GSUS|alice"; do
		scripts/dev-native.sh occ group:removeuser "${pair%%|*}" "${pair##*|}" >/dev/null 2>&1 || true
	done
	# Voters made for the adjustment vote; deleting them also drops their group memberships.
	for u in dave erin frank; do scripts/dev-native.sh occ user:delete "$u" >/dev/null 2>&1 || true; done
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
for g in "Oregon" "Oregon Facilitators" "National Organizers" "GSUS"; do occ group:add "$g"; done
occ group:adduser "Oregon" bob
occ group:adduser "Oregon Facilitators" carol

call alice GET /categories; CAT_ID="$(jq -r '.[0].id' <<<"$BODY")"

echo "- A member of a chapter with a facilitator"
call bob POST /earnings/claim "{\"categoryId\":$CAT_ID,\"hoursClaimed\":1,\"description\":\"Approver smoke: bob's claim\"}"
expect "bob submits a claim" 201
BOB_CLAIM="$(jq -r '.id' <<<"$BODY")"

call alice GET /earnings/pending
[ "$(jq -r --argjson c "$BOB_CLAIM" '[.[] | select(.id==$c)][0].canDecide' <<<"$BODY")" = false ] && pass "someone in no group sees bob's claim, view only" || fail "alice's list: $BODY"
call alice POST "/earnings/$BOB_CLAIM/approve"
expect "someone in no group cannot approve" 403
call carol GET /earnings/pending
[ "$(jq -r --argjson c "$BOB_CLAIM" '[.[] | select(.id==$c)][0].decidingAs' <<<"$BODY")" = facilitator ] && pass "carol (Oregon Facilitators only) sees it, as a facilitator" || fail "carol's list: $BODY"
call bob POST "/earnings/$BOB_CLAIM/send-to-vote"
expect "belonging to Oregon gives bob no powers" 403
call bob GET /earnings/pending
[ "$(jq -r '[.[] | select(.userId=="bob" and .canDecide)] | length' <<<"$BODY")" = 0 ] && pass "bob may decide none of his own claims" || fail "bob's list: $BODY"
call bob POST "/earnings/$BOB_CLAIM/approve"
expect "bob cannot approve his own claim" 403

echo "- Notification"
N="$(ocs carol)"
jq -e --arg c "$BOB_CLAIM" '[.ocs.data[] | select(.object_type=="claim" and .object_id==$c)] | length == 1' <<<"$N" >/dev/null 2>&1 && pass "carol was told about bob's claim" || fail "no notification for carol: $(head -c 300 <<<"$N")"
jq -e --arg c "$BOB_CLAIM" '[.ocs.data[] | select(.object_id==$c) | .subject] | all(contains("Approver smoke") | not)' <<<"$N" >/dev/null 2>&1 && pass "the description is not in the notification" || fail "description leaked into the notification"
jq -e --arg c "$BOB_CLAIM" '[(.ocs.data // [])[] | select(.object_id==$c)] | length == 0' <<<"$(ocs bob)" >/dev/null 2>&1 && pass "bob is not told about his own claim" || fail "bob was notified"
jq -e --arg c "$BOB_CLAIM" '[(.ocs.data // [])[] | select(.object_id==$c)] | length == 0' <<<"$(ocs alice)" >/dev/null 2>&1 && pass "someone in no group is not told" || fail "alice was notified"

call carol POST "/earnings/$BOB_CLAIM/approve"
expect "carol approves bob's claim" 200
jq -e --arg c "$BOB_CLAIM" '[(.ocs.data // [])[] | select(.object_id==$c)] | length == 0' <<<"$(ocs carol)" >/dev/null 2>&1 && pass "the notification is cleared once decided" || fail "notification still there"
call carol POST "/earnings/$BOB_CLAIM/approve"
expect "a decided claim cannot be decided again" 400

echo "- A member with no chapter: national organizers"
call alice POST /earnings/claim "{\"categoryId\":$CAT_ID,\"hoursClaimed\":1,\"description\":\"Approver smoke: alice has no chapter\"}"
expect "alice (no chapter) submits a claim" 201
ALICE_CLAIM="$(jq -r '.id' <<<"$BODY")"
call carol POST "/earnings/$ALICE_CLAIM/approve"
expect "the Oregon facilitator cannot decide for someone with no chapter" 403
call bob POST "/earnings/$ALICE_CLAIM/approve"
expect "a plain member cannot" 403
occ group:adduser "National Organizers" bob
call bob GET /roles
[ "$(jq -r '.nationalOrganizer' <<<"$BODY")" = true ] && pass "/api/roles reports bob as a national organizer" || fail "bob's roles: $BODY"
call bob GET /earnings/pending
[ "$(jq -r --argjson c "$ALICE_CLAIM" '[.[] | select(.id==$c)][0].decidingAs' <<<"$BODY")" = national ] && pass "once a national organizer, bob sees alice's claim" || fail "bob's list: $BODY"
call bob POST "/earnings/$ALICE_CLAIM/send-to-vote"
expect "a national organizer can send it to a vote" 200

echo "- National organizers act nationwide, but never on their own claim"
call bob POST /earnings/claim "{\"categoryId\":$CAT_ID,\"hoursClaimed\":1,\"description\":\"Approver smoke: bob again\"}"
BOB2="$(jq -r '.id' <<<"$BODY")"
call bob POST "/earnings/$BOB2/approve"
expect "a national organizer cannot decide on their own claim" 403
occ group:removeuser "National Organizers" bob
occ group:adduser GSUS alice
call alice GET /roles
[ "$(jq -r '.nationalOrganizer' <<<"$BODY")" = true ] && pass "the legacy GSUS group still counts as national organizer" || fail "alice's roles: $BODY"
call alice POST "/earnings/$BOB2/approve"
expect "so a GSUS member decides for a member whose chapter has a facilitator" 200
call admin GET /chapters/check
[ "$(jq -r '[.nationalOrganizers.legacyOnly[] | select(.group=="GSUS") | .users[]] | index("alice") != null' <<<"$BODY")" = true ] && pass "the check page lists alice as still only in GSUS" || fail "check page: $(head -c 300 <<<"$BODY")"
occ group:removeuser GSUS alice

echo "- Losing the facilitator role takes the ability away at once"
call bob POST /earnings/claim "{\"categoryId\":$CAT_ID,\"hoursClaimed\":1,\"description\":\"Approver smoke: bob, facilitator removed\"}"
BOB3="$(jq -r '.id' <<<"$BODY")"
call carol GET /earnings/pending
[ "$(jq -r --argjson c "$BOB3" '[.[] | select(.id==$c)][0].canDecide' <<<"$BODY")" = true ] && pass "carol may decide the new claim" || fail "carol's list: $BODY"
occ group:removeuser "Oregon Facilitators" carol
call carol POST "/earnings/$BOB3/approve"
expect "once removed from the facilitator group carol can no longer approve" 403
call carol GET /earnings/pending
[ "$(jq -r --argjson c "$BOB3" '[.[] | select(.id==$c)][0].canDecide' <<<"$BODY")" = false ] && pass "and it is view only for her now" || fail "carol may still decide it: $BODY"
call admin POST "/earnings/$BOB3/reject" '{"reason":"Approver smoke: cleaning up this claim"}'
expect "an admin can still settle it" 200

echo "- Adjustments by facilitators and votes (security plan Phase 4)"
# Oregon: bob (the member adjusted), dave, erin, frank (voters); carol facilitates. alice is a national organizer.
for u in dave erin frank; do
	OC_PASS="$u-pass-123" scripts/dev-native.sh occ user:add --password-from-env "$u" >/dev/null 2>&1 || true
	occ group:adduser Oregon "$u"
done
occ group:adduser "Oregon Facilitators" carol
call carol POST /adjustments '{"userId":"alice","hours":3,"reason":"Approver smoke: not in her state"}'
expect "a facilitator cannot propose for someone outside her state" 403
call carol POST /adjustments '{"userId":"bob","hours":3,"reason":"Approver smoke: a small adjustment"}'
expect "a facilitator proposes a small adjustment for a member of her state" 201
SMALL="$(jq -r '.id' <<<"$BODY")"
[ "$(jq -r '.status' <<<"$BODY")" = pending ] && pass "it waits for a second person, not applied at once" || fail "small: $BODY"
call carol POST "/adjustments/$SMALL/approve"
expect "the proposer cannot approve it" 403
call dave POST "/adjustments/$SMALL/approve"
expect "a chapter member cannot approve it alone" 403
occ group:adduser "National Organizers" alice
call alice POST "/adjustments/$SMALL/approve"
expect "a national organizer approves it" 200

call carol POST /adjustments '{"userId":"bob","hours":10,"reason":"Approver smoke: a large adjustment"}'
expect "a facilitator proposes a large adjustment" 201
BIG="$(jq -r '.id' <<<"$BODY")"
[ "$(jq -r '.status' <<<"$BODY")" = voting ] && pass "it opens a vote of Oregon's chapter members" || fail "big: $BODY"
call alice POST "/adjustments/$BIG/approve"
expect "nobody approves a vote alone, not even a national organizer" 400
call alice POST "/adjustments/$BIG/vote" '{"vote":"approve"}'
expect "a national organizer outside Oregon does not vote on an Oregon member" 403
call carol POST "/adjustments/$BIG/vote" '{"vote":"approve"}'
expect "the proposer does not vote" 403
call bob POST "/adjustments/$BIG/vote" '{"vote":"approve"}'
expect "the member adjusted does not vote" 403
call dave GET /adjustments
[ "$(jq -r --argjson id "$BIG" '[.adjustments[] | select(.id==$id)][0] | "\(.canVote) \(.vote.eligible)"' <<<"$BODY")" = "true 3" ] \
	&& pass "dave, a chapter member, sees the vote and may vote (3 eligible)" || fail "dave's list: $(head -c 400 <<<"$BODY")"
call dave POST "/adjustments/$BIG/vote" '{"vote":"approve"}'
expect "dave votes for" 200
call dave POST "/adjustments/$BIG/vote" '{"vote":"reject"}'
expect "one vote each" 400
call erin POST "/adjustments/$BIG/vote" '{"vote":"approve"}'
expect "erin votes for" 200
[ "$(jq -r '.status' <<<"$BODY")" = voting ] && pass "two votes do not decide it" || fail "after two votes: $BODY"
call frank POST "/adjustments/$BIG/vote" '{"vote":"reject"}'
expect "frank votes against" 200
[ "$(jq -r '.status' <<<"$BODY")" = approved ] && pass "everyone eligible voted, 2 to 1: applied" || fail "after three votes: $BODY"
call bob GET /ledger/my
jq -e '.transactions[0].description | contains("proposed by carol, approved by community vote (for: dave, erin; against: frank)")' <<<"$BODY" >/dev/null \
	&& pass "the ledger names the proposer and every voter" || fail "bob's ledger: $(head -c 400 <<<"$BODY")"
call carol GET "/governance?limit=30"
jq -e '[.entries[] | select(.action=="adjustment.vote")] | length >= 3' <<<"$BODY" >/dev/null && pass "each vote is in the public governance log" || fail "governance log: $(head -c 300 <<<"$BODY")"
occ group:removeuser "National Organizers" alice
occ group:removeuser "Oregon Facilitators" carol

echo "- Chapter check page"
call carol GET /chapters/check
expect "every member opens the chapter check" 200
[ "$(jq -r '.canManage' <<<"$BODY")" = false ] && pass "but is not shown the set-up fixes" || fail "carol's check page: $(head -c 200 <<<"$BODY")"
call admin GET /chapters/check
expect "admin opens the chapter check" 200
[ "$(jq -r '.canManage' <<<"$BODY")" = true ] && pass "and may fix what it reports" || fail "admin's check page: $(head -c 200 <<<"$BODY")"
[ "$(jq -r '[.chapters[] | select(.slug=="oregon")] | length' <<<"$BODY")" = 1 ] && pass "Oregon was found from the group names" || fail "Oregon missing: $BODY"

echo
if [ "$FAILS" -eq 0 ]; then echo "All approver checks passed."; else echo "$FAILS check(s) failed."; fi
exit "$FAILS"
