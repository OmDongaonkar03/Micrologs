# Micrologs - Implementation Guide

## What is Micrologs?

Micrologs is a lightweight, self-hosted analytics engine. It tracks pageviews, visitors, sessions, devices, locations, tracked links, errors, and audit events - all from your own server, your own database.

---

## Requirements

**v1 (shared hosting)**
- PHP 8.1+
- MySQL 5.7+ or MariaDB 10.4+
- Apache with `.htaccess` support (mod_rewrite enabled)
- Composer
- MaxMind GeoLite2 account (free)

**v2 additions (VPS only)**
- Valkey 7+ or Redis 6+
- Supervisor
- `predis/predis` PHP package

---

## 1. Clone the Repository

```bash
git clone https://github.com/OmDongaonkar03/Micrologs.git
cd Micrologs
```

---

## 2. Install Dependencies

```bash
composer install
```

For v2, also install the Valkey client:

```bash
composer require predis/predis
```

---

## 3. Set Up the Database

Create a new MySQL database:

```sql
CREATE DATABASE micrologs CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

The schema is imported automatically by `setup.php` in Step 7. If you prefer to import it manually instead:

```bash
mysql -u your_user -p micrologs < schema.sql
```

---

## 4. Configure Environment

Copy the example env file:

```bash
cp authorization/.env.example.php authorization/env.php
```

Edit `authorization/env.php` and fill in your values:

```php
define("DB_HOST",       "localhost");
define("DB_USER",       "your_db_user");
define("DB_PASS",       "your_db_password");
define("DB_NAME",       "micrologs");

define("APP_URL",       "https://yourdomain.com");

// MySQL timezone offset — passed to SET time_zone
define("APP_TIMEZONE",  "+05:30");
// PHP IANA timezone name — passed to date_default_timezone_set()
// Must represent the same offset as APP_TIMEZONE.
define("TIMEZONE",      "Asia/Kolkata");

define("ADMIN_KEY",       "generate_a_long_random_string");
define("IP_HASH_SALT",    "another_long_random_string");
define("GEOIP_PATH",      __DIR__ . "/../utils/geoip/GeoLite2-City.mmdb");
define("LOG_PATH",        __DIR__ . "/../logs/micrologs.log");

# Comma-separated origins allowed for CORS (include scheme, no trailing slash)
define("ALLOWED_ORIGINS", "https://yourdomain.com,http://localhost:8080");

# Trusted reverse proxy IPs (comma-separated).
# Only set this if Nginx/Apache sits in front of PHP on the same server.
# Leave empty on shared hosting - X-Forwarded-For will be ignored entirely,
# which prevents IP spoofing of the rate limiter and GeoIP.
# Example for local proxy: define("TRUSTED_PROXIES", "127.0.0.1");
define("TRUSTED_PROXIES", "");

