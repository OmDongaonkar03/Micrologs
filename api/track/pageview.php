<?php
/*
    ===============================================================
        Micrologs
        Endpoint : POST /api/track/pageview.php
        Auth     : Public key (X-API-Key header)
        Desc     : Ingest a pageview from the JS snippet
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_pageview", 60, 60);

// Block bots before doing anything
if (isBot()) {
    http_response_code(204);
    exit();
}

$project   = verifyPublicKey($conn);
$projectId = (int) $project["id"];

// --- Read + validate body (capped at 64 KB) ----------------------
$input = readJsonBody();

if (!$input) {
    sendResponse(false, "Invalid or missing JSON body", null, 400);
}

$url              = substr(trim($input["url"]               ?? ""), 0, 2048);
$pageTitle        = substr(trim($input["page_title"]        ?? ""), 0, 512);
$referrerUrl      = substr(trim($input["referrer"]          ?? ""), 0, 2048);
$visitorId        = substr(trim($input["visitor_id"]        ?? ""), 0, 256);
$fingerprint      = substr(trim($input["fingerprint"]       ?? ""), 0, 256);
$sessionToken     = substr(trim($input["session_token"]     ?? ""), 0, 256);
$screenResolution = substr(trim($input["screen_resolution"] ?? ""), 0, 20);
$timezone         = substr(trim($input["timezone"]          ?? ""), 0, 100);

if (empty($url) || empty($visitorId) || empty($sessionToken)) {
    sendResponse(false, "url, visitor_id and session_token are required", null, 400);
}

// --- Hashing -----------------------------------------------------
$visitorHash     = hash("sha256", $visitorId);
$fingerprintHash = !empty($fingerprint) ? hash("sha256", $fingerprint) : "";

// --- Server-side enrichment --------------------------------------
$ip               = getClientIp();
$ipHash           = hashIp($ip);
$geo              = geolocate($ip);
$ua               = $_SERVER["HTTP_USER_AGENT"] ?? "";
$device           = parseUserAgent($ua);
$referrerCategory = categorizeReferrer($referrerUrl);
$utm              = extractUtm($url);

// === VISITOR (upsert) ============================================
// Single query: insert new visitor, or update last_seen if exists.
// On duplicate (project_id + visitor_hash), refresh fingerprint_hash
// only if it was previously empty (e.g. first visit had no fingerprint).
$stmt = $conn->prepare("
    INSERT INTO visitors (project_id, visitor_hash, fingerprint_hash)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE
        fingerprint_hash = IF(
            fingerprint_hash = '' AND VALUES(fingerprint_hash) != '',
            VALUES(fingerprint_hash),
            fingerprint_hash
        ),
        last_seen = NOW()
");
if (!$stmt) {
    writeLog("ERROR", "visitor upsert prepare failed", ["error" => $conn->error]);
    sendResponse(false, "Server error", null, 500);
}
$stmt->bind_param("iss", $projectId, $visitorHash, $fingerprintHash);
if (!$stmt->execute()) {
    writeLog("ERROR", "visitor upsert execute failed", ["error" => $stmt->error]);
    sendResponse(false, "Server error", null, 500);
}
$visitorDbId = (int) $conn->insert_id;
$stmt->close();

// insert_id is 0 on UPDATE (existing row) — fetch the real id
if ($visitorDbId === 0) {
    $stmt = $conn->prepare(
        "SELECT id FROM visitors WHERE project_id = ? AND visitor_hash = ? LIMIT 1"
    );
    $stmt->bind_param("is", $projectId, $visitorHash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $visitorDbId = (int) $row["id"];
    } elseif (!empty($fingerprintHash)) {
        // Cookie was cleared — fingerprint fallback, then re-link visitor_hash
        $stmt = $conn->prepare(
            "SELECT id FROM visitors WHERE project_id = ? AND fingerprint_hash = ? LIMIT 1"
        );
        $stmt->bind_param("is", $projectId, $fingerprintHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $visitorDbId = (int) $row["id"];
            $stmt = $conn->prepare(
                "UPDATE visitors SET visitor_hash = ?, last_seen = NOW() WHERE id = ?"
            );
            $stmt->bind_param("si", $visitorHash, $visitorDbId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if ($visitorDbId === 0) {
    writeLog("ERROR", "could not resolve visitor after upsert", [
        "project_id"   => $projectId,
        "visitor_hash" => $visitorHash,
    ]);
    sendResponse(false, "Server error", null, 500);
}

// === SESSION (upsert) ============================================
$stmt = $conn->prepare("
    INSERT INTO sessions (project_id, visitor_id, session_token)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE
        last_activity = NOW()
");
if (!$stmt) {
    writeLog("ERROR", "session upsert prepare failed", ["error" => $conn->error]);
    sendResponse(false, "Server error", null, 500);
}
$stmt->bind_param("iis", $projectId, $visitorDbId, $sessionToken);
if (!$stmt->execute()) {
    writeLog("ERROR", "session upsert execute failed", ["error" => $stmt->error]);
    sendResponse(false, "Server error", null, 500);
}
$sessionId = (int) $conn->insert_id;
$stmt->close();

if ($sessionId === 0) {
    $stmt = $conn->prepare(
        "SELECT id FROM sessions WHERE session_token = ? LIMIT 1"
    );
    $stmt->bind_param("s", $sessionToken);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $sessionId = $row ? (int) $row["id"] : 0;
}

if ($sessionId === 0) {
    writeLog("ERROR", "could not resolve session after upsert", [
        "project_id"    => $projectId,
        "session_token" => $sessionToken,
    ]);
    sendResponse(false, "Server error", null, 500);
}

// === DEDUPLICATION ===============================================
// Same visitor + same URL within 5 minutes = don't double-count
$stmt = $conn->prepare("
    SELECT id FROM pageviews
    WHERE project_id = ? AND visitor_id = ? AND url = ?
      AND created_at >= DATE_SUB(NOW(), INTERVAL 300 SECOND)
    LIMIT 1
");
$stmt->bind_param("iis", $projectId, $visitorDbId, $url);
$stmt->execute();
$duplicate = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($duplicate) {
    sendResponse(true, "OK", ["counted" => false]);
}

// === LOCATION + DEVICE ===========================================
$locationId = resolveLocation($conn, $projectId, $geo);
$deviceId   = resolveDevice($conn, $projectId, $device);

// === INSERT PAGEVIEW =============================================
$stmt = $conn->prepare("
    INSERT INTO pageviews
        (project_id, session_id, visitor_id, location_id, device_id,
         url, page_title, referrer_url, referrer_category,
         utm_source, utm_medium, utm_campaign, utm_content, utm_term,
         screen_resolution, timezone)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
if (!$stmt) {
    writeLog("ERROR", "pageview INSERT prepare failed", [
        "error"      => $conn->error,
        "project_id" => $projectId,
    ]);
    sendResponse(false, "Failed to record pageview", null, 500);
}
$stmt->bind_param(
    "iiiiisssssssssss",
    $projectId,
    $sessionId,
    $visitorDbId,
    $locationId,
    $deviceId,
    $url,
    $pageTitle,
    $referrerUrl,
    $referrerCategory,
    $utm["utm_source"],
    $utm["utm_medium"],
    $utm["utm_campaign"],
    $utm["utm_content"],
    $utm["utm_term"],
    $screenResolution,
    $timezone
);

if (!$stmt->execute()) {
    writeLog("ERROR", "pageview INSERT execute failed", [
        "error"      => $stmt->error,
        "project_id" => $projectId,
    ]);
    sendResponse(false, "Failed to record pageview", null, 500);
}
$stmt->close();

// === BOUNCE FLAG =================================================
// Flip is_bounced = 0 only if this session now has more than 1 pageview.
// Runs as a single conditional UPDATE — no separate COUNT query needed.
$stmt = $conn->prepare("
    UPDATE sessions
    SET is_bounced = 0
    WHERE id = ?
      AND is_bounced = 1
      AND (SELECT COUNT(*) FROM pageviews WHERE session_id = ?) > 1
");
$stmt->bind_param("ii", $sessionId, $sessionId);
$stmt->execute();
$stmt->close();

sendResponse(true, "OK", ["counted" => true]);
?>
