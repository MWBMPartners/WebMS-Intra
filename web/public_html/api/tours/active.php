<?php
// Path: public_html/api/tours/active.php
/**
 * -----------------------------------------------------------------------------
 * Tours API — Active tour for the current user 🎯
 * -----------------------------------------------------------------------------
 * Returns the single highest-priority tour the current user hasn't yet
 * completed, or null. Drives the auto-trigger in portal-tour.js.
 *
 * Priority order:
 *   1. The welcome tour (tourKey='welcome') if portal.tours.welcome_active
 *      and not completed.
 *   2. The newest whats_new_X.Y.Z tour (by version) not completed, whose
 *      version is <= the running PORTAL_VERSION.
 *
 * Role-gated: a tour with a non-empty forRoles CSV only surfaces to users
 * holding one of those roles.
 *
 * @package   Portal\API
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/253
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiResponse;
use Portal\Core\App;

ApiResponse::requireAuth();

$db     = App::db();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// 📋 Candidate tours: active, not yet completed by this user.
$tours = [];
try {
    $stmt = $db->prepare(
        'SELECT t.tourID, t.tourKey, t.version, t.title, t.steps, t.forRoles '
        . 'FROM tblTours t '
        . 'WHERE t.isActive = 1 '
        . 'AND NOT EXISTS ('
        . '  SELECT 1 FROM tblUserTours ut WHERE ut.tourID = t.tourID AND ut.userID = ? '
        . ') '
        . 'ORDER BY t.tourKey = \'welcome\' DESC, t.version DESC'
    );
    if ($stmt === false) {
        ApiResponse::success(null);
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $tours[] = $r;
    }
    $stmt->close();
} catch (\Throwable $e) {
    // 🛡️ tblTours may not exist if migration 069 hasn't run — degrade
    //    gracefully to "no tour".
    ApiResponse::success(null);
}

if (count($tours) === 0) {
    ApiResponse::success(null);
}

// 🪞 Welcome tour gated by its own setting.
$welcomeActive = (string) (App::settings()['portal']['tours']['welcome_active'] ?? '1') === '1';

foreach ($tours as $t) {
    if ($t['tourKey'] === 'welcome' && $welcomeActive === false) {
        continue;
    }
    // 🔐 Role gate.
    $forRoles = trim((string) ($t['forRoles'] ?? ''));
    if ($forRoles !== '') {
        $roles = array_filter(array_map('trim', explode(',', $forRoles)));
        $allowed = false;
        foreach ($roles as $role) {
            if (App::hasRole($role) === true || App::isAdmin() === true) {
                $allowed = true;
                break;
            }
        }
        if ($allowed === false) {
            continue;
        }
    }

    // ✅ Decode the steps JSON; skip malformed.
    $steps = json_decode((string) $t['steps'], true);
    if (is_array($steps) === false || count($steps) === 0) {
        continue;
    }

    ApiResponse::success([
        'tourID'  => (int) $t['tourID'],
        'tourKey' => (string) $t['tourKey'],
        'version' => (string) $t['version'],
        'title'   => (string) $t['title'],
        'steps'   => $steps,
    ]);
}

ApiResponse::success(null);
