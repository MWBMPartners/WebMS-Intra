<?php
// Path: _core/Zoom.php
/**
 * -----------------------------------------------------------------------------
 * Zoom OAuth + Meetings API client 🎥
 * -----------------------------------------------------------------------------
 * Authorisation-code OAuth, refresh-token rotation, server-side meeting create
 * + delete, and webhook HMAC signature verification.
 *
 * Tokens are encrypted at rest via the existing libsodium pipeline
 * (encrypt_setting / decrypt_setting) so they're never stored in plain text.
 *
 * @package   Portal\Core
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/274
 * @link      https://developers.zoom.us/docs/integrations/oauth/
 * @link      https://developers.zoom.us/docs/api/meetings/
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Zoom
{
    private const AUTH_URL  = 'https://zoom.us/oauth/authorize';
    private const TOKEN_URL = 'https://zoom.us/oauth/token';
    private const API_BASE  = 'https://api.zoom.us/v2';

    /**
     * Build the OAuth authorise URL. The caller is responsible for
     * persisting the state value in the session to defend against CSRF.
     */
    public static function authorizeUrl(string $clientId, string $redirectUri, string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
        ]);
    }

    /**
     * Exchange an authorisation code for access + refresh tokens.
     * Returns the decoded JSON body, or null on transport / parse failure.
     */
    public static function exchangeCode(string $clientId, string $clientSecret, string $code, string $redirectUri): ?array
    {
        return self::tokenRequest($clientId, $clientSecret, [
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $redirectUri,
        ]);
    }

    /**
     * Rotate a refresh token. Zoom returns a NEW refresh token on every
     * refresh — callers MUST persist the rotated value or the next refresh
     * will fail.
     */
    public static function refreshToken(string $clientId, string $clientSecret, string $refreshToken): ?array
    {
        return self::tokenRequest($clientId, $clientSecret, [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * Load an account row (org-level if userId === null), decrypt the
     * refresh token, and ensure the access token is fresh. Refreshes on
     * demand and persists the rotated refresh token + new expiry.
     *
     * Returns ['accountID', 'accessToken', 'zoomUserId'] or null when the
     * account is missing / mis-configured.
     */
    public static function loadValidAccount(int $siteId, ?int $userId): ?array
    {
        $db = App::db();
        $sql = 'SELECT accountID, zoomUserId, refreshTokenEnc, accessTokenEnc, accessTokenExpiresAt '
            . 'FROM tblZoomAccount WHERE siteID = ? AND '
            . ($userId === null ? 'userID IS NULL' : 'userID = ?')
            . ' LIMIT 1';
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return null;
        }
        if ($userId === null) {
            $stmt->bind_param('i', $siteId);
        } else {
            $stmt->bind_param('ii', $siteId, $userId);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return null;
        }

        $access = $row['accessTokenEnc'] !== null ? decrypt_setting((string) $row['accessTokenEnc']) : '';
        $expiresAt = $row['accessTokenExpiresAt'] !== null ? (int) strtotime((string) $row['accessTokenExpiresAt']) : 0;

        // 60-second skew margin so we never present a token that's about to expire mid-request.
        if ($access === '' || $expiresAt - time() < 60) {
            $settings = App::settings();
            $clientId     = (string) ($settings['zoom']['clientID'] ?? '');
            $clientSecret = (string) ($settings['zoom']['clientSecret'] ?? '');
            if ($clientId === '' || $clientSecret === '') {
                return null;
            }
            $refresh = decrypt_setting((string) $row['refreshTokenEnc']);
            if ($refresh === '') {
                return null;
            }
            $body = self::refreshToken($clientId, $clientSecret, $refresh);
            if ($body === null || isset($body['access_token']) === false) {
                return null;
            }
            $access = (string) $body['access_token'];
            $newRefresh = (string) ($body['refresh_token'] ?? $refresh);
            $expiresAt  = time() + (int) ($body['expires_in'] ?? 3599);

            $u = $db->prepare(
                'UPDATE tblZoomAccount SET accessTokenEnc = ?, refreshTokenEnc = ?, accessTokenExpiresAt = FROM_UNIXTIME(?) WHERE accountID = ?'
            );
            if ($u !== false) {
                $accessEnc  = encrypt_setting($access);
                $refreshEnc = encrypt_setting($newRefresh);
                $accId      = (int) $row['accountID'];
                $u->bind_param('ssii', $accessEnc, $refreshEnc, $expiresAt, $accId);
                $u->execute();
                $u->close();
            }
        }

        return [
            'accountID'   => (int) $row['accountID'],
            'accessToken' => $access,
            'zoomUserId'  => (string) $row['zoomUserId'],
        ];
    }

    /**
     * Create a meeting under the authenticated user. Returns the decoded
     * Zoom response (with `id`, `join_url`, `start_url`, `password`) or
     * null on failure.
     */
    public static function createMeeting(string $accessToken, array $payload): ?array
    {
        $ch = curl_init(self::API_BASE . '/users/me/meetings');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            return null;
        }
        $decoded = json_decode((string) $body, true);
        return is_array($decoded) === true ? $decoded : null;
    }

    /**
     * Delete a meeting. Returns true on 2xx (including 204), false otherwise.
     */
    public static function deleteMeeting(string $accessToken, string $zoomMeetingId): bool
    {
        $ch = curl_init(self::API_BASE . '/meetings/' . rawurlencode($zoomMeetingId));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }

    /**
     * Fetch the authenticated user's Zoom profile (id, email, etc.).
     */
    public static function fetchMe(string $accessToken): ?array
    {
        $ch = curl_init(self::API_BASE . '/users/me');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code !== 200) {
            return null;
        }
        $decoded = json_decode((string) $body, true);
        return is_array($decoded) === true ? $decoded : null;
    }

    /**
     * Verify a Zoom webhook signature. Zoom posts an `x-zm-request-timestamp`
     * header alongside `x-zm-signature` of the form `v0=HEX_HMAC`, where
     * HMAC = SHA-256(secret, 'v0:' + timestamp + ':' + raw_body).
     *
     * Returns true on valid sig + non-stale timestamp (5 min window).
     *
     * @link https://developers.zoom.us/docs/api/rest/webhook-reference/
     */
    public static function verifyWebhook(string $secret, string $body, string $signatureHeader, string $timestampHeader): bool
    {
        if ($secret === '' || $signatureHeader === '' || $timestampHeader === '') {
            return false;
        }
        $ts = (int) $timestampHeader;
        if ($ts <= 0 || abs(time() - $ts) > 300) {
            return false;
        }
        $expected = 'v0=' . hash_hmac('sha256', 'v0:' . $timestampHeader . ':' . $body, $secret);
        return hash_equals($expected, $signatureHeader);
    }

    /**
     * Answer Zoom's URL-validation challenge during webhook endpoint setup.
     * Zoom posts a JSON body with event=endpoint.url_validation and a
     * payload.plainToken; we echo back plainToken + sha256(plainToken, secret).
     */
    public static function answerUrlValidation(string $secret, array $payload): array
    {
        $plain = (string) ($payload['plainToken'] ?? '');
        return [
            'plainToken'     => $plain,
            'encryptedToken' => hash_hmac('sha256', $plain, $secret),
        ];
    }

    private static function tokenRequest(string $clientId, string $clientSecret, array $params): ?array
    {
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
            'Content-Type: application/x-www-form-urlencoded',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code !== 200) {
            return null;
        }
        $decoded = json_decode((string) $body, true);
        return is_array($decoded) === true ? $decoded : null;
    }
}
