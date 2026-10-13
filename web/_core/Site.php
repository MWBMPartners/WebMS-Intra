<?php
// Path: _core/Site.php
/**
 * -----------------------------------------------------------------------------
 * Multi-Site Context Manager 🌐
 * -----------------------------------------------------------------------------
 * Central point of truth for the active site in a multi-site installation.
 * An installation tells its organisations apart in ONE of three ways, chosen
 * on Admin, Organisations, "How organisations are told apart" (the setting
 * multisite.detectionMode; the owner decided on 12 October 2026 that the
 * three are never mixed):
 *
 *   1. 'subdomain' — each organisation has its own address; the host name of
 *                    the request is matched to tblSites.hostPattern. Despite
 *                    the setting's name, any host name works, not only a
 *                    sub-domain (for example cambridge.portal.example.org).
 *   2. 'path'      — the first part of the address is the organisation's key
 *                    (for example /cambridge/expenses).
 *   3. 'session'   — the organisation is remembered in the sign-in session.
 *                    A person with several chooses one at sign-in and can
 *                    switch later. A signed-out visitor has no session, so
 *                    they always get the default organisation (number 1).
 *
 * preDetect() is the ONE place the organisation is decided (#502). Whether a
 * signed-in person may be IN that organisation is decided by SiteGate, which
 * web/public_html/index.php calls before any page runs.
 *
 * When multisite is disabled (multisite.enabled = 'false'), all methods
 * transparently return siteID=1 for full backward compatibility.
 *
 * Public API:
 *   Site::init($db, $settings, $preDetectedId)  — initialise from bootstrap
 *   Site::id()                                    — active site ID (int, default 1)
 *   Site::current()                               — full site row array or null
 *   Site::branding($key)                          — site-specific branding value
 *   Site::set($siteID, $db)                       — switch active site (session mode)
 *   Site::allActive($db)                          — all active sites
 *   Site::userSites($userID, $db)                 — sites a user belongs to
 *   Site::userBelongsTo($userID, $siteID, $db)    — access check
 *   Site::userIsSiteAdmin($userID, $db)           — site admin check for current site
 *   Site::userIsSiteRootAdmin($userID, $db)       — site root admin check for current site
 *   Site::detectionMode()                         — current detection mode string
 *   Site::pathPrefix()                            — matched path prefix (path mode only)
 *   Site::isMultisiteEnabled()                    — is multisite feature active?
 *   Site::resolveDefaultSiteForUser($userId, $db) — first assigned site for a user
 *   Site::organisationsFor($userID, $db)          — "your organisations": the one list
 *                                                   the chooser, the switcher and the
 *                                                   members-only gate all use
 *   Site::addressOf($site)                        — where another organisation's front
 *                                                   page is, in the way in use
 *   Site::modeProblems($mode, $db)                — why a way cannot be chosen now
 *   Site::timezone($siteId)                       — the ONE answer to "what is this
 *                                                   organisation's time zone?" (#604)
 *   Site::timezoneFacts($siteId)                  — that answer, with what is stored and
 *                                                   whether the stored text is a zone
 *   Site::zoneFor($stored, $siteId)               — the zone to read a stored clock time in
 *   Site::reset()                                 — clear cached state
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.9.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/45
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Site
{
    /** Default branding constants used as the FINAL fallback when neither the
     * site row nor the product brand layer (settings) yields a value. The
     * footer attribution + meta-generator logic compares the current site's
     * branding against the *resolved product name* (see productName() below),
     * not against DEFAULT_SITE_NAME — the latter is just the cold-start
     * cradle when settings haven't been loaded yet. Update these alongside
     * tblSites column defaults in full_schema.sql.
     *
     * 🏷️ Multi-brand product layer (issue #296):
     *   The product brand (WebMS Intra / ChurchMS / etc.) is selected at
     *   install time and stored in $SETTINGS['product']['name']. The
     *   constants below are the "if even the settings table is missing"
     *   fallback — they preserve historical behaviour for cold-start
     *   scenarios but no longer represent the canonical brand name.
     */
    public const DEFAULT_SITE_NAME     = 'WebMS Intra';
    public const DEFAULT_LOGO_PATH     = '/assets/images/logo.svg';
    public const DEFAULT_PRIMARY_COLOR = '#5e6ad2';
    public const DEFAULT_FAVICON_PATH  = '/assets/images/icon.svg';

    /**
     * The three ways, by the values kept in the multisite.detectionMode
     * setting. The stored values keep their old names, because databases
     * already hold them. Screens call them "sign-in session", "first part
     * of the address" and "own address each" (MODE_LABELS).
     */
    public const MODE_SESSION = 'session';
    public const MODE_PATH    = 'path';
    public const MODE_HOST    = 'subdomain';

    /** @var array<string,string> What each way is called on screen. */
    public const MODE_LABELS = [
        self::MODE_SESSION => 'Sign-in session',
        self::MODE_PATH    => 'First part of the address',
        self::MODE_HOST    => 'Own address each',
    ];

    /**
     * 🚧 Is "the first part of the address" ready to be CHOSEN on the set-up page?
     *
     * No, not yet. The owner (Salem874) decided on 12 October 2026 that this
     * way is built now but marked "not ready", and refused, until the links
     * inside the portal have been corrected. The way itself is
     * built and tested, but about 2,000 links, form targets and redirects in
     * the app pages are still written without the address builder (#536), so
     * in that way they would drop a person into the default organisation.
     * Until that sweep is finished the set-up page marks this way "not ready"
     * and refuses it (modeProblems() below), so nobody can choose a
     * half-working way.
     *
     * THE LINK-SWEEP PACKAGE FLIPS THIS ONE LINE TO true WHEN ITS LIST OF
     * UNSWEPT FOLDERS IS EMPTY. Nothing else needs to change.
     * tools/site-modes-selftest.php checks both states.
     *
     * WHAT IT DOES NOT DO: it does not switch the way off for an installation
     * that is already using it (set straight in the database before the
     * set-up page existed). Doing that would change which organisation every
     * address opens, with nobody having asked.
     */
    public const FIRST_PART_MODE_READY = false;

    /**
     * The four answers preDetect() can reach in session mode, read by
     * SiteGate. Outside session mode the answer stays SESSION_NOT_USED.
     *   SIGNED_OUT  nobody is signed in: the default organisation.
     *   OK          signed in, and in one of their own organisations.
     *   CHOOSE      signed in with two or more organisations and none chosen
     *               yet: only the chooser may be shown.
     *   NONE        signed in, but in no organisation at all: nothing of any
     *               organisation may be shown.
     */
    public const SESSION_NOT_USED   = 'not-used';
    public const SESSION_SIGNED_OUT = 'signed-out';
    public const SESSION_OK         = 'ok';
    public const SESSION_CHOOSE     = 'choose';
    public const SESSION_NONE       = 'none';

    /** @var int Active site ID (defaults to 1) */
    private static int $currentSiteID = 1;

    /** @var array|null Cached full site row from tblSites */
    private static ?array $currentSite = null;

    /** @var bool Whether init() has been called */
    private static bool $resolved = false;

    /** @var bool Whether multisite feature is enabled */
    private static bool $enabled = false;

    /** @var string Detection mode: 'subdomain', 'path', or 'session' */
    private static string $mode = 'session';

    /** @var string Matched path prefix (path mode only, e.g. 'cambridge') */
    private static string $pathPrefix = '';

    /** @var array Per-request cache: userSiteRoles[userId:siteId] => array|false */
    private static array $userSiteRoleCache = [];

    /**
     * What preDetect() read from the two portal-wide settings, kept so that
     * init() uses the SAME answer. Null until preDetect() has read them.
     *
     * init() used to read both a second time, from the merged settings, where
     * an organisation's own row overrides the portal-wide one. So one
     * organisation's administrator could add a row saying "multi-organisation
     * is off" and that organisation's pages would then be worked out one way
     * and labelled another. (AccountGuard.php already reads the portal-wide
     * row directly for the same reason.)
     *
     * @var bool|null
     */
    private static ?bool $preEnabled = null;

    /** @var string|null The way preDetect() found; see $preEnabled. */
    private static ?string $preMode = null;

    /**
     * True from the moment preDetect() starts until it finishes with every
     * look-up answered. So it is true when a look-up failed or threw, and
     * SiteGate then shows nobody any organisation's page: "organisation 1"
     * reached because a query failed is a guess, and a signed-in person must
     * never be shown an organisation on a guess.
     *
     * False when preDetect() was never called (a command-line tool that sets
     * the organisation itself), which is why this is "failed" and not "done".
     */
    private static bool $detectionFailed = false;

    /** @var string One of the SESSION_* answers above. */
    private static string $sessionState = self::SESSION_NOT_USED;

    /**
     * Session mode only: the account the organisation was worked out FOR
     * (0 = nobody signed in). SiteGate compares it with the account that is
     * signed in once the session has properly started. They differ only when
     * the session changed between the two reads, and then the organisation
     * chosen above belongs to somebody else.
     */
    private static int $resolvedUserId = 0;

    /** @var array<int, list<array<string,mixed>>> Per-request cache for organisationsFor(). */
    private static array $organisationsCache = [];

    /**
     * Has migration 217 put every organisation's zone into its own row?
     * Read at init() from the mark that migration writes (ZONE_MARK_KEY).
     * False until then, and timezone() answers from the old setting.
     */
    private static bool $zoneInRow = false;

    /**
     * The old `site.timezone` setting exactly as start-up was handed it, for
     * the organisation the settings were loaded for ($settingsSiteId). Null
     * when there was none. Used only while $zoneInRow is false.
     */
    private static ?string $oldZoneSetting = null;

    /** @var int The organisation the settings given to init() were loaded for. */
    private static int $settingsSiteId = 1;

    /** @var array<int, string> Per-request cache: another organisation's stored zone text. */
    private static array $zoneTextCache = [];

    /* ====================================================================== */
    /* Initialisation                                                         */
    /* ====================================================================== */

    /**
     * Initialise the Site context. Called from bootstrap.php after DB and
     * settings are loaded.
     *
     * @param \mysqli $db             Database connection
     * @param array   $settings       Loaded settings array
     * @param int     $preDetectedId  Site ID detected during pre-settings phase
     *
     * @return void
     */
    public static function init(\mysqli $db, array $settings, int $preDetectedId): void
    {
        self::$resolved = true;

        // 🕰️ Where this installation keeps an organisation's time zone (#604):
        //    see timezone(). Taken from the settings start-up has just
        //    loaded, so it costs no question to the database.
        $oldZone               = $settings['site']['timezone'] ?? null;
        self::$zoneInRow       = (($settings['site']['timezoneKeptAt'] ?? '') === self::ZONE_MARK_VALUE);
        self::$oldZoneSetting  = is_string($oldZone) === true ? $oldZone : null;
        self::$settingsSiteId  = $preDetectedId;
        self::$zoneTextCache   = [];

        // 🔍 Is multi-organisation on, and which way? Use what preDetect()
        //    read from the portal-wide rows, so the organisation is worked
        //    out and labelled by the same answer (see $preEnabled). The
        //    merged settings are the fallback only when preDetect() did not
        //    get as far as reading them, or was never called.
        if (self::$preEnabled !== null) {
            self::$enabled = self::$preEnabled;
            $mode          = self::$preMode ?? self::MODE_SESSION;
        } else {
            self::$enabled = (($settings['multisite']['enabled'] ?? 'false') === 'true');
            $mode          = self::knownMode((string) ($settings['multisite']['detectionMode'] ?? self::MODE_SESSION));
        }

        if (self::$enabled === false) {
            // 🏠 Single-site mode — always siteID=1
            self::$currentSiteID = 1;
            self::loadSiteRow($db);
            return;
        }

        self::$mode = $mode;
        self::$currentSiteID = $preDetectedId;

        // 📋 Load the full site row for branding and context
        self::loadSiteRow($db);
    }

    /**
     * Load the current site row from tblSites into cache.
     *
     * @param \mysqli $db Database connection
     *
     * @return void
     */
    private static function loadSiteRow(\mysqli $db): void
    {
        $stmt = $db->prepare(
            'SELECT siteID, siteName, siteKey, hostPattern, logoPath, faviconPath, '
            . 'primaryColor, copyrightOrg, timezone, isActive '
            . 'FROM tblSites WHERE siteID = ? LIMIT 1'
        );
        if ($stmt === false) {
            self::$currentSite = null;
            return;
        }
        $id = self::$currentSiteID;
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        self::$currentSite = ($row !== null && $row !== false) ? $row : null;
    }

    /* ====================================================================== */
    /* Public accessors                                                       */
    /* ====================================================================== */

    /**
     * Get the active site ID.
     *
     * @return int Site ID (defaults to 1)
     */
    public static function id(): int
    {
        return self::$currentSiteID;
    }

    /**
     * Get the full site row array.
     *
     * @return array|null Site row or null if not loaded
     */
    public static function current(): ?array
    {
        return self::$currentSite;
    }

    /**
     * Get a site-specific branding value.
     *
     * @param string $key One of: name, logo, favicon, color, copyright, timezone, key
     *
     * @return string|null Value or null if site not loaded
     */
    public static function branding(string $key): ?string
    {
        $site = self::$currentSite;
        if ($site === null) {
            return null;
        }

        if ($key === 'name') {
            return $site['siteName'];
        }
        if ($key === 'logo') {
            return $site['logoPath'];
        }
        if ($key === 'favicon') {
            // faviconPath column may not yet exist on databases that haven't
            // run migration 037; return null to let the caller fall back.
            return $site['faviconPath'] ?? null;
        }
        if ($key === 'color') {
            return $site['primaryColor'];
        }
        if ($key === 'copyright') {
            return $site['copyrightOrg'];
        }
        if ($key === 'timezone') {
            // The one answer (#604), never the row's raw text: see timezone().
            return self::timezone();
        }
        if ($key === 'key') {
            return $site['siteKey'];
        }

        return null;
    }

    /* ====================================================================== */
    /* 🕰️ The organisation's time zone (#604)                                 */
    /* ====================================================================== */

    /**
     * The mark migration 217 writes, as an installation-wide setting, once
     * every organisation's row holds the zone its pages were showing. While
     * it is absent the rows have NOT been brought into line and are not read.
     */
    public const ZONE_MARK_KEY   = 'site.timezoneKeptAt';
    public const ZONE_MARK_VALUE = 'organisation';

    /** The setting that used to decide PHP's zone. Read only while the mark is absent. */
    public const OLD_ZONE_KEY = 'site.timezone';

    /**
     * The answer when no zone has been chosen, or the stored text is not a
     * zone name. Not a guess at where the customer is: it is what start-up
     * has always used until told otherwise (bootstrap.php sets PHP to UTC
     * before anything is loaded).
     */
    public const ZONE_WHEN_NONE = 'UTC';

    /**
     * What the general settings editor says when it refuses either setting
     * above. TWO STATES, AND EACH HAS ITS OWN WORDS. It used to be one
     * sentence ("is not a setting any more… chosen at Admin, Organisations…
     * and nowhere else"), which is untrue between new files arriving and
     * Upgrade being pressed: until migration 217 has run the old setting is
     * still what every page follows, and Admin, Organisations does not take a
     * zone yet (found by the independent check, 13 October 2026).
     */
    public static function zoneLockedMessage(): string
    {
        if (self::$zoneInRow === false) {
            return 'Time zones cannot be changed just now. This installation has new files and has not yet run their '
                . 'database upgrade: run it at Admin, Upgrade. After that an organisation\'s time zone is chosen at '
                . 'Admin, Organisations. Nothing was changed.';
        }

        return 'An organisation\'s time zone is not a setting any more. It is chosen at Admin, '
            . 'Organisations (the organisation\'s Edit button, "Time zone"), and nowhere else. Nothing was changed.';
    }

    /**
     * 🕰️ THE ONE ANSWER to "what is this organisation's time zone?" (#604).
     *
     * Always a zone name PHP's main list knows, so it can be handed straight
     * to `new DateTimeZone()` or `date_default_timezone_set()`. Start-up asks
     * it to set PHP's zone, and so do the mail queue, the health page, the
     * admin dashboard, the e-mail page, the reminder jobs, giving retention
     * and the calendar importer. Do not read `tblSites.timezone` or the old
     * setting anywhere else.
     *
     * WHAT WAS WRONG. The zone was kept in two places that could differ: the
     * organisation's row (`tblSites.timezone`, set at Admin, Organisations)
     * and the `site.timezone` setting. Start-up read only the setting, so
     * most pages followed it; the mail lines on the dashboard and the health
     * page, the rota reminder and giving retention read the row; the e-mail
     * page read the row unless it was UTC; and the calendar importer read
     * the setting with a looser test of what a zone is. Run on 13 October
     * 2026: in 9 of 13 combinations of the two values one installation gave
     * two different answers.
     *
     * WHERE THE ANSWER COMES FROM
     *   - Once migration 217 has run (its mark is in the settings): the
     *     organisation's own row, and nothing else. That migration first
     *     wrote into each row what start-up was answering, so no ordinary
     *     page moved. A `site.timezone` setting put back afterwards is NOT
     *     read.
     *   - Before it has run (new files have arrived and Upgrade has not been
     *     pressed yet): exactly what start-up answered before, from the old
     *     setting. The rows are not in line yet, so reading them would move
     *     every page of an installation whose two places differed.
     *
     * WHAT COUNTS AS A ZONE: start-up's own test and no other, an exact name
     * from PHP's main list (isZoneName()). Stored text that fails it answers
     * UTC, as it always did for pages. REJECTED: accepting anything PHP can
     * open ("US/Eastern", "europe/london", " Europe/London"). The importer
     * used to, so it and the pages disagreed; taking its test would have
     * moved every page of such an installation by hours on the day of the
     * upgrade. See timezoneFacts() for what happens to those instead.
     *
     * WHAT THIS CANNOT DO: fail. If the database cannot be asked, the answer
     * is UTC and the reason goes to the server's error log. Nor can it tell
     * an organisation that chose UTC from one that never chose.
     *
     * @param int|null $siteId The organisation; null for the current one.
     */
    public static function timezone(?int $siteId = null): string
    {
        return self::timezoneFacts($siteId)['zone'];
    }

    /**
     * The answer of timezone() together with what it was worked out from.
     *
     *   zone     the answer (see timezone()).
     *   stored   the text it came from, exactly as stored; '' when none.
     *   known    true when that text is a zone name by start-up's test.
     *   inDoubt  true when it is NOT, yet PHP can still open it as a zone
     *            once the spaces round it are removed: an old name
     *            ("US/Eastern"), other capitals, a stray space. The pages of
     *            such an organisation have always shown UTC while the
     *            calendar importer read the same text as the zone it names.
     *            Nobody can know which was meant, so the importer refuses a
     *            calendar with no zone of its own there until an
     *            administrator chooses (FeedImporter), and the organisation
     *            is listed on the health page and on Admin, Organisations.
     *            False for text PHP cannot open at all ("Auckland"): both
     *            readers answered UTC for that, so there is no doubt.
     *   inRow    true once migration 217 has run and the row is the source.
     *
     * @return array{zone:string, stored:string, known:bool, inDoubt:bool, inRow:bool}
     */
    public static function timezoneFacts(?int $siteId = null): array
    {
        $siteId = $siteId ?? self::$currentSiteID;
        $stored = '';
        try {
            $stored = self::$zoneInRow === true ? self::zoneTextOfRow($siteId) : self::zoneTextOfOldSetting($siteId);
        } catch (\Throwable $e) {
            // Start-up asks this on every request, before any error handler
            // exists. UTC is what PHP is already set to at that point.
            error_log('[WebMS-Intra] The time zone of organisation ' . $siteId . ' could not be read; UTC is used: ' . $e->getMessage());
        }
        $known = self::isZoneName($stored);

        return [
            'zone'    => $known === true ? $stored : self::ZONE_WHEN_NONE,
            'stored'  => $stored,
            'known'   => $known,
            'inDoubt' => $known === false && self::phpCanOpenZone($stored) === true,
            'inRow'   => self::$zoneInRow,
        ];
    }

    /**
     * Is this text a zone name? START-UP'S OWN TEST, kept word for word in
     * what it accepts: an exact name from PHP's main list, capitals and all,
     * with no space round it. The organisation form, the event form and the
     * events API use it too, so nothing new can be stored that fails it.
     */
    public static function isZoneName(string $text): bool
    {
        static $names = null;
        if ($names === null) {
            $names = array_flip(timezone_identifiers_list());
        }

        return isset($names[$text]);
    }

    /**
     * The zone names a form offers: PHP's main list, the same one
     * isZoneName() tests against.
     *
     * @return list<string>
     */
    public static function zoneNames(): array
    {
        return timezone_identifiers_list();
    }

    /**
     * The zone to read a STORED clock time in (an event's start, say): the
     * zone stored beside it when PHP can open it, and the organisation's
     * own when it cannot.
     *
     * It is looser than isZoneName() on purpose. An event saved years ago as
     * "US/Eastern" has always been shown on that clock, and must go on being.
     * Only text PHP cannot open at all falls back, and then to the
     * organisation's zone: this used to be "Europe/London", typed into four
     * different files, which was wrong for every customer anywhere else.
     * MailQueue::useBy() applies the same two steps.
     *
     * @param string   $stored The zone text stored with the clock time.
     * @param int|null $siteId The organisation it belongs to; null for the current one.
     */
    public static function zoneFor(string $stored, ?int $siteId = null): \DateTimeZone
    {
        if (self::phpCanOpenZone($stored) === true) {
            return new \DateTimeZone(trim($stored));
        }

        return new \DateTimeZone(self::timezone($siteId));
    }

    /** Can PHP open this text as a zone, once the spaces round it are removed? */
    public static function phpCanOpenZone(string $text): bool
    {
        $name = trim($text);
        if ($name === '') {
            return false;
        }
        try {
            new \DateTimeZone($name);
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * Every organisation whose stored zone is not a zone name, for the
     * health page and Admin, Organisations. Empty until migration 217 has
     * run: before that the rows are not the source, so they prove nothing.
     *
     * @return list<array{siteID:int, siteName:string, stored:string, inDoubt:bool}>
     */
    public static function organisationsWithUnknownZone(\mysqli $db): array
    {
        if (self::$zoneInRow === false) {
            return [];
        }
        $found  = [];
        $result = $db->query('SELECT siteID, siteName, timezone FROM tblSites ORDER BY siteName ASC');
        if ($result === false) {
            return [];
        }
        while ($row = $result->fetch_assoc()) {
            $stored = (string) ($row['timezone'] ?? '');
            if (self::isZoneName($stored) === false) {
                $found[] = [
                    'siteID'   => (int) $row['siteID'],
                    'siteName' => (string) $row['siteName'],
                    'stored'   => $stored,
                    'inDoubt'  => self::phpCanOpenZone($stored),
                ];
            }
        }

        return $found;
    }

    /**
     * 🔒 Is this setting kept out of the GENERAL settings editor (/settings)?
     *
     * Yes for the old zone setting and for migration 217's mark. Nothing
     * reads the first once the mark is there, so a row typed in would only
     * mislead whoever found it; and removing the mark would send every
     * organisation back to the old setting, which no longer exists, so every
     * page would fall to UTC. The editor asks in the same three places as
     * lockedInGeneralEditor(): change, add and delete.
     *
     * WHAT IT CANNOT DO: stop either row being changed straight in the
     * database. The health page says so when the mark is missing.
     */
    public static function zoneLockedInGeneralEditor(string $key): bool
    {
        return strcasecmp($key, self::OLD_ZONE_KEY) === 0 || strcasecmp($key, self::ZONE_MARK_KEY) === 0;
    }

    /** The organisation's row, as stored: '' when there is no such organisation or the column is empty. */
    private static function zoneTextOfRow(int $siteId): string
    {
        if ($siteId === self::$currentSiteID && self::$currentSite !== null) {
            return (string) (self::$currentSite['timezone'] ?? '');
        }
        if (isset(self::$zoneTextCache[$siteId]) === true) {
            return self::$zoneTextCache[$siteId];
        }
        $stmt = App::db()->prepare('SELECT timezone FROM tblSites WHERE siteID = ? LIMIT 1');
        if ($stmt === false) {
            return '';
        }
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return self::$zoneTextCache[$siteId] = (string) ($row['timezone'] ?? '');
    }

    /**
     * What start-up read before migration 217: the organisation's own
     * `site.timezone` setting when it has one (an empty one counts, and
     * means UTC), else the installation's. For the organisation the settings
     * were loaded for it is the very value start-up was handed; for another
     * one, App::settingForSite() applies the same order.
     */
    private static function zoneTextOfOldSetting(int $siteId): string
    {
        if ($siteId === self::$settingsSiteId) {
            return (string) (self::$oldZoneSetting ?? '');
        }

        return (string) (App::settingForSite(self::OLD_ZONE_KEY, $siteId) ?? '');
    }

    /**
     * Resolve the active product name — short brand string used by header
     * meta-generator, footer powered-by mark, and the X-Powered-By header.
     *
     * Resolution order:
     *   1. $SETTINGS['product']['name']           (set by installer / admin)
     *   2. PORTAL_PRODUCT_NAME_DEFAULT constant   (set in bootstrap.php)
     *   3. self::DEFAULT_SITE_NAME                (final cold-start fallback)
     *
     * Read at call time (not cached) so admin edits to the setting take
     * effect on the next render without a redeploy.
     *
     * @return string
     */
    public static function productName(): string
    {
        // Use the App settings registry (which reflects DB + the in-memory
        // override map) when available. Falls back to the constant which
        // bootstrap.php seeded from _core/brand-defaults.php.
        if (class_exists(App::class) === true) {
            $fromSettings = App::settings('product.name');
            if ($fromSettings !== null && $fromSettings !== '') {
                return (string) $fromSettings;
            }
        }
        if (defined('PORTAL_PRODUCT_NAME_DEFAULT') === true) {
            return (string) PORTAL_PRODUCT_NAME_DEFAULT;
        }
        return self::DEFAULT_SITE_NAME;
    }

    /**
     * Resolve the active product tagline — long brand string used by the
     * installer wizard subtitle, /admin/about, and the PWA manifest
     * description.
     *
     * @return string
     */
    public static function productTagline(): string
    {
        if (class_exists(App::class) === true) {
            $fromSettings = App::settings('product.tagline');
            if ($fromSettings !== null && $fromSettings !== '') {
                return (string) $fromSettings;
            }
        }
        if (defined('PORTAL_PRODUCT_TAGLINE_DEFAULT') === true) {
            return (string) PORTAL_PRODUCT_TAGLINE_DEFAULT;
        }
        return 'Internal Management System';
    }

    /**
     * Resolve the active product publisher / copyright org. Per multi-brand
     * decision #4, this is ALWAYS "MWBM Partners Ltd (t/a MWservices)"
     * regardless of which sub-brand is installed — sub-brands are MWBM
     * products, not white-labels. Stored as a setting only so admins can
     * customise it for their own white-label deals if desired.
     *
     * @return string
     */
    public static function productPublisher(): string
    {
        if (class_exists(App::class) === true) {
            $fromSettings = App::settings('product.publisher');
            if ($fromSettings !== null && $fromSettings !== '') {
                return (string) $fromSettings;
            }
        }
        if (defined('PORTAL_PRODUCT_PUBLISHER_DEFAULT') === true) {
            return (string) PORTAL_PRODUCT_PUBLISHER_DEFAULT;
        }
        return 'MWBM Partners Ltd (t/a MWservices)';
    }

    /**
     * Detect whether the current site has CUSTOM branding — any branding
     * field differs from the active product brand defaults (resolved via
     * productName() above, NOT the DEFAULT_SITE_NAME constant). This lets a
     * ChurchMS-branded install correctly show "Powered by ChurchMS" to a
     * tenant that has customised its own siteName.
     *
     * Used by the footer template to decide whether to render the
     * "Powered by <product>" attribution. The actual show/hide is
     * additionally gated by the `branding.hidePoweredBy` setting.
     *
     * Returns false when no site row has loaded (defensive — better to
     * suppress attribution than to falsely accuse the product defaults
     * of being custom).
     *
     * @return bool
     */
    public static function usesCustomBranding(): bool
    {
        $site = self::$currentSite;
        if ($site === null) {
            return false;
        }

        // siteName / logoPath / primaryColor: stored as strings; compare
        // case-insensitively for the hex colour (where case is irrelevant)
        // and case-sensitively for the others. The product brand layer
        // (issue #296) means the "default" siteName isn't a fixed string;
        // we compare against productName() which is brand-aware.
        $siteName    = (string) ($site['siteName'] ?? '');
        $productName = self::productName();
        if ($siteName !== '' && $siteName !== $productName) {
            return true;
        }

        $logoPath = (string) ($site['logoPath'] ?? '');
        if ($logoPath !== '' && $logoPath !== self::DEFAULT_LOGO_PATH) {
            return true;
        }

        $primaryColor = (string) ($site['primaryColor'] ?? '');
        if ($primaryColor !== ''
            && strcasecmp($primaryColor, self::DEFAULT_PRIMARY_COLOR) !== 0
        ) {
            return true;
        }

        // copyrightOrg: default is NULL/empty. Any non-empty value is custom.
        $copyrightOrg = $site['copyrightOrg'] ?? null;
        if ($copyrightOrg !== null && $copyrightOrg !== '') {
            return true;
        }

        // faviconPath: default is NULL (falls back to /assets/images/favicon.ico).
        // Any explicit value set is custom.
        $faviconPath = $site['faviconPath'] ?? null;
        if ($faviconPath !== null && $faviconPath !== ''
            && $faviconPath !== self::DEFAULT_FAVICON_PATH
        ) {
            return true;
        }

        return false;
    }

    /**
     * Get the current detection mode.
     *
     * @return string 'subdomain', 'path', or 'session'
     */
    public static function detectionMode(): string
    {
        return self::$mode;
    }

    /**
     * Get the matched path prefix (path mode only).
     * E.g. 'cambridge' when URL was /cambridge/expenses.
     *
     * @return string Path prefix or empty string
     */
    public static function pathPrefix(): string
    {
        return self::$pathPrefix;
    }

    /**
     * Check if multi-site feature is enabled.
     *
     * @return bool True if multisite.enabled = 'true'
     */
    public static function isMultisiteEnabled(): bool
    {
        return self::$enabled;
    }

    /* ====================================================================== */
    /* Site switching                                                          */
    /* ====================================================================== */

    /**
     * Switch the active site (for session/switcher mode).
     * Validates the site exists and is active before switching.
     *
     * It does NOT ask whether the person belongs to that organisation: the
     * caller must (site/switch.php does, with isOwnOrganisation()). Even if
     * a caller forgot, the next request would not honour the saved number:
     * detectFromSession() checks membership again every time.
     *
     * @param int     $siteID Target site ID
     * @param \mysqli $db     Database connection
     *
     * @return bool True if switch succeeded
     */
    public static function set(int $siteID, \mysqli $db): bool
    {
        $stmt = $db->prepare(
            'SELECT siteID FROM tblSites WHERE siteID = ? AND isActive = 1 LIMIT 1'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('i', $siteID);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            return false;
        }

        self::$currentSiteID = $siteID;
        self::$currentSite = null;
        self::loadSiteRow($db);

        // 🔄 Update session
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['active_site_id'] = $siteID;
        }

        return true;
    }

    /**
     * 🔑 Re-point the site context to an API key's site for the remainder of a
     * bearer-authenticated (sessionless) request (#323 Phase 2). Bearer requests
     * carry no navbar switcher / session site, so Site::id() would otherwise be
     * the host-detected default and every Site::id()-scoped query would read the
     * wrong tenant. Unlike set(), this NEVER writes $_SESSION (there is no
     * session) and FAILS CLOSED — it throws if the target site is missing or
     * inactive rather than silently leaving the default context in place (which
     * would be a cross-tenant read/write). API-only; must run before any output.
     *
     * @param int $siteId The authenticated key's siteID (authoritative).
     *
     * @return void
     *
     * @throws \RuntimeException If the DB handle/prepare fails, or the site does
     *                           not exist or is inactive.
     */
    public static function forceContext(int $siteId): void
    {
        // 🚧 When multisite is disabled the whole install is siteID=1 by
        //    invariant (Site::init forces it). A stale API key still carrying a
        //    non-default siteID must NOT resurrect multi-tenancy — refuse rather
        //    than re-point (fail closed; #323 Phase 2 review finding 2). A key
        //    whose site already equals the current (single) site is a no-op.
        if (self::isMultisiteEnabled() === false) {
            if ($siteId === self::$currentSiteID) {
                return;
            }
            throw new \RuntimeException(
                'Site::forceContext: multisite disabled; refusing to switch to site ' . $siteId
            );
        }

        $db = App::db();

        $stmt = $db->prepare(
            'SELECT siteID FROM tblSites WHERE siteID = ? AND isActive = 1 LIMIT 1'
        );
        if ($stmt === false) {
            throw new \RuntimeException(
                'Site::forceContext prepare failed: ' . $db->error
            );
        }
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            throw new \RuntimeException(
                'Site::forceContext: site ' . $siteId . ' not found or inactive'
            );
        }

        self::$currentSiteID = $siteId;
        self::$currentSite   = null;
        self::loadSiteRow($db);
    }

    /* ====================================================================== */
    /* Site queries                                                            */
    /* ====================================================================== */

    /**
     * Get all active sites.
     *
     * @param \mysqli $db Database connection
     *
     * @return array List of site row arrays
     */
    public static function allActive(\mysqli $db): array
    {
        $result = $db->query(
            'SELECT siteID, siteName, siteKey, hostPattern, logoPath, faviconPath, '
            . 'primaryColor, copyrightOrg, timezone, isActive, createdAt '
            . 'FROM tblSites WHERE isActive = 1 ORDER BY siteName ASC'
        );
        if ($result === false) {
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get all sites (active and inactive) for admin management.
     *
     * @param \mysqli $db Database connection
     *
     * @return array List of site row arrays
     */
    public static function all(\mysqli $db): array
    {
        $result = $db->query(
            'SELECT siteID, siteName, siteKey, hostPattern, logoPath, faviconPath, '
            . 'primaryColor, copyrightOrg, timezone, isActive, createdAt, updatedAt '
            . 'FROM tblSites ORDER BY siteID ASC'
        );
        if ($result === false) {
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get sites a specific user is assigned to.
     *
     * @param int     $userID User ID
     * @param \mysqli $db     Database connection
     *
     * @return array List of site rows with user's role flags
     */
    public static function userSites(int $userID, \mysqli $db): array
    {
        $stmt = $db->prepare(
            'SELECT S.siteID, S.siteName, S.siteKey, S.logoPath, S.primaryColor, '
            . 'US.isSiteAdmin, US.isSiteRootAdmin '
            . 'FROM tblUserSites US '
            . 'JOIN tblSites S ON S.siteID = US.siteID '
            . 'WHERE US.userID = ? AND US.isActive = 1 AND S.isActive = 1 '
            . 'ORDER BY S.siteName ASC'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $userID);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * 🔍 Fetch and cache a user's site role flags in a single query.
     * Returns the tblUserSites row plus tblUsers.isRootAdmin, or false
     * if the user has no assignment to the given site.
     *
     * @param int     $userID User ID
     * @param int     $siteID Site ID
     * @param \mysqli $db     Database connection
     *
     * @return array|false Role row or false if not assigned
     */
    private static function getUserSiteRole(int $userID, int $siteID, \mysqli $db): array|false
    {
        $cacheKey = $userID . ':' . $siteID;

        // 📦 Return cached result if available
        if (isset(self::$userSiteRoleCache[$cacheKey]) === true) {
            return self::$userSiteRoleCache[$cacheKey];
        }

        // 🔍 Single query: LEFT JOIN tblUserSites so we always get isRootAdmin
        $stmt = $db->prepare(
            'SELECT U.isRootAdmin, US.isSiteAdmin, US.isSiteRootAdmin, US.isActive AS usActive '
            . 'FROM tblUsers U '
            . 'LEFT JOIN tblUserSites US ON US.userID = U.userID AND US.siteID = ? AND US.isActive = 1 '
            . 'WHERE U.userID = ? LIMIT 1'
        );
        if ($stmt === false) {
            self::$userSiteRoleCache[$cacheKey] = false;
            return false;
        }
        $stmt->bind_param('ii', $siteID, $userID);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            self::$userSiteRoleCache[$cacheKey] = false;
            return false;
        }

        self::$userSiteRoleCache[$cacheKey] = $row;
        return $row;
    }

    /**
     * Check if a user belongs to a specific site.
     *
     * @param int     $userID User ID
     * @param int     $siteID Site ID
     * @param \mysqli $db     Database connection
     *
     * @return bool True if user is assigned to the site
     */
    public static function userBelongsTo(int $userID, int $siteID, \mysqli $db): bool
    {
        $role = self::getUserSiteRole($userID, $siteID, $db);
        if ($role === false) {
            return false;
        }

        // 🛡️ Umbrella admins belong to all sites.
        //    This used to be `$role['isRootAdmin'] === '1'`, which was always
        //    false. See flagIsOn() below for why. Fixing it changes what two
        //    callers do for a global administrator with no membership row for
        //    a site:
        //    - /site/switch (site/switch.php) used to refuse to switch them
        //      into that site; it now lets them in.
        //    - Signing in (Auth::setSessionSiteId()) used to move them to the
        //      first site they DO have a row for. It now keeps them on
        //      whatever site Site::id() holds at that moment. What that is
        //      depends on how the portal works out the site:
        //        * Single-site mode: always site 1, so nothing changes.
        //        * Multi-site, subdomain or path mode: the active site whose
        //          address they signed in on, and they stay there. An
        //          address that matches no active site counts as site 1.
        //        * Multi-site, session mode: since 12 October 2026 (#502)
        //          sign-in no longer decides this at all. A person with one
        //          organisation is put in it; a person with several, a global
        //          administrator included, chooses on /site/choose. See
        //          Auth::setSessionSiteId() and detectFromSession() below.
        //      Checked on 13 September 2026 against MySQL 8.0.36 with
        //      PHP 8.5.10, and by reading the start-up order in bootstrap.php
        //      and public_html/index.php.
        //    (This comment used to describe an older fault, #502: in session
        //    mode every request came out as site 1, because the site was
        //    worked out before the session had started. That is fixed; the
        //    session is now looked at inside detectFromSession().)
        //    Ordinary members are unaffected: no row still means no.
        if (self::flagIsOn($role['isRootAdmin']) === true) {
            return true;
        }

        // 📋 Has an active tblUserSites row
        return ($role['isSiteAdmin'] !== null);
    }

    /**
     * Check if a user is a Site Admin for the current site.
     *
     * @param int     $userID User ID
     * @param \mysqli $db     Database connection
     *
     * @return bool True if user has isSiteAdmin=1 for current site
     */
    public static function userIsSiteAdmin(int $userID, \mysqli $db): bool
    {
        $role = self::getUserSiteRole($userID, self::$currentSiteID, $db);
        if ($role === false || $role['isSiteAdmin'] === null) {
            return false;
        }
        // 🔢 Was `=== '1'` on both flags, which was always false, so this
        //    method said "no" for every site administrator. See flagIsOn().
        //    ⚠️ Nothing in the portal calls this method, or
        //    userIsSiteRootAdmin() below (searched across web/ on
        //    13 September 2026). So nobody ever saw the wrong answer, and
        //    there is no symptom or caller to go looking for. Both are now
        //    correct for whoever calls them first.
        return (self::flagIsOn($role['isSiteAdmin']) === true
            || self::flagIsOn($role['isSiteRootAdmin']) === true);
    }

    /**
     * Check if a user is a Site Root Admin for the current site.
     *
     * @param int     $userID User ID
     * @param \mysqli $db     Database connection
     *
     * @return bool True if user has isSiteRootAdmin=1 for current site
     */
    public static function userIsSiteRootAdmin(int $userID, \mysqli $db): bool
    {
        $role = self::getUserSiteRole($userID, self::$currentSiteID, $db);
        if ($role === false || $role['isSiteRootAdmin'] === null) {
            return false;
        }
        // 🔢 Was `=== '1'`, which was always false. See flagIsOn().
        return self::flagIsOn($role['isSiteRootAdmin']);
    }

    /**
     * 🔢 Is a yes/no flag read from the database switched on?
     *
     * WHAT WAS WRONG BEFORE: userBelongsTo(), userIsSiteAdmin() and
     * userIsSiteRootAdmin() compared isRootAdmin, isSiteAdmin and
     * isSiteRootAdmin with the TEXT '1'. Those columns hold 0 or 1, and
     * getUserSiteRole() reads them through a prepared statement. With mysqlnd
     * (the database driver PHP ships with) a prepared statement hands
     * whole-number columns back as PHP whole numbers: 1, not '1'. In PHP
     * `1 === '1'` is false, because `===` compares the type as well as the
     * value, so every one of those flag tests said "no". Checked on
     * 13 September 2026 against MySQL 8.0.36 with PHP 8.5.10.
     *
     * What that meant for each method (an earlier version of this comment
     * said all three said "no" for everybody, which overstated it; corrected
     * after the Codex catch-up review on 14 September 2026):
     *  - userIsSiteAdmin() and userIsSiteRootAdmin() rest entirely on the
     *    flags, so they did say "no" for everybody.
     *  - userBelongsTo() only used the flag to let a global administrator
     *    belong everywhere. Its last line still accepts anybody with a
     *    membership row, so ordinary members were never refused; only a
     *    global administrator WITHOUT a membership row was.
     *
     * It accepts exactly two things as "on": the whole number 1 and the text
     * '1'. So it is right whether the value came from a prepared statement
     * (numbers) or from query() (text). A cast such as `(int) $value === 1`
     * was not used, because it
     * would also count true, '01' and 1.5 as "on"; the two-value test cannot
     * widen by accident. NULL, which is what a missing membership row gives,
     * counts as "off", as it did before.
     *
     * @param mixed $value The flag exactly as it came out of the database.
     *
     * @return bool True only when the flag holds 1 (as a number or as text).
     */
    private static function flagIsOn(mixed $value): bool
    {
        return ($value === 1 || $value === '1');
    }

    /**
     * Resolve the default site for a user after login.
     * Returns the first active site the user is assigned to, or 1 as fallback.
     *
     * @param int     $userID User ID
     * @param \mysqli $db     Database connection
     *
     * @return int Site ID
     */
    public static function resolveDefaultSiteForUser(int $userID, \mysqli $db): int
    {
        $stmt = $db->prepare(
            'SELECT US.siteID FROM tblUserSites US '
            . 'JOIN tblSites S ON S.siteID = US.siteID '
            . 'WHERE US.userID = ? AND US.isActive = 1 AND S.isActive = 1 '
            . 'ORDER BY US.siteID ASC LIMIT 1'
        );
        if ($stmt === false) {
            return 1;
        }
        $stmt->bind_param('i', $userID);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row !== null) {
            return (int) $row['siteID'];
        }
        return 1;
    }

    /* ====================================================================== */
    /* "Your organisations", and where each one is                            */
    /* ====================================================================== */

    /**
     * 👥 The organisations a person may be in: "your organisations".
     *
     * THE ONE LIST. The chooser at sign-in, the switcher in the menu, the
     * switch handler and the members-only gate (SiteGate) all ask here, so
     * they cannot disagree about who belongs where. It is:
     *   - every active organisation the person has an active membership row
     *     for (tblUserSites), or
     *   - EVERY active organisation, for a global administrator
     *     (tblUsers.isRootAdmin), with or without a row.
     * And nothing at all for an account that is switched off or gone.
     *
     * WHAT WAS WRONG: the switcher listed membership rows only
     * (userSites()), so a global administrator with no row saw no switcher
     * and had no way to move between organisations.
     *
     * The flags are tested inside the database (`= 1`), not in PHP, so the
     * "a flag arrives as the number 1, not the text '1'" trap cannot bite.
     *
     * A failed look-up THROWS (a RuntimeException, or the database's own
     * exception). It never returns an empty list for a failure: "no
     * organisations" and "could not ask" must not look the same, because
     * one refuses a person and the other is a fault to report.
     *
     * ⚠️ ONLY columns that have been in the database since multi-organisation
     * was introduced (migration 015), and it must stay that way. This runs
     * before every page, the database upgrade page included. A column added
     * by a later migration would make it fail on a database that has not run
     * that migration yet, and so lock the administrator out of the page that
     * would add it.
     *
     * Kept for the rest of the request: a signed-in page asks two or three
     * times (the gate, then the menu).
     *
     * @param int     $userID The account.
     * @param \mysqli $db     Database connection.
     *
     * @return list<array{siteID:int, siteName:string, siteKey:string, hostPattern:?string, logoPath:?string, primaryColor:?string}>
     *         Ordered by name.
     *
     * @throws \RuntimeException When the question could not be asked.
     */
    public static function organisationsFor(int $userID, \mysqli $db): array
    {
        if (isset(self::$organisationsCache[$userID]) === true) {
            return self::$organisationsCache[$userID];
        }

        $stmt = $db->prepare(
            'SELECT S.siteID, S.siteName, S.siteKey, S.hostPattern, S.logoPath, S.primaryColor '
            . 'FROM tblUsers U '
            . 'JOIN tblSites S ON S.isActive = 1 '
            . 'LEFT JOIN tblUserSites US ON US.userID = U.userID AND US.siteID = S.siteID AND US.isActive = 1 '
            . 'WHERE U.userID = ? AND U.isActive = 1 AND (US.userSiteID IS NOT NULL OR U.isRootAdmin = 1) '
            . 'ORDER BY S.siteName ASC, S.siteID ASC'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Site::organisationsFor could not be prepared: ' . $db->error);
        }
        $stmt->bind_param('i', $userID);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as $i => $row) {
            $rows[$i]['siteID'] = (int) $row['siteID'];
        }
        self::$organisationsCache[$userID] = $rows;

        return $rows;
    }

    /**
     * Is this organisation one of the person's own? See organisationsFor().
     *
     * @throws \RuntimeException When the question could not be asked.
     */
    public static function isOwnOrganisation(int $userID, int $siteID, \mysqli $db): bool
    {
        foreach (self::organisationsFor($userID, $db) as $own) {
            if ($own['siteID'] === $siteID) {
                return true;
            }
        }

        return false;
    }

    /**
     * 🔗 Where another organisation's front page is, in the way in use.
     *
     *   first part of the address   "/<its key>/"
     *   own address each            "//<its address>/" — two slashes, so the
     *                               browser keeps http or https as it is now.
     *                               A port in the present address is kept,
     *                               because every organisation is served by
     *                               the same web server.
     *   sign-in session             '' — there is no address to give; the
     *                               organisation is changed by posting to
     *                               /site/switch.
     * Also '' in own-address mode for an organisation with no address stored.
     * A caller must treat '' as "no link to offer".
     *
     * WHAT IT CANNOT DO: in own-address mode it cannot carry the sign-in
     * across. Browsers keep cookies per address, so the person signs in again
     * there. Nothing here can change that.
     *
     * @param array<string,mixed> $site A row holding siteKey and hostPattern.
     */
    public static function addressOf(array $site): string
    {
        if (self::$enabled === false) {
            return '';
        }
        if (self::$mode === self::MODE_PATH) {
            $key = (string) ($site['siteKey'] ?? '');

            return $key === '' ? '' : '/' . rawurlencode($key) . '/';
        }
        if (self::$mode === self::MODE_HOST) {
            $host = self::cleanHostName((string) ($site['hostPattern'] ?? ''));
            if ($host === null || $host === '') {
                return '';
            }
            $port = preg_match('/:(\d{1,5})$/', (string) ($_SERVER['HTTP_HOST'] ?? ''), $m) === 1 ? ':' . $m[1] : '';

            return '//' . $host . $port . '/';
        }

        return '';
    }

    /**
     * 🧹 A host name as a browser sent it, made comparable: lower case, with
     * any port and any trailing dot taken off.
     *
     * WHAT WAS WRONG: the whole Host header was compared with the stored
     * address. "portal.example.org:8443" and "portal.example.org." are the
     * same place as "portal.example.org", but neither matched, so those
     * requests quietly came out as the default organisation (seen in the
     * planner's trial of 12 October 2026).
     *
     * An address written in IPv6 form, "[::1]:8080", keeps its brackets and
     * loses only what follows them.
     */
    public static function normaliseHost(string $raw): string
    {
        $host = strtolower(trim($raw));
        if ($host === '') {
            return '';
        }
        if ($host[0] === '[') {
            $end = strpos($host, ']');

            return $end === false ? $host : substr($host, 0, $end + 1);
        }
        $colon = strrpos($host, ':');
        if ($colon !== false) {
            $host = substr($host, 0, $colon);
        }

        return rtrim($host, '.');
    }

    /**
     * ✅ An organisation's own address as an administrator typed it, checked.
     *
     * Returns '' for nothing typed, the tidied host name when it is a plain
     * host name, and null when it is anything else. Capitals and one trailing
     * dot are tidied quietly. Everything else is REFUSED, not repaired:
     * "https://x/y", a path, a port, a space or a wildcard would each be
     * stored as text that no request can ever match, and the organisation
     * would silently be unreachable at its own address.
     *
     * WHAT IT CANNOT DO: tell whether the address is really pointed at this
     * portal's web folder. That is set in the hosting panel.
     */
    public static function cleanHostName(string $typed): ?string
    {
        $host = rtrim(strtolower(trim($typed)), '.');
        if ($host === '') {
            return '';
        }
        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
        if (strlen($host) > 253 || preg_match('/^' . $label . '(?:\.' . $label . ')*$/', $host) !== 1) {
            return null;
        }

        return $host;
    }

    /**
     * 🚦 Why a way of telling organisations apart cannot be CHOSEN right now.
     *
     * The set-up page shows these sentences and its save handler refuses on
     * them, both from this one list, so the page can never offer what the
     * handler would refuse. An empty list means the way may be chosen.
     *
     *   own address each   every ACTIVE organisation needs an address, each
     *                      a plain host name, and no two organisations may
     *                      share one. (Migration 212 adds a database rule
     *                      against sharing, but skips it on a database that
     *                      already holds a shared address, so it is checked
     *                      here as well.)
     *   first part of the  not ready yet (FIRST_PART_MODE_READY), and no
     *   address            ACTIVE organisation's key may be an address the
     *                      portal itself answers on (ReservedKeys).
     *   sign-in session    nothing refuses it. What it cannot do (a
     *                      signed-out visitor always sees the default
     *                      organisation) is said on the set-up page.
     *
     * If a check cannot be run, that is itself a reason: a way is never
     * allowed on a check that did not run.
     *
     * @return list<string> Plain sentences; empty when the way may be chosen.
     */
    public static function modeProblems(string $mode, \mysqli $db): array
    {
        if ($mode === self::MODE_SESSION) {
            return [];
        }
        if ($mode !== self::MODE_PATH && $mode !== self::MODE_HOST) {
            return ['That is not one of the three ways.'];
        }

        $problems = [];
        try {
            if ($mode === self::MODE_PATH) {
                if (self::FIRST_PART_MODE_READY === false) {
                    $problems[] = 'This way is not ready yet. Many links inside the portal still leave out the '
                        . 'organisation\'s part of the address, so they would take people to the wrong organisation. '
                        . 'It will be offered here once those links have been corrected.';
                }
                foreach (ReservedKeys::clashingSites($db) as $clash) {
                    if ($clash['isActive'] === 1) {
                        $problems[] = 'The organisation "' . $clash['siteName'] . '" has the key "' . $clash['siteKey']
                            . '", which is an address the portal itself uses. Change that key first.';
                    }
                }

                return $problems;
            }

            $holders = [];
            foreach (self::all($db) as $site) {
                $stored = trim((string) ($site['hostPattern'] ?? ''));
                $active = ((int) $site['isActive'] === 1);
                if ($stored === '') {
                    if ($active === true) {
                        $problems[] = 'The organisation "' . $site['siteName'] . '" has no address yet. Give it one first.';
                    }
                    continue;
                }
                $clean = self::cleanHostName($stored);
                if ($clean === null) {
                    $problems[] = 'The address stored for "' . $site['siteName'] . '" is not a plain host name '
                        . '(such as north.example.org). Correct it first.';
                    continue;
                }
                $holders[$clean][] = (string) $site['siteName'];
            }
            foreach ($holders as $host => $names) {
                if (count($names) > 1) {
                    $problems[] = 'The address ' . $host . ' is given to more than one organisation ("'
                        . implode('", "', $names) . '"). Each organisation needs an address of its own.';
                }
            }
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] Site::modeProblems could not finish its checks: ' . $e->getMessage());
            $problems[] = 'The portal could not finish checking whether this way is safe to choose. Try again.';
        }

        return $problems;
    }

    /**
     * ⚙️ The set-up as it is STORED: is multi-organisation on, and which way.
     * Read from the two portal-wide rows, the same two preDetect() reads.
     *
     * For the set-up page. detectionMode() cannot serve it: while
     * multi-organisation is off that returns the default, not the way an
     * administrator last chose.
     *
     * @return array{enabled:bool, mode:string}
     *
     * @throws \RuntimeException When the rows could not be read.
     */
    public static function storedSetup(\mysqli $db): array
    {
        $enabled = self::portalWideSetting($db, 'multisite.enabled');
        $mode    = self::portalWideSetting($db, 'multisite.detectionMode');
        if ($enabled === false || $mode === false) {
            throw new \RuntimeException('The multi-organisation settings could not be read: ' . $db->error);
        }

        return ['enabled' => ($enabled === 'true'), 'mode' => self::knownMode($mode ?? self::MODE_SESSION)];
    }

    /**
     * 💾 Save the set-up: the two portal-wide rows, together or not at all.
     *
     * ONLY the set-up save handler calls this, after modeProblems() has
     * said the way may be chosen. It does not ask that question itself.
     * The way is left as stored when multi-organisation is being switched
     * off, so switching it back on later offers the same way.
     *
     * Each row is UPDATED where it exists and inserted only where it does
     * not. An "insert, or update on a duplicate" statement is not used: the
     * portal-wide row has nothing in its organisation column, and on a
     * database that still lacks migration 187's key such a statement adds a
     * second row instead of changing the first (the "settings table trap").
     *
     * @throws \Throwable When either row could not be written; nothing is changed then.
     */
    public static function saveSetup(\mysqli $db, bool $enabled, string $mode): void
    {
        $values = ['multisite.enabled' => $enabled === true ? 'true' : 'false'];
        if ($enabled === true) {
            $values['multisite.detectionMode'] = self::knownMode($mode);
        }

        $db->begin_transaction();
        try {
            foreach ($values as $key => $value) {
                $update = $db->prepare('UPDATE tblSettings SET settingValue = ? WHERE settingKey = ? AND siteID IS NULL');
                if ($update === false) {
                    throw new \RuntimeException('Could not prepare the update of ' . $key . ': ' . $db->error);
                }
                $update->bind_param('ss', $value, $key);
                $update->execute();
                $update->close();

                // "No row changed" also happens when the value was already
                // this one, so ask whether the row exists before adding it.
                if (self::portalWideSetting($db, $key) === null) {
                    $default = $key === 'multisite.enabled' ? 'false' : self::MODE_SESSION;
                    $insert  = $db->prepare(
                        'INSERT INTO tblSettings (siteID, settingKey, settingValue, isSensitive, defaultValue) VALUES (NULL, ?, ?, 0, ?)'
                    );
                    if ($insert === false) {
                        throw new \RuntimeException('Could not prepare the insert of ' . $key . ': ' . $db->error);
                    }
                    $insert->bind_param('sss', $key, $value, $default);
                    $insert->execute();
                    $insert->close();
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    /**
     * 🔒 Is this setting kept out of the GENERAL settings editor (/settings)?
     *
     * Yes for the two that decide how organisations are told apart. They are
     * changed on Admin, Organisations, "How organisations are told apart",
     * which refuses a way that would leave an organisation unreachable.
     * The general editor knows none of that: one typed word there would
     * change which organisation every address on the installation opens.
     * A row for ONE organisation is refused as well: nothing reads it, so
     * it would only mislead whoever found it later.
     *
     * The editor asks this in THREE places, and all three refuse: changing
     * a row and adding one (web/_apps/settings/save.php), and deleting one
     * (web/_apps/settings/index.php). The delete was missed at first: a
     * global administrator could delete the portal-wide "multisite.enabled"
     * row, which switched multi-organisation off for everybody at once.
     * tools/site-modes-selftest.php tries all three.
     *
     * WHAT IT CANNOT DO: stop somebody changing the rows straight in the
     * database. If a row goes missing, the set-up page puts it back the
     * next time it is saved (saveSetup()).
     *
     * Names are compared without regard to capitals, as the database does.
     */
    public static function lockedInGeneralEditor(string $key): bool
    {
        return strcasecmp($key, 'multisite.enabled') === 0 || strcasecmp($key, 'multisite.detectionMode') === 0;
    }

    /** What the general settings editor says when it refuses one of them. */
    public const LOCKED_MESSAGE = 'That setting decides how the organisations on this installation are told apart. '
        . 'It can only be changed at Admin, Organisations, "How organisations are told apart", where a choice that '
        . 'would leave an organisation unreachable is refused. Nothing was changed.';

    /**
     * 🔗 The address of a page as it will be under a set-up that has JUST
     * BEEN SAVED, for the redirect that follows the save.
     *
     * url() builds addresses for the way THIS request arrived by. Straight
     * after the way is changed that can be wrong: leaving "first part of
     * the address" mode, an address that still carries the organisation's
     * key would answer "page not found" on the very next request. The key
     * is kept only when the installation both was and still is in that way.
     */
    public static function urlUnder(bool $enabled, string $mode, string $routeKey): string
    {
        if ($enabled === true && $mode === self::MODE_PATH) {
            return self::url($routeKey);
        }

        return '/' . ltrim($routeKey, '/');
    }

    /** A stored way that is not one of the three is treated as the seeded default, the sign-in session. */
    private static function knownMode(string $mode): string
    {
        return isset(self::MODE_LABELS[$mode]) === true ? $mode : self::MODE_SESSION;
    }

    /** Did the organisation look-up fail, so that Site::id() is only a guess? See $detectionFailed. */
    public static function detectionFailed(): bool
    {
        return self::$detectionFailed;
    }

    /** Session mode: which of the SESSION_* answers preDetect() reached. */
    public static function sessionState(): string
    {
        return self::$sessionState;
    }

    /** Session mode: the account the organisation was worked out for (0 = nobody). See $resolvedUserId. */
    public static function resolvedUserId(): int
    {
        return self::$resolvedUserId;
    }

    /* ====================================================================== */
    /* Pre-settings site detection (called from bootstrap before settings)    */
    /* ====================================================================== */

    /**
     * 🧭 Work out which organisation this request belongs to. THE ONE PLACE
     * that decides it (#502). Called by bootstrap.php before the settings are
     * loaded, because the answer decides WHICH organisation's settings to
     * load.
     *
     * It reads the two portal-wide settings (is multi-organisation on, and
     * which way), then asks exactly one of the three detections below.
     *
     * WHEN IT CANNOT FIND OUT. It still returns 1, because start-up needs a
     * number to carry on with, but it leaves $detectionFailed set. SiteGate
     * (called by web/public_html/index.php before any page runs) then shows
     * a "try again" page instead of any organisation's page. This used to
     * return 1 and say nothing, so one failed query showed a signed-in
     * person the default organisation as if it were their own.
     *
     * A stored way that is not one of the three is treated as the seeded
     * default, the sign-in session, and written to PHP's error log. It is
     * NOT treated as a failure: that would lock every administrator out of
     * the page that corrects it.
     *
     * WHAT IT CANNOT DO: say whether the signed-in person may be in the
     * organisation an ADDRESS points at. In the two address ways the address
     * alone decides the organisation; SiteGate then refuses anybody who is
     * not a member of it.
     *
     * @param \mysqli $db Database connection
     *
     * @return int Detected site ID (defaults to 1)
     */
    public static function preDetect(\mysqli $db): int
    {
        self::$detectionFailed = true;
        self::$preEnabled      = null;
        self::$preMode         = null;
        self::$sessionState    = self::SESSION_NOT_USED;
        self::$resolvedUserId  = 0;

        // 🔍 Check if multisite is enabled (direct query, settings not loaded yet)
        $enabled = self::portalWideSetting($db, 'multisite.enabled');
        if ($enabled === false) {
            return 1;
        }
        if ($enabled !== 'true') {
            self::$preEnabled      = false;
            self::$detectionFailed = false;

            return 1;
        }

        // 🔍 Which way
        $stored = self::portalWideSetting($db, 'multisite.detectionMode');
        if ($stored === false) {
            return 1;
        }
        $mode = self::knownMode($stored ?? self::MODE_SESSION);
        if ($stored !== null && $stored !== $mode) {
            error_log('[WebMS-Intra] The setting multisite.detectionMode holds "' . $stored
                . '", which is not one of the three ways. Using the sign-in session instead.');
        }
        self::$preEnabled = true;
        self::$preMode    = $mode;

        if ($mode === self::MODE_HOST) {
            $siteId = self::detectFromSubdomain($db);
        } elseif ($mode === self::MODE_PATH) {
            $siteId = self::detectFromPath($db);
        } else {
            $siteId = self::detectFromSession($db);
        }
        if ($siteId === null) {
            return 1;
        }
        self::$detectionFailed = false;

        return $siteId;
    }

    /**
     * One portal-wide setting, read straight from the table (the settings
     * are not loaded yet).
     *
     * A DATABASE OLDER THAN MULTI-ORGANISATION ITSELF. Before migration 015
     * the settings table had no organisation column, so this query cannot
     * even be prepared there (error 1054, "unknown column"); on a database
     * that is only part built the table may be missing (error 1146). Both
     * are answered as "no such row", NOT as a failure: such a database
     * cannot have multi-organisation switched on, so "off" is the true
     * answer. Treating them as a failure would put the "try again" page
     * (SiteGate) in front of every address, the upgrade page included, and
     * an administrator could then never upgrade that database. Any other
     * error is still a failure.
     *
     * @return string|false|null The value; null when there is no such row;
     *                           false when the question could not be asked.
     *
     * @throws \mysqli_sql_exception For any database error other than those two.
     */
    private static function portalWideSetting(\mysqli $db, string $key): string|false|null
    {
        try {
            $stmt = $db->prepare('SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL LIMIT 1');
        } catch (\mysqli_sql_exception $e) {
            if ($e->getCode() === 1054 || $e->getCode() === 1146) {
                return null;
            }
            throw $e;
        }
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row === null ? null : (string) $row['settingValue'];
    }

    /**
     * Own address each: match the request's host name to an organisation's
     * stored address. The host name is tidied first (normaliseHost()): lower
     * case, no port, no trailing dot.
     *
     * An address no active organisation holds is the default organisation,
     * as before. That is safe for a signed-in person because SiteGate then
     * asks whether they are a member of it.
     *
     * @param \mysqli $db Database connection
     *
     * @return int|null Detected site ID; null when the look-up could not be made.
     */
    private static function detectFromSubdomain(\mysqli $db): ?int
    {
        $host = self::normaliseHost((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return 1;
        }

        // 🔍 Look up tblSites by hostPattern match
        $stmt = $db->prepare(
            'SELECT siteID FROM tblSites WHERE hostPattern = ? AND isActive = 1 ORDER BY siteID ASC LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('s', $host);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row !== null) {
            return (int) $row['siteID'];
        }
        return 1;
    }

    /**
     * Detect site from the first URL path segment.
     * Stores the matched prefix for Router to strip later.
     *
     * @param \mysqli $db Database connection
     *
     * @return int|null Detected site ID; null when the look-up could not be made.
     */
    private static function detectFromPath(\mysqli $db): ?int
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        if ($path === null || $path === false) {
            $path = '/';
        }

        // 🔍 Extract first path segment
        $path = ltrim($path, '/');
        $slashPos = strpos($path, '/');
        $firstSegment = ($slashPos !== false) ? substr($path, 0, $slashPos) : $path;

        if ($firstSegment === '') {
            return 1;
        }

        // 🔍 Look up tblSites by siteKey match
        $stmt = $db->prepare(
            'SELECT siteID FROM tblSites WHERE siteKey = ? AND isActive = 1 LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('s', $firstSegment);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row !== null) {
            self::$pathPrefix = $firstSegment;
            return (int) $row['siteID'];
        }
        return 1;
    }

    /**
     * 🔄 Sign-in session: the organisation the signed-in person chose.
     *
     * WHAT WAS WRONG (#502). This used to return the saved organisation only
     * "if a session is active". It never was: this runs while the portal is
     * starting up, and the session was only started afterwards, by
     * web/public_html/index.php. So every request came out as organisation
     * 1, the organisation chosen at sign-in was ignored, and the switcher
     * saved a choice that the very next page threw away. (Ran on 12 October
     * 2026: organisation 1 after sign-in and after switching, while the
     * session file held the chosen one.)
     *
     * WHAT IT DOES NOW. It takes a read-only look at the session
     * (Auth::peekSession()) and then decides from the DATABASE, never from
     * the saved number alone:
     *   - nobody signed in, or the account is switched off or gone: the
     *     default organisation, exactly as a visitor with no session gets.
     *     (The owner, Salem874, decided on 11 or 12 October 2026 that in
     *     this way a signed-out visitor always sees the default
     *     organisation.) Auth::ensureSession() ends a switched-off
     *     account's session a moment later.
     *   - the saved organisation is one of the person's own
     *     (organisationsFor()): that one.
     *   - it is not (nothing saved yet, or they have been taken out of it
     *     since), and they have exactly one organisation: that one.
     *   - it is not, and they have several: SESSION_CHOOSE. SiteGate sends
     *     them to /site/choose and lets nothing else run.
     *   - they have none: SESSION_NONE. SiteGate refuses every page.
     *
     * A SIGNED-IN PERSON NEVER FALLS BACK TO ORGANISATION 1. In the last
     * two cases a number still has to be returned, because start-up needs
     * one to load settings with: the person's first own organisation when
     * they have one, else 1. No page of that organisation is shown: SiteGate
     * stops the request before the router.
     *
     * TRIED AND REJECTED
     *   - Trusting the saved number. Somebody taken out of an organisation
     *     while signed in would stay in it until they signed out.
     *   - Working the organisation out again after the session starts and
     *     loading the settings a second time. Everything start-up derives
     *     from settings (the time zone, the response headers, the language)
     *     would have to be redone, and the next thing added there would be
     *     forgotten.
     *   - Landing a person with several organisations quietly in their
     *     first. Recording attendance in the wrong organisation would look
     *     entirely normal.
     *
     * WHAT IT CANNOT DO: tell organisations apart for a signed-out visitor.
     * Public pages and the sign-in page are always the default
     * organisation's in this way. The set-up page and the help say so.
     *
     * @param \mysqli $db Database connection
     *
     * @return int|null Site ID; null when a look-up could not be made.
     */
    private static function detectFromSession(\mysqli $db): ?int
    {
        self::$sessionState = self::SESSION_SIGNED_OUT;

        $seen   = Auth::peekSession();
        $userId = $seen['userId'];
        if ($userId <= 0) {
            return 1;
        }

        try {
            $own = self::organisationsFor($userId, $db);
        } catch (\RuntimeException $e) {
            // One line in PHP's error log. This used to return without a
            // word, so the "try again" page (SiteGate) left an administrator
            // nothing to read. In the two address ways a failed look-up
            // already reaches the log, through bootstrap.php. The database's
            // own exception is a RuntimeException too, so it lands here.
            error_log('[WebMS-Intra] Site::preDetect could not read the organisations of account ' . $userId
                . ' (sign-in session way), so the request is shown "try again": ' . $e->getMessage());

            return null;
        }

        if ($own === []) {
            // No organisation: is that because the account is switched off or
            // gone (then they count as nobody), or because an active account
            // really belongs nowhere?
            $stmt = $db->prepare('SELECT isActive FROM tblUsers WHERE userID = ? LIMIT 1');
            if ($stmt === false) {
                return null;
            }
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            // 🔢 A number from a prepared statement, never the text '1'.
            if ($row === null || (int) $row['isActive'] !== 1) {
                return 1;
            }
            self::$resolvedUserId = $userId;
            self::$sessionState   = self::SESSION_NONE;

            return 1;
        }

        self::$resolvedUserId = $userId;
        foreach ($own as $site) {
            if ($site['siteID'] === $seen['siteId']) {
                self::$sessionState = self::SESSION_OK;

                return $site['siteID'];
            }
        }
        if (count($own) === 1) {
            self::$sessionState = self::SESSION_OK;

            return $own[0]['siteID'];
        }
        self::$sessionState = self::SESSION_CHOOSE;

        return $own[0]['siteID'];
    }

    /* ====================================================================== */
    /* URL generation helper                                                   */
    /* ====================================================================== */

    /**
     * Generate a site-aware URL. In 'path' mode, prepends the site key prefix.
     * In other modes, returns the route as-is.
     *
     * @param string $routeKey Route key (e.g. 'expenses/submit')
     *
     * @return string Full URL path
     */
    public static function url(string $routeKey): string
    {
        if (self::$enabled === true && self::$mode === 'path' && self::$pathPrefix !== '') {
            return '/' . self::$pathPrefix . '/' . ltrim($routeKey, '/');
        }
        return '/' . ltrim($routeKey, '/');
    }

    /* ====================================================================== */
    /* Cache management                                                       */
    /* ====================================================================== */

    /**
     * Reset all cached state. Useful after site switch or for testing.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$currentSiteID = 1;
        self::$currentSite = null;
        self::$resolved = false;
        self::$enabled = false;
        self::$mode = 'session';
        self::$pathPrefix = '';
        self::$userSiteRoleCache = [];
        self::$preEnabled = null;
        self::$preMode = null;
        self::$detectionFailed = false;
        self::$sessionState = self::SESSION_NOT_USED;
        self::$resolvedUserId = 0;
        self::$organisationsCache = [];
        self::$zoneInRow = false;
        self::$oldZoneSetting = null;
        self::$settingsSiteId = 1;
        self::$zoneTextCache = [];
    }
}
