<?php
// Path: public_html/calendar/manage/import.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Bulk Event Import via CSV 📥
 * -----------------------------------------------------------------------------
 * Admin-only. Upload a CSV with one event per row, preview parsed rows
 * + validation errors, then run the import. Excel users should
 * "Save As → CSV (Comma delimited)" first.
 *
 * Required columns: eventName, startDateTime
 * Optional columns: endDateTime, isAllDay, locationName, description,
 *                   status (draft|published|cancelled|archived), category,
 *                   type, isPublic, isFeatured
 *
 * @package   Portal\Calendar
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

$pageTitle   = 'Bulk Import Events';
$pageSection = 'calendar';
$breadcrumbs = [
    'Dashboard'       => '/',
    'Calendar'        => '/calendar',
    'Manage Events'   => '/calendar/manage',
    'Bulk Import'     => '',
];

Auth::ensureSession();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$siteId   = Site::id();
$userId   = (int) ($_SESSION['user_id'] ?? 0);
$preview  = [];
$results  = [];
$error    = '';
$imported = false;

/** Parse + validate CSV. Returns array{rows:list<array>, errors:list<string>} */
$parseCsv = static function (string $tmpFile) use ($siteId, $mysqli): array {
    $handle = fopen($tmpFile, 'r');
    if ($handle === false) {
        return ['rows' => [], 'errors' => ['Could not open uploaded file.']];
    }
    $header = fgetcsv($handle);
    if ($header === false || count($header) < 2) {
        fclose($handle);
        return ['rows' => [], 'errors' => ['CSV must have at least 2 columns including eventName + startDateTime.']];
    }
    // 🧹 Normalise headers — strip BOM, lowercase, trim
    $header = array_map(static function ($h): string {
        return strtolower(trim(str_replace(["\xEF\xBB\xBF", '"'], '', (string) $h)));
    }, $header);

    $colMap = array_flip($header);
    if (isset($colMap['eventname']) === false || isset($colMap['startdatetime']) === false) {
        fclose($handle);
        return ['rows' => [], 'errors' => ['CSV must contain eventName and startDateTime columns. Found: ' . implode(', ', $header)]];
    }

    // 🗂️ Existing category + type lookup (case-insensitive on name)
    $catMap = [];
    $stmt = $mysqli->prepare('SELECT categoryID, LOWER(categoryName) AS n FROM tblEventCategories WHERE siteID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $catMap[$r['n']] = (int) $r['categoryID'];
        }
        $stmt->close();
    }
    $typeMap = [];
    $stmt = $mysqli->prepare('SELECT typeID, LOWER(typeName) AS n FROM tblEventTypes WHERE siteID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $typeMap[$r['n']] = (int) $r['typeID'];
        }
        $stmt->close();
    }

    $rows   = [];
    $errors = [];
    $rowNum = 1;  // header is row 1
    while (($data = fgetcsv($handle)) !== false) {
        $rowNum++;
        if (count(array_filter($data, fn ($v) => trim((string) $v) !== '')) === 0) {
            continue;  // empty row
        }

        $get = static function (string $key) use ($data, $colMap): string {
            $idx = $colMap[$key] ?? null;
            if ($idx === null || array_key_exists($idx, $data) === false) {
                return '';
            }
            return trim((string) $data[$idx]);
        };

        $name      = $get('eventname');
        $start     = $get('startdatetime');
        $end       = $get('enddatetime');
        $allDay    = strtolower($get('isallday'));
        $location  = $get('locationname');
        $desc      = $get('description');
        $status    = strtolower($get('status'));
        $catName   = strtolower($get('category'));
        $typeName  = strtolower($get('type'));
        $isPubStr  = strtolower($get('ispublic'));
        $isFeatStr = strtolower($get('isfeatured'));

        $rowErr = [];
        if ($name === '')  { $rowErr[] = 'eventName required'; }
        if ($start === '') { $rowErr[] = 'startDateTime required'; }
        $startTs = $start !== '' ? strtotime($start) : false;
        if ($start !== '' && $startTs === false) { $rowErr[] = 'startDateTime not parseable'; }
        $endTs = null;
        if ($end !== '') {
            $endTs = strtotime($end);
            if ($endTs === false || ($startTs !== false && $endTs < $startTs)) {
                $rowErr[] = 'endDateTime not parseable / before start';
            }
        }
        if ($status !== '' && in_array($status, ['draft', 'published', 'cancelled', 'archived'], true) === false) {
            $rowErr[] = 'status must be draft|published|cancelled|archived';
        }
        $catId  = $catName !== ''  ? ($catMap[$catName]  ?? null) : null;
        $typeId = $typeName !== '' ? ($typeMap[$typeName] ?? null) : null;
        if ($catName !== ''  && $catId  === null) { $rowErr[] = 'category not found: ' . $catName; }
        if ($typeName !== '' && $typeId === null) { $rowErr[] = 'type not found: ' . $typeName; }

        $rows[] = [
            'rowNum'        => $rowNum,
            'eventName'     => $name,
            'startDateTime' => $start,
            'endDateTime'   => $end !== '' ? $end : null,
            'isAllDay'      => in_array($allDay,    ['1', 'true', 'yes', 'y'], true) ? 1 : 0,
            'locationName'  => $location,
            'description'   => $desc,
            'status'        => $status !== '' ? $status : 'draft',
            'categoryID'    => $catId,
            'typeID'        => $typeId,
            'isPublic'      => in_array($isPubStr,  ['', '1', 'true', 'yes', 'y'], true) ? 1 : 0,
            'isFeatured'    => in_array($isFeatStr, ['1', 'true', 'yes', 'y'], true) ? 1 : 0,
            'errors'        => $rowErr,
            '_startTs'      => $startTs,
            '_endTs'        => $endTs,
        ];
    }
    fclose($handle);
    return ['rows' => $rows, 'errors' => $errors];
};

