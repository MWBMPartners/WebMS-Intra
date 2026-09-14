<?php
// Path: _core/AppRegistry.php
/**
 * -----------------------------------------------------------------------------
 * WebMS Intra — App Registry 📦
 * -----------------------------------------------------------------------------
 * Single source of truth for installable apps. Each app declares itself via a
 * config file at `web/_core/apps/{slug}.php` returning a metadata array.
 *
 * Used by:
 *   • /admin/apps marketplace UI — list, enable, disable.
 *   • Router::dispatch() — gates routes whose owning app is disabled.
 *   • Dashboard — renders cards for enabled apps only.
 *   • Industry filter — apps tagged with industries (church, school, business,
 *     nonprofit) can be hidden from orgs whose `portal.industry` doesn't match.
 *
 * App metadata shape:
 *   [
 *     'slug'        => 'rota',
 *     'name'        => 'Duty Roster',
 *     'description' => 'Recurring shift / duty assignments…',
 *     'icon'        => 'fa-solid fa-calendar-week',  (Font Awesome class)
 *     'color'       => '#5e6ad2',                    (border-top accent)
 *     'category'    => 'community',
 *     'industries'  => ['church', 'community', 'small-business'],
 *     'route'       => 'rota',                       (matches tblRoutes.routeKey root)
 *     'settingKey'  => 'rota.enabled',
 *     'requires'    => [],                           (other app slugs)
 *     'version'     => '1.0.0',
 *     'isCore'      => false,                        (core apps can't be disabled)
 *   ]
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/255
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class AppRegistry
{
    /** @var array<string, array<string, mixed>>|null Cached registry */
    private static ?array $registry = null;

    /**
     * @var bool True while all() is part way through reading the definition
     *      files. A call to all() that arrives during that time is answered
     *      without reading them again — see the note inside all().
     */
    private static bool $loading = false;

    /**
     * @var array<string, mixed> What each definition file returned, keyed by
     *      the file's full path, kept for the rest of the request. A file that
     *      failed to load is kept as null. Deliberately NOT emptied by
     *      invalidate() — see the note inside all() for why.
     */
    private static array $fileResults = [];

    /**
     * Return every registered app's metadata, keyed by slug.
     *
     * -------------------------------------------------------------------------
     * ONE BAD FILE USED TO TAKE THE WHOLE PORTAL DOWN
     * -------------------------------------------------------------------------
     * This reads every file in web/_core/apps/ in a loop. Until now the read
     * was a bare `require`, with nothing around it. A single one of those files
     * with a typo in it — a missing bracket, a half-finished edit, a file that
     * arrived truncated over a slow connection — stopped PHP dead.
     *
     * And it stopped it EVERYWHERE, because this list is consulted on nearly
     * every page: the router checks whether the owning app is switched on, the
     * dashboard draws a card per app, the menu is built from it. So one broken
     * file meant every page of the portal failed, for everybody.
     *
     * Worst of all, it included the page an administrator would go to in order
     * to fix it. There was no way back except editing files on the server by
     * hand.
     *
     * Now a file that cannot be read is skipped and the other forty-six carry
     * on working. The app in the broken file simply does not appear, which
     * means anything gated on it is treated as switched off — the safe
     * direction, and visible: the administrator sees one app missing from
     * /admin/apps rather than a portal that will not load at all.
     *
     * What this CANNOT do: it cannot rescue a file that breaks PHP before the
     * loop starts, and it cannot tell a deliberately removed app from a broken
     * one. That is why the failure is written to the error log as well —
     * silence would just move the puzzle somewhere else.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        if (self::$registry !== null) {
            return self::$registry;
        }

        // 🔁 A call that arrives WHILE the files below are still being read.
        //
        //    WHAT WAS WRONG: nothing is cached until every file has been read.
        //    So a definition file that itself asked for the app list — directly,
        //    or through something it calls — started the whole read again from
        //    the top, which reached the same file again, which asked again, and
        //    so on until PHP ran out of memory and the page died.
        //
        //    WHAT IT RETURNS NOW: an EMPTY list, straight away, and that empty
        //    list is NOT remembered. The outer call carries on, finishes, and
        //    caches the full list as normal, so every later caller sees every
        //    app.
        //
        //    Why empty, rather than "whatever has been read so far": a
        //    half-built list depends on the alphabetical order of the file
        //    names, so the same code would see different apps depending on what
        //    its own file happens to be called. Empty is the same every time,
        //    and every consumer already reads a missing app as switched off —
        //    isEnabled() returns false for it.
        //
        //    One thing to know: appForRoute() treats "no owning app" as "not an
        //    app, so always allowed". Code running inside a definition file
        //    must therefore not use appForRoute() to make an access decision.
        //    Nothing does today; the router only calls it long after loading
        //    has finished.
        if (self::$loading === true) {
            return [];
        }

        $dir = PORTAL_CORE . DIRECTORY_SEPARATOR . 'apps';
        $registry = [];

        // 📝 Failures are collected here and reported only AFTER the cache
        //    below has been filled in. Reporting inside the loop would call
        //    the logger while this list is still half built, and the logger
        //    reaches other parts of the framework — if any of those ever asked
        //    for the app list, it would call back into this function, which
        //    would start the loop again, for ever. Collecting first removes
        //    that possibility rather than relying on nobody ever adding such a
        //    call.
        $failures = [];

        self::$loading = true;
        try {
            if (is_dir($dir) === true) {
                foreach ((array) glob($dir . DIRECTORY_SEPARATOR . '*.php') as $file) {
                    $path = (string) $file;

                    // 📦 Each file is read at most ONCE per request, and what it
                    //    gave back is kept.
                    //
                    //    WHAT WAS WRONG: invalidate() empties the cached list so
                    //    the next call rebuilds it (it is used after an app is
                    //    switched on or off). The rebuild used to `require`
                    //    every file again. A definition file that declares a
                    //    function or a class alongside its array then declared
                    //    it a second time, and PHP stops dead on that ("Cannot
                    //    redeclare") — it is not an error that can be caught
                    //    and skipped.
                    //
                    //    Why not simply require_once, which never reads a file
                    //    twice: the second time it does not give back the
                    //    file's array, it gives back `true`, meaning "already
                    //    done". Every app would then fail the is_array() test
                    //    below and silently vanish from the list after the
                    //    first invalidate().
                    //
                    //    What this means in practice: an edit to a definition
                    //    file is not seen until the next request (nothing edits
                    //    them part way through one). A file that is NEW since
                    //    the last read is picked up, because the folder is
                    //    listed again every time. A file that failed is kept as
                    //    null, so it is skipped without being read, or
                    //    reported, a second time.
                    if (array_key_exists($path, self::$fileResults) === false) {
                        try {
                            self::$fileResults[$path] = require $path;
                        } catch (\Throwable $problem) {
                            // 🛟 Skip this one file and keep going. \Throwable
                            //    catches both ordinary exceptions and the errors
                            //    PHP raises for things like a call to a function
                            //    that does not exist, which is what a
                            //    half-finished app file usually produces.
                            self::$fileResults[$path] = null;
                            $failures[] = [
                                'file'    => basename($path),
                                'message' => $problem->getMessage(),
                            ];
                        }
                    }
                    $meta = self::$fileResults[$path];

                    if (is_array($meta) === false || isset($meta['slug']) === false) {
                        continue;
                    }
                    $slug = (string) $meta['slug'];
                    // 🛡️ Apply defaults so consumers can rely on every key existing.
                    $registry[$slug] = $meta + [
                        'name'        => ucfirst($slug),
                        'description' => '',
                        'icon'        => 'fa-solid fa-cube',
                        'color'       => '#5e6ad2',
                        'category'    => 'other',
                        'industries'  => [],
                        'route'       => $slug,
                        'settingKey'  => $slug . '.enabled',
                        'requires'    => [],
                        'version'     => '1.0.0',
                        'isCore'      => false,
                    ];
                }
            }
            ksort($registry);
            self::$registry = $registry;
        } finally {
            // Always cleared, even if something unexpected is thrown part way
            // through, so one bad moment cannot leave every later call in this
            // request answered with an empty list.
            self::$loading = false;
        }

        // 🚨 Now that the list is safely cached, say what went wrong. Both
        //    routes are used on purpose: the portal's own error log is where an
        //    administrator will look, and PHP's error log is the one that still
        //    works when the database does not — which is exactly the kind of
        //    morning on which this happens.
        foreach ($failures as $failure) {
            error_log(
                'AppRegistry: skipped unreadable app definition '
                . $failure['file'] . ' — ' . $failure['message']
            );
            try {
                Logger::errorPlatform(
                    'PHP',
                    'Error',
                    'AppRegistryFileFailed',
                    'An app definition file could not be read: ' . $failure['file'],
                    'The app in this file is being treated as switched off so the rest '
                    . 'of the portal keeps working. Fix the file in web/_core/apps/ and '
                    . 'the app comes back on its own. Reported problem: '
                    . $failure['message']
                );
            } catch (\Throwable $ignored) {
                // Logging needs a database. Very early in a request, or during
                // a database outage, it will not be there — and a logger that
                // brings down the thing it is reporting on is worse than no
                // logger. The error_log() line above has already recorded it.
                error_log('AppRegistry: could not record the above in the portal error log: ' . $ignored->getMessage());
            }
        }

        return $registry;
    }

    /**
     * Is the app with the given slug enabled?
     * Core apps are always enabled. Non-core apps respect their settingKey.
     */
    public static function isEnabled(string $slug): bool
    {
        $all = self::all();
        if (isset($all[$slug]) === false) {
            return false;
        }
        $meta = $all[$slug];
        if (($meta['isCore'] ?? false) === true) {
            return true;
        }
        // 🪞 Setting lookup honours the dot-notation path stored in
        //    `settingKey` — e.g. 'rota.enabled' → $SETTINGS['rota']['enabled'].
        $keyPath = explode('.', (string) $meta['settingKey']);
        $value   = App::settings();
        foreach ($keyPath as $part) {
            if (is_array($value) === false || isset($value[$part]) === false) {
                return false;
            }
            $value = $value[$part];
        }
        return (string) $value === '1' || (string) $value === 'true';
    }

    /**
     * Return only enabled apps.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function enabled(): array
    {
        return array_filter(
            self::all(),
            static fn (array $meta): bool => self::isEnabled((string) $meta['slug'])
        );
    }

    /**
     * Filter by industry. If the org's `portal.industry` is set and the
     * app's `industries` list is non-empty, the app must include that
     * industry to be visible. If either is empty, the app is shown.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function visibleForIndustry(): array
    {
        $orgIndustry = (string) (App::settings()['portal']['industry'] ?? '');
        if ($orgIndustry === '') {
            return self::all();
        }
        return array_filter(
            self::all(),
            static function (array $meta) use ($orgIndustry): bool {
                $industries = (array) ($meta['industries'] ?? []);
                if (count($industries) === 0) {
                    return true;
                }
                return in_array($orgIndustry, $industries, true);
            }
        );
    }

    /**
     * Resolve the owning app for a route. Returns the app metadata, or null
     * if the route doesn't belong to any registered app (assumed core / always
     * allowed in that case).
     *
     * Match strategy: the longest registered `route` prefix that the request
     * path starts with wins (so `prayer-requests` beats `prayer`).
     */
    public static function appForRoute(string $routeKey): ?array
    {
        $routeKey = ltrim($routeKey, '/');
        $all = self::all();
        $bestMatch = null;
        $bestLen = -1;
        foreach ($all as $meta) {
            $prefix = ltrim((string) $meta['route'], '/');
            if ($prefix === '') {
                continue;
            }
            if ($routeKey === $prefix || str_starts_with($routeKey, $prefix . '/')) {
                if (strlen($prefix) > $bestLen) {
                    $bestMatch = $meta;
                    $bestLen   = strlen($prefix);
                }
            }
        }
        return $bestMatch;
    }

    /**
     * Reset the in-memory cache. Used after enabling/disabling so the next
     * request picks up the new state without a full reload.
     */
    public static function invalidate(): void
    {
        self::$registry = null;
    }
}
