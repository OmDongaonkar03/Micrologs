<?php
/*
    ===============================================================
        Micrologs
        Endpoint : GET /api/analytics/link-detail.php?code=abc12xyz&range=30d
        Auth     : Secret key (X-API-Key header)
        Desc     : Full stats for a single tracked link
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_link_detail", 60, 60);

$project = verifySecretKey($conn);
$projectId = (int) $project["id"];
$range = parseDateRange();

$code = trim($_GET["code"] ?? "");

if (empty($code)) {
    sendResponse(false, "code is required", null, 400);
}

// Get link
$stmt = $conn->prepare(
    "SELECT id, code, label, destination_url, created_at FROM tracked_links WHERE code = ? AND project_id = ? LIMIT 1"
);
$stmt->bind_param("si", $code, $projectId);
$stmt->execute();
$link = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$link) {
    sendResponse(false, "Link not found", null, 404);
}

$linkId = (int) $link["id"];

// Totals
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total_clicks, COUNT(DISTINCT ip_hash) AS unique_clicks
    FROM link_clicks
    WHERE link_id = ? AND created_at BETWEEN ? AND ?
");
$stmt->bind_param("iss", $linkId, $range["from"], $range["to"]);
$stmt->execute();
$totals = $stmt->get_result()->fetch_assoc();
$stmt->close();

// By country
$stmt = $conn->prepare("
    SELECT l.country, l.country_code, COUNT(*) AS clicks
    FROM link_clicks lc
    INNER JOIN locations l ON l.id = lc.location_id
    WHERE lc.link_id = ? AND lc.created_at BETWEEN ? AND ?
    GROUP BY l.country, l.country_code
    ORDER BY clicks DESC
");
$stmt->bind_param("iss", $linkId, $range["from"], $range["to"]);
$stmt->execute();
$result = $stmt->get_result();
$byCountry = [];
while ($row = $result->fetch_assoc()) {
    $byCountry[] = [
        "country" => $row["country"],
        "country_code" => $row["country_code"],
        "clicks" => (int) $row["clicks"],
    ];
}
$stmt->close();

// By referrer
$stmt = $conn->prepare("
    SELECT referrer_category, COUNT(*) AS clicks
    FROM link_clicks
    WHERE link_id = ? AND created_at BETWEEN ? AND ?
    GROUP BY referrer_category
    ORDER BY clicks DESC
");
$stmt->bind_param("iss", $linkId, $range["from"], $range["to"]);
$stmt->execute();
$result = $stmt->get_result();
$byReferrer = [];
while ($row = $result->fetch_assoc()) {
    $byReferrer[] = [
        "category" => $row["referrer_category"],
        "clicks" => (int) $row["clicks"],
    ];
}
$stmt->close();

// By device
$stmt = $conn->prepare("
    SELECT d.device_type, COUNT(*) AS clicks
    FROM link_clicks lc
    INNER JOIN devices d ON d.id = lc.device_id
    WHERE lc.link_id = ? AND lc.created_at BETWEEN ? AND ?
    GROUP BY d.device_type
    ORDER BY clicks DESC
");
$stmt->bind_param("iss", $linkId, $range["from"], $range["to"]);
$stmt->execute();
$result = $stmt->get_result();
$byDevice = [];
while ($row = $result->fetch_assoc()) {
    $byDevice[] = [
        "device_type" => $row["device_type"],
        "clicks" => (int) $row["clicks"],
    ];
}
$stmt->close();

// Clicks over time
$stmt = $conn->prepare("
    SELECT DATE(created_at) AS date, COUNT(*) AS clicks
    FROM link_clicks
    WHERE link_id = ? AND created_at BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY date ASC
");
$stmt->bind_param("iss", $linkId, $range["from"], $range["to"]);
$stmt->execute();
$result = $stmt->get_result();
$overTime = [];
while ($row = $result->fetch_assoc()) {
    $overTime[] = ["date" => $row["date"], "clicks" => (int) $row["clicks"]];
}
$stmt->close();

sendResponse(true, "Link detail fetched successfully", [
    "range" => $range,
    "link" => $link,
    "total_clicks" => (int) ($totals["total_clicks"] ?? 0),
    "unique_clicks" => (int) ($totals["unique_clicks"] ?? 0),
    "by_country" => $byCountry,
    "by_referrer" => $byReferrer,
    "by_device" => $byDevice,
    "over_time" => $overTime,
]);