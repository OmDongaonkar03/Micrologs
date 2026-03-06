<?php
/*
    ===============================================================
        Micrologs — Health Endpoint Tests
    ===============================================================
*/

use PHPUnit\Framework\TestCase;

class HealthTest extends TestCase
{
    public function test_health_returns_200_when_healthy(): void
    {
        $res = apiRequest("GET", "/api/health.php");

        $this->assertSame(200, $res["status"]);
        $this->assertSame("healthy", $res["body"]["status"]);
    }

    public function test_health_includes_timestamp(): void
    {
        $res = apiRequest("GET", "/api/health.php");

        $this->assertArrayHasKey("timestamp", $res["body"]);
        // Timestamp format: Y-m-d H:i:s
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $res["body"]["timestamp"]
        );
    }

    public function test_health_includes_checks(): void
    {
        $res = apiRequest("GET", "/api/health.php");

        $this->assertArrayHasKey("checks", $res["body"]);
        $checks = $res["body"]["checks"];
        $this->assertArrayHasKey("php",      $checks);
        $this->assertArrayHasKey("database", $checks);
        $this->assertArrayHasKey("valkey",   $checks);
        $this->assertArrayHasKey("geoip",    $checks);
    }

    public function test_health_php_check_passes(): void
    {
        $res    = apiRequest("GET", "/api/health.php");
        $php    = $res["body"]["checks"]["php"];

        $this->assertSame("ok", $php["status"]);
        $this->assertArrayHasKey("version", $php);
        $this->assertTrue(version_compare($php["version"], "8.1.0", ">="));
    }

    public function test_health_database_check_passes(): void
    {
        $res = apiRequest("GET", "/api/health.php");
        $this->assertSame("ok", $res["body"]["checks"]["database"]["status"]);
    }

    public function test_health_valkey_check_passes(): void
    {
        $res = apiRequest("GET", "/api/health.php");
        $this->assertSame("ok", $res["body"]["checks"]["valkey"]["status"]);
    }

    public function test_health_geoip_check_is_ok_or_warn(): void
    {
        $res    = apiRequest("GET", "/api/health.php");
        $geoip  = $res["body"]["checks"]["geoip"];

        $this->assertContains($geoip["status"], ["ok", "warn"]);
        $this->assertArrayHasKey("message", $geoip);
    }

    public function test_health_requires_get_method(): void
    {
        $res = apiRequest("POST", "/api/health.php");
        $this->assertSame(405, $res["status"]);
    }

    public function test_health_each_check_has_status_and_message(): void
    {
        $res    = apiRequest("GET", "/api/health.php");
        $checks = $res["body"]["checks"];

        foreach ($checks as $name => $check) {
            $this->assertArrayHasKey("status",  $check, "$name check missing status");
            $this->assertArrayHasKey("message", $check, "$name check missing message");
            $this->assertContains(
                $check["status"],
                ["ok", "warn", "fail"],
                "$name check has invalid status"
            );
        }
    }
}
?>