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
 *      nothing more. Since #493 P4a it says whether the public door is on
 *      and gives its addresses, but it does NOT fetch the door, so whether a
 *      website really shows it is ALWAYS "not tested" — never a tick. (P4d
 *      adds the fetch that confirms a public address reaches this door.)
 *   5. THE PUBLIC DOOR'S NAME (#493 P4a, D-097): whether the door is on, its
 *      random public name, and the three writers that turn it on, replace
 *      its name and switch it off (`turnDoorOn()`, `replaceDoorName()`,
 *      `turnDoorOff()`), each recorded. The two look-ups start-up makes
 *      before any session is looked at (`siteForDoorName()`,
 *      `publicHostRow()`), and D-087's reader for test copies
 *      (`testCopyDoorOpenToAnybody()`). The door itself — what it answers —
 *      is `Portal\Core\PublicDoor`.
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
 *   - It cannot yet tell whether a public address really points at THIS
 *     portal: the set-up check does not fetch the door in this version (P4d
 *     adds that). It reports only whether the name exists.
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
     * seeded). Read by `testCopyDoorOpenToAnybody()`; written by nothing in
     * this version (package P4d adds the card).
     */
    public const KEY_DOOR_TEST_PERMITTED = 'public.door.testCopies.permitted';
    public const KEY_DOOR_TEST_OPEN      = 'public.door.testCopies.open';

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
     * organisation is active, and whether the stored row is USABLE — exactly
     * what `checkPublicHost()` would accept, and not an address members sign
     * in at (a hand edit could put either there; a members' address must never
     * be turned into a door, or every member would be locked out).
     *
     * Throws on a database fault, except 1146 (no table yet: nothing can hold
     * a public address, so null is the true answer). See siteForDoorName().
     *
     * @return array{siteId:int, active:bool, usable:bool}|null
     */
    public static function publicHostRow(\mysqli $db, string $host): ?array
    {
        if ($host === '') {
            return null;
        }
        try {
            $stmt = $db->prepare(
                'SELECT h.`siteID`, h.`hostName`, s.`isActive` FROM `' . self::HOSTS_TABLE . '` h '
                . 'INNER JOIN `tblSites` s ON s.`siteID` = h.`siteID` WHERE h.`hostName` = ? LIMIT 1'
            );
        } catch (\mysqli_sql_exception $e) {
            if ($e->getCode() === 1146) {
                return null;
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

        return ['siteId' => (int) $row['siteID'], 'active' => (int) $row['isActive'] === 1, 'usable' => $usable];
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
     * Give the organisation's public door a NEW name. Every copy pasted into
     * another website with the old name stops at once, and the page warns so
     * before the button is pressed. Recorded with both names.
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
        Logger::activity('PublicDoorNameReplaced', 'Organisation #' . $siteId . '\'s public door name was replaced: '
            . ($now['name'] === '' ? '(a name the portal would not make)' : $now['name']) . ' → ' . $name
            . '. Every copy using the old name stopped working.', $actorId);

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
     * ⚠️ NOTHING WRITES EITHER SETTING YET. The card that does is package P4d.
     *    Until it exists no page can store an organisation's 'anybody'
     *    (Admin, Settings refuses to add an organisation's row for a key the
     *    installation already holds, and migration 263 seeds the installation's
     *    row), so in this version every test copy's door is for signed-in
     *    testers only. On the live portal nothing asks this at all.
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
                    ? $db->prepare('UPDATE `' . self::HOSTS_TABLE . '` SET `hostName` = ?, `changedAtUtc` = UTC_TIMESTAMP() WHERE `siteID` = ?')
                    : $db->prepare('INSERT INTO `' . self::HOSTS_TABLE . '` (`hostName`, `siteID`, `changedAtUtc`) VALUES (?, ?, UTC_TIMESTAMP())');
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
            : 'Saved. The public door answers on this address once whoever looks after your domain points it at this portal, '
                . 'and only while the public door is on.'];
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
     * ('note'), and whether a website really shows it is ALWAYS 'untested':
     * this check does not fetch the door (P4d adds that).
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
            $rows[] = ['label' => 'Public address', 'state' => 'untested',
                'words' => 'Whether it reaches THIS portal is not checked in this version. With the public door on, open https://'
                    . $public . '/events.json in your own browser: it should show your events.'];
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
        //    untested here, because this check does not fetch the door.
        if ($door['state'] === 'on') {
            $eventsAt  = PublicDoor::addressFor($db, $siteId, PublicDoor::SURFACE_EVENTS);
            $postersAt = PublicDoor::addressFor($db, $siteId, PublicDoor::SURFACE_POSTERS);
            $rows[] = ['label' => 'The public door', 'state' => 'note', 'words' => $eventsAt === ''
                ? 'Switched on, but the portal cannot build its addresses: the portal\'s own address is not set. Until it is, the door answers nothing.'
                : 'Switched on. Its events are read from ' . $eventsAt . ' and its posters from ' . $postersAt . '.'];
        } else {
            $rows[] = ['label' => 'The public door', 'state' => 'note', 'words' => $door['state'] === 'unreadable'
                ? 'Whether it is on could not be read.'
                : 'Switched off, so nothing the portal holds reaches any outside website.'];
        }
        $rows[] = ['label' => 'The public door', 'state' => 'untested', 'words' => 'Whether your website really shows it is not '
            . 'tested by this check, which does not fetch the door. Open its addresses in your own browser to see what a website gets.'];

        return $rows;
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
