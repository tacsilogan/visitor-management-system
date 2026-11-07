<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

/*
 * Runs the background jobs the system needs while it is online:
 *   every minute  visit timing (ended slots, campus exits, end of day), queued emails,
 *                 queued push notifications, teachers' phone alerts
 *   once a day    location history older than the retention period is deleted (from 03:00)
 *
 *   php workers/run_scheduled_jobs.php          keeps running, one pass a minute (the Docker
 *                                               image starts this next to the web server)
 *   php workers/run_scheduled_jobs.php --once   one pass, for cron or Windows Task Scheduler
 *
 * Each job runs as its own PHP process with a time limit, so a failing or stuck job (or the
 * database being briefly unreachable) never stops the others or the loop.
 */

require_once dirname(__DIR__) . "/runtime.php";

const SCHEDULER_INTERVAL_SECONDS = 60;
const SCHEDULER_PURGE_HOUR = 3;

function scheduler_log(string $message): void
{
    fwrite(STDOUT, "[" . date("Y-m-d H:i:s") . "] " . $message . "\n");
}

/**
 * Runs one worker script and returns [exit code, output]. Output goes through temporary
 * files rather than pipes, which cannot be read without blocking on Windows.
 */
function scheduler_run(string $script, array $arguments, int $timeoutSeconds): array
{
    $outputFile = tempnam(sys_get_temp_dir(), "isatu_job_");
    $errorFile = tempnam(sys_get_temp_dir(), "isatu_job_");
    $command = array_merge([PHP_BINARY, __DIR__ . DIRECTORY_SEPARATOR . $script], $arguments);
    $process = proc_open($command, [
        0 => ["pipe", "r"],
        1 => ["file", $outputFile, "w"],
        2 => ["file", $errorFile, "w"],
    ], $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        @unlink($outputFile);
        @unlink($errorFile);
        return [-1, "The job could not be started."];
    }
    fclose($pipes[0]);
    $deadline = microtime(true) + $timeoutSeconds;
    $exitCode = -1;
    $note = "";
    while (true) {
        $status = proc_get_status($process);
        if (!$status["running"]) {
            // The exit code is only reported by the first call that sees the process ended.
            $exitCode = (int) $status["exitcode"];
            break;
        }
        if (microtime(true) >= $deadline) {
            proc_terminate($process);
            $exitCode = 124;
            $note = "Stopped after " . $timeoutSeconds . " seconds.";
            break;
        }
        usleep(200000);
    }
    proc_close($process);
    $output = trim(implode("\n", array_filter([
        trim((string) @file_get_contents($outputFile)),
        trim((string) @file_get_contents($errorFile)),
        $note,
    ], "strlen")));
    @unlink($outputFile);
    @unlink($errorFile);
    return [$exitCode, $output];
}

/** The jobs to run in this pass. */
function scheduler_jobs(array &$state): array
{
    $jobs = [
        ["name" => "Visit timing", "script" => "refresh_visit_states.php", "arguments" => [], "timeout" => 120],
        ["name" => "Email", "script" => "send_outbound_emails.php", "arguments" => [], "timeout" => 240],
        ["name" => "Push notifications", "script" => "send_push_notifications.php", "arguments" => [], "timeout" => 240],
        ["name" => "Teacher phone alerts", "script" => "send_web_push.php", "arguments" => [], "timeout" => 120],
    ];
    $today = date("Y-m-d");
    if ((int) date("G") >= SCHEDULER_PURGE_HOUR && ($state["purged_on"] ?? "") !== $today
        && time() >= (int) ($state["purge_retry_after"] ?? 0)) {
        $jobs[] = ["name" => "Location history cleanup", "script" => "purge_location_history.php", "arguments" => ["--apply"], "timeout" => 600, "daily" => true];
    }
    return $jobs;
}

function scheduler_state_file(): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . "isatu_vms_scheduler_state.json";
}

function scheduler_load_state(): array
{
    $state = json_decode((string) @file_get_contents(scheduler_state_file()), true);
    return is_array($state) ? $state : [];
}

function scheduler_save_state(array $state): void
{
    @file_put_contents(scheduler_state_file(), json_encode($state), LOCK_EX);
}

/**
 * One pass over every job. Exit code 2 means "not set up yet". A job that keeps failing
 * the same way is reported once, and again when it recovers, so the log stays readable.
 */
function scheduler_pass(array &$state): void
{
    foreach (scheduler_jobs($state) as $job) {
        [$exitCode, $output] = scheduler_run($job["script"], $job["arguments"], $job["timeout"]);
        $key = $job["script"];
        if ($exitCode === 2) {
            unset($state["failing"][$key]);
            if (($state["not_ready"][$key] ?? "") !== $output) {
                scheduler_log($job["name"] . " is waiting for setup: " . ($output !== "" ? $output : "not configured"));
                $state["not_ready"][$key] = $output;
            }
            continue;
        }
        unset($state["not_ready"][$key]);
        if ($exitCode !== 0) {
            if (($state["failing"][$key] ?? "") !== $output) {
                scheduler_log($job["name"] . " failed (exit code " . $exitCode . "): " . $output);
                $state["failing"][$key] = $output;
            }
            if (!empty($job["daily"])) {
                $state["purge_retry_after"] = time() + 3600;
            }
            continue;
        }
        if (isset($state["failing"][$key])) {
            scheduler_log($job["name"] . " is working again.");
            unset($state["failing"][$key]);
        }
        if (!empty($job["daily"])) {
            $state["purged_on"] = date("Y-m-d");
        }
        // Routine passes stay quiet unless something was actually sent or removed.
        if (!empty($job["daily"]) || preg_match('/\b[1-9]\d* (sent|failed|expired|invalid|location point)/', $output)) {
            scheduler_log($job["name"] . ": " . $output);
        }
    }
    scheduler_save_state($state);
}

$once = in_array("--once", $argv, true);

// Only one scheduler per machine at a time (a slow pass must not overlap the next one).
$lock = fopen(sys_get_temp_dir() . DIRECTORY_SEPARATOR . "isatu_vms_scheduler.lock", "c");
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (!$once) {
        scheduler_log("Another scheduler is already running here; this one is not needed.");
    }
    exit(0);
}

$state = scheduler_load_state();
if ($once) {
    scheduler_pass($state);
    exit(0);
}

scheduler_log("Scheduler started: background jobs run every " . SCHEDULER_INTERVAL_SECONDS . " seconds.");
while (true) {
    $started = time();
    scheduler_pass($state);
    $wait = SCHEDULER_INTERVAL_SECONDS - (time() - $started);
    if ($wait > 0) {
        sleep($wait);
    }
}
