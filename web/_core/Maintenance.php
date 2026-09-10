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
 *   • /login, /logout        — admins need to sign in to fix it
 *   • /forgot-password,
 *     /reset-password        — and to get back in if they have forgotten it
 *   • /admin/upgrade*        — the upgrader itself
 *   • /admin/maintenance*    — backup / restore UI
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
     * is active? Pass the route key (without leading `/`).
     */
    public static function isAllowed(string $routeKey): bool
    {
        $routeKey = ltrim($routeKey, '/');
        foreach (self::ALLOW_LIST as $prefix) {
            if (str_starts_with($routeKey, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Is the current session an admin user? Admins can pass through
     * the gate to access /admin/* routes (covered by ALLOW_LIST) and
     * to fix the problem.
     */
    public static function currentUserCanBypass(): bool
    {
        // 🪞 `App::isAdmin()` is the canonical predicate; this wrapper
        //    exists so future logic (e.g. "only root admins") can be
        //    centralised without touching the front controller.
        return App::isAdmin();
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
           . 'Administrators can <a href="/login">sign in</a> to complete the upgrade.'
           . '</p>'
           . '</div></body></html>';
        exit();
    }
}
