<?php
// Path: _core/ReservedKeys.php
/**
 * -----------------------------------------------------------------------------
 * Reserved Organisation Keys 🔑 (#515)
 * -----------------------------------------------------------------------------
 * WHY THIS CLASS EXISTS
 * -------------------------------------------------------------------------
 * When several organisations share one installation, each one's pages can sit
 * behind its own "site key" in the address — for example /youth/calendar.
 * `web/_apps/admin/sites/save.php` used to check only that a key was
 * lower-case letters, digits and hyphens. It never asked whether that key was
 * the same as an address the PORTAL ITSELF already relies on.
 *
 * That is not a theoretical worry. It was reproduced on a real database on
 * 15 September 2026 during the #507 work: an organisation keyed "offline" on
 * a server that sends /offline/ through PHP takes over the portal's own
 * offline page. A visitor's browser can end up storing THAT organisation's
 * signed-in dashboard as the offline fallback, with the person's name on it,
 * and signing out cannot tell it apart from the real offline page to remove
 * it. `Auth.php` (`oldServiceWorkerWouldStore()`, `logout()`) describes the
 * whole case and points here for the fix. Measured again for this package,
 * in PATH mode: a key of `login` turns `/login` into a redirect loop six
 * hops deep and never resolves; `logout` stops `/logout` signing anyone out;
 * `api` makes every `/api/...` address answer 404 instead of reaching the
 * REST API. See the plan at `.claude-work/resume/p515--plan.md` §1.5 for the
 * full measured table.
 *
 * WHAT "RESERVED" MEANS HERE
 * -------------------------------------------------------------------------
 * A key is reserved when the portal already answers on an address that
 * begins with it, for ANY of three reasons — and this class is the single
 * place that says which. A hand-typed list would go stale the moment
 * somebody registered a new address and forgot to update it, so two of the
 * three sources are read LIVE, from the same things the portal itself
 * consults when it decides how to answer a request:
 *
 *   KIND_ROUTE   — the first part of every address in `tblRoutes`, read with
 *                  a live query. A migration that seeds a new address
 *                  reserves its first part the instant that migration runs —
 *                  nobody has to remember to touch this class.
 *   KIND_SPECIAL — the addresses `Router::handleSpecialRoutes()` answers
 *                  itself, WITHOUT ever consulting `tblRoutes` (login,
 *                  logout, the OAuth callbacks, the API prefix, health, the
 *                  short public-link prefixes, the GS1 resolver prefixes).
 *                  These cannot be read live — they are `$path === '...'`
 *                  and `str_starts_with($path, '...')` literals inside a
 *                  method body, not rows in a table — so they are typed
 *                  once into `Router::SPECIAL_ROUTE_FIRST_SEGMENTS` and nowhere
 *                  else. `tools/audit-checks/check_reserved_site_keys.py`
 *                  fails the build the moment that constant and the method
 *                  disagree in EITHER direction, so "typed once" does not
 *                  mean "can go stale unnoticed".
 *   KIND_WEBROOT — every real file or folder directly inside
 *                  `web/public_html/`, read live with `scandir()`. This is
 *                  the same rule `check_webroot_shadowing.py` exists for: a
 *                  real file or folder there answers a request before the
 *                  portal is ever asked, whatever `tblRoutes` says. A folder
 *                  dropped into the web root reserves its own name the
 *                  instant it exists.
 *
 * WHAT THIS CLASS CANNOT SEE
 * -------------------------------------------------------------------------
 * - The LIVE web root on the real server may hold files this repository does
 *   not (a customer's own upload, say). This class sees exactly what really
 *   exists at the moment it runs, so that is covered here — it is only the
 *   STATIC check (`check_reserved_site_keys.py`, which reads files from the
 *   repository rather than asking a running server) that cannot see it.
 * - The reverse also happens, and is safe: the static check parses SQL TEXT,
 *   so it still reserves an address a later migration deleted (`api`'s
 *   pre-#158 route rows, see the plan §1.3) — over-reserving is the safe
 *   direction, since the worst it does is refuse a key that would in fact
 *   be free.
 *
 * WHAT THE WORDING BELOW IS FOR
 * -------------------------------------------------------------------------
 * `describe()` builds the ONE sentence shown on the save page, the health
 * page, the admin dashboard and the organisations page — the same words in
 * all four places, so an administrator reading any one of them recognises
 * the same clash on the others.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/515
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

final class ReservedKeys
{
    /** The first part of a registered address, read live from tblRoutes. */
    public const KIND_ROUTE = 'route';

    /** The router answers this address itself — see Router::SPECIAL_ROUTE_FIRST_SEGMENTS. */
    public const KIND_SPECIAL = 'special';

    /** A real file or folder in web/public_html, read live with scandir(). */
    public const KIND_WEBROOT = 'webroot';

    /**
     * Every reserved name, lower-cased, mapped to which kind(s) reserve it.
     *
     * Kinds appear in the fixed order route, special, webroot (matching
     * describe()'s wording order) when more than one applies — for example
     * "offline" is both KIND_SPECIAL (the router handles /offline/ itself)
     * and KIND_WEBROOT (web/public_html/offline/ really exists on disk).
     *
     * @return array<string, list<string>> lower-cased key => kinds reserving it.
     *
     * @throws \RuntimeException when the routes query cannot be prepared or
     *                            run. Callers decide what "cannot check" means
     *                            for them — the save page treats it as a
     *                            reason to refuse the whole request rather
     *                            than let an unreserved-looking key through
     *                            unchecked (fail closed, not fail open).
     */
    public static function all(mysqli $db): array
    {
        $reserved = [];

        // 🗺️ KIND_ROUTE — every first path segment tblRoutes actually answers
        //    on, read live so a newly-seeded address is covered the moment
        //    its migration runs, with nothing here to update by hand.
        $stmt = $db->prepare("SELECT DISTINCT SUBSTRING_INDEX(routeKey, '/', 1) AS firstSegment FROM tblRoutes");
        if ($stmt === false) {
            throw new \RuntimeException('Could not prepare the reserved-keys route query: ' . $db->error);
        }
        if ($stmt->execute() === false) {
            $stmt->close();
            throw new \RuntimeException('Could not run the reserved-keys route query: ' . $db->error);
        }
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $key = strtolower((string) $row['firstSegment']);
            if ($key === '') {
                continue;
            }
            $reserved[$key][] = self::KIND_ROUTE;
        }
        $stmt->close();

        // 🚦 KIND_SPECIAL — addresses the router answers itself without ever
        //    consulting tblRoutes. This list cannot be read live (it lives as
        //    literal string comparisons inside a method body, not rows in a
        //    table), so it is typed once on Router and nowhere else — see
        //    that constant's own comment for how it is kept from going stale.
        foreach (Router::SPECIAL_ROUTE_FIRST_SEGMENTS as $segment) {
            $key = strtolower($segment);
            if ($key === '') {
                continue;
            }
            $reserved[$key][] = self::KIND_SPECIAL;
        }

        // 📁 KIND_WEBROOT — every real file or folder directly inside the web
        //    root, read live with scandir() so a folder dropped there reserves
        //    its own name the instant it exists. dirname(__DIR__) resolves to
        //    the web/ folder from _core/ (this file's own directory), the same
        //    reasoning Auth::offlinePageAnswered() already uses elsewhere in
        //    this codebase, rather than depending on a PORTAL_* bootstrap
        //    constant this class does not need for anything else.
        $webRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public_html';
        $entries = @scandir($webRoot);
        // 📝 A false here (folder missing or unreadable) is NOT an exception —
        //    the route and special sources above still hold, and a server
        //    whose web root cannot be listed has bigger problems than this
        //    class reporting fewer reserved names than it should. Reserving
        //    LESS than reality in that one failure case is the safe direction
        //    to fail in: it never refuses a key that should have been allowed.
        if (is_array($entries) === true) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.') === true) {
                    continue;
                }
                // 📝 A name with a dot (sw.js, robots.txt) can never be typed
                //    as a site key in the first place (save.php only accepts
                //    [a-z0-9-]), so including such names here changes nothing
                //    in practice — kept anyway because it keeps this rule
                //    simple ("everything really in the web root, lower-cased")
                //    rather than adding a second rule just to skip them.
                $key = strtolower($entry);
                $reserved[$key][] = self::KIND_WEBROOT;
            }
        }

        return $reserved;
    }

    /**
     * Which kinds reserve one particular key, lower-cased first.
     *
     * @return list<string> [] when the key is free.
     *
     * @throws \RuntimeException see all().
     */
    public static function kindsFor(mysqli $db, string $key): array
    {
        $all = self::all($db);
        return $all[strtolower($key)] ?? [];
    }

    /**
     * One plain-English sentence naming every reason $key is reserved — the
     * SAME wording everywhere this is shown: the save page's flash message,
     * the health probe's detail line, the admin-dashboard alert and the
     * organisations-page badge.
     *
     * @param list<string> $kinds From kindsFor() or a clashingSites() row —
     *                             any subset of KIND_ROUTE / KIND_SPECIAL /
     *                             KIND_WEBROOT, in any order (this method
     *                             always states them route, special, webroot).
     */
    public static function describe(string $key, array $kinds): string
    {
        $safeKey = strtolower($key);
        if ($kinds === []) {
            // 📝 Stated so this method can never be read as claiming more than
            //    it does — nothing in this package ever calls it this way
            //    (every caller only reaches describe() after finding at least
            //    one kind), but a future caller should not have to guess.
            return 'The site key "' . $safeKey . '" is not reserved.';
        }

        $reasons = [];
        if (in_array(self::KIND_ROUTE, $kinds, true) === true) {
            $reasons[] = 'the portal already has pages whose address begins /' . $safeKey . '/';
        }
        if (in_array(self::KIND_SPECIAL, $kinds, true) === true) {
            $reasons[] = 'the portal handles the address /' . $safeKey . '/ itself';
        }
        if (in_array(self::KIND_WEBROOT, $kinds, true) === true) {
            $reasons[] = 'a real file or folder called "' . $safeKey . '" exists in the web root, '
                . 'so the web server would answer /' . $safeKey . '/ before the portal could';
        }

        return 'The site key "' . $safeKey . '" is reserved: ' . implode('; also, ', $reasons) . '.';
    }

    /**
     * Every organisation (active or not) whose stored key is reserved today.
     *
     * @return list<array{siteID:int, siteName:string, siteKey:string, isActive:int, kinds:list<string>}>
     *         Ordered by siteID, for a stable display order.
     *
     * @throws \RuntimeException when the sites query cannot be prepared or
     *                            run, or when all() throws (propagated
     *                            unchanged) — every caller of this method
     *                            already wraps it in a try/catch, because a
     *                            warning that cannot currently be checked
     *                            must never crash the page reporting it.
     */
    public static function clashingSites(mysqli $db): array
    {
        $reserved = self::all($db);

        $stmt = $db->prepare('SELECT siteID, siteName, siteKey, isActive FROM tblSites ORDER BY siteID');
        if ($stmt === false) {
            throw new \RuntimeException('Could not prepare the clashing-sites query: ' . $db->error);
        }
        if ($stmt->execute() === false) {
            $stmt->close();
            throw new \RuntimeException('Could not run the clashing-sites query: ' . $db->error);
        }
        $result = $stmt->get_result();

        $clashes = [];
        while ($row = $result->fetch_assoc()) {
            $key = strtolower((string) $row['siteKey']);
            if (isset($reserved[$key]) === false) {
                continue;
            }
            $clashes[] = [
                'siteID'   => (int) $row['siteID'],
                'siteName' => (string) $row['siteName'],
                'siteKey'  => (string) $row['siteKey'],
                // 🔢 Arrives from this prepared statement as the whole NUMBER
                //    1 or 0, never the text '1' — the same trap issue #497
                //    describes for isProtected. Cast with (int), never
                //    compared with === '1'.
                'isActive' => (int) $row['isActive'],
                'kinds'    => $reserved[$key],
            ];
        }
        $stmt->close();

        return $clashes;
    }

    /**
     * Are organisation keys actually part of the ADDRESS right now?
     *
     * True only when multi-site is switched on AND the detection mode is
     * 'path' — the one mode where Router::extractPath() strips a key off the
     * front of every request (Site::detectFromPath()). In 'session' or
     * 'subdomain' mode, or with multi-site off altogether, a reserved key
     * sitting in tblSites does no harm today: nothing ever reads it as part
     * of an address. That is why the health probe (2.5 in the plan) only
     * turns amber when this returns true AND a clashing organisation is
     * active — a light that goes amber for something that is harmless right
     * now is exactly the "crying wolf" this project's own rule warns against
     * (a check people learn to ignore protects nothing).
     */
    public static function keysAreAddresses(): bool
    {
        return Site::isMultisiteEnabled() === true && Site::detectionMode() === 'path';
    }
}
