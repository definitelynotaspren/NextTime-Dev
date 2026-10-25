# Where the data lives, who can see it, and how to get it out

Everything below was checked against the running dev server (Nextcloud 31, SQLite). Where something
is typical but was not run here, it says so.

## Where the data lives

**In the Nextcloud database, in tables the app creates.** There are no spreadsheets and no files
behind the screens: every request, offer, comment, claim, vote and ledger entry is a row in a table.
The app reads and writes through Nextcloud's database layer, and the tables are created by the app's
migrations (`lib/Migration/`). The table prefix is Nextcloud's (`oc_` here). In the dev setup the
database is one SQLite file, `data/owncloud.db`; a production install uses the PostgreSQL or MySQL
database chosen at install time (only SQLite has been run here).

NextTime is **not connected to any spreadsheet, Nextcloud Forms, or the mapping app's workbook**. The
organizer desk uses the same layer and field definitions as that mapping app (`lib/Schema/SchemaSpec.php`
is a copy), but its rows live in `oc_timebank_layer_rows`, and nothing imports from or exports to the
workbook yet.

| Table | Holds | Sensitivity |
|-------|-------|-------------|
| `oc_timebank_requests` | title, description, `zip`, state, free-text `location`, `latitude`/`longitude`, requester | Visible to every logged-in member, except `location` and the point: only the requester, the facilitators of the request's state and accepted volunteers get those (`RequestPresenter`). |
| `oc_timebank_volunteers` | who offered, hours, message, status | Visible to members on the request page |
| `oc_timebank_comments` | comments on requests | Members, except flagged-hidden or removed ones: the server withholds their text, moderators still see it |
| `oc_timebank_adjustments` | manual balance adjustments: member, hours, reason, proposer, approver | Admins and facilitators; the result is public in the ledger and governance log |
| `oc_timebank_governance_log` | who changed what, with old and new values and reasons | **Public to every member by design.** Never put personal details in a reason |
| `oc_timebank_intake_log` | which Nextcloud Forms submissions were imported or rejected, and why | Admins and organizers; holds IDs and reasons, no answers |
| `oc_timebank_comment_flags` | who flagged which comment, and why | Names and reasons: moderators only. Counts are public |
| `oc_timebank_withdrawals` | log of withdrawn help, with reasons | The volunteer, and the requester of that request |
| `oc_timebank_earnings`, `oc_timebank_votes` | hour claims, approvals, community votes | Your own claims; admins see pending ones; claims in a vote are visible so members can vote |
| `oc_timebank_balances`, `oc_timebank_transactions` | balances and the public ledger | Public to members by design |
| `oc_timebank_user_stats`, `oc_timebank_categories` | per-category history, service categories | Members |
| `oc_timebank_layer_rows` | organizer-desk rows: needs, offers, actions, reports. One JSON column, `data`, holds the fields | **The most sensitive table.** Contact details and exact coordinates live here. |

## How the data is protected

1. **Login is required for everything.** A visitor with no login gets `401` from every endpoint
   (checked: requests, feeds, rows, map, ledger, schema).
2. **Roles come from Nextcloud groups** (`National Organizers`, `timebank-facilitator`,
   `<State>` and `<State> Facilitators`; the legacy `timebank-organizer` and `GSUS` still count as national organizers), read on the server for each request. A plain member gets `403` on the organizer
   endpoints (checked).
3. **Fields are filtered on every read of organizer-desk data.** Each field has a visibility level
   (public, facilitator, chapter, organizer). Fields a viewer may not see are removed from the response,
   not blanked, and an undeclared field is treated as organizer-only. Tests cover every layer and field.
4. **Feeds carry only public fields.** `GET /api/layers/{layer}/feed` returns only *published* rows,
   only their public fields, with coordinates rounded to about 1 km. The survey layer is never returned.
5. **Withdrawal reasons** go only to the volunteer and the requester of that request.

### What is not protected, and the limits

- **The database holds everything in plain text.** Fields marked "organizer only" are protected by the
  app, not by encryption: `requester_contact` is stored readable in `oc_timebank_layer_rows.data`
  (checked). Anyone with database access, or the server's `config.php` credentials, or an admin shell,
  can read every table, including exact coordinates. Nextcloud's server-side encryption covers files,
  not these tables. Treat database backups as sensitive.
