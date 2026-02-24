# Micrologs

Self-hostable, plug-and-play analytics engine. Drop a JS snippet on any website and get visitor tracking, location analytics, device breakdowns, and shareable link tracking. Own your data. Open source.

---

## Stack

- **Backend** — PHP 8.1+
- **Database** — MySQL 8.0+
- **Geolocation** — MaxMind GeoLite2 (local, no API calls)
- **Snippet** — Vanilla JS, no dependencies

---

## Features

- Unique visitor tracking (cookie + fingerprint hybrid)
- Region, country, city breakdown
- Device, OS, browser analytics
- Referrer source categorization
- UTM campaign tracking
- Shareable link tracking with click analytics
- Bot filtering
- File-based rate limiting
- API key auth (public + secret key per project)

---

## Quick Start

**1. Import the database**
```bash
mysql -u root -p < schema.sql
```

**2. Configure**
```
authorization/env.php  — DB credentials, app URL, IP hash salt, GeoIP path
```

**3. Install GeoIP**

Download [GeoLite2-City.mmdb](https://dev.maxmind.com/geoip/geolite2-free-geolocation-data) and place it in `utils/geoip/`.

**4. Install MaxMind reader**
```bash
cd utils && composer require maxmind-db/reader
```

**5. Create a project**
```bash
POST /api/projects/create.php
{ "name": "My App", "allowed_domain": "myapp.com" }
```

**6. Add the snippet**
```html
<script>
  window.MICROLOGS_PUBLIC_KEY = "your_public_key";
  window.MICROLOGS_API_URL    = "https://yourdomain.com";
</script>
<script src="https://yourdomain.com/snippet/micrologs.js" async></script>
```

---

## API

All analytics endpoints require `X-API-Key: your_secret_key` header.

```
GET  /api/analytics/visitors.php?range=30d
GET  /api/analytics/pages.php
GET  /api/analytics/locations.php
GET  /api/analytics/devices.php
GET  /api/analytics/referrers.php
GET  /api/analytics/utm.php
GET  /api/analytics/links.php
GET  /api/analytics/link-detail.php?code=abc12xyz

POST /api/links/create.php
GET  /api/links/list.php
POST /api/links/delete.php

GET  /api/redirect.php?c={code}
```

---

## License

MIT — free to use, modify, and self-host.

---

Built by [Om Dongaonkar](https://github.com/OmDongaonkar03)