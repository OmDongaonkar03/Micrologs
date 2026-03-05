<?php
/*
    ===============================================================
        Micrologs
        Endpoint : POST /api/track/audit.php
        Auth     : Public key (X-API-Key header)
        Desc     : Ingest audit events from any application.
                   Validates, enriches with IP hash, pushes to
                   queue and returns 202 immediately.
                   DB write handled by audit-worker.php
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_audit", 60, 60);

// Accept secret key (backend callers) or public key (JS snippet)
// Secret key tried first — if it matches, domain lock is skipped (server-side call)
// Public key fallback — domain lock enforced (browser call)
$project = tryVerifySecretKey($conn) ?? tryVerifyPublicKey($conn);

if (!$project) {
    sendResponse(false, "Invalid API key", null, 401);
}

$projectId = (int) $project["id"];

$input = readJsonBody();

if (!$input) {
    sendResponse(false, "Invalid or missing JSON body", null, 400);
}

$action = substr(trim($input["action"] ?? ""), 0, 100);
$actor = substr(trim($input["actor"] ?? ""), 0, 255);
$context = encodeContext($input["context"] ?? null);

if (empty($action)) {
    sendResponse(false, "action is required", null, 400);
}

// Capture IP hash at request time — not in the worker
$payload = [
    "project_id" => $projectId,
    "action" => $action,
    "actor" => $actor,
    "ip_hash" => hashIp(getClientIp()),
    "context" => $context,
    "received_at" => date("Y-m-d H:i:s"),
];

queuePush("micrologs:audits", $payload);

sendResponse(true, "OK", ["queued" => true], 202);
?>