<?php
// Path: _core/Auth.php
/**
 * -----------------------------------------------------------------------------
 * Portal Core Auth 🔑
 * -----------------------------------------------------------------------------
 * Unified authentication helper – wraps Microsoft 365 OAuth, Google OAuth,
 * local accounts, WebAuthn/PassKeys, and account linking. Provides session
 * management, role checks, CSRF token utilities, and rate limiting integration.
 *
 * Public methods:
 *   Auth::check()                  → bool   – is user logged in?
 *   Auth::requireLogin()           → void   – redirect if not logged in
 *   Auth::ensureSession()          → void   – start session with secure params
 *   Auth::loginMS365()             → void   – begin MS OAuth flow (dormant until configured)
 *   Auth::callbackMS365()          → void   – handle OAuth redirect & verify JWT
 *   Auth::loginGoogle()            → void   – begin Google OAuth flow
 *   Auth::callbackGoogle()         → void   – handle Google OAuth redirect
 *   Auth::loginLocal($id, $pw)     → bool   – authenticate with username/email + password
 *   Auth::validatePassword($pw)    → array  – check password against policy
 *   Auth::isMS365Configured()      → bool   – are MS365 OAuth credentials set?
 *   Auth::isGoogleConfigured()     → bool   – are Google OAuth credentials set?
 *   Auth::linkAccount(...)         → bool   – link an external provider to a user
 *   Auth::unlinkAccount(...)       → bool   – remove a provider link (safety-checked)
 *   Auth::getLinkedAccounts(...)   → array  – list linked providers for a user
 *   Auth::countLoginMethods(...)   → int    – count available login methods
 *   Auth::logout()                 → void   – destroy session, clear offline copies (#507)
 *   Auth::csrfToken()              → string – get / create CSRF token
 *   Auth::verifyCsrf($tok)         → bool   – compare token constant-time
 *   Auth::curlPost($url, $data)    → ?string – HTTP POST via cURL
 *   Auth::isCoordinatorOf($id)     → bool   – event coordinator check (#341)
 *   Auth::isEventTeamMember($id)   → bool   – event team hub view check (#386)
 *   Auth::encrypt($plain)          → string – encrypt a secret (TOTP, etc.)
 *   Auth::decrypt($encoded)        → string – decrypt a secret ('' on failure)
 *   Auth::userRequires2fa($id)     → bool   – does this user have TOTP enabled?
 *   Auth::deviceIsTrusted($id)     → bool   – is this browser a trusted device?
 *
 * @see       https://owasp.org/www-community/controls/Session_Management_Cheat_Sheet
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.6.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use RuntimeException;
use SimpleJWT\JWT as SimpleJWT;

class Auth
{
    /* ====================================================================== */
    /* Offline copies (#507)                                                  */
    /* ====================================================================== */

    /**
     * The response header that tells the portal's service worker
     * (web/public_html/sw.js) it may keep a copy of this page for offline use.
     * The worker keeps a page ONLY when this header says "allow". The same
     * name is written in sw.js (OFFLINE_COPY_HEADER) and in the sign-out page
     * script in logout(); change all three together.
     */
    private const OFFLINE_COPY_HEADER = 'X-Offline-Copy';

    /**
     * Session entries that say nothing about who the visitor is. A page is
     * marked as safe to keep offline only when the session holds NOTHING
     * except these.
     *
     * Why a list of harmless entries, and not a list of "signed in" ones:
     * being identified is not only `user_id`. A half-finished two-step
     * sign-in holds `2fa_user_id`, and the Asset Tracker kiosk holds
     * `kiosk_user_id` without any `user_id` at all. A list of "signed in"
     * entries would silently miss the next feature to add its own. With this
     * list, an entry nobody has thought about makes pages NOT kept offline —
     * a small loss of convenience instead of a leak.
     *
     * Checked against every `$_SESSION[...]` name in web/ on 14 September 2026:
     *   csrf_token      the form token every visitor gets
     *   login_redirect  where to go back to after signing in
     *   oauth_state     a random value for a Microsoft or Google sign-in in progress
     *   portal_locale   the language a visitor chose
     *   active_site_id  which organisation's pages are being shown
     * Flash messages are deliberately NOT here, because they can repeat back
     * what a visitor typed. But what that achieves is narrower than it sounds:
     * the decision is made when the headers are sent, so a flash that is STILL
     * in the session at that moment stops the page being kept. Most pages read
     * and remove their flash before they send any output (for example
     * projects/view.php and assets/kiosk.php), and a page like that IS kept.
     * No harm was found on 14 September 2026, because the public flash texts
     * found are fixed wording with nothing the visitor typed, but do not rely
     * on this list to keep a flash out of the offline store. (An earlier
     * version of this comment said a page carrying a flash "is not kept"; an
     * independent check showed that was not true.)
     *
     * @var list<string>
     */
    private const SESSION_KEYS_SAFE_TO_KEEP_OFFLINE = [
        'csrf_token',
        'login_redirect',
        'oauth_state',
        'portal_locale',
        'active_site_id',
    ];

    /** True once the header decision below has been registered for this request. */
    private static bool $offlineCopyDecisionRegistered = false;

    /**
     * Arrange for every response to say whether the service worker may keep it.
     *
     * WHY IT IS DONE HERE, AND AT THE LAST MOMENT
     * web/public_html/index.php calls ensureSession() for every request that
     * reaches the front controller, before any page runs — and pages that call
     * requireLogin() come through ensureSession() as well. So this is the one
     * place that covers every signed-in page, including pages that do not use
     * the shared header template (downloads, sign-in steps, JSON answers).
     *
     * The decision itself is made just before the headers leave, using PHP's
     * header_register_callback(), not now. A request can change who is signed
     * in part way through (signing in, signing out, a kiosk PIN), and deciding
     * at the start would label such a response by the wrong state.
     *
     * TRIED AND REJECTED
     *   - web/_core/templates/header.php: only pages that use the template run
     *     it, so a signed-in page that does not would go unmarked.
     *   - web/public_html/index.php: runs for every page, but before the page,
     *     so it would label by the state at the start of the request.
     *   - Relying on the Cache-Control no-store that PHP already sends: PHP
     *     sends it on public pages too, so it cannot tell the two apart.
     *
     * WHAT IT CANNOT DO
     *   - PHP keeps only ONE such callback per request; registering another
     *     anywhere replaces this one without warning. If that happens, TWO
     *     things stop being sent. The first is the "allow" marker, so the
     *     worker then keeps no pages at all and offline public pages stop
     *     working. The second is `Vary: *` (added in round 2, see
     *     oldServiceWorkerWouldStore()), so a browser still running a worker
     *     from before #507 could again store a signed-in page that finishes
     *     downloading after sign-out — the very race this class exists to
     *     close. A scratch test on 15 September 2026 confirmed neither header
     *     is sent once a second callback replaces this one. (None of web/
     *     registered one when this was written.)
     *   - Responses that never start a session (for example /api-docs/, which
     *     the web server serves directly) get no marker, so they are not kept.
     *
     * @return void
     */
    private static function registerOfflineCopyDecision(): void
    {
        if (self::$offlineCopyDecisionRegistered === true || headers_sent() === true) {
            return;
        }
        self::$offlineCopyDecisionRegistered = header_register_callback(static function (): void {
            self::sendOfflineCopyHeaders();
        });
    }

    /**
     * Runs as the headers are about to be sent. Marks a page built for an
     * unidentified visitor as keepable, and makes sure a response for an
     * identified one carries "private" — plus `Vary: *` where a service worker
     * from before #507 would otherwise store it (see
     * oldServiceWorkerWouldStore()).
     *
     * @return void
     */
    private static function sendOfflineCopyHeaders(): void
    {
        // 🧹 Whatever happened earlier in the request, only this decision counts.
        header_remove(self::OFFLINE_COPY_HEADER);

        // 🔍 No session loaded means we cannot know who this was for: say nothing.
        $sessionKeys = (isset($_SESSION) === true && is_array($_SESSION) === true) ? array_keys($_SESSION) : null;

        if ($sessionKeys !== null
            && array_diff($sessionKeys, self::SESSION_KEYS_SAFE_TO_KEEP_OFFLINE) === []
        ) {
            header(self::OFFLINE_COPY_HEADER . ': allow');
            return;
        }

        if ($sessionKeys === null) {
            return;
        }

        // 🚫 An identified visitor: make sure no service worker at all can store
        //    this response, however late it finishes downloading (see
        //    oldServiceWorkerWouldStore() for why, and why not on everything).
        //    The one exception is the portal's fixed offline page (see
        //    offlinePageAnswered()): refusing it emptied the worker's whole
        //    install list on servers that send /offline/ through PHP.
        if (self::oldServiceWorkerWouldStore() === true && self::offlinePageAnswered() === false) {
            header('Vary: *', false);
        }

        // 🔒 An identified visitor. PHP's session start normally already sent
        //    "no-store", but a page may have replaced it with its own value
        //    ("no-cache, must-revalidate" on a download, "public, max-age" on
        //    media). Add "private" beside such a value — as a second header
        //    line, so the page's own directives are kept — which tells shared
        //    caches and the service worker alike not to keep it.
        foreach (headers_list() as $line) {
            if (stripos($line, 'Cache-Control:') === 0
                && (stripos($line, 'private') !== false || stripos($line, 'no-store') !== false)
            ) {
                return;
            }
        }
        header('Cache-Control: private', false);
    }

    /**
     * Would a service worker from BEFORE #507 store this response? Asked only
     * for a response to an identified visitor; a yes adds `Vary: *`.
     *
     * WHAT WAS WRONG (found by the Codex review of #507): the sign-out page
     * deletes stored signed-in pages, but a worker from before #507 stores a
     * page without waiting for it, and the copy only lands once the whole page
     * has downloaded. So a signed-in page still downloading in another tab
     * could land AFTER the sign-out page had finished deleting, and that old
     * worker then showed it offline. Reproduced on 14 September 2026 in Edge
     * 153 and in Playwright's Firefox 155 and WebKit 26.6 builds, with a second
     * tab whose page finished downloading seven seconds after sign-out.
     *
     * WHY `Vary: *` CLOSES IT: the Cache Storage standard makes the browser
     * refuse to store any response whose Vary header contains "*" — put(),
     * add() and addAll() all fail with an error, whichever worker calls them
     * (add() was seen refused this way in Edge 153, Firefox 155 and WebKit
     * 26.6 on 15 September 2026). The refusal
     * happens inside the browser, when the copy would be written, so it does
     * not matter which worker version is running, when the download finishes,
     * or whether the current sw.js can be fetched. The sign-out page then only
     * has to delete what was stored before the portal started sending this.
     * Of the pages built for a signed-in visitor among those older copies, it
     * deletes all but one, which it cannot tell apart from the offline page:
     * see the last bullet under "WHAT IT CANNOT DO" below.
     *
     * WHY IT MUST STAY EVEN ONCE NO OLD WORKER IS LEFT: a worker from after
     * #507 refuses these responses in its FETCH handler (mayKeepCopy() in
     * sw.js wants the "allow" marker on a page and turns away anything
     * "private"). But its INSTALL step stores each file on its list with
     * cache.add(), and cache.add() does not look at Cache-Control at all. So
     * for the install list, `Vary: *` is the only thing that stops a
     * signed-in page being stored. On a server that sends /offline/ through
     * PHP, that page is the dashboard of an organisation whose site key is
     * "offline".
     * WHAT THIS COMMENT USED TO SAY, AND WHY IT WAS WRONG: that the "private"
     * added below already protected a current worker, so this header was
     * only for older ones. Tested 15 September 2026: with only the `Vary: *`
     * line switched off and "private" still sent, Edge 153 stored that
     * signed-in dashboard at install time, kept it after sign-out and showed
     * it offline with the person's name. A scratch probe the same day stored
     * a "no-store, private" page through cache.add() in Edge 153, Firefox 155
     * and WebKit 26.6, and refused the same page carrying `Vary: *` in all
     * three. Removing this header, even once old workers are gone, reopens
     * that leak.
     *
     * WHICH RESPONSES: every response an old worker would store, plus some it
     * would not. It ignores the request method (the old worker only handles
     * GET), the old worker's /api/ exclusion, and the response status (the old
     * worker stores only successful answers). It also finds text/html anywhere
     * in the Content-Type, ignoring case. The old worker's own test also looks
     * anywhere in the value (indexOf('text/html') !== -1), but it is
     * case-sensitive. Matching
     * extra responses is harmless: they lose only ordinary browser-cache
     * reuse, and they are already "no-store" or "private". Do not narrow this
     * to fit the old worker exactly — a response it misses can still be
     * stored and shown after sign-out.
     *
     * History: an earlier version of the Content-Type check looked only at
     * the START of the value, which missed a Content-Type such as
     * "application/octet-stream; note=text/html" (found with a scratch
     * php-cgi probe on 15 September 2026). No page in web/ actually sends a
     * value shaped like that, so this was a comment-accuracy point, not a
     * live fault — but the check should not depend on staying lucky.
     *
     * It keeps every web page (text/html) through the old worker's
     * network-first path, and ANY response through its cache-first path when
     * the address starts with /assets/ or ends in a static file ending —
     * copied from the old worker's OWN rules, which are frozen (that worker's
     * code never changes again), so repeating them here is safe. The Asset
     * Tracker's signed-in downloads (/assets/labels-pdf,
     * /assets/resource-download) are the non-page responses this catches.
     * (Its label images are answered at /api/assets/qr, and an old worker
     * never stores anything under /api/.) A missing Content-Type counts as a
     * page, because text/html is what PHP sends when a page sets none.
     *
     * TRIED AND REJECTED
     *   - `Vary: *` on EVERY response for an identified visitor. Simpler to
     *     state, but Vary: * also stops the browser's ordinary cache reusing a
     *     response, and some signed-in responses rely on that: photos
     *     (Photos.php, "private, max-age=3600"), QR codes and the calendar feed.
     *     An old worker never stores those (they are not pages and not under
     *     /assets/), so they would lose their caching for no gain.
     *   - Replacing the old worker during sign-out instead (see logout()).
     *
     * WHAT IT CANNOT DO
     *   - It relies on the Vary header reaching the browser unchanged. Apache
     *     adding its own value (for example "Accept-Encoding" from compression)
     *     is fine, because the "*" stays in the list. A proxy or content delivery
     *     network in front of the portal that removed the header would reopen
     *     the gap; none was tested.
     *   - It cannot remove copies stored before the portal started sending it.
     *     The sign-out page and sw.js's activate handler remove those, with
     *     ONE exception that neither removes: a copy stored at '/offline/' by
     *     the CURRENT worker's install step while an older Auth.php (from
     *     before #507, so sending no `Vary: *`) was still live. For an
     *     organisation whose site key is "offline", on a server that sends
     *     /offline/ through PHP, that copy is the signed-in dashboard, with
     *     the person's name on it. The sign-out page keeps it on purpose,
     *     because it cannot tell it from the offline page: both sit under
     *     the same address, and the three headers the sign-out page reads
     *     (Content-Type, Cache-Control and the marker) are the same on both:
     *     text/html, the session's no-store, no marker. Neither carries
     *     `Vary: *`, which is why the browser stored them. Other headers do
     *     differ today (the shared page template gives the dashboard a
     *     Content-Security-Policy and removes X-Powered-By; the offline page
     *     uses no template, so it has neither change; seen 17 September 2026
     *     with curl, and in the copies Edge, Firefox and WebKit stored), but
     *     a rule built on them would not be safe: a web server can add a
     *     policy header to every response, the branding.hidePoweredBy
     *     setting removes X-Powered-By everywhere, and on such a server
     *     bootstrap.php already sends Strict-Transport-Security to both over
     *     HTTPS. Such a rule would one day delete the real offline page at
     *     every sign-out, with nothing logged. Only the body tells them
     *     apart, and the sign-out page reads no bodies; a body check was
     *     rejected too, because it would need fixed wording inside the
     *     offline page, and a later edit to that page would then delete it
     *     at every sign-out in the same silent way.
     *     The activate handler leaves it because it deletes only the stores
     *     of OTHER worker versions, and this copy sits in the current one. It
     *     is only certain to go when CACHE_VERSION next changes, because the
     *     new worker's activate handler then deletes the whole old store.
     *     What it costs, and the proper fix (refusing such site keys where
     *     they are saved), are in the comment above the echo in logout().
     *
     * @see https://w3c.github.io/ServiceWorker/#cache-put (a Vary value of "*"
     *      makes put() reject with a TypeError)
     * @return bool
     */
    private static function oldServiceWorkerWouldStore(): bool
    {
        $contentType = null;
        foreach (headers_list() as $line) {
            if (stripos($line, 'Content-Type:') === 0) {
                $contentType = strtolower(trim(substr($line, strlen('Content-Type:'))));
            }
        }
        if ($contentType === null || str_contains($contentType, 'text/html') === true) {
            return true;
        }

        // 📋 The same file endings as isStaticAsset() in the pre-#507 sw.js.
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (is_string($path) === false) {
            return false;
        }
        return str_starts_with($path, '/assets/') === true
            || preg_match('/\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|eot|webp)$/i', $path) === 1;
    }

    /**
     * Has PHP included the portal's own fixed offline page
     * (web/public_html/offline/index.php) at any point while building this
     * response? Today that means this response IS the offline page, because
     * only Router.php includes that file, and only to answer the 'offline'
     * route. Asked only right before adding `Vary: *` to a response for an
     * identified visitor — see sendOfflineCopyHeaders().
     *
     * WHAT WAS WRONG: on a server that sends /offline/ through the front
     * controller (for example nginx with try_files, which sw.js says it
     * supports), a signed-in visitor's copy of the offline page got
     * `Vary: *` like any other page. The worker's install step then fetched
     * its whole list with ONE cache.addAll() call, and addAll() is all-or-
     * nothing: that single refused response emptied the whole list, so
     * nothing was stored offline at all — not the offline page, not
     * portal.css or portal.js, not the manifest. The .catch on the install
     * step hid the failure, so nothing anywhere showed it had happened.
     * (sw.js now stores each file on its own, with cache.add(). Without this
     * exemption only the offline page itself would now be lost, but that is
     * still the page the worker exists to show.)
     * Reproduced 15 September 2026 in Edge 153 and in Playwright's Firefox
     * 155 and WebKit 26.6 builds.
     *
     * WHY EXEMPTING IT IS SAFE: web/public_html/offline/index.php is fixed
     * text. It reads no session and no database — the same reason sw.js
     * already stores it at install time with no check at all.
     *
     * WHY IT CHECKS THE FILE THAT RAN, NOT THE ADDRESS: with path-prefix
     * multi-site, an organisation whose site key is "offline" gets its OWN
     * signed-in dashboard at /offline/ — Router::extractPath() strips the
     * site key from the front of the address before routing, so that
     * address no longer means "the portal's shared offline page" for that
     * organisation. An address-only rule would exempt that dashboard from
     * `Vary: *` too, and that dashboard DOES say who is signed in.
     * Reproduced in Edge, Firefox and WebKit on 15 September 2026: with an
     * address-only exemption, that signed-in dashboard was stored at install
     * time and went on being shown offline — with the visitor's name on it —
     * even after sign-out.
     *
     * TRIED AND REJECTED
     *   - Comparing the request address to '/offline' / '/offline/': the leak
     *     above.
     *   - Comparing BOTH the address and the file: the file check alone is
     *     what makes this safe. A second, separate copy of the address here
     *     would only be able to go stale, and staleness in this direction
     *     (the address check passing when the file check would have failed)
     *     is exactly the leak above.
     *   - Asking Router.php what route this request resolved to: that would
     *     mean calling another class from inside the last-moment header
     *     callback, where any exception thrown partway through breaks the
     *     headers for every kind of response, not only this one. It would
     *     also answer the wrong question — which ROUTE matched — where the
     *     point here is which FILE actually ran.
     *   - Building the path from the PORTAL_ROOT constant instead of
     *     dirname(__DIR__): PORTAL_ROOT resolves to the same folder (see
     *     bootstrap.php), but this method runs while headers are already
     *     being sent, where a missing or undefined constant would throw an
     *     Error partway through sending them. dirname(__DIR__) needs nothing
     *     beyond what loading this class itself already guarantees, and
     *     web/_install/ loads this class too without ever defining
     *     PORTAL_ROOT.
     *
     * WHAT IT CANNOT DO
     *   - It trusts web/public_html/offline/index.php to go on reading
     *     nothing about the visitor. If that page is ever changed to show
     *     who is signed in, remove this exemption.
     *   - It only sees files PHP has already included by the time the
     *     headers are sent. Any output printed before the offline page is
     *     included makes this return false, so the exemption simply does not
     *     apply — the safe direction: the page is then not stored on a
     *     front-controller server, exactly as before this method existed.
     *   - It checks for the exact file Router.php includes for the 'offline'
     *     route today. If that changes, update this method to match;
     *     otherwise the exemption silently stops applying — again the safe
     *     direction, a loss of offline support rather than a leak.
     *   - It cannot tell whether offline/index.php produced THIS response or
     *     was only included along the way: it asks whether PHP included that
     *     file at any point in the request. A scratch php-cgi probe on 15
     *     September 2026 included it (output thrown away) inside a response
     *     that was really a signed-in dashboard, and that dashboard went out
     *     with no `Vary: *`. So any other code that included offline/index.php
     *     would silently exempt its own response, and a worker from before
     *     #507 could then store that response and show it after sign-out.
     *     Include that file only from the 'offline' route in Router.php. (A
     *     search of web/ on 15 September 2026 found nothing else including
     *     it.)
     *
     * @return bool True when PHP has included the portal's own
     *              offline/index.php at any point in this request (today,
     *              only when Router.php answers the 'offline' route with it).
     */
    private static function offlinePageAnswered(): bool
    {
        $offlinePage = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public_html'
            . DIRECTORY_SEPARATOR . 'offline' . DIRECTORY_SEPARATOR . 'index.php');
        return is_string($offlinePage) === true && in_array($offlinePage, get_included_files(), true) === true;
    }

    /* ====================================================================== */
    /* Session helpers                                                        */
    /* ====================================================================== */

    /**
     * Start session with secure cookie parameters if not already active.
     *
     * Sets HttpOnly, Secure, SameSite=Lax flags on the session cookie to
     * prevent XSS theft and CSRF attacks.
     *
     * Also arranges the offline-copy marker for this response (#507) — see
     * registerOfflineCopyDecision(). That happens even when some other code
     * already started the session, which is why it comes before the early
     * return.
     *
     * @see https://owasp.org/www-community/controls/Session_Management_Cheat_Sheet
     *
     * @return void
     */
    public static function ensureSession(): void
    {
        self::registerOfflineCopyDecision();

        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // 🔒 Set secure cookie parameters before starting the session
        // See: https://www.php.net/manual/en/function.session-set-cookie-params.php
        session_set_cookie_params([
            'lifetime' => 0,            // Session cookie (expires when browser closes)
            'path'     => '/',
            'domain'   => '',           // Current domain only
            'secure'   => (isset($_SERVER['HTTPS']) === true && $_SERVER['HTTPS'] !== 'off'),
            'httponly'  => true,         // 🛡️ Prevents JavaScript access to session cookie
            'samesite'  => 'Lax',       // 🛡️ Prevents CSRF via cross-origin requests
        ]);

        session_start();
    }

    /**
     * Check if a user is currently authenticated.
     *
     * @return bool True if user is logged in
     */
    public static function check(): bool
    {
        self::ensureSession();
        return isset($_SESSION['user_id']);
    }

    /**
     * Require authentication or redirect to login page.
     * Saves the current URL so the user can be redirected back after login.
     *
     * @return void (terminates with redirect if not authenticated)
     */
    public static function requireLogin(): void
    {
        if (self::check() === false) {
            $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/');
            header('Location: /login?redirect=' . $redirect, true, 302);
            exit();
        }
    }

    /**
     * Check whether the active user is an event coordinator for the given
     * event (#341). Coordinators have edit / manage rights for ONE event
     * without site-wide admin privileges. Admins implicitly pass this check.
     *
     * Usage in event-mutating handlers:
     *   if (App::isAdmin() === false && Auth::isCoordinatorOf($eventId) === false) {
     *       http_response_code(403); exit('Forbidden');
     *   }
     */
    public static function isCoordinatorOf(int $eventId): bool
    {
        if ($eventId <= 0 || self::check() === false) {
            return false;
        }
        if (App::isAdmin() === true) {
            return true;
        }
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT 1 FROM tblEventCoordinators '
            . 'WHERE eventID = ? AND userID = ? AND revokedAt IS NULL LIMIT 1'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ii', $eventId, $userId);
        $stmt->execute();
        $ok = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($ok === false) {
            return false;
        }

        // 🛡️ DBS gate (#310). When safeguarding.dbs_required_for_coordinators
        //    is enabled, a coordinator must hold a valid (non-expired,
        //    non-revoked) DBS check or this method returns false. Settings
        //    lookup is per-request so toggling takes effect immediately.
        if ((string) Settings::get('safeguarding.dbs_required_for_coordinators', '0') === '1') {
            $stmt = $db->prepare(
                'SELECT 1 FROM tblDbsChecks '
                . 'WHERE userID = ? AND status = "valid" AND expiresAt >= CURDATE() '
                . 'ORDER BY dbsCheckID DESC LIMIT 1'
            );
            if ($stmt === false) {
                return false;
            }
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $hasDbs = (bool) $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($hasDbs === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check whether the active user is any kind of Event Team Hub member
     * for the given event (#386 Phase 1) — coordinator, crew leader/
     * participant, job assignee, or a `tblEventPeople` row (host / speaker
     * / organiser / …). Broader than `isCoordinatorOf()` (which grants
     * MANAGE rights); this grants VIEW rights on the Team Hub only.
     *
     * Mirrors `isCoordinatorOf()`'s shape exactly — admin bypass + DBS
     * gate come for free via the `isCoordinatorOf()` short-circuit below,
     * since a coordinator is always also a team member.
     *
     * Usage in the hub page:
     *   if ($eventId <= 0 || Auth::isEventTeamMember($eventId) === false) {
     *       http_response_code(403); exit('Forbidden');
     *   }
     */
    public static function isEventTeamMember(int $eventId): bool
    {
        if ($eventId <= 0 || self::check() === false) {
            return false;
        }
        if (self::isCoordinatorOf($eventId) === true) {
            return true;
        }
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }
        $db = App::db();

        // 🎨 Crew leader or participant.
        $stmt = $db->prepare(
            'SELECT 1 FROM tblEventCrewMembers m '
            . 'JOIN tblEventCrews c ON c.crewID = m.crewID '
            . 'WHERE c.eventID = ? AND m.userID = ? LIMIT 1'
        );
        if ($stmt !== false) {
            $stmt->bind_param('ii', $eventId, $userId);
            $stmt->execute();
            $ok = (bool) $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($ok === true) {
                return true;
            }
        }

        // 🧰 Job assignee.
        $stmt = $db->prepare(
            'SELECT 1 FROM tblEventJobAssignments a '
            . 'JOIN tblEventJobs j ON j.jobID = a.jobID '
            . 'WHERE j.eventID = ? AND a.userID = ? LIMIT 1'
        );
        if ($stmt !== false) {
            $stmt->bind_param('ii', $eventId, $userId);
            $stmt->execute();
            $ok = (bool) $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($ok === true) {
                return true;
            }
        }

        // 👤 Event person (host / speaker / musician / organiser / …).
        $stmt = $db->prepare(
            'SELECT 1 FROM tblEventPeople WHERE eventID = ? AND userID = ? LIMIT 1'
        );
        if ($stmt !== false) {
            $stmt->bind_param('ii', $eventId, $userId);
            $stmt->execute();
            $ok = (bool) $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($ok === true) {
                return true;
            }
        }

        return false;
    }

    /* ====================================================================== */
    /* CSRF protection                                                        */
    /* ====================================================================== */

    /**
     * Get or generate a CSRF token for form protection.
     *
     * @return string The CSRF token (64-character hex string)
     */
    public static function csrfToken(): string
    {
        self::ensureSession();

        if (isset($_SESSION['csrf_token']) === false) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    /**
     * Verify a submitted CSRF token using constant-time comparison.
     * Rotates the token after successful verification to prevent replay.
     *
     * @see https://owasp.org/www-community/attacks/csrf
     *
     * @param string $token The submitted token to verify
     *
     * @return bool True if the token is valid
     */
    public static function verifyCsrf(string $token): bool
    {
        self::ensureSession();

        if (isset($_SESSION['csrf_token']) === false) {
            return false;
        }

        $valid = hash_equals($_SESSION['csrf_token'], $token);

        // 🔄 Rotate the token after successful verification to prevent replay attacks
        if ($valid === true) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $valid;
    }

    /**
     * 🛡️ Validate and sanitize a redirect URL to prevent open redirect attacks.
     * Only allows relative paths on the same origin. Rejects protocol-relative
     * URLs, encoded traversals, backslash tricks, and external hosts.
     *
     * @see https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html
     *
     * @param string $url The raw redirect URL (typically from $_GET['redirect'])
     * @param string $fallback Fallback URL if validation fails (default '/')
     *
     * @return string A safe redirect URL
     */
    public static function safeRedirectUrl(string $url, string $fallback = '/'): string
    {
        // 🔍 Decode the URL to catch encoded bypass attempts (%2F, %5C, etc.)
        $decoded = rawurldecode($url);

        // 🚫 Must start with a single forward slash (relative path)
        if (str_starts_with($decoded, '/') === false) {
            return $fallback;
        }

        // 🚫 Reject protocol-relative URLs (//evil.com)
        if (str_starts_with($decoded, '//') === true) {
            return $fallback;
        }

        // 🚫 Reject backslash sequences that could be interpreted as protocol-relative
        if (str_contains($decoded, '\\') === true) {
            return $fallback;
        }

        // 🚫 Reject URLs containing a scheme (javascript:, data:, etc.)
        if (preg_match('#[a-zA-Z][a-zA-Z0-9+\-.]*:#', $decoded) === 1) {
            return $fallback;
        }

        // 🔍 Parse the URL — if it has a host component, it's an external redirect
        $parsed = parse_url($decoded);
        if ($parsed === false || isset($parsed['host']) === true) {
            return $fallback;
        }

        // ✅ URL is a safe relative path
        return $url;
    }

    /* ====================================================================== */
    /* Microsoft 365 OAuth flow                                               */
    /* ====================================================================== */

    /**
     * Begin the Microsoft 365 OAuth authorization flow.
     * Redirects the user to Microsoft's login page.
     *
     * @see https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow
     *
     * @return void (terminates with redirect)
     */
    public static function loginMS365(): void
    {
        global $SETTINGS;

        if (($SETTINGS['auth']['ms365']['enduser']['clientID'] ?? '') === '') {
            throw new RuntimeException('MS365 client ID not configured.');
        }

        $clientId    = $SETTINGS['auth']['ms365']['enduser']['clientID'];
        $redirectUri = $SETTINGS['auth']['ms365']['enduser']['redirectURI'];
        $tenantId    = $SETTINGS['auth']['ms365']['tenantID'];

        // 🔐 Generate state parameter for CSRF protection of the OAuth flow
        $state = bin2hex(random_bytes(16));
        self::ensureSession();
        $_SESSION['oauth_state'] = $state;

        $authUrl  = 'https://login.microsoftonline.com/' . $tenantId . '/oauth2/v2.0/authorize?';
        $authUrl .= http_build_query([
            'client_id'     => $clientId,
            'response_type' => 'code',
            'redirect_uri'  => $redirectUri,
            'response_mode' => 'query',
            'scope'         => 'openid email profile offline_access User.Read',
            'state'         => $state,
        ]);

        header('Location: ' . $authUrl, true, 302);
        exit();
    }

    /**
     * Handle the OAuth callback from Microsoft after user consent.
     * Exchanges the authorization code for tokens, verifies the JWT ID token,
     * upserts the user in the database, and creates a session.
     *
     * @see https://learn.microsoft.com/en-us/entra/identity-platform/id-tokens
     *
     * @return void (terminates with redirect on success or error message on failure)
     */
    public static function callbackMS365(): void
    {
        self::ensureSession();
        global $SETTINGS, $mysqli;

        /* ----------------------- 0. Rate limit OAuth callbacks ------------- */
        if (RateLimiter::isBlocked() === true) {
            self::oauthError('Too many authentication attempts. Please try again later.', 429);
        }

        /* ----------------------- 1. Validate OAuth state ------------------- */
        if (isset($_GET['state']) === false || hash_equals($_SESSION['oauth_state'] ?? '', $_GET['state'] ?? '') === false) {
            Logger::errorPlatform('Auth', 'Error', 'OAUTH_STATE', 'Invalid OAuth state parameter', '');
            self::oauthError('Invalid OAuth state. Please try signing in again.');
        }

        if (isset($_GET['code']) === false) {
            Logger::errorPlatform('Auth', 'Error', 'OAUTH_CODE', 'Authorization code missing from callback', '');
            self::oauthError('Authorization code missing. Please try signing in again.');
        }

        // 🧹 Clear the OAuth state to prevent replay
        unset($_SESSION['oauth_state']);

        $code         = $_GET['code'];
        $clientId     = $SETTINGS['auth']['ms365']['enduser']['clientID'];
        $clientSecret = $SETTINGS['auth']['ms365']['enduser']['clientSecret'];
        $redirectUri  = $SETTINGS['auth']['ms365']['enduser']['redirectURI'];
        $tenantId     = $SETTINGS['auth']['ms365']['tenantID'];

        /* ----------------------- 2. Exchange code for tokens --------------- */
        $tokenEndpoint = 'https://login.microsoftonline.com/' . $tenantId . '/oauth2/v2.0/token';
        $tokenResp = self::curlPost($tokenEndpoint, [
            'client_id'     => $clientId,
            'scope'         => 'openid email profile offline_access User.Read',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
            'client_secret' => $clientSecret,
        ]);

        if ($tokenResp === null) {
            self::oauthError('Token request failed. Please try signing in again.');
        }

        $tokenData = json_decode($tokenResp, true);
        if (json_last_error() !== JSON_ERROR_NONE || isset($tokenData['id_token']) === false) {
            Logger::errorPlatform('Auth', 'Error', 'TOKEN_RESPONSE', 'Invalid token response from MS365', '');
            self::oauthError('Invalid token response. Please try signing in again.');
        }

        $idToken = $tokenData['id_token'];

        /* ----------------------- 3. Verify ID token via JWKS --------------- */
        // 🔐 Fetch the JWKS keys from Microsoft's discovery endpoint
        // See: https://learn.microsoft.com/en-us/entra/identity-platform/access-tokens#validating-tokens
        $jwksUri  = 'https://login.microsoftonline.com/' . $tenantId . '/discovery/v2.0/keys';
        $jwksJson = file_get_contents($jwksUri);
        if ($jwksJson === false) {
            Logger::errorPlatform('Auth', 'Error', 'JWKS_FETCH', 'Unable to retrieve JWKS from ' . $jwksUri, '');
            self::oauthError('Unable to retrieve signing keys. Please try again later.');
        }

        $jwks = json_decode($jwksJson, true);

        try {
            $payload = SimpleJWT::decode($idToken, $jwks, [
                'aud' => $clientId,
                'iss' => [
                    'https://login.microsoftonline.com/' . $tenantId . '/v2.0',
                    'https://sts.windows.net/' . $tenantId . '/',
                ],
            ]);
        } catch (RuntimeException $ex) {
            Logger::errorPlatform('JWT', 'Error', 'VERIFY_FAIL', $ex->getMessage(), '');
            self::oauthError('Token verification failed. Please try signing in again.');
        }

        /* ----------------------- 4. Extract user info ---------------------- */
        $sub    = $payload['sub'] ?? $payload['oid'] ?? '';
        $email  = strtolower($payload['preferred_username'] ?? ($payload['email'] ?? ''));
        $name   = $payload['name'] ?? '';
        $avatar = $payload['picture'] ?? '';

        if ($email === '') {
            Logger::errorPlatform('Auth', 'Error', 'NO_EMAIL', 'No email in ID token payload', '');
            self::oauthError('Unable to determine user email from token.');
        }

        /* ----------------------- 5. Find or create user -------------------- */
        // 🔍 Check if there's already a linked account for this MS365 sub
        $userId = self::findUserByLink('ms365', $sub !== '' ? $sub : $email, $mysqli);

        if ($userId === null) {
            // 🔍 Try to match by email address (auto-link / legacy upsert)
            $userId = self::findUserByEmail($email, $mysqli);

            if ($userId !== null) {
                // ♻️ Update existing user's name and avatar
                $upd = $mysqli->prepare('UPDATE tblUsers SET fullName = ?, avatarPath = ?, isActive = 1 WHERE userID = ?');
                if ($upd !== false) {
                    $upd->bind_param('ssi', $name, $avatar, $userId);
                    $upd->execute();
                    $upd->close();
                }

                // 🔗 Auto-link the MS365 account
                self::linkAccount($userId, 'ms365', $sub !== '' ? $sub : $email, $email, $mysqli);
            } else {
                // 🚫 Deactivated-account guard (#B7b). See
                // findInactiveUserByEmail()'s docblock for the full
                // rationale — without this, an offboarded user signing
                // back in via MS365 would crash createUser() on the
                // emailAddress UNIQUE constraint instead of seeing a
                // clean error.
                if (self::findInactiveUserByEmail($email, $mysqli) !== null) {
                    Logger::activity('LoginBlocked', 'MS365 sign-in attempt for a deactivated account: ' . $email);
                    self::oauthError('This account has been deactivated. Please contact your administrator.', 403);
                }

                // ➕ Create new user + link
                $userId = self::createUser($name, $email, $avatar, $mysqli);
                self::linkAccount($userId, 'ms365', $sub !== '' ? $sub : $email, $email, $mysqli);
            }
        } else {
            // ♻️ Update name/avatar on existing linked user
            $upd = $mysqli->prepare('UPDATE tblUsers SET fullName = ?, avatarPath = ? WHERE userID = ?');
            if ($upd !== false) {
                $upd->bind_param('ssi', $name, $avatar, $userId);
                $upd->execute();
                $upd->close();
            }
        }

        /* ----------------------- 6. Create session -------------------------- */
        // 🔄 Regenerate session ID to prevent session fixation attacks
        // See: https://owasp.org/www-community/attacks/Session_fixation
        session_regenerate_id(true);

        $_SESSION['user_id']    = $userId;
        $_SESSION['user_name']  = $name;
        $_SESSION['user_email'] = $email;

        // 🌐 Set active site ID for multi-site context
        self::setSessionSiteId($userId, $mysqli);

        /* ----------------------- 7. 🔐 2FA gate (#B2) ------------------------ */
        // 🛡️ SSO auto-links by email (step 5 above), so WITHOUT this gate a
        // 2FA-enrolled user — or an attacker who has taken over their SSO
        // identity — would sail straight past the TOTP challenge that the
        // password login path enforces. Mirrors
        // `_apps/auth/login/index.php`'s POST handler EXACTLY: same session
        // keys (`2fa_user_id`, `login_redirect`), same unset() pair, same
        // deviceIsTrusted() "remembered device" bypass, same redirect
        // target. The verify handler promotes `2fa_user_id` back to
        // `user_id` on a successful challenge.
        if (self::userRequires2fa($userId) === true && self::deviceIsTrusted($userId) === false) {
            $_SESSION['2fa_user_id']    = $userId;
            $_SESSION['login_redirect'] = self::safeRedirectUrl($_GET['redirect'] ?? '/');
            unset($_SESSION['user_id'], $_SESSION['2fa_passed']);
            Logger::activity('LoginMS365Pending2fa', 'MS365 login pending 2FA challenge', $userId);
            header('Location: /auth/2fa/verify', true, 302);
            exit();
        }

        /* ----------------------- 8. Redirect --------------------------------- */
        // 📝 Log the successful login
        Logger::activity('LoginMS365', 'User logged in via Microsoft 365');

        $target = self::safeRedirectUrl($_GET['redirect'] ?? '/');
        header('Location: ' . $target, true, 302);
        exit();
    }

    /* ====================================================================== */
    /* Google OAuth flow                                                      */
    /* ====================================================================== */

    /**
     * Begin the Google OAuth authorization flow.
     * Redirects the user to Google's consent screen.
     *
     * @see https://developers.google.com/identity/protocols/oauth2/web-server
     *
     * @return void (terminates with redirect)
     */
    public static function loginGoogle(): void
    {
        global $SETTINGS;

        if (($SETTINGS['auth']['google']['clientID'] ?? '') === '') {
            throw new RuntimeException('Google OAuth client ID not configured.');
        }

        $clientId    = $SETTINGS['auth']['google']['clientID'];
        $redirectUri = $SETTINGS['auth']['google']['redirectURI'];

        // 🔐 Generate state parameter for CSRF protection of the OAuth flow
        $state = bin2hex(random_bytes(16));
        self::ensureSession();
        $_SESSION['oauth_state'] = $state;

        $authUrl  = 'https://accounts.google.com/o/oauth2/v2/auth?';
        $params   = [
            'client_id'     => $clientId,
            'response_type' => 'code',
            'redirect_uri'  => $redirectUri,
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'online',
            'prompt'        => 'select_account',
        ];

        // 🏢 Restrict to a specific Google Workspace domain if configured
        $hd = $SETTINGS['auth']['google']['hostedDomain'] ?? '';
        if ($hd !== '') {
            $params['hd'] = $hd;
        }

        $authUrl .= http_build_query($params);

        header('Location: ' . $authUrl, true, 302);
        exit();
    }

    /**
     * Handle the OAuth callback from Google after user consent.
     * Exchanges the authorization code for tokens, verifies the ID token via
     * Google's JWKS, upserts the user, creates a linked account record, and
     * starts a session.
     *
     * @see https://developers.google.com/identity/protocols/oauth2/openid-connect
     *
     * @return void (terminates with redirect on success or error message on failure)
     */
    public static function callbackGoogle(): void
    {
        self::ensureSession();
        global $SETTINGS, $mysqli;

        /* ----------------------- 0. Rate limit OAuth callbacks ------------- */
        if (RateLimiter::isBlocked() === true) {
            self::oauthError('Too many authentication attempts. Please try again later.', 429);
        }

        /* ----------------------- 1. Validate OAuth state ------------------- */
        if (isset($_GET['state']) === false || hash_equals($_SESSION['oauth_state'] ?? '', $_GET['state'] ?? '') === false) {
            Logger::errorPlatform('Auth', 'Error', 'OAUTH_STATE', 'Invalid Google OAuth state parameter', '');
            self::oauthError('Invalid OAuth state. Please try signing in again.');
        }

        if (isset($_GET['code']) === false) {
            Logger::errorPlatform('Auth', 'Error', 'OAUTH_CODE', 'Google authorization code missing from callback', '');
            self::oauthError('Authorization code missing. Please try signing in again.');
        }

        // 🧹 Clear the OAuth state to prevent replay
        unset($_SESSION['oauth_state']);

        $code         = $_GET['code'];
        $clientId     = $SETTINGS['auth']['google']['clientID'];
        $clientSecret = $SETTINGS['auth']['google']['clientSecret'];
        $redirectUri  = $SETTINGS['auth']['google']['redirectURI'];

        /* ----------------------- 2. Exchange code for tokens --------------- */
        $tokenResp = self::curlPost('https://oauth2.googleapis.com/token', [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        if ($tokenResp === null) {
            self::oauthError('Token request failed. Please try signing in again.');
        }

        $tokenData = json_decode($tokenResp, true);
        if (json_last_error() !== JSON_ERROR_NONE || isset($tokenData['id_token']) === false) {
            Logger::errorPlatform('Auth', 'Error', 'TOKEN_RESPONSE', 'Invalid token response from Google', '');
            self::oauthError('Invalid token response. Please try signing in again.');
        }

        $idToken = $tokenData['id_token'];

        /* ----------------------- 3. Verify ID token via JWKS --------------- */
        // 🔐 Fetch Google's JWKS keys
        // See: https://developers.google.com/identity/protocols/oauth2/openid-connect#validatinganidtoken
        $jwksUri  = 'https://www.googleapis.com/oauth2/v3/certs';
        $jwksJson = file_get_contents($jwksUri);
        if ($jwksJson === false) {
            Logger::errorPlatform('Auth', 'Error', 'JWKS_FETCH', 'Unable to retrieve JWKS from Google', '');
            self::oauthError('Unable to retrieve signing keys. Please try again later.');
        }

        $jwks = json_decode($jwksJson, true);

        try {
            $payload = SimpleJWT::decode($idToken, $jwks, [
                'aud' => $clientId,
                'iss' => ['https://accounts.google.com', 'accounts.google.com'],
            ]);
        } catch (RuntimeException $ex) {
            Logger::errorPlatform('JWT', 'Error', 'VERIFY_FAIL', $ex->getMessage(), '');
            self::oauthError('Token verification failed. Please try signing in again.');
        }

        /* ----------------------- 4. Extract user info ---------------------- */
        $sub    = $payload['sub'] ?? '';
        $email  = strtolower($payload['email'] ?? '');
        $name   = $payload['name'] ?? '';
        $avatar = $payload['picture'] ?? '';

        if ($email === '' || $sub === '') {
            Logger::errorPlatform('Auth', 'Error', 'NO_EMAIL', 'No email/sub in Google ID token', '');
            self::oauthError('Unable to determine user identity from token.');
        }

        // 🏢 Enforce hosted domain restriction if configured
        $requiredHd = $SETTINGS['auth']['google']['hostedDomain'] ?? '';
        if ($requiredHd !== '') {
            $tokenHd = $payload['hd'] ?? '';
            if (strtolower($tokenHd) !== strtolower($requiredHd)) {
                Logger::errorPlatform('Auth', 'Error', 'HD_MISMATCH', 'Google domain mismatch: expected ' . $requiredHd . ', got ' . $tokenHd, '');
                self::oauthError('Your Google account is not from the allowed organisation.');
            }
        }

        /* ----------------------- 5. Find or create user -------------------- */
        // 🔍 Check if there's already a linked account for this Google sub
        $userId = self::findUserByLink('google', $sub, $mysqli);

        if ($userId === null) {
            // 🔍 Try to match by email address (auto-link)
            $userId = self::findUserByEmail($email, $mysqli);

            if ($userId !== null) {
                // ♻️ Update name/avatar from Google profile
                $upd = $mysqli->prepare('UPDATE tblUsers SET fullName = ?, avatarPath = ?, isActive = 1 WHERE userID = ?');
                if ($upd !== false) {
                    $upd->bind_param('ssi', $name, $avatar, $userId);
                    $upd->execute();
                    $upd->close();
                }

                // 🔗 Auto-link the Google account
                self::linkAccount($userId, 'google', $sub, $email, $mysqli);
            } else {
                // 🚫 Deactivated-account guard (#B7b) — same rationale as
                // callbackMS365() above.
                if (self::findInactiveUserByEmail($email, $mysqli) !== null) {
                    Logger::activity('LoginBlocked', 'Google sign-in attempt for a deactivated account: ' . $email);
                    self::oauthError('This account has been deactivated. Please contact your administrator.', 403);
                }

                // ➕ Create new user + link
                $userId = self::createUser($name, $email, $avatar, $mysqli);
                self::linkAccount($userId, 'google', $sub, $email, $mysqli);
            }
        } else {
            // ♻️ Update name/avatar on existing linked user
            $upd = $mysqli->prepare('UPDATE tblUsers SET fullName = ?, avatarPath = ? WHERE userID = ?');
            if ($upd !== false) {
                $upd->bind_param('ssi', $name, $avatar, $userId);
                $upd->execute();
                $upd->close();
            }
        }

        /* ----------------------- 6. Create session -------------------------- */
        session_regenerate_id(true);

        $_SESSION['user_id']    = $userId;
        $_SESSION['user_name']  = $name;
        $_SESSION['user_email'] = $email;

        // 🌐 Set active site ID for multi-site context
        self::setSessionSiteId($userId, $mysqli);

        /* ----------------------- 7. 🔐 2FA gate (#B2) ------------------------ */
        // 🛡️ Same SSO-bypass hole as callbackMS365() above — see the comment
        // there for the full rationale. Mirrors the password login path's
        // gate EXACTLY: same session keys, same unset() pair, same
        // deviceIsTrusted() bypass, same redirect target.
        if (self::userRequires2fa($userId) === true && self::deviceIsTrusted($userId) === false) {
            $_SESSION['2fa_user_id']    = $userId;
            $_SESSION['login_redirect'] = self::safeRedirectUrl($_GET['redirect'] ?? '/');
            unset($_SESSION['user_id'], $_SESSION['2fa_passed']);
            Logger::activity('LoginGooglePending2fa', 'Google login pending 2FA challenge', $userId);
            header('Location: /auth/2fa/verify', true, 302);
            exit();
        }

        /* ----------------------- 8. Redirect --------------------------------- */
        Logger::activity('LoginGoogle', 'User logged in via Google OAuth');

        $target = self::safeRedirectUrl($_GET['redirect'] ?? '/');
        header('Location: ' . $target, true, 302);
        exit();
    }

    /* ====================================================================== */
    /* Local account authentication                                           */
    /* ====================================================================== */

    /**
     * Authenticate a user with username or email and password (local account).
     * Queries tblLocalAccounts (joined with tblUsers) for the password hash,
     * checks rate limiting, verifies the hash, and creates a session.
     *
     * @see https://www.php.net/manual/en/function.password-verify.php
     *
     * @param string $identifier The user's username or email address
     * @param string $password   The plaintext password to verify
     *
     * @return bool True if login succeeded, false otherwise
     */
    public static function loginLocal(string $identifier, string $password): bool
    {
        self::ensureSession();
        global $mysqli;

        $identifier = strtolower(trim($identifier));

        // 🛡️ Check rate limiting BOTH per-IP AND per-username (composite).
        // The composite check defends single-account-targeted attacks that
        // rotate IPs to evade the per-IP threshold (#52).
        if (RateLimiter::isUserOrIpBlocked($identifier) === true) {
            Logger::activity('LoginBlocked', 'Rate-limited login attempt for: ' . $identifier);
            return false;
        }

        // 🔍 Look up the user via tblLocalAccounts joined with tblUsers
        // Accepts either a username (tblLocalAccounts.username) or an email
        // (tblUsers.emailAddress) so users can sign in with either one.
        $stmt = $mysqli->prepare(
            'SELECT LA.localID, LA.passwordHash, '
            . 'U.userID, U.fullName, U.emailAddress, U.isActive '
            . 'FROM tblLocalAccounts LA '
            . 'JOIN tblUsers U ON U.userID = LA.userID '
            . 'WHERE LA.username = ? OR U.emailAddress = ? '
            . 'LIMIT 1'
        );

        if ($stmt === false) {
            Logger::errorPlatform('Auth', 'Error', 'DB_PREPARE', 'Failed to prepare login query: ' . $mysqli->error, '');
            return false;
        }

        $stmt->bind_param('ss', $identifier, $identifier);
        $stmt->execute();
        $result = $stmt->get_result();
        $row    = $result->fetch_assoc();
        $stmt->close();

        // 🔐 Verify the user exists, is active, and has a password hash
        if ($row === null || (int) $row['isActive'] !== 1 || ($row['passwordHash'] ?? '') === '') {
            // 📝 Log the failed attempt (used by RateLimiter)
            Logger::activity('LoginFailed', 'Failed login attempt for: ' . $identifier);
            return false;
        }

        // 🔑 Verify password using PHP's built-in password_verify
        // See: https://www.php.net/manual/en/function.password-verify.php
        if (password_verify($password, $row['passwordHash']) === false) {
            Logger::activity('LoginFailed', 'Incorrect password for: ' . $identifier);
            return false;
        }

        // 🔄 Regenerate session ID to prevent session fixation
        // See: https://owasp.org/www-community/attacks/Session_fixation
        session_regenerate_id(true);

        // ✅ Create the user session using data from tblUsers
        $_SESSION['user_id']    = (int) $row['userID'];
        $_SESSION['user_name']  = $row['fullName'];
        $_SESSION['user_email'] = $row['emailAddress'];

        // 🌐 Set active site ID for multi-site context
        self::setSessionSiteId((int) $row['userID'], $mysqli);

        // 🕐 Update lastLogin timestamp on tblLocalAccounts
        $updateStmt = $mysqli->prepare('UPDATE tblLocalAccounts SET lastLogin = NOW() WHERE localID = ?');
        if ($updateStmt !== false) {
            $localId = (int) $row['localID'];
            $updateStmt->bind_param('i', $localId);
            $updateStmt->execute();
            $updateStmt->close();
        }

        // 📝 Log the successful login
        Logger::activity('LoginLocal', 'User logged in with local account');

        return true;
    }

    /* ====================================================================== */
    /* Password policy                                                        */
    /* ====================================================================== */

    /**
     * Validate a password against the configurable policy stored in tblSettings.
     *
     * Settings used (all under auth.password.*):
     *   minLength        – minimum character count (default 12)
     *   maxLength        – maximum character count (default 128 — bcrypt truncates at 72)
     *   requireUppercase – must contain A-Z (default true)
     *   requireLowercase – must contain a-z (default true)
     *   requireNumber    – must contain 0-9 (default true)
     *   requireSpecial   – must contain a non-alphanumeric char (default true)
     *
     * @param string $password The password to validate
     *
     * @return array{valid: bool, errors: list<string>} Validation result
     */
    public static function validatePassword(string $password): array
    {
        $errors = [];

        $minLength      = (int) (App::settings('auth.password.minLength') ?? '12');
        $maxLength      = (int) (App::settings('auth.password.maxLength') ?? '128');
        $requireUpper   = (App::settings('auth.password.requireUppercase') ?? 'true') === 'true';
        $requireLower   = (App::settings('auth.password.requireLowercase') ?? 'true') === 'true';
        $requireNumber  = (App::settings('auth.password.requireNumber') ?? 'true') === 'true';
        $requireSpecial = (App::settings('auth.password.requireSpecial') ?? 'true') === 'true';

        // 🔢 Length checks — use byte length (strlen) because that's what bcrypt sees.
        $length = strlen($password);
        if ($length < $minLength) {
            $errors[] = 'Password must be at least ' . $minLength . ' characters.';
        }
        if ($maxLength > 0 && $length > $maxLength) {
            $errors[] = 'Password must be no more than ' . $maxLength . ' characters.';
        }

        if ($requireUpper === true && preg_match('/[A-Z]/', $password) !== 1) {
            $errors[] = 'Password must contain at least one uppercase letter.';
        }

        if ($requireLower === true && preg_match('/[a-z]/', $password) !== 1) {
            $errors[] = 'Password must contain at least one lowercase letter.';
        }

        if ($requireNumber === true && preg_match('/[0-9]/', $password) !== 1) {
            $errors[] = 'Password must contain at least one number.';
        }

        if ($requireSpecial === true && preg_match('/[^a-zA-Z0-9]/', $password) !== 1) {
            $errors[] = 'Password must contain at least one special character.';
        }

        return ['valid' => (count($errors) === 0), 'errors' => $errors];
    }

    /**
     * Return the active password policy as a structured array suitable for
     * rendering on password-set forms (so users see the requirements upfront).
     *
     * @return array{minLength:int, maxLength:int, requireUppercase:bool, requireLowercase:bool, requireNumber:bool, requireSpecial:bool, rules:list<string>}
     */
    public static function passwordPolicy(): array
    {
        $minLength      = (int) (App::settings('auth.password.minLength') ?? '12');
        $maxLength      = (int) (App::settings('auth.password.maxLength') ?? '128');
        $requireUpper   = (App::settings('auth.password.requireUppercase') ?? 'true') === 'true';
        $requireLower   = (App::settings('auth.password.requireLowercase') ?? 'true') === 'true';
        $requireNumber  = (App::settings('auth.password.requireNumber') ?? 'true') === 'true';
        $requireSpecial = (App::settings('auth.password.requireSpecial') ?? 'true') === 'true';

        $rules = ['At least ' . $minLength . ' characters'];
        if ($requireUpper === true) {
            $rules[] = 'At least one uppercase letter (A-Z)';
        }
        if ($requireLower === true) {
            $rules[] = 'At least one lowercase letter (a-z)';
        }
        if ($requireNumber === true) {
            $rules[] = 'At least one number (0-9)';
        }
        if ($requireSpecial === true) {
            $rules[] = 'At least one special character (e.g. !@#$%^&*)';
        }

        return [
            'minLength'        => $minLength,
            'maxLength'        => $maxLength,
            'requireUppercase' => $requireUpper,
            'requireLowercase' => $requireLower,
            'requireNumber'    => $requireNumber,
            'requireSpecial'   => $requireSpecial,
            'rules'            => $rules,
        ];
    }

    /* ====================================================================== */
    /* TOTP secret encryption (#B1)                                           */
    /* ====================================================================== */

    /**
     * Encrypt a value for storage (e.g. a TOTP shared secret) using the same
     * libsodium secretbox scheme as the settings encryptor.
     *
     * 🔐 Thin wrapper around the global `encrypt_setting()` helper defined in
     * bootstrap.php — kept here so 2FA call sites (`_apps/auth/2fa/*.php`)
     * can go through `Auth::` like every other Auth-owned secret operation,
     * without reaching past the class into the global namespace. Signature
     * mirrors `encrypt_setting()` exactly.
     *
     * @param string $plain Plaintext value to encrypt
     *
     * @return string Base64-encoded "nonce + ciphertext", safe for storage
     */
    public static function encrypt(string $plain): string
    {
        return encrypt_setting($plain);
    }

    /**
     * Decrypt a value previously produced by `Auth::encrypt()` /
     * `encrypt_setting()`.
     *
     * 🔐 Thin wrapper around the global `decrypt_setting()` helper — see
     * `Auth::encrypt()` above. Matches `decrypt_setting()`'s contract
     * exactly: returns an EMPTY STRING (never `false`) if the value is
     * malformed, was encrypted under a different key, or has been tampered
     * with — callers must check `!== ''`, not `!== false`.
     *
     * @param string $encoded Base64-encoded ciphertext (nonce prepended)
     *
     * @return string Decrypted plaintext, or '' on failure
     */
    public static function decrypt(string $encoded): string
    {
        // 🛡️ Fail SOFT (security-review follow-up). decrypt_setting() can
        // THROW (e.g. SodiumException when a corrupt/truncated ciphertext
        // decodes to fewer than the nonce length) rather than returning '',
        // which would fatal a login flow mid-request. A stored secret is only
        // ever written as valid ciphertext, so this only triggers on genuine
        // DB corruption — return '' (treated by callers as "no/invalid
        // secret") instead of a 500.
        try {
            return decrypt_setting($encoded);
        } catch (\Throwable $e) {
            error_log('Auth::decrypt() failed: ' . $e->getMessage());
            return '';
        }
    }

    /* ====================================================================== */
    /* Trusted devices (2FA — remember this device)                           */
    /* ====================================================================== */

    /**
     * Cookie name used to remember a trusted device after a 2FA challenge.
     * Only the SHA-256 of the cookie value is stored in tblTrustedDevices.
     */
    private const TRUSTED_DEVICE_COOKIE = 'portal_td';

    /**
     * Whether the given user has TOTP 2FA enabled and therefore must pass
     * the /auth/2fa/verify challenge after primary authentication.
     * Returns false on DB error (fail open — primary auth still applies).
     */
    public static function userRequires2fa(int $userId): bool
    {
        $stmt = App::db()->prepare(
            'SELECT totpEnabled FROM tblUsers WHERE userID = ? LIMIT 1'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row !== null && (int) ($row['totpEnabled'] ?? 0) === 1;
    }

    /**
     * Issue a trusted-device cookie + DB record for the given user.
     * Called right after a successful 2FA verify, only if the user ticked
     * "Trust this device for X days".
     */
    public static function issueTrustedDevice(int $userId): void
    {
        $days = (int) (App::settings('auth.twoFactor.trustedDeviceDays') ?? '30');
        if ($days < 1) {
            $days = 30;
        }

        // 🔐 64-char hex token; only the SHA-256 hash is stored.
        $token = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $token);

        $expires = date('Y-m-d H:i:s', time() + ($days * 86400));
        $label   = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $ip      = self::clientIpForCookie();

        $db = App::db();
        $stmt = $db->prepare(
            'INSERT INTO tblTrustedDevices (userID, tokenHash, label, createdIP, expiresAt) '
            . 'VALUES (?, ?, ?, ?, ?)'
        );
        if ($stmt !== false) {
            $stmt->bind_param('issss', $userId, $hash, $label, $ip, $expires);
            $stmt->execute();
            $stmt->close();
        }

        // 🍪 Set the cookie. HttpOnly + Secure + SameSite=Lax + 2FA-windowed lifetime.
        setcookie(self::TRUSTED_DEVICE_COOKIE, $token, [
            'expires'  => time() + ($days * 86400),
            'path'     => '/',
            'secure'   => (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Check whether the requesting browser carries a valid, non-expired,
     * non-revoked trusted-device cookie for the given user. If yes, the
     * 2FA challenge should be skipped.
     *
     * Also updates lastSeenAt as a side-effect on a successful match —
     * lets admins see "last seen" in the future device-management UI.
     */
    public static function deviceIsTrusted(int $userId): bool
    {
        $token = $_COOKIE[self::TRUSTED_DEVICE_COOKIE] ?? '';
        if ($token === '' || ctype_xdigit($token) === false || strlen($token) !== 64) {
            return false;
        }
        $hash = hash('sha256', $token);

        $db = App::db();
        $stmt = $db->prepare(
            'SELECT deviceID FROM tblTrustedDevices '
            . 'WHERE userID = ? AND tokenHash = ? '
            . 'AND revokedAt IS NULL AND expiresAt > NOW() '
            . 'LIMIT 1'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('is', $userId, $hash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            return false;
        }

        // 🕐 Bump lastSeenAt (ON UPDATE CURRENT_TIMESTAMP fires on any UPDATE).
        $touch = $db->prepare(
            'UPDATE tblTrustedDevices SET lastSeenAt = NOW() WHERE deviceID = ?'
        );
        if ($touch !== false) {
            $id = (int) $row['deviceID'];
            $touch->bind_param('i', $id);
            $touch->execute();
            $touch->close();
        }

        return true;
    }

    /**
     * Revoke a single trusted device (used by /account device-management UI
     * and on password change / account deletion).
     */
    public static function revokeTrustedDevice(int $deviceId, int $userId): void
    {
        $stmt = App::db()->prepare(
            'UPDATE tblTrustedDevices SET revokedAt = NOW() '
            . 'WHERE deviceID = ? AND userID = ?'
        );
        if ($stmt !== false) {
            $stmt->bind_param('ii', $deviceId, $userId);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Revoke ALL trusted devices for a user. Called on password change,
     * account deletion, and when an admin disables a user.
     */
    public static function revokeAllTrustedDevices(int $userId): void
    {
        $stmt = App::db()->prepare(
            'UPDATE tblTrustedDevices SET revokedAt = NOW() '
            . 'WHERE userID = ? AND revokedAt IS NULL'
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Client IP detection for cookie audit fields. Mirrors RateLimiter's
     * logic — CF > XFF > REMOTE_ADDR. Kept private to Auth since it's
     * advisory only (not used for any security gate here).
     */
    private static function clientIpForCookie(): string
    {
        // 🛑 This used to read the Cloudflare and X-Forwarded-For headers
        //    directly and believe them. Anybody can send those headers, so
        //    anybody could claim any address they liked. RateLimiter::clientIp()
        //    is now the ONE place in the portal that decides which address to
        //    believe: it trusts those headers only when the request really
        //    arrived through a proxy listed in the portal.trustedProxies
        //    setting, and otherwise uses the address the connection actually
        //    came from. Delegating means a fix to that rule reaches this code
        //    too, instead of this copy quietly drifting out of step - which is
        //    exactly how it went wrong. Here the address is written into the
        //    trusted-device sign-in record, a record that is only worth keeping
        //    if it is true.
        $ip = RateLimiter::clientIp();
        return substr((string) $ip, 0, 45);
    }

    /* ====================================================================== */
    /* SSO configuration checks                                               */
    /* ====================================================================== */

    /**
     * Check whether Microsoft 365 OAuth credentials are configured.
     * Used to conditionally show the MS365 sign-in button on the login page.
     *
     * @return bool True if the MS365 enduser client ID is non-empty
     */
    public static function isMS365Configured(): bool
    {
        return (App::settings('auth.ms365.enduser.clientID') ?? '') !== '';
    }

    /**
     * Check whether Google OAuth credentials are configured.
     * Used to conditionally show the Google sign-in button on the login page.
     *
     * @return bool True if the Google client ID is non-empty
     */
    public static function isGoogleConfigured(): bool
    {
        return (App::settings('auth.google.clientID') ?? '') !== '';
    }

    /* ====================================================================== */
    /* Account linking                                                        */
    /* ====================================================================== */

    /**
     * Link an external identity provider account to a portal user.
     *
     * @param int      $userId       The portal user ID
     * @param string   $provider     Provider name (ms365, google, local)
     * @param string   $providerSub  Provider-specific unique identifier
     * @param string   $providerEmail Email from the provider
     * @param \mysqli  $db           Database connection
     *
     * @return bool True if the link was created successfully
     */
    public static function linkAccount(int $userId, string $provider, string $providerSub, string $providerEmail, \mysqli $db): bool
    {
        $stmt = $db->prepare(
            'INSERT INTO tblLinkedAccounts (userID, provider, providerSub, providerEmail) '
            . 'VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE providerEmail = VALUES(providerEmail)'
        );
        if ($stmt === false) {
            Logger::errorPlatform('Auth', 'Error', 'LINK_PREP', 'Failed to prepare link insert: ' . $db->error, '');
            return false;
        }

        $stmt->bind_param('isss', $userId, $provider, $providerSub, $providerEmail);
        $result = $stmt->execute();
        $stmt->close();

        if ($result === true) {
            Logger::activity('AccountLink', 'Linked ' . $provider . ' account (' . $providerEmail . ')');
        }

        return $result;
    }

    /**
     * Unlink an external provider from a user. Safety check: will refuse to
     * unlink if it would leave the user with zero login methods.
     *
     * @param int      $userId   The portal user ID
     * @param int      $linkID   The tblLinkedAccounts.linkID to remove
     * @param \mysqli  $db       Database connection
     *
     * @return array{success: bool, error: string}
     */
    public static function unlinkAccount(int $userId, int $linkID, \mysqli $db): array
    {
        // 🛡️ Verify the link belongs to this user
        $stmt = $db->prepare('SELECT provider, providerEmail FROM tblLinkedAccounts WHERE linkID = ? AND userID = ?');
        if ($stmt === false) {
            return ['success' => false, 'error' => 'Database error.'];
        }
        $stmt->bind_param('ii', $linkID, $userId);
        $stmt->execute();
        $link = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($link === null) {
            return ['success' => false, 'error' => 'Link not found.'];
        }

        // 🛡️ Safety: ensure the user has at least 2 login methods before unlinking
        $methodCount = self::countLoginMethods($userId, $db);
        if ($methodCount <= 1) {
            return ['success' => false, 'error' => 'Cannot unlink your only login method. Add another method first.'];
        }

        $del = $db->prepare('DELETE FROM tblLinkedAccounts WHERE linkID = ? AND userID = ?');
        if ($del === false) {
            return ['success' => false, 'error' => 'Database error.'];
        }
        $del->bind_param('ii', $linkID, $userId);
        $del->execute();
        $del->close();

        Logger::activity('AccountUnlink', 'Unlinked ' . $link['provider'] . ' account (' . ($link['providerEmail'] ?? '') . ')');

        return ['success' => true, 'error' => ''];
    }

    /**
     * Get all linked external accounts for a user.
     *
     * @param int     $userId The portal user ID
     * @param \mysqli $db     Database connection
     *
     * @return array List of linked account rows
     */
    public static function getLinkedAccounts(int $userId, \mysqli $db): array
    {
        $stmt = $db->prepare(
            'SELECT linkID, provider, providerSub, providerEmail, linkedAt '
            . 'FROM tblLinkedAccounts WHERE userID = ? ORDER BY linkedAt ASC'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * Count the total number of login methods available to a user.
     * Includes: local account (if exists) + linked accounts + WebAuthn credentials.
     *
     * @param int     $userId The portal user ID
     * @param \mysqli $db     Database connection
     *
     * @return int Number of available login methods
     */
    public static function countLoginMethods(int $userId, \mysqli $db): int
    {
        $count = 0;

        // 📝 Check for local account
        $stmt = $db->prepare('SELECT 1 FROM tblLocalAccounts WHERE userID = ? LIMIT 1');
        if ($stmt !== false) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $count++;
            }
            $stmt->close();
        }

        // 🔗 Count linked external accounts
        $stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM tblLinkedAccounts WHERE userID = ?');
        if ($stmt !== false) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $count += (int) ($row['cnt'] ?? 0);
            $stmt->close();
        }

        // 🔐 Count WebAuthn credentials
        $stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM tblWebAuthnCredentials WHERE userID = ?');
        if ($stmt !== false) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $count += (int) ($row['cnt'] ?? 0);
            $stmt->close();
        }

        return $count;
    }

    /* ====================================================================== */
    /* Logout                                                                 */
    /* ====================================================================== */

    /**
     * Destroy the current session, clear what the browser kept, then send the
     * visitor to the home page.
     *
     * WHAT WAS WRONG (#507): this used to end the session and redirect, and do
     * nothing else. The portal's service worker (web/public_html/sw.js) had
     * kept copies of the signed-in pages, and those stayed readable offline for
     * the next person to use the browser.
     *
     * WHAT IT DOES NOW
     *   1. Ends the session exactly as before.
     *   2. Sends `Clear-Site-Data: "cache"`. That ASKS the browser to empty its
     *      ordinary download cache for this site — which matters for signed-in
     *      files the portal allows the browser to reuse for a while, such as
     *      photos ("private, max-age=3600") and asset labels. Browsers do not
     *      all honour it: MDN's compatibility data (checked 14 September 2026)
     *      lists Chrome's support for "cache" as partial, Firefox as supporting
     *      it from version 138 (and earlier from 63 to 94), and Safari from 17.
     *      Treat it as a help, not a guarantee.
     *   3. Answers with a tiny page, instead of a redirect, whose script deletes
     *      every stored page from the service worker's storage (Cache Storage),
     *      keeping only static files, the offline page and manifest (except in
     *      a store written by a worker from before #507 — see the comment
     *      above the echo below), and pages the portal marked as built for a
     *      signed-out visitor. Then it goes to '/'.
     *
     * WHY BOTH 2 AND 3: tested on 14 September 2026 in Edge 153 and in
     * Playwright's Firefox 155 and WebKit 26.6 builds, `Clear-Site-Data:
     * "cache"` does NOT touch the service worker's Cache Storage in any of
     * them. The script is what reaches the stored pages. It works whichever
     * version of the worker is running, so it also clears copies kept by the
     * old worker in a browser that has not yet picked up the new sw.js.
     *
     * WHY STEP 3 IS ENOUGH WHILE ANOTHER TAB IS STILL DOWNLOADING: on its own
     * it was not (found by the Codex review; reproduced in all three browsers
     * above). A worker from before #507 could write a signed-in page that
     * finished downloading after step 3 had run. What closes that is not on
     * this page: sendOfflineCopyHeaders() gives signed-in pages `Vary: *`,
     * which browsers refuse to put into Cache Storage at all. So no signed-in
     * page can land after step 3, apart from the fixed offline page. That page
     * is exempt because it holds nothing about the visitor (see
     * offlinePageAnswered()). Step 3 only removes what was stored before.
     * Details in oldServiceWorkerWouldStore().
     *
     * TRIED AND REJECTED
     *   - `Clear-Site-Data: "storage"` does empty Cache Storage (same test), but
     *     it also unregisters the service worker — which cancels the browser's
     *     push notification subscription (#322) — and wipes localStorage (theme
     *     and readability choices) and IndexedDB (the offline form queue, #233).
     *     Too much to take away from somebody signing out of their own phone.
     *   - Asking the worker to clear itself by message: a worker from before
     *     this fix does not understand the message, and those are exactly the
     *     browsers holding signed-in copies.
     *   - Closing the race from this page by replacing the old worker: fetch
     *     the current sw.js, wait for it to install, ask it to delete the old
     *     stores whole, and remove the worker registration if no current worker
     *     answered. Built and tested on 14 September 2026 in the same three
     *     browsers; it did NOT close the race. The tab still downloading runs
     *     the page footer's worker registration when it finishes loading, and
     *     that brought the old worker straight back — in Edge even when sw.js
     *     could not be fetched — so the late copy was shown offline again. Edge
     *     also held the new worker back while the old one was busy and it did
     *     not answer in time, so sign-out removed the registration, and with it
     *     the push subscription (#322), for no reason. In Firefox and WebKit,
     *     putting the new worker in charge cut off the other tab's download.
     *   - Deleting again after a fixed wait. A page cannot see another tab's
     *     downloads, so any wait is a guess, and it slows every sign-out.
     *
     * WHAT IT CANNOT DO
     *   - It cannot reach a browser that never loads this page: a session that
     *     simply expires, or one ended by offboarding, removes nothing from the
     *     browser. The protection there is that sw.js no longer keeps signed-in
     *     pages at all.
     *   - With JavaScript switched off the script cannot run, so nothing is
     *     removed; the page moves on by itself and the session still ends. A
     *     browser that ran a worker from before #507 on earlier visits and then
     *     had JavaScript switched off keeps what that worker stored. If
     *     JavaScript is switched back on, that old worker can show those copies
     *     offline again, until the browser fetches the current sw.js (whose
     *     activate handler deletes the old store).
     *   - It keeps '/offline/' in the current worker's store by address. If
     *     the current sw.js installed while an older Auth.php was still live,
     *     then for an organisation whose site key is "offline", on a server
     *     that sends that address through PHP, that entry is the signed-in
     *     dashboard, and it is kept. Details in the comment above the echo
     *     below.
     *
     * @return void (terminates after sending the sign-out page)
     */
    public static function logout(): void
    {
        self::ensureSession();

        // 📝 Log before destroying the session (need user_id for the log)
        if (isset($_SESSION['user_id']) === true) {
            Logger::activity('Logout', 'User logged out');
        }

        // 🧹 Clear all session data
        $_SESSION = [];

        // 🍪 Delete the session cookie
        if (ini_get('session.use_cookies') === '1') {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();

        // 🧹 Ask the browser to empty its ordinary cache for this site. Not
        //    every browser honours this fully (see "WHAT IT DOES NOW" above).
        header('Clear-Site-Data: "cache"');

        // 🔒 The sign-out page itself must never be kept offline: "private"
        //    makes sw.js refuse it even though the now-empty session would
        //    otherwise earn it the "allow" marker.
        header('Cache-Control: no-store, private');

        // 🛡️ Only this page's own script may run, nothing else may load.
        $nonce = App::cspNonce();
        header("Content-Security-Policy: default-src 'none'; script-src 'nonce-" . $nonce . "'; "
            . "base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
        header('Content-Type: text/html; charset=UTF-8');

        $nonceAttr = htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8');

        // 📄 The script below decides what to delete from Cache Storage.
        //    KEPT: the addresses in ALWAYS_KEEP, which are the non-personal
        //    entries of the worker's install list — the offline page under its
        //    current name '/offline/' and its pre-fix name '/offline' (still
        //    used by an older worker), and '/manifest.json'. They are checked
        //    BEFORE the "private" rule on purpose: the install list is fetched
        //    with the sign-in cookie, so the portal labels the manifest
        //    "private" too, and an earlier version of this script therefore
        //    deleted it — leaving the offline page's manifest link broken
        //    until the worker next installed. Neither page reads anything
        //    about the visitor, which is what makes keeping them safe; if
        //    either ever does, take it out of this list and out of
        //    PRECACHE_ASSETS in sw.js.
        //    THE ADDRESS ALONE IS NOT PROOF OF WHICH FILE ANSWERED IT. On a
        //    server that sends these addresses through PHP, an organisation
        //    whose site key is "offline" (path-prefix multi-site) has its OWN
        //    signed-in dashboard at /offline and /offline/ — Router strips the
        //    site key from the front of the address before routing, so those
        //    addresses reach that organisation's dashboard, not the portal's
        //    shared offline page. A worker from before #507 stored whatever
        //    answered at install time with no check at all, so for that
        //    organisation it stored the DASHBOARD under /offline. Keeping
        //    ALWAYS_KEEP purely by address kept that dashboard after sign-out
        //    too, and the old worker went on showing it offline — with the
        //    visitor's name on it. Reproduced in Edge 153 and in Playwright's
        //    Firefox 155 and WebKit 26.6 builds on 15 September 2026.
        //    THE FIX: ALWAYS_KEEP now applies only OUTSIDE the stores a
        //    pre-#507 worker wrote — 'portal-v1' and 'portal-v2' (see
        //    PRE_507_STORES below). Those two names are frozen: that old
        //    worker's code never changes again, so it can only ever have
        //    written a store under one of those two names. Inside such a
        //    store, these addresses fall through to the ordinary rules below
        //    instead, which is what deletes a wrongly-stored dashboard.
        //    WHY THE CURRENT WORKER'S OWN STORE IS NORMALLY SAFE WITHOUT THIS
        //    CHECK: once this Auth.php is live, the browser refuses to let the
        //    current worker's install step store any copy carrying `Vary: *`.
        //    The dashboard of an organisation keyed "offline" gets that
        //    header; the real offline page is exempt (see
        //    Auth::offlinePageAnswered()). The worker's fetch handler stores a
        //    page only when the response carries the "allow" marker.
        //    WHAT THIS CANNOT PROTECT: the install step checks nothing itself.
        //    If the current sw.js installs while an OLDER Auth.php is still
        //    live, that older file sends no `Vary: *`. So for an organisation
        //    whose site key is "offline", on a server that sends /offline/
        //    through PHP, the signed-in dashboard IS stored as '/offline/' in
        //    the current store. This script then keeps it at every sign-out,
        //    and the worker shows it offline with the person's name, until
        //    CACHE_VERSION next changes. The window is the deploy that FIRST
        //    brings this file and the matching sw.js to a server whose
        //    Auth.php sends no `Vary: *` (any version from before #507).
        //    Later deploys find this Auth.php already live, and it already
        //    sends that header, so they do not reopen the window, unless a
        //    later change stops the header being sent or the server is rolled
        //    back to an older Auth.php. When this was written, deploy.yml
        //    uploaded web/public_html/ (sw.js) before web/_core/ (this file),
        //    so that first deploy opens the window while web/_core/ uploads.
        //    It stays open if that deploy stops half way, and a customer
        //    uploading files by hand can open it the same way.
        //    Reproduced 15 September 2026 in Edge 153, Firefox 155 and
        //    WebKit 26.6. The cause is a site key taking over a fixed portal
        //    address. That belongs where site keys are saved
        //    (web/_apps/admin/sites/save.php, which does not yet refuse such
        //    keys), not here. A cookie-free install fetch was tried and
        //    rejected; see the install step in sw.js.
        //    WHAT THIS COSTS: during the changeover, a browser still running
        //    a worker from before #507 on such a server shows the bare "you
        //    appear to be offline" message after sign-out, instead of the
        //    portal's offline page — because that wrongly-stored dashboard
        //    copy is now correctly deleted, and nothing else was ever stored
        //    under that address for the old worker to fall back to. On the
        //    project's own Apache hosting, the old worker's copy at
        //    '/offline' was in any case a redirect to '/offline/', and
        //    browsers already refuse to show a stored redirected response for
        //    a page load (see OFFLINE_PAGE in sw.js), so nothing changes
        //    there.
        //    TRIED AND REJECTED:
        //      - Dropping only '/offline' from ALWAYS_KEEP, keeping
        //        '/offline/' unconditionally: the old worker stores
        //        '/offline/' under this same fault when THAT is the
        //        organisation's home address instead.
        //      - Keeping ALWAYS_KEEP unconditionally, but only inside a store
        //        literally named 'portal-v3': the very next CACHE_VERSION
        //        change (portal-v4, and so on) would then silently stop
        //        keeping the offline page at sign-out for every visitor, not
        //        only the ones affected by this fault.
        //    Also KEPT: pages stored with the marker and without
        //    "private" (built for a signed-out visitor, the same test sw.js
        //    uses); static files — an address with a file ending from the same
        //    list as isStaticAsset() in sw.js, whose stored answer is not a
        //    page and not "no-store"/"private".
        //    DELETED: everything else, in every store, whatever its name — so
        //    copies from an older worker version go too.
        //    Deliberately NOT keeping everything under /assets/: the Asset
        //    Tracker's signed-in pages live there (/assets/my, /assets/item).
        //    It waits for the deletions to finish before leaving the page,
        //    because leaving could stop them part way.
        echo '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Signed out</title>
<noscript><meta http-equiv="refresh" content="0;url=/"></noscript>
<script nonce="' . $nonceAttr . '">
(function () {
    "use strict";
    var ALWAYS_KEEP = ["/offline/", "/offline", "/manifest.json"];
    // 📋 Stores a worker from before #507 could have written. Frozen: the
    //    code of that old worker never changes again, so it can only ever be
    //    one of these two names. See the comment above the echo() in
    //    Auth::logout() for why ALWAYS_KEEP must not apply inside them.
    var PRE_507_STORES = ["portal-v1", "portal-v2"];
    var MARKER = "' . self::OFFLINE_COPY_HEADER . '";
    var STATIC_FILE = /\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|eot|webp)$/i;
    function goHome() { window.location.replace("/"); }
    function keep(storeName, request, response) {
        if (!response) { return false; }
        var path = new URL(request.url).pathname;
        var cc = (response.headers.get("Cache-Control") || "").toLowerCase();
        var isPage = (response.headers.get("Content-Type") || "").toLowerCase().indexOf("text/html") !== -1;
        if (ALWAYS_KEEP.indexOf(path) !== -1 && PRE_507_STORES.indexOf(storeName) === -1) { return true; }
        if (cc.indexOf("private") !== -1) { return false; }
        if (isPage) { return response.headers.get(MARKER) === "allow"; }
        return STATIC_FILE.test(path) && cc.indexOf("no-store") === -1;
    }
    if (typeof window.caches === "undefined") { goHome(); return; }
    caches.keys().then(function (names) {
        return Promise.all(names.map(function (name) {
            return caches.open(name).then(function (cache) {
                return cache.keys().then(function (requests) {
                    return Promise.all(requests.map(function (request) {
                        return cache.match(request).then(function (response) {
                            return keep(name, request, response) ? null : cache.delete(request);
                        });
                    }));
                });
            });
        }));
    }).catch(function () { /* nothing more can be done here; still move on */ }).then(goHome);
}());
</script>
</head>
<body>
<p>You have been signed out. <a href="/">Continue</a></p>
</body>
</html>';
        exit();
    }

    /* ====================================================================== */
    /* Internal utilities                                                     */
    /* ====================================================================== */

    /**
     * Perform an HTTP POST request using cURL.
     *
     * @param string $url  The URL to POST to
     * @param array  $data Key-value pairs for the POST body (form-encoded)
     *
     * @return string|null Response body, or null on failure
     */
    public static function curlPost(string $url, array $data): ?string
    {
        $ch = curl_init($url);

        if ($ch === false) {
            Logger::errorPlatform('cURL', 'Error', 'INIT_FAIL', 'curl_init() returned false for: ' . $url, '');
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => http_build_query($data),
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => 15,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
        ]);

        $resp = curl_exec($ch);

        if ($resp === false) {
            Logger::errorPlatform('cURL', 'Error', (string) curl_errno($ch), curl_error($ch), '');
            // PHP 8+ auto-closes cURL handles; no curl_close needed.
            return null;
        }

        return $resp;
    }

    /**
     * Find a user ID by linked account (provider + providerSub).
     *
     * @param string  $provider    Provider name (ms365, google)
     * @param string  $providerSub Provider-specific unique identifier
     * @param \mysqli $db          Database connection
     *
     * @return int|null User ID if found, null otherwise
     */
    private static function findUserByLink(string $provider, string $providerSub, \mysqli $db): ?int
    {
        $stmt = $db->prepare(
            'SELECT LA.userID FROM tblLinkedAccounts LA '
            . 'JOIN tblUsers U ON U.userID = LA.userID '
            . 'WHERE LA.provider = ? AND LA.providerSub = ? AND U.isActive = 1 '
            . 'LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ss', $provider, $providerSub);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row !== null ? (int) $row['userID'] : null;
    }

    /**
     * Find a user ID by email address.
     *
     * @param string  $email Email address (already lowercased)
     * @param \mysqli $db    Database connection
     *
     * @return int|null User ID if found, null otherwise
     */
    private static function findUserByEmail(string $email, \mysqli $db): ?int
    {
        $stmt = $db->prepare('SELECT userID FROM tblUsers WHERE emailAddress = ? AND isActive = 1 LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row !== null ? (int) $row['userID'] : null;
    }

    /**
     * 🚫 Check whether an email address belongs to a DEACTIVATED user
     * (`isActive = 0`) — the mirror image of `findUserByEmail()`, which
     * only ever matches active users.
     *
     * Used by the OAuth callbacks (#B7b) to detect an offboarded person
     * signing back in via SSO. Offboarding (`_apps/offboarding/do.php`)
     * deactivates the user AND deletes their `tblLinkedAccounts` row, so
     * without this check `findUserByLink()`/`findUserByEmail()` both come
     * back null and the callback would fall through to `createUser()` —
     * which then throws on the `tblUsers.emailAddress` UNIQUE constraint
     * (mysqli is configured MYSQLI_REPORT_STRICT, so that's an uncaught
     * exception / white-screen, not a clean error).
     *
     * @param string  $email Email address (already lowercased)
     * @param \mysqli $db    Database connection
     *
     * @return int|null Deactivated user's ID if found, null otherwise
     */
    private static function findInactiveUserByEmail(string $email, \mysqli $db): ?int
    {
        $stmt = $db->prepare('SELECT userID FROM tblUsers WHERE emailAddress = ? AND isActive = 0 LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row !== null ? (int) $row['userID'] : null;
    }

    /**
     * 🚫 Redirect to login with an error flash message.
     * Used by OAuth callbacks to provide user-friendly error handling instead
     * of bare echo+exit patterns.
     *
     * @param string $message User-facing error message
     * @param int    $httpCode Optional HTTP status code (default 400)
     * @return never
     */
    private static function oauthError(string $message, int $httpCode = 400): never
    {
        self::ensureSession();
        http_response_code($httpCode);
        $_SESSION['flash_msg']  = $message;
        $_SESSION['flash_type'] = 'danger';
        header('Location: /login');
        exit();
    }

    /**
     * Create a new user record.
     *
     * @param string  $name   Full name
     * @param string  $email  Email address
     * @param string  $avatar Avatar URL
     * @param \mysqli $db     Database connection
     *
     * @return int The new user's ID
     *
     * @throws RuntimeException If the insert fails
     */
    private static function createUser(string $name, string $email, string $avatar, \mysqli $db): int
    {
        // 🔄 Wrap multi-table insert in a transaction for atomicity
        App::beginTransaction();

        try {
            $stmt = $db->prepare('INSERT INTO tblUsers (fullName, emailAddress, avatarPath, isActive) VALUES (?, ?, ?, 1)');
            if ($stmt === false) {
                throw new RuntimeException('DB prepare failed: ' . $db->error);
            }
            $stmt->bind_param('sss', $name, $email, $avatar);
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();

            if ($newId <= 0) {
                throw new RuntimeException('User insert returned invalid ID.');
            }

            // 🌐 Assign new user to the current site
            $siteId = Site::id();
            $siteStmt = $db->prepare(
                'INSERT IGNORE INTO tblUserSites (userID, siteID, isSiteAdmin, isSiteRootAdmin) '
                . 'VALUES (?, ?, 0, 0)'
            );
            if ($siteStmt === false) {
                throw new RuntimeException('DB prepare failed for tblUserSites: ' . $db->error);
            }
            $siteStmt->bind_param('ii', $newId, $siteId);
            $siteStmt->execute();
            $siteStmt->close();

            App::commit();

            return $newId;
        } catch (\Throwable $ex) {
            App::rollback();
            throw $ex;
        }
    }

    /**
     * 🌐 Set the active site ID in the session after login.
     * Uses the pre-detected site if the user belongs to it, otherwise
     * falls back to the user's first assigned site.
     *
     * @param int     $userId User ID
     * @param \mysqli $db     Database connection
     *
     * @return void
     */
    /**
     * 🌐 Public wrapper for setSessionSiteId — for use outside Auth class
     * (e.g. WebAuthn login handler in separate file).
     *
     * @param int    $userId User ID
     * @param \mysqli $db    Database connection
     *
     * @return void
     */
    public static function initSessionSite(int $userId, \mysqli $db): void
    {
        self::setSessionSiteId($userId, $db);
    }

    private static function setSessionSiteId(int $userId, \mysqli $db): void
    {
        $currentSiteId = Site::id();

        // 🔍 Check if the user belongs to the current (pre-detected) site
        if (Site::userBelongsTo($userId, $currentSiteId, $db) === true) {
            $_SESSION['active_site_id'] = $currentSiteId;
            return;
        }

        // 🔄 Fall back to the user's first assigned site
        $defaultSite = Site::resolveDefaultSiteForUser($userId, $db);
        $_SESSION['active_site_id'] = $defaultSite;
    }
}
