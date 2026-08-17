<?php
// _apps/kids/profiles.php — Parent's children list + add (#298)
declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// 👪 Parent self-service (#298 gap fix, C2) — scoped to THIS parent's own
//     children only (parentUserID = $userId). This is deliberately NOT
//     gated behind the kids_team staff role: a parent managing their own
//     child's record is not a staff/terminal view.
$children = [];
$stmt = $mysqli->prepare(
    'SELECT childID, fullName, dateOfBirth, allergies, medicalNotes, photoConsent, pickupAuthorisedNames '
    . 'FROM tblKidProfiles WHERE siteID = ? AND parentUserID = ? AND isActive = 1 ORDER BY fullName'
);
$stmt->bind_param('ii', $siteId, $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) { $children[] = $r; }
$stmt->close();

// 💬 Flash message from profiles-save.php (add / edit / deactivate results).
$flashMsg  = (string) ($_SESSION['flash_msg']  ?? '');
$flashType = (string) ($_SESSION['flash_type'] ?? 'info');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$pageTitle = 'My Children';
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:720px;">
    <h1 class="h4 mb-2"><i class="fa-solid fa-child me-2 text-primary"></i>My Children</h1>
    <p class="text-muted small">Register your children here so the kids' team can check them in safely.</p>

    <?php if ($flashMsg !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
            <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (count($children) === 0): ?>
        <div class="alert alert-info small">No children registered yet. Use the form below.</div>
    <?php else: ?>
        <div class="portal-data-list mb-4">
        <?php foreach ($children as $c): ?>
            <?php $childId = (int) $c['childID']; ?>
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
                <div class="portal-data-row-aside d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            data-bs-toggle="collapse" data-bs-target="#editChild<?php echo $childId; ?>">
                        <i class="fa-solid fa-pen me-1"></i>Edit
                    </button>
                    <form method="post" action="/kids/profiles/save" class="d-inline"
                          data-confirm="Deactivate <?php echo htmlspecialchars((string) $c['fullName'], ENT_QUOTES, 'UTF-8'); ?>? Their check-in history is kept, but they will no longer appear here or at the check-in terminal."
                          data-confirm-destructive="true">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="action" value="deactivate">
                        <input type="hidden" name="childID" value="<?php echo $childId; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">
                            <i class="fa-solid fa-user-slash me-1"></i>Deactivate
                        </button>
                    </form>
                </div>
            </div>
            <!-- ✏️ Pre-filled edit form (#298 gap fix, C2). IDOR guard lives in
                 profiles-save.php: the UPDATE is scoped to
                 childID + parentUserID = $userId, so a parent can only ever
                 edit their OWN child regardless of what childID is posted. -->
            <div class="collapse mb-2" id="editChild<?php echo $childId; ?>">
                <div class="card card-body">
                    <form method="post" action="/kids/profiles/save" class="row g-2">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="childID" value="<?php echo $childId; ?>">
                        <div class="col-md-6"><label class="form-label small">Full name <span class="text-danger">*</span></label>
                            <input type="text" name="fullName" required maxlength="120" class="form-control form-control-sm"
                                   value="<?php echo htmlspecialchars((string) $c['fullName'], ENT_QUOTES, 'UTF-8'); ?>"></div>
                        <div class="col-md-3"><label class="form-label small">Date of birth</label>
                            <input type="date" name="dateOfBirth" class="form-control form-control-sm"
                                   value="<?php echo htmlspecialchars((string) ($c['dateOfBirth'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                        <div class="col-md-3"><label class="form-label small">Photo consent</label>
                            <div class="form-check form-switch mt-1">
                                <input class="form-check-input" type="checkbox" id="pc-edit-<?php echo $childId; ?>" name="photoConsent" value="1"
                                       <?php echo (int) $c['photoConsent'] === 1 ? 'checked' : ''; ?>>
                                <label class="form-check-label small" for="pc-edit-<?php echo $childId; ?>">I consent</label>
                            </div>
                        </div>
                        <div class="col-12"><label class="form-label small">Allergies</label>
                            <textarea name="allergies" rows="2" maxlength="500" class="form-control form-control-sm"><?php echo htmlspecialchars((string) ($c['allergies'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea></div>
                        <div class="col-12"><label class="form-label small">Medical notes</label>
                            <textarea name="medicalNotes" rows="2" maxlength="1000" class="form-control form-control-sm"><?php echo htmlspecialchars((string) ($c['medicalNotes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea></div>
                        <div class="col-12"><label class="form-label small">Authorised pickup names (comma separated; leave blank = any registered parent)</label>
                            <input type="text" name="pickupAuthorisedNames" maxlength="500" class="form-control form-control-sm"
                                   value="<?php echo htmlspecialchars((string) ($c['pickupAuthorisedNames'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                   placeholder="e.g. Mum, Dad, Grandma Jones"></div>
                        <div class="col-12"><button class="btn btn-primary btn-sm"><i class="fa-solid fa-floppy-disk me-1"></i>Save changes</button></div>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2 class="h6">Add a child</h2>
    <form method="post" action="/kids/profiles/save" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="action" value="add">
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
