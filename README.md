# Micrologs

A self-hostable analytics + error tracking engine. Drop one script tag, data hits your own database. No SaaS bill, no third-party dashboard, no black box.

Works on the cheapest shared hosting you can find - handles up to **~10,000 pageviews/day** on a standard shared host with no Redis, no queue, and no VPS required. Built to grow - v2 adds caching and queuing, v3 adds websockets and webhooks. Each stage is opt-in, so shared hosting users are never broken by what VPS users unlock.

**[→ Full setup guide](IMPLEMENTATION.md)**

---

## Why Micrologs

Most analytics tools make you choose between simplicity and scale, or between free and self-hosted. Micrologs is:

- **Free forever** - MIT licensed, no usage limits, no seats
- **Runs anywhere** - PHP + MySQL, works on $2/month shared hosting
- **Your data** - nothing leaves your server, no third-party calls at runtime
- **Analytics + error tracking in one** - no need for Plausible AND Sentry separately

---

## What it tracks

**Analytics**
- Unique visitors, sessions, pageviews, bounce rate
- Country, region, city breakdown
- Device type, OS, browser
- Referrer source categorization (organic, social, email, referral)
- UTM campaign tracking
- Top pages

**Error Monitoring**
- Auto-caught JS errors (`window.onerror` + `unhandledrejection`)
- Manual errors from any backend - PHP, Node, Python, anything with HTTP
- Errors grouped by fingerprint - same error fires 1000x = 1 group, 1000 occurrences
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

---

## Roadmap

| Stage | Status | What's included |
|---|---|---|
| v1.0 - REST API | Shipped | Full analytics, error tracking, audit logs, link tracking |
| v1.1 - Security & Performance | Shipped | IP spoofing fix, payload caps, query reduction (~10k pageviews/day on shared hosting) |
| v2 - Performance | Planned | Redis caching, async queue, webhook alerts |
| v3 - Realtime | Planned | WebSockets, live dashboard feed |

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) - issues and PRs are welcome.

---

Built by [Om Dongaonkar](https://github.com/OmDongaonkar03)