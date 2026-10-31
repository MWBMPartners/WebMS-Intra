<?php
// Path: tools/offsite-settings-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The off-site backup really obeys its settings — self-test 📤🔐 (#671)
 * -----------------------------------------------------------------------------
 * `/admin/maintenance/offsite-backup` saved six settings that NOTHING in the
 * whole repository read. The sync script used the variables written into its
 * own copy on the server and said so in its header, so an administrator could
 * choose a destination, type an rclone remote and an alert address, press Save,
 * be told "Off-site backup settings saved." — and nothing anywhere acted on any
 * of it, on any installation, with or without a command line. The owner chose
 * on 30 October 2026 to make the settings real rather than delete them.
 *
 * This file proves they are real, by running the real programs: a real MySQL
 * database, the real `read-offsite-settings.php`, the real `sync-offsite.sh`
 * under real bash, real `openssl` encryption and real `rclone` sending to a
 * folder on this machine.
 *
 * WHAT IT PROVES
 *   A. The bridge (`read-offsite-settings.php`) against a real database:
 *      which values it prints, which it holds back, which it brings into range
 *      and which it refuses for sheer length — that it reads the
 *      INSTALLATION's own settings rows and not the merged array (A8, which
 *      first proves a merged read would really see something different), and
 *      that every LINE it prints is of a shape no shell could read as code
 *      (A7, on the raw output).
 *   B. The recorder (`log-offsite-result.php`) writes its row from a portal
 *      folder that is NOT named `web`, and keeps a MULTI-LINE `--output`. Both
 *      are regression tests for #671: the file required
 *      '../../web/_core/bootstrap.php', which is only right in a checkout of
 *      this repository — on a customer's server the folder holding `_core` is
 *      named by that customer, so the recorder could not load the portal, no
 *      run was ever recorded, and the off-site page showed "No runs logged
 *      yet" however many copies had really been sent; and its flag parsing
 *      could not match a value carrying a newline, so the `output` column was
 *      NULL for every run there has ever been.
 *   C. The script end to end: a copy really arrives, encrypted, at a
 *      destination the DATABASE chose and not the one in the script file; a
 *      run that is switched off on the page sends nothing and records itself
 *      as skipped; the three ways the bridge can be unusable each fall back to
 *      the script's own values out loud; and the script's OWN guard in bash
 *      refuses a value the bridge would never have printed (C9, C10, through a
 *      stand-in reader — without which that guard is never exercised at all,
 *      because the real bridge refuses the bad values first).
 *   D. Command injection, and each of the six overrides pinned by a planted
 *      fault of its own. A settings box is free text that ends up in a shell
 *      script, so values that would run a command if the output were read with
 *      `source` or `eval` are planted and proved not to run; and each override
 *      is removed from the script in turn, where the check that proves it must
 *      fail.
 *
 * WHAT IT CANNOT PROVE — read this before trusting a green run
 *   - It never sends anything to a real cloud destination. `rclone` is pointed
 *     at a folder on this machine through a throwaway config file. Nothing
 *     here tests Backblaze, R2, Google Drive, S3 or SFTP, and the s3 and sftp
 *     branches of the script are not exercised at all: they need the `aws` and
 *     `scp` programs and a second host. D3 reaches the s3 branch only as far
 *     as its refusal for a missing bucket.
 *   - It cannot prove a customer's hosting panel will run the script on time,
 *     or that `php` and `rclone` exist on their server. That is the limit this
 *     whole feature has, and the page now says so.
 *   - It says nothing about what the PAGE shows. That is the off-site section
 *     of `tools/backup-page-selftest.php`, over real HTTP.
 *   - Nothing is really e-mailed. D7 puts a stand-in `mail` program first on
 *     the PATH and reads the address it was handed; whether the server's own
 *     mail program would deliver anything is untested.
 *   - Section D proves the values it plants do not run. It cannot prove no
 *     value anywhere could: it is a test of a short list, not a proof. A7's
 *     shape check cannot tell a smuggled line from a real one either — only
 *     its COUNT of printed keys can, which is why that count is pinned.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_offsite SELFTEST_DB_PORT=33671 \
 *         SELFTEST_DB_USER=root SELFTEST_DB_PASS=p671pw \
 *         php tools/offsite-settings-selftest.php
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/671
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

$repoRoot = dirname(__DIR__);
$passed   = 0;
$failed   = 0;
$failures = [];

/**
 * Record one check. Printed as it happens, so a run that stops part way still
 * shows how far it got.
 */
function oss_check(bool $ok, string $what, string $detail = ''): void
{
    global $passed, $failed, $failures;
    if ($ok === true) {
        $passed++;
        echo '  ✓ ' . $what . "\n";
        return;
    }
    $failed++;
    $failures[] = $what . ($detail !== '' ? ' — ' . $detail : '');
    echo '  ✗ ' . $what . ($detail !== '' ? ' — ' . $detail : '') . "\n";
}

function oss_refuse(string $why): never
{
    echo "REFUSED: " . $why . "\n";
    exit(2);
}

function oss_env(string $name, string $fallback = ''): string
{
    $value = getenv($name);
    return $value === false ? $fallback : (string) $value;
}

// 🚧 A throwaway database only. The name is the guard: this file writes and
//    deletes settings rows, so pointing it at anything real would change it.
$dbName = oss_env('SELFTEST_DB_NAME', '');
if (str_starts_with($dbName, 'selftest_') === false) {
    oss_refuse("Set SELFTEST_DB_NAME to a throwaway database whose name starts with selftest_ (got '" . $dbName . "').");
}
$dbHost = oss_env('SELFTEST_DB_HOST', '127.0.0.1');
$dbUser = oss_env('SELFTEST_DB_USER', 'root');
$dbPass = oss_env('SELFTEST_DB_PASS', '');
$dbPort = (int) oss_env('SELFTEST_DB_PORT', '3306');

// 🧰 Everything this test needs on the machine. Checked up front and named,
//    rather than failing half way through with something obscure — and a
//    missing program is a REFUSAL, never a skipped check that still shows
//    green. #646's lesson: ask what a test needs, do not discover it.
foreach (['bash' => 'bash --version', 'openssl' => 'openssl version', 'rclone' => 'rclone version', 'tar' => 'tar --version'] as $program => $probe) {
    exec($probe . ' 2>/dev/null', $ignored, $exitCode);
    if ($exitCode !== 0) {
        oss_refuse('This test needs ' . $program . ' on the PATH.');
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
} catch (Throwable $e) {
    oss_refuse('Could not connect to the test database: ' . $e->getMessage());
}
$db->set_charset('utf8mb4');

// 📁 A scratch portal whose folder is deliberately NOT named "web".
//
//    THIS NAME IS THE TEST, not a detail. The recorder used to require
//    '../../web/_core/bootstrap.php', which resolves correctly only when the
//    folder holding _core is literally called "web". It is in a checkout of
//    this repository, so the manual test on 12 October 2026 passed; it is not
//    on a customer's server, where that folder is named by the customer
//    (the SFTP_PATH_… secrets in .github/workflows/deploy.yml). Copying into
//    "portal-live" is what makes this test able to fail.
$scratchParent = (string) realpath(oss_env('SELFTEST_WEB_DIR', sys_get_temp_dir()));
$scratch       = $scratchParent . DIRECTORY_SEPARATOR . 'offsite-settings-selftest-' . getmypid() . '-' . bin2hex(random_bytes(6));
if (file_exists($scratch) === true || mkdir($scratch, 0700) === false) {
    oss_refuse("Could not make a new scratch folder '" . $scratch . "'.");
}
$portal = $scratch . DIRECTORY_SEPARATOR . 'portal-live';

/**
 * Remove the scratch folder and nothing else. Only ever the folder this run
 * made itself, which is why the path is checked against $scratch rather than
 * trusted.
 */
function oss_cleanup(string $scratch): void
{
    if (is_dir($scratch) === false) {
        return;
    }
    $tree = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($scratch, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($tree as $path => $info) {
        if ($info->isDir() === true && $info->isLink() === false) {
            @rmdir((string) $path);
        } else {
            @unlink((string) $path);
        }
    }
    @rmdir($scratch);
}

register_shutdown_function(static function () use ($scratch): void {
    oss_cleanup($scratch);
});

echo "Off-site backup settings — self-test (#671)\n";
echo "Scratch portal: " . $portal . "\n\n";

// Copy web/ into the scratch portal, leaving out the folders a server owns.
mkdir($portal, 0700);
$webSource = $repoRoot . DIRECTORY_SEPARATOR . 'web';
$copied    = 0;
$tree      = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($webSource, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($tree as $path => $info) {
    $relative = substr((string) $path, strlen($webSource) + 1);
    $first    = explode(DIRECTORY_SEPARATOR, $relative)[0];
    if (in_array($first, ['_auth_keys', '_uploads', '_backups', '_libraries'], true) === true) {
        continue;
    }
    if ($info->isLink() === true) {
        oss_refuse('web/ holds a link (' . $relative . '); refusing to copy it.');
    }
    if ($info->isDir() === true) {
        mkdir($portal . DIRECTORY_SEPARATOR . $relative, 0700);
        continue;
    }
    copy((string) $path, $portal . DIRECTORY_SEPARATOR . $relative);
    $copied++;
}
if ($copied < 100) {
    oss_refuse('The copy of web/ holds only ' . $copied . ' files.');
}

mkdir($portal . DIRECTORY_SEPARATOR . '_auth_keys', 0700);
mkdir($portal . DIRECTORY_SEPARATOR . '_backups', 0750);
$backupsDir = $portal . DIRECTORY_SEPARATOR . '_backups';
$keysDir    = $portal . DIRECTORY_SEPARATOR . '_auth_keys';

file_put_contents(
    $keysDir . DIRECTORY_SEPARATOR . 'auth_creds.php',
    "<?php\n// Written by tools/offsite-settings-selftest.php for ONE run; deleted with its folder.\nreturn "
    . var_export([
        'db_host' => $dbHost,
        'db_user' => $dbUser,
        'db_pass' => $dbPass,
        'db_name' => $dbName,
        'db_port' => $dbPort,
    ], true) . ";\n"
);
file_put_contents($keysDir . DIRECTORY_SEPARATOR . 'enc.key', bin2hex(random_bytes(32)));

// The encryption key for the off-site copy. One line, as the script expects.
$offsiteKey = $keysDir . DIRECTORY_SEPARATOR . 'offsite.key';
file_put_contents($offsiteKey, base64_encode(random_bytes(48)) . "\n");
chmod($offsiteKey, 0600);

// The three files a customer copies to the server, from this repository.
$toolsDir = $repoRoot . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'offsite-backup';
$scriptAt = $backupsDir . DIRECTORY_SEPARATOR . 'sync-offsite.sh';
$readerAt = $backupsDir . DIRECTORY_SEPARATOR . 'read-offsite-settings.php';
$loggerAt = $backupsDir . DIRECTORY_SEPARATOR . 'log-offsite-result.php';
copy($toolsDir . DIRECTORY_SEPARATOR . 'sync-offsite.sh.example', $scriptAt);
copy($toolsDir . DIRECTORY_SEPARATOR . 'read-offsite-settings.php', $readerAt);
copy($toolsDir . DIRECTORY_SEPARATOR . 'log-offsite-result.php', $loggerAt);
chmod($scriptAt, 0700);
$pristineScript = (string) file_get_contents($scriptAt);

// A finished snapshot for the script to send: the portal names one
// upgrade-YYYYMMDD-HHMMSS, and _manifest.json is what makes it finished.
$snapshotName = 'upgrade-20261030-120000';
$snapshotDir  = $backupsDir . DIRECTORY_SEPARATOR . $snapshotName;
mkdir($snapshotDir, 0700);
file_put_contents($snapshotDir . DIRECTORY_SEPARATOR . 'tblSettings.sql', "-- a made-up table file for the test\nSELECT 1;\n");
file_put_contents($snapshotDir . DIRECTORY_SEPARATOR . '_manifest.json', json_encode(['tables' => ['tblSettings'], 'madeBy' => 'offsite-settings-selftest']));

// 📮 Where rclone will "send" the copy: a folder on this machine, reached
//    through a throwaway config file so that the remote has a NAME and a
//    colon in it, exactly as a real one does. A bare path would not test the
//    name:path shape the bridge insists on.
$destinationDir = $scratch . DIRECTORY_SEPARATOR . 'destination';
mkdir($destinationDir, 0700);
$rcloneConfig = $scratch . DIRECTORY_SEPARATOR . 'rclone.conf';
file_put_contents($rcloneConfig, "[p671]\ntype = local\n");
$remote = 'p671:' . $destinationDir;

/**
 * Put one installation-wide setting row in place, replacing whatever is there.
 */
function oss_setSetting(mysqli $db, string $key, ?string $value): void
{
    $db->query("DELETE FROM tblSettings WHERE settingKey = '" . $db->real_escape_string($key) . "' AND siteID IS NULL");
    if ($value === null) {
        return;
    }
    $stmt = $db->prepare('INSERT INTO tblSettings (siteID, settingKey, settingValue, isSensitive) VALUES (NULL, ?, ?, 0)');
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
}

/**
 * Put one PER-ORGANISATION setting row in place, replacing whatever is there.
 *
 * The opposite of oss_setSetting(), which only ever writes the installation's
 * own row (siteID NULL). This one writes a row belonging to one organisation,
 * which is what makes section A8 able to tell the two reads apart: the merged
 * array start-up builds holds the organisation's row where there is one, so a
 * bridge that read the merged array instead of the installation's layer would
 * print THIS value. Nothing else in this file writes such a row.
 */
function oss_setSiteSetting(mysqli $db, int $siteID, string $key, ?string $value): void
{
    $stmt = $db->prepare('DELETE FROM tblSettings WHERE settingKey = ? AND siteID = ?');
    $stmt->bind_param('si', $key, $siteID);
    $stmt->execute();
    $stmt->close();
    if ($value === null) {
        return;
    }
    $stmt = $db->prepare('INSERT INTO tblSettings (siteID, settingKey, settingValue, isSensitive) VALUES (?, ?, ?, 0)');
    $stmt->bind_param('iss', $siteID, $key, $value);
    $stmt->execute();
    $stmt->close();
}

/**
 * The six settings at once. null leaves a key out of the database entirely.
 *
 * @param array<string, string|null> $values
 */
function oss_setOffsite(mysqli $db, array $values): void
{
    foreach (['enabled', 'destination', 'rcloneRemote', 'keepWeekly', 'keepMonthly', 'alertEmail'] as $short) {
        oss_setSetting($db, 'backup.offsite.' . $short, array_key_exists($short, $values) === true ? $values[$short] : null);
    }
}

/**
 * Run the bridge and hand back what it printed and its exit code.
 *
 * @return array{out: string, err: string, code: int}
 */
function oss_runReader(string $readerAt): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(['php', $readerAt], $descriptors, $pipes);
    if (is_resource($process) === false) {
        oss_refuse('Could not run the bridge.');
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return ['out' => $out, 'err' => $err, 'code' => $code];
}

/**
 * The KEY=value lines the bridge printed, as an array.
 *
 * @return array<string, string>
 */
function oss_readerValues(string $output): array
{
    $values = [];
    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $m) === 1) {
            $values[$m[1]] = $m[2];
        }
    }
    return $values;
}

