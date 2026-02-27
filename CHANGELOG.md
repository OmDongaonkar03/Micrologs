# Changelog

All notable changes to Micrologs will be documented here.

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
- JS error monitoring — auto-caught via `window.onerror` and `unhandledrejection`
- Manual error tracking from any backend over HTTP
- Error grouping by fingerprint with occurrence counts
- Audit logging
- Tracked link shortener with click analytics
- Bot filtering
- File-based rate limiting (no Redis, shared hosting safe)
- Multi-project support from one install
- Public + secret key auth per project
- Domain locking on public keys