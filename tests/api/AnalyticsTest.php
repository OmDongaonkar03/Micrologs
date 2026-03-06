<?php
/*
    ===============================================================
        Micrologs — Analytics API Tests
        Covers all 14 analytics endpoints.
        Note: Most return empty data since no real events are
        seeded in the test DB. We test structure, auth, range
        params, and filter validation.
    ===============================================================
*/

use PHPUnit\Framework\TestCase;

class AnalyticsTest extends TestCase
{
    private static array $project = [];

    public static function setUpBeforeClass(): void
    {
        self::$project = createTestProject("Analytics Test Project");
    }

    public static function tearDownAfterClass(): void
    {
        if (!empty(self::$project)) {
            deleteTestProject(self::$project["id"], "Analytics Test Project");
        }
    }

    // ── Shared auth + method checks ───────────────────────────────

    private function assertRequiresSecretKey(string $path, array $query = []): void
    {
        $res = apiRequest("GET", $path, [], ["X-API-Key" => self::$project["public_key"]], $query);
        $this->assertSame(401, $res["status"], "Expected 401 for public key on $path");

        $res = apiRequest("GET", $path, [], [], $query);
        $this->assertSame(401, $res["status"], "Expected 401 for missing key on $path");
    }

    private function assertRequiresGetMethod(string $path): void
    {
        $res = apiRequest("POST", $path, [], ["X-API-Key" => self::$project["secret_key"]]);
        $this->assertSame(405, $res["status"], "Expected 405 for POST on $path");
    }

    private function assertValidRangeResponse(array $res, string $path): void
    {
        $this->assertSame(200, $res["status"],   "$path: expected 200");
        $this->assertTrue($res["body"]["success"], "$path: success should be true");
        $this->assertArrayHasKey("range", $res["body"]["data"], "$path: missing range key");
        $this->assertArrayHasKey("from",  $res["body"]["data"]["range"], "$path: missing range.from");
        $this->assertArrayHasKey("to",    $res["body"]["data"]["range"], "$path: missing range.to");
    }

    // ── Visitors ──────────────────────────────────────────────────

