<?php
// Path: _core/templates/error-403.php
/**
 * -----------------------------------------------------------------------------
 * 403 Access Denied Error Page 🚫
 * -----------------------------------------------------------------------------
 * The one link goes to the front page of the organisation the visitor is in,
 * built by Site::url() (#536). It used to be the bare "/", which is the
 * DEFAULT organisation's front page where organisations are told apart by
 * the first part of the address.
 *
 * @package   Portal\Core\Templates
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Site;

$pageTitle   = t('error.access_denied');
$pageSection = '';
$breadcrumbs = [];

require __DIR__ . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="portal-error-page">
    <div class="portal-error-code">403</div>
    <h1 class="portal-error-title"><?php echo htmlspecialchars(t('error.access_denied'), ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="portal-error-text">
        <?php echo htmlspecialchars(t('error.access_denied_text'), ENT_QUOTES, 'UTF-8'); ?>
    </p>
    <a href="<?php echo htmlspecialchars(Site::url(''), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary">
        <i class="fa-solid fa-house-chimney me-1"></i> <?php echo htmlspecialchars(t('error.return_to_dashboard'), ENT_QUOTES, 'UTF-8'); ?>
    </a>
</div>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'footer.php'; ?>
