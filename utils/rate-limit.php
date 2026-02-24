<?php
/**
 * File-based rate limiter (lock-free, shared hosting safe)
 *
 * - No DB
 * - No flock()
 * - Append-only files
 * - Automatic cleanup
 *
 * @param string $identifier       Unique key (email / phone / ip / custom)
 * @param int    $allowedRequests  Max allowed requests in window
 * @param int    $windowSeconds    Time window in seconds
 * @param int    $blockSeconds     Block duration in seconds (default 15 min)
 */
function rateLimitOrBlock(
    string $identifier,
    int $allowedRequests,
    int $windowSeconds,
    int $blockSeconds = 900
) {
    $baseDir = __DIR__ . "/rate_limits";
    $blockDir = __DIR__ . "/rate_blocks";

    if (!is_dir($baseDir)) {
        mkdir($baseDir, 0777, true);
    }
    if (!is_dir($blockDir)) {
        mkdir($blockDir, 0777, true);
    }

    $key = hash("sha256", $identifier);
    $userDir = $baseDir . "/" . $key;
    $blockFile = $blockDir . "/" . $key . ".block";

    $now = time();

    /* =======================
       1. BLOCK CHECK
    ======================== */
    if (file_exists($blockFile)) {
        $blockedTill = (int) file_get_contents($blockFile);

        if ($blockedTill > $now) {
            http_response_code(429);
            echo json_encode([
                "error" => "Too many attempts. Try again later.",
            ]);
            exit();
        } else {
            @unlink($blockFile); // expired block
        }
    }

    if (!is_dir($userDir)) {
        mkdir($userDir, 0777, true);
    }

    /* =======================
       2. COUNT RECENT ATTEMPTS
    ======================== */
    $attempts = 0;
    foreach (glob($userDir . "/*.req") as $file) {
        if ($now - filemtime($file) <= $windowSeconds) {
            $attempts++;
        } else {
            @unlink($file); // cleanup old
        }
    }

    /* =======================
       3. LIMIT EXCEEDED → BLOCK
    ======================== */
    if ($attempts >= $allowedRequests) {
        file_put_contents($blockFile, $now + $blockSeconds);

        // cleanup attempt files
        foreach (glob($userDir . "/*.req") as $file) {
            @unlink($file);
        }

        http_response_code(429);
        echo json_encode([
            "error" => "Too many attempts. You are temporarily blocked.",
        ]);
        exit();
    }

    /* =======================
       4. RECORD THIS ATTEMPT
       (atomic, no locks)
    ======================== */
    touch($userDir . "/" . $now . "." . uniqid("", true) . ".req");

    /* =======================
       5. OPTIONAL PROBABILISTIC CLEANUP
       (1% chance)
    ======================== */
    if (random_int(1, 100) === 1) {
        cleanupRateLimitDirs($baseDir, 86400); // 1 day max age
    }
}

/**
 * Probabilistic global cleanup
 */
function cleanupRateLimitDirs(string $baseDir, int $maxAgeSeconds)
{
    $now = time();

    foreach (glob($baseDir . "/*") as $userDir) {
        if (!is_dir($userDir)) {
            continue;
        }

        foreach (glob($userDir . "/*.req") as $file) {
            if ($now - filemtime($file) > $maxAgeSeconds) {
                @unlink($file);
            }
        }
    }
}