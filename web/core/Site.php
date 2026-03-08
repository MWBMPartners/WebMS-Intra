<?php
// Path: core/Site.php
/**
 * -----------------------------------------------------------------------------
 * Multi-Site Context Manager 🌐
 * -----------------------------------------------------------------------------
 * Central point of truth for the active site in a multi-site installation.
 * Supports three detection modes (configurable via multisite.detectionMode):
 *
 *   1. 'subdomain' — detect site from HTTP_HOST (e.g. cambridge.portal.example.com)
 *   2. 'path'      — detect site from URL prefix (e.g. /cambridge/expenses)
 *   3. 'session'   — site selected via navbar switcher, stored in session
 *
 * When multisite is disabled (multisite.enabled = 'false'), all methods
 * transparently return siteID=1 for full backward compatibility.
 *
 * Public API:
 *   Site::init($db, $settings, $preDetectedId)  — initialise from bootstrap
 *   Site::id()                                    — active site ID (int, default 1)
 *   Site::current()                               — full site row array or null
 *   Site::branding($key)                          — site-specific branding value
 *   Site::set($siteID, $db)                       — switch active site (session mode)
 *   Site::allActive($db)                          — all active sites
 *   Site::userSites($userID, $db)                 — sites a user belongs to
 *   Site::userBelongsTo($userID, $siteID, $db)    — access check
 *   Site::userIsSiteAdmin($userID, $db)           — site admin check for current site
 *   Site::userIsSiteRootAdmin($userID, $db)       — site root admin check for current site
 *   Site::detectionMode()                         — current detection mode string
 *   Site::pathPrefix()                            — matched path prefix (path mode only)
 *   Site::isMultisiteEnabled()                    — is multisite feature active?
 *   Site::resolveDefaultSiteForUser($userId, $db) — first assigned site for a user
 *   Site::reset()                                 — clear cached state
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.9.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/45
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Site
{
    /** @var int Active site ID (defaults to 1) */
    private static int $currentSiteID = 1;

    /** @var array|null Cached full site row from tblSites */
    private static ?array $currentSite = null;

    /** @var bool Whether init() has been called */
    private static bool $resolved = false;

    /** @var bool Whether multisite feature is enabled */
    private static bool $enabled = false;

    /** @var string Detection mode: 'subdomain', 'path', or 'session' */
    private static string $mode = 'session';

    /** @var string Matched path prefix (path mode only, e.g. 'cambridge') */
    private static string $pathPrefix = '';

    /** @var array Per-request cache: userSiteRoles[userId:siteId] => array|false */
    private static array $userSiteRoleCache = [];

    /* ====================================================================== */
    /* Initialisation                                                         */
    /* ====================================================================== */

    /**
     * Initialise the Site context. Called from bootstrap.php after DB and
     * settings are loaded.
     *
     * @param \mysqli $db             Database connection
     * @param array   $settings       Loaded settings array
     * @param int     $preDetectedId  Site ID detected during pre-settings phase
     *
     * @return void
     */
    public static function init(\mysqli $db, array $settings, int $preDetectedId): void
    {
        self::$resolved = true;

        // 🔍 Check if multisite is enabled
        $enabledVal = $settings['multisite']['enabled'] ?? 'false';
        self::$enabled = ($enabledVal === 'true');

        if (self::$enabled === false) {
            // 🏠 Single-site mode — always siteID=1
            self::$currentSiteID = 1;
            self::loadSiteRow($db);
            return;
        }

        self::$mode = $settings['multisite']['detectionMode'] ?? 'session';
        self::$currentSiteID = $preDetectedId;

        // 📋 Load the full site row for branding and context
        self::loadSiteRow($db);
    }

    /**
     * Load the current site row from tblSites into cache.
     *
     * @param \mysqli $db Database connection
     *
     * @return void
     */
    private static function loadSiteRow(\mysqli $db): void
    {
        $stmt = $db->prepare(
            'SELECT siteID, siteName, siteKey, hostPattern, logoPath, primaryColor, '
            . 'copyrightOrg, timezone, isActive '
            . 'FROM tblSites WHERE siteID = ? LIMIT 1'
        );
        if ($stmt === false) {
            self::$currentSite = null;
            return;
        }
        $id = self::$currentSiteID;
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        self::$currentSite = ($row !== null && $row !== false) ? $row : null;
    }

    /* ====================================================================== */
    /* Public accessors                                                       */
    /* ====================================================================== */

    /**
     * Get the active site ID.
     *
     * @return int Site ID (defaults to 1)
     */
    public static function id(): int
    {
        return self::$currentSiteID;
    }

    /**
     * Get the full site row array.
     *
     * @return array|null Site row or null if not loaded
     */
    public static function current(): ?array
    {
        return self::$currentSite;
    }

    /**
     * Get a site-specific branding value.
     *
     * @param string $key One of: name, logo, color, copyright, timezone, key
     *
     * @return string|null Value or null if site not loaded
     */
    public static function branding(string $key): ?string
    {
        $site = self::$currentSite;
        if ($site === null) {
            return null;
        }

        if ($key === 'name') {
            return $site['siteName'];
        }
        if ($key === 'logo') {
            return $site['logoPath'];
        }
        if ($key === 'color') {
            return $site['primaryColor'];
        }
        if ($key === 'copyright') {
            return $site['copyrightOrg'];
        }
        if ($key === 'timezone') {
            return $site['timezone'];
        }
        if ($key === 'key') {
            return $site['siteKey'];
        }

        return null;
    }

    /**
     * Get the current detection mode.
     *
     * @return string 'subdomain', 'path', or 'session'
     */
    public static function detectionMode(): string
    {
        return self::$mode;
    }

    /**
     * Get the matched path prefix (path mode only).
     * E.g. 'cambridge' when URL was /cambridge/expenses.
     *
     * @return string Path prefix or empty string
     */
    public static function pathPrefix(): string
    {
        return self::$pathPrefix;
    }

    /**
     * Check if multi-site feature is enabled.
     *
     * @return bool True if multisite.enabled = 'true'
     */
    public static function isMultisiteEnabled(): bool
    {
        return self::$enabled;
    }

    /* ====================================================================== */
    /* Site switching                                                          */
    /* ====================================================================== */

    /**
     * Switch the active site (for session/switcher mode).
     * Validates the site exists and is active before switching.
     *
     * @param int     $siteID Target site ID
     * @param \mysqli $db     Database connection
     *
     * @return bool True if switch succeeded
     */
    public static function set(int $siteID, \mysqli $db): bool
    {
        $stmt = $db->prepare(
            'SELECT siteID FROM tblSites WHERE siteID = ? AND isActive = 1 LIMIT 1'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('i', $siteID);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            return false;
        }

        self::$currentSiteID = $siteID;
        self::$currentSite = null;
        self::loadSiteRow($db);

        // 🔄 Update session
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['active_site_id'] = $siteID;
        }

        return true;
    }

    /* ====================================================================== */
    /* Site queries                                                            */
    /* ====================================================================== */

    /**
     * Get all active sites.
     *
     * @param \mysqli $db Database connection
     *
     * @return array List of site row arrays
     */
    public static function allActive(\mysqli $db): array
    {
        $result = $db->query(
            'SELECT siteID, siteName, siteKey, hostPattern, logoPath, primaryColor, '
            . 'copyrightOrg, timezone, isActive, createdAt '
            . 'FROM tblSites WHERE isActive = 1 ORDER BY siteName ASC'
        );
        if ($result === false) {
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get all sites (active and inactive) for admin management.
     *
     * @param \mysqli $db Database connection
     *
     * @return array List of site row arrays
     */
    public static function all(\mysqli $db): array
    {
        $result = $db->query(
            'SELECT siteID, siteName, siteKey, hostPattern, logoPath, primaryColor, '
            . 'copyrightOrg, timezone, isActive, createdAt, updatedAt '
            . 'FROM tblSites ORDER BY siteID ASC'
        );
        if ($result === false) {
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get sites a specific user is assigned to.
     *
     * @param int     $userID User ID
     * @param \mysqli $db     Database connection
     *
     * @return array List of site rows with user's role flags
     */
    public static function userSites(int $userID, \mysqli $db): array
    {
        $stmt = $db->prepare(
            'SELECT S.siteID, S.siteName, S.siteKey, S.logoPath, S.primaryColor, '
            . 'US.isSiteAdmin, US.isSiteRootAdmin '
            . 'FROM tblUserSites US '
            . 'JOIN tblSites S ON S.siteID = US.siteID '
            . 'WHERE US.userID = ? AND US.isActive = 1 AND S.isActive = 1 '
            . 'ORDER BY S.siteName ASC'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $userID);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * 🔍 Fetch and cache a user's site role flags in a single query.
     * Returns the tblUserSites row plus tblUsers.isRootAdmin, or false
     * if the user has no assignment to the given site.
     *
     * @param int     $userID User ID
     * @param int     $siteID Site ID
     * @param \mysqli $db     Database connection
     *
     * @return array|false Role row or false if not assigned
     */
    private static function getUserSiteRole(int $userID, int $siteID, \mysqli $db): array|false
    {
        $cacheKey = $userID . ':' . $siteID;

        // 📦 Return cached result if available
        if (isset(self::$userSiteRoleCache[$cacheKey]) === true) {
            return self::$userSiteRoleCache[$cacheKey];
        }

        // 🔍 Single query: LEFT JOIN tblUserSites so we always get isRootAdmin
        $stmt = $db->prepare(
            'SELECT U.isRootAdmin, US.isSiteAdmin, US.isSiteRootAdmin, US.isActive AS usActive '
            . 'FROM tblUsers U '
            . 'LEFT JOIN tblUserSites US ON US.userID = U.userID AND US.siteID = ? AND US.isActive = 1 '
            . 'WHERE U.userID = ? LIMIT 1'
        );
        if ($stmt === false) {
            self::$userSiteRoleCache[$cacheKey] = false;
            return false;
        }
        $stmt->bind_param('ii', $siteID, $userID);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            self::$userSiteRoleCache[$cacheKey] = false;
            return false;
        }

        self::$userSiteRoleCache[$cacheKey] = $row;
        return $row;
    }

    /**
     * Check if a user belongs to a specific site.
     *
     * @param int     $userID User ID
     * @param int     $siteID Site ID
     * @param \mysqli $db     Database connection
     *
     * @return bool True if user is assigned to the site
     */
    public static function userBelongsTo(int $userID, int $siteID, \mysqli $db): bool
    {
        $role = self::getUserSiteRole($userID, $siteID, $db);
        if ($role === false) {
            return false;
        }

        // 🛡️ Umbrella admins belong to all sites
        if ($role['isRootAdmin'] === '1') {
            return true;
        }

        // 📋 Has an active tblUserSites row
        return ($role['isSiteAdmin'] !== null);
    }

    /**
     * Check if a user is a Site Admin for the current site.
     *
     * @param int     $userID User ID
     * @param \mysqli $db     Database connection
     *
     * @return bool True if user has isSiteAdmin=1 for current site
     */
    public static function userIsSiteAdmin(int $userID, \mysqli $db): bool
    {
        $role = self::getUserSiteRole($userID, self::$currentSiteID, $db);
        if ($role === false || $role['isSiteAdmin'] === null) {
            return false;
        }
        return ($role['isSiteAdmin'] === '1' || $role['isSiteRootAdmin'] === '1');
    }

    /**
     * Check if a user is a Site Root Admin for the current site.
     *
     * @param int     $userID User ID
     * @param \mysqli $db     Database connection
     *
     * @return bool True if user has isSiteRootAdmin=1 for current site
     */
    public static function userIsSiteRootAdmin(int $userID, \mysqli $db): bool
    {
        $role = self::getUserSiteRole($userID, self::$currentSiteID, $db);
        if ($role === false || $role['isSiteRootAdmin'] === null) {
            return false;
        }
        return ($role['isSiteRootAdmin'] === '1');
    }

    /**
     * Resolve the default site for a user after login.
     * Returns the first active site the user is assigned to, or 1 as fallback.
     *
     * @param int     $userID User ID
     * @param \mysqli $db     Database connection
     *
     * @return int Site ID
     */
    public static function resolveDefaultSiteForUser(int $userID, \mysqli $db): int
    {
        $stmt = $db->prepare(
            'SELECT US.siteID FROM tblUserSites US '
            . 'JOIN tblSites S ON S.siteID = US.siteID '
            . 'WHERE US.userID = ? AND US.isActive = 1 AND S.isActive = 1 '
            . 'ORDER BY US.siteID ASC LIMIT 1'
        );
        if ($stmt === false) {
            return 1;
        }
        $stmt->bind_param('i', $userID);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row !== null) {
            return (int) $row['siteID'];
        }
        return 1;
    }

    /* ====================================================================== */
    /* Pre-settings site detection (called from bootstrap before settings)    */
    /* ====================================================================== */

    /**
     * Detect the active site ID before settings are loaded.
     * Uses a lightweight direct query approach since the full settings
     * array is not yet available.
     *
     * @param \mysqli $db Database connection
     *
     * @return int Detected site ID (defaults to 1)
     */
    public static function preDetect(\mysqli $db): int
    {
        // 🔍 Check if multisite is enabled (direct query, settings not loaded yet)
        $stmt = $db->prepare(
            "SELECT settingValue FROM tblSettings "
            . "WHERE settingKey = 'multisite.enabled' AND siteID IS NULL LIMIT 1"
        );
        if ($stmt === false) {
            return 1;
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row === null || $row['settingValue'] !== 'true') {
            return 1;
        }

        // 🔍 Get detection mode
        $modeStmt = $db->prepare(
            "SELECT settingValue FROM tblSettings "
            . "WHERE settingKey = 'multisite.detectionMode' AND siteID IS NULL LIMIT 1"
        );
        if ($modeStmt === false) {
            return 1;
        }
        $modeStmt->execute();
        $modeRow = $modeStmt->get_result()->fetch_assoc();
        $modeStmt->close();

        $mode = $modeRow['settingValue'] ?? 'session';

        // 🌐 Subdomain detection
        if ($mode === 'subdomain') {
            return self::detectFromSubdomain($db);
        }

        // 📂 Path prefix detection
        if ($mode === 'path') {
            return self::detectFromPath($db);
        }

        // 🔄 Session detection
        if ($mode === 'session') {
            return self::detectFromSession($db);
        }

        return 1;
    }

    /**
     * Detect site from the HTTP_HOST subdomain.
     *
     * @param \mysqli $db Database connection
     *
     * @return int Detected site ID
     */
    private static function detectFromSubdomain(\mysqli $db): int
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '') {
            return 1;
        }

        // 🔍 Look up tblSites by hostPattern match
        $stmt = $db->prepare(
            'SELECT siteID FROM tblSites WHERE hostPattern = ? AND isActive = 1 LIMIT 1'
        );
        if ($stmt === false) {
            return 1;
        }
        $stmt->bind_param('s', $host);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row !== null) {
            return (int) $row['siteID'];
        }
        return 1;
    }

    /**
     * Detect site from the first URL path segment.
     * Stores the matched prefix for Router to strip later.
     *
     * @param \mysqli $db Database connection
     *
     * @return int Detected site ID
     */
    private static function detectFromPath(\mysqli $db): int
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        if ($path === null || $path === false) {
            $path = '/';
        }

        // 🔍 Extract first path segment
        $path = ltrim($path, '/');
        $slashPos = strpos($path, '/');
        $firstSegment = ($slashPos !== false) ? substr($path, 0, $slashPos) : $path;

        if ($firstSegment === '') {
            return 1;
        }

        // 🔍 Look up tblSites by siteKey match
        $stmt = $db->prepare(
            'SELECT siteID FROM tblSites WHERE siteKey = ? AND isActive = 1 LIMIT 1'
        );
        if ($stmt === false) {
            return 1;
        }
        $stmt->bind_param('s', $firstSegment);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row !== null) {
            self::$pathPrefix = $firstSegment;
            return (int) $row['siteID'];
        }
        return 1;
    }

    /**
     * Detect site from the session.
     * Falls back to siteID=1 if no session site is set.
     *
     * @param \mysqli $db Database connection
     *
     * @return int Detected site ID
     */
    private static function detectFromSession(\mysqli $db): int
    {
        if (session_status() === PHP_SESSION_ACTIVE
            && isset($_SESSION['active_site_id']) === true
        ) {
            return (int) $_SESSION['active_site_id'];
        }
        return 1;
    }

    /* ====================================================================== */
    /* URL generation helper                                                   */
    /* ====================================================================== */

    /**
     * Generate a site-aware URL. In 'path' mode, prepends the site key prefix.
     * In other modes, returns the route as-is.
     *
     * @param string $routeKey Route key (e.g. 'expenses/submit')
     *
     * @return string Full URL path
     */
    public static function url(string $routeKey): string
    {
        if (self::$enabled === true && self::$mode === 'path' && self::$pathPrefix !== '') {
            return '/' . self::$pathPrefix . '/' . ltrim($routeKey, '/');
        }
        return '/' . ltrim($routeKey, '/');
    }

    /* ====================================================================== */
    /* Cache management                                                       */
    /* ====================================================================== */

    /**
     * Reset all cached state. Useful after site switch or for testing.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$currentSiteID = 1;
        self::$currentSite = null;
        self::$resolved = false;
        self::$enabled = false;
        self::$mode = 'session';
        self::$pathPrefix = '';
        self::$userSiteRoleCache = [];
    }
}
