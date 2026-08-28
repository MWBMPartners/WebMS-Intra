<?php
// Path: public_html/service-plans/share.php
/**
 * -----------------------------------------------------------------------------
 * Service Plans — public Order-of-Service share toggle (gap #128) 🔗
 * -----------------------------------------------------------------------------
 * CSRF'd POST handler for `/service-plans/edit.php`'s Share panel:
 *   action=enable  — turns on `/os/{token}` for this plan, minting a token
 *                    if it doesn't already have one. Refused (site-level
 *                    kill-switch) unless `service_plans.public_share.
 *                    enabled` is 'true' — enabling a plan's OWN sharing
 *                    when the site has never opted in would silently do
 *                    nothing useful (public.php checks BOTH gates anyway),
 *                    so this is refused up front with a clear flash rather
 *                    than a confusing no-op toggle.
 *   action=disable — turns `isPublicShared` off. Leaves the token in place
 *                    (harmless — public.php's uniform-404 already treats
 *                    `isPublicShared=0` identically to "unknown token"),
 *                    so re-enabling later doesn't need a new QR code.
 *   action=rotate  — mints a FRESH token, immediately invalidating any
 *                    previously shared link/QR code. Requires the plan to
 *                    already be shared (rotating an unshared plan is a
 *                    no-op — nothing to invalidate).
 *
 * Every action re-confirms the plan belongs to the CURRENT site before
 * touching a row — the same discipline as item-save.php.
 *
 * @package   Portal\ServicePlans
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/128
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Hymnal;
use Portal\Core\Logger;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db     = App::db();
$siteId = Site::id();
$planId = (int) ($_POST['planID'] ?? 0);
$action = (string) ($_POST['action'] ?? '');

$stmt = $db->prepare('SELECT planID, publicToken, isPublicShared FROM tblServicePlan WHERE planID = ? AND siteID = ? LIMIT 1');
if ($stmt === false) {
    http_response_code(500);
    exit('Server error');
}
$stmt->bind_param('ii', $planId, $siteId);
$stmt->execute();
$plan = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($plan === null) {
    http_response_code(404);
    exit('Plan not found');
}

if ($action === 'enable') {
    if ((string) App::settings('service_plans.public_share.enabled') !== 'true') {
        $_SESSION['flash_msg']  = 'Public sharing is turned off for this site — ask an admin to enable it at /admin/hymns.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: /service-plans/edit?id=' . $planId);
        exit();
    }
    $token = (string) ($plan['publicToken'] ?? '');
    if ($token === '') {
        $token = Hymnal::generateShareToken();
        $stmt  = $db->prepare('UPDATE tblServicePlan SET publicToken = ?, isPublicShared = 1 WHERE planID = ? AND siteID = ?');
        if ($stmt !== false) {
            $stmt->bind_param('sii', $token, $planId, $siteId);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        $stmt = $db->prepare('UPDATE tblServicePlan SET isPublicShared = 1 WHERE planID = ? AND siteID = ?');
        if ($stmt !== false) {
            $stmt->bind_param('ii', $planId, $siteId);
            $stmt->execute();
            $stmt->close();
        }
    }
    Logger::activity('ServicePlanPublicShareEnabled', 'Plan #' . $planId);
    $_SESSION['flash_msg']  = 'Public Order of Service is now shared.';
    $_SESSION['flash_type'] = 'success';
} elseif ($action === 'disable') {
    $stmt = $db->prepare('UPDATE tblServicePlan SET isPublicShared = 0 WHERE planID = ? AND siteID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('ii', $planId, $siteId);
        $stmt->execute();
        $stmt->close();
    }
    Logger::activity('ServicePlanPublicShareDisabled', 'Plan #' . $planId);
    $_SESSION['flash_msg']  = 'Public sharing turned off.';
    $_SESSION['flash_type'] = 'success';
} elseif ($action === 'rotate') {
    if ((int) ($plan['isPublicShared'] ?? 0) === 1) {
        $token = Hymnal::generateShareToken();
        $stmt  = $db->prepare('UPDATE tblServicePlan SET publicToken = ? WHERE planID = ? AND siteID = ?');
        if ($stmt !== false) {
            $stmt->bind_param('sii', $token, $planId, $siteId);
            $stmt->execute();
            $stmt->close();
        }
        Logger::activity('ServicePlanPublicShareRotated', 'Plan #' . $planId);
        $_SESSION['flash_msg']  = 'Share link rotated — the old link/QR code no longer works.';
        $_SESSION['flash_type'] = 'success';
    }
}

header('Location: /service-plans/edit?id=' . $planId);
exit();
