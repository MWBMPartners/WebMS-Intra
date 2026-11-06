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
            // `false` means the column could not be read. Here that is treated
            // exactly like "has not chosen", which is right: this method only
            // decides which language to draw a page in, and the language
            // already chosen is the safe answer. A caller that WRITES must not
            // do this — see the account page.
            $stored = self::storedChoice((int) $_SESSION['user_id'], $db);
            if (is_string($stored) === true && self::isSupported($stored) === true) {
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
     * 🚨 THREE ANSWERS, NOT TWO, AND THE THIRD EXISTS BECAUSE CONFLATING IT
     *    WITH THE SECOND DELETED PEOPLE'S CHOICES. A first version answered
     *    null for both "has not chosen" and "could not be read", with a
     *    docblock asserting that "nothing a caller could do differs". The
     *    account page could, and the cost was real: after a failed read it drew
     *    "Follow my browser" as the selected option, and the member's next Save
     *    — for any unrelated reason, a telephone number — posted an empty
     *    language and wrote SQL NULL over the choice they still had. Silently.
     *
     *    **A reassuring sentence in a docblock is how that survived review
     *    twice.** Measured against a real `tblUsers` with no `locale` column:
     *    both cases came back null and were indistinguishable.
     *
     * @param int          $userId The account to ask about.
     * @param mysqli|null  $db     The request's connection, or null to take
     *                             `App::db()`.
     *
     * @return string|false|null The stored code; null for "has not chosen",
     *                           which is what an empty column means on this
     *                           portal (D-060); or **false for "could not be
     *                           read"**. A caller that writes must treat false
     *                           as "do not touch it", and a caller that only
     *                           resolves a language may treat it like null.
     */
    public static function storedChoice(int $userId, ?\mysqli $db = null)
    {
        if ($userId <= 0) {
            return null;
        }
        try {
            $db   = $db ?? App::db();
            $stmt = $db->prepare('SELECT `locale` FROM `tblUsers` WHERE `userID` = ? LIMIT 1');
            if ($stmt === false) {
                // A refused prepare is "could not be read", never "has not
                // chosen" — see the three answers in the docblock.
                return false;
            }
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] Could not read the stored language; '
                . 'carrying on with the language already chosen.');
            return false;
        }
        if ($row === null) {
            // No such account. That is an answer, not a failure: there is
            // nothing stored because there is nobody to store it for.
            return null;
        }
        if ($row['locale'] === null || $row['locale'] === '') {
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
     * 🕐 THE ONE PLACE A POSTED TIME BECOMES A STORED WALL-CLOCK READING.
     *
     * Three handlers write a wall-clock column from something somebody typed
     * or sent -- `_apps/calendar/submit-save.php` (a form open to the public),
     * `_apps/events/api/create.php` and `_apps/events/api/update.php` -- and
     * `_apps/calendar/manage/import.php` uses it for a CSV row. Each had its
     * own copy of this rule for about an hour, which is #768's shape (five
     * copies of one rule, no two agreeing), so it lives here instead, BEFORE
     * that could start.
     *
     * ⚠️ IT BUILDS NO MOMENT, which is the whole point. These columns hold a
     *    reading from a clock. Giving such a reading a date and a zone makes
     *    it a moment, and on the morning the clocks go forward the hour it
     *    names does not EXIST, so PHP moves it -- **even when the zone is the
     *    correct one.** Measured:
     *
     *        2026-03-29T01:30 in Europe/London     -> 2026-03-29 02:30:00
     *        2026-03-08T02:30 in America/New_York  -> 2026-03-08 03:30:00
     *
     *    So "we passed the right zone" is not a defence; building a moment at
     *    all is the fault. That cost three live write paths -- a room booking,
     *    a public event submission and the events API (#777 rounds 2, 5, 6).
     *
     * ⚠️ AND IT CHECKS THE RANGE, NOT ONLY THE SHAPE. `\d{2}` accepts `25`,
     *    and `2026-02-31` matches any shape pattern, so `checkdate()` is what
     *    makes this a validator rather than a sieve. `strtotime()` used to
     *    accept `2026-02-31` and roll it silently forward to 3 March.
     *
     * @param  string $posted What arrived: `Y-m-d\TH:i` or `Y-m-d H:i`, with
     *                        or without seconds. A `datetime-local` input
     *                        sends one of the first two.
     *
     * @return string|null The reading as `Y-m-d H:i:s`, EXACTLY as sent, or
     *                     null when it is not a real date and time. Null means
     *                     refuse; never fall back to building a moment.
     */
    public static function wallClockFromInput(string $posted): ?string
    {
        $clock = trim($posted);
        // 📆 ISO 8601 *BASIC* FORM FIRST: `20260601T100000`, no separators.
        //    **This is this portal's OWN iCalendar wire format** -- `Ical.php`,
        //    `calendar/export.php` and `IcsReader.php` all write and read
        //    `Ymd\THis` -- so a script that reads our own `.ics` feed and
        //    pushes the events back through `/api/events/create` worked until
        //    round 8 and then got a 400. Round 8 required the same shape on
        //    the offset side, which was right, and tightened more than it
        //    recorded: `20260601T100000Z` and `20260601T100000+0200` are
        //    genuine, unambiguous instants, and by this package's own standard
        //    (see `inputCarriesOffset()` on the lower-case `z`) refusing them
        //    was wrong rather than merely stricter. Found in round 9 of #777.
        //
        //    Normalised rather than accepted separately, so there is still ONE
        //    shape rule below. `\d{8}T\d{6}` and `\d{8}T\d{4}` only; a bare
        //    `20260601` is NOT normalised, because an eight-digit number is
        //    just as likely to be something else and nothing here sends one.
        $clock = (string) preg_replace_callback(
            '/^(\d{4})(\d{2})(\d{2})[Tt](\d{2})(\d{2})(\d{2})?$/',
            static function (array $m): string {
                return $m[1] . '-' . $m[2] . '-' . $m[3] . ' '
                    . $m[4] . ':' . $m[5] . ':' . (($m[6] ?? '') !== '' ? $m[6] : '00');
            },
            $clock
        );
        // 🔤 THE SEPARATOR IS CASE-INSENSITIVE, because RFC 3339's own ABNF
        //    note allows a lower-case `t` and `z`. The first version matched
        //    only a capital `T`, so a standards-conforming client sending
        //    `2026-06-01t10:00:00` was refused with a 400 (round 7 of #777).
        $clock = (string) preg_replace('/^(\d{4}-\d{2}-\d{2})[Tt]/', '$1 ', $clock);
        // ⏱️ FRACTIONAL SECONDS ARE DROPPED, not refused. A JavaScript client
        //    sends `...10:00:00.000` as a matter of course (`toISOString()`),
        //    and this column holds whole seconds, so the fraction is simply
        //    not expressible. Refusing it rejected an ordinary integration.
        //
        // 🚨 IT MUST FOLLOW `:MM` OR `:SS`, AND THE FIRST VERSION DID NOT.
        //    `/\.\d+$/` matched the fraction on ANY component, and ISO 8601
        //    allows a decimal fraction on the LOWEST one -- so `T10.5` means
        //    **half past ten**, and with the bare-hour branch below it was
        //    accepted and stored as `10:00:00`. Measured against
        //    `3d753a4:web/_core/I18n.php`, where it was refused with a 400:
        //
        //        2026-06-01T10.5     before: refused   after: 10:00:00
        //        2026-06-01 23.9     before: refused   after: 23:00:00
        //        2026-06-01 10.5Z    before: refused   after: 11:00 (means 11:30)
        //
        //    **Up to 59 minutes lost silently, HTTP 200, on a live API and a
        //    public form** -- and this is the first time in #777 that a
        //    WIDENING caused a wrong stored value rather than a refusal. Every
        //    earlier round moved the code from "stores something wrong" to
        //    "refuses"; round 9 moved a refusal to "stores something wrong",
        //    which is the direction to check first on any shape accepted from
        //    here on (round 10).
        //
        //    Anchored to a two-digit minute or second, a decimal hour now
        //    falls through to the shape check and is refused, which is what
        //    `api-spec.json` says happens.
        //    And it must follow the SECONDS, not the minutes: a fraction on
        //    the minute component means seconds (`T10:00.5` is 10:00:30), so
        //    allowing it would be the same silent truncation one component up
        //    -- smaller, and the same fault. `api-spec.json` says "fractional
        //    SECONDS", so this is what that sentence means.
        $clock = (string) preg_replace('/(?<=:\d{2}:\d{2})\.\d+$/', '', $clock);
        // 📅 A DATE WITH NO TIME MEANS MIDNIGHT ON IT. The API has an
        //    `isAllDay` field and a bare date used to store `00:00:00`, so
        //    refusing one broke every all-day integration. Midnight is a
        //    reading like any other and needs no moment to be built.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $clock) === 1) {
            $clock .= ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} ([01][0-9]|2[0-3]):[0-5][0-9]$/', $clock) === 1) {
            $clock .= ':00';
        }
        // 🕐 AN HOUR WITH NO MINUTES is a legal ISO 8601 time, and
        //    `2026-06-01T10Z` was a working instant until round 8.
        if (preg_match('/^\d{4}-\d{2}-\d{2} ([01][0-9]|2[0-3])$/', $clock) === 1) {
            $clock .= ':00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} ([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $clock) !== 1) {
            return null;
        }
        if (checkdate((int) substr($clock, 5, 2), (int) substr($clock, 8, 2), (int) substr($clock, 0, 4)) === false) {
            return null;
        }
        return $clock;
    }

    /**
     * 🕰️ THE ONE PLACE A VALUE CARRYING ITS OWN OFFSET BECOMES A STORED READING.
     *
     * A value with an offset in it (`+02:00`, `-0500`, a trailing `Z` or `z`)
     * really IS a moment in history, so it must be converted onto the clock of
     * the zone the reading will be stored against. That half of the rule has
     * been right since #777 round 6. **What was missing is that it checked no
     * SHAPE at all**, and `inputCarriesOffset()` is asked FIRST, so anything
     * ending in something offset-shaped went straight to the constructor.
     *
     * ⚠️ THAT STORED A DATE SIX MONTHS WRONG, WITH HTTP 200 AND NO WARNING.
     *    Measured in Europe/London through the real code:
     *
     *        "01/06/2026 10:00 +0100"    ->  stored 2026-01-06 09:00:00
     *        "1 June 2026 10:00 +01:00"  ->  stored 2026-06-01 10:00:00
     *        "Z"                         ->  stored the CURRENT moment
     *        "-05"                       ->  stored the CURRENT moment
     *        "2026-02-31T10:00:00Z"      ->  stored 2026-03-03 (rolled)
     *
     *    A day-first SLASH date is read month-first by PHP, so a client
     *    sending 1 June 2026 got an event on **6 January 2026**. And the
     *    published API description listed every one of those first four as
     *    refused with 400 — the contract was untrue for exactly the half that
     *    carried an offset, while `clock-change-selftest.php`'s I28-I30 pinned
     *    the sentence for the offset-FREE half only, so they read as pinning
     *    the whole of it (#777 round 8).
     *
     * So the shape is required on BOTH sides now: the offset is split off, what
     * remains must be a shape `wallClockFromInput()` accepts — which is where
     * `checkdate()` lives, so an impossible date is refused here too — and only
     * then is the moment built.
     *
     * @param  string        $posted What arrived, carrying its own offset.
     * @param  \DateTimeZone $zone   The clock the reading will be stored on.
     *
     * @return string|null `Y-m-d H:i:s` on that clock, or null to refuse.
     *                     Null means refuse: never fall back to a loose parse.
     */
    public static function instantFromInput(string $posted, \DateTimeZone $zone): ?string
    {
        $text = trim($posted);
        // Split the offset off the end. The date's own hyphens cannot be
        // reached, because the pattern is anchored to the end of the string and
        // a bare date is not offset-bearing (see `inputCarriesOffset()`).
        if (preg_match('/^(.*?)\s*(Z|[+-]\d{2}(?::?\d{2})?)$/i', $text, $parts) !== 1) {
            return null;
        }
        $reading = $parts[1];
        $offset  = $parts[2];
        // ⚠️ THE SAME SHAPE RULE AS THE OTHER HALF, which is the whole fix. A
        //    date with no time is allowed here too (`2026-06-01Z` is odd but
        //    unambiguous), because `wallClockFromInput()` decides that, not
        //    this method -- one rule, one place.
        $shaped = self::wallClockFromInput($reading);
        if ($shaped === null) {
            return null;
        }
        try {
            $moment = new \DateTimeImmutable($shaped . ' ' . (strtoupper($offset) === 'Z' ? '+00:00' : $offset));
        } catch (\Throwable $e) {
            return null;
        }
        return $moment->setTimezone($zone)->format('Y-m-d H:i:s');
    }

    /**
     * 🕰️ Whether a posted time carries a time zone OF ITS OWN.
     *
     * An offset -- `Z`, `+02:00`, `-0500`, `+02` -- is a zone written into the
     * value, so such a value really IS a moment in history and must be put
     * onto the event's own clock before it is stored. A value without one is a
     * reading, and is stored as it stands by `wallClockFromInput()` above.
     *
     * **This distinction is what lets the events API serve an integration in
     * another country without the #777 fix breaking it.** Refusing offsets, or
     * treating them as readings, would store a time hours out.
     *
     * ⚠️ ONLY THE TIME PART IS EXAMINED, because a date's own hyphens
     *    (`2026-03-28`) would otherwise read as a negative offset. Checked
     *    rather than assumed -- I19 and I20 of
     *    `tools/clock-change-selftest.php`.
     *
     * @param  string $posted What arrived.
     *
     * @return bool True when the value names its own zone.
     */
    public static function inputCarriesOffset(string $posted): bool
    {
        $timePart = (string) preg_replace('/^\d{4}-\d{2}-\d{2}[Tt ]?/', '', trim($posted));
        // 🔤 CASE-INSENSITIVE on the `Z`, because RFC 3339's own ABNF note
        //    allows it in lower case. Without the `i` a conforming client
        //    sending `...10:00:00z` fell into the no-offset branch, where the
        //    shape check then refused it with a 400 -- and that value is a
        //    genuine instant, so refusing it was wrong, not merely stricter.
        //    Round 7 of #777; it sat inside the half the round 6 commit called
        //    identical to before.
        return preg_match('/(?:Z|[+-]\d{2}(?::?\d{2})?)$/i', $timePart) === 1;
    }
    /**
     * 🕐 A time on a CLOCK, put into words without ever becoming a moment.
     *
     * 🚨 THIS EXISTS BECAUSE `date('g:i A', strtotime($clock))` IS WRONG ONE
     *    DAY A YEAR, and four pages did exactly that (#777, #549's "mistake
     *    1"). `strtotime('01:30')` resolves a bare clock reading against
     *    TODAY in PHP's default zone — and on the morning the clocks go
     *    forward, 01:30 does not exist, so PHP moves it. Measured in
     *    Europe/London on a stored `01:30`:
     *
     *        28 March 2026  ->  1:30 AM   (right)
     *        29 March 2026  ->  2:30 AM   (WRONG, an hour out)
     *        25 October 2026 -> 1:30 AM   (right)
     *
     *    The stored data was never wrong; the display was. The round trip was
     *    correct on every other day because both halves used the same zone.
     *
     * ⚠️ AND THAT ZONE IS NOT THE FAULT. An earlier version of this docblock
     *    said PHP's default zone "has nothing to do with the organisation's",
     *    and it usually IS the organisation's: `bootstrap.php` runs
     *    `date_default_timezone_set(Site::timezone())` during start-up. **The
     *    fault is the DATE** that `strtotime()` supplies without being asked —
     *    today's — because on one morning a year the hour the reading names
     *    does not exist in that zone. Same fix, different reason, and the
     *    reason is what the next reader checks the design against.
     *
     *    **A time on a clock is not a point in history until something gives
     *    it a date AND a zone.** This gives it neither, so there is no
     *    transition for it to fall into. `web/_apps/rota/index.php` already
     *    had the right idea by slicing the text; this does the same job while
     *    keeping the twelve-hour wording those four pages showed.
     *
     * @param string|null $clock A stored clock reading: `19:30`, `19:30:00`,
     *                           or anything else, which comes back trimmed and
     *                           unchanged rather than guessed at.
     *
     * @return string Twelve-hour wording — `7:30 PM` — or '' for nothing.
     */
    public static function formatClockTime(?string $clock): string
    {
        $text = trim((string) $clock);
        if ($text === '') {
            return '';
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $text, $parts) !== 1) {
            // Not a clock reading. Hand it back rather than inventing a time:
            // a wrong hour shown confidently is worse than text nobody styled.
            return $text;
        }
        $hour   = (int) $parts[1];
        $minute = (int) $parts[2];
        if ($hour > 23 || $minute > 59) {
            return $text;
        }

        // Twelve-hour, no leading zero, matching what these pages showed before
        // — so this fixes the one wrong day without changing the other 364.
        $suffix = $hour < 12 ? 'AM' : 'PM';
        $shown  = $hour % 12;
        if ($shown === 0) {
            $shown = 12;
        }

        return $shown . ':' . str_pad((string) $minute, 2, '0', STR_PAD_LEFT) . ' ' . $suffix;
    }

    /**
     * 🗓️ A wall-clock DATE AND TIME put into words, with no zone involved.
     *
     * 🚨 THIS EXISTS BECAUSE FIXING A WRITE PATH MADE THREE READERS WRONG.
     *    `tblResourceBooking.startAt/endAt` hold wall-clock values local to
     *    the organisation. #777 stopped the booking form moving them on the
     *    way IN — and the three pages that show them back still did
     *    `date('D j M, H:i', strtotime($stored))`, which resolves the stored
     *    text as a moment in PHP's default zone.
     *
     *    Measured in Europe/London, stored against displayed:
     *
     *        2027-03-28 01:00:00  ->  Sun 28 Mar, 02:00
     *        2027-03-28 01:30:00  ->  Sun 28 Mar, 02:30
     *        2027-06-01 09:00:00  ->  Tue 1 Jun, 09:00   (ordinary day, right)
     *
     *    Before the write was fixed the two wrongs cancelled: a 01:30 ask was
     *    stored as 02:30 and shown as 02:30 — wrong, but self-consistent. With
     *    the database now holding what was asked, the member is shown an hour
     *    late and the approver approves a window they are reading wrongly.
     *    **A fix on one side of a stored value makes the other side's fault
     *    visible; look at both sides before calling it done.**
     *
     * ⚠️ IT BUILDS ITS MOMENT IN UTC, deliberately, and that is not a fudge.
     *    UTC has no transitions, so a wall-clock reading put into it and read
     *    straight back out cannot move. The day name and the month name need a
     *    calendar, and a calendar is zone-independent; the clock part needs no
     *    zone at all. The same reasoning is written out beside
     *    `shiftWallClock()` in `_apps/resources/book.php`.
     *
     * 📌 THIS IS THE ONE PLACE A WALL-CLOCK DATE AND TIME BECOMES WORDS (#784).
     *    Two helpers used to do this job: this one and
     *    `FeedAdmin::showWallClock()`, which came first and made the same UTC
     *    build. Two helpers for one rule is #768's shape -- five copies of one
     *    rule, no two agreeing, each new page copying the nearest -- so #777
     *    part two, which converted two dozen pages, settled it before starting:
     *
     *      * THIS method owns the rule: read the stored text as a reading on no
     *        clock (`wallClockReading()`), then format it.
     *      * `showWallClock()` CALLS this for the words and keeps only what it
     *        adds: "(all day)", and naming the event's own zone when it is not
     *        the organisation's. Nothing else needs that -- the event page
     *        prints "Timezone: ..." on a line of its own, and every other
     *        reader shows the stored text as it is -- so it stays there rather
     *        than becoming a second public helper.
     *
     *    Never write a third. A page needing a reading as an OBJECT (to walk
     *    day by day across a calendar grid) asks `wallClockReading()`; a page
     *    needing to compare a reading with "now" asks `wallClockNow()`.
     *
     * ⚠️ DO NOT PASS A ZONE-BEARING FORMAT CHARACTER. Because the moment is
     *    built in UTC, `'c'`, `'r'`, `'T'`, `'P'`, `'p'`, `'O'`, `'e'`,
     *    `'I'`, `'Z'` and `'U'` would each
     *    assert that this organisation's wall-clock reading IS UTC -- the very
     *    untruth this method exists to avoid, stated more confidently than the
     *    code it replaced ever did. Nothing does it: every caller passes date
     *    and clock characters only. `_apps/widget/countdown-json.php` used to
     *    emit `date('c', strtotime($nextEvent['startDateTime']))` into a
     *    PUBLIC JSON feed, and converting it to THIS method would have been
     *    the obvious move and the wrong one -- a value that needs an offset
     *    needs a real zone, which for an event is `tblEvents.timezone`. #777
     *    part two sent it to `wallClockInstant()` instead.
     *
     * @param string|null $wallClock `Y-m-d H:i:s`, or anything else, which
     *                               comes back trimmed and unchanged rather
     *                               than guessed at.
     * @param string      $format    A `date()` format, applied to the reading.
     *                               Date and clock characters only -- see the
     *                               warning above.
     *
     * @return string The words, or '' for nothing.
     */
    public static function formatClockDateTime(?string $wallClock, string $format = 'D j M, H:i'): string
    {
        $text    = trim((string) $wallClock);
        $reading = self::wallClockReading($text);
        if ($reading === null) {
            // Not a wall-clock reading (or nothing at all). Hand it back rather
            // than inventing a date: a wrong day shown confidently is worse
            // than unstyled text.
            return $text;
        }

        return $reading->format($format);
    }

    /**
     * 🗓️ A stored wall-clock reading as an object that CANNOT MOVE -- for a
     *    page that must walk it across days or take its parts, not just print
     *    it (#777 part two).
     *
     * The calendar grids bucket an event by its day and walk a multi-day
     * event one day at a time. They did it with `new DateTimeImmutable($text)`,
     * which resolves the reading in PHP's default zone -- and on the morning
     * the clocks go forward, a reading inside the hour that does not exist is
     * moved an hour on (`2027-03-28 01:30` became `02:30` in Europe/London).
     * In a zone whose clocks go forward at 23:00 (America/Nuuk,
     * America/Scoresbysund) that moves it onto the NEXT DAY, so the event sat
     * in the wrong cell of the grid.
     *
     * ⚠️ THE OBJECT IS BUILT IN UTC, AND IT IS NOT A MOMENT. UTC has no
     *    transitions, so the reading goes in and comes back out unchanged, and
     *    `modify('+1 day')` moves it to the same clock time on the next
     *    calendar day -- exactly what "this event also runs tomorrow" means.
     *    What it must NEVER be used for:
     *
     *      * comparing with a real moment (`new DateTime()`, `time()`): this
     *        object claims to be UTC and is not -- compare the TEXT with
     *        `wallClockNow()` instead;
     *      * formatting with a zone-bearing character (`c`, `r`, `T`, `P`,
     *        `O`, `e`, `U` ...): that would assert the reading IS UTC.
     *
     *    A value that genuinely needs an instant (a public feed's `startsAt`,
     *    an iCalendar `DTSTART` in UTC) needs the event's real zone:
     *    `new \DateTimeImmutable($reading, Site::zoneFor($event['timezone']))`.
     *
     * @param string|null $wallClock `Y-m-d H:i`, `Y-m-d H:i:s`, either with a
     *                               `T` between date and time.
     *
     * @return \DateTimeImmutable|null Null for anything that is not a real
     *                                 reading -- including an impossible date
     *                                 (`2027-02-31`), which is refused rather
     *                                 than rolled into March.
     */
    public static function wallClockReading(?string $wallClock): ?\DateTimeImmutable
    {
        $text = trim((string) $wallClock);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/', $text, $parts) !== 1) {
            return null;
        }
        [$whole, $year, $month, $day, $hour, $minute] = $parts;
        $second = isset($parts[6]) === true ? (int) $parts[6] : 0;
        if (checkdate((int) $month, (int) $day, (int) $year) === false
            || (int) $hour > 23 || (int) $minute > 59 || $second > 59
        ) {
            return null;
        }

        try {
            return new \DateTimeImmutable(
                sprintf(
                    '%04d-%02d-%02dT%02d:%02d:%02d',
                    (int) $year,
                    (int) $month,
                    (int) $day,
                    (int) $hour,
                    (int) $minute,
                    $second
                ),
                new \DateTimeZone('UTC')
            );
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 📍 A stored wall-clock reading made into a real INSTANT, on the clock it
     *    was written on -- for the few places that must hand an instant to
     *    something else: a public feed's `startsAt`, a countdown in the
     *    visitor's browser (#777 part two).
     *
     * Two pages did `date('c', strtotime($event['startDateTime']))`, which
     * reads the reading on PHP's DEFAULT zone -- the organisation's. An event
     * has its own zone (`tblEvents.timezone`), so an online service run for
     * another country was published hours out. Pass
     * `Site::zoneFor((string) $event['timezone'])`.
     *
     * ⚠️ THIS IS THE ONE PLACE THE HOUR THAT DOES NOT EXIST IS RESOLVED FOR AN
     *    INSTANT, and it moves FORWARD by the length of the gap: `01:30` on
     *    the morning London's clocks go forward is the instant 01:30 UTC
     *    (shown as 02:30 BST). That is RFC 5545 section 3.3.5's rule ("the
     *    UTC offset before the gap"), and it is right HERE because an instant
     *    must be SOME instant -- whereas a page that only shows or compares
     *    the reading must never come here (`formatClockDateTime()`,
     *    `wallClockNow()`), and a writer must never store what this returns.
     *
     *    For the hour the clocks go BACK, the reading happens twice and this
     *    answers with PHP's own pick, which is NOT the same in every zone
     *    (standard time in Europe, daylight time across the Americas -- see
     *    `IcsReader::laterOfARepeatedReading()`, which decides that question
     *    for imported events). An hour of doubt one night a year on a
     *    countdown is accepted rather than solved here.
     *
     * @return \DateTimeImmutable|null Null for anything that is not a real
     *                                 reading, so a broken row gives no
     *                                 instant rather than a confident wrong one.
     */
    public static function wallClockInstant(?string $wallClock, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        $reading = self::wallClockReading($wallClock);
        if ($reading === null) {
            return null;
        }
        try {
            return new \DateTimeImmutable($reading->format('Y-m-d H:i:s'), $zone);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 🕰️ "Now", as a reading on the given clock: `Y-m-d H:i:s` text, to be
     *    compared AS TEXT with a stored wall-clock reading (#777 part two).
     *
     * Several pages asked "has this event started?" with
     * `strtotime($event['startDateTime']) < time()` or
     * `new DateTime($start) < new DateTime()`. That turns the stored reading
     * into a moment in PHP's DEFAULT zone -- the organisation's, set during
     * start-up -- and an event's zone is `tblEvents.timezone`, which need not
     * be the organisation's at all: an online service run for another
     * country was judged by the wrong clock, hours out. And on the morning
     * the clocks go forward, a reading inside the missing hour was moved.
     *
     * The other way round needs no moment at all: ask what the EVENT's clock
     * reads now, and compare readings. `Y-m-d H:i:s` text sorts in time
     * order, so `strcmp($stored, I18n::wallClockNow($zone)) < 0` means
     * "already begun". Pass `Site::zoneFor((string) $event['timezone'])`,
     * which falls back to the organisation's zone for text PHP cannot open.
     *
     * ⚠️ IN THE HOUR THE CLOCKS GO BACK the event's clock reads every time
     *    between 01:00 and 01:59 twice, and a reading cannot say which
     *    passing it means. For that one hour a year this answers by the clock
     *    as it reads at the moment of asking, which is what a person standing
     *    in the room would say.
     */
    public static function wallClockNow(\DateTimeZone $zone): string
    {
        return (new \DateTimeImmutable('now', $zone))->format('Y-m-d H:i:s');
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
