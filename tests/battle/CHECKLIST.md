# Micrologs - Battle Testing Checklist

Manual failure scenario tests. Run these after deploying a new version.
Each scenario describes the setup, the action to take, and what to verify.

---

## Infrastructure Failures

### SCENARIO 1 — Valkey Down
**Setup:** Stop Valkey (`systemctl stop valkey` or `redis-cli SHUTDOWN`)

**Test:**
```bash
curl -X POST https://your-server.com/api/track/pageview.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_PUBLIC_KEY" \
  -d '{"url":"https://example.com","visitor_id":"v1","session_token":"s1"}'
```

**Expected:**
- [ ] Returns HTTP `202` (fail-open — request accepted, not dropped)
- [ ] Error logged in `logs/app.log` mentioning Valkey connection failure
- [ ] `GET /api/health.php` returns `503` with `valkey: fail`

**Restart Valkey, then verify:**
- [ ] Health returns `200` again
- [ ] Tracking works normally

---

### SCENARIO 2 — Worker Crashed
**Setup:** Kill one worker process (`kill -9 $(cat supervisor/pids/pageview-worker.pid)`)

**Test:** Send 20 pageview requests through the API

**Expected (immediate):**
- [ ] All 20 requests return `202` — endpoint doesn't know worker is dead
- [ ] Events pile up in the Valkey queue (`redis-cli LLEN micrologs:pageviews` > 0)

**After Supervisor restarts the worker (within ~5s):**
- [ ] `redis-cli LLEN micrologs:pageviews` returns `0` — queue drained
- [ ] All 20 events appear in `pageviews` table
- [ ] Health endpoint shows worker as `ok` again

---

### SCENARIO 3 — Database Down
**Setup:** Stop MySQL (`systemctl stop mysql`)

**Test:** Hit any endpoint

**Expected:**
- [ ] `GET /api/health.php` returns `503` with `database: fail`
- [ ] `GET /api/analytics/visitors.php` returns `503`
- [ ] `POST /api/track/pageview.php` still returns `202` (queue still works if Valkey is up)
- [ ] Worker logs DB connection errors but does not exit (keeps retrying)

**Restart MySQL:**
- [ ] Health returns `200`
- [ ] Worker reconnects and drains the queue

---

### SCENARIO 4 — Queue Backlog (Zero Data Loss Test)
**This is the most critical battle test.**

**Setup:**
1. Stop all three workers (`supervisorctl stop all`)
2. Confirm `redis-cli LLEN micrologs:pageviews` = 0 before starting

**Test:** Send 10,000 pageview events
```bash
# Using k6 or a simple loop:
for i in $(seq 1 10000); do
  curl -s -X POST https://your-server.com/api/track/pageview.php \
    -H "Content-Type: application/json" \
    -H "X-API-Key: YOUR_PUBLIC_KEY" \
    -d "{\"url\":\"https://example.com/page-$i\",\"visitor_id\":\"visitor-$i\",\"session_token\":\"session-$i\"}" &
done
wait
```

**Verify queue depth:**
```bash
redis-cli LLEN micrologs:pageviews   # Should be ~10000
```

**Restart workers:**
```bash
supervisorctl start all
```

**Watch drain:**
```bash
watch -n1 'redis-cli LLEN micrologs:pageviews'
```

**Expected:**
- [ ] Queue drains to `0` — every event processed
- [ ] `SELECT COUNT(*) FROM pageviews` shows the correct number (minus deduplication)
- [ ] No errors in `logs/app.log`
- [ ] **Zero data loss** — this is a hard pass/fail

---

## Input Validation

### SCENARIO 5 — Malformed JSON
```bash
curl -X POST https://your-server.com/api/track/error.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_SECRET_KEY" \
  -d 'this is not json'
```

**Expected:**
- [ ] Returns `400` with `"Invalid or missing JSON body"`

---

### SCENARIO 6 — Oversized Payload
```bash
# Generate a 100KB string
LARGE=$(python3 -c "print('x' * 102400)")

curl -X POST https://your-server.com/api/track/error.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_SECRET_KEY" \
  -d "{\"message\":\"$LARGE\"}"
```

**Expected:**
- [ ] Returns `400` or `413` — payload is rejected, not stored

---

### SCENARIO 7 — Invalid API Key
```bash
curl -X POST https://your-server.com/api/track/pageview.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: definitely_not_a_real_key" \
  -d '{"url":"https://example.com","visitor_id":"v1","session_token":"s1"}'
```

