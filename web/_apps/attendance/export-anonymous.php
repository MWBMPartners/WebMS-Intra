<?php
// Path: _apps/attendance/export-anonymous.php
/**
 * -----------------------------------------------------------------------------
 * Attendance — spreadsheet of the anonymous check-in counts 📊 (#525)
 * -----------------------------------------------------------------------------
 * One row per event per day: how many times the button was pressed, how many
 * people those presses claimed, how many different internet connections they
 * probably came from, and how they arrived.
 *
 * ADMINISTRATORS ONLY, WHATEVER THE VISIBILITY SETTING SAYS
 * ---------------------------------------------------------
 * The organisation's setting decides who sees the figures on a SCREEN. It does
 * not govern this download, and that is deliberate, confirmed by the owner on
 * 18 September 2026.
 *
 * A file behaves differently from a screen. It leaves the building, it gets
 * forwarded, and it is still sitting in somebody's downloads folder long after
 * the setting has been tightened again. And unlike the screens, these rows carry
 * EVENT NAMES — including internal events, which can collect anonymous
 * check-ins from signed-in members of the organisation.
 *
 * So the gate here is the same one the existing attendance export uses, word for
 * word: signed in, an administrator, and a valid form token carried in the
 * address. Nothing reads the visibility setting on this page at all, and nobody
 * should later "fix" that omission.
 *
 * WHY A SEPARATE FILE AND NOT EXTRA COLUMNS ON THE EXISTING EXPORT
 * ---------------------------------------------------------------
 * Two reasons. The shared exporter takes its column headings from the first row
 * it is given, so one file cannot carry two different row shapes. And "these are
 * never merged into the named attendance record" should be literally true, in
 * the files as well as on the screens.
 *
 * DELETED EVENTS ARE LEFT OUT OF THIS FILE (owner decision, 20 September 2026)
 * -----------------------------------------------------------------------------
 * The reports page keeps a deleted event's headcount in the organisation's
 * running totals, but this file does not carry that event's row at all — a
 * deleted event's name must never be able to reappear in a spreadsheet that is
 * still sitting in somebody's downloads folder. So this file's column can add
 * up to LESS than the total shown on the reports page. That is the deleted
 * events' rows, left out on purpose, not a mistake in the file.
 *
 * @package   Portal\Attendance
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/525
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// 🔧 Bootstrap. Harmless when the router has already loaded it (require_once),
//    and it matches the neighbouring attendance/export.php exactly.
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\AnonymousCheckins;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\CsvExporter;
use Portal\Core\Site;

// 🔒 Signed in.
Auth::ensureSession();
Auth::requireLogin();

// 🔑 Administrator. The admin gate lives on App, not Auth.
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

// 🛡️ Form token carried in the address, exactly as the existing attendance
//    export does — this is a download, so it is a GET.
if (Auth::verifyCsrf($_GET['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /attendance');
    exit();
}

// 🌐 This organisation only. The class enforces that inside its own SQL as
//    well, so an event belonging to somebody else can never reach these rows.
$siteId = Site::id();
$year   = (int) ($_GET['year'] ?? (int) date('Y'));
$month  = (int) ($_GET['month'] ?? 0);
if ($year < 1970 || $year > 9999) {
    $year = (int) date('Y');
}
if ($month < 1 || $month > 12) {
    $month = 0;
}

// 🔌 $mysqli, never $db. A page loaded through the data interface has no $db at
//    all, and five export buttons in this portal were dead for exactly that
//    reason (#482).
$rows = AnonymousCheckins::rowsForCsv($mysqli, $siteId, $year, $month);

// The headings are passed explicitly rather than left to be worked out from
// the first row, so a period with no check-ins still downloads a headed file
// instead of an empty one that looks broken.
$filename = 'anonymous-checkins-' . date('Y-m-d') . '.csv';
CsvExporter::download($filename, $rows, AnonymousCheckins::CSV_HEADINGS);
