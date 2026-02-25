<?php
/*
    ===============================================================
        Micrologs
        Endpoint : GET /api/analytics/error-detail.php?id=1
        Auth     : Secret key (X-API-Key header)
        Desc     : Full detail for a single error group + all events
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_error_detail", 60, 60);

$project = verifySecretKey($conn);
$projectId = (int) $project["id"];
$groupId = (int) ($_GET["id"] ?? 0);
$range = parseDateRange();

if (!$groupId) {
    sendResponse(false, "id is required", null, 400);
}

// Fetch error group
$stmt = $conn->prepare("
    SELECT id, error_type, message, file, line, severity, environment,
           status, occurrence_count, first_seen, last_seen
    FROM error_groups
    WHERE id = ? AND project_id = ? LIMIT 1
");
$stmt->bind_param("ii", $groupId, $projectId);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$group) {
    sendResponse(false, "Error group not found", null, 404);
}

// Fetch events for this group
$stmt = $conn->prepare("
    SELECT ee.id, ee.stack_trace, ee.url, ee.environment, ee.severity,
           ee.context, ee.created_at,
           d.device_type, d.os, d.browser,
           l.country, l.country_code, l.city
    FROM error_events ee
    LEFT JOIN devices d ON d.id = ee.device_id
    LEFT JOIN locations l ON l.id = ee.location_id
    WHERE ee.group_id = ? AND ee.created_at BETWEEN ? AND ?
    ORDER BY ee.created_at DESC
    LIMIT 100
");
$stmt->bind_param("iss", $groupId, $range["from"], $range["to"]);
$stmt->execute();
$result = $stmt->get_result();

$events = [];
while ($row = $result->fetch_assoc()) {
    $events[] = [
        "id" => (int) $row["id"],
        "stack_trace" => $row["stack_trace"],
        "url" => $row["url"],
        "environment" => $row["environment"],
        "severity" => $row["severity"],
        "context" => $row["context"]
            ? json_decode($row["context"], true)
            : null,
        "created_at" => $row["created_at"],
        "device" => [
            "type" => $row["device_type"],
            "os" => $row["os"],
            "browser" => $row["browser"],
        ],
        "location" => [
            "country" => $row["country"],
            "country_code" => $row["country_code"],
            "city" => $row["city"],
        ],
    ];
}
$stmt->close();

// Over time
$stmt = $conn->prepare("
    SELECT DATE(created_at) AS date, COUNT(*) AS occurrences
    FROM error_events
    WHERE group_id = ? AND created_at BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY date ASC
");
$stmt->bind_param("iss", $groupId, $range["from"], $range["to"]);
$stmt->execute();
$result = $stmt->get_result();
$overTime = [];
while ($row = $result->fetch_assoc()) {
    $overTime[] = [
        "date" => $row["date"],
        "occurrences" => (int) $row["occurrences"],
    ];
}
$stmt->close();

sendResponse(true, "Error detail fetched successfully", [
    "range" => $range,
    "group" => [
        "id" => (int) $group["id"],
        "error_type" => $group["error_type"],
        "message" => $group["message"],
        "file" => $group["file"],
        "line" => $group["line"] ? (int) $group["line"] : null,
        "severity" => $group["severity"],
        "environment" => $group["environment"],
        "status" => $group["status"],
        "occurrence_count" => (int) $group["occurrence_count"],
        "first_seen" => $group["first_seen"],
        "last_seen" => $group["last_seen"],
    ],
    "over_time" => $overTime,
    "events" => $events,
]);