<?php
// Path: public_html/payments/checkout.php
/**
 * Payments — start a checkout. POST with amount + purpose + (optional)
 * purposeRef; we redirect to the provider's hosted checkout.
 *
 * Used by Giving (purpose=giving, purposeRef=categoryID) and Projects
 * (purpose=pledge, purposeRef=pledgeID).
 *
 * @package   Portal\Payments
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/268
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Payments;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$settings = App::settings()['payments'] ?? [];
if ((string) ($settings['enabled'] ?? '0') !== '1') {
    http_response_code(503);
    exit('Payments not enabled');
}

$siteId   = Site::id();
$userId   = (int) ($_SESSION['user_id'] ?? 0);
$currency = (string) ($settings['currency'] ?? 'GBP');

$amountRaw   = (string) ($_POST['amount'] ?? '');
$clean       = preg_replace('/[^0-9.]/', '', $amountRaw) ?? '';
$amountPence = (int) round(((float) $clean) * 100);
if ($amountPence < 100) {
    $_SESSION['flash_msg']  = 'Minimum amount is 1.00.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . (string) ($_POST['return_to'] ?? '/'));
    exit();
}

$purpose     = (string) ($_POST['purpose'] ?? 'other');
$purposeRef  = trim((string) ($_POST['purposeRef'] ?? ''));
$description = trim((string) ($_POST['description'] ?? '')) ?: 'Donation';

$redirect = Payments::startCheckout($siteId, $userId, $amountPence, $currency, $description, $purpose, $purposeRef !== '' ? $purposeRef : null);
if ($redirect === null || $redirect === '') {
    $_SESSION['flash_msg']  = 'Could not start checkout — provider may not be configured.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . (string) ($_POST['return_to'] ?? '/'));
    exit();
}

header('Location: ' . $redirect, true, 303);
exit();
