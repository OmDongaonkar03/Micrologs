<?php
/*
    ===============================================================
        Micrologs — Worker Unit Tests
        Tests processPageview, processError, processAudit in
        isolation against a real test DB connection.

        These tests bypass the HTTP layer entirely — they call the
        worker functions directly with crafted payloads.

        The process* functions are inlined here to avoid requiring
        the worker files which contain an infinite while(true) loop.
    ===============================================================
*/

use PHPUnit\Framework\TestCase;

require_once __DIR__ . "/../../includes/functions.php";

class WorkerTest extends TestCase
{
    private static \mysqli $conn;
    private static int     $projectId;

    public static function setUpBeforeClass(): void
    {
        self::$conn = new \mysqli(TEST_DB_HOST, TEST_DB_USER, TEST_DB_PASS, TEST_DB_NAME);
        if (self::$conn->connect_error) {
            self::fail("Cannot connect to test DB: " . self::$conn->connect_error);
        }
        self::$conn->set_charset("utf8mb4");

        $secret  = bin2hex(random_bytes(32));
        $public  = bin2hex(random_bytes(16));
        $stmt    = self::$conn->prepare(
            "INSERT INTO projects (name, secret_key, public_key, allowed_domains) VALUES (?, ?, ?, ?)"
        );
        $name    = "Worker Test Project";
        $domains = "localhost";
        $stmt->bind_param("ssss", $name, $secret, $public, $domains);
        $stmt->execute();
        self::$projectId = (int) self::$conn->insert_id;
        $stmt->close();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$projectId) {
            $stmt = self::$conn->prepare("DELETE FROM projects WHERE id = ?");
            $stmt->bind_param("i", self::$projectId);
            $stmt->execute();
            $stmt->close();
        }
        self::$conn->close();
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function makePageviewPayload(array $overrides = []): array
    {
        return array_merge([
            "project_id"        => self::$projectId,
            "url"               => "https://example.com/test",
            "page_title"        => "Test Page",
            "referrer"          => "",
            "visitor_hash"      => hash("sha256", "test-visitor-" . uniqid()),
            "fingerprint_hash"  => hash("sha256", "test-fingerprint"),
            "session_token"     => hash("sha256", "test-session-" . uniqid()),
            "screen_resolution" => "1920x1080",
            "timezone"          => "Asia/Kolkata",
            "ip_hash"           => hash("sha256", "127.0.0.1"),
            "geo"               => ["country" => "", "country_code" => "", "region" => "", "city" => "", "is_vpn" => false],
            "device"            => ["device_type" => "desktop", "os" => "Linux", "browser" => "Chrome", "browser_version" => "120"],
            "referrer_category" => "direct",
            "utm"               => ["utm_source" => "", "utm_medium" => "", "utm_campaign" => "", "utm_content" => "", "utm_term" => ""],
            "received_at"       => date("Y-m-d H:i:s"),
        ], $overrides);
    }

    private function makeErrorPayload(array $overrides = []): array
    {
        return array_merge([
            "project_id"  => self::$projectId,
            "fingerprint" => hash("sha256", "test-error-" . uniqid()),
            "error_type"  => "PHPUnitError",
            "message"     => "Test error message",
            "file"        => "/app/test.php",
            "line"        => 42,
            "stack_trace" => null,
            "url"         => "https://example.com/test",
            "severity"    => "error",
            "environment" => "staging",
            "context"     => null,
            "geo"         => ["country" => "", "country_code" => "", "region" => "", "city" => "", "is_vpn" => false],
            "device"      => ["device_type" => "desktop", "os" => "Linux", "browser" => "Chrome", "browser_version" => "120"],
            "received_at" => date("Y-m-d H:i:s"),
        ], $overrides);
    }

    private function makeAuditPayload(array $overrides = []): array
    {
        return array_merge([
            "project_id"  => self::$projectId,
            "action"      => "user.login",
            "actor"       => "test@example.com",
            "ip_hash"     => hash("sha256", "127.0.0.1"),
            "context"     => null,
            "received_at" => date("Y-m-d H:i:s"),
        ], $overrides);
    }

    // ── Pageview Worker ───────────────────────────────────────────

