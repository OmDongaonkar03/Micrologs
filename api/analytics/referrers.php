<?php
/*
    ===============================================================
        Micrologs
        Endpoint : GET /api/analytics/referrers.php?range=30d
        Auth     : Secret key (X-API-Key header)
        Desc     : Traffic sources — by category and top referrers
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_referrers", 60, 60);

$project = verifySecretKey($conn);
$projectId = (int) $project["id"];
$range = parseDateRange();

// By category
$stmt = $conn->prepare("
    SELECT referrer_category,
           COUNT(*) AS pageviews,
           COUNT(DISTINCT visitor_id) AS unique_visitors
    FROM pageviews
    WHERE project_id = ? AND created_at BETWEEN ? AND ?
    GROUP BY referrer_category
    ORDER BY pageviews DESC
");
$stmt->bind_param("iss", $projectId, $range["from"], $range["to"]);
$stmt->execute();
$result = $stmt->get_result();
$byCategory = [];
while ($row = $result->fetch_assoc()) {
    $byCategory[] = [
        "category" => $row["referrer_category"],
        "pageviews" => (int) $row["pageviews"],
        "unique_visitors" => (int) $row["unique_visitors"],
    ];
}
$stmt->close();

// Top referrer URLs
$stmt = $conn->prepare("
    SELECT referrer_url, COUNT(*) AS pageviews
    FROM pageviews
    WHERE project_id = ? AND created_at BETWEEN ? AND ?
    AND referrer_url != ''
    GROUP BY referrer_url
    ORDER BY pageviews DESC
    LIMIT 20
");
$stmt->bind_param("iss", $projectId, $range["from"], $range["to"]);
$stmt->execute();
$result = $stmt->get_result();
$topReferrers = [];
while ($row = $result->fetch_assoc()) {
    $topReferrers[] = [
        "referrer_url" => $row["referrer_url"],
        "pageviews" => (int) $row["pageviews"],
    ];
}
$stmt->close();

sendResponse(true, "Referrers fetched successfully", [
    "range" => $range,
    "by_category" => $byCategory,
    "top_referrers" => $topReferrers,
]);