<?php
// Path: _apps/admin/maintenance/backup-import.php
/**
 * -----------------------------------------------------------------------------
 * Admin — bring a downloaded backup back in 📤🔐 (#569)
 * -----------------------------------------------------------------------------
 * Takes a file written by the Download button (`Portal\Core\BackupArchive`) and
 * unpacks it into the backups folder, where it appears in the list on
 * /admin/maintenance/backup like any other snapshot.
 *
 * IT DOES NOT RESTORE ANYTHING, AND THAT IS THE MOST IMPORTANT LINE HERE
 * -----------------------------------------------------------------------------
 * Importing and restoring are deliberately two steps. The import produces a
 * snapshot folder; putting it back is the existing Restore button, unchanged,
 * with every guard it already has — the rehearsal, the one-transaction restore,
 * the closing of the portal, and `DbBackup::WHOLE_DATABASE_ONLY` for the three
 * tables a one-table restore refuses.
 *
 * Doing it in one press would mean a second path that writes over a live
 * database, and that path would have to agree with the first about every one of
 * those guards for ever. There is ONE audited restore, and this is not it.
 *
 * It also means an administrator can CHECK an imported backup before trusting
 * it: the Backup page's "Rehearse a restore" reads the whole thing and changes
 * nothing. On a file that came back from somewhere else, that is worth doing.
 *
 * TWO WAYS TO GET THE FILE HERE, AND WHY BOTH ARE NEEDED
 * -----------------------------------------------------------------------------
 *   1. PUT IT IN `_backups/incoming/` with the hosting company's file manager
 *      or over SFTP, then press Import beside it. This always works.
 *   2. UPLOAD IT through this page. Convenient, and limited by the server:
 *      `upload_max_filesize` and `post_max_size` are commonly 2 MB to 64 MB,
 *      while a real database backup is often larger. The page reads those two
 *      settings and SAYS what this server will take, rather than letting
 *      somebody discover it by watching an upload fail.
 *
 * The first route exists precisely because the second cannot be relied on. A
 * customer on shared hosting usually cannot raise those limits, and the whole
 * point of a backup is the day something has gone wrong.
 *
 * WHAT AN IMPORTED SNAPSHOT IS CALLED
 * -----------------------------------------------------------------------------
 * `imported-YYYYMMDD-HHMMSS`, from the moment of import, NOT the name inside
 * the file. Three reasons, all deliberate:
 *
 *   • it cannot collide with a snapshot already there, including the one the
 *     file was originally made from;
 *   • it is obvious in the list which snapshots came from outside;
 *   • `DbBackup::prune()` only removes folders named `upgrade-\d{8}-\d{6}`, so
 *     an imported backup is never tidied away by the scheduled job. Somebody
 *     who went to the trouble of bringing a file back should decide themselves
 *     when it goes.
 *
 * The name the file was made under is shown on this page, so nothing is lost.
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
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// 🚧 A GLOBAL administrator only, for the same reason as the Backup page: a
//    snapshot holds every organisation on the installation. Looking is allowed
//    for any administrator; importing is not, and the refusal is in the handler
//    rather than only in what is drawn.
$mayImport    = App::isRootAdmin();
$backupsRoot  = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_backups';
$incomingRoot = $backupsRoot . DIRECTORY_SEPARATOR . 'incoming';
$userId       = isset($_SESSION['user_id']) === true ? (int) $_SESSION['user_id'] : null;

/**
 * Every file in the incoming folder that could be a backup.
 *
 * A link is never followed and never offered: a link in there could point at
 * anything on the server, and unpacking what it points at is not what anybody
 * asked for.
 *
 * @return list<array{name: string, bytes: int, when: string, envelope: array<string,mixed>|null, why: string}>
 */
function backup_import_incoming(string $incomingRoot): array
{
    if (is_dir($incomingRoot) === false) {
        return [];
    }
    $items = scandir($incomingRoot);
    if ($items === false) {
        return [];
    }

    $found = [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $incomingRoot . DIRECTORY_SEPARATOR . $item;
        if (is_link($path) === true || is_file($path) === false) {
            continue;
        }
        if (backup_import_is_safe_name($item) === false) {
            continue;
        }

        $envelope = null;
        $why      = '';
        try {
            // Reads one short line and nothing else. It authenticates NOTHING,
            // so what comes back is only used to SHOW and to decide what to
            // ask for — never acted on.
            $envelope = BackupArchive::envelopeOf($path);
        } catch (\Throwable $e) {
            $why = $e->getMessage();
        }

        $size    = filesize($path);
        $found[] = [
            'name'     => $item,
            'bytes'    => $size === false ? 0 : $size,
            'when'     => date('j M Y, H:i', (int) filemtime($path)),
            'envelope' => $envelope,
            'why'      => $why,
        ];
    }

    usort($found, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

    return $found;
}

/**
 * A file name this page may act on.
 *
 * The same reasoning as `DbBackup::isSnapshotName()`: ONE test, applied before
 * any path is built, and anything that is not a plain string is refused because
 * a browser can send `name[]=x`.
 */
function backup_import_is_safe_name(mixed $name): bool
{
    return is_string($name) === true
        && $name !== ''
        && strlen($name) <= 200
        && preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_.\-]*\z/', $name) === 1
        && str_contains($name, '..') === false;
}