// -----------------------------------------------------------------------------
// 🚦 Handle POST
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $error = 'Invalid or expired form token.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'preview' && isset($_FILES['csv_file']) === true) {
            $file = $_FILES['csv_file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $error = 'File upload failed.';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $error = 'File too large (2 MB max).';
            } else {
                $parsed = $parseCsv($file['tmp_name']);
                $preview = $parsed['rows'];
                if (count($parsed['errors']) > 0) {
                    $error = implode('; ', $parsed['errors']);
                }

                // 🧊 Stash parsed rows in the session so the confirm step
                //    doesn't need a second upload. Trim to JSON-safe shape.
                $_SESSION['eventsImportPreview'] = array_map(static function ($r) {
                    unset($r['_startTs'], $r['_endTs']);
                    return $r;
                }, $preview);
            }
        }

        if ($action === 'confirm') {
            $rows = $_SESSION['eventsImportPreview'] ?? [];
            if (count($rows) === 0) {
                $error = 'No preview data — re-upload the file.';
            } else {
                $stmt = $mysqli->prepare(
                    'INSERT INTO tblEvents '
                    . '(siteID, eventName, eventSlug, description, startDateTime, endDateTime, '
                    . 'isAllDay, locationName, status, isPublic, isFeatured, categoryID, typeID, createdByID) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if ($stmt === false) {
                    $error = t('error.db_import_prepare');
                } else {
                    $okCount = 0;
                    foreach ($rows as $r) {
                        if (count($r['errors'] ?? []) > 0) {
                            $results[] = ['ok' => false, 'row' => $r['rowNum'], 'msg' => implode('; ', $r['errors'])];
                            continue;
                        }
                        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', (string) $r['eventName']), '-'));
                        $slug = substr($slug !== '' ? $slug : 'event-' . bin2hex(random_bytes(4)), 0, 100);

                        $startSql = date('Y-m-d H:i:s', strtotime((string) $r['startDateTime']));
                        $endSql   = $r['endDateTime'] !== null && $r['endDateTime'] !== ''
                                  ? date('Y-m-d H:i:s', strtotime((string) $r['endDateTime']))
                                  : null;

                        $name    = (string) $r['eventName'];
                        $desc    = (string) $r['description'];
                        $allDay  = (int) $r['isAllDay'];
                        $loc     = (string) $r['locationName'];
                        $status  = (string) $r['status'];
                        $isPub   = (int) $r['isPublic'];
                        $isFeat  = (int) $r['isFeatured'];
                        $catId   = $r['categoryID'] !== null ? (int) $r['categoryID'] : null;
                        $typeId  = $r['typeID']     !== null ? (int) $r['typeID']     : null;

                        $stmt->bind_param(
                            'isssssisssiiii',
                            $siteId, $name, $slug, $desc, $startSql, $endSql,
                            $allDay, $loc, $status, $isPub, $isFeat,
                            $catId, $typeId, $userId
                        );
                        $okThis = $stmt->execute();
                        $results[] = [
                            'ok'  => $okThis,
                            'row' => $r['rowNum'],
                            'msg' => $okThis ? 'Imported as event #' . $stmt->insert_id : 'DB error: ' . $stmt->error,
                        ];
                        if ($okThis === true) {
                            $okCount++;
                        }
                    }
                    $stmt->close();
                    $imported = true;
                    Logger::activity('EventsImported', 'Bulk import: ' . $okCount . ' events created');
                    unset($_SESSION['eventsImportPreview']);
                }
            }
        }
    }
}

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-file-csv me-2"></i>Bulk Import Events</h1>
    <a href="/calendar/manage" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back to Manage
    </a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation me-1"></i><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<?php if ($imported === true): ?>
    <div class="card shadow-sm mb-3">
        <div class="card-header"><h2 class="h6 mb-0">Import results</h2></div>
        <div class="card-body p-0">
            <div class="portal-data-list">
                <div class="portal-data-row portal-data-header d-none d-md-flex">
                    <div class="col-md-2">Row</div>
                    <div class="col-md-2">Status</div>
                    <div class="col-md-8">Detail</div>
                </div>
                <?php foreach ($results as $r): ?>
                    <div class="portal-data-row">
                        <div class="col-12 col-md-2"><?php echo (int) $r['row']; ?></div>
                        <div class="col-12 col-md-2">
                            <?php if ($r['ok'] === true): ?>
                                <span class="badge bg-success">OK</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Skipped</span>
                            <?php endif; ?>
                        </div>
                        <div class="col-12 col-md-8 small">
                            <?php echo htmlspecialchars((string) $r['msg'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <a href="/calendar/manage/import" class="btn btn-outline-primary">Import another file</a>
    <a href="/calendar/manage" class="btn btn-outline-secondary">Back to Manage Events</a>

<?php elseif (count($preview) > 0): ?>
    <div class="alert alert-info">
        <strong><?php echo count($preview); ?></strong> row(s) parsed. Review and confirm to import.
    </div>
    <div class="card shadow-sm mb-3">
        <div class="card-body p-0">
            <div class="portal-data-list">
                <div class="portal-data-row portal-data-header d-none d-md-flex">
                    <div class="col-md-1">Row</div>
                    <div class="col-md-3">Name</div>
                    <div class="col-md-2">Start</div>
                    <div class="col-md-2">End</div>
                    <div class="col-md-2">Status</div>
                    <div class="col-md-2">Validation</div>
                </div>
                <?php foreach ($preview as $r): ?>
                    <div class="portal-data-row">
                        <div class="col-6 col-md-1"><?php echo (int) $r['rowNum']; ?></div>
                        <div class="col-6 col-md-3"><?php echo htmlspecialchars((string) $r['eventName'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-6 col-md-2 small"><?php echo htmlspecialchars((string) $r['startDateTime'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-6 col-md-2 small"><?php echo htmlspecialchars((string) ($r['endDateTime'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-6 col-md-2 small"><?php echo htmlspecialchars((string) $r['status'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-12 col-md-2 small">
                            <?php if (count($r['errors']) === 0): ?>
                                <span class="badge bg-success">Ready</span>
                            <?php else: ?>
                                <span class="badge bg-danger" title="<?php echo htmlspecialchars(implode('; ', $r['errors']), ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo count($r['errors']); ?> error(s)
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <form method="post" action="/calendar/manage/import">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="confirm">
        <button type="submit" class="btn btn-success">
            <i class="fa-solid fa-upload me-1"></i> Confirm &amp; import valid rows
        </button>
        <a href="/calendar/manage/import" class="btn btn-outline-secondary">Cancel</a>
    </form>

<?php else: ?>
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h5">CSV format</h2>
            <p class="small">Required columns: <code>eventName</code>, <code>startDateTime</code>. Optional: <code>endDateTime</code>, <code>isAllDay</code>, <code>locationName</code>, <code>description</code>, <code>status</code> (draft|published|cancelled|archived), <code>category</code>, <code>type</code>, <code>isPublic</code>, <code>isFeatured</code>. First row must be the header.</p>
            <p class="small text-muted mb-0">
                Excel users: open your file in Excel, choose <em>File → Save As → CSV (Comma delimited) (.csv)</em>.
            </p>
        </div>
    </div>

    <form method="post" action="/calendar/manage/import" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="preview">
        <div class="mb-3">
            <label for="csv_file" class="form-label">CSV file (max 2 MB)</label>
            <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required class="form-control">
        </div>
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-upload me-1"></i> Upload &amp; preview
        </button>
    </form>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
