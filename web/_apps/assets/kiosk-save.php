<?php
// Path: _apps/assets/kiosk-save.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Kiosk Terminal Save (register/revoke/reactivate/delete) 🖥️💾 (#414, Phase 3 Pass 5)
 * -----------------------------------------------------------------------------
 * POST-only, self-posting dispatch handler for the kiosk TERMINAL lifecycle
 * (`tblAssetKioskTokens`) — one route, four `action` values (mirrors
 * `stocktake-save.php`'s/`owners-save.php`'s own single-route-multi-action
 * house pattern rather than adding separate `tblRoutes` rows per verb):
 *
 *   - `create`     — register a new terminal via `AssetRegister::
 *                     mintKioskToken()`. The returned plaintext token is
 *                     stashed in a SINGLE-FIRE session flash
 *                     (`kiosk_new_token`/`kiosk_new_token_label`) for
 *                     `kiosks.php` to display exactly once — never written
 *                     anywhere else, never logged, never re-selectable.
 *   - `revoke`     — `AssetRegister::setKioskTokenActive(…, false, …)`.
 *   - `reactivate` — `AssetRegister::setKioskTokenActive(…, true, …)`.
 *   - `delete`     — `AssetRegister::deleteKioskToken()`.
 *
 * INTERNAL, session-gated admin handler — NOT the public kiosk terminal
 * itself (see `assets/kiosk`/`assets/kiosk-action`, both unprotected).
 * Every branch hands its raw posted values straight to an AssetRegister
 * method that re-validates/IDOR-guards them itself — this controller's own
 * job is limited to CSRF, the manager gate, coercing $_POST into the shape
 * each method expects, and setting a flash message before redirecting back.
 * No HTML is ever rendered here.
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

Auth::ensureSession();
Auth::requireLogin();

// 🔐 CSRF FIRST — before any authorization/mutation logic (task
// requirement for this pass — see .claude/CLAUDE.md → Code Style).
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /assets/kiosks');
    exit();
}

// 🛡️ Manager gate — admins or the asset_manager role only. Mirrors every
// other mutating Asset Tracker handler (save.php, stocktake-save.php, …).
if (App::isAdmin() !== true && App::hasRole('asset_manager') !== true) {
    Router::renderError(403);
    return;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');

switch ($action) {
    // -------------------------------------------------------------------
    // ➕ Register a new terminal.
    // -------------------------------------------------------------------
    case 'create':
        $label  = trim((string) ($_POST['label'] ?? ''));
        $result = AssetRegister::mintKioskToken($label, $userId);

        $_SESSION['flash_msg']  = $result['msg'];
        $_SESSION['flash_type'] = $result['ok'] === true ? 'success' : 'danger';

        // 🎁 Single-fire reveal — see file header. Only set on success;
        // never persisted anywhere beyond this one redirect round-trip.
        if ($result['ok'] === true) {
            $_SESSION['kiosk_new_token']       = (string) $result['token'];
            $_SESSION['kiosk_new_token_label'] = $label;
        }

        header('Location: /assets/kiosks');
        exit();

    // -------------------------------------------------------------------
    // 🚫 Revoke (takes effect immediately — see resolveKioskTerminal()).
    // -------------------------------------------------------------------
    case 'revoke':
        $tokenId = (int) ($_POST['tokenID'] ?? 0);
        $result  = AssetRegister::setKioskTokenActive($tokenId, false, $userId);

        $_SESSION['flash_msg']  = $result['msg'];
        $_SESSION['flash_type'] = $result['ok'] === true ? 'success' : 'danger';
        header('Location: /assets/kiosks');
        exit();

    // -------------------------------------------------------------------
    // ✅ Reactivate a previously-revoked terminal.
    // -------------------------------------------------------------------
    case 'reactivate':
        $tokenId = (int) ($_POST['tokenID'] ?? 0);
        $result  = AssetRegister::setKioskTokenActive($tokenId, true, $userId);

        $_SESSION['flash_msg']  = $result['msg'];
        $_SESSION['flash_type'] = $result['ok'] === true ? 'success' : 'danger';
        header('Location: /assets/kiosks');
        exit();

    // -------------------------------------------------------------------
    // 🗑️ Permanently delete a terminal registration.
    // -------------------------------------------------------------------
    case 'delete':
        $tokenId = (int) ($_POST['tokenID'] ?? 0);
        $result  = AssetRegister::deleteKioskToken($tokenId, $userId);

        $_SESSION['flash_msg']  = $result['msg'];
        $_SESSION['flash_type'] = $result['ok'] === true ? 'success' : 'danger';
        header('Location: /assets/kiosks');
        exit();

    // -------------------------------------------------------------------
    // ❓ Unknown action — never happens from this app's own forms, but
    //    fail closed rather than falling through to a no-op 200.
    // -------------------------------------------------------------------
    default:
        $_SESSION['flash_msg']  = 'Unknown kiosk terminal action.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /assets/kiosks');
        exit();
}
