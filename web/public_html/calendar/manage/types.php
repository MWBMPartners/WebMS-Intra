<?php
// Path: public_html/calendar/manage/types.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Event Types & Categories Management 🏷️
 * -----------------------------------------------------------------------------
 * Admin page for managing event types (with sub-types) and categories
 * (with sub-categories). Types support the Preaching Plan feature where
 * worship service sub-types define the service structure.
 *
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.3.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

// 📌 Page metadata
$pageTitle   = 'Event Types & Categories';
$pageSection = 'calendar';
$breadcrumbs = ['Dashboard' => '/', 'Calendar' => '/calendar', 'Manage' => '/calendar/manage', 'Types' => ''];

// 🛡️ Admin access check
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// 🌐 Multi-site scope
$siteId = Site::id();

// -----------------------------------------------------------------------------
// 💾 Handle POST actions
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /calendar/manage/types');
        exit();
    }
    $action = $_POST['action'] ?? '';
    $entity = $_POST['entity'] ?? ''; // 'type' or 'category'

    if ($action === 'create' && $entity === 'type') {
        $name     = trim($_POST['typeName'] ?? '');
        $parentID = ((int) ($_POST['parentID'] ?? 0)) ?: null;
        $slug     = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
        if ($name !== '') {
            $stmt = $mysqli->prepare('INSERT INTO tblEventTypes (typeName, typeSlug, parentID, siteID) VALUES (?, ?, ?, ?)');
            if ($stmt !== false) {
                $stmt->bind_param('ssii', $name, $slug, $parentID, $siteId);
                $stmt->execute();
                $stmt->close();
            }
            Logger::activity('TypeCreated', 'Created event type: ' . $name, $_SESSION['user_id'] ?? null);
            $_SESSION['flash_msg'] = 'Type "' . $name . '" created.';
            $_SESSION['flash_type'] = 'success';
        }
    }

    if ($action === 'create' && $entity === 'category') {
        $name     = trim($_POST['categoryName'] ?? '');
        $parentID = ((int) ($_POST['parentID'] ?? 0)) ?: null;
        $slug     = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));

        // 🎨 Colour + display style validation
        $color = trim((string) ($_POST['color'] ?? ''));
        if ($color !== '' && preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) !== 1) {
            $color = '';   // reject malformed hex silently — falls back to default
        }
        $colorParam = $color === '' ? null : $color;

        $displayStyle = (string) ($_POST['displayStyle'] ?? 'background');
        if ($displayStyle !== 'background' && $displayStyle !== 'text') {
            $displayStyle = 'background';
        }

        if ($name !== '') {
            $stmt = $mysqli->prepare(
                'INSERT INTO tblEventCategories '
                . '(categoryName, categorySlug, parentID, siteID, color, displayStyle) '
                . 'VALUES (?, ?, ?, ?, ?, ?)'
            );
            if ($stmt !== false) {
                $stmt->bind_param('ssiiss', $name, $slug, $parentID, $siteId, $colorParam, $displayStyle);
                $stmt->execute();
                $stmt->close();
            }
            Logger::activity('CategoryCreated', 'Created event category: ' . $name, $_SESSION['user_id'] ?? null);
            $_SESSION['flash_msg'] = 'Category "' . $name . '" created.';
            $_SESSION['flash_type'] = 'success';
        }
    }

    if ($action === 'update' && $entity === 'category') {
        $catID    = (int) ($_POST['categoryID'] ?? 0);
        $color    = trim((string) ($_POST['color'] ?? ''));
        if ($color !== '' && preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) !== 1) {
            $color = '';
        }
        $colorParam = $color === '' ? null : $color;
        $displayStyle = (string) ($_POST['displayStyle'] ?? 'background');
        if ($displayStyle !== 'background' && $displayStyle !== 'text') {
            $displayStyle = 'background';
        }

        if ($catID > 0) {
            $stmt = $mysqli->prepare(
                'UPDATE tblEventCategories SET color = ?, displayStyle = ? '
                . 'WHERE categoryID = ? AND siteID = ?'
            );
            if ($stmt !== false) {
                $stmt->bind_param('ssii', $colorParam, $displayStyle, $catID, $siteId);
                $stmt->execute();
                $stmt->close();
            }
            $_SESSION['flash_msg'] = 'Category appearance updated.';
            $_SESSION['flash_type'] = 'success';
        }
    }

    if ($action === 'delete' && $entity === 'type') {
        $typeID = (int) ($_POST['typeID'] ?? 0);
        if ($typeID > 0) {
            $stmtP = $mysqli->prepare('UPDATE tblEventTypes SET parentID = NULL WHERE parentID = ? AND siteID = ?');
            if ($stmtP !== false) {
                $stmtP->bind_param('ii', $typeID, $siteId);
                $stmtP->execute();
                $stmtP->close();
            }
            $stmt = $mysqli->prepare('DELETE FROM tblEventTypes WHERE typeID = ? AND siteID = ?');
            if ($stmt !== false) {
                $stmt->bind_param('ii', $typeID, $siteId);
                $stmt->execute();
                $stmt->close();
            }
            $_SESSION['flash_msg'] = 'Type deleted.';
            $_SESSION['flash_type'] = 'success';
        }
    }

    if ($action === 'delete' && $entity === 'category') {
        $catID = (int) ($_POST['categoryID'] ?? 0);
        if ($catID > 0) {
            $stmtP = $mysqli->prepare('UPDATE tblEventCategories SET parentID = NULL WHERE parentID = ? AND siteID = ?');
            if ($stmtP !== false) {
                $stmtP->bind_param('ii', $catID, $siteId);
                $stmtP->execute();
                $stmtP->close();
            }
            $stmt = $mysqli->prepare('DELETE FROM tblEventCategories WHERE categoryID = ? AND siteID = ?');
            if ($stmt !== false) {
                $stmt->bind_param('ii', $catID, $siteId);
                $stmt->execute();
                $stmt->close();
            }
            $_SESSION['flash_msg'] = 'Category deleted.';
            $_SESSION['flash_type'] = 'success';
        }
    }

    header('Location: /calendar/manage/types');
    exit();
}

