<?php
/*
 * A visitor's rights over their own account (AUTH_SECURITY.md, "Visitors' own accounts"):
 *
 * - The server records which version of the Terms and Privacy Notice each visitor agreed to and
 *   when: at sign-up, and again whenever the app asks them to agree to a new version (the age
 *   statement, "I am 18 or older, or my parent or guardian agrees for me", is part of it).
 * - Deleting their own account is account_remove_own() (account_removal_service.php).
 */

/** Notice versions look like the app's BuildConfig.CONSENT_VERSION: "terms-privacy-v5". */
const VISITOR_NOTICE_VERSION_PATTERN = '/^terms-privacy-v[0-9]{1,3}$/';

/** Whether visitor_rights_migration.sql is installed. */
function visitor_rights_schema_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        $result = $conn->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_users' AND COLUMN_NAME = 'notice_version'"
        );
        $ready = $result && (int) $result->fetch_row()[0] > 0;
    }
    return $ready;
}

/**
 * Records that the visitor agreed to [$version] of the notice, at [$agreedAt] (now by default).
 * Returns false for a version that doesn't look like one, or when the database isn't updated yet.
 */
function visitor_record_notice_agreement(mysqli $conn, int $userId, string $version, ?string $agreedAt = null): bool
{
    if (!preg_match(VISITOR_NOTICE_VERSION_PATTERN, $version) || !visitor_rights_schema_ready($conn)) {
        return false;
    }
    $at = $agreedAt ?: date("Y-m-d H:i:s");
    $stmt = $conn->prepare("UPDATE app_users SET notice_version = ?, notice_agreed_at = ? WHERE id = ? AND role = 'visitor'");
    $stmt->bind_param("ssi", $version, $at, $userId);
    $stmt->execute();
    $changed = $stmt->affected_rows >= 0;
    $stmt->close();
    return $changed;
}