**Expected:**
- [ ] Returns `401` with `"Invalid API key"`
- [ ] Nothing written to queue

---

### SCENARIO 8 — Wrong HTTP Method
```bash
curl https://your-server.com/api/track/pageview.php \
  -H "X-API-Key: YOUR_PUBLIC_KEY"
```

**Expected:**
- [ ] Returns `405` with `"Method not allowed"`

---

## Rate Limiting

### SCENARIO 9 — Rate Limit Hit
```bash
# Hit the same endpoint 65 times in one minute (limit is 60)
for i in $(seq 1 65); do
  curl -s -o /dev/null -w "%{http_code}\n" \
    -X POST https://your-server.com/api/track/pageview.php \
    -H "Content-Type: application/json" \
    -H "X-API-Key: YOUR_PUBLIC_KEY" \
    -d '{"url":"https://example.com","visitor_id":"v1","session_token":"s1"}'
done
```

**Expected:**
- [ ] First 60 requests: `202`
- [ ] Request 61+: `429`
- [ ] After 60 seconds: rate limit resets, requests accepted again

---

## Security

### SCENARIO 10 — Secret Key Used on Pageview Endpoint
```bash
curl -X POST https://your-server.com/api/track/pageview.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_SECRET_KEY" \
  -d '{"url":"https://example.com","visitor_id":"v1","session_token":"s1"}'
```

**Expected:**
- [ ] Returns `401` — pageview endpoint only accepts public key

---

### SCENARIO 11 — Public Key Used on Analytics Endpoint
```bash
curl https://your-server.com/api/analytics/visitors.php \
  -H "X-API-Key: YOUR_PUBLIC_KEY"
```

**Expected:**
- [ ] Returns `401` — analytics requires secret key

---

### SCENARIO 12 — Cross-Project Data Access
**Setup:** Create two projects (A and B), get their secret keys

```bash
# Try to access project A's analytics with project B's key
curl "https://your-server.com/api/analytics/visitors.php?range=30d" \
  -H "X-API-Key: PROJECT_B_SECRET_KEY"
```

**Expected:**
- [ ] Returns `200` but with project B's data only — project A's data is not visible
- [ ] No data bleed between projects

---

### SCENARIO 13 — Delete Project With Wrong Confirmation
```bash
curl -X POST https://your-server.com/api/projects/delete.php \
  -H "Content-Type: application/json" \
  -H "X-Admin-Key: YOUR_ADMIN_KEY" \
  -d '{"id": 1, "confirm": "wrong name"}'
```

**Expected:**
- [ ] Returns `400` — project not deleted
- [ ] Error message includes the correct project name to use as confirmation

---

## Observability

### SCENARIO 14 — Verify Logging
After running error scenarios above:

```bash
tail -100 logs/app.log
```

**Expected:**
- [ ] All errors are logged with timestamp, level, and context
- [ ] No PHP warnings or notices in the log
- [ ] Log entries are valid JSON (structured logging)

---

## Performance Baselines

Run these after confirming all scenarios pass:

| Test | Command | Pass Threshold |
|------|---------|---------------|
| Tracking p99 | `k6 run tests/load/pageview.js` | < 10ms |
| Analytics p99 (cache hit) | `k6 run tests/load/analytics.js` | < 5ms |
| Error rate under load | `k6 run -e SCENARIO=stress tests/load/pageview.js` | < 1% |
| Spike recovery | `k6 run -e SCENARIO=spike tests/load/pageview.js` | No dropped requests |
| Soak memory | `k6 run -e SCENARIO=soak tests/load/pageview.js` | PHP-FPM memory stable after 1h |

---

## Sign-Off

| Scenario | Pass | Tested By | Date |
|----------|------|-----------|------|
| 1 — Valkey Down | ☐ | | |
| 2 — Worker Crashed | ☐ | | |
| 3 — DB Down | ☐ | | |
| 4 — Queue Backlog (Zero Loss) | ☐ | | |
| 5 — Malformed JSON | ☐ | | |
| 6 — Oversized Payload | ☐ | | |
| 7 — Invalid API Key | ☐ | | |
| 8 — Wrong HTTP Method | ☐ | | |
| 9 — Rate Limit | ☐ | | |
| 10 — Secret Key on Pageview | ☐ | | |
| 11 — Public Key on Analytics | ☐ | | |
| 12 — Cross-Project Access | ☐ | | |
| 13 — Delete Confirmation | ☐ | | |
| 14 — Logging | ☐ | | |