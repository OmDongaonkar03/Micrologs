<?php
/*
    ===============================================================
        Micrologs
        Endpoint : GET /api/analytics/pages.php?range=30d&limit=20
        Auth     : Secret key (X-API-Key header)
        Desc     : Top pages by pageviews
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_pages", 60, 60);

$project = verifySecretKey($conn);
$projectId = (int) $project["id"];
$range = parseDateRange();
$limit = min(100, max(1, (int) ($_GET["limit"] ?? 20)));

$stmt = $conn->prepare("
    SELECT url, page_title,
           COUNT(*) AS pageviews,
           COUNT(DISTINCT visitor_id) AS unique_visitors
    FROM pageviews
    WHERE project_id = ? AND created_at BETWEEN ? AND ?
    GROUP BY url, page_title
    ORDER BY pageviews DESC
    LIMIT ?
");
$stmt->bind_param("issi", $projectId, $range["from"], $range["to"], $limit);
$stmt->execute();
$result = $stmt->get_result();

$pages = [];
while ($row = $result->fetch_assoc()) {
    $pages[] = [
        "url" => $row["url"],
        "page_title" => $row["page_title"],
        "pageviews" => (int) $row["pageviews"],
        "unique_visitors" => (int) $row["unique_visitors"],
    ];
}
$stmt->close();

sendResponse(true, "Pages fetched successfully", [
    "range" => $range,
    "count" => count($pages),
    "pages" => $pages,
]);
