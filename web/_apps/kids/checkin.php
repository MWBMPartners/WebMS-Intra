<?php
// _apps/kids/checkin.php — Staff terminal: search + check in (#298)
declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
$eventId = (int) ($_GET['eventID'] ?? 0);

$matches = [];
if ($q !== '') {
    $needle = '%' . $q . '%';
    $stmt = $mysqli->prepare(
        'SELECT k.childID, k.fullName, k.allergies, k.photoConsent, k.pickupAuthorisedNames, '
        . '       (SELECT COUNT(*) FROM tblKidCheckins kc WHERE kc.childID = k.childID AND kc.checkedOutAt IS NULL) AS isCheckedIn '
        . 'FROM tblKidProfiles k '
        . 'WHERE k.siteID = ? AND k.isActive = 1 AND k.fullName LIKE ? '
        . 'ORDER BY k.fullName LIMIT 30'
    );
    $stmt->bind_param('is', $siteId, $needle);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) { $matches[] = $r; }
    $stmt->close();
}

// Active check-ins (badge codes shown so parents can match)
$open = [];
$stmt = $mysqli->prepare(
    'SELECT kc.checkinID, kc.badgeCode, kc.checkedInAt, k.fullName, k.allergies '
    . 'FROM tblKidCheckins kc JOIN tblKidProfiles k ON k.childID = kc.childID '
    . 'WHERE k.siteID = ? AND kc.checkedOutAt IS NULL '
    . 'ORDER BY kc.checkedInAt DESC LIMIT 100'
);
$stmt->bind_param('i', $siteId);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) { $open[] = $r; }
$stmt->close();

$pageTitle = 'Kids Check-In Terminal';
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

if (isset($_SESSION['kids_badge_issued']) === true) {
    $issued = $_SESSION['kids_badge_issued'];
    unset($_SESSION['kids_badge_issued']);
    echo '<div class="alert alert-success text-center" style="font-size:1.4em;">';
    echo '<strong>' . htmlspecialchars((string) $issued['name'], ENT_QUOTES, 'UTF-8') . '</strong> checked in.<br>';
    echo 'Badge code: <span style="font-family:monospace; font-size:2em; letter-spacing:.3em;">' . htmlspecialchars((string) $issued['code'], ENT_QUOTES, 'UTF-8') . '</span>';
    echo '</div>';
}
?>
<div class="container py-3">
    <h1 class="h4 mb-2"><i class="fa-solid fa-children me-2 text-primary"></i>Kids Check-In Terminal</h1>

    <form method="get" class="mb-4">
        <div class="input-group">
            <input type="text" name="q" value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>" maxlength="80" autofocus class="form-control form-control-lg" placeholder="Search child by name…">
            <button class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i></button>
        </div>
    </form>

    <?php if ($q !== '' && count($matches) === 0): ?>
        <div class="alert alert-warning">No child matched "<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>".</div>
    <?php endif; ?>

    <?php if (count($matches) > 0): ?>
        <h2 class="h6">Match results</h2>
        <div class="portal-data-list mb-4">
        <?php foreach ($matches as $m): ?>
            <div class="portal-data-row">
                <div class="portal-data-row-main">
                    <strong><?php echo htmlspecialchars((string) $m['fullName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php if ((int) $m['isCheckedIn'] === 1): ?>
                        <span class="badge bg-warning text-dark">Already checked in</span>
                    <?php endif; ?>
                    <?php if (!empty($m['allergies'])): ?>
                        <div class="small text-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i><?php echo htmlspecialchars((string) $m['allergies'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                </div>
                <div class="portal-data-row-aside">
                    <?php if ((int) $m['isCheckedIn'] === 0): ?>
                        <form method="post" action="/kids/checkin/do">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                            <input type="hidden" name="childID" value="<?php echo (int) $m['childID']; ?>">
                            <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
                            <button class="btn btn-success"><i class="fa-solid fa-circle-check me-1"></i>Check in</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2 class="h6">Currently checked in (<?php echo count($open); ?>)</h2>
    <?php if (count($open) === 0): ?>
        <p class="text-muted small">No children currently checked in.</p>
    <?php else: ?>
        <div class="portal-data-list">
        <?php foreach ($open as $o): ?>
            <div class="portal-data-row">
                <div class="portal-data-row-main">
                    <strong><?php echo htmlspecialchars((string) $o['fullName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <span class="badge bg-secondary ms-1" style="font-family:monospace;"><?php echo htmlspecialchars((string) $o['badgeCode'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php if (!empty($o['allergies'])): ?>
                        <span class="badge bg-danger ms-1"><i class="fa-solid fa-triangle-exclamation"></i></span>
                    <?php endif; ?>
                    <div class="small text-muted">in @ <?php echo htmlspecialchars(date('H:i', strtotime((string) $o['checkedInAt'])), ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <hr>
    <p class="small text-muted">Parent picking up? Go to <a href="/kids/checkout">checkout</a> and enter the badge code.</p>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