/**
 * Every LINE the bridge printed, with the trailing empty one dropped.
 *
 * Deliberately NOT oss_readerValues(): this keeps the lines exactly as they
 * were printed. Splitting into KEY=value pairs first is what made the old A7
 * unable to fail — see the comment on A7 below.
 *
 * @return string[]
 */
function oss_outputLines(string $output): array
{
    $lines = preg_split('/\n/', $output) ?: [];
    while ($lines !== [] && end($lines) === '') {
        array_pop($lines);
    }
    return $lines;
}

/**
 * Judge the bridge's RAW output: the format line, then nothing but warnings
 * and KEY=value lines, each key the bridge's own and each printed once.
 *
 * It hands back the keys in the order they were printed and how many warnings
 * there were, so the caller can pin the COUNT as well as the shape. The count
 * is what catches a line smuggled in by stored text that happens to look like
 * a legal setting line.
 *
 * @return array{ok: bool, why: string, keys: string[], warns: int}
 */
function oss_judgeOutput(string $output): array
{
    $lines = oss_outputLines($output);
    $keys  = [];
    $warns = 0;
    if ($lines === [] || $lines[0] !== '# OFFSITE-SETTINGS 1') {
        return ['ok' => false, 'why' => 'the first line is not "# OFFSITE-SETTINGS 1"', 'keys' => $keys, 'warns' => $warns];
    }
    // The six keys the bridge owns. Anything else on a KEY=value line did not
    // come from its own printing loop.
    $itsOwnKeys = ['ENABLED', 'DESTINATION', 'RCLONE_REMOTE', 'KEEP_WEEKLY', 'KEEP_MONTHLY', 'ALERT_EMAIL'];
    foreach (array_slice($lines, 1) as $line) {
        if (str_starts_with($line, 'WARN: ') === true) {
            $warns++;
            continue;
        }
        // The same safe set the values are checked against below: letters,
        // digits and the handful of marks a remote, an address or a number
        // needs. No space, no quote, no backtick, no dollar, no semicolon —
        // and no newline, because a newline would have ended the line.
        if (preg_match('/^([A-Z_]+)=([A-Za-z0-9_.:@\/+=-]*)$/', $line, $m) !== 1) {
            return ['ok' => false, 'why' => 'a line is neither a warning nor a safe KEY=value line: ' . $line, 'keys' => $keys, 'warns' => $warns];
        }
        if (in_array($m[1], $itsOwnKeys, true) === false) {
            return ['ok' => false, 'why' => 'a line names a key the bridge does not own: ' . $m[1], 'keys' => $keys, 'warns' => $warns];
        }
        if (in_array($m[1], $keys, true) === true) {
            return ['ok' => false, 'why' => 'the key ' . $m[1] . ' was printed twice', 'keys' => $keys, 'warns' => $warns];
        }
        $keys[] = $m[1];
    }
    return ['ok' => true, 'why' => '', 'keys' => $keys, 'warns' => $warns];
}

/**
 * The script with ONE line taken out, for a planted fault.
 *
 * Used by section D to remove exactly one of the six places where a stored
 * setting overrides the value in the script's own file. It answers how many
 * lines it removed so the caller can refuse to go on unless it removed one:
 * a plant that did nothing makes the check after it meaningless, which is the
 * way a planted fault most often lies (and the reason D1 exists at all).
 *
 * The indentation is matched rather than written out, so re-indenting the
 * script cannot quietly stop a plant applying.
 *
 * @return array{0: string, 1: int}
 */
function oss_withoutLine(string $script, string $line): array
{
    $count   = 0;
    $changed = preg_replace(
        '/\n[ \t]*' . preg_quote($line, '/') . '(?=\n)/',
        "\n                        : # this one override removed on purpose by the self-test",
        $script,
        1,
        $count
    );
    return [is_string($changed) === true ? $changed : $script, $count];
}

/**
 * Run the sync script and hand back everything it printed and its exit code.
 *
 * $extraEnv is put in front of the command as it stands, for a run that needs
 * something else in its environment — section D's alert-address case puts a
 * stand-in "mail" program first on the PATH so that nothing is really posted
 * and the arguments can be read back.
 *
 * @return array{out: string, code: int}
 */
function oss_runScript(string $scriptAt, string $rcloneConfig, string $extraEnv = ''): array
{
    $command = ($extraEnv === '' ? '' : $extraEnv . ' ')
        . 'RCLONE_CONFIG=' . escapeshellarg($rcloneConfig)
        . ' bash ' . escapeshellarg($scriptAt) . ' 2>&1';
    $output = [];
    $code   = 0;
    exec($command, $output, $code);
    return ['out' => implode("\n", $output), 'code' => $code];
}

/** Every file now sitting at the destination. @return string[] */
function oss_destinationFiles(string $destinationDir): array
{
    $found = array_values(array_diff((array) scandir($destinationDir), ['.', '..']));
    sort($found);
    return $found;
}

/** Empty the destination between runs. */
function oss_emptyDestination(string $destinationDir): void
{
    foreach (oss_destinationFiles($destinationDir) as $name) {
        @unlink($destinationDir . DIRECTORY_SEPARATOR . $name);
    }
}

/** The newest row in the run log, or null. @return array<string,mixed>|null */
function oss_lastLogRow(mysqli $db): ?array
{
    $rs = $db->query('SELECT status, destination, snapshotName, errorMsg, output FROM tblOffsiteSyncLog ORDER BY logID DESC LIMIT 1');
    if ($rs === false) {
        return null;
    }
    $row = $rs->fetch_assoc();
    $rs->free();
    return $row === null ? null : $row;
}

/** Start the run log empty, so "the newest row" means this run's. */
function oss_clearLog(mysqli $db): void
{
    $db->query('DELETE FROM tblOffsiteSyncLog');
}

/** Put the script back exactly as it ships, after a test has edited it. */
function oss_restoreScript(string $scriptAt, string $pristineScript): void
{
    file_put_contents($scriptAt, $pristineScript);
    chmod($scriptAt, 0700);
}

// =============================================================================
echo "A. The bridge: what it prints, holds back and brings into range\n";
// =============================================================================

