#!/usr/bin/env bash
# End-to-end check of the organizer desk and its PII rules against a running Nextcloud.
#
# Roles used (created here with occ, idempotent):
#   alice  organizer            (group timebank-organizer)
#   bob    facilitator of Oregon (group "Oregon Facilitators" alone is enough; chapters are found from group names)
#   carol  plain member
# Checks that fields marked organizer/chapter never reach people outside those roles,
# that the survey layer is never returned, that the public feed carries only public
# fields with rounded points, and that assignment and source tracking work.
#
# Usage:  scripts/desk-smoke.sh [BASE_URL]
#   BASE_URL defaults to http://127.0.0.1:8081. Set NC_DIR when the server is not ../nc-server.
# Safe to re-run: it asserts on the rows it creates, not on global counts.
# Exit code is the number of failed checks (0 = all good).

set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
BASE="${1:-${BASE_URL:-http://127.0.0.1:8081}}"
API="$BASE/index.php/apps/timebank/api"
FAILS=0
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

command -v jq >/dev/null || { echo "jq is required (apt install jq / brew install jq)"; exit 99; }

pass() { printf '  \033[32mPASS\033[0m %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; FAILS=$((FAILS + 1)); }
occ() { scripts/dev-native.sh occ "$@" >/dev/null 2>&1 || true; }

# call USER METHOD PATH [JSON_BODY] -> body in $TMP/body, status in $STATUS
call() {
	local user="$1" method="$2" path="$3" body="${4:-}"
	local pw="${user}-pass-123"
	local jar="$TMP/jar-$user"
	# Nextcloud < 30 ignores OCS-APIRequest for CSRF, so keep a session and send its token.
	if [ ! -s "$TMP/token-$user" ]; then
		curl -s -c "$jar" -u "$user:$pw" -H 'OCS-APIRequest: true' "$BASE/index.php/csrftoken" | jq -r '.token // empty' >"$TMP/token-$user"
	fi
	local args=(-s -o "$TMP/body" -w '%{http_code}' -u "$user:$pw" -b "$jar" -c "$jar"
		-H "requesttoken: $(cat "$TMP/token-$user")" -H 'OCS-APIRequest: true' -H 'Accept: application/json' -X "$method")
	[ -n "$body" ] && args+=(-H 'Content-Type: application/json' -d "$body")
	STATUS="$(curl "${args[@]}" "$API$path")"
}

eq() { # eq LABEL CODE
	if [ "$STATUS" = "$2" ]; then pass "$1 ($STATUS)"; else fail "$1 - expected $2, got $STATUS: $(head -c 200 "$TMP/body")"; fi
}
has() { # has LABEL JQ_FILTER [JQ_ARGS...]
	local label="$1" filter="$2"; shift 2
	if jq -e "$@" "$filter" "$TMP/body" >/dev/null 2>&1; then pass "$label"; else fail "$label :: $(head -c 300 "$TMP/body")"; fi
}

echo "NextTime organizer desk smoke test against $BASE"

echo "- Roles"
occ group:add timebank-organizer
occ group:add timebank-facilitator
occ group:add "Oregon"
occ group:add "Oregon Facilitators"
occ group:adduser timebank-organizer alice
occ group:adduser "Oregon Facilitators" bob

echo "- Schema access"
call carol GET /schema; eq "member cannot read the schema" 403
call bob GET /schema; eq "facilitator can read the schema" 200
call alice GET /schema; eq "organizer can read the schema" 200
has "schema lists 6 layers" '.layers|length==6'
call carol GET /roles; has "member roles are false" '.organizer==false and .facilitator==false'
call alice GET /roles; has "organizer roles are true" '.organizer==true'

echo "- Manual entry"
SUMMARY="Groceries this week $$"
NEED="{\"need_or_offer\":\"need\",\"category\":\"food\",\"summary\":\"$SUMMARY\",\"zip\":\"97055\",\"lat\":\"45.40731\",\"lon\":\"-122.20149\",\"urgency\":\"this_week\",\"posted\":\"2026-10-23\",\"expires\":\"2099-01-01\",\"contact_route\":\"chapter_organizer\",\"status\":\"open\",\"requester_contact\":\"555-0100 Jane Doe\",\"publish\":\"yes\"}"
call carol POST /layers/needs_offers/rows "$NEED"; eq "member cannot create rows" 403
call bob POST /layers/needs_offers/rows "$NEED"; eq "facilitator cannot create rows" 403
call alice POST /layers/needs_offers/rows "$NEED"; eq "organizer creates a row" 201
ID="$(jq -r '.id' "$TMP/body")"
UID_="$(jq -r '.fields.uid' "$TMP/body")"
has "organizer sees requester_contact" '.fields.requester_contact=="555-0100 Jane Doe"'
has "organizer sees lat and lon" '.fields.lat==45.40731 and .fields.lon==-122.20149'
has "chapter is derived from the ZIP, source is manual" '.chapter=="oregon" and .source=="manual"'
call alice POST /layers/needs_offers/rows '{"summary":"x","ssn":"1"}'; eq "unknown field is rejected" 422
call alice POST /layers/needs_offers/rows '{"need_or_offer":"need","category":"gold"}'; eq "value outside the lookup is rejected" 422

echo "- Contact and location fields are filtered on read"
call bob GET /layers/needs_offers/rows; eq "facilitator lists rows" 200
has "facilitator sees the row's summary" 'any(.[]; .id==$id and .fields.summary==$s)' --argjson id "$ID" --arg s "$SUMMARY"
has "no row shows requester_contact to a facilitator" 'any(.[]; .fields|has("requester_contact"))|not'
has "no row shows lat or lon to a facilitator" 'any(.[]; .fields|has("lat") or has("lon"))|not'
call carol GET /layers/needs_offers/rows; eq "member cannot list rows" 403
occ group:add "Washington"
occ group:add "Washington Facilitators"
occ group:adduser "Washington" carol
call carol GET /layers/needs_offers/rows; eq "belonging to a chapter's group gives no desk access" 403
occ group:removeuser "Washington" carol
occ group:adduser "Washington Facilitators" carol
call carol GET /layers/needs_offers/rows; eq "the facilitator group alone gives desk access" 200
has "a facilitator of another chapter sees none of Oregon's rows" 'any(.[]; .id==$id)|not' --argjson id "$ID"
occ group:removeuser "Washington Facilitators" carol
call carol GET /layers/needs_offers/rows; eq "removing the facilitator group removes desk access at once" 403
call bob GET /layers/needs_offers/rows; eq "bob (Oregon Facilitators) still lists rows" 200
occ group:removeuser "Oregon Facilitators" bob
call bob GET /layers/needs_offers/rows; eq "a facilitator who loses the role loses the rows at once" 403
occ group:adduser "Oregon Facilitators" bob

echo "- Public feed"
call carol GET /layers/needs_offers/feed; eq "member reads the feed" 200
has "feed contains the published row" 'any(.features[]; .properties.uid==$u)' --arg u "$UID_"
has "feed hides requester_contact" 'any(.features[]; .properties|has("requester_contact"))|not'
has "feed hides lat and lon properties" 'any(.features[]; .properties|has("lat") or has("lon"))|not'
has "feed point is rounded to 2 decimals" 'any(.features[]; .properties.uid==$u and .geometry.coordinates==[-122.2,45.41])' --arg u "$UID_"
call carol GET /layers/resources/feed; eq "resources (exact points) has no feed" 404
call carol GET /layers/survey/feed; eq "survey feed is refused" 403

echo "- Assignment tracking"
call bob POST "/layers/needs_offers/rows/$ID/assign"; eq "facilitator cannot assign" 403
call alice POST "/layers/needs_offers/rows/$ID/assign"; eq "organizer assigns to self" 200
has "row is assigned to alice" '.assignedTo=="alice" and .assignmentStatus=="assigned"'
call alice POST "/layers/needs_offers/rows/$ID/status" '{"status":"in_progress"}'; eq "assignee updates the status" 200
call alice GET "/assignments?source=manual"; eq "assignments filter by source=manual" 200
has "my assignment is listed" 'any(.[]; .id==$id and .assignmentStatus=="in_progress")' --argjson id "$ID"
call alice GET "/assignments?source=form"
has "none of my assignments came from a form" 'length==0'
call alice GET "/layers/needs_offers/rows?assigned=unassigned"
has "the row is no longer unassigned" 'any(.[]; .id==$id)|not' --argjson id "$ID"
call bob DELETE "/layers/needs_offers/rows/$ID/assign"; eq "someone else cannot release it" 403
call alice DELETE "/layers/needs_offers/rows/$ID/assign"; eq "the holder releases it" 200

echo "- Survey is aggregate-only"
call alice GET /layers/survey/rows; eq "even an organizer gets no survey rows" 403
call alice POST /layers/survey/rows '{"chapter":"x"}'; eq "survey rows cannot be created" 403

echo "- Request coordinates and the map feed"
call alice GET /categories
CAT_ID="$(jq -r '.[0].id' "$TMP/body")"
call alice POST /requests "{\"title\":\"Garden help\",\"description\":\"Clearing a plot of land\",\"categoryId\":$CAT_ID,\"hoursBudget\":2,\"location\":\"45.4215, -122.7528\"}"; eq "create a request with lat,lng text" 201
has "coordinates are derived from the text" '.latitude==45.4215 and .longitude==-122.7528'
call alice POST /requests "{\"title\":\"Address only\",\"description\":\"Clearing a plot of land\",\"categoryId\":$CAT_ID,\"hoursBudget\":2,\"location\":\"123 Main Street\"}"; eq "an address-only request is accepted" 201
has "an address-only request has no coordinates" '.latitude==null'
call alice POST /requests "{\"title\":\"Bad coords\",\"description\":\"Clearing a plot of land\",\"categoryId\":$CAT_ID,\"hoursBudget\":2,\"latitude\":95,\"longitude\":0}"; eq "out-of-range coordinates are rejected" 400
call carol GET /map/requests; eq "map feed" 200
has "map feed has a rounded point" 'any(.features[]; .geometry.coordinates==[-122.75,45.42])'
has "map feed carries no description, requester or location" 'any(.features[]; .properties|has("description") or has("requesterId") or has("location"))|not'

echo
if [ "$FAILS" -eq 0 ]; then echo "All desk checks passed."; else echo "$FAILS desk check(s) failed."; fi
exit "$FAILS"
