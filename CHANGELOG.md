# Changelog

All notable changes to Micrologs will be documented here.

---

## [1.2.0] - 2026-03-02

Three new analytics endpoints using existing data - no schema changes, no new tracking required.

### Added
- **`GET /api/analytics/sessions.php`** - avg session duration (all sessions + engaged-only), avg pages per session, sessions over time daily breakdown.
- **`GET /api/analytics/visitors-returning.php`** - new vs returning visitors, percentage split, daily breakdown over time. New = `first_seen` within the range. Returning = `first_seen` before the range with activity within it.
- **`GET /api/analytics/errors-trend.php`** - daily error occurrences over time, top 5 error groups by occurrence, total unique groups affected. Accepts optional `?group_id=` to scope to a single error group.

---


## [1.1.0] - 2026-03-01

Security hardening and performance pass. No breaking changes - drop-in replacement for v1.0.0.

### Security
- **Fixed IP spoofing via `X-Forwarded-For`** - `getClientIp()` no longer blindly trusts the `XFF` header. It is now only honoured when the request originates from an IP listed in the new `TRUSTED_PROXIES` env constant. On shared hosting with no proxy in front, leave it empty and `XFF` is ignored entirely. This prevents attackers from spoofing the rate limiter or poisoning GeoIP data.
- **Bounded request body size** - all endpoints previously read `php://input` without a size cap. A new `readJsonBody()` helper enforces a hard 64 KB limit and returns `413` on oversized payloads, closing a trivial DoS vector.
- **Bounded context field size** - the `context` JSON field in error and audit events is now capped at 8 KB after encoding via `encodeContext()`. Oversized context is silently dropped rather than stored.
- **Custom date range validation** - custom analytics date ranges are now capped at 365 days and validated that `from` is before `to`, preventing full-table scan queries.

### Performance
- **GeoIP reader cached as static variable** - `geolocate()` no longer opens and closes `GeoLite2-City.mmdb` on every request. The reader is instantiated once per PHP-FPM process and reused, saving 20–80ms per tracking call.
- **`pageview.php` query count reduced from ~15 to 6–8** - visitor and session writes now use `INSERT ... ON DUPLICATE KEY UPDATE` instead of SELECT-then-INSERT pairs. The bounce flag update is now a single conditional `UPDATE` with an inline subquery instead of a separate `COUNT(*)` query.
- **Added `idx_dedup` composite index** on `pageviews (project_id, visitor_id, url(255), created_at)` - speeds up the 5-minute deduplication check on large tables.

### Added
- `readJsonBody(int $maxBytes)` helper in `includes/functions.php`
- `encodeContext($raw, int $maxBytes)` helper in `includes/functions.php`
- `TRUSTED_PROXIES` env constant (documented in `.env.example.php`)
- Log rotation in `writeLog()` - when `micrologs.log` exceeds 10 MB it shifts existing files down (`.1`→`.2`, `.2`→`.3` ... up to `.5`) and starts a fresh log. The oldest file `.5` is deleted. At most 6 files (60 MB) on disk at any time.
- Request ID in logs - every HTTP request generates a unique 8-character ID stored in `$GLOBALS["request_id"]`. All `writeLog()` calls include it automatically. Format: `[timestamp] [level] [request_id] [file] message`. Grep a single ID to see the complete story of one request.

---

## [1.0.0] - 2026-02-27

Initial release.

### Added
- Pageview and session tracking
- Unique visitor identification (cookie + canvas fingerprint hybrid)
- Country, region, city breakdown via MaxMind GeoLite2 (local, no runtime API calls)
- Device type, OS, browser detection
- Referrer source categorization (organic, social, email, referral, direct)
- UTM campaign tracking
- Top pages analytics
- JS error monitoring - auto-caught via `window.onerror` and `unhandledrejection`
- Manual error tracking from any backend over HTTP
- Error grouping by fingerprint with occurrence counts
- Audit logging
- Tracked link shortener with click analytics
- Bot filtering
- File-based rate limiting (no Redis, shared hosting safe)
- Multi-project support from one install
- Public + secret key auth per project
- Domain locking on public keys