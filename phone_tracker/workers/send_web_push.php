<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

/*
 * Wakes teachers' phones for new notifications (web_push_service.php). Run every minute by
 * workers/run_scheduled_jobs.php. A short while after a visitor asks, the teacher's phone buzzes.
 */

require_once dirname(__DIR__) . "/db.php";
require_once dirname(__DIR__) . "/web_push_service.php";

try {
    $counts = web_push_deliver($conn);
} catch (Throwable $error) {
    fwrite(STDERR, "Phone alerts stopped: " . $error->getMessage() . "\n");
    exit(1);
}
if ($counts["teachers"] > 0) {
    echo "Phone alerts: {$counts["teachers"]} teacher(s), {$counts["sent"]} sent, {$counts["removed"]} removed, {$counts["failed"]} failed\n";
}
