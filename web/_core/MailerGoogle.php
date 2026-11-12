<?php
// Path: _core/MailerGoogle.php
/**
 * -----------------------------------------------------------------------------
 * Google Workspace Mailer (Gmail API) ✉️
 * -----------------------------------------------------------------------------
 * Sends HTML email (optionally with attachments) via the Gmail API using a
 * service account with domain-wide delegation. The service account impersonates
 * a delegate user (e.g. a shared/generic mailbox) to send on their behalf.
 *
 * This is the Google Workspace equivalent of Mailer.php (Microsoft Graph).
 * Both share the same public interface: MailerGoogle::send($to, $subj, $html, $files).
 *
 * Requirements:
 *   - Service account JSON key file in _auth_keys/ directory
 *   - Domain-wide delegation enabled in Google Workspace Admin Console
 *   - Gmail API enabled in Google Cloud Console
 *   - Scope authorised: https://www.googleapis.com/auth/gmail.send
 *
 * Settings in tblSettings:
 *   mail.google.serviceAccountKeyFile  – filename of the JSON key in _auth_keys/
 *   mail.google.delegateUser           – email address to impersonate (sender)
 *   mail.defaultFromName               – display name (shared with MS365)
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.9.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/48
 * @see       https://developers.google.com/gmail/api/reference/rest/v1/users.messages/send
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use RuntimeException;

class MailerGoogle
{
    /** @var string Cached access token */
    private static string $token = '';

    /** @var int Token expiry timestamp */
    private static int $tokenExp = 0;

    /**
     * 📧 Send an HTML email via the Gmail API.
     *
     * @param array  $to    Array of recipient email addresses
     * @param string $subj  Email subject line
     * @param string $html  HTML body content
     * @param array  $files Array of absolute file paths to attach (optional)
     * @param int|false|null $forSiteId The organisation this send's line in the
     *        send log is recorded under, as Mailer::logSend()'s last argument:
     *        false (the default) for this request's; the queue row's for a
     *        message the mail job sends later (#1006), because the job's own
     *        organisation is not the message's.
     *
     * @return bool True if sent successfully
     */
    public static function send(array $to, string $subj, string $html, array $files = [], int|false|null $forSiteId = false): bool
    {
        global $SETTINGS;

        $delegateUser = $SETTINGS['mail']['google']['delegateUser'] ?? '';
        $fromName     = $SETTINGS['mail']['defaultFromName'] ?? 'Portal';

        if ($delegateUser === '') {
            throw new RuntimeException('Google mail delegate user not configured.');
        }

        // 📊 #234 — attachment count for the tblEmailLog audit row below
        //    (mirrors buildMime()'s own <=25MB filter; kept separate since
        //    buildMime() doesn't return a count).
        $attachmentCount = 0;
        foreach ($files as $p) {
            if (is_file($p) === true && filesize($p) <= 25 * 1024 * 1024) {
                $attachmentCount++;
            }
        }

        // 📝 Build the RFC 2822 MIME message
        $mime = self::buildMime($to, $subj, $html, $delegateUser, $fromName, $files);

        // 🔐 Base64url-encode the MIME message for the Gmail API
        $raw = self::base64urlEncode($mime);

        // 🔑 Get an access token via service account JWT
        $token = self::accessToken();

        // 📤 POST to Gmail API
        $url = 'https://gmail.googleapis.com/gmail/v1/users/' . urlencode($delegateUser) . '/messages/send';

        $ch = curl_init($url);
        if ($ch === false) {
            Logger::errorPlatform('Gmail', 'Error', 'CURL_INIT', 'curl_init() failed for Gmail send', '');
            return false;
        }

        $payload = json_encode(['raw' => $raw]);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        // PHP 8+ auto-closes cURL handles when they go out of scope; no curl_close needed.

        if ($resp === false) {
            Logger::errorPlatform('Gmail', 'Error', 'CURL_FAIL', 'Gmail API cURL error: ' . $err, '');
            return false;
        }

        if ($code >= 200 && $code < 300) {
            Mailer::logSend('google', $delegateUser, implode(', ', $to), $subj, $attachmentCount, 'sent', $code, '', '', $forSiteId);
            return true;
        }

        Logger::errorPlatform('Gmail', 'Error', (string) $code, 'Gmail send failed', (string) $resp);
        Mailer::logSend('google', $delegateUser, implode(', ', $to), $subj, $attachmentCount, 'failed', $code, '', mb_substr((string) $resp, 0, 500), $forSiteId);
        return false;
    }

    /**
     * 📝 Build an RFC 2822 MIME message.
     *
     * @param array  $to       Recipient addresses
     * @param string $subj     Subject
     * @param string $html     HTML body
     * @param string $from     From email address
     * @param string $fromName From display name
     * @param array  $files    File paths to attach
     *
     * @return string Complete MIME message
     */
    private static function buildMime(
        array $to,
        string $subj,
        string $html,
        string $from,
        string $fromName,
        array $files
    ): string {
        $boundary = '----=_Part_' . bin2hex(random_bytes(16));
        $toHeader = implode(', ', $to);

        // 📧 Encode subject for RFC 2047 (UTF-8 support)
        $encodedSubj = '=?UTF-8?B?' . base64_encode($subj) . '?=';
        // 📧 Encode from name for RFC 2047
        $encodedName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';

        $headers = "From: " . $encodedName . " <" . $from . ">\r\n"
            . "To: " . $toHeader . "\r\n"
            . "Subject: " . $encodedSubj . "\r\n"
            . "MIME-Version: 1.0\r\n";

        // 🔍 Check if we have valid attachments
        $validFiles = [];
        foreach ($files as $p) {
            if (is_file($p) === true && filesize($p) <= 25 * 1024 * 1024) {
                $validFiles[] = $p;
            }
        }

        if (count($validFiles) === 0) {
            // 📄 Simple HTML-only message
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n";
            return $headers . "\r\n" . chunk_split(base64_encode($html));
        }

        // 📎 Multipart message with attachments
        $headers .= "Content-Type: multipart/mixed; boundary=\"" . $boundary . "\"\r\n";

        $body = "\r\n--" . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html));

        foreach ($validFiles as $filePath) {
            $filename    = basename($filePath);
            $mimeType    = mime_content_type($filePath) ?: 'application/octet-stream';
            $fileContent = file_get_contents($filePath);
            if ($fileContent === false) {
                continue;
            }

            $body .= "--" . $boundary . "\r\n"
                . "Content-Type: " . $mimeType . "; name=\"" . $filename . "\"\r\n"
                . "Content-Disposition: attachment; filename=\"" . $filename . "\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($fileContent));
        }

        $body .= "--" . $boundary . "--\r\n";

        return $headers . $body;
    }

    /**
     * 🔑 Obtain a Google access token using a service account JWT (RS256).
     *
     * Uses the service account's private key to sign a JWT assertion, then
     * exchanges it for an access token via Google's OAuth2 token endpoint.
     * The JWT includes a 'sub' claim to impersonate the delegate user.
     *
     * @see https://developers.google.com/identity/protocols/oauth2/service-account
     *
     * @return string Access token
     * @throws RuntimeException If token acquisition fails
     */
    private static function accessToken(): string
    {
        // 🔄 Return cached token if still valid
        if (self::$token !== '' && time() < self::$tokenExp - 60) {
            return self::$token;
        }

        global $SETTINGS;

        $keyFile      = $SETTINGS['mail']['google']['serviceAccountKeyFile'] ?? '';
        $delegateUser = $SETTINGS['mail']['google']['delegateUser'] ?? '';

        if ($keyFile === '' || $delegateUser === '') {
            throw new RuntimeException('Google service account key file or delegate user not configured.');
        }

        // 📂 Load the service account JSON key from _auth_keys/, through the
        //    ONE place that decides whether a key file name and its contents
        //    can be used (#598 item 9), so this and Mailer::canSend() can
        //    never disagree.
        $problem = self::keyFileProblem((string) $keyFile);
        if ($problem !== null) {
            throw new RuntimeException($problem);
        }
        // Read again for the values themselves. The file can change between
        // the check above and this read (it is on disk), so a null here is
        // refused just as cleanly rather than used.
        $keyData = self::readKeyFile((string) $keyFile);
        if ($keyData === null) {
            throw new RuntimeException('Invalid service account key file format.');
        }

        // 🔐 Build the JWT assertion
        $now    = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => $keyData['client_email'],
            'sub'   => $delegateUser,
            'scope' => 'https://www.googleapis.com/auth/gmail.send',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $segments   = [];
        $segments[] = self::base64urlEncode(json_encode($header));
        $segments[] = self::base64urlEncode(json_encode($claims));

        $signingInput = implode('.', $segments);

        // 🔏 Sign with the service account's private key (RS256)
        $privateKey = openssl_pkey_get_private($keyData['private_key']);
        if ($privateKey === false) {
            throw new RuntimeException('Failed to parse service account private key.');
        }

        $signature = '';
        $signed    = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if ($signed === false) {
            throw new RuntimeException('Failed to sign JWT assertion.');
        }

        $segments[] = self::base64urlEncode($signature);
        $jwt        = implode('.', $segments);

        // 📤 Exchange JWT for access token
        $ch = curl_init('https://oauth2.googleapis.com/token');
        if ($ch === false) {
            throw new RuntimeException('curl_init() failed for Google token request.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // PHP 8+ auto-closes cURL handles; no curl_close needed.

        if ($resp === false) {
            throw new RuntimeException('Google token request failed (cURL error).');
        }

        $data = json_decode((string) $resp, true);

        if ($code < 200 || $code >= 300 || isset($data['access_token']) === false) {
            $errDesc = $data['error_description'] ?? $data['error'] ?? 'unknown error';
            throw new RuntimeException('Google token request failed: ' . $errDesc);
        }

        self::$token    = $data['access_token'];
        self::$tokenExp = $now + (int) ($data['expires_in'] ?? 3600);

        return self::$token;
    }

    /**
     * 🔧 Base64url encode (RFC 4648 Section 5 — URL-safe, no padding).
     *
     * @param string $data Raw data to encode
     *
     * @return string Base64url-encoded string
     */
    private static function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * 🔑 Is this a key file the portal can use? Null when it is, otherwise a
     * sentence saying what is wrong (#598 item 9). The ONE place this is
     * decided: Mailer::routeAndItsNeeds() (and so canSend() and the health
     * page) and accessToken() above both ask here.
     *
     * WHAT WAS WRONG. canSend() asked only "is a file of this name readable".
     * So it said "set up" for a NAME CLIMBING OUT of the key folder
     * (`../something`), which also meant the portal would read whatever file
     * that named, and for a file that was not a key at all, so every send
     * then failed while the health page said all was well.
     *
     * THE RULES
     *   - The name is a plain file name: no `/` or `\`, no `..`, no NUL byte.
     *     The file must sit IN `_auth_keys`, never anywhere a name can reach.
     *   - The file can be read and decodes as a JSON object.
     *   - It holds `private_key` and `client_email`, each non-empty text: the
     *     two things the sign-in to Google needs.
     *
     * WHAT IT CANNOT KNOW: whether Google will accept the key. Only sending
     * shows that; use Send test on the e-mail page.
     *
     * @param string $name The setting `mail.google.serviceAccountKeyFile`.
     */
    public static function keyFileProblem(string $name): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return 'the name of the Google service account key file';
        }
        if (str_contains($name, '/') === true || str_contains($name, '\\') === true
            || str_contains($name, '..') === true || str_contains($name, "\0") === true
        ) {
            return 'a Google key file NAME with no folder in it: "' . $name . '" contains a / or \\ or "..", '
                . 'and the file must be in the portal\'s _auth_keys folder itself';
        }
        $path = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_auth_keys' . DIRECTORY_SEPARATOR . $name;
        if (is_file($path) === false || is_readable($path) === false) {
            return 'the Google key file itself: "' . $name . '" is not in the portal\'s _auth_keys folder, or cannot be read';
        }
        if (self::readKeyFile($name) === null) {
            return 'a Google key file that is a real service account key: "' . $name . '" is not JSON holding '
                . 'a private_key and a client_email';
        }

        return null;
    }

    /**
     * The decoded key file, or null when it does not hold what a key must.
     * Only ever called with a name keyFileProblem() has passed (or by it,
     * after its own checks on the name).
     *
     * @return array{private_key: string, client_email: string}|null
     */
    private static function readKeyFile(string $name): ?array
    {
        $json = @file_get_contents(PORTAL_ROOT . DIRECTORY_SEPARATOR . '_auth_keys' . DIRECTORY_SEPARATOR . trim($name));
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        if (is_array($data) === false
            || is_string($data['private_key'] ?? null) === false || trim($data['private_key']) === ''
            || is_string($data['client_email'] ?? null) === false || trim($data['client_email']) === ''
        ) {
            return null;
        }

        return $data;
    }

    /**
     * 🔍 Check if Google email sending is configured.
     *
     * @return bool True if service account key file and delegate user are set
     */
    public static function isConfigured(): bool
    {
        $keyFile      = App::settings('mail.google.serviceAccountKeyFile') ?? '';
        $delegateUser = App::settings('mail.google.delegateUser') ?? '';
        return ($keyFile !== '' && $delegateUser !== '');
    }
}
