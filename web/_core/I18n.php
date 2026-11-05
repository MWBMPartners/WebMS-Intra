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
     * Ask the question again, now that a session exists.
     *
     * 🚨 WITHOUT THIS, THE FIRST TWO SOURCES IN `resolveLocale()` COULD NEVER
     *    BE REACHED, AND A MEMBER'S SAVED LANGUAGE WAS APPLIED NOWHERE (#763).
     *
     *    `init()` is called from `bootstrap.php`, and the session does not
     *    start until `index.php` calls `Auth::ensureSession()` — AFTER
     *    bootstrap has finished. index.php's own comment says so in terms:
     *    "No session is active when start-up finishes, in any of the three
     *    ways." So `session_status() === PHP_SESSION_ACTIVE` was false when
     *    the question was asked, both early branches were skipped, and every
     *    page was drawn in whatever `Accept-Language` said or the
     *    installation's default. The block in bootstrap that reads
     *    `tblUsers.locale` is behind the same test and could not run either.
     *
     *    Measured rather than reasoned, by driving this class:
     *    `tools/i18n-member-language-selftest.php` sets up the real order —
     *    `init()` with no session, then a session carrying a member whose
     *    `tblUsers.locale` is `cy` — and with this method removed the answer
     *    stays on whatever `Accept-Language` asked for. It has NOT been
     *    measured over HTTP against a drawn page; that needs a seeded
     *    installation and is said so in the test's own closing lines.
     *
     *    `init()` latches `$initialised` and returns early, so calling it
     *    again does nothing — which is why this is a separate method rather
     *    than a second `init()`. It is safe to call when there is still no
     *    session: the answer is then the same one `init()` already reached.
     *
     * ⚠️ IT MUST NOT BE CALLED BEFORE `init()`. The default from settings is
     *    read there, and resolving without it would fall back to the
     *    hard-coded default rather than the installation's.
     */
    public static function reconsiderNowSessionExists(?\mysqli $db = null): void
    {
        if (self::$initialised !== true) {
            self::init();
            return;
        }

        // 📖 The member's own choice, read once per session. This block used to
        //    live in `bootstrap.php` behind the same session test, so it could
        //    not run either — #174 wrapped it in a try/catch and nobody noticed
        //    it was unreachable. It is here so there is ONE place that answers
        //    "what language does this person want", rather than two that can
        //    disagree.
        //
        //    Every failure is swallowed on purpose: the `locale` column may be
        //    missing on an old database and the connection may blip. Falling
        //    back to the language already chosen is far better for somebody
        //    reading a page than a 500.
        if ($db !== null
            && session_status() === PHP_SESSION_ACTIVE
            && isset($_SESSION['user_id']) === true
            && isset($_SESSION['user_locale']) === false
        ) {
            $stored = self::storedChoice((int) $_SESSION['user_id'], $db);
            if ($stored !== null && self::isSupported($stored) === true) {
                $_SESSION['user_locale'] = $stored;
            }
        }

        self::setLocale(self::resolveLocale());
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
        return self::chooseLanguage($locale);
    }

    /**
     * 🌍 What the browser is asking for — the ONE public answer to that.
     *
     * The account page's "Follow my browser" option names this, and it has to
     * be this rather than `locale()`. **`locale()` is the language the page is
     * being drawn in, which for anybody who HAS a stored choice IS that stored
     * choice** — so a first version of that label said "Follow my browser
     * (Cymraeg today)" to a member whose browser asks for French, which is the
     * wrong language for exactly the people the option exists for. Measured:
     * stored `cy`, `Accept-Language: fr`, label said Cymraeg, answer would be
     * Français.
     *
     * @return string|null The matched code, or null when the browser asks for
     *                     nothing this portal knows — in which case the
     *                     installation's default decides and the page says so.
     */
    public static function browserLanguage(): ?string
    {
        return self::detectBrowserLocale();
    }

    /**
     * 🗣️ Read a member's stored language choice — the ONE place it is read.
     *
     * Both the resolver (`reconsiderNowSessionExists()`) and the control on the
     * account page ask this, so neither can hold its own copy of the statement.
     * `ContentTranslation::readerLanguage()` deliberately reads the column
     * itself: it answers a different question — which language to TRANSLATE
     * into — and its own docblock says why.
     *
     * 🚨 EVERY FAILURE IS SWALLOWED, AND ITS WORDS ARE NOT LOGGED. The `locale`
     *    column may be missing on a database nobody has upgraded yet, and the
     *    connection may blip. Falling back to the language already chosen is
     *    far better for somebody reading a page than a 500 — and a mysqli
     *    message can quote the value, which here is a person's own preference.
     *
     * @param int          $userId The account to ask about.
     * @param mysqli|null  $db     The request's connection, or null to take
     *                             `App::db()`.
     *
     * @return string|null The stored code, or null for "not chosen" — which is
     *                     what an empty column means on this portal (D-060).
     *                     Null is also the answer when it could not be read,
     *                     and the two cases are deliberately not told apart:
     *                     nothing a caller could do differs.
     */
    public static function storedChoice(int $userId, ?\mysqli $db = null): ?string
    {
        if ($userId <= 0) {
            return null;
        }
        try {
            $db   = $db ?? App::db();
            $stmt = $db->prepare('SELECT `locale` FROM `tblUsers` WHERE `userID` = ? LIMIT 1');
            if ($stmt === false) {
                return null;
            }
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] Could not read the stored language; '
                . 'carrying on with the language already chosen.');
            return null;
        }
        if ($row === null || $row['locale'] === null || $row['locale'] === '') {
            return null;
        }
        return (string) $row['locale'];
    }

    /**
     * 🗣️ Store a member's language choice — the ONE place it is stored, and
     *    the ONE place it is cleared.
     *
     * `switchLocale()` is a thin caller of this, so the `?lang=` address in
     * `index.php` and the control on the account page cannot disagree about
     * what choosing a language means. Do not add a second writer of
     * `tblUsers.locale`: for eight months there was exactly one, it sat where
     * no session existed, and that is the whole of #763.
     *
     * @param string|null $locale A language code, or null / '' meaning
     *                            "forget my choice and follow my browser".
     *                            An empty stored value is what "not chosen"
     *                            IS on this portal (#763, D-060), so clearing
     *                            has to be expressible or a member can never
     *                            undo a choice.
     *
     * @return bool False only when a language was asked for and the portal
     *              does not know it. Clearing always answers true.
     */
    public static function chooseLanguage(?string $locale): bool
    {
        $clearing = $locale === null || $locale === '';

        if ($clearing === false && self::isSupported((string) $locale) === false) {
            return false;
        }
        $locale = $clearing === true ? null : (string) $locale;

        // 📋 Store in session. Clearing UNSETS both keys rather than writing an
        //    empty string: `resolveLocale()` reads them with `isset()`, so an
        //    empty string left behind would be a stored "no language" that
        //    still counted as a visit-long choice, and the member's next page
        //    would not follow their browser as they just asked.
        if (session_status() === PHP_SESSION_ACTIVE) {
            if ($locale === null) {
                unset($_SESSION['portal_locale'], $_SESSION['user_locale']);
            } else {
                $_SESSION['portal_locale'] = $locale;
                $_SESSION['user_locale']   = $locale;
            }
        }

        // 📋 Remember the choice on the account, so it follows the member to
        //    their next device.
        //
        // 🚨 EVERY FAILURE IS SWALLOWED, AND THAT IS NOT TIDINESS. Until #763
        //    this statement could never run — no session was active where this
        //    method was called — so making it run made a new way to take a page
        //    down. `DbServer::connect()` sets MYSQLI_REPORT_ERROR together with
        //    MYSQLI_REPORT_STRICT, and it takes BOTH for mysqli to THROW rather
        //    than return false, so `prepare()`, `bind_param()`, `execute()` and
        //    `close()` can all throw: a missing `locale` column on an old
        //    database, a lock wait on `tblUsers` (ERROR 1205), a blip.
        //
        //    Measured on MySQL 8.0.36 against a `tblUsers` with no `locale`
        //    column: the bare version threw `mysqli_sql_exception: Unknown
        //    column 'locale' in 'field list'` straight out of this method.
        //
        //    **And this block runs before the maintenance and membership gates**
        //    (the `?lang=` caller in `index.php` is deliberately ahead of them),
        //    so that was a 500 on a language click on EVERY address, open or
        //    closed — word for word the harm the #509 comment beside that caller
        //    says it exists to prevent.
        //
        //    So: the language is still applied and still kept for this visit,
        //    which is what the member asked for. Only the remembering is lost,
        //    and a page that draws beats a page that does not.
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id']) === true) {
            try {
                $db = App::db();
                // Backticked because this is CHANGED SQL: it had never run before
                // #763, so making it run brings it under the owner's rule of
                // 2 November 2026.
                $stmt = $db->prepare('UPDATE `tblUsers` SET `locale` = ? WHERE `userID` = ?');
                if ($stmt !== false) {
                    $userId = (int) $_SESSION['user_id'];
                    // `bind_param` with 's' sends a PHP null as SQL NULL, which
                    // is what "not chosen" is (#763, D-060).
                    $stmt->bind_param('si', $locale, $userId);
                    $stmt->execute();
                    $stmt->close();
                }
            } catch (\Throwable $e) {
                // ⚠️ The fault's own words are NOT logged: a mysqli message can
                //    quote the value being written, and that value is a person's
                //    language preference.
                error_log('[WebMS-Intra] Could not remember the chosen language on '
                    . 'the account; it still applies for this visit.');
            }
        }

        // 📋 Apply immediately. When clearing, ask the whole chain again rather
        //    than assuming the default: the member has just said "follow my
        //    browser", so the browser's answer is the one to use.
        self::setLocale($locale === null ? self::resolveLocale() : $locale);
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

        // 🚪 Hide locales below the configured coverage threshold from the
        //    switcher (default 0 = show all). Admin opts in by setting
        //    portal.i18n.minimum_coverage_for_switcher to e.g. 0.95.
        //    Current locale is ALWAYS shown so the user can switch back.
        $settings = \Portal\Core\App::settings();
        $threshold = (float) ($settings['portal']['i18n']['minimum_coverage_for_switcher'] ?? 0);
        if ($threshold > 0) {
            foreach ($enabled as $code => $meta) {
                if ($code === self::$locale) {
                    continue;
                }
                if (self::coverage($code) < $threshold) {
                    unset($enabled[$code]);
                }
            }
        }

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
