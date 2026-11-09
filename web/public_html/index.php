<?php
// Path: public_html/index.php  (front-controller)
/**
 * -----------------------------------------------------------------------------
 * Portal Front Controller 🎯
 * -----------------------------------------------------------------------------
 * Single entry point for all live traffic.  Loads bootstrap then hands the
 * request to Core\Router.  Default route keys come from tblRoutes; if URL is
 * empty, Router maps it to "dashboard" (see Router::extractPath).
 *
 * The folder this file sits in is the web folder. The repository calls it
 * public_html; a customer's hosting may call it anything (httpdocs, www,
 * admin_html), and nothing below depends on the name.
 *
 * @package   Portal
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
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

// 🌐 The web folder is THIS file's own folder, whatever it is called. Set
//    before start-up, which only falls back to the shipped name when nobody
//    has said (see the note where bootstrap.php defines PORTAL_WEBROOT).
define('PORTAL_WEBROOT', __DIR__);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\Auth;
use Portal\Core\Gatekeeper;
// ⚠️ A class used by its SHORT name needs its `use` line in THIS file, and the
//    line only fails when it RUNS — `php -l` is happy without it (#536).
use Portal\Core\I18n;
use Portal\Core\Maintenance;
use Portal\Core\PublicDoor;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\SiteGate;

// 🚪 THE PUBLIC DOOR (#493 P4a; D-056, D-097, D-098), answered BEFORE the
//    session is started. A request on an organisation's public address, or to
//    /w/{name}/… on any other, was recognised by Site::preDetect() while the
//    portal started up — from the address alone, before any session was
//    looked at. It never reaches the session start below, the maintenance
//    gate, the test-copy gate, the organisation gate or the router: the door
//    asks maintenance, D-087 and its own questions itself, in its own order,
//    sets no cookie, and answers every refusal with one identical 404 (see
//    web/_core/PublicDoor.php). Placed above Auth::ensureSession() on
//    purpose: below it, every door answer would carry a session cookie and
//    leave a session file behind.
if (Site::isDoorRequest() === true) {
    PublicDoor::answer($mysqli);
}
// 🔁 And when it could not even be told whether this host is an organisation's
//    public address (the look-up failed), nothing is drawn and no session is
//    started: the same plain "try again" from every way in (round 2 of P4a's
//    check). The organisation gate would have said "try again" a moment later
//    anyway, but only after a session cookie and file had been made.
if (Site::publicAddressUnknown() === true) {
    PublicDoor::tryAgain();
}

// 🔑 Start (or resume) the session BEFORE the maintenance gate below.
//    Maintenance::currentUserCanBypass() → App::isUmbrellaAdmin() → App::user()
//    reads $_SESSION, but only if session_status() === PHP_SESSION_ACTIVE
//    (see App::user()) — and nothing upstream in bootstrap.php leaves a
//    session started. (Since 12 October 2026, #502, start-up does take one
//    read-only look at the session when organisations are told apart by the
//    sign-in session, and closes it again at once: Auth::peekSession(). No
//    session is active when start-up finishes, in any of the three ways.)
//    Without this call here, a logged-in admin's
//    session cookie is never read this early, App::user() caches a null
//    "not logged in" result for the rest of the request (self::$userLoaded
//    latches true on first call), and currentUserCanBypass() always
//    returns false — so admins get the 503 page along with everyone else
//    with no way to sign in and lift maintenance mode. ensureSession() is
//    idempotent (no-ops if already active), so this can't double-start the
//    session that app handlers start further down the line.
Auth::ensureSession();

// 🌍 ASK THE LANGUAGE QUESTION AGAIN, NOW THAT THERE IS A SESSION (#763).
//    `I18n::init()` runs inside bootstrap, BEFORE the line above — and the
//    first two things it consults are the member's own saved language and the
//    language switcher's choice, both of which live in the session. Neither
//    existed yet, so both were skipped and **a member's saved language was
//    applied nowhere**: every page was drawn in whatever `Accept-Language`
//    said, or the installation's default. Measured by driving the class
//    (`tools/i18n-member-language-selftest.php`), not over HTTP against a
//    drawn page — the test says so in its own closing lines.
//
//    It must come AFTER ensureSession() and BEFORE anything draws, which is
//    why it is here rather than in bootstrap. `init()` latches, so this is a
//    separate call rather than a second init.
I18n::reconsiderNowSessionExists($mysqli ?? null);

// 🌍 A LANGUAGE CHOSEN FROM AN ADDRESS, ?lang=cy (#763).
//    This ran inside bootstrap until 5 November 2026, which meant
//    `I18n::switchLocale()` found no session: it stored nothing and wrote
//    nothing, so the choice lasted exactly as long as the request that made
//    it and was gone by the time the redirect below landed. It is the ONLY
//    thing that has ever written `tblUsers.locale`.
//
//    It must come after `ensureSession()` and before anything draws. It keeps
//    its place ahead of the maintenance and membership gates, because
//    choosing a language is not something anybody should have to get past a
//    gate to do.
//
// #509 point 2: `switchLocale()` takes a `string`, so a request sent as a
//    list — ?lang[]=x — used to arrive as an array and PHP's typed parameter
//    refused it with a TypeError: a 500 and one error row, on every address,
//    open or closed. Anything that is not text is ignored rather than
//    crashing. Do not narrow that test back.
if (isset($_GET['lang']) === true && is_string($_GET['lang']) === true && $_GET['lang'] !== '') {
    I18n::switchLocale($_GET['lang']);
    // 🔀 Back to the same page without the lang parameter, so the address does
    //    not carry it around for ever.
    $currentUri = $_SERVER['REQUEST_URI'] ?? '/';
    $parsed     = parse_url($currentUri);
    $path       = $parsed['path'] ?? '/';
    parse_str($parsed['query'] ?? '', $queryParams);
    unset($queryParams['lang']);
    $newQuery = http_build_query($queryParams);
    $redirect = $path . ($newQuery !== '' ? '?' . $newQuery : '');
    header('Location: ' . $redirect, true, 302);
    exit();
}

// 🚧 Maintenance gate (#220).
//    If portal.maintenance.active = '1', OR the installed_version is
//    behind PORTAL_VERSION AND a database upgrade is really waiting (new
//    code was deployed but the DB hasn't been brought up yet), gate
//    non-admin / non-allow-listed requests to a 503 maintenance page. Newer
//    code with NO database upgrade waiting does not close the portal, and
//    the gate records nothing: it asks again on every request until the
//    Upgrade page's button has recorded the version (#499, 13 October
//    2026; see the top of Maintenance.php). Global administrators, and the
//    addresses on the two allow lists in web/_core/Maintenance.php, pass
//    through: the sign-in pages, admin/upgrade, admin/maintenance, the
//    static asset folders and offline, so a global administrator can sign
//    in and run the upgrade; and the two exact addresses cron/health and
//    health, so an uptime monitor still gets its read-only health report
//    and an upload can still be checked. Those lists
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

// 🚧 Pre-release channel gate (alpha / beta / dev).
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
// three are named explicitly, in Channel::PRE_RELEASE, which enforce() uses
// too. Which channel this installation is comes from Channel::decide()
// (bootstrap.php), in this order: the PORTAL_ENV setting, the `.channel`
// file, the web folder's name when it is exactly one of three, and otherwise
// it is ASSUMED TO BE LIVE.
//
// AN INSTALLATION THAT SAYS NOTHING IS THEREFORE NOT GATED (owner's final
// rule, 12 October 2026): nobody is locked out for want of a file. Its source
// is 'assumed', and shouldEnforce() writes one line to PHP's error log on
// each request saying so, and what settles it.
//
// WHAT THIS HAS DONE BEFORE: the channel was guessed from the web folder's
// path, and the gate stood aside whenever that guess failed, which left a
// pre-release copy with an unexpected folder name open with its errors on
// screen. Then, for one build that was never released (commit 7b30c99), an
// installation that said nothing was gated as a 'dev' copy, which refused
// every member of a live portal the moment the code was uploaded without a
// new file. The first version of all gated on 'dev' when 'dev' was only the
// failed guess.
//
// The defined() check covers a half-finished upload, in which this file has
// arrived but an older bootstrap.php that does not set PORTAL_ENV_SOURCE is
// still in place. The source is then taken as unknown (an empty word). That
// does not decide whether the gate runs (the channel alone does); it only
// means no line is written to the log for the moments the upload takes.
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
$portalChannelSource = defined('PORTAL_ENV_SOURCE') === true ? (string) PORTAL_ENV_SOURCE : '';
if (Gatekeeper::shouldEnforce(PORTAL_ENV, $portalChannelSource) === true) {
    Gatekeeper::enforce(PORTAL_ENV);
}

// 🚪 The organisation gate (#502).
//    With several organisations on one installation, bootstrap.php has by now
//    worked out which organisation this request belongs to
//    (Site::preDetect()). SiteGate stops the request here when it must not
//    reach a page: the organisation could not be worked out; a person with
//    several organisations has not chosen one yet; or (#511) the signed-in
//    person is not a member of the organisation this request belongs to.
//    That last one used to be nobody's job: the router below asks only
//    "signed in?", so a member of one organisation could open another's
//    pages and data. Its own header comment has the full list.
//
//    WHY HERE. After Auth::ensureSession() above, because it needs to know
//    who is signed in. After the maintenance and pre-release gates, so an
//    upgrade in progress still shows the holding page. And immediately
//    before Router::dispatch(), because that one call answers every address
//    there is, the data addresses under /api/ included: nothing can reach a
//    page or a handler without passing this line.
//
//    With one organisation (the ordinary installation) it returns at once
//    and changes nothing.
SiteGate::enforce($mysqli);

Router::dispatch($mysqli);