- **Free text can hold personal details.** `summary`, request titles, descriptions, comments and the
  `location` text are shown as typed. Nothing can reliably detect a name or address in a sentence, so
  the form wording and organizer review are the defence.
- **Any logged-in member can read all requests,** including their ZIP, volunteer user names, messages
  and comments. That is how the board works today. The exact `location` and point are held back (see the
  table above), but anything typed into a title or description is shown as typed.
- **Nextcloud Forms keeps its own copy.** Answers submitted through a form stay in the Forms app's tables
  (`oc_forms_v2_*`), with the submitter's account unless the form is anonymous. NextTime imports only the answers that
  match a desk field and not the submitter. Treat the Forms tables like the desk table, and delete old submissions in
  Forms when you no longer need them.
- **There is no access log** of who read what, and no per-field encryption.
- `earnings.evidence_file_id` exists for attached evidence, but nothing uploads or reads evidence yet.

## How to see the raw data outside NextTime

Today there is **no export button, no CSV or Excel download, and no public URL.** There are four routes,
and all but the last need a Nextcloud account.

1. **The JSON API, with an app password.** In Nextcloud: Personal settings, Security, "Create new app
   password". Use it instead of your real password, and revoke it when done (checked: after revoking, the
   same call returns `401`).
   ```bash
   API=https://your-server/index.php/apps/timebank/api
   curl -u you:APP_PASSWORD -H 'OCS-APIRequest: true' "$API/requests"                 # every member
   curl -u you:APP_PASSWORD -H 'OCS-APIRequest: true' "$API/layers/needs_offers/rows" # organizers/facilitators
   ```
   You see exactly what you would see in the app: an organizer gets all fields, a facilitator
   gets only their chapter's rows and no organizer-only fields.
2. **CSV for a spreadsheet,** from the same call, one line per row (checked):
   ```bash
   curl -s -u you:APP_PASSWORD -H 'OCS-APIRequest: true' "$API/layers/needs_offers/rows" |
     jq -r '(["uid","source","chapter","assignedTo","summary","zip","urgency","status"] | @csv),
            (.[] | [.fields.uid, .source, .chapter, .assignedTo, .fields.summary, .fields.zip,
                    .fields.urgency, .fields.status] | @csv)' > needs_offers.csv
   ```
3. **GeoJSON for a map tool** (QGIS, geojson.io and similar): `GET $API/layers/needs_offers/feed` and
   `GET $API/map/requests`. Both are standard GeoJSON in longitude, latitude order. They need a login,
   so a hosted tool such as uMap cannot fetch them on its own; a public map would need the decision below.
4. **Directly from the database, if you run the server.** The table names are in the list above. In the
   dev setup, for example:
   ```bash
   python3 -c "import sqlite3,json; c=sqlite3.connect('data/owncloud.db'); \
   [print(r[0], json.loads(r[1]).get('summary')) for r in c.execute('select uid, data from oc_timebank_layer_rows')]"
   ```
   For PostgreSQL or MySQL the same tables exist under the same names, and `data` is JSON text (not run here).
   This route bypasses every protection above, so it is for the server's administrators only.

## Requiring a WireGuard tunnel (or any VPN) for sensitive data

**Nothing here has been built or run.** This is the design and what each piece does and does not buy you.

### Two different things, and only one is the one you asked for

1. **Putting the database behind the tunnel.** The database listens only on the WireGuard (or
   localhost) address, and the firewall drops everything else. This stops anyone on the internet, or any
   other machine, from talking to the database. It is worth doing. **It does not make a person need the VPN
   to view PII**, because the Nextcloud server must reach the database and it reads PII for whoever is
   logged in, wherever they are.
2. **Making the VPN a condition of seeing PII or using admin powers.** This has to be enforced in front of
   the app (reverse proxy) or inside it, using the network address the request arrives from.

### Recommended setup, in order of effort

- **A. Database behind the tunnel (no code).** PostgreSQL: `listen_addresses` set to the WireGuard
  address, `pg_hba.conf` allowing only the tunnel subnet. If the database is on another host, make it a
  WireGuard peer of the app server. SQLite has no network port, so there is nothing to hide, but the file
  itself (`data/owncloud.db`) and backups need the same care.