# v2 — Valkey/Redis connection (required only if running workers)
define("VALKEY_HOST",     "127.0.0.1");
define("VALKEY_PORT",     6379);
define("VALKEY_PASSWORD", "");
```

> **Generate secure keys** — run this twice in PHP (once per key):
> ```php
> echo bin2hex(random_bytes(32));
> ```
> Use one output for `ADMIN_KEY` and a separate output for `IP_HASH_SALT`. Never use the same value for both.

> **Note on timezones** — two constants are required. `APP_TIMEZONE` is a MySQL-compatible offset (e.g. `+05:30`) passed to `SET time_zone`. `TIMEZONE` is a PHP IANA zone name (e.g. `Asia/Kolkata`) passed to `date_default_timezone_set()`. PHP does not accept offset strings like `+05:30` as timezone identifiers — you must use the named zone. Both constants must represent the same offset.

> **Note on `Geo_IP2_LICENSE_KEY`** - this constant is not used at runtime. It is only needed when downloading the GeoLite2 database file (see Step 5). You do not need to define it in `env.php`.

---

## 5. Set Up GeoIP (Optional)

GeoIP enables country, region, and city breakdown in analytics. Everything else works without it — the health check will show a `warn` status but no errors.

1. Sign up for a free account at [maxmind.com](https://www.maxmind.com/en/geolite2/signup)
2. Go to **Account → Manage License Keys → Create New License Key**
3. Download the database (replace `YOUR_LICENSE_KEY`):

```
https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-City&license_key=YOUR_LICENSE_KEY&suffix=tar.gz
```

4. Extract the archive and copy `GeoLite2-City.mmdb` to:

```
utils/geoip/GeoLite2-City.mmdb
```

5. Create `utils/geoip/.htaccess` to block public access:

```apache
Deny from all
```

---

## 6. Protect Sensitive Directories

Create `authorization/.htaccess`:

```apache
Deny from all
```

Add this to your root `.htaccess` to disable directory listing:

```apache
Options -Indexes
```

Make sure `utils/rate_limits/` and `utils/rate_blocks/` are writable by the server:

```bash
chmod 755 utils/rate_limits utils/rate_blocks
```

---

## 7. Set Up Valkey (v2 — VPS only)

Skip this step if you're on shared hosting. v1 works without Valkey.

Valkey is an open-source Redis-compatible key-value store used for the async queue and analytics cache. It is protocol-identical to Redis — if you already have Redis, it works as a drop-in.

**Install Valkey:**

```bash
sudo apt install valkey
sudo systemctl enable valkey
sudo systemctl start valkey
valkey-cli ping   # should return PONG
```

**Set a password** (recommended) in `/etc/valkey/valkey.conf`:

```
requirepass your_valkey_password
```

Then update `VALKEY_PASSWORD` in `authorization/env.php` to match.

**Local development — run Valkey in Docker:**

```bash
docker run -d --name valkey -p 6379:6379 valkey/valkey:latest
docker exec -it valkey valkey-cli ping
```

---

## 8. Start Workers (v2 — VPS only)

Workers are long-lived PHP processes that pop jobs off the Valkey queue and write them to the database. Each worker runs in an infinite loop — Supervisor keeps them alive and restarts them if they crash.

**Install Supervisor:**

```bash
sudo apt install supervisor
```

**Copy the config:**

```bash
sudo cp supervisor/micrologs-workers.conf /etc/supervisor/conf.d/micrologs-workers.conf
```

Edit the file and update the path if your install is not at `/var/www/micrologs`:

```ini
command=php /var/www/micrologs/workers/pageview-worker.php
directory=/var/www/micrologs
```

**Start workers:**

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start micrologs-worker:*
sudo supervisorctl status
```

You should see three programs running — `micrologs-pageview`, `micrologs-error`, `micrologs-audit` — each with 2–3 processes.

**Useful commands:**

```bash
sudo supervisorctl status                               # check all workers
sudo supervisorctl restart micrologs-pageview:*         # restart pageview workers
sudo supervisorctl tail -f micrologs-pageview_00 stdout # tail worker log
```

**Test locally without Supervisor:**

```bash
# Open three terminals, run one per worker
php workers/pageview-worker.php
php workers/error-worker.php
php workers/audit-worker.php
```

---

## 9. Create Your First Project

**The easiest way — open `setup.php` in your browser:**

```
https://yourdomain.com/setup.php
```

The wizard will:
1. Test your database connection
2. Import the schema (creates all tables)
3. Create your first project
4. Show your secret key, public key, and a ready-to-paste tracking snippet

> **Delete `setup.php` immediately after use.** It has no authentication — anyone who can reach it can create projects in your database.

---

**Alternatively, use the API directly:**

```bash
curl -X POST https://yourdomain.com/api/projects/create.php \
  -H "Content-Type: application/json" \
  -H "X-Admin-Key: your_admin_key" \
  -d '{
    "name": "My Website",
    "allowed_domains": ["mywebsite.com", "staging.mywebsite.com"]
  }'
```

**Response:**

```json
{
  "success": true,
  "message": "Project created. Store your secret_key safely - it will not be shown again.",
  "data": {
    "id": 1,
    "name": "My Website",
    "allowed_domains": ["mywebsite.com", "staging.mywebsite.com"],
    "secret_key": "abc123...",
    "public_key": "xyz789..."
  }
}
```

> Store the `secret_key` immediately - it is never shown again.

**Edit a project** - update name and/or allowed domains anytime:

```bash
curl -X POST https://yourdomain.com/api/projects/edit.php \
  -H "Content-Type: application/json" \
  -H "X-Admin-Key: your_admin_key" \
  -d '{
    "id": 1,
    "name": "My Website Updated",
    "allowed_domains": ["mywebsite.com", "staging.mywebsite.com", "localhost"]
  }'
```

