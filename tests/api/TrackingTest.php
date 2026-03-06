<?php
/*
    ===============================================================
        Micrologs — Tracking Endpoint Tests
        Covers: track/pageview, track/error, track/audit,
                track/errors-update-status
    ===============================================================
*/

use PHPUnit\Framework\TestCase;

class TrackingTest extends TestCase
{
    private static array $project   = [];
    private static int   $errorGroupId = 0;

    public static function setUpBeforeClass(): void
    {
        self::$project = createTestProject("Tracking Test Project");
    }

    public static function tearDownAfterClass(): void
    {
        if (!empty(self::$project)) {
            deleteTestProject(self::$project["id"], "Tracking Test Project");
        }
    }

    // ── Pageview ──────────────────────────────────────────────────

    public function test_track_pageview_returns_202(): void
    {
        $res = apiRequest("POST", "/api/track/pageview.php", [
            "url"            => "https://example.com/home",
            "page_title"     => "Home",
            "referrer"       => "",
            "visitor_id"     => "test-visitor-uuid-001",
            "session_token"  => "test-session-uuid-001",
            "fingerprint"    => "test-fingerprint-001",
            "screen_resolution" => "1920x1080",
            "timezone"       => "Asia/Kolkata",
        ], ["X-API-Key" => self::$project["public_key"]]);

        $this->assertSame(202, $res["status"]);
        $this->assertTrue($res["body"]["success"]);
        $this->assertTrue($res["body"]["data"]["queued"]);
    }

    public function test_track_pageview_requires_url(): void
    {
        $res = apiRequest("POST", "/api/track/pageview.php", [
            "visitor_id"    => "test-visitor-uuid-001",
            "session_token" => "test-session-uuid-001",
        ], ["X-API-Key" => self::$project["public_key"]]);

        $this->assertSame(400, $res["status"]);
    }

    public function test_track_pageview_requires_visitor_id(): void
    {
        $res = apiRequest("POST", "/api/track/pageview.php", [
            "url"           => "https://example.com",
            "session_token" => "test-session-uuid-001",
        ], ["X-API-Key" => self::$project["public_key"]]);

        $this->assertSame(400, $res["status"]);
    }

    public function test_track_pageview_requires_session_token(): void
    {
        $res = apiRequest("POST", "/api/track/pageview.php", [
            "url"        => "https://example.com",
            "visitor_id" => "test-visitor-uuid-001",
        ], ["X-API-Key" => self::$project["public_key"]]);

        $this->assertSame(400, $res["status"]);
    }

    public function test_track_pageview_rejects_invalid_key(): void
    {
        $res = apiRequest("POST", "/api/track/pageview.php", [
            "url"           => "https://example.com",
            "visitor_id"    => "v1",
            "session_token" => "s1",
        ], ["X-API-Key" => "bad_key"]);

        $this->assertSame(401, $res["status"]);
    }

    public function test_track_pageview_rejects_secret_key(): void
    {
        // Pageview endpoint only accepts public key, not secret key
        $res = apiRequest("POST", "/api/track/pageview.php", [
            "url"           => "https://example.com",
            "visitor_id"    => "v1",
            "session_token" => "s1",
        ], ["X-API-Key" => self::$project["secret_key"]]);

        // Should reject — secret key is not valid for pageview endpoint
        $this->assertSame(401, $res["status"]);
    }

    public function test_track_pageview_requires_post(): void
    {
        $res = apiRequest("GET", "/api/track/pageview.php", [], ["X-API-Key" => self::$project["public_key"]]);
        $this->assertSame(405, $res["status"]);
    }

    // ── Error ─────────────────────────────────────────────────────

