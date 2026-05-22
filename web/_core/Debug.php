<?php
// Path: core/Debug.php
/**
 * -----------------------------------------------------------------------------
 * Debug Mode Panel 🐛
 * -----------------------------------------------------------------------------
 * Provides a developer-facing diagnostic overlay that appears as a collapsible
 * bar fixed to the bottom of the viewport.  The panel displays page load time,
 * peak memory usage, PHP version, environment name, logged database queries,
 * arbitrary debug entries, and a safe snapshot of the current session.
 *
 * Activation requires TWO conditions:
 *   1. The URL query string contains ?debug=true
 *   2. The current user is an Admin or Root Admin (checked at render time)
 *
 * The class is designed to have minimal overhead when debug mode is not active.
 * All data collection methods (logQuery, log) silently no-op unless isEnabled()
 * returns true, so they can remain in production code without penalty.
 *
 * Public methods:
 *   Debug::start()                   record bootstrap start time
 *   Debug::logQuery($sql, $ms)       log a database query and its duration
 *   Debug::log($label, $data)        log arbitrary debug information
 *   Debug::isEnabled()               true if ?debug=true is in URL
 *   Debug::renderPanel()             HTML string for the debug bar (admin only)
 * -----------------------------------------------------------------------------
 * @package    Portal\Core
 * @author     MWBM Partners Ltd (t/a MWservices)
 * @copyright  2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version    0.1.0
 * @link       https://github.com/MWBMPartners/WebMS-Intra
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Debug
{
    // 📊 ---------------------------------------------------------------------------
    // Internal storage
    // -----------------------------------------------------------------------------

    /** @var float|null Microtime recorded at bootstrap start */
    private static ?float $startTime = null;

    /** @var array<int, array{sql: string, durationMs: float}> Logged DB queries */
    private static array $queries = [];

    /** @var array<int, array{label: string, data: mixed}> Arbitrary debug log entries */
    private static array $logs = [];

    // ⏱️ ===========================================================================
    // Public API
    // ===========================================================================

    /**
     * Record the start time at the top of bootstrap.
     *
     * Should be called as early as possible (ideally the first line of
     * bootstrap.php after declare) so the page load timer is accurate.
     *
     * @return void
     */
    public static function start(): void
    {
        // 📌 Capture high-resolution time only once
        if (self::$startTime === null) {
            self::$startTime = microtime(true);
        }
    }

    /**
     * Log a database query for display in the debug panel.
     *
     * Silently ignored if debug mode is not active (?debug=true is absent from
     * the URL), so callers do not need to guard the call themselves.
     *
     * @param string $sql        The SQL statement that was executed
     * @param float  $durationMs Execution time in milliseconds
     *
     * @return void
     */
    public static function logQuery(string $sql, float $durationMs): void
    {
        // 🚫 No-op when debug mode is not active (fast exit)
        if (self::isEnabled() === false) {
            return;
        }

        // 📝 Store the query and its timing
        self::$queries[] = [
            'sql'        => $sql,
            'durationMs' => $durationMs,
        ];
    }

    /**
     * Log an arbitrary piece of debug information.
     *
     * Accepts any data type for $data; it will be formatted with print_r in
     * the debug panel.  Silently ignored when debug mode is not active.
     *
     * @param string $label Short human-readable label for the entry
     * @param mixed  $data  The data to display (scalar, array, object, etc.)
     *
     * @return void
     */
    public static function log(string $label, mixed $data): void
    {
        // 🚫 No-op when debug mode is not active
        if (self::isEnabled() === false) {
            return;
        }

        // 📝 Store the label and data for later rendering
        self::$logs[] = [
            'label' => $label,
            'data'  => $data,
        ];
    }

    /**
     * Check whether the debug URL parameter is present AND the environment
     * permits debug mode.
     *
     * Debug mode is **unconditionally disabled** when `PORTAL_ENV === 'prod'`,
     * regardless of admin status, query parameters, or cookies.  Any attempt
     * to enable it in production is logged once per request via Logger so
     * persistent probing shows up in the activity log.
     *
     * The admin authorization check happens later in renderPanel() — this
     * function only answers "is the URL flag set in an env that allows it?".
     *
     * @return bool True if $_GET['debug'] === 'true' AND env is not prod
     */
    public static function isEnabled(): bool
    {
        if (isset($_GET['debug']) === false || $_GET['debug'] !== 'true') {
            return false;
        }

        // 🛡️ Refuse debug in production, regardless of who's asking.
        if (self::isProd() === true) {
            self::logProdAttemptOnce();
            return false;
        }

        return true;
    }

    /**
     * Returns true if the active environment is production.
     */
    private static function isProd(): bool
    {
        if (defined('PORTAL_ENV') === false) {
            return false;
        }
        return PORTAL_ENV === 'prod';
    }

    /** @var bool Guard so we only log the first prod debug attempt per request */
    private static bool $loggedProdAttempt = false;

    /**
     * Record an attempt to enable debug mode in production. Logged at most
     * once per request so a single page that probes ?debug=true doesn't
     * flood the activity table.
     */
    private static function logProdAttemptOnce(): void
    {
        if (self::$loggedProdAttempt === true) {
            return;
        }
        self::$loggedProdAttempt = true;

        $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '';
        if (str_contains((string) $ip, ',') === true) {
            $ip = trim(explode(',', (string) $ip)[0]);
        }
        $path = $_SERVER['REQUEST_URI'] ?? '';

        // 🪵 Use Logger if available; never throw — this is best-effort audit only.
        if (class_exists('\\Portal\\Core\\Logger', false) === true) {
            try {
                Logger::activity('DebugBlocked', 'Debug mode attempt in prod from ' . $ip . ' at ' . $path);
            } catch (\Throwable) {
                // 🤷 Logging failure must never break the request.
            }
        }
    }

    /**
     * Render the debug panel HTML.
     *
     * Returns an empty string if either:
     *   - debug mode is not active (?debug=true absent), OR
     *   - the current user is not an Admin / Root Admin
     *
     * The panel is a Bootstrap 5 collapse component fixed to the bottom of the
     * viewport.  The collapsed summary bar shows the query count and total query
     * time.  Expanding it reveals full details.
     *
     * NOTE: Sensitive settings values from $SETTINGS are deliberately excluded;
     * only session data and non-secret diagnostic info are displayed.
     *
     * @return string HTML for the debug bar, or empty string
     */
    public static function renderPanel(): string
    {
        // 🚫 Quick exit if debug mode is not active
        if (self::isEnabled() === false) {
            return '';
        }

        // 🛡️ Require admin privileges – this is the authorization gate
        if (App::isAdmin() === false) {
            return '';
        }

        // ⏱️ Calculate page load time
        $loadTime = 0.0;
        if (self::$startTime !== null) {
            $loadTime = (microtime(true) - self::$startTime) * 1000; // convert to ms
        }

        // 📊 Compute query statistics
        $queryCount    = count(self::$queries);
        $totalQueryMs  = 0.0;
        foreach (self::$queries as $q) {
            $totalQueryMs += $q['durationMs'];
        }

        // 💾 Peak memory usage in a human-readable format
        $peakMemory = self::formatBytes(memory_get_peak_usage(true));

        // 🌐 Environment name
        $env = 'unknown';
        if (defined('PORTAL_ENV') === true) {
            $env = PORTAL_ENV;
        }

        // 🔖 PHP version
        $phpVersion = PHP_VERSION;

        // 📝 Build the panel HTML using output buffering for readability
        $html = '';

        // --- Collapsed summary bar ---
        $html .= '<div id="portal-debug-bar" style="'
            . 'position:fixed;bottom:0;left:0;right:0;z-index:99999;'
            . 'font-family:monospace;font-size:12px;'
            . '">';

        // 🎯 Summary toggle button (always visible when debug is active)
        $html .= '<div class="bg-dark text-light px-3 py-1 d-flex justify-content-between align-items-center" '
            . 'style="cursor:pointer;" '
            . 'data-bs-toggle="collapse" data-bs-target="#portal-debug-detail" '
            . 'aria-expanded="false" aria-controls="portal-debug-detail">';

        $html .= '<span>'
            . '<strong>Debug</strong>'
            . ' | '
            . self::esc(sprintf('%.1f ms', $loadTime))
            . ' | '
            . self::esc($peakMemory)
            . ' | '
            . $queryCount . ' quer' . ($queryCount === 1 ? 'y' : 'ies')
            . ' (' . self::esc(sprintf('%.1f ms', $totalQueryMs)) . ')'
            . ' | PHP ' . self::esc($phpVersion)
            . ' | ' . self::esc(strtoupper($env))
            . '</span>';

        $html .= '<span class="badge bg-warning text-dark">Toggle Details</span>';
        $html .= '</div>';

        // --- Expanded detail panel ---
        $html .= '<div class="collapse bg-dark text-light" id="portal-debug-detail" '
            . 'style="max-height:50vh;overflow-y:auto;">';
        $html .= '<div class="p-3">';

        // 📋 Section: Overview
        $html .= '<h6 class="text-warning mb-2">Overview</h6>';
        $html .= '<table class="table table-dark table-sm table-bordered mb-3" style="font-size:11px;">';
        $html .= '<tbody>';
        $html .= '<tr><td>Page Load</td><td>' . self::esc(sprintf('%.2f ms', $loadTime)) . '</td></tr>';
        $html .= '<tr><td>Peak Memory</td><td>' . self::esc($peakMemory) . '</td></tr>';
        $html .= '<tr><td>PHP Version</td><td>' . self::esc($phpVersion) . '</td></tr>';
        $html .= '<tr><td>Environment</td><td>' . self::esc(strtoupper($env)) . '</td></tr>';
        $html .= '<tr><td>Query Count</td><td>' . $queryCount . '</td></tr>';
        $html .= '<tr><td>Total Query Time</td><td>' . self::esc(sprintf('%.2f ms', $totalQueryMs)) . '</td></tr>';
        $html .= '</tbody>';
        $html .= '</table>';

        // 🗄️ Section: Database Queries
        if ($queryCount > 0) {
            $html .= '<h6 class="text-warning mb-2">Database Queries (' . $queryCount . ')</h6>';
            $html .= '<table class="table table-dark table-sm table-bordered mb-3" style="font-size:11px;">';
            $html .= '<thead><tr><th>#</th><th>Duration</th><th>SQL</th></tr></thead>';
            $html .= '<tbody>';
            foreach (self::$queries as $i => $q) {
                $html .= '<tr>';
                $html .= '<td>' . ($i + 1) . '</td>';
                $html .= '<td>' . self::esc(sprintf('%.2f ms', $q['durationMs'])) . '</td>';
                $html .= '<td><code style="color:#7df;">' . self::esc($q['sql']) . '</code></td>';
                $html .= '</tr>';
            }
            $html .= '</tbody>';
            $html .= '</table>';
        }

        // 📝 Section: Debug Logs
        if (count(self::$logs) > 0) {
            $html .= '<h6 class="text-warning mb-2">Debug Logs (' . count(self::$logs) . ')</h6>';
            $html .= '<table class="table table-dark table-sm table-bordered mb-3" style="font-size:11px;">';
            $html .= '<thead><tr><th>#</th><th>Label</th><th>Data</th></tr></thead>';
            $html .= '<tbody>';
            foreach (self::$logs as $i => $entry) {
                $html .= '<tr>';
                $html .= '<td>' . ($i + 1) . '</td>';
                $html .= '<td>' . self::esc($entry['label']) . '</td>';
                $html .= '<td><pre style="margin:0;white-space:pre-wrap;color:#adb5bd;">'
                    . self::esc(self::formatData($entry['data']))
                    . '</pre></td>';
                $html .= '</tr>';
            }
            $html .= '</tbody>';
            $html .= '</table>';
        }

        // 🔑 Section: Session Data (non-sensitive)
        $html .= '<h6 class="text-warning mb-2">Session Data</h6>';
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION) === true) {
            // 🛡️ Filter out potentially sensitive keys before display
            $safeSession = self::filterSensitiveKeys($_SESSION);
            $html .= '<pre style="margin:0;white-space:pre-wrap;color:#adb5bd;font-size:11px;">'
                . self::esc(print_r($safeSession, true))
                . '</pre>';
        } else {
            $html .= '<p class="text-muted" style="font-size:11px;">No active session.</p>';
        }

        $html .= '</div>'; // close .p-3
        $html .= '</div>'; // close #portal-debug-detail
        $html .= '</div>'; // close #portal-debug-bar

        return $html;
    }

    // 🛡️ ===========================================================================
    // Internal helpers
    // ===========================================================================

    /**
     * Format a byte count into a human-readable string (KB, MB, GB).
     *
     * @param int $bytes Raw byte count
     *
     * @return string Formatted string (e.g. "4.25 MB")
     */
    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return sprintf('%.2f KB', $bytes / 1024);
        }
        if ($bytes < 1073741824) {
            return sprintf('%.2f MB', $bytes / 1048576);
        }
        return sprintf('%.2f GB', $bytes / 1073741824);
    }

    /**
     * Format arbitrary data for display in the debug panel.
     *
     * Scalars are returned as strings; arrays and objects are formatted with
     * print_r for readability.
     *
     * @param mixed $data The data to format
     *
     * @return string Human-readable representation
     */
    private static function formatData(mixed $data): string
    {
        if (is_string($data) === true) {
            return $data;
        }
        if (is_bool($data) === true) {
            return $data ? 'true' : 'false';
        }
        if (is_null($data) === true) {
            return 'null';
        }
        if (is_scalar($data) === true) {
            return (string) $data;
        }
        return print_r($data, true);
    }

    /**
     * Filter out potentially sensitive keys from an associative array.
     *
     * Removes keys that commonly hold secrets (tokens, passwords, keys) so
     * they are not inadvertently displayed in the debug panel.  The original
     * array is not modified.
     *
     * @param array $data The array to filter (typically $_SESSION)
     *
     * @return array Filtered copy with sensitive values replaced by "[REDACTED]"
     */
    private static function filterSensitiveKeys(array $data): array
    {
        // 🔒 List of key substrings that indicate sensitive data
        $sensitivePatterns = [
            'password',
            'secret',
            'token',
            'csrf',
            'oauth_state',
            'api_key',
            'apikey',
            'private',
        ];

        $filtered = [];
        foreach ($data as $key => $value) {
            $keyLower = strtolower((string) $key);
            $isSensitive = false;

            foreach ($sensitivePatterns as $pattern) {
                if (str_contains($keyLower, $pattern) === true) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive === true) {
                $filtered[$key] = '[REDACTED]';
            } else {
                // 🔄 Recursively filter nested arrays
                if (is_array($value) === true) {
                    $filtered[$key] = self::filterSensitiveKeys($value);
                } else {
                    $filtered[$key] = $value;
                }
            }
        }

        return $filtered;
    }

    /**
     * Escape a string for safe use inside HTML content.
     *
     * @param string $value Raw string
     *
     * @return string HTML-safe string
     */
    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
