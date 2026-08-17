<?php
// Path: _apps/assets/kiosk-pin.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — My Kiosk PIN 🔢🙋 (#414, Phase 3 Pass 5)
 * -----------------------------------------------------------------------------
 * Self-service page where ANY logged-in member sets, changes, or clears
 * THEIR OWN kiosk PIN (`tblAssetKioskPins`) — the credential that identifies
 * them at a shared, unattended kiosk terminal (`assets/kiosk`). Route seeded
 * `isProtected = 1` (migration 162) — this is a NORMAL session-gated
 * internal page, unlike the PUBLIC `assets/kiosk`/`assets/kiosk-action`
 * terminal routes (migration 161).
 *
 * There is NO userID parameter anywhere on this page, GET or POST — every
 * call into `AssetRegister::setKioskPin()`/`clearKioskPin()`/
 * `hasKioskPin()` passes STRICTLY `$_SESSION['user_id']` + `Site::id()`,
 * never an id from the request — same "the query itself can only ever touch
 * the viewer's own data" security model as `my.php` (#410). The PIN itself
 * is NEVER read back once set — `hasKioskPin()` reports only whether one is
 * currently active, never its value.
 *
 * Self-posting (GET renders, POST mutates, both on this same route) —
 * mirrors `_apps/assets/orgs.php`'s established house pattern for this
 * shape of small, single-owner settings screen.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/414
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\AssetRegister;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

// 🔒 STRICTLY the session user's own id — see file header. Never accept an
// id from $_GET/$_POST on this page, now or in any future edit.
$userId = (int) ($_SESSION['user_id'] ?? 0);
$siteId = Site::id();

// -----------------------------------------------------------------------------
// 💾 POST — set or clear THIS user's own PIN.
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🔐 CSRF FIRST — before any mutation.
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /assets/kiosk-pin');
        exit();
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'set') {
        // 🔢 Numeric-only PIN input — trimmed here; AssetRegister::
        // setKioskPin() independently re-validates the full `^\d{4,6}$`
        // shape + weak-PIN denylist regardless (never trusts this
        // controller's own coercion).
        $pin    = trim((string) ($_POST['pin'] ?? ''));
        $result = AssetRegister::setKioskPin($userId, $siteId, $pin);
    } elseif ($action === 'clear') {
        $result = AssetRegister::clearKioskPin($userId, $siteId);
    } else {
        $result = ['ok' => false, 'msg' => 'Unknown action.'];
    }

    $_SESSION['flash_msg']  = $result['msg'];
    $_SESSION['flash_type'] = $result['ok'] === true ? 'success' : 'danger';
    header('Location: /assets/kiosk-pin');
    exit();
}

// -----------------------------------------------------------------------------
// 📄 GET — render the set/change/clear form.
// -----------------------------------------------------------------------------
$hasPin = AssetRegister::hasKioskPin($userId, $siteId);

$flashMsg  = (string) ($_SESSION['flash_msg']  ?? '');
$flashType = (string) ($_SESSION['flash_type'] ?? '');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf = Auth::csrfToken();

$pageTitle   = 'My Kiosk PIN';
$pageSection = 'assets';
$breadcrumbs = ['Dashboard' => '/', 'Assets' => '/assets', 'My Kiosk PIN' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-lock me-2"></i>My Kiosk PIN</h1>
        <p class="text-secondary mb-0">Used to identify yourself at a shared self check-in/out kiosk terminal.</p>
    </div>
    <a href="/assets" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to Asset Tracker</a>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info me-2"></i>
    Your PIN is 4-6 digits and is never shown once saved. Choose something only you would guess — avoid repeated
    digits (e.g. 1111) or simple runs (e.g. 1234).
</div>

<div class="card" style="max-width: 32rem;">
    <div class="card-body">
        <p class="mb-3">
            Status:
            <?php if ($hasPin === true): ?>
                <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i>PIN set</span>
            <?php else: ?>
                <span class="badge bg-secondary"><i class="fa-solid fa-xmark me-1"></i>No PIN set</span>
            <?php endif; ?>
        </p>

        <form method="post" action="/assets/kiosk-pin" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="set">
            <div class="mb-3">
                <label for="pin" class="form-label"><?php echo $hasPin === true ? 'New PIN' : 'Choose a PIN'; ?></label>
                <input type="password" inputmode="numeric" pattern="[0-9]*" class="form-control" id="pin" name="pin"
                       minlength="4" maxlength="6" autocomplete="off" required>
                <div class="form-text">4-6 digits.</div>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-floppy-disk me-1"></i><?php echo $hasPin === true ? 'Change PIN' : 'Set PIN'; ?>
            </button>
        </form>

        <?php if ($hasPin === true): ?>
            <hr>
            <form method="post" action="/assets/kiosk-pin" data-confirm="Clear your kiosk PIN? You won't be able to use kiosk terminals until you set a new one." data-confirm-destructive="true">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="clear">
                <button type="submit" class="btn btn-outline-danger btn-sm">
                    <i class="fa-solid fa-trash me-1"></i>Clear my PIN
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
