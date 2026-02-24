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

// Block bots before doing anything
if (isBot()) {
    http_response_code(204);
    exit();
}

$project = verifyPublicKey($conn);
$projectId = (int) $project["id"];

$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    sendResponse(false, "Invalid JSON body", null, 400);
}

// Inputs
$url = substr(trim($input["url"] ?? ""), 0, 2048);
$pageTitle = substr(trim($input["page_title"] ?? ""), 0, 512);
$referrerUrl = substr(trim($input["referrer"] ?? ""), 0, 2048);
$visitorId = trim($input["visitor_id"] ?? "");
$fingerprint = trim($input["fingerprint"] ?? "");
$sessionToken = trim($input["session_token"] ?? "");
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

// Hashing
$visitorHash = hash("sha256", $visitorId);
$fingerprintHash = !empty($fingerprint) ? hash("sha256", $fingerprint) : "";

// Geolocation (server-side only)
$ip = getClientIp();
$ipHash = hashIp($ip);
$geo = geolocate($ip);

// Device
$ua = $_SERVER["HTTP_USER_AGENT"] ?? "";
$device = parseUserAgent($ua);

// Referrer + UTM
$referrerCategory = categorizeReferrer($referrerUrl);
$utm = extractUtm($url);

// Resolve visitor (hybrid: cookie + fingerprint fallback)
$stmt = $conn->prepare(
    "SELECT id FROM visitors WHERE project_id = ? AND visitor_hash = ? LIMIT 1"
);
$stmt->bind_param("is", $projectId, $visitorHash);
$stmt->execute();
$visitor = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$visitor && !empty($fingerprintHash)) {
    $stmt = $conn->prepare(
        "SELECT id FROM visitors WHERE project_id = ? AND fingerprint_hash = ? LIMIT 1"
    );
    $stmt->bind_param("is", $projectId, $fingerprintHash);
    $stmt->execute();
    $visitor = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($visitor) {
        // Re-associate cookie hash (cookie was cleared, fingerprint matched)
        $stmt = $conn->prepare(
            "UPDATE visitors SET visitor_hash = ?, last_seen = NOW() WHERE id = ?"
        );
        $stmt->bind_param("si", $visitorHash, $visitor["id"]);
        $stmt->execute();
        $stmt->close();
    }
}

if (!$visitor) {
    $stmt = $conn->prepare(
        "INSERT INTO visitors (project_id, visitor_hash, fingerprint_hash) VALUES (?, ?, ?)"
    );
    $stmt->bind_param("iss", $projectId, $visitorHash, $fingerprintHash);
    $stmt->execute();
    $visitorDbId = (int) $conn->insert_id;
    $stmt->close();
} else {
    $visitorDbId = (int) $visitor["id"];
    $stmt = $conn->prepare(
        "UPDATE visitors SET last_seen = NOW() WHERE id = ?"
    );
    $stmt->bind_param("i", $visitorDbId);
    $stmt->execute();
    $stmt->close();
}

// Resolve session
$sessionTimeout = 1800; // 30 minutes

$stmt = $conn->prepare(
    "SELECT id, last_activity FROM sessions WHERE session_token = ? LIMIT 1"
);
$stmt->bind_param("s", $sessionToken);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$session) {
    $stmt = $conn->prepare(
        "INSERT INTO sessions (project_id, visitor_id, session_token) VALUES (?, ?, ?)"
    );
    $stmt->bind_param("iis", $projectId, $visitorDbId, $sessionToken);
    $stmt->execute();
    $sessionId = (int) $conn->insert_id;
    $stmt->close();
} else {
    $lastActivity = strtotime($session["last_activity"]);

    if (time() - $lastActivity > $sessionTimeout) {
        // Session expired — open a new one
        $newToken = bin2hex(random_bytes(32));
        $stmt = $conn->prepare(
            "INSERT INTO sessions (project_id, visitor_id, session_token) VALUES (?, ?, ?)"
        );
        $stmt->bind_param("iis", $projectId, $visitorDbId, $newToken);
        $stmt->execute();
        $sessionId = (int) $conn->insert_id;
        $stmt->close();
    } else {
        $sessionId = (int) $session["id"];
        $stmt = $conn->prepare(
            "UPDATE sessions SET last_activity = NOW() WHERE id = ?"
        );
        $stmt->bind_param("i", $sessionId);
        $stmt->execute();
        $stmt->close();
    }
}

// Deduplication - same visitor + same URL within 5 minutes
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

// Resolve location + device
$locationId = resolveLocation($conn, $projectId, $geo);
$deviceId = resolveDevice($conn, $projectId, $device);

// Bounce flag — flip to 0 on 2nd+ pageview in session
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt FROM pageviews WHERE session_id = ?"
);
$stmt->bind_param("i", $sessionId);
$stmt->execute();
$count = (int) $stmt->get_result()->fetch_assoc()["cnt"];
$stmt->close();

if ($count >= 1) {
    $stmt = $conn->prepare("UPDATE sessions SET is_bounced = 0 WHERE id = ?");
    $stmt->bind_param("i", $sessionId);
    $stmt->execute();
    $stmt->close();
}

// Insert pageview
$stmt = $conn->prepare("
    INSERT INTO pageviews
        (project_id, session_id, visitor_id, location_id, device_id,
         url, page_title, referrer_url, referrer_category,
         utm_source, utm_medium, utm_campaign, utm_content, utm_term,
         screen_resolution, timezone)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
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
    sendResponse(false, "Failed to record pageview", null, 500);
}
$stmt->close();

sendResponse(true, "OK", ["counted" => true]);