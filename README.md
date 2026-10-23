# Nextcloud Time Bank

A community time banking application for Nextcloud that enables members to exchange services using hours as currency.

## Features

- **Request Board**: Post and browse community service requests
- **Skill Matching**: Find volunteers based on experience and completed work
- **Earning Claims**: Submit hours worked with optional evidence attachments
- **Admin Approval**: Workflow for verifying earning claims
- **External Voting**: Democratic oversight by community auditors
- **Public Ledger**: Transparent record of all transactions
- **Category Multipliers**: Different earn rates for specialized skills
- **Balance Tracking**: Real-time user balance and transaction history

## Requirements

- **Nextcloud**: 29 - 35. Every version in this range was run (29.0.16, 30.0.17, 31.0.14, 32.0.15, 33.0.10, 34.0.5 and 35.0.2); see CHANGELOG.
- **PHP**: 8.3 (the only version run; Nextcloud 35 itself requires it)
- **Database**: PostgreSQL 12+, MySQL 8.0+, or SQLite (small installs only)
- **Node.js**: 20.x or higher (only needed if building from source — the release tarball ships prebuilt assets)
- **npm**: 10.x or higher

## Installation

**Easiest path:** download `timebank.tar.gz` from the latest GitHub release
(it contains prebuilt frontend assets — no Node.js needed on the server),
extract it into your Nextcloud `apps/` or `custom_apps/` directory, and run
`occ app:enable timebank`. **See [INSTALL.md](INSTALL.md) for full
step-by-step instructions, including Nextcloud All-in-One (AIO).**

The options below are for development.

### Option 1: Docker Installation (Recommended for Development)

1. Clone the repository:
```bash
git clone https://github.com/definitelynotaspren/NextTime-Dev.git
cd NextTime-Dev
```

2. Create environment file:
```bash
cp .env.example .env
# Edit .env and update passwords and settings
```

3. Start the development environment:
```bash
make dev
```

4. Access Nextcloud at `http://localhost:8080` and complete the setup wizard

5. Enable the Time Bank app:
```bash
make docker-install-app
```

### Option 2: Manual Installation

1. Clone this repository into your Nextcloud apps directory:
```bash
cd /path/to/nextcloud/apps
git clone https://github.com/definitelynotaspren/NextTime-Dev.git timebank
cd timebank
```

2. Install dependencies:
```bash
make install
```

3. Build frontend assets:
```bash
make build
```

4. Enable the app in Nextcloud:
```bash
sudo -u www-data php /path/to/nextcloud/occ app:enable timebank
```

5. Run database migrations (automatic on first enable)

### Option 3: App Store Installation (Future)

Once published, install directly from the Nextcloud App Store.

## Development Setup

### Quick Start

```bash
# Install all dependencies
make install

# Start Docker development environment
make docker-up

# Build frontend (in watch mode)
make watch
```

### Available Commands

```bash
make help              # Show all available commands
make install           # Install PHP and Node.js dependencies
make build             # Build frontend assets
make watch             # Watch and rebuild frontend on changes
make test              # Run PHP unit tests
make lint              # Run PHP linter
make cs-check          # Check code style
make cs-fix            # Fix code style issues
make psalm             # Run static analysis
make appstore          # Build app package for App Store
make docker-up         # Start Docker environment
make docker-down       # Stop Docker environment
make docker-logs       # View Docker logs
make docker-shell      # Open shell in container
```

## Configuration

### Admin Settings

**Settings → Administration → Time Bank** now shows a read-only "how decisions are made" panel
instead of switches. The rules it describes are fixed in code on purpose (claims need a second person,
three votes decide a vote, balances never go below zero), and what admins *can* change inside NextTime is
done under **Categories** and **Adjustments** in the app, each change written to the public
**Governance Log** that every member can read.

The one adjustable setting is the number of hours (default 5, between 1 and 20) up to which a single admin
can adjust a balance without a second person.

### Category Multipliers

