<?php
// _apps/kids/profiles.php — Parent's children list + add (#298)
declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$children = [];
$stmt = $mysqli->prepare(
    'SELECT childID, fullName, dateOfBirth, allergies, photoConsent, pickupAuthorisedNames '
    . 'FROM tblKidProfiles WHERE siteID = ? AND parentUserID = ? AND isActive = 1 ORDER BY fullName'
);
$stmt->bind_param('ii', $siteId, $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) { $children[] = $r; }
$stmt->close();

$pageTitle = 'My Children';
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:720px;">
    <h1 class="h4 mb-2"><i class="fa-solid fa-child me-2 text-primary"></i>My Children</h1>
    <p class="text-muted small">Register your children here so the kids' team can check them in safely.</p>

    <?php if (count($children) === 0): ?>
        <div class="alert alert-info small">No children registered yet. Use the form below.</div>
    <?php else: ?>
        <div class="portal-data-list mb-4">
        <?php foreach ($children as $c): ?>
            <div class="portal-data-row">
                <div class="portal-data-row-main">
                    <strong><?php echo htmlspecialchars((string) $c['fullName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php if (!empty($c['dateOfBirth'])): ?>
                        <span class="text-muted small"> &middot; born <?php echo htmlspecialchars(date('j M Y', strtotime((string) $c['dateOfBirth'])), ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($c['allergies'])): ?>
                        <div class="small text-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i><?php echo htmlspecialchars((string) $c['allergies'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <div class="small text-muted">
                        Pickup: <?php echo !empty($c['pickupAuthorisedNames']) ? htmlspecialchars((string) $c['pickupAuthorisedNames'], ENT_QUOTES, 'UTF-8') : '<em>any registered parent</em>'; ?>
                        &middot; Photos: <?php echo (int) $c['photoConsent'] === 1 ? 'consented' : 'no consent'; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2 class="h6">Add a child</h2>
    <form method="post" action="/kids/profiles/save" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <div class="col-md-6"><label class="form-label small">Full name <span class="text-danger">*</span></label><input type="text" name="fullName" required maxlength="120" class="form-control form-control-sm"></div>
        <div class="col-md-3"><label class="form-label small">Date of birth</label><input type="date" name="dateOfBirth" class="form-control form-control-sm"></div>
        <div class="col-md-3"><label class="form-label small">Photo consent</label>
            <div class="form-check form-switch mt-1">
                <input class="form-check-input" type="checkbox" id="pc" name="photoConsent" value="1">
                <label class="form-check-label small" for="pc">I consent</label>
            </div>
        </div>
        <div class="col-12"><label class="form-label small">Allergies</label><textarea name="allergies" rows="2" maxlength="500" class="form-control form-control-sm"></textarea></div>
        <div class="col-12"><label class="form-label small">Medical notes</label><textarea name="medicalNotes" rows="2" maxlength="1000" class="form-control form-control-sm"></textarea></div>
        <div class="col-12"><label class="form-label small">Authorised pickup names (comma separated; leave blank = any registered parent)</label><input type="text" name="pickupAuthorisedNames" maxlength="500" class="form-control form-control-sm" placeholder="e.g. Mum, Dad, Grandma Jones"></div>
        <div class="col-12"><button class="btn btn-primary btn-sm"><i class="fa-solid fa-plus me-1"></i>Add child</button></div>
    </form>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
