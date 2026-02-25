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

// RESPONSE
function sendResponse($success, $message, $data = null, $status = 200)
{
    http_response_code($status);
    header("Content-Type: application/json");
    header("Access-Control-Allow-Origin: *");
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

// API KEY AUTH

function verifyPublicKey($conn)
{
    $key = $_SERVER["HTTP_X_API_KEY"] ?? "";

    if (empty($key)) {
        sendResponse(false, "API key is required", null, 401);
    }

    $stmt = $conn->prepare(
        "SELECT id, name, allowed_domain FROM projects WHERE public_key = ? AND is_active = 1 LIMIT 1"
    );
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
        $allowed = preg_replace(
            "/^www\./",
            "",
            strtolower($project["allowed_domain"])
        );
        if ($host !== $allowed && !str_ends_with($host, "." . $allowed)) {
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
        "SELECT id, name, allowed_domain FROM projects WHERE secret_key = ? AND is_active = 1 LIMIT 1"
    );
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
    if (!empty($_SERVER["HTTP_X_FORWARDED_FOR"])) {
        $ips = explode(",", $_SERVER["HTTP_X_FORWARDED_FOR"]);
        return trim($ips[0]);
    }
    return $_SERVER["REMOTE_ADDR"] ?? "";
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
        $reader = new \MaxMind\Db\Reader($dbPath);
        $record = $reader->get($ip);
        $reader->close();

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
        $from = $_GET["from"] ?? date("Y-m-d", strtotime("-30 days"));
        $to = $_GET["to"] ?? date("Y-m-d");
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
    $stmt->bind_param(
        "issssi",
        $projectId,
        $geo["country"],
        $geo["country_code"],
        $geo["region"],
        $geo["city"],
        $geo["is_vpn"]
    );
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();

    return $id;
}

function resolveDevice($conn, $projectId, $device)
{
    $stmt = $conn->prepare(
        "SELECT id FROM devices WHERE project_id = ? AND device_type = ? AND os = ? AND browser = ? AND browser_version = ? LIMIT 1"
    );
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
    $stmt->bind_param(
        "issss",
        $projectId,
        $device["device_type"],
        $device["os"],
        $device["browser"],
        $device["browser_version"]
    );
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();

    return $id;
}