# Contributing to Micrologs

Thanks for your interest. Contributions are welcome - bug fixes, improvements, and new features.

---

## Reporting a bug

Open an issue and include:
- What you did
- What you expected
- What actually happened
- PHP version, MySQL/MariaDB version, server environment

---

## Submitting a PR

1. Fork the repo and create a branch from `main`
2. Make your changes
3. Test against a local PHP + MySQL setup
4. Open a PR with a clear description of what changed and why

Keep PRs focused — one fix or feature per PR makes review faster.

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
- All SQL must use prepared statements — no raw string interpolation
- New endpoints should include method check, rate limiting, and auth at the top, in that order
- Log errors with `writeLog()`, don't silently swallow them

---

## What's in scope for v1

v1 is intentionally scoped to shared hosting — no Redis, no background workers, no daemons. If your contribution requires infrastructure beyond PHP + MySQL, it belongs in v2 or v3. Open an issue first to discuss if unsure.