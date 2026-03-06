/**
 * Micrologs — Analytics Endpoint Load Test
 *
 * Tests all 14 analytics endpoints under concurrent load.
 * Main concern: cache hit rate, p99 on cache hits vs misses.
 *
 * Usage:
 *   k6 run tests/load/analytics.js
 *   k6 run -e SCENARIO=stress tests/load/analytics.js
 *
 * Required env vars:
 *   BASE_URL   — e.g. http://your-server.com
 *   SECRET_KEY — project secret key for analytics
 */

import http from "k6/http";
import { check, sleep } from "k6";
import { Rate, Trend } from "k6/metrics";

const cacheHitRate = new Rate("cache_hit_rate");
const analyticsLatency = new Trend("analytics_latency_ms", true);
const errorRate = new Rate("error_rate");

const scenario = __ENV.SCENARIO || "baseline";

export const options = {
  scenarios: {
    analytics_load: {
      executor: scenario === "stress" ? "constant-vus" : "constant-vus",
      vus: scenario === "stress" ? 50 : 10,
      duration: "60s",
    },
  },
  thresholds: {
    // Cache hits should be fast
    "http_req_duration{name:analytics_cached}": ["p(99)<5"],
    // Cache misses (first request) can be slower
    "http_req_duration{name:analytics_uncached}": ["p(99)<500"],
    error_rate: ["rate<0.01"],
  },
};

const BASE_URL = __ENV.BASE_URL || "http://localhost:8080";
const SECRET_KEY = __ENV.SECRET_KEY || "your_secret_key_here";

const analyticsEndpoints = [
  "/api/analytics/visitors.php",
  "/api/analytics/visitors-returning.php",
  "/api/analytics/sessions.php",
  "/api/analytics/pages.php",
  "/api/analytics/devices.php",
  "/api/analytics/locations.php",
  "/api/analytics/referrers.php",
  "/api/analytics/utm.php",
  "/api/analytics/errors.php",
  "/api/analytics/errors-trend.php",
  "/api/analytics/audits.php",
  "/api/analytics/links.php",
];

const ranges = ["7d", "30d", "90d"];

function randomItem(arr) {
  return arr[Math.floor(Math.random() * arr.length)];
}

// Track which requests are first (cache miss) vs subsequent (cache hit)
let requestCount = 0;

export default function () {
  const endpoint = randomItem(analyticsEndpoints);
  const range = randomItem(ranges);
  const isFirst = requestCount++ < analyticsEndpoints.length * ranges.length;

  const tag = isFirst ? "analytics_uncached" : "analytics_cached";

  const res = http.get(`${BASE_URL}${endpoint}?range=${range}`, {
    headers: {
      "X-API-Key": SECRET_KEY,
    },
    tags: { name: tag },
  });

  const success = check(res, {
    "status is 200": (r) => r.status === 200,
    "has data key": (r) => {
      try {
        const body = JSON.parse(r.body);
        return body.success === true && "data" in body;
      } catch {
        return false;
      }
    },
  });

  errorRate.add(!success);
  analyticsLatency.add(res.timings.duration);

  sleep(0.05);
}