<?php
// Path: _apps/venues/venue-save.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings — Venue Save Handler 🏛️💾
 * -----------------------------------------------------------------------------
 * POST-only mutation endpoint behind `manage.php`. Actions:
 *   save          — create/update a venue. An inline "new landlord" name
 *                   (posted alongside) always wins over a picked landlordOrgID
 *                   — see 02-app-design.md §4.2 — by creating a fresh
 *                   `tblAssetOrgs` row via `AssetRegister::saveOrg()` (§3.3:
 *                   landlord CRUD is delegation, not duplication) and using
 *                   its id. On CREATE, `Venues::saveVenue()` seeds the six
 *                   default usage types — the success redirect sends the
 *                   manager straight to review them.
 *   landlord_edit — update an EXISTING landlord's contact details in place
 *                   (keeps the Assets app OFF-friendly landlord flow working
 *                   end to end without the Assets app itself).
 *   toggle        — flip a venue's isActive flag.
 *   delete        — HARD delete; admin-only (re-checked here regardless of
 *                   what the manage.php UI shows), refused by
 *                   `Venues::deleteVenue()` while bookings/invoices/
 *                   agreements still reference the venue.
 *
 * @package   Portal\Venues
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/429
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\Venues;

Auth::ensureSession();
Auth::requireLogin();

// 🛡️ Manager gate — admins or the venue_manager role only (delete tightens
// this further to admin-only below).
if (Venues::canManage() !== true) {
    Router::renderError(403);
    return;
}

// 🔀 POST-only endpoint — a stray GET bounces back to the manage screen.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /venues/manage');
    exit();
}

// 🔐 CSRF FIRST — before any side-effect.
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /venues/manage');
    exit();
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$action = (string) ($_POST['action'] ?? 'save');

if ($action === 'save') {
    $venueId = (int) ($_POST['venueID'] ?? 0);
    $isCreate = $venueId === 0;

    $landlordOrgId   = (int) ($_POST['landlordOrgID'] ?? 0);
    $landlordOrgName = trim((string) ($_POST['landlord_orgName'] ?? ''));

    // 🆕 An inline "new landlord" name always wins over the picked select —
    // create the org (site-scoped, tblAssetOrgs) and use its id.
    if ($landlordOrgName !== '') {
        $newOrgId = AssetRegister::saveOrg($siteId, 0, [
            'orgName'      => $landlordOrgName,
            'contactName'  => (string) ($_POST['landlord_contactName'] ?? ''),
            'contactEmail' => (string) ($_POST['landlord_contactEmail'] ?? ''),
            'contactPhone' => (string) ($_POST['landlord_contactPhone'] ?? ''),
            'agreementRef' => (string) ($_POST['landlord_agreementRef'] ?? ''),
        ], $userId);
        if ($newOrgId === 0) {
            $_SESSION['flash_msg']  = 'Landlord name is required.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: /venues/manage' . ($isCreate ? '' : '?edit=' . $venueId));
            exit();
        }
        $landlordOrgId = $newOrgId;
    }

    $data = [
        'venueName'      => (string) ($_POST['venueName'] ?? ''),
        'landlordOrgID'  => $landlordOrgId,
        'addressLine1'   => (string) ($_POST['addressLine1'] ?? ''),
        'addressLine2'   => (string) ($_POST['addressLine2'] ?? ''),
        'city'           => (string) ($_POST['city'] ?? ''),
        'region'         => (string) ($_POST['region'] ?? ''),
        'postcode'       => (string) ($_POST['postcode'] ?? ''),
        'countryCode'    => (string) ($_POST['countryCode'] ?? 'GB'),
        'timezone'       => (string) ($_POST['timezone'] ?? 'Europe/London'),
        'caretakerName'  => (string) ($_POST['caretakerName'] ?? ''),
        'caretakerPhone' => (string) ($_POST['caretakerPhone'] ?? ''),
        'notes'          => (string) ($_POST['notes'] ?? ''),
    ];

    $savedId = Venues::saveVenue($siteId, $venueId, $data, $userId);
    if ($savedId === 0) {
        $_SESSION['flash_msg']  = 'Could not save the venue — a venue name is required.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /venues/manage' . ($isCreate ? '' : '?edit=' . $venueId));
        exit();
    }

    Logger::activity('VenueSaved', ($isCreate ? 'Created' : 'Updated') . ' venue: ' . $data['venueName'], $userId > 0 ? $userId : null);

    if ($isCreate === true) {
        $_SESSION['flash_msg']  = 'Venue created — default usage types have been seeded. Review the time windows below.';
        $_SESSION['flash_type'] = 'success';
        header('Location: /venues/usage-types?venue=' . $savedId);
        exit();
    }

    $_SESSION['flash_msg']  = 'Venue updated.';
    $_SESSION['flash_type'] = 'success';
    header('Location: /venues/manage');
    exit();
}

