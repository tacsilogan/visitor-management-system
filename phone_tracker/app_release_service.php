<?php
/*
 * The visitor app on the website (GROUP_VISITS.md, "Getting the app"). The Admin uploads a new
 * version in Admin › Menu › Visitor app; the server checks the file, keeps it outside the web
 * folder, and serves the current version at download_app.php from the site's main page. The
 * version before it is kept so the Admin can go back.
 *
 * The checks read the APK itself, never trusting its name: it must be a ZIP with an Android
 * manifest for ISATU Visits (APP_RELEASE_PACKAGE), newer than the current version, and signed
 * with the same certificate. The certificate comes from the APK Signing Block (schemes v2 and
 * v3; the app has minSdk 26, so it has no v1 signature in META-INF). Android itself verifies the
 * signature when installing; this check stops a different or fake build from being published.
 */

require_once __DIR__ . "/runtime.php";

const APP_RELEASE_PACKAGE = "ph.edu.isatu.visitor";
const APP_RELEASE_NAME = "ISATU Visits";
const APP_RELEASE_MAX_BYTES = 100 * 1024 * 1024;
const APP_RELEASE_WHATS_NEW_MAX = 160;

/** Whether group_visits_migration.sql is installed. */
function app_release_schema_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        $result = $conn->query(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_releases'"
        );
        $ready = $result && (int) $result->fetch_row()[0] > 0;
    }
    return $ready;
}

/**
 * Where the app files are kept: the persistent volume's private folder on the hosted server
 * (ISATU_DATA_DIR, never served by Apache), or phone_tracker/private/apps on a laptop, which
 * phone_tracker/private/.htaccess closes to browsers.
 */
function app_release_dir(): string
{
    $data = trim((string) isatu_env("ISATU_DATA_DIR", ""));
    $dir = $data !== "" ? rtrim($data, "/\\") . "/private/apps" : __DIR__ . "/private/apps";
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("Can't create the folder for app files: {$dir}");
    }
    return $dir;
}

/**
 * The site's main address, where visitors get the app: PUBLIC_BASE_URL without its
 * phone_tracker part, else the address of this request up to the phone_tracker folder.
 */
function app_release_site_url(): string
{
    $configured = trim((string) (getenv("PUBLIC_BASE_URL") ?: ""));
    if ($configured !== "") {
        return (string) preg_replace('#/phone_tracker/?$#', "", rtrim($configured, "/")) . "/";
    }
    $host = (string) ($_SERVER["HTTP_HOST"] ?? "");
    if ($host === "") {
        $railway = trim((string) (getenv("RAILWAY_PUBLIC_DOMAIN") ?: ""));
        return $railway !== "" ? "https://" . $railway . "/" : "";
    }
    $https = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") || (int) ($_SERVER["SERVER_PORT"] ?? 0) === 443;
    $script = (string) ($_SERVER["SCRIPT_NAME"] ?? "/");
    $cut = strpos($script, "/phone_tracker/");
    $path = $cut !== false ? substr($script, 0, $cut) : rtrim(dirname($script), "/\\");
    return ($https ? "https" : "http") . "://" . $host . $path . "/";
}

/* ---------- Reading an APK ---------- */

/** Reads [$length] bytes at [$offset], or throws when the file is shorter. */
function app_release_read($handle, int $offset, int $length): string
{
    if ($length === 0) {
        return "";
    }
    if ($offset < 0 || $length < 0 || fseek($handle, $offset) !== 0) {
        throw new InvalidArgumentException("This file is damaged or isn't an Android app.");
    }
    $data = "";
    while (strlen($data) < $length) {
        $chunk = fread($handle, $length - strlen($data));
        if ($chunk === false || $chunk === "") {
            throw new InvalidArgumentException("This file is damaged or isn't an Android app.");
        }
        $data .= $chunk;
    }
    return $data;
}

function app_release_u16(string $data, int $at): int
{
    if ($at < 0 || $at + 2 > strlen($data)) {
        throw new InvalidArgumentException("This file is damaged or isn't an Android app.");
    }
    return unpack("v", $data, $at)[1];
}

function app_release_u32(string $data, int $at): int
{
    if ($at < 0 || $at + 4 > strlen($data)) {
        throw new InvalidArgumentException("This file is damaged or isn't an Android app.");
    }
    return unpack("V", $data, $at)[1];
}

