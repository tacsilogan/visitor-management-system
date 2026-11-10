<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
/*
 * Deletes what the Terms and Privacy Notice says is kept only 1 year (record_retention_service.php).
 *
 *   php workers/purge_old_records.php           count what is due, delete nothing
 *   php workers/purge_old_records.php --apply   delete it (run_scheduled_jobs.php does, once a day)
 */
require_once dirname(__DIR__) . "/db.php";
require_once dirname(__DIR__) . "/record_retention_service.php";

$apply = in_array("--apply", $argv, true);
$done = retention_run($conn, $apply);
echo $apply
    ? "Deleted under the 1-year rule: " . retention_summary($done) . ".\n"
    : "Dry run, due under the 1-year rule: " . retention_summary($done) . ". Use --apply to delete them.\n";