if ($action === 'landlord_edit') {
    $orgId         = (int) ($_POST['orgID'] ?? 0);
    $returnVenueId = (int) ($_POST['returnVenueID'] ?? 0);

    // 🛡️ Cross-tenant probe — the org must belong to THIS site before any write.
    $chk = App::db()->prepare('SELECT 1 FROM tblAssetOrgs WHERE orgID = ? AND siteID = ? LIMIT 1');
    $orgOk = false;
    if ($chk !== false) {
        $chk->bind_param('ii', $orgId, $siteId);
        $chk->execute();
        $orgOk = $chk->get_result()->fetch_assoc() !== null;
        $chk->close();
    }

    if ($orgOk === false) {
        $_SESSION['flash_msg']  = 'Landlord organisation not found.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /venues/manage' . ($returnVenueId > 0 ? '?edit=' . $returnVenueId : ''));
        exit();
    }

    $saved = AssetRegister::saveOrg($siteId, $orgId, [
        'orgName'      => (string) ($_POST['landlord_orgName'] ?? ''),
        'contactName'  => (string) ($_POST['landlord_contactName'] ?? ''),
        'contactEmail' => (string) ($_POST['landlord_contactEmail'] ?? ''),
        'contactPhone' => (string) ($_POST['landlord_contactPhone'] ?? ''),
        'agreementRef' => (string) ($_POST['landlord_agreementRef'] ?? ''),
    ], $userId);

    $_SESSION['flash_msg']  = $saved > 0 ? 'Landlord details updated.' : 'Landlord name is required.';
    $_SESSION['flash_type'] = $saved > 0 ? 'success' : 'danger';
    header('Location: /venues/manage' . ($returnVenueId > 0 ? '?edit=' . $returnVenueId : ''));
    exit();
}

if ($action === 'toggle') {
    $venueId = (int) ($_POST['venueID'] ?? 0);
    Venues::toggleVenueActive($venueId, $siteId, $userId);
    $_SESSION['flash_msg']  = 'Venue updated.';
    $_SESSION['flash_type'] = 'success';
    header('Location: /venues/manage');
    exit();
}

if ($action === 'delete') {
    // 🛡️ Hard delete is admin-only — re-checked here regardless of what the
    // calling UI shows (security item 12).
    if (App::isAdmin() !== true) {
        Router::renderError(403);
        return;
    }
    $venueId = (int) ($_POST['venueID'] ?? 0);
    $result  = Venues::deleteVenue($venueId, $siteId, $userId);
    $_SESSION['flash_msg']  = $result['ok'] === true ? 'Venue deleted.' : (string) ($result['error'] ?? 'Could not delete venue.');
    $_SESSION['flash_type'] = $result['ok'] === true ? 'success' : 'danger';
    header('Location: /venues/manage');
    exit();
}

// 🚫 Unknown action.
header('Location: /venues/manage');
exit();