Different service categories can have different earn rate multipliers:
- Basic services: 1.0x (1 hour worked = 1 hour earned)
- Specialized skills: 1.5x (1 hour worked = 1.5 hours earned)
- High-demand services: 2.0x (1 hour worked = 2 hours earned)

## Usage

### For Community Members

1. **Browse Requests**: View the Request Board to find service needs
2. **Volunteer**: Offer to help with your skills and available hours
3. **Submit Claims**: After completing work, submit an earning claim
4. **Track Balance**: Monitor your time bank balance and transaction history

### For Administrators

1. **Review Claims**: Approve or reject earning claims
2. **Manage Categories**: Create and maintain service categories
3. **Monitor Activity**: View public ledger and system statistics
4. **Handle Disputes**: Send claims to community voting if needed

### For Auditors

1. **Vote on Claims**: Participate in community voting for disputed claims
2. **Review Evidence**: Examine submitted evidence for earning claims
3. **Provide Feedback**: Add comments to voting decisions

## API Documentation

All endpoints are plain JSON under `/index.php/apps/timebank/api/...` (no OCS
envelope). "(admin)" means the endpoint checks group membership itself and
returns 403 for non-admins; "(owner only)" means the service layer checks
that the caller created the resource being modified.

### Endpoints

#### Requests

- `GET /api/requests` - List service requests (supports `status`, `categoryId`, `priority`, `limit`, `offset` filters)
- `GET /api/requests/{id}` - Get request details, volunteers, and comments
- `GET /api/requests/my` - List the current user's own requests
- `POST /api/requests` - Create a new request
- `PUT /api/requests/{id}` - Update a request *(owner only, while it is open)*
- `POST /api/requests/{id}/complete` - Mark complete: every accepted volunteer is completed and credited for the hours they proposed. `volunteerId` is optional; if given it must be an accepted volunteer of this request *(owner only)*
- `POST /api/requests/{id}/cancel` - Cancel a request that is still open or in progress *(owner only)*

There is no delete endpoint for requests — use cancel instead.

##### Request lifecycle

A request is `open` until the requester accepts an offer, then `in_progress`, and ends as
`completed` or `cancelled` (both final). Several people can offer, offers are still accepted
while a request is in progress, and the requester can accept as many as the job needs. Nobody
is declined automatically, and each accepted volunteer is credited for their own proposed hours
(hour for hour, not split). Completing a request does not credit hours: volunteers claim them
through the earning-claim flow, which an admin or 3 community votes approve. Moves the rules
refuse return HTTP 400 with a plain message; acting on someone else's request or offer returns 403.

#### Volunteers

- `POST /api/requests/{requestId}/volunteer` - Offer to help with a request
- `DELETE /api/volunteers/{id}` - Withdraw an offer. Withdrawing after being accepted needs a `reason` (3+ characters), which is kept in the log of withdrawn help, and reopens the request if no accepted volunteers remain *(offer owner only)*
- `POST /api/volunteers/{id}/accept` - Accept a volunteer offer *(request owner only)*
- `POST /api/volunteers/{id}/decline` - Decline a volunteer offer *(request owner only)*
- `GET /api/volunteers/my` - The current user's own offers, each with its request's title and status
- `GET /api/withdrawals/my` - The current user's own log of withdrawn help, with reasons

#### Comments

- `POST /api/requests/{requestId}/comments` - Add a comment to a request
- `POST /api/comments/{id}/flag` - Flag a comment that is not yours, once, with an optional `reason`. At 3 distinct flags it is hidden from members (not deleted). Flag counts are public; who flagged and why is for moderators
- `GET /api/comments/flagged` - Comments with counting flags, with reasons *(moderators only)*
- `POST /api/comments/{id}/dismiss` - Clear the flags and show the comment again *(moderators only)*
- `DELETE /api/comments/{id}` - Remove a comment. The text is kept for the audit trail, and members see "removed" with who removed it *(moderators only; authors cannot delete their own)*

Moderators are Nextcloud administrators and members of the `timebank-organizer` group.

#### Earnings