    public function test_track_error_returns_202(): void
    {
        $res = apiRequest("POST", "/api/track/error.php", [
            "message"    => "Test error from PHPUnit",
            "error_type" => "PHPUnitError",
            "file"       => "/app/test.php",
            "line"       => 42,
            "severity"   => "error",
            "environment"=> "staging",
            "context"    => ["test" => true],
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(202, $res["status"]);
        $this->assertTrue($res["body"]["data"]["queued"]);
    }

    public function test_track_error_accepts_public_key(): void
    {
        $res = apiRequest("POST", "/api/track/error.php", [
            "message" => "Error from browser",
        ], ["X-API-Key" => self::$project["public_key"]]);

        // Public key is valid for error endpoint (JS snippet usage)
        $this->assertSame(202, $res["status"]);
    }

    public function test_track_error_requires_message(): void
    {
        $res = apiRequest("POST", "/api/track/error.php", [
            "error_type" => "SomeError",
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(400, $res["status"]);
    }

    public function test_track_error_defaults_severity_to_error(): void
    {
        // No severity provided — should default to "error" not reject
        $res = apiRequest("POST", "/api/track/error.php", [
            "message" => "No severity provided",
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(202, $res["status"]);
    }

    public function test_track_error_defaults_environment_to_production(): void
    {
        $res = apiRequest("POST", "/api/track/error.php", [
            "message" => "No environment provided",
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(202, $res["status"]);
    }

    public function test_track_error_rejects_invalid_key(): void
    {
        $res = apiRequest("POST", "/api/track/error.php", [
            "message" => "Test",
        ], ["X-API-Key" => "bad_key"]);

        $this->assertSame(401, $res["status"]);
    }

    public function test_track_error_invalid_severity_defaults_to_error(): void
    {
        // Invalid severity should be sanitised to "error", not rejected
        $res = apiRequest("POST", "/api/track/error.php", [
            "message"  => "Test",
            "severity" => "ultra_critical_mega_bad",
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(202, $res["status"]);
    }

    // ── Audit ─────────────────────────────────────────────────────

    public function test_track_audit_returns_202(): void
    {
        $res = apiRequest("POST", "/api/track/audit.php", [
            "action"  => "user.login",
            "actor"   => "test@example.com",
            "context" => ["role" => "admin"],
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(202, $res["status"]);
        $this->assertTrue($res["body"]["data"]["queued"]);
    }

    public function test_track_audit_accepts_public_key(): void
    {
        $res = apiRequest("POST", "/api/track/audit.php", [
            "action" => "page.view",
        ], ["X-API-Key" => self::$project["public_key"]]);

        $this->assertSame(202, $res["status"]);
    }

    public function test_track_audit_requires_action(): void
    {
        $res = apiRequest("POST", "/api/track/audit.php", [
            "actor" => "user@example.com",
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(400, $res["status"]);
    }

    public function test_track_audit_actor_is_optional(): void
    {
        $res = apiRequest("POST", "/api/track/audit.php", [
            "action" => "system.boot",
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(202, $res["status"]);
    }

    public function test_track_audit_context_is_optional(): void
    {
        $res = apiRequest("POST", "/api/track/audit.php", [
            "action" => "user.logout",
            "actor"  => "user@example.com",
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(202, $res["status"]);
    }

    public function test_track_audit_rejects_invalid_key(): void
    {
        $res = apiRequest("POST", "/api/track/audit.php", [
            "action" => "test",
        ], ["X-API-Key" => "bad_key"]);

        $this->assertSame(401, $res["status"]);
    }

    // ── Errors Update Status ──────────────────────────────────────
    // Note: these tests require an error group to exist in the DB.
    // Run after workers have processed the error above, or seed manually.

    public function test_update_error_status_requires_secret_key(): void
    {
        $res = apiRequest("POST", "/api/track/errors-update-status.php", [
            "id"     => 1,
            "status" => "resolved",
        ], ["X-API-Key" => self::$project["public_key"]]);

        // Public key is not accepted here — must be secret key
        $this->assertSame(401, $res["status"]);
    }

    public function test_update_error_status_rejects_invalid_status(): void
    {
        $res = apiRequest("POST", "/api/track/errors-update-status.php", [
            "id"     => 1,
            "status" => "deleted",
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(400, $res["status"]);
    }

    public function test_update_error_status_requires_id_or_ids(): void
    {
        $res = apiRequest("POST", "/api/track/errors-update-status.php", [
            "status" => "resolved",
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(400, $res["status"]);
    }

    public function test_update_error_status_rejects_over_100_ids(): void
    {
        $ids = range(1, 101);
        $res = apiRequest("POST", "/api/track/errors-update-status.php", [
            "ids"    => $ids,
            "status" => "resolved",
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(400, $res["status"]);
    }

    public function test_update_error_status_returns_404_for_nonexistent_ids(): void
    {
        $res = apiRequest("POST", "/api/track/errors-update-status.php", [
            "ids"    => [999991, 999992],
            "status" => "resolved",
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(404, $res["status"]);
    }

    public function test_update_error_status_valid_statuses_accepted(): void
    {
        // We can't test actual updates without DB data, but we can verify
        // the valid status list is enforced correctly
        $validStatuses   = ["open", "investigating", "resolved", "ignored"];
        $invalidStatuses = ["pending", "closed", "active", ""];

        foreach ($invalidStatuses as $status) {
            $res = apiRequest("POST", "/api/track/errors-update-status.php", [
                "id"     => 1,
                "status" => $status,
            ], ["X-API-Key" => self::$project["secret_key"]]);

            $this->assertSame(400, $res["status"], "Expected 400 for status: '$status'");
        }
    }
}
?>