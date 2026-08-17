<?php
// Path: _apps/assets/kiosks.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Kiosk Terminals (admin list) 🖥️ (#414, Phase 3 Pass 5)
 * -----------------------------------------------------------------------------
 * Manager-facing kiosk terminal admin screen — lists every
 * `tblAssetKioskTokens` row (registered terminals) for the active site via
 * `AssetRegister::listKioskTokens()` (label/status/lastSeen/createdBy ONLY —
 * that method never selects the raw `token` column, see its own doc), with a
 * "Register terminal" form leading into `assets/kiosk-save` (action=create)
 * and per-row revoke/reactivate/delete forms leading into the same handler.
 * INTERNAL, session-gated admin screen — NOT the public kiosk terminal itself
 * (see `assets/kiosk`/`assets/kiosk-action`, both unprotected).
 *
 * ONE-SHOT TOKEN REVEAL: a freshly-minted terminal's plaintext token is
 * NEVER stored anywhere retrievable — `kiosk-save.php`'s `create` action
 * stashes it in a single-fire session flash (`kiosk_new_token`/
 * `kiosk_new_token_label`), consumed and unset the FIRST time this page
 * renders after the redirect, never shown again on any subsequent load
 * (including a reload of THIS SAME page) — mirrors the house "shown once"
 * convention already used for freshly-generated API keys elsewhere in this
 * codebase.
 *
 * Manager-gated (admin OR asset_manager role) — mirrors every other
 * mutating/manager-only Asset Tracker screen (categories.php, orgs.php,
 * stocktakes.php, …).
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/414
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;

// 🔐 Session + manager gate — mirrors every other mutating/manager-only
// Asset Tracker screen (orgs.php, categories.php, stocktakes.php, …):
// admin OR the asset_manager role.
Auth::ensureSession();
Auth::requireLogin();
$canManage = App::isAdmin() === true || App::hasRole('asset_manager') === true;
if ($canManage === false) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();

// 📋 Every terminal for this site, newest-registered first — never carries
// the raw token column (see AssetRegister::listKioskTokens()'s own doc).
$tokens = AssetRegister::listKioskTokens($siteId);

// 🎁 One-shot reveal of a freshly-minted token — see file header. Consumed
// (unset) immediately so a reload of THIS page never shows it again.
$newToken      = (string) ($_SESSION['kiosk_new_token'] ?? '');
$newTokenLabel = (string) ($_SESSION['kiosk_new_token_label'] ?? '');
unset($_SESSION['kiosk_new_token'], $_SESSION['kiosk_new_token_label']);

$flashMsg  = (string) ($_SESSION['flash_msg']  ?? '');
$flashType = (string) ($_SESSION['flash_type'] ?? '');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf = Auth::csrfToken();

