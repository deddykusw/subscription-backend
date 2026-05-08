# Subscription Backend API

A Laravel 13 REST API for managing user subscriptions with a **PayPal manual-transfer** payment flow. Built with SQLite by default (zero config) and designed to be consumed by mobile apps or external services.

---

## Table of Contents

- [Requirements](#requirements)
- [Quick Start (Local Development)](#quick-start-local-development)
- [Installation (Step-by-Step)](#installation-step-by-step)
- [Database Setup](#database-setup)
- [Running Migrations & Seeders](#running-migrations--seeders)
- [Environment Variables](#environment-variables)
- [Running the Development Server](#running-the-development-server)
- [Running Tests](#running-tests)
- [API Quick-Test with curl](#api-quick-test-with-curl)
- [Project Structure](#project-structure)

---

## Requirements

| Tool | Minimum Version |
|------|----------------|
| PHP  | 8.3 |
| Composer | 2.x |
| Node.js | 18.x (for asset compilation) |
| SQLite | 3.x (bundled with PHP on most systems) |

> **MySQL alternative:** Set `DB_CONNECTION=mysql` and fill in the MySQL variables in `.env` — no code changes needed.

---

## Quick Start (Local Development)

```bash
git clone <repo-url> subscription-backend
cd subscription-backend

# One-command setup (installs deps, generates key, runs migrations)
composer setup
```

Then visit: `http://localhost:8000`

---

## Installation (Step-by-Step)

### 1. Install PHP dependencies

```bash
composer install
```

### 2. Copy the environment file

```bash
cp .env.example .env
```

### 3. Generate the application key

```bash
php artisan key:generate
```

### 4. Install Node dependencies (for Vite assets)

```bash
npm install --ignore-scripts
```

### 5. Build frontend assets

```bash
npm run build
```

---

## Database Setup

### SQLite (Default — recommended for local development)

No extra setup needed. The SQLite file is created automatically at `database/database.sqlite`.

```bash
touch database/database.sqlite   # only if file doesn't exist
```

Verify `DB_CONNECTION=sqlite` in your `.env` (this is the default).

### MySQL (Production alternative)

Edit `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=subscription_db
DB_USERNAME=root
DB_PASSWORD=your_password
```

Create the database:

```sql
CREATE DATABASE subscription_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

---

## Running Migrations & Seeders

### Run all migrations

```bash
php artisan migrate
```

### Run migrations fresh (⚠️ drops all tables)

```bash
php artisan migrate:fresh
```

### Run individual seeders

```bash
# Seed subscription plans (Monthly & Yearly)
php artisan db:seed --class=SubscriptionPlanSeeder

# Seed the admin account (credentials from .env)
php artisan db:seed --class=AdminUserSeeder

# Seed test users (for development/testing)
php artisan db:seed --class=TestUserSeeder
```

### Run all seeders at once

```bash
php artisan db:seed
```

### Fresh migrate + seed (recommended for a clean dev environment)

```bash
php artisan migrate:fresh --seed
```

> **Note:** All seeders are **idempotent** — safe to run multiple times without creating duplicates.

---

## Environment Variables

Below is a complete reference for every variable in `.env.example`.

### Application

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_NAME` | `Subscription API` | Application name shown in emails and logs |
| `APP_ENV` | `local` | Environment (`local`, `staging`, `production`) |
| `APP_KEY` | *(generated)* | 32-byte encryption key. Generate with `php artisan key:generate` |
| `APP_DEBUG` | `true` | Set to `false` in production |
| `APP_URL` | `http://localhost:8000` | Base URL used for generating links |

### Database

| Variable | Default | Description |
|----------|---------|-------------|
| `DB_CONNECTION` | `sqlite` | Database driver: `sqlite` or `mysql` |
| `DB_DATABASE` | *(auto)* | For SQLite: absolute path to `.sqlite` file. For MySQL: database name |
| `DB_HOST` | `127.0.0.1` | MySQL host (MySQL only) |
| `DB_PORT` | `3306` | MySQL port (MySQL only) |
| `DB_USERNAME` | `root` | MySQL username (MySQL only) |
| `DB_PASSWORD` | *(empty)* | MySQL password (MySQL only) |

### Subscription

| Variable | Default | Description |
|----------|---------|-------------|
| `TRIAL_DAYS` | `7` | Duration of the free trial in days |

### Admin Account (used by `AdminUserSeeder`)

| Variable | Default | Description |
|----------|---------|-------------|
| `ADMIN_EXTERNAL_ID` | `ADMIN-001` | External ID for the seeded admin account |
| `ADMIN_NAME` | `System Administrator` | Display name |
| `ADMIN_EMAIL` | `admin@subscription.test` | Login email |
| `ADMIN_PASSWORD` | `change-me-in-production` | Plain-text password (hashed on save). **Change before seeding production!** |

### PayPal (Manual Transfer)

| Variable | Default | Description |
|----------|---------|-------------|
| `PAYPAL_RECEIVER_EMAIL` | `payments@example.com` | PayPal email shown to users in payment instructions |
| `PAYPAL_RECEIVER_NAME` | `Subscription Payments` | Display name shown alongside the email |

### Payment Proof Screenshots

| Variable | Default | Description |
|----------|---------|-------------|
| `PROOF_MAX_SIZE_KB` | `5120` | Maximum upload size in KB (default 5 MB) |
| `PROOF_STORAGE_DISK` | `public` | Laravel disk to store screenshots (`public` or `s3`) |

### Mail

| Variable | Default | Description |
|----------|---------|-------------|
| `MAIL_MAILER` | `log` | `log` writes to storage/logs. Use `smtp`/`ses`/`mailgun` in production |
| `MAIL_HOST` | `127.0.0.1` | SMTP host |
| `MAIL_PORT` | `2525` | SMTP port |
| `MAIL_FROM_ADDRESS` | `noreply@example.com` | Sender email address |
| `MAIL_FROM_NAME` | `${APP_NAME}` | Sender display name |

### CORS

| Variable | Default | Description |
|----------|---------|-------------|
| `CORS_ALLOWED_ORIGINS` | `*` | Comma-separated allowed origins, or `*` for all |
| `CORS_SUPPORTS_CREDENTIALS` | `false` | Set to `false` when using Bearer tokens (mobile apps) |
| `CORS_MAX_AGE` | `86400` | Preflight cache duration in seconds |

### Sanctum

| Variable | Default | Description |
|----------|---------|-------------|
| `SANCTUM_STATEFUL_DOMAINS` | `localhost,127.0.0.1` | Domains that receive stateful cookie sessions (SPA only). Mobile apps using Bearer tokens do not need this. |

---

## Running the Development Server

### Option A: Simple (PHP only)

```bash
php artisan serve
```

API available at: `http://localhost:8000/api/v1/`

### Option B: Full stack (recommended — uses Composer script)

```bash
composer dev
```

This starts the following concurrently:
- `php artisan serve` — API server
- `php artisan queue:listen` — job queue worker
- `php artisan pail` — real-time log viewer
- `npm run dev` — Vite dev server

---

## Running Tests

```bash
# Run all tests
composer test

# Or directly with Artisan
php artisan test

# Run a specific test file
php artisan test tests/Feature/AuthTest.php

# Run with coverage (requires Xdebug or PCOV)
php artisan test --coverage
```

---

## API Quick-Test with curl

### Register a new user

```bash
curl -s -X POST http://localhost:8000/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "external_user_id": "USR-001",
    "name": "Budi Santoso",
    "email": "budi@example.com",
    "device_name": "Budi iPhone 15"
  }' | jq .
```

### Login

```bash
curl -s -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "external_user_id": "USR-001",
    "device_name": "Budi iPhone 15"
  }' | jq .
```

Save the `token` from the response, then use it in authenticated requests:

```bash
export TOKEN="1|your-token-here"
```

### Get current user profile

```bash
curl -s http://localhost:8000/api/v1/auth/me \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" | jq .
```

### List subscription plans

```bash
curl -s http://localhost:8000/api/v1/subscription/plans \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" | jq .
```

### Create a payment order

```bash
curl -s -X POST http://localhost:8000/api/v1/subscription/order \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "user_id": 1,
    "plan_id": 1
  }' | jq .
```

> See [API_DOCUMENTATION.md](./API_DOCUMENTATION.md) for the full endpoint reference.

---

## Project Structure

```
subscription-backend/
├── app/
│   ├── Enums/
│   │   ├── PaymentOrderStatus.php   # pending | verified | rejected | cancelled
│   │   └── SubscriptionStatus.php   # trial | active | expired | cancelled
│   ├── Http/
│   │   ├── Controllers/Api/
│   │   │   ├── AuthController.php
│   │   │   ├── PaymentController.php
│   │   │   └── SubscriptionController.php
│   │   ├── Middleware/
│   │   │   ├── AdminOnly.php            # is_admin gate
│   │   │   └── CheckSubscription.php    # subscription paywall gate
│   │   └── Requests/                    # Form Request validation classes
│   ├── Models/
│   │   ├── User.php
│   │   ├── Subscription.php
│   │   ├── SubscriptionPlan.php
│   │   └── PaymentOrder.php
│   └── Services/
│       ├── AuthService.php
│       └── SubscriptionService.php
├── config/
│   ├── subscription.php    # Trial days, PayPal info, payment instructions
│   └── payment.php         # Proof storage settings
├── database/
│   ├── migrations/         # 9 migration files
│   ├── seeders/
│   │   ├── AdminUserSeeder.php
│   │   ├── SubscriptionPlanSeeder.php
│   │   └── TestUserSeeder.php
│   └── database.sqlite     # SQLite database (gitignored)
├── routes/
│   └── api.php             # All API routes (v1)
├── .env.example            # Environment variable template
└── composer.json
```
