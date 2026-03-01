<?php
/*
    ===============================================================
        Micrologs
        Endpoint : GET /api/projects/list.php
        Auth     : Admin key (X-Admin-Key header)
        Desc     : List all projects with summary stats
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_project_list", 30, 60);

$adminKey = $_SERVER["HTTP_X_ADMIN_KEY"] ?? "";
if (empty($adminKey) || $adminKey !== ADMIN_KEY) {
    sendResponse(false, "Unauthorized", null, 401);
}

$stmt = $conn->prepare("
    SELECT p.id, p.name, p.public_key, p.allowed_domains, p.is_active,
           p.created_at, p.updated_at,
           COUNT(DISTINCT tl.id)  AS total_links,
           COUNT(DISTINCT pv.id)  AS total_pageviews,
           COUNT(DISTINCT el.id)  AS total_errors
    FROM projects p
    LEFT JOIN tracked_links tl ON tl.project_id = p.id
    LEFT JOIN pageviews pv      ON pv.project_id = p.id
    LEFT JOIN error_logs el     ON el.project_id = p.id
    GROUP BY p.id
    ORDER BY p.created_at DESC
");
if (!$stmt) {
    writeLog("ERROR", "projects list SELECT prepare failed", [
        "error" => $conn->error,
    ]);
    sendResponse(false, "Server error", null, 500);
}
$stmt->execute();
$result = $stmt->get_result();

$projects = [];
while ($row = $result->fetch_assoc()) {
    $projects[] = [
        "id" => (int) $row["id"],
        "name" => $row["name"],
        "public_key" => $row["public_key"],
        "allowed_domains" => explode(",", $row["allowed_domains"]),
        "is_active" => (bool) $row["is_active"],
        "stats" => [
            "total_links" => (int) $row["total_links"],
            "total_pageviews" => (int) $row["total_pageviews"],
            "total_errors" => (int) $row["total_errors"],
        ],
        "created_at" => $row["created_at"],
        "updated_at" => $row["updated_at"],
    ];
}
$stmt->close();

sendResponse(true, "Projects fetched successfully", [
    "count" => count($projects),
    "projects" => $projects,
]);
?>