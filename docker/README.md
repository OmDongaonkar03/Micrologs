# Micrologs — Docker Dev & Test Environment

Simulates a production-like environment locally. Eliminates XAMPP/Windows limitations for load and stress testing.

## Stack

| Service | Image | Port (host) |
|---|---|---|
| Apache | httpd:2.4 | 8080 |
| PHP-FPM | php:8.2-fpm | - |
| MySQL | mysql:8.0 | 3307 |
| Valkey | valkey/valkey:7 | 6380 |
| Supervisor | (same as PHP) | - |

---

## Setup

**1. Copy the Docker env file:**

```bash
cp docker/env.docker.php authorization/env.php
```

Edit `authorization/env.php` and fill in your real `ADMIN_KEY`, `IP_HASH_SALT`, and any other values.

**2. Start all services:**

```bash
docker compose up -d
```

**3. Import the schema:**

```bash
docker exec micrologs-mysql mysql -umicrologs -pmicrologs micrologs < schema.sql
```

Or use the setup wizard at `http://localhost:8080/setup.php`.

**4. Verify everything is running:**

```bash
curl http://localhost:8080/api/health.php
```

Should return `{"status":"healthy",...}`.

---

## Running Tests

### PHPUnit (API + Workers)

```bash
# Copy and configure the test config
cp tests/phpunit.xml.dist tests/phpunit.xml
# Set ML_TEST_ADMIN_KEY to match your ADMIN_KEY in env.php

# Run inside the PHP container
docker exec micrologs-php utils/vendor/bin/phpunit --configuration tests/phpunit.xml

# Just API tests
docker exec micrologs-php utils/vendor/bin/phpunit --configuration tests/phpunit.xml --testsuite api

# Just worker tests
docker exec micrologs-php utils/vendor/bin/phpunit --configuration tests/phpunit.xml --testsuite workers
```

### k6 Load Tests

Run from your host machine (k6 installed locally):

```bash
# Baseline
k6 run -e BASE_URL=http://localhost:8080 -e PUBLIC_KEY=your_public_key tests/load/pageview.js

# Stress — works properly on Linux containers, no port exhaustion
k6 run -e BASE_URL=http://localhost:8080 -e PUBLIC_KEY=your_public_key -e SCENARIO=stress tests/load/pageview.js

# Spike
k6 run -e BASE_URL=http://localhost:8080 -e PUBLIC_KEY=your_public_key -e SCENARIO=spike tests/load/pageview.js

# Peak
k6 run -e BASE_URL=http://localhost:8080 -e PUBLIC_KEY=your_public_key -e SCENARIO=peak tests/load/pageview.js

# Analytics
k6 run -e BASE_URL=http://localhost:8080 -e SECRET_KEY=your_secret_key tests/load/analytics.js
```

---

## Useful Commands

```bash
# Start
docker compose up -d

# Stop
docker compose down

# Stop and wipe DB volume (clean slate)
docker compose down -v

# View logs
docker compose logs -f
docker compose logs -f php
docker compose logs -f supervisor

# Shell into PHP container
docker exec -it micrologs-php bash

# Check worker status
docker exec micrologs-supervisor supervisorctl status

# Check Valkey queue depth
docker exec micrologs-valkey valkey-cli LLEN micrologs:pageviews

# MySQL shell
docker exec -it micrologs-mysql mysql -umicrologs -pmicrologs micrologs
```

---

## Notes

- MySQL is exposed on port **3307** (not 3306) to avoid clashing with any local MySQL
- Valkey is exposed on port **6380** (not 6379) to avoid clashing with local Valkey
- The `authorization/env.php` used here points to `mysql` and `valkey` as hostnames (Docker service names), not `localhost`
- Switch back to your real `env.php` when working outside Docker