**List all projects:**

```bash
curl https://yourdomain.com/api/projects/list.php \
  -H "X-Admin-Key: your_admin_key"
```

**Toggle a project** - enable or disable. Disabled projects reject all incoming tracking and analytics requests:

```bash
# Toggle (flips current state)
curl -X POST https://yourdomain.com/api/projects/toggle.php \
  -H "Content-Type: application/json" \
  -H "X-Admin-Key: your_admin_key" \
  -d '{ "id": 1 }'

# Set explicitly
curl -X POST https://yourdomain.com/api/projects/toggle.php \
  -H "Content-Type: application/json" \
  -H "X-Admin-Key: your_admin_key" \
  -d '{ "id": 1, "is_active": false }'
```

**Regenerate keys** - rotate a leaked or compromised key. Old keys are invalidated immediately — update your snippet and server-side callers first:

```bash
# Rotate both keys (default)
curl -X POST https://yourdomain.com/api/projects/regenerate-keys.php \
  -H "Content-Type: application/json" \
  -H "X-Admin-Key: your_admin_key" \
  -d '{ "id": 1 }'

# Rotate only the secret key
curl -X POST https://yourdomain.com/api/projects/regenerate-keys.php \
  -H "Content-Type: application/json" \
  -H "X-Admin-Key: your_admin_key" \
  -d '{ "id": 1, "rotate_secret": true, "rotate_public": false }'
```

**Delete a project** - permanently removes the project and all its data. Requires confirmation:

```bash
curl -X POST https://yourdomain.com/api/projects/delete.php \
  -H "Content-Type: application/json" \
  -H "X-Admin-Key: your_admin_key" \
  -d '{ "id": 1, "confirm": "My Website" }'
```

The `confirm` value must exactly match the project's name. This is irreversible.

---

## 10. Add the Tracking Snippet

Add this to every page you want to track, before `</body>`:

```html
<script
  src="https://yourdomain.com/snippet/micrologs.js"
  data-public-key="your_public_key"
  data-environment="production"
  async>
</script>
```

That's it. Pageviews, sessions, devices, locations, and errors are now being tracked automatically.

**Optional - if your API lives on a different domain:**

```html
<script
  src="https://yourdomain.com/snippet/micrologs.js"
  data-public-key="your_public_key"
  data-api-url="https://api.yourdomain.com"
  data-environment="production"
  async>
</script>
```

**Framework integration:**

For React/Vue/Svelte - add once in your root `index.html`.

For Next.js - add in `layout.tsx` using `next/script`:

```jsx
<Script
  src="https://yourdomain.com/snippet/micrologs.js"
  data-public-key="your_public_key"
  data-environment="production"
  strategy="afterInteractive"
/>
```

---

## 11. Error Tracking

Errors are auto-caught from the snippet - no extra setup needed. The snippet listens to `window.onerror` and `unhandledrejection` automatically.

**Manual error - from JS:**

```js
Micrologs.error("Payment failed", { order_id: 123, amount: 2999 }, "critical");
```

**Manual error - from any backend using the secret key:**

```bash
curl -X POST https://yourdomain.com/api/track/error.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your_secret_key" \
  -d '{
    "message": "Undefined variable $user",
    "error_type": "PHP Warning",
    "file": "/app/checkout.php",
    "line": 42,
    "stack": "...",
    "severity": "error",
    "environment": "production",
    "context": { "user_id": 456, "order_id": "ORD-789" }
  }'
```

Works with any backend — PHP, Node, Python, Laravel, Django, anything that can make an HTTP request.

> The `error` endpoint accepts either `secret_key` (backend callers) or `public_key` (JS snippet). Use the secret key from backend code.

---

## 12. Audit Logging

Track any action from any application.

**From JS:**

```js
Micrologs.audit("user.login", "user@email.com", { role: "admin" });
```

**From any backend using the secret key:**

```bash
curl -X POST https://yourdomain.com/api/track/audit.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your_secret_key" \
  -d '{
    "action": "order.placed",
    "actor": "user@email.com",
    "context": { "order_id": "ORD-789", "amount": 2999 }
  }'
```

