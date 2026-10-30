#!/usr/bin/env bash
# Nextcloud Forms -> organizer desk, end to end, plus facilitators working their chapter's rows.
#
# Needs the Forms app enabled on the dev server (dev-native.sh installs it for NC 30-33 only if you add it;
# see README). Creates a form through Forms' own API, submits answers as members, then checks that NextTime
# imports them once, names their origin in the row ID, hides contact details from facilitators, rejects a bad
# one with a reason, and lets the chapter's facilitator assign and publish the row.
#
# Usage:  scripts/intake-smoke.sh [BASE_URL]
# Exit code is the number of failed checks (0 = all good).

set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
BASE="${1:-${BASE_URL:-http://127.0.0.1:8081}}"
API="$BASE/index.php/apps/timebank/api"
FORMS="$BASE/ocs/v2.php/apps/forms/api/v3"
FAILS=0
TMP="$(mktemp -d)"
cleanup() {
	for pair in "Colorado Facilitators|bob" "Washington Facilitators|carol" "National Organizers|alice"; do
		scripts/dev-native.sh occ group:removeuser "${pair%%|*}" "${pair##*|}" >/dev/null 2>&1 || true
	done
	# Leave nothing switched on that would keep importing in the background.
	call admin PUT /intake/forms '{"forms":[]}' >/dev/null 2>&1 || true
	rm -rf "$TMP"
}
trap cleanup EXIT

command -v jq >/dev/null || { echo "jq is required"; exit 99; }
pass() { printf '  \033[32mPASS\033[0m %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; FAILS=$((FAILS + 1)); }
occ() { scripts/dev-native.sh occ "$@" >/dev/null 2>&1 || true; }
pw() { case "$1" in admin) echo admin ;; *) echo "$1-pass-123" ;; esac; }

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
	BODY="$(cat "$TMP/body")"
}
forms() { # forms USER METHOD PATH [BODY]
	local args=(-s -u "$1:$(pw "$1")" -H 'OCS-APIRequest: true' -H 'Accept: application/json' -H 'Content-Type: application/json' -X "$2")
	[ -n "${4:-}" ] && args+=(-d "$4")
	curl "${args[@]}" "$FORMS$3"
}
expect() { if [ "$STATUS" = "$2" ]; then pass "$1 ($STATUS)"; else fail "$1 - expected $2, got $STATUS: $(head -c 300 <<<"$BODY")"; fi; }

echo "NextTime Forms intake smoke test against $BASE"

echo "- Set up a form and chapters"
FORM="$(forms admin POST /forms '{}' | jq -r '.ocs.data.id // empty')"
[ -n "$FORM" ] && pass "a form was created through the Forms API ($FORM)" || { fail "Forms is not available (enable the forms app)"; exit "$FAILS"; }
forms admin PATCH "/forms/$FORM" '{"keyValuePairs":{"title":"Needs and offers (smoke)","access":{"permitAllUsers":true,"showToAllUsers":true}}}' >/dev/null
declare -A Q
for t in "Need or offer" "Category" "Summary" "ZIP" "Urgency" "Contact route" "requester_contact" "Favourite colour"; do
	Q[$t]="$(forms admin POST "/forms/$FORM/questions" "{\"type\":\"short\",\"text\":\"$t\"}" | jq -r '.ocs.data.id')"
done
for g in "Colorado" "Colorado Facilitators" "Washington" "Washington Facilitators"; do occ group:add "$g"; done
occ group:add "National Organizers"
occ group:adduser "National Organizers" alice
occ group:adduser "Colorado Facilitators" bob
occ group:adduser "Washington Facilitators" carol

