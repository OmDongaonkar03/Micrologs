<?php
/*
    ===============================================================
        Micrologs
        Endpoint : POST /api/links/create.php
        Auth     : Secret key (X-API-Key header)
        Desc     : Generate a new tracked short link
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_links_create", 30, 60);

$project = verifySecretKey($conn);
$projectId = (int) $project["id"];

$input = readJsonBody();

if (!$input) {
    sendResponse(false, "Invalid or missing JSON body", null, 400);
}

$destinationUrl = trim($input["destination_url"] ?? "");
$label = substr(trim($input["label"] ?? ""), 0, 255);

if (empty($destinationUrl)) {
    sendResponse(false, "destination_url is required", null, 400);
}

if (!filter_var($destinationUrl, FILTER_VALIDATE_URL)) {
    sendResponse(false, "destination_url is not a valid URL", null, 400);
}

// Generate unique 8-char code
$chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
do {
    $code = "";
    for ($i = 0; $i < 8; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    $stmt = $conn->prepare(
        "SELECT id FROM tracked_links WHERE code = ? LIMIT 1"
    );
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} while ($exists);

$stmt = $conn->prepare(
    "INSERT INTO tracked_links (project_id, code, destination_url, label) VALUES (?, ?, ?, ?)"
);
if (!$stmt) {
    writeLog("ERROR", "tracked_links INSERT prepare failed", [
        "error" => $conn->error,
        "project_id" => $projectId,
    ]);
    sendResponse(false, "Failed to create link", null, 500);
}
$stmt->bind_param("isss", $projectId, $code, $destinationUrl, $label);

if (!$stmt->execute()) {
    writeLog("ERROR", "tracked_links INSERT execute failed", [
        "error" => $stmt->error,
        "project_id" => $projectId,
    ]);
    sendResponse(false, "Failed to create link", null, 500);
}
$stmt->close();

$baseUrl = defined("APP_URL") ? rtrim(APP_URL, "/") : "";

sendResponse(true, "Link created successfully", [
    "code" => $code,
    "short_url" => $baseUrl . "/api/redirect.php?c=" . $code,
    "destination_url" => $destinationUrl,
    "label" => $label,
]);
?>