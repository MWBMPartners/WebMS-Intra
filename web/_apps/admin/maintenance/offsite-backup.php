<?php
// Path: public_html/admin/maintenance/offsite-backup.php
/**
 * Admin — Off-site backup status + manual trigger.
 *
 * WHO MAY SAVE THE SETTINGS, AND WHY IT IS NARROWER THAN WHO MAY LOOK
 * -----------------------------------------------------------------------------
 * The settings form on this page saves portal-wide rows (nothing in the siteID
 * column). There is one off-site copy for the whole installation — every
 * organisation's records live in the same database, so they are in the same
 * snapshot — which makes where that copy goes, how long it is kept and who is
 * told when it fails decisions for the installation, not for one organisation.
 *
 * Until 13 September 2026 the only check was App::isAdmin(), which is true for
 * an administrator of a SINGLE organisation as well as for a global
 * administrator (see web/_core/App.php). So an administrator of one
 * organisation could switch the off-site copy off for everybody, point it at
 * a storage destination of their own choosing, or send the failure alerts to
 * themselves. The owner decided that settings affecting every organisation are
 * for a global administrator only.
 *
 * An administrator of one organisation can still open the page and see the
 * backup status. The settings form is shown to them read-only, with the
 * reason written above it, and the save is refused on the server whatever the
 * page shows, because a form can be sent without ever opening the page.
 *
 * "RUN NOW" IS A SEPARATE ACTION, GATED THE SAME WAY
 * -----------------------------------------------------------------------------
 * The "Run now" button does not save a setting — it posts to a different
 * handler (web/_apps/admin/maintenance/offsite-backup-run.php) that executes
 * the off-site sync script immediately. But that script archives and uploads
 * the SAME shared snapshot every organisation's data lives in, and its own
 * pruning step can delete older remote copies too, so it carries exactly the
 * same installation-wide blast radius as the settings above it. Until
 * 13 September 2026 this page showed the button (and the text inviting its
 * use) to any administrator, without checking who they were, whenever the
 * sync script and encryption key existed on disk — so an administrator of one
 * organisation could trigger, and indirectly prune, the off-site copy every
 * other organisation depends on. The button and its explanatory text are now
 * shown only when `App::isRootAdmin()` is true; a site administrator instead
 * sees a short explanation and no button. The handler itself
 * (offsite-backup-run.php) is being brought into line with this in the same
 * round of fixes, by a change to that file specifically.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.2.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/249
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/495
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\InstallationSettings;
use Portal\Core\OffsiteSettings;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// 🛡️ Looking is allowed for any administrator; saving the settings is not. See
//    the note in the file header for why. Worked out once and used by both the
//    handler and the form below, so the two can never disagree.
$mayChangePortalWideSettings = App::isRootAdmin();

// 🛡️ "Run now" is a different action from saving the settings above (it
//    executes the sync script immediately rather than writing a row), but it
//    carries the same installation-wide reach, so it needs the same
//    permission. Kept as its own named variable rather than reusing
//    $mayChangePortalWideSettings, even though both currently read
//    App::isRootAdmin(): the two questions ("may this visitor change the
//    settings" and "may this visitor run the script now") are conceptually
//    different, and giving each its own name means a future change to one
//    can't silently change the other's behaviour too. See "RUN NOW IS A
//    SEPARATE ACTION" in the file header for the history of why this exists.
$mayRunOffsiteBackup = App::isRootAdmin();

$db = App::db();

// POST handler — must run before any output so the redirect is clean.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        http_response_code(400);
        exit('Bad request');
    }

    // 🚧 Refused BEFORE anything is written: every key this form saves is
    //    portal-wide. The refusal is worded rather than a bare "forbidden",
    //    because an administrator who presses Save and is told nothing assumes
    //    the portal is broken and tries again. It sits after the form-token
    //    check, so a forged request from another website cannot fill the
    //    activity log with refusals.
    if ($mayChangePortalWideSettings === false) {
        Logger::activity(
            'SettingsGroupSaveRefused',
            'Refused: portal-wide settings group "backup.offsite" may only be changed by a global administrator',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = 'Off-site backup settings are portal-wide: there is one '
            . 'off-site copy for every organisation on this installation, not only yours. '
            . 'Only a global administrator can change them. Nothing has been changed.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/maintenance/offsite-backup'));
        exit();
    }
    $upsert = static function (mysqli $db, string $key, string $value): void {
        $stmt = $db->prepare('SELECT settingID FROM tblSettings WHERE settingKey = ? AND siteID IS NULL LIMIT 1');
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $stmt->bind_result($id);
        $exists = $stmt->fetch() === true;
        $stmt->close();
        if ($exists === true) {
            $u = $db->prepare('UPDATE tblSettings SET settingValue = ?, updatedAt = NOW() WHERE settingID = ?');
            if ($u !== false) {
                $u->bind_param('si', $value, $id);
                $u->execute();
                $u->close();
            }
        } else {
            $u = $db->prepare('INSERT INTO tblSettings (settingKey, settingValue, isSensitive, siteID, updatedAt) VALUES (?, ?, 0, NULL, NOW())');
            if ($u !== false) {
                $u->bind_param('ss', $key, $value);
                $u->execute();
                $u->close();
            }
        }
    };
    $destChoice = (string) ($_POST['destination'] ?? 'rclone');
    if (OffsiteSettings::destinationIsKnown($destChoice) === false) {
        $destChoice = 'rclone';
    }
    $upsert($db, 'backup.offsite.enabled',      isset($_POST['enabled']) === true ? '1' : '0');
    $upsert($db, 'backup.offsite.destination',  $destChoice);
    $upsert($db, 'backup.offsite.rcloneRemote', trim((string) ($_POST['rcloneRemote'] ?? '')));
    // 🗂️ The range comes from Portal\Core\OffsiteSettings, not from literals
    //    here. It was written `max(1, min(52, …))` and `max(1, min(60, …))`,
    //    which meant three places decided the same thing — this handler, the
    //    display further down, and the bridge the script reads — so changing a
    //    constant would have left the save behind, against the rule that one
    //    place decides.
    $upsert($db, 'backup.offsite.keepWeekly',   (string) OffsiteSettings::clampKeep((int) ($_POST['keepWeekly'] ?? 8), 'weekly'));
    $upsert($db, 'backup.offsite.keepMonthly',  (string) OffsiteSettings::clampKeep((int) ($_POST['keepMonthly'] ?? 12), 'monthly'));
    $upsert($db, 'backup.offsite.alertEmail',   trim((string) ($_POST['alertEmail'] ?? '')));
    // ⚠️ SAY SO NOW, not in a week's run log.
    //
    //    A remote with a space or a capital in the wrong place is an ordinary
    //    typo. It used to be stored, answered with a plain "saved", shown back
    //    in the box, and badged "in force", while every run used the remote
    //    written inside the script file — so where the customer had set that
    //    fallback to a real remote, the copy went somewhere they had not chosen
    //    and nothing in the portal said so (#671). The same question is asked
    //    here and by the bridge, through Portal\Core\OffsiteSettings, so the
    //    two answers cannot differ.
    //
    //    The value is still SAVED. Refusing it outright was considered and
    //    rejected: the other five settings on the form would be lost with it,
    //    and somebody half way through setting this up has a right to store a
    //    draft. What matters is that they are told it will not be used.
    $cannotUse = [];
    $whyRemote = OffsiteSettings::whyRemoteUnusable(trim((string) ($_POST['rcloneRemote'] ?? '')));
    $whyAlert  = OffsiteSettings::whyAlertUnusable(trim((string) ($_POST['alertEmail'] ?? '')));
    if ($whyRemote !== '') {
        $cannotUse[] = 'The rclone remote will not be used because ' . $whyRemote . '.';
    }
    if ($whyAlert !== '') {
        $cannotUse[] = 'The alert address will not be used because ' . $whyAlert . '.';
    }
    if (count($cannotUse) > 0) {
        $_SESSION['flash_msg'] = 'Saved — but one or more values cannot be used, so a run will fall back '
            . 'to the value written inside the script file on the server. ' . implode(' ', $cannotUse);
        $_SESSION['flash_type'] = 'warning';
    } else {
        $_SESSION['flash_msg']  = 'Off-site backup settings saved.';
        $_SESSION['flash_type'] = 'success';
    }
    header('Location: ' . Site::url('admin/maintenance/offsite-backup'));
    exit();
}

// 🏢 THE INSTALLATION'S OWN ROWS, NEVER THE MERGED ARRAY (#674).
//
//    This used to read App::settings(), which bootstrap has already merged:
//    an organisation's own row wins there. Every box on this form SAVES
//    installation-wide (the $upsert above writes siteID NULL), so with the
//    merged read a global administrator working inside an organisation that
//    had its own backup.offsite.* row would see that organisation's value,
//    press Save without touching anything, and make it everyone's. No
//    mistake required. The page was on the pending list of
//    tools/audit-checks/check_install_wide_prefill.py for exactly this.
//
//    There is a second reason here, beyond the rule: since #671 the sync
//    script reads these settings through read-offsite-settings.php, which
//    reads the INSTALLATION's layer. If this page showed the merged value,
//    the page and the script could show and use different destinations — the
//    page would be describing a copy going somewhere it was not going.
//
//    An organisation cannot normally get a row of its own for these keys —
//    _apps/settings/save.php refuses to add one for a key the installation
//    already has — but "normally" is not a guarantee: a row can arrive by
//    hand-written SQL or in a restored database, and that is precisely when
//    this page must not quietly promote it.
$settings = InstallationSettings::layer()['backup']['offsite'] ?? [];
if (is_array($settings) === false) {
    $settings = [];
}
$enabled  = (string) ($settings['enabled'] ?? '0') === '1';
$dest     = (string) ($settings['destination'] ?? 'rclone');
$remote   = (string) ($settings['rcloneRemote'] ?? '');
// 🗂️ Brought into the range these boxes themselves allow, FOR DISPLAY ONLY.
//
//    A stored figure outside 1-52 or 1-60 can only have come from hand-written
//    SQL or a restored database, and prefilling one into an input carrying
//    min/max makes the browser refuse to submit THE WHOLE FORM — so every
//    other setting on the page becomes unsavable, with no message saying why.
//    That trap is written down in #668 ("must never prefill a value its own
//    max forbids") and this page had it.
//
//    Clamping here changes nothing in the database: the row keeps its value
//    until somebody saves. The run itself is capped separately by
//    read-offsite-settings.php, which warns when it does so — a save-time cap
//    is not a cap (#668), and nor is a display-time one.
$keepWStored = (int) ($settings['keepWeekly'] ?? 8);
$keepMStored = (int) ($settings['keepMonthly'] ?? 12);
$keepW    = OffsiteSettings::clampKeep($keepWStored, 'weekly');
$keepM    = OffsiteSettings::clampKeep($keepMStored, 'monthly');
$keepOutOfRange = $keepW !== $keepWStored || $keepM !== $keepMStored;
$alertTo  = (string) ($settings['alertEmail'] ?? '');

$scriptPath = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_backups' . DIRECTORY_SEPARATOR . 'sync-offsite.sh';
$scriptExists = is_file($scriptPath);
$keyPath = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_auth_keys' . DIRECTORY_SEPARATOR . 'offsite.key';
$keyExists = is_readable($keyPath);

// 📖 Does the copy of the sync script ON THIS SERVER actually obey the boxes
//    below?
//
//    WHY THIS IS ASKED AT ALL (#671). Until 30 October 2026 the script read
//    none of these settings — it used the variables written into its own copy
//    — so saving this form changed nothing anywhere, on any installation. The
//    script now reads them through _backups/read-offsite-settings.php. But the
//    script is copied to the server BY HAND and is never deployed, so this
//    portal cannot assume the copy sitting there is a current one: an
//    installation set up before that date has a script that still ignores this
//    page completely. Telling an administrator their settings are in force
//    when the script on their own server ignores them would be the same fault
//    again, one step along.
//
//    WHY IT CHECKS THE MECHANISM AND NOT A VERSION LABEL. A "@version 1.2.0"
//    line is a claim, and a customer editing the file keeps the claim while
//    changing the behaviour. The two things that actually have to be true are
//    that the reader is there, and that the script really calls it. Those are
//    what is checked.
//
//    WHY THE COMMENTS ARE STRIPPED FIRST, AND THIS IS THE WHOLE POINT. The
//    script NAMES read-offsite-settings.php several times in its own comments,
//    explaining what it does and what it cannot do. So a plain search for that
//    name — or for READ_PHP — is satisfied by a script whose call has been
//    commented out, which is exactly the state this check exists to catch. In
//    a shell script a comment runs from an unquoted "#" to the end of the
//    line, and every mention in the script's prose is in such a line, so
//    dropping lines whose first non-space character is "#" is enough here.
//
//    WHAT IT CANNOT SEE, said plainly:
//      - A "#" part way along a line still leaves that line in. Nothing in the
//        shipped script needs that, and treating a "#" inside a quoted string
//        as a comment would be wrong.
//      - A CALL THAT IS THERE BUT DISABLED BY ITS OWN CONDITION. `false && php
//        "${READ_PHP}"` matches, so this says "in force" while the reader is
//        never run. That is the shape this project has twice been caught by,
//        and it is NOT guarded here: judging whether a shell condition can
//        ever be true needs a shell, not a pattern. What limits the harm is
//        that nothing but a deliberate edit produces it, and the run's own
//        output says "Settings from: this script file" when it happens.
//      - A script that calls the reader and then throws its answer away.
//      - Whether the reader itself is a current version. Only the format
//        number on its first line tells the script that, at run time.
//      - A script that is there but not executable, or scheduled nowhere at
//        all. Neither is knowable from here.
//      - Whether the RECORDER is there, which is a different question asked
//        separately just below — without it a copy can be sent and this page
//        shows nothing at all.
$readerPath   = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_backups' . DIRECTORY_SEPARATOR . 'read-offsite-settings.php';
$readerExists = is_file($readerPath);
$scriptReadsSettings = false;
if ($scriptExists === true && is_readable($scriptPath) === true) {
    // 256 KB is far more than the shipped script (about 30 KB) and stops a
    // very large file, or something that is not a script at all, being read
    // into memory on a shared host.
    $scriptText = (string) file_get_contents($scriptPath, false, null, 0, 262144);
    $codeOnly   = '';
    foreach (preg_split('/\R/', $scriptText) ?: [] as $scriptLine) {
        if (preg_match('/^\s*#/', $scriptLine) === 1) {
            continue;
        }
        $codeOnly .= $scriptLine . "\n";
    }
    // Both halves, because either alone is satisfied by a half-finished edit:
    // the path set but never used, or a call to a variable nothing sets.
    //
    // The call pattern accepts BOTH `"${READ_PHP}"` and `$READ_PHP`. It
    // required the braces at first, so a script written the other way — the
    // same call, doing the same thing — was reported in a red alert as
    // ignoring this page, which is the worse direction: it tells somebody
    // their settings are being ignored when they are obeyed, and hides the
    // switched-off notice at the same time.
    $setsReaderPath = preg_match('/READ_PHP\s*=/', $codeOnly) === 1;
    $callsReader    = preg_match('/php\s+"?\$(\{READ_PHP\}|READ_PHP\b)"?/', $codeOnly) === 1;
    $scriptReadsSettings = $setsReaderPath === true && $callsReader === true && $readerExists === true;
}

// 📝 The RECORDER, which is a fourth file and a separate question (#671).
//
//    Everything this page says about whether copies are being sent rests on
//    tblOffsiteSyncLog, and only log-offsite-result.php writes to it. Without
//    that file — or when it cannot reach the database at the moment a
//    scheduled run ends — copies really are sent and this page shows nothing,
//    which is indistinguishable from "it never ran". A failed run is just as
//    invisible, because a failure is recorded through the same file. So the
//    page has to say when it cannot see.
$recorderPath   = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_backups' . DIRECTORY_SEPARATOR . 'log-offsite-result.php';
$recorderExists = is_file($recorderPath);

// `output` is SELECTed because the run's own warnings live there, and they are
// the only record that a stored setting was refused — the bridge holds such a
// value back rather than escaping it, and says so in a WARNING line. The page
// did not read this column at first, so a mistyped destination left no trace
// anywhere an administrator could reach, while the card said "in force".
/**
 * The WARNING lines a run printed, out of its recorded output.
 *
 * These are the only record that a stored setting was HELD BACK. The settings
 * bridge refuses a value it will not put on a command line rather than
 * escaping it, and a WARNING line is the one thing that says which setting and
 * why — so a mistyped destination otherwise leaves no trace an administrator
 * can reach, while the card says the settings are in force (#671).
 *
 * The run's output is its own text, written by the script, not by a visitor —
 * but it travels through a settings box, so it is escaped where it is printed,
 * like anything else.
 *
 * @return string[]
 */
