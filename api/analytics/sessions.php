<?php
/*
    ===============================================================
        Micrologs
        Endpoint : GET /api/analytics/sessions.php?range=30d
        Auth     : Secret key (X-API-Key header)
        Desc     : Session duration, pages per session, over time
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_sessions", 60, 60);

$project = verifySecretKey($conn);
$projectId = (int) $project["id"];
$range = parseDateRange();

// Average session duration (seconds) and avg pages per session
// Duration = last_activity - started_at
// Only non-bounced sessions have meaningful duration
$stmt = $conn->prepare("
    SELECT
        COUNT(*)                                                        AS total_sessions,
        SUM(is_bounced)                                                 AS bounced_sessions,
        ROUND(AVG(TIMESTAMPDIFF(SECOND, started_at, last_activity)), 0) AS avg_duration_seconds,
        ROUND(AVG(CASE WHEN is_bounced = 0
            THEN TIMESTAMPDIFF(SECOND, started_at, last_activity)
            END), 0)                                                    AS avg_duration_engaged
    FROM sessions
    WHERE project_id = ? AND started_at BETWEEN ? AND ?
");
if (!$stmt) {
    writeLog("ERROR", "sessions summary prepare failed", [
        "error" => $conn->error,
    ]);
    sendResponse(false, "Server error", null, 500);
}
$stmt->bind_param("iss", $projectId, $range["from"], $range["to"]);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Avg pages per session
$stmt = $conn->prepare("
    SELECT ROUND(COUNT(*) / COUNT(DISTINCT session_id), 2) AS avg_pages_per_session
    FROM pageviews
    WHERE project_id = ? AND created_at BETWEEN ? AND ?
");
if (!$stmt) {
    writeLog("ERROR", "avg pages prepare failed", ["error" => $conn->error]);
    sendResponse(false, "Server error", null, 500);
}
$stmt->bind_param("iss", $projectId, $range["from"], $range["to"]);
$stmt->execute();
$pagesRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Sessions over time (daily)
$stmt = $conn->prepare("
    SELECT
        DATE(started_at)                                                    AS date,
        COUNT(*)                                                            AS sessions,
        ROUND(AVG(TIMESTAMPDIFF(SECOND, started_at, last_activity)), 0)    AS avg_duration_seconds
    FROM sessions
    WHERE project_id = ? AND started_at BETWEEN ? AND ?
    GROUP BY DATE(started_at)
    ORDER BY date ASC
");
if (!$stmt) {
    writeLog("ERROR", "sessions over time prepare failed", [
        "error" => $conn->error,
    ]);
    sendResponse(false, "Server error", null, 500);
}
$stmt->bind_param("iss", $projectId, $range["from"], $range["to"]);
$stmt->execute();
$result = $stmt->get_result();
$overTime = [];
while ($row = $result->fetch_assoc()) {
    $overTime[] = [
        "date" => $row["date"],
        "sessions" => (int) $row["sessions"],
        "avg_duration_seconds" => (int) ($row["avg_duration_seconds"] ?? 0),
    ];
}
$stmt->close();

$totalSessions = (int) ($summary["total_sessions"] ?? 0);
$bouncedSessions = (int) ($summary["bounced_sessions"] ?? 0);
$bounceRate =
    $totalSessions > 0
        ? round(($bouncedSessions / $totalSessions) * 100, 1)
        : 0;

sendResponse(true, "Session analytics fetched successfully", [
    "range" => $range,
    "total_sessions" => $totalSessions,
    "bounce_rate" => $bounceRate,
    "avg_duration_seconds" => (int) ($summary["avg_duration_seconds"] ?? 0),
    "avg_duration_engaged" => (int) ($summary["avg_duration_engaged"] ?? 0),
    "avg_pages_per_session" =>
        (float) ($pagesRow["avg_pages_per_session"] ?? 0),
    "over_time" => $overTime,
]);
?>