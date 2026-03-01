<?php
/*
    ===============================================================
        Micrologs
        Endpoint : POST /api/projects/regenerate-keys.php
        Auth     : Admin key (X-Admin-Key header)
        Desc     : Rotate a project's secret_key, public_key, or
                   both. Old keys are invalidated immediately.
                   Update your snippet and server-side callers
                   before rotating.
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_project_regen_keys", 5, 60);

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

// Which keys to rotate — default is both
$rotateSecret = (bool) ($input["rotate_secret"] ?? true);
$rotatePublic = (bool) ($input["rotate_public"] ?? true);

if (!$rotateSecret && !$rotatePublic) {
    sendResponse(
        false,
        "Nothing to rotate. Set rotate_secret and/or rotate_public to true.",
        null,
        400
    );
}

// ── Fetch existing project ────────────────────────────────────────
$stmt = $conn->prepare("SELECT id, name FROM projects WHERE id = ? LIMIT 1");
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

// ── Generate new keys ─────────────────────────────────────────────
$updates = [];
$params = [];
$types = "";
$newKeys = [];

if ($rotateSecret) {
    $newSecret = bin2hex(random_bytes(32));
    $updates[] = "secret_key = ?";
    $params[] = $newSecret;
    $types .= "s";
    $newKeys["secret_key"] = $newSecret;
}

if ($rotatePublic) {
    $newPublic = bin2hex(random_bytes(16));
    $updates[] = "public_key = ?";
    $params[] = $newPublic;
    $types .= "s";
    $newKeys["public_key"] = $newPublic;
}

// ── Execute update ────────────────────────────────────────────────
$params[] = $projectId;
$types .= "i";

$sql = "UPDATE projects SET " . implode(", ", $updates) . " WHERE id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    writeLog("ERROR", "project regenerate-keys UPDATE prepare failed", [
        "error" => $conn->error,
        "project_id" => $projectId,
    ]);
    sendResponse(false, "Failed to regenerate keys", null, 500);
}
$stmt->bind_param($types, ...$params);

if (!$stmt->execute()) {
    writeLog("ERROR", "project regenerate-keys UPDATE execute failed", [
        "error" => $stmt->error,
        "project_id" => $projectId,
    ]);
    sendResponse(false, "Failed to regenerate keys", null, 500);
}
$stmt->close();

writeLog("INFO", "Project keys rotated", [
    "project_id" => $projectId,
    "project_name" => $project["name"],
    "rotated_secret" => $rotateSecret,
    "rotated_public" => $rotatePublic,
]);

$rotatedList = array_keys($newKeys);
$message =
    "Keys rotated successfully (" .
    implode(", ", $rotatedList) .
    "). " .
    "Old keys are invalidated immediately. Update your snippet and callers now.";

sendResponse(
    true,
    $message,
    array_merge(["id" => $projectId, "name" => $project["name"]], $newKeys)
);
?>