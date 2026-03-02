<?php
/*
    ===============================================================
        Micrologs
        Endpoint : POST /api/projects/delete.php
        Auth     : Admin key (X-Admin-Key header)
        Desc     : Permanently delete a project and all its data
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_project_delete", 5, 60);

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

// ── Confirm project exists ────────────────────────────────────────
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

// ── Require explicit confirmation ─────────────────────────────────
$confirm = trim($input["confirm"] ?? "");
if ($confirm !== $project["name"]) {
    sendResponse(
        false,
        "Confirmation failed. Pass \"confirm\": \"" .
            $project["name"] .
            "\" to permanently delete this project.",
        null,
        400
    );
}

// ── Delete project (cascade handled by FK constraints, or manual) ─
// Delete child data first in case FK constraints are not set to CASCADE
$tables = [
    "link_clicks" => "link_id", // via tracked_links
    "tracked_links" => "project_id",
    "pageviews" => "project_id",
    "sessions" => "project_id",
    "error_logs" => "project_id",
    "audit_logs" => "project_id",
];

// link_clicks references tracked_links, so delete those first
$stmt = $conn->prepare(
    "DELETE lc FROM link_clicks lc
     INNER JOIN tracked_links tl ON lc.link_id = tl.id
     WHERE tl.project_id = ?"
);
if ($stmt) {
    $stmt->bind_param("i", $projectId);
    $stmt->execute();
    $stmt->close();
}

$directTables = [
    "tracked_links",
    "pageviews",
    "sessions",
    "error_groups",
    "audit_logs",
];
foreach ($directTables as $table) {
    $stmt = $conn->prepare("DELETE FROM `{$table}` WHERE project_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $projectId);
        $stmt->execute();
        $stmt->close();
    }
}

// ── Finally delete the project itself ────────────────────────────
$stmt = $conn->prepare("DELETE FROM projects WHERE id = ?");
if (!$stmt) {
    writeLog("ERROR", "project DELETE prepare failed", [
        "error" => $conn->error,
        "project_id" => $projectId,
    ]);
    sendResponse(false, "Failed to delete project", null, 500);
}
$stmt->bind_param("i", $projectId);

if (!$stmt->execute()) {
    writeLog("ERROR", "project DELETE execute failed", [
        "error" => $stmt->error,
        "project_id" => $projectId,
    ]);
    sendResponse(false, "Failed to delete project", null, 500);
}
$stmt->close();

writeLog("INFO", "Project deleted", [
    "project_id" => $projectId,
    "project_name" => $project["name"],
]);

sendResponse(
    true,
    "Project \"" .
        $project["name"] .
        "\" and all its data have been permanently deleted.",
    [
        "id" => $projectId,
        "name" => $project["name"],
    ]
);
?>