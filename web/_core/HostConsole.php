<?php
// Path: _core/HostConsole.php
/**
 * -----------------------------------------------------------------------------
 * Portal Core — HostConsole 🎙️📊
 * -----------------------------------------------------------------------------
 * Pure read-side composition helpers for the virtual host console (#317).
 * Wraps existing COP primitives:
 *   • tblLivestreamSessions (#318) — viewer count + 7-day session trend
 *   • tblDecisionMoments    (#315) — per-moment-type counters
 *   • tblSalvationCards     (#316) — recent intake stream
 *
 * NO writes. NO new tables. NO new infrastructure. Phase 1 of #317 ships
 * the dashboard composition only — Phase 2 will add host → viewer push
 * prompts which need their own schema.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/317
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class HostConsole
{
    /**
     * Count of concurrent viewers for a livestream event right now.
     * "Now" = lastPingAt within $windowSeconds AND leftAt IS NULL.
     *
     * 90s default tolerates the 30-60s ping cadence from the embed snippet
     * shipped in #318 admin/livestream/dashboard.php without false-zeros
     * during brief network blips.
     */
    public static function liveViewerCount(int $eventId, int $siteId, int $windowSeconds = 90): int
    {
        if ($eventId <= 0 || $siteId <= 0) {
            return 0;
        }
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS c FROM tblLivestreamSessions '
            . 'WHERE siteID = ? AND eventID = ? AND leftAt IS NULL '
            . '  AND lastPingAt >= DATE_SUB(NOW(), INTERVAL ? SECOND)'
        );
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('iii', $siteId, $eventId, $windowSeconds);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['c'] ?? 0);
    }

    /**
     * Per-day session counts for the last 7 days. Returned as an ordered
     * array of ['date' => 'YYYY-MM-DD', 'count' => int] dictionaries so the
     * caller can render a sparkline or a small bar chart.
     *
     * @return array<int, array{date: string, count: int}>
     */
    public static function sessionTrend7d(int $eventId, int $siteId): array
    {
        if ($eventId <= 0 || $siteId <= 0) {
            return [];
        }
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT DATE(joinedAt) AS d, COUNT(*) AS c FROM tblLivestreamSessions '
            . 'WHERE siteID = ? AND eventID = ? '
            . '  AND joinedAt >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) '
            . 'GROUP BY DATE(joinedAt) ORDER BY d ASC'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('ii', $siteId, $eventId);
        $stmt->execute();
        $result = $stmt->get_result();
        $byDate = [];
        while ($r = $result->fetch_assoc()) {
            $byDate[(string) $r['d']] = (int) $r['c'];
        }
        $stmt->close();

        // 📅 Zero-fill missing days so the sparkline doesn't have gaps.
        $out = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime('-' . $i . ' days'));
            $out[] = ['date' => $d, 'count' => $byDate[$d] ?? 0];
        }
        return $out;
    }

    /**
     * Per-momentType counter tallies for an event. Returns ALL six ENUM
     * values zero-filled (so the dashboard's six tiles always render the
     * same shape).
     *
     * @return array<string, array{count: int, updatedAt: ?string}>
     */
    public static function decisionTallies(int $eventId): array
    {
        $out = [];
        foreach (['first-decision', 'rededication', 'baptism-request', 'membership-interest', 'prayer-request', 'other'] as $type) {
            $out[$type] = ['count' => 0, 'updatedAt' => null];
        }
        if ($eventId <= 0) {
            return $out;
        }
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT momentType, count, updatedAt FROM tblDecisionMoments WHERE eventID = ?'
        );
        if ($stmt === false) {
            return $out;
        }
        $stmt->bind_param('i', $eventId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            $key = (string) $r['momentType'];
            if (isset($out[$key]) === true) {
                $out[$key] = ['count' => (int) $r['count'], 'updatedAt' => (string) $r['updatedAt']];
            }
        }
        $stmt->close();
        return $out;
    }

    /**
     * Latest salvation cards for the event. siteID-scoped (cards have a
     * nullable eventID column from #316 migration 132, so cards without an
     * event don't appear here — they're visible at /admin/decision-cards).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recentCards(int $eventId, int $siteId, int $limit = 20): array
    {
        if ($eventId <= 0 || $siteId <= 0) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT c.cardID, c.fullName, c.email, c.decision, c.status, c.createdAt, '
            . '       u.fullName AS assigneeName '
            . 'FROM tblSalvationCards c LEFT JOIN tblUsers u ON u.userID = c.assignedToID '
            . 'WHERE c.siteID = ? AND c.eventID = ? '
            . 'ORDER BY c.createdAt DESC LIMIT ?'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('iii', $siteId, $eventId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $out = [];
        while ($r = $result->fetch_assoc()) {
            $out[] = $r;
        }
        $stmt->close();
        return $out;
    }

    /**
     * Single-row probe: does the event exist AND belong to this site?
     * Used as the 404 gate before any other read query — avoids leaking
     * the existence of cross-site events through count-zero responses.
     */
    public static function eventBelongsToSite(int $eventId, int $siteId): bool
    {
        if ($eventId <= 0 || $siteId <= 0) {
            return false;
        }
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ii', $eventId, $siteId);
        $stmt->execute();
        $found = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $found;
    }
}
