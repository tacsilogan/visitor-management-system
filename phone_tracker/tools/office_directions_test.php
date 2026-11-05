<?php
/**
 * Checks the rules for directions inside a building (office_directions_service.php) without a
 * database, and with --database also saving and loading them, on a throwaway department that it
 * removes again:
 *
 *   php phone_tracker/tools/office_directions_test.php [--database]
 *
 * Exits with 1 and lists what failed when a check does not hold.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . "/office_directions_service.php";

$checks = 0;
$failures = 0;

function check(bool $holds, string $what): void
{
    global $checks, $failures;
    $checks++;
    if (!$holds) {
        $failures++;
        fwrite(STDERR, "FAIL: {$what}\n");
    }
}

function same(mixed $actual, mixed $expected, string $what): void
{
    check($actual === $expected, $what . "\n      expected " . var_export($expected, true) . "\n      got      " . var_export($actual, true));
}

function refused(array $input, string $message, string $what): void
{
    try {
        office_directions_read_input($input);
        check(false, "{$what}: it was accepted");
    } catch (InvalidArgumentException $error) {
        check(str_contains($error->getMessage(), $message), "{$what}: the error was \"{$error->getMessage()}\"");
    }
}

// The way every screen names the place.
same(office_directions_where(["building" => "CCI Building", "floor" => "2nd floor", "room" => "Room 201"]), "CCI Building, 2nd floor, Room 201", "all three");
same(office_directions_where(["building" => "CCI Building", "floor" => "2nd floor", "room" => ""]), "CCI Building, 2nd floor", "no room");
same(office_directions_where(office_directions_blank()), "", "nothing written");

// What the administrator typed is tidied.
$read = office_directions_read_input([
    "building" => "  CCI   Building ",
    "floor" => "2nd floor",
    "room" => "",
    "visitor_message" => " Take the stairs.\n Show your pass. ",
    "steps" => [
        ["text" => "  Enter through the main door. ", "photo" => ""],
        ["text" => "Go up.", "photo" => "uploads/directions/0123456789abcdef0123456789abcdef.jpg"],
        ["text" => "Room 201.", "new_photo" => 3],
    ],
]);
same($read["building"], "CCI Building", "spaces inside the name");
same($read["visitor_message"], "Take the stairs. Show your pass.", "line breaks become spaces, so the voice reads one sentence");
same($read["steps"][0], ["text" => "Enter through the main door.", "photo" => ""], "a step without a photo");
same($read["steps"][1]["photo"], "uploads/directions/0123456789abcdef0123456789abcdef.jpg", "a step that keeps its photo");
same($read["steps"][2]["new_photo"], 3, "a step with a new photo keeps its number");

// Everything is optional, and nothing at all is fine.
same(office_directions_read_input([]), office_directions_blank(), "an empty form clears the directions");

// The limits.
refused(["building" => str_repeat("x", OFFICE_DIRECTIONS_MAX_BUILDING + 1)], "at most 60", "a very long building name");
refused(["room" => str_repeat("x", OFFICE_DIRECTIONS_MAX_ROOM + 1)], "at most 30", "a very long room");
refused(["visitor_message" => str_repeat("x", OFFICE_DIRECTIONS_MAX_MESSAGE + 1)], "at most 160", "a very long description");
same(mb_strlen(office_directions_read_input(["visitor_message" => str_repeat("ñ", 160)])["visitor_message"], "UTF-8"), 160, "the limit counts letters, not bytes");
refused(["steps" => array_fill(0, OFFICE_DIRECTIONS_MAX_STEPS + 1, ["text" => "Go."])], "at most 8", "too many steps");
refused(["steps" => [["text" => "Go."], ["text" => "   "]]], "step 2", "a step with no sentence");
refused(["steps" => [["text" => str_repeat("x", OFFICE_DIRECTIONS_MAX_STEP_TEXT + 1)]]], "at most 140", "a very long step");
refused(["steps" => "stairs"], "could not be read", "steps that are not a list");
refused(["steps" => ["stairs"]], "could not be read", "a step that is not a step");

// A step can only point at a photo in the directions folder, whatever the request says.
foreach ([
    "../config/auth_secret.php",
    "uploads/profile_pictures/0123456789abcdef0123456789abcdef.png",
    "uploads/directions/../../db.php",
    "uploads/directions/0123456789abcdef0123456789abcdef.php",
    "uploads/directions/short.jpg",
    "https://example.com/photo.jpg",
] as $path) {
    refused(["steps" => [["text" => "Go.", "photo" => $path]]], "could not be read", "the photo path {$path}");
}

// Only real pictures are kept. A script named .jpg is refused, and so is a picture too large.
$script = tempnam(sys_get_temp_dir(), "isatu_");
file_put_contents($script, "<?php echo 1;");
$fails = function (array $upload, string $message, string $what): void {
    try {
        office_directions_store_photo($upload, 2);
        check(false, "{$what}: it was kept");
    } catch (InvalidArgumentException $error) {
        check(str_contains($error->getMessage(), $message), "{$what}: the error was \"{$error->getMessage()}\"");
    }
};
$fails(["tmp_name" => $script, "error" => UPLOAD_ERR_OK, "size" => filesize($script)], "must be a JPG", "a script sent as a photo");
$fails(["tmp_name" => $script, "error" => UPLOAD_ERR_OK, "size" => OFFICE_DIRECTIONS_MAX_PHOTO_BYTES + 1], "too large", "a very large file");
$fails(["tmp_name" => "", "error" => UPLOAD_ERR_NO_FILE, "size" => 0], "could not be uploaded", "a photo that did not arrive");
@unlink($script);

if (in_array("--database", $argv, true)) {
    require dirname(__DIR__) . "/db.php";
    $code = "ZZDIRCHECK";
    $forget = function () use ($conn, $code): void {
        $conn->query("DELETE FROM offices WHERE code = '{$code}'");
    };
    $forget();
    $conn->query("INSERT INTO offices (code, name, location, is_active, sort_order) VALUES ('{$code}', 'Directions Check', 'Typed location', 1, 999)");
    $admin = (int) $conn->query("SELECT id FROM app_users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetch_row()[0];

    try {
        check(office_directions_schema_ready($conn), "the tables exist");

        // A real picture, a 1 x 1 PNG, stored the way a save stores it.
        $picture = tempnam(sys_get_temp_dir(), "isatu_");
        file_put_contents($picture, base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M/wHwAEBgIApD5fRAAAAABJRU5ErkJggg=="));
        $photo = office_directions_store_photo(["tmp_name" => $picture, "error" => UPLOAD_ERR_OK, "size" => filesize($picture)], 1);
        $file = dirname(__DIR__) . "/" . $photo;
        check(is_file($file), "the photo is stored");
        check((bool) preg_match(OFFICE_DIRECTIONS_PHOTO_PATTERN, $photo), "under a name a step can use");

        $written = office_directions_read_input([
            "building" => "CCI Building",
            "floor" => "2nd floor",
            "room" => "Room 201",
            "visitor_message" => "Take the stairs near the main door.",
            "steps" => [["text" => "Enter through the main door.", "photo" => $photo], ["text" => "Room 201 is on your right.", "photo" => ""]],
        ]);
        office_directions_save($conn, $code, $written, $admin);
        $saved = office_directions_load($conn, $code)[$code] ?? [];
        same($saved["building"] ?? "", "CCI Building", "the building is saved");
        same(array_column($saved["steps"] ?? [], "text"), ["Enter through the main door.", "Room 201 is on your right."], "the steps stay in order");
        same($saved["steps"][0]["photo"] ?? "", $photo, "the photo stays with its step");
        same((string) $conn->query("SELECT location FROM offices WHERE code = '{$code}'")->fetch_row()[0], "CCI Building, 2nd floor, Room 201", "the directory's location follows");
        check(isset(office_directions_load($conn)[$code]), "it is among all the departments' directions");

        // Saved again without the photo: the file goes too.
        $written["steps"][0]["photo"] = "";
        office_directions_save($conn, $code, $written, $admin);
        check(!is_file($file), "a photo no step uses is removed");

        // A photo two departments share (Copy from) stays until neither uses it.
        file_put_contents($picture, base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M/wHwAEBgIApD5fRAAAAABJRU5ErkJggg=="));
        $shared = office_directions_store_photo(["tmp_name" => $picture, "error" => UPLOAD_ERR_OK, "size" => filesize($picture)], 1);
        $sharedFile = dirname(__DIR__) . "/" . $shared;
        $written["steps"][0]["photo"] = $shared;
        office_directions_save($conn, $code, $written, $admin);
        $conn->query("INSERT INTO offices (code, name, is_active, sort_order) VALUES ('{$code}2', 'Directions Check 2', 1, 999)");
        office_directions_save($conn, $code . "2", $written, $admin);
        office_directions_save($conn, $code . "2", office_directions_read_input([]), $admin);
        check(is_file($sharedFile), "a photo the other department still uses stays");
        $conn->query("DELETE FROM offices WHERE code = '{$code}2'");

        // Blank directions remove the rows, and leave the location alone.
        office_directions_save($conn, $code, office_directions_read_input([]), $admin);
        check(!isset(office_directions_load($conn, $code)[$code]), "blank directions leave nothing behind");
        check(!is_file($sharedFile), "and no photo");
        same((string) $conn->query("SELECT location FROM offices WHERE code = '{$code}'")->fetch_row()[0], "CCI Building, 2nd floor, Room 201", "the location stays");
    } finally {
        $conn->query("DELETE FROM offices WHERE code = '{$code}2'");
        $forget();
    }
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$checks} checks failed.\n");
    exit(1);
}
echo "All {$checks} checks passed.\n";
