<?php
// Path: public_html/leadership/manage/import.php
/**
 * -----------------------------------------------------------------------------
 * Leadership — Bulk Assignment Import via CSV 📥
 * -----------------------------------------------------------------------------
 * Admin-only. Upload a CSV of leadership assignments (one role+user per row),
 * preview rows + validation, then run the import.
 *
 * Required columns: roleName, userEmail
 * Optional:         assignedAt (Y-m-d), endsAt (Y-m-d)
 *
 * Both roleName and userEmail must already exist for the row to import —
 * roles and users are NOT auto-created. Look up users by email
 * (case-insensitive); roles by name (case-insensitive within site scope).
 *
 * @package   Portal\Leadership
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

$pageTitle   = 'Bulk Import Leadership Assignments';
$pageSection = 'leadership';
$breadcrumbs = [
    'Dashboard'  => '/',
    'Leadership' => '/leadership',
    'Manage'     => '/leadership/manage',
    'Bulk Import' => '',
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

$parseCsv = static function (string $tmpFile) use ($siteId, $mysqli): array {
    $handle = fopen($tmpFile, 'r');
    if ($handle === false) {
        return ['rows' => [], 'errors' => ['Could not open uploaded file.']];
    }
    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        return ['rows' => [], 'errors' => ['Empty file.']];
    }
    $header = array_map(static fn ($h): string =>
        strtolower(trim(str_replace(["\xEF\xBB\xBF", '"'], '', (string) $h))),
        $header
    );
    $colMap = array_flip($header);
    if (isset($colMap['rolename']) === false || isset($colMap['useremail']) === false) {
        fclose($handle);
        return ['rows' => [], 'errors' => ['CSV must contain roleName and userEmail columns.']];
    }

    // 🗂️ Site-scoped role lookup + global user lookup
    $roleMap = [];
    $stmt = $mysqli->prepare(
        'SELECT roleID, LOWER(roleName) AS n FROM tblLeadershipRoles WHERE siteID = ? AND isActive = 1'
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $roleMap[$r['n']] = (int) $r['roleID'];
        }
        $stmt->close();
    }

    $rows = [];
    $rowNum = 1;
    while (($data = fgetcsv($handle)) !== false) {
        $rowNum++;
        if (count(array_filter($data, fn ($v) => trim((string) $v) !== '')) === 0) {
            continue;
        }
        $get = static function (string $key) use ($data, $colMap): string {
            $idx = $colMap[$key] ?? null;
            if ($idx === null || array_key_exists($idx, $data) === false) {
                return '';
            }
            return trim((string) $data[$idx]);
        };

        $roleName  = strtolower($get('rolename'));
        $userEmail = strtolower($get('useremail'));
        $assigned  = $get('assignedat');
        $ends      = $get('endsat');

        $rowErr = [];
        $roleId = $roleName  !== '' ? ($roleMap[$roleName] ?? null) : null;
        if ($roleId === null) { $rowErr[] = 'role not found: ' . $roleName; }

        $userIdRow = null;
        if ($userEmail === '') {
            $rowErr[] = 'userEmail required';
        } else {
            $uStmt = $mysqli->prepare('SELECT userID FROM tblUsers WHERE LOWER(emailAddress) = ? AND isActive = 1 LIMIT 1');
            if ($uStmt !== false) {
                $uStmt->bind_param('s', $userEmail);
                $uStmt->execute();
                $ur = $uStmt->get_result()->fetch_assoc();
                $uStmt->close();
                if ($ur !== null) {
                    $userIdRow = (int) $ur['userID'];
                } else {
                    $rowErr[] = 'user not found / inactive: ' . $userEmail;
                }
            }
        }

        if ($assigned !== '' && strtotime($assigned) === false) {
            $rowErr[] = 'assignedAt not parseable';
        }
        if ($ends !== '' && strtotime($ends) === false) {
            $rowErr[] = 'endsAt not parseable';
        }

        $rows[] = [
            'rowNum'      => $rowNum,
            'roleName'    => $roleName,
            'userEmail'   => $userEmail,
            'roleID'      => $roleId,
            'userID'      => $userIdRow,
            'assignedAt'  => $assigned,
            'endsAt'      => $ends,
            'errors'      => $rowErr,
        ];
    }
    fclose($handle);
    return ['rows' => $rows, 'errors' => []];
};

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
                $_SESSION['leadershipImportPreview'] = $preview;
            }
        }
        if ($action === 'confirm') {
            $rows = $_SESSION['leadershipImportPreview'] ?? [];
            if (count($rows) === 0) {
                $error = 'No preview data — re-upload the file.';
            } else {
                // Column names match the schema (#213): `startDate`,
                // `endDate`, `createdByID` — earlier draft used
                // `assignedAt`, `endsAt`, `assignedByID` which don't
                // exist on tblLeadershipAssignments. PHP variable names
                // ($assigned, $endsAt) kept for source-code continuity.
                $stmt = $mysqli->prepare(
                    'INSERT INTO tblLeadershipAssignments '
                    . '(roleID, userID, siteID, startDate, endDate, createdByID, isActive) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, 1)'
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
                        $roleId  = (int) $r['roleID'];
                        $userIdR = (int) $r['userID'];
                        $assigned = $r['assignedAt'] !== '' ? date('Y-m-d', strtotime((string) $r['assignedAt'])) : date('Y-m-d');
                        $endsAt   = $r['endsAt']     !== '' ? date('Y-m-d', strtotime((string) $r['endsAt']))   : null;
                        $stmt->bind_param('iiissi', $roleId, $userIdR, $siteId, $assigned, $endsAt, $userId);
                        $okThis = $stmt->execute();
                        $results[] = [
                            'ok'  => $okThis,
                            'row' => $r['rowNum'],
                            'msg' => $okThis ? 'Assigned' : 'DB error: ' . $stmt->error,
                        ];
                        if ($okThis === true) { $okCount++; }
                    }
                    $stmt->close();
                    $imported = true;
                    Logger::activity('LeadershipImported', 'Bulk import: ' . $okCount . ' assignments created');
                    unset($_SESSION['leadershipImportPreview']);
                }
            }
        }
    }
}

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-file-csv me-2"></i>Bulk Import Leadership Assignments</h1>
    <a href="/leadership/manage" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back
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
                <?php foreach ($results as $r): ?>
                    <div class="portal-data-row">
                        <div class="col-6 col-md-2">Row <?php echo (int) $r['row']; ?></div>
                        <div class="col-6 col-md-2">
                            <span class="badge bg-<?php echo $r['ok'] === true ? 'success' : 'danger'; ?>">
                                <?php echo $r['ok'] === true ? 'OK' : 'Skipped'; ?>
                            </span>
                        </div>
                        <div class="col-12 col-md-8 small"><?php echo htmlspecialchars((string) $r['msg'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <a href="/leadership/manage/import" class="btn btn-outline-primary">Import another file</a>
    <a href="/leadership" class="btn btn-outline-secondary">Back to Leadership</a>

<?php elseif (count($preview) > 0): ?>
    <div class="alert alert-info"><strong><?php echo count($preview); ?></strong> row(s) parsed.</div>
    <div class="card shadow-sm mb-3">
        <div class="card-body p-0">
            <div class="portal-data-list">
                <div class="portal-data-row portal-data-header d-none d-md-flex">
                    <div class="col-md-1">Row</div>
                    <div class="col-md-3">Role</div>
                    <div class="col-md-3">User email</div>
                    <div class="col-md-2">Assigned</div>
                    <div class="col-md-2">Ends</div>
                    <div class="col-md-1">Status</div>
                </div>
                <?php foreach ($preview as $r): ?>
                    <div class="portal-data-row">
                        <div class="col-6 col-md-1"><?php echo (int) $r['rowNum']; ?></div>
                        <div class="col-6 col-md-3"><?php echo htmlspecialchars((string) $r['roleName'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-6 col-md-3"><?php echo htmlspecialchars((string) $r['userEmail'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-6 col-md-2 small"><?php echo htmlspecialchars((string) $r['assignedAt'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-6 col-md-2 small"><?php echo htmlspecialchars((string) $r['endsAt'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-12 col-md-1 small">
                            <?php if (count($r['errors']) === 0): ?>
                                <span class="badge bg-success">Ready</span>
                            <?php else: ?>
                                <span class="badge bg-danger" title="<?php echo htmlspecialchars(implode('; ', $r['errors']), ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo count($r['errors']); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <form method="post" action="/leadership/manage/import">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="confirm">
        <button type="submit" class="btn btn-success">
            <i class="fa-solid fa-upload me-1"></i> Confirm &amp; import valid rows
        </button>
        <a href="/leadership/manage/import" class="btn btn-outline-secondary">Cancel</a>
    </form>

<?php else: ?>
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h5">CSV format</h2>
            <p class="small">Required columns: <code>roleName</code>, <code>userEmail</code>. Optional: <code>assignedAt</code>, <code>endsAt</code> (YYYY-MM-DD).</p>
            <p class="small text-muted mb-0">
                Roles + users must already exist — they are NOT auto-created. Excel users: <em>File → Save As → CSV (Comma delimited)</em>.
            </p>
        </div>
    </div>
    <form method="post" action="/leadership/manage/import" enctype="multipart/form-data">
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
