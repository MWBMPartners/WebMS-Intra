<?php
// Path: _core/Logger.php
/**
 * -----------------------------------------------------------------------------
 * Portal Core Logger 📓
 * -----------------------------------------------------------------------------
 * Centralised activity and error logger writing to tblActivityLogs,
 * tblAuditTrail and tblErrors.
 * -----------------------------------------------------------------------------
 *  • Logger::activity(...)             – audit trail for every action.
 *  • Logger::audit(...)                – before/after record of a data change.
 *  • Logger::phpError(...)             – registered as set_error_handler.
 *  • Logger::exception(...)            – registered as set_exception_handler.
 *  • Logger::errorPlatform(...)        – manual logging for external libs / systems.
 *  • Logger::errorPlatformForSite(...) – the same, for a named organisation.
 * -----------------------------------------------------------------------------
 * 🛟 None of these ever throws, loops, or needs a working database. When the
 *    database cannot take the row, the essentials go to PHP's own error log
 *    instead, once. The reasons are in the section "The rule every method
 *    here follows" below.
 * -----------------------------------------------------------------------------
 * @package    Portal\Core
 * @author     Cambridge SDA
 * @license   All Rights Reserved
 * @version   1.0.1
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;
use Throwable;

class Logger
{
    /* ---------------------------------------------------------------------- */
    /* The rule every method here follows: never throw, never loop            */
    /* ---------------------------------------------------------------------- */
    /*
     * The logger is what runs while things are going wrong. The PHP error
     * handler calls it, the exception handler calls it, and dozens of pages
     * call it straight after a database failure. So whatever it does must not
     * itself become a new failure.
     *
     * WHAT WAS WRONG BEFORE (found by Codex while reviewing the change that
     * made the logger ask RateLimiter for the visitor's address)
     *
     *   1. No connection meant a crash. db() promised to hand back a database
     *      connection and simply returned the global $mysqli. When that was
     *      empty — a script that runs before bootstrap has connected, or a
     *      tool that never connects — PHP threw a TypeError inside the logger,
     *      so the original problem was never recorded and a new one replaced
     *      it.
     *
     *   2. A failing database meant a crash. bootstrap.php switches MySQLi to
     *      "strict" reporting, where every database failure throws an
     *      exception instead of quietly returning false. The prepare() and
     *      execute() calls here were not inside any catch, so a closed
     *      connection, a lost connection or a rejected row threw straight out
     *      of the logger. From inside the error handler, that exception
     *      replaced the error being recorded. From an ordinary page, it turned
     *      a harmless log line into a "500" error page for the visitor.
     *
     *   3. A failing alert e-mail could go round in a circle. Recording an
     *      error can send an alert e-mail. The Mailer records its own failures
     *      by calling back into this logger, which could send another alert,
     *      which could fail the same way, and so on. The only brake was a
     *      "cooldown" marker file; when that file could not be written, there
     *      was no brake at all.
     *
     * WHAT HAPPENS NOW
     *
     *   • Every database write goes through insertRow(), which catches every
     *     failure and says what went wrong instead of throwing.
     *   • If the row could not be written, ONE line goes to PHP's own error log
     *     (writeFallback()). It carries the same essentials the row would have
     *     held — what kind of event, the message, the file and line where
     *     there is one, the organisation, the user and the visitor's address.
     *     It never carries request headers, the session identifier or the
     *     session contents: PHP's log is read by more people and kept for
     *     longer than the database table, so it gets less.
     *   • While an error is being recorded, alerted or forwarded, any further
     *     error raised by that work goes to PHP's log only — at most one line —
     *     and never to the database, alerts or the error monitor. See
     *     writeErrorRow().
     *
     * WHAT THIS CANNOT DO
     *
     *   • It cannot make the database take the row. If the database is down,
     *     the record lives only in PHP's error log, and the admin error-log
     *     screen will not show it.
     *   • PHP's own log can be switched off, or pointed at a place nobody
     *     reads, in the server's settings. Then a record that could not reach
     *     the database is lost. The logger has nowhere safer left to put it.
     *   • A fatal PHP error that stops the script (running out of memory, for
     *     example) cannot be caught by anything here.
     */

    /** Reference to the global MySQLi connection. */
    private static ?mysqli $db = null;

    /**
     * 🔁 True while writeErrorRow() is busy with one error: writing its row,
     * sending its alert e-mail, or forwarding it to the error monitor.
     */
    private static bool $recordingError = false;

    /**
     * 🔁 True once a problem raised DURING the current error record has been
     * written to PHP's own log, so that only the first such problem is.
     */
    private static bool $nestedProblemNoted = false;

    /**
     * Get the connection bootstrap opened, or null when there is none.
     *
     * This used to promise a connection and hand back the global $mysqli
     * whatever it held. When that was empty, PHP threw a TypeError right here,
     * inside the logger. Answering null lets insertRow() say "no database
     * connection" and fall back to PHP's own log instead.
     *
     * A connection that exists but has been closed is still handed back; the
     * first thing done with it throws, and insertRow() catches that.
     */
    private static function db(): ?mysqli
    {
        if (self::$db === null) {
            global $mysqli; // Established in bootstrap.php
            if (($mysqli instanceof mysqli) === true) {
                self::$db = $mysqli;
            }
        }
        return self::$db;
    }

    /* ---------------------------------------------------------------------- */
    /* Activity Logging                                                       */
    /* ---------------------------------------------------------------------- */

    /* ---------------------------------------------------------------------- */
    /* What a record may keep about the browser session (#622)                */
    /* ---------------------------------------------------------------------- */
    /*
     * WHAT WAS WRONG
     *   Every activity record used to store two things about the session.
     *
     *   1. The session's own identifier, exactly as it is. That value IS the
     *      sign-in cookie. Anybody who could read the table, a backup file or
     *      a snapshot taken before an upgrade could be that person for as
     *      long as the session lived, an administrator included. The same
     *      method was already blanking the cookie out of the request headers
     *      it stored, and then stored the same secret in the next column.
     *   2. A copy of EVERYTHING in the session, with three named values left
     *      out. "Everything except a list" means whatever a page puts in the
     *      session tomorrow is copied too. That is how every set of two-step
     *      backup codes, and the authenticator key of anybody whose set-up
     *      page was open, came to be in the log in readable text.
     *
     * WHAT IS STORED NOW
     *   1. A FINGERPRINT of the identifier (fingerprintOf()). It cannot be
     *      turned back into the identifier and is no use as a cookie. Every
     *      record of one session still carries the same fingerprint, which
     *      the monthly sign-ins report needs: it joins a password sign-in to
     *      its code page by this column (_apps/admin/reports/data.php).
     *   2. A SHORT LIST OF NAMED FACTS (sessionFacts()): numbers and
     *      true/false only. It is a list of what goes IN, so a new value in
     *      the session is left out unless somebody adds it there on purpose.
     *
     * Migration 220 turns the identifiers already stored into the same
     * fingerprints and blanks the old copies.
     */

    /** What every stored fingerprint starts with: how one is told from an identifier. */
    public const FINGERPRINT_MARK = 'sha256:';

    /** The first value of every copy of a session written since #622. Migration 220 keeps a copy that starts with it and blanks any other. */
    public const SESSION_FACTS_FORMAT = 'facts-1';

    /**
     * 🔏 The ONE place a session identifier becomes something that may be
     * stored: "sha256:" and then the SHA-256 of the identifier, in hexadecimal.
     *
     * WHY IT STARTS WITH A MARK. A colon can never be in a real identifier:
     * Auth::prepareSessionStart() drops any cookie that is not letters,
     * digits, comma or hyphen. So a stored value that starts with the mark is
     * a fingerprint and nothing else, and the database upgrade can tell which
     * rows it has already done. Without that, a second run would fingerprint
     * the fingerprints, and one session's old and new records would no
     * longer match.
     *
     * WHY PLAIN SHA-256, with no key mixed in. Migration 220 has to make the
     * SAME value for rows already stored, in SQL, where the installation's
     * key file cannot be read: CONCAT('sha256:', SHA2(sessionID, 256)). A
     * session that began before the upgrade and carries on after it then
     * keeps one fingerprint across both. tools/activity-log-selftest.php
     * holds the two to the same answer.
     *
     * WHAT IT CANNOT DO. It is only as hard to guess as the identifier. PHP
     * makes the identifier of every signed-in session itself, long and
     * random (every sign-in route asks for a new one). But a visitor who is
     * NOT signed in can choose their own, and a short chosen one could be
     * found from its fingerprint by trying. That gives away only what that
     * visitor chose, for a session with nobody signed in.
     *
     * @param string $identifier A session identifier, as session_id() gives it.
     */
    public static function fingerprintOf(string $identifier): string
    {
        return self::FINGERPRINT_MARK . hash('sha256', $identifier);
    }

    /**
     * The fingerprint of THIS request's session, or null when there is no
     * session (a scheduled job, a tool).
     *
     * Anything that stores "which session" stores this and never
     * session_id() itself. tools/audit-checks/check_session_id_not_stored.py
     * fails when session_id() appears anywhere else under web/ without a
     * written reason.
     */
    public static function sessionFingerprint(): ?string
    {
        $identifier = session_id();
        if (is_string($identifier) === false || $identifier === '') {
            return null;
        }

        return self::fingerprintOf($identifier);
    }

    /**
     * 📋 The ONE place that decides what a record keeps about the session:
     * the facts below and nothing else. Null when the request has no session.
     *
     *   format                 always "facts-1": says the copy was written
     *                          by this list (migration 220 keeps such a copy
     *                          and blanks any other)
     *   signedIn               true when an account is signed in
     *   userID                 that account's number, else null
     *   organisationID         the organisation the person has chosen to
     *                          work in, else null (the record's own siteID
     *                          is the organisation the request was for)
     *   waitingForCodeUserID   the account whose password was accepted and
     *                          whose second step has not been given yet
     *   kioskUserID            the account a shared kiosk has been handed to
     *
     * Each answers "who was this?" for somebody reading the log after
     * something has gone wrong. Nothing in the portal reads them back.
     *
     * EVERY VALUE IS A WHOLE NUMBER, TRUE/FALSE OR NULL, AND THAT IS THE
     * SAFEGUARD. Text never crosses, so a secret that some page keeps under
     * one of these names by mistake still cannot be copied: sessionNumber()
     * answers null for anything that is not plain digits.
     *
     * TO ADD A FACT: add it here, on purpose, and say who reads it. Do not go
     * back to "everything except a list". If the list ever changes shape,
     * change SESSION_FACTS_FORMAT, and make migration 220's test for "a copy
     * written by this list" accept both.
     *
     * WHAT THIS CANNOT DO. It does not make an erasure complete. When a
     * person is erased, GdprEraser empties `userID` on their activity
     * records and nothing else on them, so the account NUMBER written here
     * stays (and so does the fingerprint). That gap is older than #622: the
     * whole-session copy stayed in the same way, with the person's name and
     * e-mail address in it. It is written up beside #622 and not changed
     * here.
     *
     * @return array{format: string, signedIn: bool, userID: int|null, organisationID: int|null, waitingForCodeUserID: int|null, kioskUserID: int|null}|null
     */
    public static function sessionFacts(): ?array
    {
        // Asked of $_SESSION itself, not of session_status(): a page that
        // answers the browser first and closes its session early ("Forgot
        // password" does) still has its session's values, and its records
        // still carry the session's fingerprint. Where no session was ever
        // opened (a scheduled job) there is no $_SESSION at all.
        if (isset($_SESSION) === false || is_array($_SESSION) === false) {
            return null;
        }

        $userId = self::sessionNumber('user_id');

        return [
            'format'               => self::SESSION_FACTS_FORMAT,
            'signedIn'             => $userId !== null,
            'userID'               => $userId,
            'organisationID'       => self::sessionNumber('active_site_id'),
            'waitingForCodeUserID' => self::sessionNumber('2fa_user_id'),
            'kioskUserID'          => self::sessionNumber('kiosk_user_id'),
        ];
    }

    /**
     * One session value as a whole number above zero, or null. A whole
     * number, or text that is nothing but one to ten digits, counts;
     * anything else (other text, a list, true) is null and is never copied.
     */
    private static function sessionNumber(string $name): ?int
    {
        $value = $_SESSION[$name] ?? null;
        if (is_int($value) === true) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) === true && preg_match('/^[0-9]{1,10}$/', $value) === 1 && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    public static function activity(string $type, string $description = '', ?int $userId = null): void
    {
        // Declared before the try so the fallback line below can use whatever
        // had been worked out before anything failed.
        $siteId = null;
        $ip     = null;

        // 🛟 Everything that gathers the row's values is inside the try as
        //    well as the write itself. None of it is expected to throw, but
        //    "not expected" is not "cannot", and this runs on every action in
        //    the portal.
        try {
            $headersJson = json_encode(self::requestHeaders());
            $ip          = self::clientIp();
            $ua          = $_SERVER['HTTP_USER_AGENT'] ?? '';

            // 🔏 A fingerprint, never the identifier; the named facts, never
            //    the session. Both are null when there is no session. See
            //    "What a record may keep about the browser session" above.
            $sessionMark  = self::sessionFingerprint();
            $sessionFacts = self::sessionFacts();
            $sessionSnap  = $sessionFacts === null ? null : json_encode($sessionFacts);
            if ($sessionSnap === false) {
                $sessionSnap = null;
            }

            // 🌐 Include siteID for multi-site context
            $siteId = Site::id();

            $failure = self::insertRow(
                'INSERT INTO tblActivityLogs ' .
                '(siteID, userID, activityType, activityDescription, requestHeaders, sessionID, visitorIP, userAgent, sessionDataSnapshot) ' .
                'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                'iisssssss',
                [$siteId, $userId, $type, $description, $headersJson, $sessionMark, $ip, $ua, $sessionSnap]
            );
        } catch (Throwable $e) {
            $failure = get_class($e) . ': ' . $e->getMessage();
        }

        if ($failure !== null) {
            // 📝 Deliberately NOT the session's fingerprint, its facts or the
            //    headers — see the section at the top of this class.
            self::writeFallback('an activity record', $failure, [
                'type'        => $type,
                'description' => $description,
                'site'        => $siteId,
                'user'        => $userId,
                'address'     => $ip,
            ]);
        }
    }

    /* ---------------------------------------------------------------------- */
    /* Audit Trail — Before/After Change Tracking                             */
    /* ---------------------------------------------------------------------- */

    /**
     * Log a detailed before/after change for a specific record.
     *
     * Like every method here it never throws. If the row cannot be written,
     * one line goes to PHP's own error log naming the table, record, action
     * and who did it — but NOT the old and new values, which can be personal
     * data and belong only in the database table.
     *
     * Callers that write their change inside a database transaction and then
     * call this should know: a failure to record the audit row no longer
     * throws, so it no longer rolls the change back. Every such caller in the
     * portal on 14 September 2026 called this after its commit, or outside a
     * transaction, so none relied on that.
     *
     * @param string      $tableName  Database table name (e.g. 'tblExpenseClaims')
     * @param int         $recordId   Primary key of the affected record
     * @param string      $action     One of: create, update, delete
     * @param array|null  $oldData    Previous state (null for create)
     * @param array|null  $newData    New state (null for delete)
     * @param int|null    $userId     Acting user ID
     * @param int|null    $apiKeyId   tblApiKeys.keyID when the change arrived via bearer API
     *                                key (#323 Phase 2). Null auto-resolves from
     *                                Portal\Core\ApiAuth::apiKeyId() IF that class exists yet
     *                                (it ships in a later #323 Phase 2 bundle) — guarded by
     *                                class_exists() so this stays backward-compatible until then.
     * @param string|null $source     'session' or 'apikey'. Null auto-resolves from
     *                                Portal\Core\ApiAuth::source() IF that class exists yet,
     *                                else defaults to 'session'.
     */
    public static function audit(
        string $tableName,
        int $recordId,
        string $action,
        ?array $oldData = null,
        ?array $newData = null,
        ?int $userId = null,
        ?int $apiKeyId = null,
        ?string $source = null
    ): void {
        // Declared before the try so the fallback line below can use whatever
        // had been worked out before anything failed.
        $siteId    = null;
        $ip        = null;
        $fieldName = null;

        try {
            $siteId = Site::id();

            // 🔌 Auto-resolve API-key attribution from Portal\Core\ApiAuth when
            //    the caller didn't pass it explicitly. ApiAuth doesn't exist yet
            //    as of this bundle (#323 Phase 2 B1 ships schema + primitives
            //    only) — class_exists()/method_exists() guards keep every
            //    existing call site (which never passes these two args)
            //    compiling and behaving exactly as before.
            if ($apiKeyId === null
                && class_exists('Portal\\Core\\ApiAuth') === true
                && method_exists('Portal\\Core\\ApiAuth', 'apiKeyId') === true
            ) {
                $apiKeyId = \Portal\Core\ApiAuth::apiKeyId();
            }
            if ($source === null
                && class_exists('Portal\\Core\\ApiAuth') === true
                && method_exists('Portal\\Core\\ApiAuth', 'source') === true
            ) {
                $source = \Portal\Core\ApiAuth::source();
            }
            if ($source === null) {
                $source = 'session';
            }

            // 📋 Build change set for updates (diff old vs new)
            $changeSet = null;
            if ($action === 'update' && $oldData !== null && $newData !== null) {
                $diff = [];
                foreach ($newData as $field => $newVal) {
                    $oldVal = $oldData[$field] ?? null;
                    if ((string) ($oldVal ?? '') !== (string) ($newVal ?? '')) {
                        $diff[$field] = ['old' => $oldVal, 'new' => $newVal];
                    }
                }
                if (count($diff) === 0) {
                    return; // No actual changes
                }
                $changeSet = json_encode($diff, JSON_UNESCAPED_UNICODE);
            }

            // 📋 For single-field tracking, use first changed field
            $oldValue  = null;
            $newValue  = null;

            if ($action === 'create' && $newData !== null) {
                $newValue = json_encode($newData, JSON_UNESCAPED_UNICODE);
            } elseif ($action === 'delete' && $oldData !== null) {
                $oldValue = json_encode($oldData, JSON_UNESCAPED_UNICODE);
            } elseif ($action === 'update' && $changeSet !== null) {
                $diffArr = json_decode($changeSet, true);
                if (is_array($diffArr) === true && count($diffArr) === 1) {
                    $fieldName = array_key_first($diffArr);
                    $oldValue  = (string) ($diffArr[$fieldName]['old'] ?? '');
                    $newValue  = (string) ($diffArr[$fieldName]['new'] ?? '');
                }
            }

            $ip = self::clientIp();

            $failure = self::insertRow(
                'INSERT INTO tblAuditTrail '
                . '(siteID, userID, apiKeyID, source, tableName, recordID, action, fieldName, oldValue, newValue, changeSet, ipAddress) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                'iiississssss',
                [
                    $siteId, $userId, $apiKeyId, $source, $tableName, $recordId, $action,
                    $fieldName, $oldValue, $newValue, $changeSet, $ip,
                ]
            );
        } catch (Throwable $e) {
            $failure = get_class($e) . ': ' . $e->getMessage();
        }

        if ($failure !== null) {
            // 📝 Deliberately NOT the old/new values or the change set — see
            //    the docblock above.
            self::writeFallback('an audit trail record', $failure, [
                'table'   => $tableName,
                'record'  => $recordId,
                'action'  => $action,
                'field'   => $fieldName,
                'site'    => $siteId,
                'user'    => $userId,
                'apiKey'  => $apiKeyId,
                'source'  => $source,
                'address' => $ip,
            ]);
        }
    }

    /* ---------------------------------------------------------------------- */
    /* PHP Error & Exception Handlers                                         */
    /* ---------------------------------------------------------------------- */

    public static function phpError(int $errno, string $errstr, string $file, int $line): void
    {
        $severity = self::severityFromErrno($errno);
        self::errorPlatform('PHP', $severity, (string) $errno, $errstr, $file . ':' . $line);
    }

    public static function exception(Throwable $ex): void
    {
        // 🛟 Building the detail cannot realistically fail, but this is the
        //    last thing that runs for an uncaught exception, so a failure here
        //    must still leave a trace rather than a second uncaught exception.
        try {
            $detail = $ex->getFile() . ':' . $ex->getLine() . "\n" . $ex->getTraceAsString();
            $code   = (string) $ex->getCode();
        } catch (Throwable $ignored) {
            $detail = '(the trace could not be read)';
            $code   = '';
        }
        self::errorPlatform('PHP', 'Fatal', $code, $ex->getMessage(), $detail);
    }

    /* ---------------------------------------------------------------------- */
    /* Generic Error Logger                                                   */
    /* ---------------------------------------------------------------------- */

    /**
     * Record a problem against WHICHEVER organisation the request is currently
     * working in.
     *
     * Unchanged, on purpose. Every existing caller — the PHP error handler, the
     * exception handler, and dozens of app pages — is a request that has
     * already worked out which organisation it belongs to, so asking Site for
     * the answer is right for all of them.
     *
     * Do not use it where the organisation is not certain. Site::id() answers 1
     * when nothing has told it otherwise, quietly, so a problem belonging to
     * one organisation would be filed against another and nothing anywhere
     * would say so. Use errorPlatformForSite() below and pass the organisation
     * in, or pass null to say honestly that it is not known.
     *
     * @param string   $platform Where the problem happened (PHP, MySQL, cURL…).
     * @param string   $severity Notification | Warning | Error | Critical | Fatal.
     * @param string   $code     A short code for this kind of problem.
     * @param string   $title    One line saying what happened.
     * @param string   $detail   The long version, including any trace.
     * @param int|null $userId   Who was affected, if anybody in particular.
     */
    public static function errorPlatform(
        string $platform,
        string $severity,
        string $code,
        string $title,
        string $detail = '',
        ?int $userId = null
    ): void {
        // 🛟 Site::id() only reads a number Site already holds. The catch is
        //    for a very early request where the Site class itself could not be
        //    loaded; then the organisation is recorded as "not known", which
        //    is the honest answer, rather than the logger throwing.
        try {
            $siteId = Site::id();
        } catch (Throwable $ignored) {
            $siteId = null;
        }
        self::writeErrorRow($siteId, $platform, $severity, $code, $title, $detail, $userId);
    }

    /**
     * Record a problem against an organisation NAMED BY THE CALLER.
     *
     * -------------------------------------------------------------------------
     * WHY THIS EXISTS
     * -------------------------------------------------------------------------
     * errorPlatform() above stamps whatever Site::id() answers. On an ordinary
     * signed-in request that is right, because working out the organisation is
     * the first thing that happens.
     *
     * It is wrong wherever there is no current organisation. Site::id() does
     * not refuse in that situation — it starts life holding the number 1 and
     * hands that back without complaint (web/_core/Site.php). So a problem
     * belonging to the second organisation on an installation would be written
     * into the FIRST organisation's error log. The first organisation's
     * administrator sees an error they cannot explain, and the second
     * organisation's administrator never learns their site had a problem at
     * all. Nothing in either log says the stamp was a guess.
     *
     * That situation is about to become common rather than rare, so the honest
     * version is being put in place first: pass the organisation in, or pass
     * null to record plainly that it is not known. An empty organisation on an
     * error row is allowed by the database and already means exactly that —
     * see tblErrors.siteID, "nullable for pre-bootstrap errors".
     *
     * Identical to errorPlatform() in every other way: same table, same
     * columns, same email alerting, same forwarding to an outside error
     * monitor. The ONLY difference is where the organisation number comes from.
     *
     * @param int|null $siteId   The organisation this belongs to, or null when
     *                           that genuinely is not known. Never a guess.
     * @param string   $platform Where the problem happened (PHP, MySQL, cURL…).
     * @param string   $severity Notification | Warning | Error | Critical | Fatal.
     * @param string   $code     A short code for this kind of problem.
     * @param string   $title    One line saying what happened.
     * @param string   $detail   The long version, including any trace.
     * @param int|null $userId   Who was affected, if anybody in particular.
     */
    public static function errorPlatformForSite(
        ?int $siteId,
        string $platform,
        string $severity,
        string $code,
        string $title,
        string $detail = '',
        ?int $userId = null
    ): void {
        self::writeErrorRow($siteId, $platform, $severity, $code, $title, $detail, $userId);
    }

    /**
     * The shared body behind the two methods above.
     *
     * Written once rather than twice on purpose. Two copies of this would drift
     * — one would gain a column, or a change to the alerting rules, and the
     * other would not, and which of the two a problem was recorded through
     * would quietly start to matter. The two public methods differ in exactly
     * one thing, which is the organisation number, and that is the one thing
     * they each decide before calling in here.
     *
     * -------------------------------------------------------------------------
     * WHY A SECOND ERROR DURING THIS ONE GOES ONLY TO PHP'S LOG
     * -------------------------------------------------------------------------
     * Recording an error does three things: write the row, maybe send an alert
     * e-mail, and maybe forward it to an outside error monitor. The e-mail
     * part calls the Mailer, and the Mailer records ITS OWN failures by
     * calling back into here (Mailer.php — a template that will not render, a
     * mis-typed shared mailbox setting, a send the provider refused). Before
     * this guard, that nested call started a whole new record — row, alert,
     * monitor — and its alert could fail the same way and call back in again.
     * The cooldown marker file was supposed to stop the second alert, but it
     * is touched with "@", so when it cannot be written nothing notices and
     * there is no brake at all. In a test on 14 September 2026 (PHP 8.5.10,
     * MySQL 8.0.36, alerts on for "Error", a mistyped shared mailbox, no
     * writable marker) ONE error made the old code write 35,732 error rows
     * and 250,135 log lines in 40 seconds, until PHP ran out of memory and
     * the request died. The same test with this guard wrote one row and one
     * log line.
     *
     * So while one error is being recorded ($recordingError), a second one
     * raised by that work is not recorded in the database, not alerted and
     * not forwarded. It is written to PHP's own log, and only the first such
     * problem per record; any more are dropped, because they are almost
     * always the same failure repeating (the Mailer is called once per alert
     * recipient). The flag is put back in `finally`, however the record ends.
     *
     * This does not change what an ordinary error does. Nothing on the normal
     * path — a row that writes, an alert that sends — calls back in here.
     *
     * What it cannot do: a problem that genuinely happens in another part of
     * the portal while an alert is being sent (there is nothing else running
     * in the same request at that moment, so this is theoretical) would also
     * go only to PHP's log.
     *
     * @param int|null $siteId Already decided by the caller. Not guessed here.
     */
    private static function writeErrorRow(
        ?int $siteId,
        string $platform,
        string $severity,
        string $code,
        string $title,
        string $detail = '',
        ?int $userId = null
    ): void {
        // 🔁 Already recording an error? Then this one was raised by that work.
        if (self::$recordingError === true) {
            if (self::$nestedProblemNoted === false) {
                self::$nestedProblemNoted = true;
                self::writeFallback(
                    'a second problem (raised while recording an earlier error)',
                    'kept out of the database, alerts and the error monitor so it cannot start a loop; only the first such problem per error is logged',
                    self::errorFallbackFields($siteId, $platform, $severity, $code, $title, $detail, $userId, self::clientIp())
                );
            }
            return;
        }

        self::$recordingError     = true;
        self::$nestedProblemNoted = false;
        try {
            $ip = null;
            try {
                $headersJson = json_encode(self::requestHeaders());
                $ip          = self::clientIp();
                $ua          = $_SERVER['HTTP_USER_AGENT'] ?? '';
                // Without a scheduled job's token: see withoutJobToken().
                $url         = self::requestAddress();

                // 🌐 $siteId arrives as a parameter. It is deliberately NOT worked out
                //    here: the whole point of having two public methods in front of
                //    this one is that the CALLER decides which organisation a problem
                //    belongs to. Asking Site::id() here would undo that, because it
                //    answers 1 when nothing has resolved a site and never says so.
                //    An empty value is written straight through and means "not
                //    known", which this column allows.

                $failure = self::insertRow(
                    'INSERT INTO tblErrors ' .
                    '(siteID, errorPlatform, errorSeverity, errorCode, errorTitle, errorDetail, userID, visitorIP, userAgent, requestURL, requestHeaders) ' .
                    'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    'isssssissss',
                    [$siteId, $platform, $severity, $code, $title, $detail, $userId, $ip, $ua, $url, $headersJson]
                );
            } catch (Throwable $e) {
                $failure = get_class($e) . ': ' . $e->getMessage();
            }

            if ($failure !== null) {
                self::writeFallback(
                    'an error',
                    $failure,
                    self::errorFallbackFields($siteId, $platform, $severity, $code, $title, $detail, $userId, $ip)
                );
            }

            // 🚨 Critical-error alerting (#229).
            //    Severities matching the configured set fire an email dispatch
            //    to portal.alerts.recipients. Rate-limited per (platform, code,
            //    title) fingerprint with portal.alerts.cooldown_minutes cooldown
            //    so a runaway error doesn't spam admins. Failures here are
            //    swallowed — alert delivery must never break the logging path.
            //    This still runs when the row could not be written: an alert
            //    during a database outage is the most useful alert there is.
            try {
                self::maybeDispatchAlert($platform, $severity, $code, $title, $detail);
            } catch (Throwable $ignored) {
                error_log('Alert dispatch failed: ' . self::oneLine($ignored->getMessage()));
            }

            // 📡 External error monitor (#143). When monitoring.enabled = '1' AND
            //    monitoring.sentryDsn is set, forward the event to Sentry /
            //    GlitchTip. Both accept the same store-API envelope. Silent
            //    no-op when not configured. Wrapped in try/catch so any
            //    transport / config failure can never break the logging path.
            try {
                ErrorMonitor::capture($platform, $severity, $code, $title, $detail, $userId);
            } catch (Throwable $ignored) {
                error_log('Error monitor capture failed: ' . self::oneLine($ignored->getMessage()));
            }
        } finally {
            self::$recordingError = false;
        }
    }

    private static function maybeDispatchAlert(
        string $platform,
        string $severity,
        string $code,
        string $title,
        string $detail
    ): void {
        $settings   = App::settings();
        $sevList    = (string) ($settings['portal']['alerts']['severities'] ?? 'Critical,Fatal');
        $configured = array_map('trim', explode(',', $sevList));
        if (in_array($severity, $configured, true) === false) {
            return;
        }
        $recipientsRaw = (string) ($settings['portal']['alerts']['recipients'] ?? '');
        $recipients    = array_filter(array_map('trim', explode(',', $recipientsRaw)));
        if (count($recipients) === 0) {
            return;
        }

        // 🕯️ Sabbath quiet hours (#231). Skip non-critical alerts during
        //    the configured window; critical alerts bypass when
        //    portal.sabbath.bypass_critical = '1' (default).
        if (Sabbath::isQuietNow() === true) {
            $bypass = (string) ($settings['portal']['sabbath']['bypass_critical'] ?? '1');
            $isCritical = in_array($severity, ['Critical', 'Fatal'], true);
            if (!($bypass === '1' && $isCritical === true)) {
                return;
            }
        }

        $cooldown   = (int) ($settings['portal']['alerts']['cooldown_minutes'] ?? 30);
        $fingerprint = hash('sha256', $platform . '|' . $code . '|' . $title);
        $sentinel    = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_backups'
                     . DIRECTORY_SEPARATOR . '.alert-' . substr($fingerprint, 0, 16);
        if (is_file($sentinel) === true
            && (time() - (int) filemtime($sentinel)) < ($cooldown * 60)
        ) {
            return;
        }
        // Touch sentinel BEFORE sending so a slow mail dispatch doesn't double-fire.
        // ⚠️ When this cannot be written (no _backups folder, no permission)
        //    the "@" hides it and there is no cooldown at all. That is why the
        //    loop protection lives in writeErrorRow() and does not rely on
        //    this file.
        @touch($sentinel);

        $portalName = (string) ($settings['site']['name'] ?? 'WebMS Intra');
        $subject    = sprintf('[%s] %s — %s', $portalName, strtoupper($severity), substr($title, 0, 100));
        $vars = [
            'severity' => $severity,
            'platform' => $platform,
            'code'     => $code,
            'title'    => $title,
            'detail'   => $detail,
            'url'      => self::requestAddress(), // without a scheduled job's token
        ];
        $plainFallback = sprintf(
            "Severity: %s\nPlatform: %s\nCode: %s\nTitle: %s\n\nDetail:\n%s\n\nURL: %s\n",
            $severity,
            $platform,
            $code,
            $title,
            substr($detail, 0, 2000),
            self::requestAddress()
        );
        foreach ($recipients as $to) {
            // 🪞 Use the framework Mailer's templated send if available; else
            //    fall back to mail() so alerting works even when no provider
            //    is fully configured.
            if (class_exists('Portal\\Core\\Mailer') === true
                && method_exists('Portal\\Core\\Mailer', 'sendTemplated') === true
            ) {
                try {
                    Mailer::sendTemplated($to, $subject, 'critical-alert', $vars);
                } catch (Throwable $ignored) {
                    @mail($to, $subject, $plainFallback);
                }
            } else {
                @mail($to, $subject, $plainFallback);
            }
        }
    }

    /* ---------------------------------------------------------------------- */
    /* Safe writing and the fallback to PHP's own log                         */
    /* ---------------------------------------------------------------------- */

    /**
     * 🛟 Write one row, and say what went wrong — rather than throw — if it
     * could not be written.
     *
     * This is the only place in this class that touches the database. Every
     * failure is caught: no connection at all, a connection that has been
     * closed (PHP throws an Error for that, not a database exception), and
     * prepare() or execute() failing, whether they throw (strict reporting,
     * which bootstrap.php turns on) or quietly return false (reporting off).
     *
     * On the normal path it does exactly what each method used to do inline:
     * prepare, bind the same values with the same type letters, execute,
     * close.
     *
     * @param string            $sql    A fixed INSERT written in this file, with ? placeholders.
     *                                  Never built from anything a visitor sent.
     * @param string            $types  The bind_param type letters, one per value.
     * @param array<int, mixed> $values The values, in placeholder order.
     * @return string|null Null when the row was written. Otherwise a short
     *                     reason, for the fallback line.
     */
    private static function insertRow(string $sql, string $types, array $values): ?string
    {
        $db = self::db();
        if ($db === null) {
            return 'no database connection';
        }
        try {
            $stmt = $db->prepare($sql);
            if ($stmt === false) {
                return 'prepare failed: ' . $db->error;
            }
            $stmt->bind_param($types, ...$values);
            $written = $stmt->execute();
            $reason  = $stmt->error;
            $stmt->close();
            if ($written === false) {
                return 'execute failed: ' . $reason;
            }
            return null;
        } catch (Throwable $e) {
            return get_class($e) . ': ' . $e->getMessage();
        }
    }

    /**
     * The essentials of an error, for its fallback line: what kind of problem,
     * the message, the file and line (which the error handler and exception
     * handler put at the start of the detail), organisation, user, address,
     * and the page being requested — the same page address the row holds.
     *
     * @return array<string, string|int|null>
     */
    private static function errorFallbackFields(
        ?int $siteId,
        string $platform,
        string $severity,
        string $code,
        string $title,
        string $detail,
        ?int $userId,
        ?string $ip
    ): array {
        return [
            'platform' => $platform,
            'severity' => $severity,
            'code'     => $code,
            'title'    => $title,
            'detail'   => $detail,
            'site'     => $siteId,
            'user'     => $userId,
            'address'  => $ip,
            'url'      => self::requestAddress(),
        ];
    }

    /**
     * 📝 Write ONE line to PHP's own error log for a record the database did
     * not take.
     *
     * Kept to a single line on purpose: newlines inside a message (a stack
     * trace, or text a visitor typed) are flattened, so one event is always
     * one line, and nobody can type a message that looks like a separate,
     * made-up log entry. Each value is cut at 2000 characters so a huge trace
     * cannot flood the log.
     *
     * @param string                          $what   What could not be recorded, in words.
     * @param string                          $reason Why, as insertRow() or a catch reported it.
     * @param array<string, string|int|null>  $fields The essentials to carry, already chosen by the caller.
     */
    private static function writeFallback(string $what, string $reason, array $fields): void
    {
        $parts = [];
        foreach ($fields as $name => $value) {
            $parts[] = $name . '=' . self::oneLine($value);
        }
        error_log(
            '[WebMS-Intra] Logger could not record ' . $what . ' in the database ('
            . self::oneLine($reason) . '): ' . implode('; ', $parts)
        );
    }

    /**
     * Flatten a value onto one line and cap its length, for writeFallback().
     */
    private static function oneLine(string|int|null $value): string
    {
        if ($value === null) {
            return '(none)';
        }
        // Control characters, including every kind of newline, become a space.
        $flat = preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $value);
        if (is_string($flat) === false) {
            return '(could not be printed)';
        }
        if (strlen($flat) > 2000) {
            $flat = substr($flat, 0, 2000) . '...[cut]';
        }
        return $flat;
    }

    /* ---------------------------------------------------------------------- */
    /* Helpers                                                                */
    /* ---------------------------------------------------------------------- */

    private static function severityFromErrno(int $errno): string
    {
        if ($errno === E_NOTICE || $errno === E_USER_NOTICE) {
            return 'Notification';
        }
        if ($errno === E_WARNING || $errno === E_USER_WARNING) {
            return 'Warning';
        }
        if ($errno === E_ERROR || $errno === E_USER_ERROR) {
            return 'Error';
        }
        return 'Fatal';
    }

    /**
     * The names a scheduled job's address carries its token under: `key`
     * (most jobs) and `token` (the health check, the backup check and the
     * retention sweep). Found by searching web/_apps/cron/ for what each job
     * reads, on 13 October 2026. A job that takes its token under another
     * name must be added here, or its token is recorded.
     */
    private const TOKEN_PARAMETERS = ['key', 'token'];

    /**
     * ✂️ The address a request asked for, with the VALUE of a scheduled job's
     * token cut out of it and "[removed]" put in its place. Everything else
     * in the address is kept. Never throws.
     *
     * Use this wherever an address is recorded or sent anywhere: the error
     * log's row, the fallback line in PHP's own log, the alert e-mail, and
     * the outside error service (ErrorMonitor).
     *
     * WHAT WAS WRONG (#597, 13 October 2026). A scheduled job is called by
     * the host's scheduler at an address that ends "?key=…" or "?token=…",
     * and that token is all that protects the job. An error on such a
     * request was recorded with the WHOLE address. So the token sat in
     * tblErrors, on Admin, Error log, for every administrator of every
     * organisation, and in every backup of that table. It went in the alert
     * e-mail too. And it was sent to the outside error service, which was
     * harmless only while that service never worked: its own secret was
     * never decrypted (#497). This release makes it work.
     *
     * HOW A PARAMETER IS RECOGNISED: by its NAME as PHP itself will read it:
     * decoded ("%74oken" is "token"), without a leading space, and without
     * any "[…]" after it. Capital letters are ignored, although PHP does not
     * ignore them, because "?KEY=" typed into a scheduler by mistake still
     * carries the real token.
     *
     * WHAT IT COSTS: the value of ANY parameter called "key" or "token" is
     * cut, on any page, not only a scheduled job's. That is deliberate. A
     * password-reset link and an invitation carry their secret under
     * "token" as well, and telling a job's address from another by its path
     * would be one more list to keep right.
     *
     * WHAT IT CANNOT DO:
     *   - It does not clean rows already stored. An address recorded before
     *     this change keeps its token until that row is deleted, and so does
     *     anything already sent to the outside service. If a job's address
     *     ever appeared in the error log, give that job a new token.
     *   - It knows only the two names above. A secret under any other name,
     *     or written into the PATH of an address (a calendar or podcast feed),
     *     is recorded as before.
     *   - It does not look inside the text of an error. A message that
     *     quotes an address is recorded as written.
     *
     * @param string $address A path with its query, as $_SERVER['REQUEST_URI'] holds it.
     */
    public static function withoutJobToken(string $address): string
    {
        $queryStart = strpos($address, '?');
        if ($queryStart === false) {
            return $address;
        }
        $pairs = explode('&', substr($address, $queryStart + 1));
        foreach ($pairs as $i => $pair) {
            $equals = strpos($pair, '=');
            if ($equals === false || $equals === strlen($pair) - 1) {
                continue; // no value to cut
            }
            $name    = strtolower(ltrim(urldecode(substr($pair, 0, $equals))));
            $bracket = strpos($name, '[');
            if ($bracket !== false) {
                $name = substr($name, 0, $bracket);
            }
            if (in_array($name, self::TOKEN_PARAMETERS, true) === true) {
                $pairs[$i] = substr($pair, 0, $equals + 1) . '[removed]';
            }
        }

        return substr($address, 0, $queryStart + 1) . implode('&', $pairs);
    }

    /** The address THIS request asked for, as it may be recorded: see withoutJobToken(). */
    private static function requestAddress(): string
    {
        return self::withoutJobToken((string) ($_SERVER['REQUEST_URI'] ?? ''));
    }

    private static function requestHeaders(): array
    {
        if (function_exists('getallheaders') === true) {
            $headers = getallheaders();
        } else {
            // Fallback for non-Apache SAPIs
            $headers = [];
            foreach ($_SERVER as $name => $value) {
                if (str_starts_with($name, 'HTTP_') === true) {
                    $key           = str_replace('_', '-', substr($name, 5));
                    $headers[$key] = $value;
                }
            }
        }
        return self::redactSensitiveHeaders($headers);
    }

    /**
     * 🔒 Redact credential-bearing headers before they are persisted to
     * tblActivityLogs / tblErrors / DbBackup snapshots. Bearer tokens,
     * session cookies, CSRF tokens and API keys must never land in plain
     * text in logs. The session itself is dealt with separately: a record
     * keeps a fingerprint of its identifier and a short list of facts, never
     * the session (#622, see sessionFingerprint() and sessionFacts()).
     *
     *
     * 🔗 THE ADDRESS THE BROWSER CAME FROM (the "Referer" header) is kept, but
     * only up to its "?" (#580). WHAT WAS WRONG: a password-reset link is an
     * address with the secret after the "?". When the reset form was sent and
     * refused (passwords that differ, one too weak, an expired form), the
     * browser named the page it came from, and this stored the whole link in
     * tblActivityLogs: unused and still good for the rest of its hour, to
     * anybody who could read that table or a backup of it. Found by running
     * it on 12 October 2026. The page a request came from is still worth
     * knowing; what follows the "?" (or a "#") is not, on any page.
     *
     * WHAT THIS CANNOT DO: it does not clean rows already written. A link
     * stored before this change stays there until it is used or runs out.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function redactSensitiveHeaders(array $headers): array
    {
        static $sensitive = ['authorization', 'cookie', 'x-csrf-token', 'x-api-key'];
        foreach ($headers as $name => $value) {
            $lower = strtolower((string) $name);
            if (in_array($lower, $sensitive, true) === true) {
                $headers[$name] = '[redacted]';
            } elseif ($lower === 'referer') {
                // strcspn(): how many characters come before the first "?" or "#".
                $headers[$name] = substr((string) $value, 0, strcspn((string) $value, '?#'));
            }
        }
        return $headers;
    }

    /**
     * 🌐 The visitor's address, as written into the activity log, the audit
     * trail and the error log.
     *
     * -------------------------------------------------------------------------
     * WHAT WAS WRONG BEFORE
     * -------------------------------------------------------------------------
     * This used to believe the CF-Connecting-IP header, and failing that the
     * first entry of X-Forwarded-For, on EVERY request, from anybody. Only when
     * neither header was sent did it use the address the web server itself
     * saw (REMOTE_ADDR). A request header is just text the visitor types, so
     * anybody could put any address they liked on every log row about them.
     *
     * That was more than untidy logs. The sign-in lockout counts failed
     * attempts per address by reading tblActivityLogs.visitorIP — which is
     * filled in HERE. RateLimiter::clientIp() had already been fixed to stop
     * believing those headers, but the rows it counts were still written with
     * whatever address the visitor chose. So the two disagreed: a visitor who
     * sent a made-up CF-Connecting-IP had their failures filed under the
     * made-up address, while the lockout looked for failures under their real
     * one, found none, and never locked them out.
     *
     * -------------------------------------------------------------------------
     * WHAT IT DOES NOW
     * -------------------------------------------------------------------------
     * It asks RateLimiter::clientIp(), the one place that decides a visitor's
     * address, so what is logged and what the lockout counts are always the
     * same address. A forwarded header is believed only when it arrives from a
     * machine listed in the portal.trustedProxies setting; the full rules are
     * beside RateLimiter::getClientIp().
     *
     * -------------------------------------------------------------------------
     * WHY IT IS WRAPPED SO CAREFULLY
     * -------------------------------------------------------------------------
     * The logger is what runs while things are going wrong — from the PHP
     * error handler, the exception handler, and after a database failure. So:
     *
     *   1. It never throws. Anything RateLimiter throws is caught here, because
     *      an exception escaping from inside the error handler would replace
     *      the problem being recorded with a new one.
     *   2. It does not need a working database. RateLimiter reads its proxy
     *      settings from the database but catches every failure itself, and
     *      then trusts no header at all. If even that goes wrong, this uses the
     *      web server's address.
     *   3. It cannot go round in a circle. RateLimiter reads its proxy
     *      settings while working out the address. If anything in that read
     *      ever writes to the log — a "could not read the proxy settings" line
     *      is exactly what somebody might reasonably add — the logger would call
     *      this, this would ask RateLimiter again, RateLimiter would read the
     *      settings again (it only remembers them once a read has finished),
     *      and so on without end. So while one lookup is under way
     *      ($lookingUp), a second call does not start another; it answers with
     *      the web server's address straight away.
     *      Nothing inside RateLimiter writes to the log today, so this guards
     *      against a future change rather than curing a loop that happens now.
     *      A PHP WARNING raised during that read does not loop even without
     *      the guard, because PHP does not call the error handler again while
     *      the handler is still running. Both behaviours were checked on
     *      13 September 2026 with PHP 8.5.10: with the guard removed, a log
     *      call from inside the read nested until the test stopped it; with the
     *      guard in place it logged once and carried on.
     *      $lookingUp belongs to this one method and lasts for the whole
     *      request; `finally` puts it back however the lookup ends.
     *
     * The fallback is NEVER a header. Falling back to CF-Connecting-IP or
     * X-Forwarded-For would bring back the fault above, only less often.
     *
     * -------------------------------------------------------------------------
     * WHAT THIS CANNOT DO
     * -------------------------------------------------------------------------
     * When the proxy settings cannot be read, a site that genuinely sits behind
     * Cloudflare or a load balancer logs that machine's address instead of the
     * visitor's. That is the safe way round, and it is the same address the
     * lockout uses at that moment. RateLimiter remembers that failed read for
     * the rest of the request, so later lookups in the same request get the
     * same answer — also on purpose, so the log and the lockout keep agreeing.
     *
     * @return string The visitor's address, or '0.0.0.0' when there is none
     *                (outside a web request).
     */
    private static function clientIp(): string
    {
        static $lookingUp = false;

        $fallback = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        // 🔁 Already inside a lookup (the logger was called from somewhere
        //    inside RateLimiter's own lookup)? Do not start another one.
        if ($lookingUp === true) {
            return $fallback;
        }

        $lookingUp = true;
        try {
            return RateLimiter::clientIp();
        } catch (Throwable $ignored) {
            // 🛟 RateLimiter could not answer. Use the connection's own
            //    address, never a header.
            return $fallback;
        } finally {
            $lookingUp = false;
        }
    }
}
