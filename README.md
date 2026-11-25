# Topic Funding (TopicLaunch)

Topic Funding is a fan-creator marketplace. Fans propose video topics and fund them, and creators fulfill funded topics within 48 hours. The platform takes a 10% fee, and creators keep 90%.

## What is in this repo

- `index.php` is the main landing page and the topic funding flow.
- `router.php` routes requests for the PHP built-in web server.
- `admin/` is creator management, revenue, and admin tools.
- `api/` holds the endpoints for creating and funding topics, payouts, and payment provider flows.
- `auth/` is login and logout.
- `config/` holds the database connection, payment configuration, and shared helpers.
- `creators/` is the creator side: dashboard, profile, topic actions, uploads, and payout requests.
- `cron/` holds the scheduled auto-refund job.
- `topics/` holds the topic pages for funding, success, and cancellation.
- `webhooks/` holds the payment webhook handlers.
- `artifacts/mockup-sandbox/` holds a standalone design mockup app, separate from the PHP product.
- `replit.md` is the detailed project reference, and `CONTEXT.md` records the project's status.

## Running it

```bash
php -S 0.0.0.0:5000 router.php
```

Database and payment settings come from environment variables and the files in `config/`.
