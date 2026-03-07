<?php
/*
    ===============================================================
        Micrologs
        Endpoint : POST /api/track/pageview.php
        Auth     : Public key (X-API-Key header)
        Desc     : Ingest a pageview from the JS snippet.
                   Validates the request, enriches with server-side
                   data, then pushes to the Valkey queue and returns
                   202 immediately. DB writes handled by the worker.
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock(getClientIp() . "_pageview", 60, 60);

// Auth and validation run first so invalid/missing keys and bad payloads
// always get the correct 401/400 response — even from bot user-agents.
$project = verifyPublicKey($conn);
$projectId = (int) $project["id"];

// --- Read + validate body (capped at 64 KB) ----------------------
$input = readJsonBody();

if (!$input) {
    sendResponse(false, "Invalid or missing JSON body", null, 400);
}

$url = substr(trim($input["url"] ?? ""), 0, 2048);
$pageTitle = substr(trim($input["page_title"] ?? ""), 0, 512);
$referrerUrl = substr(trim($input["referrer"] ?? ""), 0, 2048);
$visitorId = substr(trim($input["visitor_id"] ?? ""), 0, 256);
$fingerprint = substr(trim($input["fingerprint"] ?? ""), 0, 256);
$sessionToken = substr(trim($input["session_token"] ?? ""), 0, 256);
$screenResolution = substr(trim($input["screen_resolution"] ?? ""), 0, 20);
$timezone = substr(trim($input["timezone"] ?? ""), 0, 100);

if (empty($url) || empty($visitorId) || empty($sessionToken)) {
    sendResponse(
        false,
        "url, visitor_id and session_token are required",
        null,
        400
    );
}

// Block bots only after auth + validation — ensures auth/validation errors
// are surfaced correctly rather than being swallowed by a 204 early exit.
if (isBot()) {
    http_response_code(204);
    exit();
}

// --- Server-side enrichment --------------------------------------
// Done here, not in the worker, so IP and timestamp are accurate
// at the moment the request arrives — not when the worker picks it up.
$ip = getClientIp();

$payload = [
    "project_id" => $projectId,
    "url" => $url,
    "page_title" => $pageTitle,
    "referrer" => $referrerUrl,
    "visitor_hash" => hash("sha256", $visitorId),
    "fingerprint_hash" => !empty($fingerprint)
        ? hash("sha256", $fingerprint)
        : "",
    "session_token" => $sessionToken,
    "screen_resolution" => $screenResolution,
    "timezone" => $timezone,
    "ip_hash" => hashIp($ip),
    "ip_raw" => $ip,
    "user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? "",
    "referrer_category" => categorizeReferrer($referrerUrl),
    "utm" => extractUtm($url),
    "received_at" => date("Y-m-d H:i:s"),
];

// --- Push to queue and return immediately ------------------------
queuePush("micrologs:pageviews", $payload);

sendResponse(true, "OK", ["queued" => true], 202);
?>