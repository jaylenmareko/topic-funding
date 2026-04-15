# TopicLaunch

A PHP-based fan-creator marketplace platform where fans can request and fund custom video content from creators.

## Overview

TopicLaunch is a crowdfunding-style platform where:
- **Fans** propose "topics" (video ideas) and fund them
- **Creators** fulfill funded topics within a 48-hour deadline
- **Payments** are handled via Stripe Connect with a 10% platform fee

## Tech Stack

- **Language**: PHP 8.2
- **Database**: PostgreSQL (via Replit's built-in database, PDO pgsql driver)
- **Payments**: Stripe PHP SDK v13 (installed via Composer)
- **Web Server**: PHP built-in development server with custom router

## Project Structure

```
/admin          - Admin dashboard (creator management, revenue, webhooks)
/api            - AJAX endpoints (create topic, process payout, etc.)
/auth           - Authentication (login/logout)
/config         - Core configuration files (database, stripe, helpers)
/creators       - Creator pages (dashboard, profile, payout requests)
/cron           - Background tasks (auto_refund)
/topics         - Topic pages
/uploads        - User-generated content storage
/vendor         - Composer dependencies (Stripe PHP SDK)
/webhooks       - Stripe webhook handlers
index.php       - Main landing page
router.php      - PHP built-in server router (handles URL rewriting, vanity URLs redirect to /creators/)
```

## Running the App

The app runs via PHP's built-in web server with the custom router:
```
php -S 0.0.0.0:5000 router.php
```

The workflow "Start application" handles this automatically.

## Database

Uses Replit's PostgreSQL database. Connection is configured via environment variables:
- `PGHOST`, `PGPORT`, `PGUSER`, `PGPASSWORD`, `PGDATABASE`

The database schema includes tables: `users`, `creators`, `topics`, `contributions`, `funding_milestones`, `contribution_impact`, `creator_payouts`, `payouts`, `platform_fees`, `payout_requests`, `notifications`, `email_throttle`, `auto_refund_schedule`, `auto_refund_processed`, `refund_log`

## Dependencies

- **Stripe PHP SDK**: Installed via Composer (`composer install`)
- Composer auto-generated `vendor/autoload.php` is required by config/stripe.php

## Key Configuration Files

- `config/database.php` - Database connection, DatabaseHelper class, query methods
- `config/stripe.php` - Stripe integration, loads keys from stripe-keys.php
- `config/stripe-keys.php` - Stripe API keys (live keys)
- `.htaccess` - URL rewriting rules (for Apache deployment)
- `router.php` - URL routing for PHP built-in server

## Notes

- The app was migrated from MySQL to PostgreSQL for Replit compatibility
- MySQL-specific syntax (DATE_SUB, DATE_ADD, TIMESTAMPDIFF, ENUM, AUTO_INCREMENT) was replaced with PostgreSQL equivalents
- The `.htaccess` HTTPS redirect was removed to avoid redirect loops in Replit's proxy environment