function app_release_u64(string $data, int $at): int
{
    if ($at < 0 || $at + 8 > strlen($data)) {
        throw new InvalidArgumentException("This file is damaged or isn't an Android app.");
    }
    $value = unpack("P", $data, $at)[1];
    if ($value < 0) {
        throw new InvalidArgumentException("This file is damaged or isn't an Android app.");
    }
    return $value;
}

/** A uint32-length-prefixed part of [$data] at [$at]: [the bytes, the offset after it]. */
function app_release_prefixed(string $data, int $at): array
{
    $length = app_release_u32($data, $at);
    if ($at + 4 + $length > strlen($data)) {
        throw new InvalidArgumentException("This app's signature is damaged.");
    }
    return [substr($data, $at + 4, $length), $at + 4 + $length];
}

/**
 * What the server needs from an APK: its package, version and signing certificate.
 *
 * @return array{package: string, version_code: int, version_name: string, signing_sha256: string, signature_scheme: string}
 * @throws InvalidArgumentException with a message for the Admin
 */
function app_release_inspect(string $path): array
{
    $size = @filesize($path);
    if ($size === false || $size < 22) {
        throw new InvalidArgumentException("This file is empty or isn't an Android app.");
    }
    $handle = @fopen($path, "rb");
    if (!$handle) {
        throw new RuntimeException("Can't open the uploaded file.");
    }
    try {
        // The end of central directory record: within the last 64 KB (it ends with an optional comment).
        $tailLength = min($size, 65557);
        $tail = app_release_read($handle, $size - $tailLength, $tailLength);
        $eocd = strrpos($tail, "PK\x05\x06");
        if ($eocd === false || $eocd + 22 > strlen($tail)) {
            throw new InvalidArgumentException("This isn't an Android app file (.apk).");
        }
        $entries = app_release_u16($tail, $eocd + 10);
        $cdSize = app_release_u32($tail, $eocd + 12);
        $cdOffset = app_release_u32($tail, $eocd + 16);
        if ($cdSize > 8 * 1024 * 1024 || $cdOffset + $cdSize > $size || $entries === 0) {
            throw new InvalidArgumentException("This isn't an Android app file (.apk).");
        }
        $directory = app_release_read($handle, $cdOffset, $cdSize);

        // The files in it: the manifest and the compiled code must be there.
        $manifest = null;
        $hasDex = false;
        $at = 0;
        for ($index = 0; $index < $entries; $index++) {
            if (app_release_u32($directory, $at) !== 0x02014b50) {
                throw new InvalidArgumentException("This file is damaged or isn't an Android app.");
            }
            $method = app_release_u16($directory, $at + 10);
            $compressed = app_release_u32($directory, $at + 20);
            $uncompressed = app_release_u32($directory, $at + 24);
            $nameLength = app_release_u16($directory, $at + 28);
            $extraLength = app_release_u16($directory, $at + 30);
            $commentLength = app_release_u16($directory, $at + 32);
            $localOffset = app_release_u32($directory, $at + 42);
            $name = substr($directory, $at + 46, $nameLength);
            if ($name === "AndroidManifest.xml") {
                $manifest = ["method" => $method, "compressed" => $compressed, "uncompressed" => $uncompressed, "offset" => $localOffset];
            } elseif ($name === "classes.dex") {
                $hasDex = true;
            }
            $at += 46 + $nameLength + $extraLength + $commentLength;
        }
        if (!$manifest || !$hasDex) {
            throw new InvalidArgumentException("This isn't an Android app file (.apk).");
        }
        if ($manifest["uncompressed"] > 4 * 1024 * 1024 || $manifest["compressed"] > 4 * 1024 * 1024 || !in_array($manifest["method"], [0, 8], true)) {
            throw new InvalidArgumentException("This app's manifest can't be read.");
        }
        $local = app_release_read($handle, $manifest["offset"], 30);
        if (app_release_u32($local, 0) !== 0x04034b50) {
            throw new InvalidArgumentException("This file is damaged or isn't an Android app.");
        }
        $dataOffset = $manifest["offset"] + 30 + app_release_u16($local, 26) + app_release_u16($local, 28);
        $raw = app_release_read($handle, $dataOffset, $manifest["compressed"]);
        $xml = $manifest["method"] === 8 ? @gzinflate($raw, 4 * 1024 * 1024) : $raw;
        if (!is_string($xml) || $xml === "") {
            throw new InvalidArgumentException("This app's manifest can't be read.");
        }
        $info = app_release_manifest_info($xml);

        // The APK Signing Block sits just before the central directory.
        if ($cdOffset < 32) {
            throw new InvalidArgumentException("This app isn't signed.");
        }
        $footer = app_release_read($handle, $cdOffset - 24, 24);
        if (substr($footer, 8, 16) !== "APK Sig Block 42") {
            throw new InvalidArgumentException("This app isn't signed with a current Android signature (v2 or v3).");
        }
        $blockSize = app_release_u64($footer, 0);
        if ($blockSize < 24 || $blockSize > 16 * 1024 * 1024 || $blockSize + 8 > $cdOffset) {
            throw new InvalidArgumentException("This app's signature is damaged.");
        }
        $block = app_release_read($handle, $cdOffset - $blockSize - 8, $blockSize + 8);
        [$certificate, $scheme] = app_release_signing_certificate($block);
    } finally {
        fclose($handle);
    }
    return $info + ["signing_sha256" => hash("sha256", $certificate), "signature_scheme" => $scheme];
}

