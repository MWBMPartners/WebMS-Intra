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
 * @version   1.0.0
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
     * Return every registered app's metadata, keyed by slug.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        if (self::$registry !== null) {
            return self::$registry;
        }
        $dir = PORTAL_CORE . DIRECTORY_SEPARATOR . 'apps';
        $registry = [];
        if (is_dir($dir) === true) {
            foreach ((array) glob($dir . DIRECTORY_SEPARATOR . '*.php') as $file) {
                $meta = require $file;
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
