<?php
// Path: _core/MailQueue.php
/**
 * -----------------------------------------------------------------------------
 * The mail queue ✉️⏳ — mail that could not be sent at once
 * -----------------------------------------------------------------------------
 * A message that cannot go now is kept here, tried again, and in the end is
 * either sent, or marked as failed where an administrator can see it. It is
 * never sent twice, never sent late, and never lost in silence.
 *
 * ONLY WHAT COULD NOT BE SENT AT ONCE COMES HERE. `Mailer::send()` sends a
 * message in the request when it can, and such a message is never stored. A
 * message is kept when the mail route said "later", when the hourly limit has
 * no room, when the request has used its own budget, when the caller asked
 * for it to wait, or when a fault with the route is already on record.
 *
 * THIS CLASS HOLDS EVERY STATEMENT THAT MOVES A ROW of tblMailQueue from one
 * state to another. Each is in a method of its own, marked 🔀, so they can
 * be read one after another. `Mailer` decides WHETHER a message is kept;
 * `MailRoutes` knows the route and what is wrong with it; the scheduled job
 * (`_apps/cron/mail-queue.php`) only calls run().
 *
 * THE STATES
 *   queued   waiting to be tried, not before `nextAttemptAt`.
 *   sending  a run has taken it: `stage` is 'claimed', then 'handing-over'
 *            from just before it is given to the mail server.
 *   held     waiting for a person, or for the route to be put right.
 *   failed   given up on. Kept for a while so it can be looked at.
 *   expired  its use-by moment passed. Its body is removed at once.
 *   (sent)   there is no such state: a sent message is DELETED, and the send
 *            log (tblEmailLog) is the lasting record.
 *
 * TIMES
 *   Every stored moment is UTC and comes from the DATABASE's own clock
 *   (`UTC_TIMESTAMP()`), in the statement that writes it. Nothing here uses
 *   NOW(), PHP's clock or a PHP time zone for a stored moment, and every
 *   length is a number of exact seconds added by the database. REJECTED:
 *   working a moment out in PHP ("now plus one hour") and storing the text.
 *   On the night the clocks change that is an hour out wherever PHP or the
 *   database is set to a zone that changes.
 *   A moment is turned into a person's time zone once, in shown().
 *
 * WHAT THIS CANNOT DO
 *   - IT CANNOT RULE OUT A SECOND COPY. If a mail server accepts a message
 *     and its "accepted" reply is lost, nobody can tell. Such a message is
 *     HELD for a person and is not sent again by itself (unless its caller
 *     said a second copy does no harm). When a person does send it again it
 *     keeps the same Message-ID, which lets many mail systems drop the
 *     duplicate. No more than that is promised: SMTP itself offers no more.
 *   - It cannot send what is WAITING while the scheduled job is not running:
 *     only the job (and the one run an event broadcast makes) works through
 *     the queue. So while the job has not run lately the portal does as it
 *     did before there was a queue, wherever that gets mail delivered (see
 *     jobIsRunning()), and what still has to wait is shown in red on the
 *     health page.
 *   - It does not know whether a message was DELIVERED, only whether a mail
 *     service accepted it.
 *   - A message's attachments are copies on disk, and are in no database
 *     backup. A waiting message restored from a backup without them is
 *     failed, in words, and not sent without them.
 *   - The body is stored encrypted with the installation's key file, which is
 *     in no database backup either. Restored beside a different key, a
 *     waiting message cannot be read and is failed, in words.
 *   - One run at a time is enforced with a database lock. The claim is also
 *     safe without it; what the lock adds is that two runs cannot both spend
 *     the same room in the hourly limit. Mail sent inside ordinary requests
 *     is not under that lock: see MailRoutes::roomLeft().
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/592
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

final class MailQueue
{
    // 🚦 The three priorities. The lowest number goes first.
    public const URGENT = 1;
    public const NORMAL = 5;
    public const BULK   = 9;

    /** @var array<string, int> The words a caller of Mailer::send() may use. */
    public const PRIORITIES = ['urgent' => self::URGENT, 'normal' => self::NORMAL, 'bulk' => self::BULK];

    // ✋ Why a held message is held (`holdReason`). Package M4's page reads these.
    public const HELD_CERTIFICATE = 'certificate';      // the mail server's certificate failed its check
    public const HELD_INTERRUPTED = 'interrupted';      // a run died while handing it over
    public const HELD_UNKNOWN     = 'unknown-if-sent';  // handed over whole, and no answer came
    public const HELD_RESTORED    = 'restored';         // it came back with a restored backup, and may already have gone

    /**
     * ⏱️ How long to wait after each failed attempt, in exact seconds.
     *
     * Urgent mail: 1, 5, 15 and 30 minutes, then it is given up on. An urgent
     * message is one a person is waiting for; an hour later it is no use.
     * Everything else: 5 and 15 minutes, then 1, 3, 6 and 12 hours. That
     * comes to a little over 22 hours, which is the "about a day" the owner
     * (Salem874) decided on. If the retry setting is made longer, the last
     * wait is used again until that time is up.
     */
    private const WAITS_URGENT = [60, 300, 900, 1800];
    private const WAITS_OTHER  = [300, 900, 3600, 10800, 21600, 43200];

    /**
     * @var int How old a claim must be before the run that made it is taken
     *      for dead. Ten minutes: a single send is given up on by the mail
     *      library after about 50 seconds, so a live run never leaves a row
     *      untouched this long.
     */
    public const STALE_CLAIM_SECONDS = 600;

    /** @var int How many rows a run takes at a time. Small, so a run that dies strands few. */
    private const BATCH = 20;

    /** @var int The largest attachment that is kept, and the most one message may carry (the mail library's own limits). */
    private const MAX_FILE_BYTES    = 10485760;
    private const MAX_MESSAGE_BYTES = 20971520;

    /**
     * 🔢 The four numbers an administrator can set, each with its default and
     * the range that counts as sensible. The owner decided the defaults on
     * 12 October 2026: retry for about a day, send nothing older than 48
     * hours, keep a failed or held message 7 days.
     *
     * Each is read for the organisation the message belongs to: that
     * organisation's own row if it has one, otherwise the installation's.
     *
     * @var array<string, array{key: string, default: int, min: int, max: int, what: string}>
     */
    public const NUMBERS = [
        'retryHours'     => ['key' => 'mail.queue.retryHours',     'default' => 24, 'min' => 1, 'max' => 720, 'what' => 'how many hours a message is tried for'],
        'maxAgeHours'    => ['key' => 'mail.queue.maxAgeHours',    'default' => 48, 'min' => 1, 'max' => 720, 'what' => 'the age, in hours, past which a message is never sent'],
        'keepFailedDays' => ['key' => 'mail.queue.keepFailedDays', 'default' => 7,  'min' => 1, 'max' => 365, 'what' => 'how many days a failed message is kept'],
        'holdDays'       => ['key' => 'mail.queue.holdDays',       'default' => 7,  'min' => 1, 'max' => 365, 'what' => 'how many days a held message is kept'],
    ];

    /**
     * @var int The seconds in one day OF A KEEPING PERIOD. "Kept 7 days" is a
     *      length, 7 times this, counted on the UTC clock, where every day
     *      has exactly this many seconds. It is not "the same time next week
     *      on somebody's clock", and it is never added to a local time.
     */
    private const KEEPING_DAY_SECONDS = 86400;

    /**
     * 🛡️ The test that a message is still wanted, as SQL. ONE piece of text,
     * used by the claim AND by the last check before a message is handed
     * over, so the two cannot come to differ. A reset link that has run out
     * is worse than no e-mail.
     */
    private const STILL_WANTED = '(expiresAt IS NULL OR expiresAt > UTC_TIMESTAMP())';

    /** @var string Put in front of a body before it is encrypted, so that "decrypted to nothing" can be told from "was empty". */
    private const SEAL = "mq1\n";

    /** @var array<string, array<int, string>>|null The `mail.queue.*` rows: name => [organisation number (0 = the installation) => value]. */
    private static ?array $settingRows = null;

    /**
     * @var int How long ago the scheduled job may last have run and still
     *      count as running: 15 minutes, in exact seconds. The job is meant
     *      to run every five, so this is three missed runs.
     */
    public const JOB_RECENT_SECONDS = 900;

    /** @var bool|null What jobIsRunning() answered; null until it has been asked in this request. */
    private static ?bool $jobIsRunning = null;

    /**
     * @var array<string, array{0: class-string, 1: string}> Who is told what
     *      became of a message, by the first word of its `origin`: see
     *      tellOrigin(). A kind that is not listed ('reset') is a label only.
     */
    private const ORIGINS = [
        'newsletter' => [Newsletter::class, 'mailSettled'],
        'statement'  => [Giving::class, 'statementMailSettled'],
    ];

    // =========================================================================
    // 🔢 The four numbers
    // =========================================================================

    /**
     * Every `mail.queue.*` row, read once a request.
     *
     * Read straight from the database, as MailRoutes does, and for the same
     * reason: `$SETTINGS` is ONE organisation's view, and the job works
     * through every organisation's mail in one run.
     *
     * @return array<string, array<int, string>>
     */
    private static function settingRows(mysqli $db): array
    {
        if (self::$settingRows !== null) {
            return self::$settingRows;
        }
        $rows = [];
        try {
            $stmt = $db->prepare("SELECT settingKey, siteID, settingValue FROM tblSettings WHERE settingKey LIKE 'mail.queue.%'");
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $rows[(string) $row['settingKey']][(int) ($row['siteID'] ?? 0)] = trim((string) ($row['settingValue'] ?? ''));
            }
            $stmt->close();
        } catch (\Throwable $e) {
            // Nothing could be read: every number falls back to its default.
            error_log('MailQueue: the queue settings could not be read: ' . $e->getMessage());
        }
        self::$settingRows = $rows;

        return $rows;
    }

    /** Is this stored value a whole number inside the range for that setting? */
    private static function sensible(string $value, array $rule): bool
    {
        return preg_match('/\A[0-9]{1,4}\z/', $value) === 1 && (int) $value >= $rule['min'] && (int) $value <= $rule['max'];
    }

    /**
     * 🔢 The four numbers for one organisation's mail.
     *
     * The organisation's own value wins. A value that is not a sensible
     * number is NOT used: the installation's is used in its place, or the
     * built-in default when that is not sensible either, and
     * numberProblems() says so. An empty value means "not set" and is passed
     * over without complaint.
     *
     * @param int|null $siteId The organisation; null for mail that belongs to none.
     *
     * @return array{retryHours: int, maxAgeHours: int, keepFailedDays: int, holdDays: int}
     */
    public static function numbers(mysqli $db, ?int $siteId): array
    {
        $rows = self::settingRows($db);
        $out  = [];
        foreach (self::NUMBERS as $name => $rule) {
            $out[$name] = $rule['default'];
            foreach ([(int) ($siteId ?? 0), 0] as $scope) {
                $value = $rows[$rule['key']][$scope] ?? '';
                if ($value !== '' && self::sensible($value, $rule) === true) {
                    $out[$name] = (int) $value;
                    break;
                }
                if ($scope === 0) {
                    break; // the organisation and the installation were the same row
                }
            }
        }

        return $out;
    }

    /**
     * 📣 Every stored value that is being passed over, as plain sentences for
     * the health page. Empty when all is well.
     *
     * @return list<string>
     */
    public static function numberProblems(mysqli $db): array
    {
        $rows     = self::settingRows($db);
        $problems = [];
        foreach (self::NUMBERS as $rule) {
            foreach ($rows[$rule['key']] ?? [] as $scope => $value) {
                if ($value === '' || self::sensible($value, $rule) === true) {
                    continue;
                }
                $installation = $rows[$rule['key']][0] ?? '';
                $used         = ($scope !== 0 && $installation !== '' && self::sensible($installation, $rule) === true)
                    ? "the installation's value of " . (int) $installation
                    : 'the default of ' . $rule['default'];
                $problems[]   = 'The setting ' . $rule['key'] . ' (' . $rule['what'] . ') '
                    . ($scope === 0 ? 'for the whole installation' : 'of organisation number ' . $scope)
                    . ' is not a whole number from ' . $rule['min'] . ' to ' . $rule['max'] . ', so ' . $used . ' is used in its place.';
            }
        }

        return $problems;
    }

    /** Drop what was read from the settings and about the job, so the next call reads them afresh. */
    public static function forget(): void
    {
        self::$settingRows  = null;
        self::$jobIsRunning = null;
    }

    /**
     * 🔢 For the Settings page: may this value be stored as ONE ORGANISATION'S
     * own value for one of the four numbers? The same test numbers() applies
     * when it reads the row, so a value this accepts is a value the queue uses.
     *
     * @return array{ok: bool, words: string, installation: string}|null Null
     *         when `$key` is not, letter for letter, one of the four names:
     *         the page then treats the setting as it treats any other.
     *         Otherwise whether the value may be stored, a sentence for the
     *         person, and (also on its own, as `installation`) the sentence
     *         that says what the installation's value is. An empty value is
     *         not a number, so it is not `ok`: for a row that exists, the
     *         page removes the row instead.
     */
    public static function ownNumberCheck(mysqli $db, string $key, string $value): ?array
    {
        foreach (self::NUMBERS as $rule) {
            if ($rule['key'] !== $key) {
                continue;
            }
            $stored       = self::settingRows($db)[$key][0] ?? '';
            $installation = ($stored !== '' && self::sensible($stored, $rule) === true)
                ? 'The installation\'s value, which every organisation without its own uses, is ' . (int) $stored . '.'
                : 'The installation has no usable value of its own stored, so organisations without their own use the built-in ' . $rule['default'] . '.';
            if (self::sensible($value, $rule) === true) {
                return ['ok' => true, 'installation' => $installation,
                    'words' => 'This organisation\'s own value (' . $rule['what'] . ') is now ' . (int) $value . ', for its mail only. ' . $installation];
            }

            return ['ok' => false, 'installation' => $installation,
                'words' => 'Nothing was saved. "' . $key . '" (' . $rule['what'] . ') must be a whole number from ' . $rule['min'] . ' to ' . $rule['max'] . '. ' . $installation];
        }

        return null;
    }

    // =========================================================================
    // ⏰ Is the scheduled job running?
    // =========================================================================

    /**
     * ⏰ THE ONE QUESTION, ASKED IN THIS ONE PLACE: has the REAL scheduled job
     * run in the last 15 minutes?
     *
     * "The real job" is the hosting company's scheduler calling the job's own
     * address with the right token. Only that page records the moment
     * (`_apps/cron/mail-queue.php`, through MailRoutes::jobRan()). It used to
     * be recorded by every run of the queue, so one event broadcast, which
     * makes a run of its own, made the portal believe a job existed.
     *
     * WHY IT MATTERS. Only the job works through what is waiting. An
     * installation that has just been upgraded has no job until an
     * administrator adds one, and the first build of the queue made such an
     * installation worse than it had been: on PHP run by Apache no
     * password-reset e-mail went out at all, a mailing stopped at 25, and one
     * refused sign-in stopped all mail for ever. DELIVERY COMES BEFORE THE
     * SMALL TIMING DIFFERENCE. So while the answer here is NO, the portal
     * does as it did before the queue wherever that gets mail delivered:
     *   - "Forgot password" sends in the request, whatever the web server;
     *   - a request does not stop sending at 25 messages (it still stops
     *     after 20 seconds, so that the host cannot end it half-way);
     *   - a page may try the mail server again once the wait after a refused
     *     sign-in is over, which otherwise only the job does.
     * A failed certificate still holds mail either way (the owner's rule).
     * While the answer is YES, everything is as the queue was built.
     *
     * Read once a request. If the record cannot be read (the database has
     * not been upgraded to hold it), nothing says a job is running, and the
     * answer is no. A moment in the FUTURE is no as well: it can only come
     * from a database clock that has since been put right, and it would
     * otherwise read as "running" until that moment came round, however long
     * that was.
     *
     * WHAT THIS CANNOT DO: tell a job that was removed a minute ago from one
     * that is running (it finds out within 15 minutes), or know that the job
     * is set to run less often than every 15 minutes: such a job reads as
     * "not running" between its runs, and the health page says so.
     */
    public static function jobIsRunning(mysqli $db): bool
    {
        if (self::$jobIsRunning !== null) {
            return self::$jobIsRunning;
        }
        $running = false;
        try {
            $key    = MailRoutes::ROUTE_KEY;
            $recent = self::JOB_RECENT_SECONDS;
            $stmt   = $db->prepare('SELECT 1 FROM tblMailRouteState WHERE routeKey = ? AND jobLastRanAt > UTC_TIMESTAMP() - INTERVAL ? SECOND AND jobLastRanAt <= UTC_TIMESTAMP()');
            $stmt->bind_param('si', $key, $recent);
            $stmt->execute();
            $running = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
        } catch (\Throwable $e) {
            // See above: not readable means "no".
        }

        return self::$jobIsRunning = $running;
    }

    // =========================================================================
    // 🧾 What a caller of Mailer::send() asked for
    // =========================================================================

    /**
     * Read the fifth argument of Mailer::send().
     *
     * @param array<string, mixed> $options   `priority` ('urgent', 'normal',
     *        'bulk'), `expiresAt` (a moment in UTC as 'Y-m-d H:i:s': the
     *        message is never sent at or after it), `repeatSafe` (true when a
     *        second copy does no harm), `sendNow` (false: do not try in
     *        this request, keep it for the job) and `origin` (a kind and a
     *        number, such as 'newsletter:12': the record to correct when a
     *        kept message goes or fails; anything of another shape is
     *        ignored).
     * @param int                  $nthInRequest Which message of this request
     *        this is, counting from 1.
     *
     * @return array{priority: int, expiresAt: string|null, repeatSafe: bool, sendNow: bool, canBeKept: bool, origin: string}
     *         `canBeKept` is false when `expiresAt` was given and cannot be
     *         read. Such a message may be tried now, but is never kept:
     *         kept without its use-by moment, a reset link could go out
     *         after it had stopped working.
     */
    public static function wanted(array $options, int $nthInRequest): array
    {
        $word     = is_string($options['priority'] ?? null) === true ? strtolower($options['priority']) : '';
        // The fourth and later message of one request is bulk unless its
        // caller said otherwise: one request sending many is a mailing, and
        // must not sit in front of somebody's reset link.
        $priority = self::PRIORITIES[$word] ?? ($nthInRequest >= 4 ? self::BULK : self::NORMAL);

        $expiresAt = null;
        $canBeKept = true;
        if (array_key_exists('expiresAt', $options) === true && $options['expiresAt'] !== null && $options['expiresAt'] !== '') {
            $given = $options['expiresAt'];
            if (is_string($given) === true && preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $given) === 1) {
                $expiresAt = $given;
            } else {
                $canBeKept = false;
            }
        }

        return [
            'priority'   => $priority,
            'expiresAt'  => $expiresAt,
            'repeatSafe' => ($options['repeatSafe'] ?? false) === true,
            'sendNow'    => ($options['sendNow'] ?? true) !== false,
            'canBeKept'  => $canBeKept,
            'origin'     => (is_string($options['origin'] ?? null) === true && preg_match('/\A[a-z]{1,20}:[0-9]{1,18}\z/', $options['origin']) === 1) ? $options['origin'] : '',
        ];
    }

    /**
     * 🕰️ A use-by moment for Mailer::send()'s `expiresAt`, from a time on a
     * clock and the zone that clock is in ("the event starts at 10:00,
     * Europe/London"). The answer is that moment in UTC.
     *
     * WHY HERE. Most of what the portal knows as "when" is a time on a
     * clock: an event's start, the end of a day. The queue compares moments
     * in UTC, so each caller would otherwise write this conversion itself,
     * and one of them would forget the zone.
     *
     * WHICH ZONE. `$zone` first; when PHP does not know it, `$otherwise`.
     * The event form's zone box is free text, so an event can hold a zone
     * that is mistyped: its reminder is then worked out on the
     * ORGANISATION's own clock, which is the best the portal knows, and is
     * still kept. REJECTED: falling back to a zone written into the code
     * (London, UTC). This is a product: no zone is assumed.
     *
     * When the time cannot be read, or NEITHER zone can, the answer is text
     * that is NOT a moment, on purpose: the queue then refuses to KEEP the
     * message (it may still go at once). REJECTED: answering null, "no
     * use-by moment". A reminder kept without one could go out after its
     * event. (An organisation's zone is checked when it is saved, so
     * "neither" needs one that was put there some other way.)
     *
     * WHAT THIS CANNOT DO: on the night the clocks go back, one stretch of
     * time on the clock happens twice, and nothing here says which of the
     * two a time in it means. PHP chooses, and its choice depends on the
     * zone. Run on PHP 8.5.10: in London, Paris, Lisbon, Sydney, Lord Howe
     * and Auckland it takes the SECOND happening, so the use-by moment is up
     * to an hour LATE (half an hour on Lord Howe) and a kept message for
     * something starting in that stretch could go that much after its
     * start; in New York, Los Angeles, Santiago, St John's and the Azores it
     * takes the FIRST, so the moment is up to an hour early. (This note used
     * to say "the first, so up to an hour early" for every zone, which is
     * untrue for the first group.) On the night the clocks go forward, a
     * time in the stretch that does not exist is taken as PHP reads it
     * (moved on by the missing stretch).
     *
     * @param string $wallClock 'Y-m-d H:i:s' (or 'Y-m-d', meaning the start
     *                          of that day), as a clock in the zone shows it.
     * @param string $zone      A zone name, such as an event's own.
     * @param string $otherwise The zone to use when PHP does not know
     *                          `$zone`: the organisation's own. '' for none.
     */
    public static function useBy(string $wallClock, string $zone, string $otherwise = ''): string
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?\z/', $wallClock) !== 1) {
            return 'not known';
        }
        foreach ([$zone, $otherwise] as $name) {
            if (trim($name) === '') {
                continue;
            }
            try {
                $moment = new \DateTimeImmutable($wallClock, new \DateTimeZone(trim($name)));
            } catch (\Throwable $e) {
                continue; // not a zone PHP knows (or not a real time): try the next
            }

            return $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }

        return 'not known';
    }

    /**
     * 🌐 Can this web server let the browser go BEFORE the script has ended?
     *
     * "Forgot password" answers first and sends afterwards, so that how long
     * the answer takes does not tell a stranger whether an address has an
     * account. That only works where the answer really leaves early:
     *   - PHP-FPM (`fastcgi_finish_request()`), and LiteSpeed
     *     (`litespeed_finish_request()`): yes, by design.
     *   - PHP's built-in server, used by the self-tests: yes. An answer with
     *     its exact length and "Connection: close" is let go at once.
     *   - Anything else (PHP run by Apache as a module, through mod_fcgid or
     *     as a CGI program): NOT KNOWN, so the answer here is no. Some of
     *     those do let the browser go; none promises to.
     *
     * Where the answer is no AND the scheduled job is running
     * (jobIsRunning()), the page asks for the message to be kept for the job
     * (`sendNow` false), so nothing is sent inside the request and the reset
     * e-mail goes at the job's next run. Where the answer is no and the job
     * is NOT running, the page sends in the request after all: a reset that
     * arrives matters more than the small difference in timing. (It used to
     * keep the message whatever the job was doing, so on such a server no
     * reset e-mail went out at all until somebody added the job.)
     *
     * WHAT THIS CANNOT DO: see a proxy or a compressing layer in front of
     * PHP-FPM that holds an answer back. With `fastcgi_finish_request()` the
     * answer is complete, so such a layer has nothing left to wait for.
     */
    public static function pageCanAnswerFirst(): bool
    {
        return function_exists('fastcgi_finish_request') === true
            || function_exists('litespeed_finish_request') === true
            || PHP_SAPI === 'cli-server';
    }

    // =========================================================================
    // 🔐 The stored body
    // =========================================================================

    /** Encrypt one version of a body. Throws when the installation's key file is missing. */
    private static function seal(string $text): string
    {
        return Auth::encrypt(self::SEAL . $text);
    }

    /** Decrypt one version of a body. Null when it cannot be read on this installation. */
    private static function unseal(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        $plain = Auth::decrypt($stored);

        return str_starts_with($plain, self::SEAL) === true ? substr($plain, strlen(self::SEAL)) : null;
    }

    // =========================================================================
    // 📎 Attached files
    // =========================================================================

    /** The folder the copies live in. Outside the web folder, like everything under _uploads. */
    public static function filesFolder(): string
    {
        return PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'mail-queue';
    }

    /**
     * Copy a message's files, each into a folder of its own with a random
     * name, keeping the file's own name inside it.
     *
     * A COPY, because the caller's file may be gone by the time the job runs
     * (a giving statement's PDF is removed once it has been handed over).
     * REJECTED: storing the caller's path. The file's own name is kept inside
     * the random folder so that every mail route attaches it under the name
     * the recipient should see; the random part is the folder.
     *
     * @param list<string> $paths
     *
     * @return list<array{storedName: string, realName: string, contentType: string, sizeBytes: int}>
     *
     * @throws \UnexpectedValueException In plain words, when the fault is
     *         in the MESSAGE: a file to attach is missing, empty or too big.
     *         Keeping it could never work, so the caller treats the message
     *         as refused.
     * @throws \RuntimeException When the fault is on this server (the folder
     *         cannot be made, the copy fails). Whatever had been copied is
     *         removed first, in both cases.
     */
    private static function copyFiles(array $paths): array
    {
        $copies = [];
        $total  = 0;
        try {
            foreach ($paths as $path) {
                $path = (string) $path;
                if (is_file($path) === false || is_readable($path) === false) {
                    throw new \UnexpectedValueException('a file to attach is missing or cannot be read');
                }
                $size   = (int) filesize($path);
                $total += $size;
                if ($size < 1 || $size > self::MAX_FILE_BYTES || $total > self::MAX_MESSAGE_BYTES) {
                    throw new \UnexpectedValueException('a file to attach is empty or too big (10 MB a file and 20 MB a message at most)');
                }
                // The name the recipient sees: the file's own, with anything
                // that could step outside the folder taken out.
                $name = (string) preg_replace('/[\x00-\x1F\x7F\/\\\\]/', '', basename($path));
                $name = ($name === '' || $name === '.' || $name === '..') ? 'attachment' : mb_substr($name, 0, 200);

                $stored = bin2hex(random_bytes(16));
                $folder = self::filesFolder() . DIRECTORY_SEPARATOR . $stored;
                if (@mkdir($folder, 0700, true) === false && is_dir($folder) === false) {
                    throw new \RuntimeException('the folder for attached files could not be made');
                }
                $copies[] = ['storedName' => $stored, 'realName' => $name, 'contentType' => '', 'sizeBytes' => $size];
                if (@copy($path, $folder . DIRECTORY_SEPARATOR . $name) === false || (int) @filesize($folder . DIRECTORY_SEPARATOR . $name) !== $size) {
                    throw new \RuntimeException('a file to attach could not be copied');
                }
                $type = function_exists('mime_content_type') === true ? @mime_content_type($path) : false;
                $copies[count($copies) - 1]['contentType'] = is_string($type) === true ? mb_substr($type, 0, 120) : '';
            }
        } catch (\RuntimeException $e) {
            self::removeFolders(array_column($copies, 'storedName'));

            throw $e;
        }

        return $copies;
    }

    /**
     * Remove copies by their folder names. Only a name that is exactly 32
     * hexadecimal characters is touched, so nothing outside the queue's own
     * folder can be named.
     *
     * @param list<string> $storedNames
     */
    private static function removeFolders(array $storedNames): void
    {
        foreach ($storedNames as $stored) {
            if (preg_match('/\A[0-9a-f]{32}\z/', (string) $stored) !== 1) {
                continue;
            }
            $folder = self::filesFolder() . DIRECTORY_SEPARATOR . $stored;
            foreach ((array) @scandir($folder) as $entry) {
                if ($entry !== '.' && $entry !== '..' && is_string($entry) === true) {
                    @unlink($folder . DIRECTORY_SEPARATOR . $entry);
                }
            }
            @rmdir($folder);
        }
    }

    /** @return list<array{storedName: string, realName: string, contentType: string, sizeBytes: int}> The file rows of one message. */
    private static function fileRows(mysqli $db, int $queueId): array
    {
        $stmt = $db->prepare('SELECT storedName, realName, contentType, sizeBytes FROM tblMailQueueFiles WHERE queueID = ? ORDER BY queueFileID');
        $stmt->bind_param('i', $queueId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_map(static fn (array $r): array => [
            'storedName' => (string) $r['storedName'], 'realName' => (string) $r['realName'],
            'contentType' => (string) $r['contentType'], 'sizeBytes' => (int) $r['sizeBytes'],
        ], $rows);
    }

    // =========================================================================
    // ➕ Keeping a message
    // =========================================================================

    /**
     * 🔀 NEW ROW: keep one message, as 'queued' or as 'held'.
     *
     * With files, the row is first written as taken ('sending', stage
     * 'storing') under a mark of its own, the file rows are added, and only
     * then is it given its real status. So the job can never take a message
     * whose files are only half recorded, and no transaction is opened here.
     * REJECTED: a transaction. Starting one would quietly commit whatever
     * transaction the caller was in the middle of.
     * If the request dies between those steps, the row is put back in the
     * queue ten minutes later like any other dead claim, and the job then
     * finds fewer files than `attachmentCount` says and FAILS it in words. It
     * is never sent without a file.
     *
     * @param array{siteId: int|null, priority: int, to: list<string>, subject: string, html: string, text: string,
     *              files: list<string>, expiresAt: string|null, repeatSafe: bool, status?: string, holdReason?: string,
     *              lastStage?: string, lastError?: string, messageId?: string, attempts?: int, waitSeconds?: int, origin?: string} $message
     *        `status` is 'queued' (the default) or 'held'. `attempts` is 1
     *        when the request has already tried once. `waitSeconds` is how
     *        long before the first try by the job (0: at its next run).
     *        `origin` is the record to correct when the message goes or
     *        fails: see wanted().
     *
     * @return int The new row's number.
     *
     * @throws \UnexpectedValueException When a file to attach is missing,
     *         empty or too big: the message itself is at fault.
     * @throws \Throwable When the message cannot be kept for any other
     *         reason (the tables are not there yet, the key file is
     *         missing). Nothing is left behind either way. The caller says
     *         what that means.
     */
    public static function add(mysqli $db, array $message): int
    {
        $to       = array_values(array_filter(array_map('trim', $message['to']), static fn (string $a): bool => $a !== ''));
        $status   = ($message['status'] ?? 'queued') === 'held' ? 'held' : 'queued';
        $html     = self::seal($message['html']);
        $text     = self::seal($message['text']);
        $copies   = self::copyFiles($message['files']);
        $withFiles = $copies !== [];

        // A message with files starts as taken by this request itself.
        $firstStatus = $withFiles === true ? 'sending' : $status;
        $stage       = $withFiles === true ? 'storing' : '';
        $mark        = $withFiles === true ? bin2hex(random_bytes(16)) : '';

        $siteId     = $message['siteId'];
        $priority   = $message['priority'];
        $holdReason = $status === 'held' ? (string) ($message['holdReason'] ?? '') : '';
        $lastStage  = mb_substr((string) ($message['lastStage'] ?? ''), 0, 20);
        $lastError  = mb_substr((string) ($message['lastError'] ?? ''), 0, 500);
        $attempts   = (int) ($message['attempts'] ?? 0);
        $wait       = max(0, (int) ($message['waitSeconds'] ?? 0));
        $expiresAt  = $message['expiresAt'];
        $recipients = implode(', ', $to);
        $count      = min(65535, count($to));
        $subject    = mb_substr($message['subject'], 0, 500);
        // The message's identifier is fixed NOW, before any attempt by the
        // job, so that every attempt carries the same one: see
        // MailRoutes::newMessageId(). A message the request has already
        // tried once brings the one it was tried under.
        $messageId  = (string) ($message['messageId'] ?? '');
        $messageId  = mb_substr($messageId !== '' ? $messageId : MailRoutes::newMessageId(), 0, 330);
        $repeatSafe = $message['repeatSafe'] === true ? 1 : 0;
        $fileCount  = count($copies);
        $origin     = mb_substr((string) ($message['origin'] ?? ''), 0, 60);

        try {
            $stmt = $db->prepare(
                'INSERT INTO tblMailQueue (siteID, priority, status, stage, holdReason, lastStage, lastError, attempts, nextAttemptAt, expiresAt, '
                . 'createdAt, statusAt, claimedAt, claimToken, toRecipients, recipientCount, attachmentCount, subject, messageID, bodyHtml, bodyText, repeatSafe, origin) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? SECOND, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), '
                . "IF(? = '', NULL, UTC_TIMESTAMP()), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                'iisssssiissssiissssis',
                $siteId, $priority, $firstStatus, $stage, $holdReason, $lastStage, $lastError, $attempts, $wait, $expiresAt,
                $mark, $mark, $recipients, $count, $fileCount, $subject, $messageId, $html, $text, $repeatSafe, $origin
            );
            $stmt->execute();
            $queueId = (int) $stmt->insert_id;
            $stmt->close();

            if ($withFiles === true) {
                $file = $db->prepare('INSERT INTO tblMailQueueFiles (queueID, storedName, realName, contentType, sizeBytes) VALUES (?, ?, ?, ?, ?)');
                foreach ($copies as $copy) {
                    $file->bind_param('isssi', $queueId, $copy['storedName'], $copy['realName'], $copy['contentType'], $copy['sizeBytes']);
                    $file->execute();
                }
                $file->close();
                // Its files are all recorded: give it its real status.
                $done = $db->prepare("UPDATE tblMailQueue SET status = ?, stage = '', claimToken = '', claimedAt = NULL, statusAt = UTC_TIMESTAMP() WHERE queueID = ? AND claimToken = ?");
                $done->bind_param('sis', $status, $queueId, $mark);
                $done->execute();
                $done->close();
            }
        } catch (\Throwable $e) {
            // The copies belong to a row that was not written, or not finished.
            self::removeFolders(array_column($copies, 'storedName'));
            if (isset($queueId) === true) {
                try {
                    $undo = $db->prepare('DELETE FROM tblMailQueue WHERE queueID = ? AND claimToken = ?');
                    $undo->bind_param('is', $queueId, $mark);
                    $undo->execute();
                    $undo->close();
                } catch (\Throwable $ignored) {
                    // The row stays as a dead claim and is failed in words later: see above.
                }
            }

            throw $e;
        }

        return $queueId;
    }

    // =========================================================================
    // 📣 The record a message came from
    // =========================================================================

    /**
     * 📣 Tell the record a message came from what became of it: it WENT (a
     * mail service accepted it), or it will never go (failed, or expired).
     *
     * WHY. A newsletter and a giving statement each keep a record of their
     * own, per person. When their message was only KEPT, that record used to
     * say "delivered" and "e-mailed" at once, and nothing corrected it when
     * the message later failed. Now the record says "waiting" (the caller
     * writes that), and this is what corrects it. Called from the three
     * places a kept message reaches its end: handle() when it is sent, and
     * fail() and expire().
     *
     * Never throws: what became of the MESSAGE is already settled, and a
     * record that cannot be corrected must not undo that. The reason goes to
     * the server's error log, and the record goes on saying "waiting".
     *
     * A ROW THAT IS DELETED WITHOUT BEING SENT. An erasure tells the record
     * "did not go" BEFORE it deletes the row (eraseAddressNow()): a giving
     * statement's record is KEPT when its donor is erased, and it used to
     * say "waiting" for ever. (This note used to say the person's own
     * records are erased with the message. That is untrue for a statement.)
     * WHAT THIS CANNOT DO: tell anybody when removeWaitingFrom() deletes a
     * row. Only a password reset uses it today, and a reset has no record.
     *
     * @param string $origin As stored: 'newsletter:12'. '' means nobody is waiting to hear.
     * @param string $to     The recipients, as stored.
     */
    private static function tellOrigin(mysqli $db, string $origin, string $to, bool $went): void
    {
        if (preg_match('/\A([a-z]{1,20}):([0-9]{1,18})\z/', $origin, $found) !== 1 || isset(self::ORIGINS[$found[1]]) === false) {
            return;
        }
        try {
            (self::ORIGINS[$found[1]])($db, (int) $found[2], $went, $to);
        } catch (\Throwable $e) {
            error_log('MailQueue: the record a message came from (' . $origin . ') could not be told that the message ' . ($went === true ? 'went' : 'did not go') . ': ' . $e->getMessage());
        }
    }

    /** 📣 The same, for a row that is still in the table: fail() and expire() know only its number. */
    private static function tellOriginOf(mysqli $db, int $queueId, bool $went): void
    {
        $stmt = $db->prepare("SELECT origin, toRecipients FROM tblMailQueue WHERE queueID = ? AND origin <> ''");
        $stmt->bind_param('i', $queueId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row !== null) {
            self::tellOrigin($db, (string) $row['origin'], (string) $row['toRecipients'], $went);
        }
    }

    /**
     * 🔀 DELETE every waiting message that came from one record, with a line
     * in the send log for each. For "Forgot password": a new reset link makes
     * the person's older links stop working, so their older reset messages,
     * if any are still waiting, would arrive with dead links. Three resets
     * asked for while the mail server was down used to arrive together, two
     * of them useless.
     *
     * WHAT THIS CANNOT DO: take back a message a run of the job holds at
     * this moment (status 'sending'). That one goes, or comes back to wait
     * and is then caught by its own use-by moment at the latest.
     *
     * @param string $origin As given to Mailer::send(): 'reset:5'.
     *
     * @return int How many were removed.
     */
    public static function removeWaitingFrom(mysqli $db, string $origin): int
    {
        if ($origin === '') {
            return 0;
        }
        $stmt = $db->prepare("SELECT queueID, siteID, toRecipients, subject, attachmentCount FROM tblMailQueue WHERE origin = ? AND status IN ('queued', 'held') ORDER BY queueID");
        $stmt->bind_param('s', $origin);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $removed = 0;
        foreach ($rows as $row) {
            if (self::remove($db, (int) $row['queueID'], "status IN ('queued', 'held')") === true) {
                $removed++;
                Mailer::logQueueEvent($row, 'expired', 'replaced', 'Not sent: a newer message took its place before it could go, and what this one carried no longer works.');
            }
        }

        return $removed;
    }

    // =========================================================================
    // 🔀 The statements that move a row (in the order a run uses them)
    // =========================================================================

    /**
     * 🔀 A DEAD RUN'S ROWS. Every row a run took more than ten minutes ago
     * and never finished with.
     *
     *   stage 'claimed' or 'storing'  -> back to 'queued'. The message had
     *                                    not been given to a mail server.
     *   stage 'handing-over'          -> NOBODY CAN KNOW whether the server
     *                                    took it. It is HELD for a person
     *                                    ('interrupted') and not sent again,
     *                                    unless its caller said a second copy
     *                                    does no harm, in which case it goes
     *                                    back to 'queued'.
     *
     * REJECTED: sending every dead claim back to 'queued'. A run killed just
     * after the mail server said "accepted" would then send a second copy of
     * a statement or a newsletter to everybody it had reached.
     *
     * @return array{returned: int, held: int}
     */
    private static function recoverDeadClaims(mysqli $db): array
    {
        $old = self::STALE_CLAIM_SECONDS;

        // Read first, so each held message gets its line in the send log.
        $read = $db->prepare(
            "SELECT queueID, siteID, toRecipients, subject, attachmentCount FROM tblMailQueue WHERE status = 'sending' AND stage = 'handing-over' "
            . 'AND repeatSafe = 0 AND claimedAt <= UTC_TIMESTAMP() - INTERVAL ? SECOND'
        );
        $read->bind_param('i', $old);
        $read->execute();
        $interrupted = $read->get_result()->fetch_all(MYSQLI_ASSOC);
        $read->close();

        $words = 'Held: a run of the mail job was interrupted while handing this message to the mail server, and nobody can tell whether it went. It is not sent again by itself.';
        $held  = 0;
        foreach ($interrupted as $row) {
            $id   = (int) $row['queueID'];
            $hold = $db->prepare(
                "UPDATE tblMailQueue SET status = 'held', holdReason = ?, stage = '', claimToken = '', claimedAt = NULL, lastError = ?, statusAt = UTC_TIMESTAMP() "
                . "WHERE queueID = ? AND status = 'sending' AND stage = 'handing-over' AND repeatSafe = 0 AND claimedAt <= UTC_TIMESTAMP() - INTERVAL ? SECOND"
            );
            $reason = self::HELD_INTERRUPTED;
            $hold->bind_param('ssii', $reason, $words, $id, $old);
            $hold->execute();
            if ($hold->affected_rows === 1) {
                $held++;
                Mailer::logQueueEvent($row, 'held', self::HELD_INTERRUPTED, $words);
            }
            $hold->close();
        }

        // Everything else that is dead goes back to wait its turn.
        $back = $db->prepare(
            "UPDATE tblMailQueue SET status = 'queued', stage = '', claimToken = '', claimedAt = NULL, nextAttemptAt = UTC_TIMESTAMP(), statusAt = UTC_TIMESTAMP() "
            . "WHERE status = 'sending' AND claimedAt <= UTC_TIMESTAMP() - INTERVAL ? SECOND AND (stage <> 'handing-over' OR repeatSafe = 1)"
        );
        $back->bind_param('i', $old);
        $back->execute();
        $returned = (int) $back->affected_rows;
        $back->close();

        return ['returned' => $returned, 'held' => $held];
    }

    /**
     * 🔀 TIME IS UP. Four rules, each with the numbers of the organisation
     * the message belongs to:
     *
     *   queued or held, past its use-by moment -> 'expired', and its body is
     *                                             removed at once;
     *   queued, older than the longest wait    -> 'failed' (nothing that old
     *                                             is ever sent);
     *   held longer than the holding period    -> 'failed';
     *   failed or expired longer than the
     *   keeping period                         -> DELETED, files included.
     *
     * A HELD message older than the longest wait is NOT failed here: it stays
     * where an administrator can see it for the whole holding period. It can
     * still never be sent: see handOver().
     *
     * @return array{expired: int, failed: int, removed: int}
     */
    private static function sweep(mysqli $db): array
    {
        $done = ['expired' => 0, 'failed' => 0, 'removed' => 0];

        // 1. Past its use-by moment. The same for every organisation.
        $rows = self::rowsWhere($db, "status IN ('queued', 'held') AND expiresAt IS NOT NULL AND expiresAt <= UTC_TIMESTAMP()");
        foreach ($rows as $row) {
            if (self::expire($db, (int) $row['queueID'], "status IN ('queued', 'held')") === true) {
                $done['expired']++;
                Mailer::logQueueEvent($row, 'expired', 'expired', 'Not sent: its use-by moment passed before it could go.');
            }
        }

        // 2 to 4. One organisation at a time, because each may have its own numbers.
        $sites = $db->query('SELECT DISTINCT siteID FROM tblMailQueue');
        $ids   = [];
        while ($site = $sites->fetch_assoc()) {
            $ids[] = $site['siteID'] === null ? null : (int) $site['siteID'];
        }
        $sites->free();
        foreach ($ids as $siteId) {
            $n      = self::numbers($db, $siteId);
            $ofSite = $siteId === null ? 'siteID IS NULL' : 'siteID = ' . $siteId;

            $tooOld = self::rowsWhere($db, "status = 'queued' AND " . $ofSite . ' AND createdAt <= UTC_TIMESTAMP() - INTERVAL ' . ($n['maxAgeHours'] * 3600) . ' SECOND');
            foreach ($tooOld as $row) {
                $words = 'Not sent: it waited longer than ' . $n['maxAgeHours'] . ' hours, and nothing that old is sent.';
                if (self::fail($db, (int) $row['queueID'], "status = 'queued'", 'too-old', $words) === true) {
                    $done['failed']++;
                    Mailer::logQueueEvent($row, 'failed', 'too-old', $words);
                }
            }

            $heldTooLong = self::rowsWhere($db, "status = 'held' AND " . $ofSite . ' AND statusAt <= UTC_TIMESTAMP() - INTERVAL ' . ($n['holdDays'] * self::KEEPING_DAY_SECONDS) . ' SECOND');
            foreach ($heldTooLong as $row) {
                $words = 'Not sent: it was held for ' . $n['holdDays'] . ' days and nobody released it.';
                if (self::fail($db, (int) $row['queueID'], "status = 'held'", 'held-too-long', $words) === true) {
                    $done['failed']++;
                    Mailer::logQueueEvent($row, 'failed', 'held-too-long', $words);
                }
            }

            $finished = self::rowsWhere($db, "status IN ('failed', 'expired') AND " . $ofSite . ' AND statusAt <= UTC_TIMESTAMP() - INTERVAL ' . ($n['keepFailedDays'] * self::KEEPING_DAY_SECONDS) . ' SECOND');
            foreach ($finished as $row) {
                $done['removed'] += self::remove($db, (int) $row['queueID'], "status IN ('failed', 'expired')") === true ? 1 : 0;
            }
        }

        return $done;
    }

    /**
     * What a sweep needs to know of each row it is about to move. Never the
     * body. `$where` is fixed text written in this class, never a value from
     * outside.
     *
     * @return list<array<string, mixed>>
     */
    private static function rowsWhere(mysqli $db, string $where): array
    {
        $result = $db->query('SELECT queueID, siteID, toRecipients, subject, attachmentCount FROM tblMailQueue WHERE ' . $where . ' ORDER BY queueID LIMIT 500');
        $rows   = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();

        return $rows;
    }

    /**
     * 🔀 -> 'expired'. The body goes at once: an expired reset message holds
     * a link, and there is no reason to keep it a moment longer. So do its
     * files. `$from` is the state it must still be in (fixed text).
     */
    private static function expire(mysqli $db, int $queueId, string $from): bool
    {
        $files = array_column(self::fileRows($db, $queueId), 'storedName');
        $stmt  = $db->prepare(
            "UPDATE tblMailQueue SET status = 'expired', stage = '', holdReason = '', claimToken = '', claimedAt = NULL, bodyHtml = NULL, bodyText = NULL, "
            . "lastError = 'Not sent: its use-by moment passed before it could go.', statusAt = UTC_TIMESTAMP() WHERE queueID = ? AND " . $from
        );
        $stmt->bind_param('i', $queueId);
        $stmt->execute();
        $moved = $stmt->affected_rows === 1;
        $stmt->close();
        if ($moved === true) {
            self::tellOriginOf($db, $queueId, false);
        }
        if ($moved === true && $files !== []) {
            $gone = $db->prepare('DELETE FROM tblMailQueueFiles WHERE queueID = ?');
            $gone->bind_param('i', $queueId);
            $gone->execute();
            $gone->close();
            self::removeFolders($files);
        }

        return $moved;
    }

    /** 🔀 -> 'failed', with the reason in words. `$from` is the state it must still be in (fixed text). */
    private static function fail(mysqli $db, int $queueId, string $from, string $stage, string $words): bool
    {
        $stage = mb_substr($stage, 0, 20);
        $words = mb_substr($words, 0, 500);
        $stmt  = $db->prepare(
            "UPDATE tblMailQueue SET status = 'failed', stage = '', holdReason = '', claimToken = '', claimedAt = NULL, lastStage = ?, lastError = ?, statusAt = UTC_TIMESTAMP() "
            . 'WHERE queueID = ? AND ' . $from
        );
        $stmt->bind_param('ssi', $stage, $words, $queueId);
        $stmt->execute();
        $moved = $stmt->affected_rows === 1;
        $stmt->close();
        if ($moved === true) {
            self::tellOriginOf($db, $queueId, false);
        }

        return $moved;
    }

    /**
     * 🔀 DELETE a row and its files. Used when a message has been sent, and
     * when a failed or expired one has been kept long enough. The row goes
     * FIRST, so that whatever happens next it cannot be sent again; a copy
     * left behind by a failure here is cleared up later as a stray.
     */
    private static function remove(mysqli $db, int $queueId, string $from): bool
    {
        $files = array_column(self::fileRows($db, $queueId), 'storedName');
        $stmt  = $db->prepare('DELETE FROM tblMailQueue WHERE queueID = ? AND ' . $from);
        $stmt->bind_param('i', $queueId);
        $stmt->execute();
        $gone = $stmt->affected_rows === 1;
        $stmt->close();
        if ($gone === true) {
            self::removeFolders($files);
        }

        return $gone;
    }

    /**
     * 🔀 THE CLAIM: 'queued' -> 'sending' (stage 'claimed'), for up to
     * `$limit` rows that are due and still wanted, most urgent and oldest
     * first.
     *
     * ONE STATEMENT, with a random mark. The database gives each row to one
     * statement only, so two runs can never take the same row, whether or
     * not the one-run-at-a-time lock is held. REJECTED: reading the due rows
     * and then marking them. Two runs then read the same rows before either
     * has marked them, and both send them.
     *
     * Public so that the self-test can run several claims at once without
     * the lock, which is the only way to prove the claim alone is safe.
     *
     * @param int $priorityUpTo Only rows at least this urgent: URGENT when
     *                          the hourly limit has room for nothing else.
     *
     * @return array{token: string, rows: list<array<string, mixed>>}
     */
    public static function claim(mysqli $db, int $limit, int $priorityUpTo = self::BULK): array
    {
        $token = bin2hex(random_bytes(16));
        $stmt  = $db->prepare(
            "UPDATE tblMailQueue SET status = 'sending', stage = 'claimed', claimToken = ?, claimedAt = UTC_TIMESTAMP(), statusAt = UTC_TIMESTAMP() "
            . "WHERE status = 'queued' AND nextAttemptAt <= UTC_TIMESTAMP() AND " . self::STILL_WANTED . ' AND priority <= ? '
            . 'ORDER BY priority, createdAt, queueID LIMIT ?'
        );
        $stmt->bind_param('sii', $token, $priorityUpTo, $limit);
        $stmt->execute();
        $taken = (int) $stmt->affected_rows;
        $stmt->close();

        return ['token' => $token, 'rows' => $taken > 0 ? self::claimedRows($db, $token) : []];
    }

    /**
     * The rows a run holds, in the order it will work through them.
     *
     * `createdEpoch` is the row's creation moment as seconds since 1970,
     * worked out by the database from two UTC values. REJECTED:
     * UNIX_TIMESTAMP(createdAt), which reads the stored value as if it were
     * in the connection's own time zone and is an hour out in summer.
     *
     * @return list<array<string, mixed>>
     */
    private static function claimedRows(mysqli $db, string $token): array
    {
        $stmt = $db->prepare(
            'SELECT queueID, siteID, priority, attempts, toRecipients, recipientCount, attachmentCount, subject, messageID, bodyHtml, bodyText, repeatSafe, origin, '
            . "TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', createdAt) AS createdEpoch "
            . "FROM tblMailQueue WHERE claimToken = ? AND status = 'sending' ORDER BY priority, createdAt, queueID"
        );
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * 🔀 TAKE ONE HELD MESSAGE TO TRY THE CERTIFICATE WITH: 'held'
     * ('certificate') -> 'sending' (stage 'claimed'). While a certificate
     * fault is on record every run tries exactly one checked connection, and
     * this is the message it tries it with.
     *
     * @return array{token: string, rows: list<array<string, mixed>>}
     */
    private static function claimOneHeldForCertificate(mysqli $db): array
    {
        $token  = bin2hex(random_bytes(16));
        $reason = self::HELD_CERTIFICATE;
        $stmt   = $db->prepare(
            "UPDATE tblMailQueue SET status = 'sending', stage = 'claimed', holdReason = '', claimToken = ?, claimedAt = UTC_TIMESTAMP(), statusAt = UTC_TIMESTAMP() "
            . "WHERE status = 'held' AND holdReason = ? AND " . self::STILL_WANTED . ' ORDER BY priority, createdAt, queueID LIMIT 1'
        );
        $stmt->bind_param('ss', $token, $reason);
        $stmt->execute();
        $taken = (int) $stmt->affected_rows;
        $stmt->close();

        return ['token' => $token, 'rows' => $taken > 0 ? self::claimedRows($db, $token) : []];
    }

    /**
     * 🔀 JUST BEFORE THE MAIL SERVER IS GIVEN THE MESSAGE: stage 'claimed'
     * -> 'handing-over', and the attempt is counted.
     *
     * Marked and counted BEFORE the send, never after. A send that kills the
     * run (a fatal error, the web server ending the request) leaves no
     * result at all; the row found in this state later is "nobody knows".
     * And without counting first, one message that kills the job would stop
     * it on every run for ever.
     *
     * This is also the LAST CHECK that the message may still go: it must
     * still be this run's, still be wanted, and be younger than the longest
     * wait for its organisation. Asked of the database's clock at this very
     * moment, so a reset link that runs out while the run is working through
     * its batch is still caught.
     *
     * @return bool False when the row may not be sent. The caller finds out why.
     */
    private static function handOver(mysqli $db, int $queueId, string $token, int $maxAgeHours): bool
    {
        $maxAge = $maxAgeHours * 3600;
        $stmt   = $db->prepare(
            "UPDATE tblMailQueue SET stage = 'handing-over', attempts = attempts + 1 "
            . "WHERE queueID = ? AND claimToken = ? AND status = 'sending' AND stage = 'claimed' AND " . self::STILL_WANTED
            . ' AND createdAt > UTC_TIMESTAMP() - INTERVAL ? SECOND'
        );
        $stmt->bind_param('isi', $queueId, $token, $maxAge);
        $stmt->execute();
        $marked = $stmt->affected_rows === 1;
        $stmt->close();

        return $marked;
    }

    /**
     * 🔀 PUT BACK UNTRIED: 'sending' (stage 'claimed') -> 'queued', with no
     * attempt counted. For rows a run took and did not get to: its budget
     * ran out, the hourly limit has no room, or the route turned out to be
     * unusable. `$queueId` 0 means every row the run still holds.
     */
    private static function putBack(mysqli $db, string $token, int $queueId = 0, string $note = ''): int
    {
        $note = mb_substr($note, 0, 500);
        $stmt = $db->prepare(
            "UPDATE tblMailQueue SET status = 'queued', stage = '', claimToken = '', claimedAt = NULL, statusAt = UTC_TIMESTAMP(), "
            . "lastError = IF(? = '', lastError, ?) WHERE claimToken = ? AND status = 'sending' AND stage = 'claimed' AND (? = 0 OR queueID = ?)"
        );
        $stmt->bind_param('sssii', $note, $note, $token, $queueId, $queueId);
        $stmt->execute();
        $moved = (int) $stmt->affected_rows;
        $stmt->close();

        return $moved;
    }

    /**
     * 🔀 AFTER AN ATTEMPT THAT DID NOT GO, AND MAY GO LATER: 'sending' ->
     * 'queued' with its next moment, or -> 'failed' when it has been tried
     * for long enough.
     *
     * The wait is added BY THE DATABASE, in seconds, to the database's own
     * UTC clock. REJECTED: working the next moment out in PHP. On the night
     * the clocks change, "now plus one hour" on a local clock is two hours
     * or none.
     *
     * @param bool $giveAttemptBack True when the attempt said nothing about
     *        the message (the sign-in was refused): it is not counted.
     *
     * @return string 'queued' or 'failed'.
     */
    private static function tryLater(mysqli $db, array $row, string $token, string $stage, string $words, int $retryHours, bool $giveAttemptBack = false): string
    {
        $queueId  = (int) $row['queueID'];
        $attempts = (int) $row['attempts'] + ($giveAttemptBack === true ? 0 : 1);
        $wait     = self::waitAfter(max(1, $attempts), (int) $row['priority']);

        // How old is it, on the database's clock?
        $age = $db->prepare('SELECT TIMESTAMPDIFF(SECOND, createdAt, UTC_TIMESTAMP()) AS age FROM tblMailQueue WHERE queueID = ?');
        $age->bind_param('i', $queueId);
        $age->execute();
        $seconds = (int) ($age->get_result()->fetch_assoc()['age'] ?? 0);
        $age->close();

        if ($giveAttemptBack === false && ($wait === null || $seconds + $wait > $retryHours * 3600)) {
            $tried = 'It was tried ' . $attempts . ' time' . ($attempts === 1 ? '' : 's') . ' and is not being tried again. ';
            // handle() has already checked that the mark is this class's own.
            self::fail($db, $queueId, "status = 'sending' AND claimToken = '" . $token . "'", $stage, $tried . $words);

            return 'failed';
        }

        // An attempt that is given back says nothing about the message, so
        // it is due again at once: what holds it back is the route's own
        // record (see MailRoutes::standingProblem()), not a wait of its own.
        $wait  = $giveAttemptBack === true ? 0 : ($wait ?? 0);
        $stage = mb_substr($stage, 0, 20);
        $words = mb_substr($words, 0, 500);
        $stmt  = $db->prepare(
            "UPDATE tblMailQueue SET status = 'queued', stage = '', claimToken = '', claimedAt = NULL, attempts = ?, lastStage = ?, lastError = ?, "
            . "nextAttemptAt = UTC_TIMESTAMP() + INTERVAL ? SECOND, statusAt = UTC_TIMESTAMP() WHERE queueID = ? AND claimToken = ? AND status = 'sending'"
        );
        $stmt->bind_param('issiis', $attempts, $stage, $words, $wait, $queueId, $token);
        $stmt->execute();
        $stmt->close();

        return 'queued';
    }

    /**
     * How long to wait after this many attempts, in seconds. Null when an
     * urgent message has had its last.
     */
    public static function waitAfter(int $attempts, int $priority): ?int
    {
        $waits = $priority <= self::URGENT ? self::WAITS_URGENT : self::WAITS_OTHER;
        if ($attempts <= count($waits)) {
            return $waits[$attempts - 1];
        }

        return $priority <= self::URGENT ? null : $waits[count($waits) - 1];
    }

    /** 🔀 'sending' -> 'held', with the reason. The attempt stays counted unless it is given back. */
    private static function hold(mysqli $db, array $row, string $token, string $reason, string $stage, string $words, bool $giveAttemptBack = false): void
    {
        $queueId = (int) $row['queueID'];
        $stage   = mb_substr($stage, 0, 20);
        $words   = mb_substr($words, 0, 500);
        $back    = $giveAttemptBack === true ? 1 : 0;
        $stmt    = $db->prepare(
            "UPDATE tblMailQueue SET status = 'held', holdReason = ?, stage = '', claimToken = '', claimedAt = NULL, lastStage = ?, lastError = ?, "
            . "attempts = attempts - IF(? = 1 AND attempts > 0, 1, 0), statusAt = UTC_TIMESTAMP() WHERE queueID = ? AND claimToken = ? AND status = 'sending'"
        );
        $stmt->bind_param('sssiis', $reason, $stage, $words, $back, $queueId, $token);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * 🔀 A CERTIFICATE FAULT HAS JUST BEEN FOUND: every 'queued' message ->
     * 'held' ('certificate'). They would all meet the same refusal, and each
     * try would be another connection to a server whose certificate is not
     * trusted. MailRoutes calls this when it puts the fault on record.
     */
    public static function holdAllForCertificate(mysqli $db, string $words): int
    {
        $reason = self::HELD_CERTIFICATE;
        $words  = mb_substr($words, 0, 500);
        $stmt   = $db->prepare(
            "UPDATE tblMailQueue SET status = 'held', holdReason = ?, lastStage = 'TLS', lastError = ?, statusAt = UTC_TIMESTAMP() WHERE status = 'queued'"
        );
        $stmt->bind_param('ss', $reason, $words);
        $stmt->execute();
        $moved = (int) $stmt->affected_rows;
        $stmt->close();

        return $moved;
    }

    /**
     * 🔀 THE CERTIFICATE FAULT IS OVER: every message 'held' for it ->
     * 'queued', due at once, so held mail goes by itself. MailRoutes calls
     * this when a checked connection passes, or the mail settings change.
     * A message that has grown too old meanwhile is still never sent: the
     * sweep and handOver() see to that.
     */
    public static function releaseHeldForCertificate(mysqli $db): int
    {
        $reason = self::HELD_CERTIFICATE;
        $stmt   = $db->prepare(
            "UPDATE tblMailQueue SET status = 'queued', holdReason = '', nextAttemptAt = UTC_TIMESTAMP(), statusAt = UTC_TIMESTAMP() WHERE status = 'held' AND holdReason = ?"
        );
        $stmt->bind_param('s', $reason);
        $stmt->execute();
        $moved = (int) $stmt->affected_rows;
        $stmt->close();

        return $moved;
    }

    /**
     * 🔀 A BACKUP HAS JUST BEEN PUT BACK: every message that could go by
     * itself -> 'held' ('restored'). That is every row that is 'queued' or
     * 'sending', and every row held for the certificate (those are released
     * by themselves when the certificate passes).
     *
     * WHY. A waiting message is in the backup. If it went AFTER the backup
     * was taken, putting the backup back brings the row back, and the job
     * would send it a second time: three waiting messages came out as six
     * copies when this was run. Nobody can tell from the restored database
     * which of them went. "Never twice" is the queue's first promise, so
     * they are held for a person and none is sent by itself, not even one
     * marked as safe to repeat.
     *
     * DbBackup calls this at the end of a restore, inside the restore's own
     * transaction, so a rehearsal ("check only") undoes it with everything
     * else and a restore that is stopped leaves nothing held. It THROWS when
     * the statement fails: the restore is then stopped and undone, which is
     * better than a restore that leaves mail able to go twice.
     *
     * WHAT THIS DOES NOT DO: write a line in the send log for each message
     * (the send log has just been put back too; the reason is on the row),
     * or release anything. A held message is failed after the holding
     * period; releasing one by hand is the next package's page.
     *
     * @return int How many messages were held.
     */
    public static function holdRestored(mysqli $db): int
    {
        $reason      = self::HELD_RESTORED;
        $certificate = self::HELD_CERTIFICATE;
        $words       = 'Held: this message came back with a restored backup. It was waiting when the backup was taken and may already have been sent since, so it is not sent again by itself.';
        $stmt        = $db->prepare(
            "UPDATE tblMailQueue SET status = 'held', holdReason = ?, stage = '', claimToken = '', claimedAt = NULL, lastError = ?, statusAt = UTC_TIMESTAMP() "
            . "WHERE status IN ('queued', 'sending') OR (status = 'held' AND holdReason = ?)"
        );
        $stmt->bind_param('sss', $reason, $words, $certificate);
        $stmt->execute();
        $moved = (int) $stmt->affected_rows;
        $stmt->close();

        return $moved;
    }

    // =========================================================================
    // 🏃 One run
    // =========================================================================

    /**
     * Work through the queue once. Called by the scheduled job, and by a
     * page that has just queued a mailing and wants its first messages to go
     * straight away.
     *
     * In order: take the one-run-at-a-time lock (or leave at once); deal
     * with a dead run's rows; apply the time limits; then send what is due,
     * most urgent first, for as long as the budget and the hourly limit
     * allow; then clear up stray files.
     *
     * @param int   $maxMessages The most messages to hand to a mail route.
     * @param float $seconds     No new message is started after this long.
     *
     * @return array<string, int> NUMBERS ONLY, because the job prints this:
     *         `ran` (1, or 0 when another run held the lock), `sent`,
     *         `waiting` (tried, and will be tried again), `held`, `failed`,
     *         `expired`, `returned` (a dead run's rows put back), `removed`
     *         (old rows deleted), `strayFiles`, `stopped` (1 when the run
     *         ended early because the route cannot be used), and what is
     *         left: `leftQueued`, `leftHeld`, `leftFailed`.
     */
    public static function run(mysqli $db, int $maxMessages = 100, float $seconds = 20.0): array
    {
        $out = [
            'ran' => 0, 'sent' => 0, 'waiting' => 0, 'held' => 0, 'failed' => 0, 'expired' => 0, 'returned' => 0, 'removed' => 0,
            'strayFiles' => 0, 'stopped' => 0, 'leftQueued' => 0, 'leftHeld' => 0, 'leftFailed' => 0,
        ];

        // 🔒 One run at a time. The lock belongs to this database connection
        //    and is given up by the database itself if the run dies. It is
        //    named for this database, because such a lock is shared by every
        //    database on the server.
        $lock = $db->query("SELECT GET_LOCK(CONCAT('portal-mail-queue:', SHA1(DATABASE())), 0) AS got");
        $got  = (int) ($lock->fetch_assoc()['got'] ?? 0);
        $lock->free();
        if ($got !== 1) {
            return $out;
        }
        $out['ran'] = 1;

        try {
            // (The moment "the job last ran" is NOT recorded here any more.
            // A broadcast page makes a run too, and that told the health
            // page a job existed when none did. Only the job's own page
            // records it: see jobIsRunning().)
            $dead            = self::recoverDeadClaims($db);
            $out['returned'] = $dead['returned'];
            $out['held']    += $dead['held'];

            $swept           = self::sweep($db);
            $out['expired'] += $swept['expired'];
            $out['failed']  += $swept['failed'];
            $out['removed']  = $swept['removed'];

            self::send($db, $maxMessages, $seconds, $out);

            $out['strayFiles'] = self::removeStrayFiles($db);
        } finally {
            // Whatever happened, say what is left and give the lock up. If the
            // database itself has gone, neither can be done, and the database
            // gives the lock up by itself when the connection ends; the
            // failure that brought the run here must not be hidden by a
            // second one from this block.
            try {
                foreach (self::counts($db) as $status => $count) {
                    if (isset($out['left' . ucfirst($status)]) === true) {
                        $out['left' . ucfirst($status)] = $count;
                    }
                }
                $db->query("DO RELEASE_LOCK(CONCAT('portal-mail-queue:', SHA1(DATABASE())))");
            } catch (\Throwable $ignored) {
                // See above.
            }
        }

        return $out;
    }

    /**
     * The sending part of a run.
     *
     * @param array<string, int> $out The run's numbers, added to as it goes.
     */
    private static function send(mysqli $db, int $maxMessages, float $seconds, array &$out): void
    {
        // 🧭 Nothing is tried while the route is not set up: every message
        //    would be refused before a connection was made. Mail waits.
        if (Mailer::canSend() === false) {
            $out['stopped'] = 1;

            return;
        }

        $began   = hrtime(true);
        $started = 0;
        $problem = MailRoutes::standingProblem($db);

        // 🔐 A refused sign-in is about the MAILBOX, not about any message.
        //    While one is on record and its wait has not passed, this run
        //    does not sign in at all, for any message: offering a refused
        //    password again and again is what gets a mailbox locked by its
        //    provider. Every waiting message stays as it is.
        //    And once the wait HAS passed, this run must first claim the one
        //    try that is due (MailRoutes::claimSignInTry()). The job's runs
        //    come one at a time, but a run can also be made by a page (an
        //    event broadcast), at the same moment as another page sends:
        //    without the claim each of them signed in.
        if ($problem['problem'] === MailRoutes::PROBLEM_SIGN_IN && ($problem['tryNow'] === false || MailRoutes::claimSignInTry($db) === false)) {
            $out['stopped'] = 1;

            return;
        }

        // 🪪 A certificate fault on record: ONE checked connection a run,
        //    made with one of the held messages. If it passes, MailRoutes
        //    clears the fault and releases the rest, and the run carries on.
        //    Not made while the hourly limit has no room at all: the message
        //    it is made with would go if the certificate passed. (Beyond
        //    that, this one message is not held to the part of the limit
        //    kept for urgent mail. Nothing has been going out while the
        //    fault stood, so the hour is all but empty when it is tried.)
        if ($problem['problem'] === MailRoutes::PROBLEM_CERTIFICATE) {
            $room = MailRoutes::roomLeft($db, true);
            if ($room !== null && $room < 1) {
                $out['stopped'] = 1;

                return;
            }
            $probe = self::claimOneHeldForCertificate($db);
            if ($probe['rows'] === []) {
                // Nothing is held for it (a dead run's rows may have been
                // put back as 'queued' since): try with one of those.
                $probe = self::claim($db, 1);
            }
            foreach ($probe['rows'] as $row) {
                $started++;
                self::count($out, self::handle($db, $row, $probe['token']));
            }
            $still = MailRoutes::standingProblem($db);
            if ($still['problem'] === MailRoutes::PROBLEM_CERTIFICATE) {
                self::holdAllForCertificate($db, $still['words']);
                $out['stopped'] = 1;

                return;
            }
        }

        while ($started < $maxMessages && (hrtime(true) - $began) / 1e9 < $seconds) {
            // ⏳ The hourly limit. With no room for ordinary mail, only
            //    urgent mail is taken; with none at all, nothing is.
            $roomUrgent = MailRoutes::roomLeft($db, true);
            $roomOther  = MailRoutes::roomLeft($db, false);
            if ($roomUrgent !== null && $roomUrgent < 1) {
                break;
            }
            $upTo  = ($roomOther !== null && $roomOther < 1) ? self::URGENT : self::BULK;
            $claim = self::claim($db, min(self::BATCH, $maxMessages - $started), $upTo);
            if ($claim['rows'] === []) {
                break;
            }
            $progress = false;
            foreach ($claim['rows'] as $row) {
                if ($started >= $maxMessages || (hrtime(true) - $began) / 1e9 >= $seconds) {
                    break; // the rest of the batch is put back below
                }
                // 🚫 A message for more recipients than a WHOLE hour allows
                //    can never go (the limit was lowered after it was kept:
                //    Mailer refuses a new one at once). It used to be put
                //    back to wait, and twenty of them at the front were the
                //    whole of every batch: nothing behind them ever went.
                //    It is failed here and now, in words that name the limit.
                $never = MailRoutes::neverFits((int) $row['recipientCount'], (int) $row['priority'] <= self::URGENT);
                if ($never !== '') {
                    if (self::fail($db, (int) $row['queueID'], "status = 'sending' AND claimToken = '" . $claim['token'] . "'", 'limit', $never) === true) {
                        Mailer::logQueueEvent($row, 'failed', MailRoutes::OVER_LIMIT, $never);
                        $out['failed']++;
                    }
                    $progress = true; // the batch was not wasted: the next one reaches what was behind it
                    continue;
                }
                $room = MailRoutes::roomLeft($db, (int) $row['priority'] <= self::URGENT);
                if ($room !== null && (int) $row['recipientCount'] > $room) {
                    self::putBack($db, $claim['token'], (int) $row['queueID'], 'Waiting: the hourly limit has no room for it yet.');
                    continue;
                }
                $started++;
                $what = self::handle($db, $row, $claim['token']);
                self::count($out, $what);
                $progress = true;
                if (str_starts_with($what, 'stop') === true) {
                    // The route cannot be used (the server cannot be reached,
                    // the sign-in or the certificate was refused). Trying the
                    // rest would be the same failure again for each.
                    $out['stopped'] = 1;
                    break;
                }
            }
            self::putBack($db, $claim['token']);
            if ($progress === false || $out['stopped'] === 1) {
                break;
            }
        }

        // A certificate fault found during this run: what the run put back
        // is held with the rest, so that every message it stops is listed
        // as held and all of them are released together.
        $now = MailRoutes::standingProblem($db);
        if ($now['problem'] === MailRoutes::PROBLEM_CERTIFICATE) {
            self::holdAllForCertificate($db, $now['words']);
        }
    }

    /** Add one message's ending to the run's numbers. */
    private static function count(array &$out, string $what): void
    {
        $key = match ($what) {
            'sent'              => 'sent',
            'held', 'stop-held' => 'held',
            'failed'            => 'failed',
            'expired'           => 'expired',
            default             => 'waiting', // 'queued' and 'stop-queued': it will be tried again
        };
        $out[$key]++;
    }

    /**
     * Try ONE claimed message, and move its row to wherever that leaves it.
     *
     * Public for the same reason as claim(): the self-test drives several
     * lock-less runs at once through these two methods.
     *
     * @param array<string, mixed> $row   One row from claim().
     * @param string               $token The claim's mark.
     *
     * @return string What became of it: 'sent', 'queued', 'held', 'failed'
     *                or 'expired'; or 'stop-queued' or 'stop-held', which
     *                mean the same as the second word AND that the run should
     *                try nothing more: what went wrong would go wrong for
     *                every message alike.
     */
    public static function handle(mysqli $db, array $row, string $token): string
    {
        // A claim's mark is 32 hexadecimal characters that this class made.
        // Anything else is not a claim, and nothing is done with it.
        if (preg_match('/\A[0-9a-f]{32}\z/', $token) !== 1) {
            return 'queued';
        }
        $queueId = (int) $row['queueID'];
        $siteId  = $row['siteID'] === null ? null : (int) $row['siteID'];
        $n       = self::numbers($db, $siteId);
        // "Still this run's": the test every statement below adds. The mark
        // was checked just above, so it is safe to write into the text.
        $mine    = "status = 'sending' AND claimToken = '" . $token . "'";

        // 1. The last check, the mark and the count: see handOver().
        if (self::handOver($db, $queueId, $token, $n['maxAgeHours']) === false) {
            // Not sent. Find out why, on the database's clock: its use-by
            // moment has passed, it has grown too old, or it is no longer
            // this run's (then somebody else has dealt with it).
            $why = $db->prepare(
                'SELECT (expiresAt IS NOT NULL AND expiresAt <= UTC_TIMESTAMP()) AS isExpired FROM tblMailQueue WHERE queueID = ? AND claimToken = ?'
            );
            $why->bind_param('is', $queueId, $token);
            $why->execute();
            $found = $why->get_result()->fetch_assoc();
            $why->close();
            if ($found === null) {
                return 'queued';
            }
            if ((int) $found['isExpired'] === 1) {
                self::expire($db, $queueId, $mine);
                Mailer::logQueueEvent($row, 'expired', 'expired', 'Not sent: its use-by moment passed before it could go.');

                return 'expired';
            }
            $words = 'Not sent: it waited longer than ' . $n['maxAgeHours'] . ' hours, and nothing that old is sent.';
            self::fail($db, $queueId, $mine, 'too-old', $words);
            Mailer::logQueueEvent($row, 'failed', 'too-old', $words);

            return 'failed';
        }

        // 2. The body. One that will not decrypt (the installation's key has
        //    changed, or this is a backup put back somewhere else) is failed
        //    in words. It is never sent as a scramble.
        $html = self::unseal($row['bodyHtml']);
        $text = self::unseal($row['bodyText']);
        if ($html === null || $text === null) {
            $words = 'Not sent: the stored message cannot be read on this installation (its encryption key is not the one the message was stored with).';
            self::fail($db, $queueId, $mine, 'unreadable', $words);
            Mailer::logQueueEvent($row, 'failed', 'unreadable', $words);

            return 'failed';
        }

        // 3. The files. Every one that was attached, whole, or nothing goes.
        $paths = [];
        $files = self::fileRows($db, $queueId);
        $whole = count($files) === (int) $row['attachmentCount'];
        foreach ($files as $file) {
            $path    = self::filesFolder() . DIRECTORY_SEPARATOR . $file['storedName'] . DIRECTORY_SEPARATOR . $file['realName'];
            $whole   = $whole && is_file($path) === true && (int) filesize($path) === $file['sizeBytes'];
            $paths[] = $path;
        }
        if ($whole === false) {
            $words = 'Not sent: a file attached to it is missing or has changed. It is not sent without it.';
            self::fail($db, $queueId, $mine, 'file-missing', $words);
            Mailer::logQueueEvent($row, 'failed', 'file-missing', $words);

            return 'failed';
        }

        // 4. Hand it to the mail route.
        $answer = Mailer::deliverQueued($row, $html, $text, $paths);
        $stage  = (string) $answer['stage'];
        $words  = (string) $answer['words'];

        switch ((string) $answer['next']) {
            case MailRoutes::NEXT_SENT:
                // The send log FIRST, then the row. If the run dies between
                // the two, the row is found later as "interrupted" and held,
                // and the send log already shows that it went.
                Mailer::logQueueAttempt($row, $answer, 'sent');
                self::remove($db, $queueId, $mine);
                self::tellOrigin($db, (string) ($row['origin'] ?? ''), (string) $row['toRecipients'], true);

                return 'sent';

            case MailRoutes::NEXT_REFUSED:
                // The mail server said no to THIS message (a recipient, its
                // content, its size). The same attempt would meet the same
                // answer, so it is never tried again.
                self::fail($db, $queueId, $mine, $stage, $words);
                Mailer::logQueueAttempt($row, $answer, 'failed');

                return 'failed';

            case MailRoutes::NEXT_HOLD_CERTIFICATE:
                // About the route, not the message: the attempt is not counted.
                self::hold($db, $row, $token, self::HELD_CERTIFICATE, $stage, $words, true);
                Mailer::logQueueAttempt($row, $answer, 'held');

                return 'stop-held';

            case MailRoutes::NEXT_HOLD_UNKNOWN:
                self::hold($db, $row, $token, self::HELD_UNKNOWN, $stage, $words);
                Mailer::logQueueAttempt($row, $answer, 'held');

                return 'held';

            case MailRoutes::NEXT_SIGN_IN:
            case MailRoutes::NEXT_NOT_SET_UP:
                // About the mailbox, or a setting that went missing since
                // the run began: not about the message. It waits, uncounted.
                self::tryLater($db, $row, $token, $stage, $words, $n['retryHours'], true);
                Mailer::logQueueAttempt($row, $answer, 'queued');

                return 'stop-queued';

            default:
                // "Later": the server could not be reached, was too slow, or
                // said so. Tried again after the next wait, until it has
                // been tried for long enough.
                $now = self::tryLater($db, $row, $token, $stage, $words, $n['retryHours']);
                Mailer::logQueueAttempt($row, $answer, $now);
                if ($now === 'failed') {
                    return 'failed';
                }

                return ($answer['routeFault'] ?? false) === true ? 'stop-queued' : 'queued';
        }
    }

    /**
     * 🧹 Remove copies no row points at, once they are a day old.
     *
     * A day, so that a file being copied for a message that is about to be
     * recorded is never taken for a stray. The age is the copy's own on the
     * server's clock, as a length in seconds.
     */
    private static function removeStrayFiles(mysqli $db): int
    {
        $folder = self::filesFolder();
        if (is_dir($folder) === false) {
            return 0;
        }
        $removed = 0;
        $seen    = 0;
        foreach ((array) @scandir($folder) as $entry) {
            if (is_string($entry) === false || preg_match('/\A[0-9a-f]{32}\z/', $entry) !== 1) {
                continue;
            }
            if (++$seen > 500) {
                break; // the rest are looked at on later runs
            }
            $age = time() - (int) @filemtime($folder . DIRECTORY_SEPARATOR . $entry);
            if ($age < self::KEEPING_DAY_SECONDS) {
                continue;
            }
            $stmt = $db->prepare('SELECT 1 FROM tblMailQueueFiles WHERE storedName = ? LIMIT 1');
            $stmt->bind_param('s', $entry);
            $stmt->execute();
            $known = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
            if ($known === false) {
                self::removeFolders([$entry]);
                $removed++;
            }
        }

        return $removed;
    }

    // =========================================================================
    // 🔎 Reading
    // =========================================================================

    /**
     * How many rows are in each state.
     *
     * @return array{queued: int, sending: int, held: int, failed: int, expired: int}
     */
    public static function counts(mysqli $db): array
    {
        $counts = ['queued' => 0, 'sending' => 0, 'held' => 0, 'failed' => 0, 'expired' => 0];
        $result = $db->query('SELECT status, COUNT(*) AS n FROM tblMailQueue GROUP BY status');
        while ($row = $result->fetch_assoc()) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }
        $result->free();

        return $counts;
    }

    /**
     * 🕰️ A stored moment (UTC) as a person reads it: in their organisation's
     * time zone, with the zone's short name beside it. THE ONE PLACE a queue
     * moment is turned into local time, and it is done once, for showing.
     *
     * @param string $utc  'Y-m-d H:i:s', in UTC.
     * @param string $zone A zone name such as the organisation's own. One
     *                     PHP does not know is shown as UTC, and says UTC.
     */
    public static function shown(string $utc, string $zone): string
    {
        if ($utc === '') {
            return 'a time that was not recorded';
        }
        try {
            $moment = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return $utc . ' UTC';
        }
        try {
            $moment = $moment->setTimezone(new \DateTimeZone($zone));
        } catch (\Throwable $e) {
            // Left in UTC; the short name below then says so.
        }

        return $moment->format('j M Y, H:i T');
    }

    /**
     * 🩺 The health page's "Mail queue" line.
     *
     * Critical when the route cannot be used at all (its certificate failed
     * the check, or the mail server refused the sign-in): no mail is going.
     * Critical, too, when messages are WAITING and the scheduled job is not
     * running: nothing else will ever send them. A warning when the job is
     * not running and nothing waits yet, or something else needs a look.
     * Whether the job is running is jobIsRunning()'s answer, the same one
     * every sending page acts on, so the line and the behaviour cannot
     * disagree. (This used to keep a 30-minute rule of its own, and called
     * waiting mail with no job a warning.) Numbers and the route's own facts
     * only: never an address, a subject or a body.
     *
     * @param string $zone         The time zone to show moments in.
     * @param bool   $routeIsSetUp Whether a mail route is set up (the health
     *                             page's "Email" line has already asked).
     *
     * @return array{state: string, label: string, detail: string}|null Null
     *         when there is nothing to say: no mail route is set up, and
     *         nothing is in the queue.
     */
    public static function health(mysqli $db, string $zone, bool $routeIsSetUp): ?array
    {
        try {
            $counts = self::counts($db);
            $state  = MailRoutes::state($db);
        } catch (\Throwable $e) {
            return $routeIsSetUp === false ? null : [
                'state'  => 'warn',
                'label'  => 'Not ready',
                'detail' => 'The mail queue\'s tables could not be read, so mail that cannot be sent at once is not being kept. Run the database upgrade (Admin → Upgrade).',
            ];
        }
        $waiting = $counts['queued'] + $counts['sending'];
        $numbers = $waiting . ' waiting, ' . $counts['held'] . ' held, ' . $counts['failed'] . ' failed.';
        if ($routeIsSetUp === false) {
            return array_sum($counts) === 0 ? null : [
                'state'  => 'warn',
                'label'  => $waiting . ' waiting',
                'detail' => 'Mail is in the queue and no mail route is set up to send it: see the Email line. ' . $numbers,
            ];
        }
        $problem = MailRoutes::standingProblem($db);
        $notes   = self::numberProblems($db);

        // 🕰️ When the REAL job last ran (only its own page records that),
        //    and whether that is lately enough: the one question.
        $lastRan = (string) ($state['jobLastRanAt'] ?? '');
        $running = self::jobIsRunning($db);
        $ranText = $lastRan === '' ? 'The mail queue\'s scheduled job has never run.' : 'The mail queue\'s scheduled job last ran at ' . self::shown($lastRan, $zone) . '.';
        if ($running === false) {
            // Said in every line below while the job is not running: what
            // that means, what to do, and where the line to add is. It sends
            // an administrator to a page they can open. (It used to send
            // them to "the developer notes", which no administrator has.)
            $ranText .= ' It has not run in the last ' . intdiv(self::JOB_RECENT_SECONDS, 60) . ' minutes. Until it does, the portal sends mail inside each request, as it did before'
                . ' there was a mail queue, and anything that cannot go at once WAITS and is not tried again. Add the scheduled job in your hosting company\'s scheduler:'
                . ' the line to add is in Help → Admin Guide → "The mail queue: mail that cannot go at once". Then check the maintenance token.';
        }

        if ($problem['problem'] === MailRoutes::PROBLEM_CERTIFICATE) {
            return [
                'state'  => 'crit',
                'label'  => 'Mail is held',
                'detail' => 'The mail server\'s certificate failed its check, so NOTHING is being sent and every message is held. '
                    . $problem['words'] . ' First seen ' . self::shown($problem['since'], $zone) . ', last seen ' . self::shown($problem['seenAt'], $zone) . '. '
                    . $numbers . ' Held mail goes by itself once the certificate passes the check; a held message is kept for a limited time. ' . $ranText,
            ];
        }
        if ($problem['problem'] === MailRoutes::PROBLEM_SIGN_IN) {
            return [
                'state'  => 'crit',
                'label'  => 'Mail is waiting',
                'detail' => 'The mail server refused the mailbox\'s user name and password, so NOTHING is being sent and mail is waiting. '
                    . $problem['words'] . ' First seen ' . self::shown($problem['since'], $zone) . '. ' . $numbers
                    . ' The portal does not keep offering a refused password. Put it right at Admin → Integrations → Email (saving the settings, or a Send test that is accepted, starts mail again). ' . $ranText,
            ];
        }
        if ($running === false && $waiting > 0) {
            // 🚨 Mail is waiting and nothing will send it. CRITICAL: the
            //    only other sign is people saying an e-mail never came.
            return [
                'state'  => 'crit',
                'label'  => $waiting . ' waiting, no job',
                'detail' => $waiting . ' message' . ($waiting === 1 ? ' is' : 's are') . ' waiting in the mail queue and will NOT be sent: the scheduled job that sends waiting mail is not running. '
                    . $ranText . ' ' . $numbers . ($notes === [] ? '' : ' ' . implode(' ', $notes)),
            ];
        }

        $warnings = $notes;
        // Mail that has been tried and did not go. Only the STEP it stopped
        // at is shown, never the mail server's own words, which can name an
        // e-mail address.
        $tried = $db->query("SELECT COUNT(*) AS n, SUBSTRING_INDEX(GROUP_CONCAT(lastStage ORDER BY statusAt DESC), ',', 1) AS stage FROM tblMailQueue WHERE status = 'queued' AND attempts > 0");
        $again = $tried->fetch_assoc();
        $tried->free();
        if ((int) ($again['n'] ?? 0) > 0) {
            $warnings[] = (int) $again['n'] . ' of the waiting messages have been tried and did not go; they are tried again at growing waits. The most recent stopped while '
                . MailRoutes::stoppedWhile((string) ($again['stage'] ?? '')) . '.';
        }
        if ($running === false) {
            // Nothing is waiting yet, so this is a warning and not an alarm.
            // It must stay: it is the only thing that tells an administrator
            // to add the job BEFORE mail starts to wait.
            $warnings[] = $ranText;
        }
        if ((int) $state['encryptionFailures'] >= MailRoutes::ENCRYPTION_FAILURES_WORTH_SAYING) {
            $warnings[] = 'Switching encryption on with the mail server has failed ' . (int) $state['encryptionFailures']
                . ' times in a row. If this continues, check the mail server\'s certificate.';
        }
        if ($warnings !== []) {
            return ['state' => 'warn', 'label' => $waiting . ' waiting', 'detail' => implode(' ', $warnings) . ' ' . $numbers];
        }

        return ['state' => 'ok', 'label' => $waiting . ' waiting', 'detail' => $ranText . ' ' . $numbers];
    }

    // =========================================================================
    // 🧍 One person's messages (erasure, and "a copy of my data")
    // =========================================================================

    /** @var int How long an erasure waits for a run of the job that is working to end, in seconds. */
    public const ERASURE_WAITS_SECONDS = 20;

    /**
     * Which entries of a list of addresses ARE one address. The list is
     * split on commas; an entry counts only when, with the spaces around it
     * removed, it is the address, capital letters ignored (as they are when
     * mail is delivered). A longer address that merely contains it is
     * somebody else's: `joann@x.org` is not `ann@x.org`.
     *
     * @return array{entries: list<string>, mine: list<int>} Every entry,
     *         trimmed, and the positions of the ones that are the address.
     */
    private static function entriesOf(string $list, string $email): array
    {
        $wanted  = mb_strtolower(trim($email));
        $entries = array_map('trim', explode(',', $list));
        $mine    = [];
        foreach ($entries as $at => $entry) {
            if ($wanted !== '' && $entry !== '' && mb_strtolower($entry) === $wanted) {
                $mine[] = $at;
            }
        }

        return ['entries' => $entries, 'mine' => $mine];
    }

    /** Is this address one of a list of addresses separated by commas? See entriesOf() for the rule. */
    public static function listHasAddress(string $list, string $email): bool
    {
        return self::entriesOf($list, $email)['mine'] !== [];
    }

    /**
     * The waiting messages that are for one address. The LIKE is only a
     * first filter, to avoid reading the whole table; entriesOf() decides.
     *
     * @return list<array<string, mixed>> Each row, with `entries` and `mine`
     *         as entriesOf() gives them.
     */
    private static function rowsFor(mysqli $db, string $email): array
    {
        $like = '%' . addcslashes($email, '\\%_') . '%';
        $stmt = $db->prepare('SELECT queueID, toRecipients FROM tblMailQueue WHERE toRecipients LIKE ? ORDER BY queueID');
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $found = [];
        foreach ($rows as $row) {
            $parts = self::entriesOf((string) $row['toRecipients'], $email);
            if ($parts['mine'] !== []) {
                $found[] = $row + $parts;
            }
        }

        return $found;
    }

    /**
     * 🔀 ERASURE: take one person out of the queue.
     *
     * A waiting message for that person alone is DELETED, files included. In
     * a message for several people the address is taken off the list, so it
     * is not sent to them, and the last error is emptied because a mail
     * server's refusal can name the address. Whatever state the row is in.
     *
     * WHY IT WAITS FOR A RUN OF THE JOB TO END. A run that is handing a
     * message to the mail server writes that message's line in the send log
     * AFTER the attempt. An erasure that went through at the same moment
     * used to finish first, and the run then wrote the erased person's
     * address back. So this takes the lock a run holds, waiting up to
     * ERASURE_WAITS_SECONDS for a run that is working: everything that run
     * writes is then there for the step that follows this one, which cleans
     * the send log, and no run can start part way through.
     *
     * WHEN THE RUN IS STILL WORKING AFTER THAT. The person is taken out of
     * the queue ALL THE SAME, without the lock, and `runWasWorking` is true.
     * WHAT WAS WRONG: this used to stop there and change nothing, so the
     * person's waiting message was still in the queue, and the next run of
     * the job sent it. Taking rows out without the lock is safe for the
     * queue itself: every statement a run makes on a row names that run's
     * own mark, so a row that has gone is simply not sent, tried again or
     * recorded as anything. What the lock adds is only the order in which
     * the send log is written and cleaned, and that is what cannot be
     * promised here: the caller must count the erasure as NOT FINISHED and
     * have it run again, which cleans the send log once more.
     *
     * WHAT THIS CANNOT DO: stop a message a run is handing over at this very
     * moment (that message goes), take the person's name out of the BODY of
     * a message that is also for other people (the body is not read here),
     * or keep out a message sent inside an ordinary request, which is not
     * under the lock (see the file header).
     *
     * @return array{deleted: int, changed: int, runWasWorking: bool}
     */
    public static function eraseAddress(mysqli $db, string $email): array
    {
        $wait = self::ERASURE_WAITS_SECONDS;
        $stmt = $db->prepare("SELECT GET_LOCK(CONCAT('portal-mail-queue:', SHA1(DATABASE())), ?) AS got");
        $stmt->bind_param('i', $wait);
        $stmt->execute();
        $alone = (int) ($stmt->get_result()->fetch_assoc()['got'] ?? 0) === 1;
        $stmt->close();
        try {
            return self::eraseAddressNow($db, $email) + ['runWasWorking' => $alone === false];
        } finally {
            if ($alone === true) {
                try {
                    $db->query("DO RELEASE_LOCK(CONCAT('portal-mail-queue:', SHA1(DATABASE())))");
                } catch (\Throwable $ignored) {
                    // The database gives the lock up by itself when the connection ends.
                }
            }
        }
    }

    /** The work of eraseAddress(): every row that names the address, whatever state it is in. */
    private static function eraseAddressNow(mysqli $db, string $email): array
    {
        $done = ['deleted' => 0, 'changed' => 0];
        foreach (self::rowsFor($db, $email) as $row) {
            $queueId = (int) $row['queueID'];
            $others  = array_values(array_filter(
                $row['entries'],
                static fn (string $entry, int $at): bool => $entry !== '' && in_array($at, $row['mine'], true) === false,
                ARRAY_FILTER_USE_BOTH
            ));
            if ($others === []) {
                // 📣 The record this message came from is told "did not go"
                //    FIRST, while the row is still there to say which record
                //    that is: see tellOrigin().
                self::tellOriginOf($db, $queueId, false);
                $done['deleted'] += self::remove($db, $queueId, '1 = 1') === true ? 1 : 0;
                continue;
            }
            $list  = implode(', ', $others);
            $count = count($others);
            $stmt  = $db->prepare("UPDATE tblMailQueue SET toRecipients = ?, recipientCount = ?, lastError = '' WHERE queueID = ?");
            $stmt->bind_param('sii', $list, $count, $queueId);
            $stmt->execute();
            $stmt->close();
            $done['changed']++;
        }

        return $done;
    }
}
