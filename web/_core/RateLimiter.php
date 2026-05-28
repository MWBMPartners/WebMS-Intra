<?php
// Path: _core/RateLimiter.php
/**
 * -----------------------------------------------------------------------------
 * Login Rate Limiter 🛡️
 * -----------------------------------------------------------------------------
 * Database-backed rate limiting to prevent brute-force login attempts. Counts
 * recent failed login activity per IP address in tblActivityLogs and blocks
 * requests that exceed the configured threshold.
 *
 * Configuration via tblSettings:
 *   auth.rateLimit.maxAttempts   = 5   (max failures before lockout)
 *   auth.rateLimit.windowMinutes = 15  (time window for counting failures)
 *
 * Usage:
 *   if (RateLimiter::isBlocked($ip)) {
 *       exit('Too many attempts. Try again later.');
 *   }
 *
 * @see       https://owasp.org/www-community/controls/Blocking_Brute_Force_Attacks
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

class RateLimiter
{
    /** @var int Default maximum failed attempts (per IP) before lockout */
    private const DEFAULT_MAX_ATTEMPTS = 5;

    /** @var int Default maximum failed attempts (per username, across all IPs) — higher
     *           threshold than per-IP because legit users can fail across devices.
     *           Catches the "single account, rotating IPs" attack pattern. */
    private const DEFAULT_MAX_ATTEMPTS_BY_USERNAME = 10;

    /** @var int Default time window in minutes for counting failures */
    private const DEFAULT_WINDOW_MINUTES = 15;

    /**
     * Check if a given IP address is currently rate-limited (blocked).
     *
     * @param string|null $ip The IP address to check (null = auto-detect)
     *
     * @return bool True if the IP is blocked due to too many failed attempts
     */
    public static function isBlocked(?string $ip = null): bool
    {
        // 🌐 Auto-detect IP if not provided
        if ($ip === null) {
            $ip = self::getClientIp();
        }

        // 📊 Get configuration from settings
        $maxAttempts   = self::getMaxAttempts();
        $windowMinutes = self::getWindowMinutes();

        // 📝 Count recent failed login attempts for this IP
        $recentFailures = self::countRecentFailures($ip, $windowMinutes);

        return ($recentFailures >= $maxAttempts);
    }

    /**
     * Check if EITHER the IP OR the username (across all IPs) is rate-limited.
     *
     * Use this on login forms — the composite check defends against two
     * different attack patterns simultaneously:
     *
     *   - Same attacker, one IP, many usernames  → caught by the per-IP limit
     *     (isBlocked / DEFAULT_MAX_ATTEMPTS, default 5 / 15min).
     *   - Single targeted account, attacker rotating IPs  → caught by the
     *     per-username limit (DEFAULT_MAX_ATTEMPTS_BY_USERNAME, default 10 /
     *     15min) — this is the case that pure IP-based limiting misses.
     *
     * Also defends multi-user shared NAT scenarios: when ONE user behind a
     * shared NAT fumbles their password, the per-username limit fires on
     * THEIR account only, not on the NAT IP — so the rest of the office
     * stays unblocked.
     *
     * @param string      $username Submitted username / email (case-folded
     *                              internally before matching)
     * @param string|null $ip       Client IP (null = auto-detect)
     *
     * @return bool True if either limit has tripped
     */
    public static function isUserOrIpBlocked(string $username, ?string $ip = null): bool
    {
        if ($ip === null) {
            $ip = self::getClientIp();
        }

        // 🛡️ IP-only check first (cheap, existing behaviour)
        if (self::isBlocked($ip) === true) {
            return true;
        }

        // 🛡️ Username-only check (across all IPs)
        $username = trim(strtolower($username));
        if ($username === '') {
            return false;  // nothing to match; behave like pure IP limiter
        }

        $maxByUsername  = self::getMaxAttemptsByUsername();
        $windowMinutes  = self::getWindowMinutes();
        $userFailures   = self::countRecentFailuresByUsername($username, $windowMinutes);

        return $userFailures >= $maxByUsername;
    }

    /**
     * Get the number of minutes remaining in the lockout period.
     * Returns 0 if the IP is not currently blocked.
     *
     * @param string|null $ip The IP address to check (null = auto-detect)
     *
     * @return int Minutes remaining until the lockout expires
     */
    public static function lockoutRemaining(?string $ip = null): int
    {
        if ($ip === null) {
            $ip = self::getClientIp();
        }

        if (self::isBlocked($ip) === false) {
            return 0;
        }

        // 🔍 Find the timestamp of the oldest failure in the current window
        $windowMinutes = self::getWindowMinutes();
        $db = App::db();

        $stmt = $db->prepare(
            'SELECT MIN(timestamp) AS earliest '
            . 'FROM tblActivityLogs '
            . 'WHERE visitorIP = ? '
            . 'AND activityType = ? '
            . 'AND timestamp >= DATE_SUB(NOW(), INTERVAL ? MINUTE)'
        );

        if ($stmt === false) {
            return $windowMinutes; // Assume full lockout on DB error
        }

        $type = 'LoginFailed';
        $stmt->bind_param('ssi', $ip, $type, $windowMinutes);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row === null || $row['earliest'] === null) {
            return 0;
        }

        // ⏱️ Calculate minutes remaining from the earliest failure + window
        $earliestTime = strtotime($row['earliest']);
        $expiryTime   = $earliestTime + ($windowMinutes * 60);
        $remaining    = (int) ceil(($expiryTime - time()) / 60);

        return max(0, $remaining);
    }

    /**
     * Count recent failed login attempts for a given IP address.
     *
     * @param string $ip            The IP address to check
     * @param int    $windowMinutes Time window in minutes
     *
     * @return int Number of failed attempts in the window
     */
    private static function countRecentFailures(string $ip, int $windowMinutes): int
    {
        $db = App::db();

        // 📝 Query tblActivityLogs for recent 'LoginFailed' entries from this IP
        // See: https://dev.mysql.com/doc/refman/8.0/en/date-and-time-functions.html
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS failCount '
            . 'FROM tblActivityLogs '
            . 'WHERE visitorIP = ? '
            . 'AND activityType = ? '
            . 'AND timestamp >= DATE_SUB(NOW(), INTERVAL ? MINUTE)'
        );

        if ($stmt === false) {
            // 🛡️ On DB error, fail open (allow the attempt) to avoid locking out
            // everyone if the database has an issue
            return 0;
        }

        $type = 'LoginFailed';
        $stmt->bind_param('ssi', $ip, $type, $windowMinutes);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return (int) ($row['failCount'] ?? 0);
    }

    /**
     * Count recent LoginFailed entries whose activityDescription mentions
     * the given username — across ALL source IPs.
     *
     * The login handler logs failures as e.g.
     *   "Failed login attempt for: alice@example.com"
     *   "Incorrect password for: alice@example.com"
     * so a LIKE '%username%' match catches both forms.
     *
     * The username is bound via prepared statement parameter so SQL
     * injection is impossible. To prevent the user's literal '%' / '_'
     * from broadening the LIKE pattern, we escape them before binding.
     */
    private static function countRecentFailuresByUsername(string $username, int $windowMinutes): int
    {
        // 🛡️ Escape SQL LIKE metacharacters
        $pattern = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $username) . '%';

        $db = App::db();
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS failCount '
            . 'FROM tblActivityLogs '
            . 'WHERE activityType = ? '
            . 'AND timestamp >= DATE_SUB(NOW(), INTERVAL ? MINUTE) '
            . 'AND LOWER(activityDescription) LIKE ?'
        );
        if ($stmt === false) {
            return 0;  // fail open on DB error
        }
        $type = 'LoginFailed';
        $stmt->bind_param('sis', $type, $windowMinutes, $pattern);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['failCount'] ?? 0);
    }

    /**
     * Get the configured maximum failed attempts BY USERNAME (across all IPs).
     */
    private static function getMaxAttemptsByUsername(): int
    {
        $value = App::settings('auth.rateLimit.maxAttemptsByUsername');
        if ($value !== null && $value !== '') {
            return (int) $value;
        }
        return self::DEFAULT_MAX_ATTEMPTS_BY_USERNAME;
    }

    /**
     * Get the configured maximum failed attempts before lockout.
     *
     * @return int Maximum attempts
     */
    private static function getMaxAttempts(): int
    {
        $value = App::settings('auth.rateLimit.maxAttempts');

        if ($value !== null && $value !== '') {
            return (int) $value;
        }

        return self::DEFAULT_MAX_ATTEMPTS;
    }

    /**
     * Get the configured time window in minutes for counting failures.
     *
     * @return int Window in minutes
     */
    private static function getWindowMinutes(): int
    {
        $value = App::settings('auth.rateLimit.windowMinutes');

        if ($value !== null && $value !== '') {
            return (int) $value;
        }

        return self::DEFAULT_WINDOW_MINUTES;
    }

    /**
     * Get the client's IP address, respecting proxy headers.
     * Priority: CF-Connecting-IP > X-Forwarded-For > REMOTE_ADDR
     *
     * @see https://developers.cloudflare.com/fundamentals/reference/http-request-headers/
     *
     * @return string Client IP address
     */
    private static function getClientIp(): string
    {
        // ☁️ CloudFlare provides the real client IP in CF-Connecting-IP
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP']) === true) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }

        // 🔀 Standard proxy header (take the first IP, which is the client)
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) === true) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($parts[0]);
        }

        // 🌐 Direct connection
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