/**
 * The first signer's certificate (DER) in an APK Signing Block, from the v3 scheme when there is
 * one (it names the current key), else v2.
 *
 * @return array{0: string, 1: string}
 */
function app_release_signing_certificate(string $block): array
{
    $schemes = [0xf05368c0 => "v3", 0x7109871a => "v2"];
    $found = [];
    $at = 8;
    $end = strlen($block) - 24;
    while ($at < $end) {
        $length = app_release_u64($block, $at);
        if ($length < 4 || $at + 8 + $length > $end) {
            throw new InvalidArgumentException("This app's signature is damaged.");
        }
        $id = app_release_u32($block, $at + 8);
        if (isset($schemes[$id])) {
            $found[$id] = substr($block, $at + 12, $length - 4);
        }
        $at += 8 + $length;
    }
    foreach ($schemes as $id => $scheme) {
        if (!isset($found[$id])) {
            continue;
        }
        // signers: [signer: [signed data: [digests][certificates: [certificate]…]…]…]
        [$signers] = app_release_prefixed($found[$id], 0);
        [$signer] = app_release_prefixed($signers, 0);
        [$signedData] = app_release_prefixed($signer, 0);
        [, $afterDigests] = app_release_prefixed($signedData, 0);
        [$certificates] = app_release_prefixed($signedData, $afterDigests);
        [$certificate] = app_release_prefixed($certificates, 0);
        if (strlen($certificate) < 64) {
            throw new InvalidArgumentException("This app's signature is damaged.");
        }
        return [$certificate, $scheme];
    }
    throw new InvalidArgumentException("This app isn't signed with a current Android signature (v2 or v3).");
}

/**
 * Package, versionCode and versionName from a compiled (binary) AndroidManifest.xml.
 *
 * @return array{package: string, version_code: int, version_name: string}
 */