// A1 — the ordinary case: every value stored, every value printed.
oss_setOffsite($db, [
    'enabled'      => '1',
    'destination'  => 'rclone',
    'rcloneRemote' => 'b2:portal-backups',
    'keepWeekly'   => '4',
    'keepMonthly'  => '6',
    'alertEmail'   => 'backups@example.com',
]);
$r = oss_runReader($readerAt);
$v = oss_readerValues($r['out']);
oss_check($r['code'] === 0, 'A1 the bridge succeeds when every setting is stored', 'exit ' . $r['code'] . ' ' . $r['err']);
oss_check(str_starts_with($r['out'], "# OFFSITE-SETTINGS 1\n") === true, 'A1 its first line names the format and its number', substr($r['out'], 0, 40));
oss_check(($v['ENABLED'] ?? '') === '1', 'A1 ENABLED=1', $v['ENABLED'] ?? '(absent)');
oss_check(($v['DESTINATION'] ?? '') === 'rclone', 'A1 DESTINATION=rclone', $v['DESTINATION'] ?? '(absent)');
oss_check(($v['RCLONE_REMOTE'] ?? '') === 'b2:portal-backups', 'A1 RCLONE_REMOTE is the stored remote', $v['RCLONE_REMOTE'] ?? '(absent)');
oss_check(($v['KEEP_WEEKLY'] ?? '') === '4' && ($v['KEEP_MONTHLY'] ?? '') === '6', 'A1 both keep numbers are the stored ones', ($v['KEEP_WEEKLY'] ?? '') . '/' . ($v['KEEP_MONTHLY'] ?? ''));
oss_check(($v['ALERT_EMAIL'] ?? '') === 'backups@example.com', 'A1 ALERT_EMAIL is the stored address', $v['ALERT_EMAIL'] ?? '(absent)');
oss_check(str_contains($r['out'], 'WARN:') === false, 'A1 nothing is warned about', $r['out']);

// A2 — switched off, and a missing row.
oss_setOffsite($db, ['enabled' => '0', 'destination' => 'rclone']);
$v = oss_readerValues(oss_runReader($readerAt)['out']);
oss_check(($v['ENABLED'] ?? '') === '0', 'A2 ENABLED=0 when the row says 0', $v['ENABLED'] ?? '(absent)');
oss_setOffsite($db, ['destination' => 'rclone']);
$v = oss_readerValues(oss_runReader($readerAt)['out']);
oss_check(($v['ENABLED'] ?? '') === '0', 'A2 ENABLED=0 when there is no row at all — never assumed on', $v['ENABLED'] ?? '(absent)');
oss_check(array_key_exists('RCLONE_REMOTE', $v) === false, 'A2 a remote that is not stored is simply left out', $v['RCLONE_REMOTE'] ?? '');
oss_check(array_key_exists('KEEP_WEEKLY', $v) === false, 'A2 a keep number that is not stored is left out, so the script keeps its own');

// A2b — "on" is exactly '1' and nothing else. A value such as 'true' or 'yes'
//       must not be read as on: the page only ever writes '1' or '0', so
//       anything else arrived by hand and guessing at it would be deciding
//       somebody's backup policy for them.
foreach (['true', 'yes', 'on', '2', '01', ' 1'] as $notOne) {
    oss_setOffsite($db, ['enabled' => $notOne, 'destination' => 'rclone']);
    $v = oss_readerValues(oss_runReader($readerAt)['out']);
    oss_check(($v['ENABLED'] ?? '') === '0', "A2b ENABLED=0 for a stored '" . $notOne . "', not read as on", $v['ENABLED'] ?? '(absent)');
}

// A3 — the destination must be one of three words.
oss_setOffsite($db, ['enabled' => '1', 'destination' => 'ftp']);
$r = oss_runReader($readerAt);
$v = oss_readerValues($r['out']);
oss_check(array_key_exists('DESTINATION', $v) === false, 'A3 an unknown destination is held back', $v['DESTINATION'] ?? '');
oss_check(str_contains($r['out'], 'WARN:') === true && str_contains($r['out'], 'not one of rclone, s3 or sftp') === true, 'A3 and it says so', $r['out']);
foreach (['rclone', 's3', 'sftp'] as $good) {
    oss_setOffsite($db, ['enabled' => '1', 'destination' => $good]);
    $v = oss_readerValues(oss_runReader($readerAt)['out']);
    oss_check(($v['DESTINATION'] ?? '') === $good, 'A3 "' . $good . '" is accepted', $v['DESTINATION'] ?? '(absent)');
}

// A4 — the rclone remote. The value most worth refusing on, because it goes
//      straight into an rclone command line.
$badRemotes = [
    'no-colon-at-all'            => 'a remote with no colon',
    '-dangerous:place'           => 'a remote starting with a dash, which rclone reads as an option',
    'b2:place; touch /tmp/x'     => 'a remote carrying a second command',
    'b2:place$(whoami)'          => 'a remote carrying a command substitution',
    "b2:place\ntouch /tmp/x"     => 'a remote carrying a new line',
    'b2:place`whoami`'           => 'a remote carrying backticks',
    'b2:place with spaces'       => 'a remote with spaces',
    "b2:place'quoted'"           => 'a remote carrying a quote',
];
foreach ($badRemotes as $bad => $describe) {
    oss_setOffsite($db, ['enabled' => '1', 'destination' => 'rclone', 'rcloneRemote' => $bad]);
    $r = oss_runReader($readerAt);
    $v = oss_readerValues($r['out']);
    oss_check(array_key_exists('RCLONE_REMOTE', $v) === false, 'A4 holds back ' . $describe, 'printed: ' . ($v['RCLONE_REMOTE'] ?? '(none)'));
    oss_check(str_contains($r['out'], 'WARN:') === true, 'A4 and warns about it', $r['out']);
}
foreach (['b2:portal-backups', 'b2:', 'my-remote_1:a/b/c-d.e', 'r2:bucket/path'] as $goodRemote) {
    oss_setOffsite($db, ['enabled' => '1', 'destination' => 'rclone', 'rcloneRemote' => $goodRemote]);
    $v = oss_readerValues(oss_runReader($readerAt)['out']);
    oss_check(($v['RCLONE_REMOTE'] ?? '') === $goodRemote, 'A4 accepts the ordinary remote "' . $goodRemote . '"', $v['RCLONE_REMOTE'] ?? '(absent)');
}

// A5 — the keep numbers: brought into range, or held back, and never guessed.
$keepCases = [
    ['0',    '1',  true,  'below the lowest the page allows is brought up to 1'],
    ['999',  '52', true,  'above the highest is brought down to 52'],
    ['8',    '8',  false, 'an ordinary figure is passed through'],
    ['8.5',  null, true,  'a figure that is not whole is held back'],
    // A leading nought is ACCEPTED and printed back as a canonical decimal.
    // The bridge is what makes that safe: bash never sees "08", so it can
    // never read it as base eight (the fault the script's own note records).
    // This case is pinned because the first version of the bridge's comment
    // claimed the opposite of what its code did, and only running it showed
    // which was true.
    ['08',   '8',  false, 'a figure with a leading nought becomes a plain 8, so bash never sees a base-eight number'],
    ['abc',  null, true,  'text is held back'],
    ['-4',   null, true,  'a negative figure is held back'],
];
foreach ($keepCases as [$stored, $expected, $expectWarn, $describe]) {
    oss_setOffsite($db, ['enabled' => '1', 'destination' => 'rclone', 'keepWeekly' => $stored]);
    $r = oss_runReader($readerAt);
    $v = oss_readerValues($r['out']);
    if ($expected === null) {
        oss_check(array_key_exists('KEEP_WEEKLY', $v) === false, 'A5 ' . $describe, 'printed: ' . ($v['KEEP_WEEKLY'] ?? '(none)'));
    } else {
        oss_check(($v['KEEP_WEEKLY'] ?? '') === $expected, 'A5 ' . $describe, $v['KEEP_WEEKLY'] ?? '(absent)');
    }
    oss_check(str_contains($r['out'], 'WARN:') === $expectWarn, 'A5 warning ' . ($expectWarn === true ? 'given' : 'not given') . ' for "' . $stored . '"', $r['out']);
}
oss_setOffsite($db, ['enabled' => '1', 'destination' => 'rclone', 'keepMonthly' => '99']);
$v = oss_readerValues(oss_runReader($readerAt)['out']);
oss_check(($v['KEEP_MONTHLY'] ?? '') === '60', 'A5 the monthly figure has its own upper limit of 60', $v['KEEP_MONTHLY'] ?? '(absent)');

// A6 — the alert address.
oss_setOffsite($db, ['enabled' => '1', 'destination' => 'rclone', 'alertEmail' => '']);
$v = oss_readerValues(oss_runReader($readerAt)['out']);
oss_check(array_key_exists('ALERT_EMAIL', $v) === true && $v['ALERT_EMAIL'] === '', 'A6 an EMPTY stored address is printed as empty, so it can clear one left in the script file', $v['ALERT_EMAIL'] ?? '(absent)');
foreach (['-x@example.com' => 'an address starting with a dash', 'a b@example.com' => 'an address with a space', "a@example.com\ntouch /tmp/x" => 'an address carrying a new line', 'a@example.com; id' => 'an address carrying a second command', 'not-an-address' => 'text that is not an address'] as $badAlert => $describe) {
    oss_setOffsite($db, ['enabled' => '1', 'destination' => 'rclone', 'alertEmail' => $badAlert]);
    $r = oss_runReader($readerAt);
    $v = oss_readerValues($r['out']);
    oss_check(array_key_exists('ALERT_EMAIL', $v) === false, 'A6 holds back ' . $describe, 'printed: ' . ($v['ALERT_EMAIL'] ?? '(none)'));
}

// A7 — THE PROPERTY THE WHOLE BRIDGE RESTS ON, asked of the RAW OUTPUT.
//      Whatever is stored, every LINE the bridge prints is the format line, a
//      WARN line, or one of its own six KEY=value lines whose value holds only
//      characters that mean nothing to a shell.
//
//      WHY IT IS ASKED THIS WAY NOW, because the first version could not fail
//      and said the opposite. It read the output with oss_readerValues() and
//      then checked each VALUE for a shell character — but that helper splits
//      the output into lines before it judges anything, so a smuggled newline
//      had already become two separate values by the time the question was
//      put. The half of the claim that matters most ("a new line that would
//      turn one value into two") was untestable by construction, under a
//      comment calling this the property the whole bridge rests on. An
//      overstated guarantee is worse than none, so the test now reads the
//      printed text itself.
//
//      WHAT IT STILL CANNOT SEE, plainly: a stored value whose own text is a
//      legal KEY=value line would smuggle in a line of exactly the right
//      shape, and no check of the SHAPE can tell it from a real one. What
//      catches that is the COUNT — the keys printed are pinned to the keys the
//      six stored settings should have produced, so a smuggled line is one key
//      too many (or a key printed twice, which oss_judgeOutput() refuses).
$nasty = "x\n;$(touch /tmp/oss)`id`'\" \\|&<>*?[]{}~!#";
oss_setOffsite($db, [
    'enabled'      => $nasty,
    'destination'  => $nasty,
    'rcloneRemote' => $nasty,
    'keepWeekly'   => $nasty,
    'keepMonthly'  => $nasty,
    'alertEmail'   => $nasty,
]);
$r = oss_runReader($readerAt);
$v = oss_readerValues($r['out']);
$allSafe = true;
foreach ($v as $key => $value) {
    if (preg_match('/^[A-Za-z0-9_.:@\/+=-]*$/', $value) !== 1) {
        $allSafe = false;
        oss_check(false, 'A7 the value printed for ' . $key . ' holds only safe characters', $value);
    }
}
oss_check($allSafe === true, 'A7 with every setting holding shell characters, every printed value is still safe', $r['out']);
oss_check(substr_count($r['out'], 'ENABLED=') === 1, 'A7 exactly one ENABLED line — stored text cannot add a second', $r['out']);
oss_check(file_exists('/tmp/oss') === false, 'A7 nothing the stored text asked for was run');
// The raw output, line by line. This is the check the comment above is about.
$judge = oss_judgeOutput($r['out']);
oss_check($judge['ok'] === true, 'A7 every line printed is the format line, a warning, or one safe KEY=value line', $judge['why'] . ' | ' . $r['out']);
oss_check($judge['keys'] === ['ENABLED'], 'A7 and ENABLED is the ONLY key printed, because the other five values are all unusable — the count is what catches a smuggled line', implode(',', $judge['keys']));
oss_check($judge['warns'] === 5, 'A7 with exactly five warnings, one for each value held back', (string) $judge['warns'] . ' | ' . $r['out']);

