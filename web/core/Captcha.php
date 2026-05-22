<?php
// Path: core/Captcha.php
/**
 * -----------------------------------------------------------------------------
 * Centralised Captcha Helper 🤖
 * -----------------------------------------------------------------------------
 * Provider-agnostic captcha helper.  At call sites you don't care which
 * provider is configured — you just call:
 *
 *   Captcha::scriptTag()             // <script> tag(s) for the active provider
 *   Captcha::widget()                // widget markup (or invisible hidden input for v3)
 *   Captcha::verify($_POST)          // server-side verification
 *   Captcha::isConfigured()          // true if at least one provider is wired up
 *   Captcha::activeProvider()        // the provider key currently in effect (or '')
 *
 * Supported providers:
 *   • turnstile     Cloudflare Turnstile
 *   • hcaptcha      hCaptcha
 *   • recaptcha     Google reCAPTCHA — v2 (checkbox) or v3 (score-based)
 *                   (controlled by setting `auth.recaptcha.version`)
 *
 * Provider priority:
 *   Driven by setting `auth.captcha.priority` — a comma-separated list of
 *   provider keys (lowercase).  The first provider in the list with both a
 *   site key AND a secret key configured is used.
 *
 *   Default priority:  turnstile, recaptcha, hcaptcha
 *
 *   Admins can re-order via the drag-and-drop UI at /admin/captcha which
 *   POSTs the new order into this same setting.
 *
 * Setting keys consumed:
 *
 *   auth.captcha.priority           (string) e.g. "turnstile,recaptcha,hcaptcha"
 *
 *   auth.turnstile.siteKey
 *   auth.turnstile.secretKey
 *
 *   auth.recaptcha.siteKey
 *   auth.recaptcha.secretKey
 *   auth.recaptcha.version          "v2" (default) or "v3"
 *   auth.recaptcha.v3.action        action name (default "submit"), v3 only
 *   auth.recaptcha.v3.threshold     min score 0.0-1.0 (default 0.5), v3 only
 *
 *   auth.hcaptcha.siteKey
 *   auth.hcaptcha.secretKey
 *
 * Graceful degradation:
 *   If no provider is configured, scriptTag() / widget() return empty strings
 *   and verify() returns true (no captcha → request proceeds).
 * -----------------------------------------------------------------------------
 * @package    Portal\Core
 * @author     MWBM Partners Ltd (t/a MWservices)
 * @copyright  2025-present MWBM Partners Ltd (t/a MWservices)
 * @license    All Rights Reserved
 * @version    0.10.0
 * @link       https://github.com/MWBMPartners/WebMS-Intra
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Captcha
{
    // 🌐 Provider identifiers
    public const PROVIDER_TURNSTILE = 'turnstile';
    public const PROVIDER_RECAPTCHA = 'recaptcha';
    public const PROVIDER_HCAPTCHA  = 'hcaptcha';

    /** @var string[] Default provider priority — applied when the setting is empty */
    private const DEFAULT_PRIORITY = [
        self::PROVIDER_TURNSTILE,
        self::PROVIDER_RECAPTCHA,
        self::PROVIDER_HCAPTCHA,
    ];

    // 🌐 Endpoint URLs
    private const TURNSTILE_SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
    private const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    private const TURNSTILE_POST_FIELD = 'cf-turnstile-response';

    private const RECAPTCHA_SCRIPT_URL = 'https://www.google.com/recaptcha/api.js';
    private const RECAPTCHA_VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';
    private const RECAPTCHA_POST_FIELD = 'g-recaptcha-response';

    private const HCAPTCHA_SCRIPT_URL  = 'https://js.hcaptcha.com/1/api.js';
    private const HCAPTCHA_VERIFY_URL  = 'https://hcaptcha.com/siteverify';
    private const HCAPTCHA_POST_FIELD  = 'h-captcha-response';

    /** @var string|null Cached active provider key for the current request lifecycle */
    private static ?string $cachedActive = null;

    // 🏗️ ===========================================================================
    // Public API
    // ===========================================================================

    /**
     * Return the active provider key for this request, or empty string if none configured.
     *
     * Walks the priority list and returns the first provider with both siteKey and
     * secretKey set.  Cached for the duration of the request.
     *
     * @return string One of PROVIDER_* constants, or '' if no provider is configured
     */
    public static function activeProvider(): string
    {
        if (self::$cachedActive !== null) {
            return self::$cachedActive;
        }

        global $SETTINGS;
        $priority = self::resolvePriority($SETTINGS);

        foreach ($priority as $provider) {
            if (self::isProviderConfigured($provider, $SETTINGS) === true) {
                self::$cachedActive = $provider;
                return $provider;
            }
        }

        self::$cachedActive = '';
        return '';
    }

    /**
     * Return the <script> tag(s) required by the active captcha provider.
     *
     * For reCAPTCHA v3 the script URL includes ?render=SITE_KEY so the v3
     * runtime is initialised immediately.  All script tags are async+defer.
     *
     * @return string HTML <script> tag, or empty string if no provider is active
     */
    public static function scriptTag(): string
    {
        global $SETTINGS;
        $active = self::activeProvider();

        if ($active === self::PROVIDER_TURNSTILE) {
            return '<script src="' . self::esc(self::TURNSTILE_SCRIPT_URL) . '" async defer></script>';
        }

        if ($active === self::PROVIDER_HCAPTCHA) {
            return '<script src="' . self::esc(self::HCAPTCHA_SCRIPT_URL) . '" async defer></script>';
        }

        if ($active === self::PROVIDER_RECAPTCHA) {
            $version = self::setting($SETTINGS, 'auth', 'recaptcha', 'version');
            if ($version === 'v3') {
                $siteKey = self::setting($SETTINGS, 'auth', 'recaptcha', 'siteKey');
                // 📌 v3 needs the site key on the script URL to initialise grecaptcha
                $url = self::RECAPTCHA_SCRIPT_URL . '?render=' . rawurlencode($siteKey);
                return '<script src="' . self::esc($url) . '" async defer></script>';
            }
            // 🔲 v2 (default) — plain script
            return '<script src="' . self::esc(self::RECAPTCHA_SCRIPT_URL) . '" async defer></script>';
        }

        return '';
    }

    /**
     * Return the HTML widget markup for the active captcha provider.
     *
     * Turnstile / hCaptcha / reCAPTCHA v2:  visible widget div.
     * reCAPTCHA v3:                          invisible — hidden input + inline JS
     *                                        that auto-fetches a token on page load
     *                                        and refreshes it every ~110s (token
     *                                        TTL is 120s).
     *
     * @return string HTML, or empty string if no provider is active
     */
    public static function widget(): string
    {
        global $SETTINGS;
        $active = self::activeProvider();

        if ($active === self::PROVIDER_TURNSTILE) {
            $siteKey = self::setting($SETTINGS, 'auth', 'turnstile', 'siteKey');
            return '<div class="cf-turnstile" data-sitekey="' . self::esc($siteKey) . '"></div>';
        }

        if ($active === self::PROVIDER_HCAPTCHA) {
            $siteKey = self::setting($SETTINGS, 'auth', 'hcaptcha', 'siteKey');
            return '<div class="h-captcha" data-sitekey="' . self::esc($siteKey) . '"></div>';
        }

        if ($active === self::PROVIDER_RECAPTCHA) {
            $version = self::setting($SETTINGS, 'auth', 'recaptcha', 'version');
            $siteKey = self::setting($SETTINGS, 'auth', 'recaptcha', 'siteKey');

            if ($version === 'v3') {
                $action  = self::setting($SETTINGS, 'auth', 'recaptcha', 'v3.action');
                if ($action === '') {
                    $action = 'submit';
                }
                // 🕶️ Invisible widget: hidden input fed by grecaptcha.execute()
                // The script is loaded with ?render=siteKey so grecaptcha is auto-initialised.
                return ''
                    . '<input type="hidden" name="' . self::esc(self::RECAPTCHA_POST_FIELD)
                    . '" id="g-recaptcha-response">'
                    . '<script>(function(){'
                    . 'function exec(){if(typeof grecaptcha==="undefined"||!grecaptcha.ready){return;}'
                    . 'grecaptcha.ready(function(){'
                    . 'grecaptcha.execute(' . json_encode($siteKey) . ',{action:' . json_encode($action) . '})'
                    . '.then(function(t){var f=document.getElementById("g-recaptcha-response");if(f){f.value=t;}});'
                    . '});}'
                    . 'exec();setInterval(exec,110000);'
                    . '})();</script>';
            }

            // 🔲 v2 checkbox widget
            return '<div class="g-recaptcha" data-sitekey="' . self::esc($siteKey) . '"></div>';
        }

        return '';
    }

    /**
     * Verify the captcha response submitted by the client.
     *
     * Returns true when the active provider's verification succeeded, OR when
     * no provider is configured (graceful skip).
     *
     * @param array<string,mixed> $postData The $_POST superglobal (or equivalent)
     *
     * @return bool True if verification succeeded or no captcha is configured
     */
    public static function verify(array $postData): bool
    {
        global $SETTINGS;
        $active = self::activeProvider();

        if ($active === self::PROVIDER_TURNSTILE) {
            $secret = self::setting($SETTINGS, 'auth', 'turnstile', 'secretKey');
            return self::verifyTurnstile($secret, $postData);
        }

        if ($active === self::PROVIDER_HCAPTCHA) {
            $secret = self::setting($SETTINGS, 'auth', 'hcaptcha', 'secretKey');
            return self::verifyHcaptcha($secret, $postData);
        }

        if ($active === self::PROVIDER_RECAPTCHA) {
            $secret  = self::setting($SETTINGS, 'auth', 'recaptcha', 'secretKey');
            $version = self::setting($SETTINGS, 'auth', 'recaptcha', 'version');
            if ($version === 'v3') {
                return self::verifyRecaptchaV3($secret, $postData, $SETTINGS);
            }
            return self::verifyRecaptchaV2($secret, $postData);
        }

        // ✅ No captcha configured — allow
        return true;
    }

    /**
     * Check whether any captcha provider has keys configured.
     *
     * @return bool True if at least one provider is fully configured
     */
    public static function isConfigured(): bool
    {
        return self::activeProvider() !== '';
    }

    /**
     * Return the full set of providers we know about with their human-readable
     * names and current configuration status.  Used by the admin UI to render
     * the drag-and-drop priority list.
     *
     * @return array<int,array{key:string,label:string,configured:bool}>
     */
    public static function listProviders(): array
    {
        global $SETTINGS;
        $priority = self::resolvePriority($SETTINGS);

        // 🔍 Make sure every known provider appears in the list (in case the
        // stored priority drops one).  Known providers missing from priority
        // are appended at the end.
        $all = [
            self::PROVIDER_TURNSTILE => 'Cloudflare Turnstile',
            self::PROVIDER_HCAPTCHA  => 'hCaptcha',
            self::PROVIDER_RECAPTCHA => 'Google reCAPTCHA',
        ];
        foreach (array_keys($all) as $k) {
            if (in_array($k, $priority, true) === false) {
                $priority[] = $k;
            }
        }

        $result = [];
        foreach ($priority as $key) {
            if (isset($all[$key]) === false) {
                continue; // unknown key — skip
            }
            $result[] = [
                'key'        => $key,
                'label'      => $all[$key],
                'configured' => self::isProviderConfigured($key, $SETTINGS),
            ];
        }
        return $result;
    }

    /**
     * Validate and normalise a user-supplied priority list before it's persisted.
     *
     * Returns a comma-separated string of valid lowercase provider keys, with
     * duplicates removed and unknown values discarded.  Empty input falls back
     * to the default priority.
     *
     * @param string[]|string $input Either a comma-separated string or an array of keys
     *
     * @return string Cleaned comma-separated string suitable for storing
     */
    public static function normalisePriority(array|string $input): string
    {
        $known = [self::PROVIDER_TURNSTILE, self::PROVIDER_RECAPTCHA, self::PROVIDER_HCAPTCHA];
        $items = is_array($input) === true
            ? $input
            : explode(',', $input);

        $seen = [];
        foreach ($items as $raw) {
            $key = strtolower(trim((string) $raw));
            if ($key === '' || in_array($key, $known, true) === false) {
                continue;
            }
            if (in_array($key, $seen, true) === true) {
                continue;
            }
            $seen[] = $key;
        }
        if (count($seen) === 0) {
            return implode(',', self::DEFAULT_PRIORITY);
        }
        return implode(',', $seen);
    }

    // 🛡️ ===========================================================================
    // Provider-specific verification
    // ===========================================================================

    private static function verifyTurnstile(string $secret, array $post): bool
    {
        $token = (string) ($post[self::TURNSTILE_POST_FIELD] ?? '');
        if ($secret === '' || $token === '') {
            return false;
        }
        $json = self::postVerify(self::TURNSTILE_VERIFY_URL, $secret, $token);
        return $json !== null
            && isset($json['success']) === true
            && $json['success'] === true;
    }

    private static function verifyHcaptcha(string $secret, array $post): bool
    {
        $token = (string) ($post[self::HCAPTCHA_POST_FIELD] ?? '');
        if ($secret === '' || $token === '') {
            return false;
        }
        $json = self::postVerify(self::HCAPTCHA_VERIFY_URL, $secret, $token);
        return $json !== null
            && isset($json['success']) === true
            && $json['success'] === true;
    }

    private static function verifyRecaptchaV2(string $secret, array $post): bool
    {
        $token = (string) ($post[self::RECAPTCHA_POST_FIELD] ?? '');
        if ($secret === '' || $token === '') {
            return false;
        }
        $json = self::postVerify(self::RECAPTCHA_VERIFY_URL, $secret, $token);
        return $json !== null
            && isset($json['success']) === true
            && $json['success'] === true;
    }

    /**
     * Verify a reCAPTCHA v3 response, enforcing both action match AND score threshold.
     */
    private static function verifyRecaptchaV3(string $secret, array $post, array $settings): bool
    {
        $token = (string) ($post[self::RECAPTCHA_POST_FIELD] ?? '');
        if ($secret === '' || $token === '') {
            return false;
        }
        $json = self::postVerify(self::RECAPTCHA_VERIFY_URL, $secret, $token);
        if ($json === null || ($json['success'] ?? false) !== true) {
            return false;
        }

        // 🛡️ Action match — guards against token replay from a different page
        $expectedAction = self::setting($settings, 'auth', 'recaptcha', 'v3.action');
        if ($expectedAction === '') {
            $expectedAction = 'submit';
        }
        $actualAction = (string) ($json['action'] ?? '');
        if ($actualAction !== $expectedAction) {
            Logger::activity('CaptchaRejected', 'reCAPTCHA v3 action mismatch (expected=' . $expectedAction . ', got=' . $actualAction . ')');
            return false;
        }

        // 🎯 Score threshold
        $thresholdStr = self::setting($settings, 'auth', 'recaptcha', 'v3.threshold');
        $threshold    = $thresholdStr === '' ? 0.5 : (float) $thresholdStr;
        $score        = (float) ($json['score'] ?? 0.0);
        if ($score < $threshold) {
            Logger::activity('CaptchaRejected', 'reCAPTCHA v3 score below threshold (score=' . $score . ', threshold=' . $threshold . ')');
            return false;
        }

        return true;
    }

    // 🔧 ===========================================================================
    // Internal helpers
    // ===========================================================================

    /**
     * Read the priority setting and return a clean array of provider keys.
     *
     * Walks `auth.captcha.priority` from the global settings array.  Unknown
     * tokens, duplicates and empty entries are dropped.  Falls back to the
     * DEFAULT_PRIORITY list if the setting is empty.
     *
     * @param array<string,mixed> $settings
     *
     * @return string[]
     */
    private static function resolvePriority(array $settings): array
    {
        $raw = '';
        if (isset($settings['auth']['captcha']['priority']) === true
            && is_string($settings['auth']['captcha']['priority']) === true
        ) {
            $raw = $settings['auth']['captcha']['priority'];
        }

        $known = [self::PROVIDER_TURNSTILE, self::PROVIDER_RECAPTCHA, self::PROVIDER_HCAPTCHA];
        $items = $raw === '' ? [] : explode(',', $raw);

        $clean = [];
        foreach ($items as $token) {
            $k = strtolower(trim((string) $token));
            if ($k === '' || in_array($k, $known, true) === false) {
                continue;
            }
            if (in_array($k, $clean, true) === true) {
                continue;
            }
            $clean[] = $k;
        }

        if (count($clean) === 0) {
            return self::DEFAULT_PRIORITY;
        }
        return $clean;
    }

    /**
     * Whether a given provider has both site and secret keys set.
     */
    private static function isProviderConfigured(string $provider, array $settings): bool
    {
        $siteKey = '';
        $secret  = '';
        switch ($provider) {
            case self::PROVIDER_TURNSTILE:
                $siteKey = self::setting($settings, 'auth', 'turnstile', 'siteKey');
                $secret  = self::setting($settings, 'auth', 'turnstile', 'secretKey');
                break;
            case self::PROVIDER_RECAPTCHA:
                $siteKey = self::setting($settings, 'auth', 'recaptcha', 'siteKey');
                $secret  = self::setting($settings, 'auth', 'recaptcha', 'secretKey');
                break;
            case self::PROVIDER_HCAPTCHA:
                $siteKey = self::setting($settings, 'auth', 'hcaptcha', 'siteKey');
                $secret  = self::setting($settings, 'auth', 'hcaptcha', 'secretKey');
                break;
            default:
                return false;
        }
        return $siteKey !== '' && $secret !== '';
    }

    /**
     * Single POST helper that fires the standard siteverify request and
     * returns the decoded JSON response, or null on transport / parse failure.
     *
     * @return array<string,mixed>|null
     */
    private static function postVerify(string $url, string $secret, string $token): ?array
    {
        $response = self::curlPost($url, [
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
        if ($response === null) {
            Logger::errorPlatform('Captcha', 'Warning', 'CURL_FAIL', 'siteverify request failed', $url);
            return null;
        }
        $json = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || is_array($json) === false) {
            Logger::errorPlatform('Captcha', 'Warning', 'JSON_FAIL', 'siteverify response was not valid JSON', $response);
            return null;
        }
        return $json;
    }

    /**
     * Safely extract a nested setting value (string) from the $SETTINGS array.
     *
     * Returns '' on any missing-key / wrong-type condition.
     */
    private static function setting(array $settings, string $key1, string $key2, string $key3): string
    {
        if (isset($settings[$key1]) === false || is_array($settings[$key1]) === false) {
            return '';
        }
        if (isset($settings[$key1][$key2]) === false || is_array($settings[$key1][$key2]) === false) {
            return '';
        }

        // 🌿 Support compound third-key segments like "v3.action" (which were stored
        // under settings[key1][key2]['v3']['action'] when the dot-notation loader split them).
        if (str_contains($key3, '.') === true) {
            $parts = explode('.', $key3);
            $node  = $settings[$key1][$key2];
            foreach ($parts as $p) {
                if (is_array($node) === false || array_key_exists($p, $node) === false) {
                    return '';
                }
                $node = $node[$p];
            }
            return is_string($node) === true ? $node : '';
        }

        $value = $settings[$key1][$key2][$key3] ?? '';
        return is_string($value) === true ? $value : '';
    }

    /**
     * HTTP POST helper using cURL.
     *
     * @return string|null Response body on success, or null on failure
     */
    private static function curlPost(string $url, array $data): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $response = curl_exec($ch);
        if ($response === false) {
            $errno  = curl_errno($ch);
            $errmsg = curl_error($ch);
            Logger::errorPlatform('cURL', 'Error', (string) $errno, $errmsg, 'URL: ' . $url);
            return null;
        }
        return (string) $response;
    }

    /**
     * HTML-attribute-safe escape.
     */
    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
