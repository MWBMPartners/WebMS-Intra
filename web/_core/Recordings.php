<?php
// Path: _core/Recordings.php
/**
 * -----------------------------------------------------------------------------
 * Recordings library helpers 🎙
 * -----------------------------------------------------------------------------
 * RSS feed builder, range-aware streaming, topic-tag bookkeeping, and the
 * public podcast feed's per-site token (`podcastToken()`, used by both
 * `recordings/podcast.php` — the feed itself — and `recordings/
 * podcast-media.php` — the public, session-free enclosure endpoint for
 * self-hosted episodes, re-authenticated via this same token).
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/264
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Recordings
{
    /**
     * Resolve the on-disk upload directory for recordings, creating it if
     * needed. Recordings live under _uploads/recordings/ outside the
     * webroot — streamed via stream.php.
     */
    public static function uploadDir(): string
    {
        $dir = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'recordings';
        if (is_dir($dir) === false) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Stream a recording file with HTTP Range support so audio/video
     * clients can seek without re-downloading the whole asset.
     *
     * Returns true once it has answered (a 416 for a piece beyond the end
     * included), false when the file is missing or cannot be opened — and in
     * that case NOTHING has been sent, so the caller can still answer 404.
     *
     * 🎞️ The range itself is `ByteRange::send()`'s (#880), the ONE place a
     * `Range:` request is answered. This method used to hold its own copy,
     * with two faults the other copy (`podcast-media.php`) shared —
     * `bytes=-500` ("the last 500 bytes") was read as bytes 0 to 500, and an
     * end beyond the file was not shortened, so `Content-Length` promised
     * bytes that never came — and one of its own: a 416 answered FALSE, which
     * `recordings/stream` turned into a 404 with the 416's `Content-Range`
     * still attached.
     */
    public static function streamFile(string $path, string $mimeType): bool
    {
        if (is_file($path) === false || is_readable($path) === false) {
            return false;
        }

        header('Content-Type: ' . $mimeType);
        header('Cache-Control: public, max-age=3600');
        if (ByteRange::send($path) === false) {
            // Nothing went out: take back the two headers set for a file that
            // is not coming, so the caller's "not found" is clean.
            header_remove('Content-Type');
            header_remove('Cache-Control');
            return false;
        }

        return true;
    }

    /**
     * Build an RSS 2.0 feed with iTunes podcast extensions for the
     * recordings of a given site. Channel metadata pulled from the
     * site name + recordings.podcast_author setting.
     */
    public static function buildFeed(int $siteId, string $siteName, string $author, string $baseUrl): string
    {
        $db = App::db();
        $items = [];
        try {
            $stmt = $db->prepare(
                'SELECT recordingID, title, summary, recordedAt, durationSeconds, '
                . '       filePath, fileSize, mimeType, externalUrl '
                . 'FROM tblRecording '
                . 'WHERE siteID = ? AND isPublished = 1 AND (filePath IS NOT NULL OR externalUrl IS NOT NULL) '
                . 'ORDER BY recordedAt DESC, recordingID DESC LIMIT 100'
            );
            if ($stmt !== false) {
                $stmt->bind_param('i', $siteId);
                $stmt->execute();
                $rs = $stmt->get_result();
                while ($r = $rs->fetch_assoc()) {
                    $items[] = $r;
                }
                $stmt->close();
            }
        } catch (\Throwable $ignored) {
            // tblRecording missing → app not installed; emit empty feed.
        }

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:content="http://purl.org/rss/1.0/modules/content/">' . "\n";
        $xml .= '  <channel>' . "\n";
        $xml .= '    <title>' . self::esc($siteName) . ' — Recordings</title>' . "\n";
        $xml .= '    <link>' . self::esc($baseUrl) . '/recordings</link>' . "\n";
        $xml .= '    <description>Audio recordings from ' . self::esc($siteName) . '.</description>' . "\n";
        $xml .= '    <language>en</language>' . "\n";
        if ($author !== '') {
            $xml .= '    <itunes:author>' . self::esc($author) . '</itunes:author>' . "\n";
        }
        $xml .= '    <itunes:explicit>false</itunes:explicit>' . "\n";

        foreach ($items as $it) {
            $url = (string) ($it['externalUrl'] ?? '');
            if ($url === '' && $it['filePath'] !== null) {
                $url = $baseUrl . '/recordings/stream?id=' . (int) $it['recordingID'];
            }
            if ($url === '') {
                continue;
            }
            $pubDate = $it['recordedAt'] !== null
                ? date(DATE_RSS, (int) strtotime((string) $it['recordedAt']))
                : date(DATE_RSS);
            $xml .= '    <item>' . "\n";
            $xml .= '      <title>' . self::esc((string) $it['title']) . '</title>' . "\n";
            $xml .= '      <link>' . self::esc($baseUrl . '/recordings/view?id=' . (int) $it['recordingID']) . '</link>' . "\n";
            $xml .= '      <guid isPermaLink="false">recording-' . (int) $it['recordingID'] . '</guid>' . "\n";
            $xml .= '      <pubDate>' . self::esc($pubDate) . '</pubDate>' . "\n";
            $xml .= '      <description>' . self::esc((string) ($it['summary'] ?? '')) . '</description>' . "\n";
            if ($it['durationSeconds'] !== null) {
                $xml .= '      <itunes:duration>' . self::formatDuration((int) $it['durationSeconds']) . '</itunes:duration>' . "\n";
            }
            $mime = (string) ($it['mimeType'] ?? 'audio/mpeg');
            $len  = (int) ($it['fileSize'] ?? 0);
            $xml .= '      <enclosure url="' . self::esc($url) . '" length="' . $len . '" type="' . self::esc($mime) . '" />' . "\n";
            $xml .= '    </item>' . "\n";
        }

        $xml .= '  </channel>' . "\n" . '</rss>' . "\n";
        return $xml;
    }

    /**
     * Format seconds as HH:MM:SS (or MM:SS for short clips).
     */
    public static function formatDuration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        if ($h > 0) {
            return sprintf('%d:%02d:%02d', $h, $m, $s);
        }
        return sprintf('%d:%02d', $m, $s);
    }

    /**
     * Get-or-lazily-create this site's PUBLIC podcast feed token
     * (`recordings/podcast.php`). Stored in `tblSettings` under
     * `recordings.podcast_token`, siteID-scoped, `isSensitive = 1` —
     * encrypted at rest via the same `encrypt_setting()`/`decrypt_setting()`
     * pair every other secret in this table uses, so it round-trips
     * through the EXISTING generic `/settings` editor (settings/save.php
     * already encrypts/decrypts `isSensitive` values generically) without
     * any bespoke admin UI. Deliberately NOT seeded by any migration or
     * full_schema.sql — this is runtime data, generated the first time
     * anything calls this method, unlike the `*.cron_token` settings this
     * codebase seeds empty ahead of time.
     *
     * Never throws — a DB or encryption failure is caught and logged, and
     * an empty string is returned so `recordings/podcast.php`'s own gate
     * fails closed (an empty expected token never `hash_equals()`es a real
     * request, whatever the caller supplied).
     *
     * @return string 64-char hex token, or '' on failure.
     */
    public static function podcastToken(int $siteId): string
    {
        if ($siteId <= 0) {
            return '';
        }

        try {
            $db = App::db();
            $settingKey = 'recordings.podcast_token';

            $stmt = $db->prepare(
                'SELECT settingValue, isSensitive FROM tblSettings WHERE settingKey = ? AND siteID = ? LIMIT 1'
            );
            if ($stmt !== false) {
                $stmt->bind_param('si', $settingKey, $siteId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($row !== null) {
                    $raw = (string) ($row['settingValue'] ?? '');
                    if ($raw !== '') {
                        if ((int) $row['isSensitive'] === 1) {
                            $plain = \decrypt_setting($raw);
                            if ($plain !== '') {
                                return $plain;
                            }
                        } else {
                            return $raw;
                        }
                    }
                }
            }

            // 🔑 Lazily generate — 32 random bytes / 64 hex chars, unguessable.
            $token  = bin2hex(random_bytes(32));
            $stored = \encrypt_setting($token);

            $ins = $db->prepare(
                'INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) '
                . 'VALUES (?, ?, ?, ?, 1) '
                . 'ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue)'
            );
            if ($ins !== false) {
                $emptyDefault = '';
                $ins->bind_param('isss', $siteId, $settingKey, $stored, $emptyDefault);
                $ins->execute();
                $ins->close();
            }

            return $token;
        } catch (\Throwable $e) {
            error_log('Recordings::podcastToken() failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Increment per-site topic-tag counts after a recording has been
     * created or edited. Caller passes a CSV string; we tokenise,
     * trim, dedupe, upsert into tblRecordingTopic.
     */
    public static function syncTopics(int $siteId, string $topicsCsv): void
    {
        $tokens = array_filter(array_map('trim', explode(',', $topicsCsv)), static fn (string $t): bool => $t !== '');
        $tokens = array_values(array_unique(array_map('strtolower', $tokens)));
        if ($tokens === []) {
            return;
        }
        $db = App::db();
        foreach ($tokens as $t) {
            $stmt = $db->prepare(
                'INSERT INTO tblRecordingTopic (siteID, topic, useCount) VALUES (?, ?, 1) '
                . 'ON DUPLICATE KEY UPDATE useCount = useCount + 1'
            );
            if ($stmt !== false) {
                $stmt->bind_param('is', $siteId, $t);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
