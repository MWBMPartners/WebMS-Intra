<?php
// Path: _core/NoticeboardMedia.php
/**
 * -----------------------------------------------------------------------------
 * Noticeboard — Upload Ledger Helpers 🖼️ (#363)
 * -----------------------------------------------------------------------------
 * Shared logic for tblNoticeboardUploads (PrayerChain-style helper — see
 * _core/PrayerChain.php for the precedent) so noticeboard/api/save.php and
 * noticeboard/api/upload.php don't each reimplement "which token does this
 * poster's mediaUrl point at" / "is this upload still referenced by a live
 * poster".
 *
 * Two responsibilities:
 *   1. linkToPoster()  — called from save.php's upsert loop. When a saved
 *      poster's mediaUrl resolves to one of OUR /noticeboard/media?f=<token>
 *      URLs, stamp that upload row's posterID so the cleanup pass below can
 *      tell "attached" uploads from "abandoned" ones.
 *   2. cleanupOrphans() — called from save.php AFTER its existing
 *      soft-delete step. Deletes the file + row for any upload that is
 *      either (a) still unattached (posterID IS NULL) or (b) attached to a
 *      poster that is now soft-deleted, PROVIDED it is older than a small
 *      grace window (so an in-flight upload/edit never races a delete) AND
 *      no LIVE poster's mediaUrl still references the same storedName
 *      (defensive double-check — belt and braces alongside the posterID
 *      link). Every failure is caught + logged; a cleanup failure must never
 *      break the save request it's piggybacking on.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/363
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class NoticeboardMedia
{
    /** Grace window (minutes) before an orphaned upload becomes eligible for cleanup. */
    private const GRACE_MINUTES = 30;

    /**
     * Extract the storedName token from one of our OWN /noticeboard/media
     * URLs (e.g. "/noticeboard/media?f=<32 hex>.<ext>"). Returns null for
     * anything else (externally-hosted URL, Canva embed, empty string) —
     * those are simply not ours to link/clean up.
     *
     * @param string $mediaUrl The poster's stored mediaUrl (already
     *                         scheme-allowlisted by save.php's $safeUrl).
     *
     * @return string|null The validated `[a-f0-9]{32}\.ext` token, or null.
     */
    public static function extractStoredName(string $mediaUrl): ?string
    {
        if (str_contains($mediaUrl, 'noticeboard/media') === false) {
            return null;
        }
        $query = (string) (parse_url($mediaUrl, PHP_URL_QUERY) ?? '');
        parse_str($query, $params);
        $f = (string) ($params['f'] ?? '');
        if (preg_match('/^[a-f0-9]{32}\.(png|jpe?g|gif|webp|mp4|webm)$/', $f) !== 1) {
            return null;
        }
        return $f;
    }

    /**
     * Stamp tblNoticeboardUploads.posterID for the upload (if any) that this
     * poster's mediaUrl refers to. No-op if the URL isn't one of ours, or no
     * matching (unattached-or-same-poster) upload row exists. Best-effort —
     * caught + logged, never thrown past this method.
     *
     * @param int    $siteId   Current site (tenant scope).
     * @param int    $posterId The poster row this mediaUrl now belongs to.
     * @param string $mediaUrl The poster's stored mediaUrl.
     *
     * @return void
     */
    public static function linkToPoster(int $siteId, int $posterId, string $mediaUrl): void
    {
        $storedName = self::extractStoredName($mediaUrl);
        if ($storedName === null) {
            return;
        }
        try {
            $db   = App::db();
            $stmt = $db->prepare(
                'UPDATE tblNoticeboardUploads SET posterID = ? '
                . 'WHERE siteID = ? AND storedName = ?'
            );
            if ($stmt === false) {
                return;
            }
            $stmt->bind_param('iis', $posterId, $siteId, $storedName);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            Logger::errorPlatform(
                'NoticeboardMedia',
                'Warning',
                'LINK_UPLOAD_FAIL',
                'Failed to link upload to poster #' . $posterId,
                $e->getMessage()
            );
        }
    }

    /**
     * Delete files + rows for uploads that are orphaned: unattached
     * (posterID IS NULL) or attached to a now soft-deleted poster, AND past
     * the grace window. A live poster's mediaUrl is re-checked directly
     * before any delete (never remove a file still referenced by a live
     * poster, even if the posterID link is stale for some reason). Every
     * step is best-effort — a failure here is logged, never thrown, so it
     * can't break the save() request it's piggybacking on.
     *
     * @param int $siteId Current site (tenant scope).
     *
     * @return void
     */
    public static function cleanupOrphans(int $siteId): void
    {
        try {
            $db = App::db();

            // 🔍 Candidates: unattached, or attached to a soft-deleted poster —
            //    either way, past the grace window.
            $stmt = $db->prepare(
                'SELECT u.uploadID, u.storedName '
                . 'FROM tblNoticeboardUploads u '
                . 'LEFT JOIN tblNoticeboardPosters p ON p.posterID = u.posterID '
                . 'WHERE u.siteID = ? '
                . '  AND (u.posterID IS NULL OR p.isDeleted = 1) '
                . '  AND u.createdAt < (NOW() - INTERVAL ' . self::GRACE_MINUTES . ' MINUTE)'
            );
            if ($stmt === false) {
                return;
            }
            $stmt->bind_param('i', $siteId);
            $stmt->execute();
            $res = $stmt->get_result();
            $candidates = [];
            while ($row = $res->fetch_assoc()) {
                $candidates[] = [
                    'uploadID'   => (int) $row['uploadID'],
                    'storedName' => (string) $row['storedName'],
                ];
            }
            $stmt->close();

            if ($candidates === []) {
                return;
            }

            $uploadDir = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'noticeboard';

            // 🛡️ Never delete a file a LIVE poster still references — checked
            //    directly against mediaUrl (belt-and-braces alongside the
            //    posterID link, in case linkToPoster() ever missed a rename).
            $liveCheck = $db->prepare(
                "SELECT posterID FROM tblNoticeboardPosters "
                . "WHERE siteID = ? AND isDeleted = 0 AND mediaUrl LIKE CONCAT('%', ?, '%') LIMIT 1"
            );

            foreach ($candidates as $c) {
                if ($liveCheck !== false) {
                    $liveCheck->bind_param('is', $siteId, $c['storedName']);
                    $liveCheck->execute();
                    $stillLive = $liveCheck->get_result()->fetch_assoc() !== null;
                    if ($stillLive === true) {
                        continue;
                    }
                }

                // 🗑️ File first (best-effort — a missing file on disk is not
                //    fatal, the row is the source of truth).
                $path = $uploadDir . DIRECTORY_SEPARATOR . $c['storedName'];
                if (is_file($path) === true) {
                    if (@unlink($path) === false) {
                        Logger::errorPlatform(
                            'NoticeboardMedia',
                            'Warning',
                            'CLEANUP_UNLINK_FAIL',
                            'Failed to delete orphaned noticeboard upload file',
                            $path
                        );
                        // Keep the row so we retry next time rather than
                        // losing track of an un-deletable file.
                        continue;
                    }
                }

                $del = $db->prepare('DELETE FROM tblNoticeboardUploads WHERE uploadID = ?');
                if ($del !== false) {
                    $del->bind_param('i', $c['uploadID']);
                    $del->execute();
                    $del->close();
                }
            }
            if ($liveCheck !== false) {
                $liveCheck->close();
            }
        } catch (\Throwable $e) {
            Logger::errorPlatform(
                'NoticeboardMedia',
                'Warning',
                'CLEANUP_FAIL',
                'Noticeboard upload cleanup pass failed',
                $e->getMessage()
            );
        }
    }
}
