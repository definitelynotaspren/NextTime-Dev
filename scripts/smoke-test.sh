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

echo "- Lifecycle rules: several volunteers"
call alice POST /requests "{\"title\":\"Smoke test: move a couch\",\"description\":\"Three people to carry a couch upstairs.\",\"categoryId\":$CAT_ID,\"hoursBudget\":4}"
expect "alice posts a second request" 201
COUCH_ID="$(json '.id')"

call bob POST "/requests/$COUCH_ID/volunteer" '{"proposedHours":2}'
expect "bob offers" 201
V_BOB="$(json '.id')"
call carol POST "/requests/$COUCH_ID/volunteer" '{"proposedHours":3}'
expect "carol also offers on the same request" 201
V_CAROL="$(json '.id')"

call alice POST "/volunteers/$V_BOB/accept"
expect "alice accepts bob" 200
call alice GET "/requests/$COUCH_ID"
[ "$(json '.request.status')" = in_progress ] && pass "the first acceptance moves the request in progress" || fail "request status is '$(json '.request.status')', expected in_progress"

call admin POST "/requests/$COUCH_ID/volunteer" '{"proposedHours":1}'
expect "someone can still offer while the request is in progress" 201
call alice POST "/volunteers/$V_CAROL/accept"
expect "alice accepts carol too" 200
call alice GET "/requests/$COUCH_ID"
[ "$(jq -r '[.volunteers[] | select(.status=="accepted")] | length' <<<"$BODY")" = 2 ] && pass "both accepted volunteers stay accepted (no one is auto-declined)" || fail "expected 2 accepted volunteers: $BODY"

echo "- Lifecycle rules: integrity"
call bob POST /requests "{\"title\":\"Smoke test: other request\",\"description\":\"Owned by bob, to check offers cannot cross requests.\",\"categoryId\":$CAT_ID,\"hoursBudget\":1}"
expect "bob posts his own request" 201
OTHER_ID="$(json '.id')"
[ -n "$OTHER_ID" ] && [ "$OTHER_ID" != null ] || { fail "could not create the second request; skipping the integrity checks"; OTHER_ID=0; }
call carol POST "/requests/$OTHER_ID/volunteer" '{"proposedHours":1}'
expect "carol offers on bob's request" 201
V_FOREIGN="$(json '.id')"
call alice POST "/requests/$COUCH_ID/complete" "{\"volunteerId\":$V_FOREIGN}"
expect "completing with another request's volunteer is refused" 400
call bob GET "/requests/$OTHER_ID"
[ "$(jq -r '.volunteers[0].status' <<<"$BODY")" = offered ] && pass "the other request's offer was left untouched" || fail "other offer status: $BODY"
call carol POST "/volunteers/$V_FOREIGN/accept"
expect "only the requester can accept (carol cannot accept her own offer)" 403

call alice POST "/requests/$COUCH_ID/complete"
expect "completing finishes every accepted volunteer" 200
call alice GET "/requests/$COUCH_ID"
[ "$(jq -r '[.volunteers[] | select(.status=="completed")] | length' <<<"$BODY")" = 2 ] && pass "bob and carol are both completed" || fail "expected 2 completed volunteers: $BODY"
call alice POST "/requests/$COUCH_ID/cancel"
expect "a completed request cannot be cancelled" 400
call alice POST "/requests/$COUCH_ID/complete"
expect "a completed request cannot be completed again" 400
call alice PUT "/requests/$COUCH_ID" '{"title":"Edited after completion"}'
expect "a finished request cannot be edited" 400

echo "- Lifecycle rules: withdrawing and the log"
call alice POST /requests "{\"title\":\"Smoke test: withdraw\",\"description\":\"For the withdrawal log.\",\"categoryId\":$CAT_ID,\"hoursBudget\":2}"
expect "alice posts a third request" 201
WD_ID="$(json '.id')"
call bob POST "/requests/$WD_ID/volunteer" '{"proposedHours":2}'
expect "bob offers" 201
V_WD="$(json '.id')"
call alice POST "/volunteers/$V_WD/accept"
expect "alice accepts bob" 200
call carol DELETE "/volunteers/$V_WD"
expect "nobody else can withdraw bob's offer" 403
call bob DELETE "/volunteers/$V_WD"
expect "withdrawing accepted help without a reason is refused" 400
call bob DELETE "/volunteers/$V_WD" '{"reason":"Family emergency"}'
expect "withdrawing accepted help with a reason" 200
call alice GET "/requests/$WD_ID"
[ "$(json '.request.status')" = open ] && pass "the request reopens when no accepted volunteers remain" || fail "request status is '$(json '.request.status')', expected open"
call bob GET /withdrawals/my
expect "bob reads his log of withdrawn help" 200
[ "$(jq -r --argjson r "$WD_ID" '[.[] | select(.requestId==$r and .reason=="Family emergency")] | length' <<<"$BODY")" = 1 ] && pass "the withdrawal and its reason are logged" || fail "withdrawal not in the log: $BODY"
call bob POST "/requests/$WD_ID/volunteer" '{"proposedHours":2}'
expect "bob can offer again after withdrawing" 201

