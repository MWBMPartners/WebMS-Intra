<?php
// Path: public_html/rota/index.php
/**
 * -----------------------------------------------------------------------------
 * Rota — Member View 🗓️
 * -----------------------------------------------------------------------------
 * My upcoming duties + full roster for the next N weeks.
 *
 * @package   Portal\Rota
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/256
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$weeks  = max(1, min(12, (int) ($_GET['weeks'] ?? 8)));
$endDate = date('Y-m-d', strtotime('+' . $weeks . ' weeks'));

// 🪞 My upcoming duties (next 8 weeks, ascending)
$mySlots = [];
$stmt = $db->prepare(
    'SELECT s.slotID, s.slotDate, s.startTime, s.endTime, s.notes, '
    . '       r.name AS roleName, r.colorHex '
    . 'FROM tblRotaSlot s '
    . 'JOIN tblRotaRoleType r ON r.roleTypeID = s.roleTypeID '
    . 'WHERE s.siteID = ? AND s.assignedToID = ? AND s.slotDate >= CURDATE() AND s.slotDate <= ? '
    . 'ORDER BY s.slotDate, s.startTime'
);
if ($stmt !== false) {
    $stmt->bind_param('iis', $siteId, $userId, $endDate);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $mySlots[] = $r;
    }
    $stmt->close();
}

// 🪞 Full roster grouped by date for the same window
$roster = [];
$stmt = $db->prepare(
    'SELECT s.slotID, s.slotDate, s.startTime, s.endTime, s.assignedToID, '
    . '       r.name AS roleName, r.colorHex, '
    . '       u.fullName AS assigneeName '
    . 'FROM tblRotaSlot s '
    . 'JOIN tblRotaRoleType r ON r.roleTypeID = s.roleTypeID '
    . 'LEFT JOIN tblUsers u ON u.userID = s.assignedToID '
    . 'WHERE s.siteID = ? AND s.slotDate >= CURDATE() AND s.slotDate <= ? '
    . 'ORDER BY s.slotDate, r.name, s.startTime'
);
if ($stmt !== false) {
    $stmt->bind_param('is', $siteId, $endDate);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $roster[$r['slotDate']][] = $r;
    }
    $stmt->close();
}

$pageTitle   = 'Duty Roster';
$pageSection = 'rota';
$breadcrumbs = ['Dashboard' => '/', 'Duty Roster' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-calendar-week me-2"></i>Duty Roster</h1>
        <p class="text-secondary mb-0">Your upcoming duties + full roster for the next <?php echo $weeks; ?> weeks.</p>
    </div>
    <?php if (App::isAdmin() === true): ?>
        <a href="/rota/manage" class="btn btn-primary btn-sm"><i class="fa-solid fa-screwdriver-wrench me-1"></i>Manage</a>
    <?php endif; ?>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5"><i class="fa-solid fa-user-check me-1"></i>My upcoming duties</h2>
        <?php if (count($mySlots) === 0): ?>
            <p class="text-muted mb-0">You have no duties scheduled in the next <?php echo $weeks; ?> weeks.</p>
        <?php else: ?>
            <div class="portal-data-list">
                <?php foreach ($mySlots as $s): ?>
                    <div class="row py-2 align-items-center border-bottom">
                        <div class="col-md-3"><strong><?php echo htmlspecialchars((string) $s['slotDate'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div class="col-md-3">
                            <span class="badge" style="background:<?php echo htmlspecialchars((string) $s['colorHex'], ENT_QUOTES, 'UTF-8'); ?>;color:#fff;">
                                <?php echo htmlspecialchars((string) $s['roleName'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                        <div class="col-md-3 text-muted small">
                            <?php
                            $time = $s['startTime'] !== null
                                ? substr((string) $s['startTime'], 0, 5)
                                  . ($s['endTime'] !== null ? '–' . substr((string) $s['endTime'], 0, 5) : '')
                                : 'All day';
                            echo htmlspecialchars($time, ENT_QUOTES, 'UTF-8');
                            ?>
                        </div>
                        <div class="col-md-3 text-end">
                            <a href="/rota/swap?slot=<?php echo (int) $s['slotID']; ?>" class="btn btn-sm btn-outline-secondary">Request swap</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h2 class="h5"><i class="fa-solid fa-people-group me-1"></i>Full roster</h2>
        <?php if (count($roster) === 0): ?>
            <p class="text-muted mb-0">No duties scheduled. <?php if (App::isAdmin() === true): ?>Go to <a href="/rota/manage">manage</a> to add some.<?php endif; ?></p>
        <?php else: ?>
            <?php foreach ($roster as $date => $slots): ?>
                <h3 class="h6 mt-3"><?php echo htmlspecialchars(date('l, j F Y', strtotime((string) $date)), ENT_QUOTES, 'UTF-8'); ?></h3>
                <div class="portal-data-list">
                    <?php foreach ($slots as $s): ?>
                        <div class="row py-1 border-bottom small">
                            <div class="col-md-4">
                                <span class="badge" style="background:<?php echo htmlspecialchars((string) $s['colorHex'], ENT_QUOTES, 'UTF-8'); ?>;color:#fff;">
                                    <?php echo htmlspecialchars((string) $s['roleName'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </div>
                            <div class="col-md-3 text-muted">
                                <?php
                                $time = $s['startTime'] !== null
                                    ? substr((string) $s['startTime'], 0, 5)
                                      . ($s['endTime'] !== null ? '–' . substr((string) $s['endTime'], 0, 5) : '')
                                    : '';
                                echo htmlspecialchars($time, ENT_QUOTES, 'UTF-8');
                                ?>
                            </div>
                            <div class="col-md-5">
                                <?php if ($s['assignedToID'] === null): ?>
                                    <span class="text-warning"><i class="fa-solid fa-circle-question me-1"></i>Unfilled</span>
                                <?php else: ?>
                                    <?php echo htmlspecialchars((string) ($s['assigneeName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
