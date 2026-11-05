<?php
// Path: public_html/admin/translation/index.php
/**
 * Admin — Translation provider config + cost dashboard.
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/278
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\InstallationSettings;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\Translation;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$db = App::db();

// 🏢 THE BOXES ARE FILLED FROM THE INSTALLATION'S OWN ROWS, NOT FROM THE
//    MERGED SETTINGS (#674). `save.php` beside this file writes every one of
//    these with `siteID IS NULL`, so it writes the INSTALLATION's value — and
//    `$SETTINGS` has already let an organisation's own row win by the time any
//    page runs. Fill a box from the merged array and a global administrator
//    who happens to be in an organisation with its own row sees THAT value,
//    presses Save without touching anything, and it becomes everybody's. No
//    mistake is required.
//
//    In practice no organisation can hold a `translation.*` row today, because
//    `_apps/settings/save.php` refuses to add a per-organisation row for a key
//    the installation already has, and all of these are seeded
//    installation-wide. Said plainly rather than relied on: a row written by
//    hand or restored from a backup would still be there, and "the portal
//    cannot reach this state" is the sentence that stops the next reader
//    looking (#687).
$installationLayer = InstallationSettings::layer();
$settings = $installationLayer['translation'] ?? [];

$enabled  = (string) ($settings['enabled'] ?? '0') === '1';
$provider = (string) ($settings['provider'] ?? 'anthropic');
$cap      = (int) ($settings['monthCapPence'] ?? Translation::DEFAULT_CAP_PENCE);

$hasAnth = ((string) ($settings['anthropic']['apiKey'] ?? '')) !== '';
$hasOa   = ((string) ($settings['openai']['apiKey']    ?? '')) !== '';
$hasGoog = ((string) ($settings['google']['apiKey']    ?? '')) !== '';
$hasDeep = ((string) ($settings['deepl']['apiKey']     ?? '')) !== '';
$libBase = (string) ($settings['libre']['baseUrl'] ?? '');
$hasLib  = ((string) ($settings['libre']['apiKey']     ?? '')) !== '';

$spend = Translation::monthSpendPence();

$cacheStats = ['total' => 0, 'languages' => 0];
$rs = $db->query('SELECT COUNT(*) AS n, COUNT(DISTINCT targetLanguage) AS langs FROM tblContentTranslation');
if ($rs !== false) {
    $cacheStats = $rs->fetch_assoc() ?? $cacheStats;
    $rs->free();
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Translation';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Admin' => Site::url('admin'), 'Translation' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-language me-2"></i>Translation</h1>

<?php
// 📣 WHAT THIS DOES AND DOES NOT COVER, said at the top (#485, #761).
//    This block REPLACES the standing warning that used to say "nothing is
//    being translated yet", which was true from the day this page was built
//    until 4 November 2026: the engine, these settings and the member's opt-in
//    all existed and no address a member could reach called any of them.
//
//    It is still NOT dismissible and still NOT behind the flash-message block,
//    for the same reason as before — it must be true every time the page is
//    opened — but what it now says is a LIMIT rather than a warning that the
//    feature is inert. Three things an administrator cannot see from the
//    settings below:
//
//      • which content carries the control (three fields, not everything);
//      • that a prayer request deliberately does not, and why;
//      • that each member must permit it for their own account first.
//
//    If the allow-list in `Portal\Core\ContentTranslation` changes, change
//    this sentence in the same edit. A page that describes a list it no longer
//    matches is worse than one that says nothing.
?>
<div class="alert alert-info d-flex align-items-start" role="alert">
    <i class="fa-solid fa-circle-info me-2 mt-1"></i>
    <div>
        <strong>What members can translate.</strong>
        A &ldquo;Translate&rdquo; button appears on an <strong>announcement</strong>, a
        <strong>project&rsquo;s description</strong> and an <strong>update posted against a
        project</strong> &mdash; the things your organisation has published. Each member has
        to switch it on for their own account first, under Account &rarr; Translation,
        because translating sends the text to the outside company chosen below.
        <br>
        <strong>Prayer requests are not translatable, on purpose.</strong> A prayer request
        is somebody&rsquo;s own words, and often about a third person &mdash; a relative in
        hospital, say &mdash; who has no way to agree to their circumstances being sent to
        another company. One member pressing a button must not do that to another.
        <br>
        <strong>Every translation costs money.</strong> The first time a piece is
        translated into a language it is paid for; after that it is served from the cache
        below and costs nothing. The monthly limit stops new charges once reached &mdash;
        already-translated pieces keep working.
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card"><div class="card-body">
        <div class="small text-muted">Cached translations</div>
        <div class="display-6"><?php echo (int) $cacheStats['total']; ?></div>
        <div class="small text-muted"><?php echo (int) ($cacheStats['langs'] ?? $cacheStats['languages']); ?> target language<?php echo ((int) ($cacheStats['langs'] ?? 0)) === 1 ? '' : 's'; ?></div>
    </div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body">
        <div class="small text-muted">This month spend</div>
        <div class="display-6">£<?php echo number_format($spend / 100, 2); ?></div>
        <?php if ($cap > 0): ?>
            <div class="small text-muted">of £<?php echo number_format($cap / 100, 2); ?> cap</div>
        <?php endif; ?>
    </div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body">
        <div class="small text-muted">Active provider</div>
        <div class="display-6"><?php echo htmlspecialchars($provider, ENT_QUOTES, 'UTF-8'); ?></div>
    </div></div></div>
</div>

<div class="card">
    <div class="card-header"><strong>Configuration</strong></div>
    <div class="card-body">
        <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/translation/save'), ENT_QUOTES, 'UTF-8'); ?>" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="col-md-3">
                <label class="form-label">Provider</label>
                <select class="form-select" name="provider">
                    <option value="anthropic" <?php echo $provider === 'anthropic' ? 'selected' : ''; ?>>Anthropic (Claude)</option>
                    <option value="openai"    <?php echo $provider === 'openai'    ? 'selected' : ''; ?>>OpenAI</option>
                    <option value="google"    <?php echo $provider === 'google'    ? 'selected' : ''; ?>>Google Cloud Translation</option>
                    <option value="deepl"     <?php echo $provider === 'deepl'     ? 'selected' : ''; ?>>DeepL</option>
                    <option value="libre"     <?php echo $provider === 'libre'     ? 'selected' : ''; ?>>LibreTranslate (self-hosted)</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Monthly cap (£)</label>
                <?php
                // 💸 `max` is the hard maximum in code, in pounds. It is a courtesy
                //    to whoever is filling the form in; `save.php` is the boundary and
                //    REFUSES a larger figure with the reason rather than quietly
                //    reducing it (#668). Never prefill a value this `max` forbids — the
                //    browser then refuses to submit the whole form, which is how the
                //    only way to use a page becomes shortening the figure.
                ?>
                <input type="number" min="0" max="<?php echo (int) (Translation::MAX_CAP_PENCE / 100); ?>" step="0.01" class="form-control" name="monthCap" value="<?php echo number_format(min($cap, Translation::MAX_CAP_PENCE) / 100, 2, '.', ''); ?>">
                <div class="form-text">0 means no limit. The most that can be set here is &pound;<?php echo number_format(Translation::MAX_CAP_PENCE / 100, 2); ?> a month.</div>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="trEnabled" name="enabled" value="1" <?php echo $enabled === true ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="trEnabled">Enable translation</label>
                </div>
            </div>
            <hr>
            <div class="col-md-6">
                <h6 class="text-muted">Anthropic (recommended)</h6>
                <label class="form-label small">API key <?php echo $hasAnth === true ? '<span class="badge bg-success">set</span>' : ''; ?></label>
                <input type="password" class="form-control form-control-sm" name="anth_key" placeholder="<?php echo $hasAnth === true ? 'Leave blank to keep' : 'sk-ant-…'; ?>" autocomplete="off">
                <label class="form-label small mt-2">Model</label>
                <input type="text" class="form-control form-control-sm" name="anth_model" value="<?php echo htmlspecialchars((string) ($settings['anthropic']['model'] ?? 'claude-haiku-4-5-20251001'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-6">
                <h6 class="text-muted">OpenAI</h6>
                <label class="form-label small">API key <?php echo $hasOa === true ? '<span class="badge bg-success">set</span>' : ''; ?></label>
                <input type="password" class="form-control form-control-sm" name="oa_key" placeholder="<?php echo $hasOa === true ? 'Leave blank to keep' : ''; ?>" autocomplete="off">
                <label class="form-label small mt-2">Model</label>
                <input type="text" class="form-control form-control-sm" name="oa_model" value="<?php echo htmlspecialchars((string) ($settings['openai']['model'] ?? 'gpt-4o-mini'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-4">
                <h6 class="text-muted">Google</h6>
                <label class="form-label small">API key <?php echo $hasGoog === true ? '<span class="badge bg-success">set</span>' : ''; ?></label>
                <input type="password" class="form-control form-control-sm" name="goog_key" placeholder="<?php echo $hasGoog === true ? 'Leave blank to keep' : ''; ?>" autocomplete="off">
            </div>
            <div class="col-md-4">
                <h6 class="text-muted">DeepL</h6>
                <label class="form-label small">API key <?php echo $hasDeep === true ? '<span class="badge bg-success">set</span>' : ''; ?></label>
                <input type="password" class="form-control form-control-sm" name="deepl_key" placeholder="<?php echo $hasDeep === true ? 'Leave blank to keep' : ''; ?>" autocomplete="off">
            </div>
            <div class="col-md-4">
                <h6 class="text-muted">LibreTranslate</h6>
                <label class="form-label small">Base URL</label>
                <input type="text" class="form-control form-control-sm" name="libre_base" value="<?php echo htmlspecialchars($libBase, ENT_QUOTES, 'UTF-8'); ?>">
                <label class="form-label small mt-2">API key (if required) <?php echo $hasLib === true ? '<span class="badge bg-success">set</span>' : ''; ?></label>
                <input type="password" class="form-control form-control-sm" name="libre_key" placeholder="<?php echo $hasLib === true ? 'Leave blank to keep' : 'optional'; ?>" autocomplete="off">
            </div>
            <div class="col-12">
                <button class="btn btn-primary" type="submit">Save</button>
            </div>
        </form>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
