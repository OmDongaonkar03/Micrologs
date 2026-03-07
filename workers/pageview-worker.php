<?php
/*
    ===============================================================
        Micrologs
        File : workers/pageview-worker.php
        Desc : Pops pageview jobs from the Valkey queue and writes
               them to the database. Run this as a persistent
               background process — it loops forever.

        Local  : php workers/pageview-worker.php
        Prod   : managed by Supervisor (see /docs for config)
    ===============================================================
*/
require_once __DIR__ . "/../includes/functions.php";

// Worker manages its own DB connection — independent of web requests
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    echo "[pageview-worker] DB connection failed: " .
        $conn->connect_error .
        "\n";
    exit(1);
}
$conn->set_charset("utf8mb4");

// Match the timezone used by the web process
$tz = APP_TIMEZONE;
$stmt = $conn->prepare("SET time_zone = ?");
$stmt->bind_param("s", $tz);
$stmt->execute();
$stmt->close();

echo "[pageview-worker] started — listening on micrologs:pageviews\n";

// ── Main loop ────────────────────────────────────────────────────
// queuePop uses BLPOP with a 2s timeout, so this loop is not a
// busy-spin. It blocks waiting for messages and only wakes when
// one arrives or the timeout expires.

while (true) {
    $payload = queuePop("micrologs:pageviews");

    if ($payload === null) {
        // Timeout with no message — loop back and wait again
        continue;
    }

    try {
        processPageview($conn, $payload);
        echo "[pageview-worker] ok  project=" .
            ($payload["project_id"] ?? "?") .
            "  url=" .
            ($payload["url"] ?? "?") .
            "\n";
    } catch (\Exception $e) {
        writeLog("error", "pageview-worker: " . $e->getMessage(), [
            "project_id" => $payload["project_id"] ?? null,
            "url" => $payload["url"] ?? null,
        ]);
        echo "[pageview-worker] err " . $e->getMessage() . "\n";
        // Do not exit — keep processing the next message.
        // A single bad payload should not kill the worker.
    }
}

// ── DB logic ─────────────────────────────────────────────────────
// Mirrors exactly what pageview.php used to do synchronously.
// Receives the pre-enriched payload built at request time.

