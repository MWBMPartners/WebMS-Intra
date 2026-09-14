<?php
// Path: public_html/admin/captcha/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Captcha Provider Configuration 🤖
 * -----------------------------------------------------------------------------
 * Dedicated admin page for the multi-provider captcha system. Supports:
 *   • Drag-and-drop priority ordering (SortableJS via CDN with fallback)
 *   • Per-provider site / secret key inputs
 *   • reCAPTCHA v2 vs v3 toggle (with action + score-threshold for v3)
 *   • Live "configured / not configured" status badges
 *   • Highlight of which provider is currently active given the priority + keys
 *
 * WHO MAY CHANGE THESE, AND WHY IT IS NARROWER THAN WHO MAY LOOK
 * -----------------------------------------------------------------------------
 * Every setting on this page is saved portal-wide (nothing in the siteID
 * column), so it is the value EVERY organisation on the installation uses.
 *
 * This comment used to say that site administrators were "also allowed",
 * because the settings are shared. That was exactly the problem:
 * App::isAdmin() is true for an administrator of a SINGLE organisation, so any
 * one of them could remove the "are you a person?" check from every
 * organisation's sign-in page, or swap in keys of their own. The owner decided
 * on 13 September 2026 that settings affecting every organisation are for a
 * global administrator only.
 *
 * An administrator of one organisation can still open this page and see which
 * providers are set up, which helps when they are trying to understand
 * behaviour they cannot change. They see it read-only: the reason on the page,
 * every field disabled, no Save buttons, no drag-to-reorder, and the SECRET
 * keys left out of the page entirely (a disabled field still carries its value
 * in the page source). The public site keys are still shown, because they are
 * published in every page that shows a captcha anyway.
 *
 * Hiding the controls is a courtesy, not the control. The save handler,
 * web/_apps/admin/captcha/save.php, refuses the change on the server whatever
 * this page shows, because a form can be sent without ever opening the page.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.11.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/495
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\Router;

// 📌 Page metadata
$pageTitle   = 'Captcha Providers';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Captcha' => ''];

// 🛡️ Admin access check
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// 🛡️ Looking is allowed for any administrator; changing is not (see the file
//    header). Worked out once and used by every control below, so no part of
//    the page can disagree with another about whether this person may save.
$mayChangePortalWideSettings = App::isRootAdmin();

// 🔒 Added to every field when the person looking may not save. A disabled
//    field is only a hint to the browser; save.php is what enforces the rule.
$readOnlyAttr = $mayChangePortalWideSettings === false ? ' disabled' : '';

// 💬 Flash message from save handler
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// -----------------------------------------------------------------------------
// 📋 Pull current config snapshot
// -----------------------------------------------------------------------------
$providers       = Captcha::listProviders();   // ordered per priority
$activeProvider  = Captcha::activeProvider();  // '' if nothing configured

$turnstileSite   = (string) (App::settings('auth.turnstile.siteKey')   ?? '');
$turnstileSecret = (string) (App::settings('auth.turnstile.secretKey') ?? '');

$recaptchaSite   = (string) (App::settings('auth.recaptcha.siteKey')   ?? '');
$recaptchaSecret = (string) (App::settings('auth.recaptcha.secretKey') ?? '');
$recaptchaVer    = (string) (App::settings('auth.recaptcha.version')   ?? 'v2');
$recaptchaV3Act  = (string) (App::settings('auth.recaptcha.v3.action')    ?? 'submit');
$recaptchaV3Thr  = (string) (App::settings('auth.recaptcha.v3.threshold') ?? '0.5');

$hcaptchaSite    = (string) (App::settings('auth.hcaptcha.siteKey')   ?? '');
$hcaptchaSecret  = (string) (App::settings('auth.hcaptcha.secretKey') ?? '');

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

$csrf = Auth::csrfToken();

/**
 * 🎨 Render a "Configured / Not configured" badge for the priority list rows.
 */
$statusBadge = static function (bool $configured): string {
    if ($configured === true) {
        return '<span class="badge bg-success"><i class="fa-solid fa-check me-1"></i>Configured</span>';
    }
    return '<span class="badge bg-secondary"><i class="fa-solid fa-circle-minus me-1"></i>Not configured</span>';
};

