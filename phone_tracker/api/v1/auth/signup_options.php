<?php
declare(strict_types=1);
require_once __DIR__ . "/_signup.php";

// What the student sign-up form offers: the email domain, the years, and every course by department.
// GET  →  {student_email_domain, year_levels, departments: [{code, name, courses: [{code, name}]}]}

api_require_method("GET");
api_require_school_schema($conn);
api_success([
    "student_email_domain" => SCHOOL_STUDENT_DOMAIN,
    "year_levels" => SCHOOL_YEAR_LEVELS,
    "departments" => school_student_courses($conn),
]);
