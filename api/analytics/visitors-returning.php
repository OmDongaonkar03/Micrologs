<?php
/*
    ===============================================================
        Micrologs
        Endpoint : GET /api/analytics/visitors-returning.php?range=30d
        Auth     : Secret key (X-API-Key header)
        Desc     : New vs returning visitors breakdown + over time
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_visitors_returning", 60, 60);

$project = verifySecretKey($conn);
$projectId = (int) $project["id"];
$range = parseDateRange();

// New visitor = first_seen falls within the date range
// Returning  = first_seen is before the range start but they have
//              a pageview within the range
$stmt = $conn->prepare("
    SELECT
        COUNT(DISTINCT CASE
            WHEN v.first_seen BETWEEN ? AND ? THEN v.id
        END) AS new_visitors,
        COUNT(DISTINCT CASE
            WHEN v.first_seen < ? THEN v.id
        END) AS returning_visitors
    FROM pageviews p
    INNER JOIN visitors v ON v.id = p.visitor_id
    WHERE p.project_id = ? AND p.created_at BETWEEN ? AND ?
");
if (!$stmt) {
    writeLog("ERROR", "new vs returning prepare failed", [
        "error" => $conn->error,
    ]);
    sendResponse(false, "Server error", null, 500);
}
$stmt->bind_param(
    "sssiiss",
    $range["from"],
    $range["to"], // new: first_seen within range
    $range["from"], // returning: first_seen before range
    $projectId,
    $range["from"],
    $range["to"] // pageview within range
);
$stmt->execute();
$totals = $stmt->get_result()->fetch_assoc();
$stmt->close();

$newVisitors = (int) ($totals["new_visitors"] ?? 0);
$returningVisitors = (int) ($totals["returning_visitors"] ?? 0);
$totalVisitors = $newVisitors + $returningVisitors;

$newPct =
    $totalVisitors > 0 ? round(($newVisitors / $totalVisitors) * 100, 1) : 0;
$returningPct =
    $totalVisitors > 0
        ? round(($returningVisitors / $totalVisitors) * 100, 1)
        : 0;

// Daily breakdown over time
$stmt = $conn->prepare("
    SELECT
        DATE(p.created_at) AS date,
        COUNT(DISTINCT CASE
            WHEN v.first_seen BETWEEN ? AND ? THEN v.id
        END) AS new_visitors,
        COUNT(DISTINCT CASE
            WHEN v.first_seen < ? THEN v.id
        END) AS returning_visitors
    FROM pageviews p
    INNER JOIN visitors v ON v.id = p.visitor_id
    WHERE p.project_id = ? AND p.created_at BETWEEN ? AND ?
    GROUP BY DATE(p.created_at)
    ORDER BY date ASC
");
if (!$stmt) {
    writeLog("ERROR", "new vs returning over time prepare failed", [
        "error" => $conn->error,
    ]);
    sendResponse(false, "Server error", null, 500);
}
$stmt->bind_param(
    "sssiiss",
    $range["from"],
    $range["to"],
    $range["from"],
    $projectId,
    $range["from"],
    $range["to"]
);
$stmt->execute();
$result = $stmt->get_result();
$overTime = [];
while ($row = $result->fetch_assoc()) {
    $overTime[] = [
        "date" => $row["date"],
        "new_visitors" => (int) $row["new_visitors"],
        "returning_visitors" => (int) $row["returning_visitors"],
    ];
}
$stmt->close();

sendResponse(true, "Visitor retention fetched successfully", [
    "range" => $range,
    "total_visitors" => $totalVisitors,
    "new_visitors" => $newVisitors,
    "returning_visitors" => $returningVisitors,
    "new_pct" => $newPct,
    "returning_pct" => $returningPct,
    "over_time" => $overTime,
]);
?>