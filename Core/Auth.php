<?php
// Path: core/Auth.php
/**
 * -----------------------------------------------------------------------------
 * Portal Core Auth 🔑
 * -----------------------------------------------------------------------------
 * Unified authentication helper – wraps Microsoft 365 OAuth, local accounts,
 * and (future) Google OAuth.  Provides session management, role checks, and
 * CSRF token utilities.
 * -----------------------------------------------------------------------------
 *  Public methods:
 *    • Auth::check()          → bool           – is user logged in?
 *    • Auth::requireLogin()   → void           – redirect if not logged in
 *    • Auth::loginMS365()     → void           – begin MS OAuth flow
 *    • Auth::callbackMS365()  → void           – handle OAuth redirect
 *    • Auth::logout()         → void           – destroy session
 *    • Auth::csrfToken()      → string         – get / create CSRF token
 *    • Auth::verifyCsrf($tok) → bool           – compare token constant‑time
 * -----------------------------------------------------------------------------
 * NOTE: This stub prepares the scaffolding; MS Graph specifics (tenant, client
 * ID / secret) are pulled from $SETTINGS.  Low‑level HTTP requests will use
 * simple cURL since Composer is unavailable.  A lightweight vendor lib for JWT
 * validation (php‑jwt) will be dropped into /vendor/ in a later sub‑phase.
 * -----------------------------------------------------------------------------
 * @package    Portal\Core
 * @author     Cambridge SDA
 * @license    MIT
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli_stmt;
use RuntimeException;

class Auth
{
    /** Start session if not already started. */
    private static function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    /** Is a user currently authenticated? */
    public static function check(): bool
    {
        self::ensureSession();
        return isset($_SESSION['user_id']);
    }

    /** Require login or redirect to /login. */
    public static function requireLogin(): void
    {
        if (self::check() === false) {
            $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/');
            header('Location: /login?redirect=' . $redirect, true, 302);
            exit();
        }
    }

    /* ---------------------------------------------------------------------- */
    /* CSRF helpers                                                           */
    /* ---------------------------------------------------------------------- */

    public static function csrfToken(): string
    {
        self::ensureSession();
        if (isset($_SESSION['csrf_token']) === false) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(string $token): bool
    {
        self::ensureSession();
        if (isset($_SESSION['csrf_token']) === false) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /* ---------------------------------------------------------------------- */
    /* Microsoft 365 OAuth flow                                               */
    /* ---------------------------------------------------------------------- */

    public static function loginMS365(): void
    {
        global $SETTINGS;

        if (isset($SETTINGS['auth']['ms365']['enduser']['clientID']) === false) {
            throw new RuntimeException('MS365 client ID not configured.');
        }

        $clientId     = $SETTINGS['auth']['ms365']['enduser']['clientID'];
        $redirectUri  = $SETTINGS['auth']['ms365']['enduser']['redirectURI'];
        $tenantId     = $SETTINGS['auth']['ms365']['tenantID'];
        $state        = bin2hex(random_bytes(8));
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

    /** Handle Microsoft redirect after user consents. */
    public static function callbackMS365(): void
    {
        self::ensureSession();
        global $SETTINGS; global $mysqli;

        // Basic CSRF check on state param
        if (isset($_GET['state']) === false || $_GET['state'] !== ($_SESSION['oauth_state'] ?? '')) {
            echo 'Invalid OAuth state.';
            exit();
        }
        if (isset($_GET['code']) === false) {
            echo 'Authorization code missing.';
            exit();
        }

        $code        = $_GET['code'];
        $clientId    = $SETTINGS['auth']['ms365']['enduser']['clientID'];
        $clientSecret= $SETTINGS['auth']['ms365']['enduser']['clientSecret'];
        $redirectUri = $SETTINGS['auth']['ms365']['enduser']['redirectURI'];
        $tenantId    = $SETTINGS['auth']['ms365']['tenantID'];

        // Exchange code for tokens
        $tokenEndpoint = 'https://login.microsoftonline.com/' . $tenantId . '/oauth2/v2.0/token';
        $postFields    = [
            'client_id'     => $clientId,
            'scope'         => 'openid email profile offline_access User.Read',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
            'client_secret' => $clientSecret,
        ];

        $response = self::curlPost($tokenEndpoint, $postFields);
        if ($response === null) {
            echo 'Token request failed.';
            exit();
        }
        $tokenData = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || isset($tokenData['id_token']) === false) {
            echo 'Invalid token response.';
            exit();
        }

        // Decode JWT (header.payload.signature). We will not fully verify here –
        // in production integrate vendor/php-jwt for signature validation.
        [$headerB64, $payloadB64] = explode('.', $tokenData['id_token']);
        $payloadJson = base64_decode(strtr($payloadB64, '-_', '+/')); // Add padding handled by PHP automatically
        $payload     = json_decode($payloadJson, true);
        if (isset($payload['sub']) === false) {
            echo 'Unable to parse ID token.';
            exit();
        }

        // Upsert user in tblUsers
        $email = strtolower($payload['preferred_username'] ?? $payload['email'] ?? '');
        $name  = $payload['name'] ?? '';
        $avatar= $payload['picture'] ?? '';

        $userId = self::upsertUserMS($mysqli, $email, $name, $avatar);

        // Persist session
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_name'] = $name;
        $_SESSION['user_email'] = $email;

        // Redirect to original target
        $target = $_GET['redirect'] ?? '/';
        header('Location: ' . $target, true, 302);
        exit();
    }

    /** Destroy session and redirect to home. */
    public static function logout(): void
    {
        self::ensureSession();
        session_unset();
        session_destroy();
        header('Location: /', true, 302);
        exit();
    }

    /* ---------------------------------------------------------------------- */
    /* Internal helpers                                                       */
    /* ---------------------------------------------------------------------- */

    private static function curlPost(string $url, array $data): ?string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
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
        // Check if user exists
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
            // Update name/avatar if changed
            $stmt = $db->prepare('UPDATE tblUsers SET fullName = ?, avatarPath = ?, isActive = 1 WHERE userID = ?');
            $stmt->bind_param('ssi', $name, $avatar, $userId);
            $stmt->execute();
            $stmt->close();
            return $userId;
        }
        $stmt->close();

        // Insert new user
        $stmt = $db->prepare('INSERT INTO tblUsers (fullName, emailAddress, avatarPath, isActive) VALUES (?,?,?,1)');
        $stmt->bind_param('sss', $name, $email, $avatar);
        $stmt->execute();
        $newId = $stmt->insert_id;
        $stmt->close();
        return $newId;
    }
}
