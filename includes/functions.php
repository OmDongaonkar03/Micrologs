<?php
/*
    ===============================================================
        Micrologs
        File : includes/functions.php
        Desc : Global helper functions
    ===============================================================
*/
require_once __DIR__ . "/../authorization/config.php";
require_once __DIR__ . "/../utils/vendor/autoload.php"; // For MaxMind GeoIP2
require_once __DIR__ . "/../utils/rate-limit.php"; // For rate limiting functions

// Unique ID for HTTP requests
$GLOBALS["request_id"] = substr(bin2hex(random_bytes(4)), 0, 8);

// CORS headers are only relevant for HTTP requests, not CLI workers
if (!defined("RUNNING_AS_WORKER")) {
    $origin = $_SERVER["HTTP_ORIGIN"] ?? "";

    $allowedOrigins =
        defined("ALLOWED_ORIGINS") && ALLOWED_ORIGINS !== ""
            ? array_map("trim", explode(",", ALLOWED_ORIGINS))
            : [];

    if (in_array($origin, $allowedOrigins)) {
        header("Access-Control-Allow-Origin: $origin");
    }

    header(
        "Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS"
    );
    header("Access-Control-Allow-Headers: Content-Type, X-API-Key");
    header("Access-Control-Max-Age: 86400");

    // Handle preflight request
    if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
        http_response_code(200);
        exit();
    }
}

// LOGGING

function writeLog($level, $message, $context = [])
{
    $logPath = defined("LOG_PATH")
        ? LOG_PATH
        : __DIR__ . "/../logs/micrologs.log";

    $logDir = dirname($logPath);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    // Rotate if file exceeds 10 MB — keeps last 5 rotated files
    // filesize() is a syscall — only check on ~1-in-50 writes to reduce overhead
    if (file_exists($logPath) && random_int(1, 50) === 1 && filesize($logPath) >= 10 * 1024 * 1024) {
        $maxFiles = 5;
        // Shift: .5 dropped, .4→.5, .3→.4, .2→.3, .1→.2, then log→.1
        for ($i = $maxFiles; $i >= 2; $i--) {
            $older = $logPath . "." . $i;
            $newer = $logPath . "." . ($i - 1);
            if ($i === $maxFiles && file_exists($older)) {
                unlink($older); // drop the oldest
            }
            if (file_exists($newer)) {
                rename($newer, $older);
            }
        }
        rename($logPath, $logPath . ".1");
    }

    $timestamp = date("Y-m-d H:i:s");
    $requestId = $GLOBALS["request_id"] ?? "--------";
    $file = basename(
        debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]["file"] ?? "unknown"
    );
    $contextStr = !empty($context)
        ? " | " .
            json_encode(
                $context,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        : "";

    $line =
        "[{$timestamp}] [{$level}] [{$requestId}] [{$file}] {$message}{$contextStr}" .
        PHP_EOL;

    file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
}

// VALKEY

function getValkey(): \Predis\Client
{
    static $client = null;

    if ($client === null) {
        $client = new \Predis\Client([
            "scheme" => "tcp",
            "host" => defined("VALKEY_HOST") ? VALKEY_HOST : "127.0.0.1",
            "port" => defined("VALKEY_PORT") ? VALKEY_PORT : 6379,
            "password" => defined("VALKEY_PASSWORD") ? VALKEY_PASSWORD : "",
        ]);
    }

    return $client;
}

function queuePush(string $queue, array $payload): void
{
    try {
        $valkey = getValkey();
        $valkey->rpush($queue, [json_encode($payload)]);
    } catch (\Exception $e) {
        // If Valkey is down, log it but don't break the request
        writeLog("error", "queuePush failed: " . $e->getMessage(), [
            "queue" => $queue,
        ]);
    }
}

