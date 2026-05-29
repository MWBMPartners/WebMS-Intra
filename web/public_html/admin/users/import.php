<?php
// Path: public_html/admin/users/import.php
/**
 * -----------------------------------------------------------------------------
 * Bulk User Import via CSV 📥
 * -----------------------------------------------------------------------------
 * Allows admins to upload a CSV file to create multiple users at once.
 * Supports preview/validation before import and row-level error reporting.
 *
 * CSV columns: fullName, emailAddress, isAdmin (0/1, optional)
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.2
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/87
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

// 📌 Page metadata
$pageTitle   = 'Import Users';
$pageSection = 'admin';
$breadcrumbs = [
    ['label' => 'Admin', 'url' => '/admin'],
    ['label' => 'Users', 'url' => '/admin/users'],
    ['label' => 'Import', 'url' => ''],
];

// 🛡️ Admin access check
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$preview = [];
$results = [];
$error = '';
$imported = false;

// ---------------------------------------------------------------------------
// Handle POST — preview or import
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/users/import');
        exit();
    }

    $action = $_POST['action'] ?? '';

    // 📋 PREVIEW — parse and validate CSV
    if ($action === 'preview' && isset($_FILES['csv_file']) === true) {
        $file = $_FILES['csv_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = 'File upload failed. Please try again.';
        } elseif ($file['size'] > 2 * 1024 * 1024) {
            $error = 'File too large. Maximum size is 2 MB.';
        } else {
            $handle = fopen($file['tmp_name'], 'r');
            if ($handle === false) {
                $error = 'Could not read the uploaded file.';
            } else {
                // 📋 Read header row
                $header = fgetcsv($handle);
                if ($header === false || count($header) < 2) {
                    $error = 'CSV must have at least 2 columns: fullName, emailAddress';
                } else {
                    // 📋 Normalise headers
                    $header = array_map(function ($h) {
                        return strtolower(trim(str_replace(["\xEF\xBB\xBF", '"'], '', $h)));
                    }, $header);

                    $nameCol  = array_search('fullname', $header, true);
                    $emailCol = array_search('emailaddress', $header, true);
                    $adminCol = array_search('isadmin', $header, true);

                    if ($nameCol === false || $emailCol === false) {
                        $error = 'CSV must contain "fullName" and "emailAddress" columns. Found: ' . implode(', ', $header);
                    } else {
                        // 📋 Fetch existing emails for duplicate detection
                        $existingEmails = [];
                        $exStmt = $mysqli->prepare('SELECT LOWER(emailAddress) AS email FROM tblUsers');
                        if ($exStmt !== false) {
                            $exStmt->execute();
                            $exResult = $exStmt->get_result();
                            while ($exRow = $exResult->fetch_assoc()) {
                                $existingEmails[$exRow['email']] = true;
                            }
                            $exStmt->close();
                        }

                        $rowNum = 1;
                        while (($row = fgetcsv($handle)) !== false) {
                            $rowNum++;
                            if (count($row) < 2) {
                                continue;
                            }

                            $name    = trim($row[$nameCol] ?? '');
                            $email   = strtolower(trim($row[$emailCol] ?? ''));
                            $isAdmin = ($adminCol !== false) ? (int) ($row[$adminCol] ?? 0) : 0;

                            $rowErrors = [];
                            if ($name === '') {
                                $rowErrors[] = 'Name is required';
                            }
                            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                                $rowErrors[] = 'Invalid email';
                            }
                            if ($email !== '' && isset($existingEmails[$email]) === true) {
                                $rowErrors[] = 'Email already exists';
                            }

                            $preview[] = [
                                'row'     => $rowNum,
                                'name'    => $name,
                                'email'   => $email,
                                'isAdmin' => $isAdmin,
                                'errors'  => $rowErrors,
                                'valid'   => count($rowErrors) === 0,
                            ];
                        }
                        fclose($handle);

                        // 📋 Store preview in session for the import step
                        $_SESSION['import_preview'] = $preview;
                    }
                }
            }
        }
    }

    // 📋 IMPORT — create users from previewed data
    if ($action === 'import') {
        $preview = $_SESSION['import_preview'] ?? [];
        if (count($preview) === 0) {
            $error = 'No preview data found. Please upload the CSV again.';
        } else {
            $created = 0;
            $skipped = 0;

            // Note: tblUsers has NO siteID column — multi-site assignment is
            // via tblUserSites (inserted below). An earlier version of this
            // statement included siteID directly on tblUsers, which fatalled
            // the import with "Unknown column 'siteID'" the moment a non-
            // admin first tried to use the importer. See issue #198.
            $insertStmt = $mysqli->prepare(
                'INSERT INTO tblUsers (fullName, emailAddress, isAdmin, isActive, createdAt) '
                . 'VALUES (?, ?, ?, 1, NOW())'
            );
            $siteStmt = $mysqli->prepare(
                'INSERT INTO tblUserSites (userID, siteID, isActive) VALUES (?, ?, 1)'
            );

            if ($insertStmt !== false && $siteStmt !== false) {
                foreach ($preview as &$row) {
                    if ($row['valid'] === false) {
                        $row['result'] = 'Skipped';
                        $skipped++;
                        continue;
                    }

                    $insertStmt->bind_param('ssi', $row['name'], $row['email'], $row['isAdmin']);

                    try {
                        $insertStmt->execute();
                        $newId = $insertStmt->insert_id;
                        $siteStmt->bind_param('ii', $newId, $siteId);
                        $siteStmt->execute();
                        $row['result'] = 'Created';
                        $created++;
                    } catch (\mysqli_sql_exception $e) {
                        $row['result'] = 'Error: ' . $e->getMessage();
                        $skipped++;
                    }
                }
                unset($row);
                $insertStmt->close();
                $siteStmt->close();
            }

            $results = $preview;
            $imported = true;
            Logger::activity('BulkUserImport', 'Imported ' . $created . ' users, skipped ' . $skipped, $userId);
            unset($_SESSION['import_preview']);
        }
    }
}

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Import Users</h1>
    <a href="/admin/users" class="btn btn-outline-secondary btn-sm">
        <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>Back to Users
    </a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<?php if ($imported === true): ?>
    <!-- 📊 Import Results -->
    <div class="alert alert-success">
        <strong>Import complete.</strong>
        <?php
        $createdCount = 0;
        $skippedCount = 0;
        foreach ($results as $r) {
            if ($r['result'] === 'Created') {
                $createdCount++;
            } else {
                $skippedCount++;
            }
        }
        ?>
        <?php echo $createdCount; ?> user(s) created, <?php echo $skippedCount; ?> skipped.
    </div>

    <div class="card">
        <div class="card-body p-0">
            <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Row</th><th>Name</th><th>Email</th><th>Result</th></tr></thead>
                <tbody>
                <?php foreach ($results as $r): ?>
                    <tr>
                        <td><?php echo (int) $r['row']; ?></td>
                        <td><?php echo htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($r['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php if ($r['result'] === 'Created'): ?>
                                <span class="badge bg-success">Created</span>
                            <?php else: ?>
                                <span class="badge bg-warning"><?php echo htmlspecialchars($r['result'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif (count($preview) > 0): ?>
    <!-- 📋 Preview Table -->
    <?php
    $validCount = 0;
    $invalidCount = 0;
    foreach ($preview as $p) {
        if ($p['valid'] === true) {
            $validCount++;
        } else {
            $invalidCount++;
        }
    }
    ?>
    <div class="alert alert-info">
        <strong>Preview:</strong> <?php echo $validCount; ?> valid row(s) ready to import<?php echo ($invalidCount > 0) ? ', ' . $invalidCount . ' with errors (will be skipped)' : ''; ?>.
    </div>

    <div class="card mb-3">
        <div class="card-body p-0">
            <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Row</th><th>Name</th><th>Email</th><th>Admin</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($preview as $p): ?>
                    <tr class="<?php echo ($p['valid'] === false) ? 'table-danger' : ''; ?>">
                        <td><?php echo (int) $p['row']; ?></td>
                        <td><?php echo htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($p['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo $p['isAdmin'] === 1 ? 'Yes' : 'No'; ?></td>
                        <td>
                            <?php if ($p['valid'] === true): ?>
                                <span class="badge bg-success">Valid</span>
                            <?php else: ?>
                                <span class="badge bg-danger"><?php echo htmlspecialchars(implode(', ', $p['errors']), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($validCount > 0): ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="import">
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-upload me-1" aria-hidden="true"></i>Import <?php echo $validCount; ?> User(s)
            </button>
            <a href="/admin/users/import" class="btn btn-outline-secondary ms-2">Cancel</a>
        </form>
    <?php endif; ?>

<?php else: ?>
    <!-- 📤 Upload Form -->
    <div class="card">
        <div class="card-header">
            <h2 class="h6 mb-0">Upload CSV File</h2>
        </div>
        <div class="card-body">
            <p>Upload a CSV file with user data. The file must include these columns:</p>
            <ul>
                <li><code>fullName</code> (required) — User's full name</li>
                <li><code>emailAddress</code> (required) — Email address (must be unique)</li>
                <li><code>isAdmin</code> (optional) — 1 for admin, 0 for regular user</li>
            </ul>

            <div class="alert alert-info">
                <strong>Example CSV:</strong><br>
                <code>fullName,emailAddress,isAdmin<br>
                Jane Smith,jane@example.org,0<br>
                John Admin,john@example.org,1</code>
            </div>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="preview">

                <div class="mb-3">
                    <label for="csv_file" class="form-label">CSV File</label>
                    <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv,text/csv" required>
                    <div class="form-text">Maximum file size: 2 MB</div>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-eye me-1" aria-hidden="true"></i>Preview &amp; Validate
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
