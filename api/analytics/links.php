<?php
/*
    ===============================================================
        Micrologs
        Endpoint : GET /api/analytics/links.php?range=30d
        Auth     : Secret key (X-API-Key header)
        Desc     : Performance overview of all tracked links
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    sendResponse(false, "Method not allowed", null, 405);
}

$project = verifySecretKey($conn);
$projectId = (int) $project["id"];
$range = parseDateRange();

$baseUrl = defined("APP_URL") ? rtrim(APP_URL, "/") : "";

$stmt = $conn->prepare("
    SELECT tl.code, tl.label, tl.destination_url, tl.created_at,
           COUNT(lc.id) AS total_clicks,
           COUNT(DISTINCT lc.ip_hash) AS unique_clicks
    FROM tracked_links tl
    LEFT JOIN link_clicks lc
        ON lc.link_id = tl.id
        AND lc.created_at BETWEEN ? AND ?
    WHERE tl.project_id = ?
    GROUP BY tl.id
    ORDER BY total_clicks DESC
");
$stmt->bind_param("ssi", $range["from"], $range["to"], $projectId);
$stmt->execute();
$result = $stmt->get_result();

$links = [];
while ($row = $result->fetch_assoc()) {
    $links[] = [
        "code" => $row["code"],
        "short_url" => $baseUrl . "/api/redirect.php?c=" . $row["code"],
        "label" => $row["label"],
        "destination_url" => $row["destination_url"],
        "total_clicks" => (int) $row["total_clicks"],
        "unique_clicks" => (int) $row["unique_clicks"],
        "created_at" => $row["created_at"],
    ];
}
$stmt->close();

sendResponse(true, "Link analytics fetched successfully", [
    "range" => $range,
    "count" => count($links),
    "links" => $links,
]);