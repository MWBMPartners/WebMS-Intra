<?php
// Path: core/Gatekeeper.php
/**
 * -----------------------------------------------------------------------------
 * Alpha/Beta Gatekeeper 🚧
 * -----------------------------------------------------------------------------
 * Restricts access to alpha_html and beta_html directories based on user roles.
 * By default only Admins (`isAdmin=1`) or Root Admins (`isRootAdmin=1`) are
 * allowed.  Additional roles can be configured via tblSettings key:
 *     portal.alphaAccessRoles   = "Admin,Developer"
 *     portal.betaAccessRoles    = "Admin,Tester"
 * (comma-separated roleKey values from tblRoles)
 * -----------------------------------------------------------------------------
 * Usage in alpha_html/index.php or beta_html/index.php:
 *     require '../../core/bootstrap.php';
 *     \Portal\Core\Gatekeeper::enforce('alpha'); // or 'beta'
 *     \Portal\Core\Router::dispatch($mysqli);
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use RuntimeException;

class Gatekeeper
{
    /**
     * Enforce gate for given channel (alpha|beta).
     */
    public static function enforce(string $channel): void
    {
        if ($channel !== 'alpha' && $channel !== 'beta') {
            throw new RuntimeException('Invalid channel for gatekeeper.');
        }

        Auth::ensureSession();
        if (Auth::check() === false) {
            Auth::requireLogin(); // redirects
        }

        global $mysqli, $SETTINGS;
        $userId = $_SESSION['user_id'];

        // 1. Root admin always allowed
        $stmt = $mysqli->prepare('SELECT isRootAdmin, isAdmin FROM tblUsers WHERE userID = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row && ($row['isRootAdmin'] == '1' || $row['isAdmin'] == '1')) {
            return; // permit
        }

        // 2. Additional roles from settings
        $rolesCsv = $SETTINGS['portal'][ $channel . 'AccessRoles' ] ?? '';
        if ($rolesCsv !== '') {
            $allowedRoles = array_map('trim', explode(',', $rolesCsv));
            if (self::userHasRole($userId, $allowedRoles) === true) {
                return;
            }
        }

        // 3. Deny
        Logger::activity('GatekeeperDenied', 'User denied to ' . $channel . ' area', $userId);
        http_response_code(403);
        echo 'Access to the ' . ucfirst($channel) . ' environment is restricted.';
        exit();
    }

    /**
     * Check tblUserRoles against allowed roleKey list.
     */
    private static function userHasRole(int $userId, array $roleKeys): bool
    {
        if (empty($roleKeys)) { return false; }
        global $mysqli;
        $placeholders = implode(',', array_fill(0, count($roleKeys), '?'));
        $types = str_repeat('s', count($roleKeys));
        $sql = 'SELECT 1 FROM tblUserRoles UR JOIN tblRoles R ON R.roleID = UR.roleID WHERE UR.userID = ? AND R.roleKey IN (' . $placeholders . ') LIMIT 1';
        $stmt = $mysqli->prepare($sql);
        if ($stmt === false) { return false; }
        // Build bind params dynamically
        $bindParams = array_merge(['i'], [ $userId ], $roleKeys);
        $ref = [];
        foreach ($bindParams as $k => $v) { $ref[$k] = &$bindParams[$k]; }
        call_user_func_array([$stmt, 'bind_param'], $ref);
        $stmt->execute();
        $stmt->store_result();
        $has = $stmt->num_rows > 0;
        $stmt->close();
        return $has;
    }
}
