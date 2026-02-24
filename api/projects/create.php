<?php
/*
    ===============================================================
        Micrologs
        Endpoint : POST /api/projects/create.php
        Desc     : Create a new project, returns API keys
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

$name = trim($input["name"] ?? "");
$allowedDomain = trim($input["allowed_domain"] ?? "");

if (empty($name) || empty($allowedDomain)) {
    sendResponse(false, "name and allowed_domain are required", null, 400);
}

if (strlen($name) > 100) {
    sendResponse(false, "name must be 100 characters or less", null, 400);
}

// Strip protocol from domain if provided
$allowedDomain = preg_replace("#^https?://#", "", $allowedDomain);
$allowedDomain = rtrim($allowedDomain, "/");

// Generate keys
$secretKey = bin2hex(random_bytes(32)); // 64 char
$publicKey = bin2hex(random_bytes(16)); // 32 char

$stmt = $conn->prepare(
    "INSERT INTO projects (name, secret_key, public_key, allowed_domain) VALUES (?, ?, ?, ?)"
);
$stmt->bind_param("ssss", $name, $secretKey, $publicKey, $allowedDomain);

if (!$stmt->execute()) {
    sendResponse(false, "Failed to create project", null, 500);
}

$id = (int) $conn->insert_id;
$stmt->close();

sendResponse(
    true,
    "Project created. Store your secret_key safely — it will not be shown again.",
    [
        "id" => $id,
        "name" => $name,
        "allowed_domain" => $allowedDomain,
        "secret_key" => $secretKey,
        "public_key" => $publicKey,
    ]
);