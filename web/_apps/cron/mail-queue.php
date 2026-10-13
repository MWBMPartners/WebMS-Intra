<?php
// Path: _apps/cron/mail-queue.php
/**
 * -----------------------------------------------------------------------------
 * Cron — the mail queue's job ✉️⏳ (#592, part 3)
 * -----------------------------------------------------------------------------
 * The scheduled job that sends the mail that could not be sent at once. Each
 * call does one run of Portal\Core\MailQueue::run(): it puts a dead run's
 * messages back, applies the time limits, sends what is due (most urgent
 * first, within the hourly limit) and clears up. It is meant to be called
 * every five minutes by the hosting company's scheduler.
 *
 * The addresses below are examples only; use the portal's own.
 *
 *   curl -fsS -H "Authorization: Bearer <TOKEN>" "https://<your-portal>/cron/mail-queue"
 *
 * or, where the scheduler can only fetch an address:
 *
 *   https://<your-portal>/cron/mail-queue?token=<TOKEN>
 *
 * THE TOKEN is the one the other maintenance jobs share (the
 * `maintenance.cronToken` setting), compared in constant time. An EMPTY
 * stored token refuses everything, so the job does nothing until a global
 * administrator has set one.
 *
 * WHERE THE TOKEN MUST NOT END UP. Another scheduled job was found storing
 * its whole address, token included, with every error it recorded, and the
 * Errors page shows that address to administrators. So:
 *   - the token may be sent in the "Authorization" header, which the portal's
 *     logger never stores and which is in no web-server access log;
 *   - when it is sent in the address, it is taken out of what PHP holds for
 *     the request BEFORE anything else happens here, so that no error or
 *     activity record written from this point on can carry it.
 * An error raised while the portal starts up, BEFORE this page is loaded, is
 * covered separately: the logger cuts the value of any "token" or "key" in an
 * address it records (Logger::withoutJobToken()). (This note used to say such
 * an error was recorded with the token. That stopped being true when the
 * logger gained that cut.)
 * WHAT NEITHER CAN DO: keep a token sent in the address out of the web
 * server's own access log, which the portal does not control, or out of the
 * TEXT of an error that quotes an address. The header form avoids both.
 * (Some Apache set-ups do not pass that header to PHP; the answer is then
 * "forbidden", and the address form is the one to use.)
 *
 * WHAT IT PRINTS: NUMBERS ONLY. One line of JSON with a status word and
 * counts. Never an address, a subject or any part of a message: the answer
 * goes to a scheduler, whose output is often e-mailed or kept in a log.
 *   {"status":"ok", "sent":…, "waiting":…, "held":…, "failed":…, …}
 *   {"status":"busy", …}       another run holds the lock; nothing was done
 *   {"status":"forbidden"}     HTTP 403: no token, a wrong one, or none set
 *   {"status":"error"}         HTTP 500: the run stopped; the reason is in
 *                              the server's error log
 *
 * WHAT IT CANNOT DO
 *   - It does not run while the portal is in maintenance mode: like every
 *     scheduled job except the read-only health report, it gets the holding
 *     page (HTTP 503) then. Mail waits and goes afterwards.
 *   - On a pre-release copy (beta, alpha, dev) the OTHER scheduled jobs are
 *     behind the staff sign-in gate and cannot be run by a scheduler. This
 *     one is let past that gate, because it checks its own token and prints
 *     numbers only: see Gatekeeper::OPEN_PATHS.
 *   - It is the only thing that records "the job ran". That moment is what
 *     tells every sending page that waiting mail will be sent
 *     (MailQueue::jobIsRunning()), so it is written here, after the token
 *     has been accepted, and nowhere else.
 *   - It is a web request and is ended by the web server's own time limit.
 *     A run therefore starts no new message after 20 seconds. If it is ended
 *     all the same, nothing is lost and nothing is sent twice: see
 *     MailQueue::recoverDeadClaims().
 *
 * @package   Portal\App\Cron
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/592
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Logger;
use Portal\Core\MailQueue;
use Portal\Core\MailRoutes;

// 🙈 Never print a PHP warning into the answer: it is read by a machine, and
//    a warning would both break the JSON and could carry text from a message.
ini_set('display_errors', '0');

// -----------------------------------------------------------------------------
// 🔑 Read the token, then take it out of the request.
// -----------------------------------------------------------------------------
// This page starts no session and reads none. (The portal's start-up may still
// send a session cookie with the answer, as it does for any address; a
// scheduler ignores it. An earlier note here said "no session is involved",
// which was more than is true.)
// Only plain text counts. A hand-written request can send a LIST where text is
// expected ("?token[]=x"); turning that into text raises a PHP warning, which
// the portal records with the request's address.
$given  = '';
$header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (is_string($header) === true && preg_match('/\ABearer\s+(\S+)\z/i', trim($header), $found) === 1) {
    $given = $found[1];
} elseif (is_string($_GET['token'] ?? null) === true) {
    $given = $_GET['token'];
}
// 🧽 From here on, nothing PHP holds for this request carries the token. The
//    logger reads the address from $_SERVER['REQUEST_URI'] at the moment it
//    records something, so this is what keeps the token off the Errors page.
unset($_GET['token'], $_REQUEST['token']);
$_SERVER['QUERY_STRING'] = '';
$_SERVER['REQUEST_URI']  = (string) strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
if (isset($_SERVER['HTTP_AUTHORIZATION']) === true) {
    $_SERVER['HTTP_AUTHORIZATION'] = '[removed]';
}
if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) === true) {
    $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = '[removed]';
}

// 📄 JSON, never cached: a cached "ok" from an earlier run would hide this one's failure.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$configured = App::settings('maintenance.cronToken');
$configured = is_string($configured) === true ? $configured : '';
if ($configured === '' || hash_equals($configured, $given) === false) {
    http_response_code(403);
    echo json_encode(['status' => 'forbidden']);
    exit();
}

// -----------------------------------------------------------------------------
// 🏃 One run.
// -----------------------------------------------------------------------------
// A scheduler that hangs up early (its own time limit) must not end the run
// in the middle of handing a message over. The time limit is raised where the
// host allows it; where it does not, the run's own 20-second rule still holds.
ignore_user_abort(true);
if (function_exists('set_time_limit') === true) {
    @set_time_limit(120);
}

try {
    // 🕰️ The scheduler really called, with the right token: say so, BEFORE
    //    the run, so that it is on record even when this run finds another
    //    still working. Only this page writes it: see the file header.
    MailRoutes::jobRan(App::db());
    $numbers = MailQueue::run(App::db());
} catch (\Throwable $problem) {
    // The exception's text is a database or file-system message. The body of
    // a message is encrypted before it ever reaches a statement, so it
    // cannot be in one. Kept short all the same.
    error_log('Mail queue job: the run stopped: ' . get_class($problem) . ': ' . mb_substr($problem->getMessage(), 0, 300));
    http_response_code(500);
    echo json_encode(['status' => 'error']);
    exit();
}

if ($numbers['ran'] === 0) {
    // Not an error: the other run is doing the work. HTTP 200, so that a
    // scheduler which reports every failure does not report this.
    echo json_encode(['status' => 'busy'] + $numbers);
    exit();
}

// 📝 One activity line, and only when something happened: a line for every
//    quiet run would be 288 a day. Numbers only.
if ($numbers['sent'] + $numbers['held'] + $numbers['failed'] + $numbers['expired'] + $numbers['removed'] > 0) {
    Logger::activity(
        'MailQueueRun',
        'Mail queue: ' . $numbers['sent'] . ' sent, ' . $numbers['held'] . ' held, ' . $numbers['failed'] . ' failed, '
        . $numbers['expired'] . ' expired, ' . $numbers['removed'] . ' old rows removed'
    );
}
echo json_encode(['status' => 'ok'] + $numbers);
exit();
