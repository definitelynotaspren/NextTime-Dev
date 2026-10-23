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
| `oc_timebank_requests` | title, description, free-text `location`, `latitude`/`longitude`, requester | Visible to every logged-in member. Free text can contain an address. |
| `oc_timebank_volunteers` | who offered, hours, message, status | Visible to members on the request page |
| `oc_timebank_comments` | comments on requests | Visible to members |
| `oc_timebank_withdrawals` | log of withdrawn help, with reasons | The volunteer, and the requester of that request |
| `oc_timebank_earnings`, `oc_timebank_votes` | hour claims, approvals, community votes | Your own claims; admins see pending ones; claims in a vote are visible so members can vote |
| `oc_timebank_balances`, `oc_timebank_transactions` | balances and the public ledger | Public to members by design |
| `oc_timebank_user_stats`, `oc_timebank_categories` | per-category history, service categories | Members |
| `oc_timebank_layer_rows` | organizer-desk rows: needs, offers, actions, reports. One JSON column, `data`, holds the fields | **The most sensitive table.** Contact details and exact coordinates live here. |

## How the data is protected

1. **Login is required for everything.** A visitor with no login gets `401` from every endpoint
   (checked: requests, feeds, rows, map, ledger, schema).
2. **Roles come from Nextcloud groups** (`timebank-organizer`, `timebank-facilitator`,
   `chapter-<slug>`), read on the server for each request. A plain member gets `403` on the organizer
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
- **Any logged-in member can read all requests,** including their `location` text, volunteer user
  names, messages and comments. That is how the board works today.
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

## Options if you want data to leave NextTime

None of these exist yet. Each needs a decision from you.

- **An export button for organizers:** CSV and GeoJSON downloads, filtered by the same visibility rules, so
  nobody can export a field they cannot see.
- **A deliberately public, anonymised map feed:** published rows only, public fields, rounded points, at a
  URL that needs no login, so a public map can show it. This is a consent decision, not just a technical one.
- **Importing form submissions** from Nextcloud Forms into the organizer desk (rows with `source=form`).
