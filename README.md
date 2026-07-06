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

- **Nextcloud**: 29.x - 33.x
- **PHP**: 8.1 or higher
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

Navigate to **Settings → Administration → Time Bank** to view approval-workflow
options (require admin approval, enable community voting, required vote count,
negative balance allowance/limit).

> **Known limitation**: this settings page is currently **read-only/display
> only** — it has no save mechanism (no form submission or JavaScript wiring)
> and the values are not consulted anywhere in the backend. Regardless of what
> is shown, claims always require admin approval or an explicit
> "Send to Vote", voting always resolves at exactly 3 votes
> (`EarningService::checkVotingComplete()`), and negative balances are always
> rejected (`BalanceService::deductHours()`). Wiring this page up to actually
> persist and enforce these settings is tracked as follow-up work — see
> Known Limitations below.

Category management (create/edit/delete service categories and their earn
rate multipliers) has **no UI at all**, in this settings page or the app
itself. It's admin-only on the backend (`POST`/`PUT`/`DELETE
/api/categories...`) but must currently be done via direct API calls — see
API Documentation below.

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
- `PUT /api/requests/{id}` - Update a request *(owner only; no UI yet)*
- `POST /api/requests/{id}/complete` - Mark complete and select the winning volunteer *(owner only; no UI yet)*
- `POST /api/requests/{id}/cancel` - Cancel a request *(owner only; no UI yet)*

There is no delete endpoint for requests — use cancel instead.

#### Volunteers

- `POST /api/requests/{requestId}/volunteer` - Offer to help with a request
- `DELETE /api/volunteers/{id}` - Withdraw a volunteer offer *(owner only; no UI yet)*
- `POST /api/volunteers/{id}/accept` - Accept a volunteer offer *(request owner only; no UI yet)*
- `POST /api/volunteers/{id}/decline` - Decline a volunteer offer *(request owner only; no UI yet)*
- `GET /api/volunteers/my` - List the current user's own volunteer offers *(no UI yet)*

#### Comments

- `POST /api/requests/{requestId}/comments` - Add a comment to a request
- `DELETE /api/comments/{id}` - Delete a comment *(owner only; no UI yet)*

#### Earnings

- `POST /api/earnings/claim` - Submit an earning claim
- `GET /api/earnings/my` - Get the current user's claims
- `GET /api/earnings/pending` - Get pending claims (admin)
- `GET /api/earnings/voting` - Get claims currently in community voting
- `POST /api/earnings/{id}/approve` - Approve a claim (admin)
- `POST /api/earnings/{id}/reject` - Reject a claim (admin)
- `POST /api/earnings/{id}/send-to-vote` - Send a claim to community voting (admin)
- `POST /api/earnings/{id}/vote` - Cast a vote (`approve`/`reject`/`abstain`) on a claim in voting

#### Balance

- `GET /api/balance/my` - Get the current user's balance
- `GET /api/balance/all` - List all users' balances
- `POST /api/balance/adjust` - Manually credit/debit a user's balance (admin) *(no UI yet)*

#### Categories

- `GET /api/categories` - List service categories
- `GET /api/categories/{id}` - Get a single category
- `POST /api/categories` - Create a category (admin) *(no UI yet)*
- `PUT /api/categories/{id}` - Update a category (admin) *(no UI yet)*
- `DELETE /api/categories/{id}` - Delete a category (admin) *(no UI yet)*

#### Ledger

- `GET /api/ledger` - Get the public transaction ledger
- `GET /api/ledger/my` - Get the current user's transaction history
- `GET /api/ledger/user/{userId}` - Get another user's transaction history *(no UI yet)*

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
- `timebank_comments` - Comments on requests

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

The backend implements a full request lifecycle (open → volunteer → accept →
complete) with proper ownership checks, but the frontend doesn't yet expose
every step of it. As of v0.2.0, the following exist as working, authorized
API endpoints with **no corresponding UI**:

- **Accepting/declining a volunteer offer** and **marking a request
  complete** — a requester can currently receive volunteer offers on the
  Request Detail page but has no button to accept one, decline one, or close
  out the request. This is the biggest gap: without it the request/volunteer
  loop can't be finished end-to-end through the app today.
- **Editing or cancelling a request**, and **withdrawing a volunteer offer**
  (or viewing your own list of offers).
- **Deleting a comment.**
- **Category management** (create/edit/delete) and **manual balance
  adjustment** — both admin-only on the backend, but there's no UI, in the
  Vue app or in Admin Settings, to use them. Categories must currently be
  managed via direct API calls.
- **Viewing another user's transaction history** (`/api/ledger/user/{userId}`)
  — only "my transactions" and the full public ledger have pages.

Additionally, the **Admin Settings page is currently non-functional**: the
approval/voting/negative-balance toggles it displays aren't wired to a save
action and aren't read by any backend logic (see Configuration above for
specifics). Treat the values shown there as placeholders, not live
configuration, until this is implemented.

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
