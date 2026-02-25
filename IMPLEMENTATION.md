# Micrologs - Implementation Guide

## What is Micrologs?

Micrologs is a lightweight, self-hosted analytics engine. It tracks pageviews, visitors, sessions, devices, locations, and tracked links — all from your own server, your own database.

---

## Requirements

- PHP 8.1+
- MySQL 5.7+ or MariaDB 10.4+
- Apache with `.htaccess` support (mod_rewrite enabled)
- Composer
- MaxMind GeoLite2 account (free)

---

## 1. Clone the Repository

```bash
git clone https://github.com/OmDongaonkar03/Micrologs.git
cd Micrologs
```

---

## 2. Install Dependencies

```bash
cd utils
composer install
cd ..
```

---

## 3. Set Up the Database

Create a new MySQL database, then import the schema:

```sql
CREATE DATABASE micrologs CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Import the schema file:

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
define("DB_HOST", "localhost");
define("DB_USER", "your_db_user");
define("DB_PASS", "your_db_password");
define("DB_NAME", "micrologs");

define("APP_URL", "https://yourdomain.com");

define("ADMIN_KEY", "generate_a_long_random_string");
define("IP_HASH_SALT", "another_long_random_string");
define("GEOIP_PATH", __DIR__ . "/../utils/geoip/GeoLite2-City.mmdb");
```

> **Generate secure keys** — run this in PHP once:
> ```php
> echo bin2hex(random_bytes(32));
> ```
> Use a separate output for `ADMIN_KEY` and `IP_HASH_SALT`.

---

## 5. Set Up GeoIP

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

## 7. Create Your First Project

Send a `POST` request to `/api/projects/create.php` with the `X-Admin-Key` header:

```bash
curl -X POST https://yourdomain.com/api/projects/create.php \
  -H "Content-Type: application/json" \
  -H "X-Admin-Key: your_admin_key" \
  -d '{
    "name": "My Website",
    "allowed_domain": "mywebsite.com"
  }'
```

**Response:**

```json
{
  "success": true,
  "message": "Project created. Store your secret_key safely — it will not be shown again.",
  "data": {
    "id": 1,
    "name": "My Website",
    "allowed_domain": "mywebsite.com",
    "secret_key": "abc123...",
    "public_key": "xyz789..."
  }
}
```

> Store the `secret_key` immediately — it is never shown again.

---

## 8. Add the Tracking Snippet

Add this to every page you want to track, before `</body>`:

```html
<script>
  window.MICROLOGS_PUBLIC_KEY = "your_public_key";
  window.MICROLOGS_API_URL    = "https://yourdomain.com";
</script>
<script src="https://yourdomain.com/snippet/micrologs.js"></script>
```

That's it. Pageviews, sessions, devices, and locations are now being tracked automatically.

---

## 9. API Reference

All analytics endpoints use the `secret_key` via the `X-API-Key` header.

### Analytics

| Endpoint | Method | Description |
|---|---|---|
| `/api/analytics/visitors.php` | GET | Unique visitors, pageviews, sessions, bounce rate |
| `/api/analytics/pages.php` | GET | Top pages by views |
| `/api/analytics/devices.php` | GET | Breakdown by device, OS, browser |
| `/api/analytics/locations.php` | GET | Breakdown by country, region, city |
| `/api/analytics/referrers.php` | GET | Traffic sources |
| `/api/analytics/utm.php` | GET | UTM campaign data |

All analytics endpoints accept a `range` query param:

```
?range=7d       # last 7 days
?range=30d      # last 30 days (default)
?range=90d      # last 90 days
?range=custom&from=2025-01-01&to=2025-01-31
```

**Example:**

```bash
curl https://yourdomain.com/api/analytics/visitors.php?range=30d \
  -H "X-API-Key: your_secret_key"
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

Check whether a public or secret key is valid:

```bash
curl -X POST https://yourdomain.com/api/projects/verify.php \
  -H "Content-Type: application/json" \
  -d '{ "key": "your_key_here" }'
```

---

## 10. Key Concepts

**Public Key** — used in the JS snippet, safe to expose in the browser. Locked to your `allowed_domain`.

**Secret Key** — used server-side only for analytics and link management. Never expose in frontend code.

**Visitor ID** — stored in a cookie (`_ml_vid`) for 365 days. If the cookie is cleared, the canvas fingerprint is used to re-identify the visitor.

**Session** — tracked via `sessionStorage`. A new session starts if 30 minutes pass with no activity.

**Bot filtering** — requests from known bots, crawlers, and headless browsers are automatically ignored and not counted.

**Deduplication** — the same visitor hitting the same URL within 5 minutes is counted only once.

---

## Security Notes

- Never commit `authorization/env.php` — it is gitignored by default
- Never expose your `secret_key` in frontend code
- The `ADMIN_KEY` is only needed for project creation — keep it private
- IPs are never stored raw — they are hashed with your `IP_HASH_SALT` immediately on ingestion