// A7b — the same question with SOME values usable, so the count is pinned in
//       both directions. A check that only ever expects one key would pass on
//       a bridge that had stopped printing anything else.
oss_setOffsite($db, [
    'enabled'      => '1',
    'destination'  => 'sftp',
    'rcloneRemote' => $nasty,
    'keepWeekly'   => '3',
    'keepMonthly'  => '4',
    'alertEmail'   => $nasty,
]);
$r     = oss_runReader($readerAt);
$judge = oss_judgeOutput($r['out']);
oss_check($judge['ok'] === true, 'A7b every line is still of the right shape when four values are usable', $judge['why'] . ' | ' . $r['out']);
oss_check(
    $judge['keys'] === ['ENABLED', 'DESTINATION', 'KEEP_WEEKLY', 'KEEP_MONTHLY'],
    'A7b exactly the four usable values are printed, in the bridge\'s own order',
    implode(',', $judge['keys'])
);
oss_check($judge['warns'] === 2, 'A7b and exactly two warnings — the remote and the address', (string) $judge['warns'] . ' | ' . $r['out']);

// 🏢 A8 — THE INSTALLATION'S OWN ROWS, NEVER THE MERGED ARRAY.
//
//    The bridge's biggest comment block is about this, and until 31 October
//    2026 nothing tested it: this suite never wrote a per-organisation row, so
//    the installation's layer and the merged array always held the same thing
//    and `InstallationSettings::layerOrNull()` could have been swapped for
//    `App::settings()` with every check still green.
//
//    WHY IT MATTERS, in the case that decides it. The merged array holds the
//    ORGANISATION's row wherever one exists. There is one off-site copy for
//    the whole installation, so a merged read would let whichever organisation
//    a command-line run happens to detect decide where the whole
//    installation's database is sent, how long copies are kept there, and who
//    is told when it fails. Below, the installation says ENABLED 1 and the
//    organisation says 0 — so a merged read would quietly switch the
//    installation's backup off, which is the worst of the six.
//
//    A8a IS A FIXTURE CHECK, and it is what stops this section being
//    decoration. It proves a merged read really would see something different
//    here, by asking the portal itself. Without it, a run on a database where
//    the organisation's rows are not merged at all — because nothing detects
//    that organisation on the command line — would pass A8 while proving
//    nothing whatever. (The probe is not a copy of the bridge's logic: it asks
//    the two layers what they hold, and makes no judgement.)
$orgSiteID   = 1;      // what Site::preDetect() answers with multi-organisation off
$madeSiteRow = false;
$siteRows    = $db->query('SELECT siteID FROM tblSites WHERE siteID = ' . $orgSiteID);
if ($siteRows === false || $siteRows->num_rows !== 1) {
    // A throwaway database may hold no organisation at all. Made here and
    // removed at the end of the section, so nothing else in the file sees it.
    $db->query("INSERT INTO tblSites (siteID, siteName, siteKey) VALUES (" . $orgSiteID . ", 'Off-site self-test', 'offsite-selftest')");
    $madeSiteRow = true;
}

oss_setOffsite($db, [
    'enabled'      => '1',
    'destination'  => 'rclone',
    'rcloneRemote' => 'b2:installation-wide',
    'keepWeekly'   => '4',
    'keepMonthly'  => '6',
    'alertEmail'   => 'installation@example.com',
]);
$orgValues = [
    'enabled'      => '0',
    'destination'  => 'sftp',
    'rcloneRemote' => 'r2:one-organisation-only',
    'keepWeekly'   => '9',
    'keepMonthly'  => '11',
    'alertEmail'   => 'organisation@example.com',
];
foreach ($orgValues as $short => $orgValue) {
    oss_setSiteSetting($db, $orgSiteID, 'backup.offsite.' . $short, $orgValue);
}

$probeAt     = $backupsDir . DIRECTORY_SEPARATOR . 'oss-merged-probe.php';
$probeSource = <<<'PHP'
<?php
// Written by tools/offsite-settings-selftest.php for ONE run; deleted with its
// folder. It asks the portal what its two settings layers hold, so the test
// can prove the per-organisation rows it just wrote really WOULD fool a merged
// read. It makes no judgement of its own and is not a copy of anything.
declare(strict_types=1);
require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';
$layer = \Portal\Core\InstallationSettings::layerOrNull() ?? [];
echo 'MERGED=' . (string) (\Portal\Core\App::settings('backup.offsite.rcloneRemote') ?? '') . "\n";
echo 'MERGED_ENABLED=' . (string) (\Portal\Core\App::settings('backup.offsite.enabled') ?? '') . "\n";
echo 'INSTALLATION=' . (string) ($layer['backup']['offsite']['rcloneRemote'] ?? '') . "\n";
PHP;
file_put_contents($probeAt, $probeSource . "\n");
$probe = oss_readerValues(oss_runReader($probeAt)['out']);
oss_check(
    ($probe['MERGED'] ?? '') === 'r2:one-organisation-only',
    'A8a the merged array really does hold the ORGANISATION\'s remote, so A8b below is able to fail',
    $probe['MERGED'] ?? '(absent)'
);
oss_check(
    ($probe['MERGED_ENABLED'] ?? '') === '0',
    'A8a and the merged array says the backup is switched OFF, where the installation says on',
    $probe['MERGED_ENABLED'] ?? '(absent)'
);
oss_check(
    ($probe['INSTALLATION'] ?? '') === 'b2:installation-wide',
    'A8a while the installation\'s own layer holds the installation\'s remote',
    $probe['INSTALLATION'] ?? '(absent)'
);

$r = oss_runReader($readerAt);
$v = oss_readerValues($r['out']);
oss_check(($v['ENABLED'] ?? '') === '1', 'A8b ENABLED is the INSTALLATION\'s 1, not the organisation\'s 0 — a merged read would stop the backup', $v['ENABLED'] ?? '(absent)');
oss_check(($v['RCLONE_REMOTE'] ?? '') === 'b2:installation-wide', 'A8b the remote is the installation\'s, so the copy goes where the installation chose', $v['RCLONE_REMOTE'] ?? '(absent)');
oss_check(($v['DESTINATION'] ?? '') === 'rclone', 'A8b the destination is the installation\'s rclone, not the organisation\'s sftp', $v['DESTINATION'] ?? '(absent)');
oss_check(($v['KEEP_WEEKLY'] ?? '') === '4' && ($v['KEEP_MONTHLY'] ?? '') === '6', 'A8b both keep figures are the installation\'s', ($v['KEEP_WEEKLY'] ?? '') . '/' . ($v['KEEP_MONTHLY'] ?? ''));
oss_check(($v['ALERT_EMAIL'] ?? '') === 'installation@example.com', 'A8b the alert address is the installation\'s', $v['ALERT_EMAIL'] ?? '(absent)');
oss_check(str_contains($r['out'], 'WARN:') === false, 'A8b and nothing is warned about: both layers hold usable values, so only the LAYER can account for the answer', $r['out']);

// Put the database back to one layer, so nothing after this section is read
// against an organisation's row.
foreach (array_keys($orgValues) as $short) {
    oss_setSiteSetting($db, $orgSiteID, 'backup.offsite.' . $short, null);
}
if ($madeSiteRow === true) {
    $db->query('DELETE FROM tblSites WHERE siteID = ' . $orgSiteID);
}
unlink($probeAt);

// 📏 A9 — HELD BACK FOR SHEER LENGTH, and what "held back" means for each
//    value. The settings boxes have no length limit of their own, so this is
//    the bridge's own ceiling (OffsiteSettings::MAX_LENGTH, 256).
$longRemote = 'b2:' . str_repeat('a', 300);          // 303, and a legal SHAPE
$longAlert  = str_repeat('a', 290) . '@example.com'; // 302, and a legal shape too
oss_setOffsite($db, ['enabled' => '1', 'destination' => 'rclone', 'rcloneRemote' => $longRemote]);
$r = oss_runReader($readerAt);
$v = oss_readerValues($r['out']);
oss_check(array_key_exists('RCLONE_REMOTE', $v) === false, 'A9 a 303-character remote is held back even though its shape is legal', 'printed: ' . ($v['RCLONE_REMOTE'] ?? '(none)'));
oss_check(str_contains($r['out'], '303 characters long') === true, 'A9 and the warning names the length, which is the only thing wrong with it', $r['out']);

// The IMPORTANT half: an over-long alert address must be LEFT OUT, not printed
// as empty. Empty means "do not e-mail", which is a real choice; printing it
// would turn "we cannot use what is stored" into a decision nobody made, and
// would also clear an address left in the script's own file.
oss_setOffsite($db, ['enabled' => '1', 'destination' => 'rclone', 'alertEmail' => $longAlert]);
$r = oss_runReader($readerAt);
$v = oss_readerValues($r['out']);
oss_check(array_key_exists('ALERT_EMAIL', $v) === false, 'A9 an over-long alert address is left out ENTIRELY, never printed as empty — empty would mean "do not e-mail"', 'printed: ' . var_export($v['ALERT_EMAIL'] ?? null, true));
oss_check(str_contains($r['out'], 'alert address is 302 characters long') === true, 'A9 and the warning names the address and its length', $r['out']);

// The other three blocks each have their own length branch.
oss_setOffsite($db, ['enabled' => '1', 'destination' => str_repeat('r', 300), 'keepWeekly' => str_repeat('9', 300), 'keepMonthly' => str_repeat('9', 300)]);
$r = oss_runReader($readerAt);
$v = oss_readerValues($r['out']);
oss_check(array_key_exists('DESTINATION', $v) === false && array_key_exists('KEEP_WEEKLY', $v) === false && array_key_exists('KEEP_MONTHLY', $v) === false, 'A9 an over-long destination and over-long keep figures are all held back', implode(',', array_keys($v)));
oss_check(substr_count($r['out'], 'characters long') === 3, 'A9 with one warning each, naming the length rather than quoting 300 characters of nonsense', $r['out']);