$warningsFrom = static function (?string $output): array {
    if ($output === null || $output === '') {
        return [];
    }
    $lines = [];
    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        if (str_starts_with(trim($line), 'WARNING:') === true) {
            $lines[] = trim($line);
        }
    }
    return $lines;
};

$recent = [];
$rs = $db->query(
    'SELECT logID, runAt, triggeredBy, destination, snapshotName, bundleSize, durationSec, status, errorMsg, output '
    . 'FROM tblOffsiteSyncLog ORDER BY runAt DESC LIMIT 20'
);
if ($rs !== false) {
    while ($r = $rs->fetch_assoc()) {
        $recent[] = $r;
    }
    $rs->free();
}

$last = $recent[0] ?? null;

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Off-site backup';
$pageSection = 'admin';
// 'Maintenance' has no page of its own, so it is shown without a link (#612). It used to link to
// 'admin/maintenance', which is not an address anyone answers.
$breadcrumbs = ['Dashboard' => Site::url(''), 'Admin' => Site::url('admin'), 'Maintenance' => '', 'Off-site backup' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-cloud-arrow-up me-2"></i>Off-site backup</h1>
<p class="text-secondary">
    A locked copy of the newest database snapshot, sent somewhere outside this hosting
    account on a schedule. It is what saves you when the host loses the whole account,
    its own snapshots included.
</p>
<?php
// ⚠️ A standing notice, deliberately NOT dismissible and NOT inside the flash
//    block, the same way the AI Assist and Zoom pages say what is not
//    finished (#671).
//
//    WITHOUT IT THIS PAGE LOOKS LIKE A FINISHED FEATURE and says nothing about
//    the one thing that decides whether it can work at all: it needs a command
//    line on the server and a program to send with. This product is built for
//    shared hosting with no command line, which is the whole reason the backup
//    download and import exist, so for many customers this page can never do
//    anything — and an administrator reading it would reasonably conclude their
//    database was being copied somewhere safe.
//
//    It is shown to everybody who can open the page, not only to a global
//    administrator: a site administrator looking at "Last sync: never" deserves
//    the same explanation.
?>
<div class="alert alert-warning d-flex align-items-start" role="alert">
    <i class="fa-solid fa-triangle-exclamation me-2 mt-1"></i>
    <div>
        <strong>This needs a command line on the server, and it is not set up for you.</strong>
        The copy is made by a script that has to be put on the server by hand and
        scheduled in your hosting panel, and it needs a program to send with
        (<code>rclone</code>, the <code>aws</code> tool, or <code>scp</code>). On shared
        hosting without a command line, none of that is possible and nothing on this page
        will ever send anything — whatever it is set to.
        <strong>This page can tell you a copy WAS sent; it cannot promise that none was.</strong>
        A run is recorded at the very end, so a run that sends a copy and then cannot reach
        this database leaves nothing here to show — an empty “Recent runs” means
        “nothing recorded”, not “nothing sent”. To be certain either way, look at the
        destination itself.
        If you cannot use a command line, take copies by hand instead with
        <a href="<?php echo htmlspecialchars(Site::url('admin/maintenance/backup'), ENT_QUOTES, 'UTF-8'); ?>">Download a backup</a>,
        and keep them somewhere that is not this server. The full setup steps are in
        <code>docs/offsite-backup-setup.md</code> in the portal's source.
    </div>
</div>

<?php
// ⚠️ THE NEWEST RUN'S WARNINGS, shown where they will actually be read.
//
//    A warning means the run did not do what this page says: a setting was
//    held back and the value inside the script file was used instead. That is
//    the one way the page and the reality can still differ, so it is said
//    here, in full, rather than left in a column at the bottom of the page.
//
//    Only the newest run's, because an old warning that has since been put
//    right would be alarming and wrong; the table below carries the history.
$newestWarnings = $last !== null ? $warningsFrom($last['output'] ?? null) : [];
if (count($newestWarnings) > 0):
?>
    <div class="alert alert-warning" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i>
        <strong>The last run did not use everything set on this page.</strong>
        It warned about the following, and used the value written inside the script
        file instead. Until this is put right, the copy may be going somewhere other
        than the settings below describe.
        <ul class="mb-0 mt-2 small">
            <?php foreach ($newestWarnings as $warningLine): ?>
                <li><?php echo htmlspecialchars($warningLine, ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php
// 🗂️ A stored keep figure outside what these boxes allow. Said plainly,
//    because the boxes below now show the clamped figure and would otherwise
//    be quietly different from what is stored.
if ($keepOutOfRange === true):
?>
    <div class="alert alert-warning" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i>
        <strong>A stored &ldquo;copies to keep&rdquo; figure is outside what this page allows.</strong>
        The boxes below show the nearest allowed figure, which is also what a run uses;
        the stored value is unchanged until you save. It cannot have been set here, so it
        came from a restored database or from SQL by hand.
    </div>
<?php endif; ?>

<?php
// 🔘 Switched off, with a script sitting there ready to run. Worth saying out
//    loud, because the box and the schedule are set in two different places:
//    the hosting panel will fire the script on time and the script will decline
//    to send, which is correct and completely invisible otherwise.
//
//    Only shown once the script really does read these settings — on an older
//    copy the box decides nothing, and the notice below says so instead.
if ($enabled === false && $scriptReadsSettings === true):
?>
    <div class="alert alert-secondary d-flex align-items-start" role="alert">
        <i class="fa-solid fa-circle-pause me-2 mt-1"></i>
        <div>
            <strong>Switched off.</strong>
            The script on this server is the kind that obeys this page, and
            <em>Enabled</em> below is not ticked, so a scheduled run records itself as
            <span class="badge bg-secondary">skipped</span> and sends nothing. Tick
            <em>Enabled</em> and save to start sending.
            <br>
            <span class="small text-muted">
                One exception, and it is deliberate: the script only learns this by asking
                the portal. If it cannot — the database unreachable at that moment, or
                <code>php</code> missing from the scheduled job's path — it falls back to the
                <code>ENABLED</code> value written inside the script file, which ships as
                “send”, so that a database problem does not quietly stop your backups. Set
                that to <code>0</code> as well if this must never send.
            </span>
        </div>
    </div>
<?php endif; ?>

<?php
// 📖 The settings on this page are only in force if the copy of the script on
//    this server is one that reads them. Said here, next to the boxes, rather
//    than left for somebody to discover: an administrator who sets a
//    destination and is told it saved has every reason to think it took effect.
if ($scriptExists === true && $scriptReadsSettings === false):
?>
    <div class="alert alert-danger d-flex align-items-start" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2 mt-1"></i>
        <div>
            <strong>The script on this server ignores the settings below.</strong>
            <?php if ($readerExists === false): ?>
                <code>read-offsite-settings.php</code> is not in the portal's
                <code>_backups</code> folder, so the script has no way to read them.
            <?php else: ?>
                The copy of <code>sync-offsite.sh</code> on this server does not call
                <code>read-offsite-settings.php</code>, so it uses the values written
                inside itself.
            <?php endif; ?>
            Scripts copied to a server before 30 October 2026 read none of these boxes.
            Saving this form will store your choices, and the scheduled copy will carry
            on using the values in the script file until both files are replaced with a
            matching pair from the portal's source.
        </div>
    </div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card"><div class="card-body">
        <div class="small text-muted">Last sync</div>
        <?php if ($last !== null): ?>
            <div class="h4 mb-0">
                <?php $cls = match ((string) $last['status']) { 'success' => 'success', 'skipped' => 'secondary', default => 'danger' }; ?>
                <span class="badge bg-<?php echo $cls; ?>"><?php echo htmlspecialchars((string) $last['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                <small class="text-muted"><?php echo htmlspecialchars((string) $last['runAt'], ENT_QUOTES, 'UTF-8'); ?></small>
            </div>
        <?php else: ?>
            <div class="h4 mb-0 text-muted">never</div>
        <?php endif; ?>
    </div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body">
        <div class="small text-muted">Script + key status</div>
        <div>
            sync-offsite.sh: <?php echo $scriptExists === true ? '<span class="badge bg-success">present</span>' : '<span class="badge bg-warning">missing</span>'; ?><br>
            read-offsite-settings.php: <?php echo $readerExists === true ? '<span class="badge bg-success">present</span>' : '<span class="badge bg-warning">missing</span>'; ?><br>
            log-offsite-result.php: <?php echo $recorderExists === true ? '<span class="badge bg-success">present</span>' : '<span class="badge bg-warning">missing</span>'; ?><br>
            offsite.key: <?php echo $keyExists === true ? '<span class="badge bg-success">present</span>' : '<span class="badge bg-warning">missing</span>'; ?>
        </div>
        <?php
        // 📖 "Present" above is only about the files being there. Whether the
        //    script actually obeys this page is a different question, answered
        //    once at the top of this file, and shown here in a word so the
        //    three lines above cannot be read as meaning more than they do.
        ?>
        <div class="small mt-2">
            Settings below:
            <?php if ($scriptReadsSettings === true): ?>
                <span class="badge bg-success">in force</span>
            <?php elseif ($scriptExists === true): ?>
                <span class="badge bg-danger">ignored by the script</span>
            <?php else: ?>
                <span class="badge bg-secondary">no script to obey them</span>
            <?php endif; ?>
        </div>
    </div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body">
        <div class="small text-muted">Configured</div>
        <div class="h4 mb-0">
            <?php if ($enabled === true): ?>
                <span class="badge bg-success">enabled</span>
            <?php else: ?>
                <span class="badge bg-secondary">disabled</span>
            <?php endif; ?>
        </div>
        <div class="small text-muted"><?php echo htmlspecialchars($dest, ENT_QUOTES, 'UTF-8'); ?></div>
    </div></div></div>
</div>

<?php if ($scriptExists === false || $keyExists === false || $readerExists === false || $recorderExists === false): ?>
    <div class="card mb-3 border-warning"><div class="card-body">
        <h5><i class="fa-solid fa-triangle-exclamation me-1 text-warning"></i>Setup required</h5>
        <?php
        // 📋 A summary, not the instructions. The real steps, what each one
        //    needs and what was actually tested are in the setup guide, and
        //    keeping a second copy here is how the two came to disagree: this
        //    card said "openssl rand -base64 64" while the guide said 48, and
        //    it listed two files to copy when there are now three. The paths
        //    are written as THREE dots rather than a made-up home folder,
        //    because the portal's folder on a customer's server is named by
        //    that customer and no default is right (#500).
        ?>
        <ol class="small">
            <li>Copy <strong>three</strong> files from the portal's source,
                <code>tools/offsite-backup/</code>, into the portal's <code>_backups</code>
                folder on the server: <code>sync-offsite.sh.example</code> (as
                <code>sync-offsite.sh</code>, and make it executable),
                <code>log-offsite-result.php</code> and
                <code>read-offsite-settings.php</code>. Take all three from the same
                release: a script and a reader from different ones do not understand
                each other, and the script says so and carries on with its own values.
            </li>
            <li>Make an encryption key in the portal's <code>_auth_keys</code> folder, as
                <code>offsite.key</code>, readable only by your own account — then copy
                that key somewhere that is <em>not</em> this server, today. Without it no
                copy can ever be opened again.
            </li>
            <li>Choose a destination below and save. Set the address of an S3 bucket or
                an SFTP host in the script file itself: there is no box for either here.
            </li>
            <li>Schedule <code>sync-offsite.sh</code> in your hosting panel — weekly is
                the usual choice.
            </li>
        </ol>
        <p class="small text-muted mb-0">
            The steps in full, with the exact commands and a note of what was and was not
            tested, are in <code>docs/offsite-backup-setup.md</code>. Getting a copy back
            is section 8.6 of <code>docs/disaster-recovery-runbook.md</code>.
        </p>
    </div></div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header"><strong>Settings</strong></div>
    <div class="card-body">
        <?php
        // 🔒 Added to every control when the person looking may not save. This
        //    is a COURTESY, not the control: a disabled field is only a hint to
        //    the browser and anybody can send the form anyway. The refusal in
        //    the POST handler at the top of this file is what enforces the rule.
        $readOnlyAttr = $mayChangePortalWideSettings === false ? ' disabled' : '';
        ?>
        <?php if ($mayChangePortalWideSettings === false): ?>
            <!-- 👀 Read-only notice. Shown instead of letting somebody fill the
                 form in and only find out it was refused after they pressed Save. -->
            <div class="alert alert-info">
                <i class="fa-solid fa-circle-info me-2"></i>
                There is one off-site copy for <strong>every organisation</strong> on
                this installation, not only yours, so only a global administrator can
                change these settings. You can see the current values here.
            </div>
        <?php endif; ?>
        <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/maintenance/offsite-backup'), ENT_QUOTES, 'UTF-8'); ?>" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="save">
            <div class="col-md-3">
                <label class="form-label" for="osDestination">Send it with</label>
                <select class="form-select" id="osDestination" name="destination"<?php echo $readOnlyAttr; ?>>
                    <?php foreach (['rclone','s3','sftp'] as $d): ?>
                        <option value="<?php echo $d; ?>" <?php echo $dest === $d ? 'selected' : ''; ?>><?php echo $d; ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">
                    The program on the server that sends the copy. For
                    <code>s3</code> and <code>sftp</code> the address of the bucket or host
                    is set in the script file, not here.
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="osRemote">rclone remote</label>
                <input type="text" class="form-control" id="osRemote" name="rcloneRemote" value="<?php echo htmlspecialchars($remote, ENT_QUOTES, 'UTF-8'); ?>" placeholder="b2:portal-backups"<?php echo $readOnlyAttr; ?>>
                <div class="form-text">
                    A remote you have already made with <code>rclone config</code>, then a
                    folder — letters, digits, dots, dashes and slashes only.
                </div>
                <?php
                // 🚫 Shown whenever what is STORED cannot be used, not only after a
                //    save: a value can also arrive from a restored database or from
                //    SQL by hand, and the page must not badge the settings "in force"
                //    while this one is being ignored.
                $storedRemoteWhyNot = OffsiteSettings::whyRemoteUnusable($remote);
                if ($storedRemoteWhyNot !== ''):
                ?>
                    <div class="form-text text-danger">
                        <i class="fa-solid fa-circle-exclamation me-1"></i>
                        This will <strong>not</strong> be used, because
                        <?php echo htmlspecialchars($storedRemoteWhyNot, ENT_QUOTES, 'UTF-8'); ?>.
                        A run falls back to the remote written inside the script file.
                    </div>
                <?php endif; ?>
            </div>
            <?php
            // 🗂️ These two were labelled "Weekly" and "Monthly", which reads
            //    as a rotation keeping so many weekly copies and so many
            //    monthly ones. The script does nothing of the kind: it ADDS
            //    the two numbers and keeps that many copies, newest first,
            //    judged by name (see step 4 of sync-offsite.sh). The labels
            //    now say what actually happens, and the total is shown so
            //    nobody has to add it up to find out. Renaming the SETTINGS
            //    was considered and rejected: a stored key is read by the
            //    script on customers' servers, and the names are harmless
            //    once the page stops implying something they do not do.
            ?>
            <div class="col-md-2">
                <label class="form-label" for="osKeepWeekly">Copies to keep</label>
                <div class="input-group">
                    <?php
                    // The box's own limits come from the class as well, so there is
                    // ONE place deciding the range: the save handler, this box, the
                    // clamp above and the bridge the script reads. Typed as literals
                    // here at first, which made four copies of one rule.
                    ?>
                    <input type="number" min="<?php echo OffsiteSettings::KEEP_WEEKLY_MIN; ?>" max="<?php echo OffsiteSettings::KEEP_WEEKLY_MAX; ?>" class="form-control" id="osKeepWeekly" name="keepWeekly" value="<?php echo $keepW; ?>" aria-label="First number of copies to keep"<?php echo $readOnlyAttr; ?>>
                    <span class="input-group-text">+</span>
                    <input type="number" min="<?php echo OffsiteSettings::KEEP_MONTHLY_MIN; ?>" max="<?php echo OffsiteSettings::KEEP_MONTHLY_MAX; ?>" class="form-control" name="keepMonthly" value="<?php echo $keepM; ?>" aria-label="Second number of copies to keep"<?php echo $readOnlyAttr; ?>>
                </div>
                <div class="form-text">
                    Added together: <strong><?php echo $keepW + $keepM; ?></strong> copies
                    are kept at the destination and older ones deleted. Only with
                    <code>rclone</code> — for <code>s3</code> and <code>sftp</code>
                    nothing is ever deleted.
                </div>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="osAlert">Tell this address if it fails</label>
                <input type="email" class="form-control" id="osAlert" name="alertEmail" value="<?php echo htmlspecialchars($alertTo, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $readOnlyAttr; ?>>
                <div class="form-text">Needs the server's <code>mail</code> program. Leave empty for no email.</div>
                <?php $storedAlertWhyNot = OffsiteSettings::whyAlertUnusable($alertTo); ?>
                <?php if ($storedAlertWhyNot !== ''): ?>
                    <div class="form-text text-danger">
                        <i class="fa-solid fa-circle-exclamation me-1"></i>
                        This will <strong>not</strong> be used, because
                        <?php echo htmlspecialchars($storedAlertWhyNot, ENT_QUOTES, 'UTF-8'); ?>.
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <div class="form-check me-3">
                    <input type="checkbox" class="form-check-input" id="osEnabled" name="enabled" value="1" <?php echo $enabled === true ? 'checked' : ''; ?><?php echo $readOnlyAttr; ?>>
                    <label class="form-check-label" for="osEnabled">Enabled</label>
                </div>
                <?php if ($mayChangePortalWideSettings === true): ?>
                    <button class="btn btn-primary btn-sm" type="submit">Save</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if ($scriptExists === true && $keyExists === true && $mayRunOffsiteBackup === true): ?>
    <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/maintenance/offsite-backup/run'), ENT_QUOTES, 'UTF-8'); ?>" class="mb-3">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <button class="btn btn-warning" type="submit" data-confirm="Run the off-site sync now? This can take several minutes.">
            <i class="fa-solid fa-play me-1"></i>Run now
        </button>
        <span class="small text-muted ms-2">Runs the same script the cron uses, synchronously.</span>
    </form>
<?php elseif ($scriptExists === true && $keyExists === true): ?>
    <!-- 👀 Read-only equivalent of the button above, same pattern as the
         settings notice further up this page. No button and no text inviting
         its use - the script archives and uploads every organisation's shared
         snapshot, and can prune older remote copies, so running it is a
         global-administrator action, not a site-administrator one. -->
    <p class="small text-muted mb-3">
        <i class="fa-solid fa-lock me-1"></i>
        Running the off-site sync immediately is limited to a global
        administrator, because it acts on the shared snapshot for every
        organisation on this installation. It will still run automatically on
        its schedule.
    </p>
<?php endif; ?>

<div class="card">
    <div class="card-header"><strong>Recent runs</strong></div>
    <div class="card-body">
        <?php if (count($recent) === 0): ?>
            <?php
            // 📝 "Nothing recorded" and "nothing sent" are different things, and
            //    which one this is depends on whether the recorder is even here.
            //    Saying "no runs yet" when the file that records them is missing
            //    would be asserting something this page cannot know — copies may
            //    well be going out (#671).
            ?>
            <?php if ($recorderExists === false): ?>
                <p class="mb-0">
                    <strong>Nothing recorded, and nothing here can record it.</strong>
                    <code>log-offsite-result.php</code> is not in the portal's
                    <code>_backups</code> folder, and it is the only thing that writes to
                    this list. If the script is scheduled, copies may be being sent with
                    no sign of them here. Copy that file across, then check the
                    destination itself to see what has already gone.
                </p>
            <?php else: ?>
                <p class="text-muted mb-0">
                    No runs recorded yet. Once the script is scheduled, or you press
                    <em>Run now</em>, each run appears here with what it warned about.
                    A run that sends a copy but cannot reach this database afterwards
                    leaves nothing here, so check the destination too.
                </p>
            <?php endif; ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm small">
                    <thead><tr><th>Run</th><th>By</th><th>Snapshot</th><th>Size</th><th>Duration</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($recent as $r):
                            $cls = match ((string) $r['status']) { 'success' => 'success', 'skipped' => 'secondary', default => 'danger' };
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string) $r['runAt'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) $r['triggeredBy'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><code><?php echo htmlspecialchars((string) ($r['snapshotName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code></td>
                                <td><?php echo $r['bundleSize'] !== null ? round(((int) $r['bundleSize']) / (1024 * 1024), 1) . ' MB' : '—'; ?></td>
                                <td><?php echo $r['durationSec'] !== null ? ((int) $r['durationSec']) . 's' : '—'; ?></td>
                                <td><span class="badge bg-<?php echo $cls; ?>"><?php echo htmlspecialchars((string) $r['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php if (($r['errorMsg'] ?? '') !== ''): ?>
                                        <div class="small text-muted"><?php echo htmlspecialchars((string) $r['errorMsg'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                    <?php
                                    // ⚠️ A run's own warnings, beside its status. Each one
                                    //    means a setting on this page was held back and the
                                    //    script's own value used instead, so they belong with
                                    //    the run they happened on and not only in the notice
                                    //    at the top, which shows the newest run alone.
                                    ?>
                                    <?php foreach ($warningsFrom($r['output'] ?? null) as $warningLine): ?>
                                        <div class="small text-warning-emphasis"><?php echo htmlspecialchars($warningLine, ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
