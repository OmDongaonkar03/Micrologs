<?php
/*
    ===============================================================
        Micrologs — Test Bootstrap
        Sets up a clean test DB before each run and tears it
        down after. All tests run against micrologs_test, never
        the production DB.

        Usage: vendor/bin/phpunit --bootstrap tests/bootstrap.php
    ===============================================================
*/

define("MICROLOGS_TEST", true);

// ── Test DB config ────────────────────────────────────────────────
// Override these via environment variables in CI:
//   export ML_TEST_DB_HOST=127.0.0.1
//   export ML_TEST_DB_USER=root
//   export ML_TEST_DB_PASS=secret
define("TEST_DB_HOST", getenv("ML_TEST_DB_HOST") ?: "127.0.0.1");
define("TEST_DB_USER", getenv("ML_TEST_DB_USER") ?: "root");
define("TEST_DB_PASS", getenv("ML_TEST_DB_PASS") ?: "");
define("TEST_DB_NAME", "micrologs_test");

// Test server base URL — point at a running Micrologs instance
define("TEST_BASE_URL", getenv("ML_TEST_URL") ?: "http://localhost:8080");

// Admin key used in test requests
define("TEST_ADMIN_KEY", getenv("ML_TEST_ADMIN_KEY") ?: "test_admin_key_for_phpunit");

// Valkey for tests
define("TEST_VALKEY_HOST", getenv("ML_TEST_VALKEY_HOST") ?: "127.0.0.1");
define("TEST_VALKEY_PORT", (int)(getenv("ML_TEST_VALKEY_PORT") ?: 6379));

// ── Schema path ───────────────────────────────────────────────────
define("SCHEMA_PATH", __DIR__ . "/../schema.sql");

// ── Create + seed test DB ─────────────────────────────────────────
function setupTestDatabase(): \mysqli
{
    $conn = new \mysqli(TEST_DB_HOST, TEST_DB_USER, TEST_DB_PASS);
    if ($conn->connect_error) {
        die("[bootstrap] Cannot connect to MySQL: " . $conn->connect_error . "\n");
    }

    // Drop and recreate test DB for a clean slate
    $conn->query("DROP DATABASE IF EXISTS `" . TEST_DB_NAME . "`");
    $conn->query("CREATE DATABASE `" . TEST_DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $conn->select_db(TEST_DB_NAME);

    // Import schema
    $schema = file_get_contents(SCHEMA_PATH);
    if (!$schema) {
        die("[bootstrap] schema.sql not found at " . SCHEMA_PATH . "\n");
    }

    // Execute each statement
    $conn->multi_query($schema);
    while ($conn->more_results()) {
        $conn->next_result();
    }

    echo "[bootstrap] Test DB created: " . TEST_DB_NAME . "\n";
    return $conn;
}

function teardownTestDatabase(): void
{
    $conn = new \mysqli(TEST_DB_HOST, TEST_DB_USER, TEST_DB_PASS);
    if (!$conn->connect_error) {
        $conn->query("DROP DATABASE IF EXISTS `" . TEST_DB_NAME . "`");
        echo "[bootstrap] Test DB dropped: " . TEST_DB_NAME . "\n";
    }
}

// ── Flush rate limit keys from Valkey ─────────────────────────────
// Even though rate-limit.php skips enforcement when MICROLOGS_TEST is set,
// a previous test run that crashed before teardown (or a run without the
// updated rate-limit.php) may have left rl:block:* or rl:count:* keys behind.
// Flushing them here guarantees every run starts clean regardless.
function flushRateLimitKeys(): void
{
    try {
        $valkey = new \Predis\Client([
            "scheme" => "tcp",
            "host"   => TEST_VALKEY_HOST,
            "port"   => TEST_VALKEY_PORT,
        ]);

        $keys = $valkey->keys("rl:*");
        if (!empty($keys)) {
            $valkey->del($keys);
            echo "[bootstrap] Flushed " . count($keys) . " rate limit key(s) from Valkey\n";
        }
    } catch (\Exception $e) {
        // Valkey unavailable — rate-limit.php already fails open, so this is fine.
        // Tests will still run; just note it so it's visible in output.
        echo "[bootstrap] Warning: could not flush Valkey rate limit keys: " . $e->getMessage() . "\n";
    }
}

// ── HTTP helper ───────────────────────────────────────────────────
// Simple curl wrapper for integration tests hitting real endpoints

function apiRequest(
    string $method,
    string $path,
    array $body = [],
    array $headers = [],
    array $query = []
): array {
    $url = TEST_BASE_URL . $path;

    if (!empty($query)) {
        $url .= "?" . http_build_query($query);
    }

    $ch = curl_init($url);
    $defaultHeaders = ["Content-Type: application/json", "X-Test-Mode: phpunit"];

    foreach ($headers as $k => $v) {
        $defaultHeaders[] = "$k: $v";
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $defaultHeaders);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    if ($method === "POST") {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    } elseif ($method === "GET") {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }

    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new \RuntimeException("Curl error: $error");
    }

    $decoded = json_decode($response, true);

    return [
        "status" => $status,
        "body"   => $decoded,
        "raw"    => $response,
    ];
}

// ── Seed helpers ──────────────────────────────────────────────────

function createTestProject(string $name = "Test Project"): array
{
    $res = apiRequest("POST", "/api/projects/create.php", [
        "name"            => $name,
        "allowed_domains" => ["localhost", "127.0.0.1"],
    ], [
        "X-Admin-Key" => TEST_ADMIN_KEY,
    ]);

    if ($res["status"] !== 200 || empty($res["body"]["data"]["secret_key"])) {
        throw new \RuntimeException("Failed to create test project: " . $res["raw"]);
    }

    return $res["body"]["data"];
}

function deleteTestProject(int $id, string $name): void
{
    apiRequest("POST", "/api/projects/delete.php", [
        "id"      => $id,
        "confirm" => $name,
    ], [
        "X-Admin-Key" => TEST_ADMIN_KEY,
    ]);
}

// Run setup when bootstrap loads
flushRateLimitKeys();
setupTestDatabase();

// Teardown on shutdown
register_shutdown_function("teardownTestDatabase");
?>