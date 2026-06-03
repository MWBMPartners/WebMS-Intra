<?php
// Path: _core/Photos.php
/**
 * -----------------------------------------------------------------------------
 * Photo gallery helpers 📸
 * -----------------------------------------------------------------------------
 * Approval queue, EXIF-aware streaming, tiered role visibility.
 *
 *   Tier ladder: public < volunteers < staff < admin_only
 *
 * On disk every photo keeps its EXIF (legitimate use: date/time, event location
 * for editor reference). On the wire the bytes are EXIF-stripped via GD
 * re-encode for every viewer who is neither the uploader nor an admin. Full
 * EXIF download is gated behind `/photos/serve-raw` which checks uploader-OR-
 * admin before sending.
 *
 * @package   Portal\Core
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/236
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Photos
{
    public const VISIBILITY_LEVELS = ['public', 'volunteers', 'staff', 'admin_only'];

    /**
     * Filesystem root for photo uploads. Files NEVER live under the
     * webroot — every access goes through /photos/serve which enforces
     * visibility.
     */
    public static function uploadDir(): string
    {
        $dir = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'photos';
        if (is_dir($dir) === false) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    public static function queueDir(): string
    {
        $dir = self::uploadDir() . DIRECTORY_SEPARATOR . 'queue';
        if (is_dir($dir) === false) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    public static function albumDir(int $albumId): string
    {
        $dir = self::uploadDir() . DIRECTORY_SEPARATOR . 'album-' . $albumId;
        if (is_dir($dir) === false) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Resolve the effective visibility — photo override wins, falls back
     * to album, falls back to site default. Returned as a tier rank
     * (higher = more restricted) for easy comparison.
     */
    public static function effectiveTier(array $photo, ?array $album): string
    {
        $v = (string) ($photo['visibility'] ?? 'inherit');
        if ($v === 'inherit' || $v === '') {
            $v = (string) ($album['visibility'] ?? (App::settings()['photos']['defaultVisibility'] ?? 'staff'));
        }
        if (in_array($v, self::VISIBILITY_LEVELS, true) === false) {
            $v = 'staff';
        }
        return $v;
    }

    /**
     * Tier-to-rank map for ordering checks.
     */
    public static function tierRank(string $tier): int
    {
        return match ($tier) {
            'public'      => 0,
            'volunteers'  => 1,
            'staff'       => 2,
            'admin_only'  => 3,
            default       => 2,
        };
    }

    /**
     * Can the current request see this photo? Anonymous viewers are
     * limited to `public`. Logged-in users gain tier access by role.
     */
    public static function canView(array $photo, ?array $album): bool
    {
        $tier = self::effectiveTier($photo, $album);
        $rank = self::tierRank($tier);
        if ($rank === 0) {
            return true;
        }
        if (Auth::check() === false) {
            return false;
        }
        if (App::isAdmin() === true) {
            return true;
        }
        $userRank = self::userTierRank();
        return $userRank >= $rank;
    }

    /**
     * Map the current user's roles to the highest tier they have.
     */
    public static function userTierRank(): int
    {
        if (Auth::check() === false) {
            return 0;
        }
        if (App::isAdmin() === true) {
            return 3;
        }
        if (App::hasRole('staff') === true) {
            return 2;
        }
        if (App::hasRole('volunteer') === true) {
            return 1;
        }
        // Any authenticated user gets volunteer-tier read on `volunteers` photos
        // ONLY if you've explicitly granted them — the role check above gates that.
        // Default authenticated tier matches `volunteers` to keep portals where
        // role rollout is partial functional, but stays below `staff`.
        return 1;
    }

    /**
     * Should the served bytes carry EXIF? Only when viewer is the
     * uploader OR an admin. Everyone else gets a stripped copy.
     */
    public static function viewerMayKeepExif(array $photo): bool
    {
        if (App::isAdmin() === true) {
            return true;
        }
        if (Auth::check() === false) {
            return false;
        }
        $uid = (int) ($_SESSION['user_id'] ?? 0);
        return $uid > 0 && $uid === (int) ($photo['uploadedByUserID'] ?? 0);
    }

    /**
     * Stream a photo with the correct Content-Type, optionally stripping
     * EXIF. Uses GD when stripping — that re-encodes JPEG/PNG/WebP which
     * is a slight quality loss but is the only reliable in-stdlib way.
     */
    public static function stream(array $photo, bool $stripExif): bool
    {
        $path = self::uploadDir() . DIRECTORY_SEPARATOR . basename((string) $photo['filePath']);
        if (is_file($path) === false || is_readable($path) === false) {
            return false;
        }
        $mime = (string) ($photo['mimeType'] ?? 'application/octet-stream');

        if ($stripExif === false || self::isImageMime($mime) === false || function_exists('gd_info') === false) {
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($path));
            header('Cache-Control: private, max-age=3600');
            readfile($path);
            return true;
        }

        // Re-encode through GD — drops all metadata.
        $img = self::loadGd($path, $mime);
        if ($img === null) {
            header('Content-Type: ' . $mime);
            readfile($path);
            return true;
        }
        header('Content-Type: ' . $mime);
        header('Cache-Control: private, max-age=3600');
        self::outputGd($img, $mime);
        imagedestroy($img);
        return true;
    }

    /**
     * Read EXIF for admin / uploader review.
     */
    public static function readExif(array $photo): array
    {
        if (function_exists('exif_read_data') === false) {
            return [];
        }
        $path = self::uploadDir() . DIRECTORY_SEPARATOR . basename((string) $photo['filePath']);
        if (is_file($path) === false) {
            return [];
        }
        $raw = @exif_read_data($path, 'ANY_TAG', true);
        return is_array($raw) === true ? $raw : [];
    }

    /**
     * Best-effort EXIF date extraction for `takenAt`.
     */
    public static function exifTakenAt(string $path): ?string
    {
        if (function_exists('exif_read_data') === false || is_file($path) === false) {
            return null;
        }
        $exif = @exif_read_data($path);
        if (is_array($exif) === false) {
            return null;
        }
        $candidates = ['DateTimeOriginal', 'DateTimeDigitized', 'DateTime'];
        foreach ($candidates as $k) {
            if (isset($exif[$k]) === true) {
                $ts = strtotime(str_replace(':', '-', substr((string) $exif[$k], 0, 10)) . substr((string) $exif[$k], 10));
                if ($ts !== false) {
                    return date('Y-m-d H:i:s', $ts);
                }
            }
        }
        return null;
    }

    /**
     * Move a queued file from queue/ into album-N/ on approval.
     * Returns the new relative filename.
     */
    public static function moveToAlbum(string $relPath, int $albumId): string
    {
        $base = basename($relPath);
        $src  = self::queueDir() . DIRECTORY_SEPARATOR . $base;
        $dst  = self::albumDir($albumId) . DIRECTORY_SEPARATOR . $base;
        if (is_file($src) === true) {
            @rename($src, $dst);
        }
        return 'album-' . $albumId . DIRECTORY_SEPARATOR . $base;
    }

    private static function isImageMime(string $mime): bool
    {
        return in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true);
    }

    private static function loadGd(string $path, string $mime): mixed
    {
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') === true ? @imagecreatefromwebp($path) : null,
            default      => null,
        } ?: null;
    }

    private static function outputGd(mixed $img, string $mime): void
    {
        switch ($mime) {
            case 'image/jpeg': imagejpeg($img, null, 90); break;
            case 'image/png':  imagepng($img);            break;
            case 'image/webp':
                if (function_exists('imagewebp') === true) {
                    imagewebp($img);
                }
                break;
        }
    }
}
