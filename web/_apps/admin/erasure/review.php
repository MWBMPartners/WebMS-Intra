<?php
// Path: _apps/admin/erasure/review.php
/**
 * Admin — the free text an erasure cannot decide about on its own (#724).
 *
 * With no `?id=`, it lists the organisation's open erasure requests and how
 * many lines of free text each one is waiting on. With `?id=N`, it shows those
 * lines for request N: the table, the column, THE TEXT ITSELF, and the
 * catalogue's own words for why no rule decides it. Each gets two buttons:
 * empty it, or keep it.
 *
 * -----------------------------------------------------------------------------
 * THE GATE IS A CAPABILITY, AND IT DOES NOT FALL OPEN FOR A GLOBAL ADMINISTRATOR
 * -----------------------------------------------------------------------------
 * It asks `App::can(Capabilities::ERASURE_REVIEW)` — the capability registry
 * (#727) — and NOT `App::hasRole()`, which every ordinary role gate here uses.
 * The reason (the owner, 3 November 2026): `App::hasRole()` answers TRUE for
 * any global administrator who holds no role row at all — deliberate since
 * #516, where a global administrator is treated as holding every role in every
 * organisation. Gating this page that way would have let a hosting provider's
 * administrator read a member's own words to decide whether they must go,
 * which is the exact thing the role was created to prevent: an installation's
 * administrator and an organisation's may be DIFFERENT PARTIES (the standing
 * rule of 29 October 2026).
 *
 * ⚠️ DO NOT "FIX" THIS BACK to `App::hasRole()` for consistency with the other
 *    role gates. A global administrator who has not been granted the role is
 *    refused here on purpose. They are not locked out of putting that right —
 *    granting the role is a global administrator's job on the roles page — only
 *    out of doing the review themselves.
 *
 * ⚠️ WHAT MAKES THAT TRUE IS ONE LINE IN `Capabilities::CAPABILITIES`, not
 *    anything in this file: the `erasure.review` entry declares
 *    `globalAdminFallsOpen => false`. `App::can()` passes the account's global
 *    administrator flag to `Capabilities::allows()` as a FACT, and that method
 *    honours it only where the capability says so. Changing that one word to
 *    `true` would silently undo #724 with nothing on this page looking
 *    different — which is why `tools/audit-checks/check_capability_registry.py`
 *    is a hard gate on it and section C of `tools/capabilities-selftest.php`
 *    proves the refusal by asking with the flag set.
 *
 * ⚠️ IT USED TO ASK `Roles::has($db, $meId, $siteId, 'data_controller')`
 *    directly, which was right and is now one layer lower down: the registry's
 *    standard set for this capability is exactly `['data_controller']`, so an
 *    organisation that never opens the roles page is gated exactly as before.
 *    What the registry adds is that such an organisation can ALSO name a role
 *    of its own — a privacy officer, a company secretary — without anybody
 *    editing PHP.
 *
 * ADDRESSES: every link and form target is built by `Site::url()`, so it stays
 * inside the organisation's own address (#536). Never a bare "/admin/...", and
 * never a `.php` address — `.htaccess` answers 404 for those.
 *
 * NO INLINE SCRIPT AND NO ELEMENT HANDLER (#625): the two buttons are ordinary
 * form submits, and the warning before an "empty it" is `data-confirm`, read by
 * `assets/js/portal-actions.js`. So there is nothing here that needs
 * `App::cspNonce()`.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/724
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Capabilities;
use Portal\Core\ErasureReview;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$siteId = Site::id();

// 🛡️ See the file header. The capability registry, and never `App::hasRole()`.
// `$meId` used to be read here for the old gate's own look-up and is gone with
// it: `App::can()` reads the signed-in account itself, and this page writes
// nothing, so nothing else here needs the number.
if (App::can(Capabilities::ERASURE_REVIEW) === false) {
    Router::renderError(403);
    return;
}

$requestId = (int) ($_GET['id'] ?? 0);

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

// 🗂️ The table a decision is kept in arrives with migration 242. Asked before
//    anything else is drawn, because "no lines to decide about" and "this
//    database has not had its upgrade" must never look the same (#694).
$upgradeNeeded = ErasureReview::decisionsTableExists($db) === false;
$listProblem   = ErasureReview::whyListUnusable();

$queue = [];
$items = [];
$done  = [];
$found = ['items' => [], 'problems' => [], 'counts' => ['total' => 0, 'shown' => 0, 'undecided' => 0, 'capped' => false]];
$row   = null;

if ($requestId > 0) {
    // One request, and it must be THIS organisation's — the same test
    // `process.php` makes. A request belonging to another organisation on the
    // same installation is not this person's to read.
    //
    // ⚠️ THE `AND siteID = ?` IS A GATE, NOT A TIDY-UP, and nothing guarded it
    //    until 3 November 2026: an independent check removed it from both this
    //    page and `review-save.php` and all 52 audit scripts and all 91
    //    self-test checks stayed green. `$siteId` comes from `Site::id()` and
    //    never from the request, so a posted or typed organisation cannot
    //    reach it. Section I of `tools/erasure-review-selftest.php` now pins
    //    this statement and that bind.
    $stmt = $db->prepare(
        'SELECT `requestID`, `userID`, `subjectName`, `subjectEmail`, `status` '
        . 'FROM `tblErasureRequest` WHERE `requestID` = ? AND `siteID` = ? LIMIT 1'
    );
    if ($stmt !== false) {
        $stmt->bind_param('ii', $requestId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if ($row !== null && (int) ($row['userID'] ?? 0) > 0) {
        $found = ErasureReview::itemsFor($db, $requestId, (int) $row['userID']);
        $items = $found['items'];
        $done  = ErasureReview::decisionsRecorded($db, $requestId);
    }
} else {
    // The queue. Only requests that are still open: a completed one has been
    // closed, which (see GdprEraser::closeRequest()) cannot have happened
    // while a line was undecided, and its account number is gone so nothing
    // could be matched anyway.
    $stmt = $db->prepare(
        'SELECT `requestID`, `userID`, `subjectName`, `subjectEmail`, `status` '
        . 'FROM `tblErasureRequest` WHERE `siteID` = ? AND `userID` IS NOT NULL '
        . "AND `status` IN ('pending_confirmation', 'pending_review', 'processing') "
        // 📏 TWENTY-FIVE, not a hundred, and the reason is shared hosting.
        //    Counting the lines for ONE request asks the database about each of
        //    the seven columns, so the queue costs roughly fifteen cheap
        //    queries per row. A hundred open requests would be fifteen hundred
        //    in one page, on a host that gives the whole portal a few seconds.
        //    Open requests are rare and have a one-month deadline, so
        //    twenty-five is generous; a customer who really has more works
        //    through them.
        . 'ORDER BY `requestID` LIMIT 25'
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) {
            $queue[] = $r;
        }
        $stmt->close();
    }
    // 🔢 COUNTS, NOT ITEMS. The queue used to call `itemsFor()` for each of
    //    the twenty-five, which builds every item's WHOLE TEXT in memory —
    //    up to a page-full per column per request. It needs two numbers, so
    //    it asks for two numbers; nothing on this screen shows any text.
    foreach ($queue as $n => $r) {
        $theirs = ErasureReview::countUndecided($db, (int) $r['requestID'], (int) $r['userID']);
        $queue[$n]['undecided'] = array_sum($theirs['undecided']);
        $queue[$n]['lines']     = (int) $theirs['total'];
        $queue[$n]['problems']  = $theirs['problems'];
    }
}

$pageTitle   = 'Erasure review';
$pageSection = 'admin';
$breadcrumbs = [
    'Dashboard'        => Site::url(''),
    'Admin'            => Site::url('admin'),
    'Erasure requests' => Site::url('admin/erasure-requests'),
    'Review'           => '',
];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-scale-balanced me-2"></i>Erasure review</h1>
<p class="text-secondary">
    Some lines of free text cannot be decided by a rule, because whether they matter depends on the request in
    front of you: &ldquo;Jane&rsquo;s laptop&rdquo; on a licence seat is somebody&rsquo;s name, and &ldquo;spare
    projector&rdquo; on the next row is not. A request cannot be completed until each such line has been decided
    about here.
</p>
<?php
// 🏛️ How far this review reaches across organisations, and what that costs
//    somebody who belongs to more than one — the owner's decision of
//    3 November 2026. The wording comes from the class so that the page, the
//    class and the refusal written on the request's report cannot drift.
?>
<p class="small text-secondary">
    <i class="fa-solid fa-building-shield me-1"></i><?php echo htmlspecialchars(ErasureReview::organisationScopeNote(), ENT_QUOTES, 'UTF-8'); ?>
</p>

<?php if ($upgradeNeeded === true): ?>
    <div class="alert alert-warning">
        This portal&rsquo;s database needs its upgrade before any decision can be recorded (Admin, Upgrade). Until
        then nothing on this page can be saved, and an erasure run writes a line on its report saying the free
        text was not checked.
    </div>
<?php endif; ?>

<?php if ($listProblem !== ''): ?>
    <div class="alert alert-danger">
        The list of what needs reviewing cannot be trusted, so an erasure will refuse to complete until this is
        put right: <?php echo htmlspecialchars($listProblem, ENT_QUOTES, 'UTF-8'); ?>.
    </div>
<?php endif; ?>

<?php if ($requestId === 0): ?>

    <?php if (count($queue) === 0): ?>
        <div class="alert alert-info">No erasure request of this organisation is open.</div>
    <?php else: ?>
        <div class="card"><div class="card-body">
            <div class="portal-data-list">
                <?php foreach ($queue as $r): ?>
                    <div class="row py-2 border-bottom align-items-center small">
                        <div class="col-md-5">
                            <strong><?php echo htmlspecialchars((string) ($r['subjectName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                            <div class="text-muted"><?php echo htmlspecialchars((string) $r['subjectEmail'], ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="col-md-2">
                            <span class="badge bg-secondary"><?php echo htmlspecialchars(str_replace('_', ' ', (string) $r['status']), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="col-md-3">
                            <?php if ((int) $r['undecided'] > 0): ?>
                                <span class="text-warning"><?php echo (int) $r['undecided']; ?> line(s) to decide</span>
                            <?php elseif ((int) $r['lines'] > 0): ?>
                                <span class="text-success">all <?php echo (int) $r['lines']; ?> decided</span>
                            <?php else: ?>
                                <span class="text-muted">nothing to decide</span>
                            <?php endif; ?>
                            <?php if ($r['problems'] !== []): ?>
                                <div class="text-danger">could not look at everything</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-2 text-end">
                            <a class="btn btn-outline-primary btn-sm" href="<?php echo htmlspecialchars(Site::url(ErasureReview::PAGE_ROUTE), ENT_QUOTES, 'UTF-8'); ?>?id=<?php echo (int) $r['requestID']; ?>">Open</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div></div>
    <?php endif; ?>

<?php elseif ($row === null): ?>

    <div class="alert alert-danger">That erasure request is not one of this organisation&rsquo;s.</div>
    <a class="btn btn-outline-secondary" href="<?php echo htmlspecialchars(Site::url(ErasureReview::PAGE_ROUTE), ENT_QUOTES, 'UTF-8'); ?>">Back to the review list</a>

<?php elseif ((int) ($row['userID'] ?? 0) === 0): ?>

    <div class="alert alert-info">
        This request no longer names an account, so there is nothing to look up. A request&rsquo;s account number
        is cleared when it is closed.
    </div>
    <a class="btn btn-outline-secondary" href="<?php echo htmlspecialchars(Site::url(ErasureReview::PAGE_ROUTE), ENT_QUOTES, 'UTF-8'); ?>">Back to the review list</a>

<?php else: ?>

    <p>
        Request #<?php echo (int) $requestId; ?> &mdash;
        <strong><?php echo htmlspecialchars((string) ($row['subjectName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
        <span class="text-muted"><?php echo htmlspecialchars((string) $row['subjectEmail'], ENT_QUOTES, 'UTF-8'); ?></span>
    </p>

    <?php if ($found['problems'] !== []): ?>
        <div class="alert alert-danger">
            Some of it could not be looked at, so this request will not complete until that is cleared (running it
            again is usually enough): <?php echo htmlspecialchars(implode('; ', $found['problems']), ENT_QUOTES, 'UTF-8'); ?>.
        </div>
    <?php endif; ?>

    <?php
    // 🔢 HOW MANY THERE ARE, AND HOW MANY ARE ON SCREEN. This sentence is the
    //    other half of the fix of 3 November 2026 (see ErasureReview's
    //    docblock): the list shows at most a page-full per column, and a page
    //    that showed 500 of 501 lines WITHOUT SAYING SO is how a request came
    //    to close over a line nobody had seen. The numbers come from counts
    //    taken in the database with no limit on them, never from this list.
    $counts = (array) ($found['counts'] ?? []);
    $cTotal = (int) ($counts['total'] ?? 0);
    $cShown = (int) ($counts['shown'] ?? 0);
    $cLeft  = (int) ($counts['undecided'] ?? 0);
    ?>
    <?php if ($cTotal > 0): ?>
        <p class="small">
            <strong><?php echo $cTotal; ?></strong> line(s) of free text are held for this person,
            <strong><?php echo $cLeft; ?></strong> of them still to decide.
            <?php if ((bool) ($counts['capped'] ?? false) === true): ?>
                <span class="text-warning">
                    <?php
                    // 📏 THE NUMBERS ARE SAID EXACTLY, and two loose wordings
                    //    were caught by reading this back. It does NOT say
                    //    "the next 500": the cap is per table and column, so
                    //    one more page is not a fixed number of lines. And the
                    //    "cannot be completed" sentence only appears while
                    //    something really is undecided — with every line
                    //    decided and more than a page of them, it would
                    //    otherwise have read "while any of the 0 is
                    //    undecided".
                    ?>
                    This page is showing <?php echo $cShown; ?> of them, undecided lines first, because it shows at
                    most <?php echo (int) ErasureReview::SHOW_AT_MOST; ?> lines per table and column.
                    <?php if ($cLeft > 0): ?>
                        Decide the ones below and reload the page to see more. The request cannot be completed while
                        any of the <?php echo $cLeft; ?> undecided line(s) is left, whether or not it is on screen.
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if (count($items) === 0): ?>
        <div class="alert alert-success">
            Nothing here needs a decision for this person.
        </div>
    <?php else: ?>
        <div class="card mb-3"><div class="card-body">
            <?php foreach ($items as $item): ?>
                <?php
                $decided = (string) $item['decision'];
                $where   = (string) $item['table'] . '.' . (string) $item['column'];
                ?>
                <div class="border-bottom py-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <code><?php echo htmlspecialchars($where, ENT_QUOTES, 'UTF-8'); ?></code>
                            <span class="text-muted small">
                                row <?php echo (int) $item['rowKey']; ?>
                                (<?php echo htmlspecialchars((string) $item['keyColumn'], ENT_QUOTES, 'UTF-8'); ?>)
                            </span>
                        </div>
                        <div>
                            <?php if ($decided === ErasureReview::DECISION_KEEP): ?>
                                <span class="badge bg-success">kept</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">to decide</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="my-2 p-2 bg-body-secondary rounded">
                        <?php
                        // The text itself, whole. Judging it is the entire point
                        // of the page, so it is never shortened — a truncated
                        // line is exactly how somebody would miss a name at the
                        // end of it.
                        echo nl2br(htmlspecialchars((string) $item['text'], ENT_QUOTES, 'UTF-8'));
                        ?>
                    </div>

                    <p class="small text-secondary mb-2">
                        <strong>Why no rule decides this:</strong>
                        <?php echo htmlspecialchars((string) $item['reason'], ENT_QUOTES, 'UTF-8'); ?>
                    </p>

                    <p class="small mb-2">
                        <strong>How this row is linked to the person:</strong>
                        <code><?php echo htmlspecialchars(implode(', ', array_map('strval', (array) $item['matchedBy'])), ENT_QUOTES, 'UTF-8'); ?></code>
                        <?php if ((bool) $item['actedOnly'] === true): ?>
                            <span class="text-danger">
                                &mdash; every one of those only says this person ACTED on the row. The text may
                                well be somebody else&rsquo;s, so emptying it would take away a third
                                party&rsquo;s words. Read it before deciding.
                            </span>
                        <?php endif; ?>
                    </p>

                    <?php
                    // 🏛️ WHICH ORGANISATION THIS ROW IS IN, said per line and
                    //    not only in general. Four of the reviewable tables
                    //    record an organisation and are narrowed to this
                    //    request's; two record none, so such a row may belong
                    //    to another organisation altogether and whoever is
                    //    deciding has to know that before they empty it. Do
                    //    not replace this with a filter: there is no column to
                    //    filter on, and inventing one would be a guess.
                    ?>
                    <?php if ((bool) ($item['oneOrgOnly'] ?? false) === false): ?>
                        <p class="small text-warning mb-2">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i>
                            <strong>Not tied to one organisation.</strong>
                            This table does not record which organisation a row belongs to, so this line could be
                            from any organisation this person belongs to &mdash; not necessarily this one. It is
                            shown because the erasure will reach it either way.
                        </p>
                    <?php endif; ?>

                    <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/erasure-requests/review-save'), ENT_QUOTES, 'UTF-8'); ?>" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="requestID" value="<?php echo (int) $requestId; ?>">
                        <input type="hidden" name="tableName" value="<?php echo htmlspecialchars((string) $item['table'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="columnName" value="<?php echo htmlspecialchars((string) $item['column'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="rowKey" value="<?php echo (int) $item['rowKey']; ?>">
                        <input type="hidden" name="decision" value="<?php echo htmlspecialchars(ErasureReview::DECISION_EMPTY, ENT_QUOTES, 'UTF-8'); ?>">
                        <button class="btn btn-danger btn-sm" type="submit" data-confirm="Empty this line? The text is deleted from the row straight away and cannot be brought back. The report will record that you emptied it, by account number and time &mdash; it will NOT record the text.">
                            <i class="fa-solid fa-eraser me-1"></i>Empty it
                        </button>
                    </form>
                    <?php if ($decided !== ErasureReview::DECISION_KEEP): ?>
                        <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/erasure-requests/review-save'), ENT_QUOTES, 'UTF-8'); ?>" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="requestID" value="<?php echo (int) $requestId; ?>">
                            <input type="hidden" name="tableName" value="<?php echo htmlspecialchars((string) $item['table'], ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="columnName" value="<?php echo htmlspecialchars((string) $item['column'], ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="rowKey" value="<?php echo (int) $item['rowKey']; ?>">
                            <input type="hidden" name="decision" value="<?php echo htmlspecialchars(ErasureReview::DECISION_KEEP, ENT_QUOTES, 'UTF-8'); ?>">
                            <button class="btn btn-outline-secondary btn-sm" type="submit" data-confirm="Keep this line? Nothing is changed in the row. The report will record that you looked at it and decided to keep it, which is what lets the request be completed.">
                                <i class="fa-solid fa-check me-1"></i>Keep it
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if ($decided === ErasureReview::DECISION_KEEP): ?>
                        <span class="small text-muted ms-2">
                            Kept
                            <?php if ((int) $item['decidedBy'] > 0): ?>
                                by account #<?php echo (int) $item['decidedBy']; ?>
                            <?php endif; ?>
                            <?php if ((string) $item['decidedAt'] !== ''): ?>
                                at <?php echo htmlspecialchars((string) $item['decidedAt'], ENT_QUOTES, 'UTF-8'); ?> UTC
                            <?php endif; ?>
                            &mdash; it can still be emptied.
                        </span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div></div>
    <?php endif; ?>

    <?php
    // 🧾 Lines already DEALT WITH. Needed as well as the list above, and the
    //    reason is worth knowing: an emptied line is no longer an item at all,
    //    because there is nothing left in the cell to decide about. Without
    //    this nothing on the page would show that a press had worked.
    ?>
    <?php if (count($done) > 0): ?>
        <h2 class="h5">Decisions recorded for this request</h2>
        <div class="card"><div class="card-body">
            <div class="portal-data-list">
                <?php foreach ($done as $d): ?>
                    <div class="row py-1 border-bottom small">
                        <div class="col-md-6">
                            <code><?php echo htmlspecialchars((string) $d['table'] . '.' . (string) $d['column'], ENT_QUOTES, 'UTF-8'); ?></code>
                            <span class="text-muted">row <?php echo (int) $d['rowKey']; ?></span>
                        </div>
                        <div class="col-md-2">
                            <?php if ((string) $d['decision'] === ErasureReview::DECISION_EMPTY): ?>
                                <span class="badge bg-danger">emptied</span>
                            <?php else: ?>
                                <span class="badge bg-success">kept</span>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4 text-muted">
                            <?php if ((int) $d['decidedBy'] > 0): ?>
                                account #<?php echo (int) $d['decidedBy']; ?>
                            <?php else: ?>
                                account not named
                            <?php endif; ?>
                            <?php if ((string) $d['decidedAt'] !== ''): ?>
                                &mdash; <?php echo htmlspecialchars((string) $d['decidedAt'], ENT_QUOTES, 'UTF-8'); ?> UTC
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div></div>
    <?php endif; ?>

    <p class="mt-3">
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(Site::url(ErasureReview::PAGE_ROUTE), ENT_QUOTES, 'UTF-8'); ?>">Back to the review list</a>
    </p>

<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