// And the boundary, so this is a LIMIT rather than "long things fail".
$atTheLimit = 'b2:' . str_repeat('a', 253);          // exactly 256
oss_setOffsite($db, ['enabled' => '1', 'destination' => 'rclone', 'rcloneRemote' => $atTheLimit]);
$v = oss_readerValues(oss_runReader($readerAt)['out']);
oss_check(($v['RCLONE_REMOTE'] ?? '') === $atTheLimit, 'A9 a remote of exactly 256 characters is still used, so 256 is the limit and not a rough guess', (string) strlen($v['RCLONE_REMOTE'] ?? ''));

// 💣 A9b — the value that used to stop the backup outright. A remote of about
//    200,000 characters overflowed the pipe the script read the first line
//    through: bash printed "printf: write error: Broken pipe" and, under
//    `set -o pipefail`, the whole run died before anything was packed, blaming
//    the wrong line and never naming the setting. Two guards now: the script
//    no longer uses a pipe there, and the bridge refuses the value for length.
//    This checks the bridge's half; C12 runs the whole script with it stored.
$hugeRemote = 'b2:' . str_repeat('a', 200000);
oss_setOffsite($db, ['enabled' => '1', 'destination' => 'rclone', 'rcloneRemote' => $hugeRemote, 'keepWeekly' => '2', 'keepMonthly' => '2', 'alertEmail' => '']);
$r = oss_runReader($readerAt);
$v = oss_readerValues($r['out']);
oss_check($r['code'] === 0, 'A9b the bridge still succeeds with a 200,003-character remote stored', 'exit ' . $r['code'] . ' ' . $r['err']);
oss_check(array_key_exists('RCLONE_REMOTE', $v) === false, 'A9b and holds it back', 'printed ' . strlen($v['RCLONE_REMOTE'] ?? '') . ' characters');
oss_check(strlen($r['out']) < 2000, 'A9b and nothing of that size is printed, so the shell reading it never has to carry it', strlen($r['out']) . ' characters');

// =============================================================================
echo "\nB. The recorder works from a portal folder that is NOT named 'web'\n";
// =============================================================================

oss_clearLog($db);
$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open(
    ['php', $loggerAt, '--status=success', '--destination=rclone', '--snapshot=' . $snapshotName, '--duration-sec=3', '--output=from the self-test'],
    $descriptors,
    $pipes
);
$loggerOut = (string) stream_get_contents($pipes[1]);
$loggerErr = (string) stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$loggerCode = proc_close($process);
oss_check($loggerCode === 0, 'B1 the recorder runs from ' . basename($portal) . '/_backups/ and exits 0', 'exit ' . $loggerCode . ' ' . $loggerErr);
$row = oss_lastLogRow($db);
oss_check($row !== null && (string) $row['status'] === 'success', 'B1 and a row really reached tblOffsiteSyncLog', $row === null ? '(no row)' : (string) $row['status']);
oss_check($row !== null && (string) $row['snapshotName'] === $snapshotName, 'B1 the row names the snapshot it was told about', $row === null ? '(no row)' : (string) ($row['snapshotName'] ?? ''));

// B2 — and prove the OLD path really was the fault, by resolving it here. This
//      is the line that used to be in the file.
$oldStylePath = $backupsDir . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';
oss_check(realpath($oldStylePath) === false, "B2 the path the recorder used before (#671) finds nothing from a portal folder named '" . basename($portal) . "'", (string) realpath($oldStylePath));
// 💬 The COMMENTS have to be stripped before asking this, and the first
//    version of this check did not — so it failed on the recorder's own
//    explanation of the bug, which quotes the old line on purpose. A plain
//    search cannot tell a live require from a note about one, which is the
//    same trap the page's check is built around, met here from the other side:
//    there it let a broken thing pass, here it called a fixed thing broken.
$loggerCode = '';
foreach (preg_split('/\R/', (string) file_get_contents($loggerAt)) ?: [] as $loggerLine) {
    if (preg_match('#^\s*(//|\*|/\*)#', $loggerLine) === 1) {
        continue;
    }
    $loggerCode .= $loggerLine . "\n";
}
oss_check(str_contains($loggerCode, "'/../../web/_core/bootstrap.php'") === false, 'B2 and no line that RUNS still uses that path (its comments may quote it, and do)', $loggerCode);

// B3 — A MULTI-LINE --output REALLY REACHES THE COLUMN.
//
//      The third fault found in #671, and the quietest. The flags were read
//      with preg_match('/^--([a-z\-]+)(=(.*))?$/'), and in a pattern without
//      the "s" modifier "." does not match a newline — so the WHOLE pattern
//      failed for any value carrying one and the flag was dropped. `--output`
//      is always such a value: a run's log is never one line. So
//      tblOffsiteSyncLog.output was NULL for EVERY run ever recorded,
//      successful, skipped and failed alike, and since #671 that column is the
//      only place a refused setting's warning is kept where an administrator
//      can reach it.
//
//      --output is passed FIRST here on purpose. It pins that a flag AFTER the
//      multi-line one is still read, which is what a parser that stopped at
//      the first newline in the whole argument list would get wrong.
oss_clearLog($db);
$runLog = "Settings from: /admin/maintenance/offsite-backup (6 setting(s))\n"
    . "WARNING: the stored destination \"ftp\" is not one of rclone, s3 or sftp, so the destination in this script file is used instead\n"
    . "Selected snapshot: " . $snapshotName . "\n"
    . 'Off-site backup complete.';
$process = proc_open(
    ['php', $loggerAt, '--output=' . $runLog, '--status=success', '--destination=rclone', '--snapshot=' . $snapshotName, '--duration-sec=4'],
    $descriptors,
    $pipes
);
$loggerOut  = (string) stream_get_contents($pipes[1]);
$loggerErr  = (string) stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$loggerExit = proc_close($process);
$row        = oss_lastLogRow($db);
oss_check($loggerExit === 0, 'B3 the recorder accepts a multi-line --output and exits 0', 'exit ' . $loggerExit . ' ' . $loggerErr);
oss_check($row !== null && $row['output'] !== null, 'B3 and the output column is NOT null, which it was for every run before this fix', $row === null ? '(no row)' : var_export($row['output'], true));
oss_check($row !== null && (string) $row['output'] === $runLog, 'B3 and holds every line of it, exactly as it was given', $row === null ? '(no row)' : (string) $row['output']);
oss_check($row !== null && substr_count((string) $row['output'], "\n") === 3, 'B3 and it really is four lines, so this is not the one-line case passing by accident', $row === null ? '(no row)' : (string) substr_count((string) $row['output'], "\n"));
oss_check($row !== null && (string) $row['snapshotName'] === $snapshotName, 'B3 and the flag AFTER the multi-line one was still read', $row === null ? '(no row)' : (string) ($row['snapshotName'] ?? ''));

// =============================================================================
echo "\nC. The script end to end: the database decides, not the script file\n";
// =============================================================================

// C1 — switched off on the page: nothing packed, nothing sent, recorded as
//      skipped, and exit 0 so a hosting panel does not email a failure every
//      week for a feature deliberately turned off.
oss_clearLog($db);
oss_emptyDestination($destinationDir);
oss_setOffsite($db, ['enabled' => '0', 'destination' => 'rclone', 'rcloneRemote' => $remote, 'keepWeekly' => '2', 'keepMonthly' => '2', 'alertEmail' => '']);
$run = oss_runScript($scriptAt, $rcloneConfig);
oss_check($run['code'] === 0, 'C1 a switched-off run ends with exit code 0', 'exit ' . $run['code'] . "\n" . $run['out']);
oss_check(oss_destinationFiles($destinationDir) === [], 'C1 and sent nothing', implode(',', oss_destinationFiles($destinationDir)));
$row = oss_lastLogRow($db);
oss_check($row !== null && (string) $row['status'] === 'skipped', 'C1 and recorded itself as skipped, not failed', $row === null ? '(no row)' : (string) $row['status']);
oss_check($row !== null && str_contains((string) $row['errorMsg'], 'switched off') === true, 'C1 and the row says why, in words an administrator can read', $row === null ? '(no row)' : (string) $row['errorMsg']);
oss_check(str_contains($run['out'], 'Selected snapshot') === false, 'C1 and never even looked for a snapshot to pack', $run['out']);

// C2 — switched on: the copy really arrives, and the DATABASE chose where.
//      The script file is deliberately left pointing at a remote that does not
//      exist, so a copy arriving at all proves the stored value won.
oss_clearLog($db);
oss_emptyDestination($destinationDir);
$withBogusRemote = str_replace('RCLONE_REMOTE="myremote:portal-backups"', 'RCLONE_REMOTE="nowhere:this-remote-does-not-exist"', $pristineScript);
oss_check($withBogusRemote !== $pristineScript, 'C2 the script file was really given a bogus remote for this test');
file_put_contents($scriptAt, $withBogusRemote);
chmod($scriptAt, 0700);
oss_setOffsite($db, ['enabled' => '1', 'destination' => 'rclone', 'rcloneRemote' => $remote, 'keepWeekly' => '2', 'keepMonthly' => '2', 'alertEmail' => '']);
$run = oss_runScript($scriptAt, $rcloneConfig);
oss_check($run['code'] === 0, 'C2 the run succeeds', 'exit ' . $run['code'] . "\n" . $run['out']);
$sent = oss_destinationFiles($destinationDir);
oss_check($sent === [$snapshotName . '.tar.gz.enc'], 'C2 exactly one copy arrived, named after the snapshot', implode(',', $sent));
oss_check(str_contains($run['out'], 'Settings from: /admin/maintenance/offsite-backup') === true, 'C2 and the run says it took its settings from the page', $run['out']);
$row = oss_lastLogRow($db);
oss_check($row !== null && (string) $row['status'] === 'success', 'C2 and recorded a success', $row === null ? '(no row)' : (string) $row['status']);

