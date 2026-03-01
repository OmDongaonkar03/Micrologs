<?php
/*
    ===============================================================
        Micrologs
        Endpoint : POST /api/projects/toggle.php
        Auth     : Admin key (X-Admin-Key header)
        Desc     : Enable or disable a project. Disabled projects
                   reject all incoming tracking requests and
                   analytics queries.
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_project_toggle", 10, 60);

$adminKey = $_SERVER["HTTP_X_ADMIN_KEY"] ?? "";
if (empty($adminKey) || $adminKey !== ADMIN_KEY) {
    sendResponse(false, "Unauthorized", null, 401);
}

$input = readJsonBody();

if (!$input) {
    sendResponse(false, "Invalid or missing JSON body", null, 400);
}

$projectId = (int) ($input["id"] ?? 0);

if (!$projectId) {
    sendResponse(false, "id is required", null, 400);
}

// ── Fetch existing project ────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT id, name, is_active FROM projects WHERE id = ? LIMIT 1"
);
if (!$stmt) {
    writeLog("ERROR", "project SELECT prepare failed", [
        "error" => $conn->error,
    ]);
    sendResponse(false, "Server error", null, 500);
}
$stmt->bind_param("i", $projectId);
$stmt->execute();
$project = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$project) {
    sendResponse(false, "Project not found", null, 404);
}

// ── Determine target state ────────────────────────────────────────
// If `is_active` is provided in body, use it. Otherwise toggle current state.
if (isset($input["is_active"])) {
    if (!is_bool($input["is_active"])) {
        sendResponse(false, "is_active must be a boolean", null, 400);
    }
    $newState = $input["is_active"] ? 1 : 0;
} else {
    $newState = $project["is_active"] ? 0 : 1;
}

// ── Update ────────────────────────────────────────────────────────
$stmt = $conn->prepare("UPDATE projects SET is_active = ? WHERE id = ?");
if (!$stmt) {
    writeLog("ERROR", "project toggle UPDATE prepare failed", [
        "error" => $conn->error,
        "project_id" => $projectId,
    ]);
    sendResponse(false, "Failed to update project", null, 500);
}
$stmt->bind_param("ii", $newState, $projectId);

if (!$stmt->execute()) {
    writeLog("ERROR", "project toggle UPDATE execute failed", [
        "error" => $stmt->error,
        "project_id" => $projectId,
    ]);
    sendResponse(false, "Failed to update project", null, 500);
}
$stmt->close();

$stateLabel = $newState ? "enabled" : "disabled";

writeLog("INFO", "Project {$stateLabel}", [
    "project_id" => $projectId,
    "project_name" => $project["name"],
    "is_active" => (bool) $newState,
]);

sendResponse(
    true,
    "Project \"{$project["name"]}\" {$stateLabel} successfully.",
    [
        "id" => $projectId,
        "name" => $project["name"],
        "is_active" => (bool) $newState,
    ]
);
?>