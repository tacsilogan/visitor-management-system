<?php

/*
 * What each role may do. Endpoints check a permission instead of a role name, so a
 * new role (for example a security supervisor) only needs a new entry here.
 * Office Personnel stay limited to their own office inside the office endpoints.
 */
const AUTH_ROLE_PERMISSIONS = [
    "admin" => [
        "dashboard.admin",
        "users.manage",
        "departments.manage",
        "activity.view",
        "security.manage",
        "analytics.view",
        "analytics.reset",
        "campus_map.view",
        "campus_map.edit",
        "appointments.list",
        "visits.scan",
        "visits.complete",
        "visits.qr_override",
        "visits.monitor",
        "locations.view_live",
        "routes.view",
        "profile.self",
    ],
    "security" => [
        "campus_map.view",
        "visits.scan",
        "visits.complete",
        "visits.qr_override",
        "visits.monitor",
        "locations.view_live",
        "routes.view",
        "profile.self",
    ],
    "offices" => [
        "appointments.list",
        "office.dashboard",
        "office.appointments",
        "office.availability",
        "office.notifications",
        "profile.self",
    ],
    "visitor" => [
        "appointments.book",
        "tracking.self",
    ],
];

/**
 * @return string[]
 */
function auth_role_permissions(string $role): array
{
    return AUTH_ROLE_PERMISSIONS[$role] ?? [];
}

function auth_role_can(string $role, string $permission): bool
{
    return in_array($permission, auth_role_permissions($role), true);
}
