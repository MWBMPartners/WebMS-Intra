<?php
// Path: core/App.php
/**
 * -----------------------------------------------------------------------------
 * Application Registry 🏛️
 * -----------------------------------------------------------------------------
 * Lightweight service registry providing static access to core resources:
 * database connection, settings, current user, version info, and environment.
 *
 * This class allows new code to avoid the `global $mysqli, $SETTINGS` pattern
 * while remaining backward-compatible with existing code that uses globals.
 *
 * Usage:
 *   App::db()                     → mysqli instance
 *   App::settings('site.name')    → resolved setting value via dot-notation
 *   App::user()                   → current authenticated user array or null
 *   App::isDebug()                → true if debug mode active AND user is admin
 *   App::version()                → portal version string (e.g. "0.1.0")
 *   App::env()                    → environment string (dev|beta|prod)
 *   App::siteId()                 → active site ID (delegates to Site::id())
 *   App::isAdmin()                → 4-tier admin check (umbrella/site root/site/legacy)
 *   App::isSiteAdmin()            → site-level admin check for current site
 *   App::isSiteRootAdmin()        → site root admin check for current site
 *   App::isUmbrellaAdmin()        → umbrella (global root) admin check
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

class App
{
    /** @var mysqli|null Cached database connection */
    private static ?mysqli $db = null;

    /** @var array Cached settings array (multidimensional) */
    private static array $settings = [];

    /** @var array|null Cached current user data (lazy-loaded from DB) */
    private static ?array $currentUser = null;

    /** @var bool Whether the user data has been loaded (to distinguish null = "not loaded" vs "not logged in") */
    private static bool $userLoaded = false;

    /** @var string Portal version number */
    private static string $version = '0.1.0';

    /**
     * Initialise the App registry. Called from bootstrap.php after DB and settings are ready.
     *
     * @param mysqli $db       Active database connection
     * @param array  $settings Multidimensional settings array (from tblSettings dot-notation)
     *
     * @return void
     */
    public static function init(mysqli $db, array $settings): void
    {
        self::$db       = $db;
        self::$settings = $settings;

        // 📌 Load version from settings if available, otherwise use default
        $settingsVersion = self::settings('portal.version');
        if ($settingsVersion !== null && $settingsVersion !== '') {
            self::$version = $settingsVersion;
        }
    }

    /**
     * Get the database connection.
     * Falls back to the global $mysqli if init() hasn't been called yet.
     *
     * @return mysqli
     */
    public static function db(): mysqli
    {
        if (self::$db !== null) {
            return self::$db;
        }

        // 🔄 Fallback to global for backward compatibility
        global $mysqli;
        if ($mysqli instanceof mysqli) {
            self::$db = $mysqli;
            return self::$db;
        }

        throw new \RuntimeException('Database connection not available. Ensure bootstrap.php has been loaded.');
    }

    /**
     * Retrieve a setting value using dot-notation.
     *
     * Examples:
     *   App::settings('site.name')          → "Cambridge Mill Road..."
     *   App::settings('auth.ms365.tenantID') → "abc-123-..."
     *   App::settings()                      → entire settings array
     *
     * @param string $dotKey Dot-separated key, or empty string for all settings
     *
     * @return mixed Setting value, or null if the key does not exist
     */
    public static function settings(string $dotKey = ''): mixed
    {
        // 📋 Return the entire settings array if no key specified
        if ($dotKey === '') {
            return self::$settings;
        }

        // 🔍 Walk the dot-notation path through the multidimensional array
        $parts = explode('.', $dotKey);
        $node  = self::$settings;

        foreach ($parts as $part) {
            if (is_array($node) === false || array_key_exists($part, $node) === false) {
                return null;
            }
            $node = $node[$part];
        }

        return $node;
    }

    /**
     * Get the current authenticated user's data.
     * Lazy-loads from the database using the session user_id.
     * Returns null if no user is logged in.
     *
     * @return array|null User row from tblUsers, or null
     */
    public static function user(): ?array
    {
        // 🔄 Return cached user if already loaded
        if (self::$userLoaded === true) {
            return self::$currentUser;
        }

        self::$userLoaded = true;

        // 🔍 Check if a user is logged in via session
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        if (isset($_SESSION['user_id']) === false) {
            return null;
        }

        $userId = (int) $_SESSION['user_id'];
        $db     = self::db();

        // 📝 Fetch full user record from the database, including site-level role flags
        $siteId = Site::id();
        $stmt = $db->prepare(
            'SELECT U.userID, U.fullName, U.emailAddress, U.phoneNumber, U.avatarPath, '
            . 'U.isActive, U.isAdmin, U.isRootAdmin, U.createdAt, '
            . 'COALESCE(US.isSiteAdmin, 0) AS isSiteAdmin, '
            . 'COALESCE(US.isSiteRootAdmin, 0) AS isSiteRootAdmin '
            . 'FROM tblUsers U '
            . 'LEFT JOIN tblUserSites US ON US.userID = U.userID AND US.siteID = ? AND US.isActive = 1 '
            . 'WHERE U.userID = ? LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }

        $stmt->bind_param('ii', $siteId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user   = $result->fetch_assoc();
        $stmt->close();

        if ($user === null || $user === false) {
            return null;
        }

        self::$currentUser = $user;
        return self::$currentUser;
    }

    /**
     * Check if debug mode is active.
     * Debug mode requires BOTH:
     *   1. The URL parameter ?debug=true
     *   2. The current user is an Admin or Root Admin
     *
     * @return bool True if debug mode is active
     */
    public static function isDebug(): bool
    {
        // 🔍 Check URL parameter first (quick exit if not present)
        if (isset($_GET['debug']) === false || $_GET['debug'] !== 'true') {
            return false;
        }

        // 🛡️ Require admin privileges for debug access
        $user = self::user();
        if ($user === null) {
            return false;
        }

        return ($user['isAdmin'] === '1' || $user['isRootAdmin'] === '1');
    }

    /**
     * Get the portal version string.
     *
     * @return string Version number (e.g. "0.1.0")
     */
    public static function version(): string
    {
        return self::$version;
    }

    /**
     * Get the current environment (dev, beta, prod).
     *
     * @return string Environment identifier
     */
    public static function env(): string
    {
        if (defined('PORTAL_ENV') === true) {
            return PORTAL_ENV;
        }
        return 'dev';
    }

    /**
     * Check if the current user has a specific role.
     *
     * @param string $roleKey The role key to check (e.g. "admin", "treasurer")
     *
     * @return bool True if the user has the specified role
     */
    public static function hasRole(string $roleKey): bool
    {
        $user = self::user();
        if ($user === null) {
            return false;
        }

        // 🔑 Root admin has all roles implicitly
        if ($user['isRootAdmin'] === '1') {
            return true;
        }

        // 🔍 Check tblUserRoles for the specific role
        $db   = self::db();
        $stmt = $db->prepare(
            'SELECT 1 FROM tblUserRoles UR '
            . 'JOIN tblRoles R ON R.roleID = UR.roleID '
            . 'WHERE UR.userID = ? AND R.roleKey = ? LIMIT 1'
        );
        if ($stmt === false) {
            return false;
        }

        $userId = (int) $user['userID'];
        $stmt->bind_param('is', $userId, $roleKey);
        $stmt->execute();
        $stmt->store_result();
        $has = $stmt->num_rows > 0;
        $stmt->close();

        return $has;
    }

    /**
     * 🌐 Get the active site ID. Delegates to Site::id().
     *
     * @return int Active site ID (defaults to 1)
     */
    public static function siteId(): int
    {
        return Site::id();
    }

    /**
     * Check if the current user is at least an Admin (4-tier hierarchy).
     * Returns true if user is ANY of:
     *   - Umbrella Admin (tblUsers.isRootAdmin=1)
     *   - Site Root Admin (tblUserSites.isSiteRootAdmin=1 for current site)
     *   - Site Admin (tblUserSites.isSiteAdmin=1 for current site)
     *   - Legacy Admin (tblUsers.isAdmin=1)
     *
     * @return bool True if the user has admin-level access
     */
    public static function isAdmin(): bool
    {
        $user = self::user();
        if ($user === null) {
            return false;
        }
        return ($user['isAdmin'] === '1'
            || $user['isRootAdmin'] === '1'
            || (string) ($user['isSiteAdmin'] ?? '0') === '1'
            || (string) ($user['isSiteRootAdmin'] ?? '0') === '1');
    }

    /**
     * Check if the current user is a Root/Global (Umbrella) Admin.
     *
     * @return bool True if the user is Root Admin
     */
    public static function isRootAdmin(): bool
    {
        $user = self::user();
        if ($user === null) {
            return false;
        }
        return ($user['isRootAdmin'] === '1');
    }

    /**
     * 🌐 Check if the current user is an Umbrella Admin (alias for isRootAdmin).
     *
     * @return bool True if the user is a global root admin
     */
    public static function isUmbrellaAdmin(): bool
    {
        return self::isRootAdmin();
    }

    /**
     * 🌐 Check if the current user is a Site Admin for the current site.
     * Includes Site Root Admins (who are also site admins by implication).
     *
     * @return bool True if the user has site admin access
     */
    public static function isSiteAdmin(): bool
    {
        $user = self::user();
        if ($user === null) {
            return false;
        }

        // 🛡️ Umbrella admins are implicitly site admins everywhere
        if ($user['isRootAdmin'] === '1') {
            return true;
        }

        return ((string) ($user['isSiteAdmin'] ?? '0') === '1'
            || (string) ($user['isSiteRootAdmin'] ?? '0') === '1');
    }

    /**
     * 🌐 Check if the current user is a Site Root Admin for the current site.
     *
     * @return bool True if the user has site root admin access
     */
    public static function isSiteRootAdmin(): bool
    {
        $user = self::user();
        if ($user === null) {
            return false;
        }

        // 🛡️ Umbrella admins are implicitly site root admins everywhere
        if ($user['isRootAdmin'] === '1') {
            return true;
        }

        return ((string) ($user['isSiteRootAdmin'] ?? '0') === '1');
    }

    /**
     * Reset the cached user data (useful after role changes or for testing).
     *
     * @return void
     */
    public static function resetUser(): void
    {
        self::$currentUser = null;
        self::$userLoaded  = false;
    }
}