- `POST /api/earnings/claim` - Submit an earning claim
- `GET /api/earnings/my` - Get the current user's claims
- `GET /api/earnings/pending` - Pending claims you may decide on, each with `decidingAs` (`admin`, `facilitator` or `fallback`). Never your own claims
- `GET /api/earnings/voting` - Get claims currently in community voting
- `POST /api/earnings/{id}/approve` - Approve a claim *(an approver for the claimant, see Roles below; never the claimant)*
- `POST /api/earnings/{id}/reject` - Reject a claim, with a reason *(same)*
- `POST /api/earnings/{id}/send-to-vote` - Send a claim to community voting *(same)*
- `POST /api/earnings/{id}/vote` - Cast a vote (`approve`/`reject`/`abstain`) on a claim in voting

#### Balance

- `GET /api/balance/my` - Get the current user's balance
- `GET /api/balance/all` - List all users' balances

#### Adjustments and the governance log

- `GET /api/adjustments` - Recent balance adjustments, what you may do to each, and the threshold *(admins and facilitators)*
- `POST /api/adjustments` - Adjust a member's balance: `userId`, `hours` (negative to deduct), `reason` (10+ characters, public). Up to the threshold it is applied at once; larger ones wait as `pending` for a second person *(admins; nobody adjusts their own balance)*
- `POST /api/adjustments/{id}/approve` and `/reject` (`note`) - Decide a pending adjustment *(another admin, or a facilitator of one of the member's chapters; never the proposer or the member)*
- `POST /api/adjustments/{id}/withdraw` - The proposer takes it back. Pending ones expire after 14 days
- `PUT /api/settings/adjustment-threshold` - `hours` (1 to 20) and `reason` *(admins; logged)*
- `GET /api/governance` - The public log of category changes, adjustments and setting changes *(every member)*

The old `POST /api/balance/adjust` is removed: it changed a balance before writing the ledger, skipped checks and
needed no second person.

#### Categories

- `GET /api/categories` - List service categories, archived ones included (flagged `archived`)
- `GET /api/categories/{id}` - Get a single category
- `POST /api/categories` - Create a category (admin)
- `PUT /api/categories/{id}` - Update a category (admin). The earn rate must be above 0 and at most 5
- `DELETE /api/categories/{id}` - Delete a category (admin). Refused while it is in use (a request in progress, an open request with offers, a claim waiting) and while any past request, claim or statistic points at it
- `POST /api/categories/{id}/archive`, `/restore` - Hide a category from new requests, or bring it back (admin). Old requests keep showing it
- `GET /api/categories/{id}/usage` - What blocks deleting it (admin)

#### Map

- `GET /api/map/requests` - GeoJSON of open requests (points rounded to about 1 km; no contact fields)
- `GET /api/layers/{layer}/feed` - GeoJSON of published rows for `point_snapped` layers (`needs_offers`, `issue_reports`)

#### Organizer desk (organizer or facilitator roles)

#### Roles

Roles come from Nextcloud groups (create them under Users, or with `occ group:add`):

| Group | Meaning |
|-------|---------|
| `chapter-<slug>` (for example `chapter-mt-hood`) | You belong to that state or chapter. Gives no powers. |
| `chapter-<slug>-facilitator` | Facilitator role for that chapter. Powerless alone. |
| `GSUS` | Fallback approvers, for members whose chapter has no facilitator (and members with no chapter). Another group name can be set with the app setting `fallback_approver_group`. |
| `timebank-organizer` | Organizer desk; also moderates comments. |
| `timebank-facilitator` | Organizer desk access, without chapter rows. Does not let anyone approve claims. |

**Facilitating a chapter takes both `chapter-<slug>` and `chapter-<slug>-facilitator`.** A person may decide on a
claim, or approve a large balance adjustment, for member U (never their own) when they are a Nextcloud admin,
or they facilitate a chapter U belongs to, or none of U's chapters has a facilitator and they are in `GSUS`.
`GSUS` never overrides a chapter that has facilitators. Nextcloud admins are not organizers unless they are
also in the organizer group.

When a claim is submitted, the facilitators of the claimant's chapters are told in Nextcloud's notifications
(else `GSUS`, else the admins). The notice names who, hours and category, never the description, and clears for
everyone once the claim is decided. After 3 days they get a reminder, and after 7 days the admins are told too
(an hourly background job; Nextcloud needs its normal cron). It needs the Nextcloud *Notifications* app, which
is enabled on a normal install.

- `GET /api/schema` - layers, fields, visibility levels, lookups, chapters (organizer or facilitator)
- `GET /api/layers/{layer}/rows` - rows you may see; filters `assigned=me|unassigned`, `source=form|manual`
- `POST /api/layers/{layer}/rows` - add a row by hand (organizer); starts unpublished unless `publish=yes`
- `POST /api/layers/{layer}/rows/{id}/assign` - claim a row (organizer; optional `status`)
- `DELETE /api/layers/{layer}/rows/{id}/assign` - release a row you hold
- `POST /api/layers/{layer}/rows/{id}/status` - set `assigned`, `in_progress` or `done` on a row you hold
- `GET /api/assignments` - rows assigned to you; filters `layer`, `source`

Fields are filtered by visibility on every read. A field marked `organizer` is
never returned to a facilitator or member, and a field marked `chapter` only
to that chapter's facilitators. Undeclared fields are treated as organizer-only.
The survey layer is aggregate-only and has no row endpoints.

#### Ledger

- `GET /api/ledger` - Get the public transaction ledger
- `GET /api/ledger/my` - Get the current user's transaction history
- `GET /api/ledger/user/{userId}` - Get another user's transaction history (names in the app link to it)

### Example API Calls

#### Create a Request

```bash
curl -X POST http://localhost:8080/apps/timebank/api/requests \
  -H "Content-Type: application/json" \
  -u admin:admin \
  -d '{
    "title": "Need help with garden cleanup",
    "description": "Looking for assistance clearing overgrown garden area, approximately 3-4 hours of work",
    "categoryId": 1,
    "hoursBudget": 4,
    "priority": "normal",
    "location": "123 Main Street"
  }'
```

#### Submit Earning Claim

```bash
curl -X POST http://localhost:8080/apps/timebank/api/earnings/claim \
  -H "Content-Type: application/json" \
  -u volunteer:password \
  -d '{
    "categoryId": 1,
    "hoursClaimed": 3.5,
    "description": "Completed garden cleanup - cleared overgrown area, removed debris"
  }'
```

## Database Schema

### Tables

- `timebank_categories` - Service categories with earn rate multipliers
- `timebank_requests` - Service requests posted by community members
- `timebank_volunteers` - Volunteer offers for service requests
- `timebank_earnings` - Earning claims submitted by volunteers
- `timebank_votes` - Community votes on earning claims
- `timebank_balances` - User time bank balances
- `timebank_transactions` - Complete transaction history (public ledger)
- `timebank_user_stats` - User statistics by category
- `timebank_comments` - Comments on requests (soft-removed ones keep their text and who removed them)
- `timebank_comment_flags` - Members' flags on comments, with reasons
- `timebank_adjustments` - Manual balance adjustments and who proposed and approved them
- `timebank_governance_log` - The public, append-only log of admin decisions

## Testing

### Run Unit Tests

```bash
make test
```

### Run Code Style Checks

```bash
make cs-check
```

### Run Static Analysis

```bash
make psalm
```

## Security

Where the data lives, who can read it, what is not protected, and how to get raw
data out are written up in [docs/data-and-access.md](docs/data-and-access.md).

### Input Validation

All API endpoints validate input parameters:
- Hours must be positive numbers (max 1000)
- Descriptions require minimum character lengths
- Category and priority values are whitelisted
- User permissions are checked on all sensitive operations

### SQL Injection Prevention

All database queries use parameter binding through Nextcloud's Query Builder.

### CSRF Protection

CSRF tokens are required on all POST/PUT/DELETE endpoints (except API routes marked NoCSRFRequired for external access).

### Admin Authorization

Admin-only endpoints verify user group membership before allowing access.

## Troubleshooting

### Frontend Build Issues

```bash
# Clean and reinstall
make clean
make install
make build
```

### Database Migration Issues

```bash
# Run migrations manually
sudo -u www-data php occ migrations:execute timebank latest
```

### Docker Issues

```bash
# View logs
make docker-logs

# Rebuild containers
make docker-rebuild

# Access container shell
make docker-shell
```

### Enable Debug Mode

Add to `config/config.php`:
```php
'debug' => true,
'loglevel' => 0,
```

View logs:
```bash
tail -f /path/to/nextcloud/data/nextcloud.log
```

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Make your changes
4. Run tests and code style checks (`make test && make cs-check`)
5. Commit your changes (`git commit -m 'Add amazing feature'`)
6. Push to the branch (`git push origin feature/amazing-feature`)
7. Open a Pull Request

### Code Style

This project follows PSR-12 coding standards. Use `make cs-fix` to automatically fix style issues.

## License

This project is licensed under the AGPL-3.0-or-later License - see the LICENSE file for details.

## Support

- **Issues**: https://github.com/definitelynotaspren/NextTime-Dev/issues
- **Nextcloud Community**: https://help.nextcloud.com
- **Developer Docs**: https://docs.nextcloud.com/server/latest/developer_manual

## Known Limitations

The request lifecycle (open → volunteer → accept → complete) now works end to
end in the UI, including accepting several volunteers, declining, withdrawing
with a reason, and completing. As of v0.6.0, requesters can edit (while open) and cancel, "My Activity" lists your
requests, offers and withdrawn help, comments are moderated by community flags, and admins manage
categories and balance adjustments in the app. Remaining gaps:

- **Roles are Nextcloud groups you create and fill by hand,** including `chapter-<slug>-facilitator` and `GSUS`.
  There is no screen in NextTime for assigning them, and chapters are defined in code
  (`lib/Schema/SchemaSpec.php`: `mt-hood`, `gresham`).
- **Claim notices need the Nextcloud Notifications app and cron.** Other events (a cancelled request, a hidden
  comment, an accepted offer) are not notified.
- **A large balance adjustment needs a second approver the same way claims do** (another admin, a facilitator of
  the member's chapter, or `GSUS` when that chapter has none).
- **Nobody is told** when a request is cancelled, a flag hides a comment, or an offer is accepted. Tell the people involved.
- A member can flag a comment once, ever: after a moderator dismisses flags, those members cannot flag
  the same comment again.

Organizer desk: rows can only be entered by hand (`source=manual`); form submissions do
not create rows yet.

## Map

- A request appears on the map only if it has coordinates: pick a point on the map in the
  form, or type `lat, lng`. A free-text address (for example "123 Main Street") is saved
  but not plotted; geocoding is not implemented.
- The basemap is [OpenFreeMap](https://openfreemap.org) (OpenStreetMap data, no API key).
  Viewers' browsers connect to `tiles.openfreemap.org`. To use another tile source, change
  `MAP_STYLE_URL` in `src/composables/useMap.ts` **and** `MAP_HOST` in
  `lib/Controller/PageController.php`: Nextcloud's Content-Security-Policy blocks any host
  not listed there.
- Map and feed output is rounded to about 1 km and never includes contact fields.

## Roadmap

- [ ] Mobile app support
- [ ] Email notifications for claim approvals
- [ ] Advanced reporting and analytics
- [ ] Integration with Nextcloud Calendar for scheduled services
- [ ] Reputation system based on completed work
- [ ] Multi-language support
- [ ] Export transaction history to CSV/PDF
- [ ] REST API authentication tokens
- [ ] Webhook support for external integrations

## Acknowledgments

- Built with [Nextcloud App Development Framework](https://docs.nextcloud.com/server/latest/developer_manual/)
- Frontend powered by [Vue.js](https://vuejs.org/) and [Nextcloud Vue](https://github.com/nextcloud/nextcloud-vue)
- Icons from [Material Design Icons](https://materialdesignicons.com/)
