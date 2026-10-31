<?php
// Path: tools/offsite-backup/log-offsite-result.php
/**
 * -----------------------------------------------------------------------------
 * Off-site sync result logger 📤
 * -----------------------------------------------------------------------------
 * Called from sync-offsite.sh. Parses --status / --destination / --snapshot /
 * --bundle-size / --duration-sec / --error / --output flags and writes a row
 * into tblOffsiteSyncLog.
 *
 * @package   Portal\Backups
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/249
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/671
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// 📂 Find the portal's own folder, the one holding _core.
//
//    WHAT WAS HERE, AND WHY IT NEVER WORKED ON A REAL SERVER (#671):
//        require __DIR__ . '/../../web/_core/bootstrap.php';
//    That is only right when the portal's folder is literally named "web". It
//    is in a checkout of the repository — which is where this was tested on
//    12 October 2026, so the test passed — and it is NOT on a customer's
//    server: the deploy mirrors the CONTENTS of web/ into a folder whose name
//    the customer chooses (the SFTP_PATH_… secrets in
//    .github/workflows/deploy.yml). This file is copied to <portal>/_backups/,
//    so '../..' is the folder ABOVE the portal and '/web/_core' is not there.
//
//    What followed from it, and why it was worth fixing rather than noting:
//    the require failed, so this recorder stopped before writing anything, so
//    tblOffsiteSyncLog stayed EMPTY on every real installation. The off-site
//    page then showed "Last sync: never" and "No runs logged yet" however many
//    copies had really been sent — and a FAILED run was equally invisible,
//    because the script records a failure through this same file. Measured on
//    30 October 2026: from a folder named portal-live it resolves to
//    <parent>/web/_core/bootstrap.php, which does not exist.
//
//    The two places this file can be, and the portal relative to each:
//      - copied to <portal>/_backups/   → the portal is one level up
//      - run from tools/offsite-backup/ → the portal is ../../web
$portalCandidates = [
    __DIR__ . DIRECTORY_SEPARATOR . '..',
    __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'web',
];
$bootstrapPath = '';
foreach ($portalCandidates as $candidate) {
    $try = $candidate . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';
    if (is_file($try) === true && is_readable($try) === true) {
        $bootstrapPath = $try;
        break;
    }
}
if ($bootstrapPath === '') {
    // ⚠️ Exit 2, not 1, and a message naming the folder it looked from. The
    //    script calls this at the very end of a run that has already sent the
    //    copy, so the copy IS at the destination even though nothing was
    //    recorded; saying which folder failed is what makes that diagnosable.
    fwrite(STDERR, "ERROR: cannot find the portal's _core/bootstrap.php from " . __DIR__ . "\n");
    exit(2);
}

require $bootstrapPath;

use Portal\Core\App;

// 🧾 The flags, split by hand rather than matched as a whole.
//
//    WHAT WAS HERE, AND WHY IT THREW AWAY EVERY RUN'S LOG (#671):
//        if (preg_match('/^--([a-z\-]+)(=(.*))?$/', $arg, $m) === 1) { … }
//    In a pattern without the "s" modifier, "." does not match a newline. So
//    the WHOLE pattern failed for any value carrying one, the flag was not
//    recorded at all, and `--output` is always such a value: a run's log is
//    never one line. Measured on 30 October 2026 — a multi-line --output
//    stored NULL, the same text on one line stored fine.
//
//    What followed from it: `tblOffsiteSyncLog.output` was NULL for EVERY
//    run, successful, skipped and failed alike. That emptied the one record
//    of anything the run warned about — and since #671 the warnings are
//    load-bearing, because the settings bridge REFUSES a value it will not
//    put on a command line and a WARN line is the only thing that says so.
//    A mistyped destination therefore left no trace anywhere an
//    administrator could reach.
//
//    Splitting at the first "=" has no such trap: the name is checked on its
//    own, and the value is whatever follows, newlines and all. The name is
//    still held to the same shape, so a stray argument is ignored rather
//    than becoming a column.
$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--') === false) {
        continue;
    }
    $body     = substr($arg, 2);
    $equals   = strpos($body, '=');
    $name     = $equals === false ? $body : substr($body, 0, $equals);
    if (preg_match('/^[a-z][a-z\-]*$/', $name) !== 1) {
        continue;
    }
    $args[$name] = $equals === false ? '' : substr($body, $equals + 1);
}

$status      = (string) ($args['status'] ?? 'failed');
$destination = (string) ($args['destination'] ?? 'unknown');
$snapshot    = $args['snapshot'] ?? null;
$bundleSize  = isset($args['bundle-size']) === true ? (int) $args['bundle-size'] : null;
$duration    = isset($args['duration-sec']) === true ? (int) $args['duration-sec'] : null;
$error       = isset($args['error']) === true ? mb_substr((string) $args['error'], 0, 500) : null;
$output      = $args['output'] ?? null;
$triggeredBy = (string) ($args['triggered-by'] ?? 'cron');

if (in_array($status, ['success','failed','skipped'], true) === false) {
    $status = 'failed';
}

$db = App::db();
$stmt = $db->prepare(
    'INSERT INTO tblOffsiteSyncLog (triggeredBy, destination, snapshotName, bundleSize, durationSec, status, errorMsg, output) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
if ($stmt === false) {
    fwrite(STDERR, "Failed to prepare log insert.\n");
    exit(1);
}
$stmt->bind_param('sssiisss', $triggeredBy, $destination, $snapshot, $bundleSize, $duration, $status, $error, $output);
$stmt->execute();
$stmt->close();
echo "Logged.\n";