/** 📏 What this server will actually accept in one upload, in bytes. */
function backup_import_upload_limit(): int
{
    $toBytes = static function (string $value): int {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $unit   = strtolower($value[strlen($value) - 1]);
        $number = (int) $value;

        return match ($unit) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => $number,
        };
    };

    $upload = $toBytes((string) ini_get('upload_max_filesize'));
    $post   = $toBytes((string) ini_get('post_max_size'));

    // The smaller of the two decides, because the whole POST has to fit inside
    // post_max_size and the file inside upload_max_filesize. A zero means
    // "no limit set", which must not win a min().
    $limits = array_values(array_filter([$upload, $post], static fn (int $n): bool => $n > 0));

    return $limits === [] ? 0 : min($limits);
}

/** 📏 Bytes, in words a person reads. */
function backup_import_in_words(int $bytes): string
{
    if ($bytes <= 0) {
        return 'unknown';
    }
    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 1) . ' GB';
    }
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }

    return round($bytes / 1024) . ' KB';
}

// =============================================================================
// 📥 Actions
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirectTo = Site::url('admin/maintenance/backup/import');

    // 🔐 Form token FIRST, before anything with a side effect.
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Nothing was imported. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $redirectTo);
        return;
    }

    if ($mayImport === false) {
        Logger::activity(
            'BackupImportRefused',
            'Refused: a backup holds every organisation on this installation, so only a global '
            . 'administrator may import one',
            $userId
        );
        $_SESSION['flash_msg']  = 'A backup holds the data of every organisation on this installation, '
            . 'not only yours, so only a global administrator can import one. Nothing was imported.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $redirectTo);
        return;
    }

    $action     = (string) ($_POST['action'] ?? '');
    $passphrase = $_POST['passphrase'] ?? '';
    if (is_string($passphrase) === false) {
        $_SESSION['flash_msg']  = 'The key could not be read. Nothing was imported.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $redirectTo);
        return;
    }

    // 📄 Which file. Either one already in the incoming folder, or one being
    //    uploaded in this request.
    $sourceFile  = '';
    $temporary   = '';
    $sourceLabel = '';

    if ($action === 'import_incoming') {
        $name = $_POST['name'] ?? '';
        if (backup_import_is_safe_name($name) === false) {
            $_SESSION['flash_msg']  = 'That is not a file name this page can act on. Nothing was imported.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . $redirectTo);
            return;
        }
        $sourceFile  = $incomingRoot . DIRECTORY_SEPARATOR . (string) $name;
        $sourceLabel = (string) $name;
        if (is_link($sourceFile) === true || is_file($sourceFile) === false) {
            $_SESSION['flash_msg']  = 'There is no such file in the incoming folder. Nothing was imported.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . $redirectTo);
            return;
        }
    } elseif ($action === 'import_upload') {
        $upload = $_FILES['backupFile'] ?? null;
        $error  = is_array($upload) === true ? (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;

        // 📏 The size refusals are named separately, because "the file is too
        //    big for this server" is a different thing from "something went
        //    wrong" and leads somewhere different: to the incoming folder.
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            $limit = backup_import_upload_limit();
            $_SESSION['flash_msg']  = 'That file is larger than this server accepts in an upload ('
                . backup_import_in_words($limit) . '). Put it in the '
                . '_backups/incoming folder with your hosting company\'s file manager instead, and it '
                . 'will appear on this page. Nothing was imported.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . $redirectTo);
            return;
        }
        if ($error !== UPLOAD_ERR_OK || is_uploaded_file((string) ($upload['tmp_name'] ?? '')) === false) {
            $_SESSION['flash_msg']  = 'No file arrived, or the upload did not finish. Nothing was imported.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . $redirectTo);
            return;
        }

        // 🧯 Moved out of PHP's temporary folder into the backups folder before
        //    anything reads it, so the rest of this page works on a file in a
        //    place we control. `is_uploaded_file()` above is what makes the
        //    move safe.
        if (is_dir($incomingRoot) === false && @mkdir($incomingRoot, 0700, true) === false) {
            $_SESSION['flash_msg']  = 'The incoming folder could not be made. Nothing was imported.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . $redirectTo);
            return;
        }
        $temporary = $incomingRoot . DIRECTORY_SEPARATOR . 'upload-' . bin2hex(random_bytes(8))
            . '.' . BackupArchive::EXTENSION;
        if (move_uploaded_file((string) $upload['tmp_name'], $temporary) === false) {
            $_SESSION['flash_msg']  = 'The uploaded file could not be put in place. Nothing was imported.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . $redirectTo);
            return;
        }
        $sourceFile  = $temporary;
        $sourceLabel = (string) ($upload['name'] ?? 'the uploaded file');
    } else {
        header('Location: ' . $redirectTo);
        return;
    }

    // 🔑 What this file needs, read from its own plaintext envelope. Not
    //    trusted for anything but deciding what to ask for.
    $keyFile = null;
    try {
        $envelope = BackupArchive::envelopeOf($sourceFile);
        if ((string) $envelope['keyMode'] === BackupArchive::KEY_BOTH) {
            $keyFile = portal_key_file_contents();
            if ($keyFile === null) {
                throw new \RuntimeException(
                    'That backup also needs this installation\'s key file, and there is none here to use. '
                    . 'It can only be opened on an installation that has the same key file.'
                );
            }
        }
    } catch (\Throwable $e) {
        if ($temporary !== '') {
            @unlink($temporary);
        }
        $_SESSION['flash_msg']  = 'That file could not be read as a portal backup: ' . $e->getMessage();
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $redirectTo);
        return;
    }

    // 📂 Unpack. The name is the moment of import; see the note at the top.
    // `date()`, not `gmdate()`: `DbBackup` names its own snapshots on the
    // server's local clock, and two kinds of name on two different clocks in
    // one list is confusing for no gain.
    $intoName = 'imported-' . date('Ymd-His');
    $intoDir  = $backupsRoot . DIRECTORY_SEPARATOR . $intoName;
    $attempt  = 0;
    while (file_exists($intoDir) === true && $attempt < 5) {
        // Two imports in the same second. Rare, and a loop is cheaper than
        // explaining to somebody why their import was refused.
        $attempt++;
        $intoName = 'imported-' . date('Ymd-His') . '-' . $attempt;
        $intoDir  = $backupsRoot . DIRECTORY_SEPARATOR . $intoName;
    }

    try {
        $unpacked = BackupArchive::unpack($sourceFile, $intoDir, $passphrase, $keyFile);
    } catch (\Throwable $e) {
        if ($temporary !== '') {
            @unlink($temporary);
        }
        // 🤫 BackupArchive's messages never quote the key.
        $_SESSION['flash_msg']  = 'That backup was not imported: ' . $e->getMessage();
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $redirectTo);
        return;
    } finally {
        sodium_memzero($passphrase);
    }

    // 🧹 An uploaded file is removed once it has been unpacked: keeping it would
    //    leave a second copy of everybody's details on the server. A file the
    //    administrator put in the incoming folder themselves is LEFT ALONE —
    //    it is theirs, they may want to import it again, and deleting
    //    somebody's file without being asked is not this page's business.
    if ($temporary !== '') {
        @unlink($temporary);
    }

    Logger::activity(
        'BackupImported',
        'Imported a backup as ' . $intoName . ' (' . $unpacked['entries'] . ' files, originally '
        . $unpacked['snapshot'] . ')',
        $userId
    );

    $_SESSION['flash_msg']  = 'That backup was unpacked as "' . $intoName . '" and is now in the list on '
        . 'the Backup page. NOTHING HAS BEEN RESTORED yet. Rehearse a restore first to check it reads '
        . 'properly, then restore it when you are ready.';
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . Site::url('admin/maintenance/backup'));
    return;
}

