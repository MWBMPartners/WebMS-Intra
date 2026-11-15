<?php
// Path: _apps/admin/settings/member-limits/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Member limits: rota swap requests and the largest online gift 🚧
 * (#989, #992; the owner, 12 November 2026 — D-171, D-172; migration 298)
 * -----------------------------------------------------------------------------
 * Three numbers a member meets, each a LIMIT under the three-tier rule (#668):
 *
 *   - how many OPEN swap requests may wait for one duty (an open request is
 *     e-mailed to every member with the switch on, D-141);
 *   - how many swap requests one member may make in a day;
 *   - the largest single gift the online giving form takes.
 *
 * Each is set for this organisation, or — by a global administrator — for the
 * whole installation. The installation's figure is the MOST an organisation
 * may set, because whoever runs the installation may be a different party
 * from the organisation's administrators.
 *
 * WHAT IS ENFORCED WHERE. The page only draws. `save.php` refuses a figure
 * above what the installation allows, under a lock
 * (`SettingLimit::saveAtLevel()`); the readers (`RotaSwaps::limits()`,
 * `Giving::onlineGiftCeiling()`) take the smaller of the two levels, so a
 * figure written some other way is still held down.
 *
 * WHY EACH NUMBER HAS ITS OWN FORM. One Save for three numbers would save
 * some and refuse others, and "two of three were saved" is a sentence nobody
 * reads correctly. Each is saved, or refused, on its own.
 *
 * WHY THE ORGANISATION'S BOXES ARE FILLED FROM ITS OWN ROW ONLY. Filling them
 * from the merged settings shows the installation's number as though the
 * organisation had chosen it, and one press of Save makes it so (#674,
 * #677). An empty box means "follow the installation".
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/989
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Currency;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();
// Hiding the installation's Save is a courtesy; save.php is the control.
$mayChangeInstallationWide = App::isRootAdmin();

/** @var array<string, array{key:string, default:int, hardMax:int, money:bool, min:int, label:string, help:string, inForce:int}> $limits */
$limits = require __DIR__ . DIRECTORY_SEPARATOR . '_limits.php';

$orgCurrency     = Currency::ofSite();
$installCurrency = Currency::ofInstallation();

/** A stored figure as a box shows it: plain figures for money, '' for none. */
$boxValue = static function (?string $stored, bool $money): string {
    if ($stored === null || preg_match('/^\d{1,18}$/', trim($stored)) !== 1) {
        return '';
    }

    return $money === true ? Currency::hundredthsToDecimal((int) trim($stored)) : (string) (int) trim($stored);
};

/** A figure as a sentence shows it. */
$words = static function (int $value, bool $money, string $currency): string {
    return $money === true ? Currency::format($value, $currency) : (string) $value;
};

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
$save = htmlspecialchars(Site::url('admin/settings/member-limits/save'), ENT_QUOTES, 'UTF-8');

$pageTitle       = 'Member Limits';
$pageDescription = 'How many rota swap requests a member may make, and the largest gift the online giving form takes.';
$pageSection     = 'admin';
$breadcrumbs     = ['Dashboard' => Site::url(''), 'Member limits' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-gauge me-2"></i>Member limits</h1>

<p class="text-secondary">
    Each number below is a limit. Your organisation can set a <em>lower</em> number than this
    installation allows, but not a higher one<?php echo $mayChangeInstallationWide === true
        ? ': you can change what the installation allows in the second part of this page.'
        : '. Only whoever runs the whole installation can raise what it allows, and that may not be somebody in your organisation.'; ?>
    None of them can be switched off: the least a count may be is 1, and the least gift limit is 1.00.
</p>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info me-2"></i>
    The gift limit is in your organisation's own currency<?php echo $orgCurrency !== '' ? ' (' . htmlspecialchars($orgCurrency, ENT_QUOTES, 'UTF-8') . ')' : ''; ?>.
    The installation's figure is a plain number of each organisation's own currency and is not converted:
    this portal holds no exchange rates.
</div>

<h2 class="h4 mt-4">Your organisation</h2>
<?php foreach ($limits as $name => $limit): ?>
    <?php
    $own     = \Portal\Core\SettingLimit::storedAtLevel($mysqli, $limit['key'], $siteId);
    $allowed = \Portal\Core\SettingLimit::installationAllows($limit['key'], $limit['default'], $limit['hardMax']);
    ?>
    <form method="post" action="<?php echo $save; ?>" class="card mb-3" data-member-limit="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="card-body">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="scope" value="site">
            <input type="hidden" name="limit" value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>">
            <label class="form-label" for="site-<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($limit['label'], ENT_QUOTES, 'UTF-8'); ?></label>
            <input type="text" inputmode="<?php echo $limit['money'] === true ? 'decimal' : 'numeric'; ?>" class="form-control"
                   id="site-<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>" name="value"
                   value="<?php echo htmlspecialchars($boxValue($own, $limit['money']), ENT_QUOTES, 'UTF-8'); ?>"
                   placeholder="<?php echo htmlspecialchars('Following the installation: ' . $words($allowed, $limit['money'], $orgCurrency), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-text">
                <?php echo htmlspecialchars($limit['help'], ENT_QUOTES, 'UTF-8'); ?>
                In force now: <strong data-in-force="<?php echo (int) $limit['inForce']; ?>"><?php echo htmlspecialchars($words($limit['inForce'], $limit['money'], $orgCurrency), ENT_QUOTES, 'UTF-8'); ?></strong>.
                This installation allows at most <?php echo htmlspecialchars($words($allowed, $limit['money'], $orgCurrency), ENT_QUOTES, 'UTF-8'); ?>.
                Leave the box empty to follow the installation.
            </div>
            <button type="submit" class="btn btn-primary btn-sm mt-2"><i class="fa-solid fa-floppy-disk me-1"></i>Save for my organisation</button>
        </div>
    </form>
<?php endforeach; ?>

<h2 class="h4 mt-4">The whole installation</h2>
<p class="text-secondary small">
    Every organisation that has not chosen its own number follows these, and these are the MOST any
    organisation may set. Lowering one here lowers it for an organisation that had chosen a higher one,
    without changing what that organisation has stored.
</p>
<?php if ($mayChangeInstallationWide === false): ?>
    <div class="alert alert-secondary"><i class="fa-solid fa-lock me-2"></i>Only a global administrator can change these, so they are shown to read rather than to fill in.</div>
<?php endif; ?>
<?php foreach ($limits as $name => $limit): ?>
    <?php $inst = \Portal\Core\SettingLimit::storedAtLevel($mysqli, $limit['key'], null); ?>
    <form method="post" action="<?php echo $save; ?>" class="card mb-3">
        <div class="card-body">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="scope" value="global">
            <input type="hidden" name="limit" value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>">
            <label class="form-label" for="global-<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($limit['label'], ENT_QUOTES, 'UTF-8'); ?></label>
            <?php /* readonly, never disabled: a disabled control is not submitted. */ ?>
            <input type="text" inputmode="<?php echo $limit['money'] === true ? 'decimal' : 'numeric'; ?>" class="form-control"
                   id="global-<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>" name="value" required
                   <?php echo $mayChangeInstallationWide === false ? 'readonly' : ''; ?>
                   value="<?php echo htmlspecialchars($boxValue($inst, $limit['money']) !== '' ? $boxValue($inst, $limit['money']) : $boxValue((string) $limit['default'], $limit['money']), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-text">At most <?php echo htmlspecialchars($words($limit['hardMax'], $limit['money'], $installCurrency), ENT_QUOTES, 'UTF-8'); ?>, which is the most this portal allows.</div>
            <?php if ($mayChangeInstallationWide === true): ?>
                <button type="submit" class="btn btn-outline-primary btn-sm mt-2"><i class="fa-solid fa-earth-europe me-1"></i>Save for the whole installation</button>
            <?php endif; ?>
        </div>
    </form>
<?php endforeach; ?>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
