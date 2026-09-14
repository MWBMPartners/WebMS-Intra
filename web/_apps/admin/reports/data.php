<?php
// Path: public_html/admin/reports/data.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Reports JSON Data Endpoint
 * -----------------------------------------------------------------------------
 * Returns report data as JSON for dynamic chart rendering. Admin only.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.3
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/93
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
if (Auth::check() === false || App::isAdmin() !== true) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']);
    exit();
}

$siteId  = Site::id();
$report  = trim($_GET['report'] ?? '');

header('Content-Type: application/json');

switch ($report) {
    case 'monthly_logins':
        $data = [];
        // 🛡️ tblActivityLogs has no `createdAt` column — it's `timestamp`
        //    (see full_schema.sql). An earlier version of this comment said
        //    the old column name made prepare() fail quietly, so this
        //    returned [] on every call. That was wrong: bootstrap.php sets
        //    MYSQLI_REPORT_STRICT, so an unknown column throws the moment the
        //    statement is prepared and the whole request ends in a 500, like
        //    the attendance query below (#501).
        // 🛡️ The filter also read activityType = 'Login', which nothing in
        //    the portal ever writes, so the list was always empty (#501).
        //
        // 📋 WHAT THIS COUNTS: each COMPLETED sign-in, once. The figure is
        //    built from the activity rows each sign-in route writes. Checked
        //    by real sign-ins on a MySQL 8.0.36 test database on
        //    14 September 2026 (password and passkey). Microsoft 365 and
        //    Google cannot be run there, so those two come from reading
        //    web/_core/Auth.php only.
        //
        //    Password, no second step, or a remembered device:
        //        LoginLocal
        //    Password, then a code or backup code:
        //        LoginLocal, [TotpVerifyFailed ...], TotpVerified
        //    Passkey, no second step:              LoginWebAuthn
        //    Passkey, then a code:                 LoginWebAuthnPending2fa, TotpVerified
        //    Microsoft 365 / Google, no second step: LoginMS365 / LoginGoogle
        //    Microsoft 365 / Google, then a code:  Login...Pending2fa, TotpVerified
        //    Wrong password:          LoginFailed (twice). Refused by the limiter: LoginBlocked
        //    Passkey refused:         no activity row at all
        //
        //    So a completed sign-in is one of:
        //      - LoginMS365, LoginGoogle or LoginWebAuthn. These are only
        //        written when no second step is needed. When one IS needed,
        //        those routes write a "...Pending2fa" row instead, which is
        //        not counted.
        //      - TotpVerified. It is written once, when the second step
        //        succeeds, whichever route started the sign-in. It is written
        //        nowhere else.
        //      - LoginLocal, but ONLY when the same session has no later
        //        TotpVerified or TotpVerifyFailed row.
        //
        //    Why LoginLocal needs that extra test: Auth::loginLocal() writes
        //    LoginLocal BEFORE the sign-in page decides a second step is
        //    needed (web/_apps/auth/login/index.php). So a password sign-in
        //    that goes on to a code writes LoginLocal as well. Counting it
        //    would count that sign-in twice, and would count one the person
        //    abandoned. The two rows can USUALLY be linked, because every
        //    sign-in route gives the browser a brand-new session number
        //    (session_regenerate_id), and the code page keeps that same
        //    number. So a later code row in that session normally belongs to
        //    this LoginLocal.
        //    ⚠️ Not guaranteed. An earlier version of this comment said it
        //    belonged to "this LoginLocal and no other sign-in", and an
        //    independent check disproved that on 14 September 2026: if one
        //    person abandons the code page and a second person then signs in
        //    by password in the same browser, the first person's "waiting for
        //    a code" marker stays in the session (login/index.php does not
        //    clear it on a sign-in that needs no code). A code posted after
        //    that is recorded against the second person's session, so this
        //    query drops the second person's completed sign-in and counts the
        //    first person's abandoned one. The monthly total can still come
        //    out right, but only by coincidence. The real fix is in
        //    login/index.php, outside this file.
        //
        // ⚠️ WHAT THIS CANNOT DO. It is the closest honest figure the rows
        //    allow, not an exact one:
        //    - A password sign-in that reached the code page and was then
        //      abandoned WITHOUT any wrong code being recorded IS counted.
        //      Its only row is a LoginLocal that looks exactly like a
        //      completed one. That includes an attempt refused before the
        //      code was checked (the code-page limiter or an expired form).
        //      If one wrong code was recorded first, it is correctly left out.
        //    - After a person's data is erased, the eraser blanks sessionID
        //      on every row that carries their user number
        //      (GdprEraser: UPDATE ... WHERE userID = ?). The code rows carry
        //      it. LoginLocal does not, because Logger is not given the user
        //      number there. The link is then lost for that person: each of
        //      their password-and-code sign-ins counts twice, and an abandoned
        //      one with a recorded wrong code counts once. Checked on the test
        //      database by applying that UPDATE by hand.
        //    - Signing up through an invitation signs the person in but
        //      writes no activity row, so it is not counted.
        //    - A sign-in counts under the organisation that was active when
        //      its counted row was written.
        //    The exact fix is in the logging, not here: write LoginLocal only
        //    after the second-step decision, as the other routes do (a
        //    "LoginLocalPending2fa" row otherwise). Reported on #501.
        //
        // ❌ Tried and rejected:
        //    - IN ('LoginLocal','LoginMS365','LoginGoogle','LoginWebAuthn'),
        //      the first version of this fix. It counted abandoned password
        //      sign-ins, counted every password-and-code sign-in once from
        //      LoginLocal, and missed every passkey, Microsoft 365 or Google
        //      sign-in that needed a code.
        //    - LIKE 'Login%'. That also takes in LoginFailed, LoginBlocked
        //      and the "...Pending2fa" rows.
        //    - Adding TotpVerified to that list without the session link.
        //      That counts every password-and-code sign-in twice.
        //    - Guessing from whether the person has a second step switched on
        //      NOW. That setting changes over time. And whether a remembered
        //      device was used is not in the log: the logger blanks cookies.
        //    - Leaving out code rows whose session number has been blanked,
        //      to stop the double count after erasure. That fixes the password
        //      case, but it makes an erased person's passkey, Microsoft 365
        //      and Google sign-ins with a code count zero instead of once.
        //
        // 🏢 Only this organisation's rows. The earlier "OR siteID IS NULL"
        //    is gone. Logger::activity() always writes Site::id(), which is
        //    never empty, and migration 015 filled in the old rows. So a blank
        //    siteID only appears when an organisation has been deleted, since
        //    the column's link to tblSites is ON DELETE SET NULL. Those
        //    sign-ins belong to no current organisation, and they were being
        //    added to every organisation's figure.
        //    The code-row lookup below is deliberately NOT limited to this
        //    organisation. Its only job is to find whether this LoginLocal
        //    went on to the code page, wherever that row was recorded.
        //
        // 🐢 How the session link is made, and why this way.
        //    tblActivityLogs has no index on sessionID or timestamp.
        //    The first draft used NOT EXISTS (a later code row in this
        //    session). MySQL 8.0.36 ran that as a separate search for EVERY
        //    LoginLocal row, over all the rows after it. The time therefore
        //    grows with the number of sign-ins multiplied by the size of the
        //    log. On a 100,000-row test table (14 September 2026) it was still
        //    running when stopped at 120 seconds. This version took under half
        //    a second there, and gave the same answer on every test sign-in.
        //    The old single list with no linking took about a fifth of a
        //    second.
        //    Instead, "codes" below is a list of the sessions that reached the
        //    code page. It is read once, and MySQL CAN build its own lookup
        //    index on it when its chosen plan benefits (it is not guaranteed;
        //    the half-second figure above is what the test database showed).
        //    It holds one row per session (GROUP BY sessionID), so the
        //    join can never repeat a sign-in row and inflate COUNT(*).
        //    codes.lastCodeLogID < l.logID means "every code row in that
        //    session came before this LoginLocal", which is the same test as
        //    "no later code row". It cannot happen in practice, because the
        //    session number is brand new, but it keeps the meaning exact.
        //    The list is limited to the same 12 months: a code row always
        //    comes after its LoginLocal, so one that matters is never older
        //    than the window.
        $stmt = $mysqli->prepare(
            'SELECT DATE_FORMAT(l.`timestamp`, \'%Y-%m\') AS month, COUNT(*) AS cnt '
            . 'FROM tblActivityLogs l '
            . 'LEFT JOIN ('
            . 'SELECT sessionID, MAX(logID) AS lastCodeLogID FROM tblActivityLogs '
            . 'WHERE activityType IN (\'TotpVerified\', \'TotpVerifyFailed\') '
            . 'AND sessionID IS NOT NULL '
            . 'AND `timestamp` >= DATE_SUB(NOW(), INTERVAL 12 MONTH) '
            . 'GROUP BY sessionID'
            . ') codes ON codes.sessionID = l.sessionID AND l.activityType = \'LoginLocal\' '
            . 'WHERE l.siteID = ? '
            . 'AND l.`timestamp` >= DATE_SUB(NOW(), INTERVAL 12 MONTH) '
            . 'AND ('
            . 'l.activityType IN (\'LoginMS365\', \'LoginGoogle\', \'LoginWebAuthn\', \'TotpVerified\') '
            . 'OR (l.activityType = \'LoginLocal\' '
            . 'AND (codes.lastCodeLogID IS NULL OR codes.lastCodeLogID < l.logID))'
            . ') '
            . 'GROUP BY month ORDER BY month'
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $siteId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
            $stmt->close();
        }
        echo json_encode(['status' => 'ok', 'data' => $data]);
        break;

    case 'expense_monthly':
        $data = [];
        $stmt = $mysqli->prepare(
            'SELECT DATE_FORMAT(claimDate, \'%Y-%m\') AS month, '
            . 'COUNT(*) AS claims, COALESCE(SUM(totalAmount), 0) AS total '
            . 'FROM tblExpenseClaims WHERE siteID = ? '
            . 'AND claimDate >= DATE_SUB(NOW(), INTERVAL 12 MONTH) '
            . 'GROUP BY month ORDER BY month'
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $siteId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
            $stmt->close();
        }
        echo json_encode(['status' => 'ok', 'data' => $data]);
        break;

    case 'attendance_monthly':
        $data = [];
        // 🛡️ Same fault as reports/index.php's attendance stat (#501):
        //    headcount is not a column of tblAttendanceSessions. A session
        //    can have several counted groups (Adults, Children, Visitors,
        //    ...), each its own row in tblAttendanceCounts, linked back by
        //    sessionID — see full_schema.sql. Reading "headcount" straight
        //    off tblAttendanceSessions has never worked; MySQL refused the
        //    query at prepare() time (unknown column), and because this
        //    app's database connection throws on that (MYSQLI_REPORT_STRICT
        //    in bootstrap.php), every call to this chart data address
        //    (`/admin/reports/data?report=attendance_monthly`) was a hard
        //    500. Fixed with the same shape as
        //    attendance/index.php's own "Quick stats" query: LEFT JOIN to
        //    tblAttendanceCounts and SUM its headcount column, counting
        //    DISTINCT sessions so a session with several counted groups
        //    isn't counted more than once.
        // 🛡️ Round 2 fix (review gap, #501): the round-1 fix left out
        //    Quick stats' "s.isDeleted = 0" filter, so a session an admin
        //    had deleted in the Attendance app (soft-delete — the row and
        //    its headcount rows stay in the database) kept being counted
        //    here for good. Added, so this now really does mirror Quick
        //    stats rather than just resemble it.
        $stmt = $mysqli->prepare(
            'SELECT DATE_FORMAT(s.sessionDate, \'%Y-%m\') AS month, '
            . 'COUNT(DISTINCT s.sessionID) AS sessions, COALESCE(SUM(c.headcount), 0) AS attendance '
            . 'FROM tblAttendanceSessions s '
            . 'LEFT JOIN tblAttendanceCounts c ON c.sessionID = s.sessionID '
            . 'WHERE s.siteID = ? AND s.isDeleted = 0 '
            . 'AND s.sessionDate >= DATE_SUB(NOW(), INTERVAL 12 MONTH) '
            . 'GROUP BY month ORDER BY month'
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $siteId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
            $stmt->close();
        }
        echo json_encode(['status' => 'ok', 'data' => $data]);
        break;

    default:
        echo json_encode(['status' => 'error', 'error' => 'Unknown report type']);
        break;
}

exit();
