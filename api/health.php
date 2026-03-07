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
    // Each worker program runs multiple processes (numprocs).
    // Supervisor names PID files as {program_name}_{NN}.pid — e.g.
    // micrologs-pageview_00.pid, micrologs-pageview_01.pid, etc.
    // We glob for all PIDs belonging to each program and require every
    // process to be alive before reporting that worker as "ok".
    $workers = [
        "pageview-worker" => [
            "pattern"  => __DIR__ . "/../supervisor/pids/micrologs-pageview_*.pid",
            "numprocs" => 3,
        ],
        "error-worker" => [
            "pattern"  => __DIR__ . "/../supervisor/pids/micrologs-error_*.pid",
            "numprocs" => 2,
        ],
        "audit-worker" => [
            "pattern"  => __DIR__ . "/../supervisor/pids/micrologs-audit_*.pid",
            "numprocs" => 2,
        ],
    ];

    $allWorkersOk = true;
    $workerStatuses = [];

    foreach ($workers as $name => $config) {
        $pidFiles = glob($config["pattern"]) ?: [];
        $running  = 0;

        foreach ($pidFiles as $pidFile) {
            $pid = (int) trim(file_get_contents($pidFile));
            // posix_kill signal 0 checks process existence without killing it
            if ($pid > 0 && posix_kill($pid, 0)) {
                $running++;
            }
        }

        $expected = $config["numprocs"];
        if ($running === $expected) {
            $workerStatuses[$name] = "ok ({$running}/{$expected})";
        } else {
            $workerStatuses[$name] = "degraded ({$running}/{$expected} running)";
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