> The `audit` endpoint accepts either `secret_key` (backend callers) or `public_key` (JS snippet). Use the secret key from backend code.

---

## 13. API Reference

All analytics endpoints use the `secret_key` via the `X-API-Key` header.

### Projects

| Endpoint | Method | Auth | Description |
|---|---|---|---|
| `/api/projects/create.php` | POST | X-Admin-Key | Create a new project |
| `/api/projects/list.php` | GET | X-Admin-Key | List all projects with summary stats |
| `/api/projects/edit.php` | POST | X-Admin-Key | Edit project name or allowed domains |
| `/api/projects/toggle.php` | POST | X-Admin-Key | Enable or disable a project |
| `/api/projects/regenerate-keys.php` | POST | X-Admin-Key | Rotate secret key, public key, or both |
| `/api/projects/delete.php` | POST | X-Admin-Key | Permanently delete a project and all its data |
| `/api/projects/verify.php` | POST | None | Verify a public or secret key |
| `/api/health.php` | GET | None | System health check |

### Analytics

| Endpoint | Method | Description |
|---|---|---|
| `/api/analytics/visitors.php` | GET | Unique visitors, pageviews, sessions, bounce rate |
| `/api/analytics/visitors-returning.php` | GET | New vs returning visitors, percentage split |
| `/api/analytics/sessions.php` | GET | Avg session duration, avg pages per session |
| `/api/analytics/pages.php` | GET | Top pages by views |
| `/api/analytics/devices.php` | GET | Breakdown by device, OS, browser |
| `/api/analytics/locations.php` | GET | Breakdown by country, region, city |
| `/api/analytics/referrers.php` | GET | Traffic sources |
| `/api/analytics/utm.php` | GET | UTM campaign data |
| `/api/analytics/errors.php` | GET | Error groups with occurrence counts |
| `/api/analytics/errors-trend.php` | GET | Daily error occurrences over time, top groups |
| `/api/track/errors-update-status.php` | POST | Mark error groups as open, investigating, resolved, or ignored |
| `/api/analytics/error-detail.php` | GET | Single error group with all events |
| `/api/analytics/audits.php` | GET | Audit log events |

All analytics endpoints accept a `range` query param:

```
?range=7d       # last 7 days
?range=30d      # last 30 days (default)
?range=90d      # last 90 days
?range=custom&from=2025-01-01&to=2025-01-31
```

> For `range=custom`, both `from` and `to` are required and must be valid `YYYY-MM-DD` dates. The range cannot exceed 365 days and `from` must be before `to`. Invalid values return `400`.

**Errors endpoint filters:**

```
?range=30d&status=open&severity=critical&environment=production
```

**Update error group status** - mark errors as investigating, resolved, or ignored. Accepts a single ID or a batch of up to 100:

```bash
# Start investigating a single error group
curl -X POST https://yourdomain.com/api/track/errors-update-status.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your_secret_key" \
  -d '{ "id": 42, "status": "investigating" }'

# Resolve a single error group
curl -X POST https://yourdomain.com/api/track/errors-update-status.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your_secret_key" \
  -d '{ "id": 42, "status": "resolved" }'

# Bulk ignore multiple groups
curl -X POST https://yourdomain.com/api/track/errors-update-status.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your_secret_key" \
  -d '{ "ids": [12, 15, 22], "status": "ignored" }'
```

Valid statuses: `open` → `investigating` → `resolved` or `ignored`. Reopening a resolved error sets status back to `open`.

**Audits endpoint filters:**

```
?range=30d&action=user.login&actor=user@email.com
```

---

### Tracked Links

#### Create a link

```bash
curl -X POST https://yourdomain.com/api/links/create.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your_secret_key" \
  -d '{
    "destination_url": "https://example.com/landing-page",
    "label": "Newsletter CTA"
  }'
```

**Response:**

```json
{
  "success": true,
  "data": {
    "code": "aB3xYz12",
    "short_url": "https://yourdomain.com/api/redirect.php?c=aB3xYz12",
    "destination_url": "https://example.com/landing-page",
    "label": "Newsletter CTA"
  }
}
```

#### List links

```bash
curl https://yourdomain.com/api/links/list.php \
  -H "X-API-Key: your_secret_key"
```

