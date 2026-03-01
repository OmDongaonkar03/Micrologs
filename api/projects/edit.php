<?php
/*
    ===============================================================
        Micrologs
        Endpoint : POST /api/projects/edit.php
        Desc     : Edit project name and/or allowed domains
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_project_edit", 10, 60);

$adminKey = $_SERVER["HTTP_X_ADMIN_KEY"] ?? "";
if (empty($adminKey) || $adminKey !== ADMIN_KEY) {
    sendResponse(false, "Unauthorized", null, 401);
}

$input = readJsonBody();

if (!$input) {
    sendResponse(false, "Invalid or missing JSON body", null, 400);
}

$projectId = (int) ($input["id"] ?? 0);

if (!$projectId) {
    sendResponse(false, "id is required", null, 400);
}

// ── Fetch existing project ────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT id, name, allowed_domains FROM projects WHERE id = ? LIMIT 1"
);
if (!$stmt) {
    writeLog("ERROR", "project SELECT prepare failed", [
        "error" => $conn->error,
    ]);
    sendResponse(false, "Server error", null, 500);
}
$stmt->bind_param("i", $projectId);
$stmt->execute();
$project = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$project) {
    sendResponse(false, "Project not found", null, 404);
}

// ── Build update fields ───────────────────────────────────────────
$name = isset($input["name"]) ? trim($input["name"]) : null;
$allowedDomains = isset($input["allowed_domains"])
    ? $input["allowed_domains"]
    : null;

if ($name === null && $allowedDomains === null) {
    sendResponse(
        false,
        "Nothing to update. Provide name or allowed_domains.",
        null,
        400
    );
}

$updates = [];
$params = [];
$types = "";

// ── Validate + update name ────────────────────────────────────────
if ($name !== null) {
    if (empty($name)) {
        sendResponse(false, "name cannot be empty", null, 400);
    }
    if (strlen($name) > 100) {
        sendResponse(false, "name must be 100 characters or less", null, 400);
    }
    $updates[] = "name = ?";
    $params[] = $name;
    $types .= "s";
}

// ── Validate + update domains ─────────────────────────────────────
if ($allowedDomains !== null) {
    if (!is_array($allowedDomains) || empty($allowedDomains)) {
        sendResponse(
            false,
            "allowed_domains must be a non-empty array",
            null,
            400
        );
    }

    if (count($allowedDomains) > 20) {
        sendResponse(
            false,
            "Maximum 20 allowed domains per project",
            null,
            400
        );
    }

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

    $updates[] = "allowed_domains = ?";
    $params[] = $domainsStr;
    $types .= "s";
}

$updates[] = "updated_at = ?";
$params[] = date("Y-m-d H:i:s");
$types .= "s";

// ── Execute update ────────────────────────────────────────────────
$params[] = $projectId;
$types .= "i";

$sql = "UPDATE projects SET " . implode(", ", $updates) . " WHERE id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    writeLog("ERROR", "project UPDATE prepare failed", [
        "error" => $conn->error,
        "project_id" => $projectId,
    ]);
    sendResponse(false, "Failed to update project", null, 500);
}
$stmt->bind_param($types, ...$params);

if (!$stmt->execute()) {
    writeLog("ERROR", "project UPDATE execute failed", [
        "error" => $stmt->error,
        "project_id" => $projectId,
    ]);
    sendResponse(false, "Failed to update project", null, 500);
}
$stmt->close();

// ── Fetch updated project ─────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT id, name, allowed_domains, is_active, created_at, updated_at FROM projects WHERE id = ? LIMIT 1"
);
$stmt->bind_param("i", $projectId);
$stmt->execute();
$updated = $stmt->get_result()->fetch_assoc();
$stmt->close();

sendResponse(true, "Project updated successfully", [
    "id" => (int) $updated["id"],
    "name" => $updated["name"],
    "allowed_domains" => explode(",", $updated["allowed_domains"]),
    "is_active" => (bool) $updated["is_active"],
    "updated_at" => $updated["updated_at"],
]);
?>