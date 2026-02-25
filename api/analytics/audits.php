<?php
/*
    ===============================================================
        Micrologs
        Endpoint : GET /api/analytics/audits.php
        Auth     : Secret key (X-API-Key header)
        Params   : range, action, actor, limit
        Desc     : List audit log events
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_audits", 60, 60);

$project = verifySecretKey($conn);
$projectId = (int) $project["id"];
$range = parseDateRange();
$limit = min(200, max(1, (int) ($_GET["limit"] ?? 50)));

// Optional filters
$action = trim($_GET["action"] ?? ""); // e.g. user.login
$actor = trim($_GET["actor"] ?? ""); // e.g. user@email.com

// Build query dynamically
$where = "project_id = ? AND created_at BETWEEN ? AND ?";
$params = [$projectId, $range["from"], $range["to"]];
$types = "iss";

if (!empty($action)) {
    $where .= " AND action = ?";
    $params[] = $action;
    $types .= "s";
}
if (!empty($actor)) {
    $where .= " AND actor = ?";
    $params[] = $actor;
    $types .= "s";
}

$params[] = $limit;
$types .= "i";

$stmt = $conn->prepare("
    SELECT id, action, actor, context, created_at
    FROM audit_logs
    WHERE {$where}
    ORDER BY created_at DESC
    LIMIT ?
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$logs = [];
while ($row = $result->fetch_assoc()) {
    $logs[] = [
        "id" => (int) $row["id"],
        "action" => $row["action"],
        "actor" => $row["actor"],
        "context" => $row["context"]
            ? json_decode($row["context"], true)
            : null,
        "created_at" => $row["created_at"],
    ];
}
$stmt->close();

// Top actions in range
$stmt = $conn->prepare("
    SELECT action, COUNT(*) AS count
    FROM audit_logs
    WHERE project_id = ? AND created_at BETWEEN ? AND ?
    GROUP BY action
    ORDER BY count DESC
    LIMIT 20
");
$stmt->bind_param("iss", $projectId, $range["from"], $range["to"]);
$stmt->execute();
$result = $stmt->get_result();
$topActions = [];
while ($row = $result->fetch_assoc()) {
    $topActions[] = [
        "action" => $row["action"],
        "count" => (int) $row["count"],
    ];
}
$stmt->close();

sendResponse(true, "Audit logs fetched successfully", [
    "range" => $range,
    "count" => count($logs),
    "top_actions" => $topActions,
    "logs" => $logs,
]);