<?php
/*
    ===============================================================
        Micrologs
        Endpoint : GET /api/analytics/visitors.php?range=30d
        Auth     : Secret key (X-API-Key header)
        Desc     : Unique visitors, pageviews, sessions, bounce rate
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_visitors", 60, 60);

$project = verifySecretKey($conn);
$projectId = (int) $project["id"];
$range = parseDateRange();

// Unique visitors + total pageviews
$stmt = $conn->prepare("
    SELECT COUNT(DISTINCT visitor_id) AS unique_visitors,
           COUNT(*) AS total_pageviews
    FROM pageviews
    WHERE project_id = ? AND created_at BETWEEN ? AND ?
");
$stmt->bind_param("iss", $projectId, $range["from"], $range["to"]);
$stmt->execute();
$totals = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Sessions + bounce rate
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total_sessions, SUM(s.is_bounced) AS bounced_sessions
    FROM sessions s
    WHERE s.project_id = ? AND s.started_at BETWEEN ? AND ?
");
$stmt->bind_param("iss", $projectId, $range["from"], $range["to"]);
$stmt->execute();
$sessions = $stmt->get_result()->fetch_assoc();
$stmt->close();

$totalSessions = (int) ($sessions["total_sessions"] ?? 0);
$bouncedSessions = (int) ($sessions["bounced_sessions"] ?? 0);
$bounceRate =
    $totalSessions > 0
        ? round(($bouncedSessions / $totalSessions) * 100, 1)
        : 0;

// Traffic over time (daily)
$stmt = $conn->prepare("
    SELECT DATE(created_at) AS date,
           COUNT(*) AS pageviews,
           COUNT(DISTINCT visitor_id) AS unique_visitors
    FROM pageviews
    WHERE project_id = ? AND created_at BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY date ASC
");
$stmt->bind_param("iss", $projectId, $range["from"], $range["to"]);
$stmt->execute();
$result = $stmt->get_result();
$overTime = [];
while ($row = $result->fetch_assoc()) {
    $overTime[] = [
        "date" => $row["date"],
        "pageviews" => (int) $row["pageviews"],
        "unique_visitors" => (int) $row["unique_visitors"],
    ];
}
$stmt->close();

sendResponse(true, "Visitor analytics fetched successfully", [
    "range" => $range,
    "unique_visitors" => (int) ($totals["unique_visitors"] ?? 0),
    "total_pageviews" => (int) ($totals["total_pageviews"] ?? 0),
    "total_sessions" => $totalSessions,
    "bounce_rate" => $bounceRate,
    "over_time" => $overTime,
]);