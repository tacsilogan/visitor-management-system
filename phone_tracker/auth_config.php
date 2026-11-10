<?php

/*
 * Sign-in, lockout, and session policy. These values are the production defaults.
 * Settings returned by auth_config() can be overridden per machine in
 * config/auth.php (copy config/auth.example.php). Never commit that copy.
 */

const AUTH_SESSION_NAME = "ISATU_VMS_SESSION";
const AUTH_CSRF_COOKIE = "ISATU_VMS_CSRF";
const AUTH_TRUSTED_DEVICE_COOKIE = "ISATU_VMS_DEVICE";

// Sessions (how long people stay signed in is set in auth_config() below)
const AUTH_PENDING_TIMEOUT_SECONDS = 600;    // time allowed to finish the extra sign-in steps
const AUTH_STEP_UP_SECONDS = 900;            // "Confirm it's you" stays valid for 15 minutes

// Lockout rules (see AUTH_SECURITY.md)
const AUTH_LOCK_THRESHOLD = 5;               // wrong attempts for one account from one IP
const AUTH_LOCK_BASE_MINUTES = 10;           // first cooldown; doubles for each repeat within 24 h
const AUTH_LOCK_MAX_MINUTES = 1440;
const AUTH_LOCK_ESCALATION_HOURS = 24;
const AUTH_ACCOUNT_ALARM_FAILURES = 15;      // failures for one account within the window...
const AUTH_ACCOUNT_ALARM_MIN_IPS = 3;        // ...coming from at least this many IP addresses
const AUTH_ACCOUNT_ALARM_WINDOW_MINUTES = 60;
const AUTH_ACCOUNT_ALARM_LOCK_MINUTES = 60;
const AUTH_IP_FAILURES = 20;                 // failures from one IP across all accounts
const AUTH_IP_WINDOW_MINUTES = 10;
const AUTH_IP_LOCK_MINUTES = 10;
const AUTH_ATTEMPT_RETENTION_DAYS = 90;

// Accounts and passwords
const AUTH_TEMP_PASSWORD_HOURS = 24;
const AUTH_PASSWORD_MIN_LENGTH = 10;
const AUTH_PASSWORD_MAX_LENGTH = 64;         // bcrypt only reads the first 72 bytes
const AUTH_TRUSTED_DEVICE_DAYS = 30;
const AUTH_BACKUP_CODE_COUNT = 10;
const AUTH_TOTP_ISSUER = "ISATU Visitor Management";
const AUTH_STAFF_ROLES = ["admin", "security", "offices"];
const AUTH_ROLE_HOME = [
    "admin" => "admin.html",
    "security" => "dashboard.html",
    "offices" => "offices.html",
    "visitor" => "index.html",
];

// bcrypt hash of a random value. A sign-in for a username that does not exist is
// checked against it, so it takes as long as a sign-in with a wrong password.
const AUTH_DUMMY_PASSWORD_HASH = '$2y$10$FBMYS7N.DqpdoKOXMTRWQ.yCPZiqUKaGMFSUcr0aIYE9SJkVM/pQ6';

const AUTH_MIGRATION_MESSAGE = "Database update required: import phone_tracker/auth_security_migration.sql into phone_tracker.";

function auth_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }
    $config = [
        // Roles that must use two-step verification. Only remove "admin" on a
        // development machine, never on the deployed server.
        "two_factor_required_roles" => ["admin"],
        // Minutes without any activity before someone is signed out. 0 = never: people
        // stay signed in until they sign out or session_max_hours is reached.
        "idle_timeout_minutes" => 0,
        // Everyone signs in again this many hours after signing in (one shift).
        // 0 = no limit, which is not recommended.
        "session_max_hours" => 12,
    ];
    $localFile = __DIR__ . "/config/auth.php";
    if (is_file($localFile)) {
        $local = require $localFile;
        if (is_array($local)) {
            $config = array_replace($config, array_intersect_key($local, $config));
        }
    }
    return $config;
}

/** Seconds without activity before a session ends; 0 means inactivity never ends it. */
function auth_idle_timeout_seconds(): int
{
    return max(0, min(1440, (int) auth_config()["idle_timeout_minutes"])) * 60;
}

/** Seconds after sign-in when a session always ends; 0 means no limit. */
function auth_session_max_seconds(): int
{
    return max(0, min(720, (int) auth_config()["session_max_hours"])) * 3600;
}

function auth_two_factor_required_for(string $role): bool
{
    $roles = auth_config()["two_factor_required_roles"];
    return is_array($roles) && in_array($role, $roles, true);
}

function auth_two_factor_allowed_for(string $role): bool
{
    return in_array($role, AUTH_STAFF_ROLES, true);
}
