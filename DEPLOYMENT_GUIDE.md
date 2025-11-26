# Nextcloud Time Bank - Deployment Guide

This guide covers everything needed to deploy the Time Bank app from development to production.

## Pre-Installation Checklist

### ✅ Configuration Verification

- [x] **App ID**: `timebank` (consistent across all files)
- [x] **Namespace**: `OCA\TimeBank` (PSR-4 compliant)
- [x] **Version**: 0.1.0
- [x] **Nextcloud Compatibility**: 27.x - 32.x
- [x] **PHP Version**: 8.1+
- [x] **Database**: All tables use `timebank_` prefix
- [x] **Proper indexes**: Created for all foreign keys and query fields

### ✅ Code Quality

- [x] **Namespaces**: All classes use correct `OCA\TimeBank\*` namespace
- [x] **Input Validation**: All API endpoints validate user input
- [x] **SQL Injection Prevention**: All queries use parameter binding
- [x] **CSRF Protection**: Enabled on all mutation endpoints
- [x] **Admin Authorization**: Verified on sensitive operations
- [x] **PSR-12 Compliance**: Code follows PHP standards

### ✅ Database Schema

Tables and indexes are optimized for common queries:

| Table | Indexes | Purpose |
|-------|---------|---------|
| timebank_categories | name | Fast category lookup |
| timebank_requests | requester_id, category_id, status, created_at | Filter and sort requests |
| timebank_volunteers | request_id, volunteer_id, unique(request_id, volunteer_id) | Prevent duplicate offers |
| timebank_earnings | user_id, status | Admin approval queue |
| timebank_votes | earning_id, unique(earning_id, voter_id) | Prevent duplicate votes |
| timebank_transactions | from_user_id, to_user_id, created_at | Ledger queries |
| timebank_balances | user_id (primary) | Fast balance lookup |
| timebank_user_stats | unique(user_id, category_id) | Skill tracking |
| timebank_comments | request_id, parent_id | Threaded comments |

### ✅ Frontend Setup

- [x] **Vue 3**: Modern reactive framework
- [x] **Pinia**: State management
- [x] **Vue Router**: Client-side routing
- [x] **Vite**: Fast build tooling
- [x] **TypeScript**: Type-safe development
- [x] **Nextcloud Vue**: UI components

### ✅ Testing Infrastructure

- [x] **PHPUnit**: Unit testing framework
- [x] **Example Tests**: Transaction entity tests
- [x] **Test Suite**: Properly configured in phpunit.xml
- [x] **Code Coverage**: Ready for implementation

### ✅ Deployment Resources

- [x] **Dockerfile**: Complete Nextcloud + app environment
- [x] **docker-compose.yml**: Multi-container setup with PostgreSQL
- [x] **Makefile**: Build automation commands
- [x] **.env.example**: Configuration template
- [x] **README.md**: Comprehensive documentation

## Installation Methods

### Method 1: Docker Development (Recommended)

Perfect for local development and testing.

```bash
# Clone repository
git clone https://github.com/definitelynotaspren/NextTime-Dev.git
cd NextTime-Dev

# Set up environment
cp .env.example .env
# Edit .env with secure passwords

# Start everything
make dev

# Access at http://localhost:8080
# Default credentials: admin / admin (change immediately!)
```

**What this does:**
1. Builds Nextcloud container with all required PHP extensions
2. Creates PostgreSQL database
3. Sets up Redis for caching
4. Installs PHP dependencies
5. Installs Node.js dependencies
6. Builds frontend assets

### Method 2: Production Deployment

For deploying to an existing Nextcloud installation.

#### Prerequisites

```bash
# Check PHP version (must be 8.1+)
php -v

# Check required extensions
php -m | grep -E 'pdo|gd|zip|intl|mbstring|curl|xml'

# Check Node.js version (must be 20+)
node -v
npm -v
```

#### Installation Steps

1. **Clone to apps directory:**

```bash
cd /var/www/nextcloud/apps
sudo -u www-data git clone https://github.com/definitelynotaspren/NextTime-Dev.git timebank
cd timebank
```

2. **Install dependencies:**

```bash
# PHP dependencies
sudo -u www-data composer install --no-dev --optimize-autoloader

# Node.js dependencies
sudo -u www-data npm ci --only=production
```

3. **Build frontend:**

```bash
sudo -u www-data npm run build
```

4. **Set permissions:**

