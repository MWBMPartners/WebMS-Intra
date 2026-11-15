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
 *   - how many a member may make             (`limits()`, `whyOverLimits()`,
 *     and `request()` under its locks — #989, D-171)
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
 *   - It does not stop a member asking several NAMED people about one duty
 *     (each is one e-mail, and the per-day limit bounds them). The first
 *     acceptance wins; the others are cancelled in the same transaction and
 *     leave their pages.
 *   - It does not show an administrator who sent which requests; #989 asked
 *     and D-171 did not decide it.
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
    /** An open request for this duty is already waiting, as many as the limit allows (D-171). */
    public const OVER_OPEN_PER_DUTY = 'over-open-per-duty';
    /** This member has sent as many requests today as the limit allows (D-171). */
    public const OVER_PER_DAY       = 'over-per-day';

    // -------------------------------------------------------------------------
    // 🚧 THE TWO LIMITS ON ASKING (#989; the owner, 12 November 2026 — D-171).
    //
    //    Under D-141 an OPEN request is e-mailed to every member with the
    //    switch on, so with no limit one member could send the whole
    //    organisation any number of e-mails, each quoting their own typed
    //    message. And with no mail-queue job running those e-mails are sent
    //    inside the web request: when the host ends a long one the member
    //    sees an error although the request was SAVED, presses Send again,
    //    and everybody is e-mailed twice. The per-duty limit stops that
    //    second copy.
    //
    //    Both are LIMITS under the three-tier rule (#668): a hard maximum
    //    here, then the installation's figure, then the organisation's, each
    //    unable to loosen the one above. Set on Admin, Settings, Member
    //    limits; read through `SettingLimit`.
    //
    //    0 IS NOT "NO LIMIT" HERE, and that is deliberate: D-171 rules out "no
    //    limit", so the saves refuse 0 and the least either may be is 1. A 0
    //    written straight into the database is obeyed as 0 — nobody may ask —
    //    which is the direction that sends no e-mail.
    // -------------------------------------------------------------------------
    /** How many OPEN requests may wait for one duty at once. */
    public const OPEN_PER_DUTY_KEY      = 'rota.swap.maxOpenPerDuty';
    public const OPEN_PER_DUTY_DEFAULT  = 1;
    /** Ten: an open request already reaches the whole organisation, so more than a handful waiting for one duty is only noise. */
    public const OPEN_PER_DUTY_HARD_MAX = 10;
    /** How many requests (named and open together) one member may make in one day on the organisation's calendar. */
    public const PER_DAY_KEY            = 'rota.swap.maxRequestsPerDay';
    public const PER_DAY_DEFAULT        = 10;
    /** A hundred a day is far past any real rota and still bounds the e-mail one member can cause. */
    public const PER_DAY_HARD_MAX       = 100;

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
    /** The database refused something BEFORE the commit, and it was rolled back; nothing changed. */
    public const FAILED  = 'failed';
    /**
     * The COMMIT itself failed or its reply was lost, so whether the answer
     * was stored is NOT KNOWN: a server can commit and the reply never reach
     * PHP (#649's shape). Never worded as "nothing was changed".
     */
    public const UNKNOWN = 'unknown';

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
            // The numbered sentences are overLimitWords(), which needs the
            // count; these two are only what a caller without one reads.
            self::OVER_OPEN_PER_DUTY => 'You already have as many open requests waiting for this duty as this organisation allows, so another was not posted.',
            self::OVER_PER_DAY       => 'You have made as many swap requests today as this organisation allows, so this one was not sent.',
            default               => 'The swap request could not be answered. Nothing was changed.',
        };
    }

    /**
     * 💬 What a member reads when a request would go over a limit (D-171: "a
     * user friendly process", not a bare refusal). It says how many they have
     * made (or have waiting) AND what the limit is — two numbers, never one
     * standing for both — that nothing was sent, and what they CAN do now.
     * The swap page draws the same words BEFORE anybody presses Send
     * (`$beforeAsking`), so the refusal after a post is the rare case of two
     * windows open at once.
     *
     * WHY TWO NUMBERS (round 1 of the independent check). The first version
     * printed the LIMIT as the number made: "You have made {limit} swap
     * requests today". That is true only when the count equals the limit.
     * Lower the limit mid-day and a member who made 8 read "You have made 5";
     * at a limit of 0 it said "You have made 0 … the most this organisation
     * allows". The count is what `limitFacts()` read, the limit what
     * `limits()` read, and each is printed as itself.
     *
     * A LIMIT OF 0 has its own sentence. The saves refuse 0, so it is reached
     * only by a row written straight into the database, and then nobody may
     * ask at all: "ask again tomorrow" and "withdraw it and post a new one"
     * would both be untrue, so neither is said.
     *
     * It never says who set the limit: the installation's and the
     * organisation's figures may be set by different people
     * (`SettingLimit::refusalWords()` decided that first). And it never says
     * withdrawing a request gives one back for the day: a withdrawn request was
     * already sent, so it still counts.
     *
     * @param string $reason       `OVER_PER_DAY` or `OVER_OPEN_PER_DUTY`.
     * @param int    $limit        The limit in force.
     * @param int    $made         Requests made today (per day), or open requests waiting (per duty).
     * @param bool   $beforeAsking True on the page before anything is posted.
     */
    public static function overLimitWords(string $reason, int $limit, int $made, bool $beforeAsking = false): string
    {
        $plural = static fn (int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);
        if ($reason === self::OVER_PER_DAY) {
            if ($limit <= 0) {
                return 'This organisation is not taking rota swap requests at the moment, so '
                    . ($beforeAsking === true ? 'you cannot send one. ' : 'this one was not sent. ')
                    . 'If this duty needs covering, ask whoever manages the rota to move it for you.';
            }

            return 'You have made ' . $plural($made, 'swap request', 'swap requests') . ' today, and this organisation allows '
                . $limit . ' a day, so '
                . ($beforeAsking === true ? 'you cannot send another today. ' : 'this one was not sent. ')
                . 'You can ask again tomorrow. If this duty needs covering sooner, ask whoever manages the rota to move it for you.';
        }
        if ($reason === self::OVER_OPEN_PER_DUTY) {
            if ($limit <= 0) {
                return 'Open swap requests cannot be posted here at the moment, so '
                    . ($beforeAsking === true ? 'the open option is not offered. ' : 'this one was not posted. ')
                    . 'You can ask one named person instead.';
            }

            return 'You have ' . $plural($made, 'open request', 'open requests') . ' waiting for this duty, and this organisation allows '
                . $limit . ' at a time, so another '
                . ($beforeAsking === true ? 'cannot be posted' : 'was not posted')
                . ': everybody it would go to has already been offered this duty. '
                . 'You can ask one named person instead, or withdraw a waiting request from your Duty Roster page and post a new one.';
        }

        return 'The swap request was not sent.';
    }

    /**
     * 🚧 The two limits in force for the organisation being served, each held
     * to the installation's figure and the hard maximum (`SettingLimit`, the
     * ONE place the three-tier rule is written).
     *
     * @return array{openPerDuty:int, perDay:int}
     */
    public static function limits(): array
    {
        return [
            'openPerDuty' => SettingLimit::effective(self::OPEN_PER_DUTY_KEY, self::OPEN_PER_DUTY_DEFAULT, self::OPEN_PER_DUTY_HARD_MAX),
            'perDay'      => SettingLimit::effective(self::PER_DAY_KEY, self::PER_DAY_DEFAULT, self::PER_DAY_HARD_MAX),
        ];
    }

    /**
     * 🕛 The moment the organisation's TODAY began, in UTC, as the database
     * text a `createdAtUtc` is compared with.
     *
     * "Per day" is the organisation's calendar day (its own zone, D-171's
     * "per day" read as a member would), never the database server's, and
     * never a rolling 24 hours: "you can ask again tomorrow" has to be true.
     * `createdAtUtc` is written with `UTC_TIMESTAMP()` (migration 298) so
     * the comparison is between two UTC readings and no session time zone is
     * involved. On a day the clocks change the day is 23 or 25 hours long, as
     * it really is; see `dayStartUtcFor()` for where it begins.
     */
    public static function dayStartUtc(int $siteId): string
    {
        return self::dayStartUtcFor(Site::timezone($siteId), new \DateTimeImmutable('now'));
    }

    /**
     * 🕛 The EARLIEST instant whose reading on `$zone`'s clock falls on the
     * same date as `$now` does there — in UTC. Takes a zone and a moment so the
     * rule can be proved at FIXED moments, which "now" never reaches.
     * `dayStartUtc()` is the only caller under `web/`.
     *
     * WORKED OUT FROM THE ZONE'S OWN TRANSITIONS, NOT `setTime(0, 0)` (round 2
     * of the independent check). Where the clocks go back to exactly midnight,
     * midnight happens TWICE, and PHP's `setTime(0, 0)` picks the SECOND one:
     * Atlantic/Azores on 25 October 2026 gave 01:00 UTC where the day really
     * began at 00:00 UTC, and America/Havana on 1 November 2026 gave 05:00 UTC
     * where it began at 04:00 UTC — so a member's requests in that first hour
     * were not counted and they could go over the limit once a year. The
     * first version's docblock said "on a day the clocks change, today is
     * still that date's first moment", which was untrue for exactly those.
     *
     * So, for each stretch of time in which the zone's offset is fixed, the
     * instants whose local reading falls on the date are an interval worked
     * out by arithmetic; the answer is the smallest start among them. That
     * covers midnight skipped (America/Santiago in September: the day begins
     * at 01:00), midnight twice (the two above), and a day the clocks go back
     * ACROSS midnight, whose local date runs D, D-1, D again.
     * `tools/rota-swaps-selftest.php` sweeps every zone PHP knows against an
     * independent minute-by-minute search.
     */
    public static function dayStartUtcFor(string $zone, \DateTimeImmutable $now): string
    {
        $tz   = new \DateTimeZone($zone);
        $date = $now->setTimezone($tz)->format('Y-m-d');
        // The date's midnight read as if it were UTC: a local reading of the
        // date is in [$midnight, $midnight + 86400) once its offset is added.
        $midnight = (new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC')))->getTimestamp();
        $from     = $midnight - 2 * 86400;
        $to       = $midnight + 2 * 86400;
        $changes  = $tz->getTransitions($from, $to);
        if ($changes === false || $changes === []) {
            return gmdate('Y-m-d H:i:s', $midnight - $tz->getOffset($now));
        }
        $earliest = null;
        foreach ($changes as $i => $change) {
            // The first entry is the state at $from; each later one a change.
            $start  = max((int) $change['ts'], $from);
            $end    = isset($changes[$i + 1]) === true ? (int) $changes[$i + 1]['ts'] : $to;
            $offset = (int) $change['offset'];
            $lo     = max($start, $midnight - $offset);
            $hi     = min($end, $midnight + 86400 - $offset);
            if ($lo < $hi && ($earliest === null || $lo < $earliest)) {
                $earliest = $lo;
            }
        }

        return gmdate('Y-m-d H:i:s', $earliest ?? ($midnight - $tz->getOffset($now)));
    }

    /**
     * 🔢 The two counts the limits are judged on, for one member and one duty.
     *
     *   `openWaiting` — open requests THIS member posted for THIS duty that
     *                   are still waiting. Only theirs: a request left by
     *                   somebody who held the duty before cannot be withdrawn
     *                   by this member, so it must not block them.
     *   `sentToday`   — every request this member made today in this
     *                   organisation, named or open, whatever has become of
     *                   it since: a withdrawn or answered request was still
     *                   sent, and its e-mails still went.
     *
     * A row with no `createdAtUtc` (made before migration 298) is not counted:
     * on the day of the upgrade a member's earlier requests that day are
     * missed, once.
     *
     * @return array{openWaiting:int, sentToday:int}
     */
    public static function limitFacts(\mysqli $db, int $siteId, int $slotId, int $askerId): array
    {
        $open = $db->prepare(
            'SELECT COUNT(*) AS `n` FROM `tblRotaSwapRequest` '
            . "WHERE `slotID` = ? AND `requestedByID` = ? AND `targetUserID` IS NULL AND `status` = 'pending'"
        );
        $open->bind_param('ii', $slotId, $askerId);
        $open->execute();
        $openWaiting = (int) ($open->get_result()->fetch_assoc()['n'] ?? 0);
        $open->close();

        $since = self::dayStartUtc($siteId);
        $day   = $db->prepare(
            'SELECT COUNT(*) AS `n` FROM `tblRotaSwapRequest` `w` '
            . 'JOIN `tblRotaSlot` `s` ON `s`.`slotID` = `w`.`slotID` AND `s`.`siteID` = ? '
            . 'WHERE `w`.`requestedByID` = ? AND `w`.`createdAtUtc` >= ?'
        );
        $day->bind_param('iis', $siteId, $askerId, $since);
        $day->execute();
        $sentToday = (int) ($day->get_result()->fetch_assoc()['n'] ?? 0);
        $day->close();

        return ['openWaiting' => $openWaiting, 'sentToday' => $sentToday];
    }

    /**
     * 🚧 Would a request go over a limit? '' when it would not.
     *
     * It takes FACTS, so the swap page — which asks before drawing the form,
     * to say so before anybody types — and `request()` — which asks again on
     * counts read under its locks — apply one rule (#647). The per-day limit
     * is asked first: when it is reached no request at all can go, which is
     * the more useful thing to be told.
     *
     * @param bool                                     $open   True for an open request.
     * @param array{openWaiting:int, sentToday:int}    $facts  From `limitFacts()`.
     * @param array{openPerDuty:int, perDay:int}       $limits From `limits()`.
     */
    public static function whyOverLimits(bool $open, array $facts, array $limits): string
    {
        if ($facts['sentToday'] >= $limits['perDay']) {
            return self::OVER_PER_DAY;
        }
        if ($open === true && $facts['openWaiting'] >= $limits['openPerDuty']) {
            return self::OVER_OPEN_PER_DUTY;
        }

        return '';
    }

    /**
     * Save a new request, judged against the two limits UNDER A LOCK (#989).
     * The caller has already asked `whyNotTarget()` and checked that the duty
     * is the asker's.
     *
     * WHY THE LOCKS. Counting and then inserting is two steps, so two posts
     * at once (a double press, or two windows) could both count 9 under a
     * limit of 10 and both insert. The transaction is opened FIRST (#697) and
     * then two rows are locked, always in this order so two savers can never
     * deadlock: the ASKER's own `tblUsers` row, which serialises every request
     * one member makes (the per-day count), and the DUTY's row, which
     * serialises the open requests for one duty. The counts are read after
     * both locks, so the second post waits and then counts the first.
     *
     * @return array{swapID:int, reason:string, made:int, limit:int}
     *         `swapID` is the new request's number, 0 when none was made.
     *         `made` and `limit` are the count and the limit a limit refusal
     *         was judged on (both 0 otherwise), for `overLimitWords()`.
     *         `reason` is '' when made; a limit code (`OVER_*`) when refused;
     *         `FAILED` when the database refused something before the commit
     *         (nothing was saved); `UNKNOWN` when the commit itself failed, so
     *         the request MAY have been saved — never worded as "not sent".
     */
    public static function request(\mysqli $db, int $siteId, int $slotId, int $askerId, int $targetUserId, string $message): array
    {
        $target = $targetUserId > 0 ? $targetUserId : null;
        // The column holds 500 characters; the form says so too. Cut on a
        // character, never a byte, so a name in another alphabet is not split.
        $message    = mb_substr(trim($message), 0, 500, 'UTF-8');
        $mayHaveCommitted = false;
        try {
            $db->begin_transaction();
            // 🔒 BOTH locks are load-bearing, and each is pinned by its own
            //    two-process test in tools/rota-swaps-selftest.php: the duty's
            //    by S16-S17 (two open requests for ONE duty), the asker's by
            //    S34-S35 (two posts for two DIFFERENT duties at 9 of 10 a day,
            //    where the duty lock cannot help). Do not turn either into a
            //    plain read: a plain SELECT is a consistent read, so the first
            //    one in the transaction FIXES its snapshot before the locks are
            //    waited for, and the counts read afterwards would not see what
            //    the other post committed meanwhile — measured: with the asker's
            //    lock a plain read, S17 goes red.
            foreach (
                [
                    ['SELECT `userID` FROM `tblUsers` WHERE `userID` = ? FOR UPDATE', $askerId],
                    ['SELECT `slotID` FROM `tblRotaSlot` WHERE `slotID` = ? FOR UPDATE', $slotId],
                ] as [$sql, $id]
            ) {
                $lock = $db->prepare($sql);
                $lock->bind_param('i', $id);
                $lock->execute();
                $lock->get_result()->free();
                $lock->close();
            }

            $facts  = self::limitFacts($db, $siteId, $slotId, $askerId);
            $limits = self::limits();
            $why    = self::whyOverLimits($target === null, $facts, $limits);
            if ($why !== '') {
                $db->rollback();
                $perDay = $why === self::OVER_PER_DAY;

                return [
                    'swapID' => 0,
                    'reason' => $why,
                    'made'   => $perDay === true ? $facts['sentToday'] : $facts['openWaiting'],
                    'limit'  => $perDay === true ? $limits['perDay'] : $limits['openPerDuty'],
                ];
            }

            $stmt = $db->prepare(
                'INSERT INTO `tblRotaSwapRequest` (`slotID`, `requestedByID`, `targetUserID`, `requestMessage`, `createdAtUtc`) '
                . 'VALUES (?, ?, ?, ?, UTC_TIMESTAMP())'
            );
            $stmt->bind_param('iiis', $slotId, $askerId, $target, $message);
            $stmt->execute();
            $newId = (int) $db->insert_id;
            $stmt->close();

            $mayHaveCommitted = true;
            $db->commit();

            return ['swapID' => $newId, 'reason' => '', 'made' => 0, 'limit' => 0];
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable $ignored) {
                // The connection has gone; the server discards the transaction.
            }

            return ['swapID' => 0, 'reason' => $mayHaveCommitted === true ? self::UNKNOWN : self::FAILED, 'made' => 0, 'limit' => 0];
        }
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
     * It never throws for a database fault. A fault BEFORE the commit is
     * rolled back and answers FAILED (nothing changed: a rollback that itself
     * fails means the connection has gone, and the server then discards the
     * uncommitted transaction). A fault AT the commit answers UNKNOWN, because
     * the server may have committed and only the reply been lost.
     *
     * `$openAllowed` is the organisation's `rota.allow_open_swap` setting,
     * passed in by the caller (see `whyNotAllowed()`).
     *
     * WHAT IT ANSWERS: what THIS answer did, as established inside its own
     * locked transaction — the outcome, the answer given (`action`), who
     * asked (`askerID`, for the e-mail's recipient) and the name of whoever
     * answered (`answererName`, read inside the transaction; on an accept the
     * duty was moved to exactly that person, so it is the name of who now
     * took it). Every message and e-mail about the answer is built from these
     * facts and nothing read afterwards (#657 round 4).
     *
     * @return array{outcome:string, reason:string, slotID:int, action:string, askerID:?int, answererName:string}
     */
    public static function respond(\mysqli $db, int $siteId, int $swapId, int $userId, string $action, string $responseMessage, bool $openAllowed): array
    {
        $response   = mb_substr(trim($responseMessage), 0, 500, 'UTF-8');
        $began      = false;
        $committing = false;
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

            // ⚠️ FROM HERE ON a failure is NOT "nothing changed": the server
            //    may commit and the reply be lost (#649's shape).
            $committing = true;
            if ($db->commit() === false) {
                throw new \RuntimeException('commit answered false');
            }

            return [
                'outcome'      => self::DONE,
                'reason'       => '',
                'slotID'       => $slotId,
                'action'       => $action,
                'askerID'      => $facts['requestedByID'],
                // On an accept the duty was moved to exactly this person in
                // this transaction (the person asked, or whoever took an open
                // request), so this is the name of who took it.
                'answererName' => $answererName,
            ];
        } catch (\Throwable $e) {
            if ($committing === true) {
                // The commit itself failed or its reply was lost: whether the
                // answer was stored is not known, and is not guessed at.
                //
                // ⚠️ ROLL BACK BEFORE LOGGING — a PRECAUTION. It matters only
                //    when commit() fails on a connection that is STILL USABLE
                //    with the transaction still open (section X of the
                //    self-test injects that state): `Logger` may write on this
                //    same connection, and without the rollback the log row
                //    would land inside the open transaction and vanish when
                //    the connection closed (#694 round 3's shape).
                //    It is NOT what saves the record after a real dropped
                //    connection — measured on MySQL 8.0.36, there commit(),
                //    this rollback and the log INSERT all fail with 2006, the
                //    server has already discarded the uncommitted write, and
                //    `Logger` sends the line to its fallback instead of
                //    tblErrors; that was true before this rollback existed.
                //    A rollback never undoes a commit that did happen, so it
                //    cannot make anything worse; it does not make the outcome
                //    KNOWN either, so the answer stays UNKNOWN.
                try {
                    $db->rollback();
                } catch (\Throwable $ignored) {
                    // The connection has gone; the server has discarded
                    // anything uncommitted, and Logger falls back.
                }
                // The request's NUMBER is logged (a number names nobody,
                // D-138), so an administrator can look the request up.
                Logger::errorPlatform('Rota', 'Warning', 'SWAP_RESPOND_UNKNOWN', 'Rota swap request #' . $swapId . ': an answer may or may not have been stored — the commit did not confirm', '');

                return self::didNothing(self::UNKNOWN, '', 0);
            }
            if ($began === true) {
                try {
                    $db->rollback();
                } catch (\Throwable $ignored) {
                    // The connection has gone; the server discards the
                    // uncommitted transaction for us, so nothing was stored.
                }
            }
            // The fault's own words are not logged: they can quote a value.
            Logger::errorPlatform('Rota', 'Warning', 'SWAP_RESPOND', 'Rota swap request #' . $swapId . ': an answer was not stored — it failed before the commit and was rolled back', '');

            return self::didNothing(self::FAILED, '', 0);
        }
    }

    /** What `respond()` answers when it did NOTHING it can stand behind (refused, failed or unknown). */
    private static function didNothing(string $outcome, string $reason, int $slotId): array
    {
        return ['outcome' => $outcome, 'reason' => $reason, 'slotID' => $slotId, 'action' => '', 'askerID' => null, 'answererName' => ''];
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
     * `whereDutyIsNow()` for why). The rest is read here: the duty's role and
     * date and the asker's address, which no answer changes, the asker's
     * message, and the reply (`responseMessage`) — which THIS answer has just
     * written, so reading it back gives the reply as stored: what the
     * answerer typed, trimmed and cut to 500 characters by `respond()`.
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
