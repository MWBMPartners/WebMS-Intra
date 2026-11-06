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
// 🔢 The installation's own figure for the number of requests (#762), for
//    the FORM BOX only — the same #674 rule as every other box here.
$callCapBox = (int) ($settings['monthCallCap'] ?? Translation::DEFAULT_CALL_CAP);

$hasAnth = ((string) ($settings['anthropic']['apiKey'] ?? '')) !== '';
$hasOa   = ((string) ($settings['openai']['apiKey']    ?? '')) !== '';
$hasGoog = ((string) ($settings['google']['apiKey']    ?? '')) !== '';
$hasDeep = ((string) ($settings['deepl']['apiKey']     ?? '')) !== '';
$libBase = (string) ($settings['libre']['baseUrl'] ?? '');
$hasLib  = ((string) ($settings['libre']['apiKey']     ?? '')) !== '';

// 🧾 THIS ORGANISATION'S USE THIS MONTH, against THIS ORGANISATION'S limits.
//    The boxes below show the installation's own figures, because that is what
//    Save writes (#674); the cards and the "which limit" sentence show what is
//    actually in force for the organisation being served, because that is what
//    `Translation::translate()` obeys. They differ only where an organisation
//    holds itself to less, which no page can set today (see the note above).
//
//    `monthUsage()` answers null when the record cannot be read — on a server
//    where "Run migrations" has not been pressed, the table is not there yet —
//    and translation is then REFUSED rather than allowed (#762). The page says
//    so rather than showing £0.00, which would read as "nothing spent".
$usage       = Translation::monthUsage(Site::id(), Translation::usageMonth());
$capInForce  = Translation::capPence();
$callsInForce = Translation::callCap();
// The ONE comparison each, shared with `Translation::translate()`, so this
// page cannot say "reached" while a press still sends (or the reverse).
$moneyReached = $usage !== null && Translation::moneyReached($usage['pence']);
$callsReached = $usage !== null && Translation::callsReached($usage['calls']);
// The provider IN FORCE — the merged value `translate()` reads — for the two
// notes about it below. The form's own `$provider` stays the installation's
// (#674); the two differ only where an organisation holds a row of its own.
$translationInForce = App::settings()['translation'] ?? [];
$providerInForce    = (string) ($translationInForce['provider'] ?? 'anthropic');
$providerIsReady    = Translation::providerReady($providerInForce, $translationInForce);

// 🗂️ THIS ORGANISATION'S CACHED TRANSLATIONS (#762's check, #800 item 2).
//    Two faults in the old version, both on the card an administrator reads:
//    the query named its count `n` while the card printed `total`, so the card
//    showed 0 on every installation whatever the cache held (an independent
//    check measured "0 / 3 target languages"); and it counted the WHOLE
//    installation, so on a shared one each organisation saw everybody's. The
//    names now match and the count is this organisation's (`siteID`, recorded
//    since #765). A row with no organisation — its source deleted before
//    migration 250 could attribute it — is counted by nobody, as for money.
$cacheStats = ['total' => 0, 'langs' => 0];
$cs = $db->prepare('SELECT COUNT(*) AS `total`, COUNT(DISTINCT `targetLanguage`) AS `langs` '
    . 'FROM `tblContentTranslation` WHERE `siteID` = ?');
