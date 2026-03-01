<?php
/*
    ===============================================================
        Micrologs
        Endpoint : POST /api/links/edit.php
        Auth     : Secret key (X-API-Key header)
        Desc     : Edit a tracked link's label, destination URL,
                   or active status by code
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_links_edit", 30, 60);

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

// ── Fetch existing link ───────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT id, code, destination_url, label, is_active FROM tracked_links WHERE code = ? AND project_id = ? LIMIT 1"
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

// ── Build update fields ───────────────────────────────────────────
$destinationUrl = isset($input["destination_url"])
    ? trim($input["destination_url"])
    : null;
$label = isset($input["label"]) ? substr(trim($input["label"]), 0, 255) : null;
$isActive = isset($input["is_active"]) ? $input["is_active"] : null;

if ($destinationUrl === null && $label === null && $isActive === null) {
    sendResponse(
        false,
        "Nothing to update. Provide destination_url, label, or is_active.",
        null,
        400
    );
}

$updates = [];
$params = [];
$types = "";

// ── Validate destination_url ──────────────────────────────────────
if ($destinationUrl !== null) {
    if (empty($destinationUrl)) {
        sendResponse(false, "destination_url cannot be empty", null, 400);
    }
    if (!filter_var($destinationUrl, FILTER_VALIDATE_URL)) {
        sendResponse(false, "destination_url is not a valid URL", null, 400);
    }
    $updates[] = "destination_url = ?";
    $params[] = $destinationUrl;
    $types .= "s";
}

// ── Validate label ────────────────────────────────────────────────
if ($label !== null) {
    $updates[] = "label = ?";
    $params[] = $label;
    $types .= "s";
}

// ── Validate is_active ────────────────────────────────────────────
if ($isActive !== null) {
    if (!is_bool($isActive)) {
        sendResponse(false, "is_active must be a boolean", null, 400);
    }
    $activeInt = $isActive ? 1 : 0;
    $updates[] = "is_active = ?";
    $params[] = $activeInt;
    $types .= "i";
}

// ── Execute update ────────────────────────────────────────────────
$params[] = $link["id"];
$types .= "i";

$sql = "UPDATE tracked_links SET " . implode(", ", $updates) . " WHERE id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    writeLog("ERROR", "tracked_links UPDATE prepare failed", [
        "error" => $conn->error,
        "link_id" => $link["id"],
    ]);
    sendResponse(false, "Failed to update link", null, 500);
}
$stmt->bind_param($types, ...$params);

if (!$stmt->execute()) {
    writeLog("ERROR", "tracked_links UPDATE execute failed", [
        "error" => $stmt->error,
        "link_id" => $link["id"],
    ]);
    sendResponse(false, "Failed to update link", null, 500);
}
$stmt->close();

// ── Fetch updated link ────────────────────────────────────────────
$baseUrl = defined("APP_URL") ? rtrim(APP_URL, "/") : "";

$stmt = $conn->prepare(
    "SELECT id, code, destination_url, label, is_active, created_at FROM tracked_links WHERE id = ? LIMIT 1"
);
$stmt->bind_param("i", $link["id"]);
$stmt->execute();
$updated = $stmt->get_result()->fetch_assoc();
$stmt->close();

sendResponse(true, "Link updated successfully", [
    "id" => (int) $updated["id"],
    "code" => $updated["code"],
    "short_url" => $baseUrl . "/api/redirect.php?c=" . $updated["code"],
    "destination_url" => $updated["destination_url"],
    "label" => $updated["label"],
    "is_active" => (bool) $updated["is_active"],
    "created_at" => $updated["created_at"],
]);
?>