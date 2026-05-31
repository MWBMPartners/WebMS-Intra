<?php
// Path: public_html/milestones/index.php
/**
 * Milestones — this-week + this-month view with visibility filtering.
 *
 * @package   Portal\Milestones
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/259
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$isAdmin = App::isAdmin();

// Build a comma-separated visibility filter the current user can see.
$visible = ['members', 'public'];
if ($isAdmin === true) {
    $visible = ['private', 'team', 'members', 'public'];
}

$todayMd     = date('m-d');
$weekFromMd  = date('m-d', strtotime('+7 days'));
$monthFromMd = date('m-d', strtotime('+30 days'));

// Helper: query for a date-range window (wraps year boundary).
$fetchWindow = function (string $fromMd, string $toMd) use ($db, $visible, $userId): array {
    $vis = "'" . implode("','", array_map(static fn ($v) => preg_replace('/[^a-z]/', '', $v), $visible)) . "'";
    if ($fromMd <= $toMd) {
        $where = "m.monthDay >= '" . $fromMd . "' AND m.monthDay <= '" . $toMd . "'";
    } else {
        // Year-wrap window
        $where = "(m.monthDay >= '" . $fromMd . "' OR m.monthDay <= '" . $toMd . "')";
    }
    $sql = "SELECT m.milestoneID, m.kind, m.label, m.monthDay, m.originYear, m.privacy, "
         . "       u.userID, u.fullName "
         . "FROM tblUserMilestone m "
         . "JOIN tblUsers u ON u.userID = m.userID "
         . "WHERE " . $where . " "
         . "  AND (m.privacy IN (" . $vis . ") OR m.userID = " . $userId . ") "
         . "ORDER BY m.monthDay, u.fullName";
    $rs = $db->query($sql);
    $rows = [];
    if ($rs !== false) {
        while ($r = $rs->fetch_assoc()) {
            $rows[] = $r;
        }
        $rs->free();
    }
    return $rows;
};

$thisWeek  = $fetchWindow($todayMd, $weekFromMd);
$thisMonth = $fetchWindow($todayMd, $monthFromMd);

$pageTitle   = 'Milestones';
$pageSection = 'milestones';
$breadcrumbs = ['Dashboard' => '/', 'Milestones' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

$kindEmoji = [
    'birthday'    => '🎂',
    'anniversary' => '💍',
    'baptism'     => '💧',
    'joining'     => '🌱',
    'wedding'     => '💒',
    'other'       => '✨',
];
$renderRow = function (array $r) use ($kindEmoji): string {
    $emoji = $kindEmoji[$r['kind']] ?? '✨';
    $year = $r['originYear'] !== null && (int) $r['originYear'] > 0
        ? ' (' . (date('Y') - (int) $r['originYear']) . ' yrs)'
        : '';
    $label = (string) ($r['label'] ?? '');
    if ($label === '') {
        $label = ucfirst((string) $r['kind']);
    }
    return '<div class="row py-1 border-bottom">'
         . '<div class="col-md-3"><strong>' . htmlspecialchars(date('j M', strtotime(date('Y') . '-' . str_replace('-', '-', (string) $r['monthDay']))), ENT_QUOTES, 'UTF-8') . '</strong></div>'
         . '<div class="col-md-1">' . $emoji . '</div>'
         . '<div class="col-md-4">' . htmlspecialchars((string) $r['fullName'], ENT_QUOTES, 'UTF-8') . '</div>'
         . '<div class="col-md-4 text-muted small">' . htmlspecialchars($label . $year, ENT_QUOTES, 'UTF-8') . '</div>'
         . '</div>';
};
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-cake-candles me-2 text-pink"></i>Milestones</h1>
        <p class="text-secondary mb-0">Birthdays, anniversaries, and joining dates worth remembering.</p>
    </div>
    <a href="/milestones/me" class="btn btn-outline-primary btn-sm">My milestones</a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <h2 class="h5">This week</h2>
        <?php if (count($thisWeek) === 0): ?>
            <p class="text-muted mb-0">Nothing this week.</p>
        <?php else: ?>
            <div class="portal-data-list"><?php foreach ($thisWeek as $r) echo $renderRow($r); ?></div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h2 class="h5">Next 30 days</h2>
        <?php if (count($thisMonth) === 0): ?>
            <p class="text-muted mb-0">No upcoming milestones.</p>
        <?php else: ?>
            <div class="portal-data-list"><?php foreach ($thisMonth as $r) echo $renderRow($r); ?></div>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