// C2b — the copy really is this snapshot, really encrypted with this key.
//       Without this the test would prove only that A file arrived.
$delivered = $destinationDir . DIRECTORY_SEPARATOR . $snapshotName . '.tar.gz.enc';
$firstBytes = (string) file_get_contents($delivered, false, null, 0, 8);
oss_check(str_starts_with($firstBytes, 'Salted__') === true, 'C2b what arrived is an openssl-encrypted file, not the plain pack', bin2hex($firstBytes));
$decrypted = $scratch . DIRECTORY_SEPARATOR . 'back.tar.gz';
exec('openssl enc -d -aes-256-cbc -pbkdf2 -in ' . escapeshellarg($delivered) . ' -out ' . escapeshellarg($decrypted) . ' -pass ' . escapeshellarg('file:' . $offsiteKey) . ' 2>&1', $ignored, $decryptCode);
oss_check($decryptCode === 0, 'C2b and the key on this server opens it', 'openssl exit ' . $decryptCode);
$listing = [];
exec('tar -tzf ' . escapeshellarg($decrypted) . ' 2>&1', $listing, $tarCode);
oss_check($tarCode === 0 && in_array($snapshotName . '/_manifest.json', $listing, true) === true, 'C2b and inside is the snapshot, manifest and all', implode(',', $listing));
oss_check(count(glob($scratch . DIRECTORY_SEPARATOR . '*.tar.gz') ?: []) <= 1, 'C2b no unencrypted pack was left behind in the scratch folder');

// C3 — the keep numbers from the database really decide what is deleted.
//      Six old copies, keep 1+1, so four should go and the newest two stay.
oss_clearLog($db);
oss_emptyDestination($destinationDir);
foreach (['upgrade-20260101-000000', 'upgrade-20260201-000000', 'upgrade-20260301-000000', 'upgrade-20260401-000000', 'upgrade-20260501-000000'] as $old) {
    file_put_contents($destinationDir . DIRECTORY_SEPARATOR . $old . '.tar.gz.enc', 'an old copy');
}
file_put_contents($destinationDir . DIRECTORY_SEPARATOR . 'someone-elses-file.txt', 'not ours');
oss_setOffsite($db, ['enabled' => '1', 'destination' => 'rclone', 'rcloneRemote' => $remote, 'keepWeekly' => '1', 'keepMonthly' => '1', 'alertEmail' => '']);
$run = oss_runScript($scriptAt, $rcloneConfig);
$sent = oss_destinationFiles($destinationDir);
oss_check($run['code'] === 0, 'C3 the run succeeds with old copies present', 'exit ' . $run['code'] . "\n" . $run['out']);
oss_check(in_array($snapshotName . '.tar.gz.enc', $sent, true) === true, 'C3 the copy just sent is never deleted', implode(',', $sent));
oss_check(in_array('someone-elses-file.txt', $sent, true) === true, 'C3 a file that is not one of ours is never deleted', implode(',', $sent));
$ourCopies = array_values(array_filter($sent, static fn (string $n): bool => preg_match('/^upgrade-[0-9]{8}-[0-9]{6}\.tar\.gz\.enc$/', $n) === 1));
oss_check(count($ourCopies) === 2, 'C3 keeping 1+1 leaves exactly 2 of our copies, so the stored figures really decided', implode(',', $ourCopies));

// C4 — INJECTION. A stored remote carrying a command must not run it, and the
//      run must fall back to the script's own value and say so.
oss_clearLog($db);
oss_emptyDestination($destinationDir);
$marker = $scratch . DIRECTORY_SEPARATOR . 'pwned-' . bin2hex(random_bytes(4));
$withGoodRemote = str_replace('RCLONE_REMOTE="myremote:portal-backups"', 'RCLONE_REMOTE="' . $remote . '"', $pristineScript);
oss_check($withGoodRemote !== $pristineScript, 'C4 the script file was really given the working remote for this test');
file_put_contents($scriptAt, $withGoodRemote);
chmod($scriptAt, 0700);
$injections = [
    'rcloneRemote' => $remote . '; touch ' . $marker,
    'alertEmail'   => 'a@example.com; touch ' . $marker,
    'destination'  => 'rclone; touch ' . $marker,
    'keepWeekly'   => '1; touch ' . $marker,
];
foreach ($injections as $key => $payload) {
    oss_setOffsite($db, ['enabled' => '1', 'destination' => 'rclone', 'rcloneRemote' => $remote, 'keepWeekly' => '2', 'keepMonthly' => '2', 'alertEmail' => '']);
    oss_setSetting($db, 'backup.offsite.' . $key, $payload);
    oss_emptyDestination($destinationDir);
    $run = oss_runScript($scriptAt, $rcloneConfig);
    oss_check(file_exists($marker) === false, 'C4 a command hidden in ' . $key . ' did not run', 'the marker file was created');
    oss_check(str_contains($run['out'], 'WARNING:') === true, 'C4 and the run warned that the stored ' . $key . ' was held back', $run['out']);
    if (file_exists($marker) === true) {
        @unlink($marker);
    }
}
// The run should still have worked, using the script file's own remote.
oss_setOffsite($db, ['enabled' => '1', 'destination' => 'rclone', 'rcloneRemote' => 'not a valid remote', 'keepWeekly' => '2', 'keepMonthly' => '2', 'alertEmail' => '']);
oss_emptyDestination($destinationDir);
$run = oss_runScript($scriptAt, $rcloneConfig);
oss_check($run['code'] === 0 && oss_destinationFiles($destinationDir) === [$snapshotName . '.tar.gz.enc'], 'C4 a held-back remote falls back to the script file and the copy still goes', 'exit ' . $run['code'] . "\n" . $run['out']);
oss_check(str_contains($run['out'], 'Settings from: this script file') === true || str_contains($run['out'], 'Settings from: /admin') === true, 'C4 and the run says where its settings came from', $run['out']);

// C5 — a WARN line is never mistaken for a setting.
oss_check(str_contains($run['out'], 'WARN=') === false, 'C5 no WARN line was read as a KEY=value setting', $run['out']);

// C6 — a bridge answering in a format this script does not know: the script
//      must use its own values rather than half-read the answer.
oss_clearLog($db);
oss_emptyDestination($destinationDir);
file_put_contents($readerAt, "<?php\ndeclare(strict_types=1);\necho \"# OFFSITE-SETTINGS 99\\n\";\necho \"ENABLED=0\\n\";\n");
oss_setOffsite($db, ['enabled' => '1', 'destination' => 'rclone', 'rcloneRemote' => $remote, 'keepWeekly' => '2', 'keepMonthly' => '2', 'alertEmail' => '']);
$run = oss_runScript($scriptAt, $rcloneConfig);
oss_check(str_contains($run['out'], 'format this script does not know') === true, 'C6 a bridge answering in an unknown format is refused, in words', $run['out']);
oss_check(str_contains($run['out'], 'Settings from: this script file') === true, 'C6 and the run used the values in the script file', $run['out']);
oss_check(oss_destinationFiles($destinationDir) === [$snapshotName . '.tar.gz.enc'], 'C6 and still sent the copy, using the file"s own remote', implode(',', oss_destinationFiles($destinationDir)));
copy($toolsDir . DIRECTORY_SEPARATOR . 'read-offsite-settings.php', $readerAt);

// C7 — no bridge at all: the script says so and carries on with its own values.
oss_clearLog($db);
oss_emptyDestination($destinationDir);
unlink($readerAt);
$run = oss_runScript($scriptAt, $rcloneConfig);
oss_check(str_contains($run['out'], 'read-offsite-settings.php') === true && str_contains($run['out'], 'WARNING') === true, 'C7 a missing bridge is warned about by name', $run['out']);
oss_check(str_contains($run['out'], 'Settings from: this script file') === true, 'C7 and the values in the script file are used', $run['out']);
oss_check(oss_destinationFiles($destinationDir) === [$snapshotName . '.tar.gz.enc'], 'C7 and the copy still goes — a host with no php on the command line can still back up', implode(',', oss_destinationFiles($destinationDir)));
copy($toolsDir . DIRECTORY_SEPARATOR . 'read-offsite-settings.php', $readerAt);

// C8 — the fallback for "enabled" lives in the script file, and 0 there stops
//      a run that cannot reach the database. This is the one value with no
//      safe "carry on" answer, so it is worth pinning both ways.
oss_clearLog($db);
oss_emptyDestination($destinationDir);
unlink($readerAt);
$withEnabledOff = str_replace("\nENABLED=1\n", "\nENABLED=0\n", $withGoodRemote);
oss_check($withEnabledOff !== $withGoodRemote, 'C8 the script file really has an ENABLED fallback to change');
file_put_contents($scriptAt, $withEnabledOff);
chmod($scriptAt, 0700);
$run = oss_runScript($scriptAt, $rcloneConfig);
oss_check(oss_destinationFiles($destinationDir) === [], 'C8 with no bridge and ENABLED=0 in the file, nothing is sent', implode(',', oss_destinationFiles($destinationDir)));
copy($toolsDir . DIRECTORY_SEPARATOR . 'read-offsite-settings.php', $readerAt);
oss_restoreScript($scriptAt, $pristineScript);

