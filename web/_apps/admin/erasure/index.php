<?php
// Path: public_html/admin/erasure/index.php
/**
 * Admin — GDPR erasure-request queue with SLA monitoring.
 *
 * ADDRESSES: every redirect, link and form target here is built by
 * Router::url(), so it stays inside the organisation's own address. They
 * used to be written out ("/account/my-data"), which lands on the DEFAULT
 * organisation when organisations are told apart by the first part of the
 * address (roll-out package B2b, 12 October 2026; part of #536).
 * tools/audit-checks/check_site_aware_addresses.py holds this file to it.
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/235
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\GdprEraser;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$db     = App::db();
$siteId = Site::id();

$rows = [];
$stmt = $db->prepare(
    'SELECT requestID, userID, subjectEmail, subjectName, subjectKind, status, requestedAt, dueBy, processedAt, '
    . '       TIMESTAMPDIFF(DAY, NOW(), dueBy) AS daysLeft '
    . 'FROM tblErasureRequest WHERE siteID = ? ORDER BY '
    . '       (status IN ("completed","cancelled")) ASC, dueBy ASC LIMIT 200'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Erasure requests';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => Router::url(''), 'Admin' => Router::url('admin'), 'Erasure requests' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-shield-halved me-2"></i>Erasure requests</h1>
<p class="text-secondary">UK GDPR Article 17 requests. Process within one month of confirmation.</p>

<?php
// 🧾 #964, D-140; #1009, D-176. An event suggested WITHOUT signing in, and a
//    prayer request sent through the anonymous form (signed in or not, #988),
//    carry no account, so an ordinary request cannot find them - even when the
//    person who sent one HAS an account, as a member who used the anonymous
//    form does. Nothing here asks whether the address belongs to an account,
//    so nothing here says it does not. They ask the organisation some other way, and
//    an administrator files it here by their e-mail address. The tick-box is
//    the identity check the portal can record: it cannot check who is asking
//    itself, so it makes the administrator say they have and, since D-148,
//    note HOW, and the request's report records both and who made them. The
//    note is kept for ever, so the page says not to put the person's details
//    in it (see GdprEraser::fileVisitorAddressRequest()).
?>
<div class="card mb-3"><div class="card-body">
    <h2 class="h6">An e-mail address: event suggestions and anonymous-form prayer requests sent with it</h2>
    <p class="small text-secondary mb-2">
        A person who suggested an event without signing in gave a name and an e-mail address, and was e-mailed if it
        was approved. A person who sent an anonymous prayer request may have given a name and an e-mail address too,
        and the request keeps the network address it was sent from. If they ask for those to be erased, file the
        request here by the address they gave. It reaches only, at this organisation: the name and address given with
        event suggestions; the anonymous prayer requests sent with that address, whose name, address, network address
        and words are emptied; the old activity records of such a suggestion that quote the address, written before the
        portal stopped putting addresses there, whose address and browser details are emptied; and the e-mails telling
        that address a suggestion was approved. The events and the
        prayer requests themselves are kept. An anonymous prayer request sent without an e-mail address cannot be
        found this way. It does not touch any account, even a member&rsquo;s using the same address. A member who sent
        a request through the anonymous form while signed in is in the same position as a visitor: that request is not
        linked to their account, so their own erasure does not reach it, and it is erased only by a request filed here.
        Nothing is erased until you press Execute on the request.
    </p>
    <form method="post" action="<?php echo htmlspecialchars(Router::url('admin/erasure-requests/process'), ENT_QUOTES, 'UTF-8'); ?>" class="row g-2 align-items-end">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="file_visitor">
        <div class="col-md-5">
            <label for="visitorEmail" class="form-label small">Their e-mail address</label>
            <input type="email" id="visitorEmail" name="visitorEmail" required maxlength="255" class="form-control form-control-sm" autocomplete="off">
        </div>
        <div class="col-md-7">
            <label for="howChecked" class="form-label small">How you checked it is them</label>
            <input type="text" id="howChecked" name="howChecked" required maxlength="<?php echo (int) GdprEraser::VISITOR_NOTE_MAX; ?>" class="form-control form-control-sm" autocomplete="off" aria-describedby="howCheckedHelp">
            <div id="howCheckedHelp" class="form-text">
                For example &ldquo;replied from that address&rdquo;. At most <?php echo (int) GdprEraser::VISITOR_NOTE_MAX; ?>
                characters. <strong>This note is kept with the request for ever and is never erased, so do not put the
                person&rsquo;s name, address, telephone number or any other details in it.</strong>
            </div>
        </div>
        <div class="col-md-10">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="checkedOffPortal" name="checkedOffPortal" value="1" required>
                <label class="form-check-label small" for="checkedOffPortal">
                    I have checked, outside the portal, that this request really comes from the person who uses this
                    address. The request&rsquo;s report will record that I said so.
                </label>
            </div>
        </div>
        <div class="col-md-2">
            <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-file-circle-plus me-1"></i>File request</button>
        </div>
    </form>
</div></div>

<?php if (count($rows) === 0): ?>
    <div class="alert alert-info">No erasure requests on file.</div>
<?php else: ?>
    <div class="card"><div class="card-body">
        <div class="portal-data-list">
            <?php foreach ($rows as $r):
                $cls = match ((string) $r['status']) {
                    'completed'           => 'success',
                    'pending_confirmation'=> 'secondary',
                    'pending_review'      => 'warning',
                    'processing'          => 'info',
                    'failed'              => 'danger',
                    default               => 'secondary',
                };
                $days = (int) ($r['daysLeft'] ?? 0);
                $slaCls = $days < 0 ? 'text-danger' : ($days < 7 ? 'text-warning' : 'text-muted');
                // 🧾 #964: a request for a visitor's address. It has no free
                //    text to review and nothing that can be left for doing by
                //    hand, so neither of those two buttons is drawn for it -
                //    decided by the request's KIND, not by working out whether
                //    a review is outstanding.
                $visitorRow = (string) ($r['subjectKind'] ?? '') === GdprEraser::KIND_VISITOR_ADDRESS;
            ?>
                <div class="row py-2 border-bottom align-items-center small">
                    <div class="col-md-4">
                        <?php if ($visitorRow === true): ?>
                            <strong>E-mail address: event suggestions and anonymous-form prayer requests</strong>
                        <?php else: ?>
                            <strong><?php echo htmlspecialchars((string) ($r['subjectName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                        <?php endif; ?>
                        <div class="text-muted"><?php echo htmlspecialchars((string) $r['subjectEmail'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="col-md-2"><span class="badge bg-<?php echo $cls; ?>"><?php echo htmlspecialchars(str_replace('_', ' ', (string) $r['status']), ENT_QUOTES, 'UTF-8'); ?></span></div>
                    <div class="col-md-3 <?php echo $slaCls; ?>">
                        <?php if ((string) $r['status'] === 'completed'): ?>
                            done <?php echo htmlspecialchars((string) ($r['processedAt'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        <?php else: ?>
                            due <?php echo htmlspecialchars((string) $r['dueBy'], ENT_QUOTES, 'UTF-8'); ?>
                            <?php if ($days < 0): ?>(<?php echo abs($days); ?>d OVERDUE)<?php else: ?>(<?php echo $days; ?>d left)<?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3 text-end">
                        <?php
                        // 🛡️ The way in to the free-text review (#724). Shown on
                        //    every row rather than only where something is
                        //    waiting, and that is on purpose: THIS PAGE MUST NOT
                        //    WORK OUT WHETHER A REVIEW IS OUTSTANDING.
                        //
                        //    Asking would mean a second copy of
                        //    `ErasureReview::whyNotReadyToClose()`'s judgement
                        //    here, and this repository has paid for that shape
                        //    more than once — a page's copy of a rule drifting
                        //    from the rule. The review page itself says what is
                        //    waiting, and says plainly when nothing is.
                        //
                        //    It is a LINK and not a gated button because the
                        //    gate belongs on the page that acts: whoever opens
                        //    this queue may not hold the Data Controller role,
                        //    and finding that out by being refused on the review
                        //    page is honest. Drawing or hiding the link from a
                        //    role test here would be a second gate to keep in
                        //    step with the real one.
                        ?>
                        <?php if ($visitorRow === false): ?>
                        <a class="btn btn-outline-secondary btn-sm"
                           href="<?php echo htmlspecialchars(Router::url('admin/erasure-requests/review') . '?id=' . (int) $r['requestID'], ENT_QUOTES, 'UTF-8'); ?>">
                            <i class="fa-solid fa-user-shield me-1"></i>Review free text
                        </a>
                        <?php endif; ?>
                        <?php if ((string) $r['status'] === 'pending_review'): ?>
                            <form method="post" action="<?php echo htmlspecialchars(Router::url('admin/erasure-requests/process'), ENT_QUOTES, 'UTF-8'); ?>" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="requestID" value="<?php echo (int) $r['requestID']; ?>">
                                <input type="hidden" name="action" value="execute">
                                <button class="btn btn-danger btn-sm" type="submit" data-confirm="Run erasure now? This deletes/anonymises user data per the policy.">
                                    <i class="fa-solid fa-play me-1"></i>Execute
                                </button>
                            </form>
                            <form method="post" action="<?php echo htmlspecialchars(Router::url('admin/erasure-requests/process'), ENT_QUOTES, 'UTF-8'); ?>" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="requestID" value="<?php echo (int) $r['requestID']; ?>">
                                <input type="hidden" name="action" value="cancel">
                                <button class="btn btn-outline-secondary btn-sm" type="submit" data-confirm="Cancel this request?">Cancel</button>
                            </form>
                            <?php
                            // 🖐️ "Dealt with by hand" (#710). Offered on every
                            //    row of this status and the SERVER decides
                            //    whether to CLOSE it. The page works nothing
                            //    out, so it still opens on a database that has
                            //    not had its upgrade yet.
                            //
                            //    ⚠️ BE EXACT ABOUT WHAT THE SERVER PROTECTS,
                            //       because a first version of this comment
                            //       drew the wrong analogy. "Put back to
                            //       waiting" below REFUSES to act when a run
                            //       showed life. This never refuses to RUN: it
                            //       runs the erasure exactly as Execute does,
                            //       and only the CLOSE is conditional. On a
                            //       request that has never been run, that is
                            //       the whole erasure — which is why the
                            //       confirm text says so first, and says it as
                            //       plainly as Execute's own does.
                            //
                            //    It cannot become a way to close a request
                            //    nobody has acted on, because closing needs a
                            //    run in which EVERY failure is one no further
                            //    run can clear. Anything else is refused with
                            //    the numbers, and nothing is recorded as done
                            //    by hand.
                            //
                            //    ⚠️ "EITHER WAY YOUR ACCOUNT NUMBER GOES INTO
                            //       THE REPORT" WAS UNTRUE and an independent
                            //       check found it. On the refused path — the
                            //       likeliest one, and the reason this button
                            //       exists — the only writes are the claim's
                            //       `status`/`claimedAt` and the put-back's
                            //       `status`; `report.php` never selects
                            //       `processedByID`. The ONLY record is the
                            //       activity log, and that IS erasable (#626).
                            //       It was untrue in the self-erasure case too,
                            //       where the number is deliberately left out.
                            //       The sentence now promises the record only
                            //       where it is really written.
                            ?>
                            <?php if ($visitorRow === false): ?>
                            <form method="post" action="<?php echo htmlspecialchars(Router::url('admin/erasure-requests/process'), ENT_QUOTES, 'UTF-8'); ?>" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="requestID" value="<?php echo (int) $r['requestID']; ?>">
                                <input type="hidden" name="action" value="finish_by_hand">
                                <button class="btn btn-outline-secondary btn-sm" type="submit" data-confirm="THIS RUNS THE ERASURE. It deletes and anonymises this person&#39;s data exactly as Execute does — and on a request that has never been run, that is the whole erasure. It then closes the request ONLY IF every failure in that run is one no further run can fix: the kind whose reason says the rows must be CHECKED BY HAND. If anything else failed, nothing is closed and nothing is recorded as done by hand. When it DOES close the request, the report records that you dealt with those rows yourself, by account number and time &mdash; and that report is never erased. Unless the request is your own, in which case you are not named at all.">
                                    <i class="fa-solid fa-hand me-1"></i>Dealt with by hand
                                </button>
                            </form>
                            <?php endif; ?>
                        <?php elseif ((string) $r['status'] === 'pending_confirmation'): ?>
                            <?php
                            // ✅ Waiting for the person to click the link that
                            //    was emailed to them. If that email could not
                            //    be sent, they are told to ask an
                            //    administrator, and this is what the
                            //    administrator presses. The prompt makes the
                            //    administrator say they have checked the
                            //    request is the person's, because that is
                            //    what the email step was for.
                            ?>
                            <form method="post" action="<?php echo htmlspecialchars(Router::url('admin/erasure-requests/process'), ENT_QUOTES, 'UTF-8'); ?>" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="requestID" value="<?php echo (int) $r['requestID']; ?>">
                                <input type="hidden" name="action" value="confirm">
                                <button class="btn btn-outline-primary btn-sm" type="submit" data-confirm="Confirm this request on the person's behalf? Only do this if you have checked, yourself, that the request really comes from them. The request's report will record that you confirmed it. Nothing is erased by this.">
                                    <i class="fa-solid fa-user-check me-1"></i>Confirm on the person's behalf
                                </button>
                            </form>
                        <?php elseif ((string) $r['status'] === 'processing'): ?>
                            <?php
                            // ↩️ A request stays at "processing" only while a
                            //    run is going, which takes seconds. One still
                            //    here when this page is opened has usually
                            //    been left behind by a run the web host
                            //    stopped. The button is offered on every such
                            //    row and the server decides: it refuses, with
                            //    a plain message, if a run showed signs of
                            //    life in the last quarter of an hour. (The
                            //    page does not work that out itself, so that
                            //    it still opens on a database that has not
                            //    had its upgrade yet.)
                            ?>
                            <form method="post" action="<?php echo htmlspecialchars(Router::url('admin/erasure-requests/process'), ENT_QUOTES, 'UTF-8'); ?>" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="requestID" value="<?php echo (int) $r['requestID']; ?>">
                                <input type="hidden" name="action" value="putback">
                                <button class="btn btn-outline-secondary btn-sm" type="submit" data-confirm="Put this request back to waiting? Do this when its run stopped before it finished. Every step is safe to repeat, so nothing is lost. If a run is still going, the portal will refuse.">
                                    <i class="fa-solid fa-rotate-left me-1"></i>Put back to waiting
                                </button>
                            </form>
                        <?php endif; ?>
                        <a class="btn btn-outline-primary btn-sm" href="<?php echo htmlspecialchars(Router::url('admin/erasure-requests/report'), ENT_QUOTES, 'UTF-8'); ?>?id=<?php echo (int) $r['requestID']; ?>">Report</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div></div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