echo "- Choosing the form"
call carol GET /intake; expect "members cannot open intake" 403
call admin GET /intake; expect "admin opens intake" 200
[ "$(jq -r '.forms | length' <<<"$BODY")" = 0 ] && pass "no form is listed until someone adds it by its link" || fail "forms listed unasked: $BODY"
HASH="$(forms admin GET "/forms/$FORM" | jq -r '.ocs.data.hash')"
HOST="${BASE#*://}"
call carol POST /intake/forms/lookup "{\"link\":\"$BASE/index.php/apps/forms/s/$HASH\"}"; expect "members cannot look a form up" 403
call admin POST /intake/forms/lookup '{"link":"my needs form"}'; expect "something that is not a link is refused" 400
grep -q "AbCdEf123456" <<<"$BODY" && pass "and the message shows what to paste" || fail "no example in: $BODY"
call admin POST /intake/forms/lookup '{"link":"https://docs.google.com/forms/d/e/1FAIpQLSdxyz/viewform"}'; expect "a link on another server is refused" 400
grep -q "docs.google.com" <<<"$BODY" && pass "and the message names that server" || fail "unclear: $BODY"
call admin POST /intake/forms/lookup "{\"link\":\"$BASE/index.php/apps/forms/s/ZZZZZZZZZZZZZZZZ\"}"; expect "a code no form has is refused" 400
call admin POST /intake/forms/lookup "{\"link\":\"$BASE/index.php/apps/files/\"}"; expect "a link that is not to a form is refused" 400
call admin POST /intake/forms/lookup "{\"link\":\"$BASE/index.php/apps/forms/s/$HASH\"}"; expect "the share link finds the form" 200
[ "$(jq -r '.id' <<<"$BODY")" = "$FORM" ] && pass "and it is the right form" || fail "wrong form: $BODY"
[ "$(jq -r '[.preview.mapped[].field] | sort | join(",")' <<<"$BODY")" = "category,contact_route,need_or_offer,requester_contact,summary,urgency,zip" ] && pass "questions map to fields by their text or name" || fail "mapping: $BODY"
[ "$(jq -r '.preview.ignored | join(",")' <<<"$BODY")" = "Favourite colour" ] && pass "an unrelated question is ignored" || fail "ignored: $BODY"
[ "$(jq -r '.preview.missing | length' <<<"$BODY")" = 0 ] && pass "nothing required is missing" || fail "missing: $BODY"
call admin POST /intake/forms/lookup "{\"link\":\"$BASE/index.php/apps/forms/$HASH/edit\"}"; expect "the form's own address (with /edit) works too" 200
call admin POST /intake/forms/lookup "{\"link\":\"$HASH\"}"; expect "and so does the bare code" 200
call admin PUT /intake/forms "{\"forms\":[{\"formId\":$FORM,\"layer\":\"needs_offers\"}]}"; expect "admin adds the form" 200
call admin GET /intake
[ "$(jq -r --argjson f "$FORM" '[.forms[] | select(.formId==$f and .exists==true)] | length' <<<"$BODY")" = 1 ] && pass "the added form is listed with its title" || fail "not listed: $BODY"
call admin PUT /intake/forms '{"forms":[{"formId":999999,"layer":"needs_offers"}]}'; expect "a form that does not exist is refused" 400
call admin PUT /intake/forms "{\"forms\":[{\"formId\":$FORM,\"layer\":\"needs_offers\"}]}" >/dev/null

echo "- Two phone submissions"
sub() { # sub USER JSON_ANSWERS
	forms "$1" POST "/forms/$FORM/submissions" "{\"answers\":$2}" | jq -r '.ocs.meta.status'
}
GOOD="{\"${Q[Need or offer]}\":[\"Need\"],\"${Q[Category]}\":[\"Transportation\"],\"${Q[Summary]}\":[\"Ride to dialysis on Tuesdays\"],\"${Q[ZIP]}\":[\"80202\"],\"${Q[Urgency]}\":[\"This week\"],\"${Q[Contact route]}\":[\"Chapter organizer\"],\"${Q[requester_contact]}\":[\"text 555-0100 after 5\"],\"${Q[Favourite colour]}\":[\"Teal\"]}"
BAD="{\"${Q[Need or offer]}\":[\"Offer\"],\"${Q[Category]}\":[\"Gold\"],\"${Q[Summary]}\":[\"Bad category on purpose\"],\"${Q[ZIP]}\":[\"80202\"],\"${Q[Urgency]}\":[\"Ongoing\"],\"${Q[Contact route]}\":[\"In person\"]}"
[ "$(sub carol "$GOOD")" = ok ] && pass "carol submits a good form" || fail "good submission failed"
[ "$(sub bob "$BAD")" = ok ] && pass "bob submits one with an unknown category" || fail "bad submission failed"

