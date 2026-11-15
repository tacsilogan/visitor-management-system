<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";

api_require_method("GET");
api_success([
    "name" => "ISATU Visitor Mobile API",
    "version" => MOBILE_API_VERSION,
    "health" => "health.php",
]);