#### Get a single link

```bash
curl "https://yourdomain.com/api/links/detail.php?code=aB3xYz12" \
  -H "X-API-Key: your_secret_key"
```

#### Edit a link

```bash
curl -X POST https://yourdomain.com/api/links/edit.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your_secret_key" \
  -d '{
    "code": "aB3xYz12",
    "destination_url": "https://example.com/new-page",
    "label": "Updated CTA",
    "is_active": false
  }'
```

All fields except `code` are optional — pass only what you want to change.

#### Delete a link

```bash
curl -X POST https://yourdomain.com/api/links/delete.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your_secret_key" \
  -d '{ "code": "aB3xYz12" }'
```

#### Link analytics

```bash
curl "https://yourdomain.com/api/analytics/link-detail.php?code=aB3xYz12&range=30d" \
  -H "X-API-Key: your_secret_key"
```

---

### Verify a Key

```bash
curl -X POST https://yourdomain.com/api/projects/verify.php \
  -H "Content-Type: application/json" \
  -d '{ "key": "your_key_here" }'
```

---

### Health Check

```bash
curl https://yourdomain.com/api/health.php
```

**Response:**

```json
{
  "status": "healthy",
  "timestamp": "2026-02-25 14:30:00",
  "checks": {
    "php":      { "status": "ok", "version": "8.2.12", "message": "PHP 8.2.12" },
    "database": { "status": "ok", "message": "Connected" },
    "valkey":   { "status": "ok", "message": "Connected" },
    "workers":  {
      "status": "ok",
      "message": "All workers running",
      "workers": {
        "pageview-worker": "ok",
        "error-worker":    "ok",
        "audit-worker":    "ok"
      }
    },
    "geoip":    { "status": "warn", "message": "GeoLite2-City.mmdb not found - location tracking disabled" }
  }
}
```

Returns `200` when healthy, `503` when any critical check fails. `warn` status does not affect overall health.

> `valkey` and `workers` checks only appear when `IS_PRODUCTION` is set to `true` in `env.php`. On local or shared hosting these checks are skipped.

---

## 14. Key Concepts

**Public Key** - used in the JS snippet, safe to expose in the browser. Locked to your `allowed_domains` list.

**Secret Key** - used server-side only for analytics and link management. Also accepted by `error` and `audit` endpoints from backend callers. Never expose in frontend code.

**Allowed Domains** - one or more domains that are permitted to send data using the public key. Requests from unlisted domains are rejected. Supports subdomains automatically.

**Visitor ID** - stored in a cookie (`_ml_vid`) for 365 days. If the cookie is cleared, the canvas fingerprint is used to re-identify the visitor.

**Session** - tracked via `sessionStorage`. A new session starts if 30 minutes pass with no activity.

**Error Grouping** - errors are fingerprinted by `type + message + file + line`. Same error fired 1000 times = 1 group, 1000 occurrences. If a resolved error fires again it automatically reopens.

**Bot Filtering** - requests from known bots, crawlers, and headless browsers are automatically ignored.

**Deduplication** - the same visitor hitting the same URL within 5 minutes is counted only once.

**Async Queue (v2)** - tracking endpoints push events to a Valkey list and return `202` immediately. Background workers pop from the queue and write to the database. The browser never waits for a DB write.

**Analytics Cache (v2)** - analytics responses are cached in Valkey for 2–5 minutes. A dashboard refreshing every 30 seconds hits the DB once per 5 minutes instead of once per 30 seconds.

---

## Security Notes

- Never commit `authorization/env.php` - it is gitignored by default
- Never expose your `secret_key` in frontend code
- The `ADMIN_KEY` is only needed for project creation and editing - keep it private
- IPs are never stored raw - they are hashed with your `IP_HASH_SALT` immediately on ingestion
- On shared hosting, leave `TRUSTED_PROXIES` empty - `X-Forwarded-For` will be completely ignored, preventing IP spoofing of the rate limiter and GeoIP lookup
- On a VPS with Nginx in front of PHP-FPM, set `TRUSTED_PROXIES` to `127.0.0.1` so real client IPs are correctly read through the proxy
- Set a password on your Valkey instance (`requirepass` in `valkey.conf`) and keep port 6379 firewalled — it should never be publicly accessible