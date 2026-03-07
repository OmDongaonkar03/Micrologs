/**
 * Micrologs — k6 Load Tests
 *
 * Usage:
 *   k6 run tests/load/pageview.js                         # baseline
 *   k6 run -e SCENARIO=stress tests/load/pageview.js      # stress
 *   k6 run -e SCENARIO=spike  tests/load/pageview.js      # spike
 *   k6 run -e SCENARIO=soak   tests/load/pageview.js      # 1-hour soak
 *
 * Required env vars:
 *   BASE_URL   — e.g. http://your-server.com
 *   PUBLIC_KEY — project public key for tracking
 *
 * Install: https://k6.io/docs/get-started/installation/
 */

import http from "k6/http";
import { check, sleep } from "k6";
import { Rate, Trend } from "k6/metrics";

// ── Custom metrics ────────────────────────────────────────────────
const errorRate = new Rate("error_rate");
const trackingTrend = new Trend("tracking_latency_ms", true);

// ── Scenario config ───────────────────────────────────────────────
const scenario = __ENV.SCENARIO || "baseline";

const scenarios = {
  baseline: {
    executor: "constant-vus",
    vus: 10,
    duration: "60s",
  },
  stress: {
    executor: "constant-vus",
    vus: 100,
    duration: "60s",
  },
  peak: {
    executor: "constant-vus",
    vus: 500,
    duration: "60s",
  },
  spike: {
    executor: "ramping-vus",
    startVUs: 0,
    stages: [
      { duration: "10s", target: 500 }, // ramp up fast
      { duration: "10s", target: 500 }, // hold
      { duration: "10s", target: 0 }, // drop
    ],
  },
  soak: {
    executor: "constant-vus",
    vus: 50,
    duration: "1h",
  },
};

export const options = {
  scenarios: {
    pageview_load: scenarios[scenario] || scenarios.baseline,
  },
  thresholds: {
    // p99 under 500ms for localhost (use p(99)<10 on production VPS)
    "http_req_duration{name:track_pageview}": ["p(99)<500"],
    // Error rate under 1%
    error_rate: ["rate<0.01"],
    // No failed checks
    checks: ["rate>0.99"],
  },
};

// ── Test data ─────────────────────────────────────────────────────
const BASE_URL = __ENV.BASE_URL || "http://localhost/micrologs";
const PUBLIC_KEY = __ENV.PUBLIC_KEY || "16443b8f0ab14dfa6797736c5b92455c";

const pages = [
  "/",
  "/pricing",
  "/features",
  "/docs",
  "/blog",
  "/about",
  "/contact",
  "/signup",
  "/login",
  "/dashboard",
];

const referrers = [
  "",
  "https://google.com",
  "https://twitter.com",
  "https://github.com",
  "https://news.ycombinator.com",
];

function randomItem(arr) {
  return arr[Math.floor(Math.random() * arr.length)];
}

function randomId() {
  return Math.random().toString(36).substring(2, 18);
}

// ── VU state ──────────────────────────────────────────────────────
// Each VU gets a stable visitor ID and session (realistic — not random per request)
const visitorId = randomId();
const sessionToken = randomId();

// ── Main test function ────────────────────────────────────────────
export default function () {
  const url = "https://example.com" + randomItem(pages);
  const body = JSON.stringify({
    url,
    page_title: "Test Page",
    referrer: randomItem(referrers),
    visitor_id: visitorId,
    session_token: sessionToken,
    fingerprint: visitorId,
    screen_resolution: "1920x1080",
    timezone: "UTC",
  });

  const res = http.post(`${BASE_URL}/api/track/pageview.php`, body, {
    headers: {
      "Content-Type": "application/json",
      "X-API-Key": PUBLIC_KEY,
      Accept: "application/json",
      "Accept-Language": "en-US,en;q=0.9",
      "User-Agent":
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36",
      "X-Test-Mode": "phpunit",
    },
    tags: { name: "track_pageview" },
  });

  const success = check(res, {
    "status is 202": (r) => r.status === 202,
    "queued is true": (r) => {
      try {
        return JSON.parse(r.body).data?.queued === true;
      } catch {
        return false;
      }
    },
    "response under 500ms": (r) => r.timings.duration < 500,
  });

  if (res.status !== 202) {
    console.log(`status=${res.status} body=${res.body}`);
  }

  errorRate.add(!success);
  trackingTrend.add(res.timings.duration);

  // Minimal think time — realistic pacing
  sleep(0.1);
}

export function handleSummary(data) {
  return {
    stdout: JSON.stringify(
      {
        scenario,
        p50: data.metrics.http_req_duration?.values?.["p(50)"],
        p95: data.metrics.http_req_duration?.values?.["p(95)"],
        p99: data.metrics.http_req_duration?.values?.["p(99)"],
        errors: data.metrics.error_rate?.values?.rate,
        vus: data.metrics.vus?.values?.max,
      },
      null,
      2,
    ),
  };
}