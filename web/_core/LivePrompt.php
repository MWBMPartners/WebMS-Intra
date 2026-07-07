<?php
// Path: _core/LivePrompt.php
/**
 * -----------------------------------------------------------------------------
 * Portal Core LivePrompt 📣
 * -----------------------------------------------------------------------------
 * Helper primitives for tblLivePrompts (#317 Phase 2 / #313 Phase 2 overlap).
 *
 * Public methods:
 *   LivePrompt::publish($mysqli, ...)              → array (inserted row)
 *   LivePrompt::activePromptsForEvent($mysqli, $siteId, $eventId) → array
 *   LivePrompt::dismiss($mysqli, $promptId, $siteId, $userId) → bool
 *   LivePrompt::validateCtaUrl($url)               → ?string (null on reject, normalised on accept)
 *   LivePrompt::typeMeta($promptType)              → array (icon + colour + label)
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/317
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

class LivePrompt
{
    public const TYPES = ['decision-call', 'prayer-request', 'give-now', 'announcement'];

    /**
     * Validate a CTA URL against the strict scheme allowlist.
     *
     *   Accepts:  '' (empty), root-relative '/foo' (but NOT '//foo'),
     *             http://, https://
     *   Rejects:  '//evil.com', javascript:, data:, vbscript:, file:,
     *             anything with control characters
     *
     * @return string|null Normalised URL on accept, null on reject
     */
    public static function validateCtaUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        // 🛡️ Reject control chars + any whitespace inside the URL.
        if (preg_match('/[\x00-\x1F\x7F\s]/', $url) === 1) {
            return null;
        }
        // 🛡️ Reject protocol-relative URLs ('//evil.com').
        if (str_starts_with($url, '//') === true) {
            return null;
        }
        // ✅ Root-relative path — accept.
        if (str_starts_with($url, '/') === true) {
            return $url;
        }
        // 🛡️ Absolute URL — parse_url and check scheme exactly.
        $parts = parse_url($url);
        if ($parts === false || isset($parts['scheme']) === false) {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (in_array($scheme, ['http', 'https'], true) === false) {
            return null;
        }
        // 🛡️ host must be present and non-empty for http(s).
        if (isset($parts['host']) === false || (string) $parts['host'] === '') {
            return null;
        }
        return $url;
    }

    /**
     * Insert a new prompt. Caller has already validated authority + the
     * input shape; this method clamps + sanitises + writes.
     *
     * @return array<string, mixed> The inserted row (includes promptID, expiresAt)
     */
    public static function publish(
        mysqli $mysqli,
        int $siteId,
        int $eventId,
        string $promptType,
        string $title,
        string $body,
        string $ctaLabel,
        string $ctaUrl,
        int $createdById,
        ?int $expirySeconds = null
    ): array {
        if (in_array($promptType, self::TYPES, true) === false) {
            throw new \InvalidArgumentException('Invalid promptType');
        }

        $title    = mb_substr(trim($title), 0, 120);
        if ($title === '') {
            throw new \InvalidArgumentException('title required');
        }
        $body     = mb_substr(trim($body), 0, 500);
        $ctaLabel = mb_substr(trim($ctaLabel), 0, 60);

        $ctaUrlValidated = self::validateCtaUrl($ctaUrl);
        if ($ctaUrlValidated === null) {
            throw new \InvalidArgumentException('ctaUrl rejected (must be empty, root-relative, or http/https)');
        }

        $expirySeconds ??= (int) Settings::get('chat.promptDefaultExpirySecs', '300');
        $expirySeconds = max(30, min(3600, $expirySeconds));
        $expiresAt = (new \DateTimeImmutable())->modify('+' . $expirySeconds . ' seconds')->format('Y-m-d H:i:s');

        $bodyArg     = $body !== '' ? $body : null;
        $ctaLabelArg = $ctaLabel !== '' ? $ctaLabel : null;
        $ctaUrlArg   = $ctaUrlValidated !== '' ? $ctaUrlValidated : null;

        $stmt = $mysqli->prepare(
            'INSERT INTO tblLivePrompts '
            . '(siteID, eventID, promptType, title, body, ctaLabel, ctaUrl, expiresAt, createdByID) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('publish prepare failed');
        }
        $stmt->bind_param(
            'iissssssi',
            $siteId, $eventId, $promptType, $title, $bodyArg, $ctaLabelArg, $ctaUrlArg, $expiresAt, $createdById
        );
        $stmt->execute();
        $promptId = (int) $stmt->insert_id;
        $stmt->close();

        Logger::activity('LivePromptPublished', 'event=' . $eventId . ' type=' . $promptType . ' id=' . $promptId, $createdById);

        return [
            'promptID'    => $promptId,
            'siteID'      => $siteId,
            'eventID'     => $eventId,
            'promptType'  => $promptType,
            'title'       => $title,
            'body'        => $body,
            'ctaLabel'    => $ctaLabel,
            'ctaUrl'      => $ctaUrlValidated,
            'expiresAt'   => $expiresAt,
            'createdByID' => $createdById,
        ];
    }

    /**
     * Active prompts for an event, ordered newest-first. Excludes expired
     * AND manually-dismissed.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function activePromptsForEvent(mysqli $mysqli, int $siteId, int $eventId): array
    {
        if ($eventId <= 0 || $siteId <= 0) {
            return [];
        }
        $stmt = $mysqli->prepare(
            'SELECT promptID, promptType, title, body, ctaLabel, ctaUrl, publishedAt, expiresAt '
            . 'FROM tblLivePrompts '
            . 'WHERE siteID = ? AND eventID = ? AND dismissedAt IS NULL AND expiresAt > NOW() '
            . 'ORDER BY publishedAt DESC LIMIT 5'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('ii', $siteId, $eventId);
        $stmt->execute();
        $rows = [];
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            $rows[] = [
                'promptID'    => (int) $r['promptID'],
                'promptType'  => (string) $r['promptType'],
                'title'       => (string) $r['title'],
                'body'        => (string) ($r['body'] ?? ''),
                'ctaLabel'    => (string) ($r['ctaLabel'] ?? ''),
                'ctaUrl'      => (string) ($r['ctaUrl'] ?? ''),
                'publishedAt' => (string) $r['publishedAt'],
                'expiresAt'   => (string) $r['expiresAt'],
            ];
        }
        $stmt->close();
        return $rows;
    }

    /**
     * Manually dismiss a prompt (coordinator action). Idempotent.
     */
    public static function dismiss(mysqli $mysqli, int $promptId, int $siteId, int $userId): bool
    {
        if ($promptId <= 0 || $siteId <= 0) {
            return false;
        }
        $stmt = $mysqli->prepare(
            'UPDATE tblLivePrompts SET dismissedAt = NOW() '
            . 'WHERE promptID = ? AND siteID = ? AND dismissedAt IS NULL'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ii', $promptId, $siteId);
        $stmt->execute();
        $changed = $stmt->affected_rows > 0;
        $stmt->close();
        if ($changed === true) {
            Logger::activity('LivePromptDismissed', 'prompt=' . $promptId, $userId);
        }
        return $changed;
    }

    /**
     * Per-type icon + label + Bootstrap colour for composer + viewer overlay.
     *
     * @return array{label: string, icon: string, colour: string}
     */
    public static function typeMeta(string $promptType): array
    {
        return match ($promptType) {
            'decision-call'  => ['label' => 'Decision call',  'icon' => 'fa-hand-holding-heart', 'colour' => 'success'],
            'prayer-request' => ['label' => 'Prayer request', 'icon' => 'fa-hands-praying',     'colour' => 'info'],
            'give-now'       => ['label' => 'Give now',       'icon' => 'fa-hand-holding-dollar','colour' => 'warning'],
            'announcement'   => ['label' => 'Announcement',   'icon' => 'fa-bullhorn',          'colour' => 'primary'],
            default          => ['label' => 'Unknown',        'icon' => 'fa-circle-question',   'colour' => 'secondary'],
        };
    }
}
