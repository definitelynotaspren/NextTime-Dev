#!/usr/bin/env bash
# Fills a dev server with a small, realistic community so there is something to click through:
# requests with map pins, offers, one job in progress, a claim waiting for approval, a claim in a
# community vote, and a comment thread. Uses the app's own API, as the smoke test does.
#
# Usage:  scripts/demo-data.sh [BASE_URL]
#   BASE_URL defaults to http://127.0.0.1:8081 (scripts/dev-native.sh). For Docker use
#   http://localhost:8080 and set ADMIN_PASS to the admin password from your .env.
# Needs the users admin, alice, bob and carol (dev-native.sh setup or make docker-seed-users) and jq.
# Running it twice does nothing the second time.

set -uo pipefail

BASE="${1:-${BASE_URL:-http://127.0.0.1:8081}}"
API="$BASE/index.php/apps/timebank/api"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

command -v jq >/dev/null || { echo "jq is required (apt install jq / brew install jq)"; exit 99; }

# call USER METHOD PATH [JSON_BODY] -> body in $BODY, status in $STATUS (same as smoke-test.sh)
call() {
	local user="$1" method="$2" path="$3" body="${4:-}"
	local pass
	case "$user" in admin) pass="${ADMIN_PASS:-admin}" ;; *) pass="${user}-pass-123" ;; esac
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

# must CODE LABEL: stop with a message unless the last call returned CODE
must() {
	if [ "$STATUS" != "$1" ]; then
		echo "Could not $2 (HTTP $STATUS): $(head -c 300 <<<"$BODY")"
		exit 1
	fi
}

json() { jq -r "$1" <<<"$BODY"; }

category() { # category NAME -> id
	call alice GET /categories
	must 200 "read categories (is the server running and are the users created?)"
	jq -r --arg n "$1" '[.[] | select(.name == $n)][0].id // empty' <<<"$BODY"
}

# post USER TITLE CATEGORY HOURS PRIORITY LOCATION DESCRIPTION -> request id
post() {
	local cat
	cat="$(category "$3")"
	[ -n "$cat" ] || { echo "Category '$3' is missing; run occ maintenance:repair"; exit 1; }
	call "$1" POST /requests "$(jq -n --arg t "$2" --arg d "$7" --arg l "$6" --arg p "$5" \
		--argjson c "$cat" --argjson h "$4" \
		'{title: $t, description: $d, categoryId: $c, hoursBudget: $h, priority: $p, location: $l, zip: "97214"}')"
	must 201 "post \"$2\""
	json '.id'
}

offer() { # offer USER REQUEST HOURS MESSAGE -> offer id
	call "$1" POST "/requests/$2/volunteer" "$(jq -n --argjson h "$3" --arg m "$4" '{proposedHours: $h, message: $m}')"
	must 201 "offer help on request $2"
	json '.id'
}

claim() { # claim USER CATEGORY HOURS DESCRIPTION -> claim id
	call "$1" POST /earnings/claim "$(jq -n --argjson c "$(category "$2")" --argjson h "$3" --arg d "$4" \
		'{categoryId: $c, hoursClaimed: $h, description: $d}')"
	must 201 "submit a claim for $1"
	json '.id'
}

echo "Adding demo data to $BASE"
call alice GET /requests/my
must 200 "reach the app (is the server running and are the users created?)"
if jq -e '.requests[] | select(.title == "Ride to a medical appointment on Thursday")' <<<"$BODY" >/dev/null; then
	echo "Demo data is already there. Nothing to do."
	exit 0
fi

# Every request is in Portland (ZIP 97214). Locations are "latitude, longitude": the poster, Oregon's
# facilitators and accepted volunteers see that pin; everyone else sees the ZIP's center.
RIDE=$(post alice "Ride to a medical appointment on Thursday" Transportation 2 high "45.5122, -122.6587" \
	"I need a ride to a clinic appointment across town on Thursday morning and back afterwards. About an hour each way with the wait.")
GARDEN=$(post carol "Help planting a community garden bed" Gardening 3 normal "45.5355, -122.6230" \
	"The neighbourhood garden got a new raised bed. Looking for two people to help fill it and plant starts on Saturday.")
LAPTOP=$(post bob "Set up a laptop for video calls" "Tech Support" 1 low "45.4870, -122.6440" \
	"My laptop needs the video call app installed and the camera and microphone checked so I can talk to my family.")
MEALS=$(post alice "Batch-cook meals for a new parent" "Cooking/Food" 3 normal "45.5590, -122.6750" \
	"A neighbour just had a baby. Cooking a few freezer meals for them this week would be a big help.")
post carol "After-school homework help" Education 2 urgent "45.4995, -122.6005" \
	"Looking for someone patient to help my kid with maths homework twice next week, after school." >/dev/null
echo "  5 requests posted"

# A ride in progress: bob offered and alice accepted.
RIDE_OFFER=$(offer bob "$RIDE" 2 "I am free Thursday and have a car with plenty of room.")
call alice POST "/volunteers/$RIDE_OFFER/accept"
must 200 "accept bob's offer"
call alice POST "/requests/$RIDE/comments" '{"comment":"Thank you Bob! The appointment is at 10:30, could you pick me up at 9:45?"}'
must 201 "comment on the ride"
call bob POST "/requests/$RIDE/comments" '{"comment":"9:45 works. See you then."}'
must 201 "comment on the ride"

# The garden has two offers waiting for carol to choose.
offer alice "$GARDEN" 2 "I can bring a wheelbarrow." >/dev/null
offer bob "$GARDEN" 3 "Happy to dig, I have done raised beds before." >/dev/null

# The laptop job is done, and carol has claimed her hour; an admin or the community decides.
LAPTOP_OFFER=$(offer carol "$LAPTOP" 1 "I set these up at the library all the time.")
call bob POST "/volunteers/$LAPTOP_OFFER/accept"
must 200 "accept carol's offer"
call bob POST "/requests/$LAPTOP/complete" "{\"volunteerId\":$LAPTOP_OFFER}"
must 200 "complete the laptop job"
claim carol "Tech Support" 1 "Set up bob's laptop for video calls: installed the app and tested camera and microphone." >/dev/null
echo "  offers, one job in progress, one claim waiting for approval"

# A claim in a community vote: three members other than the claimant settle it.
VOTE_CLAIM=$(claim bob Gardening 2 "Weeded and watered the community garden beds while the usual volunteers were away.")
call admin POST "/earnings/$VOTE_CLAIM/send-to-vote"
must 200 "send bob's claim to a community vote (check ADMIN_PASS)"
echo "  one claim in a community vote"

echo "Done. Log in as alice, bob or carol (password <name>-pass-123) or admin and look around:"
echo "  $BASE/index.php/apps/timebank/"
echo "  Things to try: as carol, accept an offer on the garden; as alice or admin, vote on bob's claim"
echo "  (Votes on Claims); as admin, approve carol's claim (Approvals & Votes); offer help on request $MEALS."