function app_release_manifest_info(string $xml): array
{
    if (strlen($xml) < 8 || app_release_u16($xml, 0) !== 0x0003) {
        throw new InvalidArgumentException("This app's manifest can't be read.");
    }
    $strings = [];
    $resourceIds = [];
    $at = app_release_u16($xml, 2);
    $length = strlen($xml);
    while ($at + 8 <= $length) {
        $type = app_release_u16($xml, $at);
        $headerSize = app_release_u16($xml, $at + 2);
        $chunkSize = app_release_u32($xml, $at + 4);
        if ($chunkSize < 8 || $at + $chunkSize > $length) {
            throw new InvalidArgumentException("This app's manifest can't be read.");
        }
        if ($type === 0x0001) {
            $strings = app_release_string_pool(substr($xml, $at, $chunkSize));
        } elseif ($type === 0x0180) {
            for ($offset = $headerSize; $offset + 4 <= $chunkSize; $offset += 4) {
                $resourceIds[] = app_release_u32($xml, $at + $offset);
            }
        } elseif ($type === 0x0102) {
            // The first element is <manifest>.
            $ext = $at + $headerSize;
            $name = $strings[app_release_u32($xml, $ext + 4)] ?? "";
            if ($name !== "manifest") {
                throw new InvalidArgumentException("This app's manifest can't be read.");
            }
            $attributeStart = app_release_u16($xml, $ext + 8);
            $attributeSize = app_release_u16($xml, $ext + 10);
            $attributeCount = app_release_u16($xml, $ext + 12);
            $values = [];
            for ($index = 0; $index < $attributeCount; $index++) {
                $attr = $ext + $attributeStart + $index * $attributeSize;
                $nameIndex = app_release_u32($xml, $attr + 4);
                $rawValue = app_release_u32($xml, $attr + 8);
                $dataType = ord($xml[$attr + 15] ?? "\0");
                $data = app_release_u32($xml, $attr + 16);
                $attrName = $strings[$nameIndex] ?? "";
                // Compiled manifests may drop attribute names; android:versionCode and
                // android:versionName are then known by their resource ids.
                $resourceId = $resourceIds[$nameIndex] ?? 0;
                if ($resourceId === 0x0101021b) {
                    $attrName = "versionCode";
                } elseif ($resourceId === 0x0101021c) {
                    $attrName = "versionName";
                }
                if ($rawValue !== 0xFFFFFFFF && isset($strings[$rawValue])) {
                    $values[$attrName] = $strings[$rawValue];
                } elseif ($dataType === 0x03 && isset($strings[$data])) {
                    $values[$attrName] = $strings[$data];
                } elseif ($dataType >= 0x10 && $dataType <= 0x1f) {
                    $values[$attrName] = $data;
                }
            }
            $package = (string) ($values["package"] ?? "");
            $versionCode = (int) ($values["versionCode"] ?? 0);
            $versionName = trim((string) ($values["versionName"] ?? ""));
            if ($package === "" || $versionCode <= 0 || $versionName === "") {
                throw new InvalidArgumentException("This app's manifest has no package or version.");
            }
            return ["package" => $package, "version_code" => $versionCode, "version_name" => mb_substr($versionName, 0, 32)];
        }
        $at += $chunkSize;
    }
    throw new InvalidArgumentException("This app's manifest can't be read.");
}

/** The strings of a binary XML string pool chunk (UTF-8 or UTF-16). */
function app_release_string_pool(string $chunk): array
{
    $count = app_release_u32($chunk, 8);
    $flags = app_release_u32($chunk, 16);
    $stringsStart = app_release_u32($chunk, 20);
    $utf8 = ($flags & 0x100) !== 0;
    if ($count > 100000) {
        throw new InvalidArgumentException("This app's manifest can't be read.");
    }
    $strings = [];
    for ($index = 0; $index < $count; $index++) {
        $at = $stringsStart + app_release_u32($chunk, 28 + $index * 4);
        if ($utf8) {
            // Two lengths, each in 1 or 2 bytes: characters, then bytes.
            $chars = ord($chunk[$at] ?? "\0");
            $at += ($chars & 0x80) ? 2 : 1;
            $bytes = ord($chunk[$at] ?? "\0");
            if ($bytes & 0x80) {
                $bytes = (($bytes & 0x7f) << 8) | ord($chunk[$at + 1] ?? "\0");
                $at += 2;
            } else {
                $at += 1;
            }
            $strings[] = (string) substr($chunk, $at, $bytes);
        } else {
            $chars = app_release_u16($chunk, $at);
            $at += 2;
            if ($chars & 0x8000) {
                $chars = (($chars & 0x7fff) << 16) | app_release_u16($chunk, $at);
                $at += 2;
            }
            $strings[] = (string) mb_convert_encoding(substr($chunk, $at, $chars * 2), "UTF-8", "UTF-16LE");
        }
    }
    return $strings;
}

/* ---------- Releases ---------- */

/** "0.13.0" → comparable with version_compare(). */
function app_release_row(array $row): array
{
    return [
        "id" => (int) $row["id"],
        "version_name" => (string) $row["version_name"],
        "version_code" => (int) $row["version_code"],
        "file_size" => (int) $row["file_size"],
        "signing_sha256" => (string) $row["signing_sha256"],
        "whats_new" => (string) $row["whats_new"],
        "status" => (string) $row["status"],
        "uploaded_at" => $row["uploaded_at"],
        "published_at" => $row["published_at"],
        "published_by" => isset($row["published_by"]) ? (string) $row["published_by"] : null,
    ];
}