// =============================================================================
// 🖼️ The page
// =============================================================================
$incoming    = backup_import_incoming($incomingRoot);
$uploadLimit = backup_import_upload_limit();

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');

$pageTitle       = 'Import a Backup';
$pageDescription = 'Bring a downloaded backup file back into the portal.';
$pageSection     = 'admin';
$breadcrumbs     = [
    'Dashboard' => Site::url(''),
    'Backups'   => Site::url('admin/maintenance/backup'),
    'Import'    => '',
];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-file-import me-2"></i>Import a backup</h1>
    <a href="<?php echo htmlspecialchars(Site::url('admin/maintenance/backup'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fa-solid fa-arrow-left me-1"></i>Back to backups
    </a>
</div>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info me-2"></i>
    Importing <strong>does not restore anything</strong>. The file is unpacked and appears in the list
    on the Backup page, where you can rehearse a restore to check it reads properly before you put it
    back. That is on purpose: there is one tested way of restoring, and this is not a second one.
</div>

<?php if ($mayImport === false): ?>
    <div class="alert alert-secondary">
        <i class="fa-solid fa-lock me-2"></i>
        A backup holds the data of every organisation on this installation, not only yours, so only a
        global administrator can import one. This page is shown to read.
    </div>
<?php endif; ?>

<!-- 📁 Files already on the server. The route that always works. -->
<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5 card-title">Files in the incoming folder</h2>
        <p class="text-secondary small">
            Put a backup file in <code>_backups/incoming</code> using your hosting company&rsquo;s file
            manager or SFTP, and it will appear here. <strong>This route has no size limit</strong>, so
            it is the one to use for a real database backup.
        </p>

        <?php if ($incoming === []): ?>
            <p class="text-secondary mb-0"><em>There is nothing in that folder at the moment.</em></p>
        <?php else: ?>
            <ul class="portal-data-list">
                <?php foreach ($incoming as $file): ?>
                    <li>
                        <strong><?php echo htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        &mdash; <?php echo htmlspecialchars(backup_import_in_words($file['bytes']), ENT_QUOTES, 'UTF-8'); ?>,
                        put there <?php echo htmlspecialchars($file['when'], ENT_QUOTES, 'UTF-8'); ?>.
                        <?php if ($file['envelope'] === null): ?>
                            <span class="text-danger">
                                This cannot be read as a portal backup:
                                <?php echo htmlspecialchars($file['why'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        <?php else: ?>
                            <br>
                            Made from <code><?php echo htmlspecialchars((string) ($file['envelope']['snapshot'] ?? '?'), ENT_QUOTES, 'UTF-8'); ?></code>
                            on <?php echo htmlspecialchars((string) ($file['envelope']['written'] ?? '?'), ENT_QUOTES, 'UTF-8'); ?>,
                            protected by
                            <?php if ((string) ($file['envelope']['keyMode'] ?? '') === BackupArchive::KEY_BOTH): ?>
                                <strong>a key and this installation&rsquo;s key file</strong>
                            <?php else: ?>
                                <strong>a key</strong>
                            <?php endif; ?>.
                            <?php if ($mayImport === true): ?>
                                <form method="post" class="row g-2 align-items-end mt-2">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="action" value="import_incoming">
                                    <input type="hidden" name="name" value="<?php echo htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="col-sm-7">
                                        <label class="form-label" for="key-<?php echo htmlspecialchars(md5($file['name']), ENT_QUOTES, 'UTF-8'); ?>">The key this file was protected with</label>
                                        <input type="password" class="form-control" required
                                               autocomplete="off"
                                               id="key-<?php echo htmlspecialchars(md5($file['name']), ENT_QUOTES, 'UTF-8'); ?>"
                                               name="passphrase">
                                    </div>
                                    <div class="col-sm-5">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fa-solid fa-box-open me-1"></i>Unpack this file
                                        </button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<!-- 📤 Upload. Convenient, and honestly limited. -->
<?php if ($mayImport === true): ?>
<form method="post" enctype="multipart/form-data" class="card">
    <div class="card-body">
        <h2 class="h5 card-title">Or upload a file</h2>
        <p class="text-secondary small">
            <?php if ($uploadLimit > 0): ?>
                This server accepts uploads of up to
                <strong><?php echo htmlspecialchars(backup_import_in_words($uploadLimit), ENT_QUOTES, 'UTF-8'); ?></strong>
                (<code>upload_max_filesize</code> is
                <?php echo htmlspecialchars((string) ini_get('upload_max_filesize'), ENT_QUOTES, 'UTF-8'); ?>,
                <code>post_max_size</code> is
                <?php echo htmlspecialchars((string) ini_get('post_max_size'), ENT_QUOTES, 'UTF-8'); ?>).
                A backup larger than that has to go in the incoming folder above &mdash; your hosting
                company can usually raise these limits, but not always.
            <?php else: ?>
                This server does not say what size of upload it accepts. If an upload fails, use the
                incoming folder above instead.
            <?php endif; ?>
        </p>

        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="action" value="import_upload">

        <div class="row g-3">
            <div class="col-md-6">
                <label for="backupFile" class="form-label">The backup file</label>
                <input type="file" class="form-control" id="backupFile" name="backupFile" required>
            </div>
            <div class="col-md-6">
                <label for="uploadKey" class="form-label">The key it was protected with</label>
                <input type="password" class="form-control" id="uploadKey" name="passphrase"
                       autocomplete="off" required>
            </div>
        </div>

        <button type="submit" class="btn btn-primary mt-3">
            <i class="fa-solid fa-upload me-1"></i>Upload and unpack
        </button>
    </div>
</form>
<?php endif; ?>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
