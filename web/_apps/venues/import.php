<?php
// Path: _apps/venues/import.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings — Import Wizard 📥📊
 * -----------------------------------------------------------------------------
 * The 3-step XLSX/CSV import wizard (02-app-design.md §4.5): upload → vocab
 * map (loops until nothing is left to resolve) → preview → commit/cancel.
 * State lives in `tblVenueImportBatches`/`tblVenueImportRows`
 * (`Venues::createImportBatch/parseWorkbook/stageRows/applyVocabMap/
 * commitBatch/abandonBatch/importBackingDataWindows`) — NOT in `$_SESSION`
 * (unlike `assets/import.php`'s in-memory CSV importer) so the wizard
 * survives across requests/tabs and every step re-derives from the
 * database, never a stale cached preview.
 *
 * Every `?batch=N` access re-fetches the batch with `siteID = Site::id()`
 * in the WHERE clause (404 otherwise, §4.0 hard gate) — `Venues::` has no
 * PUBLIC single-batch getter (`validateImportBatch()` is private), so this
 * is a small site-scoped read here, same spirit as every other agent's
 * "future bookings count" style ad-hoc read.
 *
 * Vocab-mapping resolutions are keyed by the RAW cell string
 * (`Venues::applyVocabMap()`'s contract: `{"hours":{"<raw>":...}}`) — form
 * field names instead carry a base64 handle per row (bracketed array keys
 * are never mangled the way PHP's top-level dot/space translation works,
 * but base64 sidesteps the question entirely) and the raw string travels
 * alongside in a hidden `raw` field, decoded back into the real array key
 * server-side before calling `Venues::applyVocabMap()`.
 *
 * Fuzzy suggestion (02b resolution #8 — STATUS values only): case-fold +
 * strip punctuation on both sides, compare the raw value's leading word
 * against each of the site's status names' leading word; a UNIQUE match
 * pre-selects that status; a tie or no match leaves the radio unselected
 * (never auto-applied).
 *
 * @package   Portal\Venues
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/429
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Settings;
use Portal\Core\Site;
use Portal\Core\Venues;

/** Case-fold + strip everything but letters/digits/spaces, collapse whitespace. */
function venues_import_normalise(string $s): string
{
    $s = strtolower(trim($s));
    $s = (string) preg_replace('/[^a-z0-9\s]/', ' ', $s);
    $s = (string) preg_replace('/\s+/', ' ', trim($s));
    return $s;
}

/** The first whitespace-delimited "token" of a normalised string. */
function venues_import_leading_token(string $s): string
{
    $norm = venues_import_normalise($s);
    if ($norm === '') {
        return '';
    }
    $parts = explode(' ', $norm);
    return $parts[0];
}

/**
 * 02b #8 — suggest the site status whose leading token uniquely matches the
 * raw value's leading token. Ties or no match ⇒ null (never auto-applied).
 *
 * @param array<int, array<string, mixed>> $statuses
 */
function venues_import_suggest_status(string $raw, array $statuses): ?int
{
    $rawToken = venues_import_leading_token($raw);
    if ($rawToken === '') {
        return null;
    }
    $matches = [];
    foreach ($statuses as $status) {
        if (venues_import_leading_token((string) $status['statusName']) === $rawToken) {
            $matches[] = (int) $status['statusID'];
        }
    }
    return count($matches) === 1 ? $matches[0] : null;
}

Auth::ensureSession();
Auth::requireLogin();

// 🛡️ Manager gate — admins or the venue_manager role only.
if (Venues::canManage() !== true) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$db     = App::db();

/** Site-scoped batch fetch — Venues:: has no public single-batch getter. */
$fetchBatch = static function (int $batchId) use ($db, $siteId): ?array {
    if ($batchId <= 0) {
        return null;
    }
    $stmt = $db->prepare('SELECT * FROM tblVenueImportBatches WHERE batchID = ? AND siteID = ? LIMIT 1');
    if ($stmt === false) {
        return null;
    }
    $stmt->bind_param('ii', $batchId, $siteId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row !== null ? $row : null;
};

$zipAvailable = class_exists('ZipArchive');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🔐 CSRF FIRST — before any side-effect.
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /venues/import');
        exit();
    }

    $action = (string) ($_POST['action'] ?? '');

    // -------------------------------------------------------------------
    // 📤 action=upload
    // -------------------------------------------------------------------
    if ($action === 'upload') {
        $venueId = (int) ($_POST['venue'] ?? 0);
        $venue   = $venueId > 0 ? Venues::getVenue($venueId, $siteId) : null;
        if ($venue === null) {
            $_SESSION['flash_msg']  = 'Choose a venue before uploading.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: /venues/import');
            exit();
        }

        $file = $_FILES['import_file'] ?? null;
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK || is_uploaded_file((string) $file['tmp_name']) === false) {
            $_SESSION['flash_msg']  = 'File upload failed — please choose a file and try again.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: /venues/import');
            exit();
        }

        // 🛡️ Hard size cap — default 10 MB when unset/non-positive so a
        // misconfiguration can never disable the cap (resource-save.php convention).
        $maxSize = (int) Settings::get('venues.maxFileSize', '10485760');
        if ($maxSize <= 0) {
            $maxSize = 10485760;
        }
        if ((int) $file['size'] > $maxSize) {
            $_SESSION['flash_msg']  = 'File exceeds the maximum size of ' . round($maxSize / 1048576, 1) . ' MB.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: /venues/import');
            exit();
        }

        $binary = file_get_contents((string) $file['tmp_name']);
        if ($binary === false || strlen($binary) === 0) {
            $_SESSION['flash_msg']  = 'The uploaded file was empty or unreadable.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: /venues/import');
            exit();
        }

        $ext = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));

        // 🔍 finfo-SNIFF the real MIME from the bytes — never trust the
        // client-declared Content-Type or the filename extension alone.
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $sniffedMime = $finfo !== false ? finfo_buffer($finfo, $binary) : false;
        if ($finfo !== false) {
            finfo_close($finfo);
        }

        $sourceKind = null;
        if ($ext === 'csv' && in_array($sniffedMime, ['text/csv', 'text/plain', 'application/csv'], true) === true) {
            $sourceKind = 'csv';
        } elseif (
            $ext === 'xlsx' && $zipAvailable === true
            && in_array($sniffedMime, ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true) === true
        ) {
            $sourceKind = 'xlsx';
        }

        if ($sourceKind === null) {
            $_SESSION['flash_msg']  = $zipAvailable === true
                ? 'Unsupported file — upload a .xlsx workbook or a .csv file.'
                : 'Unsupported file — XLSX import is unavailable on this server; upload a .csv file instead.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: /venues/import');
            exit();
        }

        $fileHash = hash('sha256', $binary);
        $fileName = mb_substr(basename((string) $file['name']), 0, 255);

        // 🔁 Soft duplicate-import warning (non-blocking) — a prior
        // COMMITTED batch on this site/venue with the same file hash.
        $dupWarning = '';
        $dupStmt = $db->prepare(
            "SELECT committedAt FROM tblVenueImportBatches WHERE siteID = ? AND venueID = ? AND fileHash = ? AND status = 'committed' "
            . 'ORDER BY committedAt DESC LIMIT 1'
        );
        if ($dupStmt !== false) {
            $dupStmt->bind_param('iis', $siteId, $venueId, $fileHash);
            $dupStmt->execute();
            $dupRow = $dupStmt->get_result()->fetch_assoc();
            $dupStmt->close();
            if ($dupRow !== null) {
                $dupWarning = 'This exact file was already imported on ' . (string) $dupRow['committedAt'] . '. ';
            }
        }

        try {
            $parsed = Venues::parseWorkbook((string) $file['tmp_name'], $sourceKind);
        } catch (\RuntimeException $e) {
            $_SESSION['flash_msg']  = $e->getMessage() . ' If the problem persists, save each year as a .csv file and upload those instead.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: /venues/import');
            exit();
        }

        $batchId = Venues::createImportBatch($siteId, $venueId, $fileName, $fileHash, $sourceKind, $userId);
        if ($batchId === 0) {
            $_SESSION['flash_msg']  = 'Could not start the import.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: /venues/import');
            exit();
        }

        Venues::stageRows($batchId, $siteId, $venueId, $parsed);
        Logger::activity('VenueImportStarted', 'Started venue import "' . $fileName . '" for venue #' . $venueId, $userId > 0 ? $userId : null);

        $_SESSION['flash_msg']  = $dupWarning . 'File staged — resolve any unrecognised hours/status values below.';
        $_SESSION['flash_type'] = $dupWarning !== '' ? 'warning' : 'info';
        header('Location: /venues/import?batch=' . $batchId);
        exit();
    }

    // -------------------------------------------------------------------
    // 🗺️ action=map — vocab resolutions + optional BackingData windows
    // -------------------------------------------------------------------
    if ($action === 'map') {
        $batchId = (int) ($_POST['batch'] ?? 0);
        $batch   = $fetchBatch($batchId);
        if ($batch === null || (string) $batch['status'] !== 'mapping') {
            Router::renderError(404);
            return;
        }
        $venueId = (int) $batch['venueID'];

        // 🔓 Reconstruct {"hours": {"<raw>": {...}}, "status": {...}} from
        // the base64-handled POST shape (file header explains why).
        $resolutions = ['hours' => [], 'status' => []];
        foreach (['hours', 'status'] as $group) {
            foreach ((array) ($_POST['resolutions'][$group] ?? []) as $handle => $entry) {
                $raw = (string) ($entry['raw'] ?? base64_decode((string) $handle, true) ?: '');
                if ($raw === '') {
                    continue;
                }
                $mode = (string) ($entry['mode'] ?? '');
                if ($mode === 'existing' && $group === 'hours') {
                    $resolutions['hours'][$raw] = ['usageTypeID' => (int) ($entry['usageTypeID'] ?? 0)];
                } elseif ($mode === 'existing' && $group === 'status') {
                    $resolutions['status'][$raw] = ['statusID' => (int) ($entry['statusID'] ?? 0)];
                } elseif ($mode === 'create' && $group === 'hours') {
                    $resolutions['hours'][$raw] = ['create' => [
                        'typeName'  => (string) ($entry['typeName'] ?? $raw),
                        'usageKind' => (string) ($entry['usageKind'] ?? 'hire'),
                        'sortOrder' => '0',
                    ]];
                } elseif ($mode === 'create' && $group === 'status') {
                    $resolutions['status'][$raw] = ['create' => [
                        'statusName'        => (string) ($entry['statusName'] ?? $raw),
                        'statusCategory'    => (string) ($entry['statusCategory'] ?? 'proposed'),
                        'countsAsConfirmed' => isset($entry['countsAsConfirmed']) ? '1' : '0',
                        'isAvailable'       => isset($entry['isAvailable']) ? '1' : '0',
                        'color'             => '',
                        'sortOrder'         => '0',
                    ]];
                }
            }
        }

        $result = Venues::applyVocabMap($batchId, $siteId, $resolutions, $userId);

        // 📅 BackingData year-window acceptance (optional, same POST).
        $acceptedYears = array_map('intval', (array) ($_POST['accepted_years'] ?? []));
        $windowsCreated = 0;
        if (count($acceptedYears) > 0) {
            $windowsCreated = Venues::importBackingDataWindows($batchId, $siteId, $venueId, $acceptedYears, $userId);
        }

        $_SESSION['flash_msg']  = 'Resolved ' . $result['resolved'] . ' value(s) (' . $result['created'] . ' newly created)'
            . ($windowsCreated > 0 ? '; ' . $windowsCreated . ' default window(s) added from the workbook' : '') . '.';
        $_SESSION['flash_type'] = 'success';
        header('Location: /venues/import?batch=' . $batchId);
        exit();
    }

    // -------------------------------------------------------------------
    // ✅ action=commit
    // -------------------------------------------------------------------
    if ($action === 'commit') {
        $batchId = (int) ($_POST['batch'] ?? 0);
        $batch   = $fetchBatch($batchId);
        if ($batch === null) {
            Router::renderError(404);
            return;
        }
        $result = Venues::commitBatch($batchId, $siteId, $userId);
        if ($result['imported'] === 0 && $result['skipped'] === 0 && $result['errors'] === 0) {
            $_SESSION['flash_msg']  = 'Could not commit — the batch still has unresolved rows, or is not ready to commit.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: /venues/import?batch=' . $batchId);
            exit();
        }
        Logger::activity(
            'VenueImportCommitted',
            'Committed venue import batch #' . $batchId . ': ' . $result['imported'] . ' imported, '
                . $result['skipped'] . ' skipped, ' . $result['errors'] . ' errored',
            $userId > 0 ? $userId : null
        );
        $_SESSION['flash_msg']  = 'Import complete — ' . $result['imported'] . ' booking(s) created'
            . ($result['skipped'] > 0 ? ', ' . $result['skipped'] . ' skipped' : '')
            . ($result['errors'] > 0 ? ', ' . $result['errors'] . ' row(s) had errors' : '') . '.';
        $_SESSION['flash_type'] = 'success';
        header('Location: /venues/import?batch=' . $batchId);
        exit();
    }

    // -------------------------------------------------------------------
    // 🗑️ action=cancel
    // -------------------------------------------------------------------
    if ($action === 'cancel') {
        $batchId = (int) ($_POST['batch'] ?? 0);
        $batch   = $fetchBatch($batchId);
        if ($batch !== null) {
            Venues::abandonBatch($batchId, $siteId, $userId);
        }
        $_SESSION['flash_msg']  = 'Import cancelled.';
        $_SESSION['flash_type'] = 'info';
        header('Location: /venues/import');
        exit();
    }

    header('Location: /venues/import');
    exit();
}