/**
 * 🎨 Render the human-readable label + icon for a provider key.
 */
$providerIcon = static function (string $key): string {
    return match ($key) {
        'turnstile' => '<i class="fa-solid fa-shield-halved text-warning me-2"></i>',
        'recaptcha' => '<i class="fa-brands fa-google text-primary me-2"></i>',
        'hcaptcha'  => '<i class="fa-solid fa-h me-2"></i>',
        default     => '<i class="fa-solid fa-robot me-2"></i>',
    };
};
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-robot me-2"></i>Captcha Providers</h1>
        <p class="text-secondary mb-0">
            <?php
            // Same reason as the "Drag to re-order" sentence below: only
            // somebody who may save is told to configure keys and drag rows.
            if ($mayChangePortalWideSettings === true): ?>
                Configure provider keys and drag to set the fallback priority.
            <?php else: ?>
                Which providers are set up, and the order they are tried in.
            <?php endif; ?>
        </p>
    </div>
    <a href="/admin" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back to Admin
    </a>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars((string) $flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars((string) $flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($mayChangePortalWideSettings === false): ?>
    <!-- 👀 Read-only notice. Shown instead of letting somebody fill the form in
         and only find out it was refused after they pressed Save. -->
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-2"></i>
        These settings apply to <strong>every organisation</strong> on this
        installation, not only yours, so only a global administrator can change
        them. You can see which providers are set up here. Secret keys are not
        shown.
    </div>
<?php endif; ?>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info me-1"></i>
    <strong>How it works:</strong>
    The active provider is the first item below that has both a site key and a secret key.
    <?php
    // 🔒 "Drag to re-order" is only true for somebody who may save the order.
    //    It used to be shown to everybody. An administrator of one organisation
    //    sees this page read-only: the drag script is not loaded for them and
    //    there is no Save Priority button, so the sentence told them to do
    //    something the page would not let them do.
    if ($mayChangePortalWideSettings === true): ?>
        Drag to re-order.
    <?php endif; ?>
    If nothing is configured, forms that use captcha will simply skip it.
    <?php if ($activeProvider !== ''): ?>
        Current active provider:
        <strong><?php echo htmlspecialchars(ucfirst($activeProvider), ENT_QUOTES, 'UTF-8'); ?></strong>.
    <?php else: ?>
        <strong>No provider is currently active.</strong>
    <?php endif; ?>
</div>

<!-- ============================================================================ -->
<!-- 🪜 Priority list (drag-and-drop) -->
<!-- ============================================================================ -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-body-tertiary">
        <h2 class="h6 mb-0"><i class="fa-solid fa-arrows-up-down me-2"></i>Provider Priority</h2>
    </div>
    <div class="card-body">
        <form method="post" action="/admin/captcha/save" id="priorityForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action"     value="priority">
            <input type="hidden" name="priority"   id="priorityField"
                   value="<?php echo htmlspecialchars(implode(',', array_column($providers, 'key')), ENT_QUOTES, 'UTF-8'); ?>">

            <ul id="captchaPriorityList" class="list-group mb-3">
                <?php foreach ($providers as $p): ?>
                    <li class="list-group-item d-flex align-items-center justify-content-between"
                        data-key="<?php echo htmlspecialchars($p['key'], ENT_QUOTES, 'UTF-8'); ?>"
                        <?php echo $mayChangePortalWideSettings === true ? 'style="cursor: grab;"' : ''; ?>>
                        <div>
                            <i class="fa-solid fa-grip-vertical text-muted me-3" aria-hidden="true"></i>
                            <?php echo $providerIcon($p['key']); ?>
                            <strong><?php echo htmlspecialchars($p['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            <?php if ($activeProvider === $p['key']): ?>
                                <span class="badge bg-primary ms-2">Active</span>
                            <?php endif; ?>
                        </div>
                        <?php echo $statusBadge((bool) $p['configured']); ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($mayChangePortalWideSettings === true): ?>
                <!-- 🔒 Save Priority and the drag tip are only for somebody who may
                     save. save.php refuses everybody else whatever the page shows. -->
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-save me-1"></i> Save Priority
                </button>
                <span class="small text-muted ms-2">
                    Tip: drag rows to reorder. Active provider is decided top-to-bottom.
                </span>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- ============================================================================ -->
<!-- 🔑 Provider key inputs -->
<!-- ============================================================================ -->
<form method="post" action="/admin/captcha/save">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="action"     value="keys">

    <!-- ☁️ Cloudflare Turnstile -->
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-body-tertiary d-flex align-items-center">
            <h3 class="h6 mb-0 flex-grow-1">
                <i class="fa-solid fa-shield-halved text-warning me-2"></i>Cloudflare Turnstile
            </h3>
            <?php echo $statusBadge($turnstileSite !== '' && $turnstileSecret !== ''); ?>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="turnstile_site" class="form-label">Site Key</label>
                    <input type="text" class="form-control" id="turnstile_site" name="turnstile_site"
                           value="<?php echo htmlspecialchars($turnstileSite, ENT_QUOTES, 'UTF-8'); ?>"
                           autocomplete="off"<?php echo $readOnlyAttr; ?>>
                </div>
                <div class="col-md-6">
                    <label for="turnstile_secret" class="form-label">Secret Key</label>
                    <!-- 🔑 The secret is only written into the page for somebody who
                         may change it. A disabled field still carries its value in
                         the page source, so disabling alone would not hide it. -->
                    <input type="password" class="form-control" id="turnstile_secret" name="turnstile_secret"
                           value="<?php echo htmlspecialchars($mayChangePortalWideSettings === true ? $turnstileSecret : '', ENT_QUOTES, 'UTF-8'); ?>"
                           autocomplete="off"<?php echo $readOnlyAttr; ?>>
                </div>
            </div>
            <p class="form-text mb-0">
                Get keys at
                <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener noreferrer">
                    Cloudflare Turnstile dashboard <i class="fa-solid fa-arrow-up-right-from-square fa-xs"></i>
                </a>.
            </p>
        </div>
    </div>

    <!-- 🅖 Google reCAPTCHA -->
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-body-tertiary d-flex align-items-center">
            <h3 class="h6 mb-0 flex-grow-1">
                <i class="fa-brands fa-google text-primary me-2"></i>Google reCAPTCHA
            </h3>
            <?php echo $statusBadge($recaptchaSite !== '' && $recaptchaSecret !== ''); ?>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="recaptcha_site" class="form-label">Site Key</label>
                    <input type="text" class="form-control" id="recaptcha_site" name="recaptcha_site"
                           value="<?php echo htmlspecialchars($recaptchaSite, ENT_QUOTES, 'UTF-8'); ?>"
                           autocomplete="off"<?php echo $readOnlyAttr; ?>>
                </div>
                <div class="col-md-6">
                    <label for="recaptcha_secret" class="form-label">Secret Key</label>
                    <input type="password" class="form-control" id="recaptcha_secret" name="recaptcha_secret"
                           value="<?php echo htmlspecialchars($mayChangePortalWideSettings === true ? $recaptchaSecret : '', ENT_QUOTES, 'UTF-8'); ?>"
                           autocomplete="off"<?php echo $readOnlyAttr; ?>>
                </div>
                <div class="col-md-4">
                    <label for="recaptcha_version" class="form-label">Version</label>
                    <select class="form-select" id="recaptcha_version" name="recaptcha_version"<?php echo $readOnlyAttr; ?>>
                        <option value="v2" <?php echo $recaptchaVer === 'v2' ? 'selected' : ''; ?>>
                            v2 (visible checkbox)
                        </option>
                        <option value="v3" <?php echo $recaptchaVer === 'v3' ? 'selected' : ''; ?>>
                            v3 (invisible / score)
                        </option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="recaptcha_v3_action" class="form-label">v3 Action Name</label>
                    <input type="text" class="form-control" id="recaptcha_v3_action" name="recaptcha_v3_action"
                           value="<?php echo htmlspecialchars($recaptchaV3Act, ENT_QUOTES, 'UTF-8'); ?>"
                           autocomplete="off"<?php echo $readOnlyAttr; ?>>
                    <div class="form-text">Default: <code>submit</code>. v3 only.</div>
                </div>
                <div class="col-md-4">
                    <label for="recaptcha_v3_threshold" class="form-label">v3 Score Threshold</label>
                    <input type="number" class="form-control" id="recaptcha_v3_threshold" name="recaptcha_v3_threshold"
                           min="0" max="1" step="0.05"
                           value="<?php echo htmlspecialchars($recaptchaV3Thr, ENT_QUOTES, 'UTF-8'); ?>"
                           autocomplete="off"<?php echo $readOnlyAttr; ?>>
                    <div class="form-text">0.0 (allow all) to 1.0 (strict). v3 only.</div>
                </div>
            </div>
            <p class="form-text mb-0">
                Get keys at
                <a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener noreferrer">
                    Google reCAPTCHA admin <i class="fa-solid fa-arrow-up-right-from-square fa-xs"></i>
                </a>.
            </p>
        </div>
    </div>

    <!-- 🅷 hCaptcha -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-body-tertiary d-flex align-items-center">
            <h3 class="h6 mb-0 flex-grow-1">
                <i class="fa-solid fa-h me-2"></i>hCaptcha
            </h3>
            <?php echo $statusBadge($hcaptchaSite !== '' && $hcaptchaSecret !== ''); ?>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="hcaptcha_site" class="form-label">Site Key</label>
                    <input type="text" class="form-control" id="hcaptcha_site" name="hcaptcha_site"
                           value="<?php echo htmlspecialchars($hcaptchaSite, ENT_QUOTES, 'UTF-8'); ?>"
                           autocomplete="off"<?php echo $readOnlyAttr; ?>>
                </div>
                <div class="col-md-6">
                    <label for="hcaptcha_secret" class="form-label">Secret Key</label>
                    <input type="password" class="form-control" id="hcaptcha_secret" name="hcaptcha_secret"
                           value="<?php echo htmlspecialchars($mayChangePortalWideSettings === true ? $hcaptchaSecret : '', ENT_QUOTES, 'UTF-8'); ?>"
                           autocomplete="off"<?php echo $readOnlyAttr; ?>>
                </div>
            </div>
            <p class="form-text mb-0">
                Get keys at
                <a href="https://dashboard.hcaptcha.com/" target="_blank" rel="noopener noreferrer">
                    hCaptcha dashboard <i class="fa-solid fa-arrow-up-right-from-square fa-xs"></i>
                </a>.
            </p>
        </div>
    </div>

    <?php if ($mayChangePortalWideSettings === true): ?>
        <button type="submit" class="btn btn-success">
            <i class="fa-solid fa-save me-1"></i> Save Provider Keys
        </button>
        <a href="/admin/captcha" class="btn btn-outline-secondary">Cancel</a>
    <?php endif; ?>
</form>

<?php if ($mayChangePortalWideSettings === true): ?>
<!-- ============================================================================ -->
<!-- 🪜 Drag-and-drop init (SortableJS via Asset helper — SRI when configured) -->
<!--    Only loaded for somebody who may save the order: for anybody else the   -->
<!--    list is for reading, and letting them drag rows would suggest otherwise. -->
<!-- ============================================================================ -->
<?php echo \Portal\Core\Asset::sortableJs(); ?>
<script>
(function () {
    var list  = document.getElementById('captchaPriorityList');
    var field = document.getElementById('priorityField');
    if (list === null || field === null || typeof Sortable === 'undefined') {
        return;
    }
    Sortable.create(list, {
        animation: 150,
        handle: '.fa-grip-vertical',
        ghostClass: 'bg-body-tertiary',
        onSort: function () {
            var keys = [];
            list.querySelectorAll('[data-key]').forEach(function (li) {
                keys.push(li.getAttribute('data-key'));
            });
            field.value = keys.join(',');
        }
    });
})();
</script>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
