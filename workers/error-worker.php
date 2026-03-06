<?php
/*
    ===============================================================
        Micrologs
        File : workers/error-worker.php
        Desc : Pops error jobs from the Valkey queue, upserts the
               error group, inserts the event, and busts the
               errors cache so fresh data surfaces immediately.

        Local : php workers/error-worker.php
        Prod  : managed by Supervisor
    ===============================================================
*/
require_once __DIR__ . "/../includes/functions.php";

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    echo "[error-worker] DB connection failed: " . $conn->connect_error . "\n";
    exit(1);
}
$conn->set_charset("utf8mb4");

$tz = APP_TIMEZONE;
$stmt = $conn->prepare("SET time_zone = ?");
$stmt->bind_param("s", $tz);
$stmt->execute();
$stmt->close();

echo "[error-worker] started — listening on micrologs:errors\n";

while (true) {
    $payload = queuePop("micrologs:errors");

    if ($payload === null) {
        continue;
    }

    try {
        $groupId = processError($conn, $payload);
        echo "[error-worker] ok  project=" .
            ($payload["project_id"] ?? "?") .
            "  group=$groupId\n";
    } catch (\Exception $e) {
        writeLog("error", "error-worker: " . $e->getMessage(), [
            "project_id" => $payload["project_id"] ?? null,
            "fingerprint" => $payload["fingerprint"] ?? null,
        ]);
        echo "[error-worker] err " . $e->getMessage() . "\n";
        // Don't exit — keep processing
    }
}

function processError(\mysqli $conn, array $p): int
{
    $projectId = (int) $p["project_id"];
    $fingerprint = $p["fingerprint"];
    $severity = $p["severity"];
    $environment = $p["environment"];
    $receivedAt = $p["received_at"];

    // === ERROR GROUP UPSERT ======================================
    // Single query — no SELECT then INSERT race condition.
    //
    // On INSERT (new error): creates the group with count = 1
    // On DUPLICATE KEY (existing error):
    //   - increments occurrence_count
    //   - updates last_seen to now
    //   - updates severity to the latest value
    //   - reopens the group if it was previously resolved
    //     (a resolved error firing again = new problem, not old one)
    //
    // The unique constraint on (project_id, fingerprint) makes this safe
    // even with multiple workers running in parallel.
    $stmt = $conn->prepare("
        INSERT INTO error_groups
            (project_id, fingerprint, error_type, message, file, line,
             severity, environment, first_seen, last_seen)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            occurrence_count = occurrence_count + 1,
            last_seen        = VALUES(last_seen),
            severity         = VALUES(severity),
            status           = IF(status = 'resolved', 'open', status)
    ");
    if (!$stmt) {
        throw new \Exception(
            "error_groups upsert prepare failed: " . $conn->error
        );
    }

    $stmt->bind_param(
        "issssissss",
        $projectId,
        $fingerprint,
        $p["error_type"],
        $p["message"],
        $p["file"],
        $p["line"],
        $severity,
        $environment,
        $receivedAt,
        $receivedAt
    );

    if (!$stmt->execute()) {
        throw new \Exception(
            "error_groups upsert execute failed: " . $stmt->error
        );
    }

    // insert_id is the new row's ID on INSERT, or 0 on UPDATE
    $groupId = (int) $conn->insert_id;
    $stmt->close();

    // On UPDATE insert_id is 0 — fetch the real group ID by fingerprint
    if ($groupId === 0) {
        $stmt = $conn->prepare(
            "SELECT id FROM error_groups WHERE project_id = ? AND fingerprint = ? LIMIT 1"
        );
        $stmt->bind_param("is", $projectId, $fingerprint);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new \Exception(
                "could not resolve error group for fingerprint $fingerprint"
            );
        }
        $groupId = (int) $row["id"];
    }

    // === LOCATION + DEVICE =======================================
    $locationId = resolveLocation($conn, $projectId, $p["geo"]);
    $deviceId = resolveDevice($conn, $projectId, $p["device"]);

    // === INSERT ERROR EVENT ======================================
    $stmt = $conn->prepare("
        INSERT INTO error_events
            (group_id, project_id, location_id, device_id,
             stack_trace, url, environment, severity, context, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        throw new \Exception(
            "error_events INSERT prepare failed: " . $conn->error
        );
    }

    $stmt->bind_param(
        "iiisssssss",
        $groupId,
        $projectId,
        $locationId,
        $deviceId,
        $p["stack_trace"],
        $p["url"],
        $environment,
        $severity,
        $p["context"],
        $receivedAt
    );

    if (!$stmt->execute()) {
        throw new \Exception(
            "error_events INSERT execute failed: " . $stmt->error
        );
    }
    $stmt->close();

    // === CACHE INVALIDATION ======================================
    // Bust errors list and trend caches for this project.
    //
    // We always bust here — not just on reopen — because:
    //   1. A new error firing is exactly when you want fresh cache
    //   2. occurrence_count changed, which shows in the errors list
    //   3. The trend (daily occurrences over time) has a new data point
    //
    // We don't bust error-detail here because the detail view queries
    // error_events directly — the new event will show up naturally.
    // The 2-minute TTL on detail is short enough not to matter.
    try {
        $valkey = getValkey();
        $listKeys = $valkey->keys("analytics:errors:{$projectId}:*");
        $trendKeys = $valkey->keys("analytics:errors-trend:{$projectId}:*");
        $allKeys = array_merge($listKeys ?? [], $trendKeys ?? []);

        if (!empty($allKeys)) {
            $valkey->del($allKeys);
        }
    } catch (\Exception $e) {
        // Non-fatal — stale cache expires naturally
        writeLog(
            "error",
            "error-worker cache bust failed: " . $e->getMessage(),
            [
                "project_id" => $projectId,
            ]
        );
    }

    return $groupId;
}
?>