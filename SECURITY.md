# Security Policy

## Supported Versions

| Version | Supported |
|---|---|
| 1.3.x | Active |
| 1.2.x | Security fixes only |
| 1.1.x | No longer supported |
| 1.0.x | No longer supported |

Upgrade to the latest version. Security fixes are backported to the previous minor version only.

---

## Reporting a Vulnerability

**Do not open a public GitHub issue for security vulnerabilities.**

Report vulnerabilities privately via GitHub's Security Advisory feature:

**[→ Report a vulnerability](https://github.com/OmDongaonkar03/Micrologs/security/advisories/new)**

Include as much detail as possible:
- Description of the vulnerability
- Steps to reproduce
- Affected version(s)
- Potential impact
- Suggested fix if you have one

You will receive a response within **72 hours**. If the vulnerability is confirmed, a fix will be prioritized and a new release will be shipped as soon as possible. You will be credited in the release notes unless you prefer to remain anonymous.

---

## Security Design Decisions

These are intentional design choices worth understanding:

**IPs are never stored raw.** All IP addresses are SHA-256 hashed with a per-install salt (`IP_HASH_SALT`) immediately on ingestion. The raw IP never touches the database.

**Public key vs secret key separation.** The JS snippet uses a public key - safe to expose in the browser, locked to a domain whitelist. Analytics queries use a secret key - server-side only, never in frontend code.

**Domain locking.** Public keys are locked to an `allowed_domains` list per project. Requests from unlisted domains are rejected at the API level.

**X-Forwarded-For is not trusted by default.** `TRUSTED_PROXIES` must be explicitly set in `env.php`. On shared hosting with no proxy, XFF is completely ignored - preventing IP spoofing of the rate limiter and GeoIP lookup.

**Request bodies are capped at 64KB.** All endpoints enforce a hard payload size limit. Oversized requests receive a `413` response.

**Context fields are capped at 8KB.** The `context` JSON field in error and audit events is capped after encoding to prevent large blobs reaching the database.

---

## Scope

The following are in scope for vulnerability reports:

- SQL injection
- Authentication bypass
- Authorization issues (cross-project data access)
- Remote code execution
- Sensitive data exposure
- Rate limiter bypass

The following are out of scope:

- Issues requiring physical access to the server
- Social engineering
- Vulnerabilities in third-party dependencies (report those upstream)
- Issues in outdated, unsupported versions