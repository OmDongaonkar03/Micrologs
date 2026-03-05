<?php
/**
 * Valkey-backed rate limiter
 *
 * Replaces the file-based implementation with atomic Valkey operations.
 * Same function signature — no call-site changes needed.
 *
 * Strategy:
 *   - Uses a sliding-window counter via INCR + EXPIRE.
 *   - A separate block key is set when the limit is exceeded.
 *   - Both keys are namespaced under "rl:" to avoid collisions with
 *     cache and queue keys used elsewhere in the app.
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
): void {
    $key = hash("sha256", $identifier);
    $countKey = "rl:count:{$key}";
    $blockKey = "rl:block:{$key}";

    try {
        $valkey = getValkey();

        /* =======================
           1. BLOCK CHECK
        ======================== */
        if ($valkey->exists($blockKey)) {
            http_response_code(429);
            header("Content-Type: application/json");
            echo json_encode([
                "error" => "Too many attempts. Try again later.",
            ]);
            exit();
        }

        /* =======================
           2. COUNT + WINDOW
           INCR is atomic; set the TTL only on the first request in a window
           so the window slides naturally without a race condition.
        ======================== */
        $attempts = (int) $valkey->incr($countKey);

        if ($attempts === 1) {
            // First hit in this window — start the expiry clock
            $valkey->expire($countKey, $windowSeconds);
        }

        /* =======================
           3. LIMIT EXCEEDED → BLOCK
        ======================== */
        if ($attempts > $allowedRequests) {
            // Write the block key, then delete the counter so the next
            // request after the block expires starts a fresh window.
            $valkey->setex($blockKey, $blockSeconds, "1");
            $valkey->del([$countKey]);

            http_response_code(429);
            header("Content-Type: application/json");
            echo json_encode([
                "error" => "Too many attempts. You are temporarily blocked.",
            ]);
            exit();
        }
    } catch (\Exception $e) {
        // If Valkey is unavailable, fail open so the app keeps running.
        // Log the error so ops can investigate.
        if (function_exists("writeLog")) {
            writeLog(
                "error",
                "rateLimitOrBlock: Valkey unavailable, skipping rate limit",
                [
                    "identifier_hash" => hash("sha256", $identifier),
                    "error" => $e->getMessage(),
                ]
            );
        }
    }
}
?>