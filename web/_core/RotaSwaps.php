<?php
// Path: _core/RotaSwaps.php
/**
 * -----------------------------------------------------------------------------
 * Rota swap requests: asked, answered, and e-mailed about 🔁 (#657)
 * -----------------------------------------------------------------------------
 * On the Duty Roster a member may ask somebody else to take one of their
 * duties. This class is the ONE place that decides:
 *
 *   - who a request may be aimed at          (`whyNotTarget()`)
 *   - who may answer it, and how             (`whyNotAllowed()`, taking FACTS)
 *   - what an answer does to the duty        (`respond()`, under a row lock)
 *   - who is e-mailed, and what it says      (`tellAsked()`, `tellAnswered()`)
 *   - what a member reads after answering    (`answerWords()`)
 *   - where a duty is, on the asker's list   (`whereDutyIsNow()`)
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
 *     cover?", so it simply stays open. While the organisation has open
 *     requests switched OFF, no open request may be taken, including one
 *     posted before it was switched off; the asker may still withdraw it.
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
 * first to commit and is then judged on what the first wrote. What
 * `tools/rota-swaps-selftest.php` proves, and no more:
 *   - section T, the LOCK: it holds the duty's row in its own transaction,
 *     runs the real `respond()` in its own PROCESS, sees that process WAIT
 *     (asked of the database, not assumed from a sleep), moves the duty, and
 *     requires the accept to be refused. With the lock removed the accept
 *     goes through and gives away a duty that was no longer the asker's.
 *   - section V, the TRANSACTION: a scratch trigger makes the second write
 *     fail; the first write (the duty's move) must be undone with it. With
 *     the transaction removed the duty stays moved under a request still
 *     marked as waiting.
 *   - section U: two members take one open request at the same moment, each
 *     in its own process; exactly one gets the duty.
 *
 * WHO IS E-MAILED. Only by e-mail, through the member's existing notification
 * preferences (the `rotaSwaps` switch on the Notification preferences page,
 * read with `WebPush::notifyPrefAllows()`, where a choice never made means
 * YES). An e-mail is ATTEMPTED — handed to `Mailer`, which may send it, keep
 * it in the mail queue, or be unable to (no mail route set up) — to the
 * person ASKED when a request is made, and to the ASKER when it is accepted
 * or declined. An OPEN request is attempted to every active member of the
 * organisation except the asker who has the switch on (the owner,
 * 11 November 2026 — D-141), one message each, so nobody sees anybody else's
 * address (`tellOpen()`). Nobody is e-mailed about a withdrawal or a request
 * cancelled because another was accepted first: the request simply leaves
 * their page.
 *
 * THE RULE FOR WORDS SHOWN TO A MEMBER (#657; rounds 1-3 each found this
 * class broken somewhere new, so round 4 simplified it rather than refining
 * it again):
 *   - no page says that anybody "is told" or "is sent" an e-mail, because
 *     none can know;
 *   - a message about an ANSWER (the page's message to whoever answered,
 *     and the e-mail to the asker) says what THAT ANSWER DID, from facts
 *     `respond()` established inside its own locked transaction
 *     (`answerWords()`, `tellAnswered()`) — never where the duty is "now";
 *   - only a page that reads the duty's row while drawing itself says where
 *     the duty IS (`whereDutyIsNow()`, the asker's "Swap requests you made"
 *     list).
 *   REPLACED: rounds 2-3 built every such message from the duty's row read
 *   again AFTER the commit. It kept going untrue at the edges, and the read
 *   after the commit could throw and show an error page for an answer that
 *   had been stored.
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
    public const OPEN_SWITCHED_OFF = 'open-switched-off';
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
     * It takes FACTS, never a connection, so the Duty Roster page — which asks
     * it for every row before drawing an Accept, Take or Decline button — and
     * `respond()` — which asks it again on facts read under the lock — apply
     * one rule (#647). They can still give different answers when the facts
     * change between the page being drawn and the button being pressed; the
     * locked answer is the one that counts.
     *
     * @param array{status:string, targetUserID:?int, requestedByID:?int, assignedToID:?int, slotDate:string}|null $facts
     *        Null when no request of that number exists in this organisation.
     * @param string $today       `Y-m-d` on the organisation's calendar (`today()`).
     * @param bool   $openAllowed The organisation's `rota.allow_open_swap` setting
     *                            NOW. Switching it off stops every open request
     *                            being TAKEN, including one posted while it was
     *                            on: the setting is the organisation saying
     *                            "duties are not handed to whoever volunteers",
     *                            and an old request does not overrule that. The
     *                            asker can still withdraw such a request.
     */
    public static function whyNotAllowed(?array $facts, int $userId, string $action, string $today, bool $openAllowed): string
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
            if ($target === null && $openAllowed === false) {
                return self::OPEN_SWITCHED_OFF;
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
            self::OPEN_SWITCHED_OFF => 'Open swap requests are switched off here, so this one can no longer be taken. The person who asked can withdraw it.',
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
     * `$openAllowed` is the organisation's `rota.allow_open_swap` setting,
     * passed in by the caller (see `whyNotAllowed()`).
     *
     * WHAT IT ANSWERS: what THIS answer did, as established inside its own
     * locked transaction — the outcome, who asked, who answered (number and
     * name, read inside the transaction), and on an accept who the duty was
     * moved to. Every message and e-mail about the answer is built from these
     * facts and nothing read afterwards (#657 round 4).
     *
     * @return array{outcome:string, reason:string, slotID:int, action:string, askerID:?int, answererID:?int, answererName:string, movedToID:?int}
     */
    public static function respond(\mysqli $db, int $siteId, int $swapId, int $userId, string $action, string $responseMessage, bool $openAllowed): array
    {
        $response = mb_substr(trim($responseMessage), 0, 500, 'UTF-8');
        $began    = false;
        try {
            // ⚠️ THE TRANSACTION STARTS FIRST, and the read is inside it with
            //    FOR UPDATE, which locks BOTH rows the join reads — the request
            //    and its duty. What the self-test PROVES about each half, and
            //    no more:
            //      - the LOCK: section T holds the duty's row, sees this read
            //        WAIT for it, moves the duty, and requires a refusal. With
            //        ` FOR UPDATE` removed the accept goes through.
            //      - the TRANSACTION: section V makes the SECOND write fail
            //        (a scratch trigger) and requires the FIRST — the duty's
            //        move — to be undone. With the transaction removed the
            //        duty stays moved under a request still marked waiting.
            //      - section U has two members take one open request at once:
            //        exactly one gets it. That is the BEHAVIOUR; it does not on
            //        its own prove the transaction, because with autocommit
            //        the second taker still blocks on the first's write and is
            //        then refused as "changed hands". V is what proves it.
            $began = $db->begin_transaction();
            if ($began === false) {
                return self::didNothing(self::FAILED, '', 0);
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
                return self::didNothing(self::FAILED, '', 0);
            }
            $read->bind_param('ii', $swapId, $siteId);
            $read->execute();
            $row = $read->get_result()->fetch_assoc();
            $read->close();

            $facts  = $row === null ? null : self::factsFrom($row);
            $reason = self::whyNotAllowed($facts, $userId, $action, self::today($siteId), $openAllowed);
            if ($reason !== '') {
                $db->rollback();
                return self::didNothing(self::REFUSED, $reason, (int) ($row['slotID'] ?? 0));
            }
            $slotId = (int) $row['slotID'];

            // 🧾 WHO ANSWERED, read inside this transaction, so every message
            //    about the answer is built from facts this answer established
            //    — never from the duty's row read again after the commit
            //    (#657 round 4: those "where is it now" sentences failed three
            //    rounds running, and a read after the commit could throw and
            //    show an error page for an answer that WAS stored).
            $nameRead = $db->prepare('SELECT `fullName` FROM `tblUsers` WHERE `userID` = ? LIMIT 1');
            $nameRead->bind_param('i', $userId);
            $nameRead->execute();
            $answererName = trim((string) ($nameRead->get_result()->fetch_assoc()['fullName'] ?? ''));
            $nameRead->close();

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

            return [
                'outcome'      => self::DONE,
                'reason'       => '',
                'slotID'       => $slotId,
                'action'       => $action,
                'askerID'      => $facts['requestedByID'],
                'answererID'   => $userId,
                'answererName' => $answererName,
                // On an accept the duty was moved to the answerer, in this
                // transaction: for a request aimed at one person that is that
                // person; for an open request, whoever took it.
                'movedToID'    => $action === self::ACCEPT ? $userId : null,
            ];
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

            return self::didNothing(self::FAILED, '', 0);
        }
    }

    /** What `respond()` answers when it did NOTHING (refused or failed). */
    private static function didNothing(string $outcome, string $reason, int $slotId): array
    {
        return ['outcome' => $outcome, 'reason' => $reason, 'slotID' => $slotId, 'action' => '', 'askerID' => null, 'answererID' => null, 'answererName' => '', 'movedToID' => null];
    }

    /**
     * The message the person who answered reads. Fixed sentences about what
     * THIS answer did — never where the duty is "now", which an administrator
     * can change at any moment and which the page's own list already shows.
     */
    public static function answerWords(string $action): string
    {
        return match ($action) {
            self::ACCEPT   => 'Swap accepted. The duty was moved to you.',
            self::DECLINE  => 'You declined the request. This did not move the duty.',
            self::WITHDRAW => 'Your request was withdrawn. This did not move the duty.',
            default        => 'Done.',
        };
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
     * or `not-sent` (Mailer's outcome), or `not-told:<code>`, or for an open
     * request `open:<told>/<candidates>` (`tellOpen()`), or `no-request`.
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
                // D-141: an open request goes to every member with the switch on.
                return self::tellOpen($db, $siteId, $req);
            }
            $who    = self::person($db, $req['targetUserID']);
            $whyNot = self::whyNotTold($db, $siteId, $req['targetUserID'], $who);
            if ($whyNot !== '') {
                return 'not-told:' . $whyNot;
            }

            return self::sendAsk($siteId, $req, $who, false);
        } catch (\Throwable $e) {
            Logger::errorPlatform('Rota', 'Warning', 'SWAP_TELL_ASKED', 'A rota swap request e-mail could not be prepared', '');

            return 'failed';
        }
    }

    /**
     * 📣 Tell every member about an OPEN request (the owner, 11 November 2026 —
     * D-141): every ACTIVE member of the organisation, except the asker, who
     * has the "Rota swap requests" switch on.
     *
     * WHO IS A CANDIDATE, and who is E-MAILED, are two questions on purpose. The
     * candidates are read as the request form's own list is: an active
     * account with an active membership of this organisation. That list only
     * says whom to ASK about; each candidate is then judged at the moment of
     * sending by `whyNotTold()` — the same rule as the targeted e-mail, which
     * asks `Site::isOwnOrganisation()` — so the decision is never this query's.
     *
     * ONE MESSAGE EACH, never one message to everybody: a shared "To" line
     * would hand every member's address to every other member.
     *
     * Called AFTER the request is saved, never inside a transaction: an e-mail
     * cannot be taken back, and a slow mail route must not hold a lock.
     *
     * Answers `open:<told>/<candidates>`, where `told` counts messages Mailer
     * accepted to send or keep — never a promise that any arrived.
     *
     * WHAT IT CANNOT DO, measured against `Mailer` rather than assumed:
     *   - limit how many requests a member makes, and so how many e-mails
     *     everybody receives (#989, raised separately);
     *   - send everything inside one web request on a large organisation.
     *     These e-mails go out in the request that saved the swap. Mailer
     *     tries them one after another until its time budget runs out
     *     (`Mailer::REQUEST_SECONDS`, 20 seconds) and KEEPS the rest in the
     *     mail queue. While the mail queue's scheduled job is running there
     *     is also a cap of `Mailer::REQUEST_MESSAGES` (25) messages a request
     *     (`Mailer::requestMessages()`), and the job sends what was kept;
     *     with NO job running there is no count cap, and what was kept after
     *     the 20 seconds is NOT sent until a job runs. That is why no page
     *     promises anybody an e-mail.
     *   - stop a host ending a slow request. On a large organisation this
     *     loop runs until Mailer's 20-second budget is spent, and a message
     *     begun just inside it can take up to 27 seconds more (Mailer's own
     *     note on `REQUEST_MESSAGES`) — so about 47 seconds, plus one
     *     membership look-up per member before each message. A host that
     *     kills the request first leaves the request saved while the member
     *     sees an error, and pressing Send again saves a duplicate and
     *     e-mails everybody again (recorded with #989).
     *
     * @param array<string,mixed> $req What `describe()` gave.
     */
    private static function tellOpen(\mysqli $db, int $siteId, array $req): string
    {
        $asker = (int) ($req['requestedByID'] ?? 0);
        $stmt  = $db->prepare(
            'SELECT `u`.`userID` FROM `tblUsers` `u` '
            . 'INNER JOIN `tblUserSites` `us` ON `us`.`userID` = `u`.`userID` AND `us`.`siteID` = ? AND `us`.`isActive` = 1 '
            . 'WHERE `u`.`isActive` = 1 AND `u`.`userID` <> ? ORDER BY `u`.`userID`'
        );
        if ($stmt === false) {
            return 'failed';
        }
        $stmt->bind_param('ii', $siteId, $asker);
        $stmt->execute();
        $ids = array_map(static fn (array $r): int => (int) $r['userID'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        $stmt->close();

        $told = 0;
        foreach ($ids as $id) {
            $who = self::person($db, $id);
            if ($who === null || self::whyNotTold($db, $siteId, $id, $who) !== '') {
                continue;
            }
            $outcome = self::sendAsk($siteId, $req, $who, true);
            if (in_array($outcome, [Mailer::OUTCOME_SENT, Mailer::OUTCOME_QUEUED, Mailer::OUTCOME_HELD], true) === true) {
                $told++;
            }
        }

        return 'open:' . $told . '/' . count($ids);
    }

    /**
     * The "will you take this duty?" e-mail, to one person.
     *
     * @param array<string,mixed> $req What `describe()` gave.
     * @param array<string,mixed> $who The recipient's row (`person()`).
     */
    private static function sendAsk(int $siteId, array $req, array $who, bool $open): string
    {
        $asker   = $req['askerName'] !== '' ? $req['askerName'] : 'A member';
        $duty    = self::dutyWords($req);
        $subject = ($open === true ? 'Open rota swap request: ' : 'Rota swap request: ')
                 . $req['roleName'] . ' on ' . self::dateWords($req['slotDate']);
        $lines   = [
            'Hello ' . self::firstName((string) ($who['fullName'] ?? '')) . ',',
            '',
            $open === true
                ? $asker . ' has asked whether anybody can take their duty: ' . $duty . '. It is an open request: any member of the organisation can take it.'
                : $asker . ' has asked whether you can take their duty: ' . $duty . '.',
        ];
        if ($req['requestMessage'] !== '') {
            $lines[] = '';
            $lines[] = 'Their message: ' . $req['requestMessage'];
        }
        $lines[] = '';
        $lines[] = $open === true
            ? 'The request does not move the duty unless somebody takes it; the first person to take it gets it. To take it, open the Duty Roster:'
            : 'The request does not move the duty unless you accept. To accept or decline, open the Duty Roster:';
        $link    = PortalAddress::absolute('rota', $siteId);
        $lines[] = $link !== '' ? $link : '(sign in to the portal and open the Duty Roster)';
        $lines[] = '';
        $lines[] = 'You can turn these e-mails off on your Notification preferences page.';

        return self::send($siteId, (string) $who['emailAddress'], $subject, implode("\n", $lines), $req['slotDate']);
    }

    /**
     * Where a duty is NOW, in words for the member who ASKED to swap it — for
     * ONE place only: the "Swap requests you made" list on the Duty Roster,
     * which reads the duty's row fresh while it draws the page.
     *
     * WHY ONLY THERE (#657 round 4). Rounds 1 to 3 each found a sentence
     * about where a duty is that had gone untrue: "the duty is still yours"
     * after a decline, "the duty stays with the person who asked" to a
     * decliner, "still yours" after a withdrawal — each written before
     * anybody looked, or read again after the commit, while an administrator
     * can move the duty at any moment. The design that replaced them: a
     * message about an ANSWER says what THAT ANSWER DID (`answerWords()`, and
     * the e-mails in `tellAnswered()`), from facts `respond()` established
     * inside its own locked transaction; and only a page that reads the row
     * while drawing it says where the duty IS. Four branches, each pinned by
     * the self-test's list checks (E3, W1, W4, W5).
     *
     * @param int|null $holderId   Who holds the duty now (null: nobody).
     * @param string   $holderName Their name ('' when it cannot be read).
     * @param int      $askerId    The member who asked — the reader.
     */
    public static function whereDutyIsNow(?int $holderId, string $holderName, int $askerId): string
    {
        if ($holderId !== null && $holderId === $askerId) {
            return 'The duty is still yours.';
        }
        if ($holderId === null) {
            return 'The duty is no longer yours: it is not assigned to anybody at the moment.';
        }

        return 'The duty is no longer yours: it is now with ' . ($holderName !== '' ? $holderName : 'another member') . '.';
    }

    /**
     * Tell the ASKER that their request was accepted or declined. Same
     * answers as `tellAsked()`. A withdrawal e-mails nobody.
     *
     * `$did` is what `respond()` answered for THIS answer. The e-mail's
     * sentence is built from it — who answered, and on an accept that the
     * duty was moved to them, both established inside respond()'s locked
     * transaction — and says nothing about where the duty is "now" (see
     * `whereDutyIsNow()` for why). The rest (the duty's role and date, the
     * two messages, the asker's address) describes the REQUEST, which the
     * answer cannot change, so it is read here.
     *
     * @param array<string,mixed> $did What `respond()` answered (outcome DONE).
     */
    public static function tellAnswered(\mysqli $db, int $siteId, int $swapId, array $did): string
    {
        try {
            $action = (string) ($did['action'] ?? '');
            if (($did['outcome'] ?? '') !== self::DONE || ($action !== self::ACCEPT && $action !== self::DECLINE)) {
                return 'not-answered';
            }
            $req = self::describe($db, $siteId, $swapId);
            if ($req === null) {
                return 'no-request';
            }
            $askerId = $did['askerID'] ?? null;
            if ($askerId === null) {
                return 'not-told:' . self::TELL_NO_ACCOUNT;
            }
            $who    = self::person($db, (int) $askerId);
            $whyNot = self::whyNotTold($db, $siteId, (int) $askerId, $who);
            if ($whyNot !== '') {
                return 'not-told:' . $whyNot;
            }

            $accepted = $action === self::ACCEPT;
            $answerer = (string) ($did['answererName'] ?? '');
            $answerer = $answerer !== '' ? $answerer : ($accepted === true ? 'A member' : 'The member you asked');
            $duty     = self::dutyWords($req);
            $subject  = ($accepted === true ? 'Your rota swap was accepted: ' : 'Your rota swap was declined: ')
                      . $req['roleName'] . ' on ' . self::dateWords($req['slotDate']);
            $lines    = [
                'Hello ' . self::firstName((string) ($who['fullName'] ?? '')) . ',',
                '',
                $accepted === true
                    ? $answerer . ' accepted your request to swap ' . $duty . ', and the duty was moved to them.'
                    : $answerer . ' declined your request to swap ' . $duty . '. This did not move the duty.',
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
     * @return array{status:string, targetUserID:?int, requestedByID:?int, slotDate:string, assignedToID:?int, startTime:?string, endTime:?string, roleName:string, requestMessage:string, responseMessage:string, askerName:string, targetName:string, holderName:string}|null
     */
    private static function describe(\mysqli $db, int $siteId, int $swapId): ?array
    {
        $stmt = $db->prepare(
            'SELECT `w`.`status`, `w`.`targetUserID`, `w`.`requestedByID`, `w`.`requestMessage`, `w`.`responseMessage`, '
            . '`s`.`slotDate`, `s`.`startTime`, `s`.`endTime`, `s`.`assignedToID`, `r`.`name` AS `roleName`, '
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
            'assignedToID'    => $row['assignedToID'] === null ? null : (int) $row['assignedToID'],
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