function queuePop(string $queue): ?array
{
    try {
        $valkey = getValkey();
        // BLPOP blocks for up to 2 seconds waiting for a message
        // Returns null if nothing arrives in that time
        $result = $valkey->blpop([$queue], 2);
        if ($result && isset($result[1])) {
            return json_decode($result[1], true);
        }
    } catch (\Exception $e) {
        writeLog("error", "queuePop failed: " . $e->getMessage(), [
            "queue" => $queue,
        ]);
    }

    return null;
}

/**
 * Read a cached value from Valkey.
 * Returns the decoded value, or null on miss or error.
 */
function cacheGet(string $key): mixed
{
    try {
        $value = getValkey()->get($key);
        return $value !== null ? json_decode($value, true) : null;
    } catch (\Exception $e) {
        writeLog("error", "cacheGet failed: " . $e->getMessage(), [
            "key" => $key,
        ]);
        return null;
    }
}

/**
 * Store a value in Valkey with a TTL in seconds.
 * Silently skips if Valkey is unavailable — cache is optional, never blocking.
 */
function cacheSet(string $key, mixed $value, int $ttl = 300): void
{
    try {
        getValkey()->setex($key, $ttl, json_encode($value));
    } catch (\Exception $e) {
        writeLog("error", "cacheSet failed: " . $e->getMessage(), [
            "key" => $key,
        ]);
    }
}

/**
 * Delete one or more cache keys.
 * Used when a project is toggled or deleted to invalidate stale data.
 */
function cacheDel(string ...$keys): void
{
    try {
        getValkey()->del($keys);
    } catch (\Exception $e) {
        writeLog("error", "cacheDel failed: " . $e->getMessage(), [
            "keys" => $keys,
        ]);
    }
}

/**
 * Bust all analytics cache keys for a given project.
 *
 * Uses Valkey KEYS to find every key matching "analytics:*:{id}:*"
 * then deletes them all in one DEL call.
 *
 * When to call this:
 *   - projects/delete.php   — project is gone, all its cached data is stale
 *   - projects/toggle.php   — project disabled/enabled changes what queries return
 */
function cacheBustProject(int $projectId): void
{
    try {
        $valkey = getValkey();

        // Find all keys for this project across all endpoints and ranges
        // Pattern: analytics:{endpoint}:{projectId}:{anything}
        $pattern = "analytics:*:{$projectId}:*";
        $keys = $valkey->keys($pattern);

        if (!empty($keys)) {
            $valkey->del($keys);
            writeLog("info", "cache busted for project", [
                "project_id" => $projectId,
                "keys_deleted" => count($keys),
            ]);
        }
    } catch (\Exception $e) {
        // Cache bust failing is not fatal — stale data expires naturally via TTL
        writeLog("error", "cacheBustProject failed: " . $e->getMessage(), [
            "project_id" => $projectId,
        ]);
    }
}

// RESPONSE
function sendResponse(bool $success, string $message, mixed $data = null, int $status = 200): never
{
    http_response_code($status);
    header("Content-Type: application/json");
    header("Access-Control-Allow-Headers: X-API-Key, Content-Type");

    $response = [
        "success" => $success,
        "message" => $message,
    ];

    if ($data !== null) {
        $response["data"] = $data;
    }

    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit();
}

// REQUEST BODY

/**
 * Read and decode the JSON request body with a hard size cap.
 * Prevents DoS via oversized payloads.
 *
 * @param int $maxBytes Default 65 536 (64 KB) — enough for any tracking payload
 */
