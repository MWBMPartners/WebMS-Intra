<?php
// Path: public_html/admin/apps/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Apps Marketplace 📦
 * -----------------------------------------------------------------------------
 * Browse, enable, and disable installable apps. Apps are registered via
 * `web/_core/apps/{slug}.php` (see Portal\Core\AppRegistry).
 *
 * WHO MAY SWITCH APPS ON OR OFF, AND WHY IT IS NARROWER THAN WHO MAY LOOK
 * -----------------------------------------------------------------------------
 * The switch this page saves is portal-wide: the INSERT below puts NULL in the
 * siteID column. So switching an app off here switches it off for EVERY
 * organisation on the installation, not only the one the administrator is
 * working in.
 *
 * Until 13 September 2026 the only check was App::isAdmin(), which is true for
 * an administrator of a SINGLE organisation as well as for a global
 * administrator (see web/_core/App.php). So an administrator of one
 * organisation could switch off, for example, Giving or Prayer Requests for
 * every other organisation too. The owner decided that settings affecting
 * every organisation are for a global administrator only.
 *
 * Looking is still allowed. An administrator of one organisation sees the
 * list, with the reason written on the page and no Enable or Disable buttons.
 * Hiding the buttons is only a courtesy: the refusal in the handler below is
 * what actually stops the change, because a form can be sent without ever
 * opening this page.
 *
 * Probably the wrong way round, and deliberately left alone. The project notes
 * describe the app list as something each organisation switches for itself,
 * and the settings loader (web/_core/bootstrap.php) already lets an
 * organisation's own row override the portal-wide one. But this page has
 * always saved portal-wide, and saving per organisation instead would change
 * what every existing installation does, so it needs its own decision.
 * Reserving the portal-wide switch for a global administrator is safe either
 * way.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/255
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/495
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AppRegistry;
use Portal\Core\Auth;
use Portal\Core\Logger;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

// 🛡️ Looking is allowed for any administrator; switching apps is not. See the
//    note in the file header for why. Worked out once and used by both the
//    handler and the page below, so the two can never disagree.
$mayChangePortalWideSettings = App::isRootAdmin();

$flash = '';
$flashType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🔑 The form token is checked exactly ONCE per request, and only then is
    //    the person's permission looked at.
    //
    //    WHAT WAS WRONG: the first version of this handler asked
    //    Auth::verifyCsrf() in the refusal branch AND again in the save branch.
    //    A successful check replaces the token with a new one (see
    //    Auth::verifyCsrf() in web/_core/Auth.php — it does that so a captured
    //    form cannot be replayed). So for a global administrator the first
    //    check passed and used the token up, and the second check then compared
    //    the form against a token that no longer existed and failed. The save
    //    branch never ran, nothing was written and no message appeared: the
    //    button looked as if it worked and did nothing.
    //
    //    Asking once and keeping the answer in a variable cannot go wrong that
    //    way, however the branches below are rearranged later.
    $tokenIsValid = Auth::verifyCsrf($_POST['csrf_token'] ?? '');

    if ($tokenIsValid === false) {
        // 🚫 Missing, wrong or expired token (for example the page was left
        //    open for a long time, or the request came from another website).
        //    This used to be ignored in silence, which reads as "the button is
        //    broken". Nothing is written and nothing is logged, so a forged
        //    request from another website cannot fill the activity log.
        $flash = 'This form had expired or was not sent from this page, so nothing '
            . 'has been changed. Please try again.';
        $flashType = 'danger';
    } elseif ($mayChangePortalWideSettings === false) {
        // 🚧 Refused BEFORE anything is written: the switch is portal-wide, so
        //    it is for a global administrator only. The refusal is worded
        //    rather than a bare "forbidden", because an administrator who
        //    presses a button and is told nothing assumes the portal is broken
        //    and presses it again. It sits after the form-token check, so a
        //    forged request from another website cannot fill the activity log
        //    with refusals.
        $flash = 'Apps are switched on and off for the whole installation: a change '
            . 'here applies to every organisation, not only yours. Only a global '
            . 'administrator can change it. Nothing has been changed.';
        $flashType = 'danger';
        Logger::activity(
            'SettingsGroupSaveRefused',
            'Refused: portal-wide app on/off switch may only be changed by a global administrator',
            $_SESSION['user_id'] ?? null
        );
    } else {
        // 🛠️ Action handler — enable / disable. Only a global administrator
        //    with a valid token reaches this point.
        $action = (string) ($_POST['action'] ?? '');
        $slug   = (string) ($_POST['slug'] ?? '');
        $registry = AppRegistry::all();

        if ($slug === '' || isset($registry[$slug]) === false) {
            $flash = 'Unknown app.';
            $flashType = 'danger';
        } elseif (($registry[$slug]['isCore'] ?? false) === true) {
            $flash = 'Core apps cannot be disabled.';
            $flashType = 'danger';
        } else {
            $settingKey = (string) $registry[$slug]['settingKey'];
            $value = $action === 'enable' ? '1' : '0';
            try {
                $db = App::db();
                $stmt = $db->prepare(
                    "INSERT INTO `tblSettings` "
                    . "(`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) "
                    . "VALUES (NULL, ?, ?, '0', 0) "
                    . "ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`)"
                );
                if ($stmt !== false) {
                    $stmt->bind_param('ss', $settingKey, $value);
                    $stmt->execute();
                    $stmt->close();
                }
                AppRegistry::invalidate();
                $flash = sprintf(
                    '%s %s.',
                    htmlspecialchars((string) $registry[$slug]['name'], ENT_QUOTES, 'UTF-8'),
                    $action === 'enable' ? 'enabled' : 'disabled'
                );
                $flashType = 'success';
            } catch (\Throwable $e) {
                $flash = 'Failed: ' . $e->getMessage();
                $flashType = 'danger';
            }
        }
    }
}