/** The release with a status ("current", "previous", "checked"), newest first. */
function app_release_by_status(mysqli $conn, string $status): ?array
{
    if (!app_release_schema_ready($conn)) {
        return null;
    }
    $stmt = $conn->prepare(
        "SELECT r.*, COALESCE(NULLIF(u.display_name, ''), u.username) AS published_by
         FROM app_releases r LEFT JOIN app_users u ON u.id = r.published_by_user_id
         WHERE r.status = ? ORDER BY r.version_code DESC, r.id DESC LIMIT 1"
    );
    $stmt->bind_param("s", $status);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function app_release_current(mysqli $conn): ?array
{
    return app_release_by_status($conn, "current");
}

/** The certificate every new version must carry: the server's setting, else the current version's. */
function app_release_expected_key(mysqli $conn): ?string
{
    $configured = strtolower(preg_replace('/[^a-fA-F0-9]/', "", (string) isatu_env("ISATU_APP_SIGNING_SHA256", "")));
    if (strlen($configured) === 64) {
        return $configured;
    }
    $current = app_release_current($conn);
    if ($current) {
        return (string) $current["signing_sha256"];
    }
    $previous = app_release_by_status($conn, "previous");
    return $previous ? (string) $previous["signing_sha256"] : null;
}

/** "AB:CD:…" for the first bytes of a fingerprint, as apksigner prints it. */
function app_release_key_label(string $sha256): string
{
    return strtoupper(implode(":", str_split(substr($sha256, 0, 16), 2))) . "…";
}

function app_release_delete_file(string $fileName): void
{
    if ($fileName === "" || basename($fileName) !== $fileName) {
        return;
    }
    $path = app_release_dir() . "/" . $fileName;
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * Checks an uploaded APK and keeps it, ready to publish. Only one checked file waits at a time.
 *
 * @return array{release: array, checks: list<string>}
 * @throws InvalidArgumentException with a message for the Admin
 */
function app_release_check_upload(mysqli $conn, string $tmpPath, int $size, int $adminUserId): array
{
    if (!app_release_schema_ready($conn)) {
        throw new InvalidArgumentException("Database update required: import phone_tracker/group_visits_migration.sql.");
    }
    if ($size <= 0 || $size > APP_RELEASE_MAX_BYTES) {
        throw new InvalidArgumentException("Choose an app file of up to " . (APP_RELEASE_MAX_BYTES / 1024 / 1024) . " MB.");
    }
    $info = app_release_inspect($tmpPath);
    if ($info["package"] !== APP_RELEASE_PACKAGE) {
        throw new InvalidArgumentException("This is a different app ({$info["package"]}), not " . APP_RELEASE_NAME . ".");
    }
    $current = app_release_current($conn);
    if ($current && $info["version_code"] <= (int) $current["version_code"]) {
        throw new InvalidArgumentException(
            "This is version {$info["version_name"]}, which isn't newer than the current version, {$current["version_name"]}. "
            . "Raise versionCode and versionName in visitor_app/app/build.gradle.kts, then build it again."
        );
    }
    $expected = app_release_expected_key($conn);
    if ($expected !== null && !hash_equals($expected, $info["signing_sha256"])) {
        throw new InvalidArgumentException(
            "This file is signed with a different key (" . app_release_key_label($info["signing_sha256"]) . ") than "
            . APP_RELEASE_NAME . " (" . app_release_key_label($expected) . "). Phones would refuse it as an update. "
            . "Build the release with ISATU's own key (visitor_app/keystore.properties)."
        );
    }
    $fileHash = hash_file("sha256", $tmpPath);
    $same = $conn->prepare("SELECT version_name FROM app_releases WHERE file_sha256 = ? AND status <> 'retired' LIMIT 1");
    $same->bind_param("s", $fileHash);
    $same->execute();
    $duplicate = $same->get_result()->fetch_assoc();
    $same->close();
    if ($duplicate) {
        throw new InvalidArgumentException("This exact file is already here, as version {$duplicate["version_name"]}.");
    }

    // One checked file at a time: an earlier one that was never published goes.
    foreach ($conn->query("SELECT id, file_name FROM app_releases WHERE status = 'checked'")->fetch_all(MYSQLI_ASSOC) as $old) {
        app_release_delete_file((string) $old["file_name"]);
        $conn->query("DELETE FROM app_releases WHERE id = " . (int) $old["id"]);
    }
    $fileName = "isatu-visits-" . preg_replace('/[^0-9A-Za-z.\-]/', "", $info["version_name"]) . "-" . substr($fileHash, 0, 12) . ".apk";
    $target = app_release_dir() . "/" . $fileName;
    $moved = is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $target) : copy($tmpPath, $target);
    if (!$moved) {
        throw new RuntimeException("Can't save the app file.");
    }
    @chmod($target, 0600);
    $stmt = $conn->prepare(
        "INSERT INTO app_releases
         (version_name, version_code, package_name, file_name, file_size, file_sha256, signing_sha256, status, uploaded_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'checked', ?)"
    );
    $stmt->bind_param(
        "sississi",
        $info["version_name"], $info["version_code"], $info["package"], $fileName, $size, $fileHash, $info["signing_sha256"], $adminUserId
    );
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();

    $checks = [
        "An Android app: " . APP_RELEASE_NAME . ", version " . $info["version_name"],
        $current ? "Newer than the current version, " . $current["version_name"] : "The first version on the website",
        $expected !== null
            ? "Signed with the same key as " . ($current ? $current["version_name"] : "before") . " (" . app_release_key_label($info["signing_sha256"]) . "), so it is ISATU's own app"
            : "Signed with key " . app_release_key_label($info["signing_sha256"]) . ". Every later version must use this same key",
    ];
    $row = app_release_by_status($conn, "checked");
    if (!$row || (int) $row["id"] !== $id) {
        throw new RuntimeException("The checked app file wasn't saved.");
    }
    return ["release" => app_release_row($row), "checks" => $checks];
}

/**
 * Makes a checked upload the version visitors download. The current one becomes the previous
 * (kept, to go back to); the one before that is removed.
 */
function app_release_publish(mysqli $conn, int $releaseId, string $whatsNew, int $adminUserId): array
{
    $whatsNew = mb_substr(trim((string) preg_replace('/\s+/u', " ", $whatsNew)), 0, APP_RELEASE_WHATS_NEW_MAX);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT * FROM app_releases WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $releaseId);
        $stmt->execute();
        $release = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$release || $release["status"] !== "checked") {
            throw new DomainException("Choose the app file again: this one is no longer waiting to be published.");
        }
        $current = app_release_current($conn);
        if ($current && (int) $release["version_code"] <= (int) $current["version_code"]) {
            throw new DomainException("A newer version was published meanwhile. Upload a newer file.");
        }
        app_release_retire_previous($conn);
        $conn->query("UPDATE app_releases SET status = 'previous' WHERE status = 'current'");
        $publish = $conn->prepare(
            "UPDATE app_releases SET status = 'current', whats_new = ?, published_at = NOW(), published_by_user_id = ? WHERE id = ?"
        );
        $publish->bind_param("sii", $whatsNew, $adminUserId, $releaseId);
        $publish->execute();
        $publish->close();
        app_release_audit($conn, $adminUserId, "app_release.published", $releaseId, [
            "version_name" => $release["version_name"],
            "version_code" => (int) $release["version_code"],
            "previous_version" => $current["version_name"] ?? null,
        ]);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    return app_release_row(app_release_current($conn));
}

