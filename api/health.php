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

// PHP Version
$phpVersion = PHP_VERSION;
$phpOk = version_compare($phpVersion, "8.1.0", ">=");
$checks["php"] = [
    "status"  => $phpOk ? "ok" : "fail",
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
    $conn->query("SELECT 1");
    $checks["database"] = [
        "status"  => "ok",
        "message" => "Connected",
    ];
} catch (Exception $e) {
    $checks["database"] = [
        "status"  => "fail",
        "message" => "Connection failed",
    ];
    $healthy = false;
}

// Valkey
try {
    $valkey = getValkey();
    $valkey->ping();
    $checks["valkey"] = [
        "status"  => "ok",
        "message" => "Connected",
    ];
} catch (\Exception $e) {
    $checks["valkey"] = [
        "status"  => "fail",
        "message" => "Connection failed — queue and cache unavailable",
    ];
    $healthy = false;
}

// Workers — only checked in production (Supervisor not used locally)
if (defined("IS_PRODUCTION") && IS_PRODUCTION) {
    $workers = [
        "pageview-worker" => __DIR__ . "/../supervisor/pids/pageview-worker.pid",
        "error-worker"    => __DIR__ . "/../supervisor/pids/error-worker.pid",
        "audit-worker"    => __DIR__ . "/../supervisor/pids/audit-worker.pid",
    ];

    $allWorkersOk = true;
    $workerStatuses = [];

    foreach ($workers as $name => $pidFile) {
        if (!file_exists($pidFile)) {
            $workerStatuses[$name] = "not running";
            $allWorkersOk = false;
            continue;
        }

        $pid = (int) trim(file_get_contents($pidFile));

        // posix_kill with signal 0 checks if the process exists without killing it
        if ($pid > 0 && posix_kill($pid, 0)) {
            $workerStatuses[$name] = "ok";
        } else {
            $workerStatuses[$name] = "not running";
            $allWorkersOk = false;
        }
    }

    $checks["workers"] = [
        "status"  => $allWorkersOk ? "ok" : "fail",
        "message" => $allWorkersOk
            ? "All workers running"
            : "One or more workers are down — check Supervisor",
        "workers" => $workerStatuses,
    ];
    if (!$allWorkersOk) {
        $healthy = false;
    }
}

// GeoIP
$geoipPath = defined("GEOIP_PATH")
    ? GEOIP_PATH
    : __DIR__ . "/../utils/geoip/GeoLite2-City.mmdb";
$geoipExists = file_exists($geoipPath);
$checks["geoip"] = [
    "status"  => $geoipExists ? "ok" : "warn",
    "message" => $geoipExists
        ? "GeoLite2-City.mmdb found"
        : "GeoLite2-City.mmdb not found — location tracking disabled",
];
// GeoIP missing is a warning not a failure — everything else still works

// Response
$status = $healthy ? 200 : 503;

http_response_code($status);
header("Content-Type: application/json");

echo json_encode(
    [
        "status"    => $healthy ? "healthy" : "unhealthy",
        "timestamp" => date("Y-m-d H:i:s"),
        "checks"    => $checks,
    ],
    JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);
exit();
?>