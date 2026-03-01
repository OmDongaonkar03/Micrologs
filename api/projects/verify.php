<?php
/*
    ===============================================================
        Micrologs
        Endpoint : POST /api/projects/verify.php
        Desc     : Verify if an API key is valid
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_project_verify", 20, 60);

$input = readJsonBody();

if (!$input) {
    sendResponse(false, "Invalid or missing JSON body", null, 400);
}

$key = trim($input["key"] ?? "");

if (empty($key)) {
    sendResponse(false, "key is required", null, 400);
}

$stmt = $conn->prepare("
    SELECT id, name, allowed_domains,
           CASE WHEN secret_key = ? THEN 'secret' ELSE 'public' END AS key_type
    FROM projects
    WHERE (secret_key = ? OR public_key = ?) AND is_active = 1
    LIMIT 1
");
if (!$stmt) {
    writeLog("ERROR", "verify key SELECT prepare failed", [
        "error" => $conn->error,
    ]);
    sendResponse(false, "Server error", null, 500);
}
$stmt->bind_param("sss", $key, $key, $key);
$stmt->execute();
$project = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$project) {
    sendResponse(true, "Key verified", ["valid" => false]);
}

sendResponse(true, "Key verified", [
    "valid" => true,
    "key_type" => $project["key_type"],
    "project_name" => $project["name"],
    "allowed_domains" => $project["allowed_domains"],
]);
?>