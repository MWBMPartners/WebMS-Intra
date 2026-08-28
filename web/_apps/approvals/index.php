<?php
// Path: public_html/approvals/index.php
/**
 * -----------------------------------------------------------------------------
 * Approvals — Generic Inbox 📥 (#443)
 * -----------------------------------------------------------------------------
 * Three sections, no <table> (portal-data-list throughout):
 *   1. "Awaiting your decision" — Workflow::actionableForUser(). Admins see
 *      every active instance for the site (with a Mine/All filter); everyone
 *      else only ever sees rows they're actually eligible to act on — the
 *      engine (Workflow::act()) re-checks authorisation independently, this
 *      page is display-only.
 *   2. Action controls per row — one CSRF'd POST form per row to
 *      /approvals/act. No authorisation logic here at all — the handler and
 *      the engine own every guard (#443 §4.3).
 *   3. History — last 50 completed/cancelled instances for the site;
 *      non-admins see only instances they started or acted on. Each row
 *      expands (Bootstrap collapse) to the full decision timeline.
 *
 * @package   Portal\Approvals
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/443
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\I18n;
use Portal\Core\Site;
use Portal\Core\Workflow;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$isAdmin = App::isAdmin();

// 🔍 Admin "Mine / All" filter — non-admins always see only their own
// actionable rows (the query itself already restricts to those).
$filter = ($isAdmin === true && ($_GET['filter'] ?? '') === 'mine') ? 'mine' : 'all';

$actionable = Workflow::actionableForUser($userId, $siteId, $isAdmin);
if ($isAdmin === true && $filter === 'mine') {
    $actionable = array_values(array_filter($actionable, static function (array $row) use ($userId): bool {
        $atype = (string) $row['assigneeType'];
        $aval = (string) ($row['assigneeValue'] ?? '');
        if ($atype === 'user' && $aval !== '') {
            return (int) $aval === $userId;
        }
        // Role/group "mine" filtering already happened for non-admins inside
        // actionableForUser(); for an admin, 'mine' narrows to direct user
        // assignments only — role/group rows stay in "All".
        return false;
    }));
}

$history = Workflow::historyForSite($siteId, $isAdmin === true ? null : $userId, $isAdmin);

$csrf = Auth::csrfToken();

$flashMsg = $_SESSION['flash_msg'] ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// 📌 Page metadata
$pageTitle = 'Approvals';
$pageSection = 'approvals';
$breadcrumbs = ['Dashboard' => '/', 'Approvals' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 📥 Approvals Inbox -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-stamp me-2"></i>Approvals</h1>
    <?php if ($isAdmin === true): ?>
        <div class="btn-group btn-group-sm" role="group">
            <a href="/approvals?filter=all" class="btn btn-outline-secondary<?php echo $filter === 'all' ? ' active' : ''; ?>">All</a>
            <a href="/approvals?filter=mine" class="btn btn-outline-secondary<?php echo $filter === 'mine' ? ' active' : ''; ?>">Mine</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<h2 class="h5 mb-3">Awaiting your decision</h2>
<?php if (count($actionable) === 0): ?>
    <div class="alert alert-info mb-4">
        <i class="fa-solid fa-circle-check me-2"></i>Nothing awaiting your approval.
    </div>
<?php else: ?>
    <div class="portal-data-list mb-4">
        <div class="portal-data-header">
            <div class="col-3">Subject</div>
            <div class="col-2">Workflow / Step</div>
            <div class="col-2">Started by</div>
            <div class="col-2">Waiting since</div>
            <div class="col-3">Decision</div>
        </div>
        <?php foreach ($actionable as $row): ?>
            <?php
            $instanceId = (int) $row['instanceID'];
            $stepId = (int) $row['stepID'];
            $viewUrl = Workflow::contextUrl($row);
            $waitingSince = $row['currentStepStartedAt'] ?? $row['startedAt'];
            $overdue = false;
            if ($row['timeoutHours'] !== null && $waitingSince !== null) {
                $dueTs = strtotime((string) $waitingSince) + ((int) $row['timeoutHours'] * 3600);
                $overdue = $dueTs < time();
            }
            ?>
            <div class="portal-data-row">
                <div class="col-3">
                    <?php echo htmlspecialchars((string) $row['subjectLabel'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ($viewUrl !== ''): ?>
                        <a href="<?php echo htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8'); ?>" class="small ms-1" title="View">
                            <i class="fa-solid fa-arrow-up-right-from-square"></i>
                        </a>
                    <?php endif; ?>
                </div>
                <div class="col-2">
                    <div><?php echo htmlspecialchars((string) $row['workflowName'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <span class="badge bg-secondary"><?php echo htmlspecialchars((string) $row['stepName'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="col-2 small"><?php echo htmlspecialchars((string) ($row['startedByName'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-2 small">
                    <?php echo $waitingSince !== null ? htmlspecialchars(I18n::formatDate((string) $waitingSince, 'short'), ENT_QUOTES, 'UTF-8') : '—'; ?>
                    <?php if ($overdue === true): ?>
                        <span class="badge bg-danger ms-1">Overdue</span>
                    <?php endif; ?>
                </div>
                <div class="col-3">
                    <form method="post" action="/approvals/act" class="d-flex flex-wrap gap-1 align-items-center">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="instanceID" value="<?php echo $instanceId; ?>">
                        <input type="hidden" name="stepID" value="<?php echo $stepId; ?>">
                        <input type="text" name="comment" class="form-control form-control-sm" style="max-width: 10rem;" placeholder="Comment (required to reject)">
                        <button type="submit" name="decision" value="approve" class="btn btn-sm btn-success" title="Approve">
                            <i class="fa-solid fa-check"></i>
                        </button>
                        <button type="submit" name="decision" value="reject" class="btn btn-sm btn-outline-danger" title="Reject" data-confirm="Reject this request?" data-confirm-destructive="true">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                        <button type="submit" name="decision" value="comment" class="btn btn-sm btn-outline-secondary" title="Comment only">
                            <i class="fa-solid fa-comment"></i>
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<h2 class="h5 mb-3">History</h2>
<?php if (count($history) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-2"></i>No completed approvals yet.
    </div>
<?php else: ?>
    <div class="portal-data-list">
        <div class="portal-data-header">
            <div class="col-4">Subject</div>
            <div class="col-2">Workflow</div>
            <div class="col-2">Outcome</div>
            <div class="col-2">Completed</div>
            <div class="col-2 text-end">Timeline</div>
        </div>
        <?php foreach ($history as $h): ?>
            <?php
            $hid = (int) $h['instanceID'];
            $outcome = (string) ($h['outcome'] ?? $h['status']);
            $outcomeColor = match ($outcome) {
                'approved'  => 'success',
                'rejected'  => 'danger',
                'cancelled' => 'secondary',
                default     => 'secondary',
            };
            ?>
            <div class="portal-data-row">
                <div class="col-4"><?php echo htmlspecialchars((string) $h['subjectLabel'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-2 small"><?php echo htmlspecialchars((string) $h['workflowName'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-2"><span class="badge bg-<?php echo $outcomeColor; ?>"><?php echo htmlspecialchars(ucfirst($outcome), ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div class="col-2 small">
                    <?php echo $h['completedAt'] !== null ? htmlspecialchars(I18n::formatDate((string) $h['completedAt'], 'short'), ENT_QUOTES, 'UTF-8') : '—'; ?>
                </div>
                <div class="col-2 text-end">
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#wfHist<?php echo $hid; ?>">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </button>
                </div>
            </div>
            <div class="collapse" id="wfHist<?php echo $hid; ?>">
                <div class="card card-body small mb-2">
                    <?php foreach (Workflow::actionsForInstance($hid, $siteId) as $act): ?>
                        <div class="border-bottom py-1">
                            <strong><?php echo htmlspecialchars((string) $act['stepName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            — <?php echo htmlspecialchars(ucfirst((string) $act['action']), ENT_QUOTES, 'UTF-8'); ?>
                            by <?php echo htmlspecialchars((string) ($act['actorName'] ?? 'System'), ENT_QUOTES, 'UTF-8'); ?>
                            (<?php echo htmlspecialchars(I18n::formatDate((string) $act['actedAt'], 'short'), ENT_QUOTES, 'UTF-8'); ?>)
                            <?php if (($act['comment'] ?? '') !== ''): ?>
                                <div class="text-muted">"<?php echo htmlspecialchars((string) $act['comment'], ENT_QUOTES, 'UTF-8'); ?>"</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
