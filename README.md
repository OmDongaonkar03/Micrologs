# Micrologs

A self-hostable analytics + error tracking engine. Drop one script tag, data hits your own database. No SaaS bill, no third-party dashboard, no black box.

Works on the cheapest shared hosting you can find - handles up to **~10,000 pageviews/day** on a standard shared host with no Redis, no queue, and no VPS required. On a VPS with v2, the ceiling jumps to **~500,000 pageviews/day** with async queuing and Valkey caching. Each stage is opt-in — shared hosting users are never broken by what VPS users unlock.

**[→ Full setup guide](IMPLEMENTATION.md)**

---

## Why Micrologs

Most analytics tools make you choose between simplicity and scale, or between free and self-hosted. Micrologs is:

- **Free forever** - MIT licensed, no usage limits, no seats
- **Runs anywhere** - PHP + MySQL, works on $2/month shared hosting
- **Your data** - nothing leaves your server, no third-party calls at runtime
- **Analytics + error tracking in one** - no need for Plausible AND Sentry separately
- **Headless by design** - the API is the product. Query your data as JSON, visualize it however fits your stack

---

## What it tracks

**Analytics**
- Unique visitors, sessions, pageviews, bounce rate
- New vs returning visitors
- Avg session duration, avg pages per session
- Country, region, city breakdown
- Device type, OS, browser
- Referrer source categorization (organic, social, email, referral)
- UTM campaign tracking
- Top pages

**Error Monitoring**
- Auto-caught JS errors (`window.onerror` + `unhandledrejection`)
- Manual errors from any backend - PHP, Node, Python, anything with HTTP
- Errors grouped by fingerprint - same error fires 1000x = 1 group, 1000 occurrences
- Error trends over time - spot spikes after deploys
- Severity levels: `info`, `warning`, `error`, `critical`

**Other**
- Audit logging - track any action from any stack
- Tracked link shortener with click analytics
- Bot filtering
- Multi-project support from one install

---

## Quick start

**1. Add the snippet**

```html
<script
  src="https://yourdomain.com/snippet/micrologs.js"
  data-public-key="your_public_key"
  data-environment="production"
  async>
</script>
```

Pageviews, sessions, devices, locations, and JS errors are now tracked automatically.

**2. Query your data**

```bash
curl https://yourdomain.com/api/analytics/visitors.php?range=30d \
  -H "X-API-Key: your_secret_key"
```

```json
{
  "success": true,
  "data": {
    "unique_visitors": 1842,
    "total_pageviews": 5631,
    "total_sessions": 2109,
    "bounce_rate": 43.2,
    "over_time": [...]
  }
}
```

**[→ Full API reference and setup](IMPLEMENTATION.md)**

---

## Stack

- **Backend** - PHP 8.1+
- **Database** - MySQL 8.0+ / MariaDB 10.4+
- **Geolocation** - MaxMind GeoLite2 (local, no API calls at runtime)
- **Snippet** - Vanilla JS, zero dependencies, ~3KB
- **v2 (VPS only)** - Valkey 7+ (or Redis 6+), Supervisor

---

## SDKs

Official SDKs for backend error tracking and audit logging. The JS snippet handles frontend tracking automatically — SDKs are for your server-side code.

| SDK | Package | Version | Install |
|---|---|---|---|
| Node.js | [@micrologs/node](https://www.npmjs.com/package/@micrologs/node) | v1.1.0 | `npm install @micrologs/node` |
| Python | [micrologs](https://pypi.org/project/micrologs) | v1.0.0 | `pip install micrologs` |
| Laravel | [micrologs/laravel](https://packagist.org/packages/micrologs/laravel) | v1.0.0 | `composer require micrologs/laravel` |

### Compatibility

| Engine | Node SDK | Python SDK | Laravel SDK |
|---|---|---|---|
| v2.2.0 *(current)* | v1.1.0 | v1.0.0 | v1.0.0 |
| v1.3.1 *(shared hosting stable)* | v1.0.0 | v1.0.0 | v1.0.0 |

---

## Roadmap

| Stage | Status | What's included |
|---|---|---|
| v1.0 - REST API | ✅ Shipped (pre-release) | Full analytics, error tracking, audit logs, link tracking |
| v1.1 - Security & Performance | ✅ Shipped (pre-release) | IP spoofing fix, payload caps, query reduction, log rotation, request ID in logs |
| v1.2 - Analytics Depth | ✅ Shipped (pre-release) | Session analytics, new vs returning visitors, error trends over time |
| v1.3 - Complete API | ✅ Shipped (pre-release) | Project management (list, toggle, delete, rotate keys), link edit/detail, error status updates |
| v1.3.1 - First stable | ✅ Stable | Setup wizard, bug fixes, shared hosting recommended release |
| v2 - Infrastructure | ✅ Shipped | Async queue (Valkey), background workers, analytics caching, cache invalidation |
| v2.1 - Optimization | ✅ Stable | Schema cleanup, unified timezone, reduced DB round-trips, deduped auth helpers, faster bot filter |
| v2.2 - Performance | ✅ Stable (current) | Async GeoIP/UA enrichment, 10x tracking throughput, Docker dev environment |
| v3 - Realtime | Planned | WebSockets, live visitor count, live error feed |

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) - issues and PRs are welcome.

---

Built by [Om Dongaonkar](https://github.com/OmDongaonkar03)