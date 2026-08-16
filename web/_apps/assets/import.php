<?php
// Path: _apps/assets/import.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Bulk Import 📦⬆️ (STUB — Phase 2 Pass 1, #404)
 * -----------------------------------------------------------------------------
 * Placeholder for the future CSV/bulk asset-import screen. Registered as
 * the `assets/import` route (migration 160) so `check_route_targets.py`
 * stays green while the schema foundation ships ahead of the actual
 * import logic — mirrors migration 159's own "documented coming-in-a-
 * later-sub-issue stub" precedent for `_apps/assets/*.php`.
 *
 * Gate: logged-in only for now (`Auth::requireLogin()`) — the real
 * manager-only gate + CSV parsing/validation/preview flow lands with the
 * actual import feature in a later Phase 2 pass.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/404
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();

$pageTitle   = 'Import Assets';
$pageSection = 'assets';
$breadcrumbs = ['Dashboard' => '/', 'Assets' => '/assets', 'Import' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-4"><i class="fa-solid fa-file-import me-2"></i>Import Assets</h1>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info me-2"></i>
    This Asset Tracker feature arrives in a later Phase 2 update.
</div>

<p><a href="/assets" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to Asset Tracker</a></p>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
