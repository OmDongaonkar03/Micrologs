# Micrologs — Test Suite

Complete tests for all APIs, workers, and load scenarios.

## Structure

```
tests/
├── bootstrap.php           # DB setup/teardown, shared helpers
├── phpunit.xml.dist        # PHPUnit config template — copy to phpunit.xml and fill in values
│
├── api/
│   ├── ProjectTest.php     # projects/* endpoints (create, list, edit, toggle, regen, delete, verify)
│   ├── TrackingTest.php    # track/* endpoints (pageview, error, audit, errors-update-status)
│   ├── LinksTest.php       # links/* endpoints (create, list, detail, edit, delete)
│   ├── AnalyticsTest.php   # all 14 analytics endpoints
│   └── HealthTest.php      # health endpoint
│
├── workers/
│   └── WorkerTest.php      # processPageview, processError, processAudit (direct DB, no HTTP)
│
├── load/
│   ├── pageview.js         # k6 — tracking endpoint (baseline/stress/spike/soak)
│   └── analytics.js        # k6 — analytics endpoints
│
└── battle/
    └── CHECKLIST.md        # Manual failure scenario checklist
```

---

## PHP Tests (API + Workers)

### Prerequisites

- PHP 8.1+
- Composer
- MySQL running with a user that can CREATE/DROP databases
- Valkey/Redis running
- A local Micrologs instance running and pointed at the test DB (for API tests)

### Install PHPUnit

```bash
composer require --dev phpunit/phpunit ^10
```

### Configure

Copy the config template and fill in your values:

```bash
cp tests/phpunit.xml.dist tests/phpunit.xml
```

Then edit `tests/phpunit.xml` — at minimum set `ML_TEST_ADMIN_KEY` to match the `ADMIN_KEY` in your `authorization/env.php`.

The other defaults work for a standard local setup:

| Variable | Default | Description |
|---|---|---|
| `ML_TEST_DB_HOST` | `127.0.0.1` | MySQL host |
| `ML_TEST_DB_USER` | `root` | MySQL user |
| `ML_TEST_DB_PASS` | *(empty)* | MySQL password |
| `ML_TEST_URL` | `http://localhost/micrologs` | Running Micrologs instance |
| `ML_TEST_ADMIN_KEY` | *(must set)* | Must match `ADMIN_KEY` in `env.php` |
| `ML_TEST_VALKEY_HOST` | `127.0.0.1` | Valkey/Redis host |
| `ML_TEST_VALKEY_PORT` | `6379` | Valkey/Redis port |

Alternatively, export env vars directly instead of editing the file:

```bash
export ML_TEST_DB_PASS=your_password
export ML_TEST_ADMIN_KEY=your_admin_key
```

> `tests/phpunit.xml` is gitignored. Never commit it — it contains your real admin key.

### Run

```bash
# All tests
utils/vendor/bin/phpunit --configuration tests/phpunit.xml

# Just API tests
utils/vendor/bin/phpunit --configuration tests/phpunit.xml --testsuite api

# Just worker tests
utils/vendor/bin/phpunit --configuration tests/phpunit.xml --testsuite workers

# Single test class
utils/vendor/bin/phpunit tests/api/ProjectTest.php

# Single test method
utils/vendor/bin/phpunit --filter test_create_project_succeeds tests/api/ProjectTest.php
```

### How it works

1. `bootstrap.php` drops and recreates `micrologs_test` from `schema.sql` for a clean slate
2. API tests make real HTTP requests to your running instance
3. Worker tests call `processPageview`, `processError`, `processAudit` directly against the test DB — no HTTP layer involved
4. On shutdown, `micrologs_test` is dropped automatically

> API tests require a running Micrologs instance pointed at the **test DB**, not your production DB.
> The simplest way locally: configure your dev server's `env.php` to use `micrologs_test` as `DB_NAME`.

---

## Load Tests (k6)

### Install k6

```bash
# macOS
brew install k6

# Linux
sudo gpg --no-default-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg \
  --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" \
  | sudo tee /etc/apt/sources.list.d/k6.list
sudo apt-get update && sudo apt-get install k6
```

### Run

```bash
export BASE_URL=https://your-server.com
export PUBLIC_KEY=your_public_key
export SECRET_KEY=your_secret_key

# Tracking endpoint
k6 run tests/load/pageview.js                          # baseline  (10 VUs, 60s)
k6 run -e SCENARIO=stress tests/load/pageview.js       # stress   (100 VUs, 60s)
k6 run -e SCENARIO=peak   tests/load/pageview.js       # peak     (500 VUs, 60s)
k6 run -e SCENARIO=spike  tests/load/pageview.js       # spike    (0 → 500 → 0)
k6 run -e SCENARIO=soak   tests/load/pageview.js       # soak     (50 VUs, 1h)

# Analytics endpoints
k6 run tests/load/analytics.js
k6 run -e SCENARIO=stress tests/load/analytics.js
```

### Performance targets

| Metric | Target |
|---|---|
| Tracking p99 | < 10ms |
| Analytics p99 (cache hit) | < 5ms |
| Error rate | < 1% |
| Queue drain after 10k backlog | 100% — zero data loss |

---

## Battle Testing

See `tests/battle/CHECKLIST.md` for the full manual scenario checklist covering infrastructure failures, input validation, rate limiting, security, and observability.

Run these after deploying to a new environment or after infrastructure changes. **Scenario 4 (Queue Backlog)** is the most critical — it verifies zero data loss when workers are down.

---

## CI (GitHub Actions)

```yaml
name: Tests

on: [push, pull_request]

jobs:
  php:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: micrologs_test
        ports: ["3306:3306"]
      redis:
        image: redis:7
        ports: ["6379:6379"]
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.2"
      - run: composer install --no-interaction
      - run: cp tests/phpunit.xml.dist tests/phpunit.xml
      - run: |
          php -S localhost:8080 -t . &
          sleep 2
          utils/vendor/bin/phpunit --configuration tests/phpunit.xml
        env:
          ML_TEST_DB_PASS: root
          ML_TEST_ADMIN_KEY: ${{ secrets.TEST_ADMIN_KEY }}
```