    public function test_process_pageview_inserts_visitor(): void
    {
        $payload     = $this->makePageviewPayload();
        $visitorHash = $payload["visitor_hash"];

        processPageview(self::$conn, $payload);

        $stmt = self::$conn->prepare(
            "SELECT id FROM visitors WHERE project_id = ? AND visitor_hash = ? LIMIT 1"
        );
        $stmt->bind_param("is", self::$projectId, $visitorHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNotNull($row, "Visitor should be inserted");
    }

    public function test_process_pageview_inserts_session(): void
    {
        $payload      = $this->makePageviewPayload();
        $sessionToken = $payload["session_token"];

        processPageview(self::$conn, $payload);

        $stmt = self::$conn->prepare(
            "SELECT id FROM sessions WHERE project_id = ? AND session_token = ? LIMIT 1"
        );
        $stmt->bind_param("is", self::$projectId, $sessionToken);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNotNull($row, "Session should be inserted");
    }

    public function test_process_pageview_inserts_pageview_record(): void
    {
        $payload = $this->makePageviewPayload(["url" => "https://example.com/unique-" . uniqid()]);

        $countBefore = (int) self::$conn->query(
            "SELECT COUNT(*) AS c FROM pageviews WHERE project_id = " . self::$projectId
        )->fetch_assoc()["c"];

        processPageview(self::$conn, $payload);

        $countAfter = (int) self::$conn->query(
            "SELECT COUNT(*) AS c FROM pageviews WHERE project_id = " . self::$projectId
        )->fetch_assoc()["c"];

        $this->assertSame($countBefore + 1, $countAfter);
    }

    public function test_process_pageview_deduplicates_within_5_minutes(): void
    {
        $payload = $this->makePageviewPayload(["url" => "https://example.com/dedup-" . uniqid()]);

        processPageview(self::$conn, $payload);

        $countAfterFirst = (int) self::$conn->query(
            "SELECT COUNT(*) AS c FROM pageviews WHERE project_id = " . self::$projectId
        )->fetch_assoc()["c"];

        // Same visitor + same URL again — should be deduped
        processPageview(self::$conn, $payload);

        $countAfterSecond = (int) self::$conn->query(
            "SELECT COUNT(*) AS c FROM pageviews WHERE project_id = " . self::$projectId
        )->fetch_assoc()["c"];

        $this->assertSame($countAfterFirst, $countAfterSecond, "Duplicate pageview should be skipped");
    }

    public function test_process_pageview_session_is_bounced_on_first_page(): void
    {
        $payload = $this->makePageviewPayload();
        processPageview(self::$conn, $payload);

        $stmt = self::$conn->prepare(
            "SELECT is_bounced FROM sessions WHERE project_id = ? AND session_token = ? LIMIT 1"
        );
        $stmt->bind_param("is", self::$projectId, $payload["session_token"]);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertSame(1, (int) $row["is_bounced"], "Single-page session should be bounced");
    }

    public function test_process_pageview_session_not_bounced_after_second_page(): void
    {
        $sessionToken = hash("sha256", "multi-page-session-" . uniqid());
        $visitorHash  = hash("sha256", "multi-page-visitor-" . uniqid());

        $base = $this->makePageviewPayload([
            "session_token" => $sessionToken,
            "visitor_hash"  => $visitorHash,
        ]);

        processPageview(self::$conn, array_merge($base, ["url" => "https://example.com/page-1"]));
        processPageview(self::$conn, array_merge($base, ["url" => "https://example.com/page-2"]));

        $stmt = self::$conn->prepare(
            "SELECT is_bounced FROM sessions WHERE project_id = ? AND session_token = ? LIMIT 1"
        );
        $stmt->bind_param("is", self::$projectId, $sessionToken);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertSame(0, (int) $row["is_bounced"], "Multi-page session should not be bounced");
    }

    public function test_process_pageview_upserts_existing_visitor(): void
    {
        $visitorHash = hash("sha256", "returning-visitor-" . uniqid());

        $payload1 = $this->makePageviewPayload([
            "visitor_hash"  => $visitorHash,
            "session_token" => hash("sha256", "session-1-" . uniqid()),
            "url"           => "https://example.com/first",
        ]);
        processPageview(self::$conn, $payload1);

        $payload2 = $this->makePageviewPayload([
            "visitor_hash"  => $visitorHash,
            "session_token" => hash("sha256", "session-2-" . uniqid()),
            "url"           => "https://example.com/second",
        ]);
        processPageview(self::$conn, $payload2);

        $stmt = self::$conn->prepare(
            "SELECT COUNT(*) AS c FROM visitors WHERE project_id = ? AND visitor_hash = ?"
        );
        $stmt->bind_param("is", self::$projectId, $visitorHash);
        $stmt->execute();
        $count = (int) $stmt->get_result()->fetch_assoc()["c"];
        $stmt->close();

        $this->assertSame(1, $count, "Returning visitor should upsert, not duplicate");
    }

    // ── Error Worker ──────────────────────────────────────────────

    public function test_process_error_creates_error_group(): void
    {
        $payload     = $this->makeErrorPayload();
        $fingerprint = $payload["fingerprint"];

        $groupId = processError(self::$conn, $payload);

        $this->assertGreaterThan(0, $groupId);

        $stmt = self::$conn->prepare(
            "SELECT id, occurrence_count FROM error_groups WHERE project_id = ? AND fingerprint = ? LIMIT 1"
        );
        $stmt->bind_param("is", self::$projectId, $fingerprint);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row["occurrence_count"]);
    }