echo "- Self-service: editing, my offers, cancelling"
call bob PUT "/requests/$WD_ID" '{"title":"Not bob to edit"}'
expect "only the requester can edit" 403
call alice PUT "/requests/$WD_ID" '{"hoursBudget":5000}'
expect "an edit keeps the posting limits" 400
call alice PUT "/requests/$WD_ID" "{\"title\":\"Smoke test: withdraw (edited)\",\"hoursBudget\":3,\"categoryId\":$CAT_ID,\"priority\":\"high\"}"
expect "alice edits her open request" 200
[ "$(json '.title')" = "Smoke test: withdraw (edited)" ] && [ "$(json '.priority')" = high ] && pass "the edit is saved" || fail "edit not saved: $BODY"
call bob GET /volunteers/my
expect "bob lists his offers" 200
[ "$(jq -r --argjson r "$WD_ID" '[.[] | select(.request.id==$r and .request.title=="Smoke test: withdraw (edited)")] | length' <<<"$BODY")" = 1 ] && pass "each offer carries its request's title and status" || fail "offers lack request summary: $BODY"
call bob POST "/requests/$WD_ID/cancel"
expect "only the requester can cancel" 403
call alice POST "/requests/$WD_ID/cancel"
expect "alice cancels with a pending offer" 200
call bob GET /volunteers/my
[ "$(jq -r --argjson r "$WD_ID" '[.[] | select(.request.id==$r)][0].request.status' <<<"$BODY")" = cancelled ] && pass "bob sees the request is cancelled" || fail "offer still shows old status: $BODY"

echo "- Comments: flagging and moderation"
call alice POST /requests "{\"title\":\"Smoke test: comments\",\"description\":\"Used to try out comment flagging.\",\"categoryId\":$CAT_ID,\"hoursBudget\":1}"
expect "alice posts a request for comments" 201
CM_ID="$(json '.id')"
call alice POST "/requests/$CM_ID/comments" '{"comment":"   "}'
expect "an empty comment is refused" 400
call bob POST "/requests/$CM_ID/comments" '{"comment":"First comment, perfectly polite."}'
expect "bob comments" 201
C1="$(json '.id')"
call bob POST "/requests/$CM_ID/comments" '{"comment":"A second comment to be removed."}'
expect "bob comments again" 201
C2="$(json '.id')"
call bob POST "/comments/$C1/flag" '{"reason":"my own"}'
expect "you cannot flag your own comment" 400
call carol POST "/comments/$C1/flag" '{"reason":"Seems rude"}'
expect "carol flags" 201
call carol POST "/comments/$C1/flag"
expect "a second flag from the same member is refused" 400
call alice GET "/requests/$CM_ID"
[ "$(jq -r --argjson c "$C1" '.comments[] | select(.id==$c) | "\(.state) \(.flagCount)"' <<<"$BODY")" = "visible 1" ] && pass "one flag is counted in public and does not hide it" || fail "unexpected comment view: $BODY"
call alice POST "/comments/$C1/flag" '{"reason":"Not for this thread"}'
expect "alice flags" 201
call admin POST "/comments/$C1/flag" '{"reason":"Agreed"}'
expect "a third distinct flag" 201
call carol GET "/requests/$CM_ID"
[ "$(jq -r --argjson c "$C1" '.comments[] | select(.id==$c) | "\(.state) \(.comment) \(has("flags"))"' <<<"$BODY")" = "hidden null false" ] && pass "3 flags hide the text from members and keep reasons private" || fail "member still sees the comment: $BODY"
call admin GET "/requests/$CM_ID"
[ "$(jq -r --argjson c "$C1" '.comments[] | select(.id==$c) | "\(.state) \(.comment) \(.flags | length)"' <<<"$BODY")" = "hidden First comment, perfectly polite. 3" ] && [ "$(json '.canModerate')" = true ] && pass "moderators still see the text and the three reasons" || fail "moderator view wrong: $BODY"
call carol GET /comments/flagged
expect "members cannot open the moderation list" 403
call admin GET /comments/flagged
expect "admin opens the moderation list" 200
[ "$(jq -r --argjson c "$C1" '[.[] | select(.id==$c)] | length' <<<"$BODY")" = 1 ] && pass "the hidden comment is in the list" || fail "not listed: $BODY"
call carol POST "/comments/$C1/dismiss"
expect "members cannot dismiss flags" 403
call admin POST "/comments/$C1/dismiss"
expect "admin dismisses the flags" 200
call carol GET "/requests/$CM_ID"
[ "$(jq -r --argjson c "$C1" '.comments[] | select(.id==$c) | "\(.state) \(.flagCount)"' <<<"$BODY")" = "visible 0" ] && pass "dismissing restores the comment" || fail "comment not restored: $BODY"
call bob DELETE "/comments/$C2"
expect "authors cannot delete their own comments" 403
call carol DELETE "/comments/$C2"
expect "other members cannot delete comments" 403
call admin DELETE "/comments/$C2"
expect "admin removes a comment" 200
call carol GET "/requests/$CM_ID"
[ "$(jq -r --argjson c "$C2" '.comments[] | select(.id==$c) | "\(.state) \(.comment) \(.removedBy)"' <<<"$BODY")" = "removed null admin" ] && pass "members see it removed, with who removed it" || fail "removed view wrong: $BODY"
call carol POST "/comments/$C2/flag"
expect "a removed comment cannot be flagged" 400

echo "- Frontend page"
code="$(curl -s -o "$TMP/page" -w '%{http_code}' -u alice:alice-pass-123 "$BASE/index.php/apps/timebank/")"
[ "$code" = 200 ] && grep -q 'timebank-main' "$TMP/page" && pass "app page loads and references built JS" || fail "app page: $code (is js/ built?)"

echo
if [ "$FAILS" -eq 0 ]; then echo "All checks passed."; else echo "$FAILS check(s) failed."; fi
exit "$FAILS"