    public function test_visitors_returns_expected_structure(): void
    {
        $res = apiRequest("GET", "/api/analytics/visitors.php", [], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertValidRangeResponse($res, "visitors");
        $data = $res["body"]["data"];
        $this->assertArrayHasKey("unique_visitors", $data);
        $this->assertArrayHasKey("total_pageviews", $data);
        $this->assertArrayHasKey("total_sessions",  $data);
        $this->assertArrayHasKey("bounce_rate",     $data);
        $this->assertArrayHasKey("over_time",       $data);
        $this->assertIsArray($data["over_time"]);
    }

    public function test_visitors_requires_secret_key(): void
    {
        $this->assertRequiresSecretKey("/api/analytics/visitors.php");
    }

    public function test_visitors_requires_get_method(): void
    {
        $this->assertRequiresGetMethod("/api/analytics/visitors.php");
    }

    public function test_visitors_accepts_range_param(): void
    {
        foreach (["7d", "30d", "90d"] as $range) {
            $res = apiRequest(
                "GET", "/api/analytics/visitors.php",
                [], ["X-API-Key" => self::$project["secret_key"]],
                ["range" => $range]
            );
            $this->assertSame(200, $res["status"], "Range $range failed");
        }
    }

    public function test_visitors_accepts_custom_range(): void
    {
        $res = apiRequest(
            "GET", "/api/analytics/visitors.php",
            [], ["X-API-Key" => self::$project["secret_key"]],
            ["range" => "custom", "from" => "2026-01-01", "to" => "2026-01-31"]
        );
        $this->assertSame(200, $res["status"]);
    }

    public function test_visitors_rejects_custom_range_without_from_to(): void
    {
        $res = apiRequest(
            "GET", "/api/analytics/visitors.php",
            [], ["X-API-Key" => self::$project["secret_key"]],
            ["range" => "custom"]
        );
        $this->assertSame(400, $res["status"]);
    }

    public function test_visitors_rejects_range_over_365_days(): void
    {
        $res = apiRequest(
            "GET", "/api/analytics/visitors.php",
            [], ["X-API-Key" => self::$project["secret_key"]],
            ["range" => "custom", "from" => "2024-01-01", "to" => "2026-01-01"]
        );
        $this->assertSame(400, $res["status"]);
    }

    // ── Returning visitors ────────────────────────────────────────

    public function test_returning_returns_expected_structure(): void
    {
        $res = apiRequest("GET", "/api/analytics/visitors-returning.php", [], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertValidRangeResponse($res, "returning");
        $data = $res["body"]["data"];
        $this->assertArrayHasKey("total_visitors",     $data);
        $this->assertArrayHasKey("new_visitors",       $data);
        $this->assertArrayHasKey("returning_visitors", $data);
        $this->assertArrayHasKey("new_pct",            $data);
        $this->assertArrayHasKey("returning_pct",      $data);
        $this->assertArrayHasKey("over_time",          $data);
    }

    public function test_returning_requires_secret_key(): void
    {
        $this->assertRequiresSecretKey("/api/analytics/visitors-returning.php");
    }

    // ── Sessions ──────────────────────────────────────────────────

    public function test_sessions_returns_expected_structure(): void
    {
        $res = apiRequest("GET", "/api/analytics/sessions.php", [], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertValidRangeResponse($res, "sessions");
        $data = $res["body"]["data"];
        $this->assertArrayHasKey("total_sessions",        $data);
        $this->assertArrayHasKey("bounce_rate",           $data);
        $this->assertArrayHasKey("avg_duration_seconds",  $data);
        $this->assertArrayHasKey("avg_duration_engaged",  $data);
        $this->assertArrayHasKey("avg_pages_per_session", $data);
        $this->assertArrayHasKey("over_time",             $data);
    }

    public function test_sessions_requires_secret_key(): void
    {
        $this->assertRequiresSecretKey("/api/analytics/sessions.php");
    }

    // ── Pages ─────────────────────────────────────────────────────

    public function test_pages_returns_expected_structure(): void
    {
        $res = apiRequest("GET", "/api/analytics/pages.php", [], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertValidRangeResponse($res, "pages");
        $this->assertArrayHasKey("count", $res["body"]["data"]);
        $this->assertArrayHasKey("pages", $res["body"]["data"]);
        $this->assertIsArray($res["body"]["data"]["pages"]);
    }

    public function test_pages_accepts_limit_param(): void
    {
        $res = apiRequest(
            "GET", "/api/analytics/pages.php",
            [], ["X-API-Key" => self::$project["secret_key"]],
            ["limit" => 5]
        );
        $this->assertSame(200, $res["status"]);
    }

    public function test_pages_requires_secret_key(): void
    {
        $this->assertRequiresSecretKey("/api/analytics/pages.php");
    }

    // ── Devices ───────────────────────────────────────────────────

    public function test_devices_returns_expected_structure(): void
    {
        $res = apiRequest("GET", "/api/analytics/devices.php", [], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertValidRangeResponse($res, "devices");
        $data = $res["body"]["data"];
        $this->assertArrayHasKey("by_device",  $data);
        $this->assertArrayHasKey("by_os",      $data);
        $this->assertArrayHasKey("by_browser", $data);
    }

    public function test_devices_requires_secret_key(): void
    {
        $this->assertRequiresSecretKey("/api/analytics/devices.php");
    }

    // ── Locations ─────────────────────────────────────────────────

    public function test_locations_returns_expected_structure(): void
    {
        $res = apiRequest("GET", "/api/analytics/locations.php", [], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertValidRangeResponse($res, "locations");
        $data = $res["body"]["data"];
        $this->assertArrayHasKey("by_country", $data);
        $this->assertArrayHasKey("by_region",  $data);
        $this->assertArrayHasKey("by_city",    $data);
    }

    public function test_locations_requires_secret_key(): void
    {
        $this->assertRequiresSecretKey("/api/analytics/locations.php");
    }

    // ── Referrers ─────────────────────────────────────────────────

    public function test_referrers_returns_expected_structure(): void
    {
        $res = apiRequest("GET", "/api/analytics/referrers.php", [], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertValidRangeResponse($res, "referrers");
        $data = $res["body"]["data"];
        $this->assertArrayHasKey("by_category",   $data);
        $this->assertArrayHasKey("top_referrers", $data);
    }

    public function test_referrers_requires_secret_key(): void
    {
        $this->assertRequiresSecretKey("/api/analytics/referrers.php");
    }

    // ── UTM ───────────────────────────────────────────────────────

    public function test_utm_returns_expected_structure(): void
    {
        $res = apiRequest("GET", "/api/analytics/utm.php", [], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertValidRangeResponse($res, "utm");
        $data = $res["body"]["data"];
        $this->assertArrayHasKey("count",     $data);
        $this->assertArrayHasKey("campaigns", $data);
        $this->assertIsArray($data["campaigns"]);
    }

    public function test_utm_requires_secret_key(): void
    {
        $this->assertRequiresSecretKey("/api/analytics/utm.php");
    }

    // ── Errors ────────────────────────────────────────────────────

    public function test_errors_returns_expected_structure(): void
    {
        $res = apiRequest("GET", "/api/analytics/errors.php", [], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertValidRangeResponse($res, "errors");
        $data = $res["body"]["data"];
        $this->assertArrayHasKey("summary", $data);
        $this->assertArrayHasKey("count",   $data);
        $this->assertArrayHasKey("errors",  $data);

        $summary = $data["summary"];
        $this->assertArrayHasKey("total",         $summary);
        $this->assertArrayHasKey("open",          $summary);
        $this->assertArrayHasKey("investigating", $summary);
        $this->assertArrayHasKey("resolved",      $summary);
        $this->assertArrayHasKey("ignored",       $summary);
    }

    public function test_errors_accepts_valid_filters(): void
    {
        foreach (["open", "investigating", "resolved", "ignored"] as $status) {
            $res = apiRequest(
                "GET", "/api/analytics/errors.php",
                [], ["X-API-Key" => self::$project["secret_key"]],
                ["status" => $status]
            );
            $this->assertSame(200, $res["status"], "Status filter '$status' failed");
        }

        foreach (["info", "warning", "error", "critical"] as $severity) {
            $res = apiRequest(
                "GET", "/api/analytics/errors.php",
                [], ["X-API-Key" => self::$project["secret_key"]],
                ["severity" => $severity]
            );
            $this->assertSame(200, $res["status"], "Severity filter '$severity' failed");
        }
    }

    public function test_errors_requires_secret_key(): void
    {
        $this->assertRequiresSecretKey("/api/analytics/errors.php");
    }

    // ── Errors Trend ──────────────────────────────────────────────

    public function test_errors_trend_returns_expected_structure(): void
    {
        $res = apiRequest("GET", "/api/analytics/errors-trend.php", [], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertValidRangeResponse($res, "errors-trend");
        $data = $res["body"]["data"];
        $this->assertArrayHasKey("total_occurrences", $data);
        $this->assertArrayHasKey("total_groups",      $data);
        $this->assertArrayHasKey("top_groups",        $data);
        $this->assertArrayHasKey("over_time",         $data);
    }

    public function test_errors_trend_accepts_group_id_filter(): void
    {
        $res = apiRequest(
            "GET", "/api/analytics/errors-trend.php",
            [], ["X-API-Key" => self::$project["secret_key"]],
            ["group_id" => 1]
        );
        // May return empty data but should not error
        $this->assertSame(200, $res["status"]);
    }

    public function test_errors_trend_requires_secret_key(): void
    {
        $this->assertRequiresSecretKey("/api/analytics/errors-trend.php");
    }

    // ── Error Detail ──────────────────────────────────────────────

    public function test_error_detail_requires_id(): void
    {
        $res = apiRequest("GET", "/api/analytics/error-detail.php", [], ["X-API-Key" => self::$project["secret_key"]]);
        $this->assertSame(400, $res["status"]);
    }

    public function test_error_detail_returns_404_for_unknown_id(): void
    {
        $res = apiRequest(
            "GET", "/api/analytics/error-detail.php",
            [], ["X-API-Key" => self::$project["secret_key"]],
            ["id" => 999999]
        );
        $this->assertSame(404, $res["status"]);
    }

    public function test_error_detail_requires_secret_key(): void
    {
        $this->assertRequiresSecretKey("/api/analytics/error-detail.php", ["id" => 1]);
    }

    // ── Audits ────────────────────────────────────────────────────

    public function test_audits_returns_expected_structure(): void
    {
        $res = apiRequest("GET", "/api/analytics/audits.php", [], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertValidRangeResponse($res, "audits");
        $data = $res["body"]["data"];
        $this->assertArrayHasKey("count",       $data);
        $this->assertArrayHasKey("top_actions", $data);
        $this->assertArrayHasKey("logs",        $data);
        $this->assertIsArray($data["logs"]);
    }

    public function test_audits_accepts_action_and_actor_filters(): void
    {
        $res = apiRequest(
            "GET", "/api/analytics/audits.php",
            [], ["X-API-Key" => self::$project["secret_key"]],
            ["action" => "user.login", "actor" => "test@example.com"]
        );
        $this->assertSame(200, $res["status"]);
    }

    public function test_audits_requires_secret_key(): void
    {
        $this->assertRequiresSecretKey("/api/analytics/audits.php");
    }

    // ── Links analytics ───────────────────────────────────────────

    public function test_analytics_links_returns_expected_structure(): void
    {
        $res = apiRequest("GET", "/api/analytics/links.php", [], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertValidRangeResponse($res, "analytics/links");
        $data = $res["body"]["data"];
        $this->assertArrayHasKey("count", $data);
        $this->assertArrayHasKey("links", $data);
        $this->assertIsArray($data["links"]);
    }

    public function test_analytics_links_requires_secret_key(): void
    {
        $this->assertRequiresSecretKey("/api/analytics/links.php");
    }

    // ── Link Detail analytics ─────────────────────────────────────

    public function test_analytics_link_detail_requires_code(): void
    {
        $res = apiRequest("GET", "/api/analytics/link-detail.php", [], ["X-API-Key" => self::$project["secret_key"]]);
        $this->assertSame(400, $res["status"]);
    }

    public function test_analytics_link_detail_returns_404_for_unknown_code(): void
    {
        $res = apiRequest(
            "GET", "/api/analytics/link-detail.php",
            [], ["X-API-Key" => self::$project["secret_key"]],
            ["code" => "XXXXXXXX"]
        );
        $this->assertSame(404, $res["status"]);
    }

    public function test_analytics_link_detail_requires_secret_key(): void
    {
        $this->assertRequiresSecretKey("/api/analytics/link-detail.php", ["code" => "abc"]);
    }
}
?>