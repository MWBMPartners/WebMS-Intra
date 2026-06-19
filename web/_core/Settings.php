<?php
// Path: _core/Settings.php
/**
 * -----------------------------------------------------------------------------
 * Portal Core Settings 🎛️
 * -----------------------------------------------------------------------------
 * Thin static wrapper around the global $SETTINGS array (built in
 * bootstrap.php from tblSettings via dot-notation keys → nested array).
 *
 * Use Settings::get('foo.bar.baz', 'default') to read a setting in static
 * contexts (class methods) where direct $SETTINGS access via `global`
 * would be awkward and harder to mock.
 *
 * Read-only — admin writes go through the existing /admin/settings UI
 * which writes tblSettings directly.
 *
 * Public methods:
 *   Settings::get($dotKey, $default = null)  → mixed   – read a setting
 *   Settings::has($dotKey)                   → bool    – does the path exist?
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Settings
{
    /**
     * Read a setting via dot-notation against the global $SETTINGS array.
     *
     * @param string     $dotKey  Dot-separated path, e.g. 'site.name'
     * @param mixed|null $default Returned if any path segment is missing
     *
     * @return mixed The setting value, or $default if absent
     */
    public static function get(string $dotKey, mixed $default = null): mixed
    {
        global $SETTINGS;
        if (is_array($SETTINGS) === false) {
            return $default;
        }
        $node = $SETTINGS;
        foreach (explode('.', $dotKey) as $part) {
            if (is_array($node) === false || array_key_exists($part, $node) === false) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    /**
     * Check whether a setting path exists (even with a falsy value).
     *
     * @param string $dotKey Dot-separated path
     *
     * @return bool True if every path segment resolves
     */
    public static function has(string $dotKey): bool
    {
        global $SETTINGS;
        if (is_array($SETTINGS) === false) {
            return false;
        }
        $node = $SETTINGS;
        foreach (explode('.', $dotKey) as $part) {
            if (is_array($node) === false || array_key_exists($part, $node) === false) {
                return false;
            }
            $node = $node[$part];
        }
        return true;
    }
}
