<?php
/*
    ===============================================================
        Micrologs
        Endpoint : POST /api/track/audit.php
        Auth     : Public key (X-API-Key header)
        Desc     : Ingest audit events from any application
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_audit", 60, 60);

$project = verifyPublicKey($conn);
$projectId = (int) $project["id"];

$input = readJsonBody();

if (!$input) {
    sendResponse(false, "Invalid or missing JSON body", null, 400);
}

// Inputs
$action = substr(trim($input["action"] ?? ""), 0, 100);
$actor = substr(trim($input["actor"] ?? ""), 0, 255);
$context = encodeContext($input["context"] ?? null);

if (empty($action)) {
    sendResponse(false, "action is required", null, 400);
}

// Hash IP
$ip = getClientIp();
$ipHash = hashIp($ip);

// Insert audit log
$stmt = $conn->prepare("
    INSERT INTO audit_logs (project_id, action, actor, ip_hash, context)
    VALUES (?, ?, ?, ?, ?)
");
if (!$stmt) {
    writeLog("ERROR", "audit_logs INSERT prepare failed", [
        "error" => $conn->error,
    ]);
    sendResponse(false, "Failed to record audit log", null, 500);
}
$stmt->bind_param("issss", $projectId, $action, $actor, $ipHash, $context);

if (!$stmt->execute()) {
    writeLog("ERROR", "audit_logs INSERT execute failed", [
        "error" => $stmt->error,
        "project_id" => $projectId,
        "action" => $action,
    ]);
    sendResponse(false, "Failed to record audit log", null, 500);
}

$id = (int) $conn->insert_id;
$stmt->close();

sendResponse(true, "OK", ["id" => $id]);
?>