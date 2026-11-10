<?php
// Path: _core/PublicDoor.php
/**
 * -----------------------------------------------------------------------------
 * The PUBLIC DOOR: what an organisation's own website may read 🚪🌐
 * -----------------------------------------------------------------------------
 * #493, package P4a (D-056, D-057, D-087, D-094, D-095, D-097, D-098). The ONE
 * gate through which an organisation's ticked events and noticeboard posters
 * reach websites the portal does not run. In this package it answers:
 *
 *   events.json       the organisation's events that are on the public website
 *                     and have not finished yet, as JSON
 *                     (`web/_core/data/public-door-events.schema.json`);
 *   posters.json      its posters on the public website, as JSON
 *                     (`web/_core/data/public-door-posters.schema.json`);
 *   media/{file}      a poster's uploaded picture or video, ONLY while a
 *                     poster on the public website uses it;
 *   door.js           (#493 P4b) the script an organisation pastes into its
 *                     own website, which reads the feeds beside it and draws
 *                     them as TEXT inside a shadow root
 *                     (`web/_core/data/public-door.js`, whose header says how).
 *                     The countdown of the old `widget/countdown.js` (#319)
 *                     lives on as its "next event" mode (D-084).
 *   page.html         (#493 P4c) a plain page an organisation's website can
 *                     show in a FRAME, for a website that cannot paste in a
 *                     script (D-056's fallback). Drawn here, on the server,
 *                     with NO JavaScript; `?show=events` (the default),
 *                     `posters` or `both`, and `limit` as the feeds take it
 *                     (default PAGE_DEFAULT_LIMIT). Only the websites the
 *                     organisation lists may frame it, and none while the
 *                     installation forbids framing (D-082, D-094):
 *                     `frame-ancestors` comes from
 *                     `PublicWebsiteSetup::frameAncestorsFor()` and nowhere
 *                     else. See pageHeaderLines() and renderPage().
 *   door-check.txt    (#493 P4d) ONLY on an organisation's own public
 *                     address, never on /w/{name}/: the answer the set-up
 *                     check ("Check my set-up") fetches to learn whether
 *                     that address really reaches THIS organisation's door
 *                     on THIS portal. Asked with `?n=` and 64 random hex
 *                     characters the check made for that one run, it
 *                     answers one line: CHECK_FORMAT and a proof
 *                     (checkProof()) — a keyed hash of the organisation's
 *                     number, the public address and that random value,
 *                     keyed by the door's own random name. See checkProof()
 *                     for what a matching answer does and does not prove,
 *                     and why it gives nothing away.
 *
 * WHY "page.html", AND NOT THE BARE /w/{name}/ (#493 P4c): one name that is
 * the SAME on both forms of address, so `addressFor()` builds it exactly as it
 * builds the others; and the empty address stays what it was in P4a — the
 * door's identical 404, on /w/{name}/ and on a public address's own "/" — so
 * no refusal P4a pinned changes meaning. ".html" says what comes back, as
 * ".json" and ".js" do beside it.
 *
 * They are reached at /w/{name}/… on the portal's own address, where {name}
 * is the organisation's random public name (`tblPublicDoorNames`), or at /…
 * on the organisation's own public address (`tblPublicHosts`, D-095). Which
 * organisation a request is for is decided by `Site::preDetect()` — the ONE
 * place that decides it — before any session is looked at; this class reads
 * ONLY `Site::doorSiteId()`, never `Site::id()`, so a refused request can
 * never fall back to organisation 1. Nothing else answers here.
 *
 * THE QUESTIONS, IN THIS ORDER (answer())
 * -----------------------------------------------------------------------------
 *   1. Is the request well formed? GET or HEAD only, one of the addresses
 *      above, and `limit` (feeds and the page) a whole number from 1 to 50.
 *      The page's `show` must be exactly one of PAGE_SHOWS. The script takes
 *      no parameters, and ignores a query string (websites add `?v=2` to make
 *      browsers fetch a fresh copy).
 *   2. Is the portal closed for maintenance or an upgrade
 *      (`Maintenance::isActive()`)? Then 503 with Retry-After, the same for
 *      every organisation. The one answer that is not a refusal.
 *   3. Whose door is it? `Site::doorSiteId()` must name an organisation, its
 *      door must be ON (D-097: no name row, no door), and on the /w/ form the
 *      portal's own address must be stored — otherwise the door would build
 *      its links from whatever host the visitor sent
 *      (`PortalAddress::visitorCanChooseHost()`), and an answer kept by a
 *      cache for a minute could point every reader at somebody else's server.
 *   4. Is the app switched on FOR THAT ORGANISATION
 *      (`AppRegistry::isEnabledForSite()`): events need Calendar and Events,
 *      posters and their media need the Noticeboard, and the script needs at
 *      least one of the two (with both off there is nothing it could draw).
 *      The page needs the app of EVERY part it is asked for: `show=both`
 *      with either app off is refused, rather than quietly drawing half, so
 *      a switched-off app is never shown and never silently dropped.
 *   5. D-087, on a test copy only: `PublicWebsiteSetup::testCopyDoorRule()`
 *      is the ONE answer (the channel from `Portal\Core\Channel`, the
 *      test-copy gate's own switch, the installation's limit and the
 *      organisation's choice). Unless it says the door is open to anybody
 *      there, only somebody the test-copy gate itself lets in
 *      (`Gatekeeper::mayUseTestCopy()`), found by a READ-ONLY look at the
 *      session (`Auth::peekSession()`). Since #493 P4d the limit and the
 *      choice are set on Admin → Public website (the installation's global
 *      administrator, then the organisation's); with neither set, a test
 *      copy's door is for signed-in testers only — the stricter answer.
 *      Every answer from a test copy's door says `noindex, nofollow,
 *      noarchive`, whichever is chosen.
 *   6. Read, only through the three readers here: events() asks
 *      `EventVisibility::where()` and `fullDetailSelect()` in website mode with
 *      `PublicWebsite::copiedRuleFor()`, then `redact()`; posters() and
 *      media() ask `PublicWebsite::posterWhere()`, on the ORGANISATION's own
 *      calendar date. The renderers are given arrays, never the connection
 *      (`tools/audit-checks/check_public_door_reads.py` holds both rules).
 *   7. Send the headers for that kind of answer, then the answer. The page's
 *      headers (pageHeaderLines()) are the only ones whose `frame-ancestors`
 *      is not 'none': it is `PublicWebsiteSetup::frameAncestorsFor()`, asked
 *      on every request, never built here.
 *
 * EVERY REFUSAL IS ONE IDENTICAL 404 (notFound()), whatever the cause — an
 * unknown, replaced or removed name, an inactive organisation, the door off,
 * an app off, D-087, a bad address or parameter, a wrong method, a database
 * fault, or media no public poster uses — so a refusal says nothing about
 * which organisations exist or what they hold. It carries
 * `Access-Control-Allow-Origin: *`, so a script on another website sees a 404
 * rather than a CORS error. A fault is written to PHP's error log, never to
 * the activity log: a database write per anonymous request would make
 * flooding the door cheap.
 *
 * WHAT IS NEVER SENT: database numbers; who created, edited, submitted or
 * moderated anything; a cancellation reason; capacity, registration or
 * attendance; telephone, e-mail, map position or what3words; host and partner
 * names; series, import and website-date columns; a poster's sort order; and
 * anything deleted, draft, cancelled, postponed, unticked, outside its dates,
 * members-only, finished, or another organisation's.
 *
 * WHAT IT CANNOT DO, said plainly
 * -----------------------------------------------------------------------------
 *   - Refusals at different questions take different times. That is accepted:
 *     names cannot be guessed (2^96 of them).
 *   - It cannot stop a browser or a cache in between keeping a live answer for
 *     the minute `max-age=60` allows, so a change can take that long to reach
 *     a website — an unticked poster's picture included.
 *   - `/noticeboard/media` itself is unchanged and still serves any upload to
 *     whoever holds its token (#363). Only the DOOR's media address follows
 *     the website tick.
 *   - Event pictures are not sent in this version.
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

final class PublicDoor
{
    /** The events feed's address, after the name (or the public address's host). */
    public const SURFACE_EVENTS = 'events.json';

    /** The posters feed's address. */
    public const SURFACE_POSTERS = 'posters.json';

    /** A poster's media, followed by its stored file name. */
    public const MEDIA_PREFIX = 'media/';

    /** The script a website pastes in (#493 P4b). */
    public const SURFACE_SCRIPT = 'door.js';

    /**
     * Where the script's source is kept: outside the web root, so the ONLY way
     * to reach it is through this gate (a copy under public_html/ would answer
     * on every address, door switched off or not).
     */
    public const SCRIPT_FILE = 'data' . DIRECTORY_SEPARATOR . 'public-door.js';

    /** The page a website shows in a frame (#493 P4c). See the file header for why this name. */
    public const SURFACE_PAGE = 'page.html';

    /**
     * What the page may be asked to show (`?show=`), the first being the
     * default when none is sent. "events" first because the script's own
     * default is events (P4b), so the two ways of pasting agree. Anything
     * else — another word, capitals, a list — is the door's 404.
     */
    public const PAGE_SHOWS = ['events', 'posters', 'both'];

    /**
     * How many items of EACH kind the page draws when `limit` is not sent: the
     * script's default (P4b), not the feeds' 20, because both draw a list a
     * visitor reads inside somebody else's page.
     */
    public const PAGE_DEFAULT_LIMIT = 10;

    /**
     * The set-up check's address (#493 P4d), on an organisation's own public
     * address ONLY. On /w/{name}/ it is the door's 404 like any unknown
     * address: the check is about whether a public address reaches the door,
     * and a name already names it.
     */
    public const SURFACE_CHECK = 'door-check.txt';

    /** The first words of the check's one-line answer, before the proof. */
    public const CHECK_FORMAT = 'webms-public-door-check 1';

    /**
     * The page's whole stylesheet. It is written into the page in ONE <style>
     * element and allowed by its HASH in the page's content rule
     * (pageCsp()), so no other style can run there — not one injected into a
     * field, not a style attribute. Change it freely: the hash is worked out
     * from this text on every request, so the two cannot disagree.
     *
     * Light and dark follow the visitor's own setting (`prefers-color-scheme`)
     * and `color-scheme` lets the browser draw scrollbars to match. The
     * background is transparent, so the frame takes the website's own
     * background where the colour schemes agree.
     */
    public const PAGE_CSS = ':root{color-scheme:light dark;--door-accent:#3b45b5;--door-text:#1b1f24;--door-muted:#57606a;'
        . '--door-line:#d0d7de;}'
        . '@media (prefers-color-scheme:dark){:root{--door-accent:#9aa3ff;--door-text:#e6edf3;--door-muted:#9da7b3;--door-line:#30363d;}}'
        . '*{box-sizing:border-box;}'
        . 'html,body{margin:0;padding:0;background:transparent;}'
        . 'body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--door-text);line-height:1.4;'
        . 'padding:.5rem 1rem;overflow-wrap:anywhere;}'
        . '.door__heading{font-size:1.1rem;margin:.75rem 0 .25rem;}'
        . '.door__list{list-style:none;margin:0;padding:0;}'
        . '.door__item{padding:.75rem 0;border-bottom:1px solid var(--door-line);}'
        . '.door__item:last-child{border-bottom:0;}'
        . 'p{margin:0 0 .25rem;}'
        . '.door__title{font-weight:700;font-size:1.05rem;}'
        . '.door__kicker{color:var(--door-accent);font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;}'
        . '.door__when,.door__where{color:var(--door-muted);font-size:.9rem;}'
        . '.door__about{font-size:.95rem;white-space:pre-line;}'
        . '.door__link a{color:var(--door-accent);}'
        . '.door__picture{display:block;max-width:100%;height:auto;border-radius:.4rem;margin-bottom:.5rem;}'
        . '.door__empty{color:var(--door-muted);}';

    /** English weekday names for a weekly poster, Sunday first (the feed's 0 = Sunday). */
    private const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    /** How many items a feed gives when `limit` is not sent. */
    public const DEFAULT_LIMIT = 20;

    /** The most a feed gives; a larger `limit` is refused, never shortened. */
    public const MAX_LIMIT = 50;

    /** The two feeds' format names and version, written into every answer. */
    public const EVENTS_FORMAT  = 'webms-public-events';
    public const POSTERS_FORMAT = 'webms-public-posters';
    public const FORMAT_VERSION = 1;

    /** The one refusal's body. */
    public const NOT_FOUND_BODY = "Not found.\n";

    /** The 503's body. */
    public const CLOSED_BODY = "Closed for maintenance. Please try again shortly.\n";

    /**
     * The media the door serves, by the stored file's ending, with the ONE
     * type each is sent as. A stored upload whose recorded type is not the
     * one listed for its ending is refused (it would be a hand edit).
     */
    public const MEDIA_TYPES = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
    ];

    /** Every feed and media answer's content rule: it is data, never a page. */
    public const DATA_CSP = "default-src 'none'; frame-ancestors 'none'; sandbox";

    /**
     * Headers start-up (and PHP's session start) may have queued that the
     * door replaces or must never send. `Set-Cookie` above all: a door
     * answer never sets a cookie.
     */
    private const REMOVED_HEADERS = [
        'Set-Cookie', 'X-Frame-Options', 'Cross-Origin-Opener-Policy', 'Cross-Origin-Resource-Policy',
        'Cross-Origin-Embedder-Policy', 'X-Robots-Tag', 'Referrer-Policy', 'Content-Security-Policy',
        'Cache-Control', 'Expires', 'Pragma', 'Vary', 'Last-Modified', 'Access-Control-Allow-Origin',
        'Access-Control-Allow-Credentials', 'Access-Control-Allow-Methods', 'X-Offline-Copy',
    ];

    // =========================================================================
    // 🚪 The gate
    // =========================================================================

    /**
     * Answer a door request and stop. Called by `web/public_html/index.php`
     * BEFORE `Auth::ensureSession()`, so no session is ever started, and in
     * place of the maintenance gate, the test-copy gate and `SiteGate`, which
     * it asks for itself (see the file header for the order).
     *
     * ANYTHING THAT THROWS is a refusal: the identical 404, with the fault in
     * PHP's error log. Nothing is sent before the whole answer is ready, so a
     * fault can never leave half an answer behind.
     */
    public static function answer(\mysqli $db): never
    {
        try {
            self::answerOrThrow($db);
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] PublicDoor: a request was refused because of a fault: ' . get_class($e) . ': '
                . $e->getMessage());
            self::notFound();
        }
        exit();
    }

    /** The questions, in order. Returns only by sending an answer; refusals end the request. */
    private static function answerOrThrow(\mysqli $db): void
    {
        // 1️⃣ The request.
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            self::notFound();
        }
        $surface = Site::doorSurface();
        $media   = '';
        $show    = '';
        if ($surface === self::SURFACE_EVENTS || $surface === self::SURFACE_POSTERS) {
            $limit = self::limitFrom($_GET['limit'] ?? null);
            if ($limit === null) {
                self::notFound();
            }
        } elseif ($surface === self::SURFACE_PAGE) {
            $limit = self::limitFrom($_GET['limit'] ?? null, self::PAGE_DEFAULT_LIMIT);
            $show  = self::pageShowFrom($_GET['show'] ?? null);
            if ($limit === null || $show === null) {
                self::notFound();
            }
        } elseif ($surface === self::SURFACE_SCRIPT) {
            $limit = 0;
        } elseif (str_starts_with($surface, self::MEDIA_PREFIX) === true) {
            $media = substr($surface, strlen(self::MEDIA_PREFIX));
            if (self::isMediaName($media) === false) {
                self::notFound();
            }
            $limit = 0;
        } elseif ($surface === self::SURFACE_CHECK) {
            // #493 P4d: the set-up check's address, on an organisation's own
            // PUBLIC ADDRESS only and with exactly one well-formed random
            // value. On /w/{name}/ it is an unknown address: the door's 404.
            if (Site::doorOnPublicHost() === false || self::isCheckNonce($_GET['n'] ?? null) === false) {
                self::notFound();
            }
            $limit = 0;
        } else {
            self::notFound();
        }

        // 2️⃣ Closed for maintenance or an upgrade: the same 503 for everybody.
        if (Maintenance::isActive() === true) {
            self::closed();
        }

        // 3️⃣ Whose door, and is it on?
        $siteId = Site::doorSiteId();
        if ($siteId === null) {
            self::notFound();
        }
        if (PublicWebsiteSetup::doorStateFor($db, $siteId)['state'] !== 'on') {
            self::notFound();
        }
        if (Site::doorOnPublicHost() === false && PortalAddress::visitorCanChooseHost() === true) {
            self::notFound();
        }

        // 4️⃣ The app, switched on for THIS organisation. The script needs
        //    either: it draws events or posters, and with both off it could
        //    draw neither, so it is refused like anything else.
        if ($surface === self::SURFACE_CHECK) {
            // The set-up check asks whether the ADDRESS reaches the door, not
            // what it shows, so no app is needed: the check says separately
            // what is on the public website today.
        } elseif ($surface === self::SURFACE_SCRIPT) {
            if (AppRegistry::isEnabledForSite('calendar', $siteId) === false
                && AppRegistry::isEnabledForSite('noticeboard', $siteId) === false) {
                self::notFound();
            }
        } elseif ($surface === self::SURFACE_PAGE) {
            // Every part asked for needs its own app: "both" needs both.
            if ($show !== 'posters' && AppRegistry::isEnabledForSite('calendar', $siteId) === false) {
                self::notFound();
            }
            if ($show !== 'events' && AppRegistry::isEnabledForSite('noticeboard', $siteId) === false) {
                self::notFound();
            }
        } else {
            $app = $surface === self::SURFACE_EVENTS ? 'calendar' : 'noticeboard';
            if (AppRegistry::isEnabledForSite($app, $siteId) === false) {
                self::notFound();
            }
        }

        // 5️⃣ D-087: a test copy's door is for signed-in testers only unless
        //    the installation permits AND the organisation chose "open to
        //    anybody" (#493 P4d). ONE answer, testCopyDoorRule(), which the
        //    card and the set-up check ask too, so the three cannot disagree.
        $rule        = PublicWebsiteSetup::testCopyDoorRule($db, $siteId);
        $testCopy    = $rule !== PublicWebsiteSetup::DOOR_TEST_LIVE;
        $testersOnly = $rule === PublicWebsiteSetup::DOOR_TEST_TESTERS;
        if ($testersOnly === true) {
            if (Gatekeeper::mayUseTestCopy($db, self::peekSignedInAccount(), PORTAL_ENV) === false) {
                self::notFound();
            }
        }

        // 6️⃣ Read, then 7️⃣ answer.
        if ($surface === self::SURFACE_CHECK) {
            // The proof is made for THIS organisation, THIS public address and
            // the value the check sent. The address is the one IN EFFECT —
            // the same answer Site::preDetect() resolved this request by —
            // and the name is read again here, so a name replaced a moment
            // ago gives the new name's proof.
            $checkHost = PublicWebsiteSetup::publicHostInEffect($db, $siteId);
            $checkName = PublicWebsiteSetup::doorStateFor($db, $siteId);
            if ($checkHost === '' || $checkName['state'] !== 'on') {
                self::notFound();
            }
            $line = self::CHECK_FORMAT . ' ' . self::checkProof($checkName['name'], $siteId, $checkHost, (string) $_GET['n']) . "\n";
            self::sendCheckHeaders();
            header('Content-Length: ' . (string) strlen($line));
            echo $line;
            exit();
        }
        if ($surface === self::SURFACE_PAGE) {
            // Read only the parts asked for, through the same readers as the
            // feeds, so the page can never show what a feed would not.
            $events  = $show === 'posters' ? null : self::events($db, $siteId, $limit);
            $posters = $show === 'events' ? null : self::posters($db, $siteId, $limit);
            $page    = self::renderPage($events, $posters);
            // frame-ancestors: the ONE place that decides it (D-082, D-094),
            // asked fresh for every answer. Never built here.
            self::sendPageHeaders(PublicWebsiteSetup::frameAncestorsFor($db, $siteId), $testCopy, $testersOnly);
            header('Content-Length: ' . (string) strlen($page));
            echo $page;
            exit();
        }
        if ($surface === self::SURFACE_SCRIPT) {
            $script = self::script();
            // `text/javascript` with nosniff: a browser runs it as a script
            // only because of this type, and never sniffs it as anything
            // else. The same headers as a feed otherwise (caching for a
            // minute, so switching the door off reaches websites within one).
            self::sendDataHeaders('text/javascript; charset=utf-8', $testCopy, $testersOnly);
            header('Content-Length: ' . (string) strlen($script));
            echo $script;
            exit();
        }
        if ($media !== '') {
            $file = self::media($db, $siteId, $media);
            if ($file === null) {
                self::notFound();
            }
            self::sendDataHeaders($file['type'], $testCopy, $testersOnly);
            header('Content-Length: ' . (string) $file['size']);
            readfile($file['path']);
            exit();
        }
        $body = $surface === self::SURFACE_EVENTS
            ? self::renderEvents(self::events($db, $siteId, $limit), self::nowUtc())
            : self::renderPosters(self::posters($db, $siteId, $limit), self::nowUtc());
        self::sendDataHeaders('application/json; charset=utf-8', $testCopy, $testersOnly);
        echo $body;
        exit();
    }

    /**
     * The `limit` a feed was asked for: the default when none was sent, a
     * whole number from 1 to MAX_LIMIT, or null — refused — for anything else
     * (a list, a sign, a leading zero, a decimal point, too large).
     */
    public static function limitFrom(mixed $raw, int $default = self::DEFAULT_LIMIT): ?int
    {
        if ($raw === null) {
            return $default;
        }
        if (is_string($raw) === false || preg_match('/^[1-9][0-9]{0,2}$/D', $raw) !== 1) {
            return null;
        }
        $n = (int) $raw;

        return $n <= self::MAX_LIMIT ? $n : null;
    }

    /**
     * What the page was asked to show: the first of PAGE_SHOWS when `show` is
     * not sent, exactly one of them, or null — refused — for anything else
     * (another word, capitals, an empty value, a list). Exact on purpose: a
     * tidied value would make two addresses mean one page, and a cache in
     * between would keep both.
     */
    public static function pageShowFrom(mixed $raw): ?string
    {
        if ($raw === null) {
            return self::PAGE_SHOWS[0];
        }
        if (is_string($raw) === false || in_array($raw, self::PAGE_SHOWS, true) === false) {
            return null;
        }

        return $raw;
    }

    /**
     * Is this the random value the set-up check sends (`?n=`): exactly 64
     * lower-case hex characters, as `bin2hex(random_bytes(32))` makes? A
     * list, capitals, or any other length is refused before anything else
     * is asked.
     */
    public static function isCheckNonce(mixed $raw): bool
    {
        return is_string($raw) === true && preg_match('/^[0-9a-f]{64}$/D', $raw) === 1;
    }

    /**
     * 🔏 The set-up check's PROOF (#493 P4d): an HMAC-SHA256 of the
     * organisation's number, its public address and the check's random
     * value, keyed by the door's random NAME (`tblPublicDoorNames`, 96 random
     * bits). Pure, so the check works out the same value to compare.
     *
     * WHAT A MATCHING ANSWER PROVES, AND WHAT IT DOES NOT:
     *   - the random value is new for every run of the check, so a copy of an
     *     earlier answer (a cache, a mirror, a parked domain replaying what it
     *     once saw) never matches;
     *   - the proof cannot be made without the door's CURRENT name, and
     *     another portal, or another organisation's door on this one, has a
     *     different name and number, so its answer is in the same FORM and
     *     never matches — which is how the check can say "a door answered,
     *     but not this one";
     *   - so a match means the answer came from something holding this
     *     organisation's current door name: this door, a server passing the
     *     request on to it (which really does reach the door, and cannot be
     *     told apart), or somebody who knows the name. The check's words
     *     claim exactly that — "a door holding your door's current name" —
     *     and never "only this door could make it" (round 1 of P4d's check).
     * The name is not a secret the way a password is — a copy pasted with
     * /w/{name}/ shows it — so somebody who knows it COULD answer with a
     * matching proof. That is no use to them: they would also have to control
     * the address being checked, and such a party can already pass the
     * request on to the real door. All they could gain is a false "it
     * answers as your door" about an address they ALREADY control — and an
     * organisation whose public address somebody else controls has lost it
     * already, whatever this check says.
     *
     * WHY IT GIVES NOTHING AWAY: the answer is a keyed hash, so it reveals
     * neither the name nor the number, and the address is part of what is
     * hashed, so two addresses cannot be matched up as one organisation's by
     * comparing answers. It is sent only on an organisation's public address
     * IN EFFECT, with its door on and its organisation active, and on a
     * testers-only test copy only to a tester. It does NOT ask whether
     * Calendar and Events or the Noticeboard is switched on (round 1 of P4d's
     * check: an earlier sentence here said it was sent only where the feeds
     * answer, which was untrue): it says whether the ADDRESS reaches the
     * door, and nothing about what is shown there.
     */
    public static function checkProof(string $doorName, int $siteId, string $host, string $nonce): string
    {
        return hash_hmac('sha256', "webms-public-door-check-v1\n" . $siteId . "\n" . $host . "\n" . $nonce, $doorName);
    }

    /** Is this a stored upload's name as the noticeboard makes them: 32 hex characters and a known ending? */
    public static function isMediaName(string $name): bool
    {
        if (preg_match('/^[a-f0-9]{32}\.([a-z0-9]+)$/D', $name, $m) !== 1) {
            return false;
        }

        return array_key_exists($m[1], self::MEDIA_TYPES) === true;
    }

    /**
     * Who is signed in, by a READ-ONLY look at the session (D-087's tester
     * test), or 0. It never makes a session file: with PHP's usual "files"
     * store, a cookie naming a session that has no file is answered "nobody"
     * WITHOUT starting PHP's session at all — PHP's strict mode would
     * otherwise mint a new, empty session file for a made-up cookie. For any
     * other store this guard cannot see, and `Auth::peekSession()` is asked
     * as it is; whatever it queues (a cookie, cache headers) is removed
     * before the answer is sent.
     */
    private static function peekSignedInAccount(): int
    {
        $cookie = $_COOKIE[session_name()] ?? null;
        if (is_string($cookie) === false || preg_match('/^[A-Za-z0-9,\-]{16,256}$/D', $cookie) !== 1) {
            return 0;
        }
        if (ini_get('session.save_handler') === 'files') {
            $path = (string) session_save_path();
            $semi = strrpos($path, ';');
            $path = $semi === false ? $path : substr($path, $semi + 1);
            $path = $path === '' ? sys_get_temp_dir() : $path;
            $file = $path . DIRECTORY_SEPARATOR . 'sess_' . $cookie;
            if (is_file($file) === false) {
                return 0;
            }
            // ⏳ EXPIRED is nobody. The portal sets no idle limit of its own:
            //    a session ends when PHP's clear-out deletes a file untouched
            //    for `session.gc_maxlifetime` seconds. That clear-out runs only
            //    now and then, so a stale file can outlive its lifetime; the
            //    door does not honour one (round 1 of P4a's check), which is
            //    the stricter reading of the same expiry.
            $lifetime = (int) ini_get('session.gc_maxlifetime');
            $touched  = filemtime($file);
            if ($lifetime > 0 && ($touched === false || $touched + $lifetime < time())) {
                return 0;
            }
        }

        return Auth::peekSession()['userId'];
    }

    // =========================================================================
    // 📖 The three readers — the ONLY database reads in this file
    // =========================================================================

    /**
     * The organisation's events on the public website that have not finished
     * yet on their OWN clock (`Events::nowOnItsOwnClock()`), soonest first,
     * at most `$limit`. An ALL-DAY event lasts until the end of its last day
     * on that clock — its stored times are only the day's midnight, so
     * judged by them it vanished at the START of its own day (round 1 of
     * P4a's check). The arithmetic is on the clock readings themselves, so it
     * needs no zone. It reads in website mode, with the copied-in rule in force for the
     * organisation; published only; full detail only where the rule allows it,
     * cut with `EventVisibility::redact()` otherwise.
     *
     * @return array{organisation:array{name:string, timezone:string}, events:list<array<string,mixed>>}
     */
    public static function events(\mysqli $db, int $siteId, int $limit): array
    {
        $org   = self::organisationFacts($db, $siteId);
        $today = self::organisationToday($siteId);
        $vis   = EventVisibility::where('e', EventVisibility::MODE_WEBSITE, 0, $today, PublicWebsite::copiedRuleFor($db, $siteId));
        $full  = EventVisibility::fullDetailSelect('e', EventVisibility::MODE_WEBSITE, 0, $today, 'canSeeFull');
        $now   = Events::nowOnItsOwnClock($db, 'e', $siteId);
        $stmt  = $db->prepare(
            'SELECT e.`eventSlug`, e.`eventName`, e.`description`, e.`startDateTime`, e.`endDateTime`, e.`timezone`, '
            . 'e.`isAllDay`, e.`locationName`, e.`locationAddress`, e.`locationWebURL`, ' . $full['sql'] . ' '
            . 'FROM `tblEvents` e WHERE e.`siteID` = ? AND e.`isDeleted` = 0 AND e.`status` = \'published\' '
            . 'AND (CASE WHEN e.`isAllDay` = 1 THEN DATE_ADD(DATE(COALESCE(e.`endDateTime`, e.`startDateTime`)), INTERVAL 1 DAY) - INTERVAL 1 SECOND '
            . 'ELSE COALESCE(e.`endDateTime`, e.`startDateTime`) END) >= ' . $now['sql']
            . $vis['sql'] . ' ORDER BY e.`startDateTime` ASC, e.`eventID` ASC LIMIT ?'
        );
        $stmt->bind_param(
            $full['types'] . 'i' . $now['types'] . $vis['types'] . 'i',
            ...array_merge($full['params'], [$siteId], $now['params'], $vis['params'], [$limit])
        );
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $events = [];
        foreach ($rows as $row) {
            $row  = EventVisibility::redact($row, (int) $row['canSeeFull'] === 1);
            $zone = Site::zoneFor((string) $row['timezone'], $siteId);
            $events[] = [
                'id'             => (string) $row['eventSlug'],
                'title'          => (string) $row['eventName'],
                'startsAt'       => self::instantText($row['startDateTime'], $zone),
                'endsAt'         => self::instantText($row['endDateTime'], $zone),
                // #493 P4b: the readings AS STORED, for showing. On the morning
                // a clock goes forward a reading inside the missing hour is
                // still the time the organisation typed (#777: a clock reading
                // is not a moment), while startsAt — an instant — has had to
                // move past the gap (I18n::wallClockInstant()). A reader that
                // SHOWS a time uses these, so it agrees with the portal's own
                // pages; one that counts down uses startsAt.
                'startsOnClock'  => self::clockText($row['startDateTime'], $zone),
                'endsOnClock'    => self::clockText($row['endDateTime'], $zone),
                'allDay'         => (int) $row['isAllDay'] === 1,
                'timezone'       => $zone->getName(),
                'detailsLimited' => $row['detailsLimited'] === true,
                'description'    => $row['detailsLimited'] === true ? null : self::textOrNull($row['description'] ?? null),
                'location'       => $row['detailsLimited'] === true ? null : self::locationFrom($row),
            ];
        }

        return ['organisation' => $org, 'events' => $events];
    }

    /**
     * The organisation's posters on the public website TODAY on its own
     * calendar (`PublicWebsite::posterWhere()`), in the board's own order, at
     * most `$limit`. A poster's uploaded media is pointed at the door's own
     * media address, and only when the upload is this organisation's.
     *
     * @return array{organisation:array{name:string, timezone:string}, posters:list<array<string,mixed>>}
     */
    public static function posters(\mysqli $db, int $siteId, int $limit): array
    {
        $org  = self::organisationFacts($db, $siteId);
        $pw   = PublicWebsite::posterWhere('p', self::organisationToday($siteId));
        $stmt = $db->prepare(
            'SELECT p.`title`, p.`kicker`, p.`category`, p.`scheduleType`, p.`eventDate`, p.`weekday`, p.`eventTime`, '
            . 'p.`location`, p.`link`, p.`mediaType`, p.`mediaUrl`, p.`canvaUrl`, p.`thumbUrl` '
            . 'FROM `tblNoticeboardPosters` p WHERE p.`siteID` = ?' . $pw['sql']
            . ' ORDER BY p.`sortOrder` ASC, p.`posterID` ASC LIMIT ?'
        );
        $stmt->bind_param('i' . $pw['types'] . 'i', ...array_merge([$siteId], $pw['params'], [$limit]));
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Which of the uploads these posters name are THIS organisation's.
        $named = [];
        foreach ($rows as $row) {
            foreach ([(string) $row['mediaUrl'], (string) $row['thumbUrl']] as $url) {
                $stored = NoticeboardMedia::extractStoredName($url);
                if ($stored !== null) {
                    $named[$stored] = true;
                }
            }
        }
        $own = [];
        if ($named !== []) {
            $marks = implode(',', array_fill(0, count($named), '?'));
            $up    = $db->prepare('SELECT `storedName` FROM `tblNoticeboardUploads` WHERE `siteID` = ? AND `storedName` IN (' . $marks . ')');
            $up->bind_param('i' . str_repeat('s', count($named)), ...array_merge([$siteId], array_keys($named)));
            $up->execute();
            foreach ($up->get_result()->fetch_all(MYSQLI_ASSOC) as $one) {
                $own[(string) $one['storedName']] = true;
            }
            $up->close();
        }
        // The door's own media address, worked out once: '' when none can be built.
        $mediaBase    = self::addressFor($db, $siteId, self::MEDIA_PREFIX);
        $mediaAddress = static function (string $url) use ($mediaBase, $own): ?string {
            $stored = NoticeboardMedia::extractStoredName($url);
            if ($stored !== null) {
                if (isset($own[$stored]) === false || self::isMediaName($stored) === false || $mediaBase === '') {
                    return null;
                }

                return $mediaBase . $stored;
            }

            return self::httpsOrNull($url);
        };

        $posters = [];
        foreach ($rows as $row) {
            $type  = (string) $row['mediaType'];
            $media = null;
            if ($type === 'image' || $type === 'video') {
                $url = $mediaAddress((string) $row['mediaUrl']);
                $media = $url === null ? null : ['type' => $type, 'url' => $url];
            }
            $canva = $type === 'canva' ? self::httpsOrNull((string) $row['canvaUrl']) : null;
            if ($canva !== null && str_starts_with($canva, 'https://www.canva.com/') === false) {
                $canva = null;
            }
            $posters[] = [
                'title'    => (string) $row['title'],
                'kicker'   => self::textOrNull($row['kicker']),
                'category' => self::textOrNull($row['category']),
                'when'     => self::posterWhen($row),
                'time'     => self::clockOrNull($row['eventTime']),
                'location' => self::textOrNull($row['location']),
                'link'     => self::httpsOrNull((string) $row['link']),
                'media'    => $media,
                'thumbUrl' => trim((string) $row['thumbUrl']) === '' ? null : $mediaAddress((string) $row['thumbUrl']),
                'canvaUrl' => $canva,
            ];
        }

        return ['organisation' => $org, 'posters' => $posters];
    }

    /**
     * One poster upload, for the door's media address: only when the upload
     * is THIS organisation's AND a poster on the public website right now
     * uses it (as its picture or video, or as its thumbnail). The moment the
     * poster is unticked, deleted or outside its dates, this answers null.
     *
     * @return array{path:string, type:string, size:int}|null
     */
    public static function media(\mysqli $db, int $siteId, string $stored): ?array
    {
        if (self::isMediaName($stored) === false) {
            return null;
        }
        $stmt = $db->prepare('SELECT `mimeType` FROM `tblNoticeboardUploads` WHERE `siteID` = ? AND `storedName` = ? LIMIT 1');
        $stmt->bind_param('is', $siteId, $stored);
        $stmt->execute();
        $upload = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $type = self::MEDIA_TYPES[(string) pathinfo($stored, PATHINFO_EXTENSION)];
        if ($upload === null || (string) $upload['mimeType'] !== $type) {
            return null;
        }

        $pw   = PublicWebsite::posterWhere('p', self::organisationToday($siteId));
        $like = '%' . $stored;
        $stmt = $db->prepare(
            'SELECT p.`mediaType`, p.`mediaUrl`, p.`thumbUrl` FROM `tblNoticeboardPosters` p WHERE p.`siteID` = ?' . $pw['sql']
            . ' AND (p.`mediaUrl` LIKE ? OR p.`thumbUrl` LIKE ?)'
        );
        $stmt->bind_param('i' . $pw['types'] . 'ss', ...array_merge([$siteId], $pw['params'], [$like, $like]));
        $stmt->execute();
        $posters = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $used = false;
        foreach ($posters as $poster) {
            $asMedia = in_array((string) $poster['mediaType'], ['image', 'video'], true) === true
                && NoticeboardMedia::extractStoredName((string) $poster['mediaUrl']) === $stored;
            if ($asMedia === true || NoticeboardMedia::extractStoredName((string) $poster['thumbUrl']) === $stored) {
                $used = true;
                break;
            }
        }
        if ($used === false) {
            return null;
        }

        $path = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'noticeboard' . DIRECTORY_SEPARATOR . basename($stored);
        if (is_file($path) === false || is_readable($path) === false) {
            return null;
        }

        return ['path' => $path, 'type' => $type, 'size' => (int) filesize($path)];
    }

    /**
     * The script's source, exactly as kept on disk (`SCRIPT_FILE`). The same
     * bytes for every organisation: it finds its feed from its own address,
     * so nothing about an organisation is written into it. A file that cannot
     * be read is a fault, and so the door's one 404.
     */
    public static function script(): string
    {
        $text = @file_get_contents(PORTAL_CORE . DIRECTORY_SEPARATOR . self::SCRIPT_FILE);
        if ($text === false || $text === '') {
            throw new \RuntimeException('the public door\'s script could not be read');
        }

        return $text;
    }

    /**
     * The organisation's name and zone, for the feeds' heading. Read by the
     * readers only.
     *
     * ⚠️ IT DOES NOT ASK WHETHER THE ORGANISATION IS ACTIVE, on purpose. That
     *    is decided ONCE, in `Site::preDetect()` (the name and the public
     *    address both look up active organisations only). A second copy here
     *    was tried and taken out (P4a's own test): it made the real guard
     *    impossible to test, because taking either one out left the other
     *    standing, so neither could be shown to matter — and a guard that no
     *    test can fail is how a guard quietly disappears.
     */
    private static function organisationFacts(\mysqli $db, int $siteId): array
    {
        $stmt = $db->prepare('SELECT `siteName` FROM `tblSites` WHERE `siteID` = ? LIMIT 1');
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            throw new \RuntimeException('the organisation does not exist');
        }

        return ['name' => (string) $row['siteName'], 'timezone' => Site::timezone($siteId)];
    }

    // =========================================================================
    // 🖨️ The renderers — given arrays, never the connection
    // =========================================================================

    /**
     * The events feed, as JSON. Pure: what it is given is what it writes.
     *
     * @param array{organisation:array{name:string, timezone:string}, events:list<array<string,mixed>>} $read
     */
    public static function renderEvents(array $read, string $generatedAt): string
    {
        return self::json([
            'format'       => self::EVENTS_FORMAT,
            'version'      => self::FORMAT_VERSION,
            'organisation' => $read['organisation'],
            'generatedAt'  => $generatedAt,
            'events'       => $read['events'],
        ]);
    }

    /**
     * The posters feed, as JSON. Pure.
     *
     * @param array{organisation:array{name:string, timezone:string}, posters:list<array<string,mixed>>} $read
     */
    public static function renderPosters(array $read, string $generatedAt): string
    {
        return self::json([
            'format'       => self::POSTERS_FORMAT,
            'version'      => self::FORMAT_VERSION,
            'organisation' => $read['organisation'],
            'generatedAt'  => $generatedAt,
            'posters'      => $read['posters'],
        ]);
    }

    /**
     * 🖼️ The page a website frames (#493 P4c), as HTML. Pure: given what the
     * readers answered (null for a part not asked for), it writes the page.
     *
     * WHAT IT PROMISES, and how:
     *   - NO JAVASCRIPT. It writes no <script>, no event handler and no
     *     `javascript:` address, and its content rule (pageCsp()) allows no
     *     script at all, so even a field that somehow carried one could not
     *     run it.
     *   - EVERY VALUE ESCAPED, through text() and nothing else — titles,
     *     descriptions, places, addresses, kickers, times, zone labels and
     *     the organisation's name in the <title> alike. A title such as
     *     `<img onerror=…>` shows as those characters.
     *   - LINKS AND PICTURES ONLY TO https:// (httpsOrNull(), asked again
     *     here even though the readers asked it, because the door's own media
     *     address is only as https as the portal's address). A link opens in
     *     a new tab with no referrer, so the frame never navigates and the
     *     other website is told nothing.
     *   - EACH EVENT'S TIME ON ITS OWN CLOCK: the reading as stored
     *     (`startsOnClock`), written by `I18n::formatClockDateTime()` — the
     *     ONE place a wall-clock reading becomes words, which builds no
     *     moment (#777) — so a visitor anywhere sees the time where the event
     *     happens, and an event stored at 01:30 on the morning the clocks go
     *     forward shows 01:30. Where the event's clock is not the
     *     organisation's, its place is named ("New York time"), as the
     *     script does.
     *
     * Its words are English, as the script's are (#899).
     *
     * @param array{organisation:array{name:string, timezone:string}, events:list<array<string,mixed>>}|null  $events
     * @param array{organisation:array{name:string, timezone:string}, posters:list<array<string,mixed>>}|null $posters
     */
    public static function renderPage(?array $events, ?array $posters): string
    {
        $org      = ($events ?? $posters ?? ['organisation' => ['name' => '', 'timezone' => '']])['organisation'];
        $both     = $events !== null && $posters !== null;
        $what     = $both === true ? 'events and noticeboard' : ($events !== null ? 'events' : 'noticeboard');
        $orgName  = trim((string) ($org['name'] ?? ''));
        $title    = ($orgName === '' ? '' : $orgName . ' — ') . $what;
        $orgClock = (string) ($org['timezone'] ?? '');

        $out = "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . "<meta name=\"referrer\" content=\"no-referrer\">\n"
            . '<title>' . self::text($title) . "</title>\n"
            . '<style>' . self::PAGE_CSS . "</style>\n</head>\n<body>\n<main class=\"door\">\n";
        if ($events !== null) {
            $out .= self::pageEvents($events['events'], $orgClock, $both);
        }
        if ($posters !== null) {
            $out .= self::pagePosters($posters['posters'], $both);
        }

        return $out . "</main>\n</body>\n</html>\n";
    }

    /** The events part of the page. */
    private static function pageEvents(array $events, string $orgClock, bool $heading): string
    {
        $out = "<section class=\"door__part\" data-door-part=\"events\">\n"
            . ($heading === true ? "<h2 class=\"door__heading\">Events</h2>\n" : '');
        if ($events === []) {
            return $out . "<p class=\"door__empty\">No upcoming events.</p>\n</section>\n";
        }
        $out .= "<ul class=\"door__list\">\n";
        foreach ($events as $ev) {
            $out .= "<li class=\"door__item\">\n<p class=\"door__title\">" . self::text($ev['title'] ?? '') . "</p>\n";
            $when = self::eventWhen($ev, $orgClock);
            if ($when !== '') {
                $out .= '<p class="door__when">' . self::text($when) . "</p>\n";
            }
            $place = is_array($ev['location'] ?? null) === true ? $ev['location'] : null;
            if ($place !== null) {
                $where = implode(', ', array_values(array_filter([trim((string) ($place['name'] ?? '')), trim((string) ($place['address'] ?? ''))],
                    static fn (string $one): bool => $one !== '')));
                if ($where !== '') {
                    $out .= '<p class="door__where">' . self::text($where) . "</p>\n";
                }
                $out .= self::pageLink($place['url'] ?? null, 'Venue details');
            }
            $about = trim((string) ($ev['description'] ?? ''));
            if ($about !== '') {
                $out .= '<p class="door__about">' . self::text($about) . "</p>\n";
            }
            $out .= "</li>\n";
        }

        return $out . "</ul>\n</section>\n";
    }

    /** The posters part of the page. */
    private static function pagePosters(array $posters, bool $heading): string
    {
        $out = "<section class=\"door__part\" data-door-part=\"posters\">\n"
            . ($heading === true ? "<h2 class=\"door__heading\">Noticeboard</h2>\n" : '');
        if ($posters === []) {
            return $out . "<p class=\"door__empty\">Nothing on the noticeboard just now.</p>\n</section>\n";
        }
        $out .= "<ul class=\"door__list\">\n";
        foreach ($posters as $p) {
            $out .= "<li class=\"door__item\">\n";
            $media   = is_array($p['media'] ?? null) === true ? $p['media'] : null;
            $picture = $p['thumbUrl'] ?? (($media !== null && ($media['type'] ?? '') === 'image') ? ($media['url'] ?? null) : null);
            $src     = is_string($picture) === true ? self::httpsOrNull($picture) : null;
            if ($src !== null) {
                $out .= '<img class="door__picture" src="' . self::text($src) . '" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">' . "\n";
            }
            $kicker = trim((string) ($p['kicker'] ?? ''));
            if ($kicker !== '') {
                $out .= '<p class="door__kicker">' . self::text($kicker) . "</p>\n";
            }
            $out .= '<p class="door__title">' . self::text($p['title'] ?? '') . "</p>\n";
            $posterWhen = self::posterWhenWords($p);
            if ($posterWhen !== '') {
                $out .= '<p class="door__when">' . self::text($posterWhen) . "</p>\n";
            }
            $place = trim((string) ($p['location'] ?? ''));
            if ($place !== '') {
                $out .= '<p class="door__where">' . self::text($place) . "</p>\n";
            }
            $out .= self::pageLink($p['link'] ?? null, 'Find out more') . "</li>\n";
        }

        return $out . "</ul>\n</section>\n";
    }

    /**
     * An event's day and time as words, on ITS OWN clock: the stored reading
     * (`startsOnClock`), never the instant. An all-day event gives its day
     * only. Its place is named when its clock is not the organisation's.
     */
    private static function eventWhen(array $ev, string $orgClock): string
    {
        $reading = $ev['startsOnClock'] ?? null;
        if (is_string($reading) === false || I18n::wallClockReading($reading) === null) {
            return '';
        }
        $words = I18n::formatClockDateTime($reading, ($ev['allDay'] ?? false) === true ? 'D j M Y' : 'D j M Y, H:i');
        $zone  = trim((string) ($ev['timezone'] ?? ''));
        if ($zone !== '' && $orgClock !== '' && $zone !== $orgClock) {
            // "America/New_York" becomes "New York time": the place, as a person says it.
            $parts  = explode('/', $zone);
            $words .= ' (' . str_replace('_', ' ', (string) end($parts)) . ' time)';
        }

        return $words;
    }

    /** A poster's day ("Every Tuesday", or a date) and time, as words. */
    private static function posterWhenWords(array $p): string
    {
        $when  = is_array($p['when'] ?? null) === true ? $p['when'] : null;
        $words = '';
        if ($when !== null && ($when['type'] ?? '') === 'weekly' && is_int($when['weekday'] ?? null) === true
            && isset(self::WEEKDAYS[$when['weekday']]) === true) {
            $words = 'Every ' . self::WEEKDAYS[$when['weekday']];
        } elseif ($when !== null && ($when['type'] ?? '') === 'once' && is_string($when['date'] ?? null) === true
            && I18n::wallClockReading($when['date'] . ' 00:00') !== null) {
            $words = I18n::formatClockDateTime($when['date'] . ' 00:00', 'D j M Y');
        }
        $time = trim((string) ($p['time'] ?? ''));
        if ($time !== '') {
            $words = $words === '' ? $time : $words . ', ' . $time;
        }

        return $words;
    }

    /** A link, only to an https:// address, opened in a new tab and telling the other side nothing — or ''. */
    private static function pageLink(mixed $address, string $words): string
    {
        $safe = is_string($address) === true ? self::httpsOrNull($address) : null;
        if ($safe === null) {
            return '';
        }

        return '<p class="door__link"><a href="' . self::text($safe) . '" target="_blank" rel="noopener noreferrer nofollow" '
            . 'referrerpolicy="no-referrer">' . self::text($words) . "</a></p>\n";
    }

    /** Text for the page: every value the page writes goes through here. */
    private static function text(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    // =========================================================================
    // 🔗 Addresses
    // =========================================================================

    /**
     * 🔗 The whole address of one of the organisation's door addresses —
     * THE ONE PLACE they are built:
     *   - on the organisation's public address when it has one AND the
     *     installation's global administrator has approved it (D-095, D-101):
     *     https://events.example.org/events.json;
     *   - otherwise the portal's own address for the organisation
     *     (`PortalAddress::base()`) plus /w/{name}/: never `Site::url()`,
     *     which would add the members' path part, and never the request's
     *     host.
     * '' when the door is off, or no address can be built without trusting a
     * host the visitor chose — and then the door answers nothing on /w/.
     *
     * @param string $what 'events.json', 'posters.json', 'door.js', 'page.html' or 'media/{file}'.
     */
    public static function addressFor(\mysqli $db, int $siteId, string $what): string
    {
        $door = PublicWebsiteSetup::doorStateFor($db, $siteId);
        if ($door['state'] !== 'on') {
            return '';
        }
        // D-101: only the public address IN EFFECT (approved, and not an
        // address members sign in at) is the door, so only that one is
        // written into the door's own addresses.
        $host = PublicWebsiteSetup::publicHostInEffect($db, $siteId);
        if ($host !== '') {
            return 'https://' . $host . '/' . $what;
        }

        return self::nameAddressFor($db, $siteId, $what);
    }

    /**
     * 🔗 The door's address on the PORTAL'S OWN address — /w/{name}/… —
     * whether or not a public address is in effect, or '' when the door is
     * off or no such address can be built without trusting a host the visitor
     * chose. `addressFor()` uses it when there is no public address.
     *
     * WHY IT IS ASKED ON ITS OWN (#493 P4d, round 1 of its check): on a test
     * copy whose door answers signed-in testers only, this is the ONLY form a
     * tester can use. A browser sends the portal's sign-in only to the
     * address it signed in at (the session cookie names no domain), and a
     * public address answers the door alone (D-098), so nobody is ever signed
     * in there: a public address answers NOBODY on such a test copy. The card
     * and the set-up check name this address in that state, never the public
     * one.
     */
    public static function nameAddressFor(\mysqli $db, int $siteId, string $what): string
    {
        $door = PublicWebsiteSetup::doorStateFor($db, $siteId);
        if ($door['state'] !== 'on' || PortalAddress::visitorCanChooseHost() === true) {
            return '';
        }
        $base = PortalAddress::base($siteId);
        if ($base === '') {
            return '';
        }

        return $base . '/w/' . $door['name'] . '/' . $what;
    }

    /**
     * Why `addressFor()` cannot build an address for this organisation, in
     * words for the card and the set-up check — '' when it can. The ONE
     * place this is said, because the first version blamed the portal's own
     * address in every case, which is untrue in "own address each" mode
     * (round 1 of P4a's check): there the portal's address can be set and
     * this organisation still have no address of its own.
     */
    public static function whyNoAddress(\mysqli $db, int $siteId): string
    {
        if (PublicWebsiteSetup::doorStateFor($db, $siteId)['state'] !== 'on'
            || PublicWebsiteSetup::publicHostInEffect($db, $siteId) !== '') {
            return '';
        }
        if (PortalAddress::state() === PortalAddress::STATE_UNREADABLE) {
            return 'The portal\'s own address could not be read, so the door answers nothing on it yet.';
        }
        if (PortalAddress::visitorCanChooseHost() === true) {
            return 'The portal\'s own address is not set, so the door answers nothing on it yet. A global administrator sets it '
                . 'on Admin, Settings, Organisation (or give this organisation a public address below).';
        }
        if (PortalAddress::base($siteId) === '') {
            return 'This installation gives each organisation its own address, and this organisation has none, so the door '
                . 'answers nothing yet. A global administrator gives it one on Admin, Organisations (or give it a public '
                . 'address below).';
        }

        return '';
    }

    // =========================================================================
    // 📤 Answers and headers
    // =========================================================================

    /**
     * ⛔ The ONE refusal: identical for every cause, so it says nothing. Also
     * used by the router for a /w/ address that reached it (a path-mode
     * prefix in front, for example).
     */
    public static function notFound(): never
    {
        self::clearHeaders();
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('Access-Control-Allow-Origin: *');
        header('Cross-Origin-Resource-Policy: cross-origin');
        header('Content-Security-Policy: ' . self::DATA_CSP);
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        echo self::NOT_FOUND_BODY;
        exit();
    }

    /** The body of tryAgain(): it names no organisation, page or reason beyond what a visitor can act on. */
    public const TRY_AGAIN_BODY = "Please try again in a moment.\n";

    /**
     * 🔁 The answer when it could not be told whether this request is on an
     * organisation's public address (`Site::publicAddressUnknown()`, a failed
     * look-up). Every way into start-up gives it — index.php, error.php and
     * the API description — straight after start-up and before any session:
     * no cookie, no session file, no organisation named, nothing that is a
     * page (round 2 of P4a's check). It is the same answer on the members'
     * address, where a failed look-up would have shown "Please try again"
     * anyway.
     */
    public static function tryAgain(): never
    {
        self::clearHeaders();
        http_response_code(503);
        header('Retry-After: 30');
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('Access-Control-Allow-Origin: *');
        header('Cross-Origin-Resource-Policy: cross-origin');
        header('Content-Security-Policy: ' . self::DATA_CSP);
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        echo self::TRY_AGAIN_BODY;
        exit();
    }

    /** 🚧 Closed for maintenance or an upgrade: the same for every organisation and every address. */
    private static function closed(): never
    {
        self::clearHeaders();
        http_response_code(503);
        header('Retry-After: 60');
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('Access-Control-Allow-Origin: *');
        header('Cross-Origin-Resource-Policy: cross-origin');
        header('Content-Security-Policy: ' . self::DATA_CSP);
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        echo self::CLOSED_BODY;
        exit();
    }

    /**
     * The headers on a feed or media answer:
     *   - readable by any website (`Access-Control-Allow-Origin: *`, never a
     *     credentials header) and loadable from one
     *     (`Cross-Origin-Resource-Policy: cross-origin`);
     *   - data, never a page (`DATA_CSP`), never framed;
     *   - no Referer sent on, nothing sniffed;
     *   - kept by browsers and caches for one minute, so a change reaches
     *     websites within about a minute — EXCEPT on a test copy whose door is
     *     for testers only, where the answer depends on who asked, so it is
     *     `private, no-store` with `Vary: Cookie`;
     *   - `noindex` on the live portal; on a test copy `noindex, nofollow,
     *     noarchive` on every answer (D-087).
     */
    private static function sendDataHeaders(string $contentType, bool $testCopy, bool $testersOnly): void
    {
        self::clearHeaders();
        http_response_code(200);
        header('Content-Type: ' . $contentType);
        header('Access-Control-Allow-Origin: *');
        header('Cross-Origin-Resource-Policy: cross-origin');
        header('Content-Security-Policy: ' . self::DATA_CSP);
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        if ($testersOnly === true) {
            header('Cache-Control: private, no-store');
            header('Vary: Cookie');
        } else {
            header('Cache-Control: public, max-age=60');
        }
        header('X-Robots-Tag: ' . ($testCopy === true ? 'noindex, nofollow, noarchive' : 'noindex'));
    }

    /**
     * The headers on the set-up check's answer (#493 P4d): plain text, never
     * kept by a browser or a cache (`no-store` — each answer is for one run
     * of the check alone), data and never a page, never framed, nothing
     * sniffed, and `noindex, nofollow, noarchive` on EVERY portal, because
     * nobody should ever find it in a search. No
     * Access-Control-Allow-Origin: only the portal's own server reads it,
     * never another website's script.
     */
    private static function sendCheckHeaders(): void
    {
        self::clearHeaders();
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('Content-Security-Policy: ' . self::DATA_CSP);
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
    }

    /**
     * The page's content rule (#493 P4c), with `$frameAncestors` — which
     * comes from `PublicWebsiteSetup::frameAncestorsFor()` and nowhere else —
     * put in as it is. Nothing is allowed by default; the page's ONE
     * stylesheet by its hash; pictures only over https; no script of any
     * kind (there is no `script-src`, so `default-src 'none'` refuses every
     * one); no `<base>` and no form. `sandbox` adds a second wall: even a
     * script that somehow got in would not run, and the page cannot steer
     * the website around it. `allow-popups` with
     * `allow-popups-to-escape-sandbox` is what lets a "Find out more" link
     * open an ordinary new tab.
     */
    public static function pageCsp(string $frameAncestors): string
    {
        return "default-src 'none'; img-src https:; style-src 'sha256-" . base64_encode(hash('sha256', self::PAGE_CSS, true)) . "'; "
            . "base-uri 'none'; form-action 'none'; frame-ancestors " . $frameAncestors . '; '
            . 'sandbox allow-popups allow-popups-to-escape-sandbox';
    }

    /**
     * Every header on the page's answer, as lines, in the order they are
     * sent. Pure, so the browser test serves exactly these (D-065: whether a
     * browser REFUSES a frame is driven in a real one, by
     * tools/public-door-script-selftest.mjs, not read here).
     *
     *   - Content-Security-Policy: pageCsp(), whose `frame-ancestors` is the
     *     organisation's listed websites, or 'none' when it lists none or the
     *     installation forbids framing (D-082, D-094).
     *   - X-Frame-Options: DENY, but ONLY when `frame-ancestors` is 'none'.
     *     It is the older header for the same question, and it can name no
     *     website. `X-Frame-Options: ALLOW-FROM <website>` was considered and
     *     is NOT sent: the only browser that obeys it is Internet Explorer 11,
     *     out of support since June 2022; it can name only ONE website, so it
     *     would cover a one-website list and nothing longer; and every current
     *     browser ignores it (frame-ancestors is what they obey).
     *     A browser that understands `frame-ancestors` ignores X-Frame-Options
     *     altogether (the HTML standard says so), so sending it changes
     *     nothing there; it matters only to a browser too old for
     *     `frame-ancestors`, which is then told no. When websites ARE listed
     *     it is LEFT OUT, because the only values it has — DENY and
     *     SAMEORIGIN — would refuse the very websites the organisation listed
     *     in any browser that honoured it.
     *     ⚠️ THE COST OF LEAVING IT OUT, said plainly: once websites are
     *     listed, a browser too old to understand `frame-ancestors` (Internet
     *     Explorer 11 is the one still met) sees no framing rule at all, so
     *     ANY website could show the page in a frame there. The page holds
     *     only what the organisation already put on the public website, so
     *     what such a website could show is public anyway; what it gains is
     *     the organisation's look inside somebody else's page. Every browser
     *     that understands `frame-ancestors` — all current ones — refuses an
     *     unlisted website. Chosen over DENY or SAMEORIGIN because those
     *     would break the listed websites in that same old browser, which is
     *     the opposite of what the organisation asked for (D-082).
     *   - Cross-Origin-Resource-Policy: cross-origin, so a website that
     *     requires it of everything it embeds (COEP) can still frame the page.
     *   - No Access-Control-Allow-Origin: a page is opened in a frame, never
     *     read by another website's script.
     *   - Caching, Referrer-Policy, nosniff and X-Robots-Tag exactly as the
     *     feeds have them: one minute; `private, no-store` with `Vary: Cookie`
     *     on a testers-only test copy (D-087); `noindex` — the page is meant
     *     to be seen inside the organisation's own website, not found on its
     *     own — and on a test copy `noindex, nofollow, noarchive`.
     *
     * @return list<string>
     */
    public static function pageHeaderLines(string $frameAncestors, bool $testCopy, bool $testersOnly): array
    {
        $lines = [
            'Content-Type: text/html; charset=utf-8',
            'Content-Security-Policy: ' . self::pageCsp($frameAncestors),
        ];
        if ($frameAncestors === "'none'") {
            $lines[] = 'X-Frame-Options: DENY';
        }
        $lines[] = 'Cross-Origin-Resource-Policy: cross-origin';
        $lines[] = 'Referrer-Policy: no-referrer';
        $lines[] = 'X-Content-Type-Options: nosniff';
        if ($testersOnly === true) {
            $lines[] = 'Cache-Control: private, no-store';
            $lines[] = 'Vary: Cookie';
        } else {
            $lines[] = 'Cache-Control: public, max-age=60';
        }
        $lines[] = 'X-Robots-Tag: ' . ($testCopy === true ? 'noindex, nofollow, noarchive' : 'noindex');

        return $lines;
    }

    /** Send the page's headers (pageHeaderLines()), after clearing whatever start-up queued. */
    private static function sendPageHeaders(string $frameAncestors, bool $testCopy, bool $testersOnly): void
    {
        self::clearHeaders();
        http_response_code(200);
        foreach (self::pageHeaderLines($frameAncestors, $testCopy, $testersOnly) as $line) {
            header($line);
        }
    }

    /** Take away every header the door replaces or must never send (REMOVED_HEADERS). */
    private static function clearHeaders(): void
    {
        foreach (self::REMOVED_HEADERS as $name) {
            header_remove($name);
        }
    }

    // =========================================================================
    // 🧹 Small helpers
    // =========================================================================

    /** "Today" on the ORGANISATION's calendar, the one the website dates are kept on. */
    private static function organisationToday(int $siteId): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(Site::timezone($siteId))))->format('Y-m-d');
    }

    /** This moment in UTC, as the feeds write it. */
    private static function nowUtc(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * A stored wall-clock reading exactly as stored, "YYYY-MM-DDTHH:MM:SS"
     * with NO offset (it is a reading, not a moment), or null. Null whenever
     * instantText() is null too, so a reading the portal cannot read is not
     * shown in one field and refused in the other.
     */
    private static function clockText(mixed $reading, \DateTimeZone $zone): ?string
    {
        if ($reading === null || self::instantText($reading, $zone) === null
            || preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})$/D', (string) $reading, $m) !== 1) {
            return null;
        }

        return $m[1] . 'T' . $m[2];
    }

    /** A stored wall-clock reading as an instant with its offset, on the event's own clock, or null. */
    private static function instantText(mixed $reading, \DateTimeZone $zone): ?string
    {
        if ($reading === null) {
            return null;
        }
        $instant = I18n::wallClockInstant((string) $reading, $zone);

        return $instant === null ? null : $instant->format('Y-m-d\TH:i:sP');
    }

    /** Where an event is, from its full-detail columns: nothing that could reach a person (no phone, e-mail or map position). */
    private static function locationFrom(array $row): ?array
    {
        $name    = self::textOrNull($row['locationName'] ?? null);
        $address = self::textOrNull($row['locationAddress'] ?? null);
        $url     = self::httpsOrNull((string) ($row['locationWebURL'] ?? ''));
        if ($name === null && $address === null && $url === null) {
            return null;
        }

        return ['name' => $name, 'address' => $address, 'url' => $url];
    }

    /** A poster's day: once on a date, or every week on a weekday (0 = Sunday), or null when it says neither. */
    private static function posterWhen(array $row): ?array
    {
        if ((string) $row['scheduleType'] === 'weekly' && $row['weekday'] !== null
            && (int) $row['weekday'] >= 0 && (int) $row['weekday'] <= 6) {
            return ['type' => 'weekly', 'weekday' => (int) $row['weekday']];
        }
        if ((string) $row['scheduleType'] === 'once' && $row['eventDate'] !== null
            && preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) $row['eventDate']) === 1) {
            return ['type' => 'once', 'date' => (string) $row['eventDate']];
        }

        return null;
    }

    /** A TIME column as HH:MM, from the text itself — a clock reading, never a moment (#777). */
    private static function clockOrNull(mixed $time): ?string
    {
        if ($time === null || preg_match('/^(\d{2}):(\d{2})/', (string) $time, $m) !== 1) {
            return null;
        }

        return $m[1] . ':' . $m[2];
    }

    private static function textOrNull(mixed $text): ?string
    {
        $text = trim((string) ($text ?? ''));

        return $text === '' ? null : $text;
    }

    /** An address only when it is a plain https:// web address; anything else (http, javascript:, a page path) is null. */
    private static function httpsOrNull(string $url): ?string
    {
        $url = trim($url);
        if (str_starts_with(strtolower($url), 'https://') === false || preg_match('/[\x00-\x20\x7F"<>\\\\]/', $url) === 1) {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) === true && $host !== '' ? $url : null;
    }

    private static function json(array $data): string
    {
        // JSON_HEX_*: `<`, `>`, `&`, `'` and `"` inside a value are written as
        // \u escapes, so an answer that somebody pastes into a page, or a
        // browser that sniffs it as HTML despite nosniff, can never meet a
        // live `</script>` or a quote that ends an attribute (round 1 of P4a's
        // check). The JSON means exactly the same to any reader.
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) . "\n";
    }
}
