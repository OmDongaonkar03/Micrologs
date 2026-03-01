<?php
/*
    ===============================================================
        Micrologs
        Endpoint : POST /api/links/delete.php
        Auth     : Secret key (X-API-Key header)
        Desc     : Delete a tracked link by code
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_links_delete", 30, 60);

$project = verifySecretKey($conn);
$projectId = (int) $project["id"];

$input = readJsonBody();

if (!$input) {
    sendResponse(false, "Invalid or missing JSON body", null, 400);
}

$code = trim($input["code"] ?? "");

if (empty($code)) {
    sendResponse(false, "code is required", null, 400);
}

$stmt = $conn->prepare(
    "SELECT id FROM tracked_links WHERE code = ? AND project_id = ? LIMIT 1"
);
if (!$stmt) {
    writeLog("ERROR", "tracked_links SELECT prepare failed", [
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

$stmt = $conn->prepare("DELETE FROM tracked_links WHERE id = ?");
if (!$stmt) {
    writeLog("ERROR", "tracked_links DELETE prepare failed", [
        "error" => $conn->error,
    ]);
    sendResponse(false, "Failed to delete link", null, 500);
}
$stmt->bind_param("i", $link["id"]);

if (!$stmt->execute()) {
    writeLog("ERROR", "tracked_links DELETE execute failed", [
        "error" => $stmt->error,
        "code" => $code,
    ]);
    sendResponse(false, "Failed to delete link", null, 500);
}
$stmt->close();

sendResponse(true, "Link deleted successfully", ["code" => $code]);
?>