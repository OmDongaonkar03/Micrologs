<?php
/*
    ===============================================================
        Micrologs
        File : workers/audit-worker.php
        Desc : Pops audit jobs from the Valkey queue and writes
               them to audit_logs. Single INSERT per message —
               the simplest worker in the system.

        Local : php workers/audit-worker.php
        Prod  : managed by Supervisor
    ===============================================================
*/

define("RUNNING_AS_WORKER", true);

require_once __DIR__ . "/../includes/functions.php";

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    echo "[audit-worker] DB connection failed: " . $conn->connect_error . "\n";
    exit(1);
}
$conn->set_charset("utf8mb4");

$tz = APP_TIMEZONE;
$stmt = $conn->prepare("SET time_zone = ?");
$stmt->bind_param("s", $tz);
$stmt->execute();
$stmt->close();

echo "[audit-worker] started — listening on micrologs:audits\n";

while (true) {
    $payload = queuePop("micrologs:audits");

    if ($payload === null) {
        continue;
    }

    try {
        processAudit($conn, $payload);
        echo "[audit-worker] ok  project=" .
            ($payload["project_id"] ?? "?") .
            "  action=" .
            ($payload["action"] ?? "?") .
            "\n";
    } catch (\Exception $e) {
        writeLog("error", "audit-worker: " . $e->getMessage(), [
            "project_id" => $payload["project_id"] ?? null,
            "action" => $payload["action"] ?? null,
        ]);
        echo "[audit-worker] err " . $e->getMessage() . "\n";
        // Don't exit — keep processing
    }
}

function processAudit(\mysqli $conn, array $p): void
{
    $projectId = (int) $p["project_id"];
    $action = $p["action"];
    $actor = $p["actor"] ?? "";
    $ipHash = $p["ip_hash"] ?? "";
    $context = $p["context"] ?? null; // already encoded JSON string or null
    $receivedAt = $p["received_at"];

    $stmt = $conn->prepare("
        INSERT INTO audit_logs (project_id, action, actor, ip_hash, context, created_at)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        throw new \Exception(
            "audit_logs INSERT prepare failed: " . $conn->error
        );
    }

    $stmt->bind_param(
        "isssss",
        $projectId,
        $action,
        $actor,
        $ipHash,
        $context,
        $receivedAt
    );

    if (!$stmt->execute()) {
        throw new \Exception(
            "audit_logs INSERT execute failed: " . $stmt->error
        );
    }

    $stmt->close();
}
?>