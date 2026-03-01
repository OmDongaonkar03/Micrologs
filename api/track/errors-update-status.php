<?php
/*
    ===============================================================
        Micrologs
        Endpoint : POST /api/track/errors-update-status.php
        Auth     : Secret key (X-API-Key header)
        Desc     : Update the status of one or more error groups.
                   Accepts a single id or an array of ids (max 100).
                   Valid statuses: open, investigating, resolved, ignored.
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_errors_update_status", 30, 60);

$project = verifySecretKey($conn);
$projectId = (int) $project["id"];

$input = readJsonBody();

if (!$input) {
    sendResponse(false, "Invalid or missing JSON body", null, 400);
}

// ── Validate status ───────────────────────────────────────────────
$status = trim($input["status"] ?? "");
$validStatuses = ["open", "investigating", "resolved", "ignored"];

if (!in_array($status, $validStatuses)) {
    sendResponse(
        false,
        "status is required and must be one of: " .
            implode(", ", $validStatuses),
        null,
        400
    );
}

// ── Resolve IDs — accept single int or array ──────────────────────
$raw = $input["ids"] ?? ($input["id"] ?? null);

if ($raw === null) {
    sendResponse(false, "ids (array) or id (integer) is required", null, 400);
}

$ids = is_array($raw) ? $raw : [$raw];
$ids = array_values(array_unique(array_filter(array_map("intval", $ids))));

if (empty($ids)) {
    sendResponse(false, "No valid IDs provided", null, 400);
}

if (count($ids) > 100) {
    sendResponse(false, "Maximum 100 IDs per request", null, 400);
}

// ── Verify all IDs belong to this project ────────────────────────
$placeholders = implode(",", array_fill(0, count($ids), "?"));
$types = str_repeat("i", count($ids));

$stmt = $conn->prepare("
    SELECT id FROM error_groups
    WHERE project_id = ? AND id IN ({$placeholders})
");
if (!$stmt) {
    writeLog("ERROR", "error_groups verify SELECT prepare failed", [
        "error" => $conn->error,
    ]);
    sendResponse(false, "Server error", null, 500);
}
$stmt->bind_param("i" . $types, $projectId, ...$ids);
$stmt->execute();
$result = $stmt->get_result();

$validIds = [];
while ($row = $result->fetch_assoc()) {
    $validIds[] = (int) $row["id"];
}
$stmt->close();

$invalidIds = array_values(array_diff($ids, $validIds));

if (empty($validIds)) {
    sendResponse(
        false,
        "No matching error groups found for this project",
        null,
        404
    );
}

// ── Update status ─────────────────────────────────────────────────
$updatePlaceholders = implode(",", array_fill(0, count($validIds), "?"));
$updateTypes = "s" . str_repeat("i", count($validIds)) . "i";

$stmt = $conn->prepare("
    UPDATE error_groups
    SET status = ?
    WHERE id IN ({$updatePlaceholders}) AND project_id = ?
");
if (!$stmt) {
    writeLog("ERROR", "error_groups UPDATE prepare failed", [
        "error" => $conn->error,
    ]);
    sendResponse(false, "Failed to update error status", null, 500);
}
$stmt->bind_param($updateTypes, $status, ...$validIds, ...[$projectId]);

if (!$stmt->execute()) {
    writeLog("ERROR", "error_groups UPDATE execute failed", [
        "error" => $stmt->error,
        "project_id" => $projectId,
        "ids" => $validIds,
        "status" => $status,
    ]);
    sendResponse(false, "Failed to update error status", null, 500);
}
$affected = $stmt->affected_rows;
$stmt->close();

$response = [
    "updated" => $affected,
    "ids" => $validIds,
    "status" => $status,
];

if (!empty($invalidIds)) {
    $response["not_found"] = $invalidIds;
}

$message =
    "{$affected} error group" .
    ($affected === 1 ? "" : "s") .
    " marked as {$status}.";
if (!empty($invalidIds)) {
    $message .= " " . count($invalidIds) . " ID(s) not found and were skipped.";
}

sendResponse(true, $message, $response);
?>