<?php
// Path: core/Gatekeeper.php
/**
 * -----------------------------------------------------------------------------
 * Channel Gatekeeper 🚧
 * -----------------------------------------------------------------------------
 * Restricts access to non-production channels (alpha / beta deploys served from
 * the server's public_html_dev/ or public_html_beta/ directories) based on user
 * roles. By default only Admins (`isAdmin=1`) or Root Admins (`isRootAdmin=1`)
 * are allowed. Additional roles can be configured via tblSettings:
 *     portal.devAccessRoles     = "Admin,Developer"
 * (comma-separated roleKey values from tblRoles)
 *
 * The primary channel is 'dev'. The legacy 'alpha' and 'beta' channels are
 * retained in VALID_CHANNELS for backwards compatibility.
 * -----------------------------------------------------------------------------
 * Usage from a front controller running under PORTAL_ENV=dev or =beta:
 *     require_once '../_core/bootstrap.php';
 *     \Portal\Core\Gatekeeper::enforce('dev');
 *     \Portal\Core\Router::dispatch($mysqli);
 *
 * The single front controller lives at public_html/index.php in the repo;
 * branch-based deploy maps it to the appropriate server-side directory.
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use RuntimeException;

class Gatekeeper
{
    /**
     * Channels that can bypass individual route auth (they handle it at the gate).
     */
    private const VALID_CHANNELS = ['alpha', 'beta', 'dev'];

    /**
     * Exact paths that must remain accessible without passing through the gate
     * (login flow, OAuth callbacks, health check).
     */
    private const OPEN_PATHS = [
        '',
        'login',
        'login/ms365',
        'login/ms365/callback',
        'logout',
        'health',
        'forgot-password',
        'forgot-password/save',
        'reset-password',
        'reset-password/save',
    ];

    /**
     * Path prefixes that bypass the gate (e.g. help centre, public API docs).
     */
    private const OPEN_PREFIXES = [
        'help',
    ];

    /**
     * Enforce gate for given channel (dev|alpha|beta).
     */
    public static function enforce(string $channel): void
    {
        if (in_array($channel, self::VALID_CHANNELS, true) === false) {
            throw new RuntimeException('Invalid channel for gatekeeper.');
        }

        // 🔓 Allow login, auth, and public routes through without restriction
        $path = Router::extractPath();
        if (in_array($path, self::OPEN_PATHS, true) === true) {
            return;
        }
        foreach (self::OPEN_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/') === true) {
                return;
            }
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

        if ($row !== null && ($row['isRootAdmin'] === '1' || $row['isAdmin'] === '1')) {
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

        // 3. 🚫 Deny -- log and show the 403 error page
        Logger::activity('GatekeeperDenied', 'User denied to ' . $channel . ' area', $userId);
        Router::renderError(403);
        exit();
    }

    /**
     * Check tblUserRoles against allowed roleKey list.
     */
    private static function userHasRole(int $userId, array $roleKeys): bool
    {
        if (count($roleKeys) === 0) {
            return false;
        }

        global $mysqli;

        $placeholders = implode(',', array_fill(0, count($roleKeys), '?'));
        $types        = 'i' . str_repeat('s', count($roleKeys));
        $sql          = 'SELECT 1 FROM tblUserRoles UR '
                      . 'JOIN tblRoles R ON R.roleID = UR.roleID '
                      . 'WHERE UR.userID = ? AND R.roleKey IN (' . $placeholders . ') LIMIT 1';

        $stmt = $mysqli->prepare($sql);
        if ($stmt === false) {
            return false;
        }

        // Build bind params dynamically
        $bindParams = array_merge([$types], [$userId], $roleKeys);
        $ref        = [];
        foreach ($bindParams as $k => $_unused) {
            $ref[$k] = &$bindParams[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $ref);

        $stmt->execute();
        $stmt->store_result();
        $has = $stmt->num_rows > 0;
        $stmt->close();

        return $has;
    }
}
