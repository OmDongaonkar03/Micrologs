<?php
/*
    ===============================================================
        Micrologs
        Endpoint : GET /api/analytics/devices.php?range=30d
        Auth     : Secret key (X-API-Key header)
        Desc     : Visitors by device type, OS, browser
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    sendResponse(false, "Method not allowed", null, 405);
}

$project = verifySecretKey($conn);
$projectId = (int) $project["id"];
$range = parseDateRange();

// By device type
$stmt = $conn->prepare("
    SELECT d.device_type,
           COUNT(*) AS pageviews,
           COUNT(DISTINCT p.visitor_id) AS unique_visitors
    FROM pageviews p
    INNER JOIN devices d ON d.id = p.device_id
    WHERE p.project_id = ? AND p.created_at BETWEEN ? AND ?
    GROUP BY d.device_type
    ORDER BY pageviews DESC
");
$stmt->bind_param("iss", $projectId, $range["from"], $range["to"]);
$stmt->execute();
$result = $stmt->get_result();
$byDevice = [];
while ($row = $result->fetch_assoc()) {
    $byDevice[] = [
        "device_type" => $row["device_type"],
        "pageviews" => (int) $row["pageviews"],
        "unique_visitors" => (int) $row["unique_visitors"],
    ];
}
$stmt->close();

// By OS
$stmt = $conn->prepare("
    SELECT d.os, COUNT(*) AS pageviews
    FROM pageviews p
    INNER JOIN devices d ON d.id = p.device_id
    WHERE p.project_id = ? AND p.created_at BETWEEN ? AND ?
    GROUP BY d.os
    ORDER BY pageviews DESC
");
$stmt->bind_param("iss", $projectId, $range["from"], $range["to"]);
$stmt->execute();
$result = $stmt->get_result();
$byOs = [];
while ($row = $result->fetch_assoc()) {
    $byOs[] = ["os" => $row["os"], "pageviews" => (int) $row["pageviews"]];
}
$stmt->close();

// By browser
$stmt = $conn->prepare("
    SELECT d.browser, COUNT(*) AS pageviews
    FROM pageviews p
    INNER JOIN devices d ON d.id = p.device_id
    WHERE p.project_id = ? AND p.created_at BETWEEN ? AND ?
    GROUP BY d.browser
    ORDER BY pageviews DESC
");
$stmt->bind_param("iss", $projectId, $range["from"], $range["to"]);
$stmt->execute();
$result = $stmt->get_result();
$byBrowser = [];
while ($row = $result->fetch_assoc()) {
    $byBrowser[] = [
        "browser" => $row["browser"],
        "pageviews" => (int) $row["pageviews"],
    ];
}
$stmt->close();

sendResponse(true, "Devices fetched successfully", [
    "range" => $range,
    "by_device" => $byDevice,
    "by_os" => $byOs,
    "by_browser" => $byBrowser,
]);