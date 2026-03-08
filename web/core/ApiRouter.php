<?php
// Path: core/ApiRouter.php
/**
 * -----------------------------------------------------------------------------
 * API Request Router 🔌
 * -----------------------------------------------------------------------------
 * Handles dispatching of API requests (api/{appName}/{action}) to their
 * handler files. Separated from the main Router for single-responsibility.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.1
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/80
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class ApiRouter
{
    /**
     * 🔌 Dispatch an API request to the appropriate handler.
     *
     * API routes follow the pattern: api/{appName}/{action}
     * which maps to: public_html/{appName}/api/{action}.php
     *
     * @param string $path The full API path (e.g. "api/expenses/list")
     * @return void
     */
    public static function dispatch(string $path): void
    {
        // 🔍 Parse the API path into components
        $parts = explode('/', $path);

        // 📋 Need at least: api / appName / action
        if (count($parts) < 3) {
            ApiResponse::error('Invalid API path', 400);
        }

        $appName = $parts[1];
        $action  = $parts[2];

        // 🛡️ Sanitise to prevent directory traversal
        if (preg_match('/^[a-z0-9\-]+$/', $appName) !== 1
            || preg_match('/^[a-z0-9\-]+$/', $action) !== 1) {
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
        global $SETTINGS;
        $enabled = self::resolveSetting($SETTINGS, 'api.' . $appName . '.' . $action . '.enabled');

        if ($enabled !== 'true') {
            ApiResponse::error('This API endpoint is disabled', 403);
        }

        // 🚀 Include the API handler
        require $apiFile;
    }

    /**
     * 📋 Resolve a dot-notation setting key against the $SETTINGS array.
     *
     * @param array  $settings The multidimensional settings array
     * @param string $dotKey   Dot-separated key (e.g. "api.expenses.list.enabled")
     * @return mixed|null The setting value, or null if not found
     */
    public static function resolveSetting(array $settings, string $dotKey): mixed
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
}
