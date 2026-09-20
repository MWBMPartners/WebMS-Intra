<?php
// Path: _core/ApiResponse.php
/**
 * -----------------------------------------------------------------------------
 * JSON API Response Builder 🔌
 * -----------------------------------------------------------------------------
 * Provides standardised JSON response formatting for all API endpoints. Ensures
 * consistent envelope structure, proper HTTP headers, and security controls.
 *
 * All API responses follow the envelope format:
 *   { "status": "ok|error", "data": ..., "meta": { "timestamp": ..., "version": ... } }
 *
 * Usage:
 *   ApiResponse::success($data);           // 200 OK with data
 *   ApiResponse::success($data, 201);      // 201 Created
 *   ApiResponse::error('Not found', 404);  // 404 error
 *   ApiResponse::requireAuth();            // enforce authentication
 *   ApiResponse::requireEnabled('api.expenses.list.enabled'); // check setting
 *
 * @see       https://jsonapi.org/format/
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

class ApiResponse
{
    /**
     * The `meta` block that goes on every response, success or error.
     *
     * Always carries the time the response was produced and the portal
     * version. It additionally carries `csrfToken` in one specific case: the
     * request was a write, it was authenticated by the caller's login session
     * rather than an API key, and its anti-forgery check passed.
     *
     * Why that extra field exists: the portal replaces the anti-forgery token
     * every time it accepts one, so the same token can never be used twice.
     * A caller that writes more than once without reloading the page would
     * otherwise be holding a dead token and be refused on its second write.
     * Handing the replacement back closes that gap. The browsable API
     * documentation page at /api-docs uses it, and so can any in-page script.
     *
     * The token is not a secret from this caller — they already hold the
     * session it belongs to, and it is useless to anyone else. Requests
     * authenticated with an API key never get this field, because they carry
     * no session for a token to protect.
     *
     * @return array<string,mixed>
     */
    private static function meta(): array
    {
        $meta = [
            'timestamp' => gmdate('c'),
            'version'   => App::version(),
        ];

        // `false` for the second argument means "do not try to autoload it" —
        // handlers that never went through ApiAuth must not drag it in.
        if (class_exists(ApiAuth::class, false) === true) {
            $rotated = ApiAuth::rotatedCsrf();
            if ($rotated !== null) {
                $meta['csrfToken'] = $rotated;
            }
        }

        return $meta;
    }

    /**
     * Send a JSON success response and terminate.
     *
     * @param mixed $data Payload data to include in the response
     * @param int   $code HTTP status code (default 200)
     *
     * @return never This method always terminates execution
     */
    public static function success(mixed $data = null, int $code = 200): never
    {
        // 📤 Set response headers
        http_response_code($code);
        self::setJsonHeaders();

        // 📦 Build the standard response envelope
        $response = [
            'status' => 'ok',
            'data'   => $data,
            'meta'   => self::meta(),
        ];

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    /**
     * Send a JSON error response and terminate.
     *
     * @param string      $message Human-readable error message
     * @param int         $code    HTTP status code (default 400)
     * @param string|null $detail  Additional detail (only shown in debug mode)
     *
     * @return never This method always terminates execution
     */
    public static function error(string $message, int $code = 400, ?string $detail = null): never
    {
        // 📤 Set response headers
        http_response_code($code);
        self::setJsonHeaders();

        // 📦 Build the error response envelope
        $response = [
            'status'  => 'error',
            'message' => $message,
            'meta'    => self::meta(),
        ];

        // 🐛 Include detail only when debug mode is active (admin-only)
        if ($detail !== null && App::isDebug() === true) {
            $response['detail'] = $detail;
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    /**
     * Require authentication for the API endpoint.
     * Sends a 401 error if the user is not authenticated.
     *
     * @return void (terminates with 401 if not authenticated)
     */
    public static function requireAuth(): void
    {
        if (Auth::check() === false) {
            self::error('Authentication required', 401);
        }
    }

    /**
     * Require that a specific API endpoint is enabled in tblSettings.
     * Sends a 403 error if the setting is not set to 'true'.
     *
     * @param string $settingKey Dot-notation setting key (e.g. 'api.expenses.list.enabled')
     *
     * @return void (terminates with 403 if disabled)
     */
    public static function requireEnabled(string $settingKey): void
    {
        $value = App::settings($settingKey);

        if ($value !== 'true') {
            self::error('This API endpoint is disabled', 403);
        }
    }

    /**
     * Require that the current user has admin privileges.
     * Sends a 403 error if the user is not an admin.
     *
     * @return void (terminates with 403 if not admin)
     */
    public static function requireAdmin(): void
    {
        self::requireAuth();

        if (App::isAdmin() === false) {
            self::error('Admin access required', 403);
        }
    }

    /**
     * Require a valid API key on the request (#323 Phase 1). Bypasses the
     * session entirely — reads the bearer token from the Authorization
     * header, verifies via ApiKey::findByPlaintext, optionally checks scopes.
     *
     * Use INSTEAD of requireAuth in API handlers that want to be reachable
     * by external integrations. Use ALONGSIDE if a handler can accept either
     * (try requireApiKey first, fall back to requireAuth).
     *
     * Returns the key row so the handler can read keyID + siteID + scopes
     * without re-querying.
     *
     * @param array<int, string> $requiredScopes Empty = any active key OK
     *
     * @return array<string, mixed> The matched key row
     */
    public static function requireApiKey(array $requiredScopes = []): array
    {
        $auth = (string) (
            $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? ''
        );
        if (str_starts_with($auth, 'Bearer ') === false) {
            self::error('Bearer token required in Authorization header', 401);
        }
        $token = trim(substr($auth, 7));
        $row   = ApiKey::findByPlaintext($token);
        if ($row === null) {
            self::error('Invalid or expired API key', 401);
        }
        foreach ($requiredScopes as $scope) {
            if (ApiKey::hasScope($row, (string) $scope) === false) {
                self::error('API key missing required scope: ' . (string) $scope, 403);
            }
        }
        return $row;
    }

    /**
     * Filter sensitive fields from data before sending via API.
     * Removes any keys that are marked as sensitive or contain personal data.
     *
     * @param array        $data           The data array to filter
     * @param array<string> $sensitiveKeys Keys to remove (e.g. ['passwordHash', 'emailAddress'])
     *
     * @return array Filtered data with sensitive fields removed
     */
    public static function filterSensitive(array $data, array $sensitiveKeys = []): array
    {
        // 📌 Default sensitive field names that should never appear in API output
        $defaultSensitive = [
            'passwordHash',
            'keyHash',       // tblApiKeys (#323 Phase 1) — never leak the hashed token
            'clientSecret',
            'secretKey',
            'encKey',
        ];

        $allSensitive = array_merge($defaultSensitive, $sensitiveKeys);

        foreach ($allSensitive as $key) {
            if (array_key_exists($key, $data) === true) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    /**
     * Set standard JSON API response headers.
     * Includes security headers to prevent content sniffing and clickjacking.
     *
     * @return void
     */
    private static function setJsonHeaders(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        // 🛡️ Codex catch-up C1 (20 September 2026): WHAT WAS WRONG —
        //    Auth::sendOfflineCopyHeaders() adds "Vary: *" to stop an old
        //    service worker storing a personal answer, but only for a
        //    request whose SESSION identifies a person. A bearer-key (API
        //    key) request has a session too — the front controller starts
        //    one for every request — but nothing in it identifies anyone,
        //    so that code takes its "nothing to protect" branch and this
        //    class's JSON answers never got "Vary: *" at all. On an
        //    installation whose site key happens to be exactly "assets",
        //    an address like "/assets/api/v1/users" is read by the portal
        //    as the API (the prefix is stripped before routing) but by an
        //    OLD, already-installed service worker as an ordinary
        //    "/assets/..." address — outside that worker's "/api/"
        //    exclusion — so it could store the personal JSON answer in the
        //    visitor's browser. THE FIX: every answer through this class
        //    carries "Vary: *" as well, closing that one gap regardless of
        //    which worker version is installed. WHAT THIS COSTS: nothing —
        //    the Cache-Control line above already says "no-store", so no
        //    correctly-behaving cache was ever going to keep this answer;
        //    this header exists only for the OLD worker that does not read
        //    Cache-Control before deciding to store a response. WHAT THIS
        //    CANNOT DO: it only covers JSON sent THROUGH this class. A
        //    handler that builds its own response without calling
        //    ApiResponse (push/api/* is POST-only, which the old worker
        //    never stored anyway; worship/api/*, api/translate.php,
        //    api/ai-improve.php all answer only a signed-in SESSION,
        //    which Auth.php's own "Vary: *" already covers) is untouched
        //    by this line.
        header('Vary: *', false);

        // 🔒 CORS headers - restrict to same origin by default
        // Individual API endpoints can override if cross-origin access is needed
        header('X-Frame-Options: DENY');
    }
}
