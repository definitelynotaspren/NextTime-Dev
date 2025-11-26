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

- **Nextcloud**: 27.x - 32.x
- **PHP**: 8.1 or higher
- **Database**: PostgreSQL 12+ or MySQL 8.0+ (PostgreSQL recommended)
- **Node.js**: 20.x or higher (for building frontend)
- **npm**: 11.3 or higher

## Installation

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

Navigate to **Settings → Administration → Time Bank** to configure:

- **Category Management**: Create service categories with custom earn rate multipliers
- **Voting Settings**: Configure voting thresholds and quorum requirements
- **System Settings**: Set approval workflows and notification preferences

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

### Endpoints

#### Requests

- `GET /api/requests` - List all service requests
- `GET /api/requests/{id}` - Get request details
- `POST /api/requests` - Create new request
- `PUT /api/requests/{id}` - Update request
- `DELETE /api/requests/{id}` - Delete request

#### Earnings

- `POST /api/earnings/claim` - Submit earning claim
- `GET /api/earnings/my` - Get user's claims
- `GET /api/earnings/pending` - Get pending claims (admin)
- `POST /api/earnings/{id}/approve` - Approve claim (admin)
- `POST /api/earnings/{id}/reject` - Reject claim (admin)
- `POST /api/earnings/{id}/send-to-vote` - Send to voting (admin)
- `POST /api/earnings/{id}/vote` - Submit vote

#### Balance

- `GET /api/balance` - Get current user balance
- `GET /api/balance/transactions` - Get transaction history

#### Ledger

- `GET /api/ledger` - Get public transaction ledger
- `GET /api/ledger/stats` - Get system statistics

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
