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

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_project_create", 5, 60);

$adminKey = $_SERVER["HTTP_X_ADMIN_KEY"] ?? "";
if (empty($adminKey) || $adminKey !== ADMIN_KEY) {
    sendResponse(false, "Unauthorized", null, 401);
}

$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    sendResponse(false, "Invalid JSON body", null, 400);
}

$name = trim($input["name"] ?? "");
$allowedDomains = $input["allowed_domains"] ?? [];

if (empty($name)) {
    sendResponse(false, "name is required", null, 400);
}

if (empty($allowedDomains) || !is_array($allowedDomains)) {
    sendResponse(false, "allowed_domains must be a non-empty array", null, 400);
}

if (strlen($name) > 100) {
    sendResponse(false, "name must be 100 characters or less", null, 400);
}

if (count($allowedDomains) > 20) {
    sendResponse(false, "Maximum 20 allowed domains per project", null, 400);
}

// ── Sanitize domains ──────────────────────────────────────────────
$cleaned = [];
foreach ($allowedDomains as $domain) {
    $domain = trim($domain);
    $domain = preg_replace("#^https?://#", "", $domain);
    $domain = rtrim($domain, "/");
    $domain = strtolower($domain);

    if (empty($domain)) {
        continue;
    }

    if (strlen($domain) > 253) {
        sendResponse(false, "Domain '{$domain}' is too long", null, 400);
    }

    $cleaned[] = $domain;
}

if (empty($cleaned)) {
    sendResponse(false, "No valid domains provided", null, 400);
}

$cleaned = array_values(array_unique($cleaned));
$domainsStr = implode(",", $cleaned);

// ── Generate keys ─────────────────────────────────────────────────
$secretKey = bin2hex(random_bytes(32));
$publicKey = bin2hex(random_bytes(16));

$stmt = $conn->prepare(
    "INSERT INTO projects (name, secret_key, public_key, allowed_domains) VALUES (?, ?, ?, ?)"
);
if (!$stmt) {
    writeLog("ERROR", "project INSERT prepare failed", [
        "error" => $conn->error,
        "name" => $name,
    ]);
    sendResponse(false, "Failed to create project", null, 500);
}
$stmt->bind_param("ssss", $name, $secretKey, $publicKey, $domainsStr);

if (!$stmt->execute()) {
    writeLog("ERROR", "project INSERT execute failed", [
        "error" => $stmt->error,
        "name" => $name,
    ]);
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
        "allowed_domains" => $cleaned,
        "secret_key" => $secretKey,
        "public_key" => $publicKey,
    ]
);
?>