// -----------------------------------------------------------------------------
// 🖼️ GET — render the current step.
// -----------------------------------------------------------------------------
$batchId = (int) ($_GET['batch'] ?? 0);
$batch   = null;
if ($batchId > 0) {
    $batch = $fetchBatch($batchId);
    if ($batch === null) {
        Router::renderError(404);
        return;
    }
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$state = 'upload';
$stepData = [];

if ($batch !== null && (string) $batch['status'] === 'mapping') {
    $venueId = (int) $batch['venueID'];

    $unknownHours = [];
    $stmt = $db->prepare("SELECT DISTINCT rawHours FROM tblVenueImportRows WHERE batchID = ? AND rawHours <> '' AND mappedUsageTypeID IS NULL ORDER BY rawHours");
    if ($stmt !== false) {
        $stmt->bind_param('i', $batchId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $unknownHours[] = (string) $row['rawHours'];
        }
        $stmt->close();
    }

    $unknownStatuses = [];
    $stmt = $db->prepare("SELECT DISTINCT rawStatus FROM tblVenueImportRows WHERE batchID = ? AND rawStatus <> '' AND mappedStatusID IS NULL ORDER BY rawStatus");
    if ($stmt !== false) {
        $stmt->bind_param('i', $batchId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $unknownStatuses[] = (string) $row['rawStatus'];
        }
        $stmt->close();
    }

    $pendingCount = 0;
    $pendingRowNums = [];
    $stmt = $db->prepare("SELECT rowNum FROM tblVenueImportRows WHERE batchID = ? AND rowState = 'pending' ORDER BY rowNum");
    if ($stmt !== false) {
        $stmt->bind_param('i', $batchId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $pendingRowNums[] = (int) $row['rowNum'];
        }
        $stmt->close();
        $pendingCount = count($pendingRowNums);
    }

    if (count($unknownHours) > 0 || count($unknownStatuses) > 0) {
        $state = 'mapping';
        $venueTypes = Venues::listUsageTypes($venueId, $siteId, true);
        $statuses   = Venues::listStatuses($siteId, true);
        $vocabMap   = json_decode((string) ($batch['vocabMap'] ?? '{}'), true) ?: [];
        $usageWindowsByYear = $vocabMap['backingData']['usageWindowsByYear'] ?? [];

        $stepData = [
            'unknownHours'       => $unknownHours,
            'unknownStatuses'    => $unknownStatuses,
            'venueTypes'         => $venueTypes,
            'statuses'           => $statuses,
            'usageWindowsByYear' => $usageWindowsByYear,
            'pendingRowNums'     => $pendingRowNums,
        ];
    } elseif ($pendingCount > 0) {
        // 🚧 Rows with a blank HOURS or STATUS cell can never resolve via
        // vocab mapping (there is no raw value to map) — a dead end that
        // needs the source file corrected and re-uploaded.
        $state = 'stuck';
        $stepData = ['pendingRowNums' => $pendingRowNums];
    } else {
        $state = 'preview';
        $counts = ['ready' => 0, 'skipped' => 0, 'error' => 0, 'imported' => 0];
        $stmt = $db->prepare('SELECT rowState, COUNT(*) AS c FROM tblVenueImportRows WHERE batchID = ? GROUP BY rowState');
        if ($stmt !== false) {
            $stmt->bind_param('i', $batchId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $counts[(string) $row['rowState']] = (int) $row['c'];
            }
            $stmt->close();
        }
        $preview = [];
        $stmt = $db->prepare('SELECT * FROM tblVenueImportRows WHERE batchID = ? ORDER BY sheetYear, rowNum LIMIT 20');
        if ($stmt !== false) {
            $stmt->bind_param('i', $batchId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $preview[] = $row;
            }
            $stmt->close();
        }
        $stepData = ['counts' => $counts, 'preview' => $preview];
    }
} elseif ($batch !== null && (string) $batch['status'] === 'committed') {
    $state = 'done';
} elseif ($batch !== null) {
    // uploaded / abandoned — nothing more to show, back to step 1.
    $batch = null;
    $state = 'upload';
}

$venues = Venues::listVenues($siteId, true);

$pageTitle   = 'Import Schedule';
$pageSection = 'venues';
$breadcrumbs = ['Dashboard' => '/', 'Venues' => '/venues', 'Import' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-file-import me-2"></i>Import Schedule</h1>
    <a href="/venues" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to schedule</a>
</div>

<?php if ($state === 'upload'): ?>
    <?php if ($zipAvailable === false): ?>
        <div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation me-1"></i>XLSX import is unavailable on this server — only .csv uploads are accepted.</div>
    <?php endif; ?>
    <div class="card mb-3">
        <div class="card-header"><strong>Format</strong></div>
        <div class="card-body small">
            <p class="mb-0">
                Expected columns: <strong>DATE</strong>, <strong>HOURS</strong>, <strong>TIMES</strong>, <strong>NOTES</strong>, <strong>STATUS</strong>
                (order-free, matched by header name). One sheet per calendar year, plus an optional <code>BackingData</code> sheet with
                per-year default windows. If Excel upload ever fails on this server, save each year's sheet as CSV and upload those instead — CSV always works.
            </p>
        </div>
    </div>
    <?php if (count($venues) === 0): ?>
        <div class="alert alert-info">Add a venue first (<a href="/venues/manage">Manage Venues</a>) before importing a schedule.</div>
    <?php else: ?>
        <div class="card">
            <div class="card-body">
                <form method="post" action="/venues/import" enctype="multipart/form-data" class="row g-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="upload">
                    <div class="col-md-4">
                        <label class="form-label small" for="venue">Venue *</label>
                        <select class="form-select form-select-sm" id="venue" name="venue" required>
                            <option value="">— choose —</option>
                            <?php foreach ($venues as $v): ?>
                                <option value="<?php echo (int) $v['venueID']; ?>"><?php echo htmlspecialchars((string) $v['venueName'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small" for="import_file">File <?php echo $zipAvailable === true ? '(.xlsx or .csv)' : '(.csv)'; ?></label>
                        <input type="file" class="form-control form-control-sm" id="import_file" name="import_file" required
                               accept="<?php echo $zipAvailable === true ? '.xlsx,.csv' : '.csv'; ?>">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-upload me-1"></i>Upload &amp; stage</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

<?php elseif ($state === 'mapping'): ?>
    <div class="alert alert-warning">Resolve every unrecognised HOURS/STATUS value below before this batch can be previewed.</div>
    <form method="post" action="/venues/import">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="map">
        <input type="hidden" name="batch" value="<?php echo $batchId; ?>">

        <?php if (count($stepData['unknownHours']) > 0): ?>
            <div class="card mb-3">
                <div class="card-header"><strong>Unrecognised HOURS values</strong></div>
                <div class="card-body">
                    <?php foreach ($stepData['unknownHours'] as $raw): ?>
                        <?php $handle = base64_encode($raw); ?>
                        <div class="border rounded p-2 mb-2">
                            <div class="mb-1"><code><?php echo htmlspecialchars($raw, ENT_QUOTES, 'UTF-8'); ?></code></div>
                            <input type="hidden" name="resolutions[hours][<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?>][raw]" value="<?php echo htmlspecialchars($raw, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="resolutions[hours][<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?>][mode]" value="existing" id="h_existing_<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?>" checked>
                                <label class="form-check-label small" for="h_existing_<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?>">Map to an existing usage type</label>
                            </div>
                            <select class="form-select form-select-sm mb-2" name="resolutions[hours][<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?>][usageTypeID]">
                                <option value="">— choose —</option>
                                <?php foreach ($stepData['venueTypes'] as $t): ?>
                                    <option value="<?php echo (int) $t['usageTypeID']; ?>"><?php echo htmlspecialchars((string) $t['typeName'], ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="resolutions[hours][<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?>][mode]" value="create" id="h_create_<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?>">
                                <label class="form-check-label small" for="h_create_<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?>">Create as a new usage type</label>
                            </div>
                            <div class="row g-1">
                                <div class="col-md-6">
                                    <input type="text" class="form-control form-control-sm" name="resolutions[hours][<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?>][typeName]"
                                           placeholder="Name" value="<?php echo htmlspecialchars($raw, ENT_QUOTES, 'UTF-8'); ?>" maxlength="100">
                                </div>
                                <div class="col-md-6">
                                    <select class="form-select form-select-sm" name="resolutions[hours][<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?>][usageKind]">
                                        <?php foreach (Venues::USAGE_KINDS as $kind): ?>
                                            <option value="<?php echo htmlspecialchars($kind, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucfirst($kind), ENT_QUOTES, 'UTF-8'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-text">New types get a default time window afterwards from <a href="/venues/usage-types?venue=<?php echo (int) $batch['venueID']; ?>" target="_blank">Usage Types</a>.</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (count($stepData['unknownStatuses']) > 0): ?>
            <div class="card mb-3">
                <div class="card-header"><strong>Unrecognised STATUS values</strong></div>
                <div class="card-body">
                    <?php foreach ($stepData['unknownStatuses'] as $raw): ?>
                        <?php
                        $handle = base64_encode($raw);
                        $suggested = venues_import_suggest_status($raw, $stepData['statuses']);
                        ?>
                        <div class="border rounded p-2 mb-2">
                            <div class="mb-1"><code><?php echo htmlspecialchars($raw, ENT_QUOTES, 'UTF-8'); ?></code></div>
                            <input type="hidden" name="resolutions[status][<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?>][raw]" value="<?php echo htmlspecialchars($raw, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="resolutions[status][<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?>][mode]" value="existing" id="s_existing_<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?>" checked>
                                <label class="form-check-label small" for="s_existing_<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?>">Map to an existing status<?php echo $suggested !== null ? ' <span class="badge bg-info text-dark">suggested</span>' : ''; ?></label>
                            </div>
                            <select class="form-select form-select-sm mb-2" name="resolutions[status][<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?>][statusID]">
                                <option value="" <?php echo $suggested === null ? 'selected' : ''; ?>>— choose —</option>
                                <?php foreach ($stepData['statuses'] as $st): ?>
                                    <option value="<?php echo (int) $st['statusID']; ?>" <?php echo $suggested === (int) $st['statusID'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars((string) $st['statusName'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="resolutions[status][<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?>][mode]" value="create" id="s_create_<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?>">
                                <label class="form-check-label small" for="s_create_<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?>">Create as a new status</label>
                            </div>
                            <div class="row g-1">
                                <div class="col-md-4">
                                    <input type="text" class="form-control form-control-sm" name="resolutions[status][<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?>][statusName]"
                                           placeholder="Name" value="<?php echo htmlspecialchars($raw, ENT_QUOTES, 'UTF-8'); ?>" maxlength="150">
                                </div>
                                <div class="col-md-4">
                                    <select class="form-select form-select-sm" name="resolutions[status][<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?>][statusCategory]">
                                        <?php foreach (Venues::STATUS_CATEGORIES as $cat): ?>
                                            <option value="<?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucfirst($cat), ENT_QUOTES, 'UTF-8'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2 form-check mt-1">
                                    <input class="form-check-input" type="checkbox" name="resolutions[status][<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?>][countsAsConfirmed]" value="1" id="cac_<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?>">
                                    <label class="form-check-label small" for="cac_<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?>">Confirmed</label>
                                </div>
                                <div class="col-md-2 form-check mt-1">
                                    <input class="form-check-input" type="checkbox" name="resolutions[status][<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?>][isAvailable]" value="1" id="av_<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?>" checked>
                                    <label class="form-check-label small" for="av_<?php echo htmlspecialchars($handle, ENT_QUOTES, 'UTF-8'); ?>">Available</label>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (count($stepData['usageWindowsByYear']) > 0): ?>
            <div class="card mb-3">
                <div class="card-header"><strong>BackingData default windows found in the workbook</strong></div>
                <div class="card-body small">
                    <p>Tick a year to create its default time windows now (<code>effectiveFrom</code> = 1 Jan that year).</p>
                    <?php foreach ($stepData['usageWindowsByYear'] as $year => $typeWindows): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="accepted_years[]" value="<?php echo (int) $year; ?>" id="year_<?php echo (int) $year; ?>">
                            <label class="form-check-label" for="year_<?php echo (int) $year; ?>">
                                <strong><?php echo (int) $year; ?></strong> —
                                <?php
                                $bits = [];
                                foreach ((array) $typeWindows as $typeName => $window) {
                                    $bits[] = htmlspecialchars((string) $typeName, ENT_QUOTES, 'UTF-8') . ' '
                                        . htmlspecialchars(substr((string) ($window['start'] ?? ''), 0, 5) . '–' . substr((string) ($window['end'] ?? ''), 0, 5), ENT_QUOTES, 'UTF-8');
                                }
                                echo implode(', ', $bits);
                                ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check me-1"></i>Apply &amp; continue</button>
        <button type="submit" class="btn btn-outline-secondary" name="action" value="cancel" formnovalidate>Cancel import</button>
    </form>

<?php elseif ($state === 'stuck'): ?>
    <div class="alert alert-danger">
        <?php echo count($stepData['pendingRowNums']); ?> row(s) are missing an HOURS or STATUS value entirely, so there is nothing to map them to
        (row numbers: <?php echo htmlspecialchars(implode(', ', $stepData['pendingRowNums']), ENT_QUOTES, 'UTF-8'); ?>).
        Fix the source file and re-upload, or cancel this import.
    </div>
    <form method="post" action="/venues/import" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="batch" value="<?php echo $batchId; ?>">
        <button type="submit" class="btn btn-outline-secondary">Cancel import</button>
    </form>

<?php elseif ($state === 'preview'): ?>
    <?php $counts = $stepData['counts']; ?>
    <div class="card mb-3">
        <div class="card-body">
            <span class="badge bg-success"><?php echo $counts['ready']; ?> ready</span>
            <span class="badge bg-secondary"><?php echo $counts['skipped']; ?> skipped</span>
            <span class="badge <?php echo $counts['error'] > 0 ? 'bg-danger' : 'bg-secondary'; ?>"><?php echo $counts['error']; ?> error</span>
        </div>
    </div>

    <?php if (count($stepData['preview']) > 0): ?>
        <div class="portal-data-list mb-3">
            <div class="portal-data-header">
                <div class="col-2">Date</div>
                <div class="col-2">Hours</div>
                <div class="col-2">Times</div>
                <div class="col-2">Status</div>
                <div class="col-2">State</div>
                <div class="col-2">Note</div>
            </div>
            <?php foreach ($stepData['preview'] as $row): ?>
                <div class="portal-data-row">
                    <div class="col-2 small"><?php echo htmlspecialchars((string) ($row['parsedDate'] ?? $row['rawDate']), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-2 small"><?php echo htmlspecialchars((string) $row['rawHours'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-2 small">
                        <?php echo $row['parsedStartTime'] !== null
                            ? htmlspecialchars(substr((string) $row['parsedStartTime'], 0, 5) . '–' . substr((string) $row['parsedEndTime'], 0, 5), ENT_QUOTES, 'UTF-8')
                            : '<span class="text-muted">—</span>'; ?>
                    </div>
                    <div class="col-2 small"><?php echo htmlspecialchars((string) $row['rawStatus'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-2 small"><span class="badge bg-<?php echo ((string) $row['rowState']) === 'ready' ? 'success' : (((string) $row['rowState']) === 'error' ? 'danger' : 'secondary'); ?>"><?php echo htmlspecialchars((string) $row['rowState'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                    <div class="col-2 small text-muted"><?php echo htmlspecialchars((string) ($row['stateNote'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="small text-muted">Showing the first 20 rows of <?php echo array_sum($counts); ?>.</p>
    <?php endif; ?>

    <form method="post" action="/venues/import" class="d-inline" data-confirm="Commit this import? Ready rows will become real bookings — this cannot be undone from here.">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="commit">
        <input type="hidden" name="batch" value="<?php echo $batchId; ?>">
        <button type="submit" class="btn btn-success" <?php echo $counts['ready'] === 0 ? 'disabled' : ''; ?>><i class="fa-solid fa-check me-1"></i>Commit &amp; import <?php echo $counts['ready']; ?> booking(s)</button>
    </form>
    <form method="post" action="/venues/import" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="batch" value="<?php echo $batchId; ?>">
        <button type="submit" class="btn btn-outline-secondary">Cancel</button>
    </form>

<?php elseif ($state === 'done'): ?>
    <div class="alert alert-success">
        Imported <?php echo (int) $batch['importedCount']; ?> booking(s); <?php echo (int) $batch['skippedCount']; ?> skipped.
    </div>
    <a href="/venues?venue=<?php echo (int) $batch['venueID']; ?>" class="btn btn-primary"><i class="fa-solid fa-calendar me-1"></i>View schedule</a>
    <a href="/venues/import" class="btn btn-outline-secondary">Import another file</a>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
