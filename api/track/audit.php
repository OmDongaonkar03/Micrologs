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

$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    sendResponse(false, "Invalid JSON body", null, 400);
}

// Inputs
$action = substr(trim($input["action"] ?? ""), 0, 100); // e.g. user.login, order.placed
$actor = substr(trim($input["actor"] ?? ""), 0, 255); // e.g. user@email.com, user_id:123, system
$context =
    isset($input["context"]) && is_array($input["context"])
        ? json_encode($input["context"])
        : null;

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
$stmt->bind_param("issss", $projectId, $action, $actor, $ipHash, $context);

if (!$stmt->execute()) {
    sendResponse(false, "Failed to record audit log", null, 500);
}

$id = (int) $conn->insert_id;
$stmt->close();

sendResponse(true, "OK", ["id" => $id]);