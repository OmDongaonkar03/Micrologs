<?php
/*
    ===============================================================
        Micrologs
        Endpoint : GET /api/health.php
        Auth     : None — public
        Desc     : System health check for uptime monitors
    ===============================================================
*/

include_once __DIR__ . "/../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    sendResponse(false, "Method not allowed", null, 405);
}

$checks = [];
$healthy = true;

//PHP Version
$phpVersion = PHP_VERSION;
$phpOk = version_compare($phpVersion, "8.1.0", ">=");
$checks["php"] = [
    "status" => $phpOk ? "ok" : "fail",
    "version" => $phpVersion,
    "message" => $phpOk
        ? "PHP {$phpVersion}"
        : "PHP 8.1+ required, running {$phpVersion}",
];
if (!$phpOk) {
    $healthy = false;
}

// Database
try {
    $result = $conn->query("SELECT 1");
    $checks["database"] = [
        "status" => "ok",
        "message" => "Connected",
    ];
} catch (Exception $e) {
    $checks["database"] = [
        "status" => "fail",
        "message" => "Connection failed",
    ];
    $healthy = false;
}

// GeoIP
$geoipPath = defined("GEOIP_PATH")
    ? GEOIP_PATH
    : __DIR__ . "/../utils/geoip/GeoLite2-City.mmdb";
$geoipExists = file_exists($geoipPath);
$checks["geoip"] = [
    "status" => $geoipExists ? "ok" : "warn",
    "message" => $geoipExists
        ? "GeoLite2-City.mmdb found"
        : "GeoLite2-City.mmdb not found — location tracking disabled",
];
// GeoIP missing is a warning not a failure — everything else still works

// Rate limit folders writable
$rateLimitsDir = __DIR__ . "/../utils/rate_limits";
$rateBlocksDir = __DIR__ . "/../utils/rate_blocks";

$limitsOk = is_dir($rateLimitsDir) && is_writable($rateLimitsDir);
$blocksOk = is_dir($rateBlocksDir) && is_writable($rateBlocksDir);
$rateOk = $limitsOk && $blocksOk;

$checks["rate_limiter"] = [
    "status" => $rateOk ? "ok" : "fail",
    "message" => $rateOk
        ? "rate_limits and rate_blocks directories are writable"
        : "rate_limits or rate_blocks directory missing or not writable — run: chmod 755 utils/rate_limits utils/rate_blocks",
];
if (!$rateOk) {
    $healthy = false;
}

// Response
$status = $healthy ? 200 : 503;

http_response_code($status);
header("Content-Type: application/json");

echo json_encode(
    [
        "status" => $healthy ? "healthy" : "unhealthy",
        "timestamp" => date("Y-m-d H:i:s"),
        "checks" => $checks,
    ],
    JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);
exit();