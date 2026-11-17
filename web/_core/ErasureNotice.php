<?php
// Path: _core/ErasureNotice.php
/**
 * -----------------------------------------------------------------------------
 * Telling an organisation's administrators that an erasure request is waiting
 * -----------------------------------------------------------------------------
 * WHY THIS EXISTS (#1136). When a member's own erasure request reaches "waiting
 * for an administrator" (`status = 'pending_review'`), the law gives the
 * organisation one month to act on it (`tblErasureRequest.dueBy`). Nothing used
 * to tell anybody: the request sat on Admin, Erasure requests until somebody
 * happened to open that page, which no menu or card led to either.
 *
 * THE ONE PLACE this notice is worded and sent. It lives in its own file, not
 * in GdprEraser, on purpose: the erasure engine is large and several packages
 * edit it, and a notice is a separate job from erasing.
 *
 * WHO IS TOLD: ImportNotifier::recipients(), unchanged. It is the portal's
 * existing answer to "the administrators of this organisation who can be
 * e-mailed" (its own administrators, else every active global administrator),
 * and it is the same people the requests page lets in. Reused rather than
 * copied: a second copy of that rule would drift. It also returns each
 * person's `notifyPrefs`; they are IGNORED here on purpose. A notification
 * switch must not be able to silence a message about a legal deadline.
 *
 * WHICH REQUESTS: only the two routes the MEMBER takes (the e-mailed
 * confirmation link, and "Delete my account"). The other routes are an
 * administrator's own act on the page, so that administrator already knows.
 * The callers decide that; this class only ever reports "waiting".
 *
 * THE E-MAIL NAMES NOBODY. The member's name and address stay on the page
 * behind a sign-in; nothing personal goes into a mailbox. It carries the
 * organisation's name, the due date as stored, and the address of the page.
 *
 * IT NEVER THROWS and never changes what the member sees: a broken mail route
 * must not break a member's confirmation. What it cannot do: promise anybody
 * read it. A kept message (the mail queue) is counted as "told" because the
 * queue will send it; Mailer::send() answering true means sent OR kept.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class ErasureNotice
{
    /**
     * Tell the organisation's administrators that request $requestId is
     * waiting for them. Sends only while the request really is still
     * `pending_review` for that organisation: read again here, after the
     * caller's own change was stored, so a request that has already moved on
     * (carried out, cancelled) is never announced as waiting.
     *
     * @return int How many administrators were e-mailed or had a message queued
     *             for them. 0 when nobody could be told, and never an exception.
     */
    public static function requestWaiting(int $siteId, int $requestId): int
    {
        try {
            // Its own connection, so a page calling this keeps no database
            // handling of its own (gdpr-coverage-selftest pins that for
            // "Delete my account").
            $db = App::db();
            // The use-by moment is worked out by the database: dueBy is a
            // reading on its own clock, and the queue wants UTC. The offset
            // between the database's two clocks is added to UTC_TIMESTAMP(),
            // so the result does not depend on the session's time zone.
            $stmt = $db->prepare(
                'SELECT `userID`, DATE_FORMAT(`dueBy`, \'%e %M %Y\') AS `dueText`, '
                . 'DATE_FORMAT(UTC_TIMESTAMP() + INTERVAL TIMESTAMPDIFF(SECOND, NOW(), `dueBy`) SECOND, \'%Y-%m-%d %H:%i:%s\') AS `useBy` '
                . 'FROM `tblErasureRequest` WHERE `requestID` = ? AND `siteID` = ? AND `status` = \'pending_review\' LIMIT 1'
            );
            if ($stmt === false) {
                return 0;
            }
            $stmt->bind_param('ii', $requestId, $siteId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row === null || $row['dueText'] === null) {
                return 0;
            }

            $requestUser = $row['userID'] === null ? null : (int) $row['userID'];

            $name = Mailer::organisationNameFor($siteId);
            if ($name === '') {
                $name = 'the portal';
            }
            $link = PortalAddress::absolute('admin/erasure-requests', $siteId);

            $body = 'Somebody in ' . $name . ' has asked for their personal details to be erased, and the request is now '
                . "waiting for an administrator. The law allows one month to act on it; this one is due by " . $row['dueText'] . ".\n\n"
                . ($link !== '' ? 'Open the erasure requests page to review it: ' . $link . "\n\n" : "Open Admin, Erasure requests to review it.\n\n")
                . 'This message does not say who asked; the page does.';
            $subject = 'An erasure request is waiting in ' . $name;
            $options = [
                'siteId'     => $siteId,
                'bodyIsText' => true,
                'expiresAt'  => (string) $row['useBy'],
                'repeatSafe' => true,
            ];

            $told = 0;
            $seen = [];
            foreach (ImportNotifier::recipients($db, $siteId) as $person) {
                $address = $person['email'];
                $key     = strtolower($address);
                if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false || isset($seen[$key]) === true) {
                    continue;
                }
                $seen[$key] = true;
                try {
                    Mailer::send($address, $subject, $body, [], $options);
                    $outcome = Mailer::lastOutcome();
                } catch (\Throwable $e) {
                    // One mailbox failing must not stop the others being told.
                    continue;
                }
                if ($outcome === Mailer::OUTCOME_SENT || $outcome === Mailer::OUTCOME_QUEUED || $outcome === Mailer::OUTCOME_HELD) {
                    $told++;
                }
            }

            // 🧾 The activity line. Two rules, both about erasure reaching it.
            //    1. An erasure finds activity records by their `userID` and by
            //       nothing else, and Logger::activity() fills in the network
            //       address, the browser and the headers of whoever is making
            //       THIS request. When a member confirms from a phone, signed
            //       out, that line would hold their address and name nobody,
            //       and would outlive the very erasure it announces. So the
            //       request's own account number is passed, and the line is
            //       reached by the erasure like every other record of theirs.
            //    2. Logger files a line under the ADDRESS's organisation
            //       (Site::id()), which for a link opened at another
            //       organisation's address is not the request's. Then no line
            //       is written at all: the queued messages already say who
            //       was told, and a line in the wrong organisation's log
            //       would carry a stranger's network address into it.
            //    The words are numbers only.
            if (Site::id() === $siteId && $requestUser !== null) {
                Logger::activity(
                    'GdprErasureRequestAnnounced',
                    'Erasure request #' . $requestId . ' is waiting; ' . $told . ' administrator(s) were e-mailed or had the message queued for them',
                    $requestUser
                );
            }

            return $told;
        } catch (\Throwable $e) {
            // Never the fault's own words: they can quote an address.
            error_log('[WebMS-Intra] ErasureNotice: the notice for erasure request #' . $requestId . ' ended with ' . get_class($e));

            return 0;
        }
    }

    /**
     * For the dashboard card: how many requests wait for an administrator in
     * this organisation and when the earliest is due.
     *
     * An unreadable answer is `count => null`, never zero: "none waiting"
     * about a legal deadline must not be what a failed read looks like (#1012).
     *
     * @return array{count:?int, earliestDue:?string}
     */
    public static function waitingFor(\mysqli $db, int $siteId): array
    {
        try {
            $stmt = $db->prepare(
                'SELECT COUNT(*) AS `n`, DATE_FORMAT(MIN(`dueBy`), \'%e %M %Y\') AS `first` '
                . 'FROM `tblErasureRequest` WHERE `siteID` = ? AND `status` = \'pending_review\''
            );
            if ($stmt === false) {
                return ['count' => null, 'earliestDue' => null];
            }
            $stmt->bind_param('i', $siteId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row === null) {
                return ['count' => null, 'earliestDue' => null];
            }

            return ['count' => (int) $row['n'], 'earliestDue' => $row['first'] === null ? null : (string) $row['first']];
        } catch (\Throwable $e) {
            return ['count' => null, 'earliestDue' => null];
        }
    }
}
