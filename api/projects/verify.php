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

$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    sendResponse(false, "Invalid JSON body", null, 400);
}

$key = trim($input["key"] ?? "");

if (empty($key)) {
    sendResponse(false, "key is required", null, 400);
}

$stmt = $conn->prepare("
    SELECT id, name, allowed_domain,
           CASE WHEN secret_key = ? THEN 'secret' ELSE 'public' END AS key_type
    FROM projects
    WHERE (secret_key = ? OR public_key = ?) AND is_active = 1
    LIMIT 1
");
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
    "allowed_domain" => $project["allowed_domain"],
]);