<?php
// Path: _apps/small-groups/mine.php
/**
 * -----------------------------------------------------------------------------
 * Small Groups — My Groups 👥
 * -----------------------------------------------------------------------------
 * The member-facing landing page: groups the current user is an active
 * member of, plus any outstanding join requests. Uses
 * `SmallGroups::groupsFor()` — the #150/#321 supported entry point — never
 * a hand-rolled join.
 *
 * @package   Portal\SmallGroups
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/150
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Settings;
use Portal\Core\Site;
use Portal\Core\SmallGroups;

Auth::ensureSession();
Auth::requireLogin();

if (SmallGroups::isEnabled() === false) {
    Router::renderError(404);
    return;
}

$siteId = Site::id();
$user   = App::user();
$userId = (int) ($user['userID'] ?? 0);

$myActive  = SmallGroups::groupsFor($siteId, $userId, 'active');
$myPending = SmallGroups::groupsFor($siteId, $userId, 'pending');

$directoryVisible = (string) Settings::get('small-groups.directory_visible', '1');
$directoryOn = $directoryVisible === '1' || $directoryVisible === 'true';

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$pageTitle   = 'My Groups';
$pageSection = 'small-groups';
$breadcrumbs = ['Dashboard' => '/', 'Small Groups' => '/small-groups', 'My Groups' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

$esc = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo $esc($flashType); ?> alert-dismissible fade show">
        <?php echo $esc($flashMsg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-2">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-user-group me-2"></i>My Groups</h1>
        <p class="text-secondary mb-0">Groups and classes you belong to.</p>
    </div>
    <a href="/small-groups" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-list me-1"></i>All Groups</a>
</div>

<?php if (count($myPending) > 0): ?>
    <h2 class="h5 mb-2">Pending requests</h2>
    <div class="portal-data-list mb-4">
        <?php foreach ($myPending as $g): ?>
            <div class="portal-data-row">
                <div class="col-8">
                    <a href="/small-groups/group?id=<?php echo (int) $g['groupID']; ?>" class="text-decoration-none">
                        <?php echo $esc($g['groupName']); ?>
                    </a>
                </div>
                <div class="col-4 text-end">
                    <span class="badge bg-warning text-dark">Awaiting approval</span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (count($myActive) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-1"></i>
        You are not a member of any group yet.
        <?php if ($directoryOn === true): ?>
            <a href="/small-groups" class="alert-link">Browse the directory</a> to find one to join.
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($myActive as $g): ?>
            <?php
            $meetsBits = [];
            if ($g['meetingDay'] !== null) {
                $meetsBits[] = $dayNames[(int) $g['meetingDay']] ?? '';
            }
            if ($g['meetingTime'] !== null && $g['meetingTime'] !== '') {
                $meetsBits[] = date('g:i A', strtotime((string) $g['meetingTime']));
            }
            ?>
            <div class="col-12 col-md-6 col-lg-4">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="card-title">
                            <a href="/small-groups/group?id=<?php echo (int) $g['groupID']; ?>" class="text-decoration-none">
                                <?php echo $esc($g['groupName']); ?>
                            </a>
                        </h5>
                        <p class="card-text small text-secondary mb-2">
                            <?php echo count($meetsBits) > 0 ? $esc(implode(' at ', $meetsBits)) : 'Meeting time varies'; ?>
                        </p>
                        <span class="badge bg-secondary mb-2"><?php echo $esc(ucwords(str_replace('-', ' ', (string) $g['memberRole']))); ?></span>
                        <?php if (($g['joinedAt'] ?? null) !== null): ?>
                            <p class="small text-muted mb-2">Member since <?php echo $esc(date('j M Y', strtotime((string) $g['joinedAt']))); ?></p>
                        <?php endif; ?>
                        <form method="post" action="/small-groups/join">
                            <input type="hidden" name="csrf_token" value="<?php echo $esc(Auth::csrfToken()); ?>">
                            <input type="hidden" name="action" value="leave">
                            <input type="hidden" name="groupID" value="<?php echo (int) $g['groupID']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Leave this group?">
                                <i class="fa-solid fa-arrow-right-from-bracket me-1"></i>Leave
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
