<?php
// Path: _core/Router.php
/**
 * -----------------------------------------------------------------------------
 * Portal Front-Controller Router 🎯
 * -----------------------------------------------------------------------------
 * Central request dispatcher for the Portal. Called by the single front
 * controller at public_html/index.php (branch-based deploy lands this file
 * in the server's public_html/ or public_html_dev/ or public_html_beta/
 * as appropriate — there's no per-channel front controller in the repo).
 *
 * Routing flow:
 *   1. Extract clean path from REQUEST_URI (strip query string and slashes)
 *   2. Check hardcoded special routes (login, logout, MS365 callbacks, API, health)
 *   3. Query tblRoutes for a matching routeKey
 *   4. If the route is protected (isProtected=1), enforce authentication
 *   5. Include the target app file (relative to PORTAL_APPS / public_html directory)
 *   6. If no route matches, render the 404 error page
 *
 * Clean URLs are achieved via .htaccess RewriteRule → index.php, so the user
 * sees /expenses/submit rather than /expenses/submit/index.php.
 *
 * @see       https://httpd.apache.org/docs/2.4/mod/mod_rewrite.html
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.1
 * @link      https://github.com/MWBMPartners/WebMS-Intra
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

class Router
{
    /**
     * The first part of every address handleSpecialRoutes() answers itself,
     * WITHOUT ever consulting tblRoutes — 'login', 'logout', 'offline',
     * 'health', the OAuth callback prefix 'login/...', the API prefix 'api',
     * and every short public-link prefix ('e', 'a', 'os', 'f') and GS1
     * Digital Link prefix ('01', '8003', '8004') handleSpecialRoutes() below
     * recognises.
     *
     * This is the ONLY hand-typed part of the reserved-organisation-key list
     * (#515, see ReservedKeys::all()) — everything else that list reserves is
     * read LIVE from tblRoutes or the real web root, so it cannot go stale.
     * This constant CAN go stale, because handleSpecialRoutes() is a method
     * body full of `$path === '...'` and `str_starts_with($path, '...')`
     * literals, not rows in a table nothing here can query.
     *
     * `tools/audit-checks/check_reserved_site_keys.py` closes that gap by
     * PARSING handleSpecialRoutes() itself and failing the build the moment
     * this constant and that method disagree in EITHER direction — an
     * address added to the method without being added here, or a name kept
     * here after the matching code was removed. Add a special route → add
     * its first part here, in the SAME change, or the build fails.
     *
     * @var list<string>
     */
    public const SPECIAL_ROUTE_FIRST_SEGMENTS = [
        '01', '8003', '8004', 'a', 'api', 'e', 'f', 'health', 'login', 'logout', 'offline', 'os',
    ];

    /**
     * Main dispatch method - called by front controllers.
     *
     * @param mysqli $db Active MySQLi database connection
     *
     * @return void
     */
    public static function dispatch(mysqli $db): void
    {
        // 🌐 Extract and normalise the request path
        $path = self::extractPath();

        // 🔀 Check hardcoded special routes first (these bypass tblRoutes).
        //    Passed BY REFERENCE — the empty-path ('/') branch rewrites
        //    $path to 'dashboard' and falls through (returns false) so the
        //    lookup below can resolve it via tblRoutes. Previously $path was
        //    a value parameter, so that rewrite only ever mutated a local
        //    copy inside handleSpecialRoutes() and dispatch() kept looking
        //    up the still-empty routeKey='' (never seeded) → 404 on every
        //    site-root request. Reference param makes the rewrite land here.
        if (self::handleSpecialRoutes($path) === true) {
            return; // Special route handled and exited
        }

        // 📌 Store the current (possibly rewritten, e.g. '' → 'dashboard')
        //    path for use by templates (e.g. nav highlighting). Defined
        //    AFTER handleSpecialRoutes() so nav highlighting reflects the
        //    resolved route rather than the raw empty path.
        if (defined('PORTAL_CURRENT_ROUTE') === false) {
            define('PORTAL_CURRENT_ROUTE', $path);
        }

        // 🔍 Look up route in the database
        $route = self::findRoute($db, $path);

        if ($route === null) {
            // 🚫 No matching route found - show 404
            self::renderError(404);
            return;
        }

        // 🛡️ If the route is protected, send a signed-out visitor to sign in.
        //
        //    This used to test only `=== '1'`, the TEXT "1". But the route is
        //    read through a prepared statement, and a prepared statement hands
        //    back a yes/no column as the whole NUMBER 1, not as text. A number
        //    is never identical to text, so the test was false for every route
        //    and this line never sent anybody to sign in. Any page without a
        //    sign-in check of its own was open to the whole internet — proved on
        //    13 September 2026 for the expenses treasury queue (claimants'
        //    names and amounts), expense submission and approval, the
        //    dashboard, attendance and the support page (issue #497).
        //
        //    Both forms are accepted, so this keeps working if the route is ever
        //    read by a plain query instead, which returns text.
        //
        //    Scheduled jobs must NOT be seeded as protected: they arrive with a
        //    token and no session, so this line would quietly redirect them to
        //    the sign-in page and the job would never run. Every scheduled job
        //    therefore lives at a `cron/...` address seeded with isProtected = 0
        //    and checks its own token (see web/_apps/cron/).
        if ($route['isProtected'] === 1 || $route['isProtected'] === '1') {
            Auth::requireLogin();
        }

        // 📂 Build the full path to the target app file. Try PORTAL_APPS
        //    first (where all app controllers live after #159), then fall
        //    back to the web folder for the small set of entry-point pages
        //    that intentionally live there (the manifest, openapi.json,
        //    robots.txt, the sitemap).
        //
        //    The web folder is PORTAL_WEBROOT, which the front controller set
        //    to its own folder. It used to be spelt 'public_html' here, so on
        //    hosting that calls the web folder anything else those four
        //    addresses answered "page not found".
        $relTarget = str_replace('/', DIRECTORY_SEPARATOR, $route['targetFile']);
        $targetFile = PORTAL_APPS . DIRECTORY_SEPARATOR . $relTarget;
        if (is_readable($targetFile) === false) {
            $fallbackFile = PORTAL_WEBROOT . DIRECTORY_SEPARATOR . $relTarget;
            if (is_readable($fallbackFile) === true) {
                $targetFile = $fallbackFile;
            } else {
                Logger::errorPlatform(
                    'Router',
                    'Error',
                    '404',
                    'Route target file not found',
                    'routeKey=' . $path . ' targetFile=' . $route['targetFile']
                );
                self::renderError(404);
                return;
            }
        }

        // 📌 Define the current app section for navigation highlighting
        // Extract the top-level app name from the path (e.g. "expenses" from "expenses/submit")
        $pathParts = explode('/', $path);
        if (defined('PORTAL_CURRENT_APP') === false) {
            define('PORTAL_CURRENT_APP', $pathParts[0] ?? 'dashboard');
        }

        // 🚪 App enable/disable gate (#255). If the route's owning app is
        //    registered AND disabled, render a friendly 403 explaining why
        //    instead of letting the user hit a half-broken handler.
        //    Routes not owned by any registered app pass through unchanged.
        $owningApp = AppRegistry::appForRoute($path);
        if ($owningApp !== null && AppRegistry::isEnabled((string) $owningApp['slug']) === false) {
            self::renderAppDisabled($owningApp);
            return;
        }

        // 🚀 Include the target app file.
        //    This require runs inside dispatch()'s METHOD scope, so the included
        //    controller inherits only these locals — NOT global scope. Legacy
        //    controllers (pre-#159) reference the DB handle as a bare $mysqli and
        //    settings as a bare $SETTINGS; both are bootstrap globals, so without
        //    importing them here they would be undefined inside the handler (PHP
        //    include scope = the enclosing function's locals). Import them so the
        //    documented contract actually holds — no-op if already provided, so it
        //    can't regress anything. New code should prefer App::db()/App::settings().
        global $mysqli, $SETTINGS; // #373 — expose bootstrap globals to controllers
        require $targetFile;
    }

    /**
     * Extract and normalise the clean URL path from the current request.
     *
     * Examples:
     *   /expenses/submit?foo=bar  →  "expenses/submit"
     *   /                         →  ""
     *   /login/                   →  "login"
     *   /api/expenses/list        →  "api/expenses/list"
     *
     * @return string Normalised path (lowercase, no leading/trailing slashes)
     */
    public static function extractPath(): string
    {
        // 📝 Get the raw URI and strip the query string
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        if ($path === null || $path === false) {
            $path = '/';
        }

        // 🔤 Normalise: lowercase, trim slashes, collapse double slashes
        $path = strtolower(trim($path, '/'));
        $path = (string) preg_replace('#/{2,}#', '/', $path);

        // 🌐 In path-prefix multisite mode, strip the site key from the URL
        // E.g. /cambridge/expenses → expenses (Site::preDetect already stored the prefix)
        //
        // The prefix is lower-cased here because the path was, two lines up.
        // Site::pathPrefix() gives the part AS IT WAS TYPED, and the database
        // finds the organisation whatever capitals were used. So
        // "/CAMBRIDGE/expenses" was taken for Cambridge, and then compared
        // "cambridge/expenses" with "CAMBRIDGE/": no match, the part stayed
        // on the path, and every page answered "not found" (#536). With no
        // first part in use the prefix is '' and this changes nothing.
        // WHAT IT DOES NOT DO: it does not change the part the links on the
        // page are built with. Site::url() still uses it as typed, so the
        // person stays at the spelling they came in on.
        $sitePrefix = strtolower(Site::pathPrefix());
        if ($sitePrefix !== '' && str_starts_with($path, $sitePrefix . '/') === true) {
            $path = substr($path, strlen($sitePrefix) + 1);
        } elseif ($sitePrefix !== '' && $path === $sitePrefix) {
            $path = '';
        }

        return $path;
    }

    /**
     * Handle hardcoded special routes that bypass tblRoutes.
     * Returns true if a special route was matched (and the response was sent).
     *
     * @param string $path The normalised request path, taken BY REFERENCE so
     *                      the empty-path→'dashboard' rewrite below reaches
     *                      dispatch()'s own $path instead of only mutating a
     *                      local copy (fixes site-root 404 — A1).
     *
     * @return bool True if a special route was handled
     */
    private static function handleSpecialRoutes(string &$path): bool
    {
        // 🔌 Hand these pages the same two things dispatch() hands an ordinary
        //    page. Without this line they get NOTHING, because a file loaded
        //    from inside a method sees only that method's own local variables —
        //    and this method had none to give.
        //
        //    Two pages were broken by that and nobody had noticed:
        //    `auth/login/webauthn.php` (signing in with a passkey) and
        //    `calendar/public-landing.php` (the public page for a single event,
        //    at /e/{name}). Both ask the database for things using `$mysqli`,
        //    and `$mysqli` was simply not there, so both stopped dead.
        //
        //    Both of the other two routers already do exactly this — see
        //    `dispatch()` below and `ApiRouter::dispatch()`. This one was the
        //    odd one out, which is why the pages it loads were the broken ones.
        global $mysqli, $SETTINGS;

        // 🏠 Empty path → dashboard (default home page). Rewriting $path
        //    here (by reference) then returning false lets dispatch()
        //    fall through to the normal tblRoutes lookup using routeKey
        //    'dashboard' — no dashboard-rendering logic duplicated here.
        if ($path === '') {
            $path = 'dashboard';
            return false;
        }

        // 🔑 Microsoft 365 OAuth initiation
        if ($path === 'login/ms365') {
            Auth::loginMS365();
            return true; // loginMS365() calls exit() after redirect
        }

        // 🔑 Microsoft 365 OAuth callback
        if ($path === 'login/ms365/callback') {
            Auth::callbackMS365();
            return true; // callbackMS365() calls exit() after redirect
        }

        // 🔑 Google OAuth initiation
        if ($path === 'login/google') {
            Auth::loginGoogle();
            return true; // loginGoogle() calls exit() after redirect
        }

        // 🔑 Google OAuth callback
        if ($path === 'login/google/callback') {
            Auth::callbackGoogle();
            return true; // callbackGoogle() calls exit() after redirect
        }

        // 🔐 WebAuthn authentication API (login flow)
        if ($path === 'login/webauthn') {
            require PORTAL_APPS . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'login' . DIRECTORY_SEPARATOR . 'webauthn.php';
            return true;
        }

        // 🚪 Logout
        if ($path === 'logout') {
            Auth::logout();
            return true; // logout() calls exit() after redirect
        }

        // 📵 Offline fallback page (served by service worker when no network)
        //    Lives in offline/ in the web folder rather than in _apps/ — the
        //    service worker fetches it via a direct URL, and Apache must be
        //    able to serve it as a static asset under the webroot.
        //    PORTAL_WEBROOT, not the name 'public_html': see the note where
        //    bootstrap.php defines it. Auth::offlinePageAnswered() looks for
        //    this same file by the same constant, and the two must agree.
        if ($path === 'offline') {
            require PORTAL_WEBROOT . DIRECTORY_SEPARATOR . 'offline' . DIRECTORY_SEPARATOR . 'index.php';
            return true;
        }

        // 💚 Health check endpoint (used by CI/CD deploy pipeline)
        if ($path === 'health') {
            self::healthCheck();
            return true;
        }

        // 🔌 API routes (prefix: api/) — delegated to ApiRouter
        if (str_starts_with($path, 'api/') === true) {
            ApiRouter::dispatch($path);
            return true;
        }

        // 📣 Per-event public landing page (#346) — short URL /e/<slug>.
        //    The trailing segment is captured into $_GET['slug'] and the
        //    page (web/_apps/calendar/public-landing.php) renders the
        //    branded hero + countdown + RSVP CTA.
        if (str_starts_with($path, 'e/') === true) {
            $slug = substr($path, 2);
            if (preg_match('/^[a-z0-9][a-z0-9\-]{0,79}$/i', $slug) === 1) {
                $_GET['slug'] = $slug;
                require PORTAL_APPS . DIRECTORY_SEPARATOR . 'calendar' . DIRECTORY_SEPARATOR . 'public-landing.php';
                return true;
            }
        }

        // 🏷️ Asset Tracker public lost-and-found page (#393) — short URL
        //    /a/<token>. Cloned from the /e/<slug> block above. The token
        //    is tblAssets.publicToken — always exactly 32 lowercase-hex
        //    chars (bin2hex(random_bytes(16)), see AssetRegister::
        //    generatePublicToken()) — so the pattern is anchored and exact
        //    rather than the open-ended slug pattern above. Not a
        //    tblRoutes row: like /e/<slug>, this bypasses the DB route
        //    lookup entirely so the page works even with tblRoutes
        //    unreachable/mid-migration. The handler
        //    (web/_apps/assets/tag.php) owns the full uniform-404 logic
        //    for unknown/disabled/confidential tokens — see its header
        //    comment.
        if (str_starts_with($path, 'a/') === true) {
            $token = substr($path, 2);
            if (preg_match('/^[a-f0-9]{32}$/', $token) === 1) {
                $_GET['token'] = $token;
                require PORTAL_APPS . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'tag.php';
                return true;
            }
        }

        // 📖 Service Plans public Order-of-Service page (gap #128) — short
        //    URL /os/<token>. Cloned from the /a/<token> block immediately
        //    above: the token is tblServicePlan.publicToken — always exactly
        //    32 lowercase-hex chars (bin2hex(random_bytes(16)), see
        //    Portal\Core\Hymnal::generateShareToken()) — anchored + exact
        //    regex, not a tblRoutes row (bypasses the DB route lookup
        //    entirely, same mid-migration-safety rationale as every other
        //    special-route block on this page). The handler
        //    (web/_apps/service-plans/public.php) owns the full uniform-404
        //    logic for unknown/unshared/unpublished/disabled tokens — see
        //    its header comment.
        if (str_starts_with($path, 'os/') === true) {
            $token = substr($path, 3);
            if (preg_match('/^[a-f0-9]{32}$/', $token) === 1) {
                $_GET['token'] = $token;
                require PORTAL_APPS . DIRECTORY_SEPARATOR . 'service-plans' . DIRECTORY_SEPARATOR . 'public.php';
                return true;
            }
        }

        // 📝 Forms Builder public fill page (#153) — short URL /f/<token>. Cloned
        //    from the /os/<token> block immediately above: the token is
        //    tblForms.publicToken — always exactly 32 lowercase-hex chars
        //    (bin2hex(random_bytes(16)), see Portal\Core\FormEngine::
        //    generatePublicToken()) — anchored + exact regex, not a tblRoutes row
        //    (bypasses the DB route lookup entirely, same mid-migration-safety
        //    rationale as every other special-route block on this page). The
        //    handler (web/_apps/forms/public.php) owns the full uniform-404 logic
        //    for unknown/unpublished/closed/disabled tokens — see its header.
        if (str_starts_with($path, 'f/') === true) {
            $token = substr($path, 2);
            if (preg_match('/^[a-f0-9]{32}$/', $token) === 1) {
                $_GET['token'] = $token;
                require PORTAL_APPS . DIRECTORY_SEPARATOR . 'forms' . DIRECTORY_SEPARATOR . 'public.php';
                return true;
            }
        }

        // 🔗 Asset Tracker GS1 Digital Link resolver (#415, Phase 3 Pass 6
        // — FINAL Asset Tracker pass) — recognises the three GS1
        // Application Identifier path shapes a GS1 Digital Link URI uses
        // for a bare key-only lookup: 01=GTIN, 8003=GRAI, 8004=GIAI (the
        // three schemes AssetRegister::resolveDigitalLink()'s own AI→
        // typeCode map understands — see that method's doc). Modelled
        // EXACTLY on the `a/{token}` block immediately above: NOT a
        // tblRoutes row (bypasses the DB route lookup entirely, same
        // mid-migration-safety rationale as every other special-route
        // block on this page), ANCHORED regexes so this can never shadow
        // a real route, and the handler (web/_apps/assets/dl.php) owns
        // the full feature-gate/uniform-404 logic — this block only ever
        // sets pre-validated $_GET values and requires it directly.
        // $path is already lower-cased by extractPath() above, so the
        // A-Z half of every character class below is unreachable in
        // practice; kept for shape-parity with the GS1 AI-21/GIAI
        // alphanumeric ranges themselves, which permit upper-case.
        //
        // 01/{gtin}[/21/{serial}] — gtin is 8-14 digits (GS1's own
        // GTIN-8/12/13/14 family). An optional /21/{serial} suffix
        // (AI 21 = serial number) is accepted for URL-completeness but
        // resolveDigitalLink() ignores it — see that method's own doc for
        // why a serial that doesn't further disambiguate a GTIN lookup is
        // fine to drop.
        if (str_starts_with($path, '01/') === true) {
            if (preg_match('#^01/(\d{8,14})(?:/21/([A-Za-z0-9\-_.]{1,20}))?$#', $path, $m) === 1) {
                $_GET['dl_ai']     = '01';
                $_GET['dl_value']  = $m[1];
                $_GET['dl_serial'] = $m[2] ?? '';
                require PORTAL_APPS . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'dl.php';
                return true;
            }
        }

        // 8003/{grai} — GRAI (AI 8003). GS1's GRAI key is a 14-digit base
        // that may carry an appended serial in the SAME path segment (no
        // separate /21/ split, unlike GTIN above), hence the wider
        // alphanumeric length range.
        if (str_starts_with($path, '8003/') === true) {
            if (preg_match('#^8003/([A-Za-z0-9\-_.]{14,30})$#', $path, $m) === 1) {
                $_GET['dl_ai']     = '8003';
                $_GET['dl_value']  = $m[1];
                $_GET['dl_serial'] = '';
                require PORTAL_APPS . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'dl.php';
                return true;
            }
        }

        // 8004/{giai} — GIAI (AI 8004), GS1's alphanumeric, no-fixed-
        // length individual asset identifier — the key scheme migration
        // 159's seed data calls out as purpose-built for identifying
        // assets (see AssetRegister's #397 section).
        if (str_starts_with($path, '8004/') === true) {
            if (preg_match('#^8004/([A-Za-z0-9\-_.]{1,30})$#', $path, $m) === 1) {
                $_GET['dl_ai']     = '8004';
                $_GET['dl_value']  = $m[1];
                $_GET['dl_serial'] = '';
                require PORTAL_APPS . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'dl.php';
                return true;
            }
        }

        return false;
    }

    /**
     * Look up a route in tblRoutes by its routeKey.
     *
     * @param mysqli $db   Database connection
     * @param string $path The normalised request path (used as routeKey)
     *
     * @return array{routeKey: string, targetFile: string, isProtected: int|string}|null
     *         isProtected arrives as the number 1 or 0 through this prepared
     *         statement — see the comment in dispatch() for why that matters.
     */
    private static function findRoute(mysqli $db, string $path): ?array
    {
        // 📝 Use a prepared statement to safely query the route
        $stmt = $db->prepare(
            'SELECT routeKey, targetFile, isProtected FROM tblRoutes WHERE routeKey = ? LIMIT 1'
        );
        if ($stmt === false) {
            Logger::errorPlatform('MySQL', 'Error', 'ROUTE_PREP_FAIL', $db->error, '');
            return null;
        }

        $stmt->bind_param('s', $path);
        $stmt->execute();
        $result = $stmt->get_result();
        $route  = $result->fetch_assoc();
        $stmt->close();

        // 🔍 Return null if no matching route was found
        if ($route === null || $route === false) {
            return null;
        }

        return $route;
    }

    /**
     * Render an error page (404, 403, 500).
     *
     * @param int $code HTTP status code
     *
     * Render the "app is disabled" page (#255). Friendly message rather
     * than a generic 403 so users understand the feature isn't broken,
     * just turned off.
     *
     * THE PAGE USED TO NAME THE WRONG APP (#570). The header template is
     * loaded INSIDE this function, so it shares this function's variables,
     * and the menu it draws (templates/nav.php) writes `$appName` once for
     * every app in the menu. This function had put the switched-off app's
     * name in `$appName` before loading the header, so by the time the
     * heading was printed it held the LAST app in the menu: with Approvals
     * switched off the page said "Forms Builder is disabled" (seen by the
     * part 8 self-test, 5 October 2026). The description was not
     * overwritten, so name and description could disagree.
     *
     * The two values now have names the templates do not use.
     * `tools/app-switch-selftest.php` reads the templates and fails if a
     * variable this function sets before the header and reads after it is
     * ever one the templates assign. Do not rename them back.
     *
     * The two links are built by Router::url(), as the menu's are, so they
     * stay inside the organisation's own address when organisations are
     * told apart by the first part of the address; they used to be the
     * bare `/admin/apps` and `/`, which left it.
     *
     * @param array<string, mixed> $app Metadata from AppRegistry.
     */
    public static function renderAppDisabled(array $app): void
    {
        http_response_code(403);
        $pageTitle       = 'App disabled';
        $pageSection     = '';
        $breadcrumbs     = [];
        $disabledAppName = (string) ($app['name'] ?? 'This app');
        $disabledAppDesc = (string) ($app['description'] ?? '');
        require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
        echo '<div class="portal-error-page text-center py-5">';
        echo '<div class="portal-error-code"><i class="fa-solid fa-power-off text-muted"></i></div>';
        echo '<h1 class="portal-error-title">' . htmlspecialchars($disabledAppName, ENT_QUOTES, 'UTF-8') . ' is disabled</h1>';
        if ($disabledAppDesc !== '') {
            echo '<p class="text-muted">' . htmlspecialchars($disabledAppDesc, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        if (App::isAdmin() === true) {
            echo '<p>An administrator can enable this app at <a href="'
                . htmlspecialchars(self::url('admin/apps'), ENT_QUOTES, 'UTF-8') . '">Admin &rarr; Apps</a>.</p>';
        } else {
            echo '<p>Please ask an administrator if you need this feature.</p>';
        }
        echo '<a href="' . htmlspecialchars(self::url(''), ENT_QUOTES, 'UTF-8')
            . '" class="btn btn-primary"><i class="fa-solid fa-house-chimney me-1"></i> Return to portal</a>';
        echo '</div>';
        require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
    }

    /**
     * The one answer for an event the viewer may not see, whatever the
     * reason: missing, deleted, a draft, or internal and the viewer may not
     * see it. All the same page, on purpose, so the answer cannot be used to
     * learn which events exist (#532). 404 underneath, so a search engine
     * drops a dead address — this replaces an earlier answer (a 302 sign-in
     * redirect) that told a search engine nothing and asked somebody
     * following a DEAD link to sign in for something that no longer exists,
     * an answer the owner changed on 20 September 2026. Also carries a
     * sign-in link for anybody who has an account, since a genuinely
     * refused event usually means "you are not signed in" or "you are
     * signed in as the wrong account".
     *
     * A GET page calls this then `return`s; a POST handler calls it then
     * `exit()`s (mirrors Auth::requireLogin()'s own calling convention).
     *
     * WHAT THIS CANNOT DO: tell the visitor which of the reasons applies —
     * that is the entire point of giving every reason the same page.
     */
    public static function renderEventUnavailable(): void
    {
        http_response_code(404);
        require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'event-unavailable.php';
    }

    /**
     * @return void
     */
    public static function renderError(int $code): void
    {
        http_response_code($code);

        // 📄 Try to load the template error page
        $templateFile = PORTAL_CORE . DIRECTORY_SEPARATOR
                      . 'templates' . DIRECTORY_SEPARATOR
                      . 'error-' . $code . '.php';

        if (is_readable($templateFile) === true) {
            require $templateFile;
        } else {
            // 📝 Minimal fallback if template doesn't exist yet
            $titles = [
                403 => 'Access Denied',
                404 => 'Page Not Found',
                500 => 'Server Error',
            ];
            $title = $titles[$code] ?? 'Error ' . $code;
            // ♿ Emit lang + dir so assistive tech announces this fallback page
            //    correctly. Guarded because renderError() can fire in degraded
            //    states — fall back to en/ltr if I18n isn't ready.
            $lang = 'en';
            $dir  = 'ltr';
            if (class_exists(I18n::class) === true) {
                $lang = I18n::locale();
                $dir  = I18n::dir();
            }
            echo '<!doctype html><html lang="' . htmlspecialchars($lang, ENT_QUOTES, 'UTF-8')
               . '" dir="' . htmlspecialchars($dir, ENT_QUOTES, 'UTF-8') . '">'
               . '<head><meta charset="utf-8"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title></head>';
            echo '<body style="font-family:system-ui;text-align:center;padding:4rem;">';
            echo '<h1>' . $code . '</h1><p>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p>';
            // 🏢 The organisation's own front page, built like every other
            //    link (#536). This was the bare "/", which under "the first
            //    part of the address" is the DEFAULT organisation's front
            //    page, so the one link on this page led out of the
            //    organisation the person was in.
            echo '<a href="' . htmlspecialchars(self::url(''), ENT_QUOTES, 'UTF-8') . '">Return to Portal</a></body></html>';
        }
    }

    /**
     * Health check endpoint for CI/CD monitoring + uptime probes.
     *
     * Returns a JSON document with the portal status, env, version, PHP
     * version, current site ID, and the result of a lightweight DB ping
     * (`SELECT 1`). Returns 200 only when the DB ping succeeds — uptime
     * monitors should treat any non-200 as a failure.
     *
     * Output shape:
     *   {
     *     "status":    "ok" | "degraded",
     *     "env":       "dev" | "beta" | "prod",
     *     "version":   "1.0.0",
     *     "phpVersion":"8.5.5",
     *     "siteID":    1,
     *     "db":        "ok" | "error",
     *     "time":      "2026-05-22T13:00:00+00:00"
     *   }
     *
     * The endpoint never requires auth — it's whitelisted at the top of
     * Router::dispatch(). Designed for CloudFlare / Pingdom / GitHub
     * Actions uptime probes.
     *
     * @return void
     */
    private static function healthCheck(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        // 🩺 Lightweight DB ping — selects literal 1 so any working
        // connection succeeds, no schema dependency.
        $dbStatus = 'error';
        try {
            $db = App::db();
            $result = $db->query('SELECT 1');
            if ($result !== false) {
                $row = $result->fetch_row();
                if ($row !== null && (int) ($row[0] ?? 0) === 1) {
                    $dbStatus = 'ok';
                }
            }
        } catch (\Throwable) {
            // 📝 Don't leak details in the response — Logger captured it.
            $dbStatus = 'error';
        }

        $overall = $dbStatus === 'ok' ? 'ok' : 'degraded';
        http_response_code($dbStatus === 'ok' ? 200 : 503);

        // 🧭 `channelSource` says HOW the channel in `env` was decided:
        //    'environment' (the PORTAL_ENV setting), 'file' (the `.channel`
        //    file), 'folder' (the web folder is named public_html,
        //    public_html_beta or public_html_dev) or 'assumed' (nothing
        //    says, so the portal takes itself to be live: see Channel.php).
        //    It is here so that whoever uploads the code, or a monitor, can
        //    see at once that an installation's channel was never written
        //    down, from an address that answers without a sign-in on every
        //    channel. For an assumed channel it is the ONLY sign visible
        //    from outside. It gives away no more than `env` beside it
        //    already does.
        echo json_encode([
            'status'     => $overall,
            'env'        => PORTAL_ENV,
            'channelSource' => PORTAL_ENV_SOURCE,
            'version'    => App::version(),
            'phpVersion' => PHP_VERSION,
            'siteID'     => Site::id(),
            'db'         => $dbStatus,
            'time'       => gmdate('c'),
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    /**
     * 🔗 The address of a page, inside the organisation being looked at.
     *
     * THIS IS NOW ONE LINE THAT ASKS Site::url() (#536). The two used to be
     * two copies of one rule. They agreed on every page, but nothing made
     * them agree, and the next change to how an organisation's part of the
     * address is added would have had to be made twice. Site::url() is the
     * rule; this is the older name for it, kept because more than a
     * hundred calls already use it (120 on 13 October 2026; Site::url()
     * itself is called more than twice as often).
     *
     * WHAT IT CANNOT DO
     *   - It does not check that the address is a registered one;
     *     routeExists() below does that.
     *   - It builds an address for the organisation THIS request is in. It
     *     cannot build one for another organisation, or a whole address with
     *     a host name for an e-mail.
     *   - During start-up, before Site::init() has run, it adds no
     *     organisation's part (the old copy did). Nothing builds an address
     *     that early.
     *
     * @param string $routeKey The route key (e.g. "expenses/submit")
     *
     * @return string The clean URL (e.g. "/expenses/submit")
     */
    public static function url(string $routeKey): string
    {
        return Site::url($routeKey);
    }

    /**
     * 📜 The same address, made safe to sit INSIDE a quoted string in a
     * script that a page prints:
     *
     *     fetch('<?php echo Router::urlForScript('geo/lookup'); ?>', { … })
     *
     * WHY NOT htmlspecialchars(). Inside a <script> element the browser does
     * not turn "&amp;" back into "&", so an address escaped for HTML would
     * be sent with the escapes still in it. A script needs its own escaping:
     * a quote, "<", ">" and "&" are written as \uXXXX, and a backslash as
     * two backslashes. A script reads each back as the character, and none
     * of them can end the string or end the <script> element.
     *
     * WHY NOT json_encode() ON ITS OWN, WHICH PRINTS ITS OWN QUOTES. The
     * pages this is used in already have quotes round the address, some
     * single and some double. Keeping the page's own quotes means that with
     * no organisation's part in use the page comes out byte for byte as it
     * did before, which is how the link sweep is proved to have changed
     * nothing for an ordinary installation (#536).
     *
     * WHAT IT CANNOT DO: it is not safe inside a string written between
     * backticks (a "${" is not escaped), nor outside quotes altogether. Put
     * it between single or double quotes only.
     *
     * @param string $routeKey The route key (e.g. "geo/lookup")
     *
     * @return string The address, with no quotes of its own
     */
    public static function urlForScript(string $routeKey): string
    {
        $quoted = json_encode(
            Site::url($routeKey),
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
        );

        // json_encode() gives the text with a double quote at each end; the
        // page supplies its own, so both are taken off.
        return $quoted === false ? '' : substr($quoted, 1, -1);
    }

    /**
     * @var array<string, bool>|null Every route key in the database, loaded
     * once per request. Null until the first call to routeExists().
     */
    private static ?array $routeKeyCache = null;

    /**
     * Is there actually a page at this address?
     *
     * Menus and dashboards build links from the settings table, which knows
     * which apps are switched on but knows nothing about which addresses
     * exist. Before this existed, several links were being drawn for apps
     * whose real entry page sits somewhere else — `/salvation` when the page
     * is at `/decision-card`, `/reports` when it is at `/admin/reports`,
     * `/kids` when there is no such page at all, only `/kids/checkin`. Every
     * one of those links led to "page not found".
     *
     * Call this before drawing any link built from a setting name rather than
     * from a known address.
     *
     * Every route key is fetched once and kept for the rest of the request,
     * so a menu with thirty links still costs a single query. If the query
     * fails, this returns true — a menu missing every one of its links would
     * be a far worse outcome than a link that might not work.
     *
     * @param string $routeKey The address to check, with no leading slash
     *                         (for example "decision-card" or "kids/checkin").
     *
     * @return bool True when a page is registered at that address.
     */
    public static function routeExists(string $routeKey): bool
    {
        $routeKey = trim($routeKey, '/');
        if ($routeKey === '') {
            return false;
        }

        if (self::$routeKeyCache === null) {
            try {
                $db     = App::db();
                $result = $db->query('SELECT routeKey FROM tblRoutes');
                if ($result === false) {
                    // Leave the cache unset so a later call can try again, and
                    // say yes for now — a menu missing every one of its links
                    // is a far worse outcome than a link that might not work.
                    return true;
                }

                self::$routeKeyCache = [];
                while ($row = $result->fetch_assoc()) {
                    self::$routeKeyCache[(string) $row['routeKey']] = true;
                }
                $result->free();
            } catch (\Throwable $e) {
                // No database connection yet, or the query threw. Same
                // reasoning as above: do not hide the menu over it.
                Logger::errorPlatform('MySQL', 'Error', 'ROUTE_LIST_FAIL', $e->getMessage(), '');
                return true;
            }
        }

        return isset(self::$routeKeyCache[$routeKey]);
    }

    /**
     * Get the current normalised request path.
     *
     * @return string The current path
     */
    public static function currentPath(): string
    {
        if (defined('PORTAL_CURRENT_ROUTE') === true) {
            return PORTAL_CURRENT_ROUTE;
        }
        return self::extractPath();
    }

    /**
     * Check if the current request path matches a given pattern.
     * Supports wildcard (*) at the end for prefix matching.
     *
     * Examples:
     *   Router::is('expenses/*')   → true for /expenses/submit, /expenses/approve
     *   Router::is('dashboard')    → true only for /dashboard
     *
     * @param string $pattern Route pattern to match against
     *
     * @return bool True if the current path matches the pattern
     */
    public static function is(string $pattern): bool
    {
        $current = self::currentPath();

        // 🔍 Wildcard prefix match
        if (str_ends_with($pattern, '*') === true) {
            $prefix = rtrim($pattern, '*');
            return str_starts_with($current, $prefix);
        }

        // 🔍 Exact match
        return $current === $pattern;
    }
}
