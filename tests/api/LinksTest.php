<?php
/*
    ===============================================================
        Micrologs — Links API Tests
        Covers: links/create, list, detail, edit, delete
    ===============================================================
*/

use PHPUnit\Framework\TestCase;

class LinksTest extends TestCase
{
    private static array  $project = [];
    private static string $linkCode = "";

    public static function setUpBeforeClass(): void
    {
        self::$project = createTestProject("Links Test Project");
    }

    public static function tearDownAfterClass(): void
    {
        if (!empty(self::$project)) {
            deleteTestProject(self::$project["id"], "Links Test Project");
        }
    }

    // ── Create ────────────────────────────────────────────────────

    public function test_create_link_returns_code_and_short_url(): void
    {
        $res = apiRequest("POST", "/api/links/create.php", [
            "destination_url" => "https://example.com/landing",
            "label"           => "Test Link",
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(200, $res["status"]);
        $this->assertTrue($res["body"]["success"]);

        $data = $res["body"]["data"];
        $this->assertArrayHasKey("code",            $data);
        $this->assertArrayHasKey("short_url",       $data);
        $this->assertArrayHasKey("destination_url", $data);

        // Code is 8 chars
        $this->assertSame(8, strlen($data["code"]));

        // Short URL contains the code
        $this->assertStringContainsString($data["code"], $data["short_url"]);

        self::$linkCode = $data["code"];
    }

    public function test_create_link_without_label(): void
    {
        $res = apiRequest("POST", "/api/links/create.php", [
            "destination_url" => "https://example.com/no-label",
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(200, $res["status"]);
        $this->assertArrayHasKey("code", $res["body"]["data"]);

        // Cleanup
        apiRequest("POST", "/api/links/delete.php", [
            "code" => $res["body"]["data"]["code"],
        ], ["X-API-Key" => self::$project["secret_key"]]);
    }

    public function test_create_link_requires_destination_url(): void
    {
        $res = apiRequest("POST", "/api/links/create.php", [
            "label" => "No URL",
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(400, $res["status"]);
    }

    public function test_create_link_rejects_invalid_url(): void
    {
        $res = apiRequest("POST", "/api/links/create.php", [
            "destination_url" => "not-a-url",
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(400, $res["status"]);
    }

    public function test_create_link_requires_secret_key(): void
    {
        $res = apiRequest("POST", "/api/links/create.php", [
            "destination_url" => "https://example.com",
        ], ["X-API-Key" => self::$project["public_key"]]);

        $this->assertSame(401, $res["status"]);
    }

    public function test_create_link_generates_unique_codes(): void
    {
        $codes = [];
        for ($i = 0; $i < 5; $i++) {
            $res = apiRequest("POST", "/api/links/create.php", [
                "destination_url" => "https://example.com/page-$i",
            ], ["X-API-Key" => self::$project["secret_key"]]);

            $this->assertSame(200, $res["status"]);
            $code = $res["body"]["data"]["code"];
            $this->assertNotContains($code, $codes, "Duplicate code generated");
            $codes[] = $code;

            // Cleanup
            apiRequest("POST", "/api/links/delete.php", [
                "code" => $code,
            ], ["X-API-Key" => self::$project["secret_key"]]);
        }
    }

    // ── List ──────────────────────────────────────────────────────

    public function test_list_links_returns_array(): void
    {
        $res = apiRequest("GET", "/api/links/list.php", [], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(200, $res["status"]);
        $this->assertTrue($res["body"]["success"]);
        $this->assertArrayHasKey("count", $res["body"]["data"]);
        $this->assertArrayHasKey("links", $res["body"]["data"]);
        $this->assertIsArray($res["body"]["data"]["links"]);
    }

    public function test_list_links_includes_test_link(): void
    {
        $this->assertNotEmpty(self::$linkCode);

        $res = apiRequest("GET", "/api/links/list.php", [], ["X-API-Key" => self::$project["secret_key"]]);
        $codes = array_column($res["body"]["data"]["links"], "code");

        $this->assertContains(self::$linkCode, $codes);
    }

    public function test_list_links_requires_secret_key(): void
    {
        $res = apiRequest("GET", "/api/links/list.php", [], ["X-API-Key" => self::$project["public_key"]]);
        $this->assertSame(401, $res["status"]);
    }

    // ── Detail ────────────────────────────────────────────────────

    public function test_get_link_detail_by_code(): void
    {
        $this->assertNotEmpty(self::$linkCode);

        $res = apiRequest(
            "GET",
            "/api/links/detail.php",
            [],
            ["X-API-Key" => self::$project["secret_key"]],
            ["code" => self::$linkCode]
        );

        $this->assertSame(200, $res["status"]);
        $data = $res["body"]["data"];
        $this->assertSame(self::$linkCode, $data["code"]);
        $this->assertArrayHasKey("total_clicks", $data);
        $this->assertArrayHasKey("is_active",    $data);
        $this->assertArrayHasKey("created_at",   $data);
    }

    public function test_get_link_detail_requires_code(): void
    {
        $res = apiRequest("GET", "/api/links/detail.php", [], ["X-API-Key" => self::$project["secret_key"]]);
        $this->assertSame(400, $res["status"]);
    }

    public function test_get_link_detail_returns_404_for_unknown_code(): void
    {
        $res = apiRequest(
            "GET",
            "/api/links/detail.php",
            [],
            ["X-API-Key" => self::$project["secret_key"]],
            ["code" => "XXXXXXXX"]
        );

        $this->assertSame(404, $res["status"]);
    }

    // ── Edit ──────────────────────────────────────────────────────

    public function test_edit_link_label(): void
    {
        $this->assertNotEmpty(self::$linkCode);

        $res = apiRequest("POST", "/api/links/edit.php", [
            "code"  => self::$linkCode,
            "label" => "Updated Label",
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(200, $res["status"]);
        $this->assertSame("Updated Label", $res["body"]["data"]["label"]);
    }

    public function test_edit_link_destination_url(): void
    {
        $this->assertNotEmpty(self::$linkCode);

        $res = apiRequest("POST", "/api/links/edit.php", [
            "code"            => self::$linkCode,
            "destination_url" => "https://example.com/updated",
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(200, $res["status"]);
        $this->assertSame("https://example.com/updated", $res["body"]["data"]["destination_url"]);
    }

    public function test_edit_link_is_active(): void
    {
        $this->assertNotEmpty(self::$linkCode);

        $res = apiRequest("POST", "/api/links/edit.php", [
            "code"      => self::$linkCode,
            "is_active" => false,
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(200, $res["status"]);
        $this->assertFalse($res["body"]["data"]["is_active"]);

        // Re-enable
        apiRequest("POST", "/api/links/edit.php", [
            "code"      => self::$linkCode,
            "is_active" => true,
        ], ["X-API-Key" => self::$project["secret_key"]]);
    }

    public function test_edit_link_requires_code(): void
    {
        $res = apiRequest("POST", "/api/links/edit.php", [
            "label" => "No Code",
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(400, $res["status"]);
    }

    public function test_edit_link_requires_at_least_one_field(): void
    {
        $this->assertNotEmpty(self::$linkCode);

        $res = apiRequest("POST", "/api/links/edit.php", [
            "code" => self::$linkCode,
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(400, $res["status"]);
    }

    public function test_edit_link_rejects_invalid_destination_url(): void
    {
        $this->assertNotEmpty(self::$linkCode);

        $res = apiRequest("POST", "/api/links/edit.php", [
            "code"            => self::$linkCode,
            "destination_url" => "not-a-url",
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(400, $res["status"]);
    }

    public function test_edit_link_returns_404_for_unknown_code(): void
    {
        $res = apiRequest("POST", "/api/links/edit.php", [
            "code"  => "XXXXXXXX",
            "label" => "Ghost",
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(404, $res["status"]);
    }

    // ── Delete ────────────────────────────────────────────────────

    public function test_delete_link_succeeds(): void
    {
        // Create a disposable link first
        $create = apiRequest("POST", "/api/links/create.php", [
            "destination_url" => "https://example.com/disposable",
            "label"           => "Disposable",
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $code = $create["body"]["data"]["code"];

        $res = apiRequest("POST", "/api/links/delete.php", [
            "code" => $code,
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(200, $res["status"]);
        $this->assertSame($code, $res["body"]["data"]["code"]);

        // Verify it's gone
        $detail = apiRequest(
            "GET",
            "/api/links/detail.php",
            [],
            ["X-API-Key" => self::$project["secret_key"]],
            ["code" => $code]
        );
        $this->assertSame(404, $detail["status"]);
    }

    public function test_delete_link_requires_code(): void
    {
        $res = apiRequest("POST", "/api/links/delete.php", [], ["X-API-Key" => self::$project["secret_key"]]);
        $this->assertSame(400, $res["status"]);
    }

    public function test_delete_link_returns_404_for_unknown_code(): void
    {
        $res = apiRequest("POST", "/api/links/delete.php", [
            "code" => "XXXXXXXX",
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(404, $res["status"]);
    }

    public function test_delete_link_cannot_delete_other_projects_link(): void
    {
        // Create a second project and a link under it
        $other = createTestProject("Other Project");

        $link = apiRequest("POST", "/api/links/create.php", [
            "destination_url" => "https://other.com",
        ], ["X-API-Key" => $other["secret_key"]]);

        $code = $link["body"]["data"]["code"];

        // Try to delete using the first project's key
        $res = apiRequest("POST", "/api/links/delete.php", [
            "code" => $code,
        ], ["X-API-Key" => self::$project["secret_key"]]);

        $this->assertSame(404, $res["status"]);

        // Cleanup
        deleteTestProject($other["id"], "Other Project");
    }
}
?>