<?php

/**
 * Directions inside a building (INDOOR_DIRECTIONS.md). GPS leads a visitor to the building
 * door; from there these words and photos lead them to the office door. Administrators write
 * them in Campus Map > Inside, and the visitor app receives them with the campus map.
 * Requires indoor_directions_migration.sql; before that, every office simply has none.
 */

const OFFICE_DIRECTIONS_MIGRATION_MESSAGE = "Database update required: import phone_tracker/indoor_directions_migration.sql into phone_tracker.";
const OFFICE_DIRECTIONS_MAX_STEPS = 8;
const OFFICE_DIRECTIONS_MAX_BUILDING = 60;
const OFFICE_DIRECTIONS_MAX_FLOOR = 30;
const OFFICE_DIRECTIONS_MAX_ROOM = 30;
const OFFICE_DIRECTIONS_MAX_MESSAGE = 160;
const OFFICE_DIRECTIONS_MAX_STEP_TEXT = 140;
/** Browsers shrink photos before sending them; this only stops something unexpected. */
const OFFICE_DIRECTIONS_MAX_PHOTO_BYTES = 3145728;
const OFFICE_DIRECTIONS_PHOTO_PATTERN = '#^uploads/directions/[a-f0-9]{32}\.(?:jpg|png|webp)$#';

function office_directions_schema_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) $conn->query("SELECT office_code, visitor_message FROM office_directions LIMIT 0")
                && (bool) $conn->query("SELECT step_text, photo_path FROM office_direction_steps LIMIT 0");
        } catch (Throwable $ignored) {
            $ready = false;
        }
    }
    return $ready;
}

/** A department with no directions yet. */
function office_directions_blank(): array
{
    return ["building" => "", "floor" => "", "room" => "", "visitor_message" => "", "steps" => []];
}

/** "CCI Building, 2nd floor, Room 201". */
function office_directions_where(array $directions): string
{
    return implode(", ", array_filter(
        [$directions["building"] ?? "", $directions["floor"] ?? "", $directions["room"] ?? ""],
        fn ($part) => $part !== ""
    ));
}

/**
 * Every department's directions, or one department's.
 *
 * @return array<string, array{building: string, floor: string, room: string, visitor_message: string,
 *                             steps: array<int, array{text: string, photo: string}>}>
 */
function office_directions_load(mysqli $conn, ?string $officeCode = null): array
{
    if (!office_directions_schema_ready($conn)) {
        return [];
    }
    $directions = [];
    $where = $officeCode !== null ? " WHERE office_code = ?" : "";
    $stmt = $conn->prepare("SELECT office_code, building, floor, room, visitor_message FROM office_directions" . $where);
    if ($officeCode !== null) {
        $stmt->bind_param("s", $officeCode);
    }
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $directions[(string) $row["office_code"]] = [
            "building" => (string) $row["building"],
            "floor" => (string) $row["floor"],
            "room" => (string) $row["room"],
            "visitor_message" => (string) $row["visitor_message"],
            "steps" => [],
        ];
    }
    $stmt->close();
    $stmt = $conn->prepare(
        "SELECT office_code, step_text, photo_path FROM office_direction_steps" . $where . " ORDER BY office_code, sort_order, id"
    );
    if ($officeCode !== null) {
        $stmt->bind_param("s", $officeCode);
    }
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $code = (string) $row["office_code"];
        $directions[$code] = $directions[$code] ?? office_directions_blank();
        $directions[$code]["steps"][] = ["text" => (string) $row["step_text"], "photo" => (string) $row["photo_path"]];
    }
    $stmt->close();
    return $directions;
}

function office_directions_clean_text(string $value): string
{
    return trim(preg_replace('/\s+/u', " ", $value) ?? "");
}

/**
 * Checks what the administrator typed.
 *
 * Each step in $input["steps"] has "text", and either "photo" (a photo it already has, or "")
 * or "new_photo" (the number of a photo sent with this request, kept as given here).
 *
 * @throws InvalidArgumentException with a message for the administrator.
 */
function office_directions_read_input(array $input): array
{
    $text = function (string $key, int $limit, string $label) use ($input): string {
        $value = office_directions_clean_text((string) ($input[$key] ?? ""));
        if (mb_strlen($value, "UTF-8") > $limit) {
            throw new InvalidArgumentException($label . " can have at most " . $limit . " characters.");
        }
        return $value;
    };
    $directions = [
        "building" => $text("building", OFFICE_DIRECTIONS_MAX_BUILDING, "The building name"),
        "floor" => $text("floor", OFFICE_DIRECTIONS_MAX_FLOOR, "The floor"),
        "room" => $text("room", OFFICE_DIRECTIONS_MAX_ROOM, "The room"),
        "visitor_message" => $text("visitor_message", OFFICE_DIRECTIONS_MAX_MESSAGE, "The description for visitors"),
        "steps" => [],
    ];
    $steps = $input["steps"] ?? [];
    if (!is_array($steps)) {
        throw new InvalidArgumentException("The steps could not be read. Refresh the page and try again.");
    }
    if (count($steps) > OFFICE_DIRECTIONS_MAX_STEPS) {
        throw new InvalidArgumentException("A department can have at most " . OFFICE_DIRECTIONS_MAX_STEPS . " steps.");
    }
    foreach (array_values($steps) as $index => $step) {
        $number = $index + 1;
        if (!is_array($step)) {
            throw new InvalidArgumentException("Step " . $number . " could not be read. Refresh the page and try again.");
        }
        $sentence = office_directions_clean_text((string) ($step["text"] ?? ""));
        if ($sentence === "") {
            throw new InvalidArgumentException("Write what to do in step " . $number . ", or remove the step.");
        }
        if (mb_strlen($sentence, "UTF-8") > OFFICE_DIRECTIONS_MAX_STEP_TEXT) {
            throw new InvalidArgumentException("Step " . $number . " can have at most " . OFFICE_DIRECTIONS_MAX_STEP_TEXT . " characters.");
        }
        $photo = (string) ($step["photo"] ?? "");
        if ($photo !== "" && !preg_match(OFFICE_DIRECTIONS_PHOTO_PATTERN, $photo)) {
            throw new InvalidArgumentException("The photo of step " . $number . " could not be read. Refresh the page and try again.");
        }
        $clean = ["text" => $sentence, "photo" => $photo];
        if (isset($step["new_photo"]) && is_numeric($step["new_photo"])) {
            $clean["new_photo"] = (int) $step["new_photo"];
        }
        $directions["steps"][] = $clean;
    }
    return $directions;
}

