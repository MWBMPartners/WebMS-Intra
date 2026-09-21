<?php
// Path: _core/Maintenance.php
/**
 * -----------------------------------------------------------------------------
 * WebMS Intra — Maintenance Mode 🚧
 * -----------------------------------------------------------------------------
 * Gate that locks public portal access while an upgrade / migration / drop
 * is in progress, then automatically releases when the work completes.
 *
 * Two state inputs combine to decide whether a request is gated:
 *
 *   1. portal.installed_version  (tblSettings, written by installer step 5
 *      and by Migrator::runAll() on success). If LESS than the code version
 *      from _core/version.php → "version drift" → gate on.
 *
 *   2. portal.maintenance.active (tblSettings, written by /admin/upgrade
 *      while it's running, and by the installer's drop-and-rebuild path).
 *      If '1' → gate on.
 *
 * Either input alone is sufficient to gate. Both clear automatically when
 * the upgrade completes — version drift resolves when installed_version
 * gets bumped; the explicit flag is cleared by whoever set it.
 *
 * Allow-list (always pass through, even when gated):
 *   • /login, /logout        — a global administrator needs to sign in to fix it
 *   • /forgot-password,
 *     /reset-password        — and to get back in if they have forgotten it
 *   • /admin/upgrade*        — the upgrader itself
 *   • /admin/maintenance*    — backup / restore UI
 *   • /cron/health           — that exact address only, not anything that
 *                              starts with it: the uptime monitor's
 *                              read-only health report (owner's decision,
 *                              14 September 2026). Every OTHER cron/
 *                              address is blocked for EVERYBODY while
 *                              maintenance is on, administrators included —
 *                              enforced by blocks(), not by this allow
 *                              list, because an administrator would
 *                              otherwise bypass it like any other gated
 *                              page (#509 point 3). See EXACT_ALLOW_LIST.
 *   • /assets/css/* /js/* /images/* /fonts/* /vendor/* /noticeboard/*
 *                            — static subdirs only (CSS/JS for the
 *                              maintenance page itself). NOT a bare
 *                              `assets/` prefix (#393) — the Asset Tracker
 *                              app now owns the `/assets` route, and a
 *                              bare prefix would let its pages bypass
 *                              maintenance mode entirely via prefix match.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Maintenance
{
    /**
     * Routes that bypass the gate even when maintenance is active.
     * Path prefixes — checked with `str_starts_with`.
     */
    private const ALLOW_LIST = [
        // 🚪 THE WAY BACK IN. Get these wrong and an administrator is locked out
        //    of their own portal with no way to fix it, which is exactly what
        //    happened here.
        //
        //    These are compared against the ADDRESS a visitor typed — the
        //    routeKey — not against the file that answers it. The two are not
        //    the same, and the difference is easy to miss because they look
        //    alike. The sign-in page is a good example: its address is `login`,
        //    while the file that answers it is `auth/login/index.php`.
        //
        //    This list used to say `auth/login` and `auth/logout`. Those are
        //    fragments of FILE PATHS. Neither is an address, so neither ever
        //    matched anything, and the sign-in page was blocked along with
        //    everything else.
        //
        //    That mattered more than it sounds, because maintenance mode
        //    switches itself on whenever the code is newer than the database —
        //    which is every single upgrade. A signed-out administrator during an
        //    upgrade was shut out completely, and the holding page's own
        //    "sign in" link pointed at the same address that did not exist.
        //
        //    Before adding anything here, check it against the seeded addresses
        //    in web/_sql/full_schema.sql, not against the folder layout.
        'login',
        'logout',
        'forgot-password',
        'reset-password',

        // 🔐 The second step of signing in, for anybody using a code from their
        //    phone. Leaving this out is a half-fix that looks like a whole one:
        //    the administrator reaches the sign-in page, types the right
        //    password, and is then sent here — straight into the wall the fix
        //    was supposed to remove. Found by listing every address involved in
        //    signing in and checking each one, rather than by assuming the
        //    sign-in page was the whole journey.
        //
        //    Three separate places send people here after a correct password:
        //    auth/login/index.php, auth/login/webauthn.php and Auth.php.
        //
        //    Only `verify` is listed. Setting up two-factor and turning it OFF
        //    are account housekeeping, not part of getting back in, and
        //    `auth/2fa/disable` in particular has no business being reachable
        //    while the portal is closed.
        'auth/2fa/verify',
        'admin/upgrade',
        'admin/maintenance',
        // 🎯 Static asset subdirs ONLY (#393) — deliberately NOT a bare
        //    `assets/` prefix. The Asset Tracker app's routes all live
        //    under `/assets` too (`/assets`, `/assets/item`, …), and a
        //    bare-prefix entry here would let every Asset Tracker page
        //    bypass maintenance mode right along with the CSS/JS this
        //    list exists to allow. Listing the real static subdirs by
        //    name keeps the CSS/JS the maintenance page itself needs
        //    reachable while closing that gap.
        'assets/css/',
        'assets/js/',
        'assets/images/',
        'assets/fonts/',
        'assets/vendor/',
        'assets/noticeboard/',
        'offline',
    ];

    /**
     * Addresses that bypass the gate ONLY when the whole address matches,
     * character for character. Compared with an exact in_array() check,
     * never with `str_starts_with` like ALLOW_LIST above.
     */
    private const EXACT_ALLOW_LIST = [
        // 🩺 THE UPTIME MONITOR'S HEALTH REPORT — AND NOTHING ELSE UNDER cron/.
        //
        //    Owner's decision, 14 September 2026. Until then a monitor called
        //    /admin/maintenance/health?cron=1&token=…, which the
        //    `admin/maintenance` entry above let through. So during an upgrade
        //    the monitor still got its report, including the line saying that
        //    maintenance mode is on. Issue #497 moved that report to its own
        //    address, /cron/health, and nothing under cron/ was on this list,
        //    so a monitor would have seen the portal as simply down for the
        //    whole of every upgrade.
        //
        //    Only this one job is let through. The other scheduled jobs
        //    (cron/retention-sweep, cron/backup-check, cron/event-reminders and
        //    the rest) stay behind the holding page on purpose. They delete,
        //    send or change things, and must not do that to a database that is
        //    half way through an upgrade. The health checks are meant only to
        //    read, and that is the whole reason this one is let through.
        //
        //    Three ways the page COULD still write were found on 14 September
        //    2026, and all three are closed in web/_apps/cron/health.php. The
        //    portal's error handler writes a tblErrors row for every PHP
        //    warning, even one hidden with "@". A warning could come from:
        //      - the session count (a session file deleted while being
        //        counted);
        //      - the token check itself, for anybody without the token
        //        (sending ?token[]=x);
        //      - header(), on a copy that shows errors on screen with output
        //        not held back, once a visible warning from the checks had
        //        been printed and so had sent the headers early. The page's
        //        harmless handler had already been put back by then.
        //    The page now accepts the token only as plain text, never prints a
        //    PHP warning into its answer, and while maintenance mode is on
        //    keeps a handler that does not touch the database in place from
        //    just before the token check until the page stops. See the comment
        //    there for what that cannot cover. In particular, code that runs
        //    BEFORE any page, for every address including the holding page,
        //    can still write an error row (?lang[]=x in bootstrap does); that
        //    is not something this list or that page can change.
        //
        //    What was measured, against a test database: correct, wrong,
        //    missing and list-shaped tokens and every warning condition above,
        //    under both PHP's built-in server and php-cgi with output not held
        //    back and errors shown on screen. Nothing was added to tblErrors.
        //    That covers those calls, not every request anybody could invent.
        //    If anybody ever makes the health checks write something, this
        //    entry has to come out again.
        //
        //    WHY AN EXACT MATCH, NOT A PREFIX
        //    Putting 'cron/health' in ALLOW_LIST would let through every
        //    address that merely STARTS with those letters. A future
        //    cron/health-report-mailer or cron/healthcheck-writer would then
        //    walk past the holding page without anybody having decided that it
        //    should. An exact match cannot grow by accident.
        //
        //    WHAT STRING THIS IS COMPARED WITH
        //    The front controller hands isAllowed() the result of
        //    Router::extractPath(), the same function the Router uses to pick
        //    the page. By then the address has lost its query string, been put
        //    into lower case, had slashes trimmed from both ends and repeated
        //    slashes squeezed to one, and (when organisations are told apart by
        //    the first part of the address) had the organisation's part removed.
        //    So /cron/health?token=…, /cron/health/, /CRON/Health and
        //    /<organisation>/cron/health all arrive here as exactly
        //    'cron/health'. That is right, because the Router treats every one
        //    of them as the same page. /cron/healthx, /cron/health-writer and
        //    /cron/health/extra do not match, and get the holding page.
        //
        //    WHAT THIS CANNOT DO
        //    - It does not check the token. It only lets the request reach
        //      web/_apps/cron/health.php, which refuses a missing or wrong
        //      token itself (403).
        //    - It does not get the request past the pre-release gate. On an
        //      alpha or beta copy, web/_core/Gatekeeper.php runs after this and
        //      still sends a caller with no session to the sign-in page.
        //    - It depends on being given Router::extractPath(). A string tidied
        //      up some other way (a trailing slash left on, say) just fails to
        //      match, which keeps the holding page up: the safe way round.
        'cron/health',
    ];

    /**
     * Is maintenance currently active? Combines version-drift detection
     * with the explicit `portal.maintenance.active` flag.
     */
    public static function isActive(): bool
    {
        // 🆔 Explicit flag wins — if an admin (or the installer) flipped
        //    it on, we're in maintenance regardless of versions.
        $explicit = App::settings()['portal']['maintenance']['active'] ?? '0';
        if ((string) $explicit === '1' || $explicit === true) {
            return true;
        }

        // 🔢 Version-drift check. portal.installed_version is what's
        //    actually in the DB; PORTAL_VERSION is what the code on
        //    disk thinks. If the code is newer than the DB, an upgrade
        //    is needed and we shouldn't let users in until it's run.
        $installed = App::settings()['portal']['installed_version'] ?? '';
        if (!is_string($installed) || $installed === '') {
            // 🪞 Legacy install — never recorded a version. Don't gate
            //    on a missing value; let the user in and let the next
            //    explicit upgrade record the version.
            return false;
        }
        if (defined('PORTAL_VERSION')
            && version_compare($installed, (string) PORTAL_VERSION, '<')
        ) {
            return true;
        }

        return false;
    }

    /**
     * Is the given request path allowed through even when maintenance
     * is active? Pass Router::extractPath() — see EXACT_ALLOW_LIST for why
     * the exact matches depend on that.
     */
    public static function isAllowed(string $routeKey): bool
    {
        $routeKey = ltrim($routeKey, '/');

        // 🎯 Whole-address matches first. Strict comparison, so only the
        //    exact text listed counts — never an address that starts with it.
        if (in_array($routeKey, self::EXACT_ALLOW_LIST, true) === true) {
            return true;
        }

        foreach (self::ALLOW_LIST as $prefix) {
            if (str_starts_with($routeKey, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Is the current session a GLOBAL administrator? Only a global
     * administrator can pass through the gate to reach anything other than
     * the always-open addresses on the two allow lists above.
     *
     * OWNER DECISION, 20 September 2026 — narrowed from "any administrator"
     * to "a global administrator only". Before this change, the gate used
     * `App::isAdmin()`, which is true for a SITE administrator of whichever
     * ONE organisation happens to be open, and for an account carrying the
     * older, portal-wide `isAdmin` flag — neither of whom can necessarily
     * finish an upgrade, and both of whom could otherwise browse a
     * half-upgraded database while everybody else saw the closed sign. That
     * let far more people in than the one kind of account that can actually
     * complete the upgrade (`web/_install/upgrade.php` has always required
     * `App::isUmbrellaAdmin()`, so this wrapper now agrees with it — the
     * SAME word, so the two gates visibly match rather than quietly
     * diverging). Nobody is locked out of anything they could actually use:
     * sign-in, the upgrade page and the health check stay open to everybody
     * regardless of this method (see the allow lists above), so a site
     * administrator who cannot bypass maintenance was never going to be
     * able to run the upgrade either way.
     *
     * WHAT THIS CANNOT DO: it does not close `cron/health` — that stays
     * reachable by design, on EXACT_ALLOW_LIST, before this method is ever
     * consulted. A global administrator who signs in during maintenance
     * still SEES a half-upgraded portal; that is the point of letting them
     * in at all — to finish the upgrade, not to use the portal normally.
     */
    public static function currentUserCanBypass(): bool
    {
        // 🪞 `App::isUmbrellaAdmin()` is the canonical predicate for "global
        //    administrator" — the same word `web/_install/upgrade.php` uses
        //    for the same distinction, so the two gates cannot quietly drift
        //    apart from each other again.
        return App::isUmbrellaAdmin();
    }

    /**
     * Does maintenance mode stop THIS request? The ONE place that answer is
     * decided, so the front controller does not have to repeat the
     * ordering rules itself.
     *
     * Closed AND not on either allow list AND (a cron address, which
     * NOBODY bypasses, OR a visitor who may not bypass).
     *
     * #509 point 3: before this method existed, ANY administrator —
     * including a legacy `isAdmin`-flag account with no real membership
     * anywhere — could open a scheduled-job address by hand while the
     * portal was closed and have it run for real. `/cron/retention-sweep`
     * did exactly that: a signed-in administrator opening it mid-upgrade
     * ran the clear-out against what could be a half-upgraded database.
     * `/cron/health` is deliberately unaffected by this rule — it is on
     * EXACT_ALLOW_LIST, so `isAllowed()` already lets it through before
     * this method is even reached; every OTHER cron/ address is now
     * blocked for everybody, administrators included, whatever
     * `currentUserCanBypass()` says.
     *
     * Order matters for cost, not just correctness: `isActive()` only
     * reads settings already in memory; `currentUserCanBypass()` may query
     * the account (App::isUmbrellaAdmin() → App::user()), so it is asked
     * LAST, once every cheaper answer has already failed to settle the
     * question.
     *
     * @param string $routeKey The address the visitor asked for — pass
     *                         Router::extractPath(), matching isAllowed().
     */
    public static function blocks(string $routeKey): bool
    {
        if (self::isActive() === false) {
            return false;
        }
        if (self::isAllowed($routeKey) === true) {
            return false;
        }
        if (str_starts_with(ltrim($routeKey, '/'), 'cron/') === true) {
            return true;
        }
        return self::currentUserCanBypass() === false;
    }

    /**
     * Flip the explicit maintenance flag on or off.
     *
     * @param bool $active true → enable maintenance; false → release.
     */
    public static function setActive(bool $active, ?string $message = null): bool
    {
        $db = App::db();
        try {
            $stmt = $db->prepare(
                "INSERT INTO `tblSettings` "
                . "(`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) "
                . "VALUES (NULL, 'portal.maintenance.active', ?, '0', 0) "
                . "ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`)"
            );
            if ($stmt === false) {
                return false;
            }
            $value = $active === true ? '1' : '0';
            $stmt->bind_param('s', $value);
            $stmt->execute();
            $stmt->close();

            if ($message !== null) {
                $stmt = $db->prepare(
                    "INSERT INTO `tblSettings` "
                    . "(`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) "
                    . "VALUES (NULL, 'portal.maintenance.message', ?, '', 0) "
                    . "ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`)"
                );
                if ($stmt !== false) {
                    $stmt->bind_param('s', $message);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            return true;
        } catch (\mysqli_sql_exception $e) {
            return false;
        }
    }

    /**
     * Render the standalone maintenance page (themed, no Bootstrap
     * dependency since the rest of the framework might be mid-upgrade)
     * and exit. Called by the front controller when a non-allowed
     * non-admin request lands during maintenance.
     */
    public static function renderAndExit(): void
    {
        // #509 point 1/2's other half. While the portal is closed, the
        // database may be half upgraded — a table this page's own queries
        // touch could be mid-ALTER. A warning raised while DRAWING THIS
        // PAGE used to go through the portal's normal handler, which
        // writes to tblErrors — the very table that might not be in a
        // fit state to accept the write. Same shape as cron/health.php's
        // own guard (see that file for the fuller reasoning): send it to
        // PHP's own error log instead, for the rest of this request. Never
        // paired with restore_error_handler() — this method always ends by
        // exiting, so there is no "rest of the request" to hand the normal
        // handler back to.
        //
        // WHAT THIS CANNOT COVER: anything that runs BEFORE this method is
        // even called — bootstrap, session start, the gate decision
        // itself. Auth::ensureSession()'s own guard (#509 point 1) and
        // bootstrap.php's ?lang= type check (#509 point 2) cover the two
        // known cases there; a genuine server fault before the gate is
        // still recorded normally, on purpose — that is a real fault
        // worth knowing about, not an artefact of the portal being closed.
        set_error_handler(static function (): bool {
            return false; // let PHP's own handling deal with it
        });

        http_response_code(503);
        header('Retry-After: 60');
        // 🤖 Belt-and-braces — bootstrap should already have set this,
        //    but render the no-index policy explicitly here since this
        //    method can be invoked before the global header dispatch
        //    completes (#247).
        header('X-Robots-Tag: noindex, nofollow, noai, noimageai');

        $customMessage = App::settings()['portal']['maintenance']['message'] ?? '';
        $messageHtml = '';
        if (is_string($customMessage) && trim($customMessage) !== '') {
            $messageHtml = '<p>' . htmlspecialchars(
                trim($customMessage),
                ENT_QUOTES,
                'UTF-8'
            ) . '</p>';
        }

        $portalName = htmlspecialchars(
            (string) (App::settings()['site']['name'] ?? 'WebMS Intra'),
            ENT_QUOTES,
            'UTF-8'
        );

        // 🎨 Self-contained themed page — mirrors the installer's
        //    "Already Installed" look so the user sees a consistent
        //    brand even when the rest of the framework is mid-upgrade.
        echo '<!doctype html><html lang="en"><head><title>Maintenance — '
           . $portalName . '</title>'
           . '<meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<meta http-equiv="refresh" content="60">'
           . '<style>'
           . ':root{'
           .   '--bg:#f7f8fa;--surface:#ffffff;--text:#1b2330;--muted:#6b7280;'
           .   '--border:#e5e7eb;--primary:#5e6ad2;--primary-hover:#4f5bbf;'
           . '}'
           . '@media (prefers-color-scheme: dark){:root{'
           .   '--bg:#0f1115;--surface:#161a22;--text:#e8eaf0;--muted:#9aa3b2;'
           .   '--border:#2c3441;--primary:#7b86e8;--primary-hover:#8f99eb;'
           . '}}'
           . 'html,body{height:100%;}'
           . 'body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;'
           .   'background:var(--bg);color:var(--text);text-align:center;'
           .   'padding:4rem 1.25rem;margin:0;line-height:1.55;}'
           . '.card{max-width:560px;margin:0 auto;background:var(--surface);'
           .   'border:1px solid var(--border);border-radius:.75rem;padding:2.5rem 2rem;'
           .   'box-shadow:0 4px 6px -1px rgba(16,24,40,.08),0 2px 4px -2px rgba(16,24,40,.06);}'
           . 'h1{margin:0 0 1rem;font-weight:600;letter-spacing:-.01em;}'
           . 'p{margin:0 0 1rem;color:var(--muted);}'
           . '.dot{display:inline-block;width:.5rem;height:.5rem;'
           .   'background:var(--primary);border-radius:50%;margin:0 .25rem;'
           .   'animation:bounce 1.4s infinite ease-in-out both;}'
           . '.dot:nth-child(2){animation-delay:-.16s;}'
           . '.dot:nth-child(3){animation-delay:-.32s;}'
           . '@keyframes bounce{0%,80%,100%{transform:scale(0);}40%{transform:scale(1);}}'
           . 'a{color:var(--primary);text-decoration:none;font-weight:500;}'
           . 'a:hover,a:focus{color:var(--primary-hover);text-decoration:underline;}'
           . '</style></head>'
           . '<body><div class="card">'
           . '<h1>Portal Maintenance</h1>'
           . $messageHtml
           . '<p>' . $portalName . ' is being upgraded. We\'ll be back shortly.</p>'
           . '<p><span class="dot"></span><span class="dot"></span><span class="dot"></span></p>'
           . '<p style="font-size:.85em;color:var(--muted);">'
           . 'This page will reload automatically.<br>'
           . 'A global administrator can <a href="/login">sign in</a> to complete the upgrade.'
           . '</p>'
           . '</div></body></html>';
        exit();
    }
}
