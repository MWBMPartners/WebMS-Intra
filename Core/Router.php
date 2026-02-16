<?php
// Path: core/Router.php
/**
 * -----------------------------------------------------------------------------
 * Portal Front-Controller Router 🎯
 * -----------------------------------------------------------------------------
 * Central request dispatcher for the Portal. Called by the front-controller
 * entry points (public_html/index.php, public_html_dev/index.php).
 *
 * Routing flow:
 *   1. Extract clean path from REQUEST_URI (strip query string and slashes)
 *   2. Check hardcoded special routes (login, logout, MS365 callbacks, API, health)
 *   3. Query tblRoutes for a matching routeKey
 *   4. If the route is protected (isProtected=1), enforce authentication
 *   5. Include the target app file (relative to PORTAL_APPS directory)
 *   6. If no route matches, render the 404 error page
 *
 * Clean URLs are achieved via .htaccess RewriteRule → index.php, so the user
 * sees /expenses/submit rather than /expenses/submit/index.php.
 *
 * @see       https://httpd.apache.org/docs/2.4/mod/mod_rewrite.html
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

class Router
{
    /**
     * Main dispatch method - called by front controllers.
     *
     * @param mysqli $db Active MySQLi database connection
     *
     * @return void
     */
    public static function dispatch(mysqli $db): void
    {
        // 🌐 Extract and normalise the request path
        $path = self::extractPath();

        // 📌 Store the current path for use by templates (e.g. nav highlighting)
        if (defined('PORTAL_CURRENT_ROUTE') === false) {
            define('PORTAL_CURRENT_ROUTE', $path);
        }

        // 🔀 Check hardcoded special routes first (these bypass tblRoutes)
        if (self::handleSpecialRoutes($path) === true) {
            return; // Special route handled and exited
        }

        // 🔍 Look up route in the database
        $route = self::findRoute($db, $path);

        if ($route === null) {
            // 🚫 No matching route found - show 404
            self::renderError(404);
            return;
        }

        // 🛡️ If the route is protected, enforce authentication
        if ($route['isProtected'] == '1') {
            Auth::requireLogin();
        }

        // 📂 Build the full path to the target app file
        $targetFile = PORTAL_APPS . DIRECTORY_SEPARATOR
                    . str_replace('/', DIRECTORY_SEPARATOR, $route['targetFile']);

        // ✅ Verify the target file exists and is readable
        if (is_readable($targetFile) === false) {
            Logger::errorPlatform(
                'Router',
                'Error',
                '404',
                'Route target file not found',
                'routeKey=' . $path . ' targetFile=' . $route['targetFile']
            );
            self::renderError(404);
            return;
        }

        // 📌 Define the current app section for navigation highlighting
        // Extract the top-level app name from the path (e.g. "expenses" from "expenses/submit")
        $pathParts = explode('/', $path);
        if (defined('PORTAL_CURRENT_APP') === false) {
            define('PORTAL_CURRENT_APP', $pathParts[0] ?? 'dashboard');
        }

        // 🚀 Include the target app file
        // The app file has access to $db (as $mysqli via global), $SETTINGS, and all
        // core classes via the autoloader. The template system (header.php / footer.php)
        // is used by the app file to render consistent page chrome.
        require $targetFile;
    }

    /**
     * Extract and normalise the clean URL path from the current request.
     *
     * Examples:
     *   /expenses/submit?foo=bar  →  "expenses/submit"
     *   /                         →  ""
     *   /login/                   →  "login"
     *   /api/expenses/list        →  "api/expenses/list"
     *
     * @return string Normalised path (lowercase, no leading/trailing slashes)
     */
    public static function extractPath(): string
    {
        // 📝 Get the raw URI and strip the query string
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        if ($path === null || $path === false) {
            $path = '/';
        }

        // 🔤 Normalise: lowercase, trim slashes, collapse double slashes
        $path = strtolower(trim($path, '/'));
        $path = (string) preg_replace('#/{2,}#', '/', $path);

        return $path;
    }

    /**
     * Handle hardcoded special routes that bypass tblRoutes.
     * Returns true if a special route was matched (and the response was sent).
     *
     * @param string $path The normalised request path
     *
     * @return bool True if a special route was handled
     */
    private static function handleSpecialRoutes(string $path): bool
    {
        // 🏠 Empty path → dashboard (default home page)
        if ($path === '') {
            // Redirect to dashboard route which will be handled via tblRoutes
            // This avoids duplicating the dashboard logic here
            $path = 'dashboard';
            // Fall through to database route lookup by returning false
            // and letting dispatch() continue with the 'dashboard' routeKey
            if (defined('PORTAL_CURRENT_ROUTE') === true) {
                // Redefine won't work, so we handle this in dispatch
            }
            // Actually, update the path and let it fall through to findRoute
            $_SERVER['REQUEST_URI'] = '/dashboard';
            return false;
        }

        // 🔑 Microsoft 365 OAuth initiation
        if ($path === 'login/ms365') {
            Auth::loginMS365();
            return true; // loginMS365() calls exit() after redirect
        }

        // 🔑 Microsoft 365 OAuth callback
        if ($path === 'login/ms365/callback') {
            Auth::callbackMS365();
            return true; // callbackMS365() calls exit() after redirect
        }

        // 🚪 Logout
        if ($path === 'logout') {
            Auth::logout();
            return true; // logout() calls exit() after redirect
        }

        // 💚 Health check endpoint (used by CI/CD deploy pipeline)
        if ($path === 'health') {
            self::healthCheck();
            return true;
        }

        // 🔌 API routes (prefix: api/)
        if (str_starts_with($path, 'api/') === true) {
            self::dispatchApi($path);
            return true;
        }

        return false;
    }

    /**
     * Look up a route in tblRoutes by its routeKey.
     *
     * @param mysqli $db   Database connection
     * @param string $path The normalised request path (used as routeKey)
     *
     * @return array{routeKey: string, targetFile: string, isProtected: string}|null
     */
    private static function findRoute(mysqli $db, string $path): ?array
    {
        // 📝 Use a prepared statement to safely query the route
        $stmt = $db->prepare(
            'SELECT routeKey, targetFile, isProtected FROM tblRoutes WHERE routeKey = ? LIMIT 1'
        );
        if ($stmt === false) {
            Logger::errorPlatform('MySQL', 'Error', 'ROUTE_PREP_FAIL', $db->error, '');
            return null;
        }

        $stmt->bind_param('s', $path);
        $stmt->execute();
        $result = $stmt->get_result();
        $route  = $result->fetch_assoc();
        $stmt->close();

        // 🔍 Return null if no matching route was found
        if ($route === null || $route === false) {
            return null;
        }

        return $route;
    }

    /**
     * Dispatch an API request to the appropriate handler.
     *
     * API routes follow the pattern: api/{appName}/{action}
     * which maps to: apps/{appName}/api/{action}.php
     *
     * @param string $path The full API path (e.g. "api/expenses/list")
     *
     * @return void
     */
    private static function dispatchApi(string $path): void
    {
        // 🔍 Parse the API path into components
        // Expected format: api/{appName}/{action}
        $parts = explode('/', $path);

        // Need at least: api / appName / action
        if (count($parts) < 3) {
            ApiResponse::error('Invalid API path', 400);
        }

        $appName = $parts[1];
        $action  = $parts[2];

        // 🛡️ Sanitise to prevent directory traversal
        // Only allow alphanumeric characters and hyphens in app/action names
        if (preg_match('/^[a-z0-9\-]+$/', $appName) !== 1 || preg_match('/^[a-z0-9\-]+$/', $action) !== 1) {
            ApiResponse::error('Invalid API path characters', 400);
        }

        // 📂 Build the path to the API handler file
        $apiFile = PORTAL_APPS . DIRECTORY_SEPARATOR
                 . $appName . DIRECTORY_SEPARATOR
                 . 'api' . DIRECTORY_SEPARATOR
                 . $action . '.php';

        // ✅ Check the file exists
        if (is_readable($apiFile) === false) {
            ApiResponse::error('API endpoint not found', 404);
        }

        // 🔍 Check if the API endpoint is enabled in settings
        // Setting key format: api.{appName}.{action}.enabled
        global $SETTINGS;
        $enabledKey = 'api.' . $appName . '.' . $action . '.enabled';
        $enabled    = self::resolveSettingDot($SETTINGS, $enabledKey);

        if ($enabled !== 'true') {
            ApiResponse::error('This API endpoint is disabled', 403);
        }

        // 🚀 Include the API handler
        require $apiFile;
    }

    /**
     * Resolve a dot-notation setting key against the $SETTINGS array.
     *
     * @param array  $settings The multidimensional settings array
     * @param string $dotKey   Dot-separated key (e.g. "api.expenses.list.enabled")
     *
     * @return mixed|null The setting value, or null if not found
     */
    private static function resolveSettingDot(array $settings, string $dotKey): mixed
    {
        $parts = explode('.', $dotKey);
        $node  = $settings;
        foreach ($parts as $part) {
            if (is_array($node) === false || array_key_exists($part, $node) === false) {
                return null;
            }
            $node = $node[$part];
        }
        return $node;
    }

    /**
     * Render an error page (404, 403, 500).
     *
     * @param int $code HTTP status code
     *
     * @return void
     */
    public static function renderError(int $code): void
    {
        http_response_code($code);

        // 📄 Try to load the template error page
        $templateFile = PORTAL_CORE . DIRECTORY_SEPARATOR
                      . 'templates' . DIRECTORY_SEPARATOR
                      . 'error-' . $code . '.php';

        if (is_readable($templateFile) === true) {
            require $templateFile;
        } else {
            // 📝 Minimal fallback if template doesn't exist yet
            $titles = [
                403 => 'Access Denied',
                404 => 'Page Not Found',
                500 => 'Server Error',
            ];
            $title = $titles[$code] ?? 'Error ' . $code;
            echo '<!doctype html><html><head><title>' . $title . '</title></head>';
            echo '<body style="font-family:system-ui;text-align:center;padding:4rem;">';
            echo '<h1>' . $code . '</h1><p>' . htmlspecialchars($title) . '</p>';
            echo '<a href="/">Return to Portal</a></body></html>';
        }
    }

    /**
     * Health check endpoint for CI/CD monitoring.
     * Returns a simple JSON response indicating the portal is operational.
     *
     * @return void
     */
    private static function healthCheck(): void
    {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'  => 'ok',
            'env'     => PORTAL_ENV,
            'version' => App::version(),
            'time'    => gmdate('c'),
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    /**
     * Generate a clean URL for a given route key.
     * Useful for building links in templates without hardcoding paths.
     *
     * @param string $routeKey The route key (e.g. "expenses/submit")
     *
     * @return string The clean URL (e.g. "/expenses/submit")
     */
    public static function url(string $routeKey): string
    {
        return '/' . ltrim($routeKey, '/');
    }

    /**
     * Get the current normalised request path.
     *
     * @return string The current path
     */
    public static function currentPath(): string
    {
        if (defined('PORTAL_CURRENT_ROUTE') === true) {
            return PORTAL_CURRENT_ROUTE;
        }
        return self::extractPath();
    }

    /**
     * Check if the current request path matches a given pattern.
     * Supports wildcard (*) at the end for prefix matching.
     *
     * Examples:
     *   Router::is('expenses/*')   → true for /expenses/submit, /expenses/approve
     *   Router::is('dashboard')    → true only for /dashboard
     *
     * @param string $pattern Route pattern to match against
     *
     * @return bool True if the current path matches the pattern
     */
    public static function is(string $pattern): bool
    {
        $current = self::currentPath();

        // 🔍 Wildcard prefix match
        if (str_ends_with($pattern, '*') === true) {
            $prefix = rtrim($pattern, '*');
            return str_starts_with($current, $prefix);
        }

        // 🔍 Exact match
        return $current === $pattern;
    }
}
