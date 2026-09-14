<?php
// Path: _apps/cron/health.php
/**
 * -----------------------------------------------------------------------------
 * Cron — System Health for uptime monitors 🩺 (#228, #497)
 * -----------------------------------------------------------------------------
 * Returns every System Health check as JSON, for an uptime monitor or a
 * scheduled job:
 *
 *   curl -fsS "https://<your-portal>/cron/health?token=<TOKEN>"
 *
 * Not to be confused with /health, which needs no token and only says whether
 * the database answers. This one runs the full set of checks (database, disk,
 * backups, recent errors, sessions, migrations, PHP, maintenance flag), so it
 * is kept behind a token.
 *
 * WHY IT HAS ITS OWN ADDRESS
 * This used to be /admin/maintenance/health?cron=1&token=…, a mode of the
 * staff page. That page is seeded as a protected address, and from
 * 14 September 2026 the Router really does send a signed-out visitor to the
 * sign-in page (issue #497). A monitor has a token but no session, so it would
 * have been redirected — and most monitors count a redirect as "up", so a
 * broken portal would have gone on looking healthy. Every other scheduled job
 * already lives at a cron/... address seeded as NOT protected with its own
 * token check, and this now does too. The "?cron=1" mode has been removed from
 * the staff page.
 *
 * THE TOKEN CHECK IS DELIBERATELY THE SAME AS THE OLD JOB MODE
 * Same setting (`maintenance.cronToken`), same `token` query parameter, same
 * constant-time comparison, and the same responses: 403 with
 * {"error":"invalid_token"} for a missing, wrong or unset token, and
 * {"overall":…, "checked_at":…, "probes":{…}} otherwise. An EMPTY stored token
 * refuses everything.
 *
 * The checks themselves are in _health-probes.php beside this file, shared
 * with the staff page so the two can never disagree.
 *
 * IT STILL ANSWERS DURING MAINTENANCE MODE — AND MUST STAY READ-ONLY
 * While the portal is closed for an upgrade, web/_core/Maintenance.php lets
 * exactly this address past the holding page (owner's decision,
 * 14 September 2026). A monitor therefore gets this report, including the
 * "Maintenance mode" check, instead of a 503 page, just as it did at the old
 * address. It is only let through because this page is meant to write
 * nothing. Do not add a check that writes anything without first taking
 * `cron/health` off that list. No other cron/ job is let through: the ones
 * that change things stay behind the holding page, so they never run against
 * a half-upgraded database.
 *
 * "Meant to write nothing" needed three fixes before it was true of this
 * page's own code, because the portal's error handler records every PHP
 * warning as a tblErrors row. A warning could be raised in the checks (a
 * session file vanishing while it was counted), by anybody in the token check
 * (?token[]=x), and, on a copy that shows PHP errors on screen, by header()
 * after an earlier warning had been printed into the answer. So:
 *   - the token is only accepted as plain text;
 *   - this page never prints a PHP warning into its answer, maintenance or
 *     not;
 *   - while maintenance mode is on, a handler that leaves the database alone
 *     is in place from just before the token check until the page stops, and
 *     an exception anywhere in that stretch gets a 500 JSON answer without a
 *     database row.
 * The comments above that code explain what each part covers and what it
 * cannot, including the code that runs before this page, which is not covered.
 *
 * What was measured on 14 September 2026, with maintenance mode on, against a
 * test database, under both PHP's built-in server and php-cgi with output not
 * held back and errors shown on screen: correct, wrong, missing and
 * list-shaped tokens; all three warning conditions above; and, in a test-only
 * copy, output forced out before the headers, a warning just before the
 * report, and an exception just before the report. Nothing was added to
 * tblErrors. PHP's session handling still created its usual empty session file
 * for each request. That is a measurement of those calls, not a promise about
 * every possible request.
 *
 * Which line shows the upgrade depends on why the portal is closed. The
 * "Maintenance mode" check reads only the on/off switch
 * (portal.maintenance.active). When the portal has closed ITSELF because the
 * code is newer than the database, that switch can still be off, so the line
 * says "Off" and the "Migrations" line says "upgrade needed" instead.
 *
 * WHAT IT CANNOT DO
 *   - On a beta or alpha copy it is behind the pre-release gate, like every
 *     other scheduled job (owner's decision, 13 September 2026). That gate
 *     runs after the maintenance check, so it applies during maintenance too.
 *
 * @package   Portal\App\Cron
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/228
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/497
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Maintenance;

// -----------------------------------------------------------------------------
// 🙈 NEVER PRINT A PHP WARNING INTO THIS PAGE'S ANSWER, MAINTENANCE OR NOT.
// -----------------------------------------------------------------------------
//    What was wrong (found by the third verification, 14 September 2026):
//    web/_core/bootstrap.php shows PHP errors on screen on every channel except
//    the live one ('prod'), including a live server whose web folder name the
//    portal does not recognise. On hosting where output is not held back
//    (FastCGI with output_buffering=0, which is common), a warning printed
//    inside the checks went out as the first bytes of the answer. Two things
//    followed:
//      - PHP has to send the headers before the first byte of the body, so
//        the status and content type were fixed there and then (200,
//        text/html). The later header('Content-Type: application/json') then
//        raised "headers already sent". The maintenance-only handler below had
//        already been put back by then, so that second warning reached the
//        portal's handler and was written to tblErrors while the portal was
//        closed for an upgrade.
//      - The body was the warning text followed by the JSON, which is not
//        valid JSON, so a monitor could not read the report at all.
//
//    What happens now: display is switched off for this page, before any of
//    its own code runs. A warning is still reported exactly as before
//    (tblErrors when the portal is open, PHP's own log either way, if the host
//    has that log switched on). It simply is not printed into the answer, so
//    the headers are not sent early and the JSON stays readable.
//
//    Why for the whole page and not only during maintenance: the answer is
//    only ever read by a machine, and a warning printed into it breaks the
//    JSON just as badly when the portal is open. On the live channel display
//    was already off, so nothing changes there. On a beta, alpha or dev copy
//    the one difference when the portal is open is that the warning text no
//    longer appears at the top of the JSON; it is still on the Errors page.
//    This is a setting for this page only. It does not change bootstrap's
//    rule for any other page, which is deliberately left as it is (see the
//    note there).
//
//    Tried and rejected:
//      - Sending the Content-Type header before the checks. The status (200,
//        403 or 500) is not known until after the token check and the checks,
//        and a warning printed after the headers would still corrupt the JSON.
//      - Holding the output back with ob_start() and throwing away anything
//        printed. The warning text is still produced; every way out of the
//        page would have to remember to discard it, because exit() sends
//        whatever is held back; and it does nothing about the database write,
//        which is the handler's job below.
//
//    What this CANNOT do:
//      - Anything printed BEFORE this line (by bootstrap, say, on a copy that
//        shows errors) is already in the answer and may already have sent the
//        headers. This page cannot take it back. During maintenance the
//        resulting header() warnings still stay out of the database, because
//        the handler below is in place by then.
//      - A host that locks display_errors on in its own configuration makes
//        ini_set() quietly do nothing. The JSON could then be corrupted as
//        before; the database would still be left alone during maintenance,
//        for the same reason.
ini_set('display_errors', '0');

require_once __DIR__ . DIRECTORY_SEPARATOR . '_health-probes.php';

// -----------------------------------------------------------------------------
// 🚧 WHILE MAINTENANCE MODE IS ON, NOTHING THIS PAGE DOES MAY BE WRITTEN TO THE
//    DATABASE — NOT EVEN A RECORD OF A PROBLEM.
// -----------------------------------------------------------------------------
//    What was wrong, found in three steps on 14 September 2026:
//
//    1. The checks themselves only read. But the portal's own error handler
//       (set up in web/_core/bootstrap.php) writes a row into tblErrors for
//       EVERY PHP warning, even one the code has deliberately hidden with "@".
//       The session count reads the modified time of each session file with
//       @filemtime(); if PHP's own session clean-up deletes a file between the
//       folder being listed and that read, a warning is raised and a row was
//       written. Reproduced: one call, one new tblErrors row. An uncaught
//       exception would do the same through the exception handler. The first
//       fix put a harmless handler around the checks only.
//
//    2. That first fix started too late. The token check came before it and
//       read the token with (string) $_GET['token']. Anybody, with no token at
//       all, could send ?token[]=x. PHP then turns the token into a LIST, and
//       converting a list to text raises an "Array to string conversion"
//       warning, which the portal's handler wrote into tblErrors. Reproduced by
//       the second verification: one signed-out call, a 403 answer, and one
//       new tblErrors row. The old address, /admin/maintenance/health?cron=1,
//       read the token the same way, so the fault was not new, but this
//       address is only let through during maintenance because it writes
//       nothing, so it had to be closed.
//
//    3. The handler was put back too early. It was removed in a `finally`
//       block straight after the checks, BEFORE the page sent its headers and
//       its JSON. Anything those last steps raised went to the portal's
//       handler. The route found by the third verification: errors shown on
//       screen, output not held back (php-cgi with output_buffering=0), and a
//       visible notice in the checks (a backup snapshot whose _manifest.json
//       is a folder). The notice was printed, which sent the headers; the
//       handler was put back; header() then raised "headers already sent";
//       one tblErrors row. The display part is covered by the section above;
//       this part is why the handler now stays.
//
//    What happens now:
//      - The token is only accepted if it arrived as plain text. Anything
//        else (a list, for example) is treated as no token, which gets the
//        same 403 as a wrong one. This removes that warning whether or not
//        the portal is in maintenance mode, so an open portal also stops
//        recording a row for it. The answer a caller sees is unchanged.
//      - ONLY while Maintenance::isActive() says the portal is closed (the
//        same function the front controller asked a moment ago), a warning
//        handler that does not touch the database is put in place just
//        before the token check and is NEVER put back. Every step after it
//        is covered: the token check, the checks, http_response_code(),
//        header(), json_encode(), echo and exit(). It hands each warning
//        straight on to PHP's built-in handling. That still respects "@", so a
//        hidden warning is recorded nowhere; a warning that is not hidden goes
//        to PHP's own error log (the server's log, not the portal's
//        database), which is the same place the portal's logger falls back to
//        when it cannot write a row. It starts BEFORE the token check, so a
//        caller who does not know the token cannot reach any code outside it.
//        Leaving it in place is safe because every way through this page
//        ends in exit(): no later code in the request needs the portal's
//        handler back, and anything PHP reports while the request winds down
//        cannot reach the portal's handler either.
//      - In that same stretch, an exception is caught here, one line goes to
//        PHP's own error log, and the caller gets HTTP 500 with
//        {"error":"health_check_failed"}. A 500 is what it would have got
//        before, too; only the database row is gone. The answers themselves
//        (403 and the report) are written inside that stretch too, so an
//        exception while writing them is caught the same way.
//
//    Tried and rejected for step 3:
//      - Moving header() above the restore, or restoring the handler just
//        before each exit(). Either works for today's code, but it leaves a
//        restore step on every way out of the page that the next change could
//        put in the wrong place again, which is exactly how step 3 happened.
//        Never restoring cannot be put in the wrong place.
//
//    Why the handler is not used when the portal is open: then a warning here
//    is a real fault that an administrator should see on the Errors page, and
//    the database is safe to write to. The original handlers stay in place and
//    an exception is passed straight on to them, exactly as before.
//
//    What this CANNOT do:
//      - Everything that runs before this page (bootstrap, starting the
//        session, looking up the address) runs for every request, including
//        the ones that are shown the holding page. A warning or exception
//        there is written exactly as it would be for any other visitor. For
//        example, ?lang[]=x on ANY address makes bootstrap write tblErrors
//        rows during maintenance, holding page or not. This page does not add
//        that exposure and cannot remove it; it is a fault in bootstrap.
//      - It does not stop a deliberate write. If somebody adds a check that
//        writes on purpose, `cron/health` must come off EXACT_ALLOW_LIST in
//        web/_core/Maintenance.php.
//      - A fatal error that stops PHP outright (running out of memory, for
//        example) cannot be caught by anything in PHP. Nothing in the portal
//        registers a shutdown function that would record one, so it writes no
//        row either.
//      - The empty session file PHP creates for each request is not covered.
//        That is PHP's own session handling, on disk, not the database.
//      - Maintenance::isActive() itself runs just before the handler is put in
//        place, because the handler depends on its answer. It only reads the
//        settings already loaded into memory.
$closedForUpgrade = Maintenance::isActive();
if ($closedForUpgrade === true) {
    // ⚠️ Deliberately never paired with restore_error_handler(): see step 3.
    set_error_handler(static function (): bool {
        // false = let PHP's built-in handling deal with it (see above).
        return false;
    });
}

try {
    // -------------------------------------------------------------------------
    // 🔑 Token check. No session is involved at all.
    // -------------------------------------------------------------------------
    $expected = (string) (App::settings()['maintenance']['cronToken'] ?? '');
    $supplied = $_GET['token'] ?? '';
    if (is_string($supplied) === false) {
        // ?token[]=x and similar: treated as no token (see above).
        $supplied = '';
    }

    // 🚫 A missing, wrong or unset token: the same 403 answer as always.
    if ($expected === '' || hash_equals($expected, $supplied) === false) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'invalid_token']);
        exit();
    }

    // -------------------------------------------------------------------------
    // 🩺 Run the checks, only for a caller with the right token.
    // -------------------------------------------------------------------------
    $health = maintenance_health_probes(App::db());

    // 📤 Report. Same shape, same key order as before.
    header('Content-Type: application/json');
    echo json_encode([
        'overall'    => $health['overall'],
        'checked_at' => date('c'),
        'probes'     => $health['probes'],
    ]);
    exit();
} catch (\Throwable $e) {
    if ($closedForUpgrade === false) {
        // Portal open: behave exactly as before this change.
        throw $e;
    }
    error_log(
        '[WebMS-Intra] /cron/health checks failed during maintenance mode (not written to the database): '
        . get_class($e) . ': ' . str_replace(["\r", "\n"], ' ', $e->getMessage())
    );
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'health_check_failed']);
    exit();
}
