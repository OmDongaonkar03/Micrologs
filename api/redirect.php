<?php
/*
    ===============================================================
        Micrologs
        Endpoint : GET /api/redirect.php?c={code}
        Auth     : None — public
        Desc     : Log click and redirect to destination (302)
    ===============================================================
*/

include_once __DIR__ . "/../includes/functions.php";

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_redirect", 120, 60);

$code = trim($_GET["c"] ?? "");

if (empty($code)) {
    http_response_code(400);
    echo "Missing link code";
    exit();
}

$stmt = $conn->prepare(
    "SELECT id, project_id, destination_url, is_active FROM tracked_links WHERE code = ? LIMIT 1"
);
$stmt->bind_param("s", $code);
$stmt->execute();
$link = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$link || !$link["is_active"]) {
    http_response_code(404);
    echo "Link not found";
    exit();
}

// ── Validate destination scheme ───────────────────────────────
$scheme = strtolower(parse_url($link["destination_url"], PHP_URL_SCHEME) ?? "");
if (!in_array($scheme, ["http", "https"])) {
    http_response_code(400);
    echo "Invalid destination URL";
    exit();
}

// Log the click
$ip = getClientIp();
$ipHash = hashIp($ip);
$geo = geolocate($ip);
$ua = $_SERVER["HTTP_USER_AGENT"] ?? "";
$device = parseUserAgent($ua);
$referrer = substr($_SERVER["HTTP_REFERER"] ?? "", 0, 2048);
$referrerCategory = categorizeReferrer($referrer);

$locationId = resolveLocation($conn, (int) $link["project_id"], $geo);
$deviceId = resolveDevice($conn, (int) $link["project_id"], $device);

$stmt = $conn->prepare("
    INSERT INTO link_clicks (link_id, project_id, location_id, device_id, referrer_url, referrer_category, ip_hash)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param(
    "iiissss",
    $link["id"],
    $link["project_id"],
    $locationId,
    $deviceId,
    $referrer,
    $referrerCategory,
    $ipHash
);
$stmt->execute();
$stmt->close();

// ── 302 redirect — always hits our server so every click is counted
header("Location: " . $link["destination_url"], true, 302);
exit();
?>