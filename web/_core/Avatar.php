<?php
// Path: core/Avatar.php
/**
 * -----------------------------------------------------------------------------
 * Avatar Cascade System 🖼️
 * -----------------------------------------------------------------------------
 * Resolves the best available avatar image for a user by walking a priority
 * cascade:
 *
 *   1. External URL (MS365 / any https:// path stored in avatarPath)
 *   2. Local file   (a relative path in avatarPath, e.g. "uploads/avatars/42.jpg")
 *   3. Gravatar     (md5 of lowercased, trimmed emailAddress, mystery-person default)
 *   4. Placeholder  (static SVG shipped with the portal)
 *
 * The class exposes two public methods:
 *   Avatar::url($user, $size)            raw URL string
 *   Avatar::img($user, $size, $class)    complete <img> tag (WCAG-compliant)
 *
 * The $user array is expected to carry the keys: emailAddress, avatarPath,
 * fullName.  These match the columns returned from tblUsers and the session
 * data populated by Auth::callbackMS365().
 * -----------------------------------------------------------------------------
 * @package    Portal\Core
 * @author     MWBM Partners Ltd (t/a MWservices)
 * @copyright  2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version    0.1.0
 * @link       https://github.com/MWBMPartners/WebMS-Intra
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Avatar
{
    // 📂 ---------------------------------------------------------------------------
    // Constants
    // -----------------------------------------------------------------------------

    /** @var string Web-root-relative path to the static placeholder SVG */
    private const PLACEHOLDER_PATH = '/assets/images/avatar-placeholder.svg';

    /** @var string Gravatar base URL (HTTPS) */
    private const GRAVATAR_BASE = 'https://www.gravatar.com/avatar/';

    /** @var string Gravatar default style parameter (mystery person silhouette) */
    private const GRAVATAR_DEFAULT = 'mp';

    // 🌐 ===========================================================================
    // Public API
    // ===========================================================================

    /**
     * Resolve the best available avatar URL for a user.
     *
     * Priority cascade:
     *   1. If avatarPath starts with http:// or https:// it is treated as an
     *      external URL (typically the MS365 photo endpoint) and returned as-is.
     *   2. If avatarPath is a non-empty string that does not look like a URL,
     *      it is treated as a web-root-relative local file path.  A leading
     *      slash is prepended if missing.
     *   3. If an emailAddress is available, a Gravatar URL is constructed using
     *      the md5 hash of the lowercased, trimmed address.  The mystery-person
     *      silhouette is used as the Gravatar default so the service itself
     *      provides a fallback if no Gravatar is registered.
     *   4. As a last resort, the static placeholder SVG is returned.
     *
     * @param array $user User data array with keys: emailAddress, avatarPath, fullName
     * @param int   $size Desired avatar dimension in pixels (width = height)
     *
     * @return string Absolute or root-relative URL to the avatar image
     */
    public static function url(array $user, int $size = 40): string
    {
        // 📷 Extract the avatar path from the user array (may be empty or absent)
        $avatarPath = trim($user['avatarPath'] ?? '');

        // 🔗 Priority 1: External URL (MS365 photo or any https:// source)
        if ($avatarPath !== '' && self::isExternalUrl($avatarPath) === true) {
            return $avatarPath;
        }

        // 📂 Priority 2: Local file path (relative to web root)
        if ($avatarPath !== '') {
            // Ensure a leading slash so the browser resolves from the web root
            if (str_starts_with($avatarPath, '/') === false) {
                $avatarPath = '/' . $avatarPath;
            }
            return $avatarPath;
        }

        // 🌐 Priority 3: Gravatar (requires an email address)
        $email = trim($user['emailAddress'] ?? '');
        if ($email !== '') {
            return self::gravatarUrl($email, $size);
        }

        // 🎭 Priority 4: Static placeholder SVG
        return self::PLACEHOLDER_PATH;
    }

    /**
     * Generate a complete <img> HTML tag for the user's avatar.
     *
     * The tag includes:
     *   - src:     resolved via the cascade in url()
     *   - alt:     the user's full name (falls back to "User avatar")
     *   - width/height: set to the requested size (square, uniform dimensions)
     *   - loading: "lazy" to defer off-screen images
     *   - class:   configurable CSS class(es) for styling
     *   - role:    "img" for assistive technology (WCAG compliance)
     *
     * @param array  $user  User data array with keys: emailAddress, avatarPath, fullName
     * @param int    $size  Desired avatar dimension in pixels
     * @param string $class CSS class(es) to apply to the <img> element
     *
     * @return string Complete HTML <img> element
     */
    public static function img(array $user, int $size = 40, string $class = 'portal-avatar'): string
    {
        // 🔗 Resolve the avatar URL through the cascade
        $src = self::url($user, $size);

        // 📝 Determine the alt text from the user's full name
        $fullName = trim($user['fullName'] ?? '');
        if ($fullName !== '') {
            $altText = $fullName;
        } else {
            $altText = 'User avatar';
        }

        // 🏗️ Build the <img> tag with all required attributes
        $tag = '<img'
            . ' src="'     . self::esc($src)   . '"'
            . ' alt="'     . self::esc($altText) . '"'
            . ' width="'   . $size . '"'
            . ' height="'  . $size . '"'
            . ' loading="lazy"'
            . ' class="'   . self::esc($class)  . '"'
            . ' role="img"'
            . '>';

        return $tag;
    }

    // 🛡️ ===========================================================================
    // Internal helpers
    // ===========================================================================

    /**
     * Check whether a path looks like an external (absolute) URL.
     *
     * @param string $path The path or URL to test
     *
     * @return bool True if the path starts with http:// or https://
     */
    private static function isExternalUrl(string $path): bool
    {
        if (str_starts_with($path, 'https://') === true) {
            return true;
        }
        if (str_starts_with($path, 'http://') === true) {
            return true;
        }
        return false;
    }

    /**
     * Build a Gravatar URL for the given email address.
     *
     * Gravatar uses the md5 hash of the lowercased, trimmed email address as the
     * lookup key.  The `d=mp` parameter requests the "mystery person" silhouette
     * as the default when no Gravatar is registered for the address.  The `s`
     * parameter controls the returned image dimensions.
     *
     * @param string $email User's email address
     * @param int    $size  Desired image dimension in pixels (1-2048)
     *
     * @return string Full Gravatar HTTPS URL
     */
    private static function gravatarUrl(string $email, int $size): string
    {
        // 🔑 Hash: lowercase, trim whitespace, then md5
        $hash = md5(strtolower(trim($email)));

        // 🌐 Build URL with size and default parameters
        return self::GRAVATAR_BASE . $hash
            . '?s=' . $size
            . '&d=' . self::GRAVATAR_DEFAULT;
    }

    /**
     * Escape a string for safe use inside an HTML attribute value.
     *
     * @param string $value Raw string
     *
     * @return string HTML-safe string
     */
    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
