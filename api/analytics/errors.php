<?php
/*
    ===============================================================
        Micrologs
        Endpoint : GET /api/analytics/errors.php
        Auth     : Secret key (X-API-Key header)
        Params   : range, status, severity, environment
        Desc     : List error groups with occurrence counts
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_errors_analytics", 60, 60);

$project = verifySecretKey($conn);
$projectId = (int) $project["id"];
$range = parseDateRange();
$limit = min(100, max(1, (int) ($_GET["limit"] ?? 50)));

// Optional filters
$status = in_array($_GET["status"] ?? "", ["open", "resolved", "ignored"])
    ? $_GET["status"]
    : null;
$severity = in_array($_GET["severity"] ?? "", [
    "info",
    "warning",
    "error",
    "critical",
])
    ? $_GET["severity"]
    : null;
$environment = in_array($_GET["environment"] ?? "", [
    "production",
    "staging",
    "development",
])
    ? $_GET["environment"]
    : null;

// Build query dynamically
$where = "project_id = ? AND last_seen BETWEEN ? AND ?";
$params = [$projectId, $range["from"], $range["to"]];
$types = "iss";

if ($status) {
    $where .= " AND status = ?";
    $params[] = $status;
    $types .= "s";
}
if ($severity) {
    $where .= " AND severity = ?";
    $params[] = $severity;
    $types .= "s";
}
if ($environment) {
    $where .= " AND environment = ?";
    $params[] = $environment;
    $types .= "s";
}

$params[] = $limit;
$types .= "i";

$stmt = $conn->prepare("
    SELECT id, error_type, message, file, line, severity, environment,
           status, occurrence_count, first_seen, last_seen
    FROM error_groups
    WHERE {$where}
    ORDER BY last_seen DESC
    LIMIT ?
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$errors = [];
while ($row = $result->fetch_assoc()) {
    $errors[] = [
        "id" => (int) $row["id"],
        "error_type" => $row["error_type"],
        "message" => $row["message"],
        "file" => $row["file"],
        "line" => $row["line"] ? (int) $row["line"] : null,
        "severity" => $row["severity"],
        "environment" => $row["environment"],
        "status" => $row["status"],
        "occurrence_count" => (int) $row["occurrence_count"],
        "first_seen" => $row["first_seen"],
        "last_seen" => $row["last_seen"],
    ];
}
$stmt->close();

// Summary counts
$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'open') AS open,
        SUM(status = 'resolved') AS resolved,
        SUM(status = 'ignored') AS ignored,
        SUM(severity = 'critical') AS critical,
        SUM(severity = 'error') AS error
    FROM error_groups
    WHERE project_id = ? AND last_seen BETWEEN ? AND ?
");
$stmt->bind_param("iss", $projectId, $range["from"], $range["to"]);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

sendResponse(true, "Errors fetched successfully", [
    "range" => $range,
    "summary" => [
        "total" => (int) $summary["total"],
        "open" => (int) $summary["open"],
        "resolved" => (int) $summary["resolved"],
        "ignored" => (int) $summary["ignored"],
        "critical" => (int) $summary["critical"],
        "error" => (int) $summary["error"],
    ],
    "count" => count($errors),
    "errors" => $errors,
]);