<?php
/*
    ===============================================================
        Micrologs
        Endpoint : GET /api/analytics/errors-trend.php?range=30d
                   Optional: &group_id=1 to scope to one error group
        Auth     : Secret key (X-API-Key header)
        Desc     : Daily error occurrences over time — all groups or a single group
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_errors_trend", 60, 60);

$project = verifySecretKey($conn);
$projectId = (int) $project["id"];
$range = parseDateRange();
$groupId = isset($_GET["group_id"]) ? (int) $_GET["group_id"] : null;

// Summary — total errors in range, unique groups affected
$stmt = $conn->prepare(
    "
    SELECT
        COUNT(*)                AS total_occurrences,
        COUNT(DISTINCT group_id) AS total_groups
    FROM error_events
    WHERE project_id = ? AND created_at BETWEEN ? AND ?
    " .
        ($groupId ? "AND group_id = ?" : "") .
        "
"
);
if (!$stmt) {
    writeLog("ERROR", "errors trend summary prepare failed", [
        "error" => $conn->error,
    ]);
    sendResponse(false, "Server error", null, 500);
}
if ($groupId) {
    $stmt->bind_param(
        "issi",
        $projectId,
        $range["from"],
        $range["to"],
        $groupId
    );
} else {
    $stmt->bind_param("iss", $projectId, $range["from"], $range["to"]);
}
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Daily trend — total occurrences per day
$stmt = $conn->prepare(
    "
    SELECT
        DATE(created_at)    AS date,
        COUNT(*)            AS occurrences,
        COUNT(DISTINCT group_id) AS unique_groups
    FROM error_events
    WHERE project_id = ? AND created_at BETWEEN ? AND ?
    " .
        ($groupId ? "AND group_id = ?" : "") .
        "
    GROUP BY DATE(created_at)
    ORDER BY date ASC
"
);
if (!$stmt) {
    writeLog("ERROR", "errors trend over time prepare failed", [
        "error" => $conn->error,
    ]);
    sendResponse(false, "Server error", null, 500);
}
if ($groupId) {
    $stmt->bind_param(
        "issi",
        $projectId,
        $range["from"],
        $range["to"],
        $groupId
    );
} else {
    $stmt->bind_param("iss", $projectId, $range["from"], $range["to"]);
}
$stmt->execute();
$result = $stmt->get_result();
$overTime = [];
while ($row = $result->fetch_assoc()) {
    $overTime[] = [
        "date" => $row["date"],
        "occurrences" => (int) $row["occurrences"],
        "unique_groups" => (int) $row["unique_groups"],
    ];
}
$stmt->close();

// Top 5 error groups by occurrence in this range
$stmt = $conn->prepare(
    "
    SELECT
        eg.id,
        eg.error_type,
        eg.message,
        eg.severity,
        eg.status,
        COUNT(ee.id) AS occurrences
    FROM error_events ee
    INNER JOIN error_groups eg ON eg.id = ee.group_id
    WHERE ee.project_id = ? AND ee.created_at BETWEEN ? AND ?
    " .
        ($groupId ? "AND ee.group_id = ?" : "") .
        "
    GROUP BY eg.id, eg.error_type, eg.message, eg.severity, eg.status
    ORDER BY occurrences DESC
    LIMIT 5
"
);
if (!$stmt) {
    writeLog("ERROR", "errors top groups prepare failed", [
        "error" => $conn->error,
    ]);
    sendResponse(false, "Server error", null, 500);
}
if ($groupId) {
    $stmt->bind_param(
        "issi",
        $projectId,
        $range["from"],
        $range["to"],
        $groupId
    );
} else {
    $stmt->bind_param("iss", $projectId, $range["from"], $range["to"]);
}
$stmt->execute();
$result = $stmt->get_result();
$topGroups = [];
while ($row = $result->fetch_assoc()) {
    $topGroups[] = [
        "id" => (int) $row["id"],
        "error_type" => $row["error_type"],
        "message" => $row["message"],
        "severity" => $row["severity"],
        "status" => $row["status"],
        "occurrences" => (int) $row["occurrences"],
    ];
}
$stmt->close();

sendResponse(true, "Error trend fetched successfully", [
    "range" => $range,
    "group_id" => $groupId,
    "total_occurrences" => (int) ($summary["total_occurrences"] ?? 0),
    "total_groups" => (int) ($summary["total_groups"] ?? 0),
    "top_groups" => $topGroups,
    "over_time" => $overTime,
]);
?>