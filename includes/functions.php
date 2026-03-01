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

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

$allowedOrigins =
    defined("ALLOWED_ORIGINS") && ALLOWED_ORIGINS !== ""
        ? array_map("trim", explode(",", ALLOWED_ORIGINS))
        : [];

if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
}

header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key");
header("Access-Control-Max-Age: 86400");

// Handle preflight request
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
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
    if (file_exists($logPath) && filesize($logPath) >= 10 * 1024 * 1024) {
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
        "[{$timestamp}] [{$level}] [{$file}] {$message}{$contextStr}" . PHP_EOL;

    file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
}

// RESPONSE
function sendResponse($success, $message, $data = null, $status = 200)
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
function encodeContext($raw, int $maxBytes = 8192): ?string
{
    if (!isset($raw) || !is_array($raw)) {
        return null;
    }

    $encoded = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($encoded === false || strlen($encoded) > $maxBytes) {
        return null;
    }

    return $encoded;
}

// API KEY AUTH

function verifyPublicKey($conn)
{
    $key = $_SERVER["HTTP_X_API_KEY"] ?? "";

    if (empty($key)) {
        sendResponse(false, "API key is required", null, 401);
    }

    $stmt = $conn->prepare(
        "SELECT id, name, allowed_domains FROM projects WHERE public_key = ? AND is_active = 1 LIMIT 1"
    );
    if (!$stmt) {
        writeLog("ERROR", "verifyPublicKey prepare failed", [
            "error" => $conn->error,
        ]);
        sendResponse(false, "Server error", null, 500);
    }
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $project = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$project) {
        sendResponse(false, "Invalid API key", null, 401);
    }

    // Domain lock — check Origin or Referer
    $origin = $_SERVER["HTTP_ORIGIN"] ?? ($_SERVER["HTTP_REFERER"] ?? "");
    if (!empty($origin)) {
        $host = preg_replace(
            "/^www\./",
            "",
            strtolower(parse_url($origin, PHP_URL_HOST) ?? "")
        );

        $allowed = false;
        $domains = explode(",", $project["allowed_domains"]);

        foreach ($domains as $domain) {
            $domain = preg_replace("/^www\./", "", strtolower(trim($domain)));
            if ($host === $domain || str_ends_with($host, "." . $domain)) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            sendResponse(false, "Domain not allowed for this key", null, 403);
        }
    }

    return $project;
}

function verifySecretKey($conn)
{
    $key = $_SERVER["HTTP_X_API_KEY"] ?? "";

    if (empty($key)) {
        sendResponse(false, "API key is required", null, 401);
    }

    $stmt = $conn->prepare(
        "SELECT id, name, allowed_domains FROM projects WHERE secret_key = ? AND is_active = 1 LIMIT 1"
    );
    if (!$stmt) {
        writeLog("ERROR", "verifySecretKey prepare failed", [
            "error" => $conn->error,
        ]);
        sendResponse(false, "Server error", null, 500);
    }
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $project = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$project) {
        sendResponse(false, "Invalid API key", null, 401);
    }

    return $project;
}

// BOT FILTER

function isBot()
{
    $ua = strtolower($_SERVER["HTTP_USER_AGENT"] ?? "");

    if (empty($ua)) {
        return true;
    }

    $botSignatures = [
        "bot",
        "crawler",
        "spider",
        "slurp",
        "curl",
        "wget",
        "python-requests",
        "python-urllib",
        "go-http-client",
        "scrapy",
        "facebookexternalhit",
        "twitterbot",
        "linkedinbot",
        "applebot",
        "googlebot",
        "bingbot",
        "yandexbot",
        "baiduspider",
        "semrushbot",
        "ahrefsbot",
        "mj12bot",
        "dotbot",
        "uptimerobot",
        "pingdom",
        "gtmetrix",
        "headlesschrome",
        "phantomjs",
        "axios",
    ];

    foreach ($botSignatures as $sig) {
        if (str_contains($ua, $sig)) {
            return true;
        }
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

function getClientIp()
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

    if (!empty($trustedProxies) && in_array($remoteAddr, $trustedProxies, true)) {
        if (!empty($_SERVER["HTTP_X_FORWARDED_FOR"])) {
            // XFF chain: "client, proxy1, proxy2" — take the leftmost (real client)
            $ips = array_map("trim", explode(",", $_SERVER["HTTP_X_FORWARDED_FOR"]));
            $clientIp = $ips[0];
            // Basic validation — must look like an IP
            if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
                return $clientIp;
            }
        }
    }

    return $remoteAddr;
}

function hashIp($ip)
{
    $salt = defined("IP_HASH_SALT") ? IP_HASH_SALT : "micrologs_default_salt";
    return hash("sha256", $ip . $salt);
}

// GEOLOCATION

function geolocate($ip)
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

function parseUserAgent($ua)
{
    return [
        "device_type" => detectDeviceType($ua),
        "os" => detectOs($ua),
        "browser" => detectBrowser($ua),
        "browser_version" => detectBrowserVersion($ua),
    ];
}

function detectDeviceType($ua)
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

function detectOs($ua)
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

function detectBrowser($ua)
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

function detectBrowserVersion($ua)
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

function categorizeReferrer($referrer)
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

function extractUtm($url)
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

function parseDateRange()
{
    $range = $_GET["range"] ?? "30d";

    if ($range === "custom") {
        $from = $_GET["from"] ?? "";
        $to   = $_GET["to"]   ?? "";

        if (
            empty($from) ||
            empty($to) ||
            !DateTime::createFromFormat("Y-m-d", $from) ||
            !DateTime::createFromFormat("Y-m-d", $to)
        ) {
            sendResponse(false, "Invalid date format. Use YYYY-MM-DD", null, 400);
        }

        // Prevent full-table scans: cap custom ranges at 365 days
        $diffDays = (strtotime($to) - strtotime($from)) / 86400;
        if ($diffDays < 0) {
            sendResponse(false, "'from' must be before 'to'", null, 400);
        }
        if ($diffDays > 365) {
            sendResponse(false, "Custom date range cannot exceed 365 days", null, 400);
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

function resolveLocation($conn, $projectId, $geo)
{
    if (empty($geo["country_code"])) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT id FROM locations WHERE project_id = ? AND country_code = ? AND region = ? AND city = ? LIMIT 1"
    );
    if (!$stmt) {
        writeLog("ERROR", "resolveLocation SELECT prepare failed", [
            "error" => $conn->error,
        ]);
        return null;
    }
    $stmt->bind_param(
        "isss",
        $projectId,
        $geo["country_code"],
        $geo["region"],
        $geo["city"]
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        return (int) $row["id"];
    }

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

function resolveDevice($conn, $projectId, $device)
{
    $stmt = $conn->prepare(
        "SELECT id FROM devices WHERE project_id = ? AND device_type = ? AND os = ? AND browser = ? AND browser_version = ? LIMIT 1"
    );
    if (!$stmt) {
        writeLog("ERROR", "resolveDevice SELECT prepare failed", [
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
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        return (int) $row["id"];
    }

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