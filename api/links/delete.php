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

$project = verifySecretKey($conn);
$projectId = (int) $project["id"];

$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    sendResponse(false, "Invalid JSON body", null, 400);
}

$code = trim($input["code"] ?? "");

if (empty($code)) {
    sendResponse(false, "code is required", null, 400);
}

$stmt = $conn->prepare(
    "SELECT id FROM tracked_links WHERE code = ? AND project_id = ? LIMIT 1"
);
$stmt->bind_param("si", $code, $projectId);
$stmt->execute();
$link = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$link) {
    sendResponse(false, "Link not found", null, 404);
}

$stmt = $conn->prepare("DELETE FROM tracked_links WHERE id = ?");
$stmt->bind_param("i", $link["id"]);

if (!$stmt->execute()) {
    sendResponse(false, "Failed to delete link", null, 500);
}
$stmt->close();

sendResponse(true, "Link deleted successfully", ["code" => $code]);