<?php
/*
    ===============================================================
        Micrologs
        Endpoint : GET /api/analytics/utm.php?range=30d
        Auth     : Secret key (X-API-Key header)
        Desc     : UTM campaign breakdown
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    sendResponse(false, "Method not allowed", null, 405);
}

$project = verifySecretKey($conn);
$projectId = (int) $project["id"];
$range = parseDateRange();

$stmt = $conn->prepare("
    SELECT utm_source, utm_medium, utm_campaign,
           COUNT(*) AS pageviews,
           COUNT(DISTINCT visitor_id) AS unique_visitors
    FROM pageviews
    WHERE project_id = ? AND created_at BETWEEN ? AND ?
    AND utm_campaign != ''
    GROUP BY utm_source, utm_medium, utm_campaign
    ORDER BY pageviews DESC
");
$stmt->bind_param("iss", $projectId, $range["from"], $range["to"]);
$stmt->execute();
$result = $stmt->get_result();

$campaigns = [];
while ($row = $result->fetch_assoc()) {
    $campaigns[] = [
        "utm_source" => $row["utm_source"],
        "utm_medium" => $row["utm_medium"],
        "utm_campaign" => $row["utm_campaign"],
        "pageviews" => (int) $row["pageviews"],
        "unique_visitors" => (int) $row["unique_visitors"],
    ];
}
$stmt->close();

sendResponse(true, "UTM data fetched successfully", [
    "range" => $range,
    "count" => count($campaigns),
    "campaigns" => $campaigns,
]);