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
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/249
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/../../web/_core/bootstrap.php';

use Portal\Core\App;

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z\-]+)(=(.*))?$/', $arg, $m) === 1) {
        $args[$m[1]] = $m[3] ?? '';
    }
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
