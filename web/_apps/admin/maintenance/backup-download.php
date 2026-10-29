<?php
// Path: _apps/admin/maintenance/backup-download.php
/**
 * -----------------------------------------------------------------------------
 * Admin — download a snapshot as one encrypted file 📥🔐 (#569)
 * -----------------------------------------------------------------------------
 * The form target for the Download button on /admin/maintenance/backup. Packs
 * one snapshot folder into a single encrypted file (`Portal\Core\BackupArchive`)
 * and sends it to the browser.
 *
 * WHY THIS IS A POST, WHICH LOOKS WRONG FOR A DOWNLOAD
 * -----------------------------------------------------------------------------
 * Because the key is typed in, and a key must never travel in an address. An
 * address reaches the server's access log, the browser's history, the
 * `Referer` header of the next request, and any proxy in between. A GET
 * download with `?key=…` would write the one secret protecting every member's
 * personal data into at least three places that are not meant to hold secrets
 * and are rarely treated as if they do.
 *
 * So the key is posted, the file comes back as the answer to that POST, and the
 * key appears in no log anywhere. The cost is that the browser cannot resume an
 * interrupted download, which is the right trade.
 *
 * WHAT IT DOES, IN ORDER
 *   1. Signed in, an administrator, and a GLOBAL administrator — the same gate
 *      as the Backup page itself, because a backup holds every organisation's
 *      data and not just the one the person administers.
 *   2. The form token.
 *   3. The snapshot name, through `DbBackup::isSnapshotName()` — the one test
 *      for a name from a browser, before any path is built.
 *   4. Packs into a temporary file inside `_backups/`, which is OUTSIDE the web
 *      root, so a half-written download can never be fetched by address.
 *   5. Streams it out and removes the temporary file. The removal is a
 *      SHUTDOWN FUNCTION, registered before the file exists, because PHP ends
 *      the script the moment it notices the browser has gone — so a line after
 *      the sending loop is not reached on a cancelled download. A shutdown
 *      function is, and so is `ignore_user_abort(true)` keeping the script
 *      alive long enough to get there.
 *
 * WHY IT PACKS TO A FILE FIRST RATHER THAN STRAIGHT TO THE BROWSER
 * -----------------------------------------------------------------------------
 * So that a failure happens BEFORE anything is sent. Once bytes have gone, the
 * HTTP answer cannot be taken back: the browser would save a part file with no
 * way of knowing it was incomplete, and a part backup is worse than none
 * because somebody keeps it believing it is a copy of their data. Packing first
 * means a failure can still be an error page.
 *
 * It costs disk: briefly, the snapshot exists twice. The alternative costs
 * correctness.
 *
 * WHAT IS NEVER RECORDED
 *   The key. Not in the activity log, not in an error, not in a flash message.
 *   The activity log records THAT a backup was downloaded, by whom, and which
 *   snapshot — which is exactly what an audit needs — and nothing about how it
 *   was protected beyond which of the two ways was chosen.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/569
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\BackupArchive;
use Portal\Core\DbBackup;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$backTo = Site::url('admin/maintenance/backup');

/** Send the administrator back with a message. Never carries the key. */
$refuse = static function (string $message) use ($backTo): void {
    // 🧼 The key is wiped on the way out of every refusal too, not only after
    //    a successful pack. Not a strong guarantee in PHP — a string may already
    //    have been copied — but free, and it shortens the window in which a
    //    crash dump could hold it.
    if (isset($_POST['passphrase']) === true && is_string($_POST['passphrase']) === true) {
        sodium_memzero($_POST['passphrase']);
    }
    if (isset($_POST['passphraseAgain']) === true && is_string($_POST['passphraseAgain']) === true) {
        sodium_memzero($_POST['passphraseAgain']);
    }
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $backTo);
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $backTo);
    return;
}

// 🔐 Form token FIRST, before anything with a side effect.
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $refuse('Invalid or expired form token. Nothing was downloaded. Please try again.');
    return;
}

// 🚧 A GLOBAL administrator only. A snapshot holds every organisation on the
//    installation, so an administrator of one must not be able to take a copy
//    of the others. Checked here and not merely hidden on the page.
if (App::isRootAdmin() === false) {
    Logger::activity(
        'BackupDownloadRefused',
        'Refused: a backup holds every organisation on this installation, so only a global '
        . 'administrator may download one',
        isset($_SESSION['user_id']) === true ? (int) $_SESSION['user_id'] : null
    );
    $refuse(
        'A backup holds the data of every organisation on this installation, not only yours, so '
        . 'only a global administrator can download one. Nothing was downloaded.'
    );
    return;
}

$userId = isset($_SESSION['user_id']) === true ? (int) $_SESSION['user_id'] : null;

// ✅ The snapshot's name, through the one test for a name from a browser.
$name = $_POST['name'] ?? '';
if (DbBackup::isSnapshotName($name) === false) {
    $refuse('That is not a snapshot name. Nothing was downloaded.');
    return;
}

// ✅ How it is to be protected.
$keyMode = (string) ($_POST['keyMode'] ?? BackupArchive::KEY_PASSPHRASE);
if (in_array($keyMode, BackupArchive::KEY_MODES, true) === false) {
    $refuse('That is not a way of protecting a backup. Nothing was downloaded.');
    return;
}

