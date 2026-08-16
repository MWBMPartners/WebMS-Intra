<?php
// Path: _apps/assets/value-report.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Asset Value Report 📦📊 (STUB — Phase 2 Pass 1, #404)
 * -----------------------------------------------------------------------------
 * Placeholder for the future register-value/depreciation reporting screen
 * (current book value, insured value, purchase-cost totals, filterable by
 * category/location). Registered as the `assets/value-report` route
 * (migration 160) so `check_route_targets.py` stays green while the
 * schema foundation ships ahead of the actual report — mirrors migration
 * 159's own "documented coming-in-a-later-sub-issue stub" precedent for
 * `_apps/assets/*.php`.
 *
 * Gate: logged-in only for now — the real manager-only gate lands with
 * the actual report (financial reporting is management-only across the
 * rest of this app).
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

$pageTitle   = 'Asset Value Report';
$pageSection = 'assets';
$breadcrumbs = ['Dashboard' => '/', 'Assets' => '/assets', 'Value Report' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-4"><i class="fa-solid fa-chart-column me-2"></i>Asset Value Report</h1>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info me-2"></i>
    This Asset Tracker feature arrives in a later Phase 2 update.
</div>

<p><a href="/assets" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to Asset Tracker</a></p>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