function readJsonBody(int $maxBytes = 65536): ?array
{
    $raw = file_get_contents("php://input", false, null, 0, $maxBytes + 1);

    if ($raw === false || $raw === "") {
        return null;
    }

    if (strlen($raw) > $maxBytes) {
        sendResponse(false, "Payload too large", null, 413);
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * Encode and cap context JSON to prevent huge blobs in the DB.
 * Returns null if empty / not an array / too large after encoding.
 */
function encodeContext(mixed $raw, int $maxBytes = 8192): ?string
{
    if (!isset($raw) || !is_array($raw)) {
        return null;
    }

    $encoded = json_encode(
        $raw,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($encoded === false || strlen($encoded) > $maxBytes) {
        return null;
    }

    return $encoded;
}

// API KEY AUTH

/**
 * Fetch a project row by key column ('public_key' or 'secret_key').
 * Returns the project array on success, null if not found or DB error.
 * Column name is validated internally — never interpolated from user input.
 */
function fetchProjectByKey(mysqli $conn, string $column, string $key): ?array
{
    // Whitelist the column — never interpolate untrusted values into SQL
    if (!in_array($column, ["public_key", "secret_key"], true)) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT id, name, allowed_domains FROM projects WHERE {$column} = ? AND is_active = 1 LIMIT 1"
    );
    if (!$stmt) {
        writeLog("ERROR", "fetchProjectByKey prepare failed", [
            "column" => $column,
            "error"  => $conn->error,
        ]);
        return null;
    }
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $project = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $project ?: null;
}

/**
 * Enforce domain lock for public-key requests (browser/JS snippet callers).
 * Returns true if the request origin is allowed, false if it should be rejected.
 * Always returns true when no Origin/Referer header is present (e.g. server-side callers).
 */
function checkDomainLock(array $project): bool
{
    $origin = $_SERVER["HTTP_ORIGIN"] ?? ($_SERVER["HTTP_REFERER"] ?? "");
    if (empty($origin)) {
        return true;
    }

    $host = preg_replace(
        "/^www\./",
        "",
        strtolower(parse_url($origin, PHP_URL_HOST) ?? "")
    );

    foreach (explode(",", $project["allowed_domains"]) as $domain) {
        $domain = preg_replace("/^www\./", "", strtolower(trim($domain)));
        if ($host === $domain || str_ends_with($host, "." . $domain)) {
            return true;
        }
    }

    return false;
}

/**
 * Hard verify — public key. Exits with 401/403 on any failure.
 * Used by pageview.php (JS snippet — public key only).
 */
function verifyPublicKey(mysqli $conn): array
{
    $key = $_SERVER["HTTP_X_API_KEY"] ?? "";
    if (empty($key)) {
        sendResponse(false, "API key is required", null, 401);
    }

    $project = fetchProjectByKey($conn, "public_key", $key);
    if (!$project) {
        sendResponse(false, "Invalid API key", null, 401);
    }

    if (!checkDomainLock($project)) {
        sendResponse(false, "Domain not allowed for this key", null, 403);
    }

    return $project;
}

/**
 * Hard verify — secret key. Exits with 401 on any failure.
 * Used by all analytics and management endpoints.
 */
function verifySecretKey(mysqli $conn): array
{
    $key = $_SERVER["HTTP_X_API_KEY"] ?? "";
    if (empty($key)) {
        sendResponse(false, "API key is required", null, 401);
    }

    $project = fetchProjectByKey($conn, "secret_key", $key);
    if (!$project) {
        sendResponse(false, "Invalid API key", null, 401);
    }

    return $project;
}

/**
 * Soft verify — secret key. Returns null on any failure instead of exiting.
 * Used by endpoints that accept either key type (error.php, audit.php).
 */
function tryVerifySecretKey(mysqli $conn): ?array
{
    $key = $_SERVER["HTTP_X_API_KEY"] ?? "";
    if (empty($key)) {
        return null;
    }

    return fetchProjectByKey($conn, "secret_key", $key);
}

/**
 * Soft verify — public key + domain lock. Returns null on any failure instead of exiting.
 * Used by endpoints that accept either key type (error.php, audit.php).
 */
function tryVerifyPublicKey(mysqli $conn): ?array
{
    $key = $_SERVER["HTTP_X_API_KEY"] ?? "";
    if (empty($key)) {
        return null;
    }

    $project = fetchProjectByKey($conn, "public_key", $key);
    if (!$project) {
        return null;
    }

    return checkDomainLock($project) ? $project : null;
}

// BOT FILTER

// Single compiled pattern — checked once per request via preg_match()
// instead of 27 individual str_contains calls on the hot path.
define("BOT_UA_PATTERN", implode("|", [
    "bot", "crawler", "spider", "slurp", "curl", "wget",
    "python-requests", "python-urllib", "go-http-client", "scrapy",
    "facebookexternalhit", "twitterbot", "linkedinbot", "applebot",
    "googlebot", "bingbot", "yandexbot", "baiduspider", "semrushbot",
    "ahrefsbot", "mj12bot", "dotbot", "uptimerobot", "pingdom",
    "gtmetrix", "headlesschrome", "phantomjs", "axios",
]));

function isBot(): bool
{
    // Skip bot detection during test runs — same guard used in rate-limit.php.
    if ((defined("MICROLOGS_TEST") && MICROLOGS_TEST === true) ||
        (($_SERVER["HTTP_X_TEST_MODE"] ?? "") === "phpunit" && !IS_PRODUCTION)) {
        return false;
    }

    $ua = strtolower($_SERVER["HTTP_USER_AGENT"] ?? "");

    if (empty($ua)) {
        return true;
    }

    if (preg_match("/" . BOT_UA_PATTERN . "/", $ua)) {
        return true;
    }

    // Real browsers always send these headers
    if (
        empty($_SERVER["HTTP_ACCEPT_LANGUAGE"]) ||
        empty($_SERVER["HTTP_ACCEPT"])
    ) {
        return true;
    }

    return false;
}

// IP

function getClientIp(): string
{
    $remoteAddr = $_SERVER["REMOTE_ADDR"] ?? "";

    // Only trust X-Forwarded-For if the direct connection comes from a known
    // trusted proxy (e.g. Nginx/Apache on the same machine, or a CDN IP).
    // Define TRUSTED_PROXIES as a comma-separated list of IPs/CIDRs in env.php.
    // If not defined or empty, we never trust XFF — preventing IP spoofing.
    $trustedProxies = [];
    if (defined("TRUSTED_PROXIES") && TRUSTED_PROXIES !== "") {
        $trustedProxies = array_map("trim", explode(",", TRUSTED_PROXIES));
    }

    if (
        !empty($trustedProxies) &&
        in_array($remoteAddr, $trustedProxies, true)
    ) {
        if (!empty($_SERVER["HTTP_X_FORWARDED_FOR"])) {
            // XFF chain: "client, proxy1, proxy2" — take the leftmost (real client)
            $ips = array_map(
                "trim",
                explode(",", $_SERVER["HTTP_X_FORWARDED_FOR"])
            );
            $clientIp = $ips[0];
            // Basic validation — must look like an IP
            if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
                return $clientIp;
            }
        }
    }

    return $remoteAddr;
}

function hashIp(string $ip): string
{
    $salt = defined("IP_HASH_SALT") ? IP_HASH_SALT : "micrologs_default_salt";
    return hash("sha256", $ip . $salt);
}

// GEOLOCATION

function geolocate(string $ip): array
{
    $default = [
        "country" => "",
        "country_code" => "",
        "region" => "",
        "city" => "",
        "is_vpn" => 0,
    ];

    $dbPath = defined("GEOIP_PATH")
        ? GEOIP_PATH
        : __DIR__ . "/../../geoip/GeoLite2-City.mmdb";

    if (!file_exists($dbPath)) {
        return $default;
    }

    try {
        static $reader = null;
        if ($reader === null) {
            $reader = new \MaxMind\Db\Reader($dbPath);
        }

        $record = $reader->get($ip);

        if (!$record) {
            return $default;
        }

        return [
            "country" => $record["country"]["names"]["en"] ?? "",
            "country_code" => $record["country"]["iso_code"] ?? "",
            "region" => $record["subdivisions"][0]["names"]["en"] ?? "",
            "city" => $record["city"]["names"]["en"] ?? "",
            "is_vpn" => 0,
        ];
    } catch (Exception $e) {
        writeLog("ERROR", "GeoIP lookup failed", [
            "ip" => $ip,
            "error" => $e->getMessage(),
        ]);
        return $default;
    }
}

// DEVICE PARSING

function parseUserAgent(string $ua): array
{
    return [
        "device_type" => detectDeviceType($ua),
        "os" => detectOs($ua),
        "browser" => detectBrowser($ua),
        "browser_version" => detectBrowserVersion($ua),
    ];
}

function detectDeviceType(string $ua): string
{
    if (preg_match("/tablet|ipad|playbook|silk/i", $ua)) {
        return "tablet";
    }
    if (
        preg_match(
            "/mobile|android|iphone|ipod|blackberry|opera mini|iemobile/i",
            $ua
        )
    ) {
        return "mobile";
    }
    return "desktop";
}

function detectOs(string $ua): string
{
    if (str_contains($ua, "Windows")) {
        return "Windows";
    }
    if (str_contains($ua, "Mac OS X")) {
        return "macOS";
    }
    if (str_contains($ua, "iPhone") || str_contains($ua, "iPad")) {
        return "iOS";
    }
    if (str_contains($ua, "Android")) {
        return "Android";
    }
    if (str_contains($ua, "CrOS")) {
        return "ChromeOS";
    }
    if (str_contains($ua, "Linux")) {
        return "Linux";
    }
    return "Unknown";
}

function detectBrowser(string $ua): string
{
    if (str_contains($ua, "Edg/")) {
        return "Edge";
    }
    if (str_contains($ua, "OPR/")) {
        return "Opera";
    }
    if (str_contains($ua, "Chrome")) {
        return "Chrome";
    }
    if (str_contains($ua, "Firefox")) {
        return "Firefox";
    }
    if (str_contains($ua, "Safari") && !str_contains($ua, "Chrome")) {
        return "Safari";
    }
    if (str_contains($ua, "MSIE") || str_contains($ua, "Trident")) {
        return "IE";
    }
    return "Unknown";
}

function detectBrowserVersion(string $ua): string
{
    $patterns = [
        "/Edg\/([\d.]+)/" => "Edge",
        "/OPR\/([\d.]+)/" => "Opera",
        "/Chrome\/([\d.]+)/" => "Chrome",
        "/Firefox\/([\d.]+)/" => "Firefox",
        "/Version\/([\d.]+).*Safari/" => "Safari",
    ];

    foreach ($patterns as $pattern => $browser) {
        if (preg_match($pattern, $ua, $m)) {
            return $m[1] ?? "";
        }
    }

    return "";
}

// REFERRER

function categorizeReferrer(string $referrer): string
{
    if (empty($referrer)) {
        return "direct";
    }

    $host = strtolower(parse_url($referrer, PHP_URL_HOST) ?? "");
    $host = preg_replace("/^www\./", "", $host);

    $searchEngines = [
        "google",
        "bing",
        "yahoo",
        "duckduckgo",
        "baidu",
        "yandex",
        "ecosia",
    ];
    $socialNets = [
        "facebook",
        "instagram",
        "twitter",
        "x.com",
        "linkedin",
        "tiktok",
        "youtube",
        "pinterest",
        "reddit",
        "snapchat",
        "whatsapp",
        "telegram",
        "discord",
    ];
    $emailClients = ["mail", "outlook", "gmail", "protonmail", "zoho"];

    foreach ($searchEngines as $s) {
        if (str_contains($host, $s)) {
            return "organic_search";
        }
    }
    foreach ($socialNets as $s) {
        if (str_contains($host, $s)) {
            return "social";
        }
    }
    foreach ($emailClients as $s) {
        if (str_contains($host, $s)) {
            return "email";
        }
    }

    return "referral";
}

// UTM

function extractUtm(string $url): array
{
    parse_str(parse_url($url, PHP_URL_QUERY) ?? "", $params);

    return [
        "utm_source" => substr($params["utm_source"] ?? "", 0, 255),
        "utm_medium" => substr($params["utm_medium"] ?? "", 0, 255),
        "utm_campaign" => substr($params["utm_campaign"] ?? "", 0, 255),
        "utm_content" => substr($params["utm_content"] ?? "", 0, 255),
        "utm_term" => substr($params["utm_term"] ?? "", 0, 255),
    ];
}

// DATE RANGE

function parseDateRange(): array
{
    $range = $_GET["range"] ?? "30d";

    if ($range === "custom") {
        $from = $_GET["from"] ?? "";
        $to = $_GET["to"] ?? "";

        if (
            empty($from) ||
            empty($to) ||
            !DateTime::createFromFormat("Y-m-d", $from) ||
            !DateTime::createFromFormat("Y-m-d", $to)
        ) {
            sendResponse(
                false,
                "Invalid date format. Use YYYY-MM-DD",
                null,
                400
            );
        }

        // Prevent full-table scans: cap custom ranges at 365 days
        $diffDays = (strtotime($to) - strtotime($from)) / 86400;
        if ($diffDays < 0) {
            sendResponse(false, "'from' must be before 'to'", null, 400);
        }
        if ($diffDays > 365) {
            sendResponse(
                false,
                "Custom date range cannot exceed 365 days",
                null,
                400
            );
        }
    } else {
        $days = (int) filter_var($range, FILTER_SANITIZE_NUMBER_INT);
        $days = max(1, min($days, 365));
        $from = date("Y-m-d", strtotime("-{$days} days"));
        $to = date("Y-m-d");
    }

    return [
        "from" => $from . " 00:00:00",
        "to" => $to . " 23:59:59",
    ];
}

// LOCATION + DEVICE RESOLVE

function resolveLocation(mysqli $conn, int $projectId, array $geo): ?int
{
    if (empty($geo["country_code"])) {
        return null;
    }

    // INSERT ... ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id) handles both
    // the "new row" and "row already exists" cases in a single round-trip.
    // The SELECT before INSERT was redundant — removed.
    $stmt = $conn->prepare(
        "INSERT INTO locations (project_id, country, country_code, region, city, is_vpn) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
    );
    if (!$stmt) {
        writeLog("ERROR", "resolveLocation INSERT prepare failed", [
            "error" => $conn->error,
        ]);
        return null;
    }
    $stmt->bind_param(
        "issssi",
        $projectId,
        $geo["country"],
        $geo["country_code"],
        $geo["region"],
        $geo["city"],
        $geo["is_vpn"]
    );
    if (!$stmt->execute()) {
        writeLog("ERROR", "resolveLocation INSERT execute failed", [
            "error" => $stmt->error,
        ]);
        $stmt->close();
        return null;
    }
    $id = (int) $conn->insert_id;
    $stmt->close();

    return $id;
}

function resolveDevice(mysqli $conn, int $projectId, array $device): ?int
{
    // INSERT ... ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id) handles both
    // the "new row" and "row already exists" cases in a single round-trip.
    // The SELECT before INSERT was redundant — removed.
    $stmt = $conn->prepare(
        "INSERT INTO devices (project_id, device_type, os, browser, browser_version) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
    );
    if (!$stmt) {
        writeLog("ERROR", "resolveDevice INSERT prepare failed", [
            "error" => $conn->error,
        ]);
        return null;
    }
    $stmt->bind_param(
        "issss",
        $projectId,
        $device["device_type"],
        $device["os"],
        $device["browser"],
        $device["browser_version"]
    );
    if (!$stmt->execute()) {
        writeLog("ERROR", "resolveDevice INSERT execute failed", [
            "error" => $stmt->error,
        ]);
        $stmt->close();
        return null;
    }
    $id = (int) $conn->insert_id;
    $stmt->close();

    return $id;
}
?>