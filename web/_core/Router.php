<?php
// Path: _core/Router.php
/**
 * -----------------------------------------------------------------------------
 * Portal Front-Controller Router 🎯
 * -----------------------------------------------------------------------------
 * Central request dispatcher for the Portal. Called by the single front
 * controller at public_html/index.php (branch-based deploy lands this file
 * in the server's public_html/ or public_html_dev/ or public_html_beta/
 * as appropriate — there's no per-channel front controller in the repo).
 *
 * Routing flow:
 *   1. Extract clean path from REQUEST_URI (strip query string and slashes)
 *   2. Check hardcoded special routes (login, logout, MS365 callbacks, API, health)
 *   3. Query tblRoutes for a matching routeKey
 *   4. If the route is protected (isProtected=1), enforce authentication
 *   5. Include the target app file (relative to PORTAL_APPS / public_html directory)
 *   6. If no route matches, render the 404 error page
 *
 * Clean URLs are achieved via .htaccess RewriteRule → index.php, so the user
 * sees /expenses/submit rather than /expenses/submit/index.php.
 *
 * @see       https://httpd.apache.org/docs/2.4/mod/mod_rewrite.html
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
        if ($route['isProtected'] === '1') {
            Auth::requireLogin();
        }

        // 📂 Build the full path to the target app file. Try PORTAL_APPS
        //    first (where all app controllers live after #159), then fall
        //    back to public_html/ for the small set of entry-point pages
        //    that intentionally live in the webroot (Swagger UI, openapi.json,
        //    Apache ErrorDocument target, PWA offline fallback).
        $relTarget = str_replace('/', DIRECTORY_SEPARATOR, $route['targetFile']);
        $targetFile = PORTAL_APPS . DIRECTORY_SEPARATOR . $relTarget;
        if (is_readable($targetFile) === false) {
            $fallbackFile = PORTAL_ROOT . DIRECTORY_SEPARATOR . 'public_html'
                          . DIRECTORY_SEPARATOR . $relTarget;
            if (is_readable($fallbackFile) === true) {
                $targetFile = $fallbackFile;
            } else {
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
        }

        // 📌 Define the current app section for navigation highlighting
        // Extract the top-level app name from the path (e.g. "expenses" from "expenses/submit")
        $pathParts = explode('/', $path);
        if (defined('PORTAL_CURRENT_APP') === false) {
            define('PORTAL_CURRENT_APP', $pathParts[0] ?? 'dashboard');
        }

        // 🚪 App enable/disable gate (#255). If the route's owning app is
        //    registered AND disabled, render a friendly 403 explaining why
        //    instead of letting the user hit a half-broken handler.
        //    Routes not owned by any registered app pass through unchanged.
        $owningApp = AppRegistry::appForRoute($path);
        if ($owningApp !== null && AppRegistry::isEnabled((string) $owningApp['slug']) === false) {
            self::renderAppDisabled($owningApp);
            return;
        }

        // 🚀 Include the target app file
        // The app file has access to $db (as $mysqli via global), $SETTINGS, and all
        // core classes via the autoloader. The template engine (header.php / footer.php)
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

        // 🌐 In path-prefix multisite mode, strip the site key from the URL
        // E.g. /cambridge/expenses → expenses (Site::preDetect already stored the prefix)
        $sitePrefix = Site::pathPrefix();
        if ($sitePrefix !== '' && str_starts_with($path, $sitePrefix . '/') === true) {
            $path = substr($path, strlen($sitePrefix) + 1);
        } elseif ($sitePrefix !== '' && $path === $sitePrefix) {
            $path = '';
        }

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

        // 🔑 Google OAuth initiation
        if ($path === 'login/google') {
            Auth::loginGoogle();
            return true; // loginGoogle() calls exit() after redirect
        }

        // 🔑 Google OAuth callback
        if ($path === 'login/google/callback') {
            Auth::callbackGoogle();
            return true; // callbackGoogle() calls exit() after redirect
        }

        // 🔐 WebAuthn authentication API (login flow)
        if ($path === 'login/webauthn') {
            require PORTAL_APPS . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'login' . DIRECTORY_SEPARATOR . 'webauthn.php';
            return true;
        }

        // 🚪 Logout
        if ($path === 'logout') {
            Auth::logout();
            return true; // logout() calls exit() after redirect
        }

        // 📵 Offline fallback page (served by service worker when no network)
        //    Lives in public_html/offline/ rather than _apps/ — the service
        //    worker fetches it via a direct URL, and Apache must be able to
        //    serve it as a static asset under the webroot.
        if ($path === 'offline') {
            require PORTAL_ROOT . DIRECTORY_SEPARATOR . 'public_html'
                  . DIRECTORY_SEPARATOR . 'offline' . DIRECTORY_SEPARATOR . 'index.php';
            return true;
        }

        // 💚 Health check endpoint (used by CI/CD deploy pipeline)
        if ($path === 'health') {
            self::healthCheck();
            return true;
        }

        // 🔌 API routes (prefix: api/) — delegated to ApiRouter
        if (str_starts_with($path, 'api/') === true) {
            ApiRouter::dispatch($path);
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
     * Render an error page (404, 403, 500).
     *
     * @param int $code HTTP status code
     *
     * Render the "app is disabled" page (#255). Friendly message rather
     * than a generic 403 so users understand the feature isn't broken,
     * just turned off.
     *
     * @param array<string, mixed> $app Metadata from AppRegistry.
     */
    public static function renderAppDisabled(array $app): void
    {
        http_response_code(403);
        $pageTitle   = 'App disabled';
        $pageSection = '';
        $breadcrumbs = [];
        $appName     = (string) ($app['name'] ?? 'This app');
        $appDesc     = (string) ($app['description'] ?? '');
        require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
        echo '<div class="portal-error-page text-center py-5">';
        echo '<div class="portal-error-code"><i class="fa-solid fa-power-off text-muted"></i></div>';
        echo '<h1 class="portal-error-title">' . htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') . ' is disabled</h1>';
        if ($appDesc !== '') {
            echo '<p class="text-muted">' . htmlspecialchars($appDesc, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        if (App::isAdmin() === true) {
            echo '<p>An administrator can enable this app at <a href="/admin/apps">/admin/apps</a>.</p>';
        } else {
            echo '<p>Please ask an administrator if you need this feature.</p>';
        }
        echo '<a href="/" class="btn btn-primary"><i class="fa-solid fa-house-chimney me-1"></i> Return to portal</a>';
        echo '</div>';
        require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
    }

    /**
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
            echo '<h1>' . $code . '</h1><p>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p>';
            echo '<a href="/">Return to Portal</a></body></html>';
        }
    }

    /**
     * Health check endpoint for CI/CD monitoring + uptime probes.
     *
     * Returns a JSON document with the portal status, env, version, PHP
     * version, current site ID, and the result of a lightweight DB ping
     * (`SELECT 1`). Returns 200 only when the DB ping succeeds — uptime
     * monitors should treat any non-200 as a failure.
     *
     * Output shape:
     *   {
     *     "status":    "ok" | "degraded",
     *     "env":       "dev" | "beta" | "prod",
     *     "version":   "1.0.0",
     *     "phpVersion":"8.5.5",
     *     "siteID":    1,
     *     "db":        "ok" | "error",
     *     "time":      "2026-05-22T13:00:00+00:00"
     *   }
     *
     * The endpoint never requires auth — it's whitelisted at the top of
     * Router::dispatch(). Designed for CloudFlare / Pingdom / GitHub
     * Actions uptime probes.
     *
     * @return void
     */
    private static function healthCheck(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        // 🩺 Lightweight DB ping — selects literal 1 so any working
        // connection succeeds, no schema dependency.
        $dbStatus = 'error';
        try {
            $db = App::db();
            $result = $db->query('SELECT 1');
            if ($result !== false) {
                $row = $result->fetch_row();
                if ($row !== null && (int) ($row[0] ?? 0) === 1) {
                    $dbStatus = 'ok';
                }
            }
        } catch (\Throwable) {
            // 📝 Don't leak details in the response — Logger captured it.
            $dbStatus = 'error';
        }

        $overall = $dbStatus === 'ok' ? 'ok' : 'degraded';
        http_response_code($dbStatus === 'ok' ? 200 : 503);

        echo json_encode([
            'status'     => $overall,
            'env'        => PORTAL_ENV,
            'version'    => App::version(),
            'phpVersion' => PHP_VERSION,
            'siteID'     => Site::id(),
            'db'         => $dbStatus,
            'time'       => gmdate('c'),
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
        // 🌐 In path-prefix multisite mode, prepend the site key to the URL
        $prefix = Site::pathPrefix();
        if ($prefix !== '') {
            return '/' . $prefix . '/' . ltrim($routeKey, '/');
        }
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