/** The folder on the server that holds step photos. */
function office_directions_photo_folder(): string
{
    return __DIR__ . "/uploads/directions";
}

/**
 * Stores a photo sent with a save and returns its path.
 *
 * @throws InvalidArgumentException with a message for the administrator.
 */
function office_directions_store_photo(array $upload, int $stepNumber): string
{
    $problem = "The photo of step " . $stepNumber;
    if ((int) ($upload["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException($problem . " could not be uploaded. Choose it again.");
    }
    if ((int) $upload["size"] <= 0 || (int) $upload["size"] > OFFICE_DIRECTIONS_MAX_PHOTO_BYTES) {
        throw new InvalidArgumentException($problem . " is too large. Choose a smaller one.");
    }
    // Whatever the file claims to be, only a real image is kept (and nothing PHP would run).
    $info = @getimagesize((string) $upload["tmp_name"]);
    $mime = is_array($info) ? (string) ($info["mime"] ?? "") : "";
    $extensions = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];
    if (!isset($extensions[$mime]) || (int) $info[0] < 1 || (int) $info[1] < 1 || (int) $info[0] > 5000 || (int) $info[1] > 5000) {
        throw new InvalidArgumentException($problem . " must be a JPG, PNG, or WebP picture.");
    }
    $folder = office_directions_photo_folder();
    if (!is_dir($folder) && !mkdir($folder, 0755, true)) {
        throw new RuntimeException("The photo folder is unavailable.");
    }
    $name = bin2hex(random_bytes(16)) . "." . $extensions[$mime];
    $target = $folder . DIRECTORY_SEPARATOR . $name;
    if (!(is_uploaded_file((string) $upload["tmp_name"])
        ? move_uploaded_file((string) $upload["tmp_name"], $target)
        : rename((string) $upload["tmp_name"], $target))) {
        throw new RuntimeException("The photo could not be saved.");
    }
    return "uploads/directions/" . $name;
}

/** Removes photos no step uses any more. A photo copied to another department stays. */
function office_directions_remove_unused_photos(mysqli $conn, array $paths): void
{
    foreach (array_unique($paths) as $path) {
        if ($path === "" || !preg_match(OFFICE_DIRECTIONS_PHOTO_PATTERN, (string) $path)) {
            continue;
        }
        $stmt = $conn->prepare("SELECT COUNT(*) FROM office_direction_steps WHERE photo_path = ?");
        $stmt->bind_param("s", $path);
        $stmt->execute();
        $used = (int) $stmt->get_result()->fetch_row()[0] > 0;
        $stmt->close();
        if (!$used) {
            @unlink(office_directions_photo_folder() . DIRECTORY_SEPARATOR . basename((string) $path));
        }
    }
}

/**
 * Replaces a department's directions. The Department Directory's location follows the building,
 * floor and room, so every screen names the office the same way. Blank directions leave that
 * location alone. The caller checks the permission and has read the input.
 */
function office_directions_save(mysqli $conn, string $officeCode, array $directions, int $userId): void
{
    $previous = office_directions_load($conn, $officeCode)[$officeCode] ?? office_directions_blank();
    $where = office_directions_where($directions);
    $empty = $where === "" && $directions["visitor_message"] === "" && !$directions["steps"];

    $conn->begin_transaction();
    try {
        $clear = $conn->prepare("DELETE FROM office_direction_steps WHERE office_code = ?");
        $clear->bind_param("s", $officeCode);
        $clear->execute();
        $clear->close();
        if ($empty) {
            $remove = $conn->prepare("DELETE FROM office_directions WHERE office_code = ?");
            $remove->bind_param("s", $officeCode);
            $remove->execute();
            $remove->close();
        } else {
            $save = $conn->prepare(
                "INSERT INTO office_directions (office_code, building, floor, room, visitor_message, updated_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE building = VALUES(building), floor = VALUES(floor), room = VALUES(room),
                     visitor_message = VALUES(visitor_message), updated_by_user_id = VALUES(updated_by_user_id)"
            );
            $save->bind_param("sssssi", $officeCode, $directions["building"], $directions["floor"], $directions["room"], $directions["visitor_message"], $userId);
            $save->execute();
            $save->close();
            $insert = $conn->prepare("INSERT INTO office_direction_steps (office_code, sort_order, step_text, photo_path) VALUES (?, ?, ?, ?)");
            foreach ($directions["steps"] as $index => $step) {
                $order = $index + 1;
                $insert->bind_param("siss", $officeCode, $order, $step["text"], $step["photo"]);
                $insert->execute();
            }
            $insert->close();
        }
        if ($where !== "") {
            $location = $conn->prepare("UPDATE offices SET location = ? WHERE code = ?");
            $location->bind_param("ss", $where, $officeCode);
            $location->execute();
            $location->close();
        }
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    office_directions_remove_unused_photos($conn, array_column($previous["steps"], "photo"));
}