- **B. Gate the sensitive URLs at the reverse proxy (no code).** Serve NextTime normally on the public
  hostname, but refuse these paths unless the request comes from the tunnel subnet. It is a network
  rule, so it holds even for someone with a valid password or app password. The paths, from the routes in
  this app:
  - PII (organizers and facilitators): `/index.php/apps/timebank/api/layers/`, `/api/assignments`,
    `/api/schema`, and the Organizer Desk screens that call them.
  - Admin powers: `/api/adjustments`, `/api/settings/`, `/api/balance/all`, `/api/earnings/pending`, `/api/earnings/{id}/approve`,
    `/reject`, `/send-to-vote`, `POST|PUT|DELETE /api/categories`, and Nextcloud's own `/settings/admin`,
    `/settings/users`, `/settings/apps`, and `/ocs/v2.php/cloud/users` and `/ocs/v2.php/cloud/groups`. The
    last two matter most: whoever can add a user to `timebank-organizer` can give themselves PII access.
  ```nginx
  # Example only, not run. 10.8.0.0/24 is the WireGuard subnet.
  location ~ ^/index\.php/apps/timebank/api/(layers|assignments|schema|balance|earnings/pending|earnings/\d+/(approve|reject|send-to-vote)) {
      allow 10.8.0.0/24;
      deny all;
      try_files $uri /index.php$request_uri;   # then your usual PHP handling
  }
  ```
  Two traps: set Nextcloud's `trusted_proxies` and make the proxy overwrite (not append to) `X-Forwarded-For`,
  or the app sees the proxy's address, or a spoofed one. And block the same paths on every hostname that
  reaches the server, or the rule is bypassed by the other name.
- **C. Check it inside the app too (small code change, needs your go-ahead).** A setting such as
  `pii_networks` (a list of CIDRs). When the request's address, as Nextcloud sees it, is outside the list,
  the server treats the viewer as a plain member for organizer-desk data: organizer-only and chapter fields
  are removed exactly as `FieldPolicy` does today, and the desk endpoints answer `403`. It fails closed, so an
  empty or broken setting means no PII. This is defense in depth: if a proxy rule is mistyped or an upgrade
  adds a new route, the app still refuses. It also lets the screen say "connect to the VPN to see this".

### What stays out of reach of this

- **Regular members cannot all be on a VPN**, so the board, comments, names and request ZIPs stay visible
  to any logged-in member over the normal site. Exact locations are only sent to the requester, the
  state's facilitators and accepted volunteers; keep addresses out of titles and descriptions.
- **A tunnel proves the device, not the person.** Add Nextcloud's two-factor login (TOTP app) and, in
  Settings, Security, enforce it for the `timebank-organizer`, `timebank-facilitator` and admin groups.
- **Whoever has a shell or database credentials on the server bypasses all of it,** including admins. The
  VPN narrows who can *reach* the server; it cannot stop its own administrators. What helps there: keep the
  number of server administrators small, use the Nextcloud *Auditing / Logging* app to record group changes
  and logins, treat backups as sensitive, and keep NextTime's own decisions public (the proposed public log
  of settings changes). Removing someone's WireGuard peer ends their network access at once.

### How the same rule applies to admins

Admins get the same network rule plus more: the admin paths in B are tunnel-only, two-factor is
enforced for the admin group, and large balance adjustments need a second person (decision 5), so one
compromised admin cannot move hours alone. Nextcloud admins are *not* organizers unless they are in the
`timebank-organizer` group, so the admin role by itself does not expose organizer-desk PII in the app.
(They can still read the database, see the limit above.)

### What I would build, if you say go

C alone: `pii_networks` setting, a check in the layer and assignment endpoints, a "VPN required" message in
the desk, unit tests for in/out of range and for a spoofed forwarded header, and an entry in the Admin
Settings page. Size S. A and B are server configuration, not code; I can write them up as a step-by-step
install note for your host if you tell me which web server and database you run.

## Options if you want data to leave NextTime

None of these exist yet. Each needs a decision from you.

- **An export button for organizers:** CSV and GeoJSON downloads, filtered by the same visibility rules, so
  nobody can export a field they cannot see.
- **A deliberately public, anonymised map feed:** published rows only, public fields, rounded points, at a
  URL that needs no login, so a public map can show it. This is a consent decision, not just a technical one.
- **Importing form submissions** from Nextcloud Forms into the organizer desk (rows with `source=form`).
