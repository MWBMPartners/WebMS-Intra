<?php
// Path: public_html/calendar/manage/month-themes.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Month Themes / Strap-lines Admin 🗓️
 * -----------------------------------------------------------------------------
 * Per-year-per-month text shown under each month name on /calendar?view=year.
 * Stored in tblCalendarMonthThemes; one row per (siteID, year, month).
 *
 * Usage:
 *   /calendar/manage/month-themes              → current year
 *   /calendar/manage/month-themes?year=2027    → specific year
 *
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.11.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

// 📌 Page metadata
$pageTitle   = 'Month Themes';
$pageSection = 'calendar';
$breadcrumbs = [
    'Dashboard'        => '/',
    'Calendar'         => '/calendar',
    'Manage'           => '/calendar/manage',
    'Month Themes'     => '',
];

Auth::ensureSession();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();
$year   = (int) ($_GET['year'] ?? $_POST['year'] ?? date('Y'));
if ($year < 1900 || $year > 2999) {
    $year = (int) date('Y');
}

// -----------------------------------------------------------------------------
// 💾 POST handler — save all 12 months in one round-trip
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /calendar/manage/month-themes?year=' . $year, true, 302);
        exit();
    }

    $themes = $_POST['theme'] ?? [];
    if (is_array($themes) === true) {
        $upsert = $mysqli->prepare(
            'INSERT INTO tblCalendarMonthThemes (siteID, year, month, themeText) '
            . 'VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE themeText = VALUES(themeText)'
        );
        $deleteEmpty = $mysqli->prepare(
            'DELETE FROM tblCalendarMonthThemes '
            . 'WHERE siteID = ? AND year = ? AND month = ?'
        );

        if ($upsert !== false && $deleteEmpty !== false) {
            for ($m = 1; $m <= 12; $m++) {
                $val = trim((string) ($themes[(string) $m] ?? ''));
                if (mb_strlen($val) > 255) {
                    $val = mb_substr($val, 0, 255);
                }
                if ($val === '') {
                    // 🧹 Empty value → remove any existing row so the cell goes back to default
                    $deleteEmpty->bind_param('iii', $siteId, $year, $m);
                    $deleteEmpty->execute();
                } else {
                    $upsert->bind_param('iiis', $siteId, $year, $m, $val);
                    $upsert->execute();
                }
            }
            $upsert->close();
            $deleteEmpty->close();

            Logger::activity('CalendarMonthThemesSaved', 'Saved month themes for year ' . $year);
            $_SESSION['flash_msg']  = 'Month themes for ' . $year . ' saved.';
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_msg']  = 'Database error saving themes.';
            $_SESSION['flash_type'] = 'danger';
        }
    }

    header('Location: /calendar/manage/month-themes?year=' . $year, true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 📋 Load existing themes for this year
// -----------------------------------------------------------------------------
$existing = [];
$stmt = $mysqli->prepare(
    'SELECT month, themeText FROM tblCalendarMonthThemes '
    . 'WHERE siteID = ? AND year = ?'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $siteId, $year);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $existing[(int) $row['month']] = (string) $row['themeText'];
    }
    $stmt->close();
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

$months = [
    1 => 'January', 2 => 'February', 3 => 'March',    4 => 'April',
    5 => 'May',     6 => 'June',     7 => 'July',     8 => 'August',
    9 => 'September',10 => 'October',11 => 'November',12 => 'December',
];
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="mb-0">
        <i class="fa-solid fa-quote-left me-2"></i>Month Themes — <?php echo (int) $year; ?>
    </h1>
    <div class="d-flex gap-2">
        <a href="/calendar/manage" class="btn btn-outline-secondary">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Manage
        </a>
        <a href="/calendar?view=year&amp;date=<?php echo (int) $year; ?>-01-01" class="btn btn-outline-primary">
            <i class="fa-solid fa-eye me-1"></i> View on Planner
        </a>
    </div>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Year picker -->
<form method="get" action="/calendar/manage/month-themes" class="row g-2 align-items-end mb-3">
    <div class="col-auto">
        <label for="year-input" class="form-label small mb-1">Year</label>
        <input id="year-input" type="number" name="year" class="form-control form-control-sm"
               min="1900" max="2999" value="<?php echo (int) $year; ?>">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-outline-secondary">Load</button>
    </div>
</form>

<!-- Themes editor -->
<form method="post" action="/calendar/manage/month-themes">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="year" value="<?php echo (int) $year; ?>">

    <div class="card shadow-sm">
        <div class="card-body">
            <p class="text-muted small">
                Each month can carry a short "strap-line" or theme that appears under its
                name on the year-planner view. Leave a field blank to remove the theme
                for that month.
            </p>

            <div class="row g-3">
                <?php foreach ($months as $num => $name): ?>
                    <div class="col-12 col-md-6 col-lg-4">
                        <label for="theme-<?php echo $num; ?>" class="form-label">
                            <strong><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></strong>
                        </label>
                        <input id="theme-<?php echo $num; ?>"
                               type="text"
                               name="theme[<?php echo $num; ?>]"
                               class="form-control"
                               maxlength="255"
                               value="<?php echo htmlspecialchars($existing[$num] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                               placeholder="e.g. ~Healthy connections~">
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="card-footer d-flex gap-2">
            <button type="submit" class="btn btn-success">
                <i class="fa-solid fa-save me-1"></i> Save Themes
            </button>
            <a href="/calendar?view=year&amp;date=<?php echo (int) $year; ?>-01-01" class="btn btn-outline-secondary">
                Cancel
            </a>
        </div>
    </div>
</form>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
