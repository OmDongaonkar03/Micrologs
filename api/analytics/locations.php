<?php
/*
    ===============================================================
        Micrologs
        Endpoint : GET /api/analytics/locations.php?range=30d
        Auth     : Secret key (X-API-Key header)
        Desc     : Visitors by country, region, city
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_locations", 60, 60);

$project = verifySecretKey($conn);
$projectId = (int) $project["id"];
$range = parseDateRange();

// By country
$stmt = $conn->prepare("
    SELECT l.country, l.country_code,
           COUNT(*) AS pageviews,
           COUNT(DISTINCT p.visitor_id) AS unique_visitors
    FROM pageviews p
    INNER JOIN locations l ON l.id = p.location_id
    WHERE p.project_id = ? AND p.created_at BETWEEN ? AND ?
    GROUP BY l.country, l.country_code
    ORDER BY pageviews DESC
");
$stmt->bind_param("iss", $projectId, $range["from"], $range["to"]);
$stmt->execute();
$result = $stmt->get_result();
$byCountry = [];
while ($row = $result->fetch_assoc()) {
    $byCountry[] = [
        "country" => $row["country"],
        "country_code" => $row["country_code"],
        "pageviews" => (int) $row["pageviews"],
        "unique_visitors" => (int) $row["unique_visitors"],
    ];
}
$stmt->close();

// By region
$stmt = $conn->prepare("
    SELECT l.country, l.country_code, l.region,
           COUNT(*) AS pageviews
    FROM pageviews p
    INNER JOIN locations l ON l.id = p.location_id
    WHERE p.project_id = ? AND p.created_at BETWEEN ? AND ?
    AND l.region != ''
    GROUP BY l.country, l.country_code, l.region
    ORDER BY pageviews DESC
    LIMIT 50
");
$stmt->bind_param("iss", $projectId, $range["from"], $range["to"]);
$stmt->execute();
$result = $stmt->get_result();
$byRegion = [];
while ($row = $result->fetch_assoc()) {
    $byRegion[] = [
        "country" => $row["country"],
        "country_code" => $row["country_code"],
        "region" => $row["region"],
        "pageviews" => (int) $row["pageviews"],
    ];
}
$stmt->close();

// By city
$stmt = $conn->prepare("
    SELECT l.city, l.region, l.country_code,
           COUNT(*) AS pageviews
    FROM pageviews p
    INNER JOIN locations l ON l.id = p.location_id
    WHERE p.project_id = ? AND p.created_at BETWEEN ? AND ?
    AND l.city != ''
    GROUP BY l.city, l.region, l.country_code
    ORDER BY pageviews DESC
    LIMIT 50
");
$stmt->bind_param("iss", $projectId, $range["from"], $range["to"]);
$stmt->execute();
$result = $stmt->get_result();
$byCity = [];
while ($row = $result->fetch_assoc()) {
    $byCity[] = [
        "city" => $row["city"],
        "region" => $row["region"],
        "country_code" => $row["country_code"],
        "pageviews" => (int) $row["pageviews"],
    ];
}
$stmt->close();

sendResponse(true, "Locations fetched successfully", [
    "range" => $range,
    "by_country" => $byCountry,
    "by_region" => $byRegion,
    "by_city" => $byCity,
]);