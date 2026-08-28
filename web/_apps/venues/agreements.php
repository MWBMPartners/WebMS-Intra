<?php
// Path: _apps/venues/agreements.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings — Hire Agreements 📜
 * -----------------------------------------------------------------------------
 * List + inline create/edit (via `?edit=ID`, `edit=0` for a new agreement,
 * house pattern per `_apps/assets/orgs.php`) of the site's hire agreements
 * with the landlord — standing/blanket or ad-hoc. The edit panel also lists
 * attached files (title/size/uploader/date + download/delete) and an
 * upload sub-form (both posting to `agreement-files.php`).
 *
 * Renewal countdown badge: danger when the agreement's `renewalDate` (or,
 * absent that, `termEnd - noticePeriodDays`) falls within 30 days —
 * mirrors the reminder sweep's own lead-day logic (§6/Venues::
 * dueAgreementRenewals()) so the badge and the emailed nudge agree.
 *
 * Quick status actions (Activate/Expire/Terminate) post `agreement-save.php
 * action=set-status` — the manual family (`active`,`terminated`,`expired`)
 * ONLY; `superseded` is never directly settable here, it is the exclusive
 * side-effect of the "Renew / supersede" flow (`?edit=0&supersede=OLDID`
 * pre-fills a NEW agreement's form from the old one, posting `action=
 * supersede`).
 *
 * @package   Portal\Venues
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/429
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\Venues;

Auth::ensureSession();
Auth::requireLogin();

// 🛡️ Manager gate — every §4.2-4.7 handler top-gates on canManage().
if (Venues::canManage() !== true) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$db     = App::db();

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

// -----------------------------------------------------------------------------
// 📋 Filters + the list.
// -----------------------------------------------------------------------------
$venueFilter  = (int) ($_GET['venue'] ?? 0);
$statusFilter = (string) ($_GET['status'] ?? '');
if (in_array($statusFilter, Venues::AGREEMENT_STATUSES, true) === false) {
    $statusFilter = '';
}

$venues = Venues::listVenues($siteId, false);

$filters = [];
if ($venueFilter > 0) {
    $filters['venueID'] = $venueFilter;
}
if ($statusFilter !== '') {
    $filters['status'] = $statusFilter;
}
$agreements = Venues::listAgreements($siteId, $filters);

// 📎 File counts per agreement — a supplementary display detail, one
// grouped read query (not a Venues:: mutation, so it stays a direct
// prepared SELECT here rather than adding a new class method for it).
$fileCounts = [];
$fcStmt = $db->prepare('SELECT agreementID, COUNT(*) AS cnt FROM tblVenueAgreementFiles WHERE siteID = ? GROUP BY agreementID');
if ($fcStmt !== false) {
    $fcStmt->bind_param('i', $siteId);
    $fcStmt->execute();
    $fcResult = $fcStmt->get_result();
    while ($fcRow = $fcResult->fetch_assoc()) {
        $fileCounts[(int) $fcRow['agreementID']] = (int) $fcRow['cnt'];
    }
    $fcStmt->close();
}

// 🔔 Renewal countdown — mirrors dueAgreementRenewals()'s own trigger
// logic (renewalDate, else termEnd - noticePeriodDays) purely for display.
$today = new \DateTimeImmutable('today');
foreach ($agreements as &$a) {
    $renewOn = null;
    if (!empty($a['renewalDate'])) {
        $renewOn = (string) $a['renewalDate'];
    } elseif (!empty($a['termEnd'])) {
        $notice = (int) ($a['noticePeriodDays'] ?? 0);
        $renewOn = (new \DateTimeImmutable((string) $a['termEnd']))->modify('-' . $notice . ' days')->format('Y-m-d');
    }
    $a['renewalBadge'] = null;
    if ($renewOn !== null) {
        $days = (int) $today->diff(new \DateTimeImmutable($renewOn))->format('%r%a');
        if ($days <= 30) {
            $a['renewalBadge'] = ['days' => $days, 'date' => $renewOn];
        }
    }
}
unset($a);

// -----------------------------------------------------------------------------
// ✏️ Edit / create panel.
// -----------------------------------------------------------------------------
$editId       = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editAgreement = null;
$supersedeId  = (int) ($_GET['supersede'] ?? 0);
$supersedeFrom = null;

if ($editId !== null && $editId > 0) {
    $editAgreement = Venues::getAgreement($editId, $siteId);
    if ($editAgreement === null) {
        Router::renderError(404);
        return;
    }
} elseif ($editId === 0 && $supersedeId > 0) {
    $supersedeFrom = Venues::getAgreement($supersedeId, $siteId);
    if ($supersedeFrom === null) {
        Router::renderError(404);
        return;
    }
}

$statusOptions = ['draft', 'active', 'expired', 'terminated']; // superseded is exclusively supersede()'s side-effect.

$pageTitle   = 'Hire Agreements';
$pageSection = 'venues';
$breadcrumbs = ['Dashboard' => '/', 'Venue Bookings' => '/venues', 'Agreements' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-building-columns me-2"></i>Hire Agreements</h1>
<p class="text-muted">What has been agreed with the landlord — standing/blanket hire arrangements and one-off (ad-hoc) contracts, with renewal reminders.</p>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" action="/venues/agreements" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small" for="venue">Venue</label>
                <select class="form-select form-select-sm" id="venue" name="venue" onchange="this.form.submit()">
                    <option value="0">All venues</option>
                    <?php foreach ($venues as $v): ?>
                        <option value="<?php echo (int) $v['venueID']; ?>" <?php echo $venueFilter === (int) $v['venueID'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $v['venueName'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small" for="status">Status</label>
                <select class="form-select form-select-sm" id="status" name="status" onchange="this.form.submit()">
                    <option value="">All statuses</option>
                    <?php foreach (Venues::AGREEMENT_STATUSES as $s): ?>
                        <option value="<?php echo htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $statusFilter === $s ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(ucfirst($s), ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 text-end">
                <a href="/venues/agreements?edit=0" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus me-1"></i>New agreement</a>
            </div>
        </form>
    </div>
</div>

<?php if (count($agreements) === 0): ?>
    <div class="alert alert-info">No hire agreements recorded yet.</div>
<?php else: ?>
    <div class="portal-data-list mb-4">
        <div class="portal-data-header">
            <div class="col-3">Title</div>
            <div class="col-2">Venue</div>
            <div class="col-2">Term</div>
            <div class="col-2">Rate</div>
            <div class="col-1">Status</div>
            <div class="col-1">Files</div>
            <div class="col-1 text-end">Edit</div>
        </div>
        <?php foreach ($agreements as $a): ?>
            <div class="portal-data-row align-items-center">
                <div class="col-3">
                    <strong><?php echo htmlspecialchars((string) $a['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <span class="badge bg-secondary ms-1"><?php echo htmlspecialchars((string) $a['agreementType'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php if ($a['renewalBadge'] !== null): ?>
                        <br><span class="badge bg-danger mt-1">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i>
                            <?php echo (int) $a['renewalBadge']['days'] < 0 ? 'overdue since' : 'renews'; ?>
                            <?php echo htmlspecialchars((string) $a['renewalBadge']['date'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="col-2 small"><?php echo htmlspecialchars((string) $a['venueName'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-2 small text-muted">
                    <?php echo htmlspecialchars((string) ($a['termStart'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                    &ndash;
                    <?php echo htmlspecialchars((string) ($a['termEnd'] ?? 'open-ended'), ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="col-2 small text-muted">
                    <?php if ($a['rateAmountPence'] !== null): ?>
                        £<?php echo number_format(((int) $a['rateAmountPence']) / 100, 2); ?> / <?php echo htmlspecialchars((string) ($a['rateUnit'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </div>
                <div class="col-1">
                    <span class="badge bg-<?php echo (string) $a['status'] === 'active' ? 'success' : ((string) $a['status'] === 'draft' ? 'secondary' : 'warning'); ?>">
                        <?php echo htmlspecialchars((string) $a['status'], ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
                <div class="col-1 small"><?php echo (int) ($fileCounts[(int) $a['agreementID']] ?? 0); ?></div>
                <div class="col-1 text-end">
                    <a href="/venues/agreements?edit=<?php echo (int) $a['agreementID']; ?>" class="btn btn-sm btn-outline-secondary" title="Edit">
                        <i class="fa-solid fa-pen"></i>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($editId !== null): ?>
    <?php
    // 🌱 Field source: an existing agreement, a supersede-prefill, or blank.
    $f = $editAgreement ?? $supersedeFrom ?? [];
    $formVenueId  = (int) ($f['venueID'] ?? $venueFilter);
    $isEdit       = $editAgreement !== null;
    $isSupersede  = $supersedeFrom !== null;
    $formAction   = $isSupersede ? 'supersede' : 'save';
    $defaultCurrency = (string) App::settings('venues.currency') ?: 'GBP';
    ?>
    <div class="card mb-4" id="agreement-form">
        <div class="card-body">
            <h2 class="h5">
                <?php echo $isSupersede ? 'Renew agreement (creates a new record)' : ($isEdit ? 'Edit agreement' : 'New agreement'); ?>
            </h2>
            <?php if ($isSupersede): ?>
                <p class="text-muted small">The previous agreement will be marked <strong>superseded</strong> once this renewal is saved.</p>
            <?php endif; ?>
            <form method="post" action="/venues/agreement-save" class="row g-2">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="<?php echo htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($isEdit): ?>
                    <input type="hidden" name="agreementID" value="<?php echo (int) $editAgreement['agreementID']; ?>">
                <?php elseif ($isSupersede): ?>
                    <input type="hidden" name="oldAgreementID" value="<?php echo (int) $supersedeFrom['agreementID']; ?>">
                <?php endif; ?>

                <div class="col-md-4">
                    <label class="form-label small" for="venueID">Venue</label>
                    <?php if ($isEdit || $isSupersede): ?>
                        <input type="text" class="form-control form-control-sm" value="<?php echo htmlspecialchars((string) ($f['venueName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" disabled>
                        <input type="hidden" name="venueID" value="<?php echo $formVenueId; ?>">
                    <?php else: ?>
                        <select class="form-select form-select-sm" id="venueID" name="venueID" required>
                            <option value="0">— choose —</option>
                            <?php foreach ($venues as $v): ?>
                                <option value="<?php echo (int) $v['venueID']; ?>" <?php echo $formVenueId === (int) $v['venueID'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) $v['venueName'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label small" for="agreementType">Type</label>
                    <select class="form-select form-select-sm" id="agreementType" name="agreementType">
                        <?php foreach (Venues::AGREEMENT_TYPES as $t): ?>
                            <option value="<?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (string) ($f['agreementType'] ?? 'standing') === $t ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst($t), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label small" for="title">Title</label>
                    <input type="text" class="form-control form-control-sm" id="title" name="title" required maxlength="255"
                           value="<?php echo htmlspecialchars($isSupersede ? '' : (string) ($f['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                           placeholder="<?php echo $isSupersede ? htmlspecialchars('e.g. ' . (string) ($f['title'] ?? '') . ' (renewal)', ENT_QUOTES, 'UTF-8') : ''; ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label small" for="reference">Landlord reference</label>
                    <input type="text" class="form-control form-control-sm" id="reference" name="reference" maxlength="100"
                           value="<?php echo htmlspecialchars($isSupersede ? '' : (string) ($f['reference'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small" for="termStart">Term start</label>
                    <input type="date" class="form-control form-control-sm" id="termStart" name="termStart"
                           value="<?php echo htmlspecialchars($isSupersede ? '' : (string) ($f['termStart'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small" for="termEnd">Term end</label>
                    <input type="date" class="form-control form-control-sm" id="termEnd" name="termEnd"
                           value="<?php echo htmlspecialchars($isSupersede ? '' : (string) ($f['termEnd'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small" for="renewalDate">Renewal due</label>
                    <input type="date" class="form-control form-control-sm" id="renewalDate" name="renewalDate"
                           value="<?php echo htmlspecialchars($isSupersede ? '' : (string) ($f['renewalDate'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small" for="noticePeriodDays">Notice period (days)</label>
                    <input type="number" min="0" class="form-control form-control-sm" id="noticePeriodDays" name="noticePeriodDays"
                           value="<?php echo htmlspecialchars($isSupersede ? '' : (string) ($f['noticePeriodDays'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label small" for="rateAmount">Rate</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">£</span>
                        <input type="number" step="0.01" min="0" class="form-control form-control-sm" id="rateAmount" name="rateAmount"
                               value="<?php echo isset($f['rateAmountPence']) && $f['rateAmountPence'] !== null && $isSupersede === false ? htmlspecialchars(number_format(((int) $f['rateAmountPence']) / 100, 2, '.', ''), ENT_QUOTES, 'UTF-8') : ''; ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small" for="rateUnit">Rate unit</label>
                    <select class="form-select form-select-sm" id="rateUnit" name="rateUnit">
                        <option value="">— none —</option>
                        <?php foreach (Venues::RATE_UNITS as $u): ?>
                            <option value="<?php echo htmlspecialchars($u, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (string) ($f['rateUnit'] ?? '') === $u ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small" for="currency">Currency</label>
                    <input type="text" class="form-control form-control-sm" id="currency" name="currency" maxlength="3"
                           value="<?php echo htmlspecialchars($isSupersede ? $defaultCurrency : (string) ($f['currency'] ?? $defaultCurrency), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small" for="status">Status</label>
                    <select class="form-select form-select-sm" id="status" name="status">
                        <?php foreach ($statusOptions as $s): ?>
                            <option value="<?php echo htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (string) ($isSupersede ? 'active' : ($f['status'] ?? 'draft')) === $s ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst($s), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label small" for="notes">Notes</label>
                    <textarea class="form-control form-control-sm" id="notes" name="notes" rows="2"><?php echo htmlspecialchars($isSupersede ? '' : (string) ($f['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="col-12 mt-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fa-solid fa-check me-1"></i><?php echo $isSupersede ? 'Create renewal' : 'Save agreement'; ?>
                    </button>
                    <a href="/venues/agreements" class="btn btn-outline-secondary btn-sm">Cancel</a>
                </div>
            </form>

            <?php if ($isEdit): ?>
                <hr>
                <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                    <span class="text-muted small me-2">Quick actions:</span>
                    <?php foreach (['active' => 'Activate', 'expired' => 'Mark expired', 'terminated' => 'Terminate'] as $sVal => $sLabel): ?>
                        <?php if ((string) $editAgreement['status'] !== $sVal && (string) $editAgreement['status'] !== 'superseded'): ?>
                            <form method="post" action="/venues/agreement-save" class="d-inline"
                                  data-confirm="<?php echo htmlspecialchars($sLabel . ' this agreement?', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="set-status">
                                <input type="hidden" name="agreementID" value="<?php echo (int) $editAgreement['agreementID']; ?>">
                                <input type="hidden" name="status" value="<?php echo htmlspecialchars($sVal, ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary"><?php echo htmlspecialchars($sLabel, ENT_QUOTES, 'UTF-8'); ?></button>
                            </form>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ((string) $editAgreement['status'] !== 'superseded'): ?>
                        <a href="/venues/agreements?edit=0&amp;supersede=<?php echo (int) $editAgreement['agreementID']; ?>" class="btn btn-sm btn-outline-primary">
                            <i class="fa-solid fa-rotate me-1"></i>Renew / supersede
                        </a>
                    <?php endif; ?>
                </div>

                <h3 class="h6">Attached files</h3>
                <?php if (count($editAgreement['files']) === 0): ?>
                    <p class="text-muted small">No files attached.</p>
                <?php else: ?>
                    <div class="portal-data-list mb-3">
                        <div class="portal-data-header">
                            <div class="col-4">Title</div>
                            <div class="col-3">File</div>
                            <div class="col-2">Size</div>
                            <div class="col-3 text-end">Actions</div>
                        </div>
                        <?php foreach ($editAgreement['files'] as $file): ?>
                            <div class="portal-data-row align-items-center">
                                <div class="col-4"><?php echo htmlspecialchars((string) $file['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="col-3 small text-muted"><?php echo htmlspecialchars((string) $file['fileName'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="col-2 small text-muted"><?php echo $file['fileSize'] !== null ? number_format(((int) $file['fileSize']) / 1024, 1) . ' KB' : '—'; ?></div>
                                <div class="col-3 text-end">
                                    <a href="/venues/agreement-download?id=<?php echo (int) $file['fileID']; ?>" class="btn btn-sm btn-outline-secondary" title="Download">
                                        <i class="fa-solid fa-download"></i>
                                    </a>
                                    <form method="post" action="/venues/agreement-files" class="d-inline" data-confirm="Delete this file?" data-confirm-destructive="true">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="agreementID" value="<?php echo (int) $editAgreement['agreementID']; ?>">
                                        <input type="hidden" name="fileID" value="<?php echo (int) $file['fileID']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="/venues/agreement-files" enctype="multipart/form-data" class="row g-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="upload">
                    <input type="hidden" name="agreementID" value="<?php echo (int) $editAgreement['agreementID']; ?>">
                    <div class="col-md-4">
                        <label class="form-label small" for="fileTitle">File title</label>
                        <input type="text" class="form-control form-control-sm" id="fileTitle" name="title" maxlength="255" placeholder="e.g. Signed agreement 2026">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small" for="file">File (PDF, image or Word document)</label>
                        <input type="file" class="form-control form-control-sm" id="file" name="file" accept=".pdf,.png,.jpg,.jpeg,.webp,.docx" required>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-outline-primary btn-sm w-100"><i class="fa-solid fa-upload me-1"></i>Upload</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<a href="/venues" class="btn btn-outline-secondary mt-3"><i class="fa-solid fa-arrow-left me-1"></i>Back to schedule</a>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
