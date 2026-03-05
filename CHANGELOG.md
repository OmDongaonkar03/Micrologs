# Changelog

All notable changes to Micrologs will be documented here.

---

## [2.0.0] - 2026-03-05

Infrastructure release. All tracking writes are now async. All analytics reads are cached. Requires Valkey (or Redis) and Supervisor on the server. Shared hosting users on v1.3.x are unaffected — v2 is opt-in via a VPS deployment.

### Added

**Async ingestion queue**
- `workers/pageview-worker.php` — processes pageview writes from the `micrologs:pageviews` queue
- `workers/error-worker.php` — processes error writes from the `micrologs:errors` queue
- `workers/audit-worker.php` — processes audit writes from the `micrologs:audits` queue
- `supervisor/micrologs-workers.conf` — production Supervisor config, 3 workers for pageviews, 2 each for errors and audits

**Valkey/Redis helpers in `includes/functions.php`**
- `getValkey()` — singleton Valkey connection, reused per process
- `queuePush(queue, payload)` — RPUSH to queue, silent on Valkey failure
- `queuePop(queue)` — BLPOP with 2s timeout, no busy spin
- `cacheGet(key)` — returns decoded value or null on miss/failure
- `cacheSet(key, value, ttl)` — setex with TTL, silent on failure
- `cacheDel(...keys)` — delete one or more keys
- `cacheBustProject(projectId)` — pattern-delete all analytics keys for a project
- `RUNNING_AS_WORKER` guard — skips CORS headers when included from CLI

**Analytics caching**
- All 14 analytics endpoints now use cache-aside pattern
- 5-minute TTL on aggregate endpoints (`visitors`, `sessions`, `pages`, `devices`, `locations`, `referrers`, `utm`, `visitors-returning`, `links`, `link-detail`, `audits`)
- 2-minute TTL on error endpoints (`errors`, `errors-trend`, `error-detail`) — fresher data when monitoring errors
- Cache keys encode project ID + date range (+ active filters where applicable) so different queries never collide

**Cache invalidation**
- `projects/delete.php` — busts all analytics keys for the deleted project
- `projects/toggle.php` — busts all analytics keys when a project is enabled or disabled
- `links/edit.php` — busts `link-detail` keys for the edited link code
- `track/errors-update-status.php` — busts errors list, errors-trend, and error-detail keys for updated groups
- `workers/error-worker.php` — busts errors list and errors-trend keys on every error write

**Auth improvement**
- `tryVerifySecretKey($conn)` — soft auth helper, returns null instead of exit on failure
- `tryVerifyPublicKey($conn)` — soft auth helper with domain lock, returns null instead of exit on failure
- `api/track/error.php` and `api/track/audit.php` now accept either secret key (backend callers) or public key (JS snippet), tried in that order

### Changed

- `api/track/pageview.php` — removed all DB writes. Now validates, enriches (IP, GeoIP, device, UTM, `received_at`), pushes to `micrologs:pageviews` queue, returns `202 Accepted`
- `api/track/error.php` — removed all DB writes. Now validates, enriches (fingerprint, GeoIP, device, `received_at`), pushes to `micrologs:errors` queue, returns `202 Accepted`
- `api/track/audit.php` — removed DB write. Now validates, enriches (IP hash, `received_at`), pushes to `micrologs:audits` queue, returns `202 Accepted`

### Requirements added

- **Valkey 7+** (or Redis 6+) — queue and cache transport
- **Supervisor** — worker process management
- **`predis/predis`** — pure PHP Valkey/Redis client (`composer require predis/predis`)

### Performance

- Tracking endpoint response time: from ~50–200ms (sync DB writes) to ~2–5ms (queue push)
- Analytics read response time: ~2–5ms on cache hit, unchanged on cache miss
- DB write load: moved entirely off the HTTP request cycle into background workers
- Analytics read load: reduced ~10x for dashboards that poll on a short interval

---

## [1.3.1] - 2026-03-03

### Added
- `setup.php` — browser-based setup wizard. Tests DB connection, imports schema, creates first project, and outputs the tracking snippet ready to copy. Delete it after use.

### Fixed
- `GET /api/analytics/errors.php` - `status=investigating` filter was silently dropped, returning all statuses instead. Added `investigating` to the allowlist.
- `GET /api/analytics/errors.php` - summary counts were missing `investigating`. Response now includes all four status counts: `open`, `investigating`, `resolved`, `ignored`.

### Changed
- `composer install` now runs from the project root. A root `composer.json` sets `vendor-dir` to `utils/vendor` so the internal structure is unchanged.
- `authorization/.env.example.php` - key generation command added inline next to `ADMIN_KEY` and `IP_HASH_SALT` so users don't have to hunt for it in the docs.
- `IMPLEMENTATION.md` - GeoIP setup step now clearly marked as optional. Key generation note updated. Composer step simplified. Setup wizard documented.

---

## [1.3.0] - 2026-03-02

Complete project management and error workflow APIs.

### Added
- **`GET /api/projects/list.php`** - List all projects with summary stats (total links, pageviews, errors). Auth: admin key.
- **`POST /api/projects/toggle.php`** - Enable or disable a project. Disabled projects reject all tracking and analytics requests. Accepts `is_active` bool to set explicitly, or omit to flip current state. Auth: admin key.
- **`POST /api/projects/regenerate-keys.php`** - Rotate `secret_key`, `public_key`, or both. Old keys are invalidated immediately. Accepts `rotate_secret` and `rotate_public` bools (both default true). Auth: admin key.
- **`POST /api/projects/delete.php`** - Permanently delete a project and all its data. Requires `"confirm": "<project name>"` as a safety check. Auth: admin key.
- **`GET /api/links/detail.php`** - Fetch a single tracked link by `?code=` including `total_clicks`. Auth: secret key.
- **`POST /api/links/edit.php`** - Edit a link's `destination_url`, `label`, or `is_active` by code. All fields optional except `code`. Auth: secret key.
- **`POST /api/track/errors-update-status.php`** - Mark error groups as `open`, `investigating`, `resolved`, or `ignored`. Accepts single `id` or array of `ids` (max 100 per request). IDs not belonging to the project are skipped and reported in `not_found`. Auth: secret key.

### Changed
- **`error_groups.status` ENUM expanded** - Added `investigating` between `open` and `resolved`. Run the migration below on existing installs:

```sql
ALTER TABLE `error_groups`
  MODIFY `status` ENUM('open','investigating','resolved','ignored') NOT NULL DEFAULT 'open';
```

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