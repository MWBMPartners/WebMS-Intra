<?php
// Path: _core/ApiAuth.php
/**
 * -----------------------------------------------------------------------------
 * Dual-Mode API Authentication 🔐
 * -----------------------------------------------------------------------------
 * Single choke-point that resolves and enforces authentication for every
 * `_apps/{app}/api/{action}` handler, in EITHER of two mutually-exclusive modes:
 *
 *   1. Bearer API key  — `Authorization: Bearer wbms_…` (external integrations).
 *      Scope-gated per resource:verb, tenant-pinned to the key's own site, and
 *      rate-limited per key. CSRF is NOT required (no cookies, no session — the
 *      OWASP-sanctioned exemption for token auth).
 *   2. Session cookie  — the existing logged-in portal user. Reproduces the
 *      current per-handler boilerplate VERBATIM (same order, same error strings
 *      and status codes) so behaviour is provably unchanged: requireAuth →
 *      (optional) requireAdmin → ensureSession → CSRF (writes only).
 *
 * Which mode is used is decided solely by the presence of a `Bearer wbms_…`
 * Authorization header (isBearer()). A non-`wbms_` bearer token falls through
 * to the session path untouched, so this never hijacks a future OAuth scheme.
 *
 * Usage in a handler:
 *   ApiAuth::requireMethod('POST');                 // no-op under /api/v1 dispatch
 *   $body = ApiAuth::requireWrite('events:write');  // returns decoded JSON body
 *   // …handler validation + prepared statements…
 *   Logger::audit('tblEvents', $id, 'create', null, $new); // source auto-resolved
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/323
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class ApiAuth
{
    /** @var array<string,mixed>|null The authenticated tblApiKeys row (bearer mode), else null. */
    private static ?array $keyRow = null;

    /** @var string 'session' | 'apikey' — the resolved auth channel (default session). */
    private static string $source = 'session';

    /** @var array<string,mixed>|null Cached decoded JSON request body (php://input read once). */
    private static ?array $cachedBody = null;

    /** @var bool Whether an auth resolution has completed this request. */
    private static bool $resolved = false;

    /** Fallback per-key rate-limit window (overridden by api.rateLimit.perKey.* settings). */
    private const DEFAULT_MAX_REQUESTS  = 300;
    private const DEFAULT_WINDOW_MINUTES = 5;

    /* ====================================================================== */
    /* Mode detection                                                         */
    /* ====================================================================== */

    /**
     * True when the request carries an `Authorization: Bearer wbms_…` header.
     * Only OUR token prefix counts — any other bearer scheme is ignored here so
     * the session path handles it (future-proofs OAuth without hijacking it).
     *
     * @return bool
     */
    public static function isBearer(): bool
    {
        $auth = (string) (
            $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? ''
        );
        if (str_starts_with($auth, 'Bearer ') === false) {
            return false;
        }
        $token = trim(substr($auth, 7));
        return str_starts_with($token, ApiKey::TOKEN_PREFIX);
    }

    /* ====================================================================== */
    /* Method guard                                                           */
    /* ====================================================================== */

    /**
     * Legacy-method guard — the drop-in replacement for the per-handler
     * `if ($_SERVER['REQUEST_METHOD'] !== 'POST') { ApiResponse::error('POST required', 405); }`.
     * No-op under `/api/v1/*` dispatch, where ApiRouter::dispatchV1() has already
     * validated the verb against the resource shape (so PUT/PATCH/DELETE reach
     * handlers whose legacy guard only accepted POST).
     *
     * @param string ...$methods Allowed HTTP verbs (e.g. 'POST', 'PUT', 'PATCH').
     *
     * @return void Terminates with 405 + Allow header if the verb is not allowed.
     */
    public static function requireMethod(string ...$methods): void
    {
        if (defined('PORTAL_API_V1') === true) {
            return;
        }
        $method  = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $allowed = array_map('strtoupper', $methods);
        if (in_array($method, $allowed, true) === false) {
            header('Allow: ' . implode(', ', $allowed));
            ApiResponse::error('Method not allowed', 405);
        }
    }

    /* ====================================================================== */
    /* Read / write auth resolution                                           */
    /* ====================================================================== */

    /**
     * Resolve + enforce auth for a READ endpoint.
     *   • Bearer: verify key, require $scope, pin to the key's site, rate-limit.
     *   • Session: requireAuth (+ requireAdmin when $sessionNeedsAdmin).
     * Terminates 401/403/429 on failure.
     *
     * @param string $scope             The resource:read scope the key must hold.
     * @param bool   $sessionNeedsAdmin Session-mode reads that are admin-only.
     *
     * @return void
     */
    public static function requireRead(string $scope, bool $sessionNeedsAdmin = false): void
    {
        if (self::isBearer() === true) {
            self::resolveBearer($scope);
            return;
        }
        ApiResponse::requireAuth();
        if ($sessionNeedsAdmin === true) {
            ApiResponse::requireAdmin();
        }
        self::$source   = 'session';
        self::$resolved = true;
    }

    /**
     * Resolve + enforce auth for a WRITE endpoint, and return the decoded JSON body.
     *   • Bearer: verify key, require $scope, pin to the key's site, rate-limit.
     *             NO CSRF (token auth is CSRF-exempt by construction).
     *   • Session: requireAuth (+ requireAdmin when $sessionNeedsAdmin) →
     *             Auth::ensureSession() → CSRF via `X-CSRF-TOKEN` header or
     *             `csrf_token` body field, verbatim to the existing handlers.
     * Terminates 401/403/429 on failure.
     *
     * @param string $scope             The resource:write scope the key must hold.
     * @param bool   $sessionNeedsAdmin Session-mode writes that require admin (default true).
     *
     * @return array<string,mixed> The decoded JSON request body ([] when absent/invalid).
     */
    public static function requireWrite(string $scope, bool $sessionNeedsAdmin = true): array
    {
        if (self::isBearer() === true) {
            self::resolveBearer($scope);
            // 🔓 Bearer requests carry no session cookie — CSRF is meaningless.
            return self::body();
        }

        // 🔒 Session path — reproduce the historical handler boilerplate exactly.
        ApiResponse::requireAuth();
        if ($sessionNeedsAdmin === true) {
            ApiResponse::requireAdmin();
        }
        Auth::ensureSession();

        $body       = self::body();
        $csrfHeader = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $csrfBody   = (string) ($body['csrf_token'] ?? '');
        if (Auth::verifyCsrf($csrfHeader !== '' ? $csrfHeader : $csrfBody) === false) {
            ApiResponse::error('CSRF check failed', 403);
        }

        self::$source   = 'session';
        self::$resolved = true;
        return $body;
    }

    /**
     * 🔑 Bearer resolution (shared by read + write). Verifies the key + scope
     * via the Phase-1 helper, pins the site context to the KEY's site (bearer
     * requests have no session-selected site), then enforces the per-key rate
     * limit. Terminates 401/403/429/500 on failure.
     *
     * @param string $scope Required scope.
     *
     * @return void
     */
    private static function resolveBearer(string $scope): void
    {
        // Phase-1 helper: 401 invalid/expired, 403 missing scope (wildcards handled).
        $row = ApiResponse::requireApiKey([$scope]);
        self::$keyRow   = $row;
        self::$source   = 'apikey';
        self::$resolved = true;

        // 🏢 Tenant pinning (security-critical): the key's siteID is authoritative.
        //    Without a session, Site::id() is only the host-detected default, so
        //    re-point it to the key's site — this makes every Site::id()-scoped
        //    query in the handler tenant-correct. Fail CLOSED (never fall back to
        //    the default site) if the key's site is missing/inactive.
        $keySite = (int) ($row['siteID'] ?? 0);
        if ($keySite > 0 && $keySite !== Site::id()) {
            try {
                Site::forceContext($keySite);
            } catch (\Throwable $e) {
                Logger::errorPlatform('API', 'Error', 'API_SITE_CONTEXT', $e->getMessage(), '');
                ApiResponse::error('API key site is unavailable', 500);
            }
        }

        // ⏱️ Per-key sliding-window rate limit (applies to reads AND writes).
        $keyId  = (int) ($row['keyID'] ?? 0);
        $bucket = 'apikey:' . $keyId;
        $max    = (int) (App::settings('api.rateLimit.perKey.maxRequests') ?? self::DEFAULT_MAX_REQUESTS);
        $windowSecs = ((int) (App::settings('api.rateLimit.perKey.windowMinutes') ?? self::DEFAULT_WINDOW_MINUTES)) * 60;
        if ($max > 0 && $windowSecs > 0) {
            if (RateLimiter::tooMany($bucket, $max, $windowSecs) === true) {
                header('Retry-After: ' . RateLimiter::retryAfter($bucket, $max, $windowSecs));
                ApiResponse::error('Rate limit exceeded', 429);
            }
            RateLimiter::recordHit($bucket, $windowSecs);
        }
    }

    /* ====================================================================== */
    /* Post-resolution accessors (for handlers + Logger::audit attribution)   */
    /* ====================================================================== */

    /**
     * 'apikey' when the request authenticated with a bearer key, else 'session'.
     * Safe to call even when no ApiAuth resolution ran (returns the 'session'
     * default) — Logger::audit relies on this from non-API contexts too.
     *
     * @return string
     */
    public static function source(): string
    {
        return self::$source;
    }

    /**
     * keyID of the authenticated API key, or null in session mode / unresolved.
     *
     * @return int|null
     */
    public static function apiKeyId(): ?int
    {
        if (self::$keyRow === null) {
            return null;
        }
        $id = (int) (self::$keyRow['keyID'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /**
     * The authenticated key's site, or null in session mode. Handlers that need
     * to record ownership on a bearer-created row can use this alongside
     * Site::id() (which forceContext() has already pinned to the same value).
     *
     * @return int|null
     */
    public static function apiKeySiteId(): ?int
    {
        if (self::$keyRow === null) {
            return null;
        }
        $id = (int) (self::$keyRow['siteID'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /**
     * Session user id (session mode), or null in bearer mode. Handlers use this
     * for creator/owner columns: `ApiAuth::actorUserId() ?? 0`.
     *
     * @return int|null
     */
    public static function actorUserId(): ?int
    {
        if (self::$source === 'apikey') {
            return null;
        }
        $uid = (int) ($_SESSION['user_id'] ?? 0);
        return $uid > 0 ? $uid : null;
    }

    /**
     * Parsed JSON request body from php://input, cached (php://input is read
     * exactly once so multipart handlers aren't disturbed). [] when absent or
     * not a JSON object.
     *
     * @return array<string,mixed>
     */
    public static function body(): array
    {
        if (self::$cachedBody !== null) {
            return self::$cachedBody;
        }
        $raw     = (string) file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        self::$cachedBody = is_array($decoded) === true ? $decoded : [];
        return self::$cachedBody;
    }
}
