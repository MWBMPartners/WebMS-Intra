<?php
// Path: core/templates/error-403.php
/**
 * -----------------------------------------------------------------------------
 * 403 Access Denied Error Page 🚫
 * -----------------------------------------------------------------------------
 * @package   Portal\Core\Templates
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$pageTitle   = 'Access Denied';
$pageSection = '';
$breadcrumbs = [];

require __DIR__ . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="portal-error-page">
    <div class="portal-error-code">403</div>
    <h1 class="portal-error-title">Access Denied</h1>
    <p class="portal-error-text">
        You do not have permission to access this page. Please contact your administrator
        if you believe this is an error.
    </p>
    <a href="/" class="btn btn-primary">
        <i class="fa-solid fa-house-chimney me-1"></i> Return to Dashboard
    </a>
</div>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'footer.php'; ?>
