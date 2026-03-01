# Micrologs Roadmap

This is the honest, living roadmap for Micrologs. Items move when they're ready, not on a fixed schedule. If something here interests you, open an issue or a PR.

---

## Shipped

### v1.0.0
Full tracking pipeline and REST API on shared hosting.
- Pageview, session, visitor tracking
- JS error monitoring - auto-caught + manual
- Error grouping by fingerprint
- Audit logging
- Tracked link shortener with click analytics
- MaxMind GeoLite2 geolocation - local, no runtime API calls
- Bot filtering, domain locking, public + secret key auth
- Multi-project support

### v1.1.0
Security hardening and performance pass.
- Fixed IP spoofing via X-Forwarded-For
- Hard 64KB payload cap on all endpoints
- Context field capped at 8KB
- pageview.php queries reduced from ~15 to 6–8
- GeoIP reader cached as static variable
- Log rotation with 5-file history
- Request ID in every log line

### v1.2.0
Analytics depth - no schema changes, no new tracking.
- `sessions.php` - avg session duration, avg pages per session
- `visitors-returning.php` - new vs returning visitors
- `errors-trend.php` - daily error occurrences, top groups, single-group filter

---

## Active (v1.2.x)

Patch releases for security fixes and minor optimizations only. No new features in v1.2.x.

- [ ] APCu rate limiter - drop-in replacement for the file-based rate limiter on hosts that support APCu. Atomic, zero filesystem I/O, no race condition.

---

## Planned

### v2.0.0 - Infrastructure
Target: VPS, ~100k pageviews/day ceiling.

- [ ] Valkey/Redis as caching and queue transport
- [ ] Async ingestion queue via Symfony Messenger - HTTP endpoints dispatch a message and return immediately, workers process DB writes in background
- [ ] Webhook alerts - configurable triggers (new error group, error threshold, etc.)
- [ ] Worker process management and monitoring
- [ ] Health endpoint extended with queue depth and worker status

### v2.x - SDKs
- [ ] Node.js SDK - wraps all track + analytics endpoints
- [ ] Python SDK
- [ ] Laravel SDK - first-class Laravel integration
- [ ] WordPress plugin - one-click install for WP sites

### v3.0.0 - Realtime
Target: VPS, persistent connections.

- [ ] WebSockets - live visitor count, live error feed
- [ ] Live dashboard data feed
- [ ] Real-time error alerting

---

## Under Consideration

These are not committed - they need more thought or depend on community demand.

- **Hosted SaaS version** - Engine stays MIT licensed. SaaS adds a dashboard UI, billing, and managed hosting. Timing: after v2, when organic demand for a hosted version is clear.
- **GDPR compliance documentation** - formal documentation for teams that need to justify self-hosted analytics to legal/compliance.
- **Grafana datasource plugin** - expose Micrologs data as a Grafana datasource.
- **Retention analytics** - cohort-based visitor retention over time.

---

## Will Not Build

These are explicitly out of scope for the core engine.

- **A bundled dashboard UI** - Micrologs is headless by design. The API is the product. Build your own dashboard, pipe data into Grafana, query it from your admin panel - whatever fits your stack.
- **Docker image** - contradicts the shared hosting first principle of v1. May reconsider for v2 as an optional deployment method.
- **Cloud-specific integrations** - no AWS/GCP/Azure specific features in the core engine.

---

## Contributing

If something on this roadmap interests you, open an issue before starting work so we can align on approach. PRs without prior discussion may be declined not because the work is bad but because it might conflict with planned architecture.

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.