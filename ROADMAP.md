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

### v1.3.0
Complete API coverage - full project management and error workflows.
- `projects/list.php` - list all projects with summary stats
- `projects/toggle.php` - enable/disable a project
- `projects/regenerate-keys.php` - rotate secret/public keys, old keys invalidated immediately
- `projects/delete.php` - permanently delete a project (confirmation required)
- `links/detail.php` - fetch a single link by code with click count
- `links/edit.php` - edit link destination, label, or active state
- `track/errors-update-status.php` - update error group status individually or in bulk
- `error_groups.status` ENUM expanded - added `investigating` between `open` and `resolved`

### v2.0.0
Infrastructure release — async writes, cached reads. Requires Valkey + Supervisor on a VPS.
- All tracking endpoints (`pageview`, `error`, `audit`) now push to a Valkey queue and return `202` immediately
- Three background workers process DB writes off the HTTP request cycle
- All 14 analytics endpoints use cache-aside with 2–5 minute TTL
- Targeted cache invalidation on project delete/toggle, link edits, and error status changes
- `error.php` and `audit.php` now accept either secret key (backend) or public key (JS snippet)
- Supervisor config included at `supervisor/micrologs-workers.conf`
- Tracking response time: ~2–5ms. Analytics on cache hit: ~2–5ms.

### @micrologs/node v1.0.1
Official Node.js SDK - zero dependencies, Node 18+, CJS + ESM, silent on failure.
- Wraps every engine endpoint: tracking, link management, analytics
- `npm install @micrologs/node`
- [npmjs.com/package/@micrologs/node](https://www.npmjs.com/package/@micrologs/node)

### micrologs (Python) v0.1.0
Official Python SDK - zero dependencies, Python 3.8+, sync, silent on failure.
- Wraps every engine endpoint: tracking, link management, analytics
- `pip install micrologs`
- [pypi.org/project/micrologs](https://pypi.org/project/micrologs/)

### micrologs/laravel v0.1.0
Official Laravel SDK - service provider, facade, auto-discovery, TrackErrors middleware. Laravel 10, 11, 12.
- Wraps every engine endpoint: tracking, link management, analytics
- Automatic exception capture with request context via `TrackErrors` middleware
- `composer require micrologs/laravel`
- [packagist.org/packages/micrologs/laravel](https://packagist.org/packages/micrologs/laravel)

### v2.1.0
Schema and code optimization pass. No new endpoints, no breaking changes.
- Unified `APP_TIMEZONE` constant — single source of truth for both PHP and MySQL timezone, removing silent divergence risk
- Redundant duplicate indexes dropped from `projects`, `sessions`, `tracked_links`, `pageviews`, `error_events`, and `audit_logs` — a `UNIQUE KEY` already creates a B-tree index, maintaining a second identical one is pure write overhead
- `idx_dedup` dropped from `pageviews` — heavy 4-column index unused by any query path
- `visitors.fingerprint_hash` made nullable — empty string entries were polluting the index
- `created_at` added to `locations` and `devices` for observability
- `resolveLocation()` and `resolveDevice()` reduced from 2 DB round-trips to 1 per tracked event — SELECT before INSERT was redundant given `ON DUPLICATE KEY UPDATE`
- `fetchProjectByKey()` and `checkDomainLock()` extracted — eliminates 4 copies of the same SQL and domain-lock loop across the key verification functions
- `isBot()` rewritten as single `preg_match` against a compiled pattern — replaces 27 sequential `str_contains` calls on the hot path
- `writeLog()` rotation check sampled at ~1-in-50 writes — removes a `filesize()` syscall on every log line

---

## Active (v2.x)

Patch releases and minor improvements on the v2 foundation.

- [ ] APCu rate limiter - drop-in replacement for the file-based rate limiter on hosts that support APCu. Atomic, zero filesystem I/O, no race condition.
- [ ] `verifyPublicKey` Valkey cache - cache the key→project lookup with 60s TTL, eliminating one DB query on every single tracking request.
- [ ] Health endpoint extended with queue depth and worker status.

---

## Planned

### v3.0.0 - Realtime
Target: VPS, persistent connections.

- [ ] WebSockets - live visitor count, live error feed
- [ ] Live dashboard data feed
- [ ] Real-time error alerting

### v2.x - Plugins
- [ ] WordPress plugin - one-click install for WP sites
- [ ] Webhook alerts - configurable triggers (new error group, error threshold, etc.)

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