call admin POST /intake/run; expect "Check now" 200
[ "$(jq -r '.imported' <<<"$BODY")" = 1 ] && [ "$(jq -r '.rejected' <<<"$BODY")" = 1 ] && pass "one imported, one rejected" || fail "result: $BODY"
call admin POST /intake/run
[ "$(jq -r '.imported' <<<"$BODY")" = 0 ] && [ "$(jq -r '.rejected' <<<"$BODY")" = 0 ] && pass "checking again imports nothing twice" || fail "second run: $BODY"
call admin GET /intake
[ "$(jq -r --argjson f "$FORM" '[.recent[] | select(.formId==$f and .status=="rejected" and (.reason|test("category")))] | length' <<<"$BODY")" = 1 ] && pass "the rejection says why" || fail "log: $BODY"

echo "- The row on the desk"
call alice GET /layers/needs_offers/rows
ROW="$(jq -c --arg u "NO-F$FORM-" '[.[] | select(.fields.uid | startswith($u))][0]' <<<"$BODY")"
[ "$ROW" != null ] && pass "the organizer sees the form row, ID starts NO-F$FORM-" || fail "no row: $BODY"
ROWID="$(jq -r '.id' <<<"$ROW")"
[ "$(jq -r '.source' <<<"$ROW")" = form ] && [ "$(jq -r '.fields.category' <<<"$ROW")" = transport ] && pass "source is form and 'Transportation' became transport" || fail "row: $ROW"
[ "$(jq -r '.fields.requester_contact' <<<"$ROW")" = "text 555-0100 after 5" ] && pass "the organizer sees the contact detail" || fail "contact missing: $ROW"
[ "$(jq -r '.chapter' <<<"$ROW")" = colorado ] && pass "ZIP 80202 put it in the Colorado chapter" || fail "chapter: $ROW"
[ "$(jq -r '.fields | has("favourite_colour")' <<<"$ROW")" = false ] && [[ "$ROW" != *Teal* ]] && pass "an unrelated answer was not stored" || fail "stray answer stored"

call bob GET /layers/needs_offers/rows
[ "$(jq -r --argjson r "$ROWID" '[.[] | select(.id==$r)] | length' <<<"$BODY")" = 1 ] && pass "the Colorado facilitator sees the row" || fail "bob's rows: $BODY"
[ "$(jq -r --argjson r "$ROWID" '[.[] | select(.id==$r)][0].fields | has("requester_contact")' <<<"$BODY")" = false ] && pass "but not the contact detail" || fail "facilitator sees contact"
call carol GET /layers/needs_offers/rows
[ "$(jq -r --argjson r "$ROWID" '[.[] | select(.id==$r)] | length' <<<"$BODY")" = 0 ] && pass "a Washington facilitator does not see it" || fail "carol sees it"

echo "- A facilitator works their own chapter's row"
call carol POST "/layers/needs_offers/rows/$ROWID/assign"; expect "the Washington facilitator cannot take it" 403
call bob POST "/layers/needs_offers/rows/$ROWID/assign"; expect "the Colorado facilitator assigns it to themselves" 200
call bob POST "/layers/needs_offers/rows/$ROWID/status" '{"status":"in_progress"}'; expect "and sets its status" 200
call bob POST "/layers/needs_offers/rows/$ROWID/publish" '{"publish":"yes"}'; expect "and publishes it" 200
call carol POST "/layers/needs_offers/rows/$ROWID/publish" '{"publish":"no"}'; expect "another chapter's facilitator cannot unpublish it" 403
call alice POST "/layers/needs_offers/rows/$ROWID/assign"; expect "an organizer cannot take a row someone holds" 409
occ group:removeuser "Colorado Facilitators" bob
call bob POST "/layers/needs_offers/rows/$ROWID/status" '{"status":"done"}'; expect "once removed from the facilitator group, bob can no longer change it" 403
call alice DELETE "/layers/needs_offers/rows/$ROWID/assign"; expect "an organizer can release a row its holder can no longer work" 200
call admin GET "/governance?limit=100"
[ "$(jq -r '[.entries[] | select(.action=="setting.forms_intake")] | length' <<<"$BODY")" -ge 1 ] && pass "choosing a form is in the public log" || fail "not logged"

echo
if [ "$FAILS" -eq 0 ]; then echo "All intake checks passed."; else echo "$FAILS check(s) failed."; fi
exit "$FAILS"