if ($cs !== false) {
    $siteForCache = Site::id();
    $cs->bind_param('i', $siteForCache);
    $cs->execute();
    $cacheRow = $cs->get_result()->fetch_assoc();
    $cs->close();
    if (is_array($cacheRow) === true) {
        $cacheStats = ['total' => (int) $cacheRow['total'], 'langs' => (int) $cacheRow['langs']];
    }
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
        below and costs nothing. <strong>There are two monthly limits</strong>, and
        whichever is reached first stops new translations until the next month &mdash;
        already-translated pieces keep working. One is the money each translation company
        <em>reports</em> charging; the other is the <em>number</em> of translations sent to
        it, which works whatever the company reports.
    </div>
</div>

<?php
// 🚦 WHICH LIMIT IS STOPPING TRANSLATION, said in words (#762, D-059). The
//    owner's condition for having two limits at all: "the page must say WHICH
//    one stopped it, or somebody will raise their budget and find nothing
//    changes." So each sentence names the limit AND says that raising the
//    other one will not help. `data-limit-reached` is there for the self-test,
//    which reads it alongside the words.
?>
<?php if ($usage === null): ?>
    <div class="alert alert-danger" role="alert" data-limit-reached="unreadable">
        <strong>This month&rsquo;s translation use could not be read, so no new translations
        are being sent.</strong> The usual cause is that the portal&rsquo;s files have been
        updated and the database upgrade has not been run yet &mdash; ask a global
        administrator to open Admin &rarr; Upgrade. Pieces already translated keep working.
    </div>
<?php elseif ($moneyReached === true && $callsReached === true): ?>
    <div class="alert alert-warning" role="alert" data-limit-reached="both">
        <strong>Both monthly limits are reached</strong> &mdash; the money limit and the limit
        on the number of translations. New translations are stopped until the start of next
        month (in UTC). To allow more now, both limits would have to be raised.
    </div>
<?php elseif ($moneyReached === true): ?>
    <div class="alert alert-warning" role="alert" data-limit-reached="money">
        <strong>The monthly money limit is reached</strong>, so new translations are stopped
        until the start of next month (in UTC). Raising the limit on the <em>number</em> of
        translations will not change this; only the money limit is stopping them.
    </div>
<?php elseif ($callsReached === true): ?>
    <div class="alert alert-warning" role="alert" data-limit-reached="calls">
        <strong>The monthly limit on the number of translations is reached</strong>, so new
        translations are stopped until the start of next month (in UTC). Raising the
        <em>money</em> limit will not change this; only the number limit is stopping them.
    </div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card"><div class="card-body">
        <div class="small text-muted">Cached translations</div>
        <div class="display-6" data-cached-count="<?php echo (int) $cacheStats['total']; ?>"><?php echo (int) $cacheStats['total']; ?></div>
        <div class="small text-muted"><?php echo (int) $cacheStats['langs']; ?> target language<?php echo $cacheStats['langs'] === 1 ? '' : 's'; ?></div>
    </div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body">
        <div class="small text-muted">Money spent this month</div>
        <div class="display-6"><?php echo $usage === null ? '&mdash;' : '£' . number_format($usage['pence'] / 100, 2); ?></div>
        <div class="small text-muted"><?php echo $capInForce > 0 ? 'of £' . number_format($capInForce / 100, 2) . ' limit' : 'no money limit'; ?></div>
    </div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body">
        <div class="small text-muted">Translations sent this month</div>
        <div class="display-6"><?php echo $usage === null ? '&mdash;' : number_format($usage['calls']); ?></div>
        <div class="small text-muted"><?php echo $callsInForce > 0 ? 'of ' . number_format($callsInForce) . ' limit' : 'no limit on the number'; ?></div>
    </div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body">
        <div class="small text-muted">Active provider</div>
        <div class="display-6"><?php echo htmlspecialchars($provider, ENT_QUOTES, 'UTF-8'); ?></div>
    </div></div></div>
</div>

<?php if ((string) ($translationInForce['enabled'] ?? '0') === '1' && $providerIsReady === false): ?>
    <?php
    // 🔌 #762: nothing is sent, and nothing counted, while the chosen company
    //    has no key. Said here because a member pressing Translate is told only
    //    "not fully set up", and this is the person who can fix it.
    ?>
    <div class="alert alert-warning" role="alert" data-provider-not-ready="1">
        <strong>Translation is switched on, but it is not fully set up</strong>, so nothing is being
        sent: the chosen translation company has no API key (or, for LibreTranslate, no address), or
        the company named in the settings is not one the portal recognises. Members pressing
        Translate are told it has not been fully set up. Choose a company and add its key below.
    </div>
<?php endif; ?>

<?php if ($providerInForce === 'libre'): ?>
    <?php
    // 📣 THE HONEST SENTENCE D-059 welcomed as an addition (#762) — AND ITS
    //    FIRST VERSION WAS UNTRUE IN EXACTLY THE STATE IT EXISTS FOR. It said
    //    the money figure "stays at £0.00" and the money limit "can never stop
    //    a translation" with LibreTranslate chosen. But the month's record is
    //    per ORGANISATION, not per provider: money spent earlier in the month
    //    on a paid company (or carried in by migration 254) stays in it. An
    //    independent check measured the page showing the money banner, a card
    //    reading £2.50 of £1.00, and directly below it this note saying the
    //    figure stays at £0.00 and only the number limit can stop translation
    //    — pointing the administrator at the wrong limit, which is the cost
    //    D-059 named. So it says only what is always true: LibreTranslate ADDS
    //    nothing to the money figure.
    ?>
    <div class="alert alert-secondary" role="note" data-libre-reports-no-cost="1">
        <strong>LibreTranslate does not tell the portal what it charges</strong>, so translations
        sent to it add nothing to the money spent this month. Any money shown above was recorded
        earlier, on another translation company. While LibreTranslate is in use, the limit on the
        <em>number</em> of translations is the one that keeps track of what it is asked to do. A
        LibreTranslate server you run yourself is free; a hosted one, such as libretranslate.com,
        may charge you, whatever the money figure here says.
    </div>
<?php endif; ?>

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
            <div class="col-md-3">
                <label class="form-label">Monthly limit on the number of translations</label>
                <?php
                // 🔢 #762. Prefilled from the installation's own row (#674) and
                //    never above its own `max`, for the same reason as the money
                //    box. `step="1"`: a request is not divisible, and save.php
                //    refuses a fraction rather than rounding it.
                ?>
                <input type="number" min="0" max="<?php echo (int) Translation::MAX_CALL_CAP; ?>" step="1" class="form-control" name="monthCalls" value="<?php echo (int) min(max($callCapBox, 0), Translation::MAX_CALL_CAP); ?>">
                <div class="form-text">Each attempt to reach the translation company counts, including one that fails — the portal cannot always tell whether a failed attempt reached the company. Nothing is counted while the chosen company has no key (or, for LibreTranslate, no server address), because nothing can be sent. 0 means no limit. The most that can be set is <?php echo number_format(Translation::MAX_CALL_CAP); ?> a month.</div>
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
