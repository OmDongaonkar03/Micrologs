<?php
/*
    ===============================================================
        Micrologs
        Endpoint : POST /api/track/error.php
        Auth     : Public key (X-API-Key header)
        Desc     : Ingest errors from JS snippet or any backend.
                   Validates, enriches with server-side data,
                   pushes to queue and returns 202 immediately.
                   DB write handled by error-worker.php
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_errors", 60, 60);

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

$message = substr(trim($input["message"] ?? ""), 0, 1024);
$errorType = substr(trim($input["error_type"] ?? "Unknown"), 0, 100);
$file = substr(trim($input["file"] ?? ""), 0, 512);
$line = isset($input["line"]) ? (int) $input["line"] : null;
$stackTrace = isset($input["stack"])
    ? substr(trim($input["stack"]), 0, 65535)
    : null;
$url = substr(trim($input["url"] ?? ""), 0, 2048);
$severity = in_array($input["severity"] ?? "", [
    "info",
    "warning",
    "error",
    "critical",
])
    ? $input["severity"]
    : "error";
$environment = in_array($input["environment"] ?? "", [
    "production",
    "staging",
    "development",
])
    ? $input["environment"]
    : "production";
$context = encodeContext($input["context"] ?? null);

if (empty($message)) {
    sendResponse(false, "message is required", null, 400);
}

// Fingerprint computed here — needs project_id + error fields, all available now
$fingerprint = hash(
    "sha256",
    $projectId . $errorType . $message . $file . ($line ?? "")
);

// Server-side enrichment captured at request time
$ip = getClientIp();

$payload = [
    "project_id" => $projectId,
    "fingerprint" => $fingerprint,
    "error_type" => $errorType,
    "message" => $message,
    "file" => $file,
    "line" => $line,
    "stack_trace" => $stackTrace,
    "url" => $url,
    "severity" => $severity,
    "environment" => $environment,
    "context" => $context,
    "geo" => geolocate($ip),
    "device" => parseUserAgent($_SERVER["HTTP_USER_AGENT"] ?? ""),
    "received_at" => date("Y-m-d H:i:s"),
];

queuePush("micrologs:errors", $payload);

sendResponse(true, "OK", ["queued" => true], 202);
?>