// ✅ The key. A posted array must not reach strlen(), so the type is checked
//    before the length — a request chooses the TYPE of what it sends as well as
//    the value, and this project has shipped one sign-in bypass that began
//    exactly there.
$passphrase = $_POST['passphrase'] ?? '';
if (is_string($passphrase) === false) {
    $refuse('The key could not be read. Nothing was downloaded.');
    return;
}
if (strlen($passphrase) < BackupArchive::MIN_PASSPHRASE) {
    $refuse(
        'The key must be at least ' . BackupArchive::MIN_PASSPHRASE . ' characters, because it is '
        . 'the only thing protecting everyone\'s personal details in that file. Nothing was downloaded.'
    );
    return;
}
if ($passphrase !== (string) ($_POST['passphraseAgain'] ?? '')) {
    // Typed twice on purpose: a key that cannot be recovered is a file that
    // cannot be opened, and a typo would only be discovered on the day it
    // mattered.
    $refuse('The two keys do not match. Nothing was downloaded, so nothing is lost — please try again.');
    return;
}

$backup   = new DbBackup($mysqli);
$snapshot = $backup->pathOf((string) $name);
if (is_dir($snapshot) === false) {
    $refuse('There is no snapshot of that name. Nothing was downloaded.');
    return;
}

// 🔑 The key file, only when it was asked for.
$keyFile = null;
if ($keyMode === BackupArchive::KEY_BOTH) {
    $keyFile = portal_key_file_contents();
    if ($keyFile === null) {
        $refuse(
            'This backup was to include the installation\'s own key file, and there is no key file '
            . 'to include. Nothing was downloaded.'
        );
        return;
    }
}

// 📦 Pack into the backups folder, which is outside the web root. A random
//    name, so two downloads at once cannot collide and nothing is predictable.
$temporary = $snapshot . '-download-' . bin2hex(random_bytes(8)) . '.' . BackupArchive::EXTENSION;

// 🧹 REGISTERED BEFORE THE FILE EXISTS, so there is no path on which it is
//    missed. A shutdown function runs even when the browser goes away and even
//    when PHP hits its time limit, which is exactly what the `@unlink` at the
//    end of this file does NOT cover.
//
//    THIS WAS A REAL LEAK, measured by the independent check: PHP stops the
//    script at the `echo`/`flush()` that first notices the connection has gone,
//    so the `connection_aborted()` test in the sending loop never gets a turn
//    and the line after the loop never runs. A cancelled download therefore
//    left a full encrypted copy of the database in `_backups/`, which nothing
//    ever removes (`prune()` only deletes folders named `upgrade-…`). A few
//    cancelled downloads of a large database would fill a shared-hosting quota
//    — against this page's own reasoning that leaving one "would quietly double
//    the places that data sits". Two comments here said it was always removed;
//    both were wrong and are corrected below.
//
//    NOT PROVED BY ANY CHECK, and said so plainly rather than left to look
//    guarded. A check was written for it and PASSED with both guards removed,
//    because the page test's snapshot is small enough that the server finishes
//    the answer before the test's client gives up — so no cancellation was ever
//    staged. Staging one honestly needs a database big enough to still be
//    streaming after the timeout, which is minutes of runtime on every pull
//    request. These two lines rest on PHP's documented behaviour and on the
//    independent check's measurement of the leak with a crafted loop. See the
//    note beside the download checks in `tools/backup-page-selftest.php`.
register_shutdown_function(static function () use ($temporary): void {
    if (is_file($temporary) === true) {
        @unlink($temporary);
    }
});

// 🔌 Keep going when the browser goes away, just long enough to reach the
//    clean-up. Without this PHP ends the script the moment it notices, and the
//    file is left behind.
ignore_user_abort(true);

try {
    $packed = BackupArchive::pack($snapshot, $temporary, $passphrase, $keyMode, $keyFile);
} catch (\Throwable $e) {
    @unlink($temporary);
    // 🤫 The message is shown, and it never carries the key: BackupArchive is
    //    written so that none of its messages quote one.
    $refuse('The backup could not be packed: ' . $e->getMessage());
    return;
} finally {
    // 🧼 The key is wiped from this request's memory either way. Not a strong
    //    guarantee in PHP — a string may have been copied — but free, and it
    //    shortens the window in which a crash dump could hold it.
    sodium_memzero($passphrase);
}

// 📜 Recorded because it matters who took a copy of everybody's data, and when.
//    Which way it was protected is recorded; the key is not.
Logger::activity(
    'BackupDownloaded',
    'Downloaded snapshot ' . $name . ' as one encrypted file ('
    . ($keyMode === BackupArchive::KEY_BOTH ? 'key and this installation\'s key file' : 'key only')
    . ', ' . $packed['entries'] . ' files, ' . $packed['bytes'] . ' bytes)',
    $userId
);

// -----------------------------------------------------------------------------
// 📤 Send it, then remove it.
// -----------------------------------------------------------------------------
// Buffering is turned off so a large file is not held in memory on its way out,
// and so that nothing already buffered lands in front of the file's first byte.
while (ob_get_level() > 0) {
    ob_end_clean();
}

$fileName = $name . '.' . BackupArchive::EXTENSION;

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . $packed['bytes']);
// A backup must never be kept by a proxy or by the browser's cache.
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$handle = fopen($temporary, 'rb');
if ($handle !== false) {
    // 64 KB at a time, stopping if the person closes the browser: there is no
    // point encrypting the rest of a download nobody is receiving.
    while (feof($handle) === false && connection_aborted() === 0) {
        $piece = fread($handle, 65536);
        if ($piece === false || $piece === '') {
            break;
        }
        echo $piece;
        flush();
    }
    fclose($handle);
}

// 🧹 The ordinary path. The shutdown function registered near the top is what
//    covers the others — a cancelled download, or PHP's time limit — and it is
//    registered rather than relied on here precisely because this line is NOT
//    reached in those cases. Removing it twice is harmless; missing it once
//    leaves a full copy of everybody's details on the server.
@unlink($temporary);
