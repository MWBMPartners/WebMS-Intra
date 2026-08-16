<?php
// Path: _apps/assets/loan-save.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Save Asset Loan (stub) 📦
 * -----------------------------------------------------------------------------
 * POST handler — creates/updates a tblAssetLoans row.
 *
 * FOUNDATION-PASS STUB (#393). The route/settings/schema for this handler
 * ship in this change so check_route_targets.py stays green and later
 * sub-issues have a real, reachable file to fill in — but the actual
 * behaviour described above is NOT built yet. This page requires login
 * and shows a "coming soon" notice only.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();

$pageTitle   = 'Save Asset Loan';
$pageSection = 'assets';
$breadcrumbs = ['Dashboard' => '/', 'Assets' => '/assets', 'Save Asset Loan' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info me-2"></i>
    This feature arrives in a later Asset Tracker sub-issue.
</div>
<p><a href="/assets" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to Asset Tracker</a></p>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
