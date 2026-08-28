<?php
// Path: _apps/venues/booking-save.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings — Booking Mutations 🏛️💾
 * -----------------------------------------------------------------------------
 * POST-only. CSRF is checked FIRST, before the manager gate or any side
 * effect (`_apps/assets/orgs.php` l.53-58 order). Every mutation is
 * delegated to a single `Venues::` choke-point method — this file posts
 * NOTHING that those methods don't independently re-validate site/venue-
 * scoped (venue∈site, status∈site, usage type∈venue, room∈venue, event∈site,
 * agreement∈venue, group∈venue — `Venues::saveBooking()` §3.4). This file's
 * own job is CSRF-first, the manager re-check, the pounds→pence boundary
 * conversion, and flash + redirect (PRG).
 *
 * Actions: save · delete · group-status · group-delete.
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

use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\Venues;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// 🚪 Non-POST hits this mutation-only route → send it back to the schedule.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /venues');
    exit();
}

// 🔐 CSRF FIRST — before any side-effect, before the manager gate.
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /venues');
    exit();
}

// 🛡️ Manager gate — re-checked here regardless of what booking.php already showed.
if (Venues::canManage() !== true) {
    Router::renderError(403);
    return;
}

$action  = (string) ($_POST['action'] ?? 'save');
$venueId = (int) ($_POST['venueID'] ?? 0);

switch ($action) {
    case 'save':
        $bookingId = (int) ($_POST['bookingID'] ?? 0);

        // 💷 Pounds → pence, ONLY at this boundary. Blank field = no cost set.
        $costPounds = trim((string) ($_POST['costPence'] ?? ''));
        $data = $_POST;
        $data['costPence'] = $costPounds !== '' ? (string) (int) round(((float) $costPounds) * 100) : null;

        $result = Venues::saveBooking($siteId, $bookingId, $data, $userId);
        if (count($result['errors']) > 0) {
            $_SESSION['flash_msg']  = implode(' ', $result['errors']);
            $_SESSION['flash_type'] = 'danger';
            $back = $bookingId > 0
                ? '/venues/booking?id=' . $bookingId
                : '/venues/booking?venue=' . $venueId . '&date=' . rawurlencode((string) ($_POST['bookingDate'] ?? ''));
            header('Location: ' . $back);
            exit();
        }

        Logger::activity('VenueBookingSaved', 'Booking #' . $result['id'] . ' saved for venue #' . $venueId, $userId);
        $_SESSION['flash_msg']  = 'Booking saved.';
        $_SESSION['flash_type'] = 'success';
        $bookingDate = (string) ($_POST['bookingDate'] ?? '');
        $yr = $bookingDate !== '' ? (int) date('Y', (int) strtotime($bookingDate)) : (int) date('Y');
        header('Location: /venues?venue=' . $venueId . '&year=' . $yr . '#d-' . rawurlencode($bookingDate));
        exit();

    case 'delete':
        $bookingId = (int) ($_POST['bookingID'] ?? 0);
        $ok = Venues::softDeleteBooking($bookingId, $siteId, $userId);
        $_SESSION['flash_msg']  = $ok === true ? 'Booking deleted.' : 'Could not delete that booking.';
        $_SESSION['flash_type'] = $ok === true ? 'success' : 'danger';
        header('Location: /venues?venue=' . $venueId);
        exit();

    case 'group-status':
        $groupId  = (int) ($_POST['groupID'] ?? 0);
        $statusId = (int) ($_POST['statusID'] ?? 0);
        $count = Venues::setGroupStatus($groupId, $siteId, $statusId, $userId);
        $_SESSION['flash_msg']  = $count > 0 ? 'Status applied to ' . $count . ' booking(s).' : 'Could not update the run.';
        $_SESSION['flash_type'] = $count > 0 ? 'success' : 'danger';
        header('Location: /venues?venue=' . $venueId);
        exit();

    case 'group-delete':
        $groupId = (int) ($_POST['groupID'] ?? 0);
        $count = Venues::softDeleteGroup($groupId, $siteId, $userId);
        $_SESSION['flash_msg']  = $count > 0 ? 'Deleted ' . $count . ' booking(s).' : 'Could not delete the run.';
        $_SESSION['flash_type'] = $count > 0 ? 'success' : 'danger';
        header('Location: /venues?venue=' . $venueId);
        exit();

    default:
        header('Location: /venues?venue=' . $venueId);
        exit();
}
