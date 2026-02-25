<?php
/*
    ===============================================================
        Micrologs
        Endpoint : POST /api/track/error.php
        Auth     : Public key (X-API-Key header)
        Desc     : Ingest errors from JS snippet or any backend
    ===============================================================
*/

include_once __DIR__ . "/../../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    sendResponse(false, "Method not allowed", null, 405);
}

rateLimitOrBlock($_SERVER["REMOTE_ADDR"] . "_errors", 60, 60);

$project = verifyPublicKey($conn);
$projectId = (int) $project["id"];

$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    sendResponse(false, "Invalid JSON body", null, 400);
}

// Inputs
$message = substr(trim($input["message"] ?? ""), 0, 1024);
$errorType = substr(trim($input["error_type"] ?? "Unknown"), 0, 100);
$file = substr(trim($input["file"] ?? ""), 0, 512);
$line = isset($input["line"]) ? (int) $input["line"] : null;
$stackTrace = isset($input["stack"])
    ? substr(trim($input["stack"]), 0, 65535)
    : null;
$url = substr(trim($input["url"] ?? ""), 0, 2048);
$severity = in_array($input["severity"] ?? "", [
    "info",
    "warning",
    "error",
    "critical",
])
    ? $input["severity"]
    : "error";
$environment = in_array($input["environment"] ?? "", [
    "production",
    "staging",
    "development",
])
    ? $input["environment"]
    : "production";
$context =
    isset($input["context"]) && is_array($input["context"])
        ? json_encode($input["context"])
        : null;

if (empty($message)) {
    sendResponse(false, "message is required", null, 400);
}

// Fingerprint - group same errors together
$fingerprint = hash(
    "sha256",
    $projectId . $errorType . $message . $file . ($line ?? "")
);

// Geolocation + Device
$ip = getClientIp();
$geo = geolocate($ip);
$ua = $_SERVER["HTTP_USER_AGENT"] ?? "";
$device = parseUserAgent($ua);
$locationId = resolveLocation($conn, $projectId, $geo);
$deviceId = resolveDevice($conn, $projectId, $device);

// Upsert error group
$now = date("Y-m-d H:i:s");

$stmt = $conn->prepare(
    "SELECT id FROM error_groups WHERE project_id = ? AND fingerprint = ? LIMIT 1"
);
$stmt->bind_param("is", $projectId, $fingerprint);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($group) {
    // Existing group - increment count and update last_seen
    $groupId = (int) $group["id"];
    $stmt = $conn->prepare("
        UPDATE error_groups
        SET occurrence_count = occurrence_count + 1,
            last_seen = ?,
            severity = ?,
            status = IF(status = 'resolved', 'open', status)
        WHERE id = ?
    ");
    $stmt->bind_param("ssi", $now, $severity, $groupId);
    $stmt->execute();
    $stmt->close();
} else {
    // New error group
    $stmt = $conn->prepare("
        INSERT INTO error_groups
            (project_id, fingerprint, error_type, message, file, line, severity, environment, first_seen, last_seen)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "issssissss",
        $projectId,
        $fingerprint,
        $errorType,
        $message,
        $file,
        $line,
        $severity,
        $environment,
        $now,
        $now
    );
    $stmt->execute();
    $groupId = (int) $conn->insert_id;
    $stmt->close();
}

// Insert error event
$stmt = $conn->prepare("
    INSERT INTO error_events
        (group_id, project_id, location_id, device_id, stack_trace, url, environment, severity, context)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param(
    "iiisssss",
    $groupId,
    $projectId,
    $locationId,
    $deviceId,
    $stackTrace,
    $url,
    $environment,
    $severity,
    $context
);

if (!$stmt->execute()) {
    sendResponse(false, "Failed to record error", null, 500);
}
$stmt->close();

sendResponse(true, "OK", ["group_id" => $groupId]);