function processPageview(\mysqli $conn, array $p): void
{
    $projectId = (int) $p["project_id"];
    $visitorHash = $p["visitor_hash"];
    $fingerHash = $p["fingerprint_hash"];
    $sessionToken = $p["session_token"];
    $url = $p["url"];

    // === VISITOR (upsert) ========================================
    // Insert new visitor, or update last_seen on duplicate.
    // Refresh fingerprint_hash only if it was previously empty.
    $stmt = $conn->prepare("
        INSERT INTO visitors (project_id, visitor_hash, fingerprint_hash)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            fingerprint_hash = IF(
                fingerprint_hash = '' AND VALUES(fingerprint_hash) != '',
                VALUES(fingerprint_hash),
                fingerprint_hash
            ),
            last_seen = NOW()
    ");
    if (!$stmt) {
        throw new \Exception("visitor upsert prepare failed: " . $conn->error);
    }
    $stmt->bind_param("iss", $projectId, $visitorHash, $fingerHash);
    if (!$stmt->execute()) {
        throw new \Exception("visitor upsert execute failed: " . $stmt->error);
    }
    $visitorDbId = (int) $conn->insert_id;
    $stmt->close();

    // insert_id is 0 on UPDATE — fetch the real id
    if ($visitorDbId === 0) {
        $stmt = $conn->prepare(
            "SELECT id FROM visitors WHERE project_id = ? AND visitor_hash = ? LIMIT 1"
        );
        $stmt->bind_param("is", $projectId, $visitorHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $visitorDbId = (int) $row["id"];
        } elseif (!empty($fingerHash)) {
            // Cookie was cleared — fingerprint fallback, then re-link visitor_hash
            $stmt = $conn->prepare(
                "SELECT id FROM visitors WHERE project_id = ? AND fingerprint_hash = ? LIMIT 1"
            );
            $stmt->bind_param("is", $projectId, $fingerHash);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                $visitorDbId = (int) $row["id"];
                $stmt = $conn->prepare(
                    "UPDATE visitors SET visitor_hash = ?, last_seen = NOW() WHERE id = ?"
                );
                $stmt->bind_param("si", $visitorHash, $visitorDbId);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    if ($visitorDbId === 0) {
        throw new \Exception("could not resolve visitor for hash $visitorHash");
    }

    // === SESSION (upsert) ========================================
    $stmt = $conn->prepare("
        INSERT INTO sessions (project_id, visitor_id, session_token)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            last_activity = NOW()
    ");
    if (!$stmt) {
        throw new \Exception("session upsert prepare failed: " . $conn->error);
    }
    $stmt->bind_param("iis", $projectId, $visitorDbId, $sessionToken);
    if (!$stmt->execute()) {
        throw new \Exception("session upsert execute failed: " . $stmt->error);
    }
    $sessionId = (int) $conn->insert_id;
    $stmt->close();

    if ($sessionId === 0) {
        $stmt = $conn->prepare(
            "SELECT id FROM sessions WHERE session_token = ? LIMIT 1"
        );
        $stmt->bind_param("s", $sessionToken);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $sessionId = $row ? (int) $row["id"] : 0;
    }

    if ($sessionId === 0) {
        throw new \Exception(
            "could not resolve session for token $sessionToken"
        );
    }

    // === DEDUPLICATION ===========================================
    // Same visitor + same URL within 5 minutes = skip
    $stmt = $conn->prepare("
        SELECT id FROM pageviews
        WHERE project_id = ? AND visitor_id = ? AND url = ?
          AND created_at >= DATE_SUB(NOW(), INTERVAL 300 SECOND)
        LIMIT 1
    ");
    $stmt->bind_param("iis", $projectId, $visitorDbId, $url);
    $stmt->execute();
    $duplicate = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($duplicate) {
        return; // duplicate — skip silently, not an error
    }

    // === LOCATION + DEVICE =======================================
    // Enrichment moved here from the endpoint so the HTTP request
    // returns immediately without blocking on GeoIP / UA parsing.
    $geo = geolocate($p["ip_raw"] ?? "");
    $device = parseUserAgent($p["user_agent"] ?? "");
    $locationId = resolveLocation($conn, $projectId, $geo);
    $deviceId = resolveDevice($conn, $projectId, $device);

    // === INSERT PAGEVIEW =========================================
    // Use received_at from the payload — the timestamp was captured
    // when the HTTP request arrived, not when the worker runs.
    $utm = $p["utm"];
    $stmt = $conn->prepare("
        INSERT INTO pageviews
            (project_id, session_id, visitor_id, location_id, device_id,
             url, page_title, referrer_url, referrer_category,
             utm_source, utm_medium, utm_campaign, utm_content, utm_term,
             screen_resolution, timezone, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        throw new \Exception("pageview INSERT prepare failed: " . $conn->error);
    }
    $stmt->bind_param(
        "iiiiissssssssssss",
        $projectId,
        $sessionId,
        $visitorDbId,
        $locationId,
        $deviceId,
        $url,
        $p["page_title"],
        $p["referrer"],
        $p["referrer_category"],
        $utm["utm_source"],
        $utm["utm_medium"],
        $utm["utm_campaign"],
        $utm["utm_content"],
        $utm["utm_term"],
        $p["screen_resolution"],
        $p["timezone"],
        $p["received_at"]
    );
    if (!$stmt->execute()) {
        throw new \Exception("pageview INSERT execute failed: " . $stmt->error);
    }
    $stmt->close();

    // === BOUNCE FLAG =============================================
    // Flip is_bounced = 0 only if this session now has more than 1 pageview.
    // Single conditional UPDATE — no separate COUNT query needed.
    $stmt = $conn->prepare("
        UPDATE sessions
        SET is_bounced = 0
        WHERE id = ?
          AND is_bounced = 1
          AND (SELECT COUNT(*) FROM pageviews WHERE session_id = ?) > 1
    ");
    $stmt->bind_param("ii", $sessionId, $sessionId);
    $stmt->execute();
    $stmt->close();
}
?>