<?php
// Path: core/templates/error-500.php
/**
 * -----------------------------------------------------------------------------
 * 500 Server Error Page ⚠️
 * -----------------------------------------------------------------------------
 * @package   Portal\Core\Templates
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$pageTitle   = 'Server Error';
$pageSection = '';
$breadcrumbs = [];

require __DIR__ . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="portal-error-page">
    <div class="portal-error-code">500</div>
    <h1 class="portal-error-title">Something Went Wrong</h1>
    <p class="portal-error-text">
        An unexpected error occurred. The issue has been logged and our team will look into it.
        Please try again later.
    </p>
    <a href="/" class="btn btn-primary">
        <i class="fa-solid fa-house-chimney me-1"></i> Return to Dashboard
    </a>
</div>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'footer.php'; ?>