    public function test_process_error_increments_existing_group(): void
    {
        $fingerprint = hash("sha256", "repeated-error-" . uniqid());
        $payload     = $this->makeErrorPayload(["fingerprint" => $fingerprint]);

        processError(self::$conn, $payload);
        processError(self::$conn, $payload);
        processError(self::$conn, $payload);

        $stmt = self::$conn->prepare(
            "SELECT occurrence_count FROM error_groups WHERE project_id = ? AND fingerprint = ? LIMIT 1"
        );
        $stmt->bind_param("is", self::$projectId, $fingerprint);
        $stmt->execute();
        $count = (int) $stmt->get_result()->fetch_assoc()["occurrence_count"];
        $stmt->close();

        $this->assertSame(3, $count);
    }

    public function test_process_error_creates_error_event(): void
    {
        $payload = $this->makeErrorPayload();
        $groupId = processError(self::$conn, $payload);

        $stmt = self::$conn->prepare(
            "SELECT id FROM error_events WHERE group_id = ? AND project_id = ? LIMIT 1"
        );
        $stmt->bind_param("ii", $groupId, self::$projectId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNotNull($row, "error_events record should be created");
    }

    public function test_process_error_reopens_resolved_group(): void
    {
        $fingerprint = hash("sha256", "resolved-error-" . uniqid());
        $payload     = $this->makeErrorPayload(["fingerprint" => $fingerprint]);

        $groupId = processError(self::$conn, $payload);

        $stmt = self::$conn->prepare("UPDATE error_groups SET status = 'resolved' WHERE id = ?");
        $stmt->bind_param("i", $groupId);
        $stmt->execute();
        $stmt->close();

        processError(self::$conn, $payload);

        $stmt = self::$conn->prepare("SELECT status FROM error_groups WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $groupId);
        $stmt->execute();
        $status = $stmt->get_result()->fetch_assoc()["status"];
        $stmt->close();

        $this->assertSame("open", $status, "Resolved error fired again should reopen");
    }

    public function test_process_error_does_not_reopen_ignored_group(): void
    {
        $fingerprint = hash("sha256", "ignored-error-" . uniqid());
        $payload     = $this->makeErrorPayload(["fingerprint" => $fingerprint]);

        $groupId = processError(self::$conn, $payload);

        $stmt = self::$conn->prepare("UPDATE error_groups SET status = 'ignored' WHERE id = ?");
        $stmt->bind_param("i", $groupId);
        $stmt->execute();
        $stmt->close();

        processError(self::$conn, $payload);

        $stmt = self::$conn->prepare("SELECT status FROM error_groups WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $groupId);
        $stmt->execute();
        $status = $stmt->get_result()->fetch_assoc()["status"];
        $stmt->close();

        $this->assertSame("ignored", $status, "Ignored error should not be reopened");
    }

    // ── Audit Worker ──────────────────────────────────────────────

    public function test_process_audit_inserts_log_record(): void
    {
        $payload = $this->makeAuditPayload(["action" => "unit.test." . uniqid()]);
        $action  = $payload["action"];

        processAudit(self::$conn, $payload);

        $stmt = self::$conn->prepare(
            "SELECT id, action, actor FROM audit_logs WHERE project_id = ? AND action = ? LIMIT 1"
        );
        $stmt->bind_param("is", self::$projectId, $action);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNotNull($row);
        $this->assertSame("test@example.com", $row["actor"]);
    }

    public function test_process_audit_stores_context_as_json(): void
    {
        $context = json_encode(["role" => "admin", "ip" => "127.0.0.1"]);
        $action  = "unit.test.context." . uniqid();
        $payload = $this->makeAuditPayload(["action" => $action, "context" => $context]);

        processAudit(self::$conn, $payload);

        $stmt = self::$conn->prepare(
            "SELECT context FROM audit_logs WHERE project_id = ? AND action = ? LIMIT 1"
        );
        $stmt->bind_param("is", self::$projectId, $action);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNotNull($row);
        $decoded = json_decode($row["context"], true);
        $this->assertSame("admin", $decoded["role"]);
    }

    public function test_process_audit_handles_null_actor(): void
    {
        $payload = $this->makeAuditPayload(["action" => "system.boot", "actor" => ""]);
        $this->expectNotToPerformAssertions();
        processAudit(self::$conn, $payload);
    }

    public function test_process_audit_handles_null_context(): void
    {
        $payload = $this->makeAuditPayload(["action" => "no.context." . uniqid(), "context" => null]);
        $this->expectNotToPerformAssertions();
        processAudit(self::$conn, $payload);
    }
}

// ── Inlined worker functions ──────────────────────────────────────
// Copied from workers/ — kept here to avoid requiring the worker
// files which contain an infinite while(true) loop.

function processPageview(\mysqli $conn, array $p): void
{
    $projectId = (int) $p["project_id"];
    $visitorHash = $p["visitor_hash"];
    $fingerHash = $p["fingerprint_hash"];
    $sessionToken = $p["session_token"];
    $url = $p["url"];

    $stmt = $conn->prepare("
        INSERT INTO visitors (project_id, visitor_hash, fingerprint_hash)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            fingerprint_hash = IF(
                fingerprint_hash = '' AND VALUES(fingerprint_hash) != '',
                VALUES(fingerprint_hash),
                fingerprint_hash
            ),
            last_seen = NOW()
    ");
    if (!$stmt) {
        throw new \Exception("visitor upsert prepare failed: " . $conn->error);
    }
    $stmt->bind_param("iss", $projectId, $visitorHash, $fingerHash);
    if (!$stmt->execute()) {
        throw new \Exception("visitor upsert execute failed: " . $stmt->error);
    }
    $visitorDbId = (int) $conn->insert_id;
    $stmt->close();

    if ($visitorDbId === 0) {
        $stmt = $conn->prepare(
            "SELECT id FROM visitors WHERE project_id = ? AND visitor_hash = ? LIMIT 1"
        );
        $stmt->bind_param("is", $projectId, $visitorHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $visitorDbId = (int) $row["id"];
        } elseif (!empty($fingerHash)) {
            $stmt = $conn->prepare(
                "SELECT id FROM visitors WHERE project_id = ? AND fingerprint_hash = ? LIMIT 1"
            );
            $stmt->bind_param("is", $projectId, $fingerHash);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                $visitorDbId = (int) $row["id"];
                $stmt = $conn->prepare(
                    "UPDATE visitors SET visitor_hash = ?, last_seen = NOW() WHERE id = ?"
                );
                $stmt->bind_param("si", $visitorHash, $visitorDbId);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    if ($visitorDbId === 0) {
        throw new \Exception("could not resolve visitor for hash $visitorHash");
    }

    $stmt = $conn->prepare("
        INSERT INTO sessions (project_id, visitor_id, session_token)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            last_activity = NOW()
    ");
    if (!$stmt) {
        throw new \Exception("session upsert prepare failed: " . $conn->error);
    }
    $stmt->bind_param("iis", $projectId, $visitorDbId, $sessionToken);
    if (!$stmt->execute()) {
        throw new \Exception("session upsert execute failed: " . $stmt->error);
    }
    $sessionId = (int) $conn->insert_id;
    $stmt->close();

    if ($sessionId === 0) {
        $stmt = $conn->prepare(
            "SELECT id FROM sessions WHERE session_token = ? LIMIT 1"
        );
        $stmt->bind_param("s", $sessionToken);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $sessionId = $row ? (int) $row["id"] : 0;
    }

    if ($sessionId === 0) {
        throw new \Exception("could not resolve session for token $sessionToken");
    }

    $stmt = $conn->prepare("
        SELECT id FROM pageviews
        WHERE project_id = ? AND visitor_id = ? AND url = ?
          AND created_at >= DATE_SUB(NOW(), INTERVAL 300 SECOND)
        LIMIT 1
    ");
    $stmt->bind_param("iis", $projectId, $visitorDbId, $url);
    $stmt->execute();
    $duplicate = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($duplicate) {
        return;
    }

    $locationId = resolveLocation($conn, $projectId, $p["geo"]);
    $deviceId   = resolveDevice($conn, $projectId, $p["device"]);

    $utm  = $p["utm"];
    $stmt = $conn->prepare("
        INSERT INTO pageviews
            (project_id, session_id, visitor_id, location_id, device_id,
             url, page_title, referrer_url, referrer_category,
             utm_source, utm_medium, utm_campaign, utm_content, utm_term,
             screen_resolution, timezone, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        throw new \Exception("pageview INSERT prepare failed: " . $conn->error);
    }
    $stmt->bind_param(
        "iiiiissssssssssss",
        $projectId,
        $sessionId,
        $visitorDbId,
        $locationId,
        $deviceId,
        $url,
        $p["page_title"],
        $p["referrer"],
        $p["referrer_category"],
        $utm["utm_source"],
        $utm["utm_medium"],
        $utm["utm_campaign"],
        $utm["utm_content"],
        $utm["utm_term"],
        $p["screen_resolution"],
        $p["timezone"],
        $p["received_at"]
    );
    if (!$stmt->execute()) {
        throw new \Exception("pageview INSERT execute failed: " . $stmt->error);
    }
    $stmt->close();

    $stmt = $conn->prepare("
        UPDATE sessions
        SET is_bounced = 0
        WHERE id = ?
          AND is_bounced = 1
          AND (SELECT COUNT(*) FROM pageviews WHERE session_id = ?) > 1
    ");
    $stmt->bind_param("ii", $sessionId, $sessionId);
    $stmt->execute();
    $stmt->close();
}

function processError(\mysqli $conn, array $p): int
{
    $projectId   = (int) $p["project_id"];
    $fingerprint = $p["fingerprint"];
    $severity    = $p["severity"];
    $environment = $p["environment"];
    $receivedAt  = $p["received_at"];

    $stmt = $conn->prepare("
        INSERT INTO error_groups
            (project_id, fingerprint, error_type, message, file, line,
             severity, environment, first_seen, last_seen)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            occurrence_count = occurrence_count + 1,
            last_seen        = VALUES(last_seen),
            severity         = VALUES(severity),
            status           = IF(status = 'resolved', 'open', status)
    ");
    if (!$stmt) {
        throw new \Exception("error_groups upsert prepare failed: " . $conn->error);
    }

    $stmt->bind_param(
        "issssissss",
        $projectId,
        $fingerprint,
        $p["error_type"],
        $p["message"],
        $p["file"],
        $p["line"],
        $severity,
        $environment,
        $receivedAt,
        $receivedAt
    );

    if (!$stmt->execute()) {
        throw new \Exception("error_groups upsert execute failed: " . $stmt->error);
    }

    $groupId = (int) $conn->insert_id;
    $stmt->close();

    if ($groupId === 0) {
        $stmt = $conn->prepare(
            "SELECT id FROM error_groups WHERE project_id = ? AND fingerprint = ? LIMIT 1"
        );
        $stmt->bind_param("is", $projectId, $fingerprint);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new \Exception("could not resolve error group for fingerprint $fingerprint");
        }
        $groupId = (int) $row["id"];
    }

    $locationId = resolveLocation($conn, $projectId, $p["geo"]);
    $deviceId   = resolveDevice($conn, $projectId, $p["device"]);

    $stmt = $conn->prepare("
        INSERT INTO error_events
            (group_id, project_id, location_id, device_id,
             stack_trace, url, environment, severity, context, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        throw new \Exception("error_events INSERT prepare failed: " . $conn->error);
    }

    $stmt->bind_param(
        "iiisssssss",
        $groupId,
        $projectId,
        $locationId,
        $deviceId,
        $p["stack_trace"],
        $p["url"],
        $environment,
        $severity,
        $p["context"],
        $receivedAt
    );

    if (!$stmt->execute()) {
        throw new \Exception("error_events INSERT execute failed: " . $stmt->error);
    }
    $stmt->close();

    // Cache bust — non-fatal
    try {
        $valkey    = getValkey();
        $listKeys  = $valkey->keys("analytics:errors:{$projectId}:*");
        $trendKeys = $valkey->keys("analytics:errors-trend:{$projectId}:*");
        $allKeys   = array_merge($listKeys ?? [], $trendKeys ?? []);
        if (!empty($allKeys)) {
            $valkey->del($allKeys);
        }
    } catch (\Exception $e) {
        // Non-fatal
    }

    return $groupId;
}

function processAudit(\mysqli $conn, array $p): void
{
    $projectId  = (int) $p["project_id"];
    $action     = $p["action"];
    $actor      = $p["actor"] ?? "";
    $ipHash     = $p["ip_hash"] ?? "";
    $context    = $p["context"] ?? null;
    $receivedAt = $p["received_at"];

    $stmt = $conn->prepare("
        INSERT INTO audit_logs (project_id, action, actor, ip_hash, context, created_at)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        throw new \Exception("audit_logs INSERT prepare failed: " . $conn->error);
    }

    $stmt->bind_param("isssss", $projectId, $action, $actor, $ipHash, $context, $receivedAt);

    if (!$stmt->execute()) {
        throw new \Exception("audit_logs INSERT execute failed: " . $stmt->error);
    }

    $stmt->close();
}
?>