// 🪞 Industry filter — admin can set portal.industry to hide irrelevant apps.
$orgIndustry = (string) (App::settings()['portal']['industry'] ?? '');
$apps        = AppRegistry::all();

if ($orgIndustry !== '') {
    $apps = array_filter(
        $apps,
        static function (array $meta) use ($orgIndustry): bool {
            $industries = (array) ($meta['industries'] ?? []);
            return count($industries) === 0 || in_array($orgIndustry, $industries, true);
        }
    );
}

// 🪞 Group by category
$grouped = [];
foreach ($apps as $slug => $meta) {
    $cat = (string) ($meta['category'] ?? 'other');
    $grouped[$cat][$slug] = $meta;
}
ksort($grouped);

$pageTitle   = 'Apps';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Apps' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-cubes me-2"></i>Apps</h1>
        <p class="text-secondary mb-0">Enable or disable installable apps. Core apps are always on.</p>
    </div>
    <a href="/admin" class="btn btn-outline-secondary btn-sm">&larr; Admin</a>
</div>

<?php if ($flash !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<?php if ($mayChangePortalWideSettings === false): ?>
    <!-- 👀 Read-only notice. Shown instead of the Enable / Disable buttons, so
         nobody presses one and only then finds out it was refused. -->
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-2"></i>
        Switching an app on or off here applies to <strong>every
        organisation</strong> on this installation, not only yours, so only a
        global administrator can do it. You can see which apps are switched on.
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h6 mb-2">Organisation profile</h2>
        <p class="small text-muted mb-2">Setting your organisation's industry filters the app list to relevant apps only. Setting key: <code>portal.industry</code>.</p>
        <p class="mb-0">
            <strong>Current:</strong>
            <code><?php echo $orgIndustry !== '' ? htmlspecialchars($orgIndustry, ENT_QUOTES, 'UTF-8') : '(unset — all apps shown)'; ?></code>
            &middot; <a href="/admin/settings">Change in /admin/settings</a>
        </p>
    </div>
</div>

<?php foreach ($grouped as $category => $apps): ?>
    <h2 class="h5 mt-4 mb-2 text-capitalize"><?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?></h2>
    <div class="row g-3 mb-2">
        <?php foreach ($apps as $slug => $meta):
            $enabled = AppRegistry::isEnabled($slug);
            $isCore  = (bool) ($meta['isCore'] ?? false);
        ?>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 <?php echo $enabled === true ? 'border-success' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-2">
                            <span style="color:<?php echo htmlspecialchars((string) $meta['color'], ENT_QUOTES, 'UTF-8'); ?>;font-size:1.25rem;margin-right:.5rem;">
                                <i class="<?php echo htmlspecialchars((string) $meta['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                            </span>
                            <strong><?php echo htmlspecialchars((string) $meta['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            <?php if ($isCore === true): ?>
                                <span class="badge bg-secondary ms-auto">Core</span>
                            <?php elseif ($enabled === true): ?>
                                <span class="badge bg-success ms-auto">Enabled</span>
                            <?php else: ?>
                                <span class="badge bg-light text-dark ms-auto">Disabled</span>
                            <?php endif; ?>
                        </div>
                        <p class="small text-muted mb-2"><?php echo htmlspecialchars((string) $meta['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php if (count((array) $meta['industries']) > 0): ?>
                            <p class="small mb-2">
                                <?php foreach ((array) $meta['industries'] as $ind): ?>
                                    <span class="badge bg-light text-muted me-1"><?php echo htmlspecialchars((string) $ind, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endforeach; ?>
                            </p>
                        <?php endif; ?>
                        <?php
                        // 🔒 No Enable / Disable buttons for somebody who may not
                        //    use them. A courtesy only: the refusal at the top of
                        //    this file is what actually stops the change.
                        if ($isCore === false && $mayChangePortalWideSettings === true): ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="slug" value="<?php echo htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php if ($enabled === true): ?>
                                    <input type="hidden" name="action" value="disable">
                                    <button type="submit" class="btn btn-outline-danger btn-sm"
                                            data-confirm="Disable <?php echo htmlspecialchars((string) $meta['name'], ENT_QUOTES, 'UTF-8'); ?>? Users will no longer be able to access this app." data-confirm-destructive="true">
                                        Disable
                                    </button>
                                <?php else: ?>
                                    <input type="hidden" name="action" value="enable">
                                    <button type="submit" class="btn btn-success btn-sm">Enable</button>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
