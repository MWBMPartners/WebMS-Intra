<?php
// Path: _core/RotaSwaps.php
/**
 * -----------------------------------------------------------------------------
 * Rota swap requests: asked, answered, and the people told 🔁 (#657)
 * -----------------------------------------------------------------------------
 * On the Duty Roster a member may ask somebody else to take one of their
 * duties. This class is the ONE place that decides:
 *
 *   - who a request may be aimed at          (`whyNotTarget()`)
 *   - who may answer it, and how             (`whyNotAllowed()`, taking FACTS)
 *   - what an answer does to the duty        (`respond()`, under a row lock)
 *   - who is told, and what they are told    (`tellAsked()`, `tellAnswered()`)
 *
 * WHAT WAS WRONG (#657). `rota/swap.php` saved a request and told the member
 * "the other member will be notified", and nobody was: there was no e-mail,
 * push or text anywhere in the rota. `rota/swap-respond.php` existed and had
 * an address, but no page linked to it and nothing listed a member's incoming
 * requests, so a request could never be answered and a duty never changed
 * hands. That handler also had three faults that became LIVE the moment a page
 * offered it, which is why it was rewritten rather than linked to:
 *
 *   1. It read the request, then wrote, with NO LOCK. Two presses of Accept
 *      (a double-click, or two people answering an open request) could both
 *      read "pending" and both write — the duty landing with whoever wrote
 *      last, and both told they had it.
 *   2. It never asked whether the duty was still the asker's. An
 *      administrator who had already moved the duty to somebody else would
 *      have it moved again by an old request.
 *   3. Anybody could DECLINE an open request ("could anybody cover?"), which
 *      closed it for everybody else.
 *
 * And `rota/swap.php` accepted ANY posted account number as the person asked,
 * not only one from its own list, so a crafted post could aim a request (and
 * now an e-mail) at somebody in another organisation. `whyNotTarget()` closes
 * that.
 *
 * THE RULES, in the order `whyNotAllowed()` asks them. The order matters: a
 * person who may not answer a request is told only that, never whether it
 * is still waiting or whose duty it is now.
 *
 *   - A request aimed at ONE person may be accepted or declined by that
 *     person and nobody else — not the asker, not an administrator.
 *   - An OPEN request ("anyone can cover", allowed by `rota.allow_open_swap`)
 *     may be ACCEPTED by any member of the organisation except the asker. It
 *     cannot be declined: one person saying no does not answer "could anybody
 *     cover?", so it simply stays open. (Put to the owner on #657 as a
 *     question; this is the recommended answer, built while it waits.)
 *   - The asker may WITHDRAW their own request while it is waiting.
 *   - A request that is no longer waiting cannot be answered again. That is
 *     what makes a second press of Accept a refusal rather than a second move.
 *   - An ACCEPT is refused when the duty is no longer the asker's (an
 *     administrator moved it, or another request for it was accepted first),
 *     when the asker's account has been erased, and when the duty's date has
 *     already passed on the organisation's own calendar. A DECLINE is still
 *     allowed in those cases: saying no moves nothing.
 *
 * WHY THE LOCK, AND WHERE. `respond()` opens its transaction FIRST and then
 * reads the request and its duty with `SELECT … FOR UPDATE` (#697: a lock
 * outside a transaction is decoration). Every rule above is asked of what
 * that locked read returns, and the writes that follow are by key. So a
 * second answer, or an administrator's change to the duty, waits for the
 * first to commit and is then judged on what the first wrote.
 * `tools/rota-swaps-selftest.php` section T proves the lock is load-bearing:
 * it holds the duty's row in its own transaction, runs the real `respond()`
 * in its own PROCESS, moves the duty while that process waits, and requires
 * the accept to be refused. With the lock removed the accept goes through and
 * gives away a duty that was no longer the asker's to give.
 *
 * WHO IS TOLD. Only by e-mail, through the member's existing notification
 * preferences (the `rotaSwaps` switch on the Notification preferences page,
 * read with `WebPush::notifyPrefAllows()`, where a choice never made means
 * YES). The person ASKED is told when a request is made; the ASKER is told
 * when it is accepted or declined. Nobody is told about an OPEN request —
 * e-mailing the whole organisation is a broadcast, which is a different
 * feature — it is shown to every member on the Duty Roster page instead.
 * Nobody is told about a withdrawal or a request cancelled because another
 * was accepted first: the request simply leaves their page.
 *
 * A person is sent nothing when their account is switched off, when they are
 * no longer a member of the organisation (asked of `Site::isOwnOrganisation()`,
 * never a second membership test), when they have switched the e-mails off,
 * or when they have no usable address. That is asked at the moment of
 * sending, not when the request was made (#610's lesson: judge a person at
 * the moment their message would go).
 *
 * E-mail is not held for quiet hours: nothing in the portal holds e-mail
 * (see the Notification preferences page); only text messages are held
 * (D-028), and nothing here sends a text.
 *
 * WHAT THIS CANNOT DO, said plainly:
 *   - It cannot tell whether an e-mail ARRIVED. `tellAsked()` answers what
 *     `Mailer` answered: sent, kept in the queue, or not sent. Neither page
 *     promises the other person an e-mail, for that reason.
 *   - It does not stop a member asking several people about one duty. The
 *     first acceptance wins; the others are cancelled in the same
 *     transaction and leave their pages.
 *   - It does not check that the person taking a duty is free at that time,
 *     or suited to the role. The rota has no such facts to check against.
 *   - The HTTP test harness runs one request at a time, so the race is
 *     proved against this class directly (section T), not over HTTP.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/657
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class RotaSwaps
{
    /** The notification preference that switches these e-mails (`NotifyPrefs::SETTABLE`). */
    public const PREF_KEY = 'rotaSwaps';

    /** The three answers a member can give. */
    public const ACCEPT   = 'accept';
    public const DECLINE  = 'decline';
    public const WITHDRAW = 'withdraw';

    // -------------------------------------------------------------------------
    // 🚫 Why an answer is refused. Each is a CODE; `refusalWords()` is the one
    //    place each becomes a sentence a member reads.
    // -------------------------------------------------------------------------
    public const NOT_FOUND       = 'not-found';
    public const NOT_YOURS       = 'not-yours';
    public const OWN_REQUEST     = 'own-request';
    public const OPEN_NO_DECLINE = 'open-no-decline';
    public const NOT_WAITING     = 'not-waiting';
    public const ASKER_GONE      = 'asker-gone';
    public const CHANGED_HANDS   = 'changed-hands';
    public const PAST            = 'past';
    public const UNKNOWN_ACTION  = 'unknown-action';

    // -------------------------------------------------------------------------
    // 🚫 Why a request may not be aimed at somebody.
    // -------------------------------------------------------------------------
    public const TARGET_SELF      = 'target-self';
    public const TARGET_NOT_HERE  = 'target-not-here';
    public const OPEN_NOT_ALLOWED = 'open-not-allowed';

    // -------------------------------------------------------------------------
    // 📨 Why somebody was not sent an e-mail (`whyNotTold()`).
    // -------------------------------------------------------------------------
    public const TELL_NO_ACCOUNT   = 'no-account';
    public const TELL_ACCOUNT_OFF  = 'account-off';
    public const TELL_NOT_MEMBER   = 'not-member';
    public const TELL_SWITCHED_OFF = 'switched-off';
    public const TELL_NO_ADDRESS   = 'no-address';

    /** `respond()` stored the answer. */
    public const DONE    = 'done';
    /** `respond()` refused the answer; `reason` says why, and nothing changed. */
    public const REFUSED = 'refused';
    /** The database refused something; nothing changed. */
    public const FAILED  = 'failed';

    /**
     * Today's date on the ORGANISATION's own calendar, as `Y-m-d`.
     *
     * A duty's `slotDate` is a date on that calendar (a DATE column, never a
     * moment), so "has this duty passed?" compares two dates on one calendar
     * as text. Never the database server's CURDATE(), whose zone is the
     * server's (#783's shape).
     */
    public static function today(int $siteId): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(Site::timezone($siteId))))->format('Y-m-d');
    }

    /**
     * May a request be aimed at `$targetUserId`? '' when it may.
     *
     * `$targetUserId` 0 means an OPEN request, allowed only while the
     * organisation's `rota.allow_open_swap` setting is on — the form hides the
     * choice when it is off, and this is what stops a hand-made post.
     *
     * A named person must be a member of this organisation (asked of
     * `Site::isOwnOrganisation()`, which also requires their account to be
     * switched on), and not the asker.
     *
     * @throws \RuntimeException When membership could not be asked.
     */
    public static function whyNotTarget(\mysqli $db, int $siteId, int $askerId, int $targetUserId, bool $openAllowed): string
    {
        if ($targetUserId === 0) {
            return $openAllowed === true ? '' : self::OPEN_NOT_ALLOWED;
        }
        if ($targetUserId === $askerId) {
            return self::TARGET_SELF;
        }
        if ($targetUserId < 0 || Site::isOwnOrganisation($targetUserId, $siteId, $db) === false) {
            return self::TARGET_NOT_HERE;
        }

        return '';
    }

    /**
     * May `$userId` give `$action` to the request described by `$facts`? ''
     * when they may, otherwise one of the refusal codes above.
     *
     * It takes FACTS, never a connection, so the page that draws the buttons
     * and `respond()` — which asks it again on facts read under the lock —
     * cannot disagree (#647).
     *
     * @param array{status:string, targetUserID:?int, requestedByID:?int, assignedToID:?int, slotDate:string}|null $facts
     *        Null when no request of that number exists in this organisation.
     * @param string $today `Y-m-d` on the organisation's calendar (`today()`).
     */
    public static function whyNotAllowed(?array $facts, int $userId, string $action, string $today): string
    {
        if (in_array($action, [self::ACCEPT, self::DECLINE, self::WITHDRAW], true) === false) {
            return self::UNKNOWN_ACTION;
        }
        if ($facts === null) {
            return self::NOT_FOUND;
        }

        $target = $facts['targetUserID'];
        $asker  = $facts['requestedByID'];

        // 👤 WHO first, so a stranger learns nothing else about the request.
        if ($action === self::WITHDRAW) {
            if ($asker === null || $asker !== $userId) {
                return self::NOT_YOURS;
            }
        } else {
            if ($target !== null && $target !== $userId) {
                // Aimed at somebody else. The asker lands here too when they
                // try to answer their own request; that is NOT_YOURS rather
                // than OWN_REQUEST because the request is not theirs to ANSWER.
                return self::NOT_YOURS;
            }
            if ($target === null && $asker !== null && $asker === $userId) {
                return self::OWN_REQUEST;
            }
            if ($target === null && $action === self::DECLINE) {
                return self::OPEN_NO_DECLINE;
            }
        }

        // ⏳ STILL WAITING? This is what refuses a second press of Accept.
        if ($facts['status'] !== 'pending') {
            return self::NOT_WAITING;
        }

        // 🔁 Only an ACCEPT moves the duty, so only an accept needs the duty
        //    still to be the asker's, and still to come.
        if ($action === self::ACCEPT) {
            if ($asker === null) {
                return self::ASKER_GONE;
            }
            if ($facts['assignedToID'] === null || $facts['assignedToID'] !== $asker) {
                return self::CHANGED_HANDS;
            }
            if (strcmp($facts['slotDate'], $today) < 0) {
                return self::PAST;
            }
        }

        return '';
    }

    /** The sentence a member reads for a refusal code. */
    public static function refusalWords(string $reason): string
    {
        return match ($reason) {
            self::NOT_FOUND       => 'That swap request could not be found.',
            self::NOT_YOURS       => 'That swap request is not yours to answer.',
            self::OWN_REQUEST     => 'You cannot accept your own swap request. You can withdraw it instead.',
            self::OPEN_NO_DECLINE => 'An open request cannot be declined. It stays open for somebody else to accept.',
            self::NOT_WAITING     => 'That swap request has already been answered or withdrawn, so nothing was changed.',
            self::ASKER_GONE      => 'The person who asked for that swap no longer has an account, so the duty cannot be passed on. Nothing was changed.',
            self::CHANGED_HANDS   => 'That duty is no longer with the person who asked, so the swap cannot go ahead. Nothing was changed.',
            self::PAST            => 'That duty\'s date has already passed, so the swap cannot go ahead. Nothing was changed.',
            self::TARGET_SELF     => 'You cannot ask yourself to cover your own duty.',
            self::TARGET_NOT_HERE => 'That person is not a member of this organisation, so the request was not sent.',
            self::OPEN_NOT_ALLOWED => 'Open swap requests are switched off here. Please choose a person to ask.',
            self::UNKNOWN_ACTION  => 'That answer was not understood, so nothing was changed.',
            default               => 'The swap request could not be answered. Nothing was changed.',
        };
    }

    /**
     * Save a new request. Answers the new request's number, or 0 when the
     * database refused it. The caller has already asked `whyNotTarget()` and
     * checked that the duty is the asker's.
     */
    public static function request(\mysqli $db, int $slotId, int $askerId, int $targetUserId, string $message): int
    {
        $target = $targetUserId > 0 ? $targetUserId : null;
        // The column holds 500 characters; the form says so too. Cut on a
        // character, never a byte, so a name in another alphabet is not split.
        $message = mb_substr(trim($message), 0, 500, 'UTF-8');
        $stmt = $db->prepare(
            'INSERT INTO `tblRotaSwapRequest` (`slotID`, `requestedByID`, `targetUserID`, `requestMessage`) VALUES (?, ?, ?, ?)'
        );
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('iiis', $slotId, $askerId, $target, $message);
        $stmt->execute();
        $newId = (int) $db->insert_id;
        $stmt->close();

        return $newId;
    }

    /**
     * Answer a request, under a row lock. See the class docblock for the rules
     * and for why the lock is where it is.
     *
     * On ACCEPT the duty moves to `$userId`, its "reminder already sent" stamp
     * is cleared (so the new person gets their own reminder — the gap #439
     * write-path rule), the request is marked accepted, and every OTHER
     * request still waiting for the same duty is cancelled, because the duty
     * is no longer the asker's to give.
     *
     * It never throws for a database fault: it rolls back and answers FAILED.
     *
     * @return array{outcome:string, reason:string, slotID:int}
     */
    public static function respond(\mysqli $db, int $siteId, int $swapId, int $userId, string $action, string $responseMessage): array
    {
        $response = mb_substr(trim($responseMessage), 0, 500, 'UTF-8');
        $began    = false;
        try {
            // ⚠️ THE TRANSACTION STARTS FIRST, and the read is inside it with
            //    FOR UPDATE, which locks BOTH rows the join reads — the request
            //    and its duty. Section T of the self-test proves this matters.
            $began = $db->begin_transaction();
            if ($began === false) {
                return ['outcome' => self::FAILED, 'reason' => '', 'slotID' => 0];
            }
            $read = $db->prepare(
                'SELECT `w`.`swapID`, `w`.`slotID`, `w`.`status`, `w`.`targetUserID`, `w`.`requestedByID`, '
                . '`s`.`assignedToID`, `s`.`slotDate` '
                . 'FROM `tblRotaSwapRequest` `w` '
                . 'JOIN `tblRotaSlot` `s` ON `s`.`slotID` = `w`.`slotID` '
                . 'WHERE `w`.`swapID` = ? AND `s`.`siteID` = ? '
                . 'FOR UPDATE'
            );
            if ($read === false) {
                $db->rollback();
                return ['outcome' => self::FAILED, 'reason' => '', 'slotID' => 0];
            }
            $read->bind_param('ii', $swapId, $siteId);
            $read->execute();
            $row = $read->get_result()->fetch_assoc();
            $read->close();

            $facts  = $row === null ? null : self::factsFrom($row);
            $reason = self::whyNotAllowed($facts, $userId, $action, self::today($siteId));
            if ($reason !== '') {
                $db->rollback();
                return ['outcome' => self::REFUSED, 'reason' => $reason, 'slotID' => (int) ($row['slotID'] ?? 0)];
            }
            $slotId = (int) $row['slotID'];

            if ($action === self::ACCEPT) {
                $move = $db->prepare('UPDATE `tblRotaSlot` SET `assignedToID` = ?, `reminderSentAt` = NULL WHERE `slotID` = ?');
                $move->bind_param('ii', $userId, $slotId);
                $move->execute();
                $move->close();

                $mark = $db->prepare(
                    "UPDATE `tblRotaSwapRequest` SET `status` = 'accepted', `responseMessage` = ?, `respondedAt` = NOW() WHERE `swapID` = ?"
                );
                $mark->bind_param('si', $response, $swapId);
                $mark->execute();
                $mark->close();

                // Every other request still waiting for this duty: the duty is
                // no longer the asker's to give.
                $others = $db->prepare(
                    "UPDATE `tblRotaSwapRequest` SET `status` = 'cancelled', `respondedAt` = NOW() "
                    . "WHERE `slotID` = ? AND `swapID` <> ? AND `status` = 'pending'"
                );
                $others->bind_param('ii', $slotId, $swapId);
                $others->execute();
                $others->close();
            } else {
                $status = $action === self::DECLINE ? 'declined' : 'cancelled';
                $mark   = $db->prepare(
                    'UPDATE `tblRotaSwapRequest` SET `status` = ?, `responseMessage` = ?, `respondedAt` = NOW() WHERE `swapID` = ?'
                );
                $kept = $action === self::DECLINE ? $response : null;
                $mark->bind_param('ssi', $status, $kept, $swapId);
                $mark->execute();
                $mark->close();
            }

            $db->commit();

            return ['outcome' => self::DONE, 'reason' => '', 'slotID' => $slotId];
        } catch (\Throwable $e) {
            if ($began === true) {
                try {
                    $db->rollback();
                } catch (\Throwable $ignored) {
                    // The connection has gone; the server rolls back for us.
                }
            }
            // The fault's own words are not logged: they can quote a value.
            Logger::errorPlatform('Rota', 'Warning', 'SWAP_RESPOND', 'A rota swap answer could not be stored', '');

            return ['outcome' => self::FAILED, 'reason' => '', 'slotID' => 0];
        }
    }

    /**
     * May `$userId` be sent a rota swap e-mail on behalf of `$siteId`? ''
     * when they may, otherwise one of the TELL_ codes.
     *
     * Asked at the moment of sending, every time. The membership half is
     * `Site::isOwnOrganisation()` — the one test of "is this one of your
     * organisations", which also requires an active account and an active
     * membership — never a second membership query here.
     *
     * @param array<string,mixed>|null $person The account's row: isActive, emailAddress, notifyPrefs.
     */
    public static function whyNotTold(\mysqli $db, int $siteId, int $userId, ?array $person): string
    {
        if ($person === null) {
            return self::TELL_NO_ACCOUNT;
        }
        if ((int) ($person['isActive'] ?? 0) !== 1) {
            return self::TELL_ACCOUNT_OFF;
        }
        if (Site::isOwnOrganisation($userId, $siteId, $db) === false) {
            return self::TELL_NOT_MEMBER;
        }
        $prefs = $person['notifyPrefs'] === null ? null : (string) $person['notifyPrefs'];
        if (WebPush::notifyPrefAllows($prefs, self::PREF_KEY) === false) {
            return self::TELL_SWITCHED_OFF;
        }
        if (filter_var((string) ($person['emailAddress'] ?? ''), FILTER_VALIDATE_EMAIL) === false) {
            return self::TELL_NO_ADDRESS;
        }

        return '';
    }

    /**
     * Tell the person ASKED that a request is waiting for them. Answers what
     * happened, as a word: `sent`, `queued`, `held`, `not-set-up`, `refused`
     * or `not-sent` (Mailer's outcome), or `not-told:<code>`, or
     * `open` for an open request (nobody is told), or `no-request`.
     *
     * Never throws: telling somebody must never undo or hide the request.
     */
    public static function tellAsked(\mysqli $db, int $siteId, int $swapId): string
    {
        try {
            $req = self::describe($db, $siteId, $swapId);
            if ($req === null) {
                return 'no-request';
            }
            if ($req['targetUserID'] === null) {
                return 'open';
            }
            $who    = self::person($db, $req['targetUserID']);
            $whyNot = self::whyNotTold($db, $siteId, $req['targetUserID'], $who);
            if ($whyNot !== '') {
                return 'not-told:' . $whyNot;
            }

            $asker   = $req['askerName'] !== '' ? $req['askerName'] : 'A member';
            $duty    = self::dutyWords($req);
            $subject = 'Rota swap request: ' . $req['roleName'] . ' on ' . self::dateWords($req['slotDate']);
            $lines   = [
                'Hello ' . self::firstName((string) ($who['fullName'] ?? '')) . ',',
                '',
                $asker . ' has asked whether you can take their duty: ' . $duty . '.',
            ];
            if ($req['requestMessage'] !== '') {
                $lines[] = '';
                $lines[] = 'Their message: ' . $req['requestMessage'];
            }
            $lines[] = '';
            $lines[] = 'Nothing changes unless you accept. To accept or decline, open the Duty Roster:';
            $link    = PortalAddress::absolute('rota', $siteId);
            $lines[] = $link !== '' ? $link : '(sign in to the portal and open the Duty Roster)';
            $lines[] = '';
            $lines[] = 'You can turn these e-mails off on your Notification preferences page.';

            return self::send($siteId, (string) $who['emailAddress'], $subject, implode("\n", $lines), $req['slotDate']);
        } catch (\Throwable $e) {
            Logger::errorPlatform('Rota', 'Warning', 'SWAP_TELL_ASKED', 'A rota swap request e-mail could not be prepared', '');

            return 'failed';
        }
    }

    /**
     * Tell the ASKER that their request was accepted or declined. Same
     * answers as `tellAsked()`. A withdrawal tells nobody.
     */
    public static function tellAnswered(\mysqli $db, int $siteId, int $swapId): string
    {
        try {
            $req = self::describe($db, $siteId, $swapId);
            if ($req === null) {
                return 'no-request';
            }
            if ($req['status'] !== 'accepted' && $req['status'] !== 'declined') {
                return 'not-answered';
            }
            if ($req['requestedByID'] === null) {
                return 'not-told:' . self::TELL_NO_ACCOUNT;
            }
            $who    = self::person($db, $req['requestedByID']);
            $whyNot = self::whyNotTold($db, $siteId, $req['requestedByID'], $who);
            if ($whyNot !== '') {
                return 'not-told:' . $whyNot;
            }

            $accepted = $req['status'] === 'accepted';
            // For an accepted request the person who took the duty is the
            // duty's holder as written by `respond()` in the same request; an
            // open request records nobody else, so this is the one place it
            // can be read.
            $answerer = $accepted === true ? $req['holderName'] : $req['targetName'];
            $answerer = $answerer !== '' ? $answerer : 'The member you asked';
            $duty     = self::dutyWords($req);
            $subject  = ($accepted === true ? 'Your rota swap was accepted: ' : 'Your rota swap was declined: ')
                      . $req['roleName'] . ' on ' . self::dateWords($req['slotDate']);
            $lines    = [
                'Hello ' . self::firstName((string) ($who['fullName'] ?? '')) . ',',
                '',
                $accepted === true
                    ? $answerer . ' accepted your request to swap ' . $duty . '. The duty is now theirs, and is no longer on your list.'
                    : $answerer . ' declined your request to swap ' . $duty . '. The duty is still yours.',
            ];
            if ($req['responseMessage'] !== '') {
                $lines[] = '';
                $lines[] = 'Their message: ' . $req['responseMessage'];
            }
            $lines[] = '';
            $lines[] = 'The Duty Roster:';
            $link    = PortalAddress::absolute('rota', $siteId);
            $lines[] = $link !== '' ? $link : '(sign in to the portal and open the Duty Roster)';
            $lines[] = '';
            $lines[] = 'You can turn these e-mails off on your Notification preferences page.';

            return self::send($siteId, (string) $who['emailAddress'], $subject, implode("\n", $lines), $req['slotDate']);
        } catch (\Throwable $e) {
            Logger::errorPlatform('Rota', 'Warning', 'SWAP_TELL_ANSWERED', 'A rota swap answer e-mail could not be prepared', '');

            return 'failed';
        }
    }

    /**
     * Requests for the Duty Roster page: those waiting for `$userId` to
     * answer (aimed at them, or open and not their own), and those they made.
     * Only duties from today onwards on the organisation's calendar; a made
     * request is listed for 30 days after it was answered, so the asker can
     * see what happened to it.
     *
     * @return array{forMe:list<array<string,mixed>>, open:list<array<string,mixed>>, mine:list<array<string,mixed>>}
     */
    public static function forPage(\mysqli $db, int $siteId, int $userId): array
    {
        $today = self::today($siteId);
        $base  = 'SELECT `w`.`swapID`, `w`.`slotID`, `w`.`status`, `w`.`targetUserID`, `w`.`requestedByID`, '
               . '`w`.`requestMessage`, `w`.`responseMessage`, `w`.`respondedAt`, `w`.`createdAt`, '
               . '`s`.`slotDate`, `s`.`startTime`, `s`.`endTime`, `s`.`assignedToID`, `r`.`name` AS `roleName`, '
               . '`ua`.`fullName` AS `askerName`, `ut`.`fullName` AS `targetName`, `uh`.`fullName` AS `holderName` '
               . 'FROM `tblRotaSwapRequest` `w` '
               . 'JOIN `tblRotaSlot` `s` ON `s`.`slotID` = `w`.`slotID` '
               . 'JOIN `tblRotaRoleType` `r` ON `r`.`roleTypeID` = `s`.`roleTypeID` '
               . 'LEFT JOIN `tblUsers` `ua` ON `ua`.`userID` = `w`.`requestedByID` '
               . 'LEFT JOIN `tblUsers` `ut` ON `ut`.`userID` = `w`.`targetUserID` '
               . 'LEFT JOIN `tblUsers` `uh` ON `uh`.`userID` = `s`.`assignedToID` '
               . 'WHERE `s`.`siteID` = ? AND `s`.`slotDate` >= ? ';

        $run = static function (string $sql, string $types, array $params) use ($db): array {
            $stmt = $db->prepare($sql);
            if ($stmt === false) {
                return [];
            }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            return $rows;
        };

        return [
            'forMe' => $run(
                $base . "AND `w`.`status` = 'pending' AND `w`.`targetUserID` = ? ORDER BY `s`.`slotDate`, `w`.`swapID`",
                'isi',
                [$siteId, $today, $userId]
            ),
            'open' => $run(
                $base . "AND `w`.`status` = 'pending' AND `w`.`targetUserID` IS NULL "
                . 'AND (`w`.`requestedByID` IS NULL OR `w`.`requestedByID` <> ?) ORDER BY `s`.`slotDate`, `w`.`swapID`',
                'isi',
                [$siteId, $today, $userId]
            ),
            'mine' => $run(
                $base . 'AND `w`.`requestedByID` = ? '
                . "AND (`w`.`status` = 'pending' OR `w`.`respondedAt` >= DATE_SUB(NOW(), INTERVAL 30 DAY)) "
                . 'ORDER BY `s`.`slotDate`, `w`.`swapID`',
                'isi',
                [$siteId, $today, $userId]
            ),
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * The locked row as the facts `whyNotAllowed()` takes. Account numbers are
     * turned into ints here, and an empty column stays NULL: the database
     * hands numbers back as text or as ints depending on the driver, and a
     * `!==` between '7' and 7 would refuse the rightful person (#647's trap).
     *
     * @param array<string,mixed> $row
     * @return array{status:string, targetUserID:?int, requestedByID:?int, assignedToID:?int, slotDate:string}
     */
    public static function factsFrom(array $row): array
    {
        $int = static fn (mixed $v): ?int => $v === null ? null : (int) $v;

        return [
            'status'        => (string) $row['status'],
            'targetUserID'  => $int($row['targetUserID']),
            'requestedByID' => $int($row['requestedByID']),
            'assignedToID'  => $int($row['assignedToID']),
            'slotDate'      => (string) $row['slotDate'],
        ];
    }

    /**
     * One request with everything an e-mail about it needs, or null.
     *
     * @return array{status:string, targetUserID:?int, requestedByID:?int, slotDate:string, startTime:?string, endTime:?string, roleName:string, requestMessage:string, responseMessage:string, askerName:string, targetName:string, holderName:string}|null
     */
    private static function describe(\mysqli $db, int $siteId, int $swapId): ?array
    {
        $stmt = $db->prepare(
            'SELECT `w`.`status`, `w`.`targetUserID`, `w`.`requestedByID`, `w`.`requestMessage`, `w`.`responseMessage`, '
            . '`s`.`slotDate`, `s`.`startTime`, `s`.`endTime`, `r`.`name` AS `roleName`, '
            . '`ua`.`fullName` AS `askerName`, `ut`.`fullName` AS `targetName`, `uh`.`fullName` AS `holderName` '
            . 'FROM `tblRotaSwapRequest` `w` '
            . 'JOIN `tblRotaSlot` `s` ON `s`.`slotID` = `w`.`slotID` '
            . 'JOIN `tblRotaRoleType` `r` ON `r`.`roleTypeID` = `s`.`roleTypeID` '
            . 'LEFT JOIN `tblUsers` `ua` ON `ua`.`userID` = `w`.`requestedByID` '
            . 'LEFT JOIN `tblUsers` `ut` ON `ut`.`userID` = `w`.`targetUserID` '
            . 'LEFT JOIN `tblUsers` `uh` ON `uh`.`userID` = `s`.`assignedToID` '
            . 'WHERE `w`.`swapID` = ? AND `s`.`siteID` = ? LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $swapId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return null;
        }

        return [
            'status'          => (string) $row['status'],
            'targetUserID'    => $row['targetUserID'] === null ? null : (int) $row['targetUserID'],
            'requestedByID'   => $row['requestedByID'] === null ? null : (int) $row['requestedByID'],
            'slotDate'        => (string) $row['slotDate'],
            'startTime'       => $row['startTime'] === null ? null : (string) $row['startTime'],
            'endTime'         => $row['endTime'] === null ? null : (string) $row['endTime'],
            'roleName'        => (string) $row['roleName'],
            'requestMessage'  => trim((string) ($row['requestMessage'] ?? '')),
            'responseMessage' => trim((string) ($row['responseMessage'] ?? '')),
            'askerName'       => trim((string) ($row['askerName'] ?? '')),
            'targetName'      => trim((string) ($row['targetName'] ?? '')),
            'holderName'      => trim((string) ($row['holderName'] ?? '')),
        ];
    }

    /**
     * The facts about one account that `whyNotTold()` needs, or null.
     *
     * @return array<string,mixed>|null
     */
    private static function person(\mysqli $db, int $userId): ?array
    {
        $stmt = $db->prepare(
            'SELECT `isActive`, `emailAddress`, `notifyPrefs`, `fullName` FROM `tblUsers` WHERE `userID` = ? LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row;
    }

    /**
     * Send one plain-text e-mail on behalf of `$siteId`.
     *
     * `bodyIsText` because the body quotes words a member typed (their
     * message, and their name): without it Mailer guesses HTML from any `<`,
     * and would send that text as markup (#859). `siteId` so the footer is
     * this organisation's (#683). The use-by moment is the end of the duty's
     * own day on the organisation's clock: after that the request is about a
     * duty that has happened, and is no use to anybody.
     */
    private static function send(int $siteId, string $to, string $subject, string $body, string $slotDate): string
    {
        $nextDay = \DateTimeImmutable::createFromFormat('!Y-m-d', $slotDate, new \DateTimeZone('UTC'));
        $useBy   = $nextDay === false
            ? 'not known'
            : MailQueue::useBy($nextDay->modify('+1 day')->format('Y-m-d'), Site::timezone($siteId));
        $options = ['siteId' => $siteId, 'bodyIsText' => true];
        if ($useBy !== 'not known') {
            $options['expiresAt'] = $useBy;
        }
        Mailer::send($to, $subject, $body, [], $options);

        return Mailer::lastOutcome();
    }

    /** "Sound desk on Sunday 15 November 2026, 10:00–12:00" — a date on the calendar, no zone involved. */
    private static function dutyWords(array $req): string
    {
        $words = $req['roleName'] . ' on ' . self::dateWords($req['slotDate']);
        if ($req['startTime'] !== null) {
            $words .= ', ' . substr($req['startTime'], 0, 5);
            if ($req['endTime'] !== null) {
                $words .= '–' . substr($req['endTime'], 0, 5);
            }
        }

        return $words;
    }

    /**
     * A DATE column as words. Built in UTC from the digits on purpose: a date
     * has no time and so no zone, and building it in a zone with a clock
     * change at midnight could move it (#777).
     */
    public static function dateWords(string $slotDate): string
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $slotDate, new \DateTimeZone('UTC'));

        return $day === false ? $slotDate : $day->format('l j F Y');
    }

    /** The first word of a name, for a greeting; "there" when there is none. */
    private static function firstName(string $fullName): string
    {
        $first = trim((string) strtok(trim($fullName), ' '));

        return $first !== '' ? $first : 'there';
    }
}
