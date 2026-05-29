<?php
// Path: _core/I18n.php
/**
 * -----------------------------------------------------------------------------
 * Internationalisation (i18n) Framework 🌐
 * -----------------------------------------------------------------------------
 * Provides translation, parameterisation, pluralisation, and locale management
 * for the portal. All user-facing strings are loaded from language files stored
 * in web/_lang/{locale}.php. Each file returns a flat associative array mapping
 * string keys to translated text.
 *
 * Features:
 *   - t('key')              — simple translation lookup
 *   - t('welcome', ['name' => 'John']) — parameterised string
 *   - t('items_count', ['count' => 5]) — pluralisation via | separator
 *   - Fallback chain: user locale → default locale → raw key
 *   - Accept-Language header auto-detection on first visit
 *   - Per-user locale preference (stored in tblUsers.locale)
 *   - RTL detection per locale
 *   - Date/time/number/currency formatting per locale
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.7.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class I18n
{
    // 📋 Loaded translation arrays keyed by locale code
    private static array $strings = [];

    // 📋 Current active locale (e.g. 'en', 'fr', 'ar')
    private static string $locale = 'en';

    // 📋 Default fallback locale
    private static string $defaultLocale = 'en';

    // 📋 Whether the class has been initialised
    private static bool $initialised = false;

    // 📋 Available locales with metadata
    private static array $locales = [
        'en' => ['name' => 'English',    'native' => 'English',    'dir' => 'ltr'],
        'cy' => ['name' => 'Welsh',      'native' => 'Cymraeg',    'dir' => 'ltr'],
        'fr' => ['name' => 'French',     'native' => 'Français',   'dir' => 'ltr'],
        'de' => ['name' => 'German',     'native' => 'Deutsch',    'dir' => 'ltr'],
        'es' => ['name' => 'Spanish',    'native' => 'Español',    'dir' => 'ltr'],
        'pt' => ['name' => 'Portuguese', 'native' => 'Português',  'dir' => 'ltr'],
        'ar' => ['name' => 'Arabic',     'native' => 'العربية',    'dir' => 'rtl'],
        'he' => ['name' => 'Hebrew',     'native' => 'עברית',      'dir' => 'rtl'],
        'fa' => ['name' => 'Farsi',      'native' => 'فارسی',      'dir' => 'rtl'],
        'ur' => ['name' => 'Urdu',       'native' => 'اردو',       'dir' => 'rtl'],
        'zh' => ['name' => 'Chinese',    'native' => '中文',        'dir' => 'ltr'],
        'ja' => ['name' => 'Japanese',   'native' => '日本語',      'dir' => 'ltr'],
        'ko' => ['name' => 'Korean',     'native' => '한국어',      'dir' => 'ltr'],
    ];

    /**
     * Initialise the i18n system. Called from bootstrap.php after settings load.
     *
     * Determines the active locale from (in priority order):
     *   1. User's DB preference (tblUsers.locale)
     *   2. Session-stored locale
     *   3. Accept-Language header auto-detection
     *   4. Default locale from settings
     *
     * @return void
     */
    public static function init(): void
    {
        if (self::$initialised === true) {
            return;
        }
        self::$initialised = true;

        // 📋 Load default locale from settings
        $defaultFromSettings = App::settings('i18n.defaultLocale');
        if ($defaultFromSettings !== null && $defaultFromSettings !== '') {
            self::$defaultLocale = $defaultFromSettings;
        }

        // 📋 Determine active locale (priority chain)
        $locale = self::resolveLocale();
        self::setLocale($locale);
    }

    /**
     * Resolve the active locale from available sources.
     *
     * @return string Resolved locale code
     */
    private static function resolveLocale(): string
    {
        // 1️⃣ User preference from DB (if logged in)
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id']) === true) {
            $userLocale = $_SESSION['user_locale'] ?? null;
            if ($userLocale !== null && $userLocale !== '' && self::isSupported($userLocale) === true) {
                return $userLocale;
            }
        }

        // 2️⃣ Session-stored locale (set by language switcher)
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['portal_locale']) === true) {
            $sessLocale = $_SESSION['portal_locale'];
            if (self::isSupported($sessLocale) === true) {
                return $sessLocale;
            }
        }

        // 3️⃣ Accept-Language header auto-detection
        $browserLocale = self::detectBrowserLocale();
        if ($browserLocale !== null) {
            return $browserLocale;
        }

        // 4️⃣ Default locale
        return self::$defaultLocale;
    }

    /**
     * Detect the best matching locale from the browser's Accept-Language header.
     *
     * @return string|null Matched locale code, or null if no match
     *
     * @see https://www.rfc-editor.org/rfc/rfc7231#section-5.3.5
     */
    private static function detectBrowserLocale(): ?string
    {
        $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if ($header === '') {
            return null;
        }

        // 📋 Parse Accept-Language header into weighted list
        // Format: en-GB,en;q=0.9,fr;q=0.8
        $entries = [];
        $parts = explode(',', $header);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $bits = explode(';', $part);
            $lang = strtolower(trim($bits[0]));
            $q = 1.0;
            if (isset($bits[1]) === true) {
                $qMatch = [];
                if (preg_match('/q\s*=\s*([0-9.]+)/', $bits[1], $qMatch) === 1) {
                    $q = (float) $qMatch[1];
                }
            }
            $entries[$lang] = $q;
        }

        // 📋 Sort by quality factor descending
        arsort($entries);

        // 📋 Match against supported locales
        foreach ($entries as $lang => $_q) {
            // Exact match (e.g. 'fr' matches 'fr')
            $shortLang = substr($lang, 0, 2);
            if (self::isSupported($shortLang) === true) {
                return $shortLang;
            }
        }

        return null;
    }

    /**
     * Set the active locale and load its translation file.
     *
     * @param string $locale Locale code (e.g. 'en', 'fr', 'ar')
     *
     * @return void
     */
    public static function setLocale(string $locale): void
    {
        if (self::isSupported($locale) === false) {
            $locale = self::$defaultLocale;
        }

        self::$locale = $locale;
        self::loadStrings($locale);

        // 📋 Also pre-load the default locale for fallback
        if ($locale !== self::$defaultLocale) {
            self::loadStrings(self::$defaultLocale);
        }

        // 📋 Set PHP locale for date/number formatting
        // Using .UTF-8 suffix for proper Unicode support
        $phpLocale = $locale . '_' . strtoupper($locale) . '.UTF-8';
        setlocale(LC_TIME, $phpLocale, $locale);
    }

    /**
     * Load a translation file for a given locale (if not already loaded).
     *
     * @param string $locale Locale code
     *
     * @return void
     */
    private static function loadStrings(string $locale): void
    {
        if (isset(self::$strings[$locale]) === true) {
            return;
        }

        $langDir = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_lang';
        $file    = $langDir . DIRECTORY_SEPARATOR . $locale . '.php';

        if (is_readable($file) === true) {
            $loaded = require $file;
            if (is_array($loaded) === true) {
                self::$strings[$locale] = $loaded;
                return;
            }
        }

        // 📋 File not found or invalid — initialise empty array
        self::$strings[$locale] = [];
    }

    /**
     * Translate a string key with optional parameter substitution and pluralisation.
     *
     * Parameterisation: t('welcome', ['name' => 'John']) replaces :name in the string.
     *
     * Pluralisation: Use | to separate singular and plural forms in the translation.
     * The 'count' parameter selects which form to use:
     *   'items_count' => 'One item|:count items'
     *   t('items_count', ['count' => 1])  → 'One item'
     *   t('items_count', ['count' => 5])  → '5 items'
     *
     * Three-form pluralisation (zero|one|many):
     *   'items_count' => 'No items|One item|:count items'
     *   t('items_count', ['count' => 0])  → 'No items'
     *
     * @param string $key    Translation key (dot-notation supported: 'auth.login_title')
     * @param array  $params Replacement parameters (key => value)
     *
     * @return string Translated string, or the raw key if not found
     */
    public static function t(string $key, array $params = []): string
    {
        // 📋 Ensure initialisation
        if (self::$initialised === false) {
            self::init();
        }

        // 📋 Look up in current locale, then fallback locale
        $text = self::$strings[self::$locale][$key]
             ?? self::$strings[self::$defaultLocale][$key]
             ?? $key;

        // 📋 Handle pluralisation (pipe-separated forms)
        if (str_contains($text, '|') === true && array_key_exists('count', $params) === true) {
            $text = self::pluralise($text, (int) $params['count']);
        }

        // 📋 Parameter substitution (:name → value)
        foreach ($params as $paramKey => $paramValue) {
            $text = str_replace(':' . $paramKey, (string) $paramValue, $text);
        }

        return $text;
    }

    /**
     * Select the correct plural form from a pipe-separated string.
     *
     * Two forms: singular|plural (count=1 → singular, else → plural)
     * Three forms: zero|singular|plural (count=0 → zero, count=1 → singular, else → plural)
     *
     * @param string $text  Pipe-separated forms
     * @param int    $count The count value
     *
     * @return string Selected form
     */
    private static function pluralise(string $text, int $count): string
    {
        $forms = explode('|', $text);

        if (count($forms) === 3) {
            // zero | one | many
            if ($count === 0) {
                return $forms[0];
            }
            if ($count === 1) {
                return $forms[1];
            }
            return $forms[2];
        }

        if (count($forms) === 2) {
            // one | many
            return ($count === 1) ? $forms[0] : $forms[1];
        }

        return $text;
    }

    /**
     * Get the current active locale code.
     *
     * @return string Locale code (e.g. 'en', 'fr')
     */
    public static function locale(): string
    {
        return self::$locale;
    }

    /**
     * Get the default fallback locale code.
     *
     * @return string Default locale code
     */
    public static function defaultLocale(): string
    {
        return self::$defaultLocale;
    }

    /**
     * Check if a locale code is supported (has metadata entry).
     *
     * @param string $locale Locale code to check
     *
     * @return bool True if the locale is in the supported list
     */
    public static function isSupported(string $locale): bool
    {
        return array_key_exists($locale, self::$locales);
    }

    /**
     * Check if the current locale is RTL (right-to-left).
     *
     * @return bool True if the current locale uses RTL text direction
     */
    public static function isRtl(): bool
    {
        return (self::$locales[self::$locale]['dir'] ?? 'ltr') === 'rtl';
    }

    /**
     * Get the text direction for the current locale ('ltr' or 'rtl').
     *
     * @return string 'ltr' or 'rtl'
     */
    public static function dir(): string
    {
        return self::$locales[self::$locale]['dir'] ?? 'ltr';
    }

    /**
     * Get the list of all supported locales with their metadata.
     *
     * @return array Associative array of locale code => metadata
     */
    public static function availableLocales(): array
    {
        return self::$locales;
    }

    /**
     * Get metadata for a specific locale.
     *
     * @param string $locale Locale code
     *
     * @return array|null Locale metadata or null if not supported
     */
    public static function localeMeta(string $locale): ?array
    {
        return self::$locales[$locale] ?? null;
    }

    /**
     * Get enabled locales (those with a translation file present).
     *
     * @return array Associative array of available locale code => metadata
     */
    public static function enabledLocales(): array
    {
        $langDir = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_lang';
        $enabled = [];

        foreach (self::$locales as $code => $meta) {
            $file = $langDir . DIRECTORY_SEPARATOR . $code . '.php';
            if (is_readable($file) === true) {
                $enabled[$code] = $meta;
            }
        }

        return $enabled;
    }

    /**
     * Switch the current user's locale. Stores in session and (if logged in) DB.
     *
     * @param string $locale New locale code
     *
     * @return bool True if the locale was set successfully
     */
    public static function switchLocale(string $locale): bool
    {
        if (self::isSupported($locale) === false) {
            return false;
        }

        // 📋 Store in session
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['portal_locale'] = $locale;
            $_SESSION['user_locale']   = $locale;
        }

        // 📋 Persist to DB if user is logged in
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id']) === true) {
            $db = App::db();
            $stmt = $db->prepare('UPDATE tblUsers SET locale = ? WHERE userID = ?');
            if ($stmt !== false) {
                $userId = (int) $_SESSION['user_id'];
                $stmt->bind_param('si', $locale, $userId);
                $stmt->execute();
                $stmt->close();
            }
        }

        // 📋 Apply immediately
        self::setLocale($locale);
        return true;
    }

    /**
     * Format a date according to the current locale.
     *
     * @param string $date   Date string (Y-m-d or datetime)
     * @param string $format Format key: 'short', 'medium', 'long', or a PHP date format
     *
     * @return string Formatted date
     */
    public static function formatDate(string $date, string $format = 'medium'): string
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return $date;
        }

        // 🔧 Check configurable settings first (display.dateFormat overrides 'medium')
        if ($format === 'medium') {
            global $SETTINGS;
            $configuredFormat = $SETTINGS['display']['dateFormat'] ?? '';
            if ($configuredFormat !== '') {
                return date($configuredFormat, $timestamp);
            }
        }

        // 📋 Use translation keys for date formats
        $formatKey = 'format.date.' . $format;
        $phpFormat = self::t($formatKey);

        // 📋 If no translation found, use defaults
        if ($phpFormat === $formatKey) {
            $defaults = [
                'short'  => 'd/m/Y',
                'medium' => 'j M Y',
                'long'   => 'l, j F Y',
            ];
            $phpFormat = $defaults[$format] ?? $format;
        }

        return date($phpFormat, $timestamp);
    }

    /**
     * Format a date and time according to the current locale.
     *
     * @param string $datetime Datetime string
     * @param string $format   Format key: 'short', 'medium', 'long'
     *
     * @return string Formatted datetime
     */
    public static function formatDateTime(string $datetime, string $format = 'medium'): string
    {
        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return $datetime;
        }

        // 🔧 Check configurable settings first (display.dateTimeFormat overrides 'medium')
        if ($format === 'medium') {
            global $SETTINGS;
            $configuredFormat = $SETTINGS['display']['dateTimeFormat'] ?? '';
            if ($configuredFormat !== '') {
                return date($configuredFormat, $timestamp);
            }
        }

        $formatKey = 'format.datetime.' . $format;
        $phpFormat = self::t($formatKey);

        if ($phpFormat === $formatKey) {
            $defaults = [
                'short'  => 'd/m/Y H:i',
                'medium' => 'j M Y, H:i',
                'long'   => 'l, j F Y \a\t H:i',
            ];
            $phpFormat = $defaults[$format] ?? $format;
        }

        return date($phpFormat, $timestamp);
    }

    /**
     * Format a number according to the current locale.
     *
     * @param float|int $number   The number to format
     * @param int       $decimals Number of decimal places
     *
     * @return string Formatted number
     */
    public static function formatNumber(float|int $number, int $decimals = 0): string
    {
        $decPoint  = self::t('format.decimal_point');
        $thousSep  = self::t('format.thousands_separator');

        // 📋 Use defaults if translation keys not found
        if ($decPoint === 'format.decimal_point') {
            $decPoint = '.';
        }
        if ($thousSep === 'format.thousands_separator') {
            $thousSep = ',';
        }

        return number_format($number, $decimals, $decPoint, $thousSep);
    }

    /**
     * Format a currency amount according to the current locale.
     *
     * @param float|int $amount   Amount in major units
     * @param string    $currency Currency code (e.g. 'GBP', 'USD', 'EUR')
     *
     * @return string Formatted currency string
     */
    public static function formatCurrency(float|int $amount, string $currency = ''): string
    {
        if ($currency === '') {
            $currency = App::settings('site.currency') ?? 'GBP';
        }

        // 📋 Currency symbols
        $symbols = [
            'GBP' => '£', 'USD' => '$', 'EUR' => '€', 'JPY' => '¥',
            'CAD' => 'CA$', 'AUD' => 'A$', 'CHF' => 'CHF ',
        ];
        $symbol = $symbols[$currency] ?? $currency . ' ';

        $formatted = self::formatNumber((float) $amount, 2);

        // 📋 Some locales put symbol after the number
        $symbolPosition = self::t('format.currency_position');
        if ($symbolPosition === 'after') {
            return $formatted . $symbol;
        }

        return $symbol . $formatted;
    }

    /**
     * Render the language switcher dropdown HTML.
     * Used in the nav bar for changing locale.
     *
     * @return string HTML for the language switcher
     */
    public static function languageSwitcher(): string
    {
        $enabled = self::enabledLocales();
        if (count($enabled) <= 1) {
            return ''; // 📋 No switcher needed if only one language available
        }

        $current     = self::$locale;
        $currentMeta = self::$locales[$current] ?? ['native' => $current];

        $html  = '<div class="dropdown">';
        $html .= '<button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" ';
        $html .= 'data-bs-toggle="dropdown" aria-expanded="false" aria-label="' . htmlspecialchars(self::t('nav.change_language'), ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<i class="fa-solid fa-globe me-1"></i>';
        $html .= htmlspecialchars($currentMeta['native'], ENT_QUOTES, 'UTF-8');
        $html .= '</button>';
        $html .= '<ul class="dropdown-menu dropdown-menu-end">';

        foreach ($enabled as $code => $meta) {
            $active = ($code === $current) ? ' active' : '';
            $html .= '<li>';
            $html .= '<a class="dropdown-item' . $active . '" href="?lang=' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '">';
            $html .= htmlspecialchars($meta['native'], ENT_QUOTES, 'UTF-8');
            $html .= ' <span class="text-muted small">(' . htmlspecialchars($meta['name'], ENT_QUOTES, 'UTF-8') . ')</span>';

            // 🌐 Partial-coverage indicator — see #210. Locales with < 95%
            //    parity to en.php get a badge so users know to expect
            //    English fallbacks for some strings.
            $coverage = self::coverage($code);
            if ($coverage < 0.95) {
                $percent  = (int) round($coverage * 100);
                $tooltip  = htmlspecialchars(
                    self::t('i18n.partial_coverage_tooltip', ['percent' => $percent]),
                    ENT_QUOTES,
                    'UTF-8'
                );
                $html .= ' <span class="badge bg-warning text-dark ms-1" title="' . $tooltip . '">' . $percent . '%</span>';
            }

            if ($code === $current) {
                $html .= ' <i class="fa-solid fa-check ms-1"></i>';
            }
            $html .= '</a></li>';
        }

        $html .= '</ul></div>';
        return $html;
    }

    /**
     * Compute translation coverage for a locale relative to the default (en).
     *
     * Returns the fraction of en.php keys that have a matching entry in the
     * given locale's strings file. Used by `languageSwitcher()` to badge
     * locales that aren't at 100% parity so users know to expect English
     * fallbacks for missing keys.
     *
     * Note: this triggers `loadStrings()` for every locale it's called on,
     * which is fine for a per-page render (results cache in `self::$strings`)
     * but you wouldn't want to call this on every `t()` invocation.
     *
     * @param string $locale Locale code (e.g. 'cy', 'fr')
     * @return float Coverage ratio in [0.0, 1.0]. 1.0 = at parity with en.php.
     */
    public static function coverage(string $locale): float
    {
        if ($locale === 'en') {
            return 1.0;
        }
        self::loadStrings('en');
        self::loadStrings($locale);
        $en    = self::$strings['en']    ?? [];
        $other = self::$strings[$locale] ?? [];
        $total = count($en);
        if ($total === 0) {
            return 1.0; // 🛡️ Avoid div-by-zero on a missing baseline.
        }
        $present = 0;
        foreach ($en as $key => $_) {
            if (array_key_exists($key, $other) === true) {
                $present++;
            }
        }
        return $present / $total;
    }
}
