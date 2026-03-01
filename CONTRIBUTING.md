# Contributing to Micrologs

Thanks for your interest. Contributions are welcome - bug fixes, improvements, and new features.

**Before starting any significant work, open an issue first.** PRs without prior discussion may be declined - not because the work is bad, but because it might conflict with planned architecture. A quick issue saves everyone's time.

---

## Reporting a bug

Open an issue and include:
- What you did
- What you expected
- What actually happened
- PHP version, MySQL/MariaDB version, server environment

---

## Submitting a PR

1. Open an issue and discuss the change first
2. Fork the repo and create a branch from `main`
3. Make your changes
4. Test against a local PHP + MySQL setup
5. Open a PR with a clear description of what changed and why

Keep PRs focused - one fix or feature per PR makes review faster.

---

## Local setup

Requirements: PHP 8.1+, MySQL 8.0+ or MariaDB 10.4+, Composer

```bash
git clone https://github.com/OmDongaonkar03/Micrologs.git
cd Micrologs
cd utils && composer install && cd ..
cp authorization/.env.example.php authorization/env.php
# fill in your DB credentials and keys in env.php
mysql -u your_user -p micrologs < schema.sql
```

---

## Code style

- Follow the existing style in the file you're editing
- All SQL must use prepared statements - no raw string interpolation
- New endpoints should include method check, rate limiting, and auth at the top, in that order
- Log errors with `writeLog()`, don't silently swallow them
- Every `writeLog()` call automatically includes the request ID - no need to add it manually

---

## What's in scope for v1

v1 is intentionally scoped to shared hosting - no Redis, no background workers, no daemons. If your contribution requires infrastructure beyond PHP + MySQL, it belongs in v2 or v3. Check the [ROADMAP.md](ROADMAP.md) before starting work to see where things are headed.

---

## What we won't merge

- A bundled dashboard UI - Micrologs is headless by design, see [ROADMAP.md](ROADMAP.md)
- Raw string interpolation in SQL queries
- Contributions that break shared hosting compatibility in v1.x
- PRs that haven't been discussed in an issue first