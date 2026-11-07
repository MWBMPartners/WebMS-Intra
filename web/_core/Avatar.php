<?php
// Path: _core/Avatar.php
/**
 * -----------------------------------------------------------------------------
 * Avatar Cascade System 🖼️
 * -----------------------------------------------------------------------------
 * Resolves the best available avatar image for a user by walking a priority
 * cascade:
 *
 *   1. The sign-in provider's photo (an https:// address stored in avatarPath)
 *      — ONLY when OutsideServices allows it for this person (#778)
 *   2. Local file   (a relative path in avatarPath, e.g. "uploads/avatars/42.jpg")
 *   3. Gravatar     — ONLY when OutsideServices allows it for this person (#778)
 *   4. Placeholder  (static SVG shipped with the portal)
 *
 * WHAT CHANGED WITH #778, AND WHY THIS FILE HOLDS NO OUTSIDE ADDRESS ANY MORE
 * ---------------------------------------------------------------------------
 * Steps 1 and 3 send something about the member to another company: a Gravatar
 * address is a scramble of their e-mail that Gravatar matches to them, and a
 * sign-in photo is an address on Google's servers that is theirs alone. This
 * file used to build the Gravatar address itself (`GRAVATAR_BASE`,
 * `gravatarUrl()`) and return a stored outside address as it was, with nothing
 * anybody could switch off. Both are now asked of
 * `Portal\Core\OutsideServices`, which applies the owner's rule (D-058: the
 * strictest of the installation, every organisation the member belongs to, and
 * the member's own choice) and BUILDS the address only after deciding. So an
 * outside address cannot be produced here without that decision, and
 * `tools/audit-checks/check_outside_services_gated.py` fails if an outside host
 * name ever appears in this file again or the two builders stop being called.
 *
 * With both off — the default (D-073 accepts this) — a member with no local
 * photo gets the placeholder silhouette.
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
 * @version    0.2.0
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

    // 🌐 ===========================================================================
    // Public API
    // ===========================================================================

    /**
     * Resolve the best available avatar URL for a user.
     *
     * Priority cascade:
     *   1. A value that is plainly a path on THIS portal (localPath(), a strict
     *      allow-list) is used as it is. Nothing leaves the portal.
     *   2. Anything else in avatarPath is treated as another website: it is used
     *      only if `OutsideServices::providerPhotoIfAllowed()` hands it back (an
     *      https:// address on the permitted photo hosts, and allowed). If it
     *      does not, the cascade goes on to Gravatar and then the placeholder.
     *   3. Gravatar, only if `OutsideServices::gravatarUrlIfAllowed()` builds an
     *      address for this person.
     *   4. The static placeholder SVG.
     *
     * The person is `$user['userID']`: OutsideServices refuses a row without
     * one, because it would not know whose permission it was using.
     *
     * @param array $user User data array with keys: userID, emailAddress, avatarPath, fullName
     * @param int   $size Desired avatar dimension in pixels (width = height)
     *
     * @return string Absolute or root-relative URL to the avatar image
     */
    public static function url(array $user, int $size = 40): string
    {
        // 📷 Extract the avatar path from the user array (may be empty or absent)
        $avatarPath = trim((string) ($user['avatarPath'] ?? ''));

        if ($avatarPath !== '') {
            // 📂 Priority 2 (asked FIRST): a path on THIS portal, by a strict
            //    allow-list. Anything that is not plainly one is another website
            //    as far as a browser is concerned — see localPath().
            $local = self::localPath($avatarPath);
            if ($local !== null) {
                return $local;
            }
            // 🔗 Priority 1: the sign-in provider's photo — only if allowed
            //    (#778), and only an https:// address on its photo hosts.
            //    Anything else that is not local draws the silhouette.
            $photo = OutsideServices::providerPhotoIfAllowed($user);
            if ($photo !== null) {
                return $photo;
            }
        }

        // 🌐 Priority 3: Gravatar — only if allowed for this person (#778).
        $gravatar = OutsideServices::gravatarUrlIfAllowed($user, $size);
        if ($gravatar !== null) {
            return $gravatar;
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
     * 📂 The stored value as a path on THIS portal, or null when it is not
     * plainly one.
     *
     * AN ALLOW-LIST, NOT A LIST OF WHAT IS EXTERNAL (#778, rounds 1 and 2 of
     * the independent check). The old test asked "does it start with http://
     * or https://", and round 1 added "//". Each left spellings a browser reads
     * as ANOTHER HOST while this file returned them as a local `src`: `/\host/x`
     * (a browser treats a backslash as a slash), `\\host/x`, `/<TAB>/host/x`
     * (a browser removes a tab inside an address). The worst,
     * a backslash followed by Google's photo host (its name is deliberately not written here: only OutsideServices may name it, and the audit check counts it), would have skipped the outside-services
     * decision entirely while the page rule allowed that host. Listing bad
     * spellings is the shape that keeps losing; listing the one good shape is
     * not.
     *
     * Local means: an optional single leading `/`, then a letter, digit or
     * underscore, then only letters, digits, `_`, `.`, `/` and `-`, and never
     * `//`. No backslash, no whitespace or control character, no `:` (so no
     * scheme), no `?`, `#` or `%`. A relative path gets its leading slash, as
     * before. Everything else goes to the decision, which accepts only an
     * https:// address on the permitted photo hosts, or draws the silhouette.
     */
    private static function localPath(string $path): ?string
    {
        $body = str_starts_with($path, '/') === true ? substr($path, 1) : $path;
        if (preg_match('#^[A-Za-z0-9_][A-Za-z0-9_./-]*$#D', $body) !== 1 || str_contains($body, '//') === true) {
            return null;
        }

        return '/' . $body;
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