```bash
chown -R www-data:www-data /var/www/nextcloud/apps/timebank
chmod -R 755 /var/www/nextcloud/apps/timebank
```

5. **Enable app:**

```bash
sudo -u www-data php /var/www/nextcloud/occ app:enable timebank
```

6. **Verify installation:**

```bash
sudo -u www-data php /var/www/nextcloud/occ app:list | grep timebank
```

### Method 3: App Store (Future)

Once submitted to the Nextcloud App Store:

1. Login to Nextcloud as admin
2. Navigate to Apps → Organization
3. Search for "Time Bank"
4. Click "Download and enable"

## Configuration

### Initial Setup

1. **Admin Settings**

Navigate to: `Settings → Administration → Time Bank`

Configure:
- Default categories
- Approval workflow
- Voting requirements

2. **Seed Categories**

The app includes a migration that seeds default categories:

```php
// lib/Migration/SeedCategories.php
- General Help (1.0x multiplier)
- Skilled Trades (1.5x multiplier)
- Professional Services (2.0x multiplier)
```

3. **User Permissions**

Time Bank uses Nextcloud's built-in user management:

| Role | Capabilities |
|------|-------------|
| User | Create requests, volunteer, submit claims, vote |
| Admin | Approve/reject claims, manage categories, view all data |

### Database Configuration

For optimal performance with PostgreSQL:

```sql
-- Check indexes are created
SELECT tablename, indexname
FROM pg_indexes
WHERE tablename LIKE 'timebank_%';

-- Analyze tables for query optimization
ANALYZE timebank_transactions;
ANALYZE timebank_earnings;
ANALYZE timebank_balances;
```

### Redis Caching (Recommended)

Enable Redis in Nextcloud's config.php:

```php
'memcache.local' => '\OC\Memcache\APCu',
'memcache.distributed' => '\OC\Memcache\Redis',
'memcache.locking' => '\OC\Memcache\Redis',
'redis' => [
    'host' => 'localhost',
    'port' => 6379,
    'password' => 'your-redis-password',
],
```

## Performance Optimization

### Database Indexes

All critical queries use indexes:

```sql
-- User balance lookup: O(1) with primary key
SELECT balance FROM timebank_balances WHERE user_id = ?;

-- Pending claims: Indexed on status
SELECT * FROM timebank_earnings WHERE status = 'pending';

-- User transactions: Indexed on both user columns
SELECT * FROM timebank_transactions
WHERE from_user_id = ? OR to_user_id = ?
ORDER BY created_at DESC;
```

### Caching Strategy

Implement caching for frequently accessed data:

```php
// Balance caching (future enhancement)
$balance = $cache->get('timebank_balance_' . $userId);
if ($balance === null) {
    $balance = $this->balanceMapper->getBalance($userId);
    $cache->set('timebank_balance_' . $userId, $balance, 300); // 5 min
}
```

### Query Optimization

- Pagination on all list endpoints (default: 50 items)
- Eager loading for related entities
- Database query monitoring with Nextcloud debug mode

## Security Checklist

### Input Validation

All endpoints validate input:

```php
// Hours validation
if (!$hours || !is_numeric($hours) || $hours <= 0 || $hours > 1000) {
    return new DataResponse(['error' => 'Invalid hours'], 400);
}

// String length validation
if (strlen($description) < 10 || strlen($description) > 5000) {
    return new DataResponse(['error' => 'Invalid description length'], 400);
}

// Enum validation
if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
    return new DataResponse(['error' => 'Invalid priority'], 400);
}
```

### SQL Injection Prevention

All queries use parameter binding:

```php
$qb = $this->db->getQueryBuilder();
$qb->select('*')
   ->from('timebank_transactions')
   ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
   ->orderBy('created_at', 'DESC');
```

### CSRF Protection

POST/PUT/DELETE endpoints require CSRF tokens:

```php
#[NoAdminRequired]
#[ApiRoute(verb: 'POST', url: '/api/earnings/claim')]
public function claim(): DataResponse {
    // CSRF protection enabled by default
}
```

GET endpoints can skip CSRF:

```php
#[NoAdminRequired]
#[NoCSRFRequired]  // Safe for GET requests
#[ApiRoute(verb: 'GET', url: '/api/earnings/my')]
```

### Admin Authorization

Admin-only operations check group membership:

```php
if (!$this->groupManager->isAdmin($this->userId)) {
    return new DataResponse(['error' => 'Unauthorized'], Http::STATUS_FORBIDDEN);
}
```