// -----------------------------------------------------------------------------
// 📋 Fetch types and categories
// -----------------------------------------------------------------------------
$typesList = [];
$stmtTypes = $mysqli->prepare(
    'SELECT t.*, p.typeName AS parentName FROM tblEventTypes t '
    . 'LEFT JOIN tblEventTypes p ON p.typeID = t.parentID '
    . 'WHERE t.siteID = ? '
    . 'ORDER BY t.parentID IS NOT NULL, t.sortOrder, t.typeName'
);
if ($stmtTypes !== false) {
    $stmtTypes->bind_param('i', $siteId);
    $stmtTypes->execute();
    $resultTypes = $stmtTypes->get_result();
    while ($r = $resultTypes->fetch_assoc()) {
        $typesList[] = $r;
    }
    $stmtTypes->close();
}

$categoriesList = [];
$stmtCats = $mysqli->prepare(
    'SELECT c.*, p.categoryName AS parentName FROM tblEventCategories c '
    . 'LEFT JOIN tblEventCategories p ON p.categoryID = c.parentID '
    . 'WHERE c.siteID = ? '
    . 'ORDER BY c.parentID IS NOT NULL, c.sortOrder, c.categoryName'
);
if ($stmtCats !== false) {
    $stmtCats->bind_param('i', $siteId);
    $stmtCats->execute();
    $resultCats = $stmtCats->get_result();
    while ($r = $resultCats->fetch_assoc()) {
        $categoriesList[] = $r;
    }
    $stmtCats->close();
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-tags me-2"></i>Types & Categories</h1>
    <a href="/calendar/manage" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i>Events</a>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- 🏷️ Event Types -->
    <div class="col-12 col-lg-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Event Types</h5>
                <button class="btn btn-sm btn-success" data-bs-toggle="collapse" data-bs-target="#addTypeForm">
                    <i class="fa-solid fa-plus"></i>
                </button>
            </div>

            <div class="collapse" id="addTypeForm">
                <div class="card-body border-bottom">
                    <form method="post" action="/calendar/manage/types">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" name="entity" value="type">
                        <div class="mb-2">
                            <input type="text" class="form-control form-control-sm" name="typeName" placeholder="Type name" required>
                        </div>
                        <div class="mb-2">
                            <select name="parentID" class="form-select form-select-sm">
                                <option value="">— Top-level —</option>
                                <?php foreach ($typesList as $t): ?>
                                    <?php if ($t['parentID'] === null): ?>
                                        <option value="<?php echo (int) $t['typeID']; ?>">
                                            <?php echo htmlspecialchars($t['typeName'], ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-sm btn-success">Add</button>
                    </form>
                </div>
            </div>

            <div class="card-body p-0">
                <div class="portal-data-list">
                    <?php foreach ($typesList as $t): ?>
                        <div class="portal-data-row">
                            <div class="col-8">
                                <?php echo ($t['parentID'] !== null) ? '<span class="text-muted ms-3">↳</span> ' : ''; ?>
                                <?php echo htmlspecialchars($t['typeName'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ($t['isActive'] !== '1' && (int) $t['isActive'] !== 1): ?>
                                    <span class="badge bg-secondary ms-1">Inactive</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-4 text-end">
                                <form method="post" action="/calendar/manage/types" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="entity" value="type">
                                    <input type="hidden" name="typeID" value="<?php echo (int) $t['typeID']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                            onclick="return confirm('Delete type: <?php echo htmlspecialchars($t['typeName'], ENT_QUOTES, 'UTF-8'); ?>?');">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 📂 Event Categories -->
    <div class="col-12 col-lg-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Event Categories</h5>
                <button class="btn btn-sm btn-success" data-bs-toggle="collapse" data-bs-target="#addCatForm">
                    <i class="fa-solid fa-plus"></i>
                </button>
            </div>

            <div class="collapse" id="addCatForm">
                <div class="card-body border-bottom">
                    <form method="post" action="/calendar/manage/types">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" name="entity" value="category">
                        <div class="mb-2">
                            <input type="text" class="form-control form-control-sm" name="categoryName" placeholder="Category name" required>
                        </div>
                        <div class="mb-2">
                            <select name="parentID" class="form-select form-select-sm">
                                <option value="">— Top-level —</option>
                                <?php foreach ($categoriesList as $c): ?>
                                    <?php if ($c['parentID'] === null): ?>
                                        <option value="<?php echo (int) $c['categoryID']; ?>">
                                            <?php echo htmlspecialchars($c['categoryName'], ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-7">
                                <label class="form-label small mb-1">Colour</label>
                                <input type="color" class="form-control form-control-sm form-control-color"
                                       name="color" value="#5e6ad2" title="Pick a colour">
                            </div>
                            <div class="col-5">
                                <label class="form-label small mb-1">Style</label>
                                <select name="displayStyle" class="form-select form-select-sm">
                                    <option value="background">Background</option>
                                    <option value="text">Text only</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-sm btn-success">Add</button>
                    </form>
                </div>
            </div>

            <div class="card-body p-0">
                <div class="portal-data-list">
                    <?php foreach ($categoriesList as $c): ?>
                        <?php
                        $catColor = (string) ($c['color'] ?? '');
                        $catStyle = (string) ($c['displayStyle'] ?? 'background');
                        ?>
                        <div class="portal-data-row align-items-center">
                            <div class="col-12 col-md-5">
                                <?php echo ($c['parentID'] !== null) ? '<span class="text-muted ms-3">↳</span> ' : ''; ?>
                                <?php if ($catColor !== '' && preg_match('/^#[0-9a-fA-F]{3,8}$/', $catColor) === 1): ?>
                                    <?php if ($catStyle === 'text'): ?>
                                        <span style="color: <?php echo htmlspecialchars($catColor, ENT_QUOTES, 'UTF-8'); ?>; font-weight: 600;">
                                            <?php echo htmlspecialchars($c['categoryName'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge"
                                              style="background: <?php echo htmlspecialchars($catColor, ENT_QUOTES, 'UTF-8'); ?>; color: #fff;">
                                            <?php echo htmlspecialchars($c['categoryName'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($c['categoryName'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php endif; ?>
                            </div>
                            <div class="col-12 col-md-5">
                                <form method="post" action="/calendar/manage/types" class="row g-1 align-items-center">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="entity" value="category">
                                    <input type="hidden" name="categoryID" value="<?php echo (int) $c['categoryID']; ?>">
                                    <div class="col-auto">
                                        <input type="color" class="form-control form-control-sm form-control-color"
                                               name="color"
                                               value="<?php echo $catColor !== '' ? htmlspecialchars($catColor, ENT_QUOTES, 'UTF-8') : '#5e6ad2'; ?>"
                                               title="Category colour">
                                    </div>
                                    <div class="col-auto">
                                        <select name="displayStyle" class="form-select form-select-sm">
                                            <option value="background" <?php echo $catStyle === 'background' ? 'selected' : ''; ?>>Background</option>
                                            <option value="text"       <?php echo $catStyle === 'text'       ? 'selected' : ''; ?>>Text only</option>
                                        </select>
                                    </div>
                                    <div class="col-auto">
                                        <button type="submit" class="btn btn-sm btn-outline-primary" title="Save appearance">
                                            <i class="fa-solid fa-save"></i>
                                        </button>
                                    </div>
                                </form>
                            </div>
                            <div class="col-12 col-md-2 text-end">
                                <form method="post" action="/calendar/manage/types" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="entity" value="category">
                                    <input type="hidden" name="categoryID" value="<?php echo (int) $c['categoryID']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                            onclick="return confirm('Delete category: <?php echo htmlspecialchars($c['categoryName'], ENT_QUOTES, 'UTF-8'); ?>?');">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
