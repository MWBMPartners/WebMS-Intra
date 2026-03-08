<?php
// Path: core/Mailer.php
/**
 * -----------------------------------------------------------------------------
 * Portal Mailer — Multi-Provider Dispatcher ✉️
 * -----------------------------------------------------------------------------
 * Sends HTML email (optionally with attachments) via the configured provider.
 * Dispatches to either Microsoft Graph (MS365) or Gmail API (Google Workspace)
 * based on the `mail.provider` setting.
 *
 * The MS365 backend uses application-level OAuth2 (client credentials) and
 * Microsoft Graph to send from a shared mailbox via SendAs delegation.
 *
 * The Google backend uses a service account with domain-wide delegation
 * and the Gmail API. See MailerGoogle.php for the implementation.
 *
 * Provider selection:
 *   mail.provider = 'ms365'   → Microsoft Graph (default)
 *   mail.provider = 'google'  → Gmail API via service account
 *
 * Usage:
 *   Mailer::send(
 *     to:    ['alice@example.com'],
 *     subj:  'Expense Claim Approved',
 *     html:  '<p>Congrats…</p>',
 *     files: ['/path/file.pdf']
 *   );
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.9.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/48
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use RuntimeException;

class Mailer
{
    private static string $token    = '';
    private static int    $tokenExp = 0;

    /**
     * 📧 Send an email via the configured provider.
     *
     * Checks `mail.provider` setting and dispatches to the appropriate backend.
     * Defaults to MS365 (Microsoft Graph) for backward compatibility.
     *
     * @param array  $to    Array of recipient email addresses
     * @param string $subj  Email subject line
     * @param string $html  HTML body content
     * @param array  $files Array of absolute file paths to attach (optional)
     *
     * @return bool True if sent successfully
     */
    public static function send(array $to, string $subj, string $html, array $files = []): bool
    {
        global $SETTINGS;

        // 🔀 Dispatch to the configured provider
        $provider = strtolower($SETTINGS['mail']['provider'] ?? 'ms365');

        if ($provider === 'google') {
            return MailerGoogle::send($to, $subj, $html, $files);
        }

        // 📧 Default: Microsoft Graph (MS365)
        return self::sendViaGraph($to, $subj, $html, $files);
    }

    /**
     * 📧 Send an email via Microsoft Graph (MS365 shared mailbox).
     *
     * @param array  $to    Recipient email addresses
     * @param string $subj  Subject
     * @param string $html  HTML body
     * @param array  $files File paths to attach
     *
     * @return bool True if sent successfully
     */
    private static function sendViaGraph(array $to, string $subj, string $html, array $files = []): bool
    {
        global $SETTINGS;

        $fromAddr = $SETTINGS['mail']['defaultFromAddress'] ?? '';

        if ($fromAddr === '') {
            throw new RuntimeException('From address missing');
        }

        $msg = [
            'message' => [
                'subject'      => $subj,
                'body'         => ['contentType' => 'HTML', 'content' => $html],
                'toRecipients' => array_map(
                    fn($e) => ['emailAddress' => ['address' => $e]],
                    $to
                ),
                'attachments'  => self::attach($files),
            ],
        ];

        $token = self::accessToken();
        $url   = 'https://graph.microsoft.com/v1.0/users/' . urlencode($fromAddr) . '/sendMail';

        $ch = curl_init($url);
        if ($ch === false) {
            Logger::errorPlatform('Graph', 'Error', 'CURL_INIT', 'curl_init() failed for sendMail', '');
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($msg),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($code >= 200 && $code < 300) {
            return true;
        }

        Logger::errorPlatform('Graph', 'Error', (string) $code, 'Mail send fail', (string) $resp);
        return false;
    }

    /** Build attachment array (<= 4MB each inline) */
    private static function attach(array $paths): array
    {
        $out = [];
        foreach ($paths as $p) {
            if (is_file($p) === false || filesize($p) > 4 * 1024 * 1024) {
                continue;
            }
            $out[] = [
                '@odata.type'  => '#microsoft.graph.fileAttachment',
                'name'         => basename($p),
                'contentType'  => mime_content_type($p) ?: 'application/octet-stream',
                'contentBytes' => base64_encode(file_get_contents($p)),
            ];
        }
        return $out;
    }

    /**
     * 🔍 Get the currently configured mail provider.
     *
     * @return string 'ms365' or 'google'
     */
    public static function provider(): string
    {
        return strtolower(App::settings('mail.provider') ?? 'ms365');
    }

    /** Obtain / cache app token (MS365 client credentials) */
    private static function accessToken(): string
    {
        if (self::$token !== '' && time() < self::$tokenExp - 60) {
            return self::$token;
        }

        global $SETTINGS;

        $cid    = $SETTINGS['auth']['ms365']['appwide']['clientID'] ?? '';
        $sec    = $SETTINGS['auth']['ms365']['appwide']['clientSecret'] ?? '';
        $tenant = $SETTINGS['auth']['ms365']['tenantID'] ?? '';

        if ($cid === '' || $sec === '' || $tenant === '') {
            throw new RuntimeException('Graph creds missing');
        }

        $url  = 'https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/token';
        $post = [
            'client_id'     => $cid,
            'client_secret' => $sec,
            'grant_type'    => 'client_credentials',
            'scope'         => 'https://graph.microsoft.com/.default',
        ];

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init() failed for token request');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($post),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $resp = curl_exec($ch);
        if ($resp === false) {
            throw new RuntimeException('Token request failed');
        }

        $data = json_decode($resp, true);
        if (isset($data['access_token']) === false) {
            throw new RuntimeException('Token parse fail');
        }

        self::$token    = $data['access_token'];
        self::$tokenExp = time() + (int) $data['expires_in'];

        return self::$token;
    }
}
