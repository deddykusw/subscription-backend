# Deployment Guide — Subscription Backend

A step-by-step guide for deploying this Laravel 13 API to a production server.

---

## Table of Contents

- [Server Requirements](#server-requirements)
- [Production Deployment (VPS/Shared Hosting)](#production-deployment-vpsshared-hosting)
- [Switching from SQLite to MySQL](#switching-from-sqlite-to-mysql)
- [SQLite Backup Strategy](#sqlite-backup-strategy)
- [Security Considerations](#security-considerations)
- [Performance Optimization](#performance-optimization)
- [Queue Worker Setup](#queue-worker-setup)
- [Nginx Configuration](#nginx-configuration)
- [Health Check](#health-check)

---

## Server Requirements

| Requirement | Minimum |
|-------------|---------|
| PHP | 8.3 with `pdo_sqlite`, `pdo_mysql`, `mbstring`, `openssl`, `fileinfo` extensions |
| Composer | 2.x |
| Web server | Nginx (recommended) or Apache |
| SQLite | 3.x (if using SQLite) |
| MySQL | 8.0+ (if using MySQL) |
| Storage | Writable `storage/` and `bootstrap/cache/` directories |

---

## Production Deployment (VPS/Shared Hosting)

### Step 1 — Upload the code

```bash
git clone <repo-url> /var/www/subscription-backend
cd /var/www/subscription-backend
```

Or via rsync from local:

```bash
rsync -avz --exclude='.env' --exclude='vendor' --exclude='node_modules' \
  ./ user@your-server:/var/www/subscription-backend/
```

### Step 2 — Install PHP dependencies (production mode)

```bash
composer install --no-dev --optimize-autoloader
```

### Step 3 — Create and configure `.env`

```bash
cp .env.example .env
nano .env
```

Critical production values to set:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.yourdomain.com
APP_KEY=                          # will be generated next

DB_CONNECTION=sqlite              # or mysql
LOG_CHANNEL=daily
MAIL_MAILER=smtp                  # configure real mail provider

ADMIN_EMAIL=admin@yourdomain.com
ADMIN_PASSWORD=strong-password-here   # change before seeding!

PAYPAL_RECEIVER_EMAIL=payments@yourdomain.com
CORS_ALLOWED_ORIGINS=https://yourmobileapp.com
```

### Step 4 — Generate application key

```bash
php artisan key:generate
```

### Step 5 — Create the SQLite database file (SQLite only)

```bash
mkdir -p database
touch database/database.sqlite
chmod 664 database/database.sqlite
chown www-data:www-data database/database.sqlite
```

### Step 6 — Run migrations

```bash
php artisan migrate --force
```

### Step 7 — Seed the database

```bash
# Seed plans and admin account
php artisan db:seed --class=SubscriptionPlanSeeder --force
php artisan db:seed --class=AdminUserSeeder --force
```

### Step 8 — Set file permissions

```bash
chown -R www-data:www-data /var/www/subscription-backend
chmod -R 755 /var/www/subscription-backend
chmod -R 775 /var/www/subscription-backend/storage
chmod -R 775 /var/www/subscription-backend/bootstrap/cache
```

### Step 9 — Link storage (for payment proof screenshots)

```bash
php artisan storage:link
```

### Step 10 — Optimize for production

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

To clear caches (e.g. after `.env` changes):

```bash
php artisan optimize:clear
```

---

## Switching from SQLite to MySQL

1. Update `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=subscription_db
DB_USERNAME=subscription_user
DB_PASSWORD=strong_db_password
```

2. Create the MySQL database and user:

```sql
CREATE DATABASE subscription_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'subscription_user'@'localhost' IDENTIFIED BY 'strong_db_password';
GRANT ALL PRIVILEGES ON subscription_db.* TO 'subscription_user'@'localhost';
FLUSH PRIVILEGES;
```

3. Run migrations:

```bash
php artisan migrate --force
```

---

## SQLite Backup Strategy

> **Important:** SQLite is a single file. Protect it with regular backups.

### Manual backup

```bash
# Copy with timestamp
cp /var/www/subscription-backend/database/database.sqlite \
   /backups/subscription/database_$(date +%Y%m%d_%H%M%S).sqlite
```

### Use SQLite's hot-backup tool (safe for live databases)

```bash
sqlite3 /var/www/subscription-backend/database/database.sqlite \
  ".backup '/backups/subscription/database_$(date +%Y%m%d).sqlite'"
```

This is **safe to run while the app is live** — it uses SQLite's built-in backup API.

### Automated daily backup with cron

```bash
crontab -e
```

Add:

```cron
# Daily backup at 2 AM, keep 30 days of backups
0 2 * * * sqlite3 /var/www/subscription-backend/database/database.sqlite \
  ".backup '/backups/subscription/db_$(date +\%Y\%m\%d).sqlite'" \
  && find /backups/subscription/ -name "db_*.sqlite" -mtime +30 -delete
```

### Off-site backup (recommended)

Sync to S3 or another server daily:

```bash
# Using AWS CLI
aws s3 cp /backups/subscription/db_$(date +%Y%m%d).sqlite \
  s3://your-bucket/subscription-backups/

# Using rsync to remote server
rsync -az /backups/subscription/ backup-server:/remote/backups/subscription/
```

### Backup before every deployment

```bash
# Add to your deploy script
sqlite3 /var/www/subscription-backend/database/database.sqlite \
  ".backup '/backups/subscription/pre-deploy_$(date +%Y%m%d_%H%M%S).sqlite'"

# Then run migrations
php artisan migrate --force
```

---

## Security Considerations

### 1. Environment file

```bash
# Never expose .env to the web
chmod 600 /var/www/subscription-backend/.env
chown www-data:www-data /var/www/subscription-backend/.env
```

Ensure your web server root points to `public/`, not the project root.

### 2. Disable debug mode

```env
APP_DEBUG=false
APP_ENV=production
```

### 3. Strong admin credentials

Change `ADMIN_PASSWORD` in `.env` before running `AdminUserSeeder` in production. The password is hashed automatically — it is never stored in plain text.

### 4. CORS — restrict allowed origins

```env
# Don't use * in production — restrict to your actual app domain
CORS_ALLOWED_ORIGINS=https://yourmobileapp.com,https://admin.yourdomain.com
```

### 5. HTTPS only

Configure Nginx with SSL (Let's Encrypt is free):

```bash
certbot --nginx -d api.yourdomain.com
```

Force HTTPS by adding to your Nginx config:

```nginx
if ($scheme != "https") {
    return 301 https://$host$request_uri;
}
```

### 6. SQLite file — keep outside web root

The `database/` directory is already outside `public/` but double-check your Nginx config only serves from `public/`.

### 7. Rate limiting

Laravel has built-in rate limiting on `api` routes (60 req/min by default). To customize, edit `bootstrap/app.php` or use `RateLimiter` in a service provider.

### 8. Token rotation

Tokens issued by Sanctum do not expire by default. To add expiration, set in `config/sanctum.php`:

```php
'expiration' => 60 * 24 * 7, // 7 days in minutes
```

Then run a scheduled prune:

```bash
# Add to Scheduler in routes/console.php
php artisan sanctum:prune-expired --hours=168
```

---

## Performance Optimization

### 1. OPcache (PHP)

Enable in `php.ini`:

```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
opcache.revalidate_freq=60
```

### 2. Config, route & view caching

```bash
php artisan optimize
```

This runs `config:cache`, `route:cache`, `view:cache`, and `event:cache` in one command.

### 3. Composer autoloader optimization

```bash
composer install --no-dev --optimize-autoloader --classmap-authoritative
```

### 4. Database indexing

The migrations already add indexes on frequently queried columns (`user_id`, `status`, `end_date`). If you add custom queries, add indexes via migration:

```php
$table->index(['user_id', 'status', 'end_date']);
```

### 5. SQLite WAL mode (for concurrent reads)

Enable Write-Ahead Logging for better read concurrency under load. Add to `config/database.php` under the sqlite connection:

```php
'options' => [
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
],
// Or run manually:
// PRAGMA journal_mode=WAL;
```

Or run once after first startup:

```bash
sqlite3 /var/www/subscription-backend/database/database.sqlite "PRAGMA journal_mode=WAL;"
```

### 6. Queue jobs asynchronously

Payment verification emails are sent via the queue. Ensure the queue worker is running (see below) so emails do not slow down API responses.

---

## Queue Worker Setup

### Using Supervisor (recommended)

Install Supervisor:

```bash
apt-get install supervisor
```

Create `/etc/supervisor/conf.d/subscription-worker.conf`:

```ini
[program:subscription-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/subscription-backend/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/subscription-worker.log
stopwaitsecs=3600
```

```bash
supervisorctl reread
supervisorctl update
supervisorctl start subscription-worker:*
```

### Restart worker after deployment

```bash
php artisan queue:restart
```

---

## Nginx Configuration

```nginx
server {
    listen 80;
    server_name api.yourdomain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name api.yourdomain.com;

    ssl_certificate     /etc/letsencrypt/live/api.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.yourdomain.com/privkey.pem;

    root /var/www/subscription-backend/public;
    index index.php;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header X-XSS-Protection "1; mode=block";

    # Max upload size for payment proof screenshots
    client_max_body_size 6M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    # Serve storage files (payment proof screenshots)
    location /storage {
        alias /var/www/subscription-backend/public/storage;
        try_files $uri $uri/ =404;
    }
}
```

---

## Health Check

Test that the deployment is working:

```bash
# Should return 200 with subscription plans
curl -s https://api.yourdomain.com/api/v1/subscription/plans \
  -H "Authorization: Bearer YOUR_TEST_TOKEN" | jq .success
```

Check logs for errors:

```bash
tail -f /var/www/subscription-backend/storage/logs/laravel.log
```

Check queue worker status:

```bash
supervisorctl status subscription-worker:*
```

---

## Deployment Checklist

```
[ ] APP_DEBUG=false
[ ] APP_ENV=production
[ ] APP_KEY is set (php artisan key:generate)
[ ] CORS_ALLOWED_ORIGINS set to real domain (not *)
[ ] ADMIN_PASSWORD changed from default
[ ] PAYPAL_RECEIVER_EMAIL set to real PayPal account
[ ] MAIL_MAILER configured for real email provider
[ ] HTTPS configured and forced
[ ] storage:link run
[ ] php artisan optimize run
[ ] Queue worker running via Supervisor
[ ] Automated SQLite backups scheduled
[ ] File permissions set (storage/ and bootstrap/cache/ writable by www-data)
```
