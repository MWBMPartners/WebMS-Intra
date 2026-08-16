<?php
// Path: _apps/assets/my.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — My Assets 📦🙋 (#410)
 * -----------------------------------------------------------------------------
 * "Assets I own/borrow/am responsible for" personal view, via
 * AssetRegister::listForUser() — STRICTLY the session user's own data.
 * There is NO id parameter anywhere on this page, GET or POST — every
 * caller of listForUser() (this file is the only one) MUST pass the
 * current session's own $_SESSION['user_id'], never an arbitrary id from
 * a query string, form field, or any other request-controlled source.
 * This is deliberate and load-bearing: unlike item.php (which is gated
 * per-asset by isResponsibleFor()/isConfidential), this page has NO
 * per-row access check of its own — its ENTIRE security model is "the
 * query itself can only ever return the viewer's own assets", so a
 * parameter-based lookup here would be a direct IDOR into every other
 * member's ownership/loan/licence data. See AssetRegister::listForUser()'s
 * own doc for the three ways an asset can appear (owner/on-loan/licensed).
 *
 * Gate: logged-in only — every member can see their own assigned assets,
 * no manager gate belongs here (mirrors the Pass 1 stub's own doc, and
 * calendar/my-events.php's equivalent "my …" page).
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/404
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/410
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\AssetRegister;
use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();

// 🔒 STRICTLY the session user's own id — see file header. Never accept an
// id from $_GET/$_POST on this page, now or in any future edit.
$userId = (int) ($_SESSION['user_id'] ?? 0);

$myAssets = AssetRegister::listForUser($userId);

$pageTitle   = 'My Assets';
$pageSection = 'assets';
$breadcrumbs = ['Dashboard' => '/', 'Assets' => '/assets', 'My Assets' => ''];

// 🎨 Small display maps — mirrors item.php's own $statusBadge/reason-icon
// lookup convention.
$statusBadge = [
    'in-service' => 'success', 'in-repair' => 'warning', 'on-loan' => 'info',
    'borrowed'   => 'info',    'in-storage' => 'secondary', 'retired' => 'secondary',
    'disposed'   => 'dark',    'lost' => 'danger', 'stolen' => 'danger',
];
$reasonLabel = ['owner' => 'Owner/custodian', 'on-loan' => 'On loan to me', 'licensed' => 'Licence seat'];
$reasonIcon  = ['owner' => 'fa-user-check', 'on-loan' => 'fa-right-left', 'licensed' => 'fa-key'];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-1"><i class="fa-solid fa-user-tag me-2"></i>My Assets</h1>
<p class="text-muted mb-4">
    Assets you own or are the custodian of, currently have on loan, or hold a licence seat for.
</p>

<?php if (count($myAssets) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-2"></i>
        You're not connected to any assets yet — you'll see items here once you're recorded as an owner/custodian, have an active loan, or hold a licence seat.
    </div>
<?php else: ?>
    <div class="portal-data-list">
        <?php foreach ($myAssets as $a): ?>
            <div class="portal-data-row align-items-start <?php echo (bool) $a['loanIsOverdue'] === true ? 'bg-danger-subtle' : ''; ?>">
                <div class="col-6 col-md-4">
                    <a href="/assets/item?id=<?php echo (int) $a['assetID']; ?>" class="text-decoration-none">
                        <i class="fa-solid <?php echo (string) $a['assetKind'] === 'digital' ? 'fa-cloud' : 'fa-box'; ?> me-2 text-muted"></i>
                        <?php echo htmlspecialchars((string) $a['name'], ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                    <?php if (!empty($a['assetTagCode'])): ?>
                        <br><small class="text-muted"><?php echo htmlspecialchars((string) $a['assetTagCode'], ENT_QUOTES, 'UTF-8'); ?></small>
                    <?php endif; ?>
                </div>
                <div class="col-3 col-md-2">
                    <span class="badge bg-<?php echo htmlspecialchars($statusBadge[(string) $a['status']] ?? 'secondary', ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars(ucwords(str_replace('-', ' ', (string) $a['status'])), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
                <div class="col-3 col-md-3">
                    <?php foreach ((array) $a['reasons'] as $reason): ?>
                        <span class="badge bg-light text-dark border mb-1 d-inline-block">
                            <i class="fa-solid <?php echo htmlspecialchars($reasonIcon[$reason] ?? 'fa-circle', ENT_QUOTES, 'UTF-8'); ?> me-1"></i>
                            <?php echo htmlspecialchars($reasonLabel[$reason] ?? ucfirst($reason), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <div class="col-12 col-md-3 small text-muted">
                    <?php if (in_array('on-loan', (array) $a['reasons'], true) === true && $a['loanDueDate'] !== null): ?>
                        Due back: <?php echo htmlspecialchars((string) $a['loanDueDate'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ((bool) $a['loanIsOverdue'] === true): ?>
                            <span class="badge bg-danger ms-1"><i class="fa-solid fa-triangle-exclamation me-1"></i>Overdue</span>
                        <?php endif; ?>
                    <?php elseif (in_array('licensed', (array) $a['reasons'], true) === true && !empty($a['seatLabel'])): ?>
                        Seat: <?php echo htmlspecialchars((string) $a['seatLabel'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<p class="mt-3"><a href="/assets" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to Asset Tracker</a></p>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