/** Goes back to the version before: it becomes current again, and the newer one is kept as previous. */
function app_release_go_back(mysqli $conn, int $adminUserId): array
{
    $conn->begin_transaction();
    try {
        $previous = app_release_by_status($conn, "previous");
        $current = app_release_current($conn);
        if (!$previous || !$current) {
            throw new DomainException("There is no earlier version to go back to.");
        }
        $swap = $conn->prepare(
            "UPDATE app_releases SET status = IF(id = ?, 'current', 'previous'), published_at = IF(id = ?, NOW(), published_at),
                    published_by_user_id = IF(id = ?, ?, published_by_user_id)
             WHERE id IN (?, ?)"
        );
        $previousId = (int) $previous["id"];
        $currentId = (int) $current["id"];
        $swap->bind_param("iiiiii", $previousId, $previousId, $previousId, $adminUserId, $previousId, $currentId);
        $swap->execute();
        $swap->close();
        app_release_audit($conn, $adminUserId, "app_release.went_back", $previousId, [
            "version_name" => $previous["version_name"],
            "from_version" => $current["version_name"],
        ]);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    return app_release_row(app_release_current($conn));
}

/** Removes the version before the previous one, and its file. */
function app_release_retire_previous(mysqli $conn): void
{
    foreach ($conn->query("SELECT id, file_name FROM app_releases WHERE status = 'previous'")->fetch_all(MYSQLI_ASSOC) as $old) {
        app_release_delete_file((string) $old["file_name"]);
        $conn->query("UPDATE app_releases SET status = 'retired' WHERE id = " . (int) $old["id"]);
    }
}

/** Drops a checked upload that won't be published. */
function app_release_discard(mysqli $conn, int $releaseId): void
{
    $stmt = $conn->prepare("SELECT file_name FROM app_releases WHERE id = ? AND status = 'checked'");
    $stmt->bind_param("i", $releaseId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        app_release_delete_file((string) $row["file_name"]);
        $delete = $conn->prepare("DELETE FROM app_releases WHERE id = ? AND status = 'checked'");
        $delete->bind_param("i", $releaseId);
        $delete->execute();
        $delete->close();
    }
}

function app_release_audit(mysqli $conn, int $actorUserId, string $action, int $releaseId, array $details): void
{
    $stmt = $conn->prepare(
        "INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, details_json, ip_address)
         VALUES (?, ?, 'app_release', ?, ?, ?)"
    );
    if (!$stmt) {
        return;
    }
    $entityId = (string) $releaseId;
    $json = (string) json_encode($details);
    $ip = isset($_SERVER["REMOTE_ADDR"]) ? substr((string) $_SERVER["REMOTE_ADDR"], 0, 45) : "";
    $stmt->bind_param("issss", $actorUserId, $action, $entityId, $json, $ip);
    $stmt->execute();
    $stmt->close();
}

/**
 * For Admin › Visitor app: downloads in all and this week, and how many phones that used the app
 * in the last 30 days run an older version.
 *
 * @return array{downloads: int, downloads_week: int, older_phones: int}
 */
function app_release_stats(mysqli $conn): array
{
    $stats = ["downloads" => 0, "downloads_week" => 0, "older_phones" => 0];
    if (!app_release_schema_ready($conn)) {
        return $stats;
    }
    $row = $conn->query(
        "SELECT COUNT(*) AS downloads, COALESCE(SUM(downloaded_at >= NOW() - INTERVAL 7 DAY), 0) AS downloads_week FROM app_release_downloads"
    )->fetch_assoc();
    $stats["downloads"] = (int) $row["downloads"];
    $stats["downloads_week"] = (int) $row["downloads_week"];
    $current = app_release_current($conn);
    if ($current) {
        $result = $conn->query(
            "SELECT app_version FROM mobile_devices WHERE is_active = 1 AND last_seen_at >= NOW() - INTERVAL 30 DAY AND app_version <> ''"
        );
        foreach ($result ? $result->fetch_all(MYSQLI_ASSOC) : [] as $device) {
            // "0.12.0" or "0.12.0 (17)": compare the version name.
            $version = (string) preg_replace('/[^0-9.].*$/', "", (string) $device["app_version"]);
            if ($version !== "" && version_compare($version, (string) $current["version_name"], "<")) {
                $stats["older_phones"]++;
            }
        }
    }
    return $stats;
}

/** Counts a download (only when, never who). */
function app_release_count_download(mysqli $conn, int $releaseId): void
{
    $stmt = $conn->prepare("INSERT INTO app_release_downloads (release_id) VALUES (?)");
    $stmt->bind_param("i", $releaseId);
    $stmt->execute();
    $stmt->close();
}

/** "39.8 MB" */
function app_release_size_label(int $bytes): string
{
    return number_format($bytes / 1024 / 1024, 1) . " MB";
}