// 🌐 Absolute enrolment URL for the freshly-minted terminal, so a manager
// can copy/paste (or scan) it straight onto the physical device. Host is
// read from the CONNECTION itself (never a request body) — same
// convention as AssetRegister::labelPublicUrl()'s own siteBaseUrl() helper.
$scheme       = (isset($_SERVER['HTTPS']) === true && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host         = (string) ($_SERVER['HTTP_HOST'] ?? '');
$enrolmentUrl = $newToken !== '' ? $scheme . $host . '/assets/kiosk?token=' . $newToken : '';

$pageTitle   = 'Kiosk Terminals';
$pageSection = 'assets';
$breadcrumbs = ['Dashboard' => '/', 'Assets' => '/assets', 'Kiosk Terminals' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-tablet-screen-button me-2"></i>Kiosk Terminals</h1>
        <p class="text-secondary mb-0">Register and manage shared self check-in/out devices.</p>
    </div>
    <a href="/assets" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to Asset Tracker</a>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<?php if ($newToken !== ''): ?>
    <!-- 🎁 One-shot token reveal — see file header. Never rendered again
         after this one load. -->
    <div class="alert alert-warning">
        <h2 class="h6"><i class="fa-solid fa-triangle-exclamation me-2"></i>Copy this now — it won't be shown again</h2>
        <p class="mb-2">
            Terminal "<strong><?php echo htmlspecialchars($newTokenLabel, ENT_QUOTES, 'UTF-8'); ?></strong>" is registered.
            Open the link below on the physical kiosk device (or scan it as a QR code) to enrol it:
        </p>
        <code class="d-block p-2 bg-body-tertiary rounded" style="word-break: break-all;">
            <?php echo htmlspecialchars($enrolmentUrl, ENT_QUOTES, 'UTF-8'); ?>
        </code>
    </div>
<?php endif; ?>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info me-2"></i>
    A kiosk terminal is a SHARED, unattended device — anyone standing in front of it can identify themselves with
    their own PIN (set at <a href="/assets/kiosk-pin">My kiosk PIN</a>) to check items in or out. Revoke a terminal
    immediately if the device is lost or decommissioned.
</div>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5"><i class="fa-solid fa-plus me-2"></i>Register a terminal</h2>
        <form method="post" action="/assets/kiosk-save" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="create">
            <div class="col-md-8">
                <label class="form-label small" for="label">Terminal label</label>
                <input type="text" class="form-control" id="label" name="label" required maxlength="150"
                       placeholder="e.g. AV cupboard tablet">
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fa-solid fa-plus me-1"></i>Register
                </button>
            </div>
        </form>
    </div>
</div>

<h2 class="h5 mb-3">Registered terminals</h2>
<?php if (count($tokens) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-2"></i>No kiosk terminals have been registered yet.
    </div>
<?php else: ?>
    <div class="portal-data-list">
        <div class="portal-data-header">
            <div class="col-4">Label</div>
            <div class="col-2">Status</div>
            <div class="col-3">Last seen</div>
            <div class="col-3 text-end">&nbsp;</div>
        </div>
        <?php foreach ($tokens as $t): ?>
            <?php
            $tokenId  = (int) $t['tokenID'];
            $isActive = (int) $t['isActive'] === 1;
            ?>
            <div class="portal-data-row align-items-center">
                <div class="col-4">
                    <strong><?php echo htmlspecialchars((string) $t['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <br><small class="text-muted">
                        Registered <?php echo htmlspecialchars(date('d M Y', strtotime((string) $t['createdAt'])), ENT_QUOTES, 'UTF-8'); ?>
                        <?php if (!empty($t['createdByName'])): ?>
                            by <?php echo htmlspecialchars((string) $t['createdByName'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </small>
                </div>
                <div class="col-2">
                    <span class="badge bg-<?php echo $isActive === true ? 'success' : 'secondary'; ?>">
                        <?php echo $isActive === true ? 'Active' : 'Revoked'; ?>
                    </span>
                </div>
                <div class="col-3 small text-muted">
                    <?php echo $t['lastSeenAt'] !== null
                        ? htmlspecialchars(date('d M Y H:i', strtotime((string) $t['lastSeenAt'])), ENT_QUOTES, 'UTF-8')
                        : 'Never'; ?>
                </div>
                <div class="col-3 text-end">
                    <?php if ($isActive === true): ?>
                        <form method="post" action="/assets/kiosk-save" class="d-inline"
                              data-confirm="Revoke this terminal? It will stop working immediately.">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="revoke">
                            <input type="hidden" name="tokenID" value="<?php echo $tokenId; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-warning" title="Revoke">
                                <i class="fa-solid fa-ban"></i>
                            </button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="/assets/kiosk-save" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="reactivate">
                            <input type="hidden" name="tokenID" value="<?php echo $tokenId; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-success" title="Reactivate">
                                <i class="fa-solid fa-rotate-left"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="/assets/kiosk-save" class="d-inline"
                          data-confirm="Permanently delete this terminal registration? This can't be undone."
                          data-confirm-destructive="true">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="tokenID" value="<?php echo $tokenId; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