## Monitoring & Debugging

### Enable Debug Mode

Edit `config/config.php`:

```php
'debug' => true,
'loglevel' => 0, // 0=debug, 1=info, 2=warn, 3=error
```

### View Logs

```bash
# Real-time log monitoring
tail -f /var/www/nextcloud/data/nextcloud.log

# Search for Time Bank errors
grep "timebank" /var/www/nextcloud/data/nextcloud.log

# Docker logs
make docker-logs
```

### Health Checks

```bash
# Check app status
sudo -u www-data php occ app:list | grep timebank

# Check database migrations
sudo -u www-data php occ migrations:status timebank

# Run integrity check
sudo -u www-data php occ integrity:check-app timebank
```

### Performance Monitoring

```bash
# Check slow queries (PostgreSQL)
SELECT query, mean_exec_time, calls
FROM pg_stat_statements
WHERE query LIKE '%timebank_%'
ORDER BY mean_exec_time DESC
LIMIT 10;

# Monitor Redis cache hits
redis-cli info stats | grep keyspace
```

## Backup & Recovery

### Database Backup

```bash
# PostgreSQL backup
pg_dump -U nextcloud -d nextcloud \
  --table='timebank_*' \
  > timebank_backup_$(date +%Y%m%d).sql

# Restore
psql -U nextcloud -d nextcloud < timebank_backup_20240115.sql
```

### Full Backup

```bash
# Backup app directory
tar czf timebank_app_backup.tar.gz /var/www/nextcloud/apps/timebank

# Backup data directory
tar czf timebank_data_backup.tar.gz /var/www/nextcloud/data/appdata_*/timebank
```

## Troubleshooting

### Common Issues

**1. App won't enable**

```bash
# Check PHP version
php -v  # Must be 8.1+

# Check dependencies
cd /var/www/nextcloud/apps/timebank
composer install

# Check logs
tail -f /var/www/nextcloud/data/nextcloud.log
```

**2. Frontend not loading**

```bash
# Rebuild assets
cd /var/www/nextcloud/apps/timebank
npm install
npm run build

# Clear browser cache
# Check browser console for errors
```

**3. Database errors**

```bash
# Check migrations
sudo -u www-data php occ migrations:status timebank

# Run migrations manually
sudo -u www-data php occ migrations:execute timebank latest
```

**4. Permission errors**

```bash
# Fix ownership
chown -R www-data:www-data /var/www/nextcloud/apps/timebank

# Fix permissions
chmod -R 755 /var/www/nextcloud/apps/timebank
```

### Debug Checklist

- [ ] Check Nextcloud version compatibility (27-32)
- [ ] Verify PHP version (8.1+)
- [ ] Check all PHP extensions installed
- [ ] Verify database migrations ran
- [ ] Check file permissions (www-data:www-data)
- [ ] Review nextcloud.log for errors
- [ ] Clear browser cache
- [ ] Test with different user account
- [ ] Check admin group membership
- [ ] Verify database connection

## Upgrading

### From Previous Version

```bash
# Backup first!
make backup

# Pull latest code
git pull origin main

# Install dependencies
composer install --no-dev
npm ci --only=production

# Build frontend
npm run build

# Run migrations
sudo -u www-data php occ migrations:migrate timebank

# Clear caches
sudo -u www-data php occ maintenance:mode --on
sudo -u www-data php occ maintenance:mode --off
```

## Production Checklist

Before going live:

- [ ] Change all default passwords (.env file)
- [ ] Enable HTTPS (configure reverse proxy)
- [ ] Set up database backups (automated)
- [ ] Configure Redis caching
- [ ] Enable PHP opcache
- [ ] Set up monitoring (uptime, errors)
- [ ] Configure email notifications (Nextcloud mail)
- [ ] Review and adjust category multipliers
- [ ] Test approval workflow
- [ ] Test voting system
- [ ] Document admin procedures
- [ ] Train admin users
- [ ] Set up support channel
- [ ] Disable debug mode
- [ ] Set production log level (error only)
- [ ] Run security scan
- [ ] Load test with expected users

## Support & Resources

- **GitHub Issues**: https://github.com/definitelynotaspren/NextTime-Dev/issues
- **Nextcloud Docs**: https://docs.nextcloud.com
- **Community Forum**: https://help.nextcloud.com
- **Developer Chat**: https://cloud.nextcloud.com/call/xs25tz5y

## License

AGPL-3.0-or-later
