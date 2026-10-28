<?php
// Path: _core/ImportNotifier.php
/**
 * -----------------------------------------------------------------------------
 * Telling administrators that imported events are waiting 🔔🗓️ (#514, part 9)
 * -----------------------------------------------------------------------------
 * An event copied in from an outside calendar can wait for an administrator's
 * approval before it is shown more widely (#514 part 7). Until this file,
 * nobody was told that anything was waiting: an administrator found out only
 * by opening the list. This class tells them, three ways:
 *
 *   - a card on the dashboard, with the number;
 *   - ONE e-mail a day, on a day when something is waiting;
 *   - a push, when a run finds an event waiting that is newer than the last
 *     one the person was told about (one push, however many are new).
 *
 * It tells administrators only. NOTHING HERE IS SENT TO A MEMBER, and nobody
 * who is not an administrator of the organisation gets a card, a message or
 * a push, so no number can tell a member that hidden events exist.
 *
 * HOW IT IS BUILT, AND WHAT WAS REJECTED
 * --------------------------------------
 * ONE pass (run()), called by the scheduled job that already refreshes the
 * outside calendars (_apps/cron/import-feeds.php). The pass works out "is
 * there a waiting event this person has not been told about?" from the data.
 * REJECTED: the first plan, which fired a push from six save pages and two
 * places in the importer. A waiting event can arise in all eight, and in
 * any written later; a pass that reads the data gives the same answer
 * however the event came to wait, and touches none of those pages.
 *
 * NOBODY IS TOLD TWICE. The pass's only memory is tblExternalApprovalNotices
 * (migration 218): one row for each administrator in each organisation. A
 * person's turn is taken by CHANGING their row in one statement ("set the
 * day to today WHERE the day is before today"). Only one run can make that
 * change, so two runs at the same moment cannot both send. REJECTED: reading
 * the row and then sending. Two runs that both read "not told yet" both send.
 *
 * TIMES (the owner's rule of 23 September 2026)
 * ---------------------------------------------
 * - "Now" is ONE reading of the DATABASE's UTC clock for the whole pass
 *   (FeedResolver::databaseNowUtc()), never PHP's clock.
 * - "Today", and "is it 07:00 yet?", are read on the ORGANISATION's own
 *   clock: Site::timezone() is the one answer to its zone (#604). The day
 *   is kept as a calendar date and compared only as "is the stored day
 *   before today?"; the hour is compared as text. Nothing here adds 24
 *   hours or counts a day in seconds, so the two nights a year when a day
 *   is 23 or 25 hours long cannot move a message.
 * - An event's time in the e-mail is the event's OWN clock time, exactly as
 *   stored and NEVER converted, with the event's own zone named beside it.
 *   That is the same text the approvals list shows (FeedAdmin::showWallClock()).
 * - `emailAt` and `pushAt` are moments in UTC, written with UTC_TIMESTAMP()
 *   whatever the database server's own time zone is. Nothing is compared
 *   with them.
 *
 * QUIET HOURS HOLD NOTHING (the owner's decision of 13 October 2026)
 * -------------------------------------------------------------------
 * This notice is sent like every other e-mail of the portal, and so is its
 * push, whether or not the person is in their Sabbath quiet hours. The first
 * build held both and sent them after the window; that was taken out.
 *
 * WHAT THE E-MAIL CARRIES (the owner's decision of 12 October 2026)
 * -----------------------------------------------------------------
 * Each waiting event's TITLE AND TIME, as well as the numbers. The plan had
 * recommended numbers only; the owner overruled it. So:
 * - A person is shown only what the approvals list would show THAT person at
 *   that moment. The rows come from the list's own reader
 *   (FeedAdmin::pendingApprovals()) and the person passes the list's own
 *   gate (EventVisibility::isAdminOfSite()) just before anything is sent
 *   to them. There is no second rule here.
 * - The message says when the list was made, because an event can change,
 *   be decided or be hidden before the message is read.
 * - No more than MAX_EVENTS_LISTED events are listed; then "and N more".
 * - A message that cannot go at once is kept by the mail queue. It carries
 *   a USE-BY moment: the start of the organisation's next day. Today's list
 *   goes today or not at all; tomorrow's pass makes a fresh one.
 * - NO title, place or description goes into a log line, an activity row,
 *   an error row, the job's own output or the push. Those hold counts and
 *   numbers only. (The e-mail's subject is kept in the send log, as for all
 *   mail: it holds a number and the organisation's name.)
 *
 * WHAT THIS CANNOT DO
 * -------------------
 * - Know what became of a message the mail queue KEPT. The row here says
 *   "kept"; whether it later went or lapsed is in the mail queue and the
 *   send log. Either way that person's next message is tomorrow's.
 * - Try again the same day after a failed send. One try a day, on purpose:
 *   the dashboard and the list are unaffected, and a provider that is down
 *   would otherwise be asked every few minutes.
 * - Reach everybody in one run when the mail or push service is slow. The
 *   pass has PASS_SECONDS; people it does not reach are not claimed, so the
 *   next run (a few minutes later) reaches them. One push call is not cut
 *   short once it has begun: see pushStep() for what it can cost.
 * - Send a push that was claimed in the instant before the process was
 *   killed. A claim and its call are milliseconds apart, but a claim is
 *   never undone. The dashboard and the daily e-mail still tell that person.
 * - Stop a push arriving at night, or in a person's quiet hours. The
 *   device's own settings decide.
 * - Send a push about events that count again because a paused calendar was
 *   switched back on: their numbers are older than the last one told about.
 *   The card and the next daily e-mail include them.
 * - Tell a global administrator by push on a device that never subscribed
 *   while in that organisation: a push subscription belongs to one
 *   organisation.
 * - Follow an organisation that changes its time zone on the day: the
 *   stored day is then compared with a date on a different calendar. The
 *   worst case is one day without a message, never a second message.
 * - Give each organisation its own sender or frame. One mail route serves
 *   the whole installation; the subject and the text name the organisation.
 *
 * Test: tools/import-notifier-selftest.php
 *
 * @package   Portal\Core
 * @author    Salem874 (MWBM Partners Ltd, t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class ImportNotifier
{
    /**
     * ⏱️ How long the job gives this pass, in seconds. A slow mail or push
     * service uses it up, so fewer people are reached in that run; nobody is
     * lost, because a person not reached is not claimed. It is when the pass
     * stops STARTING things, not a limit on how long it can take: one push
     * call or one send already under way runs to its own end.
     */
    public const PASS_SECONDS = 15;

    /**
     * 🕖 The first moment of a day at which the daily e-mail may go, on the
     * ORGANISATION's own clock, as 'H:i'. Compared as text with the same
     * format, which is safe because both are always five characters.
     * There is no end hour, on purpose: a job that first runs at 14:00
     * still sends that day's message.
     */
    public const FIRST_HOUR = '07:00';

    /** 📋 The most events one message lists. The rest are counted: "and N more". */
    public const MAX_EVENTS_LISTED = 20;

    /** 📋 The most calendars one message names. The rest are counted too. */
    public const MAX_CALENDARS_LISTED = 20;

    /** The table that remembers who has been told (migration 218). */
    public const TABLE = 'tblExternalApprovalNotices';

    /** The preference that switches the daily e-mail off for one person. A missing key means "yes". */
    public const PREF_EMAIL = 'approvalRequests';

    /** The preference that switches the push off for one person. A missing key means "yes". */
    public const PREF_PUSH = 'pushApprovals';

    /** What `emailOutcome` can hold. See the column's comment in migration 218. */
    public const OUTCOME_CLAIMED = 'claimed';
    public const OUTCOME_SENT    = 'sent';
    public const OUTCOME_KEPT    = 'kept';
    public const OUTCOME_FAILED  = 'failed';

    /** The push channel, its tag (a newer push replaces an older one on the device), and how long the push service keeps trying. */
    private const PUSH_CHANNEL = 'reminders';
    private const PUSH_TAG     = 'feed-approvals';
    private const PUSH_SECONDS = 3600;

    // =========================================================================
    // 🔢 What is waiting, and who is told
    // =========================================================================

    /**
     * How many events of ONE organisation are waiting, by calendar.
     *
     * ONE statement. It counts waiting rows whose calendar is SWITCHED ON.
     * A paused calendar's events can be shown to nobody whatever is decided,
     * so nobody is nudged about them; the approvals list still shows them,
     * marked "(paused)". The join is the list's own (FeedAdmin::pendingApprovals()),
     * so this number is the list's rows that are not marked paused.
     *
     * @return array{total:int, newestId:int, byCalendar:list<array{feedID:int, name:string, n:int}>}
     *         `newestId` is the highest waiting approval number: what a push
     *         is counted against. `byCalendar` is in the list's order, by
     *         calendar name.
     */
    public static function waiting(\mysqli $db, int $siteId): array
    {
        $stmt = $db->prepare(
            'SELECT f.feedID, f.name, COUNT(*) AS n, MAX(a.approvalID) AS newest '
            . 'FROM tblExternalEventApprovals a '
            . 'JOIN tblExternalFeeds f ON f.feedID = a.feedID AND f.siteID = a.siteID '
            . "WHERE a.siteID = ? AND a.status = 'pending' AND f.isActive = 1 "
            . 'GROUP BY f.feedID, f.name ORDER BY f.name, f.feedID'
        );
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $out = ['total' => 0, 'newestId' => 0, 'byCalendar' => []];
        foreach ($rows as $row) {
            $out['total']       += (int) $row['n'];
            $out['newestId']     = max($out['newestId'], (int) $row['newest']);
            $out['byCalendar'][] = ['feedID' => (int) $row['feedID'], 'name' => (string) $row['name'], 'n' => (int) $row['n']];
        }

        return $out;
    }

    /**
     * Who is told about ONE organisation's waiting events.
     *
     * Its own administrators: an active account with an active membership
     * row there that is a site administrator's, and a global administrator
     * who holds an active membership row there.
     *
     * WHEN NONE OF THOSE HAS A USABLE E-MAIL ADDRESS (an organisation with
     * no administrator of its own, most often): every active global
     * administrator as well. Settled by the owner on 17 and 24 September
     * 2026: somebody must always hear. They are ADDED to the list, not put
     * in its place, so an administrator with no address still gets a push.
     *
     * EVERY PERSON RETURNED PASSES EventVisibility::isAdminOfSite(), the
     * gate of the approvals list itself. The self-test holds that for every
     * account in its world, so the two cannot drift apart. The old
     * portal-wide `isAdmin` flag counts for nothing here, for the reason
     * given on that gate: it says nothing about which organisation.
     *
     * It is read afresh on every call and never remembered: an administrator
     * who is demoted is not told by the next run.
     *
     * @return list<array{userID:int, email:string, notifyPrefs:?string}>
     */
    public static function recipients(\mysqli $db, int $siteId): array
    {
        $stmt = $db->prepare(
            'SELECT u.userID, u.emailAddress, u.notifyPrefs FROM tblUsers u '
            . 'JOIN tblUserSites s ON s.userID = u.userID AND s.siteID = ? AND s.isActive = 1 '
            . 'WHERE u.isActive = 1 AND (s.isSiteAdmin = 1 OR s.isSiteRootAdmin = 1 OR u.isRootAdmin = 1) '
            . 'ORDER BY u.userID'
        );
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $people     = [];
        $reachable  = false;
        foreach ($rows as $row) {
            $person = self::person($row);
            $people[$person['userID']] = $person;
            if (self::usableAddress($person['email']) === true) {
                $reachable = true;
            }
        }

        if ($reachable === false) {
            // 🛟 Nobody of the organisation's own can be e-mailed.
            $result = $db->query('SELECT userID, emailAddress, notifyPrefs FROM tblUsers WHERE isActive = 1 AND isRootAdmin = 1 ORDER BY userID');
            if (($result instanceof \mysqli_result) === true) {
                while (($row = $result->fetch_assoc()) !== null) {
                    $person = self::person($row);
                    $people[$person['userID']] ??= $person;
                }
                $result->free();
            }
        }

        return array_values($people);
    }

    /** @param array<string,mixed> $row @return array{userID:int, email:string, notifyPrefs:?string} */
    private static function person(array $row): array
    {
        return [
            'userID'      => (int) $row['userID'],
            'email'       => trim((string) $row['emailAddress']),
            'notifyPrefs' => $row['notifyPrefs'] === null ? null : (string) $row['notifyPrefs'],
        ];
    }

    /** Is this text an address a message can be sent to? */
    private static function usableAddress(string $email): bool
    {
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    // =========================================================================
    // 🏠 The dashboard card, and where the list is
    // =========================================================================

    /**
     * The dashboard's card for ONE person in ONE organisation, or null when
     * there is none to show.
     *
     * Null unless the person passes the approvals list's own gate
     * (EventVisibility::isAdminOfSite()) AND something is waiting. A member
     * therefore gets no card and no number. REJECTED: App::isAdmin(), which
     * the dashboard uses for its other cards. It also says yes to the old
     * portal-wide flag, which says nothing about which organisation.
     *
     * The link is built by Site::url(), as every address a page writes is
     * (#536). That is right HERE because a page is drawn for the
     * organisation of its own request; run() below must not use it.
     *
     * A database that has not been upgraded far enough to hold the calendar
     * tables answers null (error 1146, "no such table"). Any other database
     * fault is not hidden.
     *
     * @return array{label:string, value:int, icon:string, color:string, url:string}|null
     */
    public static function dashboardCard(\mysqli $db, int $userId, int $siteId): ?array
    {
        try {
            if (EventVisibility::isAdminOfSite($db, $userId, $siteId) === false) {
                return null;
            }
            $total = self::waiting($db, $siteId)['total'];
        } catch (\mysqli_sql_exception $problem) {
            if ($problem->getCode() === 1146) {
                return null;
            }
            throw $problem;
        }
        if ($total <= 0) {
            return null;
        }
        [$route, $fragment] = self::inboxRoute($siteId);

        return [
            'label' => 'Imported events waiting for approval',
            'value' => $total,
            'icon'  => 'fa-solid fa-stamp',
            'color' => 'warning',
            'url'   => Site::url($route) . $fragment,
        ];
    }

    /**
     * Where ONE organisation's waiting events are listed: the page, and the
     * part of it. A ROUTE, never an address: the caller builds the address
     * the right way for where it is (Site::url() on a page,
     * PortalAddress::absolute() in something sent out).
     *
     * The Approvals inbox when that organisation has the Approvals app
     * switched on; otherwise the outside calendars page, where each
     * calendar's own page lists its waiting events (the only place to
     * decide when Approvals is off).
     *
     * @return array{0:string, 1:string} The route, and '' or a '#part'.
     */
    public static function inboxRoute(int $siteId): array
    {
        return AppRegistry::isEnabledForSite('approvals', $siteId) === true
            ? ['approvals', '#imports']
            : ['admin/calendar/feeds', ''];
    }

    // =========================================================================
    // 🔁 The pass
    // =========================================================================

    /**
     * The whole pass: for every organisation with something waiting, tell
     * each administrator who has not been told. IT NEVER THROWS.
     *
     * IN ORDER
     *   1. List the active organisations that have a waiting event on a
     *      switched-on calendar. Usually none, so most runs cost this one
     *      statement and nothing else.
     *   2. For each: what is waiting, who is told, the organisation's local
     *      day and time from the ONE clock, and what each person has already
     *      been told (one read; a hint only, the claims are what decide).
     *   3. THE PUSH FIRST, for everybody owed one: each person's "told up to"
     *      number is raised in one statement (one changed row means this
     *      run owns it), and then ONE push call is made for the
     *      organisation, to the people claimed. See pushStep().
     *   4. THEN THE E-MAILS, one person at a time, stopping at the deadline:
     *      from FIRST_HOUR on the organisation's clock, for a usable address
     *      whose owner has not switched it off. The message is built from
     *      what is true now, the day is claimed in one statement, and only
     *      then is it sent and its outcome recorded.
     *   A person whose preference is off, or who is not reached before the
     *   deadline, is never claimed.
     *
     * WHY THE PUSH GOES BEFORE THE E-MAILS. This used to be the other way
     * round: each person's push was claimed as the pass went through the
     * people, and the one call was made after every e-mail had been sent. A
     * claim is never undone and never tried again, so a run that was killed,
     * or ended by a fault, somewhere in those seconds of sending left people
     * claimed for a push that no run would ever send (found by the
     * independent check, 13 October 2026). Now a claim and its call are
     * milliseconds apart, with nothing slow between them.
     *
     * A ROUTE THAT CANNOT SEND CLAIMS NOTHING. With no mail route set up no
     * day is claimed, and with push not set up no number is raised, so the
     * first run after either is set up tells people. (It also means an
     * installation with neither writes no rows at all.)
     *
     * THE ORGANISATION IN HAND is made the current one for the length of
     * its turn (Site::forceContext(), as the reminder jobs do) and the
     * job's own is put back at the end, whatever happened in between. That
     * is what files a kept message, its send-log line and any activity row
     * under the right organisation, and lets the mail queue use that
     * organisation's own waiting times.
     *
     * NO STATEMENT IS LEFT OPEN ACROSS A SEND, and nothing is sent inside a
     * transaction: a slow mail service must not hold a lock.
     *
     * @param float                   $deadline A moment on PHP's clock (microtime(true)). After it no organisation is
     *                                          begun, no push call is started and no e-mail is started. It cannot
     *                                          cut short a push call or a send that is already under way.
     * @param \DateTimeImmutable|null $nowUtc   "Now". Null means the database's own UTC clock. The self-test passes a moment.
     * @param callable|null           $mail     For the self-test: called in place of Mailer::send(), with the address,
     *                                          the subject, the HTML and the options, and answering one of the
     *                                          Mailer::OUTCOME_ values. The job passes null.
     * @param callable|null           $push     For the self-test: called in place of WebPush::sendToChannel(), with the
     *                                          same arguments, and answering as it does. The job passes null.
     *
     * @return array{setUp:bool, mailReady:bool, pushReady:bool, organisations:int, emailed:int, kept:int,
     *               keptNoMailJob:int, emailProblems:int, pushPeople:int, pushAccepted:int, notReached:int,
     *               notStarted:int, problems:int}
     *         `setUp` false: the table is not there yet (new files, the database upgrade not run); nothing was done.
     *         `emailed`: a mail service accepted it. `kept`: the mail queue has it and its job is running, so it
     *         goes later today or lapses at its use-by moment. `keptNoMailJob`: the mail queue has it and NO mail
     *         job is running, so nothing will send it unless one is started before it lapses.
     *         `pushPeople` counts PEOPLE a push call was addressed to. `pushAccepted` counts DEVICES the push
     *         service took the push for, by the sender's own answer; it is 0 when every push failed.
     *         `notReached` counts people who had something to be told and were passed over at the deadline;
     *         `notStarted` organisations not begun.
     *         `problems` counts faults that ended an organisation's turn, a push or the whole pass early
     *         (each is one line in the error log). A send that failed is `emailProblems`, not this.
     */
    public static function run(\mysqli $db, float $deadline, ?\DateTimeImmutable $nowUtc = null, ?callable $mail = null, ?callable $push = null): array
    {
        $out = [
            'setUp' => true, 'mailReady' => false, 'pushReady' => false, 'organisations' => 0, 'emailed' => 0, 'kept' => 0,
            'keptNoMailJob' => 0, 'emailProblems' => 0, 'pushPeople' => 0, 'pushAccepted' => 0, 'notReached' => 0,
            'notStarted' => 0, 'problems' => 0,
        ];
        $home = Site::id();

        try {
            // 1️⃣ Which organisations have something waiting.
            $sites = self::organisationsWithWaiting($db);
            if ($sites === []) {
                return $out;
            }
            if (self::tableExists($db) === false) {
                $out['setUp'] = false;

                return $out;
            }

            // What can carry a message at all. A recorder handed in by the
            // self-test always can.
            $out['mailReady'] = $mail !== null ? true : Mailer::canSend();
            $out['pushReady'] = $push !== null ? true : WebPush::isConfigured();
            if ($out['mailReady'] === false && $out['pushReady'] === false) {
                return $out;
            }

            // 🕰️ ONE reading of "now" for the whole pass, as a moment in UTC.
            //    With none handed in it is the DATABASE's UTC clock
            //    (UTC_TIMESTAMP()), never its local clock: on a database
            //    server whose own time zone is not UTC, NOW() read as UTC
            //    would put every organisation's "today" and "07:00" hours out.
            $nowUtc = ($nowUtc ?? FeedResolver::databaseNowUtc($db))->setTimezone(new \DateTimeZone('UTC'));

            foreach ($sites as $index => $site) {
                if (microtime(true) >= $deadline) {
                    $out['notStarted'] = count($sites) - $index;
                    break;
                }
                try {
                    self::tellOrganisation($db, $site, $nowUtc, $deadline, $mail, $push, $out);
                } catch (\Throwable $problem) {
                    if (self::isMissingTable($problem) === true) {
                        throw $problem; // the whole pass is "not set up": see below
                    }
                    // One organisation's fault must not stop the others.
                    // THE WORDS ARE TRUE OF BOTH ROUTES: a person is claimed
                    // only just before being told, so whoever this turn had
                    // not got to is unclaimed and is told by the next run.
                    // (They used to say "people not reached are told by the
                    // next run" while people claimed for a push, and not yet
                    // pushed, were not. See run()'s note on the order.)
                    $out['problems']++;
                    self::logProblem($site['siteID'], 'ImportNoticeOrganisationFailed', 'Telling administrators about imported events waiting for approval '
                        . 'stopped part way for this organisation. Whoever had not been reached yet is told by the next run.', $problem);
                }
            }
        } catch (\Throwable $problem) {
            if (self::isMissingTable($problem) === true) {
                // New files have arrived and the database upgrade has not
                // been run. Nothing is wrong that the upgrade will not put
                // right, so no error is recorded.
                $out['setUp'] = false;
            } else {
                $out['problems']++;
                self::logProblem(null, 'ImportNoticePassFailed', 'The pass that tells administrators about imported events waiting for approval could not be run.', $problem);
            }
        } finally {
            // 🧭 Whatever happened above, the job's own organisation is the
            //    current one again before the job carries on.
            self::backTo($home);
        }

        return $out;
    }

    /**
     * One organisation's turn. See run() for the order, and for why the
     * push goes before the e-mails.
     *
     * QUIET HOURS HOLD NOTHING HERE (the owner's decision, 13 October 2026:
     * "Hold nothing"). This notice is sent like every other e-mail the
     * portal sends, and so is its push, whether or not the person is in
     * their Sabbath quiet hours. REJECTED, and built first: passing over a
     * person in their quiet hours, unclaimed, so that the first run after
     * the window told them. No other e-mail of the portal is held, and the
     * notification preferences page says so.
     *
     * @param array{siteID:int, siteName:string} $site
     * @param array<string,int|bool>             $out  run()'s counts, added to here.
     */
    private static function tellOrganisation(\mysqli $db, array $site, \DateTimeImmutable $nowUtc, float $deadline, ?callable $mail, ?callable $push, array &$out): void
    {
        $siteId = $site['siteID'];
        if (self::enter($siteId) === false) {
            // Multi-organisation is switched off and this is not the one
            // organisation, or it was switched off a moment ago. Nobody can
            // open it, so nobody is told about it.
            return;
        }

        $waiting = self::waiting($db, $siteId);
        if ($waiting['total'] === 0) {
            return; // decided, or paused, since the organisations were listed
        }
        $out['organisations']++;

        // Read afresh on every run: never remembered (see recipients()).
        $people = self::recipients($db, $siteId);
        $told   = self::alreadyTold($db, $siteId);

        // 🕰️ The organisation's own day and time, from the one clock.
        $zone      = new \DateTimeZone(Site::timezone($siteId));
        $local     = $nowUtc->setTimezone($zone);
        $localDate = $local->format('Y-m-d');
        $mailHour  = $local->format('H:i') >= self::FIRST_HOUR;

        // 🔗 Where the list is. PortalAddress is the ONE place an address in
        //    something sent out comes from (#500). '' means there is no
        //    address to give: the link is then left out, never guessed.
        //    REJECTED: Site::url() here. It carries the address part of the
        //    request that started the JOB, which is not this organisation's.
        [$route, $fragment] = self::inboxRoute($siteId);
        $whole = PortalAddress::absolute($route, $siteId);
        $link  = $whole === '' ? '' : $whole . $fragment;

        // 📋 What there is to tell each person, going by the earlier read.
        //    A HINT: the claims are what decide. A person who has switched a
        //    route off is not claimed for it, so switching it on later still
        //    tells them.
        $forPush = [];
        $forMail = [];
        foreach ($people as $person) {
            $has = $told[$person['userID']] ?? null;
            if ($out['pushReady'] === true
                && ($has === null || $has['pushUpTo'] < $waiting['newestId'])
                && WebPush::notifyPrefAllows($person['notifyPrefs'], self::PREF_PUSH) === true) {
                $forPush[] = $person;
            }
            if ($out['mailReady'] === true && $mailHour === true
                && self::usableAddress($person['email']) === true
                && ($has === null || $has['emailLocalDate'] === null || $has['emailLocalDate'] < $localDate)
                && WebPush::notifyPrefAllows($person['notifyPrefs'], self::PREF_EMAIL) === true) {
                $forMail[] = $person;
            }
        }

        /** @var array<int,bool> $hasRow Who has a row in the table already, so that one is made at most once. */
        $hasRow = array_fill_keys(array_keys($told), true);
        /** @var array<int,bool> $missed Who had something to be told and was passed over at the deadline. */
        $missed = [];

        // 🔔 FIRST, THE PUSH. Nothing is claimed, and no call is started,
        //    once the deadline has passed: those people stay unclaimed and
        //    the next run pushes them.
        if ($forPush !== []) {
            if (microtime(true) >= $deadline) {
                foreach ($forPush as $person) {
                    $missed[$person['userID']] = true;
                }
            } else {
                self::pushStep($db, $siteId, $forPush, $waiting, $whole, $fragment, $push, $out, $hasRow);
            }
        }

        // ✉️ THEN THE E-MAILS, one person at a time.
        foreach ($forMail as $index => $person) {
            if (microtime(true) >= $deadline) {
                // Not started, so not claimed: the next run sends them.
                foreach (array_slice($forMail, $index) as $late) {
                    $missed[$late['userID']] = true;
                }
                break;
            }
            $userId = $person['userID'];

            // 🛡️ The approvals list's own gate, asked AT THIS MOMENT, just
            //    before this person's message is built. The list of people
            //    was read at the start of the organisation's turn, and a
            //    slow send to one person can leave seconds between that read
            //    and the next person. Somebody demoted in those seconds is
            //    sent no message. (The push asks the same gate, twice: see
            //    pushStep().)
            if (EventVisibility::isAdminOfSite($db, $userId, $siteId) === false) {
                continue;
            }

            // 📝 Built from what is true NOW, before the claim, so that a
            //    person is never claimed for a message there turned out to
            //    be nothing to put in.
            $message = self::buildEmail($db, $site, $zone, $nowUtc, $link, $route);
            if ($message === null) {
                continue; // nothing is waiting any more
            }
            if (isset($hasRow[$userId]) === false) {
                self::makeRow($db, $siteId, $userId);
                $hasRow[$userId] = true;
            }
            if (self::claimEmail($db, $siteId, $userId, $localDate) === false) {
                continue; // another run took today's turn for this person
            }

            // 📮 Sent, and only then recorded. Everything the mail queue
            //    needs should it keep the message: the use-by moment, and
            //    that a second copy does no harm (so an unanswered hand-over
            //    is tried again and does not wait for a person).
            $outcome = self::OUTCOME_FAILED;
            $detail  = '';
            try {
                $options = ['expiresAt' => self::useBy($local, $zone), 'repeatSafe' => true];
                $answer  = $mail !== null
                    ? (string) $mail($person['email'], $message['subject'], $message['html'], $options)
                    : self::sendThroughMailer($person['email'], $message['subject'], $message['html'], $options);
                if ($answer === Mailer::OUTCOME_SENT) {
                    $outcome = self::OUTCOME_SENT;
                } elseif ($answer === Mailer::OUTCOME_QUEUED || $answer === Mailer::OUTCOME_HELD) {
                    // The MAIL QUEUE's own two words for "kept" (waiting, or
                    // held for a person or a certificate). Nothing to do
                    // with quiet hours.
                    $outcome = self::OUTCOME_KEPT;
                } else {
                    $detail = 'The mail service answered "' . preg_replace('/[^a-z-]/', '', $answer) . '".';
                }
            } catch (\Throwable $problem) {
                // Only the KIND of fault is kept. Its own words can name
                // the address the message was for.
                $detail = 'The send ended with ' . get_class($problem) . '.';
            }
            self::recordEmail($db, $siteId, $userId, $localDate, $outcome);

            if ($outcome === self::OUTCOME_SENT) {
                $out['emailed']++;
            } elseif ($outcome === self::OUTCOME_KEPT) {
                // 📭 "Kept" means two different things to whoever reads the
                //    job's line, so it is counted two ways. With the mail
                //    queue's job running, a kept message goes later today.
                //    With NO such job running nothing works through the
                //    queue: the message sits there and lapses at its use-by
                //    moment unless a job is started first. That used to be
                //    counted under "kept" too, which read as "it will go
                //    later". MailQueue::jobIsRunning() is the one place that
                //    answers the question. What is STORED for the person is
                //    "kept" either way, because that is what happened.
                $out[MailQueue::jobIsRunning($db) === true ? 'kept' : 'keptNoMailJob']++;
            } else {
                $out['emailProblems']++;
                // One line, naming nobody: the account is a number.
                self::logLine($siteId, 'ImportNoticeEmailFailed', 'The daily e-mail about imported events waiting for approval could not be sent to one '
                    . 'administrator. It is not tried again today; the dashboard and the approvals list are unaffected.', $detail, $userId);
            }
        }

        $out['notReached'] += count($missed);
    }

    /**
     * 🔔 The push for ONE organisation: claim each person, ask the gate
     * again, and make ONE call. Called only before the deadline.
     *
     * A CLAIM IS ALWAYS FOLLOWED BY ITS CALL. A claim is never undone and
     * never tried again, so once anybody has been claimed the call is made
     * for them even if a later claim ends in a fault; the fault is then
     * passed on, so that it is recorded and ends the organisation's turn.
     * (Somebody the fault stopped this run reaching is unclaimed, and is
     * pushed by the next run.)
     *
     * THE GATE IS ASKED TWICE, both times of the approvals list's own gate
     * (EventVisibility::isAdminOfSite()): before a person is claimed, and
     * again for everybody claimed just before the call. The second asking
     * closes the last gap: somebody who stops being an administrator after
     * their claim is still left out of the call. If the gate cannot be
     * asked (the database has given way) the person is left out: nobody is
     * pushed on the strength of an answer that could not be had.
     *
     * WHAT ONE CALL CAN COST. WebPush sends to one device after another and
     * waits up to ten seconds for each (its own limit, and up to 500
     * devices). So an organisation with many devices that do not answer can
     * use far more than the pass's seconds in this one call. The deadline is
     * looked at BEFORE the first claim and not again: it stops this call
     * being started, and it cannot cut it short. What it costs is time, not
     * a message: every e-mail not started because of it is sent by the next
     * run.
     *
     * WHAT IT CANNOT DO: send a push that was claimed in the instant before
     * the process was killed, or before the database gave way. The claim
     * stands, and the dashboard and the daily e-mail are what tell that
     * person. Nor can it know that a push arrived: `pushAccepted` is the
     * push service's "taken", which is not "shown on the device".
     *
     * @param list<array{userID:int, email:string, notifyPrefs:?string}>                                   $forPush Who is owed a push, by the earlier read.
     * @param array{total:int, newestId:int, byCalendar:list<array{feedID:int, name:string, n:int}>}       $waiting waiting()'s answer for this organisation.
     * @param string                                                                                       $whole   The whole address of the list, or ''.
     * @param array<string,int|bool>                                                                       $out     run()'s counts.
     * @param array<int,bool>                                                                              $hasRow  Who has a row already; added to here.
     */
    private static function pushStep(\mysqli $db, int $siteId, array $forPush, array $waiting, string $whole, string $fragment, ?callable $push, array &$out, array &$hasRow): void
    {
        $claimed = [];
        $pushTo  = [];
        $fault   = null;
        try {
            foreach ($forPush as $person) {
                $userId = $person['userID'];
                if (EventVisibility::isAdminOfSite($db, $userId, $siteId) === false) {
                    continue;
                }
                if (isset($hasRow[$userId]) === false) {
                    self::makeRow($db, $siteId, $userId);
                    $hasRow[$userId] = true;
                }
                // Only for a waiting event newer than the last one this
                // person was told about. One changed row: this run owns it.
                if (self::claimPush($db, $siteId, $userId, $waiting['newestId']) === true) {
                    $claimed[] = $userId;
                }
            }
        } catch (\Throwable $problem) {
            $fault = $problem; // passed on below, AFTER the call for the people already claimed
        }
        try {
            foreach ($claimed as $userId) {
                if (EventVisibility::isAdminOfSite($db, $userId, $siteId) === true) {
                    $pushTo[] = $userId;
                }
            }
        } catch (\Throwable $problem) {
            $fault ??= $problem; // whoever could not be asked about is left out
        }

        // ONE push for the organisation. A number and no name of anything:
        // the words can appear on a locked screen.
        if ($pushTo !== []) {
            $payload = [
                'title' => 'Imported events waiting for approval',
                'body'  => self::countWords($waiting['total']) . ' waiting for approval.',
                'tag'   => self::PUSH_TAG,
            ];
            // The service worker opens only a PATH (sw.js), so the host name
            // is taken off. With no address to give, the page it opens is
            // left to the service worker.
            $path = $whole === '' ? '' : (string) parse_url($whole, PHP_URL_PATH);
            if ($path !== '' && str_starts_with($path, '/') === true && str_starts_with($path, '//') === false) {
                $payload['url'] = $path . $fragment;
            }
            try {
                $answer = $push !== null
                    ? $push($siteId, self::PUSH_CHANNEL, $payload, self::PUSH_SECONDS, 'normal', self::PUSH_TAG, self::PREF_PUSH, $pushTo)
                    : WebPush::sendToChannel($siteId, self::PUSH_CHANNEL, $payload, self::PUSH_SECONDS, 'normal', self::PUSH_TAG, self::PREF_PUSH, $pushTo);
                // 🔢 Two numbers, because they are two different things.
                //    This used to be one, "pushed", which counted the people
                //    addressed even when every push had failed.
                $out['pushPeople']   += count($pushTo);
                $out['pushAccepted'] += is_array($answer) === true ? (int) ($answer['sent'] ?? 0) : 0;
            } catch (\Throwable $problem) {
                $out['problems']++;
                self::logProblem($siteId, 'ImportNoticePushFailed', 'The push about imported events waiting for approval could not be sent. '
                    . 'It is not sent again for the same events; the daily e-mail and the dashboard are unaffected.', $problem);
            }
        }

        if ($fault !== null) {
            throw $fault;
        }
    }

    // =========================================================================
    // ✉️ The message
    // =========================================================================

    /**
     * The daily message for ONE organisation, from what is true at this
     * moment, or null when nothing is waiting.
     *
     * WHERE THE EVENTS COME FROM: FeedAdmin::pendingApprovals(), the reader
     * of the approvals list itself, asked for this organisation's
     * switched-on calendars one by one. So the message can never hold a row
     * the list would not show an administrator of this organisation, and
     * never a row of another organisation: that reader takes the
     * organisation's number and reads nothing else.
     *
     * A CALENDAR WHOSE ROWS ARE LISTED has its number from the SAME read as
     * those rows, so "3 waiting" is never printed above two of them.
     *
     * A CALENDAR PAST THE LIMITS DOES NOT. Once MAX_EVENTS_LISTED events have
     * been listed, or MAX_CALENDARS_LISTED calendars named, the rest are not
     * read again and keep the number from the count taken at the top of this
     * method. That number can be a few seconds old: if somebody approves
     * those events in between, the message names a calendar with a count that
     * has just stopped being true, and the total is that much too high. No
     * title is shown for such a calendar, so nobody is told about an event they
     * may not see.
     *
     * It is USUALLY too high rather than too low, because approving is what
     * normally happens between the two moments — but an import that adds rows
     * in that gap makes it too low, so "never too low" would be too strong a
     * claim to make. (It was made, and a review on 27 October 2026 took it
     * out.)
     *
     * Re-reading every calendar would cost one more query per calendar on
     * shared hosting, where this runs inside a scheduled job with a hard time
     * limit, to correct a number the message already says is a snapshot (it
     * prints when the list was made). That trade is deliberate. This
     * paragraph exists because the docblock used to claim the stronger
     * guarantee for EVERY calendar, which was not true of the code below it
     * (found by an outside review, 27 October 2026).
     *
     * WHAT IS SHOWN OF AN EVENT: its title and its time, and nothing else.
     * No place and no description (the owner asked for title and time).
     *
     * @param array{siteID:int, siteName:string} $site
     * @param string                             $link  The whole address of the list, or '' when there is none to give.
     * @param string                             $route Which page the list is on (inboxRoute()), for the words used when there is no link.
     *
     * @return array{subject:string, html:string, total:int, listed:int}|null
     */
    private static function buildEmail(\mysqli $db, array $site, \DateTimeZone $zone, \DateTimeImmutable $nowUtc, string $link, string $route): ?array
    {
        $siteId   = $site['siteID'];
        $esc      = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        // A name is one line of text. Anything that could start a new line
        // is taken out before it goes near a subject.
        $orgName  = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $site['siteName']));
        $waiting  = self::waiting($db, $siteId);

        $total     = 0;
        $listed    = 0;
        $calendars = 0;
        $unnamed   = 0;
        $blocks    = '';
        foreach ($waiting['byCalendar'] as $calendar) {
            $count = $calendar['n'];
            $items = '';
            $room  = self::MAX_EVENTS_LISTED - $listed;
            if ($room > 0 && $calendars < self::MAX_CALENDARS_LISTED) {
                $page = FeedAdmin::pendingApprovals($db, $siteId, $calendar['feedID'], $room);
                if ($page['rows'] !== [] && (int) $page['rows'][0]['feedActive'] !== 1) {
                    continue; // paused a moment ago: nobody is nudged about it
                }
                // The number and the rows from the SAME read (0 when every
                // one of its events was decided a moment ago).
                $count = (int) $page['total'];
                foreach ($page['rows'] as $row) {
                    $items .= '<li>' . $esc((string) $row['snapTitle']) . ' &mdash; ' . $esc(self::eventTime($row, $zone, $siteId)) . '</li>';
                    $listed++;
                }
            }
            if ($count <= 0) {
                continue; // every one of its events was decided a moment ago
            }
            $total += $count;
            if ($calendars >= self::MAX_CALENDARS_LISTED) {
                $unnamed++;
                continue;
            }
            $calendars++;
            $blocks .= '<p><strong>' . $esc((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $calendar['name'])) . ': ' . $count . ' waiting</strong></p>'
                . ($items === '' ? '' : '<ul>' . $items . '</ul>');
        }
        if ($total <= 0) {
            return null;
        }

        $subject = '[' . $orgName . '] ' . self::countWords($total) . ' waiting for approval';

        $html = '<p>' . $esc(self::countWords($total)) . ' waiting for approval at ' . $esc($orgName) . '.</p>'
            . '<p>Each one was copied in from an outside calendar, and a rule or a choice asks for it to be shown to more people than '
            . 'its calendar\'s own setting allows. Until an administrator approves or declines, it is shown only as that setting allows, '
            . 'and an event its outside calendar marks private is shown to administrators only.</p>'
            . $blocks;
        if ($total > $listed) {
            $more  = $total - $listed;
            $html .= '<p>' . ($listed === 0 ? '' : 'and ') . $more . ' more ' . ($more === 1 ? 'is' : 'are') . ' waiting that ' . ($more === 1 ? 'is' : 'are') . ' not listed here.</p>';
        }
        if ($unnamed > 0) {
            $html .= '<p>' . $unnamed . ' more ' . ($unnamed === 1 ? 'calendar has' : 'calendars have') . ' events waiting and ' . ($unnamed === 1 ? 'is' : 'are') . ' not named here.</p>';
        }

        // 🔗 The link, or how to get there when there is no address to give.
        if ($link !== '') {
            $html .= '<p><a href="' . $esc($link) . '">Open the list of waiting events</a></p>';
        } elseif ($route === 'approvals') {
            $html .= '<p>To decide, sign in to the portal and open Approvals. They are under "Imported events waiting for approval".</p>';
        } else {
            $html .= '<p>To decide, sign in to the portal and open Admin, then Outside calendars. Each calendar\'s own page lists its waiting events.</p>';
        }

        // 🕰️ When the list was made, on the organisation's own clock, with
        //    the zone's short name (BST, GMT) AND its full name: the short
        //    name is what tells the two 01:30s apart on the night the clocks
        //    go back.
        $html .= '<p>This list was made on ' . $esc(FeedAdmin::showMoment($nowUtc->format('Y-m-d H:i:s'), $zone)) . ' (' . $esc($zone->getName())
            . ' time). An event may have been changed, decided or hidden since then. The list in the portal always shows what is true now.</p>'
            . '<p>Each time shown is the time on the event\'s own clock, with its time zone beside it. A whole-day event shows its date only.</p>';

        // 🙋 Why they got it, and how to stop it.
        $prefs = PortalAddress::absolute('account/notifications', $siteId);
        $html .= '<p><small>You are getting this because you are an administrator of ' . $esc($orgName) . '. It is sent at most once a day, and only on a '
            . 'day when something is waiting. To stop it, switch off "Approval requests" on your Notification preferences page'
            . ($prefs === '' ? '' : ' (<a href="' . $esc($prefs) . '">open it</a>)') . '. That switch also stops the portal\'s other e-mails about approvals: '
            . 'a step that is waiting on you, and the decision on a request you sent in.</small></p>';

        return ['subject' => $subject, 'html' => $html, 'total' => $total, 'listed' => $listed];
    }

    /**
     * One waiting event's time, for the message.
     *
     * THE EVENT'S OWN CLOCK TIME, EXACTLY AS STORED, NEVER CONVERTED: the
     * approvals list and the calendar grids print the same stored text, and
     * a time moved into another zone here would disagree with both. The
     * words are the list's own (FeedAdmin::showWallClock()).
     *
     * THE ZONE IS ALWAYS NAMED for an event with a time. The list names it
     * only when it differs from the organisation's, because the list is
     * read inside the portal; a message is read anywhere. The name is the
     * one answer to "which zone is this stored time in?": Site::zoneFor(),
     * the event's own zone, or the organisation's when the stored text is
     * not a zone PHP can open (#604).
     *
     * A whole-day event shows its date and "(all day)", as the list does,
     * with no zone: it has no time on a clock for a zone to change.
     *
     * WHAT IT CANNOT DO: say which of the two 01:30s is meant on the night
     * the clocks go back. The stored time does not say, so neither does
     * this.
     *
     * @param array<string,mixed> $row One row from FeedAdmin::pendingApprovals().
     */
    private static function eventTime(array $row, \DateTimeZone $orgZone, int $siteId): string
    {
        $allDay = (int) $row['snapIsAllDay'] === 1;
        $text   = FeedAdmin::showWallClock((string) $row['snapStart'], null, $allDay, $orgZone);
        if ($allDay === true) {
            return $text;
        }

        return $text . ' (' . Site::zoneFor((string) $row['snapTimezone'], $siteId)->getName() . ')';
    }

    /**
     * The moment after which a kept message must never be sent: the start
     * of the organisation's NEXT day, as a UTC moment for the mail queue.
     *
     * WHY THEN. The message is "today's list". Tomorrow's pass makes a
     * fresh one from 07:00, so a list delivered after midnight would be
     * followed, or even overtaken, by a newer one.
     *
     * HOW. The next calendar date is found with "+1 day", which follows
     * the calendar, and MailQueue::useBy() turns "the start of that date in
     * this zone" into a moment. REJECTED: today's midnight plus 86,400
     * seconds, which is an hour out on the two days a year that are 23 or
     * 25 hours long. In a zone whose clocks go forward AT midnight
     * (Santiago), that midnight does not exist; PHP reads it as the first
     * moment that does, which is the start of the day.
     */
    private static function useBy(\DateTimeImmutable $local, \DateTimeZone $zone): string
    {
        return MailQueue::useBy($local->modify('+1 day')->format('Y-m-d'), $zone->getName());
    }

    /**
     * Hand ONE message to the portal's mailer and say what became of it.
     * send() answers true for sent OR kept, so the answer that is recorded
     * is lastOutcome() (see Mailer.php, '"TRUE" IS NOT "SENT"').
     *
     * @param array{expiresAt:string, repeatSafe:bool} $options
     */
    private static function sendThroughMailer(string $to, string $subject, string $html, array $options): string
    {
        Mailer::send($to, $subject, $html, [], $options);

        return Mailer::lastOutcome();
    }

    /** "1 imported event is" or "3 imported events are". The caller adds what follows. */
    private static function countWords(int $count): string
    {
        return $count === 1 ? '1 imported event is' : $count . ' imported events are';
    }

    // =========================================================================
    // 🗃️ The table
    // =========================================================================

    /**
     * The active organisations with a waiting event on a switched-on
     * calendar, with each one's name.
     *
     * @return list<array{siteID:int, siteName:string}>
     */
    private static function organisationsWithWaiting(\mysqli $db): array
    {
        $result = $db->query(
            'SELECT DISTINCT s.siteID, s.siteName FROM tblExternalEventApprovals a '
            . 'JOIN tblExternalFeeds f ON f.feedID = a.feedID AND f.siteID = a.siteID '
            . 'JOIN tblSites s ON s.siteID = a.siteID '
            . "WHERE a.status = 'pending' AND f.isActive = 1 AND s.isActive = 1 ORDER BY s.siteID"
        );
        $sites = [];
        if (($result instanceof \mysqli_result) === true) {
            while (($row = $result->fetch_assoc()) !== null) {
                $sites[] = ['siteID' => (int) $row['siteID'], 'siteName' => (string) $row['siteName']];
            }
            $result->free();
        }

        return $sites;
    }

    /**
     * Is the table there? It is not between new files arriving and the
     * database upgrade being run. `SHOW TABLES LIKE` answers that without
     * failing, as Newsletter::demoRegisterExists() explains.
     */
    private static function tableExists(\mysqli $db): bool
    {
        $result = $db->query("SHOW TABLES LIKE '" . self::TABLE . "'");
        if (($result instanceof \mysqli_result) === false) {
            return false;
        }
        $exists = $result->num_rows > 0;
        $result->free();

        return $exists;
    }

    /**
     * What each person of ONE organisation has been told so far.
     *
     * A HINT, read once for the organisation's turn, that saves a statement
     * for everybody already told. It decides nothing: another run may have
     * changed a row since. The claiming statements below are what decide.
     *
     * @return array<int, array{emailLocalDate:?string, pushUpTo:int}> By account number.
     */
    private static function alreadyTold(\mysqli $db, int $siteId): array
    {
        $stmt = $db->prepare('SELECT userID, emailLocalDate, pushUpToApprovalID FROM tblExternalApprovalNotices WHERE siteID = ?');
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $told = [];
        foreach ($rows as $row) {
            $told[(int) $row['userID']] = [
                'emailLocalDate' => $row['emailLocalDate'] === null ? null : (string) $row['emailLocalDate'],
                'pushUpTo'       => (int) $row['pushUpToApprovalID'],
            ];
        }

        return $told;
    }

    /**
     * Make a person's row if they have none. "Insert, or leave alone": a row
     * another run made a moment ago is left as it is, and an account deleted
     * a moment ago gets no row (the claim that follows then changes nothing).
     */
    private static function makeRow(\mysqli $db, int $siteId, int $userId): void
    {
        $stmt = $db->prepare('INSERT IGNORE INTO tblExternalApprovalNotices (siteID, userID) VALUES (?, ?)');
        $stmt->bind_param('ii', $siteId, $userId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Take the push for one person: raise their "told up to" number, but
     * only if it is still lower. True when THIS run made the change.
     */
    private static function claimPush(\mysqli $db, int $siteId, int $userId, int $newestId): bool
    {
        // ⚠️ ONE EVENT CAN BE SKIPPED HERE, AND THAT IS ACCEPTED (#643).
        //
        //    An approval's number comes from the database's own counter, which
        //    hands one out when the row is INSERTED, not when it is COMMITTED.
        //    Each import runs in a long transaction holding only ITS OWN
        //    calendar's row lock, so two calendars importing at once can take
        //    100 and 101 and commit in the other order:
        //
        //      1. calendar A inserts approval 100; its transaction stays open;
        //      2. calendar B inserts 101 and commits;
        //      3. this runs, sees 101, pushes, records "told up to 101";
        //      4. calendar A commits. 100 is waiting — and `< 101` is false,
        //         so no push is ever sent for it.
        //
        //    CONFIRMED, not assumed, on 28 October 2026: `approvalID` really is
        //    `AUTO_INCREMENT`, `waiting()` really reports the highest number
        //    ACROSS the organisation's calendars, and nothing orders the two
        //    commits. The reviewer who reported it had read neither the table
        //    nor the importer and said so.
        //
        //    THE OWNER CHOSE TO LEAVE IT, and the reason is the rule this whole
        //    class is built on: NEVER TWICE COMES BEFORE ALWAYS DELIVERED.
        //    Every way of closing it — a moment instead of a number, re-reading
        //    after a pause — risks a second push for an event somebody has
        //    already been told about, which is a worse outcome than this one.
        //
        //    AND THIS ONE LOSES NOTHING. The event stays in the approvals list,
        //    the admin page's counts include it, and the next day's e-mail
        //    names it, because the e-mail is claimed by LOCAL DATE and not by
        //    approval number. Only the push is skipped. Section N29 of
        //    `tools/import-notifier-selftest.php` holds all of that, including
        //    the three "nothing is lost" halves — so if a change ever turns
        //    this from "a push is skipped" into "an event is hidden", a test
        //    says so. Be clear about the limit of that, though: N29 is NOT a
        //    tripwire against redesigning the mark. Three plants were tried
        //    against it and none was caught, because the mark only ever rises
        //    and the gate compares it with the HIGHEST waiting number, which in
        //    that scenario already equals the mark. A real fix has to change
        //    the data model, and would need its own checks.
        //
        //    If you are about to fix it: the fix must not be able to push
        //    twice. That is the whole constraint, and it is why `<` is here
        //    rather than `<=`.

        $stmt = $db->prepare(
            'UPDATE tblExternalApprovalNotices SET pushUpToApprovalID = ?, pushAt = UTC_TIMESTAMP() '
            . 'WHERE siteID = ? AND userID = ? AND pushUpToApprovalID < ?'
        );
        $stmt->bind_param('iiii', $newestId, $siteId, $userId, $newestId);
        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
        $stmt->close();

        return $changed;
    }

    /**
     * Take today's e-mail for one person: set their day to today, but only
     * if it is still an earlier day (or none). True when THIS run made the
     * change. `$localDate` is a date on the ORGANISATION's own calendar,
     * compared with a date written the same way: never a UTC date.
     */
    private static function claimEmail(\mysqli $db, int $siteId, int $userId, string $localDate): bool
    {
        $stmt = $db->prepare(
            "UPDATE tblExternalApprovalNotices SET emailLocalDate = ?, emailOutcome = 'claimed', emailAt = UTC_TIMESTAMP() "
            . 'WHERE siteID = ? AND userID = ? AND (emailLocalDate IS NULL OR emailLocalDate < ?)'
        );
        $stmt->bind_param('siis', $localDate, $siteId, $userId, $localDate);
        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
        $stmt->close();

        return $changed;
    }

    /** Write down what became of the e-mail this run claimed. Only the row of that same day is touched. */
    private static function recordEmail(\mysqli $db, int $siteId, int $userId, string $localDate, string $outcome): void
    {
        $stmt = $db->prepare('UPDATE tblExternalApprovalNotices SET emailOutcome = ? WHERE siteID = ? AND userID = ? AND emailLocalDate = ?');
        $stmt->bind_param('siis', $outcome, $siteId, $userId, $localDate);
        $stmt->execute();
        $stmt->close();
    }

    // =========================================================================
    // 🧭 Which organisation is in hand, and the log
    // =========================================================================

    /**
     * Make one organisation the current one. False when it cannot be (see
     * tellOrganisation()). Asking for the one already current does nothing,
     * which is every run of an installation with a single organisation.
     */
    private static function enter(int $siteId): bool
    {
        if (Site::id() === $siteId) {
            return true;
        }
        try {
            Site::forceContext($siteId);
        } catch (\Throwable $problem) {
            return false;
        }

        return true;
    }

    /**
     * Put the job's own organisation back, so that whatever the job does
     * next runs exactly as it did before this pass existed. If it cannot be
     * put back, one line goes to PHP's log; nothing in the calendar job
     * reads the current organisation, so it carries on.
     */
    private static function backTo(int $home): void
    {
        if (self::enter($home) === false) {
            error_log('[WebMS-Intra] ImportNotifier could not put the job\'s own organisation (' . $home . ') back as the current one.');
        }
    }

    /** Is this fault "no such table" (error 1146)? */
    private static function isMissingTable(\Throwable $problem): bool
    {
        return ($problem instanceof \mysqli_sql_exception) === true && $problem->getCode() === 1146;
    }

    /**
     * One line in the error log about a fault. ONLY the kind of fault and
     * where in the code it was raised: a fault's own words can hold an
     * address or a name, and nothing here may log one.
     */
    private static function logProblem(?int $siteId, string $code, string $title, \Throwable $problem): void
    {
        self::logLine($siteId, $code, $title, get_class($problem) . ' at ' . basename($problem->getFile()) . ':' . $problem->getLine() . '.');
    }

    /**
     * Write one line to the portal's error log, against the organisation it
     * belongs to. Never throws: recording a problem is not worth causing
     * another one. A warning, not an error: nothing is broken for anybody
     * using the portal.
     */
    private static function logLine(?int $siteId, string $code, string $title, string $detail, ?int $userId = null): void
    {
        try {
            Logger::errorPlatformForSite($siteId, 'ImportNotifier', 'Warning', $code, $title, $detail, $userId);
        } catch (\Throwable $loggingProblem) {
            // See above.
        }
    }
}
