# Topic Funding — CONTEXT.md

## Stage Contract

| | |
|---|---|
| **Stage** | Parked — built but not actively developed |
| **Done criteria** | N/A until reactivated |
| **Next stage** | Unclear — superseded by TopicLaunch (`projects/business/TopicLaunch/`) |

---

## What This Is

PHP-based fan-creator marketplace. Fans propose and fund video topics; creators fulfill within 48 hours. 10% platform fee, creators keep 90%.

**Note:** May be the older/PHP version of TopicLaunch. Check `projects/business/TopicLaunch/` for the current version before working on this.

---

## Stack

- PHP 8.2
- PostgreSQL (Replit built-in)
- Stripe Connect (PHP SDK v13)
- Hosted on Replit

---

## File Map

```
topic-funding/
├── index.php           ← main landing + Stripe funding flow
├── router.php          ← PHP built-in server router
├── admin/              ← creator management, revenue, webhooks
├── api/                ← AJAX endpoints
├── auth/               ← login/logout
├── config/             ← DB, Stripe, helpers
├── creators/           ← creator dashboard, profile, payouts
├── cron/               ← auto_refund background task
├── topics/             ← topic pages
├── artifacts/          ← outputs
└── webhooks/           ← Stripe webhook handlers
```

## Running

```
php -S 0.0.0.0:5000 router.php
```
