# Micrologs

Self-hostable, plug-and-play analytics engine. Drop a JS snippet on any website and get visitor tracking, location analytics, device breakdowns, shareable link tracking, error monitoring, and audit logging. Own your data. Open source.

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
- Error monitoring — auto-caught + manual, grouped by fingerprint
- Audit logging — track any action from any stack
- Bot filtering
- File-based rate limiting
- API key auth (public + secret key per project)

---

Built by [Om Dongaonkar](https://github.com/OmDongaonkar03)