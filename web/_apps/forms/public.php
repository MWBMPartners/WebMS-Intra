<?php
// Path: _apps/forms/public.php
/**
 * -----------------------------------------------------------------------------
 * Forms Builder — Public Fill Page 🌐
 * -----------------------------------------------------------------------------
 * Reached ONLY via Router::handleSpecialRoutes()'s `f/{token}` special case
 * — cloned from the `os/{token}` block (service-plans/public.php's own
 * precedent) — NOT a tblRoutes row, so this works even mid-migration.
 * `$_GET['token']` is pre-validated by the Router against `^[a-f0-9]{32}$`
 * before this file is even required; re-validated here defensively rather
 * than trusting that (same discipline as every other public-token page in
 * this codebase).
 *
 * ACCESS MODEL (security-relevant — read before changing):
 *   Renders the form ONLY when ALL of the following hold, in order:
 *     1. The token matches an existing `tblForms.publicToken`.
 *     2. That form's `audience` is 'public' or 'both'.
 *     3. That form's `status = 'published'`.
 *     4. `FormEngine::isOpen($form)` — within its own opensAt/closesAt window.
 *     5. `forms.allowPublic` is 'true' FOR THE FORM'S OWN SITE (the
 *        site-level kill-switch — default OFF).
 *     6. `forms.enabled` is 'true'/'1' FOR THE FORM'S OWN SITE (the
 *        AppRegistry gate — this special route bypasses the normal
 *        tblRoutes/AppRegistry check every other `forms/*` page gets, so
 *        this file re-checks it explicitly).
 *   ANY of these failing renders the exact SAME 404 — uniform response, no
 *   oracle. An attacker probing tokens can never tell "wrong token" apart
 *   from "right token, but sharing is off" or "right token, but the app is
 *   disabled".
 *
 * TENANT SAFETY: this page has NO active-site context (public, anonymous,
 * and the Host header may not even belong to the form's own site on a
 * multi-site install) — EVERY query after the token lookup is scoped
 * explicitly to `$form['siteID']` (never `Site::id()`), and the settings
 * gates above use `App::settingForSite()` rather than the bootstrap
 * `$SETTINGS` snapshot (ApiRouter::resolveEnabledFlag()'s identical
 * reasoning for a tenant-pinned bearer request).
 *
 * @package   Portal\Forms
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/153
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Asset;
use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\FormEngine;
use Portal\Core\Router;

// 🔍 Defensive re-validation — the Router already checked this shape.
$token = (string) ($_GET['token'] ?? '');
if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
    Router::renderError(404);
    return;
}

$form = FormEngine::getFormByToken($token);

// 🚫 Gate 1 — unknown token.
if ($form === null) {
    Router::renderError(404);
    return;
}

$formSiteId = (int) $form['siteID'];

// 🚫 Gates 2-6 — every remaining gate, uniform 404 on any failure.
$audienceOk = in_array((string) $form['audience'], ['public', 'both'], true);
$isPublished = (string) $form['status'] === 'published';
$isOpen = FormEngine::isOpen($form);
$sitePublicOn = (string) (App::settingForSite('forms.allowPublic', $formSiteId) ?? 'false') === 'true';
$appEnabledOn = in_array(
    (string) (App::settingForSite('forms.enabled', $formSiteId) ?? '0'),
    ['true', '1'],
    true
);

if ($audienceOk === false || $isPublished === false || $isOpen === false || $sitePublicOn === false || $appEnabledOn === false) {
    Router::renderError(404);
    return;
}

// ✅ Every gate passed.
$formId = (int) $form['formID'];
$fields = FormEngine::getFields($formId);

Auth::ensureSession();

$submitted = isset($_GET['submitted']) === true && $_GET['submitted'] === '1';
$errFlash  = (string) ($_GET['err'] ?? '');

// 🚩 Field-level validation flash bag — namespaced separately from the
// internal fill.php/submit.php flash keys so an anonymous visitor and a
// logged-in member sharing a browser session can never cross-contaminate.
$flashOld    = $_SESSION['forms_pub_flash_old']    ?? [];
$flashErrors = $_SESSION['forms_pub_flash_errors'] ?? [];
$flashToken  = (string) ($_SESSION['forms_pub_flash_token'] ?? '');
unset($_SESSION['forms_pub_flash_old'], $_SESSION['forms_pub_flash_errors'], $_SESSION['forms_pub_flash_token']);
if ($flashToken !== $token) {
    $flashOld = [];
    $flashErrors = [];
}

$siteBaseUrl = rtrim((string) (App::settingForSite('site.url', $formSiteId) ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? ''))), '/');
$siteNameRaw = (string) (App::settingForSite('site.name', $formSiteId) ?? 'this organisation');

$titleSafe = htmlspecialchars((string) $form['title'], ENT_QUOTES, 'UTF-8');
$siteNameSafe = htmlspecialchars($siteNameRaw, ENT_QUOTES, 'UTF-8');
$descRaw = (string) ($form['description'] ?? '');
$confirmationRaw = trim((string) ($form['confirmationText'] ?? ''));
$confirmationSafe = $confirmationRaw !== ''
    ? nl2br(htmlspecialchars($confirmationRaw, ENT_QUOTES, 'UTF-8'))
    : 'Thank you — your response has been recorded.';

$csrf = Auth::csrfToken();
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $titleSafe; ?> &middot; <?php echo $siteNameSafe; ?></title>

    <!-- 🤖 Public form — never index in search engines -->
    <meta name="robots" content="noindex, nofollow">

    <?php echo Asset::bootstrapCss(); ?>
    <?php echo Asset::fontAwesomeCss(); ?>
    <?php echo Asset::portalCss(); ?>

    <script>
    (function(){
        var t = localStorage.getItem('portal-theme');
        if (t === 'dark' || t === 'light') {
            document.documentElement.setAttribute('data-bs-theme', t);
        }
    })();
    </script>

    <?php echo Captcha::scriptTag(); ?>
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 py-4">
<div class="card shadow p-4" style="min-width:min(320px,100%);max-width:640px;width:100%;">

    <div class="text-center mb-3">
        <i class="fa-solid fa-clipboard-list fa-2x text-muted mb-2"></i>
        <h1 class="h4 mb-1"><?php echo $titleSafe; ?></h1>
        <p class="text-muted small mb-0"><?php echo $siteNameSafe; ?></p>
    </div>

    <?php if ($submitted === true): ?>
        <div class="alert alert-success small" role="alert">
            <i class="fa-solid fa-circle-check me-1"></i>
            <?php echo $confirmationSafe; ?>
        </div>
        <a href="<?php echo htmlspecialchars($siteBaseUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary w-100">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Home
        </a>
    <?php else: ?>

        <?php if ($errFlash !== ''): ?>
            <div class="alert alert-danger small">
                <i class="fa-solid fa-circle-exclamation me-1"></i>
                <?php echo htmlspecialchars($errFlash, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($descRaw !== ''): ?>
            <p class="text-muted small"><?php echo nl2br(htmlspecialchars($descRaw, ENT_QUOTES, 'UTF-8')); ?></p>
        <?php endif; ?>

        <form method="post" action="/forms/public-submit" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="formToken" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">

            <!-- 🕳️ Honeypot — hidden from humans via CSS, visible to naive bots. -->
            <div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">
                <label for="website">Leave this field blank</label>
                <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
            </div>

            <?php foreach ($fields as $field): ?>
                <?php echo FormEngine::renderField($field, $flashOld, $flashErrors); ?>
            <?php endforeach; ?>

            <?php echo Captcha::widget(); ?>

            <button type="submit" class="btn btn-primary w-100 mb-2">
                <i class="fa-solid fa-paper-plane me-1"></i> Submit
            </button>
        </form>

    <?php endif; ?>

</div>

<?php echo Asset::bootstrapJs(); ?>
</body>
</html>
