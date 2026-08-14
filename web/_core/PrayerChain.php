<?php
// Path: _core/PrayerChain.php
/**
 * -----------------------------------------------------------------------------
 * Prayer Chain — Partner Assignment Helpers 🙏
 * -----------------------------------------------------------------------------
 * Shared logic for the #311 partner-assignment residuals so
 * save.php / anonymous-save.php / api/create.php / moderate.php don't each
 * reimplement "who's eligible + who's least loaded + how do we tell them".
 *
 * Eligible partner = a user holding the `prayer_team` role (tblUserRoles /
 * tblRoles, the same convention already used by the moderation gate in
 * api/moderate.php) who is an active member of the target site. No separate
 * admin UI is needed to manage this — the role is seeded by migration 148
 * and appears automatically in the existing generic role-assignment
 * checkboxes at /admin/users.
 *
 * "Open assignment" = a request currently assigned to that partner with
 * status IN ('pending', 'active') — i.e. not yet answered or archived. This
 * count is used both as the manual-assignment load-balancing hint (moderate
 * UI dropdowns) and as the auto-assign round-robin tiebreaker.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/311
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class PrayerChain
{
    /**
     * 📋 Eligible partners for a site, each annotated with their current
     * OPEN assignment count, ordered least-loaded first (ties → lowest
     * userID) — the exact order the auto-assign picker and the manual
     * dropdown load-balancing hint both need.
     *
     * @return array<int, array{userID:int, fullName:string, openCount:int}>
     */
    public static function eligiblePartners(int $siteId): array
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT u.userID, u.fullName, '
            . '  (SELECT COUNT(*) FROM tblPrayerRequests pr '
            . '     WHERE pr.siteID = ? AND pr.assignedToUserID = u.userID '
            . '       AND pr.status IN ("pending", "active")) AS openCount '
            . 'FROM tblUsers u '
            . 'INNER JOIN tblUserRoles ur ON ur.userID = u.userID '
            . 'INNER JOIN tblRoles r ON r.roleID = ur.roleID AND r.roleKey = "prayer_team" '
            . 'INNER JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
            . 'WHERE u.isActive = 1 '
            . 'ORDER BY openCount ASC, u.userID ASC'
        );
        $partners = [];
        if ($stmt !== false) {
            $stmt->bind_param('ii', $siteId, $siteId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $partners[] = [
                    'userID'    => (int) $row['userID'],
                    'fullName'  => (string) ($row['fullName'] ?? ''),
                    'openCount' => (int) $row['openCount'],
                ];
            }
            $stmt->close();
        }
        return $partners;
    }

    /**
     * 🎯 Round-robin auto-assign target: the eligible partner with the
     * smallest open-assignment count (ties → lowest userID — both already
     * guaranteed by eligiblePartners()'s ORDER BY). Null when no partner is
     * eligible (auto-assign is then silently skipped by the caller).
     */
    public static function pickAutoAssignPartner(int $siteId): ?int
    {
        $partners = self::eligiblePartners($siteId);
        return $partners === [] ? null : $partners[0]['userID'];
    }

    /**
     * 🤖 Opt-in auto-assign for a freshly-submitted request (#311 residual
     * #4). No-op unless `prayer-requests.autoAssign` = 'true'. Only assigns
     * a request that is still unassigned (defensive — callers pass a
     * brand-new requestID so this is normally moot). Every failure is
     * caught + logged; this method never throws, so it never aborts the
     * caller's submission flow.
     */
    public static function maybeAutoAssign(int $siteId, int $requestId): void
    {
        if ((App::settings('prayer-requests.autoAssign') ?? 'false') !== 'true') {
            return;
        }
        try {
            $partnerId = self::pickAutoAssignPartner($siteId);
            if ($partnerId === null) {
                return;
            }
            $db = App::db();
            $stmt = $db->prepare(
                'UPDATE tblPrayerRequests SET assignedToUserID = ?, assignedAt = NOW() '
                . 'WHERE requestID = ? AND siteID = ? AND assignedToUserID IS NULL'
            );
            if ($stmt === false) {
                return;
            }
            $stmt->bind_param('iii', $partnerId, $requestId, $siteId);
            $stmt->execute();
            $assigned = $stmt->affected_rows > 0;
            $stmt->close();

            if ($assigned === true) {
                Logger::activity(
                    'PrayerRequestAutoAssigned',
                    'Request #' . $requestId . ' auto-assigned to user #' . $partnerId
                );
                self::notifyAssignment($siteId, $requestId, $partnerId);
            }
        } catch (\Throwable $e) {
            Logger::errorPlatform(
                'PrayerChain',
                'Warning',
                'AUTO_ASSIGN_FAIL',
                'Auto-assign failed for request #' . $requestId,
                $e->getMessage()
            );
        }
    }

    /**
     * 📣 Notify a partner they've been assigned a request — email (Mailer)
     * + SMS (Sms::send, category 'prayer_assignment'). Used by BOTH the
     * manual assign action (moderate.php) and maybeAutoAssign() above.
     *
     * Respects existing opt-in conventions: email always attempts (every
     * user has an emailAddress); SMS only fires if the partner has a
     * VERIFIED number AND has opted into the 'prayer_assignment' category
     * via /account/sms (tblUserSmsPreference — the same gate the account
     * SMS-preferences UI already exposes). Sms::send()'s own quiet-hours /
     * daily-cap logic still applies on top of this. Every failure is
     * caught + logged — never allowed to break the assignment transaction.
     */
    public static function notifyAssignment(int $siteId, int $requestId, int $partnerUserId): void
    {
        if ((App::settings('prayer-requests.notifyOnAssign') ?? 'true') !== 'true') {
            return;
        }

        try {
            $db = App::db();

            // 🔍 The request subject (for the message) + the partner's contact details
            $stmt = $db->prepare(
                'SELECT subject FROM tblPrayerRequests WHERE requestID = ? AND siteID = ? LIMIT 1'
            );
            if ($stmt === false) {
                return;
            }
            $stmt->bind_param('ii', $requestId, $siteId);
            $stmt->execute();
            $request = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($request === null) {
                return;
            }
            $subject = (string) $request['subject'];

            $stmt = $db->prepare('SELECT emailAddress FROM tblUsers WHERE userID = ? LIMIT 1');
            if ($stmt === false) {
                return;
            }
            $stmt->bind_param('i', $partnerUserId);
            $stmt->execute();
            $partner = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($partner === null) {
                return;
            }

            // 🔗 Absolute link (relative paths don't render usefully in email/SMS clients)
            $protocol = (isset($_SERVER['HTTPS']) === true && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host     = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $link     = $protocol . '://' . $host . Site::url('account/my-prayer-list');

            // 📧 Email — best-effort, never throws past this method
            $email = (string) $partner['emailAddress'];
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $siteName = (string) (App::settings('site.name') ?? Site::productName());
                $html = '<p>You have been assigned a new prayer request:</p>'
                    . '<p><strong>' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</strong></p>'
                    . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">View on My Prayer List</a></p>';
                try {
                    Mailer::send($email, '[' . $siteName . '] New prayer assignment', $html);
                } catch (\Throwable $e) {
                    Logger::errorPlatform('PrayerChain', 'Warning', 'NOTIFY_EMAIL_FAIL', 'Assignment email failed', $e->getMessage());
                }
            }

            // 📱 SMS — only if verified + opted into 'prayer_assignment' (respects the
            //    existing per-category opt-in convention; Sms::send() itself handles
            //    the daily cap + Sabbath quiet-hours deferral on top of this gate).
            $stmt = $db->prepare(
                'SELECT phoneNumber FROM tblUserSmsPreference '
                . 'WHERE siteID = ? AND userID = ? AND isVerified = 1 '
                . '  AND FIND_IN_SET("prayer_assignment", categories) > 0 LIMIT 1'
            );
            if ($stmt !== false) {
                $stmt->bind_param('ii', $siteId, $partnerUserId);
                $stmt->execute();
                $pref = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($pref !== null) {
                    $body = 'New prayer assignment: ' . $subject . '. ' . $link;
                    Sms::send($siteId, (string) $pref['phoneNumber'], $body, 'prayer_assignment', $partnerUserId);
                }
            }
        } catch (\Throwable $e) {
            Logger::errorPlatform(
                'PrayerChain',
                'Warning',
                'NOTIFY_FAIL',
                'Failed to notify assigned prayer partner #' . $partnerUserId . ' for request #' . $requestId,
                $e->getMessage()
            );
        }
    }
}
