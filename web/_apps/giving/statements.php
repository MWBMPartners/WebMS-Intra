<?php
// Path: _apps/giving/statements.php
/**
 * -----------------------------------------------------------------------------
 * Giving — Bulk year-end statements 🧾 (gap #4, #440)
 * -----------------------------------------------------------------------------
 * Treasurer-only "Annual statements" page: pick a period (calendar year by
 * default, with free from/to fields and a one-click UK tax year preset),
 * preview per-donor totals + Gift Aid eligible sums, then generate PDFs,
 * download them all as a ZIP, or email each donor their own statement.
 *
 * All the actual work happens in the POST/GET handlers this page links to
 * (statements-generate.php / statements-email.php / statements-download.php)
 * — this file is read-only preview + forms, mirroring the Newsletter
 * recipients.php pattern (preview pre-send, status post-send).
 *
 * @package   Portal\Giving
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/440
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Giving;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (Giving::canManage() === false) {
    Router::renderError(403);
    return;
}

$siteId   = Site::id();
$settings = App::settings()['giving'] ?? [];
$currency = (string) ($settings['currency'] ?? 'GBP');

// 📅 Q1 default — last FULL calendar year. A free from/to pair (plus the
// client-side UK-tax-year preset button below) covers everything else;
// there is no server-side tax-year logic anywhere in this codebase to
// reuse, so the preset is deliberately just a form-filler (see the plan).
$defaultYear = (int) date('Y') - 1;
$from = (string) ($_GET['from'] ?? ($defaultYear . '-01-01'));
$to   = (string) ($_GET['to']   ?? ($defaultYear . '-12-31'));
if (strtotime($from) === false || strtotime($to) === false || strtotime($from) > strtotime($to)) {
    $from = $defaultYear . '-01-01';
    $to   = $defaultYear . '-12-31';
}
$periodKey   = Giving::statementPeriodKey($from, $to);
$periodLabel = Giving::periodLabel($from, $to);

$rows     = Giving::statementsPreview($siteId, $from, $to);
$excluded = Giving::statementsExcludedSummary($siteId, $from, $to);

$generatedCount = 0;
$emailedCount   = 0;
$erroredCount   = 0;
$optedOutCount  = 0;
$departedCount  = 0;
foreach ($rows as $r) {
    if ($r['pdfPath'] !== null) {
        $generatedCount++;
    }
    if ($r['emailedAt'] !== null) {
        $emailedCount++;
    }
    if ($r['errorMsg'] !== null) {
        $erroredCount++;
    }
    if ($r['optedOut'] === true) {
        $optedOutCount++;
    }
    if ($r['usActive'] === false) {
        $departedCount++;
    }
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf  = Auth::csrfToken();
$nonce = htmlspecialchars(App::cspNonce(), ENT_QUOTES, 'UTF-8');

$pageTitle   = 'Annual Statements';
$pageSection = 'giving';
$breadcrumbs = ['Dashboard' => '/', 'Giving' => '/giving', 'Manage' => '/giving/manage', 'Statements' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-file-invoice me-2"></i>Annual Statements</h1>
    <a href="/giving/manage" class="btn btn-outline-secondary btn-sm">← Back to Manage</a>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header"><strong>Period</strong></div>
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small">From</label>
                <input type="date" id="stmt-from" name="from" value="<?php echo htmlspecialchars($from, ENT_QUOTES, 'UTF-8'); ?>" class="form-control form-control-sm">
            </div>
            <div class="col-md-3">
                <label class="form-label small">To</label>
                <input type="date" id="stmt-to" name="to" value="<?php echo htmlspecialchars($to, ENT_QUOTES, 'UTF-8'); ?>" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-primary btn-sm w-100" type="submit">Apply</button>
            </div>
            <div class="col-md-4">
                <button type="button" class="btn btn-outline-secondary btn-sm w-100" onclick="applyUkTaxYearPreset()">
                    <i class="fa-regular fa-calendar me-1"></i>UK tax year (6 Apr – 5 Apr)
                </button>
            </div>
        </form>
        <p class="small text-muted mt-2 mb-0">
            Showing <strong><?php echo htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
            (<?php echo htmlspecialchars($from, ENT_QUOTES, 'UTF-8'); ?> to <?php echo htmlspecialchars($to, ENT_QUOTES, 'UTF-8'); ?>).
        </p>
    </div>
</div>

<div class="row g-2 mb-3">
    <div class="col-6 col-md-2"><div class="alert alert-light border mb-0 text-center"><div class="fs-5 fw-bold"><?php echo count($rows); ?></div><div class="small text-muted">Donors</div></div></div>
    <div class="col-6 col-md-2"><div class="alert alert-light border mb-0 text-center"><div class="fs-5 fw-bold"><?php echo $generatedCount; ?></div><div class="small text-muted">Generated</div></div></div>
    <div class="col-6 col-md-2"><div class="alert alert-light border mb-0 text-center"><div class="fs-5 fw-bold"><?php echo $emailedCount; ?></div><div class="small text-muted">Emailed</div></div></div>
    <div class="col-6 col-md-2"><div class="alert alert-light border mb-0 text-center"><div class="fs-5 fw-bold"><?php echo $optedOutCount; ?></div><div class="small text-muted">Opted out</div></div></div>
    <div class="col-6 col-md-2"><div class="alert alert-light border mb-0 text-center"><div class="fs-5 fw-bold"><?php echo $departedCount; ?></div><div class="small text-muted">Left the site</div></div></div>
    <div class="col-6 col-md-2"><div class="alert alert-light border mb-0 text-center"><div class="fs-5 fw-bold"><?php echo $erroredCount; ?></div><div class="small text-muted">Errors</div></div></div>
</div>

<div class="card mb-3">
    <div class="card-header"><strong>Actions</strong></div>
    <div class="card-body d-flex flex-wrap gap-2">
        <form method="post" action="/giving/statements-generate" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="from" value="<?php echo htmlspecialchars($from, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="to" value="<?php echo htmlspecialchars($to, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="stmt-regen" name="regenerate" value="1">
                <label class="form-check-label small" for="stmt-regen">Regenerate PDFs</label>
            </div>
            <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-gears me-1"></i>Generate statements</button>
        </form>

        <a class="btn btn-outline-secondary btn-sm"
           href="/giving/statements-download?from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>">
            <i class="fa-solid fa-file-zipper me-1"></i>Download all as ZIP
        </a>

        <form method="post" action="/giving/statements-email" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="from" value="<?php echo htmlspecialchars($from, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="to" value="<?php echo htmlspecialchars($to, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="stmt-resend" name="resend" value="1">
                <label class="form-check-label small" for="stmt-resend">Resend to already-emailed donors</label>
            </div>
            <button class="btn btn-success btn-sm" type="submit" data-confirm="Email statements to eligible donors for this period?">
                <i class="fa-solid fa-paper-plane me-1"></i>Email statements
            </button>
        </form>
    </div>
    <div class="card-footer small text-muted">
        Statements batch in groups of <?php echo (int) ($settings['statements']['batchPerRun'] ?? 25); ?> per click — re-click "Generate"/"Email" to continue a larger run, or configure the giving cron to finish unattended (see DEV_NOTES.md).
    </div>
</div>

<?php if (count($rows) === 0): ?>
    <div class="alert alert-info">No donors with recorded giving in this period.</div>
<?php else: ?>
    <div class="card"><div class="card-body">
        <div class="portal-data-list">
            <?php foreach ($rows as $r): ?>
                <div class="row py-2 border-bottom small align-items-center">
                    <div class="col-md-3">
                        <strong><?php echo htmlspecialchars($r['fullName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        <?php if ($r['usActive'] === false): ?>
                            <span class="badge bg-secondary ms-1" title="No longer an active member of this site">left site</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-2 text-muted">
                        <?php if ($r['emailAddress'] !== ''): ?>
                            <?php echo htmlspecialchars($r['emailAddress'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark">no email</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-1 text-end"><?php echo htmlspecialchars(Giving::formatAmount($r['totalPence'], $currency), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-md-2 text-end text-muted">Gift Aid: <?php echo htmlspecialchars(Giving::formatAmount($r['giftAidPence'], $currency), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-md-2">
                        <?php if ($r['optedOut'] === true): ?><span class="badge bg-secondary">opted out</span><?php endif; ?>
                        <?php if ($r['errorMsg'] !== null): ?>
                            <span class="badge bg-danger" title="<?php echo htmlspecialchars($r['errorMsg'], ENT_QUOTES, 'UTF-8'); ?>">error</span>
                        <?php elseif ($r['emailedAt'] !== null): ?>
                            <span class="badge bg-success">emailed</span>
                        <?php elseif ($r['pdfPath'] !== null): ?>
                            <span class="badge bg-info text-dark">generated</span>
                        <?php else: ?>
                            <span class="badge bg-light text-dark border">pending</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-2 text-end">
                        <?php if ($r['pdfPath'] !== null): ?>
                            <a class="btn btn-link btn-sm p-0 me-2"
                               href="/giving/statements-download?from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>&donorID=<?php echo (int) $r['donorID']; ?>">
                                <i class="fa-solid fa-download"></i> PDF
                            </a>
                        <?php endif; ?>
                        <?php if ($r['errorMsg'] !== null): ?>
                            <form method="post" action="/giving/statements-generate" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="retry">
                                <input type="hidden" name="from" value="<?php echo htmlspecialchars($from, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="to" value="<?php echo htmlspecialchars($to, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="donorID" value="<?php echo (int) $r['donorID']; ?>">
                                <button type="submit" class="btn btn-link btn-sm p-0 text-danger">Retry</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div></div>
<?php endif; ?>

<?php if ($excluded['count'] > 0): ?>
    <p class="small text-muted mt-3">
        <?php echo (int) $excluded['count']; ?> gift(s) totalling
        <?php echo htmlspecialchars(Giving::formatAmount($excluded['totalPence'], $currency), ENT_QUOTES, 'UTF-8'); ?>
        were recorded without a named member donor (anonymous or free-text) and are not included above —
        statements can only be produced for a member donor.
    </p>
<?php endif; ?>

<script nonce="<?php echo $nonce; ?>">
// 🗓️ UK tax year preset — client-side only (fills the from/to fields with
// the most recently STARTED 6 Apr – 5 Apr window, then submits). There is
// no server-side tax-year logic anywhere in this codebase (#440 §1.5) —
// donor statements are informational; the HMRC claim itself goes through
// the existing CSV export, which already takes any custom range.
function applyUkTaxYearPreset() {
    var now = new Date();
    var y = now.getFullYear();
    // Tax year 6 Apr (y-1) – 5 Apr y if we're before 6 Apr this year,
    // otherwise 6 Apr y – 5 Apr (y+1).
    var startYear = (now.getMonth() > 3 || (now.getMonth() === 3 && now.getDate() >= 6)) ? y : (y - 1);
    document.getElementById('stmt-from').value = startYear + '-04-06';
    document.getElementById('stmt-to').value = (startYear + 1) + '-04-05';
}
</script>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
