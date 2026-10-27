<?php
// Path: _core/Sms.php
/**
 * -----------------------------------------------------------------------------
 * Text messages (SMS): sending, holding for quiet hours, and the provider 📱
 * -----------------------------------------------------------------------------
 * Sends a text through the provider the installation has chosen (Twilio,
 * MessageBird or AWS SNS), and records every text in tblSmsMessage, sent or
 * not, so the cost and the history can be read afterwards.
 *
 *   sms.provider = twilio | messagebird | aws
 *
 * Text messages cost the customer money and land on people's phones. So:
 *   - nothing is sent while texts are switched off (`sms.enabled`);
 *   - nothing is sent once the day's limit is reached (`sms.dailyCap`);
 *   - a text that is not urgent is NOT sent to a person who is inside
 *     their Sabbath quiet hours. It is HELD, and sent once those quiet
 *     hours have ended, or recorded as not sent when it is no longer worth
 *     sending. See "A TEXT HELD FOR QUIET HOURS" below.
 *   - TWO KINDS ARE NEVER HELD (NEVER_HELD): a critical alert, which
 *     includes the code that confirms a number, and an emergency notice.
 *     The owner (Salem874) decided the second on 13 October 2026: "Never
 *     hold an emergency text." It was first built as a held kind with two
 *     hours to live; a notice about this morning that arrives tomorrow
 *     evening, or not at all, is not what an emergency notice is for.
 *
 * A TEXT HELD FOR QUIET HOURS (#610)
 *   Until 13 October 2026 a held text was recorded as "queued" with the
 *   reason "quiet-hours", and NOTHING EVER LOOKED AT THE ROW AGAIN: the text
 *   was dropped while its record said it was waiting. Now:
 *
 *   held      send() records it as status 'queued' with HELD_REASON in
 *             `errorMsg`, an empty `sentAt` (it has not been sent) and a
 *             use-by moment in `heldUseBy`.
 *   sent      sendHeld(), which the scheduled job calls
 *             (`_apps/cron/mail-queue.php`), asks Sabbath, the same code that
 *             decided to hold it, whether the person's quiet hours have
 *             ended. When they have, it takes the row in ONE statement,
 *             hands the text to the provider, and records what the provider
 *             said. A row is taken once and never taken again.
 *   not sent  a held text whose use-by moment has passed is recorded as
 *             'failed', with the reason in plain words that begin "Not
 *             sent: quiet hours". So is a text held before this was built
 *             (it has no use-by moment), and one whose person should not
 *             get it when it comes due: their account has been switched
 *             off, they have changed their number, or they have stopped
 *             texts of that kind. WHERE THERE ARE SEVERAL ORGANISATIONS
 *             two more things are asked: that the person is a member of
 *             the organisation, and that it has not been switched off.
 *             With one organisation neither is asked (see
 *             whyNotThisPerson() for why).
 *
 *   NEVER TWICE comes before "always delivered". If a run is ended while it
 *   is handing a text to the provider, nobody can tell whether the text
 *   went. That text is recorded as "not known whether it was sent" and is
 *   NOT sent again: a missed text costs less than a second one.
 *
 * TIMES
 *   `heldUseBy` and `claimedAt` are MOMENTS IN UTC, written by the
 *   database's own clock (`UTC_TIMESTAMP()`) in the statement that stores
 *   them, and every length is a number of exact seconds added by the
 *   database. "Is this person in their quiet hours?" is asked of Sabbath for
 *   a moment read from that same clock (nowOnDatabaseClock()), both when a
 *   text is held and when it is sent. Sabbath works its window out on a
 *   clock in the zone the Sabbath settings name, so the answer stays right
 *   on the two nights a year the clocks change.
 *   `createdAt` and `sentAt` are older than all this and are written by the
 *   database server's OWN clock (not UTC), as they always were: every row
 *   from before migration 219 holds them that way. The daily limit counts a
 *   day on that clock.
 *
 * WHAT THIS CANNOT DO
 *   - Send a held text while no scheduled job is running. Only the job
 *     (`/cron/mail-queue`) sends held texts. With no job a held text waits
 *     until its use-by moment and is then recorded as not sent, the next
 *     time the portal handles any text. A text that a run had taken, when
 *     that run died and no run followed, is recorded then as well, as "not
 *     known whether it was sent". (This note used to say only the first
 *     half, and until the fix round of 13 October 2026 only the job
 *     recorded the second: with no job such a text read "queued" for
 *     ever.) Until the portal handles another text, or the job runs, both
 *     go on reading "queued".
 *   - Know whether the NUMBER still belongs to the person, for a text sent
 *     at once. That is whyNoLongerWanted(), and it is asked only of a held
 *     text, where up to two days have passed and the number may have moved
 *     on. A text sent now goes to the number its caller has just read.
 *
 *   THIS LIST USED TO SAY the class could not ask, of a text sent AT ONCE,
 *   whether the person's account or organisation had been switched off. It
 *   asks now (#610, the owner's decision of 26 October 2026): send() puts the
 *   same question as sendHeld(), through whyNotThisPerson(), to every text
 *   that names a person. A person is judged at the moment their text would
 *   go -- now for a text going now, and when it comes due for a held one.
 *   - Know whether a text was DELIVERED, only whether the provider took it.
 *   - Read a text. Whether a late text is still true is judged from its
 *     KIND (see HELD_USE_WITHIN), or from a use-by moment its caller gives.
 *     A text typed by an administrator and sent as a kind that "keeps" is
 *     sent late even if its own words were about that morning.
 *   - Give each organisation its own quiet hours. Sabbath reads one window
 *     from the settings of the request in hand, and those settings are
 *     saved for the whole installation.
 *   - Stop the day's limit being passed by one text when two things send
 *     at the same moment: two ordinary requests, or a run of the job and an
 *     ordinary request. Only two RUNS OF THE JOB cannot do it, because they
 *     share a lock. (This note used to name the two requests only.)
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/272
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/610
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

class Sms
{
    public const CATEGORIES = [
        'critical_alerts',
        'rota_changes',
        'emergency_comms',
        'newsletter_digest',
        // 🙏 Prayer-partner assignment pings (#311) — opt-in via /account/sms
        // like every other category; PrayerChain::notifyAssignment() checks
        // this via FIND_IN_SET before calling Sms::send().
        'prayer_assignment',
    ];

    // =========================================================================
    // 🕯️ A text held for quiet hours: its mark, its use-by moment, its words
    // =========================================================================

    /**
     * @var string The mark of a HELD text: status 'queued' with exactly this
     *      in `errorMsg`. Texts held before migration 219 carry it too,
     *      which is how they are found and recorded as not sent.
     */
    public const HELD_REASON = 'quiet-hours';

    /**
     * 🚨 THE KINDS OF TEXT THAT QUIET HOURS NEVER HOLD. Each is sent at
     * once, whatever the hour, to a person who has asked for that kind.
     *
     *   critical_alerts  as it always was. The code that confirms a number
     *                    is sent as this kind, and runs out in ten minutes.
     *   emergency_comms  the owner's decision of 13 October 2026 (Salem874):
     *                    "Never hold an emergency text." See the file header.
     *
     * This list is the ONE place that decides it: send() asks nothing else.
     * Adding a kind here means people get it during their quiet hours, so
     * it is a decision for the owner, not a tidy-up.
     *
     * @var list<string>
     */
    public const NEVER_HELD = ['critical_alerts', 'emergency_comms'];

    /**
     * ⏳ HOW LONG A HELD TEXT STAYS WORTH SENDING, by its kind, in exact
     * seconds from the moment it was held. A caller that knows better gives
     * its own use-by moment to send().
     *
     * Quiet hours last about a day, so a held text arrives up to a day late.
     * Every kind listed here KEEPS that long (a prayer request to pray for,
     * a newsletter digest, a change to a rota on a named day). It is sent
     * when the quiet hours end, and never more than 48 hours after it was
     * written: the same "nothing older than 48 hours is sent" the owner
     * (Salem874) chose for e-mail on 12 October 2026.
     *
     * The two kinds in NEVER_HELD are not here because they are never held.
     * ('emergency_comms' was here, with two hours, until the owner decided
     * it is never held.)
     *
     * REJECTED: 48 hours for every kind, listed or not. A kind nobody has
     * thought about may say something about this morning, and would then
     * arrive tomorrow evening: see HELD_USE_WITHIN_UNKNOWN.
     *
     * @var array<string, int>
     */
    private const HELD_USE_WITHIN = [
        'rota_changes'      => 172800,
        'newsletter_digest' => 172800,
        'prayer_assignment' => 172800,
    ];

    /**
     * @var int For a kind that is not listed above ('general', or one added
     *      later and not yet thought about): the portal knows nothing about
     *      what such a text says, so it is treated as being about NOW. It
     *      is sent only if the person's quiet hours end within two hours of
     *      its being written.
     */
    private const HELD_USE_WITHIN_UNKNOWN = 7200;

    /**
     * @var int The longest any held text may wait, whatever its caller
     *      asked for: 48 hours, in exact seconds. It is what stops a job
     *      that was away for a week from sending a week of texts when it
     *      comes back.
     */
    public const HELD_NEVER_AFTER = 172800;

    /**
     * @var int How old a claim must be before the run that made it is taken
     *      for dead: ten minutes. A provider is given up on after 15
     *      seconds, so a live run never leaves a claimed row this long.
     */
    public const HELD_CLAIM_DEAD_SECONDS = 600;

    // 🗣️ What is written in `errorMsg` when a held text moves on. Plain
    //    words, because this column is the only place that says WHY. Each
    //    fits the column (255 characters): the connection is strict, so one
    //    that did not fit would be an error and not a cut-off sentence.
    public const SENT_AFTER_HOLD     = 'Held for quiet hours, then sent when they had ended.';
    public const NOT_SENT_USE_BY     = 'Not sent: quiet hours. It was held, and its use-by moment passed before it could be sent.';
    public const NOT_SENT_OLD        = 'Not sent: quiet hours. It was held before the portal could send a held text afterwards, so it has no use-by moment and is never sent.';
    public const NOT_SENT_NOT_KEPT   = 'Not sent: quiet hours. It could not be kept for later: its use-by moment could not be read, or had already passed.';
    public const NOT_SENT_NO_ROOM    = 'Not sent: quiet hours. It could not be kept for later: the database has not been upgraded to hold a use-by moment.';
    public const NOT_SENT_PERSON     = 'Not sent: quiet hours. When they ended, the person no longer had an account.';
    public const NOT_SENT_ACCOUNT_OFF = 'Not sent: quiet hours. When they ended, the person\'s account had been switched off.';
    // ("was not a member", not "was no longer a member": a person with a
    // confirmed number and no membership may never have been one.)
    public const NOT_SENT_NOT_MEMBER = 'Not sent: quiet hours. When they ended, the person was not a member of this organisation.';
    public const NOT_SENT_ORG_OFF    = 'Not sent: quiet hours. When they ended, the organisation had been switched off.';
    public const NOT_SENT_NUMBER     = 'Not sent: quiet hours. When they ended, this number was not the person\'s confirmed number for texts.';
    public const NOT_SENT_OPTED_OUT  = 'Not sent: quiet hours. When they ended, the person no longer wanted texts of this kind.';
    // 🚫 THE SAME FOUR REASONS, FOR A TEXT THAT IS SENT AT ONCE (#610).
    //    The NOT_SENT_* sentences above are written for a HELD text coming
    //    due, so each begins "Not sent: quiet hours." and says "When they
    //    ended, ...". A text that was never held has no quiet hours to speak
    //    of and no "when they ended": using those words would record
    //    something untrue. These say the same four things in the present
    //    tense. whyNotThisPerson() decides WHICH of the four applies; these
    //    only put it into words, so there is still exactly one place that
    //    decides who may be sent a text.
    public const AT_ONCE_PERSON      = 'Not sent: the person no longer has an account.';
    public const AT_ONCE_ACCOUNT_OFF = 'Not sent: the person\'s account has been switched off.';
    public const AT_ONCE_NOT_MEMBER  = 'Not sent: the person is not a member of this organisation.';
    public const AT_ONCE_ORG_OFF     = 'Not sent: the organisation has been switched off.';

    public const NOT_SENT_RESTORED   = 'Not sent: quiet hours. It came back with a restored backup: it was waiting when the backup was taken and may already have been sent since, so it is not sent again.';
    public const UNKNOWN_INTERRUPTED = 'Not known whether it was sent: a run of the scheduled job was ended while handing this held text to the provider. It is not sent again.';

    /** @var string What became of the last text given to send(): 'off', 'sent', 'failed', 'held' or 'not-sent'. */
    private static string $lastOutcome = '';

    /** @var bool Whether this request has already corrected the record of held texts that can no longer go (see send()). */
    private static bool $settledInRequest = false;

    /**
     * Send a text to one number, now, or hold it for the person's quiet hours.
     *
     * Every text that gets as far as the day's limit is recorded in
     * tblSmsMessage, whatever becomes of it.
     *
     * THE ORDER IS DELIBERATE: switched off, then the day's limit, then quiet
     * hours. The limit comes first, as it always did: once it is reached
     * nothing more is taken that day, held or not.
     *
     * @param string|null $useBy For a text that ends up HELD: the moment
     *        after which it must not be sent, as a moment in UTC,
     *        'Y-m-d H:i:s' (MailQueue::useBy() makes one from a time on a
     *        clock and a zone). Null uses the length for its kind
     *        (HELD_USE_WITHIN). It is never later than 48 hours from now.
     *        A value that cannot be read, or has already passed, means the
     *        text is NOT kept: it is recorded as not sent. It makes no
     *        difference to a text that is sent at once.
     *
     * @return bool True only when the provider took the text NOW. False for
     *         a held text too: lastOutcome() tells them apart.
     */
    public static function send(int $siteId, string $number, string $body, string $category = 'general', ?int $userId = null, ?string $useBy = null): bool
    {
        $settings = App::settings()['sms'] ?? [];
        if ((string) ($settings['enabled'] ?? '0') !== '1') {
            self::$lastOutcome = 'off';

            return false;
        }

        // 🧹 Once a request: correct the record of any held text that can no
        //    longer go, a text that a dead run had taken included. The
        //    scheduled job does this at every run. It is done here as well
        //    so that, on an installation with NO job, a text does not go on
        //    reading "queued" for ever, which was the fault.
        if (self::$settledInRequest === false) {
            self::$settledInRequest = true;
            self::settleHeld(App::db(), 200, false);
        }

        // Daily cap — block before the wire to avoid runaway cost.
        //   The last argument is false: this text was never handed to the
        //   provider, so it has no "sent at". It used to be stamped with one,
        //   which the list of recent texts then showed as if it were a sending
        //   time -- and that list's own comment says such a row cannot exist.
        //   Found by the independent check of this package, in a line this
        //   package did not write.
        if (self::sentTodayCount($siteId) >= (int) ($settings['dailyCap'] ?? 100)) {
            self::record($siteId, $userId, $number, $body, $category, 'failed', null, null, 'daily-cap', false);
            self::$lastOutcome = 'failed';

            return false;
        }

        // 🕯️ Quiet hours: a text is held for a person who is inside theirs,
        //    unless it is of a kind that is never held (NEVER_HELD: a
        //    critical alert, an emergency notice). A person who has switched
        //    quiet hours off for themselves is never "inside" (Sabbath
        //    decides). The moment asked about comes from the database's
        //    clock: see nowOnDatabaseClock().
        if (in_array($category, self::NEVER_HELD, true) === false && $userId !== null && Sabbath::isQuietNow($userId, self::nowOnDatabaseClock(App::db())) === true) {
            self::$lastOutcome = self::hold($siteId, $userId, $number, $body, $category, $useBy) === true ? 'held' : 'not-sent';

            return false;
        }

        // 🚫 MAY THIS PERSON BE SENT THIS ORGANISATION'S TEXT, NOW?
        //    Asked here, AFTER the decision to hold, so it is asked only of a
        //    text that is about to go. A text that has been held is asked the
        //    same question by sendHeld() when it comes DUE, which is the more
        //    accurate moment, and this must not pre-empt it.
        //
        //    THIS WAS BUILT THE OTHER WAY ROUND FIRST, and the reason given
        //    was wrong. The comment said holding a text for somebody who may
        //    not be sent one "would only put off recording the same answer by
        //    up to two days". It would not: the held path asks when the text
        //    comes due, not when it was written. The independent check ran the
        //    case and measured the cost -- an account switched off on the
        //    Friday evening and switched back on the Saturday morning. With
        //    the question asked first, the text was refused outright. With it
        //    asked here, the text is held on Friday, the person is asked again
        //    on Saturday when it comes due, they are allowed by then, and the
        //    text IS delivered. The first order silently lost a text that the
        //    portal used to deliver. A suspension lifted inside two days is an
        //    ordinary thing, not a strange one.
        //
        //    SO THE RULE IS: judge a person at the moment their text would go.
        //    For a text going now, that is here. For a held text, that is when
        //    it comes due. Both ask the same one question
        //    (whyNotThisPerson()); only the words differ.
        //
        //    WHY IT IS ASKED AT ALL (#610, the owner's decision of 26 October
        //    2026). Until now only a HELD text was asked. A text sent AT ONCE
        //    was never asked, so a broadcast or a prayer-chain text could go
        //    to somebody whose account had been switched off, or who had been
        //    removed from the organisation, for as long as their number sat in
        //    the preferences table. Of the three callers only the prayer
        //    chain's own choice of a partner asked anything.
        //
        //    IT IS THE SAME RULE, NOT A SECOND COPY. whyNotThisPerson() is the
        //    one place that decides; only the words differ (AT_ONCE_* rather
        //    than the NOT_SENT_* sentences, which are written for a held text
        //    coming due).
        //
        //    WITH NO PERSON NAMED there is nobody to ask about: a caller that
        //    gives only a number has chosen the number itself, and the portal
        //    has no account to judge. Such a text is sent as before.
        //
        //    THIS APPLIES TO EVERY KIND. The NEVER_HELD kinds (a critical
        //    alert, an emergency notice, the code that confirms a number) are
        //    never held, so they always reach this line, and a person whose
        //    account has been switched off is not sent one.
        //
        //    WHAT IT COSTS: one lookup per text, and a second for the
        //    membership where there are several organisations. Measured by the
        //    independent check at 0.079 ms and 0.090 ms on MySQL 8.0.36 --
        //    about 68 ms on a 400-recipient broadcast, against 400 calls to
        //    the provider.
        //
        //    WHAT IT CANNOT DO: it cannot tell whether the number still
        //    belongs to that person. That is whyNoLongerWanted(), and it is
        //    asked only of a held text, where up to two days have passed. A
        //    text sent now goes to the number its caller has just read.
        if ($userId !== null) {
            $why = self::whyNotThisPerson(App::db(), $siteId, $userId);
            if ($why !== '') {
                // No "sent at": it was never handed to the provider. And no
                // person named when the account has gone, because the record
                // points at tblUsers (fk_sms_user, ON DELETE SET NULL).
                self::record(
                    $siteId,
                    $why === 'no-account' ? null : $userId,
                    $number,
                    $body,
                    $category,
                    'failed',
                    null,
                    null,
                    self::atOncePersonWords($why),
                    false
                );
                self::$lastOutcome = 'not-sent';

                return false;
            }
        }

        $provider = (string) ($settings['provider'] ?? 'twilio');
        $result = static::providerSend($provider, $settings, $number, $body);

        $status = $result['ok'] === true ? 'sent' : 'failed';
        self::record($siteId, $userId, $number, $body, $category, $status, $result['ref'], $result['costPence'], $result['error']);
        self::$lastOutcome = $status;

        return $result['ok'];
    }

    /**
     * What became of the last text given to send(): 'off' (texts are
     * switched off, nothing recorded), 'sent', 'failed' (the day's limit, or
     * the provider refused), 'held' (waiting for the person's quiet hours to
     * end) or 'not-sent' -- which now means either of two things: the person
     * may not be sent this organisation's text at all (their account or the
     * organisation is switched off, or they are not a member: see send()), or
     * it was quiet hours and the text could not be kept for later.
     * '' before any text has been sent in this request.
     */
    public static function lastOutcome(): string
    {
        return self::$lastOutcome;
    }

    /**
     * Send to every opted-in, verified subscriber for a category on this site.
     *
     * @param string|null $useBy Passed to send() for each text: see there.
     *
     * @return array{sent: int, skipped: int, held: int} `skipped` is every
     *         text that did not go NOW, as it always was, so it INCLUDES the
     *         held ones. `held` says how many of those are waiting for the
     *         person's quiet hours to end.
     */
    public static function sendCategory(int $siteId, string $category, string $body, ?string $useBy = null): array
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT userID, phoneNumber FROM tblUserSmsPreference '
            . 'WHERE siteID = ? AND isVerified = 1 AND FIND_IN_SET(?, categories) > 0'
        );
        $sent = 0;
        $skipped = 0;
        $held = 0;
        if ($stmt !== false) {
            $stmt->bind_param('is', $siteId, $category);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_assoc()) {
                $ok = self::send($siteId, (string) $r['phoneNumber'], $body, $category, (int) $r['userID'], $useBy);
                if ($ok === true) {
                    $sent++;
                } else {
                    $skipped++;
                    if (self::$lastOutcome === 'held') {
                        $held++;
                    }
                }
            }
            $stmt->close();
        }
        return ['sent' => $sent, 'skipped' => $skipped, 'held' => $held];
    }

    /**
     * 🗣️ What the broadcast page tells the administrator when a broadcast
     * has been handed to sendCategory(): how many texts went, how many are
     * held, and how many were skipped.
     *
     * It is here, and not in the page, so that it can be tested
     * (tools/held-texts-selftest.php), because a wrong sentence here costs
     * money: the page used to say "N sent, M skipped" with the held texts
     * counted as skipped. That was true while a held text was never sent.
     * Since #610 it is sent when the person's quiet hours end, and an
     * administrator told "skipped" may well send the broadcast again, so
     * that people get it twice.
     *
     * Only the scheduled job sends a held text, and ALL THIS CAN KNOW is
     * whether that job has run in the last 15 minutes
     * (MailQueue::jobIsRunning() means that and no more).
     *   - It has: the sentence says the portal sends the held texts itself,
     *     so the broadcast must not be sent again.
     *   - It has not: the sentence says so, says what a held text needs in
     *     order to be sent, and says where to look before sending again. It
     *     does NOT say the texts will not be sent. A job that runs once an
     *     hour, a job that is late, and a job set up tomorrow all send them,
     *     as long as they run before the texts are two days old.
     * REJECTED, and built first: "(and NOT going to be sent afterwards,
     * because the portal's scheduled job is not running)". The second
     * check ran exactly those three cases, and each time the job's next
     * call handed every held text over. An administrator told "not going
     * to be sent" may send the broadcast again, and people get two texts:
     * the very thing this sentence exists to stop.
     *
     * @param array{sent?: int, skipped?: int, held?: int} $result       What sendCategory() answered.
     * @param bool                                         $jobIsRunning MailQueue::jobIsRunning(): whether the job has run in the last 15 minutes.
     */
    public static function broadcastSummary(array $result, bool $jobIsRunning): string
    {
        $sent    = (int) ($result['sent'] ?? 0);
        $held    = (int) ($result['held'] ?? 0);
        // `skipped` has always been "did not go now", so it includes the held.
        $skipped = max(0, (int) ($result['skipped'] ?? 0) - $held);
        $words   = 'Broadcast complete: ' . $sent . ' sent';
        if ($held > 0) {
            $words .= ', ' . $held . ' held for quiet hours';
            $words .= $jobIsRunning === true
                ? ' (the portal sends ' . ($held === 1 ? 'it' : 'them') . ' itself when the person\'s quiet hours end, so do not send the broadcast again)'
                : ' (the portal\'s scheduled job, which sends ' . ($held === 1 ? 'it' : 'them') . ', has not run in the last 15 minutes:'
                    . ' a held text is sent only if that job runs before the text is two days old,'
                    . ' so look at Recent messages before sending the broadcast again)';
        }

        return $words . ', ' . $skipped . ' skipped.';
    }

    /**
     * How many texts this organisation has SENT today (used by the daily cap).
     *
     * Counted by the moment a text was sent (`sentAt`), not the moment it
     * was written (`createdAt`). For a text sent at once the two are the
     * same. For a text held yesterday and sent today they are not, and it
     * used to be counted against yesterday: a morning of held texts would
     * then have gone out on top of today's full limit.
     *
     * "Today" is a day on the database server's own clock, as it always
     * was, because `sentAt` is written by that clock.
     */
    public static function sentTodayCount(int $siteId): int
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM tblSmsMessage WHERE siteID = ? '
            . "AND status IN ('sent','delivered') AND DATE(COALESCE(sentAt, createdAt)) = CURDATE()"
        );
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $stmt->bind_result($n);
        $stmt->fetch();
        $stmt->close();
        return (int) $n;
    }

    /**
     * What this organisation's texts have cost so far this month, in pence.
     *
     * By the moment each text was sent, for the reason given at
     * sentTodayCount(): a text held on the last day of a month and sent on
     * the first costs money in the month it went.
     */
    public static function monthSpendPence(int $siteId): int
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT COALESCE(SUM(costPence), 0) FROM tblSmsMessage '
            . "WHERE siteID = ? AND COALESCE(sentAt, createdAt) >= DATE_FORMAT(NOW(), '%Y-%m-01')"
        );
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $stmt->bind_result($s);
        $stmt->fetch();
        $stmt->close();
        return (int) $s;
    }

    /**
     * Generate + store a 6-digit verification code (10-min lifetime),
     * then send it to the number. Returns true on dispatch success.
     */
    public static function startVerification(int $siteId, int $userId, string $number): bool
    {
        $code = (string) random_int(100000, 999999);
        $db = App::db();
        $stmt = $db->prepare(
            'INSERT INTO tblUserSmsPreference (siteID, userID, phoneNumber, verificationCode, verificationExpires) '
            . 'VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE)) '
            . 'ON DUPLICATE KEY UPDATE phoneNumber = VALUES(phoneNumber), '
            . '    isVerified = 0, '
            . '    verificationCode = VALUES(verificationCode), '
            . '    verificationExpires = VALUES(verificationExpires)'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('iiss', $siteId, $userId, $number, $code);
        $stmt->execute();
        $stmt->close();

        return self::send($siteId, $number, 'Your portal verification code is ' . $code . '. It expires in 10 minutes.', 'critical_alerts', $userId);
    }

    /**
     * Verify a user-entered code. Returns true on match (and flags row
     * verified, clears the code).
     */
    public static function completeVerification(int $siteId, int $userId, string $code): bool
    {
        $db = App::db();
        $stmt = $db->prepare(
            'UPDATE tblUserSmsPreference SET isVerified = 1, verificationCode = NULL, verificationExpires = NULL '
            . 'WHERE siteID = ? AND userID = ? AND verificationCode = ? '
            . 'AND verificationExpires IS NOT NULL AND verificationExpires >= NOW()'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('iis', $siteId, $userId, $code);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected > 0;
    }

    /**
     * Light E.164 normalisation: strip spaces, accept leading + or 0.
     * Caller stores the result; providers reject invalid numbers at send.
     */
    public static function normaliseNumber(string $raw): string
    {
        $cleaned = preg_replace('/[^0-9+]/', '', $raw);
        if ($cleaned === null || $cleaned === '') {
            return '';
        }
        if (str_starts_with($cleaned, '0') === true && str_starts_with($cleaned, '00') === false) {
            // Assume UK if dialled with leading 0 — caller can override later.
            $cleaned = '+44' . substr($cleaned, 1);
        }
        return $cleaned;
    }

    /**
     * Write one text's row.
     *
     * @param bool $stampSentAt False for a text that was stopped by quiet
     *        hours: its "sent at" is left empty, because it was not sent.
     *        (Every other row has always been given the moment it was
     *        recorded, the day's-limit refusal included; that is left as it
     *        was.)
     */
    private static function record(int $siteId, ?int $userId, string $number, string $body, string $category, string $status, ?string $providerRef, ?int $costPence, ?string $error, bool $stampSentAt = true): void
    {
        $db = App::db();
        $stmt = $db->prepare(
            'INSERT INTO tblSmsMessage (siteID, recipientUserID, recipientNumber, body, category, status, '
            . ' provider, providerRef, costPence, errorMsg, sentAt) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ' . ($stampSentAt === true ? 'NOW()' : 'NULL') . ')'
        );
        if ($stmt === false) {
            return;
        }
        $provider = (string) (App::settings()['sms']['provider'] ?? 'twilio');
        $stmt->bind_param(
            'iissssssis',
            $siteId, $userId, $number, $body, $category, $status, $provider, $providerRef, $costPence, $error
        );

        // 🧯 IF THE PERSON HAS GONE, KEEP THE RECORD AND DROP THE NAME.
        //    This row points at tblUsers (fk_sms_user), so naming somebody who
        //    has been deleted is REFUSED by the database -- and because this
        //    class reports errors as exceptions, that refusal used to end the
        //    whole request. One deleted account could therefore stop a
        //    broadcast part way through, losing every text after it.
        //    The key is ON DELETE SET NULL, so an empty person is exactly what
        //    the database expects for a record of somebody who is no longer
        //    there. The number, the words, the kind and the reason all still
        //    matter and are all still kept.
        //    IT IS DONE HERE, AND ONLY HERE, because this is the one place
        //    that writes such a row: every caller is covered at once and none
        //    has to remember. Checking first would cost a question of the
        //    database for every text ever sent, to guard against something
        //    that almost never happens; this costs nothing until it does.
        //    REJECTED: letting the caller decide. send()'s own gate does name
        //    no person when it knows the account has gone, but it is not the
        //    only caller, and the daily-limit refusal above it reached this
        //    line first with a name the database would not accept.
        try {
            $stmt->execute();
        } catch (\mysqli_sql_exception $refused) {
            // 1452 is "a row that does not exist is named". Anything else is
            // not this problem and must not be swallowed.
            if ($refused->getCode() !== 1452 || $userId === null) {
                $stmt->close();

                throw $refused;
            }
            $stmt->close();
            $stmt = $db->prepare(
                'INSERT INTO tblSmsMessage (siteID, recipientUserID, recipientNumber, body, category, status, '
                . ' provider, providerRef, costPence, errorMsg, sentAt) '
                . 'VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ' . ($stampSentAt === true ? 'NOW()' : 'NULL') . ')'
            );
            if ($stmt === false) {
                return;
            }
            // Nine values, the person left out: site, number, body, kind,
            // status, provider, reference, cost (a number), reason.
            $stmt->bind_param(
                'issssssis',
                $siteId, $number, $body, $category, $status, $provider, $providerRef, $costPence, $error
            );
            $stmt->execute();
        }
        $stmt->close();
    }

    // =========================================================================
    // 🕯️ Held for quiet hours: holding, and sending afterwards (#610)
    // =========================================================================

    /**
     * 🕰️ This moment, as seconds since 1970, from the DATABASE's clock.
     *
     * "Is this person in their quiet hours?" is asked for this moment, both
     * when a text is held and when it is sent, so one clock decides
     * everything about a held text: the use-by moment is the database's
     * too. UNIX_TIMESTAMP() with nothing in the brackets is an exact moment
     * and does not depend on the connection's time zone. REJECTED:
     * UNIX_TIMESTAMP(NOW()), which reads a time on a clock back into a
     * moment and is an hour out, for an hour, on the night the clocks go
     * back.
     *
     * If the database cannot be asked, PHP's clock is used: a text is never
     * stopped, or let through, because a clock could not be read.
     */
    private static function nowOnDatabaseClock(mysqli $db): int
    {
        try {
            $result = $db->query('SELECT UNIX_TIMESTAMP() AS nowEpoch');
            $now    = (int) ($result->fetch_assoc()['nowEpoch'] ?? 0);
            $result->free();
            if ($now > 0) {
                return $now;
            }
        } catch (\Throwable $ignored) {
            // See above.
        }

        return time();
    }

    /**
     * 🕯️ HOLD a text for a person who is inside their quiet hours.
     *
     * The use-by moment is worked out by the database, as "now plus a number
     * of exact seconds", in the statement that stores it. REJECTED: working
     * it out in PHP and storing the text. On the night the clocks change
     * that is an hour out wherever PHP is set to a zone that changes.
     *
     * @return bool True when it is held. False when it could not be kept
     *         for later; it is then recorded as not sent, with the reason.
     */
    private static function hold(int $siteId, int $userId, string $number, string $body, string $category, ?string $useBy): bool
    {
        $db     = App::db();
        $within = self::HELD_USE_WITHIN[$category] ?? self::HELD_USE_WITHIN_UNKNOWN;

        if ($useBy !== null) {
            // The caller's own use-by moment. How long is left until it, by
            // the database's clock? Text that is not a moment (which is what
            // MailQueue::useBy() answers when it cannot work one out) leaves
            // nothing to count, and a date that does not exist counts as
            // empty: either way the text is not kept. Kept without its
            // use-by moment, a "starts in an hour" text could go out a day
            // after the start.
            $left = null;
            if (preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $useBy) === 1) {
                $stmt = $db->prepare('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), ?) AS secondsLeft');
                $stmt->bind_param('s', $useBy);
                $stmt->execute();
                $left = $stmt->get_result()->fetch_assoc()['secondsLeft'] ?? null;
                $stmt->close();
            }
            if ($left === null || (int) $left <= 0) {
                self::record($siteId, $userId, $number, $body, $category, 'failed', null, null, self::NOT_SENT_NOT_KEPT, false);

                return false;
            }
            $within = (int) $left;
        }
        $within = min($within, self::HELD_NEVER_AFTER);

        try {
            $stmt = $db->prepare(
                'INSERT INTO tblSmsMessage (siteID, recipientUserID, recipientNumber, body, category, status, provider, errorMsg, sentAt, heldUseBy) '
                . "VALUES (?, ?, ?, ?, ?, 'queued', ?, ?, NULL, UTC_TIMESTAMP() + INTERVAL ? SECOND)"
            );
            $provider = (string) (App::settings()['sms']['provider'] ?? 'twilio');
            $reason   = self::HELD_REASON;
            $stmt->bind_param('iisssssi', $siteId, $userId, $number, $body, $category, $provider, $reason, $within);
            $stmt->execute();
            $stmt->close();
        } catch (\mysqli_sql_exception $problem) {
            // 1054 is "unknown column": the new files are in place and the
            // database has not been upgraded yet (migration 219), so there
            // is nowhere to keep a use-by moment. The text is NOT held
            // without one. It is recorded as not sent, in the columns the
            // table has always had. Any other fault is passed on, as a
            // failed record always was.
            if ((int) $problem->getCode() !== 1054) {
                throw $problem;
            }
            self::record($siteId, $userId, $number, $body, $category, 'failed', null, null, self::NOT_SENT_NO_ROOM, false);

            return false;
        }

        return true;
    }

    /**
     * 🔀 CORRECT THE RECORD of every held text that can no longer be sent.
     *
     *   taken more than ten minutes
     *   ago and never finished       -> "not known whether it was sent"
     *                                   (UNKNOWN_INTERRUPTED).
     *   held, with no use-by moment  -> not sent (NOT_SENT_OLD). It was held
     *                                   before migration 219, or came back
     *                                   with a backup older than that.
     *   held, past its use-by moment -> not sent (NOT_SENT_USE_BY).
     *
     * NOTHING HERE SENDS. Each row is found by an ordinary read and then
     * changed by its own number, in a statement that asks the same question
     * again, so a row that has moved on in between is left alone.
     *
     * ALL THREE, FOR EVERY CALLER. Until the fix round of 13 October 2026
     * only the scheduled job settled a text that a dead run had taken. On an
     * installation whose job never ran again such a text read "queued" for
     * ever: the same untrue record this whole change exists to end. Ten
     * minutes is far longer than a live run ever leaves a text taken, so
     * settling it from an ordinary request cannot cut across a run.
     *
     * REJECTED: one UPDATE for each kind ("... WHERE heldUseBy <= now"). It
     * is shorter, and it locks the stretch of the index it reads as well as
     * the rows. send() can be called inside its caller's own transaction;
     * two such requests at the same moment, each going on to store a held
     * text of its own, could then each wait for the other, and the database
     * ends one of them, undoing whatever that caller was in the middle of.
     * Changing rows by number takes a lock on those rows only, and nearly
     * always there are none.
     *
     * @param int  $most        The most rows of each kind to change.
     * @param bool $throwFaults True from the job's run, which reports a
     *                          fault itself. False from send(), where a
     *                          fault is swallowed: correcting an old record
     *                          must never stop a text that is being sent
     *                          now.
     *
     * @return int How many rows were changed.
     */
    private static function settleHeld(mysqli $db, int $most, bool $throwFaults): int
    {
        // Each kind: which rows (fixed text written here, never a value from
        // outside), the words to record, and whether `sentAt` is emptied.
        // It is emptied on a row from before migration 219: the old code
        // stamped it when it HELD the text, which never went.
        $kinds = [
            [
                'claimedAt IS NOT NULL AND claimedAt <= UTC_TIMESTAMP() - INTERVAL ' . self::HELD_CLAIM_DEAD_SECONDS . ' SECOND',
                self::UNKNOWN_INTERRUPTED,
                false,
            ],
            ['claimedAt IS NULL AND heldUseBy IS NULL', self::NOT_SENT_OLD, true],
            ['claimedAt IS NULL AND heldUseBy <= UTC_TIMESTAMP()', self::NOT_SENT_USE_BY, false],
        ];

        $held    = self::HELD_REASON;
        $changed = 0;
        try {
            foreach ($kinds as [$which, $words, $emptySentAt]) {
                $find = $db->prepare("SELECT messageID FROM tblSmsMessage WHERE status = 'queued' AND errorMsg = ? AND " . $which . ' ORDER BY messageID LIMIT ?');
                $find->bind_param('si', $held, $most);
                $find->execute();
                $ids = array_column($find->get_result()->fetch_all(MYSQLI_ASSOC), 'messageID');
                $find->close();
                if ($ids === []) {
                    continue;
                }
                $move = $db->prepare(
                    "UPDATE tblSmsMessage SET status = 'failed', errorMsg = ?" . ($emptySentAt === true ? ', sentAt = NULL' : '')
                    . " WHERE messageID = ? AND status = 'queued' AND errorMsg = ? AND " . $which
                );
                foreach ($ids as $id) {
                    $id = (int) $id;
                    $move->bind_param('sis', $words, $id, $held);
                    $move->execute();
                    $changed += (int) $move->affected_rows;
                }
                $move->close();
            }
        } catch (\Throwable $problem) {
            if ($throwFaults === true) {
                throw $problem; // the job's run reports it: see sendHeld()
            }
        }

        return $changed;
    }

    /**
     * 🏃 ONE RUN OF THE HELD TEXTS: correct the record of those that can no
     * longer go, then send those whose person's quiet hours have ended.
     * Called by the scheduled job (`_apps/cron/mail-queue.php`) after its
     * mail step, and by nothing else.
     *
     * FOR EACH HELD TEXT, IN THE ORDER THEY WERE HELD
     *   1. No account any more -> not sent.
     *   2. Still inside their quiet hours (Sabbath, asked for this moment)
     *      -> left alone. Nothing is changed, so the next run asks again.
     *   3. The account has been switched off; or, where there are several
     *      organisations, the person is not a member of that one, or it
     *      has been switched off (whyNotThisPerson()) -> not sent.
     *   4. The number is no longer the person's confirmed number for texts
     *      in that organisation, or they have stopped texts of this kind
     *      (whyNoLongerWanted()) -> not sent. A day has passed; what is
     *      true of the person NOW decides, in 3 and in 4.
     *   5. The organisation's limit for today is reached -> left alone, to
     *      go on a later day if its use-by moment allows.
     *   6. THE CLAIM: the row is taken in one statement (claimHeld()). Only
     *      one run can take it.
     *   7. The provider is given the text, and what it said is recorded.
     *
     * IT NEVER THROWS, so it can never stop the mail step it runs beside.
     * A fault is counted in `error` and written to the server's error log
     * as a class name and a number, never as words: a database's own
     * message can quote the value it choked on.
     *
     * NEVER A FLOOD: at most `$most` texts are handed over in one run, no
     * new one is started after `$seconds`, and the day's limit holds.
     *
     * WHAT IT CANNOT DO: say whether a text went when the run is ended
     * between the claim and the provider's answer. Such a row is found by a
     * later run and recorded as "not known whether it was sent"; it is not
     * sent again.
     *
     * @param int   $most    The most texts to hand to the provider.
     * @param float $seconds No new text is started after this long.
     *
     * @return array{ran: int, sent: int, failed: int, notSent: int, left: int, notReady: int, error: int}
     *         NUMBERS ONLY, because the job prints this: `ran` (1, or 0 when
     *         another run held the lock), `sent`, `failed` (the provider
     *         refused), `notSent` (recorded as not sent, or as not known),
     *         `left` (still held when the run ended; not counted, so 0, when
     *         another run held the lock), `notReady` (1 when the database
     *         has not been upgraded to hold a use-by moment) and `error` (1
     *         when the run was stopped by a fault).
     */
    public static function sendHeld(mysqli $db, int $most = 20, float $seconds = 8.0): array
    {
        $out      = ['ran' => 0, 'sent' => 0, 'failed' => 0, 'notSent' => 0, 'left' => 0, 'notReady' => 0, 'error' => 0];
        $deadline = microtime(true) + $seconds;
        $locked   = false;

        try {
            // 🔒 One run at a time. The claim below is safe without this;
            //    what the lock adds is that two runs cannot both spend the
            //    same room in the day's limit. It belongs to this database
            //    connection and is given up by the database if the run dies.
            $lock = $db->query("SELECT GET_LOCK(CONCAT('portal-held-texts:', SHA1(DATABASE())), 0) AS got");
            $got  = (int) ($lock->fetch_assoc()['got'] ?? 0);
            $lock->free();
            if ($got !== 1) {
                return $out;
            }
            $locked     = true;
            $out['ran'] = 1;

            $out['notSent'] += self::settleHeld($db, 500, true);

            // 📴 Texts switched off since: nothing is sent. What is held
            //    waits, and is recorded as not sent when its use-by passes.
            $settings = App::settings()['sms'] ?? [];
            if ((string) ($settings['enabled'] ?? '0') === '1') {
                self::sendDue($db, $settings, $most, $deadline, $out);
            }
        } catch (\Throwable $problem) {
            if ($problem instanceof \mysqli_sql_exception && (int) $problem->getCode() === 1054) {
                // "Unknown column": the files are new and migration 219 has
                // not run. Nothing can be held yet either (see hold()), so
                // there is nothing to do, and nothing worth an error line
                // every five minutes.
                $out['notReady'] = 1;
            } else {
                $out['error'] = 1;
                error_log('Held texts: the run was stopped: ' . get_class($problem) . ' (' . (int) $problem->getCode() . ')');
            }
        } finally {
            // Whatever happened in a run that held the lock: say what is
            // left, and give the lock up. If the database has gone neither
            // can be done, and it gives the lock up by itself when the
            // connection ends. (A run that did not get the lock has already
            // answered above: PHP works out what a `return` gives back
            // BEFORE this block runs, so nothing here could change it.)
            if ($locked === true) {
                try {
                    $held = self::HELD_REASON;
                    $stmt = $db->prepare("SELECT COUNT(*) AS n FROM tblSmsMessage WHERE status = 'queued' AND errorMsg = ?");
                    $stmt->bind_param('s', $held);
                    $stmt->execute();
                    $out['left'] = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
                    $stmt->close();
                } catch (\Throwable $ignored) {
                    // See above.
                }
                try {
                    $db->query("DO RELEASE_LOCK(CONCAT('portal-held-texts:', SHA1(DATABASE())))");
                } catch (\Throwable $ignored) {
                    // See above.
                }
            }
        }

        return $out;
    }

    /**
     * The sending part of a run: see sendHeld() for the order of the seven
     * steps. Public only so that the self-test can run two of these at
     * the same moment WITHOUT the one-run-at-a-time lock, which is the only
     * way to prove that the claim alone stops a second copy.
     *
     * @param array<string, mixed> $settings The `sms` settings.
     * @param float                $deadline No new text is started after
     *                                       this moment (microtime(true)).
     * @param array<string, int>   $out      The run's numbers, added to.
     */
    public static function sendDue(mysqli $db, array $settings, int $most, float $deadline, array &$out): void
    {
        $cap      = (int) ($settings['dailyCap'] ?? 100);
        $provider = (string) ($settings['provider'] ?? 'twilio');
        $held     = self::HELD_REASON;
        $after    = 0;
        /** @var array<int, bool> $quiet Whether each person is still in their quiet hours: asked once a run. */
        $quiet = [];
        /** @var array<int, bool> $full The organisations whose limit for today this run found reached. */
        $full = [];

        while (true) {
            // The held texts that could still go, a page at a time, oldest
            // first. Read without taking anything: the claim comes later,
            // for one row, once every other question has been answered.
            $stmt = $db->prepare(
                'SELECT messageID, siteID, recipientUserID, recipientNumber, body, category FROM tblSmsMessage '
                . "WHERE status = 'queued' AND errorMsg = ? AND claimedAt IS NULL AND heldUseBy > UTC_TIMESTAMP() AND messageID > ? "
                . 'ORDER BY messageID LIMIT 50'
            );
            $stmt->bind_param('si', $held, $after);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                if ($out['sent'] + $out['failed'] >= $most || microtime(true) >= $deadline) {
                    return;
                }
                $id     = (int) $row['messageID'];
                $after  = $id;
                $siteId = (int) $row['siteID'];

                // 1. The account has gone (the link empties this column).
                if ($row['recipientUserID'] === null) {
                    $out['notSent'] += self::settleOne($db, $id, self::NOT_SENT_PERSON);
                    continue;
                }
                $userId = (int) $row['recipientUserID'];

                // 2. Still in their quiet hours? The same question, of the
                //    same code, that held it. A person who has since
                //    switched quiet hours off is no longer "inside".
                if (isset($quiet[$userId]) === false) {
                    $quiet[$userId] = Sabbath::isQuietNow($userId, self::nowOnDatabaseClock($db));
                }
                if ($quiet[$userId] === true) {
                    continue;
                }

                // 3. May this person still be sent this organisation's text?
                //    The account, the membership and the organisation, as
                //    they stand now. (Until the fix round of 13 October 2026
                //    this was not asked: a person whose account had been
                //    switched off while a text waited was still sent it.)
                $why = self::whyNotThisPerson($db, $siteId, $userId);
                if ($why !== '') {
                    $out['notSent'] += self::settleOne($db, $id, self::heldPersonWords($why));
                    continue;
                }

                // 4. Is the number still theirs, and do they still want it?
                $why = self::whyNoLongerWanted($db, $siteId, $userId, (string) $row['recipientNumber'], (string) $row['category']);
                if ($why !== '') {
                    $out['notSent'] += self::settleOne($db, $id, $why);
                    continue;
                }

                // 5. Today's limit, counted again before every text.
                if (isset($full[$siteId]) === true) {
                    continue;
                }
                if (self::sentTodayCount($siteId) >= $cap) {
                    $full[$siteId] = true;
                    continue;
                }

                // 6. The claim. Not ours: another run took it, or its
                //    use-by moment passed this very second.
                if (self::claimHeld($db, $id) === false) {
                    continue;
                }

                // 7. Hand it over, and write down what the provider said.
                $result = static::providerSend($provider, $settings, (string) $row['recipientNumber'], (string) $row['body']);
                self::finishHeld($db, $id, $provider, $result);
                $out[$result['ok'] === true ? 'sent' : 'failed']++;
            }
        }
    }

    /**
     * 🔀 THE CLAIM: a held text is taken by filling in `claimedAt`, in ONE
     * statement that only succeeds while the row is still held, untaken and
     * inside its use-by moment.
     *
     * The database gives a row to one statement only, so two runs at the
     * same moment can never both take it. REJECTED: reading the row and
     * then marking it. Two runs then read it before either has marked it,
     * and both send it.
     *
     * A claim is NEVER undone. Whatever happens after it, the text is not
     * offered to a run again.
     *
     * @return bool True when this run now holds the text.
     */
    private static function claimHeld(mysqli $db, int $messageId): bool
    {
        $held = self::HELD_REASON;
        $stmt = $db->prepare(
            'UPDATE tblSmsMessage SET claimedAt = UTC_TIMESTAMP() '
            . "WHERE messageID = ? AND status = 'queued' AND errorMsg = ? AND claimedAt IS NULL AND heldUseBy IS NOT NULL AND heldUseBy > UTC_TIMESTAMP()"
        );
        $stmt->bind_param('is', $messageId, $held);
        $stmt->execute();
        $mine = $stmt->affected_rows === 1;
        $stmt->close();

        return $mine;
    }

    /**
     * 🔀 AFTER THE PROVIDER ANSWERED: a claimed text becomes 'sent' or
     * 'failed'. `sentAt` is the moment it really went, on the database
     * server's own clock like every other row's, which is what the day's
     * limit and the month's cost count by.
     *
     * A text the provider refused is NOT tried again: a refusal is often a
     * time-out, and nobody can tell whether a text that timed out went.
     *
     * @param array{ok: bool, ref: string|null, costPence: int|null, error: string|null} $result
     */
    private static function finishHeld(mysqli $db, int $messageId, string $provider, array $result): void
    {
        $status = $result['ok'] === true ? 'sent' : 'failed';
        $words  = $result['ok'] === true
            ? self::SENT_AFTER_HOLD
            : mb_substr('Held for quiet hours, then refused by the provider: ' . (string) ($result['error'] ?? 'no reason given'), 0, 255);
        $ref    = $result['ref'] === null ? null : mb_substr((string) $result['ref'], 0, 100);
        $cost   = $result['costPence'];
        $held   = self::HELD_REASON;
        $stmt   = $db->prepare(
            'UPDATE tblSmsMessage SET status = ?, provider = ?, providerRef = ?, costPence = ?, errorMsg = ?, sentAt = NOW() '
            . "WHERE messageID = ? AND status = 'queued' AND errorMsg = ? AND claimedAt IS NOT NULL"
        );
        $stmt->bind_param('sssisis', $status, $provider, $ref, $cost, $words, $messageId, $held);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * 🔀 ONE held text recorded as not sent, with the reason. Only while it
     * is still held and untaken.
     *
     * @return int 1 when the row was changed, 0 when it had already moved on.
     */
    private static function settleOne(mysqli $db, int $messageId, string $words): int
    {
        $held = self::HELD_REASON;
        $stmt = $db->prepare(
            "UPDATE tblSmsMessage SET status = 'failed', errorMsg = ? WHERE messageID = ? AND status = 'queued' AND errorMsg = ? AND claimedAt IS NULL"
        );
        $stmt->bind_param('sis', $words, $messageId, $held);
        $stmt->execute();
        $changed = (int) $stmt->affected_rows;
        $stmt->close();

        return $changed;
    }

    /**
     * May this person still be sent this organisation's text? Asked when
     * their quiet hours have ended, up to two days after it was written.
     *
     * A text can carry something private (a prayer-chain text carries the
     * subject of somebody's prayer request). A person whose account was
     * switched off while it waited, who has left the organisation, or whose
     * organisation has been switched off, must not be sent it.
     *
     * THE RULE IS NOT WRITTEN HERE.
     *   - With SEVERAL organisations it is Site::isOwnOrganisation(), the
     *     one list of "your organisations" that the membership gate uses on
     *     every page (SiteGate): the account is switched on, the
     *     organisation is switched on, and the person is a member of it or
     *     a global administrator. Whoever could open that organisation's
     *     pages can be sent its text, and nobody else.
     *   - With ONE organisation that gate guards nothing, and a person need
     *     have no membership row at all. The one question left is the one
     *     sign-in asks: is the account switched on?
     * The reads below choose the WORDS when the answer is no. They decide
     * nothing, apart from the account's own switch, which both rules share.
     * REJECTED: asking tblUserSites directly. It would refuse every text on
     * an installation with one organisation, where nobody has such a row,
     * and a global administrator everywhere.
     *
     * It THROWS when Site cannot ask its question. The run then stops with
     * the text still held and untaken, and the next run asks again: a text
     * is not sent on a guess.
     *
     * IT IS ASKED AT BOTH MOMENTS since #610's follow-up: by sendHeld() when
     * a held text comes due, and by send() for a text about to go now. The
     * answer can differ between those two moments -- an account switched off
     * on the Friday and back on by the Saturday -- which is why each asks at
     * the moment its own text would go, and why this is not asked when a text
     * is merely being held. (This note used to say "a HELD text only".)
     *
     * WHAT IT CANNOT DO: say whether the NUMBER is still the person's. That
     * is whyNoLongerWanted(), and only a held text is asked it.
     *
     * @return string '' when the text may go. Otherwise a REASON, one of
     *                'no-account', 'account-off', 'not-member' or 'org-off'
     *                -- NOT the words to record. Put it through
     *                heldPersonWords() or atOncePersonWords() to get those.
     *                It used to return the words, which is why those two
     *                exist, and why writing this value straight into the
     *                record would put "account-off" in front of an
     *                administrator.
     */
    private static function whyNotThisPerson(mysqli $db, int $siteId, int $userId): string
    {
        $stmt = $db->prepare(
            'SELECT U.isActive AS accountOn, (SELECT S.isActive FROM tblSites S WHERE S.siteID = ? LIMIT 1) AS organisationOn '
            . 'FROM tblUsers U WHERE U.userID = ? LIMIT 1'
        );
        $stmt->bind_param('ii', $siteId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            return 'no-account';
        }
        if ((int) $row['accountOn'] !== 1) {
            return 'account-off';
        }
        if (Site::isMultisiteEnabled() === false || Site::isOwnOrganisation($userId, $siteId, $db) === true) {
            return '';
        }

        return (int) ($row['organisationOn'] ?? 0) !== 1 ? 'org-off' : 'not-member';
    }

    /**
     * The words to record for a HELD text that may no longer go to its
     * person, from the reason whyNotThisPerson() gave.
     *
     * WHY THIS IS SEPARATE FROM THE REASON. Until #610's follow-up,
     * whyNotThisPerson() returned the words themselves, which meant only a
     * held text could use it: every sentence says "When they ended, ...".
     * send() now asks the same question for a text it is about to send at
     * once, and needed the same four reasons in different words. Splitting
     * "which reason" from "what to write" let send() reuse the rule instead
     * of a second copy of it, which is what this project forbids.
     */
    private static function heldPersonWords(string $reason): string
    {
        return match ($reason) {
            'no-account'  => self::NOT_SENT_PERSON,
            'account-off' => self::NOT_SENT_ACCOUNT_OFF,
            'not-member'  => self::NOT_SENT_NOT_MEMBER,
            'org-off'     => self::NOT_SENT_ORG_OFF,
            default       => '',
        };
    }

    /**
     * The same four reasons in the words for a text sent AT ONCE: no quiet
     * hours, and no "when they ended". See AT_ONCE_* above.
     */
    private static function atOncePersonWords(string $reason): string
    {
        return match ($reason) {
            'no-account'  => self::AT_ONCE_PERSON,
            'account-off' => self::AT_ONCE_ACCOUNT_OFF,
            'not-member'  => self::AT_ONCE_NOT_MEMBER,
            'org-off'     => self::AT_ONCE_ORG_OFF,
            default       => '',
        };
    }

    /**
     * Should a held text still go to this number, for this person? Asked
     * when their quiet hours have ended, which is up to two days after the
     * text was written.
     *
     * A held text goes only to a number that is, AT THAT MOMENT, the
     * person's confirmed number for texts in that organisation. A person
     * who changed or removed their number while a text waited must not have
     * it sent to the old one: that number may be somebody else's by now.
     * And for the kinds a person opts in to (CATEGORIES), only while they
     * are still opted in.
     *
     * @return string '' when it should go; otherwise the words to record.
     */
    private static function whyNoLongerWanted(mysqli $db, int $siteId, int $userId, string $number, string $category): string
    {
        $stmt = $db->prepare('SELECT phoneNumber, isVerified, FIND_IN_SET(?, categories) AS wantsKind FROM tblUserSmsPreference WHERE siteID = ? AND userID = ? LIMIT 1');
        $stmt->bind_param('sii', $category, $siteId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row === null || (int) $row['isVerified'] !== 1 || (string) $row['phoneNumber'] !== $number) {
            return self::NOT_SENT_NUMBER;
        }
        if (in_array($category, self::CATEGORIES, true) === true && (int) $row['wantsKind'] < 1) {
            return self::NOT_SENT_OPTED_OUT;
        }

        return '';
    }

    /**
     * 🔀 EVERY HELD TEXT THAT CAME BACK WITH A RESTORED BACKUP is recorded
     * as not sent, and none is sent by itself.
     *
     * WHY. A text that was held when a backup was taken is in the backup as
     * "held". If it was sent afterwards, putting the backup back brings its
     * row back as "held", and the job would send it a SECOND time. Nobody
     * can tell from the restored rows which of them went. The mail queue
     * has the same trap (MailQueue::holdRestored()); there a message is held
     * for a person, and here, where no page releases a text, it is recorded
     * as not sent.
     *
     * DbBackup calls this at the end of a restore, inside the restore's own
     * transaction, so a rehearsal ("check only") undoes it with everything
     * else. It THROWS when the statement fails: the restore is then stopped
     * and undone, which is better than a restore that leaves texts able to
     * go twice. It needs nothing but the connection it is given, and names
     * only columns the table has had since it was made, so it works
     * whatever the age of the backup.
     *
     * @return int How many texts were recorded as not sent.
     */
    public static function holdRestored(mysqli $db): int
    {
        $words = self::NOT_SENT_RESTORED;
        $held  = self::HELD_REASON;
        $stmt  = $db->prepare("UPDATE tblSmsMessage SET status = 'failed', errorMsg = ? WHERE status = 'queued' AND errorMsg = ?");
        $stmt->bind_param('ss', $words, $held);
        $stmt->execute();
        $moved = (int) $stmt->affected_rows;
        $stmt->close();

        return $moved;
    }

    /**
     * Dispatch to the configured provider. Each provider returns
     * ['ok' => bool, 'ref' => string|null, 'costPence' => int|null,
     *  'error' => string|null] so the caller can persist a uniform row.
     *
     * `protected`, and called as `static::providerSend()`, so that
     * tools/held-texts-selftest.php can extend this class and put a
     * recorder here: that is how the test sends no real text. Nothing in
     * the portal extends this class.
     */
    protected static function providerSend(string $provider, array $settings, string $number, string $body): array
    {
        switch ($provider) {
            case 'twilio':
                return self::sendTwilio($settings, $number, $body);
            case 'messagebird':
                return self::sendMessageBird($settings, $number, $body);
            case 'aws':
                return self::sendAwsSns($settings, $number, $body);
            default:
                return ['ok' => false, 'ref' => null, 'costPence' => null, 'error' => 'unknown-provider'];
        }
    }

    /**
     * Twilio Messages API. Basic-auth with SID + token.
     *
     * @link https://www.twilio.com/docs/sms/send-messages
     */
    private static function sendTwilio(array $settings, string $number, string $body): array
    {
        $sid    = (string) ($settings['twilio']['sid'] ?? '');
        $token  = (string) ($settings['twilio']['token'] ?? '');
        $from   = (string) ($settings['fromNumber'] ?? '');
        if ($sid === '' || $token === '' || $from === '') {
            return ['ok' => false, 'ref' => null, 'costPence' => null, 'error' => 'twilio-not-configured'];
        }
        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'To'   => $number,
            'From' => $from,
            'Body' => $body,
        ]));
        curl_setopt($ch, CURLOPT_USERPWD, $sid . ':' . $token);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code < 200 || $code >= 300) {
            return ['ok' => false, 'ref' => null, 'costPence' => null, 'error' => 'twilio-http-' . $code];
        }
        $body = json_decode((string) $resp, true);
        $ref  = is_array($body) === true ? (string) ($body['sid'] ?? '') : '';
        // Twilio doesn't return price on initial accept (it's added on the
        // status callback); 4p is a safe pessimistic placeholder.
        return ['ok' => true, 'ref' => $ref !== '' ? $ref : null, 'costPence' => 4, 'error' => null];
    }

    /**
     * MessageBird Messages API. Authorization header with the API key.
     *
     * @link https://developers.messagebird.com/api/sms-messaging/
     */
    private static function sendMessageBird(array $settings, string $number, string $body): array
    {
        $key  = (string) ($settings['messagebird']['apiKey'] ?? '');
        $from = (string) ($settings['fromNumber'] ?? '');
        if ($key === '' || $from === '') {
            return ['ok' => false, 'ref' => null, 'costPence' => null, 'error' => 'messagebird-not-configured'];
        }
        $ch = curl_init('https://rest.messagebird.com/messages');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'recipients' => $number,
            'originator' => $from,
            'body'       => $body,
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: AccessKey ' . $key,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code < 200 || $code >= 300) {
            return ['ok' => false, 'ref' => null, 'costPence' => null, 'error' => 'messagebird-http-' . $code];
        }
        $body = json_decode((string) $resp, true);
        $ref  = is_array($body) === true ? (string) ($body['id'] ?? '') : '';
        return ['ok' => true, 'ref' => $ref !== '' ? $ref : null, 'costPence' => 5, 'error' => null];
    }

    /**
     * AWS SNS Publish. Uses SigV4-signed POST to the regional endpoint.
     * This is the most fiddly of the three — minimal canonical request
     * + string-to-sign + hmac-sha256 chain.
     *
     * @link https://docs.aws.amazon.com/sns/latest/api/API_Publish.html
     */
    private static function sendAwsSns(array $settings, string $number, string $body): array
    {
        $accessKey = (string) ($settings['aws']['accessKey'] ?? '');
        $secret    = (string) ($settings['aws']['secret'] ?? '');
        $region    = (string) ($settings['aws']['region'] ?? 'eu-west-1');
        if ($accessKey === '' || $secret === '') {
            return ['ok' => false, 'ref' => null, 'costPence' => null, 'error' => 'aws-not-configured'];
        }
        $host    = 'sns.' . $region . '.amazonaws.com';
        $payload = http_build_query([
            'Action'      => 'Publish',
            'PhoneNumber' => $number,
            'Message'     => $body,
            'Version'     => '2010-03-31',
        ]);
        $ts       = gmdate('Ymd\THis\Z');
        $date     = gmdate('Ymd');
        $bodyHash = hash('sha256', $payload);

        $canonical  = "POST\n/\n\ncontent-type:application/x-www-form-urlencoded\nhost:" . $host
            . "\nx-amz-date:" . $ts . "\n\ncontent-type;host;x-amz-date\n" . $bodyHash;
        $scope      = $date . '/' . $region . '/sns/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $ts . "\n" . $scope . "\n" . hash('sha256', $canonical);

        $kDate    = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
        $kRegion  = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 'sns', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $auth = 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/' . $scope
            . ', SignedHeaders=content-type;host;x-amz-date, Signature=' . $signature;

        $ch = curl_init('https://' . $host . '/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'X-Amz-Date: ' . $ts,
            'Authorization: ' . $auth,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code < 200 || $code >= 300) {
            return ['ok' => false, 'ref' => null, 'costPence' => null, 'error' => 'aws-http-' . $code];
        }
        $messageId = null;
        if (preg_match('/<MessageId>([^<]+)<\/MessageId>/', (string) $resp, $m) === 1) {
            $messageId = $m[1];
        }
        return ['ok' => true, 'ref' => $messageId, 'costPence' => 6, 'error' => null];
    }
}
