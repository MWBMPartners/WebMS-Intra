<?php
// Path: _core/CloudflareStream.php
/**
 * -----------------------------------------------------------------------------
 * Cloudflare Stream management API client ☁️🎬 (#386 Phase 1.5)
 * -----------------------------------------------------------------------------
 * cURL client for Cloudflare's Stream *management* REST API — direct-upload
 * minting, status polling, edit, and delete. Modelled directly on
 * `Portal\Core\Zoom` (Bearer auth, `curl_init` + `CURLOPT_RETURNTRANSFER` +
 * `CURLOPT_TIMEOUT`, JSON decode, null/false on any failure). TLS
 * verification is left at cURL's own defaults throughout — never touched,
 * matching the Zoom precedent (DreamHost outbound cURL with default CA
 * verification is already proven working by Zoom/Captcha/Sms/Mailer).
 *
 * Distinct credential from `VideoEmbed`'s signing key on purpose — see that
 * class's docblock. This class uses ONLY `cfstream.apiToken` (Stream:Edit
 * custom token) + `cfstream.accountID`, both read via `Settings::get()`
 * (bootstrap auto-decrypts `isSensitive` rows, so the token is plaintext
 * here and ONLY here — server-side, never echoed, never logged). The whole
 * class is inert (`isConfigured()` returns false, every mutator returns
 * null/false without an HTTP call) until an admin pastes both values into
 * `/admin/integrations/cloudflare-stream`.
 *
 * Endpoints used — flagged **[CF-kc]** in the Phase 1.5 design doc (grounded
 * in the long-stable Stream API as of training knowledge, not independently
 * re-verified against a live account in this session because the docs MCP
 * doesn't index the Stream direct-upload/edit pages and
 * developers.cloudflare.com is egress-blocked from this container). Confirm
 * against `api.cloudflare.com` at the FIRST live upload:
 *   - POST   /accounts/{acct}/stream/direct_upload
 *   - GET    /accounts/{acct}/stream/{uid}
 *   - POST   /accounts/{acct}/stream/{uid}   (CF uses POST, not PATCH, to edit)
 *   - DELETE /accounts/{acct}/stream/{uid}
 * A slightly-off endpoint here cannot break anything before that first real
 * upload — every caller in this codebase already treats null/false as "not
 * available" and renders a graceful fallback tile.
 *
 * Public methods:
 *   CloudflareStream::isConfigured()                                  -> bool
 *   CloudflareStream::createDirectUpload($maxDur, $signed, $origins)  -> ?array{uploadURL,uid}
 *   CloudflareStream::getVideo($uid)                                  -> ?array (CF's `result` object)
 *   CloudflareStream::updateVideo($uid, $signed, $origins)            -> bool
 *   CloudflareStream::deleteVideo($uid)                               -> bool
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/386
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class CloudflareStream
{
    /** @var string Cloudflare API base — account ID is appended per-call. */
    private const API_BASE = 'https://api.cloudflare.com/client/v4/accounts/';

    /** @var string Character class for a Cloudflare Stream UID (32-hex). */
    private const UID_RE = '/^[0-9a-f]{32}$/';

    /** @var string Character class for one bare-hostname allowedOrigins entry. */
    private const ORIGIN_RE = '/^[a-z0-9.-]+$/i';

    /** @var int Maximum allowedOrigins entries accepted per call. */
    private const MAX_ORIGINS = 10;

    /* ====================================================================== */
    /* Configuration                                                          */
    /* ====================================================================== */

    /**
     * True iff both `cfstream.apiToken` and `cfstream.accountID` are
     * non-empty. Every other public method short-circuits to null/false
     * when this is false, so callers never need to check it separately
     * before calling — it's exposed for UI gating (e.g. only rendering the
     * upload panel when the feature is actually usable).
     */
    public static function isConfigured(): bool
    {
        return (string) Settings::get('cfstream.apiToken', '') !== ''
            && (string) Settings::get('cfstream.accountID', '') !== '';
    }

    /* ====================================================================== */
    /* Direct creator upload                                                  */
    /* ====================================================================== */

    /**
     * Mint a one-time, single-video Cloudflare Stream direct-upload URL.
     * The browser uploads straight to the returned `uploadURL` — the file
     * never touches the portal server, so DreamHost's PHP upload limits
     * are irrelevant.
     *
     * @param int         $maxDurationSeconds Reserved encoding capacity, 1-21600 (CF hard cap)
     * @param bool        $requireSignedUrls  Cloudflare `requireSignedURLs` on the minted video
     * @param string|null $allowedOrigins     Comma-separated bare hostnames, or null/blank for "any"
     * @param int         $expirySeconds      Upload-URL lifetime, clamped to CF's 2min-6h window
     *
     * @return array{uploadURL:string,uid:string}|null Null on any failure — never throws
     */
    public static function createDirectUpload(
        int $maxDurationSeconds,
        bool $requireSignedUrls,
        ?string $allowedOrigins,
        int $expirySeconds = 3600
    ): ?array {
        if (self::isConfigured() === false) {
            return null;
        }

        if ($maxDurationSeconds <= 0 || $maxDurationSeconds > 21600) {
            $maxDurationSeconds = 3600;
        }
        // 🛡️ Clamp to Cloudflare's documented direct-upload expiry window
        //    (min ~2 minutes, max ~6 hours) rather than trusting the caller.
        if ($expirySeconds < 120) {
            $expirySeconds = 120;
        }
        if ($expirySeconds > 21600) {
            $expirySeconds = 21600;
        }

        $body = [
            'maxDurationSeconds' => $maxDurationSeconds,
            'requireSignedURLs'  => $requireSignedUrls,
            'expiry'             => gmdate('Y-m-d\TH:i:s\Z', time() + $expirySeconds),
        ];
        $origins = self::parseOrigins($allowedOrigins);
        if (count($origins) > 0) {
            $body['allowedOrigins'] = $origins;
        }

        $resp = self::request('POST', '/stream/direct_upload', $body);
        if ($resp['success'] !== true || is_array($resp['result']) === false) {
            return null;
        }

        $uploadUrl = (string) ($resp['result']['uploadURL'] ?? '');
        $uid       = strtolower((string) ($resp['result']['uid'] ?? ''));
        if ($uploadUrl === '' || preg_match(self::UID_RE, $uid) !== 1) {
            Logger::errorPlatform(
                'CloudflareStream',
                'Error',
                'CFSTREAM_MINT_SHAPE',
                'Cloudflare Stream direct_upload response missing uploadURL/uid',
                ''
            );
            return null;
        }

        return ['uploadURL' => $uploadUrl, 'uid' => $uid];
    }

    /* ====================================================================== */
    /* Status / detail                                                        */
    /* ====================================================================== */

    /**
     * Fetch full video detail from Cloudflare (readyToStream, status.state,
     * status.errorReasonText, requireSignedURLs, allowedOrigins, duration,
     * playback URLs, …). Returns the raw `result` object, or null on any
     * failure (not-configured, invalid uid, transport error, non-2xx).
     */
    public static function getVideo(string $uid): ?array
    {
        $uid = strtolower(trim($uid));
        if (preg_match(self::UID_RE, $uid) !== 1 || self::isConfigured() === false) {
            return null;
        }

        $resp = self::request('GET', '/stream/' . $uid);
        if ($resp['success'] !== true || is_array($resp['result']) === false) {
            return null;
        }

        return $resp['result'];
    }

    /* ====================================================================== */
    /* Edit / delete                                                          */
    /* ====================================================================== */

    /**
     * Update a video's `requireSignedURLs` + `allowedOrigins` on the
     * Cloudflare side. Cloudflare's Stream API uses POST (not PATCH) for
     * "edit video details" — see the class docblock [CF-kc] note.
     */
    public static function updateVideo(string $uid, bool $requireSignedUrls, ?string $allowedOrigins): bool
    {
        $uid = strtolower(trim($uid));
        if (preg_match(self::UID_RE, $uid) !== 1 || self::isConfigured() === false) {
            return false;
        }

        $body = [
            'requireSignedURLs' => $requireSignedUrls,
            // CF semantics: an explicit empty array clears the restriction
            // (== "any origin"), matching a blanked-out admin/hub field.
            'allowedOrigins'    => self::parseOrigins($allowedOrigins),
        ];

        $resp = self::request('POST', '/stream/' . $uid, $body);

        return $resp['success'] === true;
    }

    /**
     * Delete a video from Cloudflare Stream. Returns true on 2xx success,
     * false otherwise (not-configured, invalid uid, transport error,
     * non-2xx) — callers treat false as "best effort, log and move on",
     * never as a reason to fail an in-progress local mutation.
     */
    public static function deleteVideo(string $uid): bool
    {
        $uid = strtolower(trim($uid));
        if (preg_match(self::UID_RE, $uid) !== 1 || self::isConfigured() === false) {
            return false;
        }

        $resp = self::request('DELETE', '/stream/' . $uid);

        return $resp['success'] === true;
    }

    /* ====================================================================== */
    /* Internals                                                              */
    /* ====================================================================== */

    /**
     * Parse a comma-separated bare-hostname list into a validated array,
     * capped at self::MAX_ORIGINS. Invalid entries are silently dropped
     * (callers that need to reject bad input validate BEFORE calling this
     * class — e.g. the save handlers re-validate with the same character
     * class and flash an error instead of calling Cloudflare at all).
     *
     * @return list<string>
     */
    private static function parseOrigins(?string $csv): array
    {
        if ($csv === null) {
            return [];
        }
        $csv = trim($csv);
        if ($csv === '') {
            return [];
        }

        $parts = array_filter(array_map('trim', explode(',', $csv)));
        $parts = array_slice(array_values($parts), 0, self::MAX_ORIGINS);

        $out = [];
        foreach ($parts as $part) {
            if (preg_match(self::ORIGIN_RE, $part) === 1) {
                $out[] = strtolower($part);
            }
        }

        return $out;
    }

    /**
     * Shared cURL request/response handling for every Cloudflare Stream
     * call. Bearer auth via `cfstream.apiToken` (Settings::get — already
     * decrypted server-side), JSON body when present, 15s timeout, decodes
     * Cloudflare's standard `{success, result, errors, messages}` envelope.
     *
     * SECURITY: the API token is read here and ONLY here, placed ONLY in
     * the Authorization header, and NEVER included in any log line, return
     * value, or exception message — on any failure only the HTTP method,
     * path (which contains a video uid, not a secret), status code, and
     * Cloudflare's own error message (if any) are logged via
     * Logger::errorPlatform(). TLS verification is left at cURL's compiled-
     * in defaults — no CURLOPT_SSL_VERIFYPEER / CURLOPT_SSL_VERIFYHOST
     * override anywhere in this class (Zoom.php precedent).
     *
     * @return array{success:bool,result:?array,errors:array,httpCode:int}
     */
    private static function request(string $method, string $path, ?array $body = null): array
    {
        $fail = ['success' => false, 'result' => null, 'errors' => [], 'httpCode' => 0];

        $accountId = (string) Settings::get('cfstream.accountID', '');
        $apiToken  = (string) Settings::get('cfstream.apiToken', '');
        if ($accountId === '' || $apiToken === '') {
            return $fail;
        }

        $url = self::API_BASE . rawurlencode($accountId) . $path;

        $ch = curl_init($url);
        if ($ch === false) {
            return $fail;
        }

        $headers = ['Authorization: Bearer ' . $apiToken];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) json_encode($body, JSON_UNESCAPED_SLASHES));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        // 🛡️ No CURLOPT_SSL_VERIFYPEER / CURLOPT_SSL_VERIFYHOST here —
        //    cURL's default (verify ON) is what we want, always.

        $raw       = curl_exec($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrNo = curl_errno($ch);
        curl_close($ch);

        if ($raw === false) {
            Logger::errorPlatform(
                'CloudflareStream',
                'Error',
                'CFSTREAM_TRANSPORT',
                'Cloudflare Stream API transport failure',
                'method=' . $method . ' path=' . $path . ' curlErrno=' . (string) $curlErrNo
            );
            return $fail;
        }

        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded) === false) {
            Logger::errorPlatform(
                'CloudflareStream',
                'Error',
                'CFSTREAM_DECODE',
                'Cloudflare Stream API returned a non-JSON body',
                'method=' . $method . ' path=' . $path . ' httpCode=' . (string) $httpCode
            );
            return $fail;
        }

        $success = (bool) ($decoded['success'] ?? false);
        $result  = is_array($decoded['result'] ?? null) ? $decoded['result'] : null;
        $errors  = is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [];

        if ($success === false || $httpCode < 200 || $httpCode >= 300) {
            $firstMessage = (string) ($errors[0]['message'] ?? 'unknown error');
            Logger::errorPlatform(
                'CloudflareStream',
                'Warning',
                'CFSTREAM_API_ERROR',
                'Cloudflare Stream API call failed',
                'method=' . $method . ' path=' . $path . ' httpCode=' . (string) $httpCode . ' message=' . $firstMessage
            );
            return ['success' => false, 'result' => $result, 'errors' => $errors, 'httpCode' => $httpCode];
        }

        return ['success' => true, 'result' => $result, 'errors' => $errors, 'httpCode' => $httpCode];
    }
}
