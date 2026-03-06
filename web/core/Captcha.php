<?php
// Path: core/Captcha.php
/**
 * -----------------------------------------------------------------------------
 * Centralised Captcha Helper 🤖
 * -----------------------------------------------------------------------------
 * Replaces the loose captchaScriptTag(), captchaWidget(), and captchaVerify()
 * functions that were previously defined inline in the login page and duplicated
 * in the expenses submission form.
 *
 * Provider priority:  Cloudflare Turnstile  >  Google reCAPTCHA v2  >  none
 *
 * The active provider is determined by checking the global $SETTINGS array for
 * configured site key / secret key pairs:
 *
 *   Turnstile:   $SETTINGS['auth']['turnstile']['siteKey']
 *                $SETTINGS['auth']['turnstile']['secretKey']
 *
 *   reCAPTCHA:   $SETTINGS['auth']['recaptcha']['siteKey']
 *                $SETTINGS['auth']['recaptcha']['secretKey']
 *
 * If neither provider has keys configured, all methods gracefully degrade:
 *   - scriptTag() and widget() return empty strings (no markup injected)
 *   - verify() returns true (captcha is silently skipped)
 *   - isConfigured() returns false
 *
 * Server-side verification uses cURL to POST to the provider's siteverify
 * endpoint.  The cURL call is handled internally (self::curlPost) to keep
 * this class self-contained and avoid a dependency on Auth's visibility.
 *
 * Public methods:
 *   Captcha::scriptTag()           <script> tag for the active provider
 *   Captcha::widget()              HTML widget div for the active provider
 *   Captcha::verify(array $post)   server-side captcha verification
 *   Captcha::isConfigured()        true if any provider has keys set
 * -----------------------------------------------------------------------------
 * @package    Portal\Core
 * @author     MWBM Partners Ltd (t/a MWservices)
 * @copyright  2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version    0.1.0
 * @link       https://github.com/MWBMPartners/WebMS-Intra
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Captcha
{
    // 🌐 ---------------------------------------------------------------------------
    // Provider constants
    // -----------------------------------------------------------------------------

    /** @var string Turnstile client-side API script URL */
    private const TURNSTILE_SCRIPT_URL =
        'https://challenges.cloudflare.com/turnstile/v0/api.js';

    /** @var string Turnstile server-side verification endpoint */
    private const TURNSTILE_VERIFY_URL =
        'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** @var string reCAPTCHA client-side API script URL */
    private const RECAPTCHA_SCRIPT_URL =
        'https://www.google.com/recaptcha/api.js';

    /** @var string reCAPTCHA server-side verification endpoint */
    private const RECAPTCHA_VERIFY_URL =
        'https://www.google.com/recaptcha/api/siteverify';

    /** @var string POST field name used by Turnstile for the client response token */
    private const TURNSTILE_POST_FIELD = 'cf-turnstile-response';

    /** @var string POST field name used by reCAPTCHA for the client response token */
    private const RECAPTCHA_POST_FIELD = 'g-recaptcha-response';

    // 🏗️ ===========================================================================
    // Public API
    // ===========================================================================

    /**
     * Return the <script> tag required by the active captcha provider.
     *
     * The script is loaded with `async defer` to avoid blocking page rendering.
     * If no captcha provider is configured, returns an empty string so templates
     * can unconditionally echo the result without conditional guards.
     *
     * @return string HTML <script> tag, or empty string if no provider is active
     */
    public static function scriptTag(): string
    {
        global $SETTINGS;

        // 🔍 Priority 1: Cloudflare Turnstile
        $turnstileSiteKey = self::setting($SETTINGS, 'auth', 'turnstile', 'siteKey');
        if ($turnstileSiteKey !== '') {
            return '<script src="' . self::esc(self::TURNSTILE_SCRIPT_URL) . '" async defer></script>';
        }

        // 🔍 Priority 2: Google reCAPTCHA v2
        $recaptchaSiteKey = self::setting($SETTINGS, 'auth', 'recaptcha', 'siteKey');
        if ($recaptchaSiteKey !== '') {
            return '<script src="' . self::esc(self::RECAPTCHA_SCRIPT_URL) . '" async defer></script>';
        }

        // 🚫 No provider configured
        return '';
    }

    /**
     * Return the HTML widget markup for the active captcha provider.
     *
     * For Turnstile this is a <div class="cf-turnstile"> with a data-sitekey
     * attribute.  For reCAPTCHA it is a <div class="g-recaptcha"> with the
     * same attribute pattern.  Returns an empty string if no provider is active.
     *
     * @return string HTML div element, or empty string
     */
    public static function widget(): string
    {
        global $SETTINGS;

        // 🔍 Priority 1: Cloudflare Turnstile
        $turnstileSiteKey = self::setting($SETTINGS, 'auth', 'turnstile', 'siteKey');
        if ($turnstileSiteKey !== '') {
            return '<div class="cf-turnstile" data-sitekey="'
                . self::esc($turnstileSiteKey)
                . '"></div>';
        }

        // 🔍 Priority 2: Google reCAPTCHA v2
        $recaptchaSiteKey = self::setting($SETTINGS, 'auth', 'recaptcha', 'siteKey');
        if ($recaptchaSiteKey !== '') {
            return '<div class="g-recaptcha" data-sitekey="'
                . self::esc($recaptchaSiteKey)
                . '"></div>';
        }

        // 🚫 No provider configured
        return '';
    }

    /**
     * Verify the captcha response submitted by the client.
     *
     * Inspects $postData for the appropriate token field depending on the active
     * provider, then sends a server-side POST to the provider's siteverify
     * endpoint using cURL.
     *
     * Behaviour summary:
     *   - Turnstile configured  -> checks "cf-turnstile-response" field
     *   - reCAPTCHA configured   -> checks "g-recaptcha-response" field
     *   - Neither configured     -> returns true (graceful skip)
     *
     * @param array $postData The $_POST superglobal (or equivalent array)
     *
     * @return bool True if the captcha verification succeeded, or if no captcha
     *              is configured (allowing the form submission to proceed)
     */
    public static function verify(array $postData): bool
    {
        global $SETTINGS;

        // 🔍 Priority 1: Cloudflare Turnstile verification
        $turnstileSecret = self::setting($SETTINGS, 'auth', 'turnstile', 'secretKey');
        if ($turnstileSecret !== '') {
            return self::verifyTurnstile($turnstileSecret, $postData);
        }

        // 🔍 Priority 2: Google reCAPTCHA verification
        $recaptchaSecret = self::setting($SETTINGS, 'auth', 'recaptcha', 'secretKey');
        if ($recaptchaSecret !== '') {
            return self::verifyRecaptcha($recaptchaSecret, $postData);
        }

        // ✅ No captcha configured – allow the request to proceed
        return true;
    }

    /**
     * Check whether any captcha provider has keys configured.
     *
     * Returns true if either Turnstile or reCAPTCHA has both a site key AND
     * a secret key set to non-empty values.  Useful for conditionally showing
     * captcha-related UI hints or toggling form validation rules.
     *
     * @return bool True if at least one provider is fully configured
     */
    public static function isConfigured(): bool
    {
        global $SETTINGS;

        // 🔍 Check Turnstile keys
        $turnstileSite   = self::setting($SETTINGS, 'auth', 'turnstile', 'siteKey');
        $turnstileSecret = self::setting($SETTINGS, 'auth', 'turnstile', 'secretKey');
        if ($turnstileSite !== '' && $turnstileSecret !== '') {
            return true;
        }

        // 🔍 Check reCAPTCHA keys
        $recaptchaSite   = self::setting($SETTINGS, 'auth', 'recaptcha', 'siteKey');
        $recaptchaSecret = self::setting($SETTINGS, 'auth', 'recaptcha', 'secretKey');
        if ($recaptchaSite !== '' && $recaptchaSecret !== '') {
            return true;
        }

        return false;
    }

    // 🛡️ ===========================================================================
    // Provider-specific verification
    // ===========================================================================

    /**
     * Verify a Cloudflare Turnstile response token.
     *
     * Extracts the "cf-turnstile-response" field from the POST data and sends
     * it to Cloudflare's siteverify endpoint along with the secret key and the
     * visitor's IP address.
     *
     * @param string $secretKey The Turnstile secret key from settings
     * @param array  $postData  The $_POST array containing the turnstile token
     *
     * @return bool True if the token is valid
     */
    private static function verifyTurnstile(string $secretKey, array $postData): bool
    {
        // 📝 Extract the client-side token from POST data
        $token = $postData[self::TURNSTILE_POST_FIELD] ?? '';
        if ($token === '') {
            // 🚫 No token submitted – the widget was likely not completed
            return false;
        }

        // 🌐 POST to Cloudflare's verification endpoint
        $response = self::curlPost(self::TURNSTILE_VERIFY_URL, [
            'secret'   => $secretKey,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        // 🔍 Parse the JSON response and check the success flag
        if ($response === null) {
            // 📝 Log the cURL failure – treat as verification failure
            Logger::errorPlatform('Captcha', 'Warning', 'CURL_FAIL', 'Turnstile verify request failed', '');
            return false;
        }

        $json = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Logger::errorPlatform('Captcha', 'Warning', 'JSON_FAIL', 'Turnstile response was not valid JSON', $response);
            return false;
        }

        return isset($json['success']) && $json['success'] === true;
    }

    /**
     * Verify a Google reCAPTCHA v2 response token.
     *
     * Extracts the "g-recaptcha-response" field from the POST data and sends
     * it to Google's siteverify endpoint along with the secret key and the
     * visitor's IP address.
     *
     * @param string $secretKey The reCAPTCHA secret key from settings
     * @param array  $postData  The $_POST array containing the reCAPTCHA token
     *
     * @return bool True if the token is valid
     */
    private static function verifyRecaptcha(string $secretKey, array $postData): bool
    {
        // 📝 Extract the client-side token from POST data
        $token = $postData[self::RECAPTCHA_POST_FIELD] ?? '';
        if ($token === '') {
            // 🚫 No token submitted – the widget was likely not completed
            return false;
        }

        // 🌐 POST to Google's verification endpoint
        $response = self::curlPost(self::RECAPTCHA_VERIFY_URL, [
            'secret'   => $secretKey,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        // 🔍 Parse the JSON response and check the success flag
        if ($response === null) {
            Logger::errorPlatform('Captcha', 'Warning', 'CURL_FAIL', 'reCAPTCHA verify request failed', '');
            return false;
        }

        $json = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Logger::errorPlatform('Captcha', 'Warning', 'JSON_FAIL', 'reCAPTCHA response was not valid JSON', $response);
            return false;
        }

        // 📝 Google returns success as a boolean true
        return isset($json['success']) && $json['success'] === true;
    }

    // 🔧 ===========================================================================
    // Internal helpers
    // ===========================================================================

    /**
     * Safely extract a nested setting value from the $SETTINGS array.
     *
     * Walks through up to three levels of keys and returns the string value,
     * or an empty string if any level is missing or the final value is empty.
     *
     * @param array  $settings The global $SETTINGS multidimensional array
     * @param string $key1     First-level key  (e.g. "auth")
     * @param string $key2     Second-level key (e.g. "turnstile")
     * @param string $key3     Third-level key  (e.g. "siteKey")
     *
     * @return string The setting value, or empty string if not found
     */
    private static function setting(array $settings, string $key1, string $key2, string $key3): string
    {
        if (isset($settings[$key1]) === false || is_array($settings[$key1]) === false) {
            return '';
        }
        if (isset($settings[$key1][$key2]) === false || is_array($settings[$key1][$key2]) === false) {
            return '';
        }
        $value = $settings[$key1][$key2][$key3] ?? '';
        if (is_string($value) === false) {
            return '';
        }
        return $value;
    }

    /**
     * Execute an HTTP POST request using cURL.
     *
     * This is a self-contained cURL wrapper so that Captcha does not depend on
     * Auth::curlPost() (which is currently declared private in Auth.php).  The
     * implementation mirrors the pattern used across the portal.
     *
     * @param string $url  The endpoint URL to POST to
     * @param array  $data Associative array of POST fields
     *
     * @return string|null Response body on success, or null on failure
     */
    private static function curlPost(string $url, array $data): ?string
    {
        // 🌐 Initialise the cURL handle
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        // 📝 Configure cURL options
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        // 🔐 Ensure SSL certificate verification is enabled (security best practice)
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        // 🚀 Execute the request
        $response = curl_exec($ch);

        // 🔍 Check for cURL errors
        if ($response === false) {
            $errno  = curl_errno($ch);
            $errmsg = curl_error($ch);
            curl_close($ch);
            Logger::errorPlatform(
                'cURL',
                'Error',
                (string) $errno,
                $errmsg,
                'URL: ' . $url
            );
            return null;
        }

        curl_close($ch);
        return (string) $response;
    }

    /**
     * Escape a string for safe use inside an HTML attribute value.
     *
     * @param string $value Raw string
     *
     * @return string HTML-safe string
     */
    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
