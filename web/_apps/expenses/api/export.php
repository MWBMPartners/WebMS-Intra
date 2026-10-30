<?php
/**
 * 📄 web/_apps/expenses/api/export.php
 *
 * Hands back every expense claim in the organisation as a CSV file, for an
 * administrator or a holder of the `approver` role.
 *
 * NOT AN API ENDPOINT, despite the folder (#675). This file is reached through
 * the ORDINARY page router: `tblRoutes` holds
 * `expenses/api/export` -> `expenses/api/export.php`, and `Router::dispatch()`
 * resolves a target under `PORTAL_APPS` first. At that address NEITHER the
 * `api.expenses.export.enabled` row NOR the Expenses app's own API-folder
 * switch applies — two fewer gates than the folder name implies. The
 * `api/expenses/export` address that ApiRouter WOULD serve answers 403,
 * because that row is deliberately not seeded: a bearer-token client could
 * not use this handler anyway, since it needs a session and reads its
 * anti-forgery token from the query string.
 *
 * Its own path comment used to say `web/public_html/...`, left behind by the
 * #159 move of app pages out of the web root, and it called itself an
 * "endpoint" — which is the exact misreading that made #675 report a working
 * download as a dead handler.
 *
 * @package   WebMS Intra
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present All Rights Reserved
 * @license   All Rights Reserved
 * @version   0.8.1
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/77
 */

declare(strict_types=1);

// 🔧 Bootstrap
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\CsvExporter;
use Portal\Core\ExpenseRules;

// 🔒 Authentication & authorisation
Auth::ensureSession();
Auth::requireLogin();

// 🔑 Only an administrator, or somebody holding the `approver` role, may
//    download the spreadsheet.
//
//    THE RULE ITSELF IS NOT HERE ANY MORE (#675). It is
//    ExpenseRules::mayExportClaims(), because the Export button on
//    `expenses/approve` has to ask the same question and the two must never
//    be able to disagree. They did: the button was drawn for everybody who
//    could open that page, and this gate then refused most of them with the
//    bare "Forbidden" below. Do not put a second role test in either file.
//
//    Kept from the earlier version because it is worth knowing: these gates
//    live on `App`, not `Auth`. `Auth::isAdmin()` and `Auth::isApprover()` do
//    not exist, and the original code called them, so it fatalled before any
//    export logic could run at all.
if (ExpenseRules::mayExportClaims() === false) {
    http_response_code(403);
    exit('Forbidden');
}

// 🛡️ Form-token check on a GET download — deliberately the non-spending one.
//    WHAT WAS WRONG HERE (#634): this used Auth::verifyCsrf(), which REPLACES
//    the token whenever it accepts one. The approvals page that holds this
//    export link also holds the form that approves or rejects a claim, built
//    from the same token. So downloading the list spent the token and the next
//    approval was refused; approving first killed the export link. Nothing
//    reported it as a token problem — the person simply saw a failure on a
//    money page. A download cannot take a replacement token from its own
//    answer, because the answer is the CSV file itself.
//    This export only READS, so single use buys nothing here. See the long
//    note on Auth::verifyCsrfWithoutSpending() for what that gives up.
if (Auth::verifyCsrfWithoutSpending($_GET['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    // ⚠️ WHAT WAS WRONG HERE (#612): this sent the person to 'expenses', which is not
    //    an address anyone answers (the pages are expenses/submit, /approve,
    //    /treasury and /view), so an expired form token ended in "page not found"
    //    instead of the message set just above. The export button lives on the
    //    approvals page (expenses/approve/index.php), which also shows
    //    `flash_msg`, so that is where the person goes back to.
    header('Location: ' . Router::url('expenses/approve'));
    exit();
}

// 🌐 Site context
$siteId = Site::id();

// 📊 Query expense claims
$sql = "SELECT
            EC.claimID,
            U.fullName       AS Claimant,
            D.deptName       AS Department,
            EC.claimTitle    AS Title,
            EC.claimDate     AS Date,
            EC.totalAmount   AS Amount,
            EC.status        AS Status
        FROM tblExpenseClaims EC
        JOIN tblUsers U ON U.userID = EC.userID
        LEFT JOIN tblDepts D ON D.deptID = EC.deptID
        WHERE EC.siteID = ?
        ORDER BY EC.claimDate DESC";

// 🔌 Use $mysqli rather than $db, because $mysqli works under BOTH routers.
//
//    This line used to say $db. That was NOT broken — a review corrected an
//    earlier claim here that it was, and the correction was right. Router's
//    method signature is `dispatch(mysqli $db)`, and the page is loaded from
//    inside that method, so a page loaded that way inherits $db along with
//    everything else local to it. Checked by experiment, not by reading.
//
//    The reason to prefer $mysqli is narrower and real. There are two routers.
//    Router::dispatch takes $db as a parameter AND imports $mysqli. But
//    ApiRouter::dispatch takes only a path, and imports $mysqli alone — so a
//    page loaded by that one has no $db at all.
//
//    So $db works today for pages reached through an ordinary address, and
//    stops working the moment a page is reached through the data interface
//    instead. $mysqli works in both places. Using it costs nothing and removes
//    a way for this page to break later for a reason nobody would connect to
//    this line.
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('i', $siteId);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

// 📥 Send CSV download.
//
//    THE DATE IS THE ORGANISATION'S OWN (#604). `date()` would use whatever
//    zone PHP is configured with, which is not necessarily the zone the
//    organisation keeps its diary in — so late on a Sunday evening in Sydney
//    the file would be named for Saturday. `Site::timezone()` is the ONE place
//    an organisation's zone is answered; never read tblSites.timezone here.
$today    = new DateTimeImmutable('now', new DateTimeZone(Site::timezone()));
$filename = 'expenses-export-' . $today->format('Y-m-d') . '.csv';

//    THE HEADINGS ARE PASSED IN, and that is deliberate (#675). Left to
//    itself, CsvExporter::download() takes them from `array_keys($rows[0])`
//    and writes NO HEADING ROW AT ALL when there are no rows. So an
//    administrator of an organisation with no claims yet downloaded a file of
//    three bytes — the byte-order mark and nothing else — while the API
//    description promised a heading row and a column order. Found by the
//    independent check on 30 October 2026.
//
//    Naming them here also makes the documented order load-bearing rather
//    than a side effect of the order the SELECT's aliases happen to come back
//    in, so a reordered query can no longer silently reorder somebody's
//    spreadsheet columns.
CsvExporter::download(
    $filename,
    $rows,
    ['claimID', 'Claimant', 'Department', 'Title', 'Date', 'Amount', 'Status']
);
