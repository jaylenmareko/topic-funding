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
index.php       - Main landing page (creator picker with active/funded topic display + Stripe funding flow)
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

## Pending Email Notifications (not yet implemented)

The following transactional emails need to be built and wired into the relevant creator actions (`creators/topic_actions.php`, upload handler, etc.):

1. **Creator puts a fully-funded topic on hold** — email all contributors of that topic. Let them know the creator has paused it and that their funds are still held safely.

2. **Creator declines a fully-funded topic** — email all contributors. Include refund information: they will receive a full refund to their original payment method, and typical processing time.

3. **Creator declines an active (not yet fully funded) topic** — email all contributors who have already contributed. Include refund information as above.

4. **Creator uploads / completes a topic** — email all contributors. Let them know the content is ready and include a direct link to the video/content URL.

All emails should be sent via the existing email infrastructure (check `config/` for mailer setup). Use the `email_throttle` table to avoid duplicate sends.
