<?php
/*
    ===============================================================
        Micrologs
        Endpoint : GET /api/links/detail.php?code=XXXXXXXX
        Auth     : Secret key (X-API-Key header)
        Desc     : Fetch a single tracked link by code
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_links_detail", 60, 60);

$project = verifySecretKey($conn);
$projectId = (int) $project["id"];

$code = trim($_GET["code"] ?? "");

if (empty($code)) {
    sendResponse(false, "code query parameter is required", null, 400);
}

$baseUrl = defined("APP_URL") ? rtrim(APP_URL, "/") : "";

$stmt = $conn->prepare("
    SELECT tl.id, tl.code, tl.destination_url, tl.label, tl.is_active, tl.created_at,
           COUNT(lc.id) AS total_clicks
    FROM tracked_links tl
    LEFT JOIN link_clicks lc ON lc.link_id = tl.id
    WHERE tl.code = ? AND tl.project_id = ?
    GROUP BY tl.id
    LIMIT 1
");
if (!$stmt) {
    writeLog("ERROR", "tracked_links detail SELECT prepare failed", [
        "error" => $conn->error,
    ]);
    sendResponse(false, "Server error", null, 500);
}
$stmt->bind_param("si", $code, $projectId);
$stmt->execute();
$link = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$link) {
    sendResponse(false, "Link not found", null, 404);
}

sendResponse(true, "Link fetched successfully", [
    "id" => (int) $link["id"],
    "code" => $link["code"],
    "short_url" => $baseUrl . "/api/redirect.php?c=" . $link["code"],
    "destination_url" => $link["destination_url"],
    "label" => $link["label"],
    "is_active" => (bool) $link["is_active"],
    "total_clicks" => (int) $link["total_clicks"],
    "created_at" => $link["created_at"],
]);
?>