// 🛡️ C9 — THE SCRIPT'S OWN GUARD, WHICH NOTHING ABOVE EXERCISES.
//
//    The script checks every value AGAIN in bash, on purpose, because that is
//    the guard that has to hold if the bridge is ever replaced or edited on
//    the server. But every value the bash loop has seen so far came through
//    the real bridge, which refuses the bad ones first — so as far as this
//    test went, the bash re-validation was dead code: it could have been
//    widened, or deleted outright, with every check still passing.
//
//    So this uses the trick C6 already uses: a STAND-IN reader. It answers in
//    the format the script does know, so the script really reads it, and every
//    value it sends is one the script must refuse. The script file is left with
//    values that work, so "the copy still arrived" is the proof that each
//    fallback was used.
//
//    WHAT EACH CHECK HERE IS WORTH, said exactly, because the marker files are
//    the weaker half. A refused value is never put on a command line unquoted,
//    so widening a pattern would usually make the run FAIL rather than run
//    anything. The one real exception is a keep figure: bash arithmetic does
//    run a command hidden in an array subscript — measured on this machine's
//    bash 3.2, `K='a[$(touch f)]'; : $((K + 1))` creates the file, while the
//    `10#` prefix the script uses makes it an error instead. So the markers
//    prove nothing ran; the WARNING lines and the arrived copy are what prove
//    each value was REFUSED rather than taken and merely survived.
oss_clearLog($db);
oss_emptyDestination($destinationDir);
oss_restoreScript($scriptAt, $withGoodRemote);
$standInMarkers = [];
foreach (['destination', 'remote', 'keep', 'bare-line', 'substitution'] as $whatRan) {
    $standInMarkers[$whatRan] = $scratch . DIRECTORY_SEPARATOR . 'ran-' . $whatRan . '-' . bin2hex(random_bytes(4));
}
$standInLines = [
    '# OFFSITE-SETTINGS 1',
    'WARN: a warning the stand-in reader made up',
    // Not '1' or '0', so the script must keep its own.
    'ENABLED=yes',
    'DESTINATION=rclone; touch ' . $standInMarkers['destination'],
    'RCLONE_REMOTE=' . $remote . '; touch ' . $standInMarkers['remote'],
    // The array-subscript shape, the one place bash arithmetic really can run
    // a command (see the note above).
    'KEEP_WEEKLY=a[$(touch ' . $standInMarkers['keep'] . ')]',
    'KEEP_MONTHLY=-4',
    // A legal address apart from the leading dash, which the mail program
    // would read as an option. Only the "not a dash" half refuses this one.
    'ALERT_EMAIL=-alerts@example.com',
    // Two lines that are not KEY=value at all. Nothing should happen to
    // either: this is what "never source, never eval" means.
    'touch ' . $standInMarkers['bare-line'],
    '$(touch ' . $standInMarkers['substitution'] . ')',
];
file_put_contents(
    $readerAt,
    "<?php\n"
    . "// A STAND-IN reader, written by tools/offsite-settings-selftest.php for one\n"
    . "// run and deleted with its folder. It answers in the format the script knows\n"
    . "// and sends values the script must refuse, which is the only way to exercise\n"
    . "// the script's own second guard: the real bridge refuses them first.\n"
    . "declare(strict_types=1);\n"
    . 'echo ' . var_export(implode("\n", $standInLines) . "\n", true) . ";\n"
);
oss_setOffsite($db, ['enabled' => '1', 'destination' => 'rclone', 'rcloneRemote' => $remote, 'keepWeekly' => '2', 'keepMonthly' => '2', 'alertEmail' => '']);
$run = oss_runScript($scriptAt, $rcloneConfig);
oss_check($run['code'] === 0, 'C9 the run carries on when every value sent is one it will not use', 'exit ' . $run['code'] . "\n" . $run['out']);
oss_check(oss_destinationFiles($destinationDir) === [$snapshotName . '.tar.gz.enc'], 'C9 and the copy still goes, using the values in the script file', implode(',', oss_destinationFiles($destinationDir)));
oss_check(str_contains($run['out'], 'Settings from: this script file') === true, 'C9 and the run says NOT ONE of the six was taken', $run['out']);
foreach (['ENABLED', 'DESTINATION', 'RCLONE_REMOTE', 'KEEP_WEEKLY', 'KEEP_MONTHLY', 'ALERT_EMAIL'] as $refusedKey) {
    oss_check(
        str_contains($run['out'], 'WARNING: the settings reader sent a ' . $refusedKey . ' this script will not use') === true,
        'C9 the refusal of ' . $refusedKey . ' is said out loud, naming the setting',
        $run['out']
    );
}
oss_check(str_contains($run['out'], 'WARNING: a warning the stand-in reader made up') === true, 'C9 and a WARN line from the reader is still passed through', $run['out']);
foreach ($standInMarkers as $whatRan => $markerPath) {
    oss_check(file_exists($markerPath) === false, 'C9 nothing hidden in the ' . $whatRan . ' was run', 'the marker file was created');
    if (file_exists($markerPath) === true) {
        @unlink($markerPath);
    }
}

// C10 — the one guard that is NOT a pattern: a value may not start with a
//       dash. The remote "-p671:…" matches the shape the script allows in
//       every other respect, so only `!= -*` refuses it — and rclone would
//       read it as an option rather than a remote. Five of the six values are
//       usable here, so the count in the "Settings from:" line is the signal:
//       it says five, not six.
oss_clearLog($db);
oss_emptyDestination($destinationDir);
file_put_contents(
    $readerAt,
    "<?php\n"
    . "// A second STAND-IN reader (see C9), sending one value the script must\n"
    . "// refuse and five it must take.\n"
    . "declare(strict_types=1);\n"
    . 'echo ' . var_export(
        "# OFFSITE-SETTINGS 1\nENABLED=1\nDESTINATION=rclone\nRCLONE_REMOTE=-p671:somewhere\nKEEP_WEEKLY=2\nKEEP_MONTHLY=2\nALERT_EMAIL=\n",
        true
    ) . ";\n"
);
$run = oss_runScript($scriptAt, $rcloneConfig);
oss_check(
    str_contains($run['out'], 'WARNING: the settings reader sent a RCLONE_REMOTE this script will not use') === true,
    'C10 a remote starting with a dash is refused by the script itself and named',
    $run['out']
);
oss_check(
    str_contains($run['out'], 'Settings from: /admin/maintenance/offsite-backup (5 setting(s))') === true,
    'C10 and the run counts five settings taken, not six — the one refused is not counted',
    $run['out']
);
oss_check($run['code'] === 0 && oss_destinationFiles($destinationDir) === [$snapshotName . '.tar.gz.enc'], 'C10 and the copy goes to the remote in the script file instead', 'exit ' . $run['code'] . "\n" . $run['out']);
copy($toolsDir . DIRECTORY_SEPARATOR . 'read-offsite-settings.php', $readerAt);

// 🧾 C11 — a WARNING from a REAL run reaches the recorded row's output column.
//    That column is the only place a refused setting is kept where an
//    administrator can reach it: the hosting panel's e-mail is the only other
//    copy, and it goes to whoever set the schedule up. B3 proves the recorder
//    keeps a multi-line output; this proves a real run really produces one and
//    hands it over.
oss_clearLog($db);
oss_emptyDestination($destinationDir);
oss_setOffsite($db, ['enabled' => '1', 'destination' => 'ftp', 'rcloneRemote' => $remote, 'keepWeekly' => '2', 'keepMonthly' => '2', 'alertEmail' => '']);
$run = oss_runScript($scriptAt, $rcloneConfig);
oss_check($run['code'] === 0, 'C11 a run with one setting held back still succeeds', 'exit ' . $run['code'] . "\n" . $run['out']);
$row = oss_lastLogRow($db);
oss_check($row !== null && $row['output'] !== null, 'C11 and the run was recorded WITH its output', $row === null ? '(no row)' : var_export($row['output'], true));
oss_check(
    $row !== null && str_contains((string) $row['output'], 'WARNING: the stored destination "ftp" is not one of rclone, s3 or sftp') === true,
    'C11 and the recorded output holds the warning about the held-back destination, word for word',
    $row === null ? '(no row)' : (string) $row['output']
);
oss_check($row !== null && substr_count((string) $row['output'], "\n") > 1, 'C11 and it is the whole multi-line log, not just the first line', $row === null ? '(no row)' : (string) substr_count((string) $row['output'], "\n"));

// 💣 C12 — the 200,000-character remote, through the whole script. A9b proves
//    the bridge holds it back; this proves the RUN survives it. It used to
//    break the pipe the first line was read through and, under
//    `set -o pipefail`, stop the whole run before anything was packed.
oss_clearLog($db);
oss_emptyDestination($destinationDir);
oss_setOffsite($db, ['enabled' => '1', 'destination' => 'rclone', 'rcloneRemote' => $hugeRemote, 'keepWeekly' => '2', 'keepMonthly' => '2', 'alertEmail' => '']);
$run = oss_runScript($scriptAt, $rcloneConfig);
oss_check($run['code'] === 0, 'C12 a 200,003-character stored remote no longer stops the run', 'exit ' . $run['code'] . "\n" . substr($run['out'], 0, 2000));
oss_check(oss_destinationFiles($destinationDir) === [$snapshotName . '.tar.gz.enc'], 'C12 and the copy is still packed and sent, to the remote in the script file', implode(',', oss_destinationFiles($destinationDir)));
oss_check(str_contains($run['out'], 'characters long') === true, 'C12 and the run says why that setting was not used', substr($run['out'], 0, 2000));
oss_check(strlen($run['out']) < 20000, 'C12 and the run\'s own log never carries the value itself', strlen($run['out']) . ' characters');
oss_restoreScript($scriptAt, $pristineScript);

// =============================================================================
echo "\nD. Each of the six overrides, pinned by a planted fault of its own\n";
// =============================================================================

// 🌱 A test that only ever sees working code proves nothing about its own
//    ability to fail. So each case below takes ONE override out of the script
//    — the bridge is left in place and still read, only the one assignment
//    that applies that setting is removed — and checks that the proof of it
//    really collapses.
//
//    WHY ALL SIX NOW, AND WHAT WAS WRONG BEFORE. Until 31 October 2026 this
//    section planted one fault, on the rclone remote, and judged it by
//    "nothing arrived at the destination" — a signal that ANY plant satisfies,
//    a syntax error in the script included. ENABLED was pinned by C1 and C8
//    and the two keep figures by C3, but DESTINATION and ALERT_EMAIL could
//    have been unwired with every check in this file staying green: C2 stores
//    "rclone", which is also the value in the script file, so that override
//    carried nothing at all.
//
//    So every case has TWO parts: a positive check that the stored value
//    really reached the script and changed what it did, and the same check run
//    again with that one override removed, where it must fail. The removal
//    goes through oss_withoutLine(), which refuses to go on unless it really
//    removed one line.

// 📬 A stand-in for the server's mail program, so the alert address can be
//    read back and nothing is really posted. The script calls "mail" by name,
//    so putting this first on the PATH is enough. /usr/bin/mail does exist on
//    this machine, which is exactly why the stand-in is needed: without it
//    D7 would try to deliver a message.
$standInBin = $scratch . DIRECTORY_SEPARATOR . 'bin';
mkdir($standInBin, 0700);
$mailRecord = $scratch . DIRECTORY_SEPARATOR . 'mail-was-called.txt';
file_put_contents(
    $standInBin . DIRECTORY_SEPARATOR . 'mail',
    "#!/usr/bin/env bash\n"
    . "# A stand-in for the server's mail program, written by\n"
    . "# tools/offsite-settings-selftest.php for one run and deleted with its\n"
    . "# folder. It records the arguments it was given and swallows the message.\n"
    . 'printf '  . "'%s\\n' \"\$*\" >> " . escapeshellarg($mailRecord) . "\n"
    . "cat > /dev/null\n"
);
chmod($standInBin . DIRECTORY_SEPARATOR . 'mail', 0700);
$withMailStandIn = 'PATH=' . escapeshellarg($standInBin . PATH_SEPARATOR . (string) getenv('PATH'));

/** Five old copies at the destination, for the two "copies to keep" cases. */
function oss_layOldCopies(string $destinationDir): void
{
    foreach (['upgrade-20260101-000000', 'upgrade-20260201-000000', 'upgrade-20260301-000000', 'upgrade-20260401-000000', 'upgrade-20260501-000000'] as $old) {
        file_put_contents($destinationDir . DIRECTORY_SEPARATOR . $old . '.tar.gz.enc', 'an old copy');
    }
}

/** How many of OUR copies are at the destination now. */
function oss_ourCopies(string $destinationDir): int
{
    return count(array_filter(
        oss_destinationFiles($destinationDir),
        static fn (string $n): bool => preg_match('/^upgrade-[0-9]{8}-[0-9]{6}\.tar\.gz\.enc$/', $n) === 1
    ));
}

