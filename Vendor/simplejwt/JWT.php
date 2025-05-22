<?php
// Path: core/Auth.php
/**
 * -----------------------------------------------------------------------------
 * Portal Core Auth 🔑
 * -----------------------------------------------------------------------------
 * Unified authentication helper – wraps Microsoft 365 OAuth, local accounts,
 * and (future) Google OAuth.  Provides session management, role checks, and
 * CSRF token utilities.
 * -----------------------------------------------------------------------------
 *  Public methods:
 *    • Auth::check()          → bool           – is user logged in?
 *    • Auth::requireLogin()   → void           – redirect if not logged in
 *    • Auth::loginMS365()     → void           – begin MS OAuth flow
 *    • Auth::callbackMS365()  → void           – handle OAuth redirect & verify
 *    • Auth::logout()         → void           – destroy session
 *    • Auth::csrfToken()      → string         – get / create CSRF token
 *    • Auth::verifyCsrf($tok) → bool           – compare token constant-time
 * -----------------------------------------------------------------------------
 * @package    Portal\Core
 * @license    MIT
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use RuntimeException;
use SimpleJWT\JWT as SimpleJWT;

class Auth
{
    /* ---------------------------------------------------------------------- */
    /* Session helpers                                                        */
    /* ---------------------------------------------------------------------- */

    private static function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public static function check(): bool
    {
        self::ensureSession();
        return isset($_SESSION['user_id']);
    }

    public static function requireLogin(): void
    {
        if (self::check() === false) {
            $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/');
            header('Location: /login?redirect=' . $redirect, true, 302);
            exit();
        }
    }

    /* ---------------------------------------------------------------------- */
    /* CSRF                                                                   */
    /* ---------------------------------------------------------------------- */

    public static function csrfToken(): string
    {
        self::ensureSession();
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(string $token): bool
    {
        self::ensureSession();
        if (!isset($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /* ---------------------------------------------------------------------- */
    /* Microsoft 365 OAuth                                                    */
    /* ---------------------------------------------------------------------- */

    public static function loginMS365(): void
    {
        global $SETTINGS;

        if (($SETTINGS['auth']['ms365']['enduser']['clientID'] ?? '') === '') {
            throw new RuntimeException('MS365 client ID not configured.');
        }

        $clientId    = $SETTINGS['auth']['ms365']['enduser']['clientID'];
        $redirectUri = $SETTINGS['auth']['ms365']['enduser']['redirectURI'];
        $tenantId    = $SETTINGS['auth']['ms365']['tenantID'];

        $state = bin2hex(random_bytes(8));
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

    public static function callbackMS365(): void
    {
        self::ensureSession();
        global $SETTINGS, $mysqli;

        /* ------------------------- 1. CSRF / basic checks ----------------- */
        if (!isset($_GET['state']) || $_GET['state'] !== ($_SESSION['oauth_state'] ?? '')) {
            echo 'Invalid OAuth state.';
            exit();
        }
        if (!isset($_GET['code'])) {
            echo 'Authorization code missing.';
            exit();
        }

        $code         = $_GET['code'];
        $clientId     = $SETTINGS['auth']['ms365']['enduser']['clientID'];
        $clientSecret = $SETTINGS['auth']['ms365']['enduser']['clientSecret'];
        $redirectUri  = $SETTINGS['auth']['ms365']['enduser']['redirectURI'];
        $tenantId     = $SETTINGS['auth']['ms365']['tenantID'];

        /* ------------------------- 2. Exchange code for tokens ------------ */
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
        if (!isset($tokenData['id_token'])) {
            echo 'Invalid token response.';
            exit();
        }
        $idToken = $tokenData['id_token'];

        /* ------------------------- 3. Verify ID token --------------------- */
        $jwksUri = 'https://login.microsoftonline.com/' . $tenantId . '/discovery/v2.0/keys';
        $jwksJson = file_get_contents($jwksUri);
        if ($jwksJson === false) {
            echo 'Unable to retrieve JWKS.';
            exit();
        }
        $jwks = json_decode($jwksJson, true);
        try {
            $payload = SimpleJWT::decode($idToken, $jwks);
        } catch (RuntimeException $ex) {
            Logger::errorPlatform('JWT', 'Error', 'VERIFY_FAIL', $ex->getMessage(), '');
            echo 'Token verification failed.';
            exit();
        }

        /* ------------------------- 4. Upsert user ------------------------- */
        $email  = strtolower($payload['preferred_username'] ?? ($payload['email'] ?? ''));
        $name   = $payload['name'] ?? '';
        $avatar = $payload['picture'] ?? '';

        $userId = self::upsertUserMS($mysqli, $email, $name, $avatar);

        /* ------------------------- 5. Persist & redirect ------------------ */
        $_SESSION['user_id']    = $userId;
        $_SESSION['user_name']  = $name;
        $_SESSION['user_email'] = $email;

        Logger::activity('LoginMS365', 'User logged in via Microsoft');

        $target = $_GET['redirect'] ?? '/';
        header('Location: ' . $target, true, 302);
        exit();
    }

    public static function logout(): void
    {
        self::ensureSession();
        session_unset();
        session_destroy();
        header('Location: /', true, 302);
        exit();
    }

    /* ---------------------------------------------------------------------- */
    /* Internal utilities                                                     */
    /* ---------------------------------------------------------------------- */

    public static function curlPost(string $url, array $data): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
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

    private static function upsertUserMS($db, string $email, string $name, string $avatar): int
    {
        // Check existing user
        $stmt = $db->prepare('SELECT userID FROM tblUsers WHERE emailAddress = ? LIMIT 1');
        if ($stmt === false) {
            throw new RuntimeException('DB prepare failed: ' . $db->error);
        }
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $userId = (int) $row['userID'];
            $stmt->close();
            $stmt = $db->prepare('UPDATE tblUsers SET fullName = ?, avatarPath = ?, isActive = 1 WHERE userID = ?');
            $stmt->bind_param('ssi', $name, $avatar, $userId);
            $stmt->execute();
            $stmt->close();
            return $userId;
        }
        $stmt->close();

        // Insert new
        $stmt = $db->prepare('INSERT INTO tblUsers (fullName, emailAddress, avatarPath, isActive) VALUES (?,?,?,1)');
        $stmt->bind_param('sss', $name, $email, $avatar);
        $stmt->execute();
        $newId = $stmt->insert_id;
        $stmt->close();
        return $newId;
    }
}
