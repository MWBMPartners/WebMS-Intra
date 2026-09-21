<?php
// Path: public_html/index.php  (front-controller)
/**
 * -----------------------------------------------------------------------------
 * Portal Front Controller 🎯
 * -----------------------------------------------------------------------------
 * Single entry point for all live traffic.  Loads bootstrap then hands the
 * request to Core\Router.  Default route keys come from tblRoutes; if URL is
 * empty, Router maps it to "dashboard" (see Router::extractPath).
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// 🔧 Check if the portal has been installed (credentials file exists)
$authCredsPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '_auth_keys' . DIRECTORY_SEPARATOR . 'auth_creds.php';
if (is_readable($authCredsPath) === false) {
    // 📌 Redirect to the installation wizard
    $installPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '_install' . DIRECTORY_SEPARATOR . 'index.php';
    if (is_readable($installPath) === true) {
        require $installPath;
        exit();
    }
    http_response_code(500);
    exit('Portal not configured. Please run the installer.');
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\Auth;
use Portal\Core\Gatekeeper;
use Portal\Core\Maintenance;
use Portal\Core\Router;

// 🔑 Start (or resume) the session BEFORE the maintenance gate below.
//    Maintenance::currentUserCanBypass() → App::isUmbrellaAdmin() → App::user()
//    reads $_SESSION, but only if session_status() === PHP_SESSION_ACTIVE
//    (see App::user()) — and nothing upstream in bootstrap.php starts a
//    session (it's started lazily by individual app pages via this same
//    Auth::ensureSession() call). Without it here, a logged-in admin's
//    session cookie is never read this early, App::user() caches a null
//    "not logged in" result for the rest of the request (self::$userLoaded
//    latches true on first call), and currentUserCanBypass() always
//    returns false — so admins get the 503 page along with everyone else
//    with no way to sign in and lift maintenance mode. ensureSession() is
//    idempotent (no-ops if already active), so this can't double-start the
//    session that app handlers start further down the line.
Auth::ensureSession();

// 🚧 Maintenance gate (#220).
//    If portal.maintenance.active = '1' OR the installed_version is
//    behind PORTAL_VERSION (indicating new code was deployed but the
//    DB hasn't been brought up yet), gate non-admin / non-allow-listed
//    requests to a 503 maintenance page. Global administrators, and the
//    addresses on the two allow lists in web/_core/Maintenance.php, pass
//    through: the sign-in pages, admin/upgrade, admin/maintenance, the
//    static asset folders and offline, so a global administrator can sign
//    in and run the upgrade; and the one exact address cron/health, so an
//    uptime monitor still gets its read-only health report. Those lists
//    are the real ones; this summary is only a guide and has gone out of
//    date before. Narrowed from "any administrator" to "global
//    administrator only" by an owner decision on 20 September 2026 (#515)
//    — see Maintenance::currentUserCanBypass()'s own doc comment for why.
//
//    #509 point 3 — CHANGED: the three-part condition this line used to
//    spell out here has moved into Maintenance::blocks(), which adds one
//    more rule this file used to be missing: every cron/ address OTHER
//    than cron/health is now blocked for EVERYBODY while the portal is
//    closed, administrators included — a signed-in administrator opening
//    a scheduled-job address by hand during an upgrade used to run it for
//    real, against what might be a half-upgraded database. See
//    Maintenance::blocks()'s own doc comment for the full ordering and
//    reasoning; this file no longer needs to know the rule's shape, only
//    that it exists.
if (Maintenance::blocks(Router::extractPath()) === true) {
    Maintenance::renderAndExit();
}

// 🚧 Pre-release channel gate (alpha / beta).
// -----------------------------------------------------------------------------
// WHAT WAS WRONG
// -----------------------------------------------------------------------------
// web/_core/Gatekeeper.php exists to keep strangers out of the alpha and beta
// copies of the portal — the ones running unfinished work. Searching the whole
// of web/ for the word "Gatekeeper" found the class itself and one line of
// translated text, and nothing else. Nothing had ever called it. So the
// pre-release portals were open to anybody who knew the address, and had been
// since the class was written.
//
// This is the project's "shipped but unreachable" pattern pointing the
// dangerous way round: not a feature nobody can use, but a protection nobody
// was getting.
//
// -----------------------------------------------------------------------------
// WHY IT IS PLACED HERE, AFTER THE MAINTENANCE GATE
// -----------------------------------------------------------------------------
// It has to come after Auth::ensureSession() above, because it needs to know
// who is signed in. It comes after the maintenance gate so that an upgrade in
// progress still shows the "back shortly" page rather than a sign-in redirect.
// It comes before Router::dispatch() because from that point on the request
// belongs to a page.
//
// It cannot lock anybody out of the installer. When the portal has not been
// installed yet, the top of this file hands the request to the installation
// wizard and stops, long before this line is reached.
//
// -----------------------------------------------------------------------------
// WHEN IT RUNS — decided in ONE place, Gatekeeper::shouldEnforce()
// -----------------------------------------------------------------------------
// Only on the 'dev', 'beta' and 'alpha' channels, never on the live one. Those
// three are named explicitly, in Gatekeeper::VALID_CHANNELS, which enforce()
// uses too. So a future new value, or an unexpected value from the PORTAL_ENV
// environment variable, cannot switch the gate on by accident.
// ('alpha' was missing from the first version, although the in-app help says
// alpha sites are gated. bootstrap.php only produces 'alpha' from the
// environment variable, so it is always a deliberate choice.)
//
// And only when bootstrap.php worked the channel out from something deliberate
// — PORTAL_ENV_SOURCE is 'environment' or 'folder' — never from its
// last-resort guess, 'fallback'.
//
// WHAT WAS WRONG: the first version gated on 'dev' however that had been
// decided. bootstrap.php settles on 'dev' when it recognises neither an
// environment variable nor a web folder name, so a LIVE server whose web folder
// simply has an unexpected name would have asked every ordinary member to sign
// in as staff. When the gate now declines for that reason it writes one line to
// PHP's error log, so a genuine pre-release copy left open by it is noticed.
//
// The defined() check covers a half-finished deploy, in which this file has
// arrived but an older bootstrap.php that does not set PORTAL_ENV_SOURCE is
// still in place. The channel is then treated as a guess: the direction that
// cannot lock members out.
//
// -----------------------------------------------------------------------------
// THE WAY OUT, IF THIS EVER SHUTS THE WRONG PEOPLE OUT
// -----------------------------------------------------------------------------
// portal.gatekeeper.enabled, seeded 'true'. Setting it to 'false' switches the
// gate off. An administrator is never locked out by the gate itself — the
// sign-in pages are on its open list, and anybody with the Admin or Root Admin
// flag on their user record passes it — so there is always a way back in to
// change it. Only the PORTAL-WIDE row of that setting is read (a row added for
// one organisation is ignored — see Gatekeeper::shouldEnforce()), and changing
// the portal-wide row needs a global administrator.
//
// -----------------------------------------------------------------------------
// KNOWN CONSEQUENCE, WRITTEN DOWN RATHER THAN DISCOVERED
// -----------------------------------------------------------------------------
// On the alpha and beta channels this now also refuses the addresses that
// machines rather than people use: the scheduled-job addresses under /cron/,
// the payment and Zoom notification addresses, and the newsletter open/click
// trackers. Each of those already carries its own secret or signature, so they
// were never the open door this gate is about. If a scheduled job or a payment
// notification is genuinely wanted on a pre-release channel, add 'cron' to
// Gatekeeper::OPEN_PREFIXES (and the individual addresses to OPEN_PATHS)
// rather than switching the whole gate off.
$portalChannelSource = defined('PORTAL_ENV_SOURCE') === true ? (string) PORTAL_ENV_SOURCE : 'fallback';
if (Gatekeeper::shouldEnforce(PORTAL_ENV, $portalChannelSource) === true) {
    Gatekeeper::enforce(PORTAL_ENV);
}

Router::dispatch($mysqli);