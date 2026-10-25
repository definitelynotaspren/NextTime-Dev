# NextTime

Community time banking and mutual-aid coordination for Nextcloud (app id `timebank`). Members post requests for
help, volunteer for each other, and earn hours that a facilitator, an admin or a community vote approves. Every
movement of hours lands in a public ledger. Organizers and chapter facilitators also work a desk of needs and offers,
which can arrive from Nextcloud Forms.

## Features

- **Request Board and map**: post, browse and map service requests; the requester accepts volunteers (several allowed),
  completes the job, and can edit (while open) or cancel it.
- **Earning claims**: volunteers claim hours for work done. A claim is approved, rejected or sent to a community vote
  by someone other than the claimant: an admin, the facilitator of the claimant's chapter, or a national organizer.
  Any member except the claimant can vote.
- **Public ledger and personal balance**: every movement of hours is public; names link to a member's own ledger.
- **Chapters from your Nextcloud groups**: a group "Colorado" and a group "Colorado Facilitators" make the Colorado
  chapter, with no setup (see [Roles and chapters](#roles-and-chapters)).
- **Organizer desk**: needs, offers, actions and reports, filtered by who may see which field, with assignment
  tracking, so personal details stay out of reach unless a role allows them.
- **Form intake**: new submissions of Nextcloud Forms you choose become unpublished desk rows with an ID that shows
  where they came from.
- **Community moderation**: three flags from different members hide a comment; moderators keep or remove it.
- **Admin tools with a paper trail**: categories (archive instead of deleting what is in use), balance adjustments that
  need a second person above a threshold, and a **Governance Log** every member can read.

## Requirements

- **Nextcloud** 29 to 35. Each of 29.0.16, 30.0.17, 31.0.14, 32.0.15, 33.0.10, 34.0.5 and 35.0.2 was run through the
  unit, API, desk, approver and browser tests (see [CHANGELOG.md](CHANGELOG.md)).
- **PHP** 8.3 to 8.5. Run on 8.3 (all tests) and 8.5 (the official `nextcloud:35-apache` image: API smoke test and a
  browser check); 8.4 is untested.
- **Database**: the app declares PostgreSQL 12+, MySQL 8.0+ and SQLite, but **only SQLite has been run**. Test the
  others before relying on them.
- **Nextcloud Notifications app** (shipped and enabled on a normal install): needed for "a claim is waiting" notices.
- **Nextcloud Forms app** (optional): only if you want form intake. Intake reads Forms' own tables and was run against
  Forms 5.2 on Nextcloud 31; other Forms versions were not run, so check **Form Intake** after upgrading Forms. Nextcloud's normal **background jobs (cron)**
  must be running for reminders and for intake to check forms every five minutes.
- **Node.js 20+ and npm 10+**: only to build from source. A release package ships the built frontend.

## Installation

**Normal install.** Get a built package (a `timebank.tar.gz` from a release, or build one yourself with
`make appstore`, which writes `build/timebank.tar.gz`), extract it into your Nextcloud `apps/` or `custom_apps/`
directory so the folder is named `timebank`, then run:

```bash
sudo -u www-data php occ app:enable timebank
```

Enabling runs the database migrations and seeds the default categories. [INSTALL.md](INSTALL.md) has step-by-step
instructions, including Nextcloud All-in-One.

After the app is on, create the groups that give people roles (see [Roles and chapters](#roles-and-chapters)).
Nothing works for facilitators until their groups exist.

**Upgrading.** Replace the app folder with the new version and run `sudo -u www-data php occ upgrade`. New versions
add database migrations; never edit one that has shipped.

### Development: a Docker-free server (the one the tests use)

```bash
git clone https://github.com/definitelynotaspren/NextTime-Dev.git
cd NextTime-Dev
make install                 # composer + npm dependencies
make build                   # build the frontend into js/ and css/
make native-setup            # clones Nextcloud (stable31) next to this repo, installs it on SQLite, enables the app,
                             # creates the users below and installs the Viewer and Notifications apps
make native-start            # http://127.0.0.1:8081/index.php/apps/timebank/
```

Logins: `admin/admin`, `alice/alice-pass-123`, `bob/bob-pass-123`, `carol/carol-pass-123`. After changing the app's
version or adding a migration, run `scripts/dev-native.sh upgrade`. `scripts/dev-native.sh occ ...` runs `occ` against
this server. Setup needs network access to clone Nextcloud, and `composer` and `git`.

### Development: Docker

Needs Docker with the `docker compose` command, Node.js >= 20 and Composer on your machine.

```bash
cp .env.example .env         # then change the passwords in .env
make dev                     # dependencies, frontend build, then Postgres 15 + Redis 7 + Nextcloud 31 (PHP 8.3)
# wait until http://localhost:8080/status.php shows "installed":true (a minute or two the first time)
make docker-install-app      # enables NextTime
make docker-seed-users       # alice, bob, carol with passwords <name>-pass-123
```

Log in at http://localhost:8080 as `admin` with the password from `.env`. The image's password policy rejects
`admin` as a password, so the scripts take it from `ADMIN_PASS`, for example
`ADMIN_PASS='your-admin-password' make smoke BASE_URL=http://localhost:8080`. Nextcloud 29's image is not used
because it ships PHP 8.2, and NextTime needs 8.3. The API smoke test passes on this stack; the browser and desk
tests are written for the Docker-free server.

### Try it: a server with demo data to click through

After either setup above, fill the server with a small community: five requests with pins on the map, offers
waiting for an answer, a job in progress, a claim waiting for an admin, and a claim in a community vote.

```bash
make demo                                                            # Docker-free server
ADMIN_PASS='your-admin-password' make demo BASE_URL=http://localhost:8080   # Docker
```

Then log in as `alice`, `bob` or `carol` (password `<name>-pass-123`) or as `admin`, each in their own browser
or private window, and try: carol accepts an offer on the garden request; alice votes on bob's claim under
**Votes on Claims**; admin approves carol's claim under **Approvals & Votes**; anyone offers help on the meals
request. Running `make demo` again does nothing. The first login shows Nextcloud's welcome dialog; close it.

### Manual install from source

Clone into the apps directory as `timebank`, run `make install` and `make build`, then `occ app:enable timebank`.

### Available commands

```bash
make help              # list the commands
make install           # install PHP and Node.js dependencies
make build             # production frontend build (js/, css/)
make watch             # rebuild on change
make test              # PHPUnit (needs NEXTCLOUD_ROOT=/path/to/a/nextcloud/server, see Testing)
make test-native       # PHPUnit against ../nc-server (made by make native-setup)
make lint              # php -l over the tree
make cs-check          # code style (cs-fix to apply)
make psalm             # static analysis (a CI gate: no findings, no baseline)
make smoke             # end-to-end API test (BASE_URL=..., ADMIN_PASS=... for Docker)
make demo              # demo data to click through (BASE_URL=..., ADMIN_PASS=... for Docker)
make appstore          # build/timebank.tar.gz for installing
make native-setup / native-start / native-stop
make docker-up / docker-down / docker-logs / docker-shell / docker-rebuild / docker-install-app / docker-seed-users
make clean             # removes js/, node_modules/ and vendor/
```

## Configuration

### Admin settings

**Settings → Administration → Time Bank** is a read-only panel that explains how decisions are made. Those rules are
fixed in code on purpose: claims need someone other than the claimant, three votes settle a vote, balances never go
below zero. What admins can change inside the app is done on its own pages (**Categories**, **Adjustments**,
**Form Intake**), and each change is written to the public **Governance Log**.

### App settings

Set with `occ config:app:set timebank <key> --value=<value>`:

| Key | Default | What it does |
|-----|---------|--------------|
| `national_organizer_group` | `National Organizers` | Id or name of the national organizer group |
| `fallback_approver_group` | `GSUS` | Legacy: its members still count as national organizers until moved (see Roles) |
| `chapter_zip_prefixes` | built-in for New York City | JSON for city chapters, for example `{"Chicago": ["606"]}`. Replaces the default when set |
| `adjustment_threshold` | `5` | Hours up to which one admin can adjust a balance alone (1 to 20). Change it under **Adjustments**, which records a reason publicly |
| `forms_intake` | none | Which forms feed the desk. Add and remove them on the **Form Intake** page |

### Category earn rates

Each category has a multiplier: working 1 hour in a category with rate 1.2 earns 1.2 hours. The default categories
are seeded at 1.0 to 1.5, and admins can set any rate above 0 and up to 5 under **Categories**.

## Usage

- **Members**: browse the **Request Board** (list or map) and offer help; post a request; after a job, **Submit
  Earnings** (claim hours); watch **My Balance**, **My Activity** (your requests, offers and withdrawn help) and the
  **Public Ledger**; vote on claims in **Approvals & Votes**; flag a comment that does not belong.
- **Requesters**: accept or decline offers, **Mark complete** (this does *not* credit hours; each volunteer claims
  theirs), edit while the request is open, or cancel.
- **Facilitators and approvers**: **Approvals & Votes** shows only the claims you may decide, and why. You can approve,
  reject (with a reason) or send to a community vote. Facilitators also work their chapter's rows on the **Organizer Desk**.
- **National organizers**: claims and adjustments for any member, the desk everywhere, the **Form Intake** and **Chapters** pages, and comment moderation.
- **Admins**: **Categories**, **Adjustments**, the **Governance Log**, and approval of claims.

Walk-throughs of an offer, a need and a three-person job are in [docs/flows.md](docs/flows.md). What is not built yet is
in [docs/proposals/next-steps.md](docs/proposals/next-steps.md).

## Roles and chapters

Roles come from Nextcloud groups, so an admin creates nothing beyond the groups the organization already has:

| Group | Meaning |
|-------|---------|
| `<State>` (for example "Colorado") | Who belongs to that state or chapter. Gives no powers. |
| `<State> Facilitator` or `<State> Facilitators` | The facilitators of that chapter. **This group alone gives the power**; they need not also be in `<State>`. |
| `National Organizers` | National organizers: decide claims and adjustments for any member (never their own), so also for members whose chapter has no facilitator or who have no chapter; the organizer desk everywhere; comment moderation. Another group can be set with `national_organizer_group`. |
| `timebank-organizer`, `GSUS` | Legacy groups replaced by `National Organizers`. Their members still count as national organizers until an admin moves them; the **Chapters** page lists who is left. Note this gives former `GSUS` members nationwide decide power, not only for members without a facilitator. |
| `timebank-facilitator` | Desk access without chapter rows. Does not let anyone approve claims. |

NextTime finds chapters by pairing group names: a facilitator group pairs with the group of the same name minus
"Facilitator(s)", ignoring case, spaces, hyphens and underscores. The name used is the group's display name, or its id
when it has none; every role check uses the group id. A pair, or a lone facilitator group named after a US state (or a
city chapter), is a chapter. A facilitator group that matches nothing (such as "Chapter Facilitators") is ignored. The
**Chapters** page (admins and organizers) lists what was found, who facilitates each, states that have no facilitator
group, and groups it ignored, so a spelling mistake shows up. Nextcloud admins are not national organizers unless they are
also in that group.

A person may decide on a claim, or approve a large balance adjustment, for member U (never their own) when they are a
Nextcloud admin, or they are in the facilitator group of a chapter U belongs to, or they are a national organizer. Roles are read from the groups on every
request and nothing is cached, so removing someone from a facilitator group ends their ability to approve, or to see that
chapter's rows, on their next click.

A chapter's facilitators work its desk rows: they can add rows for ZIPs in their chapter, assign them to themselves, set
progress, publish and unpublish. National organizers can do the same anywhere. The privacy rules do not change: a facilitator sees
public and chapter fields, not organizer-only ones such as the requester's contact details.

Which chapter reviews a desk row is worked out from its ZIP code each time: the chapter named after the ZIP's state, or a
city chapter's own prefixes.

When a claim is submitted, the facilitators of the claimant's chapters are told in Nextcloud notifications (else
the national organizers, else the admins). The notice names who, hours and category, never the description, and clears for everyone once
the claim is decided. After 3 days the same people get a reminder, and after 7 days the admins are told too (an hourly
background job).

## Form intake (Nextcloud Forms)

NextTime checks the Nextcloud Forms you choose every five minutes (a background job; also **Check now** on the **Form
Intake** page, for admins and organizers). Each new submission becomes one **unpublished** desk row with
`source = form`. Every row has a unique ID that shows its origin: `NO-F3-17` is submission 17 of form 3 (Nextcloud
Forms), `NO-M1A2B3C` was typed in on the desk by hand. A submission is looked at once; one that fails the desk's rules is
logged as rejected with the reason, and is looked at again only when someone presses **Look again at rejected**.

**To add a form**, open **Form Intake**, paste the form's link under **Add a form**, press **Look up this form**, check
how its questions map, and press **Add this form**. The link has to be a form on this same Nextcloud, made in the Forms
app: the **share link** from the form's Share panel (`https://your-server/index.php/apps/forms/s/<code>`), or the address
shown while the form is open (`https://your-server/index.php/apps/forms/<code>`, also with `/edit` or `/results`). The
code at the end identifies the form; links to other servers or to Google Forms are refused with a message. Only forms
added this way are listed, so organizers never see the titles of forms nobody gave them. **Remove** stops checking a form;
rows already imported stay on the desk.

A question feeds the desk field whose key matches its internal name or its text, ignoring case and punctuation ("Need or
offer" feeds `need_or_offer`). Choice answers are matched to the field's allowed values ("Transportation" becomes
`transport`). `posted`, `expires` (30 days) and `status` (`open`) are filled in when the form does not ask. Answers to
questions that match no field are not kept. The form needs questions for: Need or offer, Category, Summary, ZIP, Urgency
and Contact route. Optional ones include Area hint and Requester contact (organizer-only). **Check how it maps** on the
Form Intake page, and the lookup step before you add a form, show what a form would do.

It reads the Forms app's own tables and writes nothing to Forms. Forms keeps the original answers, including who submitted
them unless the form is anonymous; NextTime does not copy the submitter's account into the row.

## Map

- A request appears on the map only if it has coordinates: pick a point on the map in the form, or type `lat, lng`. A
  free-text address (for example "123 Main Street") is saved but not plotted; geocoding is not implemented.
- The basemap is [OpenFreeMap](https://openfreemap.org) (OpenStreetMap data, no API key). Viewers' browsers connect to
  `tiles.openfreemap.org`. To use another tile source, change `MAP_STYLE_URL` in `src/composables/useMap.ts` **and**
  `MAP_HOST` in `lib/Controller/PageController.php`: Nextcloud's Content-Security-Policy blocks any host not listed there.
- Map and feed output is rounded to about 1 km and never includes contact fields.

## API documentation

All endpoints are plain JSON under `/index.php/apps/timebank/api/...` (no OCS envelope). Log in with a Nextcloud account
or an app password. Calls that change data must send `OCS-APIRequest: true` (Nextcloud 30 and later; Nextcloud 29 needs a
session cookie and a `requesttoken` header instead, as `scripts/smoke-test.sh` does). Errors are `{"error": "..."}`:
400 for a move the rules refuse, 403 when you may not do it, 404 when it does not exist, 429 (empty body) when you post
too often. Lists return at most 100 rows a page (`limit` 1 to 100, `offset` 0 or more). "(admin)" means admins only;
"(owner only)" means the person who created the thing.

### Requests

- `GET /api/requests` - list (filters `status`, `categoryId`, `priority`, `limit`, `offset`)
- `GET /api/requests/{id}` - details, volunteers and comments (`canModerate` tells you if you can moderate)
- `GET /api/requests/my` - your own requests
- `POST /api/requests` - create (`title` 5+ characters, `description` 20+, `categoryId`, `hoursBudget` up to 1000,
  `priority`, optional `deadline`, `location`, `latitude`, `longitude`). Archived categories are refused
- `PUT /api/requests/{id}` - update *(owner only, while open; the same limits apply)*
- `POST /api/requests/{id}/complete` - every accepted volunteer is completed. Credits no hours *(owner only)*
- `POST /api/requests/{id}/cancel` - cancel an open or in-progress request *(owner only)*

There is no delete for requests; cancel instead. A request is `open` until an offer is accepted, then `in_progress`, and
ends `completed` or `cancelled` (both final). Several people can offer and be accepted; each accepted volunteer is credited
their own proposed hours (not split), and hours are only credited when the volunteer's claim is approved.

### Volunteers

- `POST /api/requests/{requestId}/volunteer` - offer to help
- `DELETE /api/volunteers/{id}` - withdraw. After being accepted a `reason` (3+ characters) is required, logged, and the
  request reopens if no accepted volunteer remains *(offer owner only)*
- `POST /api/volunteers/{id}/accept`, `/decline` *(request owner only)*
- `GET /api/volunteers/my` - your offers with their request's title and status
- `GET /api/withdrawals/my` - your log of withdrawn help

### Comments

- `POST /api/requests/{requestId}/comments` - add a comment
- `POST /api/comments/{id}/flag` - flag a comment that is not yours, once, with an optional `reason`. At 3 distinct
  flags it is hidden from members. Counts are public; who flagged and why is for moderators
- `GET /api/comments/flagged`, `POST /api/comments/{id}/dismiss`, `DELETE /api/comments/{id}` *(moderators only; the
  text is kept and the removal is recorded. Authors cannot delete their own)*

Moderators are Nextcloud admins and members of `timebank-organizer`.

### Earnings

- `POST /api/earnings/claim` - claim hours (`categoryId`, `hoursClaimed` up to 1000, `description` 10+ characters)
- `GET /api/earnings/my` - your claims
- `GET /api/earnings/pending` - pending claims you may decide, each with `decidingAs` (`admin`, `facilitator`, `national`)
- `GET /api/earnings/voting` - claims in a community vote
- `POST /api/earnings/{id}/approve`, `/reject` (`reason` 10+ characters), `/send-to-vote` *(an approver for the claimant;
  never the claimant; a claim is decided once)*
- `POST /api/earnings/{id}/vote` - `approve`, `reject` or `abstain` (any member but the claimant, once). Three votes settle it

### Balance, ledger, adjustments, governance

- `GET /api/balance/my`, `GET /api/balance/all`
- `GET /api/ledger`, `GET /api/ledger/my`, `GET /api/ledger/user/{userId}` - the public ledger
- `GET /api/adjustments` - recent adjustments, what you may do to each, the threshold *(admins, facilitators, national organizers)*
- `POST /api/adjustments` - `userId`, `hours` (negative to deduct), `reason` (10+ characters, public). Up to the threshold it
  applies at once; larger ones wait for a second person *(admins; nobody adjusts their own balance)*
- `POST /api/adjustments/{id}/approve`, `/reject` (`note`), `/withdraw` - a second approver (never the proposer or the
  member), or the proposer withdrawing. Pending ones expire after 14 days
- `PUT /api/settings/adjustment-threshold` - `hours` (1 to 20) and `reason` *(admins)*
- `GET /api/governance` - the public log of admin decisions *(every member)*

### Categories

- `GET /api/categories` - all, archived ones included (flagged `archived`); `GET /api/categories/{id}`
- `POST /api/categories`, `PUT /api/categories/{id}` *(admin; earn rate above 0 and at most 5)*
- `POST /api/categories/{id}/archive`, `/restore` *(admin)*
- `DELETE /api/categories/{id}` *(admin)*. Refused while a request is in progress, an open request has offers or a claim is
  waiting, and while any past request, claim or statistic uses it. Archive instead
- `GET /api/categories/{id}/usage` *(admin)*

### Map and public feeds

- `GET /api/map/requests` - GeoJSON of open requests (points rounded to about 1 km; no contact fields)
- `GET /api/layers/{layer}/feed` - GeoJSON of published rows for `point_snapped` layers (`needs_offers`, `issue_reports`)

### Organizer desk (organizers and facilitators)

- `GET /api/schema` - layers, fields, visibility levels, lookups and the chapters found
- `GET /api/layers/{layer}/rows` - rows you may see; filters `assigned=me|unassigned`, `source=form|manual`
- `POST /api/layers/{layer}/rows` - add a row (organizer anywhere; a facilitator for ZIPs in their chapter); unpublished unless `publish=yes`
- `POST /api/layers/{layer}/rows/{id}/assign` - take a row (organizer, or the facilitator of its chapter); `DELETE` the same path releases it (an organizer can release any)
- `POST /api/layers/{layer}/rows/{id}/status` - `assigned`, `in_progress` or `done`, on a row you hold
- `POST /api/layers/{layer}/rows/{id}/publish` - `publish=yes|no`
- `GET /api/assignments` - rows assigned to you; filters `layer`, `source`

Fields are filtered by visibility on every read. A field marked `organizer` never reaches a facilitator or member, and a
`chapter` field only the facilitators of that chapter. Undeclared fields are treated as organizer-only. The survey layer
is aggregate-only and has no row endpoints.

### Roles, chapters and intake (admins and organizers)

- `GET /api/roles` - your own flags (`member`, `chapterMember`, `facilitatorOf`, `nationalOrganizer`, `facilitator`, `moderator`, `admin`; `organizer` is an alias of `nationalOrganizer`)
- `GET /api/chapters/check` - chapters found from your groups, their facilitators, and groups that did not pair
- `GET /api/intake` (the added forms and recent submissions), `POST /api/intake/forms/lookup` (`link`: finds a form and
  shows its mapping, adds nothing), `PUT /api/intake/forms` (the full list of added forms), `GET /api/intake/forms/{id}/preview?layer=needs_offers`,
  `POST /api/intake/run`, `POST /api/intake/retry`

### Example calls

```bash
# Create a request. Use a category id from GET /api/categories. The header is required.
curl -X POST http://localhost:8080/index.php/apps/timebank/api/requests \
  -H "Content-Type: application/json" -H "OCS-APIRequest: true" \
  -u alice:APP_PASSWORD \
  -d '{"title":"Garden cleanup","description":"Clearing an overgrown garden area, about 3 to 4 hours of work.","categoryId":1,"hoursBudget":4,"priority":"normal"}'

# Claim hours
curl -X POST http://localhost:8080/index.php/apps/timebank/api/earnings/claim \
  -H "Content-Type: application/json" -H "OCS-APIRequest: true" \
  -u bob:APP_PASSWORD \
  -d '{"categoryId":1,"hoursClaimed":3.5,"description":"Cleared the overgrown area and removed debris"}'
```

Use an app password (Personal settings, Security) instead of your real password, and revoke it afterwards.

## Database schema

Tables created by the migrations (prefix `oc_` in a default install):

- `timebank_categories` - service categories, earn rates, `archived_at`
- `timebank_requests` - requests, with optional latitude and longitude
- `timebank_volunteers` - offers to help
- `timebank_withdrawals` - log of withdrawn help, with reasons
- `timebank_comments`, `timebank_comment_flags` - comments (soft-removed ones keep their text) and members' flags
- `timebank_earnings`, `timebank_votes` - claims and community votes
- `timebank_balances`, `timebank_transactions` - balances and the public ledger
- `timebank_user_stats` - hours and jobs per category
- `timebank_adjustments` - manual balance adjustments and who proposed and approved them
- `timebank_governance_log` - the public, append-only log of admin decisions
- `timebank_layer_rows` - organizer-desk rows (one JSON column holds the fields; the most sensitive table)
- `timebank_intake_log` - which Forms submissions were imported or rejected, and why

[docs/data-and-access.md](docs/data-and-access.md) says who can read each, what is not protected, and how to get raw data out.

## Testing

```bash
# Unit tests need a Nextcloud server checkout (make native-setup creates ../nc-server)
NEXTCLOUD_ROOT=../nc-server composer test:unit     # same as make test-native

# Against a running server (the dev server on port 8081 by default; pass a URL to test another)
scripts/smoke-test.sh      # the full request, claim, vote and ledger lifecycle
scripts/desk-smoke.sh      # organizer desk and privacy rules
scripts/approver-smoke.sh  # who may approve, national organizers, notifications (uses occ, needs the dev server)
scripts/intake-smoke.sh    # Forms intake end to end (needs the Forms app: occ app:enable forms)
node scripts/ui-smoke.cjs  # the real forms and screens in a headless browser (needs Playwright)

composer lint && composer cs:check && npm run lint && npm run stylelint   # fast checks
```

The smoke scripts create groups and users with `occ`, so run them against a disposable dev server, never a real one.

## Security

Where the data lives, who can read it, what is not protected and how to get raw data out:
[docs/data-and-access.md](docs/data-and-access.md).

- **Input**: hours must be above 0 (claims and offers up to 1000); text lengths are checked; categories, priorities and
  desk lookups are whitelisted; unknown desk fields are rejected.
- **Queries** use parameter binding through Nextcloud's query builder.
- **CSRF**: changing calls need a request token or `OCS-APIRequest: true`; read-only calls skip it.
- **Authorization** is checked on the server on every call from Nextcloud groups; the links hidden in the UI are only a
  convenience. Nobody can approve or vote on their own claim, or adjust their own balance.
- **Rate limits** per person, per 10 minutes: 50 new requests, 50 claims, and 100 each of comments, offers and votes.
  Beyond that the call gets HTTP 429 until the window passes.
- **Bulk reading**: every list is capped at 100 rows a page.
- **Evidence files**: a claim's `evidenceFileId` must be a file the claimant can read in their own Nextcloud files.
- **Not encrypted at rest**: everything is plain text in the database, so protect database access and backups.

## Troubleshooting

- **Blank page**: the frontend is not built. Run `make build`, or install a built package.
- **Migrations**: run `sudo -u www-data php occ upgrade` (or `occ migrations:migrate timebank latest`).
- **Facilitators cannot approve or see rows**: open **Chapters** and check that their facilitator group was found and that
  its name matches the chapter's group. A group named `Arkansas Facilitaters` will not pair with `Arkansas`.
- **No claim notices**: the Nextcloud Notifications app must be enabled and Nextcloud cron running.
- **Forms are not imported**: the Forms app must be enabled, the form added under **Form Intake**, and cron running;
  rejected submissions and their reasons are listed on that page.
- **Map shows no markers**: check the Content-Security-Policy host (see Map above) and that requests have coordinates.
- **Dev server stalls**: PHP's built-in server is single-threaded; `make native-start` already runs it with 4 workers.
- **Debug logging** in `config/config.php`: `'debug' => true, 'loglevel' => 0,` then `tail -f data/nextcloud.log`.

## Known limitations

- **Roles are Nextcloud groups you fill by hand.** There is no screen for assigning people to chapters or facilitator
  groups; the Chapters page shows what was found but does not edit it.
- **ZIP-to-state uses a built-in table** (generated October 2026). A few prefixes that straddle a state line are handled by
  exact ZIP; military and unassigned prefixes have no chapter; city chapters need their prefixes set.
- **Only claim notices exist.** Nobody is told when a request is cancelled, a flag hides a comment or an offer is accepted.
- **Claims are not tied to a request or to co-workers**, and a desk row is not a board request. See
  [docs/proposals/next-steps.md](docs/proposals/next-steps.md).
- **Approving a claim creates hours; nothing charges the person helped.** How hours are spent is still to be designed.
- **A facilitator working a row cannot see the requester's contact details** (organizer-only).
- **A member can flag a comment once, ever**: after a moderator keeps it, those members cannot flag it again.
- **Evidence files** for claims are not uploaded from the app yet; the API checks the claimant can read a given file id.
- **Rate limits are fixed** (see Security) and count every call, including refused ones; a burst of data entry by one
  person can hit them, and they then wait up to 10 minutes.
- **SQLite (all tests) and PostgreSQL (the API smoke test, on the Docker stack) have been run.** MySQL/MariaDB and
  PHP 8.4 are untested.

## Roadmap

See [docs/proposals/next-steps.md](docs/proposals/next-steps.md) for the maintained list. Not planned yet: mobile app,
calendar integration, CSV/PDF export, a NextTime-hosted public form, translations.

## Contributing

1. Fork the repository and create a branch.
2. Make your change and run the fast checks above plus the tests that apply (`CLAUDE.md` lists what counts as done).
3. Use conventional commit messages (`feat:`, `fix:`, `docs:` and so on); CI rejects others.
4. Open a pull request.

Code style is the Nextcloud coding standard (tabs), enforced by `composer cs:check` (`composer cs:fix` applies it) and
ESLint and stylelint for the frontend. Schema changes go in a new migration and a version bump in `appinfo/info.xml`.

## License

AGPL-3.0-or-later. See the [LICENSE](LICENSE) file.

## Support

- **Issues**: https://github.com/definitelynotaspren/NextTime-Dev/issues
- **Nextcloud community**: https://help.nextcloud.com
- **Developer docs**: https://docs.nextcloud.com/server/latest/developer_manual

## Acknowledgments

- Built with the [Nextcloud app framework](https://docs.nextcloud.com/server/latest/developer_manual/)
- Frontend: [Vue.js](https://vuejs.org/) and [Nextcloud Vue](https://github.com/nextcloud/nextcloud-vue), with
  [MapLibre GL](https://maplibre.org/) for the map
- Map tiles by [OpenFreeMap](https://openfreemap.org), data from OpenStreetMap contributors
