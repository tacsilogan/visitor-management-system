<?php
declare(strict_types=1);
/*
 * How long records are kept, as the app's Terms and Privacy Notice promises ("How long we keep it"
 * in visitor_app/.../ui/TermsNotice.kt):
 *   - a visit, with its consent, notifications, history and the activity records about it, goes
 *     1 year after the visit ended;
 *   - a visitor's or student's own activity records go 1 year after the activity;
 *   - a visitor's or student's account goes after 1 year without use: no sign-in and no visit;
 *   - emails, in-app notifications and sign-up requests go 1 year after they were made.
 * Staff accounts are never removed here. What staff did is kept unless it is about a visit, which
 * goes with the visit: the notice says some records are kept for security. Location points and
 * sign-in attempts have their own, shorter cleanups (90 days).
 *
 * workers/purge_old_records.php runs this once a day; tools/record_retention_test.php checks it.
 */

/** As in "NOW() - INTERVAL 1 YEAR". The notice says 1 year: change both together. */
const RETENTION_PERIOD = "1 YEAR";
const RETENTION_BATCH = 500;

/**
 * Counts what is older than the notice allows, or with $apply deletes it.
 *
 * @return array<string, int> how many, by kind ("visits", "accounts", ...)
 */
function retention_run(mysqli $conn, bool $apply): array
{
    $cutoff = "NOW() - INTERVAL " . RETENTION_PERIOD;
    $appUser = "SELECT id FROM app_users WHERE role IN ('visitor', 'student')";
    $done = [];

    // The database removes a visit's consent, notifications, history, tracking and presence with
    // it, but would only unlink the activity records about it, so those go first.
    $done["visits"] = retention_batches($conn, $apply, "appointments",
        "COALESCE(scheduled_end_at, scheduled_start_at, created_at) < {$cutoff} AND status <> 'checked_in'",
        function (string $ids) use ($conn): int {
            $conn->query("DELETE FROM audit_logs WHERE appointment_id IN ({$ids})");
            $conn->query("DELETE FROM appointments WHERE id IN ({$ids})");
            return $conn->affected_rows;
        });

    // A visit with several stops, once none of its stops is left.
    $done["multi-stop visits"] = retention_batches($conn, $apply, "visits",
        "COALESCE(visit_date, DATE(created_at)) < CURDATE() - INTERVAL " . RETENTION_PERIOD . "
         AND NOT EXISTS (SELECT 1 FROM appointments a WHERE a.visit_id = visits.id)");

    // Last used: signing up, signing in, the app being used, or a visit (one still to come counts).
    $done["accounts"] = retention_batches($conn, $apply, "app_users",
        "role IN ('visitor', 'student') AND GREATEST(
            created_at,
            COALESCE(last_login_at, created_at),
            COALESCE(last_seen_at, created_at),
            COALESCE((SELECT MAX(COALESCE(t.last_used_at, t.created_at)) FROM api_access_tokens t WHERE t.user_id = app_users.id), created_at),
            COALESCE((SELECT MAX(GREATEST(a.created_at, COALESCE(a.scheduled_end_at, a.created_at))) FROM appointments a WHERE a.visitor_user_id = app_users.id), created_at)
         ) < {$cutoff}",
        function (string $ids) use ($conn): int {
            // What they did, the emails to them, and their sign-up, which the database would only unlink.
            $conn->query("DELETE FROM audit_logs WHERE actor_user_id IN ({$ids})");
            $conn->query("DELETE FROM outbound_emails WHERE user_id IN ({$ids})");
            $conn->query("DELETE FROM account_requests WHERE user_id IN ({$ids})");
            $conn->query("DELETE FROM app_users WHERE id IN ({$ids}) AND role IN ('visitor', 'student')");
            return $conn->affected_rows;
        });

    // What visitors and students did, and anything left about a visit or with no one behind it.
    $done["activity records"] = retention_batches($conn, $apply, "audit_logs",
        "created_at < {$cutoff} AND (appointment_id IS NOT NULL OR actor_user_id IS NULL OR actor_user_id IN ({$appUser}))");

    $done["notifications"] = retention_batches($conn, $apply, "app_notifications", "created_at < {$cutoff}");
    $done["emails"] = retention_batches($conn, $apply, "outbound_emails", "created_at < {$cutoff}");
    $done["sign-up requests"] = retention_batches($conn, $apply, "account_requests",
        "account_type IN ('visitor', 'student') AND created_at < {$cutoff}");
    return $done;
}

/**
 * Counts the rows of $table matching $where, or deletes them a batch at a time, each batch in its
 * own transaction. $delete gets the batch's ids and returns how many rows it removed; without it
 * the rows are simply deleted.
 */
function retention_batches(mysqli $conn, bool $apply, string $table, string $where, ?callable $delete = null): int
{
    if (!$apply) {
        return (int) $conn->query("SELECT COUNT(*) FROM {$table} WHERE {$where}")->fetch_row()[0];
    }
    $total = 0;
    do {
        $ids = array_map(
            static fn(array $row): int => (int) $row[0],
            $conn->query("SELECT id FROM {$table} WHERE {$where} ORDER BY id LIMIT " . RETENTION_BATCH)->fetch_all()
        );
        if (!$ids) {
            break;
        }
        $list = implode(",", $ids);
        $conn->begin_transaction();
        try {
            if ($delete !== null) {
                $removed = $delete($list);
            } else {
                $conn->query("DELETE FROM {$table} WHERE id IN ({$list})");
                $removed = $conn->affected_rows;
            }
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }
        if ($removed < 1) {
            // Found but not removed: stop rather than find the same rows again forever.
            throw new RuntimeException("Could not remove old rows from {$table}.");
        }
        $total += $removed;
    } while (count($ids) === RETENTION_BATCH);
    return $total;
}

/** "3 visits, 1 account, 0 activity records, ..." */
function retention_summary(array $done): string
{
    $parts = [];
    foreach ($done as $kind => $count) {
        $parts[] = $count . " " . ($count === 1 ? rtrim($kind, "s") : $kind);
    }
    return implode(", ", $parts);
}
