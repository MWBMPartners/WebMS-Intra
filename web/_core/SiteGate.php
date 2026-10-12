<?php
// Path: _core/SiteGate.php
/**
 * -----------------------------------------------------------------------------
 * The organisation gate 🚪🌐
 * -----------------------------------------------------------------------------
 * One installation can serve several organisations. Site::preDetect() decides
 * WHICH organisation a request belongs to. This class decides whether the
 * request may go any further, and its gate, enforce(), is called from ONE
 * place: web/public_html/index.php, just before Router::dispatch(). Every
 * address the router answers comes through that line: pages, form handlers
 * and the data addresses under /api/ alike. (Its second method, wouldStop(),
 * only answers yes or no, for the one page that cannot be gated that way:
 * see "WHAT IT CANNOT DO" below.)
 *
 * WHAT IT STOPS (roll-out packages F1 and F2; #502 and #511)
 *   1. The organisation could not be worked out (a look-up failed while the
 *      portal was starting): a "try again" page, for everybody. Site::id()
 *      is then only a guess, and nobody is shown an organisation on a guess.
 *   2. Sign-in session mode, and the person signed in now is not the person
 *      the organisation was worked out for: the same "try again" page. That
 *      can only happen when the session changed in the instant between the
 *      two reads (see Auth::peekSession()).
 *   3. Sign-in session mode, a person with several organisations who has not
 *      chosen one yet: sent to /site/choose, whatever they asked for.
 *   4. ONLY MEMBERS GET IN (#511), in all three ways. A signed-in person who
 *      is not a member of the organisation this request belongs to gets the
 *      refusal page (HTTP 403) on EVERY address of it: pages, form handlers,
 *      data addresses, and its public pages too. The owner (Salem874)
 *      chose that strictness on 12 October 2026: they can sign out to see
 *      the public pages. "A member" is somebody whose own organisations
 *      (Site::organisationsFor()) include this one: an active membership
 *      row, or a global administrator. An account that belongs to NO
 *      organisation is therefore refused everywhere, and the page tells it
 *      to ask an administrator.
 *
 *      WHAT WAS WRONG. Being signed in counted as being a member of
 *      whichever organisation the address opened. The router asked only
 *      "signed in?". So a member of organisation A could read organisation
 *      B's internal event through B's /api/events/detail (proved 14
 *      September 2026), B's attendance list and head counts (12 October
 *      2026), and B's dashboard, in both address ways.
 *
 *      WHY ONE RULE HERE AND NOT A CHECK IN EVERY PAGE. About 300 files read
 *      the sign-in straight from the session. A rule each of them had to
 *      remember would be forgotten by the next page written.
 *
 * THE CHOOSER IS SHOWN FROM HERE, not through the address table. The gate
 * sends people to /site/choose, so the gate must be able to show it whatever
 * state the database is in. Its address is seeded by migration 212; on a
 * database that has not run that migration yet (new code just uploaded, the
 * upgrade not yet run) the router would answer "page not found", and
 * everybody with several organisations would be sent round to a page that
 * does not exist. For the same reason a GLOBAL ADMINISTRATOR who has not
 * chosen yet may still open the database upgrade page (admin/upgrade): it
 * belongs to the whole installation, and it is the page that runs the
 * migration.
 *
 * WHAT ALWAYS PASSES: the three addresses in OPEN_PATHS, so that somebody
 * who is stopped can still sign out, choose, or switch. None of the three
 * shows anything of the organisation the address belongs to: the chooser
 * lists the person's OWN organisations, and the switch handler moves them
 * only into one of those.
 *
 * WHAT IT LEAVES ALONE
 *   - An installation with one organisation (multi-organisation switched
 *     off): nothing here runs past the first test.
 *   - Signed-out visitors, scheduled jobs and requests made with an API key.
 *     None of them carries a signed-in session, and this class looks at
 *     nothing else. (A request that sends BOTH a key and a signed-in
 *     browser session is judged by the session. Letting the key's header
 *     switch the gate off would let anybody past it by adding a made-up
 *     header.)
 *
 * WHAT IT CANNOT DO
 *   - It cannot stop an address the web server answers without
 *     web/public_html/index.php, because enforce() is called only from
 *     there. There are three kinds:
 *       - Files under /assets/, served with no PHP at all. They hold
 *         nothing that belongs to one organisation.
 *       - /error.php, the page the web server shows for its own errors.
 *         enforce() does not run there. That file asks wouldStop() below
 *         instead, and on a yes shows its plain page: no menu, and no
 *         organisation's name or apps. It does NOT show the refusal page,
 *         and it sends nobody to the chooser. (Until 12 October 2026 it
 *         asked nothing, and this list did not mention it: a signed-in
 *         member of one organisation was shown another's menu there.)
 *       - /api-docs/index.php, the browsable description of the API. It
 *         does not ask the gate anything. The description it shows is the
 *         same for every organisation.
 *   - It does not decide what a MEMBER may do inside their organisation.
 *     That is still each page's own rule.
 *   - It does not close the rest of #511: an administrator of one
 *     organisation can still grant an event's coordinator role to somebody
 *     from another organisation, and the older portal-wide administrator
 *     flag (tblUsers.isAdmin) still counts in every organisation its holder
 *     is a member of.
 *   - It refuses on the person's NEXT request. A page already on screen
 *     when somebody is taken out of an organisation stays on screen.
 *
 * The pages it shows (and /site/choose) use pageStart() and pageEnd() below:
 * a plain page with the product's name and no organisation's name, logo or
 * colour, because the person looking at it may not be entitled to see whose
 * organisation the address belongs to. The wording is plain English written
 * here, not translated text: these are rare, short notices, and the language
 * files are not part of this package.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/502
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/511
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class SiteGate
{
    /**
     * The addresses a stopped person may still use, compared with what
     * Router::extractPath() returns (the address with any organisation key
     * already taken off the front, in lower case, with no slashes at the
     * ends). Exact matches only.
     *
     * @var list<string>
     */
    public const OPEN_PATHS = ['logout', 'site/switch', 'site/choose'];

    /**
     * Stop the request here if it may not go on. Returns only when it may.
     *
     * Must be called AFTER Auth::ensureSession() (it reads who is signed in)
     * and BEFORE Router::dispatch().
     *
     * @param \mysqli $db Database connection.
     *
     * @return void
     */
    public static function enforce(\mysqli $db): void
    {
        $path = Router::extractPath();

        // 1. The organisation is only a guess: show nobody anything.
        if (Site::detectionFailed() === true) {
            if ($path === 'logout') {
                return;
            }
            self::tryAgain($path);
        }

        // One organisation only: nothing to guard.
        if (Site::isMultisiteEnabled() === false) {
            return;
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);

        if (Site::detectionMode() === Site::MODE_SESSION) {
            // 2. Worked out for somebody else.
            if ($userId !== Site::resolvedUserId()) {
                if ($path === 'logout') {
                    return;
                }
                self::tryAgain($path);
            }
            if ($userId === 0) {
                return;
            }
        }

        // The chooser, for anybody signed in, in every way: shown from here
        // (see "THE CHOOSER IS SHOWN FROM HERE" at the top of this file).
        if ($userId > 0 && $path === 'site/choose') {
            self::showChooser();
        }

        if (Site::detectionMode() === Site::MODE_SESSION) {
            // 3. Several organisations and none chosen yet. (Somebody with
            //    NO organisation is not sent to the chooser: rule 4 below
            //    gives them the refusal page, which says what to do.)
            if (Site::sessionState() === Site::SESSION_CHOOSE) {
                if (in_array($path, self::OPEN_PATHS, true) === true) {
                    return;
                }
                if (self::upgradePageForGlobalAdministrator($path) === false) {
                    self::sendToChooser($path);
                }
            }
        }

        // 4. Only members get in (#511). The same rule in all three ways.
        //    Nobody signed in: nothing to ask. That is every signed-out
        //    visitor, every scheduled job, and every request made with an
        //    API key, none of which carries a signed-in session.
        if ($userId === 0) {
            return;
        }
        if (in_array($path, self::OPEN_PATHS, true) === true) {
            return;
        }
        // The organisation asked about is Site::id(): the one THIS REQUEST
        // belongs to, which in the two address ways is the one the address
        // opens. Never the organisation saved in the session: that is the
        // person's own, so asking about it would let everybody in everywhere.
        try {
            $own = Site::organisationsFor($userId, $db);
        } catch (\Throwable $e) {
            // Could not ask. Not knowing is not a yes.
            error_log('[WebMS-Intra] SiteGate: could not read the organisations of account ' . $userId . ': ' . $e->getMessage());
            self::tryAgain($path);
        }
        foreach ($own as $site) {
            if ($site['siteID'] === Site::id()) {
                return;
            }
        }
        self::notAMember($db, $userId, $own, $path);
    }

    /**
     * Would enforce() stop this request on an ordinary page? It only
     * answers: it shows nothing, sends nobody anywhere and ends nothing.
     *
     * FOR ONE CALLER: web/public_html/error.php, the page the web server
     * shows for its own errors. The web server reaches that file without
     * index.php, so enforce() never runs for it, and it used to draw the
     * shared header whoever was asking. A signed-in member of organisation A
     * who met a web-server error at B's address was shown B's menu of
     * switched-on apps (found by the independent check, 12 October 2026).
     * error.php now asks this first, and shows its plain page on a yes.
     *
     * WHY NOT enforce() ITSELF. enforce() answers with its own pages and
     * status codes (403, 503, a redirect to the chooser). An error page has
     * to keep the status the web server asked for (404, say).
     *
     * ⚠️ KEEP IT IN STEP WITH enforce(): the same rules in the same order,
     * without the passes for particular addresses, because an error page is
     * not the sign-out page, the chooser or the upgrade page. A rule added
     * there belongs here too. tools/site-modes-selftest.php (checks E01 to
     * E04) holds the two together for a member, a non-member, an account in
     * no organisation and a person who has not chosen yet.
     *
     * Must be called AFTER Auth::ensureSession(), as enforce() must.
     *
     * WHAT IT CANNOT DO: say WHY. The caller gets yes or no only. And when
     * it cannot find out, it says yes: not knowing is not a pass.
     *
     * @param \mysqli $db Database connection.
     *
     * @return bool True when the request must be shown nothing of the
     *              organisation it belongs to.
     */
    public static function wouldStop(\mysqli $db): bool
    {
        if (Site::detectionFailed() === true) {
            return true;
        }
        if (Site::isMultisiteEnabled() === false) {
            return false;
        }

        $userId      = (int) ($_SESSION['user_id'] ?? 0);
        $sessionMode = (Site::detectionMode() === Site::MODE_SESSION);
        if ($sessionMode === true && $userId !== Site::resolvedUserId()) {
            return true;
        }
        if ($userId === 0) {
            return false;
        }
        if ($sessionMode === true && Site::sessionState() === Site::SESSION_CHOOSE) {
            return true;
        }
        try {
            return Site::isOwnOrganisation($userId, Site::id(), $db) === false;
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] SiteGate: could not read the organisations of account ' . $userId
                . ' for the error page, so it is shown the plain one: ' . $e->getMessage());

            return true;
        }
    }

    /**
     * The refusal: HTTP 403 and the "not a member" page, or the same in JSON
     * for a data address. Nothing of the refused organisation is in either.
     *
     * @param list<array<string,mixed>> $own The person's own organisations.
     */
    private static function notAMember(\mysqli $db, int $userId, array $own, string $path): never
    {
        if (self::isDataAddress($path) === true) {
            ApiResponse::error(
                $own === []
                    ? 'This account has not been added to any organisation. Ask an administrator to add you.'
                    : 'This account is not a member of the organisation this address belongs to.',
                403
            );
        }

        http_response_code(403);
        // Handed to the template, which is loaded inside this method and so
        // sees exactly these two and nothing else.
        $gateSignedInAs = self::accountLabel($db, $userId);
        $gateOwn        = $own;
        require __DIR__ . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'not-a-member.php';
        exit();
    }

    /**
     * Show the chooser page itself, without asking the address table.
     * The page lists only the person's own organisations.
     */
    private static function showChooser(): never
    {
        require PORTAL_APPS . DIRECTORY_SEPARATOR . 'site' . DIRECTORY_SEPARATOR . 'choose.php';
        exit();
    }

    /**
     * May this request go on although the person has not chosen an
     * organisation yet? Only the database upgrade page, and only for a
     * global administrator. The page checks that again itself.
     */
    private static function upgradePageForGlobalAdministrator(string $path): bool
    {
        return $path === 'admin/upgrade' && App::isUmbrellaAdmin() === true;
    }

    /** Is this one of the data addresses, which must be answered in JSON and never with a page or a redirect? */
    private static function isDataAddress(string $path): bool
    {
        return $path === 'api' || str_starts_with($path, 'api/') === true;
    }

    /**
     * "Could not work out which organisation": HTTP 503, and nothing of any
     * organisation. 503 with Retry-After, because the cause is passing (a
     * database hiccup, or a session that changed under the request) and a
     * second try normally succeeds.
     */
    private static function tryAgain(string $path): never
    {
        header('Retry-After: 30');
        if (self::isDataAddress($path) === true) {
            ApiResponse::error('The portal could not work out which organisation this request belongs to. Try again.', 503);
        }

        http_response_code(503);
        self::pageStart('Please try again', 'The portal could not work out which organisation this page belongs to.');
        echo '<h1>Please try again</h1>';
        echo '<p>The portal could not work out which organisation this page belongs to, so it has shown you nothing rather than guess.</p>';
        echo '<p>Wait a moment, then try again. If this keeps happening, tell an administrator.</p>';
        echo '<p class="actions">';
        echo '<a class="button" href="' . htmlspecialchars(Site::url(''), ENT_QUOTES, 'UTF-8') . '">Try again</a> ';
        echo '<a href="' . htmlspecialchars(Site::url('logout'), ENT_QUOTES, 'UTF-8') . '">Sign out</a>';
        echo '</p>';
        self::pageEnd();
        exit();
    }

    /**
     * Send somebody with no organisation chosen to the chooser. A data
     * address gets a refusal in JSON instead: a script cannot follow a
     * redirect to a page and choose.
     */
    private static function sendToChooser(string $path): never
    {
        if (self::isDataAddress($path) === true) {
            ApiResponse::error('Choose an organisation first: open the portal in a browser and pick one.', 403);
        }
        header('Location: ' . Site::url('site/choose'), true, 302);
        exit();
    }

    /**
     * "Name (e-mail address)" of an account, for "You are signed in as …".
     * Empty when it cannot be read; the pages then leave the sentence out.
     */
    public static function accountLabel(\mysqli $db, int $userId): string
    {
        try {
            $stmt = $db->prepare('SELECT fullName, emailAddress FROM tblUsers WHERE userID = ? LIMIT 1');
            if ($stmt === false) {
                return '';
            }
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (\Throwable) {
            return '';
        }
        if ($row === null) {
            return '';
        }
        $name  = trim((string) ($row['fullName'] ?? ''));
        $email = trim((string) ($row['emailAddress'] ?? ''));
        if ($name === '') {
            return $email;
        }

        return $email === '' ? $name : $name . ' (' . $email . ')';
    }

    /**
     * 🎨 The top of a plain, stand-alone page: everything up to and including
     * the opening of the one white card the page's own content goes in.
     *
     * It deliberately shows the PRODUCT's name and icon and no
     * organisation's: see the note at the top of this file. The look copies
     * the maintenance page (Maintenance::renderAndExit()), so the portal's
     * stand-alone pages match one another, and it needs no stylesheet from
     * the network.
     *
     * The page is marked "do not index" and "do not keep a copy": it is
     * about one signed-in person.
     *
     * Long names and addresses wrap inside the card (overflow-wrap), and the
     * buttons are full width, so nothing runs off a phone screen.
     *
     * @param string $title       The page's title (the product name is added).
     * @param string $description One sentence for the page's description.
     */
    public static function pageStart(string $title, string $description): void
    {
        if (headers_sent() === false) {
            header('Content-Type: text/html; charset=utf-8');
            header('X-Robots-Tag: noindex, nofollow, noai, noimageai');
            header('Cache-Control: no-store, private');
        }
        $lang = 'en';
        $dir  = 'ltr';
        if (class_exists(I18n::class) === true) {
            $lang = I18n::locale();
            $dir  = I18n::dir();
        }
        $esc = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        echo '<!doctype html><html lang="' . $esc($lang) . '" dir="' . $esc($dir) . '"><head>'
           . '<meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<meta name="robots" content="noindex, nofollow">'
           . '<meta name="description" content="' . $esc($description) . '">'
           . '<link rel="icon" type="image/svg+xml" href="' . $esc(Site::DEFAULT_FAVICON_PATH) . '">'
           . '<title>' . $esc($title . ' - ' . Site::productName()) . '</title>'
           . '<style>'
           . ':root{'
           .   '--bg:#f7f8fa;--surface:#ffffff;--text:#1b2330;--muted:#6b7280;'
           .   '--border:#e5e7eb;--primary:#5e6ad2;--primary-hover:#4f5bbf;--on-primary:#ffffff;'
           .   '--warn-bg:#fff3cd;--warn-text:#664d03;'
           . '}'
           . '@media (prefers-color-scheme: dark){:root{'
           .   '--bg:#0f1115;--surface:#161a22;--text:#e8eaf0;--muted:#9aa3b2;'
           .   '--border:#2c3441;--primary:#7b86e8;--primary-hover:#8f99eb;--on-primary:#0f1115;'
           .   '--warn-bg:#3a3000;--warn-text:#ffe69c;'
           . '}}'
           . '*{box-sizing:border-box;}'
           . 'body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;'
           .   'background:var(--bg);color:var(--text);padding:3rem 1rem;margin:0;line-height:1.55;}'
           . '.card{max-width:560px;margin:0 auto;background:var(--surface);'
           .   'border:1px solid var(--border);border-radius:.75rem;padding:2rem 1.5rem;'
           .   'box-shadow:0 4px 6px -1px rgba(16,24,40,.08),0 2px 4px -2px rgba(16,24,40,.06);'
           .   'overflow-wrap:anywhere;}'
           . 'h1{margin:0 0 1rem;font-size:1.5rem;font-weight:600;letter-spacing:-.01em;}'
           . 'p{margin:0 0 1rem;}'
           . '.muted{color:var(--muted);font-size:.9rem;}'
           . '.notice{background:var(--warn-bg);color:var(--warn-text);border-radius:.5rem;padding:.75rem 1rem;}'
           . 'ul.choices{list-style:none;margin:0 0 1rem;padding:0;}'
           . 'ul.choices li{margin:0 0 .5rem;}'
           . 'ul.choices form{margin:0;}'
           . '.button{display:inline-block;font:inherit;font-weight:500;text-align:center;cursor:pointer;'
           .   'background:var(--primary);color:var(--on-primary);border:0;border-radius:.5rem;'
           .   'padding:.6rem 1rem;text-decoration:none;}'
           . '.button:hover,.button:focus{background:var(--primary-hover);color:var(--on-primary);text-decoration:none;}'
           . 'ul.choices .button{display:block;width:100%;}'
           . '.actions{margin-top:1.5rem;}'
           . 'a{color:var(--primary);font-weight:500;}'
           . 'a:hover,a:focus{color:var(--primary-hover);}'
           . '</style></head>'
           . '<body><main class="card">';
    }

    /** The bottom of a page begun with pageStart(). */
    public static function pageEnd(): void
    {
        echo '</main></body></html>';
    }
}
