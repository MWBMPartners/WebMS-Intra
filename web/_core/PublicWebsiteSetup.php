<?php
// Path: _core/PublicWebsiteSetup.php
/**
 * -----------------------------------------------------------------------------
 * Setting up the PUBLIC WEBSITE: who may, what is stored, and the set-up check 🌐🛠️
 * -----------------------------------------------------------------------------
 * #493, package P3. The ONE place that decides everything behind the page
 * Admin → Public website (`/admin/public-website`):
 *
 *   1. WHO MAY OPEN IT (`maySetUp()`, D-085): the installation's global
 *      administrator, the organisation's own global administrator, or somebody
 *      holding the permission "Set up the public website"
 *      (`Capabilities::WEBSITE_SETUP`), which ships pointing at the role
 *      "Website Set-up" that NOBODY holds. An ordinary administrator of the
 *      organisation does NOT pass: this page decides what outside websites may
 *      do with the organisation's pages, which is a global administrator's
 *      job until they hand it to somebody by name.
 *   2. WHO MAY HAND THAT JOB OUT (`mayHandOut()`, `whyRoleChangeRefused()`,
 *      D-093): only a global administrator — of the organisation or of the
 *      installation — may change which roles open "Set up the public
 *      website", or give or take away a role that currently opens it. The
 *      Roles page and the Users page both ask here; neither keeps a copy.
 *   3. WHAT IS STORED, and reading it back CHECKED AGAIN every time:
 *        public.website.address  the organisation's MAIN website (D-095),
 *                                for example https://www.ourchurch.org. The
 *                                organisation's own row only.
 *        public.frame.origins    the websites allowed to show the
 *                                organisation's public pages in a frame
 *                                (D-082). The organisation's own row only; an
 *                                installation-wide row is IGNORED, because one
 *                                organisation's list must never become
 *                                everybody's.
 *        public.frame.permitted  INSTALLATION ONLY, 'yes' or 'no' (D-094):
 *                                whether framing is allowed at all. 'no' wins
 *                                over every organisation's list.
 *        tblPublicHosts          the OPTIONAL public address for the portal's
 *                                own public pages (D-095), for example
 *                                events.ourchurch.org. One per organisation,
 *                                and no two organisations may share one — the
 *                                database's unique key is the real guard.
 *   4. THE SET-UP CHECK (`checkSetUp()`): what can be known today, and
 *      nothing more. It says whether the public door is on and gives its
 *      addresses. Since #493 P4d it also FETCHES the organisation's public
 *      address, when one is in effect and the door would answer there, and
 *      says whether the answer carried the proof only a door holding this
 *      organisation's current door name can work out
 *      (`PublicDoor::checkProof()`, `publicAddressVerdict()`).
 *      Whether a WEBSITE really shows the door is still always "not
 *      tested": the only page of the organisation's own website it fetches is the
 *      main website's FRONT page, to see that it answers; it never looks
 *      for the door there.
 *   5. THE PUBLIC DOOR'S NAME (#493 P4a, D-097): whether the door is on, its
 *      random public name, and the three writers that turn it on, replace
 *      its name and switch it off (`turnDoorOn()`, `replaceDoorName()`,
 *      `turnDoorOff()`), each recorded. The two look-ups start-up makes
 *      before any session is looked at (`siteForDoorName()`,
 *      `publicHostRow()`), and D-087 for test copies: `testCopyDoorRule()`,
 *      the ONE answer the door, the card and the set-up check ask, and the
 *      two writers of its tiered setting (#493 P4d) — `saveTestCopyLimit()`
 *      (the installation's global administrator sets the ceiling) and
 *      `saveTestCopyChoice()` (an organisation's global administrator
 *      chooses within it). Every sentence telling somebody who the door
 *      answers, where to try it or what a website gets is
 *      `doorReachWords()`'s, whole, per state — the card, the set-up check
 *      and both saves ask it. The door itself — what it answers — is
 *      `Portal\Core\PublicDoor`.
 *
 * EVERY BAD VALUE IS REFUSED WITH ITS REASON, never quietly corrected (#668).
 * Tidying that cannot change what an address means (capitals, one closing
 * "/", a port that is the usual one) is done quietly, as `PortalAddress`
 * already does; anything else — a path, a wildcard, a plain-http framing
 * website, a twenty-first line — refuses the WHOLE save and says which line.
 *
 * WHAT IT CANNOT DO, said plainly
 * -----------------------------------------------------------------------------
 *   - Nothing here SENDS a header or answers on the public address:
 *     `Portal\Core\PublicDoor` does both (#493 P4a), asking this class for
 *     the framing rule (`frameAncestorsFor()`) and the public address.
 *   - The set-up check can tell that a public address reaches THIS
 *     organisation's door on THIS portal, but not whether something sits in
 *     between and passes the request on (a proxy or a content network): an
 *     answer that came that way carries the same proof, and it really did
 *     reach the door. See `PublicDoor::checkProof()`.
 *   - It cannot stop a row being changed straight in the database. That is
 *     why every reader checks the stored value again and drops (and logs)
 *     anything it would have refused.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/493
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class PublicWebsiteSetup
{
    /**
     * The three settings keys. They are declared on `PublicWebsite`, whose
     * `lockedInGeneralEditor()` refuses them in the general settings editor,
     * and copied here so a reader of this class sees what it stores. The
     * settings-locks self-test finds a lock's keys by reading the lock
     * method's own body, so the keys must be `self::` constants THERE.
     */
    public const KEY_WEBSITE         = PublicWebsite::WEBSITE_ADDRESS_KEY;
    public const KEY_FRAME_ORIGINS   = PublicWebsite::FRAME_ORIGINS_KEY;
    public const KEY_FRAME_PERMITTED = PublicWebsite::FRAME_PERMITTED_KEY;

    /** The table holding each organisation's optional public address (D-095). */
    public const HOSTS_TABLE = 'tblPublicHosts';

    /**
     * The table holding each organisation's public door name (#493 P4a,
     * migration 263). A row means the door is on; no row means off (D-097).
     */
    public const DOOR_TABLE = 'tblPublicDoorNames';

    /**
     * D-087's two settings. The installation's limit ('testers' or 'either',
     * installation row only, seeded 'testers' by migration 263) and the
     * organisation's own choice ('testers' or 'anybody', its own row only, not
     * seeded). Read by `testCopyDoorOpenToAnybody()`, through
     * `testCopyDoorRule()`. Written ONLY by `saveTestCopyLimit()` and
     * `saveTestCopyChoice()`, from the card on Admin → Public website (#493
     * P4d); the general settings editor refuses both
     * (`PublicWebsite::LOCKED_KEYS`). Declared on `PublicWebsite`, which locks
     * them.
     */
    public const KEY_DOOR_TEST_PERMITTED = PublicWebsite::DOOR_TEST_PERMITTED_KEY;
    public const KEY_DOOR_TEST_OPEN      = PublicWebsite::DOOR_TEST_OPEN_KEY;

    /**
     * The most websites one organisation may list as allowed to frame its
     * pages. A cap IN CODE (D-082): a list longer than this is refused with
     * the reason, never shortened.
     */
    public const MAX_FRAME_ORIGINS = 20;

    /** How long the set-up check waits for the main website, in seconds. */
    public const CHECK_TIMEOUT_SECONDS = 5;

    /** How many set-up checks one person may run in the window below. */
    public const CHECK_LIMIT = 6;

    /** The window, in seconds, the limit above counts in. */
    public const CHECK_WINDOW_SECONDS = 600;

    /**
     * The whole set-up check's main-website fetch may take this long in all,
     * in seconds, counting every redirect it follows. Each step has
     * `CHECK_TIMEOUT_SECONDS` of its own within it. Looking the NAME up is not
     * counted by either: PHP's name lookup cannot be given a time limit.
     */
    public const CHECK_DEADLINE_SECONDS = 15;

    /** How many redirects the check follows before it gives up. */
    public const CHECK_MAX_REDIRECTS = 3;

    /** What the Roles page and the Users page say when D-093 refuses a change. */
    public const HAND_OUT_REFUSED = 'Only a global administrator of this organisation (or of the whole installation) '
        . 'can change who may set up the public website. That covers which roles open "Set up the public website" on '
        . 'the Roles page, and giving or taking away a role that opens it. Nothing was changed.';

    // =========================================================================
    // 1️⃣ Who may open the page (D-085), and who may hand the job out (D-093)
    // =========================================================================

    /**
     * Is this ACTIVE account a global administrator of this organisation —
     * the installation's (`isRootAdmin`), or this organisation's own
     * (`isSiteRootAdmin` on an ACTIVE membership of THIS organisation)?
     *
     * Read LIVE from the database, never from the session: a flag taken away
     * counts for nothing on the very next request. An ordinary administrator
     * (`isAdmin`, `isSiteAdmin`) is NOT a global administrator and answers
     * false — that is the whole of D-085 and D-093.
     */
    public static function isGlobalAdministratorOf(\mysqli $db, int $userId, int $siteId): bool
    {
        if ($userId <= 0 || $siteId <= 0) {
            return false;
        }
        $stmt = $db->prepare(
            'SELECT 1 FROM `tblUsers` u WHERE u.`userID` = ? AND u.`isActive` = 1 AND (u.`isRootAdmin` = 1 OR EXISTS ('
            . 'SELECT 1 FROM `tblUserSites` s WHERE s.`userID` = u.`userID` AND s.`siteID` = ? AND s.`isActive` = 1 '
            . 'AND s.`isSiteRootAdmin` = 1))'
        );
        $stmt->bind_param('ii', $userId, $siteId);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_row();
        $stmt->close();

        return $found !== null;
    }

    /**
     * 🚪 May this person open Admin → Public website, and save on it, for this
     * organisation? THE ONE GATE: the page, its save handler, its check
     * button, the admin dashboard's tile and the menu entry all ask it.
     *
     *   - a global administrator of the installation or of the organisation,
     *     always (D-017: "an organisation's global administrator has automatic
     *     access to everything");
     *   - anybody else only through the permission "Set up the public
     *     website", which an organisation's global administrator can point at
     *     any role (D-085) and which ships pointing at "Website Set-up",
     *     granted to nobody.
     *
     * An ordinary administrator does NOT pass. `false` is passed as the
     * global-administrator flag to `Capabilities::allows()` because the global
     * administrators were answered just above, from the database.
     */
    public static function maySetUp(\mysqli $db, int $userId, int $siteId): bool
    {
        if ($userId <= 0 || $siteId <= 0) {
            return false;
        }
        if (self::isGlobalAdministratorOf($db, $userId, $siteId) === true) {
            return true;
        }

        return Capabilities::allows($db, $userId, $siteId, Capabilities::WEBSITE_SETUP, false);
    }

    /**
     * The same question for the person signed in, in the organisation open
     * now, for the menu and the admin dashboard's tile. NEVER THROWS: it is
     * asked while drawing every page, and a database without migration 262
     * (files reach a server before anybody presses "Run migrations") must
     * hide a menu entry, not break the page. Remembered for the request.
     */
    public static function maySetUpHere(): bool
    {
        static $answer = null;
        if ($answer !== null) {
            return $answer;
        }
        try {
            $userId = Auth::check() === true ? (int) ($_SESSION['user_id'] ?? 0) : 0;
            $answer = $userId > 0 ? self::maySetUp(App::db(), $userId, Site::id()) : false;
        } catch (\Throwable $e) {
            $answer = false;
        }

        return $answer;
    }

    /**
     * May this person HAND OUT the job (D-093)? Only a global administrator of
     * this organisation or of the installation. Holding the permission
     * itself, or being an ordinary administrator, is not enough — otherwise
     * an ordinary administrator could give themselves the job.
     */
    public static function mayHandOut(\mysqli $db, int $userId, int $siteId): bool
    {
        return self::isGlobalAdministratorOf($db, $userId, $siteId);
    }

    /**
     * The role keys that open "Set up the public website" in this
     * organisation right now: its own chosen set, or the shipped one.
     *
     * @return list<string>
     */
    public static function rolesThatOpenSetUp(\mysqli $db, int $siteId): array
    {
        return Capabilities::roleKeysInForce($db, $siteId, Capabilities::WEBSITE_SETUP);
    }

    /**
     * D-093 in ONE place. Given the role keys a change would GIVE or TAKE AWAY
     * (or, on the Roles page, the role a change would remove from the
     * permission's set), why may this person not make it — or '' when they
     * may.
     *
     * Refused only when the change touches a role that CURRENTLY opens the
     * permission AND the person is not a global administrator. A change that
     * touches no such role is not this rule's business, so an ordinary
     * administrator keeps every other part of the Users and Roles pages.
     *
     * @param list<string> $roleKeysTouched Role keys given or taken away.
     */
    public static function whyRoleChangeRefused(\mysqli $db, int $actorId, int $siteId, array $roleKeysTouched): string
    {
        if (self::mayHandOut($db, $actorId, $siteId) === true) {
            return '';
        }
        $opens = self::rolesThatOpenSetUp($db, $siteId);
        foreach ($roleKeysTouched as $key) {
            if (in_array(Roles::normaliseKey((string) $key), $opens, true) === true) {
                return self::HAND_OUT_REFUSED;
            }
        }

        return '';
    }

    /**
     * D-093 for the Roles page's "who can open what" form: may this person
     * change which roles open THIS capability? Every capability but
     * "Set up the public website" answers '' (not this rule's business).
     */
    public static function whyMappingChangeRefused(\mysqli $db, int $actorId, int $siteId, string $capability): string
    {
        if ($capability !== Capabilities::WEBSITE_SETUP) {
            return '';
        }

        return self::mayHandOut($db, $actorId, $siteId) === true ? '' : self::HAND_OUT_REFUSED;
    }

    /** Record a refusal of D-093, naming the person by number only (#626). */
    public static function recordHandOutRefused(?int $actorId, int $siteId, string $where): void
    {
        Logger::activity(
            'PublicWebsiteSetupHandOutRefused',
            'Refused: a change to who may set up the public website for organisation #' . $siteId
            . ' by somebody who is not its global administrator (' . $where . '). Nothing was changed.',
            $actorId
        );
    }

    // =========================================================================
    // 2️⃣ Checking what somebody typed — pure, no database
    // =========================================================================

    /**
     * The main website (D-095), checked by `PortalAddress::check()` — the
     * portal's one address rule. http is accepted, with a warning.
     *
     * @return array{address:string, problem:string, warning:string}
     */
    public static function checkWebsiteAddress(string $typed): array
    {
        $checked = PortalAddress::check($typed);
        if ($checked['problem'] !== '') {
            return ['address' => '', 'problem' => $checked['problem'], 'warning' => ''];
        }
        $warning = str_starts_with($checked['address'], 'http://') === true
            ? 'That address begins http://, so visitors\' browsers will warn that it is not secure, and the set-up check '
                . 'and the list of websites allowed to frame your pages both need https://. Ask whoever looks after the '
                . 'website whether it can be reached at https:// instead.'
            : '';

        return ['address' => $checked['address'], 'problem' => '', 'warning' => $warning];
    }

    /**
     * One website allowed to frame the public pages (D-082). Only https; the
     * scheme, the host name and an optional port; nothing else.
     *
     * ACCEPTED: "https://www.example.org", the same with a port, a closing
     * "/", capitals, or the usual port 443 (all tidied quietly), and a bare
     * host name, taken to mean https.
     *
     * REFUSED, each with its reason: http or any other scheme; a "*" or any
     * other wildcard; a keyword such as 'self' or 'none'; a path, "?" or "#";
     * a user name; a name with no dot (localhost, or a keyword typed bare);
     * a number instead of a name — any host whose LAST part is digits, or 0x
     * and hex digits (`endsInANumber()`), which a browser reads as an address
     * number; anything `PortalAddress::check()` refuses.
     *
     * The answer never holds a space, a ";" or a line break: a header built
     * from it cannot be split (`frameAncestorsValue()` checks again).
     *
     * @return array{origin:string, problem:string}
     */
    public static function checkFrameOrigin(string $typed): array
    {
        $refuse = static fn (string $why): array => ['origin' => '', 'problem' => $why];
        $text   = trim($typed);
        if ($text === '') {
            return ['origin' => '', 'problem' => ''];
        }
        if (str_contains($text, '*') === true) {
            return $refuse('A "*" (a wildcard) is not allowed: list each website by its full name.');
        }
        if (str_contains($text, "'") === true || str_contains($text, '"') === true || str_contains($text, ';') === true) {
            return $refuse('Type only the website\'s address, such as https://www.example.org — no quotation marks, keywords or ";".');
        }
        if (preg_match('#^([A-Za-z][A-Za-z0-9+.\-]*):#', $text, $m) === 1
            && preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*://#', $text) === 1
            && strtolower($m[1]) !== 'https') {
            return $refuse('Only https:// websites can be allowed to frame your pages. Plain http:// is not secure.');
        }
        if (preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*:[^/]#', $text) === 1 && preg_match('#^[^:/]+:\d{1,5}$#', $text) !== 1) {
            return $refuse('That is not a website address. Type it like https://www.example.org');
        }
        $checked = PortalAddress::check($text);
        if ($checked['problem'] !== '') {
            return $refuse($checked['problem']);
        }
        if (str_starts_with($checked['address'], 'https://') === false) {
            return $refuse('Only https:// websites can be allowed to frame your pages. Plain http:// is not secure.');
        }
        $hostAndPort = substr($checked['address'], strlen('https://'));
        $host        = (string) preg_replace('/:\d+$/', '', $hostAndPort);
        if (str_contains($host, '.') === false) {
            return $refuse('"' . $host . '" is not a website\'s full name. Type the whole name, such as https://www.example.org');
        }
        if (self::endsInANumber($host) === true) {
            return $refuse('Type the website\'s name, not a number, such as https://www.example.org');
        }

        return ['origin' => $checked['address'], 'problem' => ''];
    }

    /**
     * The whole list, one website per line. ONE bad line refuses the WHOLE
     * list, naming the line; duplicates (after tidying) are collapsed; more
     * than `MAX_FRAME_ORIGINS` is refused. An empty list is allowed: it means
     * "no website may frame our pages".
     *
     * @return array{origins:list<string>, problem:string}
     */
    public static function checkFrameOrigins(string $typed): array
    {
        $origins = [];
        $lines   = preg_split('/\R/', $typed) ?: [];
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $one = self::checkFrameOrigin($line);
            if ($one['problem'] !== '') {
                return ['origins' => [], 'problem' => 'Line ' . ($index + 1) . ' ("' . mb_substr(trim($line), 0, 80) . '"): '
                    . $one['problem'] . ' Nothing was saved.'];
            }
            if (in_array($one['origin'], $origins, true) === false) {
                $origins[] = $one['origin'];
            }
        }
        if (count($origins) > self::MAX_FRAME_ORIGINS) {
            return ['origins' => [], 'problem' => 'At most ' . self::MAX_FRAME_ORIGINS . ' websites can be listed, and this list has '
                . count($origins) . '. Nothing was saved.'];
        }

        return ['origins' => $origins, 'problem' => ''];
    }

    /**
     * What goes after `frame-ancestors` in the public door's
     * Content-Security-Policy (D-082), from a list of websites. Pure.
     *
     * An empty list gives `'none'` — no website may frame the pages. Each
     * entry is checked AGAIN and dropped if it is not one `checkFrameOrigin()`
     * accepts exactly as written, so this can never put `*`, `;`, a quote or
     * a line break into a header, whoever called it with whatever.
     *
     * @param list<string> $origins
     */
    public static function frameAncestorsValue(array $origins): string
    {
        $keep = [];
        foreach ($origins as $origin) {
            if (is_string($origin) === false) {
                continue;
            }
            $again = self::checkFrameOrigin($origin);
            if ($again['problem'] !== '' || $again['origin'] !== $origin) {
                continue;
            }
            if (preg_match('#^https://[a-z0-9.\-]+(?::\d{1,5})?$#', $origin) !== 1) {
                continue;
            }
            if (in_array($origin, $keep, true) === false) {
                $keep[] = $origin;
            }
            if (count($keep) >= self::MAX_FRAME_ORIGINS) {
                break;
            }
        }

        return $keep === [] ? "'none'" : implode(' ', $keep);
    }

    /**
     * The optional public address for the portal's public pages (D-095): a
     * host NAME and nothing else. Capitals are tidied quietly (a name is the
     * same name in capitals); everything else is refused with its reason,
     * including a number instead of a name (`endsInANumber()`).
     *
     * @return array{host:string, problem:string}
     */
    public static function checkPublicHost(string $typed): array
    {
        $refuse = static fn (string $why): array => ['host' => '', 'problem' => $why];
        $text   = trim($typed);
        if ($text === '') {
            return ['host' => '', 'problem' => ''];
        }
        if (preg_match('/[\x00-\x20\x7F]/', $text) === 1) {
            return $refuse('The address has a space or a line break in it. Type only the name, such as events.example.org');
        }
        if (str_contains($text, '://') === true) {
            return $refuse('Type only the name, such as events.example.org — without https:// in front.');
        }
        if (str_contains($text, '@') === true) {
            return $refuse('The address must not hold a user name (anything before an @ sign).');
        }
        if (strpbrk($text, '/?#') !== false) {
            return $refuse('Type only the name, with no folder, page or question mark after it, such as events.example.org');
        }
        if (str_contains($text, ':') === true) {
            return $refuse('Type only the name, with no port number after it. The public pages answer on the usual https port.');
        }
        if (preg_match('/[^\x00-\x7F]/', $text) === 1) {
            return $refuse('A name with letters outside a to z has to be typed in its "xn--" form, which your domain '
                . 'registrar can show you.');
        }
        $host = rtrim(strtolower($text), '.');
        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
        if ($host === '' || strlen($host) > 253 || preg_match('/^' . $label . '(?:\.' . $label . ')*$/', $host) !== 1) {
            return $refuse('That is not a name the portal can use. Use letters a to z, digits, hyphens and dots, such as events.example.org');
        }
        if (str_contains($host, '.') === false) {
            return $refuse('"' . $host . '" is not a full name. Type the whole name, such as events.example.org');
        }
        if (self::endsInANumber($host) === true) {
            return $refuse('Type a name, not a number, such as events.example.org');
        }

        return ['host' => $host, 'problem' => ''];
    }

    // =========================================================================
    // 3️⃣ Reading what is stored — checked again every time
    // =========================================================================

    /**
     * The organisation's main website, or '' when none is stored or what is
     * stored would be refused (a hand edit). Never throws.
     */
    public static function websiteAddressFor(\mysqli $db, int $siteId): string
    {
        try {
            $stored = self::organisationRow($db, $siteId, self::KEY_WEBSITE, false);
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] PublicWebsiteSetup: the main website could not be read: ' . $e->getMessage());

            return '';
        }
        if ($stored === null || trim($stored) === '') {
            return '';
        }
        $checked = self::checkWebsiteAddress($stored);
        if ($checked['problem'] !== '') {
            error_log('[WebMS-Intra] PublicWebsiteSetup: organisation #' . $siteId . '\'s stored main website is not one '
                . 'this page would accept, so it is ignored.');

            return '';
        }
        if ($checked['address'] !== $stored) {
            // Only tidying differs (capitals, a closing "/", the usual port):
            // the same address, so it is used in its tidied form.
            error_log('[WebMS-Intra] PublicWebsiteSetup: organisation #' . $siteId . '\'s stored main website was not in its '
                . 'tidied form; the tidied form is used.');

            return $checked['address'];
        }

        return $stored;
    }

    /**
     * Does the installation allow framing at all (D-094)? 'yes' is the
     * default. A missing row is the default; any value other than exactly
     * 'yes' or 'no', or a database that cannot be asked, counts as 'no' —
     * the stricter answer wins. Never throws.
     */
    public static function framingPermitted(\mysqli $db): bool
    {
        try {
            $stmt = $db->prepare('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL LIMIT 1');
            $key  = self::KEY_FRAME_PERMITTED;
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] PublicWebsiteSetup: whether framing is permitted could not be read, so it is not: ' . $e->getMessage());

            return false;
        }
        if ($row === null) {
            return true;
        }

        return (string) ($row['settingValue'] ?? '') === 'yes';
    }

    /**
     * The websites this organisation lists as allowed to frame its pages, as
     * STORED and checked again — whatever the installation's switch says.
     * Only the organisation's own row is read: an installation-wide row is
     * ignored. A line a hand edit stored that this page would refuse is
     * dropped and logged. Never throws.
     *
     * @return list<string>
     */
    public static function frameOriginsStored(\mysqli $db, int $siteId): array
    {
        try {
            $stored = self::organisationRow($db, $siteId, self::KEY_FRAME_ORIGINS, false);
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] PublicWebsiteSetup: the framing list could not be read, so none is used: ' . $e->getMessage());

            return [];
        }
        if ($stored === null) {
            return [];
        }
        $out     = [];
        $dropped = 0;
        foreach (preg_split('/\R/', $stored) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $one = self::checkFrameOrigin($line);
            if ($one['problem'] !== '' || $one['origin'] !== trim($line)) {
                $dropped++;
                continue;
            }
            if (in_array($one['origin'], $out, true) === false && count($out) < self::MAX_FRAME_ORIGINS) {
                $out[] = $one['origin'];
            }
        }
        if ($dropped > 0) {
            error_log('[WebMS-Intra] PublicWebsiteSetup: organisation #' . $siteId . '\'s stored framing list held '
                . $dropped . ' line(s) this page would refuse; they are ignored.');
        }

        return $out;
    }

    /**
     * The websites allowed to frame this organisation's pages NOW: none at
     * all while the installation forbids framing (D-094), else the stored
     * list. This is what package P4's door will use.
     *
     * @return list<string>
     */
    public static function frameOriginsInForce(\mysqli $db, int $siteId): array
    {
        if (self::framingPermitted($db) === false) {
            return [];
        }

        return self::frameOriginsStored($db, $siteId);
    }

    /** The `frame-ancestors` value in force for this organisation (P4 sends it). */
    public static function frameAncestorsFor(\mysqli $db, int $siteId): string
    {
        return self::frameAncestorsValue(self::frameOriginsInForce($db, $siteId));
    }

    /**
     * The organisation's optional public address, or '' when it has none or
     * what is stored would be refused. Never throws: '' when the table is
     * missing (before migration 262).
     */
    public static function publicHostFor(\mysqli $db, int $siteId): string
    {
        try {
            $stmt = $db->prepare('SELECT `hostName` FROM `' . self::HOSTS_TABLE . '` WHERE `siteID` = ? LIMIT 1');
            $stmt->bind_param('i', $siteId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (\Throwable $e) {
            return '';
        }
        if ($row === null) {
            return '';
        }
        $stored  = (string) $row['hostName'];
        $checked = self::checkPublicHost($stored);
        if ($checked['problem'] !== '' || $checked['host'] !== $stored) {
            error_log('[WebMS-Intra] PublicWebsiteSetup: organisation #' . $siteId . '\'s stored public address is not one '
                . 'this page would accept, so it is ignored.');

            return '';
        }

        return $stored;
    }

    /**
     * Is this organisation's public address APPROVED by the installation's
     * global administrator (D-101)? Only an approved address is the public
     * door, and only an approved one is used in the door's own addresses.
     * False when there is no address, it is not approved, the column is not
     * there yet (before migration 263) or the database cannot be asked.
     * Never throws.
     */
    public static function publicHostApproved(\mysqli $db, int $siteId): bool
    {
        try {
            $stmt = $db->prepare('SELECT `approvedAtUtc` FROM `' . self::HOSTS_TABLE . '` WHERE `siteID` = ? LIMIT 1');
            $stmt->bind_param('i', $siteId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (\Throwable $e) {
            return false;
        }

        return $row !== null && $row['approvedAtUtc'] !== null && self::publicHostFor($db, $siteId) !== '';
    }

    /**
     * 🚪 The organisation's public address IN EFFECT — the one the public door
     * really answers on — or ''. THE ONE QUESTION every place asks before it
     * names a public address as the door's (round 7 of P4a's check: the card,
     * `PublicDoor::addressFor()`, `whyNoAddress()`, the replace-name words and
     * the handler's refusal asked three different questions and disagreed):
     *   - an address is stored and is one this page would accept;
     *   - the installation's global administrator has APPROVED it (D-101);
     *   - it is NOT an address members sign in at (the portal's own address,
     *     or an organisation's own) — `publicHostRow()` refuses such an
     *     address at request time, so the door never answers there even when
     *     approved, and nothing may claim it does.
     * Never throws: '' when anything cannot be read. Built on
     * `publicHostStatus()`, so the two can never disagree.
     */
    public static function publicHostInEffect(\mysqli $db, int $siteId): string
    {
        return self::publicHostStatus($db, $siteId) === self::HOST_APPROVED ? self::publicHostFor($db, $siteId) : '';
    }

    /** `publicHostStatus()`'s answers. */
    public const HOST_NONE = 'none';
    public const HOST_WAITING = 'waiting';
    public const HOST_APPROVED = 'approved';
    public const HOST_MEMBERS = 'members';
    public const HOST_UNCHECKED = 'unchecked';

    /**
     * Where the organisation's public address stands — the ONE answer the
     * card, the set-up check and `publicHostInEffect()` give words to (round
     * 8 of P4a's check: an approved address whose members' check could not be
     * READ was reported as an address members sign in at, the wrong reason):
     *   HOST_NONE       no address (or a stored one this page would refuse);
     *   HOST_WAITING    not approved by the installation (D-101), or whether
     *                   it is approved could not be read — either way, not
     *                   the door;
     *   HOST_APPROVED   approved, and not an address members sign in at:
     *                   the door answers on it;
     *   HOST_MEMBERS    approved, but it IS an address members sign in at,
     *                   which `publicHostRow()` refuses at request time;
     *   HOST_UNCHECKED  approved, but the members' addresses could not be
     *                   read, so it is NOT counted as the door's address.
     * Never throws.
     */
    public static function publicHostStatus(\mysqli $db, int $siteId): string
    {
        $host = self::publicHostFor($db, $siteId);
        if ($host === '') {
            return self::HOST_NONE;
        }
        if (self::publicHostApproved($db, $siteId) === false) {
            return self::HOST_WAITING;
        }
        try {
            $members = self::hostsMembersUse($db, '');
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] PublicWebsiteSetup: the addresses members sign in at could not be read: ' . $e->getMessage());

            return self::HOST_UNCHECKED;
        }

        return in_array($host, $members, true) === true ? self::HOST_MEMBERS : self::HOST_APPROVED;
    }

    /**
     * Every organisation's public address, for the installation's global
     * administrator's approval list (D-101): organisation, its name, the
     * address as stored, and whether it is approved. Never throws: NULL when
     * it cannot be read — never [], because an empty list reads as "no
     * organisation has entered one", untrue exactly when the installation's
     * administrator needs the list (round 7 of P4a's check).
     *
     * Each row also says whether the address is one members sign in at
     * (`membersAddress`: true, false, or null when that could not be read),
     * so the list can say an approved address is "not in use".
     *
     * @return list<array{siteId:int, siteName:string, host:string, approved:bool, membersAddress:?bool}>|null
     */
    public static function allPublicHosts(\mysqli $db): ?array
    {
        try {
            $rs = $db->query(
                'SELECT h.`siteID`, s.`siteName`, h.`hostName`, h.`approvedAtUtc` FROM `' . self::HOSTS_TABLE . '` h '
                . 'INNER JOIN `tblSites` s ON s.`siteID` = h.`siteID` ORDER BY (h.`approvedAtUtc` IS NOT NULL), s.`siteName`, h.`siteID`'
            );
            $out = [];
            try {
                $members = self::hostsMembersUse($db, '');
            } catch (\Throwable $e) {
                $members = null;
            }
            while (is_array($row = $rs->fetch_assoc()) === true) {
                $out[] = ['siteId' => (int) $row['siteID'], 'siteName' => (string) $row['siteName'],
                    'host' => (string) $row['hostName'], 'approved' => $row['approvedAtUtc'] !== null,
                    'membersAddress' => $members === null ? null : in_array((string) $row['hostName'], $members, true)];
            }

            return $out;
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] PublicWebsiteSetup: the public addresses could not be listed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * ✅ APPROVE an organisation's public address (D-101): the installation's
     * global administrator ONLY, because the installation and an organisation
     * may be different parties. THIS is the one refusal: the page's handler
     * passes `App::isRootAdmin()` and asks nothing itself, and the page draws
     * the list only for a global administrator (round 7 of P4a's check: an
     * earlier comment said it was asked in both places). The address approved
     * must be the one still stored, exactly as stored (`$host`), so an address
     * changed while the page was open is never approved by accident, and what
     * is recorded and shown is the STORED spelling. Refused while the portal's
     * own address is not set, as saving is: then nobody can tell whether the
     * address is one members sign in at. Recorded, and so is every refusal.
     *
     * @return array{saved:bool, type:string, message:string}
     */
    public static function approvePublicHost(\mysqli $db, int $siteId, string $host, bool $isRootAdmin, ?int $actorId): array
    {
        if ($isRootAdmin === false) {
            return self::refusedAndRecorded('public address approval', $siteId, 'Only a global administrator of the whole '
                . 'installation can approve a public address. Nothing was changed.', $actorId);
        }
        if (PortalAddress::visitorCanChooseHost() === true) {
            return self::refusedAndRecorded('public address approval', $siteId, 'The portal\'s own address is not set, so it '
                . 'cannot tell whether this is an address members sign in at. Set it on Admin, Settings, Organisation first. '
                . 'Nothing was changed.', $actorId);
        }
        // The posted spelling must BE the stored one: MySQL compares names
        // without regard to capitals or trailing spaces, so the UPDATE alone
        // would accept " EVENTS.example.org " and record that spelling.
        $stored = self::publicHostFor($db, $siteId);
        if ($stored === '' || $stored !== $host) {
            return self::refusedAndRecorded('public address approval', $siteId, 'That address is no longer the one waiting '
                . 'for approval (it was changed, removed or approved already). Nothing was changed; look at the page again.', $actorId);
        }
        try {
            $stmt = $db->prepare('UPDATE `' . self::HOSTS_TABLE . '` SET `approvedAtUtc` = UTC_TIMESTAMP(), `approvedByID` = ? '
                . 'WHERE `siteID` = ? AND `hostName` = ? AND `approvedAtUtc` IS NULL');
            $stmt->bind_param('iis', $actorId, $siteId, $stored);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] PublicWebsiteSetup: a public address could not be approved: ' . $e->getMessage());

            return self::refusedAndRecorded('public address approval', $siteId, 'The database refused the change. Nothing was changed.', $actorId);
        }
        if ($changed < 1) {
            return self::refusedAndRecorded('public address approval', $siteId, 'That address is no longer the one waiting '
                . 'for approval (it was changed, removed or approved already). Nothing was changed; look at the page again.', $actorId);
        }
        Logger::activity('PublicWebsitePublicAddressApproved', 'Organisation #' . $siteId . '\'s public address ' . $stored
            . ' was approved by the installation\'s global administrator. It answers as the public door from now on.', $actorId);

        return ['saved' => true, 'type' => 'success', 'message' => 'Approved: ' . $stored . ' now answers as that organisation\'s '
            . 'public door, while its door is on.'];
    }

    /**
     * Withdraw approval of an organisation's public address (D-101): the
     * installation's global administrator only — THIS is the one refusal, as
     * for approving. Takes effect on the very next request: the address stops
     * being the public door at once. Recorded, and so is every refusal.
     *
     * @return array{saved:bool, type:string, message:string}
     */
    public static function withdrawPublicHostApproval(\mysqli $db, int $siteId, bool $isRootAdmin, ?int $actorId): array
    {
        if ($isRootAdmin === false) {
            return self::refusedAndRecorded('public address approval', $siteId, 'Only a global administrator of the whole '
                . 'installation can withdraw approval of a public address. Nothing was changed.', $actorId);
        }
        $host = self::publicHostFor($db, $siteId);
        try {
            $stmt = $db->prepare('UPDATE `' . self::HOSTS_TABLE . '` SET `approvedAtUtc` = NULL, `approvedByID` = NULL '
                . 'WHERE `siteID` = ? AND `approvedAtUtc` IS NOT NULL');
            $stmt->bind_param('i', $siteId);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] PublicWebsiteSetup: approval of a public address could not be withdrawn: ' . $e->getMessage());

            return self::refusedAndRecorded('public address approval', $siteId, 'The database refused the change. Nothing was changed.', $actorId);
        }
        if ($changed < 1) {
            return ['saved' => true, 'type' => 'info', 'message' => 'That address was not approved. Nothing was changed.'];
        }
        Logger::activity('PublicWebsitePublicAddressApprovalWithdrawn', 'Approval of organisation #' . $siteId . '\'s public '
            . 'address ' . ($host === '' ? '(unreadable)' : $host) . ' was withdrawn by the installation\'s global administrator. '
            . 'It is not the public door any more.', $actorId);

        return ['saved' => true, 'type' => 'success', 'message' => 'Approval withdrawn: that address is not the public door any more.'];
    }

    // =========================================================================
    // 3b. The public door's name, and the test-copy setting (#493 P4a)
    // =========================================================================

    /**
     * Is this text shaped like a public door name: exactly 24 lower-case hex
     * characters? Every name the portal makes is (`bin2hex(random_bytes(12))`).
     * Capitals are NOT accepted: the column's collation ignores capitals, so
     * accepting them would give one name 2^24 spellings, and a door address
     * is only ever copied, never typed.
     */
    public static function isDoorName(string $text): bool
    {
        return preg_match('/^[0-9a-f]{24}$/D', $text) === 1;
    }

    /**
     * Which ACTIVE organisation holds this public door name, or null when none
     * does (an unknown, replaced or removed name, a name of a switched-off
     * organisation, or text that is not a name at all). Asked by
     * `Site::preDetect()` while the portal starts up, before any session is
     * looked at.
     *
     * ⚠️ IT THROWS on a database fault, on purpose: start-up must tell "no such
     *    name" (a 404) apart from "could not ask" (also a 404 for the door, but
     *    logged). The one exception is error 1146, the table not existing yet
     *    (files reach a server before anybody presses "Run migrations"): then
     *    no organisation can have a door, so null is the true answer.
     */
    public static function siteForDoorName(\mysqli $db, string $name): ?int
    {
        if (self::isDoorName($name) === false) {
            return null;
        }
        try {
            $stmt = $db->prepare(
                'SELECT n.`siteID` FROM `' . self::DOOR_TABLE . '` n INNER JOIN `tblSites` s ON s.`siteID` = n.`siteID` '
                . 'WHERE n.`publicName` = ? AND s.`isActive` = 1 LIMIT 1'
            );
        } catch (\mysqli_sql_exception $e) {
            if ($e->getCode() === 1146) {
                return null;
            }
            throw $e;
        }
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row === null ? null : (int) $row['siteID'];
    }

    /**
     * The stored public-address row for a host name, for `Site::preDetect()`:
     * null when no organisation holds it, else the organisation, whether that
     * organisation is active, whether the installation's global administrator
     * has APPROVED it (D-101: an unapproved address is not the door at all),
     * and whether the stored row is USABLE — exactly
     * what `checkPublicHost()` would accept, and not an address members sign
     * in at (a hand edit could put either there; a members' address must never
     * be turned into a door, or every member would be locked out).
     *
     * Throws on a database fault, except 1146 (no table yet: nothing can hold
     * a public address, so null is the true answer). See siteForDoorName().
     *
     * @return array{siteId:int, active:bool, usable:bool, approved:bool}|null
     */
    public static function publicHostRow(\mysqli $db, string $host): ?array
    {
        if ($host === '') {
            return null;
        }
        try {
            $stmt = $db->prepare(
                'SELECT h.`siteID`, h.`hostName`, h.`approvedAtUtc`, s.`isActive` FROM `' . self::HOSTS_TABLE . '` h '
                . 'INNER JOIN `tblSites` s ON s.`siteID` = h.`siteID` WHERE h.`hostName` = ? LIMIT 1'
            );
        } catch (\mysqli_sql_exception $e) {
            // 1146: no table yet; 1054 BECAUSE migration 263's approval
            // column is not there yet. Either way no address can have been
            // APPROVED (D-101), so "no public address" is the true answer.
            // Any OTHER unknown column is a real fault and is thrown, so it
            // fails closed (P4a's own test plants one).
            if ($e->getCode() === 1146) {
                return null;
            }
            if ($e->getCode() === 1054) {
                $col = $db->query("SHOW COLUMNS FROM `" . self::HOSTS_TABLE . "` LIKE 'approvedAtUtc'");
                if ($col !== false && $col->num_rows === 0) {
                    return null;
                }
            }
            throw $e;
        }
        $stmt->bind_param('s', $host);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return null;
        }
        $stored  = (string) $row['hostName'];
        $checked = self::checkPublicHost($stored);
        $usable  = $checked['problem'] === '' && $checked['host'] === $stored && $stored === $host;
        if ($usable === true && in_array($stored, self::hostsMembersUse($db, ''), true) === true) {
            error_log('[WebMS-Intra] PublicWebsiteSetup: organisation #' . (int) $row['siteID'] . '\'s stored public address '
                . 'is an address members sign in at, so it is NOT treated as a public address.');
            $usable = false;
        }

        return ['siteId' => (int) $row['siteID'], 'active' => (int) $row['isActive'] === 1, 'usable' => $usable,
            'approved' => $row['approvedAtUtc'] !== null];
    }

    /**
     * Is this organisation's public door on, and what is its name? Never
     * throws.
     *   'on'         a row holding a name the portal could have made;
     *   'off'        no row (D-097: how every organisation starts);
     *   'spoiled'    a row whose name is not one the portal makes (a hand
     *                edit). The door does NOT answer: a public address needs
     *                the door on, and no request can carry that name;
     *   'unreadable' the database could not be asked. The door does not
     *                answer, and the card says it could not be read.
     *
     * @return array{state:string, name:string}
     */
    public static function doorStateFor(\mysqli $db, int $siteId): array
    {
        try {
            $stmt = $db->prepare('SELECT `publicName` FROM `' . self::DOOR_TABLE . '` WHERE `siteID` = ? LIMIT 1');
            $stmt->bind_param('i', $siteId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] PublicWebsiteSetup: whether the public door is on could not be read: ' . $e->getMessage());

            return ['state' => 'unreadable', 'name' => ''];
        }
        if ($row === null) {
            return ['state' => 'off', 'name' => ''];
        }
        $name = (string) $row['publicName'];
        if (self::isDoorName($name) === false) {
            error_log('[WebMS-Intra] PublicWebsiteSetup: organisation #' . $siteId . '\'s stored public door name is not one '
                . 'the portal makes, so the door does not answer.');

            return ['state' => 'spoiled', 'name' => ''];
        }

        return ['state' => 'on', 'name' => $name];
    }

    /**
     * 🚪 Turn the organisation's public door on (D-097): give it a new random
     * name. Already on → nothing changes, and it says so. Recorded.
     *
     * @return array{saved:bool, type:string, message:string}
     */
    public static function turnDoorOn(\mysqli $db, int $siteId, ?int $actorId): array
    {
        $now = self::doorStateFor($db, $siteId);
        if ($now['state'] === 'on') {
            return ['saved' => true, 'type' => 'info', 'message' => 'The public door is already on. Nothing was changed.'];
        }
        if ($now['state'] === 'spoiled') {
            return self::refusedAndRecorded('public door', $siteId, 'The stored public door name is not one the portal makes, '
                . 'so it cannot simply be switched on. Use "Replace the name" to give it a new one. Nothing was changed.', $actorId);
        }
        if ($now['state'] === 'unreadable') {
            return self::refusedAndRecorded('public door', $siteId, 'Whether the public door is on could not be read. Nothing was changed.', $actorId);
        }
        $name = bin2hex(random_bytes(12));
        try {
            $stmt = $db->prepare('INSERT INTO `' . self::DOOR_TABLE . '` (`siteID`, `publicName`, `changedAtUtc`) VALUES (?, ?, UTC_TIMESTAMP())');
            $stmt->bind_param('is', $siteId, $name);
            $stmt->execute();
            $stmt->close();
        } catch (\mysqli_sql_exception $e) {
            if ($e->getCode() === 1062 && self::doorStateFor($db, $siteId)['state'] === 'on') {
                // Turned on at the same moment, probably from another tab.
                return ['saved' => true, 'type' => 'info', 'message' => 'The public door is already on (it was turned on at the same '
                    . 'moment, probably from another tab). Nothing was changed.'];
            }
            error_log('[WebMS-Intra] PublicWebsiteSetup: the public door could not be turned on: ' . $e->getMessage());

            return self::refusedAndRecorded('public door', $siteId, 'The database refused the change. Nothing was changed.', $actorId);
        }
        Logger::activity('PublicDoorTurnedOn', 'Organisation #' . $siteId . '\'s public door was turned on, with the public name '
            . $name . '.', $actorId);

        return ['saved' => true, 'type' => 'success', 'message' => 'The public door is on. Your website can now read the events and '
            . 'posters you have put on the public website, at the addresses shown below.'];
    }

    /**
     * Give the organisation's public door a NEW name. Every copy that uses
     * the portal's own address with the old name (/w/{old name}/...) stops at
     * once, and the page warns so before the button is pressed. Recorded with
     * both names.
     *
     * ⚠️ IT DOES NOT TOUCH THE ORGANISATION'S PUBLIC ADDRESS (D-095). Copies
     *    that use the public address (https://events.example.org/events.json)
     *    carry no name, so they go on working; to stop those, the door is
     *    switched off or the public address is changed or removed. Round 1 of
     *    P4a's check measured the first version promising "every copy stops"
     *    while the public address went on answering, and the message, the
     *    card and the record all say so now.
     *
     * @return array{saved:bool, type:string, message:string}
     */
    public static function replaceDoorName(\mysqli $db, int $siteId, ?int $actorId): array
    {
        $now = self::doorStateFor($db, $siteId);
        if ($now['state'] === 'off') {
            return self::refusedAndRecorded('public door', $siteId, 'The public door is off, so there is no name to replace. '
                . 'Nothing was changed.', $actorId);
        }
        if ($now['state'] === 'unreadable') {
            return self::refusedAndRecorded('public door', $siteId, 'Whether the public door is on could not be read. Nothing was changed.', $actorId);
        }
        $name = bin2hex(random_bytes(12));
        try {
            $stmt = $db->prepare('UPDATE `' . self::DOOR_TABLE . '` SET `publicName` = ?, `changedAtUtc` = UTC_TIMESTAMP() WHERE `siteID` = ?');
            $stmt->bind_param('si', $name, $siteId);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] PublicWebsiteSetup: the public door name could not be replaced: ' . $e->getMessage());

            return self::refusedAndRecorded('public door', $siteId, 'The database refused the change. Nothing was changed.', $actorId);
        }
        if ($changed < 1) {
            // ⚠️ The row vanished between the read and the write (switched off
            //    at the same moment): say so rather than report a new name.
            return self::refusedAndRecorded('public door', $siteId, 'Somebody switched the public door off at the same moment. '
                . 'Nothing was changed; look at the page again.', $actorId);
        }
        $publicHost = self::publicHostInEffect($db, $siteId);
        Logger::activity('PublicDoorNameReplaced', 'Organisation #' . $siteId . '\'s public door name was replaced: '
            . ($now['name'] === '' ? '(a name the portal would not make)' : $now['name']) . ' → ' . $name
            . '. Every copy using the old name on the portal\'s own address stopped working'
            . ($publicHost === '' ? '.' : '; copies using the public address ' . $publicHost . ' are NOT affected.'), $actorId);

        if ($publicHost !== '') {
            return ['saved' => true, 'type' => 'warning', 'message' => 'The public door has a new name. Copies that use the '
                . 'portal\'s own address with the old name have stopped working. Your public address, ' . $publicHost
                . ', is NOT affected by the name: copies that use it go on working. To stop those, switch the public door '
                . 'off, or change or remove the public address.'];
        }

        return ['saved' => true, 'type' => 'warning', 'message' => 'The public door has a new name. Every copy pasted into a '
            . 'website with the old name has stopped working; paste the new addresses shown below wherever you use them.'];
    }

    /**
     * Switch the organisation's public door off: delete its name. Every copy
     * pasted into another website stops at once, and so does its public
     * address. Already off → nothing changes, and it says so. Recorded.
     *
     * @return array{saved:bool, type:string, message:string}
     */
    public static function turnDoorOff(\mysqli $db, int $siteId, ?int $actorId): array
    {
        $was = self::doorStateFor($db, $siteId);
        if ($was['state'] === 'unreadable') {
            return self::refusedAndRecorded('public door', $siteId, 'Whether the public door is on could not be read. Nothing was changed.', $actorId);
        }
        try {
            $stmt = $db->prepare('DELETE FROM `' . self::DOOR_TABLE . '` WHERE `siteID` = ?');
            $stmt->bind_param('i', $siteId);
            $stmt->execute();
            $gone = $stmt->affected_rows;
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] PublicWebsiteSetup: the public door could not be switched off: ' . $e->getMessage());

            return self::refusedAndRecorded('public door', $siteId, 'The database refused the change. Nothing was changed.', $actorId);
        }
        if ($gone < 1) {
            return ['saved' => true, 'type' => 'info', 'message' => 'The public door is already off. Nothing was changed.'];
        }
        Logger::activity('PublicDoorTurnedOff', 'Organisation #' . $siteId . '\'s public door was switched off (its name was '
            . ($was['name'] === '' ? 'not one the portal makes' : $was['name']) . '). Nothing reaches any outside website now.', $actorId);

        return ['saved' => true, 'type' => 'success', 'message' => 'The public door is off. Nothing the portal holds reaches any '
            . 'outside website now, and every copy pasted into one has stopped working.'];
    }

    /**
     * D-087, THE READER: on a test copy of the portal (the alpha, beta or dev
     * channel), is this organisation's public door open to ANYBODY, rather
     * than to signed-in testers only? The stricter of two answers wins:
     *   - the installation's limit, `public.door.testCopies.permitted`
     *     (installation row only): 'either' lets an organisation choose,
     *     'testers' (seeded) does not;
     *   - the organisation's own choice, `public.door.testCopies.open` (its
     *     own row only — an installation-wide row of it is ignored, so one
     *     organisation's choice never becomes everybody's): 'anybody' or
     *     'testers'; no row means 'testers'.
     * True only when the installation says exactly 'either' AND the
     * organisation says exactly 'anybody'. Anything else, any spelling nobody
     * saved, and a database that cannot be asked, is 'testers' — the stricter.
     * Never throws.
     *
     * The two settings are written ONLY by `saveTestCopyLimit()` and
     * `saveTestCopyChoice()`, from the card on Admin → Public website (#493
     * P4d). The general settings editor refuses to add, change or delete
     * either (`PublicWebsite::LOCKED_KEYS`, since round 1 of P4a's check).
     * Nothing asks this on the live portal: `testCopyDoorRule()` answers
     * DOOR_TEST_LIVE there before it is reached.
     */
    public static function testCopyDoorOpenToAnybody(\mysqli $db, int $siteId): bool
    {
        try {
            $stmt = $db->prepare('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL LIMIT 1');
            $key  = self::KEY_DOOR_TEST_PERMITTED;
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $limit = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($limit === null || (string) ($limit['settingValue'] ?? '') !== 'either') {
                return false;
            }
            $own = self::organisationRow($db, $siteId, self::KEY_DOOR_TEST_OPEN, false);
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] PublicWebsiteSetup: the test-copy door setting could not be read, so the door is for testers only: '
                . $e->getMessage());

            return false;
        }

        return $own === 'anybody';
    }

    /** `testCopyDoorRule()`'s answers. */
    public const DOOR_TEST_LIVE    = 'live';
    public const DOOR_TEST_UNGATED = 'ungated';
    public const DOOR_TEST_TESTERS = 'testers';
    public const DOOR_TEST_ANYBODY = 'anybody';

    /**
     * 🧪 D-087 IN ONE PLACE (#493 P4d): what a test copy does to this
     * organisation's public door RIGHT NOW. The door, the card on Admin →
     * Public website and the set-up check all ask this, so the three can
     * never disagree.
     *
     *   DOOR_TEST_LIVE     not a test copy: `PORTAL_ENV` is not one of
     *                      `Channel::PRE_RELEASE` — the ONE list of test
     *                      channels. D-087 does not apply at all.
     *   DOOR_TEST_UNGATED  a test copy whose staff sign-in gate is switched
     *                      off (`Gatekeeper::shouldEnforce()` says no —
     *                      `portal.gatekeeper.enabled` is false). Then every
     *                      page of the test copy is open to anybody, and so is
     *                      the door, whatever is chosen here.
     *   DOOR_TEST_TESTERS  the door answers only somebody the test-copy gate
     *                      lets in (`Gatekeeper::mayUseTestCopy()`).
     *   DOOR_TEST_ANYBODY  the installation permits it AND the organisation
     *                      chose it (`testCopyDoorOpenToAnybody()`).
     * On every answer but LIVE, the door tells search engines not to list
     * anything (`noindex, nofollow, noarchive`).
     *
     * The channel is asked FIRST, so `Gatekeeper::shouldEnforce()` is never
     * asked on the live portal: there it writes a line to the error log
     * whenever the channel was only assumed, and on a door request (which
     * anybody can send) that is a line per anonymous request. Before #493 P4d
     * the door asked it first and wrote that line; the members' pages still
     * do, from index.php. Never throws.
     */
    public static function testCopyDoorRule(\mysqli $db, int $siteId): string
    {
        // PORTAL_ENV is defined by start-up on every request; only a test
        // that loads this class on its own can lack it, and is then live.
        $channel = defined('PORTAL_ENV') === true ? (string) PORTAL_ENV : '';
        if (in_array($channel, Channel::PRE_RELEASE, true) === false) {
            return self::DOOR_TEST_LIVE;
        }
        $source = defined('PORTAL_ENV_SOURCE') === true ? (string) PORTAL_ENV_SOURCE : '';
        if (Gatekeeper::shouldEnforce($channel, $source) === false) {
            return self::DOOR_TEST_UNGATED;
        }

        return self::testCopyDoorOpenToAnybody($db, $siteId) === true ? self::DOOR_TEST_ANYBODY : self::DOOR_TEST_TESTERS;
    }

    /**
     * The installation's limit as the card shows it: 'either' (an organisation
     * may choose "open to anybody"), 'testers', or null when it could not be
     * read. A missing row, or any value nobody saved, is 'testers' — what the
     * door does with it. Never throws.
     */
    public static function testCopyLimit(\mysqli $db): ?string
    {
        try {
            $stmt = $db->prepare('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL LIMIT 1');
            $key  = self::KEY_DOOR_TEST_PERMITTED;
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (\Throwable $e) {
            return null;
        }

        return $row !== null && (string) ($row['settingValue'] ?? '') === 'either' ? 'either' : 'testers';
    }

    /**
     * The organisation's OWN choice as the card shows it: 'anybody',
     * 'testers', '' when it has never chosen (which the door reads as
     * 'testers'), or null when it could not be read. Any value nobody saved
     * reads as 'testers'. Never throws.
     */
    public static function testCopyChoice(\mysqli $db, int $siteId): ?string
    {
        try {
            $own = self::organisationRow($db, $siteId, self::KEY_DOOR_TEST_OPEN, false);
        } catch (\Throwable $e) {
            return null;
        }
        if ($own === null) {
            return '';
        }

        return $own === 'anybody' ? 'anybody' : 'testers';
    }

    // =========================================================================
    // 4️⃣ Saving — every save and every refusal is recorded
    // =========================================================================

    /**
     * Save the organisation's main website. Empty DELETES its row.
     *
     * @return array{saved:bool, type:string, message:string}
     */
    public static function saveWebsiteAddress(\mysqli $db, int $siteId, string $typed, ?int $actorId): array
    {
        $checked = self::checkWebsiteAddress($typed);
        if ($checked['problem'] !== '') {
            return self::refusedAndRecorded('main website', $siteId, $checked['problem'] . ' Nothing was saved.', $actorId);
        }
        try {
            $was = self::organisationRow($db, $siteId, self::KEY_WEBSITE, false);
            if ($checked['address'] === '') {
                self::deleteOrganisationRow($db, $siteId, self::KEY_WEBSITE);
            } else {
                self::upsertOrganisationRow($db, $siteId, self::KEY_WEBSITE, $checked['address']);
            }
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] PublicWebsiteSetup: the main website could not be saved: ' . $e->getMessage());

            return self::refusedAndRecorded('main website', $siteId, 'The database refused the change. Nothing was saved.', $actorId);
        }
        Logger::activity(
            'PublicWebsiteAddressSaved',
            'Organisation #' . $siteId . '\'s main website: ' . ($was === null || $was === '' ? '(none)' : $was)
            . ' → ' . ($checked['address'] === '' ? '(none)' : $checked['address']) . '.',
            $actorId
        );
        if ($checked['address'] === '') {
            return ['saved' => true, 'type' => 'success', 'message' => 'Saved: no main website is recorded now.'];
        }
        if ($checked['warning'] !== '') {
            return ['saved' => true, 'type' => 'warning', 'message' => 'Saved. ' . $checked['warning']];
        }

        return ['saved' => true, 'type' => 'success', 'message' => 'Saved.'];
    }

    /**
     * Save the list of websites allowed to frame the organisation's pages.
     * Empty DELETES the row (always allowed). A non-empty list is refused
     * while the installation forbids framing (D-094), with the reason. The
     * installation's switch is read and LOCKED inside the transaction that
     * writes (#647), so a switch-off at the same moment cannot be missed.
     *
     * @return array{saved:bool, type:string, message:string}
     */
    public static function saveFrameOrigins(\mysqli $db, int $siteId, string $typed, ?int $actorId): array
    {
        $checked = self::checkFrameOrigins($typed);
        if ($checked['problem'] !== '') {
            return self::refusedAndRecorded('framing list', $siteId, $checked['problem'], $actorId);
        }
        $value = implode("\n", $checked['origins']);
        try {
            $db->begin_transaction();
            $permitted = self::installationRowLocked($db, self::KEY_FRAME_PERMITTED);
            if ($value !== '' && $permitted !== null && $permitted !== 'yes') {
                $db->rollback();

                return self::refusedAndRecorded('framing list', $siteId, 'This installation does not allow any outside '
                    . 'website to show its organisations\' pages in a frame, so a list cannot be saved. You can still empty '
                    . 'it. Nothing was saved.', $actorId);
            }
            $was = self::organisationRow($db, $siteId, self::KEY_FRAME_ORIGINS, true);
            if ($value === '') {
                self::deleteOrganisationRow($db, $siteId, self::KEY_FRAME_ORIGINS);
            } else {
                self::upsertOrganisationRow($db, $siteId, self::KEY_FRAME_ORIGINS, $value);
            }
            $db->commit();
        } catch (\Throwable $e) {
            self::rollbackQuietly($db);
            error_log('[WebMS-Intra] PublicWebsiteSetup: the framing list could not be saved: ' . $e->getMessage());

            return self::refusedAndRecorded('framing list', $siteId, 'The database refused the change. Nothing was saved.', $actorId);
        }
        Logger::activity(
            'PublicWebsiteFramingSaved',
            'Organisation #' . $siteId . '\'s websites allowed to frame its public pages: '
            . ($was === null || $was === '' ? '(none)' : str_replace("\n", ', ', $was)) . ' → '
            . ($value === '' ? '(none)' : str_replace("\n", ', ', $value)) . '.',
            $actorId
        );

        return ['saved' => true, 'type' => 'success', 'message' => $value === ''
            ? 'Saved: no outside website may show your public pages in a frame.'
            : 'Saved: ' . count($checked['origins']) . ' website' . (count($checked['origins']) === 1 ? '' : 's') . ' may show your public pages in a frame.'];
    }

    /**
     * Save the installation's switch (D-094): 'yes' or 'no'. Only the
     * installation's global administrator — asked here as well as on the
     * page, because the installation and an organisation may be different
     * parties.
     *
     * @return array{saved:bool, type:string, message:string}
     */
    public static function saveFramingPermitted(\mysqli $db, string $value, bool $isRootAdmin, ?int $actorId): array
    {
        if ($isRootAdmin === false) {
            return self::refusedAndRecorded('installation\'s framing switch', 0, 'Only a global administrator of the whole '
                . 'installation can change whether framing is allowed. Nothing was saved.', $actorId);
        }
        if ($value !== 'yes' && $value !== 'no') {
            return self::refusedAndRecorded('installation\'s framing switch', 0, 'That choice was not recognised. Nothing was saved.', $actorId);
        }
        try {
            $stmt = $db->prepare(
                'INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) '
                . "VALUES (NULL, ?, ?, 'yes', 0) ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`)"
            );
            $key = self::KEY_FRAME_PERMITTED;
            $stmt->bind_param('ss', $key, $value);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] PublicWebsiteSetup: the framing switch could not be saved: ' . $e->getMessage());

            return self::refusedAndRecorded('installation\'s framing switch', 0, 'The database refused the change. Nothing was saved.', $actorId);
        }
        Logger::activity(
            'PublicWebsiteFramingSwitchSaved',
            'The installation now ' . ($value === 'yes' ? 'ALLOWS' : 'FORBIDS') . ' organisations\' public pages being framed by outside websites.',
            $actorId
        );

        return ['saved' => true, 'type' => 'success', 'message' => $value === 'yes'
            ? 'Saved: each organisation may list the websites allowed to show its public pages in a frame.'
            : 'Saved: no outside website may show any organisation\'s public pages in a frame, whatever their lists say.'];
    }

    /**
     * 🧪 Save the INSTALLATION's limit for test copies' public doors (D-087,
     * #493 P4d): 'testers' — every organisation's door on a test copy answers
     * signed-in testers only — or 'either' — each organisation's global
     * administrator may choose "open to anybody". Only the installation's
     * global administrator (asked here as well as on the page, because the
     * installation and an organisation may be different parties), and only on
     * a test copy: on the live portal the setting does nothing, and a Save
     * that does nothing is a lie (#671).
     *
     * Lowering it to 'testers' is ALWAYS allowed, and needs no clearing up:
     * the stricter wins when the door READS (`testCopyDoorOpenToAnybody()`),
     * so an organisation's stored "anybody" stops counting at once and is
     * kept, to count again only if the limit is raised. The message says how
     * many organisations that touches; the record carries only the count.
     *
     * @return array{saved:bool, type:string, message:string}
     */
    public static function saveTestCopyLimit(\mysqli $db, string $value, bool $isRootAdmin, ?int $actorId): array
    {
        $what = 'limit for test copies\' public doors';
        if ($isRootAdmin === false) {
            return self::refusedAndRecorded($what, 0, 'Only a global administrator of the whole installation can change what '
                . 'test copies\' public doors may do. Nothing was saved.', $actorId);
        }
        if (self::testCopyDoorRule($db, 0) === self::DOOR_TEST_LIVE) {
            return self::refusedAndRecorded($what, 0, self::LIVE_REFUSAL, $actorId);
        }
        if ($value !== 'testers' && $value !== 'either') {
            return self::refusedAndRecorded($what, 0, 'That choice was not recognised. Nothing was saved.', $actorId);
        }
        try {
            $db->begin_transaction();
            $was  = self::installationRowLocked($db, self::KEY_DOOR_TEST_PERMITTED);
            $stmt = $db->prepare(
                'INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) '
                . "VALUES (NULL, ?, ?, 'testers', 0) ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`)"
            );
            $key = self::KEY_DOOR_TEST_PERMITTED;
            $stmt->bind_param('ss', $key, $value);
            $stmt->execute();
            $stmt->close();
            $count = $db->prepare(
                'SELECT COUNT(*) AS `n` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NOT NULL AND `settingValue` = \'anybody\''
            );
            $open  = self::KEY_DOOR_TEST_OPEN;
            $count->bind_param('s', $open);
            $count->execute();
            $chose = (int) ($count->get_result()->fetch_assoc()['n'] ?? 0);
            $count->close();
            $db->commit();
        } catch (\Throwable $e) {
            self::rollbackQuietly($db);
            error_log('[WebMS-Intra] PublicWebsiteSetup: the limit for test copies\' public doors could not be saved: ' . $e->getMessage());

            return self::refusedAndRecorded($what, 0, 'The database refused the change. Nothing was saved.', $actorId);
        }
        $wasWord = $was === 'either' ? 'either' : 'testers';
        $several = $chose === 1 ? '' : 's';
        Logger::activity(
            'PublicDoorTestCopyLimitSaved',
            'Test copies\' public doors: the installation\'s limit ' . $wasWord . ' → ' . $value . ' (' . ($value === 'either'
                ? 'each organisation may choose "open to anybody"' : 'signed-in testers only, for every organisation') . '); '
                . $chose . ' organisation' . $several . ' had chosen "open to anybody".',
            $actorId
        );
        // The words are doorReachWords()'s, from the rule in force NOW (round
        // 2 of P4d's check: every sentence about who the door answers lives
        // there, whole, for each state).
        $words = self::doorReachWords(['rule' => self::testCopyDoorRule($db, 0), 'limit' => $value, 'chose' => $chose]);

        return ['saved' => true, 'type' => $words['savedLimitType'], 'message' => $words['savedLimit']];
    }

    /**
     * 🧪 Save ONE organisation's choice for its door on a test copy (D-087,
     * #493 P4d): 'testers' or 'anybody'. Only a global administrator of that
     * organisation or of the installation (`isGlobalAdministratorOf()`, read
     * from the database) — D-087 names the organisation's GLOBAL
     * administrator, so somebody given "Set up the public website" may see
     * the choice and may not make it. Only on a test copy.
     *
     * 'anybody' is REFUSED, with the reason and recorded, while the
     * installation's limit is not 'either' — never quietly saved as
     * 'testers' (#668). The limit is read and LOCKED inside the transaction
     * that writes (#647), so a limit lowered at the same moment cannot be
     * missed: one waits for the other.
     *
     * @return array{saved:bool, type:string, message:string}
     */
    public static function saveTestCopyChoice(\mysqli $db, int $siteId, string $value, ?int $actorId): array
    {
        $what = 'choice for its public door on test copies';
        if (self::isGlobalAdministratorOf($db, (int) $actorId, $siteId) === false) {
            return self::refusedAndRecorded($what, $siteId, 'Only a global administrator of this organisation can choose who its '
                . 'public door answers on a test copy. Nothing was saved.', $actorId);
        }
        if (self::testCopyDoorRule($db, $siteId) === self::DOOR_TEST_LIVE) {
            return self::refusedAndRecorded($what, $siteId, self::LIVE_REFUSAL, $actorId);
        }
        if ($value !== 'testers' && $value !== 'anybody') {
            return self::refusedAndRecorded($what, $siteId, 'That choice was not recognised. Nothing was saved.', $actorId);
        }
        try {
            $db->begin_transaction();
            $limit = self::installationRowLocked($db, self::KEY_DOOR_TEST_PERMITTED);
            if ($value === 'anybody' && $limit !== 'either') {
                $db->rollback();

                return self::refusedAndRecorded($what, $siteId, 'This installation lets test copies\' public doors answer signed-in '
                    . 'testers only, so "open to anybody" cannot be chosen. The installation\'s global administrator can change '
                    . 'that. Nothing was saved.', $actorId);
            }
            $was = self::organisationRow($db, $siteId, self::KEY_DOOR_TEST_OPEN, true);
            self::upsertOrganisationRow($db, $siteId, self::KEY_DOOR_TEST_OPEN, $value);
            $db->commit();
        } catch (\Throwable $e) {
            self::rollbackQuietly($db);
            error_log('[WebMS-Intra] PublicWebsiteSetup: the choice for a test copy\'s public door could not be saved: ' . $e->getMessage());

            return self::refusedAndRecorded($what, $siteId, 'The database refused the change. Nothing was saved.', $actorId);
        }
        Logger::activity(
            'PublicDoorTestCopyChoiceSaved',
            'Organisation #' . $siteId . '\'s public door on test copies: ' . ($was === null || $was === '' ? '(not chosen)'
                : ($was === 'anybody' ? 'anybody' : 'testers')) . ' → ' . $value . '.',
            $actorId
        );

        // The words are doorReachWords()'s, from the facts as they are NOW —
        // the rule, whether the door is on, its addresses — so the message
        // can never say the door answers somebody it does not (rounds 1 and 2
        // of P4d's check found three such messages).
        $words = self::doorReachWords(['choice' => $value] + self::doorReachFacts($db, $siteId));

        return ['saved' => true, 'type' => $words['savedChoiceType'], 'message' => $words['savedChoice']];
    }

    /**
     * The facts `doorReachWords()` is keyed by, read for one organisation NOW.
     * Never throws (each reader it asks never does, and the apps are asked
     * inside a catch: an app that cannot be asked counts as switched off,
     * which is what the door does — it refuses).
     *
     *   rule         `testCopyDoorRule()`: live, ungated, testers or anybody;
     *   door         `doorStateFor()`'s state: on, off, spoiled (a name the
     *                portal would not make) or unreadable — the door answers
     *                nobody in the last three, for three different reasons;
     *   public       the public address IN EFFECT (`publicHostInEffect()`), or '';
     *   wEvents      the door's /w/ events address on the portal's own address
     *                (`PublicDoor::nameAddressFor()`), or '';
     *   wPosters     the same for posters;
     *   calendar,
     *   noticeboard  whether Calendar and Events, and the Noticeboard, are
     *                switched on for the organisation
     *                (`AppRegistry::isEnabledForSite()`, as the door asks).
     *
     * @return array{rule:string, door:string, public:string, wEvents:string, wPosters:string, calendar:bool, noticeboard:bool}
     */
    public static function doorReachFacts(\mysqli $db, int $siteId): array
    {
        try {
            $calendar    = AppRegistry::isEnabledForSite('calendar', $siteId);
            $noticeboard = AppRegistry::isEnabledForSite('noticeboard', $siteId);
        } catch (\Throwable $e) {
            $calendar    = false;
            $noticeboard = false;
        }

        return [
            'rule'        => self::testCopyDoorRule($db, $siteId),
            'door'        => self::doorStateFor($db, $siteId)['state'],
            'public'      => self::publicHostInEffect($db, $siteId),
            'wEvents'     => PublicDoor::nameAddressFor($db, $siteId, PublicDoor::SURFACE_EVENTS),
            'wPosters'    => PublicDoor::nameAddressFor($db, $siteId, PublicDoor::SURFACE_POSTERS),
            'calendar'    => $calendar,
            'noticeboard' => $noticeboard,
        ];
    }

    /**
     * 🗣️ EVERY SENTENCE THAT TELLS SOMEBODY WHO THE PUBLIC DOOR ANSWERS, where
     * to try it, or what a website gets — in ONE place (#493 P4d, round 2 of
     * its check; the house precedent is `DbBackup::cascadeWarning()`, #694).
     * Pure: it takes facts (`doorReachFacts()`, plus `choice` for the choice's
     * save and `limit` and `chose` for the limit's) and opens no connection,
     * so the self-test asks it for every combination and compares each page
     * with it. No page writes a sentence of its own about these things.
     *
     * ⚠️ WHY IT EXISTS. Rounds 1 and 2 of P4d's check each found such a
     *    sentence untrue in one combination of states; patching each where it
     *    was written missed the next. Round 3 found three more facts the
     *    sentences depend on (a spoiled or unreadable door, the two apps, and
     *    how browsers really treat the sign-in cookie), each added HERE.
     *
     * THE STATES, decided in this order:
     *   1. the door is not on — switched off, a stored name the portal would
     *      not make, or not readable — so it answers nobody; each says its
     *      own reason;
     *   2. on, but with NO address (no public address in effect and no /w/
     *      address) — nobody can reach it;
     *   3. on, but Calendar and Events AND the Noticeboard are both switched
     *      off — every address it has answers nothing, so there is nothing
     *      to show;
     *   4. the live portal, a test copy with its own staff gate switched off,
     *      or one open to anybody — it answers anybody (on a test copy every
     *      answer tells search engines not to list it);
     *   5. a test copy for signed-in testers only.
     * In states 4 and 5, when ONE app is switched off the sentences say what
     * the door does not give, and never point anybody at that app's address.
     *
     * WHAT A BROWSER REALLY SENDS (round 3 of P4d's check, measured in a real
     * browser). The sign-in cookie names no domain (host-only) and is
     * SameSite=Lax. So it is NEVER sent to a public address (another host),
     * and never to a website's own server reading a feed; and the pasted
     * script's own feed requests are made without cookies, so the script
     * shows nothing to anybody. The words say exactly that.
     *
     * ⚠️ THESE SENTENCES SAY NOTHING ABOUT FRAMES, on purpose (round 4 of
     *    P4d's check). Whether a framed copy shows anything depends on the
     *    browser's cookie rules AND on the framing list and the
     *    installation's framing switch, which this method is not given; a
     *    sentence about frames here was untrue two rounds running (first a
     *    "never", then a promise the framing rule refuses by default). Frames
     *    are the framing card's business, which knows its list. The
     *    self-test fails if a sentence here mentions a frame.
     *
     * "Until it is opened to anybody" is always followed by who decides it,
     * which is true whoever is reading, so no sentence depends on the viewer.
     *
     * RETURNS, for each place that shows one (null where that place shows
     * nothing in this state):
     *   alert            the warning in the public door card (testers only,
     *                    door on, with an address and something to show);
     *   card             the test-copy card's sentence (null on the live
     *                    portal);
     *   reachRow         the set-up check's reach row when it does NOT fetch
     *                    the public address (given one in effect): the door
     *                    is not on, or nobody can be signed in there; null
     *                    means "fetch";
     *   doorRow          the set-up check's "not tested" row about the door;
     *   savedChoice,
     *   savedChoiceType  what saving the organisation's choice says (given
     *                    `choice`) — the choice, then `card` — and its colour:
     *                    amber unless the choice is in force AND the door
     *                    answers somebody here (so the gate-off state, where
     *                    the door answers everybody but the choice has no
     *                    effect, is amber too);
     *   savedLimit,
     *   savedLimitType   what saving the installation's limit says (given
     *                    `limit` and `chose`), and its colour.
     *
     * @param array<string,mixed> $f
     *
     * @return array{alert:?string, card:?string, reachRow:?string, doorRow:string, savedChoice:string, savedChoiceType:string, savedLimit:string, savedLimitType:string}
     */
    public static function doorReachWords(array $f): array
    {
        $rule  = (string) ($f['rule'] ?? self::DOOR_TEST_LIVE);
        $door  = (string) ($f['door'] ?? 'off');
        $host  = (string) ($f['public'] ?? '');
        $we    = (string) ($f['wEvents'] ?? '');
        $wp    = (string) ($f['wPosters'] ?? '');
        $cal   = ($f['calendar'] ?? true) === true;
        $nb    = ($f['noticeboard'] ?? true) === true;
        $until = ' until it is opened to anybody, which a global administrator of this organisation chooses on this page, '
            . 'within what the installation\'s global administrator allows';
        $notTested = 'Whether your website really shows it is not tested by this check, which fetches only your main website\'s '
            . 'front page (to see that it answers) and never looks for the door on your website\'s pages.';
        $reachNot = 'Whether it reaches this portal\'s public door was not checked: ';
        // What the door gives when ONE app is switched off — a whole sentence.
        $appsNote = $cal === true && $nb === false
            ? ' The Noticeboard is switched off for your organisation, so the door gives no posters.'
            : ($cal === false && $nb === true
                ? ' Calendar and Events is switched off for your organisation, so the door gives no events.' : '');
        // Where a tester can try it: only the /w/ addresses of what the door gives.
        $try = $we === ''
            ? 'The portal cannot build the door\'s address on its own address either, so testers cannot try it here.'
            : ($cal === true && $nb === true
                ? 'Testers can try the door at ' . $we . ' (events) and ' . $wp . ' (posters), in a browser signed in here.'
                : ($cal === true
                    ? 'Testers can try the door at ' . $we . ' (events), in a browser signed in here; the Noticeboard is switched '
                        . 'off for your organisation, so the door gives no posters.'
                    : 'Testers can try the door at ' . $wp . ' (posters), in a browser signed in here; Calendar and Events is '
                        . 'switched off for your organisation, so the door gives no events.'));
        $firstTry = $cal === true ? $we : $wp;

        $out = ['alert' => null, 'card' => null, 'reachRow' => null, 'doorRow' => '', 'savedChoice' => '', 'savedChoiceType' => 'warning',
            'savedLimit' => '', 'savedLimitType' => 'success'];

        if ($door !== 'on') {
            // 1️⃣ The door is not on: three reasons, each said.
            if ($door === 'spoiled') {
                $out['card'] = 'Your public door\'s stored name is not one the portal makes (it was changed by hand), so the door '
                    . 'answers nobody on this test copy, whatever is chosen here, until the name is replaced on the public door card.';
                $out['doorRow'] = $notTested . ' Its stored name is not one the portal makes, so it answers nobody and a website '
                    . 'gets nothing from it until the name is replaced.';
                $out['reachRow'] = $reachNot . 'the door\'s stored name is not one the portal makes, so nothing answers there '
                    . 'for your organisation until the name is replaced.';
            } elseif ($door === 'unreadable') {
                $out['card'] = 'Whether your public door is on could not be read just now; while it cannot be read, the door '
                    . 'answers nobody on this test copy, whatever is chosen here.';
                $out['doorRow'] = $notTested . ' Whether the door is on could not be read just now; while it cannot be read, a '
                    . 'website gets nothing from it.';
                $out['reachRow'] = $reachNot . 'whether the door is on could not be read, so nothing answers there for your '
                    . 'organisation while it cannot be read.';
            } else {
                $out['card'] = 'Your public door is switched off, so it answers nobody on this test copy, whatever is chosen here.';
                $out['doorRow'] = $notTested . ' While the door is switched off, a website gets nothing from it.';
                $out['reachRow'] = $reachNot . 'the door is switched off, so nothing answers there for your organisation.';
            }
        } elseif ($host === '' && $we === '') {
            // 2️⃣ On, with no address at all.
            $out['card']    = 'Your public door is on, but it has no address yet (the public door card on this page says why), '
                . 'so nobody can reach it here.';
            $out['doorRow'] = $notTested . ' The door has no address yet (see above), so a website gets nothing from it.';
        } elseif ($cal === false && $nb === false) {
            // 3️⃣ On, with nothing to show.
            $out['card']    = 'Your public door is on, but Calendar and Events and the Noticeboard are both switched off for your '
                . 'organisation, so it has nothing to show: its feeds, its script, its page and its pictures all answer nothing, '
                . 'whatever is chosen here.';
            $out['doorRow'] = $notTested . ' With Calendar and Events and the Noticeboard both switched off for your organisation, '
                . 'the door has nothing to show, so a website gets nothing from it.';
            if ($rule === self::DOOR_TEST_TESTERS && $host !== '') {
                $out['reachRow'] = $reachNot . 'on this test copy the door answers only people signed in here who may use the '
                    . 'test copy, and a browser never sends that sign-in to a public address — so ' . $host . ' answers nobody '
                    . 'here,' . $until . '. There is nothing for testers to try either: Calendar and Events and the Noticeboard '
                    . 'are both switched off for your organisation.';
            }
        } elseif ($rule !== self::DOOR_TEST_TESTERS) {
            // 4️⃣ It answers anybody.
            $out['doorRow'] = $notTested . ' Open its addresses in your own browser to see what a website gets.' . $appsNote;
            if ($rule === self::DOOR_TEST_UNGATED) {
                $out['card'] = 'This test copy\'s own staff sign-in gate is switched off, so every page of it — your public door '
                    . 'included — answers anybody, whatever is chosen here, until that gate is switched back on. Every answer '
                    . 'from the door tells search engines not to list it.' . $appsNote;
            } elseif ($rule === self::DOOR_TEST_ANYBODY) {
                $out['card'] = 'Your public door here answers anybody on the internet, so a script pasted into a real website, and a '
                    . 'website reading its feeds, get what it gives. Every answer from the door tells search engines not to list it.'
                    . $appsNote;
                $out['savedChoiceType'] = 'success';
            }
        } else {
            // 5️⃣ Testers only.
            $out['savedChoiceType'] = 'success';
            $publicNobody = 'your public address, ' . $host . ', answers nobody, because a browser never sends that sign-in there';
            if ($we !== '') {
                $out['card'] = 'Your public door here answers only people signed in to this test copy who may use it, so the public '
                    . 'sees nothing from it. A tester signed in here can open it directly at its address on the portal\'s own '
                    . 'address, such as ' . $firstTry . '; a pasted script shows nothing, a website\'s own server gets nothing'
                    . ($host !== '' ? ', and ' . $publicNobody : '') . ',' . $until . '.' . $appsNote;
                $out['doorRow'] = $notTested . ' On this test copy the public sees nothing from the door: it answers only people '
                    . 'signed in here, a pasted script shows nothing and a website\'s own server gets nothing'
                    . ($host !== '' ? ', and ' . $publicNobody : '') . '.' . $appsNote;
                $out['alert'] = $host !== ''
                    ? 'This is a test copy, and its public door answers only people signed in here who may use the test copy, so '
                        . 'the public sees nothing from it. The addresses above are on your public address, ' . $host . ', which a '
                        . 'browser never sends that sign-in to — so they answer nobody, and neither does any copy pasted into a '
                        . 'website that uses them,' . $until . '. ' . $try
                    : 'This is a test copy, and its public door answers only people signed in here who may use the test copy, so '
                        . 'the public sees nothing from it. Opened directly in a browser signed in here, the addresses above show '
                        . 'what testers get; a pasted script shows nothing,' . $until . '. ' . $try;
            } else {
                // A public address only: a browser never sends the sign-in there.
                $out['card'] = 'Your public door here answers only people signed in to this test copy who may use it, so the public '
                    . 'sees nothing from it — and as the portal cannot build the door\'s address on its own address, nobody can '
                    . 'reach it at all: ' . $publicNobody . ',' . $until . '.' . $appsNote;
                $out['doorRow'] = $notTested . ' On this test copy the public sees nothing from the door: it answers only people '
                    . 'signed in here, ' . $publicNobody . ', and the portal cannot build the door\'s address on its own address.'
                    . $appsNote;
                $out['alert'] = 'This is a test copy, and its public door answers only people signed in here who may use the test '
                    . 'copy, so the public sees nothing from it. The addresses above are on your public address, ' . $host
                    . ', which a browser never sends that sign-in to — so they answer nobody, and neither does any copy pasted '
                    . 'into a website that uses them,' . $until . '. ' . $try;
                $out['savedChoiceType'] = 'warning';
            }
            if ($host !== '') {
                $out['reachRow'] = $reachNot . 'on this test copy the door answers only people signed in here who may use the '
                    . 'test copy, and a browser never sends that sign-in to a public address — so ' . $host . ' answers nobody '
                    . 'here,' . $until . '. ' . $try;
            }
        }
        // The live portal has no test-copy card and no test-copy warning. Its
        // reach row is still said when the door is not on.
        if ($rule === self::DOOR_TEST_LIVE) {
            $out['card']  = null;
            $out['alert'] = null;
            if ($door === 'on') {
                $out['reachRow'] = null;
            }
        }
        // The reach row exists only for a public address IN EFFECT.
        if ($host === '') {
            $out['reachRow'] = null;
        }

        // The choice's save: the choice, then the whole truth for the state.
        $choice = (string) ($f['choice'] ?? '');
        if ($out['card'] !== null && ($choice === 'testers' || $choice === 'anybody')) {
            $out['savedChoice'] = 'Saved: your choice is ' . ($choice === 'anybody' ? 'open to anybody' : 'signed-in testers only')
                . '. ' . $out['card'];
        }

        // The limit's save, for the whole installation. It names no door: an
        // organisation that is switched off, or whose door is off, answers
        // nothing at all, so the sentence says what happens to a door that
        // answers, and no more.
        $limit   = (string) ($f['limit'] ?? '');
        $chose   = (int) ($f['chose'] ?? 0);
        $several = $chose === 1 ? '' : 's';
        if ($limit === 'testers' || $limit === 'either') {
            if ($rule === self::DOOR_TEST_UNGATED) {
                $out['savedLimitType'] = 'warning';
                $out['savedLimit'] = 'Saved: ' . ($limit === 'either'
                    ? 'each organisation may choose to open its public door to anybody on this test copy.'
                    : 'organisations\' public doors on this test copy are for signed-in testers only.')
                    . ' This test copy\'s own staff sign-in gate is switched off, though, so every page of it answers anybody — '
                    . 'every public door that answers at all included — until that gate is switched back on. This limit takes '
                    . 'effect then.';
            } elseif ($limit === 'either') {
                $out['savedLimit'] = 'Saved: on this test copy, each organisation\'s global administrator may now choose to open '
                    . 'its public door to anybody (search engines are always told not to list it).'
                    . ($chose > 0 ? ' ' . $chose . ' organisation' . $several . ' had already chosen that; from now on, whenever '
                        . ($chose === 1 ? 'that door answers' : 'those doors answer') . ' at all, '
                        . ($chose === 1 ? 'it answers' : 'they answer') . ' anybody.' : '');
            } else {
                $out['savedLimit'] = 'Saved: on this test copy every organisation\'s public door answers signed-in testers only.'
                    . ($chose > 0 ? ' ' . $chose . ' organisation' . $several
                        . ' had chosen "open to anybody"; that choice is kept but does nothing while this limit stands.' : '');
            }
        }

        return $out;
    }

    /** What both test-copy writers say on the live portal, where the setting does nothing. */
    public const LIVE_REFUSAL = 'This is the live portal. This setting changes only how the public door answers on a test copy '
        . '(the alpha, beta or dev copy), so it is set on that copy, where it is shown. Nothing was saved.';

    /**
     * Save the organisation's optional public address (D-095). Empty DELETES
     * its row. Refused when it is the portal's own address, any
     * organisation's own address on this portal, the address this request
     * arrived at, or another organisation's public address — the last by the
     * database's unique key, which is the real guard against a race; the
     * message never names the other organisation.
     *
     * @return array{saved:bool, type:string, message:string}
     */
    public static function savePublicHost(\mysqli $db, int $siteId, string $typed, ?int $actorId, string $requestHost = ''): array
    {
        $checked = self::checkPublicHost($typed);
        if ($checked['problem'] !== '') {
            return self::refusedAndRecorded('public address', $siteId, $checked['problem'] . ' Nothing was saved.', $actorId);
        }
        $host = $checked['host'];
        try {
            $was = self::publicHostFor($db, $siteId);
            if ($host === '') {
                $del = $db->prepare('DELETE FROM `' . self::HOSTS_TABLE . '` WHERE `siteID` = ?');
                $del->bind_param('i', $siteId);
                $del->execute();
                $del->close();
            } else {
                // D-101, round 6 of P4a's check: with the portal's own address
                // NOT stored, the portal cannot tell which addresses members
                // sign in at — the members' address itself could be saved as
                // a public one (the /w/ form refuses in this state too).
                if (PortalAddress::visitorCanChooseHost() === true) {
                    return self::refusedAndRecorded('public address', $siteId, 'The portal\'s own address is not set, so it '
                        . 'cannot tell which addresses members sign in at. A global administrator sets it on Admin, Settings, '
                        . 'Organisation first. Nothing was saved.', $actorId);
                }
                if (in_array($host, self::hostsMembersUse($db, $requestHost), true) === true) {
                    return self::refusedAndRecorded('public address', $siteId, 'That is an address the portal already '
                        . 'answers on for signing in. The public address has to be a different name, so public visitors '
                        . 'never reach the address members sign in at. Nothing was saved.', $actorId);
                }
                if ($host === $was) {
                    return ['saved' => true, 'type' => 'info', 'message' => 'Saved. Nothing was different.'];
                }
                // ⚠️ AN UPDATE OR AN INSERT, NEVER `INSERT … ON DUPLICATE KEY
                //    UPDATE`. This table has TWO unique keys (the organisation and
                //    the name), and when a new row clashes with both MySQL
                //    updates whichever clashing row it meets first — which could
                //    be ANOTHER organisation's row, quietly "saving" a name that
                //    is theirs. A plain UPDATE or INSERT fails with 1062 instead.
                $stmt = $was !== '' || self::hasHostRow($db, $siteId) === true
                    // D-101: saving or CHANGING the address empties its
                    // approval — a new address waits for the installation's
                    // global administrator like a first one.
                    ? $db->prepare('UPDATE `' . self::HOSTS_TABLE . '` SET `hostName` = ?, `changedAtUtc` = UTC_TIMESTAMP(), '
                        . '`approvedAtUtc` = NULL, `approvedByID` = NULL WHERE `siteID` = ?')
                    : $db->prepare('INSERT INTO `' . self::HOSTS_TABLE . '` (`hostName`, `siteID`, `changedAtUtc`, `approvedAtUtc`, `approvedByID`) '
                        . 'VALUES (?, ?, UTC_TIMESTAMP(), NULL, NULL)');
                $stmt->bind_param('si', $host, $siteId);
                try {
                    $stmt->execute();
                    // ⚠️ An UPDATE whose row has vanished (somebody emptied this
                    //    organisation's address at the same moment) changes
                    //    nothing and raises nothing — so ask, rather than report
                    //    "Saved" and record a change that did not happen.
                    if ($stmt->affected_rows < 1) {
                        $stmt->close();

                        return self::refusedAndRecorded('public address', $siteId, 'Somebody changed this organisation\'s '
                            . 'public address at the same moment. Nothing was saved; look at the page again and try once more.', $actorId);
                    }
                } catch (\mysqli_sql_exception $dup) {
                    $stmt->close();
                    if ($dup->getCode() === 1062) {
                        // WHOSE row clashed — asked, never assumed. Two saves for
                        // the SAME organisation at once clash too (on the
                        // organisation's own key, or on the same name), and
                        // telling that person "another organisation" would be
                        // untrue.
                        $owner = self::hostOwner($db, $host);
                        if ($owner === $siteId) {
                            return ['saved' => true, 'type' => 'info', 'message' => 'That is already this organisation\'s public '
                                . 'address (it was saved at the same moment, probably from another tab). Nothing was different.'];
                        }
                        if ($owner === null) {
                            return self::refusedAndRecorded('public address', $siteId, 'Somebody changed this organisation\'s '
                                . 'public address at the same moment. Nothing was saved; look at the page again and try once more.', $actorId);
                        }

                        return self::refusedAndRecorded('public address', $siteId, 'That public address is already used '
                            . 'by another organisation on this portal. Each public address belongs to one organisation. '
                            . 'Nothing was saved.', $actorId);
                    }
                    throw $dup;
                }
                $stmt->close();
            }
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] PublicWebsiteSetup: the public address could not be saved: ' . $e->getMessage());

            return self::refusedAndRecorded('public address', $siteId, 'The database refused the change. Nothing was saved.', $actorId);
        }
        Logger::activity(
            'PublicWebsitePublicAddressSaved',
            'Organisation #' . $siteId . '\'s public address for its public pages: ' . ($was === '' ? '(none)' : $was)
            . ' → ' . ($host === '' ? '(none)' : $host) . '.',
            $actorId
        );

        return ['saved' => true, 'type' => 'success', 'message' => $host === ''
            ? 'Saved: no public address is set, so the public pages will use the portal\'s own address.'
            : 'Saved. It does nothing yet: it waits for the installation\'s global administrator to approve it. Once approved, '
                . 'the public door answers on it — after whoever looks after your domain points it at this portal, and only '
                . 'while the public door is on.'];
    }

    /**
     * The host names members sign in at, which a public address must never
     * be: the portal's own address (`site.url`, installation row), every
     * organisation's own address (`tblSites.hostPattern`), and the address
     * this request arrived at.
     *
     * @return list<string>
     */
    public static function hostsMembersUse(\mysqli $db, string $requestHost = ''): array
    {
        $out = [];
        $add = static function (string $host) use (&$out): void {
            $host = rtrim(strtolower(trim($host)), '.');
            if ($host !== '' && in_array($host, $out, true) === false) {
                $out[] = $host;
            }
        };
        $stmt = $db->prepare('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL LIMIT 1');
        $key  = PortalAddress::SETTING;
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row !== null) {
            $portal = PortalAddress::clean((string) $row['settingValue']);
            if ($portal !== null && $portal !== '') {
                $add((string) parse_url($portal, PHP_URL_HOST));
            }
        }
        $rs = $db->query('SELECT `hostPattern` FROM `tblSites` WHERE `hostPattern` IS NOT NULL AND `hostPattern` <> \'\'');
        if ($rs !== false) {
            while (is_array($site = $rs->fetch_assoc()) === true) {
                $add((string) $site['hostPattern']);
            }
        }
        $add((string) preg_replace('/:\d+$/', '', $requestHost));

        return $out;
    }

    // =========================================================================
    // 5️⃣ "Check my set-up" — what can be known today, and nothing more
    // =========================================================================

    /**
     * Run the set-up check for one organisation. Read-only: it changes no
     * setting. Each row is `[label, state, words]` where state is 'ok',
     * 'problem', 'note' or 'untested'. The door is reported as on or off
     * ('note'). Since #493 P4d a public address IN EFFECT is fetched at
     * `PublicDoor::SURFACE_CHECK` and the answer judged by
     * `publicAddressVerdict()` — whenever the door would answer there; when
     * it would not, the row says why it was not checked (`publicAddressRow()`).
     * Whether a WEBSITE really shows the door is ALWAYS 'untested': the
     * only page of the organisation's own website the check fetches is the
     * main website's FRONT page, to see that it answers, and it never looks
     * for the door there.
     *
     * ⚠️ IT TAKES NO "FETCH" ARGUMENT ANY MORE (round 3 of P3's check). A test
     *    hook that replaced the fetch let the real path pass different options
     *    from the ones the test saw, with every check green. The real
     *    `SafeFetch::get()` is called here, once, with
     *    `mainWebsiteFetchOptions()`; the self-test records the options by
     *    running this method against a scratch copy of SafeFetch.
     *
     * @return list<array{label:string, state:string, words:string}>
     */
    public static function checkSetUp(\mysqli $db, int $siteId): array
    {
        $rows    = [];
        $website = self::websiteAddressFor($db, $siteId);
        $origins = self::frameOriginsStored($db, $siteId);

        // 🏠 The main website.
        if ($website === '') {
            $rows[] = ['label' => 'Your main website', 'state' => 'problem', 'words' => 'Not set. Type your main website\'s address on this page.'];
        } else {
            $host = (string) parse_url($website, PHP_URL_HOST);
            $port = parse_url($website, PHP_URL_PORT);
            $port = ($port === null || $port === false) ? 443 : (int) $port;
            if ($port !== 443) {
                // ⚠️ A WEBSITE AT ANOTHER PORT CANNOT BE CHECKED, and that is
                //    said rather than guessed. The page accepts such an address
                //    (it is a real address), but SafeFetch reaches only the
                //    standard https port. Port 443 itself — which an http://
                //    address keeps, because 443 is not http's usual port — IS
                //    the standard https port, so it is checked (round 3).
                $rows[] = ['label' => 'Your main website', 'state' => 'untested', 'words' => $website . ': it uses port ' . $port
                    . ', which is not the standard https port, so this check cannot reach it. Open it in your own browser to see what your visitors get.'];
            } else {
                try {
                    $got = SafeFetch::get('https://' . $host . '/', self::mainWebsiteFetchOptions());
                } catch (\Throwable $e) {
                    $got = ['ok' => false, 'status' => 0, 'capped' => false];
                }
                $verdict = self::mainWebsiteVerdict($got);
                $rows[]  = ['label' => 'Your main website', 'state' => $verdict['state'], 'words' => $website . ': ' . $verdict['words']];
                if (str_starts_with($website, 'http://') === true) {
                    $rows[] = ['label' => 'Your main website', 'state' => 'note', 'words' => 'It is recorded with http://; it was checked at https://.'];
                }
            }
        }

        // 🌍 The optional public address.
        $public = self::publicHostFor($db, $siteId);
        if ($public === '') {
            $rows[] = ['label' => 'Public address', 'state' => 'note', 'words' => 'None set. That is fine: the public pages will use the portal\'s own address.'];
        } else {
            $unique = self::hostOwnerCount($db, $public) === 1;
            $reserved = in_array($public, self::hostsMembersUse($db), true);
            $resolves = SafeFetch::check('https://' . $public . '/')['ok'] === true;
            $rows[] = ['label' => 'Public address', 'state' => ($unique === true && $reserved === false) ? 'ok' : 'problem',
                'words' => $public . ($unique === true ? ' is used by this organisation only' : ' is not this organisation\'s alone')
                    . ($reserved === true ? ', and it is an address members sign in at' : '') . '.'];
            $rows[] = ['label' => 'Public address', 'state' => $resolves === true ? 'ok' : 'problem',
                'words' => $resolves === true ? 'Its name exists on the internet.' : 'Its name could not be found on the internet, or it points at a private network. Ask whoever looks after your domain to point it at this portal.'];
            $rows[] = match (self::publicHostStatus($db, $siteId)) {
                self::HOST_APPROVED  => ['label' => 'Public address', 'state' => 'ok', 'words' => 'Approved by the installation\'s administrator.'],
                self::HOST_MEMBERS   => ['label' => 'Public address', 'state' => 'problem', 'words' => 'Approved, but it is also an '
                    . 'address members sign in at, so the public door does NOT answer on it.'],
                self::HOST_UNCHECKED => ['label' => 'Public address', 'state' => 'problem', 'words' => 'Approved, but whether it is '
                    . 'also an address members sign in at could not be checked just now, so it is not counted as the public door\'s '
                    . 'address. Try again later.'],
                default              => ['label' => 'Public address', 'state' => 'problem', 'words' => 'Waiting for the '
                    . 'installation\'s administrator to approve it. Until then it does nothing.'],
            };
            $rows[] = self::publicAddressRow($db, $siteId, $public);
        }

        // 🖼️ Framing.
        $permitted = self::framingPermitted($db);
        if ($origins === []) {
            $rows[] = ['label' => 'Websites allowed to frame your pages', 'state' => 'note', 'words' => 'None listed, so no outside website may show your public pages in a frame.'];
        } else {
            foreach ($origins as $origin) {
                $rows[] = ['label' => 'Websites allowed to frame your pages', 'state' => $permitted === true ? 'ok' : 'problem',
                    'words' => $origin . ($permitted === true ? ' is a valid https website and is allowed.' : ' is valid, but this installation does not allow framing at all, so it is ignored.')];
            }
        }
        if ($website !== '' && $origins !== []) {
            $mainOrigin = self::checkFrameOrigin('https://' . substr($website, (int) strpos($website, '://') + 3));
            if ($mainOrigin['problem'] === '' && in_array($mainOrigin['origin'], $origins, true) === false) {
                $rows[] = ['label' => 'Websites allowed to frame your pages', 'state' => 'note',
                    'words' => 'Your main website is not on the list. Add it if the frame is going on your main website.'];
            }
        }

        // 📋 What is on the public website today, counted by the rules the door uses.
        $door = self::doorStateFor($db, $siteId);
        [$events, $posters] = self::countsOnWebsiteToday($db, $siteId);
        $rows[] = ['label' => 'On the public website today', 'state' => 'note', 'words' => $events === null
            ? 'The events and posters could not be counted.'
            : $events . ' event' . ($events === 1 ? '' : 's') . ' and ' . $posters . ' noticeboard poster' . ($posters === 1 ? '' : 's')
                . ' are marked to show today' . ($door['state'] === 'on'
                    ? ' (the door leaves out events that have already finished).'
                    : '. Nothing is shown anywhere while the public door is off.')];

        // 🧭 The portal's own address.
        $state = PortalAddress::state();
        $rows[] = $state === PortalAddress::STATE_SET
            ? ['label' => 'The portal\'s own address', 'state' => 'ok', 'words' => 'Set (' . PortalAddress::saved() . ').']
            : ['label' => 'The portal\'s own address', 'state' => 'problem', 'words' => $state === PortalAddress::STATE_UNREADABLE
                ? 'It could not be read.' : 'Not set, or not usable. A global administrator sets it on Admin, Settings, Organisation.'];

        // 🚪 The public door (#493 P4a): on or off, with its addresses — said,
        //    never ticked. Whether a website really shows it is ALWAYS
        //    untested here, because this check never looks for the door on
        //    the organisation's website (it fetches the main website's front
        //    page only to see that it answers).
        if ($door['state'] === 'on') {
            $eventsAt  = PublicDoor::addressFor($db, $siteId, PublicDoor::SURFACE_EVENTS);
            $postersAt = PublicDoor::addressFor($db, $siteId, PublicDoor::SURFACE_POSTERS);
            $rows[] = ['label' => 'The public door', 'state' => 'note', 'words' => $eventsAt === ''
                ? 'Switched on, but the portal cannot build its addresses. ' . PublicDoor::whyNoAddress($db, $siteId)
                : 'Switched on. Its events are read from ' . $eventsAt . ' and its posters from ' . $postersAt . '.'];
        } else {
            $rows[] = ['label' => 'The public door', 'state' => 'note', 'words' => $door['state'] === 'unreadable'
                ? 'Whether it is on could not be read.'
                : 'Switched off, so nothing the portal holds reaches any outside website.'];
        }
        $rows[] = ['label' => 'The public door', 'state' => 'untested', 'words' => self::doorReachWords(self::doorReachFacts($db, $siteId))['doorRow']];

        return $rows;
    }

    /**
     * 🚪 Does the organisation's public address reach THIS organisation's
     * public door on THIS portal (#493 P4d)? ONE row, whose words are true in
     * every state:
     *
     *   - not approved yet (D-101), or approved but not in effect (an address
     *     members sign in at, or one that could not be checked): 'untested',
     *     because the door does not answer there, so there is nothing to
     *     fetch — the row above it says why;
     *   - the door off, spoiled or unreadable: 'untested', the same reason;
     *   - a test copy whose door answers signed-in testers only
     *     (`testCopyDoorRule()`): 'untested'. This check is not signed in
     *     when it fetches, so the door would refuse it, which proves nothing
     *     — and neither is anybody else at the public address: a browser
     *     sends the portal's sign-in only to the address it signed in at (the
     *     session cookie names no domain), so on such a test copy the public
     *     address answers NOBODY. The row says so, and names the door's
     *     /w/{name}/ address on the portal's own address
     *     (`PublicDoor::nameAddressFor()`), the one place a tester can try it
     *     (round 1 of P4d's check: it told people to open the public address
     *     "in this browser", which can never work);
     *   - otherwise it FETCHES `https://{address}/door-check.txt?n={a random
     *     value made for this run}` through `SafeFetch` (the ONE way to fetch
     *     an address somebody typed; it never sets `CURLOPT_ENCODING`, #716)
     *     with `publicAddressFetchOptions()`, and `publicAddressVerdict()`
     *     says what the answer means.
     *
     * The random value is made here, sent once, and kept nowhere: the proof
     * it asks for (`PublicDoor::checkProof()`) is worked out from the door's
     * name as it stands NOW, so a name replaced between the two reads gives a
     * "not this door" answer that a second press puts right.
     *
     * @return array{label:string, state:string, words:string}
     */
    public static function publicAddressRow(\mysqli $db, int $siteId, string $public): array
    {
        $label  = 'Public address';
        $status = self::publicHostStatus($db, $siteId);
        if ($status === self::HOST_WAITING) {
            return ['label' => $label, 'state' => 'untested', 'words' => 'Whether it reaches this portal\'s public door was not '
                . 'checked: until the installation\'s administrator approves it, the door does not answer there.'];
        }
        if ($status !== self::HOST_APPROVED) {
            return ['label' => $label, 'state' => 'untested', 'words' => 'Whether it reaches this portal\'s public door was not '
                . 'checked: as said above, the door does not answer on it.'];
        }
        // The door not on (switched off, a spoiled name, unreadable) or a
        // testers-only test copy: nothing to fetch, and doorReachWords() says
        // why (round 3 of P4d's check: a spoiled name used to be called
        // "not switched on").
        $reach = self::doorReachWords(self::doorReachFacts($db, $siteId));
        if ($reach['reachRow'] !== null) {
            return ['label' => $label, 'state' => 'untested', 'words' => $reach['reachRow']];
        }
        $door  = self::doorStateFor($db, $siteId);
        $nonce = bin2hex(random_bytes(32));
        $proof = PublicDoor::checkProof($door['name'], $siteId, $public, $nonce);
        try {
            $got = SafeFetch::get('https://' . $public . '/' . PublicDoor::SURFACE_CHECK . '?n=' . $nonce, self::publicAddressFetchOptions());
        } catch (\Throwable $e) {
            $got = ['ok' => false, 'status' => 0, 'body' => '', 'capped' => false];
        }
        $verdict = self::publicAddressVerdict($got, $proof, $public);

        return ['label' => $label, 'state' => $verdict['state'], 'words' => $verdict['words']];
    }

    /**
     * The options the public-address fetch hands `SafeFetch::get()`: the
     * same time limits as the main website's, https only, a small answer
     * (the door's is one line), and NO redirects — the door never sends one,
     * so an answer that does is not the door's, whatever it leads to.
     *
     * @return array{timeoutSeconds:int, connectTimeoutSeconds:int, maxBytes:int, maxRedirects:int, httpsOnly:bool, accept:string, deadline:float}
     */
    public static function publicAddressFetchOptions(): array
    {
        return [
            'timeoutSeconds'        => self::CHECK_TIMEOUT_SECONDS,
            'connectTimeoutSeconds' => self::CHECK_TIMEOUT_SECONDS,
            'maxBytes'              => 1024,
            'maxRedirects'          => 0,
            'httpsOnly'             => true,
            'accept'                => 'text/plain',
            'deadline'              => self::CHECK_DEADLINE_SECONDS + microtime(true),
        ];
    }

    /**
     * 🔎 What the public-address fetch's answer means — ONLY what is
     * certainly known. Pure, and public so the self-test asks it directly.
     *
     *   - this server has no curl: 'untested', it could not be checked here;
     *   - a complete HTTP 200 whose whole body is exactly
     *     `PublicDoor::CHECK_FORMAT`, a space, `$expectedProof` and a line
     *     break (compared with `hash_equals()`): 'ok' — the answer carried
     *     this run's proof, which only something holding this organisation's
     *     CURRENT door name can work out: this portal's door, a server
     *     passing the request on to it, or somebody who knows the name (it is
     *     in any copy pasted with /w/{name}/ — see `PublicDoor::checkProof()`
     *     for why that last is no use to anybody). The words claim exactly
     *     that and no more (round 1 of P4d's check);
     *   - a complete HTTP 200 in that same form with ANY OTHER proof:
     *     'problem' — a public door of this kind answered, but not this one.
     *     It does not say whose: another installation of the portal and
     *     another organisation's door here look the same from outside, so
     *     the sentence gives both as examples;
     *   - anything else, including no answer at all: 'problem', one sentence
     *     that is always true, worded "for example" as the main website's is
     *     (`mainWebsiteVerdict()`'s rule — three rounds of P3's check showed
     *     SafeFetch's answer cannot tell causes apart), plus the last HTTP
     *     status where SafeFetch reports one, as a fact and not a cause.
     *
     * @param array<string,mixed> $got A `SafeFetch::get()` result.
     *
     * @return array{state:string, words:string}
     */
    public static function publicAddressVerdict(array $got, string $expectedProof, string $public): array
    {
        if (function_exists('curl_init') !== true) {
            return ['state' => 'untested', 'words' => 'Whether it reaches this portal\'s public door could not be checked on this '
                . 'server, because this server cannot fetch outside addresses (PHP\'s curl extension is not installed or is switched '
                . 'off on this server). Open https://' . $public . '/events.json in your own browser to see what the door gives there.'];
        }
        $status = (int) ($got['status'] ?? 0);
        $body   = is_string($got['body'] ?? null) === true ? $got['body'] : '';
        if ($status === 200 && ($got['ok'] ?? false) === true) {
            if (hash_equals(PublicDoor::CHECK_FORMAT . ' ' . $expectedProof . "\n", $body) === true) {
                return ['state' => 'ok', 'words' => 'It answers as your organisation\'s public door: the answer from https://'
                    . $public . '/ carried this check\'s one-off proof, which only a door holding your door\'s current name can work out.'];
            }
            if (preg_match('/^' . preg_quote(PublicDoor::CHECK_FORMAT, '/') . ' [0-9a-f]{64}\n$/D', $body) === 1) {
                return ['state' => 'problem', 'words' => 'A public door answered at https://' . $public . '/, but not this '
                    . 'organisation\'s door on this portal. That can happen, for example, when the address leads to another '
                    . 'installation of the portal, or to another organisation\'s door. Make sure the address points at this portal.'];
            }
        }

        return ['state' => 'problem', 'words' => 'This check did not get an answer from this organisation\'s public door at https://'
            . $public . '/. That can happen, for example, when the address points at a different server, something in front of '
            . 'it answers instead or sends visitors on to another address, its security certificate does not cover the name, the '
            . 'portal is closed for maintenance, or this server cannot reach outside addresses or its own public address. Open '
            . 'https://' . $public . '/events.json in your own browser to see what the door gives there.'
            . ($status === 0 ? '' : ' The last answer this check received was HTTP ' . $status . '.')];
    }

    /**
     * The options the set-up check hands `SafeFetch::get()` for the main
     * website: the per-step limit, the redirect limit, https only, and an
     * overall DEADLINE, worked out at the moment of asking.
     *
     * @return array{timeoutSeconds:int, connectTimeoutSeconds:int, maxBytes:int, maxRedirects:int, httpsOnly:bool, deadline:float}
     */
    public static function mainWebsiteFetchOptions(): array
    {
        return [
            'timeoutSeconds'        => self::CHECK_TIMEOUT_SECONDS,
            'connectTimeoutSeconds' => self::CHECK_TIMEOUT_SECONDS,
            'maxBytes'              => 2000000,
            'maxRedirects'          => self::CHECK_MAX_REDIRECTS,
            'httpsOnly'             => true,
            'deadline'              => microtime(true) + self::CHECK_DEADLINE_SECONDS,
        ];
    }

    /**
     * The HTTP statuses that mean the main website answered and TURNED THE
     * CHECK AWAY: refused (403) and too many requests (429) — what bot
     * protection and rate limits typically send an automated check while the
     * website is fine for visitors. Only these are shown "For information";
     * see `mainWebsiteVerdict()`. 401 is deliberately NOT here (round 7): a
     * 401 at the front page means EVERY visitor is asked for a password, so
     * the public cannot see the website at all.
     *
     * @var list<int>
     */
    public const TURNED_AWAY_STATUSES = [403, 429];

    /** What the check says about the main website whenever it did not get a normal page. */
    public const NOT_A_NORMAL_PAGE = 'This check did not get a normal page from your main website. That can happen, for '
        . 'example, when a website is down, its name does not exist or points at a private or internal address, it sends '
        . 'visitors on to another address this check will not follow, it has a security-certificate '
        . 'problem, it turns away automated visitors, or this server is not allowed to make outside connections. Open it in '
        . 'your own browser to see what your visitors get.';

    /**
     * 🔎 What the check says about the main website — ONLY what is certainly
     * known. Pure, and public so the self-test asks it directly.
     *
     *   - a complete HTTP 200 (or a 200 cut off only by this check's own size
     *     limit): "it answered";
     *   - this server has no curl: "could not be checked on this server";
     *   - anything else: ONE sentence that is always true
     *     (`NOT_A_NORMAL_PAGE`, worded "for example" — never a complete list),
     *     plus — only where SafeFetch reports one — the last HTTP status
     *     received, as a fact and not as a cause. The row is "For
     *     information" ('note') ONLY for the statuses in
     *     `TURNED_AWAY_STATUSES` — 403 and 429 — where the website answered
     *     and turned this check away (refused it, or said "too many
     *     requests"): what bot protection and rate limits send an automated
     *     check while the website is fine for visitors. EVERY other answer,
     *     and no answer at all, is "Needs attention" ('problem'): a 401 means
     *     every visitor is asked for a password, a 5xx is the website's own
     *     server saying it is failing, and a 404, 410 or 421 often means a
     *     lapsed or misconfigured domain (a hosting service answering "no
     *     site here"). (Round 4 drew the line at "400 or more", which called a
     *     failing server "For information"; round 5 at "400-499", which still
     *     called a 404 "For information"; round 6 at 401, 403 and 429; round 7
     *     took 401 out.)
     *
     * ⚠️ NO TEST-ONLY PARAMETER (round 4). It used to take `$curlAvailable`
     *    so a test could say "no curl"; every test then passed it, and the
     *    one path the portal uses — asking PHP — was exercised by nothing
     *    (round 3's hook fault in a new place). The self-test now runs the
     *    REAL `checkSetUp()` in a PHP with `curl_init` disabled.
     *
     * ⚠️ WHY IT NAMES NO CAUSE. Three rounds of P3's check each found the
     *    previous version naming causes that were untrue for real answers: by
     *    status alone (round 1), by SafeFetch's own sentences and status
     *    (rounds 2 and 3: a 301 whose body was cut off, a two-step redirect
     *    ending at http:// or at a missing name, a 300 with an address, a 304,
     *    a redirect followed by a dead address — which SafeFetch reports with
     *    the FAILING step's status, 0). SafeFetch's answer does not carry
     *    enough to tell those apart, and the repository's rule is that an item
     *    failing two rounds running is simplified, not refined again. So the
     *    sentence lists possibilities and the person looks in a browser.
     *
     * @param array<string,mixed> $got A `SafeFetch::get()` result.
     *
     * @return array{state:string, words:string}
     */
    public static function mainWebsiteVerdict(array $got): array
    {
        $status = (int) ($got['status'] ?? 0);
        if (function_exists('curl_init') === false) {
            return ['state' => 'untested', 'words' => 'it could not be checked on this server, because this server cannot '
                . 'fetch outside addresses (PHP\'s curl extension is not installed or is switched off on this server). Open it in your own browser to see what your visitors get.'];
        }
        if ($status === 200 && (($got['ok'] ?? false) === true || ($got['capped'] ?? false) === true)) {
            return ['state' => 'ok', 'words' => 'it answered.'];
        }

        return ['state' => in_array($status, self::TURNED_AWAY_STATUSES, true) === true ? 'note' : 'problem', 'words' => self::NOT_A_NORMAL_PAGE
            . ($status > 0 ? ' The last answer this check received was HTTP ' . $status . '.' : '')];
    }

    /**
     * How many events and posters are marked to show on the public website
     * TODAY, on the organisation's own calendar — by the SAME rules the
     * public door will use: `EventVisibility::where()` in website mode with
     * `PublicWebsite::copiedRuleFor()`, and `PublicWebsite::posterWhere()`.
     * Events are counted whatever their date, published and not deleted.
     *
     * @return array{0:?int, 1:?int} [events, posters], nulls when they could not be counted
     */
    public static function countsOnWebsiteToday(\mysqli $db, int $siteId): array
    {
        try {
            $today = (new \DateTimeImmutable('now', new \DateTimeZone(Site::timezone($siteId))))->format('Y-m-d');
            $vis   = EventVisibility::where('e', EventVisibility::MODE_WEBSITE, 0, $today, PublicWebsite::copiedRuleFor($db, $siteId));
            $stmt  = $db->prepare(
                'SELECT COUNT(*) AS `n` FROM `tblEvents` e WHERE e.`siteID` = ? AND e.`isDeleted` = 0 AND e.`status` = \'published\''
                . $vis['sql']
            );
            $stmt->bind_param('i' . $vis['types'], ...array_merge([$siteId], $vis['params']));
            $stmt->execute();
            $events = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
            $stmt->close();

            $pw   = PublicWebsite::posterWhere('p', $today);
            $stmt = $db->prepare('SELECT COUNT(*) AS `n` FROM `tblNoticeboardPosters` p WHERE p.`siteID` = ?' . $pw['sql']);
            $stmt->bind_param('i' . $pw['types'], ...array_merge([$siteId], $pw['params']));
            $stmt->execute();
            $posters = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] PublicWebsiteSetup: what is on the public website could not be counted: ' . $e->getMessage());

            return [null, null];
        }

        return [$events, $posters];
    }

    // =========================================================================
    // 🧹 Small helpers
    // =========================================================================

    /**
     * Would a browser read this host as a NUMBER (an IPv4 address) rather
     * than a name? True when its LAST part is all digits, or `0x` followed by
     * hex digits — the rule web browsers apply (the URL standard's "ends in a
     * number"), so `192.0.2.4`, `127.1`, `127.1.2`, `0x7f.1` and
     * `0x7f.0.0.1` all answer true. No real top-level domain is a number, so
     * this refuses no real name. Round 1 of P3's check: the first version
     * refused only four dotted decimal numbers and let the other forms by.
     */
    private static function endsInANumber(string $host): bool
    {
        $parts = explode('.', $host);
        $last  = (string) end($parts);

        return preg_match('/^(?:\d+|0x[0-9a-f]*)$/i', $last) === 1;
    }

    /** Does this organisation have a public-address row at all (even one a hand edit spoiled)? */
    private static function hasHostRow(\mysqli $db, int $siteId): bool
    {
        $stmt = $db->prepare('SELECT 1 FROM `' . self::HOSTS_TABLE . '` WHERE `siteID` = ?');
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_row();
        $stmt->close();

        return $found !== null;
    }

    /** Which organisation holds this public address, or null when none does. */
    private static function hostOwner(\mysqli $db, string $host): ?int
    {
        $stmt = $db->prepare('SELECT `siteID` FROM `' . self::HOSTS_TABLE . '` WHERE `hostName` = ? LIMIT 1');
        $stmt->bind_param('s', $host);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row === null ? null : (int) $row['siteID'];
    }

    /** How many organisations hold this public address (the unique key makes it 0 or 1). */
    private static function hostOwnerCount(\mysqli $db, string $host): int
    {
        $stmt = $db->prepare('SELECT COUNT(*) AS `n` FROM `' . self::HOSTS_TABLE . '` WHERE `hostName` = ?');
        $stmt->bind_param('s', $host);
        $stmt->execute();
        $n = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();

        return $n;
    }

    /** One organisation's own row for a key, exactly as stored, or null. */
    private static function organisationRow(\mysqli $db, int $siteId, string $key, bool $lock): ?string
    {
        $stmt = $db->prepare(
            'SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` = ? LIMIT 1' . ($lock === true ? ' FOR UPDATE' : '')
        );
        $stmt->bind_param('si', $key, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row === null ? null : (string) ($row['settingValue'] ?? '');
    }

    /** The installation's row for a key, LOCKED, or null when there is none. */
    private static function installationRowLocked(\mysqli $db, string $key): ?string
    {
        $stmt = $db->prepare('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL LIMIT 1 FOR UPDATE');
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row === null ? null : (string) ($row['settingValue'] ?? '');
    }

    private static function upsertOrganisationRow(\mysqli $db, int $siteId, string $key, string $value): void
    {
        $stmt = $db->prepare(
            'INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`, `updatedAt`) '
            . "VALUES (?, ?, ?, '', 0, NOW()) ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`), `updatedAt` = NOW()"
        );
        $stmt->bind_param('iss', $siteId, $key, $value);
        $stmt->execute();
        $stmt->close();
    }

    private static function deleteOrganisationRow(\mysqli $db, int $siteId, string $key): void
    {
        $stmt = $db->prepare('DELETE FROM `tblSettings` WHERE `siteID` = ? AND `settingKey` = ?');
        $stmt->bind_param('is', $siteId, $key);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Record a refusal and answer it. `$siteId` 0 is the installation.
     *
     * @return array{saved:bool, type:string, message:string}
     */
    private static function refusedAndRecorded(string $what, int $siteId, string $why, ?int $actorId): array
    {
        Logger::activity(
            'PublicWebsiteSetupRefused',
            'Refused: a change to ' . ($siteId > 0 ? 'organisation #' . $siteId . '\'s ' : 'the ') . $what . '. ' . $why,
            $actorId
        );

        return ['saved' => false, 'type' => 'danger', 'message' => $why];
    }

    private static function rollbackQuietly(\mysqli $db): void
    {
        try {
            $db->rollback();
        } catch (\Throwable $ignored) {
            // Nothing more can be done; the caller reports the failure.
        }
    }
}
