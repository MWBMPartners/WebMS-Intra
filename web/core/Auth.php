<?php
// Path: core/Auth.php
/**
 * -----------------------------------------------------------------------------
 * Portal Core Auth 🔑
 * -----------------------------------------------------------------------------
 * Unified authentication helper – wraps Microsoft 365 OAuth, Google OAuth,
 * local accounts, WebAuthn/PassKeys, and account linking. Provides session
 * management, role checks, CSRF token utilities, and rate limiting integration.
 *
 * Public methods:
 *   Auth::check()                  → bool   – is user logged in?
 *   Auth::requireLogin()           → void   – redirect if not logged in
 *   Auth::ensureSession()          → void   – start session with secure params
 *   Auth::loginMS365()             → void   – begin MS OAuth flow (dormant until configured)
 *   Auth::callbackMS365()          → void   – handle OAuth redirect & verify JWT
 *   Auth::loginGoogle()            → void   – begin Google OAuth flow
 *   Auth::callbackGoogle()         → void   – handle Google OAuth redirect
 *   Auth::loginLocal($id, $pw)     → bool   – authenticate with username/email + password
 *   Auth::validatePassword($pw)    → array  – check password against policy
 *   Auth::isMS365Configured()      → bool   – are MS365 OAuth credentials set?
 *   Auth::isGoogleConfigured()     → bool   – are Google OAuth credentials set?
 *   Auth::linkAccount(...)         → bool   – link an external provider to a user
 *   Auth::unlinkAccount(...)       → bool   – remove a provider link (safety-checked)
 *   Auth::getLinkedAccounts(...)   → array  – list linked providers for a user
 *   Auth::countLoginMethods(...)   → int    – count available login methods
 *   Auth::logout()                 → void   – destroy session
 *   Auth::csrfToken()              → string – get / create CSRF token
 *   Auth::verifyCsrf($tok)         → bool   – compare token constant-time
 *   Auth::curlPost($url, $data)    → ?string – HTTP POST via cURL
 *
 * @see       https://owasp.org/www-community/controls/Session_Management_Cheat_Sheet
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.5.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use RuntimeException;
use SimpleJWT\JWT as SimpleJWT;

class Auth
{
    /* ====================================================================== */
    /* Session helpers                                                        */
    /* ====================================================================== */

    /**
     * Start session with secure cookie parameters if not already active.
     *
     * Sets HttpOnly, Secure, SameSite=Lax flags on the session cookie to
     * prevent XSS theft and CSRF attacks.
     *
     * @see https://owasp.org/www-community/controls/Session_Management_Cheat_Sheet
     *
     * @return void
     */
    public static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // 🔒 Set secure cookie parameters before starting the session
        // See: https://www.php.net/manual/en/function.session-set-cookie-params.php
        session_set_cookie_params([
            'lifetime' => 0,            // Session cookie (expires when browser closes)
            'path'     => '/',
            'domain'   => '',           // Current domain only
            'secure'   => (isset($_SERVER['HTTPS']) === true && $_SERVER['HTTPS'] !== 'off'),
            'httponly'  => true,         // 🛡️ Prevents JavaScript access to session cookie
            'samesite'  => 'Lax',       // 🛡️ Prevents CSRF via cross-origin requests
        ]);

        session_start();
    }

    /**
     * Check if a user is currently authenticated.
     *
     * @return bool True if user is logged in
     */
    public static function check(): bool
    {
        self::ensureSession();
        return isset($_SESSION['user_id']);
    }

    /**
     * Require authentication or redirect to login page.
     * Saves the current URL so the user can be redirected back after login.
     *
     * @return void (terminates with redirect if not authenticated)
     */
    public static function requireLogin(): void
    {
        if (self::check() === false) {
            $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/');
            header('Location: /login?redirect=' . $redirect, true, 302);
            exit();
        }
    }

    /* ====================================================================== */
    /* CSRF protection                                                        */
    /* ====================================================================== */

    /**
     * Get or generate a CSRF token for form protection.
     *
     * @return string The CSRF token (64-character hex string)
     */
    public static function csrfToken(): string
    {
        self::ensureSession();

        if (isset($_SESSION['csrf_token']) === false) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    /**
     * Verify a submitted CSRF token using constant-time comparison.
     * Rotates the token after successful verification to prevent replay.
     *
     * @see https://owasp.org/www-community/attacks/csrf
     *
     * @param string $token The submitted token to verify
     *
     * @return bool True if the token is valid
     */
    public static function verifyCsrf(string $token): bool
    {
        self::ensureSession();

        if (isset($_SESSION['csrf_token']) === false) {
            return false;
        }

        $valid = hash_equals($_SESSION['csrf_token'], $token);

        // 🔄 Rotate the token after successful verification to prevent replay attacks
        if ($valid === true) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $valid;
    }

    /**
     * 🛡️ Validate and sanitize a redirect URL to prevent open redirect attacks.
     * Only allows relative paths on the same origin. Rejects protocol-relative
     * URLs, encoded traversals, backslash tricks, and external hosts.
     *
     * @see https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html
     *
     * @param string $url The raw redirect URL (typically from $_GET['redirect'])
     * @param string $fallback Fallback URL if validation fails (default '/')
     *
     * @return string A safe redirect URL
     */
    public static function safeRedirectUrl(string $url, string $fallback = '/'): string
    {
        // 🔍 Decode the URL to catch encoded bypass attempts (%2F, %5C, etc.)
        $decoded = rawurldecode($url);

        // 🚫 Must start with a single forward slash (relative path)
        if (str_starts_with($decoded, '/') === false) {
            return $fallback;
        }

        // 🚫 Reject protocol-relative URLs (//evil.com)
        if (str_starts_with($decoded, '//') === true) {
            return $fallback;
        }

        // 🚫 Reject backslash sequences that could be interpreted as protocol-relative
        if (str_contains($decoded, '\\') === true) {
            return $fallback;
        }

        // 🚫 Reject URLs containing a scheme (javascript:, data:, etc.)
        if (preg_match('#[a-zA-Z][a-zA-Z0-9+\-.]*:#', $decoded) === 1) {
            return $fallback;
        }

        // 🔍 Parse the URL — if it has a host component, it's an external redirect
        $parsed = parse_url($decoded);
        if ($parsed === false || isset($parsed['host']) === true) {
            return $fallback;
        }

        // ✅ URL is a safe relative path
        return $url;
    }

    /* ====================================================================== */
    /* Microsoft 365 OAuth flow                                               */
    /* ====================================================================== */

    /**
     * Begin the Microsoft 365 OAuth authorization flow.
     * Redirects the user to Microsoft's login page.
     *
     * @see https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow
     *
     * @return void (terminates with redirect)
     */
    public static function loginMS365(): void
    {
        global $SETTINGS;

        if (($SETTINGS['auth']['ms365']['enduser']['clientID'] ?? '') === '') {
            throw new RuntimeException('MS365 client ID not configured.');
        }

        $clientId    = $SETTINGS['auth']['ms365']['enduser']['clientID'];
        $redirectUri = $SETTINGS['auth']['ms365']['enduser']['redirectURI'];
        $tenantId    = $SETTINGS['auth']['ms365']['tenantID'];

        // 🔐 Generate state parameter for CSRF protection of the OAuth flow
        $state = bin2hex(random_bytes(16));
        self::ensureSession();
        $_SESSION['oauth_state'] = $state;

        $authUrl  = 'https://login.microsoftonline.com/' . $tenantId . '/oauth2/v2.0/authorize?';
        $authUrl .= http_build_query([
            'client_id'     => $clientId,
            'response_type' => 'code',
            'redirect_uri'  => $redirectUri,
            'response_mode' => 'query',
            'scope'         => 'openid email profile offline_access User.Read',
            'state'         => $state,
        ]);

        header('Location: ' . $authUrl, true, 302);
        exit();
    }

    /**
     * Handle the OAuth callback from Microsoft after user consent.
     * Exchanges the authorization code for tokens, verifies the JWT ID token,
     * upserts the user in the database, and creates a session.
     *
     * @see https://learn.microsoft.com/en-us/entra/identity-platform/id-tokens
     *
     * @return void (terminates with redirect on success or error message on failure)
     */
    public static function callbackMS365(): void
    {
        self::ensureSession();
        global $SETTINGS, $mysqli;

        /* ----------------------- 0. Rate limit OAuth callbacks ------------- */
        if (RateLimiter::isBlocked() === true) {
            self::oauthError('Too many authentication attempts. Please try again later.', 429);
        }

        /* ----------------------- 1. Validate OAuth state ------------------- */
        if (isset($_GET['state']) === false || hash_equals($_SESSION['oauth_state'] ?? '', $_GET['state'] ?? '') === false) {
            Logger::errorPlatform('Auth', 'Error', 'OAUTH_STATE', 'Invalid OAuth state parameter', '');
            self::oauthError('Invalid OAuth state. Please try signing in again.');
        }

        if (isset($_GET['code']) === false) {
            Logger::errorPlatform('Auth', 'Error', 'OAUTH_CODE', 'Authorization code missing from callback', '');
            self::oauthError('Authorization code missing. Please try signing in again.');
        }

        // 🧹 Clear the OAuth state to prevent replay
        unset($_SESSION['oauth_state']);

        $code         = $_GET['code'];
        $clientId     = $SETTINGS['auth']['ms365']['enduser']['clientID'];
        $clientSecret = $SETTINGS['auth']['ms365']['enduser']['clientSecret'];
        $redirectUri  = $SETTINGS['auth']['ms365']['enduser']['redirectURI'];
        $tenantId     = $SETTINGS['auth']['ms365']['tenantID'];

        /* ----------------------- 2. Exchange code for tokens --------------- */
        $tokenEndpoint = 'https://login.microsoftonline.com/' . $tenantId . '/oauth2/v2.0/token';
        $tokenResp = self::curlPost($tokenEndpoint, [
            'client_id'     => $clientId,
            'scope'         => 'openid email profile offline_access User.Read',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
            'client_secret' => $clientSecret,
        ]);

        if ($tokenResp === null) {
            self::oauthError('Token request failed. Please try signing in again.');
        }

        $tokenData = json_decode($tokenResp, true);
        if (json_last_error() !== JSON_ERROR_NONE || isset($tokenData['id_token']) === false) {
            Logger::errorPlatform('Auth', 'Error', 'TOKEN_RESPONSE', 'Invalid token response from MS365', '');
            self::oauthError('Invalid token response. Please try signing in again.');
        }

        $idToken = $tokenData['id_token'];

        /* ----------------------- 3. Verify ID token via JWKS --------------- */
        // 🔐 Fetch the JWKS keys from Microsoft's discovery endpoint
        // See: https://learn.microsoft.com/en-us/entra/identity-platform/access-tokens#validating-tokens
        $jwksUri  = 'https://login.microsoftonline.com/' . $tenantId . '/discovery/v2.0/keys';
        $jwksJson = file_get_contents($jwksUri);
        if ($jwksJson === false) {
            Logger::errorPlatform('Auth', 'Error', 'JWKS_FETCH', 'Unable to retrieve JWKS from ' . $jwksUri, '');
            self::oauthError('Unable to retrieve signing keys. Please try again later.');
        }

        $jwks = json_decode($jwksJson, true);

        try {
            $payload = SimpleJWT::decode($idToken, $jwks, [
                'aud' => $clientId,
                'iss' => [
                    'https://login.microsoftonline.com/' . $tenantId . '/v2.0',
                    'https://sts.windows.net/' . $tenantId . '/',
                ],
            ]);
        } catch (RuntimeException $ex) {
            Logger::errorPlatform('JWT', 'Error', 'VERIFY_FAIL', $ex->getMessage(), '');
            self::oauthError('Token verification failed. Please try signing in again.');
        }

        /* ----------------------- 4. Extract user info ---------------------- */
        $sub    = $payload['sub'] ?? $payload['oid'] ?? '';
        $email  = strtolower($payload['preferred_username'] ?? ($payload['email'] ?? ''));
        $name   = $payload['name'] ?? '';
        $avatar = $payload['picture'] ?? '';

        if ($email === '') {
            Logger::errorPlatform('Auth', 'Error', 'NO_EMAIL', 'No email in ID token payload', '');
            self::oauthError('Unable to determine user email from token.');
        }

        /* ----------------------- 5. Find or create user -------------------- */
        // 🔍 Check if there's already a linked account for this MS365 sub
        $userId = self::findUserByLink('ms365', $sub !== '' ? $sub : $email, $mysqli);

        if ($userId === null) {
            // 🔍 Try to match by email address (auto-link / legacy upsert)
            $userId = self::findUserByEmail($email, $mysqli);

            if ($userId !== null) {
                // ♻️ Update existing user's name and avatar
                $upd = $mysqli->prepare('UPDATE tblUsers SET fullName = ?, avatarPath = ?, isActive = 1 WHERE userID = ?');
                if ($upd !== false) {
                    $upd->bind_param('ssi', $name, $avatar, $userId);
                    $upd->execute();
                    $upd->close();
                }

                // 🔗 Auto-link the MS365 account
                self::linkAccount($userId, 'ms365', $sub !== '' ? $sub : $email, $email, $mysqli);
            } else {
                // ➕ Create new user + link
                $userId = self::createUser($name, $email, $avatar, $mysqli);
                self::linkAccount($userId, 'ms365', $sub !== '' ? $sub : $email, $email, $mysqli);
            }
        } else {
            // ♻️ Update name/avatar on existing linked user
            $upd = $mysqli->prepare('UPDATE tblUsers SET fullName = ?, avatarPath = ? WHERE userID = ?');
            if ($upd !== false) {
                $upd->bind_param('ssi', $name, $avatar, $userId);
                $upd->execute();
                $upd->close();
            }
        }

        /* ----------------------- 6. Create session & redirect -------------- */
        // 🔄 Regenerate session ID to prevent session fixation attacks
        // See: https://owasp.org/www-community/attacks/Session_fixation
        session_regenerate_id(true);

        $_SESSION['user_id']    = $userId;
        $_SESSION['user_name']  = $name;
        $_SESSION['user_email'] = $email;

        // 🌐 Set active site ID for multi-site context
        self::setSessionSiteId($userId, $mysqli);

        // 📝 Log the successful login
        Logger::activity('LoginMS365', 'User logged in via Microsoft 365');

        $target = self::safeRedirectUrl($_GET['redirect'] ?? '/');
        header('Location: ' . $target, true, 302);
        exit();
    }

    /* ====================================================================== */
    /* Google OAuth flow                                                      */
    /* ====================================================================== */

    /**
     * Begin the Google OAuth authorization flow.
     * Redirects the user to Google's consent screen.
     *
     * @see https://developers.google.com/identity/protocols/oauth2/web-server
     *
     * @return void (terminates with redirect)
     */
    public static function loginGoogle(): void
    {
        global $SETTINGS;

        if (($SETTINGS['auth']['google']['clientID'] ?? '') === '') {
            throw new RuntimeException('Google OAuth client ID not configured.');
        }

        $clientId    = $SETTINGS['auth']['google']['clientID'];
        $redirectUri = $SETTINGS['auth']['google']['redirectURI'];

        // 🔐 Generate state parameter for CSRF protection of the OAuth flow
        $state = bin2hex(random_bytes(16));
        self::ensureSession();
        $_SESSION['oauth_state'] = $state;

        $authUrl  = 'https://accounts.google.com/o/oauth2/v2/auth?';
        $params   = [
            'client_id'     => $clientId,
            'response_type' => 'code',
            'redirect_uri'  => $redirectUri,
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'online',
            'prompt'        => 'select_account',
        ];

        // 🏢 Restrict to a specific Google Workspace domain if configured
        $hd = $SETTINGS['auth']['google']['hostedDomain'] ?? '';
        if ($hd !== '') {
            $params['hd'] = $hd;
        }

        $authUrl .= http_build_query($params);

        header('Location: ' . $authUrl, true, 302);
        exit();
    }

    /**
     * Handle the OAuth callback from Google after user consent.
     * Exchanges the authorization code for tokens, verifies the ID token via
     * Google's JWKS, upserts the user, creates a linked account record, and
     * starts a session.
     *
     * @see https://developers.google.com/identity/protocols/oauth2/openid-connect
     *
     * @return void (terminates with redirect on success or error message on failure)
     */
    public static function callbackGoogle(): void
    {
        self::ensureSession();
        global $SETTINGS, $mysqli;

        /* ----------------------- 0. Rate limit OAuth callbacks ------------- */
        if (RateLimiter::isBlocked() === true) {
            self::oauthError('Too many authentication attempts. Please try again later.', 429);
        }

        /* ----------------------- 1. Validate OAuth state ------------------- */
        if (isset($_GET['state']) === false || hash_equals($_SESSION['oauth_state'] ?? '', $_GET['state'] ?? '') === false) {
            Logger::errorPlatform('Auth', 'Error', 'OAUTH_STATE', 'Invalid Google OAuth state parameter', '');
            self::oauthError('Invalid OAuth state. Please try signing in again.');
        }

        if (isset($_GET['code']) === false) {
            Logger::errorPlatform('Auth', 'Error', 'OAUTH_CODE', 'Google authorization code missing from callback', '');
            self::oauthError('Authorization code missing. Please try signing in again.');
        }

        // 🧹 Clear the OAuth state to prevent replay
        unset($_SESSION['oauth_state']);

        $code         = $_GET['code'];
        $clientId     = $SETTINGS['auth']['google']['clientID'];
        $clientSecret = $SETTINGS['auth']['google']['clientSecret'];
        $redirectUri  = $SETTINGS['auth']['google']['redirectURI'];

        /* ----------------------- 2. Exchange code for tokens --------------- */
        $tokenResp = self::curlPost('https://oauth2.googleapis.com/token', [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        if ($tokenResp === null) {
            self::oauthError('Token request failed. Please try signing in again.');
        }

        $tokenData = json_decode($tokenResp, true);
        if (json_last_error() !== JSON_ERROR_NONE || isset($tokenData['id_token']) === false) {
            Logger::errorPlatform('Auth', 'Error', 'TOKEN_RESPONSE', 'Invalid token response from Google', '');
            self::oauthError('Invalid token response. Please try signing in again.');
        }

        $idToken = $tokenData['id_token'];

        /* ----------------------- 3. Verify ID token via JWKS --------------- */
        // 🔐 Fetch Google's JWKS keys
        // See: https://developers.google.com/identity/protocols/oauth2/openid-connect#validatinganidtoken
        $jwksUri  = 'https://www.googleapis.com/oauth2/v3/certs';
        $jwksJson = file_get_contents($jwksUri);
        if ($jwksJson === false) {
            Logger::errorPlatform('Auth', 'Error', 'JWKS_FETCH', 'Unable to retrieve JWKS from Google', '');
            self::oauthError('Unable to retrieve signing keys. Please try again later.');
        }

        $jwks = json_decode($jwksJson, true);

        try {
            $payload = SimpleJWT::decode($idToken, $jwks, [
                'aud' => $clientId,
                'iss' => ['https://accounts.google.com', 'accounts.google.com'],
            ]);
        } catch (RuntimeException $ex) {
            Logger::errorPlatform('JWT', 'Error', 'VERIFY_FAIL', $ex->getMessage(), '');
            self::oauthError('Token verification failed. Please try signing in again.');
        }

        /* ----------------------- 4. Extract user info ---------------------- */
        $sub    = $payload['sub'] ?? '';
        $email  = strtolower($payload['email'] ?? '');
        $name   = $payload['name'] ?? '';
        $avatar = $payload['picture'] ?? '';

        if ($email === '' || $sub === '') {
            Logger::errorPlatform('Auth', 'Error', 'NO_EMAIL', 'No email/sub in Google ID token', '');
            self::oauthError('Unable to determine user identity from token.');
        }

        // 🏢 Enforce hosted domain restriction if configured
        $requiredHd = $SETTINGS['auth']['google']['hostedDomain'] ?? '';
        if ($requiredHd !== '') {
            $tokenHd = $payload['hd'] ?? '';
            if (strtolower($tokenHd) !== strtolower($requiredHd)) {
                Logger::errorPlatform('Auth', 'Error', 'HD_MISMATCH', 'Google domain mismatch: expected ' . $requiredHd . ', got ' . $tokenHd, '');
                self::oauthError('Your Google account is not from the allowed organisation.');
            }
        }

        /* ----------------------- 5. Find or create user -------------------- */
        // 🔍 Check if there's already a linked account for this Google sub
        $userId = self::findUserByLink('google', $sub, $mysqli);

        if ($userId === null) {
            // 🔍 Try to match by email address (auto-link)
            $userId = self::findUserByEmail($email, $mysqli);

            if ($userId !== null) {
                // ♻️ Update name/avatar from Google profile
                $upd = $mysqli->prepare('UPDATE tblUsers SET fullName = ?, avatarPath = ?, isActive = 1 WHERE userID = ?');
                if ($upd !== false) {
                    $upd->bind_param('ssi', $name, $avatar, $userId);
                    $upd->execute();
                    $upd->close();
                }

                // 🔗 Auto-link the Google account
                self::linkAccount($userId, 'google', $sub, $email, $mysqli);
            } else {
                // ➕ Create new user + link
                $userId = self::createUser($name, $email, $avatar, $mysqli);
                self::linkAccount($userId, 'google', $sub, $email, $mysqli);
            }
        } else {
            // ♻️ Update name/avatar on existing linked user
            $upd = $mysqli->prepare('UPDATE tblUsers SET fullName = ?, avatarPath = ? WHERE userID = ?');
            if ($upd !== false) {
                $upd->bind_param('ssi', $name, $avatar, $userId);
                $upd->execute();
                $upd->close();
            }
        }

        /* ----------------------- 6. Create session & redirect -------------- */
        session_regenerate_id(true);

        $_SESSION['user_id']    = $userId;
        $_SESSION['user_name']  = $name;
        $_SESSION['user_email'] = $email;

        // 🌐 Set active site ID for multi-site context
        self::setSessionSiteId($userId, $mysqli);

        Logger::activity('LoginGoogle', 'User logged in via Google OAuth');

        $target = self::safeRedirectUrl($_GET['redirect'] ?? '/');
        header('Location: ' . $target, true, 302);
        exit();
    }

    /* ====================================================================== */
    /* Local account authentication                                           */
    /* ====================================================================== */

    /**
     * Authenticate a user with username or email and password (local account).
     * Queries tblLocalAccounts (joined with tblUsers) for the password hash,
     * checks rate limiting, verifies the hash, and creates a session.
     *
     * @see https://www.php.net/manual/en/function.password-verify.php
     *
     * @param string $identifier The user's username or email address
     * @param string $password   The plaintext password to verify
     *
     * @return bool True if login succeeded, false otherwise
     */
    public static function loginLocal(string $identifier, string $password): bool
    {
        self::ensureSession();
        global $mysqli;

        $identifier = strtolower(trim($identifier));

        // 🛡️ Check rate limiting before attempting authentication
        if (RateLimiter::isBlocked() === true) {
            Logger::activity('LoginBlocked', 'Rate-limited login attempt for: ' . $identifier);
            return false;
        }

        // 🔍 Look up the user via tblLocalAccounts joined with tblUsers
        // Accepts either a username (tblLocalAccounts.username) or an email
        // (tblUsers.emailAddress) so users can sign in with either one.
        $stmt = $mysqli->prepare(
            'SELECT LA.localID, LA.passwordHash, '
            . 'U.userID, U.fullName, U.emailAddress, U.isActive '
            . 'FROM tblLocalAccounts LA '
            . 'JOIN tblUsers U ON U.userID = LA.userID '
            . 'WHERE LA.username = ? OR U.emailAddress = ? '
            . 'LIMIT 1'
        );

        if ($stmt === false) {
            Logger::errorPlatform('Auth', 'Error', 'DB_PREPARE', 'Failed to prepare login query: ' . $mysqli->error, '');
            return false;
        }

        $stmt->bind_param('ss', $identifier, $identifier);
        $stmt->execute();
        $result = $stmt->get_result();
        $row    = $result->fetch_assoc();
        $stmt->close();

        // 🔐 Verify the user exists, is active, and has a password hash
        if ($row === null || (int) $row['isActive'] !== 1 || ($row['passwordHash'] ?? '') === '') {
            // 📝 Log the failed attempt (used by RateLimiter)
            Logger::activity('LoginFailed', 'Failed login attempt for: ' . $identifier);
            return false;
        }

        // 🔑 Verify password using PHP's built-in password_verify
        // See: https://www.php.net/manual/en/function.password-verify.php
        if (password_verify($password, $row['passwordHash']) === false) {
            Logger::activity('LoginFailed', 'Incorrect password for: ' . $identifier);
            return false;
        }

        // 🔄 Regenerate session ID to prevent session fixation
        // See: https://owasp.org/www-community/attacks/Session_fixation
        session_regenerate_id(true);

        // ✅ Create the user session using data from tblUsers
        $_SESSION['user_id']    = (int) $row['userID'];
        $_SESSION['user_name']  = $row['fullName'];
        $_SESSION['user_email'] = $row['emailAddress'];

        // 🌐 Set active site ID for multi-site context
        self::setSessionSiteId((int) $row['userID'], $mysqli);

        // 🕐 Update lastLogin timestamp on tblLocalAccounts
        $updateStmt = $mysqli->prepare('UPDATE tblLocalAccounts SET lastLogin = NOW() WHERE localID = ?');
        if ($updateStmt !== false) {
            $localId = (int) $row['localID'];
            $updateStmt->bind_param('i', $localId);
            $updateStmt->execute();
            $updateStmt->close();
        }

        // 📝 Log the successful login
        Logger::activity('LoginLocal', 'User logged in with local account');

        return true;
    }

    /* ====================================================================== */
    /* Password policy                                                        */
    /* ====================================================================== */

    /**
     * Validate a password against the configurable policy stored in tblSettings.
     *
     * Settings used (all under auth.password.*):
     *   minLength        – minimum character count (default 8)
     *   requireUppercase – must contain A-Z and a-z (default true)
     *   requireNumber    – must contain 0-9 (default true)
     *   requireSpecial   – must contain a non-alphanumeric char (default true)
     *
     * @param string $password The password to validate
     *
     * @return array{valid: bool, errors: list<string>} Validation result
     */
    public static function validatePassword(string $password): array
    {
        $errors = [];

        $minLength      = (int) (App::settings('auth.password.minLength') ?? '8');
        $requireUpper   = (App::settings('auth.password.requireUppercase') ?? 'true') === 'true';
        $requireNumber  = (App::settings('auth.password.requireNumber') ?? 'true') === 'true';
        $requireSpecial = (App::settings('auth.password.requireSpecial') ?? 'true') === 'true';

        if (strlen($password) < $minLength) {
            $errors[] = 'Password must be at least ' . $minLength . ' characters.';
        }

        if ($requireUpper === true && preg_match('/[A-Z]/', $password) !== 1) {
            $errors[] = 'Password must contain at least one uppercase letter.';
        }

        if ($requireUpper === true && preg_match('/[a-z]/', $password) !== 1) {
            $errors[] = 'Password must contain at least one lowercase letter.';
        }

        if ($requireNumber === true && preg_match('/[0-9]/', $password) !== 1) {
            $errors[] = 'Password must contain at least one number.';
        }

        if ($requireSpecial === true && preg_match('/[^a-zA-Z0-9]/', $password) !== 1) {
            $errors[] = 'Password must contain at least one special character.';
        }

        return ['valid' => (count($errors) === 0), 'errors' => $errors];
    }

    /* ====================================================================== */
    /* SSO configuration checks                                               */
    /* ====================================================================== */

    /**
     * Check whether Microsoft 365 OAuth credentials are configured.
     * Used to conditionally show the MS365 sign-in button on the login page.
     *
     * @return bool True if the MS365 enduser client ID is non-empty
     */
    public static function isMS365Configured(): bool
    {
        return (App::settings('auth.ms365.enduser.clientID') ?? '') !== '';
    }

    /**
     * Check whether Google OAuth credentials are configured.
     * Used to conditionally show the Google sign-in button on the login page.
     *
     * @return bool True if the Google client ID is non-empty
     */
    public static function isGoogleConfigured(): bool
    {
        return (App::settings('auth.google.clientID') ?? '') !== '';
    }

    /* ====================================================================== */
    /* Account linking                                                        */
    /* ====================================================================== */

    /**
     * Link an external identity provider account to a portal user.
     *
     * @param int      $userId       The portal user ID
     * @param string   $provider     Provider name (ms365, google, local)
     * @param string   $providerSub  Provider-specific unique identifier
     * @param string   $providerEmail Email from the provider
     * @param \mysqli  $db           Database connection
     *
     * @return bool True if the link was created successfully
     */
    public static function linkAccount(int $userId, string $provider, string $providerSub, string $providerEmail, \mysqli $db): bool
    {
        $stmt = $db->prepare(
            'INSERT INTO tblLinkedAccounts (userID, provider, providerSub, providerEmail) '
            . 'VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE providerEmail = VALUES(providerEmail)'
        );
        if ($stmt === false) {
            Logger::errorPlatform('Auth', 'Error', 'LINK_PREP', 'Failed to prepare link insert: ' . $db->error, '');
            return false;
        }

        $stmt->bind_param('isss', $userId, $provider, $providerSub, $providerEmail);
        $result = $stmt->execute();
        $stmt->close();

        if ($result === true) {
            Logger::activity('AccountLink', 'Linked ' . $provider . ' account (' . $providerEmail . ')');
        }

        return $result;
    }

    /**
     * Unlink an external provider from a user. Safety check: will refuse to
     * unlink if it would leave the user with zero login methods.
     *
     * @param int      $userId   The portal user ID
     * @param int      $linkID   The tblLinkedAccounts.linkID to remove
     * @param \mysqli  $db       Database connection
     *
     * @return array{success: bool, error: string}
     */
    public static function unlinkAccount(int $userId, int $linkID, \mysqli $db): array
    {
        // 🛡️ Verify the link belongs to this user
        $stmt = $db->prepare('SELECT provider, providerEmail FROM tblLinkedAccounts WHERE linkID = ? AND userID = ?');
        if ($stmt === false) {
            return ['success' => false, 'error' => 'Database error.'];
        }
        $stmt->bind_param('ii', $linkID, $userId);
        $stmt->execute();
        $link = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($link === null) {
            return ['success' => false, 'error' => 'Link not found.'];
        }

        // 🛡️ Safety: ensure the user has at least 2 login methods before unlinking
        $methodCount = self::countLoginMethods($userId, $db);
        if ($methodCount <= 1) {
            return ['success' => false, 'error' => 'Cannot unlink your only login method. Add another method first.'];
        }

        $del = $db->prepare('DELETE FROM tblLinkedAccounts WHERE linkID = ? AND userID = ?');
        if ($del === false) {
            return ['success' => false, 'error' => 'Database error.'];
        }
        $del->bind_param('ii', $linkID, $userId);
        $del->execute();
        $del->close();

        Logger::activity('AccountUnlink', 'Unlinked ' . $link['provider'] . ' account (' . ($link['providerEmail'] ?? '') . ')');

        return ['success' => true, 'error' => ''];
    }

    /**
     * Get all linked external accounts for a user.
     *
     * @param int     $userId The portal user ID
     * @param \mysqli $db     Database connection
     *
     * @return array List of linked account rows
     */
    public static function getLinkedAccounts(int $userId, \mysqli $db): array
    {
        $stmt = $db->prepare(
            'SELECT linkID, provider, providerSub, providerEmail, linkedAt '
            . 'FROM tblLinkedAccounts WHERE userID = ? ORDER BY linkedAt ASC'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * Count the total number of login methods available to a user.
     * Includes: local account (if exists) + linked accounts + WebAuthn credentials.
     *
     * @param int     $userId The portal user ID
     * @param \mysqli $db     Database connection
     *
     * @return int Number of available login methods
     */
    public static function countLoginMethods(int $userId, \mysqli $db): int
    {
        $count = 0;

        // 📝 Check for local account
        $stmt = $db->prepare('SELECT 1 FROM tblLocalAccounts WHERE userID = ? LIMIT 1');
        if ($stmt !== false) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $count++;
            }
            $stmt->close();
        }

        // 🔗 Count linked external accounts
        $stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM tblLinkedAccounts WHERE userID = ?');
        if ($stmt !== false) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $count += (int) ($row['cnt'] ?? 0);
            $stmt->close();
        }

        // 🔐 Count WebAuthn credentials
        $stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM tblWebAuthnCredentials WHERE userID = ?');
        if ($stmt !== false) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $count += (int) ($row['cnt'] ?? 0);
            $stmt->close();
        }

        return $count;
    }

    /* ====================================================================== */
    /* Logout                                                                 */
    /* ====================================================================== */

    /**
     * Destroy the current session and redirect to the home page.
     *
     * @return void (terminates with redirect)
     */
    public static function logout(): void
    {
        self::ensureSession();

        // 📝 Log before destroying the session (need user_id for the log)
        if (isset($_SESSION['user_id']) === true) {
            Logger::activity('Logout', 'User logged out');
        }

        // 🧹 Clear all session data
        $_SESSION = [];

        // 🍪 Delete the session cookie
        if (ini_get('session.use_cookies') === '1') {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();

        header('Location: /', true, 302);
        exit();
    }

    /* ====================================================================== */
    /* Internal utilities                                                     */
    /* ====================================================================== */

    /**
     * Perform an HTTP POST request using cURL.
     *
     * @param string $url  The URL to POST to
     * @param array  $data Key-value pairs for the POST body (form-encoded)
     *
     * @return string|null Response body, or null on failure
     */
    public static function curlPost(string $url, array $data): ?string
    {
        $ch = curl_init($url);

        if ($ch === false) {
            Logger::errorPlatform('cURL', 'Error', 'INIT_FAIL', 'curl_init() returned false for: ' . $url, '');
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => http_build_query($data),
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => 15,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
        ]);

        $resp = curl_exec($ch);

        if ($resp === false) {
            Logger::errorPlatform('cURL', 'Error', (string) curl_errno($ch), curl_error($ch), '');
            curl_close($ch);
            return null;
        }

        curl_close($ch);
        return $resp;
    }

    /**
     * Find a user ID by linked account (provider + providerSub).
     *
     * @param string  $provider    Provider name (ms365, google)
     * @param string  $providerSub Provider-specific unique identifier
     * @param \mysqli $db          Database connection
     *
     * @return int|null User ID if found, null otherwise
     */
    private static function findUserByLink(string $provider, string $providerSub, \mysqli $db): ?int
    {
        $stmt = $db->prepare(
            'SELECT LA.userID FROM tblLinkedAccounts LA '
            . 'JOIN tblUsers U ON U.userID = LA.userID '
            . 'WHERE LA.provider = ? AND LA.providerSub = ? AND U.isActive = 1 '
            . 'LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ss', $provider, $providerSub);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row !== null ? (int) $row['userID'] : null;
    }

    /**
     * Find a user ID by email address.
     *
     * @param string  $email Email address (already lowercased)
     * @param \mysqli $db    Database connection
     *
     * @return int|null User ID if found, null otherwise
     */
    private static function findUserByEmail(string $email, \mysqli $db): ?int
    {
        $stmt = $db->prepare('SELECT userID FROM tblUsers WHERE emailAddress = ? AND isActive = 1 LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row !== null ? (int) $row['userID'] : null;
    }

    /**
     * 🚫 Redirect to login with an error flash message.
     * Used by OAuth callbacks to provide user-friendly error handling instead
     * of bare echo+exit patterns.
     *
     * @param string $message User-facing error message
     * @param int    $httpCode Optional HTTP status code (default 400)
     * @return never
     */
    private static function oauthError(string $message, int $httpCode = 400): never
    {
        self::ensureSession();
        http_response_code($httpCode);
        $_SESSION['flash_msg']  = $message;
        $_SESSION['flash_type'] = 'danger';
        header('Location: /login');
        exit();
    }

    /**
     * Create a new user record.
     *
     * @param string  $name   Full name
     * @param string  $email  Email address
     * @param string  $avatar Avatar URL
     * @param \mysqli $db     Database connection
     *
     * @return int The new user's ID
     *
     * @throws RuntimeException If the insert fails
     */
    private static function createUser(string $name, string $email, string $avatar, \mysqli $db): int
    {
        // 🔄 Wrap multi-table insert in a transaction for atomicity
        App::beginTransaction();

        try {
            $stmt = $db->prepare('INSERT INTO tblUsers (fullName, emailAddress, avatarPath, isActive) VALUES (?, ?, ?, 1)');
            if ($stmt === false) {
                throw new RuntimeException('DB prepare failed: ' . $db->error);
            }
            $stmt->bind_param('sss', $name, $email, $avatar);
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();

            if ($newId <= 0) {
                throw new RuntimeException('User insert returned invalid ID.');
            }

            // 🌐 Assign new user to the current site
            $siteId = Site::id();
            $siteStmt = $db->prepare(
                'INSERT IGNORE INTO tblUserSites (userID, siteID, isSiteAdmin, isSiteRootAdmin) '
                . 'VALUES (?, ?, 0, 0)'
            );
            if ($siteStmt === false) {
                throw new RuntimeException('DB prepare failed for tblUserSites: ' . $db->error);
            }
            $siteStmt->bind_param('ii', $newId, $siteId);
            $siteStmt->execute();
            $siteStmt->close();

            App::commit();

            return $newId;
        } catch (\Throwable $ex) {
            App::rollback();
            throw $ex;
        }
    }

    /**
     * 🌐 Set the active site ID in the session after login.
     * Uses the pre-detected site if the user belongs to it, otherwise
     * falls back to the user's first assigned site.
     *
     * @param int     $userId User ID
     * @param \mysqli $db     Database connection
     *
     * @return void
     */
    /**
     * 🌐 Public wrapper for setSessionSiteId — for use outside Auth class
     * (e.g. WebAuthn login handler in separate file).
     *
     * @param int    $userId User ID
     * @param \mysqli $db    Database connection
     *
     * @return void
     */
    public static function initSessionSite(int $userId, \mysqli $db): void
    {
        self::setSessionSiteId($userId, $db);
    }

    private static function setSessionSiteId(int $userId, \mysqli $db): void
    {
        $currentSiteId = Site::id();

        // 🔍 Check if the user belongs to the current (pre-detected) site
        if (Site::userBelongsTo($userId, $currentSiteId, $db) === true) {
            $_SESSION['active_site_id'] = $currentSiteId;
            return;
        }

        // 🔄 Fall back to the user's first assigned site
        $defaultSite = Site::resolveDefaultSiteForUser($userId, $db);
        $_SESSION['active_site_id'] = $defaultSite;
    }
}
