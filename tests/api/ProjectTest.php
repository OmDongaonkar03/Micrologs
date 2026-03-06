<?php
/*
    ===============================================================
        Micrologs — Project API Tests
        Covers: create, list, edit, toggle, regenerate-keys,
                delete, verify
    ===============================================================
*/

use PHPUnit\Framework\TestCase;

class ProjectTest extends TestCase
{
    private static array $project = [];

    // ── Create ────────────────────────────────────────────────────

    public function test_create_project_succeeds(): void
    {
        $res = apiRequest(
            "POST",
            "/api/projects/create.php",
            [
                "name" => "PHPUnit Project",
                "allowed_domains" => ["example.com", "staging.example.com"],
            ],
            ["X-Admin-Key" => TEST_ADMIN_KEY]
        );

        $this->assertSame(200, $res["status"]);
        $this->assertTrue($res["body"]["success"]);

        $data = $res["body"]["data"];
        $this->assertArrayHasKey("id", $data);
        $this->assertArrayHasKey("secret_key", $data);
        $this->assertArrayHasKey("public_key", $data);
        $this->assertSame("PHPUnit Project", $data["name"]);
        $this->assertCount(2, $data["allowed_domains"]);

        // Secret key is 64 hex chars (32 bytes)
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $data["secret_key"]
        );
        // Public key is 32 hex chars (16 bytes)
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{32}$/',
            $data["public_key"]
        );

        self::$project = $data;
    }

    public function test_create_project_strips_https_from_domains(): void
    {
        $res = apiRequest(
            "POST",
            "/api/projects/create.php",
            [
                "name" => "Domain Strip Test",
                "allowed_domains" => [
                    "https://example.com/",
                    "http://staging.example.com",
                ],
            ],
            ["X-Admin-Key" => TEST_ADMIN_KEY]
        );

        $this->assertSame(200, $res["status"]);
        $domains = $res["body"]["data"]["allowed_domains"];
        $this->assertContains("example.com", $domains);
        $this->assertContains("staging.example.com", $domains);

        // Cleanup
        deleteTestProject($res["body"]["data"]["id"], "Domain Strip Test");
    }

    public function test_create_project_requires_name(): void
    {
        $res = apiRequest(
            "POST",
            "/api/projects/create.php",
            [
                "allowed_domains" => ["example.com"],
            ],
            ["X-Admin-Key" => TEST_ADMIN_KEY]
        );

        $this->assertSame(400, $res["status"]);
        $this->assertFalse($res["body"]["success"]);
    }

    public function test_create_project_requires_domains(): void
    {
        $res = apiRequest(
            "POST",
            "/api/projects/create.php",
            [
                "name" => "No Domains",
            ],
            ["X-Admin-Key" => TEST_ADMIN_KEY]
        );

        $this->assertSame(400, $res["status"]);
    }

    public function test_create_project_rejects_wrong_admin_key(): void
    {
        $res = apiRequest(
            "POST",
            "/api/projects/create.php",
            [
                "name" => "Hacker Project",
                "allowed_domains" => ["evil.com"],
            ],
            ["X-Admin-Key" => "wrong_key"]
        );

        $this->assertSame(401, $res["status"]);
    }

    public function test_create_project_requires_post_method(): void
    {
        $res = apiRequest(
            "GET",
            "/api/projects/create.php",
            [],
            ["X-Admin-Key" => TEST_ADMIN_KEY]
        );
        $this->assertSame(405, $res["status"]);
    }

    // ── List ──────────────────────────────────────────────────────

    public function test_list_projects_returns_array(): void
    {
        $res = apiRequest(
            "GET",
            "/api/projects/list.php",
            [],
            ["X-Admin-Key" => TEST_ADMIN_KEY]
        );

        $this->assertSame(200, $res["status"]);
        $this->assertTrue($res["body"]["success"]);
        $this->assertArrayHasKey("count", $res["body"]["data"]);
        $this->assertArrayHasKey("projects", $res["body"]["data"]);
        $this->assertIsArray($res["body"]["data"]["projects"]);
    }

    public function test_list_projects_requires_admin_key(): void
    {
        $res = apiRequest("GET", "/api/projects/list.php");
        $this->assertSame(401, $res["status"]);
    }

    public function test_list_projects_includes_stats(): void
    {
        $res = apiRequest(
            "GET",
            "/api/projects/list.php",
            [],
            ["X-Admin-Key" => TEST_ADMIN_KEY]
        );

        $this->assertSame(200, $res["status"]);
        $project = $res["body"]["data"]["projects"][0] ?? null;
        $this->assertNotNull($project);
        $this->assertArrayHasKey("stats", $project);
        $this->assertArrayHasKey("total_links", $project["stats"]);
        $this->assertArrayHasKey("total_pageviews", $project["stats"]);
        $this->assertArrayHasKey("total_errors", $project["stats"]);
    }

    // ── Edit ──────────────────────────────────────────────────────

    public function test_edit_project_name(): void
    {
        $this->assertNotEmpty(
            self::$project,
            "Depends on test_create_project_succeeds"
        );

        $res = apiRequest(
            "POST",
            "/api/projects/edit.php",
            [
                "id" => self::$project["id"],
                "name" => "PHPUnit Project Updated",
            ],
            ["X-Admin-Key" => TEST_ADMIN_KEY]
        );

        $this->assertSame(200, $res["status"]);
        $this->assertSame(
            "PHPUnit Project Updated",
            $res["body"]["data"]["name"]
        );

        // Restore name for subsequent tests
        apiRequest(
            "POST",
            "/api/projects/edit.php",
            [
                "id" => self::$project["id"],
                "name" => "PHPUnit Project",
            ],
            ["X-Admin-Key" => TEST_ADMIN_KEY]
        );
    }

    public function test_edit_project_domains(): void
    {
        $this->assertNotEmpty(self::$project);

        $res = apiRequest(
            "POST",
            "/api/projects/edit.php",
            [
                "id" => self::$project["id"],
                "allowed_domains" => ["newdomain.com"],
            ],
            ["X-Admin-Key" => TEST_ADMIN_KEY]
        );

        $this->assertSame(200, $res["status"]);
        $this->assertContains(
            "newdomain.com",
            $res["body"]["data"]["allowed_domains"]
        );
    }

    public function test_edit_project_requires_something_to_update(): void
    {
        $this->assertNotEmpty(self::$project);

        $res = apiRequest(
            "POST",
            "/api/projects/edit.php",
            [
                "id" => self::$project["id"],
            ],
            ["X-Admin-Key" => TEST_ADMIN_KEY]
        );

        $this->assertSame(400, $res["status"]);
    }

    public function test_edit_nonexistent_project_returns_404(): void
    {
        $res = apiRequest(
            "POST",
            "/api/projects/edit.php",
            [
                "id" => 999999,
                "name" => "Ghost",
            ],
            ["X-Admin-Key" => TEST_ADMIN_KEY]
        );

        $this->assertSame(404, $res["status"]);
    }

    // ── Toggle ────────────────────────────────────────────────────

    public function test_toggle_project_disables_and_re_enables(): void
    {
        $this->assertNotEmpty(self::$project);
        $id = self::$project["id"];

        // Disable
        $res = apiRequest(
            "POST",
            "/api/projects/toggle.php",
            [
                "id" => $id,
                "is_active" => false,
            ],
            ["X-Admin-Key" => TEST_ADMIN_KEY]
        );

        $this->assertSame(200, $res["status"]);
        $this->assertFalse($res["body"]["data"]["is_active"]);

        // Re-enable
        $res = apiRequest(
            "POST",
            "/api/projects/toggle.php",
            [
                "id" => $id,
                "is_active" => true,
            ],
            ["X-Admin-Key" => TEST_ADMIN_KEY]
        );

        $this->assertSame(200, $res["status"]);
        $this->assertTrue($res["body"]["data"]["is_active"]);
    }

    public function test_toggle_without_is_active_flips_state(): void
    {
        $this->assertNotEmpty(self::$project);

        // Get current state
        $list = apiRequest(
            "GET",
            "/api/projects/list.php",
            [],
            ["X-Admin-Key" => TEST_ADMIN_KEY]
        );
        $current = null;
        foreach ($list["body"]["data"]["projects"] as $p) {
            if ($p["id"] === self::$project["id"]) {
                $current = $p;
                break;
            }
        }
        $this->assertNotNull($current);

        // Toggle without specifying is_active
        $res = apiRequest(
            "POST",
            "/api/projects/toggle.php",
            [
                "id" => self::$project["id"],
            ],
            ["X-Admin-Key" => TEST_ADMIN_KEY]
        );

        $this->assertSame(200, $res["status"]);
        $this->assertNotSame(
            $current["is_active"],
            $res["body"]["data"]["is_active"]
        );

        // Toggle back
        apiRequest(
            "POST",
            "/api/projects/toggle.php",
            [
                "id" => self::$project["id"],
            ],
            ["X-Admin-Key" => TEST_ADMIN_KEY]
        );
    }

    // ── Regenerate Keys ───────────────────────────────────────────

    public function test_regenerate_both_keys(): void
    {
        $this->assertNotEmpty(self::$project);

        $res = apiRequest(
            "POST",
            "/api/projects/regenerate-keys.php",
            [
                "id" => self::$project["id"],
            ],
            ["X-Admin-Key" => TEST_ADMIN_KEY]
        );

        $this->assertSame(200, $res["status"]);
        $this->assertArrayHasKey("secret_key", $res["body"]["data"]);
        $this->assertArrayHasKey("public_key", $res["body"]["data"]);

        // New keys should differ from original
        $this->assertNotSame(
            self::$project["secret_key"],
            $res["body"]["data"]["secret_key"]
        );
        $this->assertNotSame(
            self::$project["public_key"],
            $res["body"]["data"]["public_key"]
        );

        // Update stored project with new keys
        self::$project["secret_key"] = $res["body"]["data"]["secret_key"];
        self::$project["public_key"] = $res["body"]["data"]["public_key"];
    }

    public function test_regenerate_secret_key_only(): void
    {
        $this->assertNotEmpty(self::$project);

        $res = apiRequest(
            "POST",
            "/api/projects/regenerate-keys.php",
            [
                "id" => self::$project["id"],
                "rotate_secret" => true,
                "rotate_public" => false,
            ],
            ["X-Admin-Key" => TEST_ADMIN_KEY]
        );

        $this->assertSame(200, $res["status"]);
        $this->assertArrayHasKey("secret_key", $res["body"]["data"]);
        $this->assertArrayNotHasKey("public_key", $res["body"]["data"]);

        self::$project["secret_key"] = $res["body"]["data"]["secret_key"];
    }

    public function test_regenerate_nothing_returns_400(): void
    {
        $this->assertNotEmpty(self::$project);

        $res = apiRequest(
            "POST",
            "/api/projects/regenerate-keys.php",
            [
                "id" => self::$project["id"],
                "rotate_secret" => false,
                "rotate_public" => false,
            ],
            ["X-Admin-Key" => TEST_ADMIN_KEY]
        );

        $this->assertSame(400, $res["status"]);
    }

    // ── Verify ────────────────────────────────────────────────────

    public function test_verify_valid_secret_key(): void
    {
        $this->assertNotEmpty(self::$project);

        $res = apiRequest("POST", "/api/projects/verify.php", [
            "key" => self::$project["secret_key"],
        ]);

        $this->assertSame(200, $res["status"]);
        $this->assertTrue($res["body"]["data"]["valid"]);
        $this->assertSame("secret", $res["body"]["data"]["key_type"]);
    }

    public function test_verify_valid_public_key(): void
    {
        $this->assertNotEmpty(self::$project);

        $res = apiRequest("POST", "/api/projects/verify.php", [
            "key" => self::$project["public_key"],
        ]);

        $this->assertSame(200, $res["status"]);
        $this->assertTrue($res["body"]["data"]["valid"]);
        $this->assertSame("public", $res["body"]["data"]["key_type"]);
    }

    public function test_verify_invalid_key_returns_valid_false(): void
    {
        $res = apiRequest("POST", "/api/projects/verify.php", [
            "key" => "definitely_not_a_real_key",
        ]);

        $this->assertSame(200, $res["status"]);
        $this->assertFalse($res["body"]["data"]["valid"]);
    }

    public function test_verify_requires_key_field(): void
    {
        $res = apiRequest("POST", "/api/projects/verify.php", []);
        $this->assertSame(400, $res["status"]);
    }

    // ── Delete ────────────────────────────────────────────────────

    public function test_delete_project_requires_confirmation(): void
    {
        $this->assertNotEmpty(self::$project);

        $res = apiRequest(
            "POST",
            "/api/projects/delete.php",
            [
                "id" => self::$project["id"],
                "confirm" => "Wrong Name",
            ],
            ["X-Admin-Key" => TEST_ADMIN_KEY]
        );

        $this->assertSame(400, $res["status"]);
    }

    public function test_delete_project_succeeds_with_correct_confirmation(): void
    {
        $this->assertNotEmpty(self::$project);

        $res = apiRequest(
            "POST",
            "/api/projects/delete.php",
            [
                "id" => self::$project["id"],
                "confirm" => "PHPUnit Project",
            ],
            ["X-Admin-Key" => TEST_ADMIN_KEY]
        );

        $this->assertSame(200, $res["status"]);
        $this->assertTrue($res["body"]["success"]);
    }

    public function test_delete_nonexistent_project_returns_404(): void
    {
        $res = apiRequest(
            "POST",
            "/api/projects/delete.php",
            [
                "id" => 999999,
                "confirm" => "ghost",
            ],
            ["X-Admin-Key" => TEST_ADMIN_KEY]
        );

        $this->assertSame(404, $res["status"]);
    }
}
?>