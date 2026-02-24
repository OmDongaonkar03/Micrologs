<?php
/*
    ===============================================================
        Micrologs
        Endpoint : GET /api/links/list.php
        Auth     : Secret key (X-API-Key header)
        Desc     : List all tracked links for a project
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    sendResponse(false, "Method not allowed", null, 405);
}

$project = verifySecretKey($conn);
$projectId = (int) $project["id"];

$baseUrl = defined("APP_URL") ? rtrim(APP_URL, "/") : "";

$stmt = $conn->prepare("
    SELECT tl.id, tl.code, tl.destination_url, tl.label, tl.is_active, tl.created_at,
           COUNT(lc.id) AS total_clicks
    FROM tracked_links tl
    LEFT JOIN link_clicks lc ON lc.link_id = tl.id
    WHERE tl.project_id = ?
    GROUP BY tl.id
    ORDER BY tl.created_at DESC
");
$stmt->bind_param("i", $projectId);
$stmt->execute();
$result = $stmt->get_result();

$links = [];
while ($row = $result->fetch_assoc()) {
    $links[] = [
        "id" => (int) $row["id"],
        "code" => $row["code"],
        "short_url" => $baseUrl . "/api/redirect.php?c=" . $row["code"],
        "destination_url" => $row["destination_url"],
        "label" => $row["label"],
        "is_active" => (bool) $row["is_active"],
        "total_clicks" => (int) $row["total_clicks"],
        "created_at" => $row["created_at"],
    ];
}
$stmt->close();

sendResponse(true, "Links fetched successfully", [
    "count" => count($links),
    "links" => $links,
]);