// --- D1/D2: the rclone remote ------------------------------------------------
//     The script file points at a remote that does not exist, so a copy
//     arriving at all (C2) is the proof. With the override gone the run must
//     fail ON THE SCRIPT FILE'S OWN REMOTE — which is a signal no syntax error
//     could produce, and that is the part that was missing.
oss_clearLog($db);
oss_emptyDestination($destinationDir);
$allSixStored = ['enabled' => '1', 'destination' => 'rclone', 'rcloneRemote' => $remote, 'keepWeekly' => '2', 'keepMonthly' => '2', 'alertEmail' => ''];
oss_setOffsite($db, $allSixStored);
[$withoutRemote, $removedLines] = oss_withoutLine($withBogusRemote, 'RCLONE_REMOTE="${SETTING_VALUE}"');
oss_check($removedLines === 1, 'D1 the remote override really was removed from the script (if this fails, the plant did nothing and D2 means nothing)', 'lines removed: ' . $removedLines);
oss_restoreScript($scriptAt, $withoutRemote);
$run = oss_runScript($scriptAt, $rcloneConfig);
oss_check(str_contains($run['out'], 'Settings from: /admin/maintenance/offsite-backup (6 setting(s))') === true, 'D2 the bridge was still read and still counted six settings, so the plant removed the override and nothing else', $run['out']);
oss_check(str_contains($run['out'], 'nowhere:this-remote-does-not-exist') === true, 'D2 and the run failed naming the remote in the SCRIPT FILE, so that is the one it used', $run['out']);
oss_check(oss_destinationFiles($destinationDir) === [], 'D2 and nothing arrived, so C2\'s copy really was proof of the override', implode(',', oss_destinationFiles($destinationDir)));
$row = oss_lastLogRow($db);
oss_check($row !== null && (string) $row['status'] === 'failed', 'D2 and the failure was recorded rather than passing in silence', $row === null ? '(no row)' : (string) $row['status']);

// --- D3: the destination ----------------------------------------------------
//     Stored "s3", while the script file's s3 block is commented out. The
//     stored word therefore makes the run refuse with a message naming s3 —
//     something the script file's own "rclone" could never produce.
oss_clearLog($db);
oss_emptyDestination($destinationDir);
oss_restoreScript($scriptAt, $withGoodRemote);
oss_setOffsite($db, ['enabled' => '1', 'destination' => 's3', 'rcloneRemote' => $remote, 'keepWeekly' => '2', 'keepMonthly' => '2', 'alertEmail' => '']);
$run = oss_runScript($scriptAt, $rcloneConfig);
oss_check(str_contains($run['out'], 'The destination is s3 but S3_BUCKET is not set') === true, 'D3 a stored destination of "s3" really reaches the script, which refuses because this file has no bucket set', $run['out']);
oss_check(oss_destinationFiles($destinationDir) === [], 'D3 and nothing was sent with rclone, which is what the script file says to use', implode(',', oss_destinationFiles($destinationDir)));
[$withoutDestination, $removedLines] = oss_withoutLine($withGoodRemote, 'DESTINATION="${SETTING_VALUE}"');
oss_check($removedLines === 1, 'D3 the destination override really was removed for the planted run', 'lines removed: ' . $removedLines);
oss_emptyDestination($destinationDir);
oss_restoreScript($scriptAt, $withoutDestination);
$run = oss_runScript($scriptAt, $rcloneConfig);
oss_check(str_contains($run['out'], 'The destination is s3 but S3_BUCKET is not set') === false, 'D3 with that one override removed the stored "s3" reaches nothing', $run['out']);
oss_check(oss_destinationFiles($destinationDir) === [$snapshotName . '.tar.gz.enc'], 'D3 and the run quietly uses rclone from the script file instead — which is what the check above would have missed', implode(',', oss_destinationFiles($destinationDir)));

// --- D4: enabled ------------------------------------------------------------
//     The script file says 1. Stored 0 must stop the run; with the override
//     gone the whole database is packed and sent by a setting that says not to.
oss_clearLog($db);
oss_emptyDestination($destinationDir);
oss_restoreScript($scriptAt, $withGoodRemote);
oss_setOffsite($db, ['enabled' => '0', 'destination' => 'rclone', 'rcloneRemote' => $remote, 'keepWeekly' => '2', 'keepMonthly' => '2', 'alertEmail' => '']);
$run = oss_runScript($scriptAt, $rcloneConfig);
$row = oss_lastLogRow($db);
oss_check($row !== null && (string) $row['status'] === 'skipped', 'D4 a stored 0 skips the run even though the script file says 1', $row === null ? '(no row)' : (string) $row['status']);
oss_check(oss_destinationFiles($destinationDir) === [], 'D4 and nothing was sent', implode(',', oss_destinationFiles($destinationDir)));
[$withoutEnabled, $removedLines] = oss_withoutLine($withGoodRemote, 'ENABLED="${SETTING_VALUE}"');
oss_check($removedLines === 1, 'D4 the enabled override really was removed for the planted run', 'lines removed: ' . $removedLines);
oss_clearLog($db);
oss_restoreScript($scriptAt, $withoutEnabled);
$run = oss_runScript($scriptAt, $rcloneConfig);
$row = oss_lastLogRow($db);
oss_check($row !== null && (string) $row['status'] === 'success', 'D4 with that one override removed the run goes ahead against the stored 0', $row === null ? '(no row)' : (string) $row['status']);
oss_check(oss_destinationFiles($destinationDir) === [$snapshotName . '.tar.gz.enc'], 'D4 and a copy is sent that nobody asked for, so D4 above was really testing the override', implode(',', oss_destinationFiles($destinationDir)));

// --- D5 and D6: the two "copies to keep" figures ----------------------------
//     The script file says 8 and 12, so 20 kept. Stored 1 and 1 means 2 kept,
//     and the run says so in words: "The newest 2 are kept". Removing either
//     override changes that number to 9 (8 + 1) or 13 (1 + 12), so each is
//     caught by a figure only its own override can produce.
oss_clearLog($db);
oss_emptyDestination($destinationDir);
oss_layOldCopies($destinationDir);
oss_restoreScript($scriptAt, $withGoodRemote);
oss_setOffsite($db, ['enabled' => '1', 'destination' => 'rclone', 'rcloneRemote' => $remote, 'keepWeekly' => '1', 'keepMonthly' => '1', 'alertEmail' => '']);
$run = oss_runScript($scriptAt, $rcloneConfig);
oss_check(str_contains($run['out'], 'The newest 2 are kept') === true, 'D5 stored figures of 1 and 1 really decide: the run keeps 2, not the script file\'s 20', $run['out']);
oss_check(oss_ourCopies($destinationDir) === 2, 'D5 and exactly 2 of our copies are left at the destination', (string) oss_ourCopies($destinationDir));
[$withoutWeekly, $removedLines] = oss_withoutLine($withGoodRemote, 'KEEP_WEEKLY="${SETTING_VALUE}"');
oss_check($removedLines === 1, 'D5 the weekly override really was removed for the planted run', 'lines removed: ' . $removedLines);
oss_emptyDestination($destinationDir);
oss_layOldCopies($destinationDir);
oss_restoreScript($scriptAt, $withoutWeekly);
$run = oss_runScript($scriptAt, $rcloneConfig);
oss_check(str_contains($run['out'], 'The newest 9 are kept') === true, 'D5 with that one override removed the run keeps 9 (the file\'s 8 plus the stored 1), so the figure above was really the stored one', $run['out']);
oss_check(oss_ourCopies($destinationDir) === 6, 'D5 and nothing old is deleted at all', (string) oss_ourCopies($destinationDir));
[$withoutMonthly, $removedLines] = oss_withoutLine($withGoodRemote, 'KEEP_MONTHLY="${SETTING_VALUE}"');
oss_check($removedLines === 1, 'D6 the monthly override really was removed for the planted run', 'lines removed: ' . $removedLines);
oss_emptyDestination($destinationDir);
oss_layOldCopies($destinationDir);
oss_restoreScript($scriptAt, $withoutMonthly);
$run = oss_runScript($scriptAt, $rcloneConfig);
oss_check(str_contains($run['out'], 'The newest 13 are kept') === true, 'D6 and removing the monthly override alone gives 13 (the stored 1 plus the file\'s 12)', $run['out']);

// --- D7: the alert address --------------------------------------------------
//     The only one with no effect on a SUCCESSFUL run: it is used in die(),
//     where a failure is e-mailed. So this run is made to fail, and a stand-in
//     mail program records which address it was handed. The script file is
//     given a different address, so the two directions can be told apart —
//     with an empty one in the file, removing the override would simply send
//     nothing and look much like a failure to send.
oss_clearLog($db);
oss_emptyDestination($destinationDir);
@unlink($mailRecord);
$withFileAlert = str_replace('ALERT_EMAIL=""', 'ALERT_EMAIL="file-fallback@example.com"', $withBogusRemote);
oss_check($withFileAlert !== $withBogusRemote, 'D7 the script file was really given its own alert address for this test');
oss_restoreScript($scriptAt, $withFileAlert);
// A stored remote the bridge will not use, so the run falls back to the script
// file's bogus remote and fails — which is what reaches die().
oss_setOffsite($db, ['enabled' => '1', 'destination' => 'rclone', 'rcloneRemote' => 'not a valid remote', 'keepWeekly' => '2', 'keepMonthly' => '2', 'alertEmail' => 'stored-alert@example.com']);
$run         = oss_runScript($scriptAt, $rcloneConfig, $withMailStandIn);
$mailedTo    = is_file($mailRecord) === true ? (string) file_get_contents($mailRecord) : '';
oss_check($run['code'] !== 0, 'D7 the run fails, which is the only moment the alert address is used', 'exit ' . $run['code']);
oss_check(str_contains($mailedTo, 'stored-alert@example.com') === true, 'D7 and the failure is e-mailed to the address stored on the page', $mailedTo === '' ? '(mail was never called)' : $mailedTo);
oss_check(str_contains($mailedTo, 'file-fallback@example.com') === false, 'D7 and NOT to the one in the script file', $mailedTo);
[$withoutAlert, $removedLines] = oss_withoutLine($withFileAlert, 'ALERT_EMAIL="${SETTING_VALUE}"');
oss_check($removedLines === 1, 'D7 the alert override really was removed for the planted run', 'lines removed: ' . $removedLines);
@unlink($mailRecord);
oss_restoreScript($scriptAt, $withoutAlert);
$run      = oss_runScript($scriptAt, $rcloneConfig, $withMailStandIn);
$mailedTo = is_file($mailRecord) === true ? (string) file_get_contents($mailRecord) : '';
oss_check(str_contains($mailedTo, 'file-fallback@example.com') === true, 'D7 with that one override removed the failure goes to the script file\'s address, so the check above was really testing the override', $mailedTo === '' ? '(mail was never called)' : $mailedTo);
oss_check(str_contains($mailedTo, 'stored-alert@example.com') === false, 'D7 and the address the administrator typed is never told', $mailedTo);

oss_restoreScript($scriptAt, $pristineScript);

// =============================================================================
echo "\n" . str_repeat('-', 70) . "\n";
echo 'Passed: ' . $passed . '   Failed: ' . $failed . "\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $line) {
        echo '  - ' . $line . "\n";
    }
    echo "\nNOT OK\n";
    exit(1);
}
echo "\nREACHED THE END\n";
exit(0);
