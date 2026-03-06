<?php
// Path: core/Auth.php
/**
 * -----------------------------------------------------------------------------
 * Portal Core Auth 🔑
 * -----------------------------------------------------------------------------
 * Unified authentication helper – wraps Microsoft 365 OAuth, local accounts,
 * and (future) Google OAuth. Provides session management, role checks, CSRF
 * token utilities, and rate limiting integration.
 *
 * Public methods:
 *   Auth::check()                  → bool   – is user logged in?
 *   Auth::requireLogin()           → void   – redirect if not logged in
 *   Auth::ensureSession()          → void   – start session with secure params
 *   Auth::loginMS365()             → void   – begin MS OAuth flow (dormant until configured)
 *   Auth::callbackMS365()          → void   – handle OAuth redirect & verify JWT
 *   Auth::loginLocal($id, $pw)     → bool   – authenticate with username/email + password
 *   Auth::validatePassword($pw)    → array  – check password against policy
 *   Auth::isMS365Configured()      → bool   – are MS365 OAuth credentials set?
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
 * @version   0.1.0
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

        /* ----------------------- 1. Validate OAuth state ------------------- */
        if (isset($_GET['state']) === false || hash_equals($_SESSION['oauth_state'] ?? '', $_GET['state'] ?? '') === false) {
            Logger::errorPlatform('Auth', 'Error', 'OAUTH_STATE', 'Invalid OAuth state parameter', '');
            echo 'Invalid OAuth state.';
            exit();
        }

        if (isset($_GET['code']) === false) {
            Logger::errorPlatform('Auth', 'Error', 'OAUTH_CODE', 'Authorization code missing from callback', '');
            echo 'Authorization code missing.';
            exit();
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
            echo 'Token request failed.';
            exit();
        }

        $tokenData = json_decode($tokenResp, true);
        if (json_last_error() !== JSON_ERROR_NONE || isset($tokenData['id_token']) === false) {
            Logger::errorPlatform('Auth', 'Error', 'TOKEN_RESPONSE', 'Invalid token response from MS365', '');
            echo 'Invalid token response.';
            exit();
        }

        $idToken = $tokenData['id_token'];

        /* ----------------------- 3. Verify ID token via JWKS --------------- */
        // 🔐 Fetch the JWKS keys from Microsoft's discovery endpoint
        // See: https://learn.microsoft.com/en-us/entra/identity-platform/access-tokens#validating-tokens
        $jwksUri  = 'https://login.microsoftonline.com/' . $tenantId . '/discovery/v2.0/keys';
        $jwksJson = file_get_contents($jwksUri);
        if ($jwksJson === false) {
            Logger::errorPlatform('Auth', 'Error', 'JWKS_FETCH', 'Unable to retrieve JWKS from ' . $jwksUri, '');
            echo 'Unable to retrieve signing keys.';
            exit();
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
            echo 'Token verification failed.';
            exit();
        }

        /* ----------------------- 4. Upsert user in database ---------------- */
        $email  = strtolower($payload['preferred_username'] ?? ($payload['email'] ?? ''));
        $name   = $payload['name'] ?? '';
        $avatar = $payload['picture'] ?? '';

        if ($email === '') {
            Logger::errorPlatform('Auth', 'Error', 'NO_EMAIL', 'No email in ID token payload', '');
            echo 'Unable to determine user email from token.';
            exit();
        }

        $userId = self::upsertUserMS($mysqli, $email, $name, $avatar);

        /* ----------------------- 5. Create session & redirect -------------- */
        // 🔄 Regenerate session ID to prevent session fixation attacks
        // See: https://owasp.org/www-community/attacks/Session_fixation
        session_regenerate_id(true);

        $_SESSION['user_id']    = $userId;
        $_SESSION['user_name']  = $name;
        $_SESSION['user_email'] = $email;

        // 📝 Log the successful login
        Logger::activity('LoginMS365', 'User logged in via Microsoft 365');

        $target = $_GET['redirect'] ?? '/';
        if (str_starts_with($target, '/') === false || str_starts_with($target, '//') === true) {
            $target = '/';
        }
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
    /* SSO configuration check                                                */
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
     * Upsert a user record for Microsoft 365 login.
     * Creates a new user if they don't exist, or updates their name/avatar if they do.
     *
     * @param \mysqli $db     Database connection
     * @param string  $email  User's email address (already lowercased)
     * @param string  $name   User's display name from MS365
     * @param string  $avatar User's avatar URL from MS365
     *
     * @return int The user's ID (new or existing)
     *
     * @throws RuntimeException If the database query fails
     */
    private static function upsertUserMS(\mysqli $db, string $email, string $name, string $avatar): int
    {
        // 🔍 Check if the user already exists
        $stmt = $db->prepare('SELECT userID FROM tblUsers WHERE emailAddress = ? LIMIT 1');
        if ($stmt === false) {
            throw new RuntimeException('DB prepare failed: ' . $db->error);
        }

        $stmt->bind_param('s', $email);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($row = $res->fetch_assoc()) {
            // ♻️ Update existing user's name and avatar
            $userId = (int) $row['userID'];
            $stmt->close();

            $stmt = $db->prepare('UPDATE tblUsers SET fullName = ?, avatarPath = ?, isActive = 1 WHERE userID = ?');
            if ($stmt === false) {
                throw new RuntimeException('DB prepare failed: ' . $db->error);
            }
            $stmt->bind_param('ssi', $name, $avatar, $userId);
            $stmt->execute();
            $stmt->close();

            return $userId;
        }
        $stmt->close();

        // ➕ Insert new user
        $stmt = $db->prepare('INSERT INTO tblUsers (fullName, emailAddress, avatarPath, isActive) VALUES (?, ?, ?, 1)');
        if ($stmt === false) {
            throw new RuntimeException('DB prepare failed: ' . $db->error);
        }
        $stmt->bind_param('sss', $name, $email, $avatar);
        $stmt->execute();
        $newId = $stmt->insert_id;
        $stmt->close();

        return $newId;
    }
}
