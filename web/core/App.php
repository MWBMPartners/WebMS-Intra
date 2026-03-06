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
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   MIT
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

        // 📝 Fetch full user record from the database
        $stmt = $db->prepare(
            'SELECT userID, fullName, emailAddress, phoneNumber, avatarPath, '
            . 'isActive, isAdmin, isRootAdmin, createdAt '
            . 'FROM tblUsers WHERE userID = ? LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }

        $stmt->bind_param('i', $userId);
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
     * Check if the current user is at least an Admin.
     *
     * @return bool True if the user is Admin or Root Admin
     */
    public static function isAdmin(): bool
    {
        $user = self::user();
        if ($user === null) {
            return false;
        }
        return ($user['isAdmin'] === '1' || $user['isRootAdmin'] === '1');
    }

    /**
     * Check if the current user is a Root/Global Admin.
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
