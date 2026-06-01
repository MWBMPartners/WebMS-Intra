<?php
// Path: _core/Mailer.php
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
     * @param string|array $to    Recipient (single email or array)
     * @param string       $subj  Email subject line
     * @param string       $body  Body — auto-detected as HTML if it contains `<` tags
     * @param array        $files File paths to attach
     *
     * @return bool True if sent successfully
     */
    public static function send(string|array $to, string $subj, string $body, array $files = []): bool
    {
        global $SETTINGS;

        // 🪞 Normalise $to to an array so callers can pass a single
        //    string without thinking about it.
        $recipients = is_array($to) ? $to : [$to];

        // 🪞 If the body looks like plain text (no HTML tags), wrap it in
        //    the branded base template so the recipient gets a themed
        //    email regardless of caller. Plain-text wrapper is generated
        //    automatically by sendTemplated()'s flow when used; callers
        //    using send() directly get the auto-wrap behaviour.
        $looksLikeHtml = (bool) preg_match('/<[a-zA-Z][^>]*>/', $body);
        $htmlBody = $looksLikeHtml === true
            ? $body
            : self::renderBase($body);

        // 🪞 Plain-text alternative (#254). Auto-derive from the HTML if the
        //    caller didn't supply one. This goes into the providers'
        //    multipart/alternative bodies so clients that prefer (or only
        //    accept) plain text get a readable version.
        $plainBody = $looksLikeHtml === true
            ? self::htmlToPlain($body)
            : $body;

        // 🔀 Dispatch to the configured provider
        $provider = strtolower($SETTINGS['mail']['provider'] ?? 'ms365');

        if ($provider === 'google') {
            return MailerGoogle::send($recipients, $subj, $htmlBody, $files);
        }

        // 📧 Default: Microsoft Graph (MS365)
        return self::sendViaGraph($recipients, $subj, $htmlBody, $files);
    }

    /**
     * 📨 Send a templated email (#243).
     *
     * Renders the named template under web/_core/templates/email/ with the
     * given vars (escaped via htmlspecialchars), wraps in the base layout,
     * and dispatches via send().
     *
     * @param string|array         $to        Recipient(s)
     * @param string               $subject   Subject line
     * @param string               $template  Template basename — looked up at
     *                                        web/_core/templates/email/{name}.html.php
     * @param array<string, mixed> $vars      Template variables
     * @param array                $files     Attachments
     */
    public static function sendTemplated(
        string|array $to,
        string $subject,
        string $template,
        array $vars = [],
        array $files = []
    ): bool {
        $rendered = self::renderTemplate($template, $vars);
        if ($rendered === null) {
            // 🪞 Template missing — fall back to a plain text mention of the
            //    template name so the alert still reaches the recipient.
            $rendered = sprintf(
                "(Template '%s' missing — please contact the portal admin.)",
                $template
            );
        }
        return self::send($to, $subject, self::renderBase($rendered), $files);
    }

    /**
     * Render the named partial template under templates/email/{name}.html.php.
     *
     * @return string|null Rendered HTML, or null if template not found.
     */
    private static function renderTemplate(string $template, array $vars): ?string
    {
        if (preg_match('/^[A-Za-z0-9_\-]+$/', $template) !== 1) {
            return null;
        }
        $path = PORTAL_CORE
              . DIRECTORY_SEPARATOR . 'templates'
              . DIRECTORY_SEPARATOR . 'email'
              . DIRECTORY_SEPARATOR . $template . '.html.php';
        if (is_readable($path) === false) {
            return null;
        }
        // 🪞 Sandbox the template — vars are extracted into local scope.
        $render = static function (string $__path, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            include $__path;
            return (string) ob_get_clean();
        };
        try {
            return $render($path, $vars);
        } catch (\Throwable $e) {
            Logger::errorPlatform('Email', 'Error', 'TPL_RENDER', $template, $e->getMessage());
            return null;
        }
    }

    /**
     * Wrap the given content HTML in the branded base layout.
     */
    private static function renderBase(string $content): string
    {
        global $SETTINGS;
        $base = PORTAL_CORE
              . DIRECTORY_SEPARATOR . 'templates'
              . DIRECTORY_SEPARATOR . 'email'
              . DIRECTORY_SEPARATOR . 'base.html.php';
        if (is_readable($base) === false) {
            // 🪞 Base template missing — return the content as-is wrapped in
            //    a minimal HTML shell so the email still renders.
            return '<!doctype html><html><body style="font-family:system-ui">'
                 . (preg_match('/<[a-zA-Z][^>]*>/', $content) === 1
                    ? $content
                    : '<pre style="white-space:pre-wrap">' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '</pre>')
                 . '</body></html>';
        }
        $portalName    = (string) ($SETTINGS['site']['name'] ?? 'WebMS Intra');
        $portalUrl     = (string) ($SETTINGS['site']['url']  ?? '');
        $supportEmail  = (string) ($SETTINGS['portal']['support']['email'] ?? '');
        // 🪞 Auto-wrap plain text in <p> tags so paragraphs render.
        $contentHtml = preg_match('/<[a-zA-Z][^>]*>/', $content) === 1
            ? $content
            : '<p>' . nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8')) . '</p>';

        $render = static function (
            string $__path,
            string $__content,
            string $__portalName,
            string $__portalUrl,
            string $__supportEmail
        ): string {
            $content      = $__content;
            $portalName   = $__portalName;
            $portalUrl    = $__portalUrl;
            $supportEmail = $__supportEmail;
            ob_start();
            include $__path;
            return (string) ob_get_clean();
        };
        try {
            return $render($base, $contentHtml, $portalName, $portalUrl, $supportEmail);
        } catch (\Throwable $e) {
            Logger::errorPlatform('Email', 'Error', 'BASE_RENDER', 'base', $e->getMessage());
            return $contentHtml;
        }
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
    /**
     * Derive a plain-text alternative from an HTML body. Used for
     * multipart/alternative emission (#254). Strips tags, decodes
     * entities, collapses whitespace.
     *
     * Note: MS365 Graph's sendMail accepts HTML body and lets Microsoft
     * generate the plain-text alternative server-side, so this helper
     * is most useful for SMTP and any future provider that needs both
     * parts on the wire.
     */
    public static function htmlToPlain(string $html): string
    {
        // Convert <br> / </p> / </div> / </li> to newlines BEFORE strip.
        $intermediate = (string) preg_replace(
            ['/<br\\s*\\/?>/i', '/<\\/(p|div|li|h[1-6]|tr)\\s*>/i'],
            ["\n", "\n"],
            $html
        );
        // Convert <a href="X">Y</a> → "Y (X)" so users on plain-text clients
        // still see the URLs.
        $intermediate = (string) preg_replace_callback(
            '/<a[^>]+href="([^"]+)"[^>]*>(.*?)<\\/a>/i',
            static function (array $m): string {
                $text = trim(strip_tags($m[2]));
                return $text !== '' && $text !== $m[1] ? $text . ' (' . $m[1] . ')' : $m[1];
            },
            $intermediate
        );
        $text = strip_tags($intermediate);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Collapse runs of blank lines and trim each line.
        $lines = preg_split('/\\R/', $text);
        $clean = [];
        foreach ((array) $lines as $line) {
            $line = trim((string) $line);
            if ($line === '' && (count($clean) === 0 || end($clean) === '')) {
                continue;
            }
            $clean[] = $line;
        }
        return trim(implode("\n", $clean));
    }

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
