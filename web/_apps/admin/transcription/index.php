<?php
// Path: _apps/admin/transcription/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Transcription: the queue, what it has cost, and who may change what 🗒
 * -----------------------------------------------------------------------------
 * THREE THINGS WERE WRONG HERE, and the first one made the whole feature inert.
 *
 * 1. THE "PROCESS NEXT" BUTTON WAS INSIDE THE SAVE FORM. HTML has no nested
 *    forms: a parser that meets a second `<form>` while one is open DROPS the
 *    tag and keeps its contents, so the button's owner became the outer form
 *    and pressing "Process next 5" posted the configuration to
 *    `admin/transcription/save`. Measured rather than assumed — PHP 8.4's own
 *    spec-compliant parser (`Dom\HTMLDocument`) reports ONE form in that markup
 *    and gives the button the Save form's action. So the only control anywhere
 *    that could drain the queue silently saved the settings instead, said
 *    "Transcription settings saved", and transcribed nothing. Ever.
 *
 * 2. IT SHOWED EVERY ORGANISATION'S WORK TO ANY ORGANISATION'S ADMINISTRATOR.
 *    The counts, the month's spend and the "Recent transcripts" list were read
 *    with no organisation filter at all, and that list carries recording
 *    TITLES. `App::isAdmin()` is true for an administrator of a single
 *    organisation, and an installation may serve organisations with nothing to
 *    do with one another. Everything is now read through the scoped methods on
 *    `Transcription`, which is the one place those queries live.
 *
 * 3. IT OFFERED A SAVE BUTTON TO PEOPLE WHO MAY NOT SAVE. Every setting on this
 *    page is installation-wide — one provider, one API key, paid for by whoever
 *    runs the installation — and `save.php` has refused anybody but a global
 *    administrator since 11 September 2026. Its own header says the page "was
 *    not changed, so an administrator of one organisation still sees a Save
 *    button there". They no longer do: they see what is set, and who to ask.
 *
 * WHAT A PER-ORGANISATION ADMINISTRATOR MAY STILL DO, and why that is right:
 * press "Process next". Draining their OWN organisation's queue is their
 * business, and what bounds it is the monthly budget below rather than their
 * role — which is the whole reason that budget now exists.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.5.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/276
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\InstallationSettings;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\Transcription;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);

    return;
}

$siteId     = Site::id();
$settings   = App::settings()['transcription'] ?? [];
$isGlobal   = App::isRootAdmin() === true;
$enabled    = (string) ($settings['enabled'] ?? '0') === '1';
$provider   = (string) ($settings['provider'] ?? 'openai');
$language   = (string) ($settings['language'] ?? 'en');
$batch      = (int) ($settings['batchSize'] ?? 5);
$hasOaKey   = ((string) ($settings['openai']['apiKey'] ?? '')) !== '';
$oaModel    = (string) ($settings['openai']['model'] ?? 'whisper-1');
$hasAaKey   = ((string) ($settings['assemblyai']['apiKey'] ?? '')) !== '';
$localBin   = (string) ($settings['local']['binPath'] ?? '');
$ready      = Transcription::providerIsReady($settings);

// 💳 The budget. `capFrom()` is the ONE place the two layers are weighed
//    against each other: the installation's value CAPS this organisation's and
//    is never simply overridden by it (#668, owner 29 October 2026). The
//    installation's own figure is shown beside it so the number on screen is
//    attributed honestly — somebody who set 20,000 for their organisation and
//    sees 5,000 in force needs to know WHY, or they will set it again.
$capPence     = Transcription::capFrom(App::settings(), InstallationSettings::layer());
$installCap   = Transcription::installationAllowsPence(InstallationSettings::layer());

// 🏷️ WHETHER THIS ORGANISATION ASKED FOR MORE THAN IT MAY HAVE.
//
//    This compared the effective cap with the MERGED setting, which is a
//    different question and could say something untrue. When an installation's
//    own stored figure is above the hard maximum in code and the organisation
//    has chosen nothing, the merged value IS the installation's larger figure,
//    so the comparison came out true and the page told a global administrator
//    "your organisation asked for more" about a number the organisation never
//    touched — it was the installation's own figure that code cut back.
//
//    Found by the independent check, and the self-test had been CREATING that
//    exact state (J06 stores 999999999 installation-wide) while checking only
//    the figure and never the sentence beside it.
//
//    The sentence is about the ORGANISATION'S OWN row, so that is what is read.
//    There is no site-only settings layer to ask, so it is read directly; no
//    row means the organisation asked for nothing.
$orgOwnCap = null;
$orgCapRow = $db->prepare('SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID = ? LIMIT 1');
if ($orgCapRow !== false) {
    $capKey = Transcription::CAP_KEY;
    $orgCapRow->bind_param('si', $capKey, $siteId);
    $orgCapRow->execute();
    $foundCap = $orgCapRow->get_result()->fetch_assoc();
    $orgCapRow->close();
    if ($foundCap !== null && preg_match('/^\d+$/', trim((string) $foundCap['settingValue'])) === 1) {
        $orgOwnCap = (int) trim((string) $foundCap['settingValue']);
    }
}
$cappedAbove = $orgOwnCap !== null && $orgOwnCap > $capPence;
$monthSpend   = Transcription::monthSpendPence($siteId);
$queueStats   = Transcription::queueStatsFor($siteId);
$recent       = Transcription::recentFor($siteId, 30);

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle       = 'Transcription';
$pageDescription = 'Turn recordings into text you can search, and see what it has cost this month.';
$pageSection     = 'admin';
$breadcrumbs     = ['Dashboard' => Site::url(''), 'Admin' => Site::url('admin'), 'Transcription' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-closed-captioning me-2"></i>Transcription</h1>

<?php // 🚦 Nothing here does anything until a company has been set up. Said at
      //    the top rather than left for somebody to work out from a queue that
      //    never moves. ?>
<?php if ($ready === false): ?>
    <div class="alert alert-warning">
        <strong>No transcription company is set up yet.</strong>
        Recordings can be put in the queue, but nothing will be transcribed until
        <?php echo $isGlobal === true ? 'you choose a company below and enter its key.' : 'a global administrator chooses one and enters its key.'; ?>
    </div>
<?php endif; ?>

<?php // ⏱️ A job that says "processing" but was handed over too long ago to
      //    still be running. This is what a shared host leaves behind when it
      //    kills a long request, and it is the one thing that explains a
      //    recording that never finishes, so it is said plainly. ?>
<?php if ($queueStats['stuck'] > 0): ?>
    <div class="alert alert-info">
        <strong><?php echo (int) $queueStats['stuck']; ?></strong>
        <?php echo $queueStats['stuck'] === 1 ? 'recording was' : 'recordings were'; ?>
        sent to the transcription company and never came back &mdash; most likely the request was cut
        short by the server. <?php echo $queueStats['stuck'] === 1 ? 'It' : 'They'; ?> can be sent again
        from the recording's own page. <strong>That attempt may well have been charged for</strong>, and
        nothing here can tell: the request may have died before the company was reached, while it was
        working, or after it finished and before the answer could be written down.
    </div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card"><div class="card-body">
        <div class="small text-muted">Waiting</div>
        <div class="display-6"><?php echo (int) $queueStats['queued']; ?></div>
    </div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body">
        <div class="small text-muted">Ready to read</div>
        <div class="display-6"><?php echo (int) $queueStats['completed']; ?></div>
    </div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body">
        <div class="small text-muted">Could not be done</div>
        <div class="display-6"><?php echo (int) $queueStats['failed']; ?></div>
    </div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body">
        <div class="small text-muted">This month</div>
        <div class="display-6">&pound;<?php echo number_format($monthSpend / 100, 2); ?></div>
        <div class="small text-muted">
            of &pound;<?php echo number_format($capPence / 100, 2); ?>
            <?php if ($cappedAbove === true): ?>
                <br><span class="text-warning-emphasis">Your organisation asked for more; the installation allows
                &pound;<?php echo number_format($installCap / 100, 2); ?>.</span>
            <?php endif; ?>
        </div>
    </div></div></div>
</div>

<?php // ▶️ THE QUEUE BUTTON, IN A FORM OF ITS OWN.
      //
      //    This used to sit INSIDE the configuration form below, which is not
      //    something HTML allows: the inner tag is dropped and the button ends
      //    up owned by the outer form, so pressing it saved the settings and
      //    drained nothing. It is first on the page now, and nothing may put it
      //    back inside another form. ?>
<div class="card mb-3">
    <div class="card-header"><strong>The queue</strong></div>
    <div class="card-body">
        <?php if ((int) $queueStats['queued'] > 0): ?>
            <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/transcription/run'), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <button class="btn btn-outline-success" type="submit" <?php echo $ready === false ? 'disabled' : ''; ?>>
                    <i class="fa-solid fa-play me-1"></i>Process next <?php echo (int) $batch; ?>
                </button>
                <div class="form-text">
                    Sends up to <?php echo (int) $batch; ?> of your organisation's waiting recordings to the
                    transcription company, which charges for each one. This can take several minutes and the
                    page will wait for it. Nothing happens on its own: the queue only moves when somebody
                    presses this.
                </div>
            </form>
        <?php else: ?>
            <p class="text-muted mb-0">
                Nothing is waiting. A recording is put in the queue with the &ldquo;Transcribe&rdquo; button on
                its own page under <a href="<?php echo htmlspecialchars(Site::url('recordings'), ENT_QUOTES, 'UTF-8'); ?>">Recordings</a>.
            </p>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><strong>Configuration</strong></div>
    <div class="card-body">
        <?php if ($isGlobal === false): ?>
            <?php // 🚧 Read-only for an administrator of one organisation, and
                  //    NOT by disabling the controls: a disabled control is not
                  //    submitted, so a half-disabled form posts gaps and Save
                  //    quietly writes defaults over somebody's choices. This
                  //    project has been caught by exactly that. There is simply
                  //    no form here. ?>
            <div class="alert alert-secondary">
                These settings apply to <strong>every organisation on this installation</strong> &mdash; one
                transcription company, one account, one bill. Only a global administrator can change them.
                Your own organisation's settings are on the main Settings page and are unaffected.
            </div>
            <div class="portal-data-list small">
                <div class="row py-1 border-bottom"><div class="col-md-4 text-muted">Switched on</div><div class="col-md-8"><?php echo $enabled === true ? 'Yes' : 'No'; ?></div></div>
                <div class="row py-1 border-bottom"><div class="col-md-4 text-muted">Company</div><div class="col-md-8"><?php
                    echo htmlspecialchars(match ($provider) {
                        'openai'     => 'OpenAI Whisper',
                        'assemblyai' => 'AssemblyAI',
                        'local'      => 'Local whisper program on this server',
                        default      => $provider,
                    }, ENT_QUOTES, 'UTF-8');
                ?></div></div>
                <div class="row py-1 border-bottom"><div class="col-md-4 text-muted">Account key</div><div class="col-md-8"><?php echo $ready === true ? 'Set' : 'Not set'; ?></div></div>
                <div class="row py-1 border-bottom"><div class="col-md-4 text-muted">Language</div><div class="col-md-8"><?php echo htmlspecialchars($language, ENT_QUOTES, 'UTF-8'); ?></div></div>
                <div class="row py-1 border-bottom"><div class="col-md-4 text-muted">Recordings per run</div><div class="col-md-8"><?php echo (int) $batch; ?></div></div>
                <div class="row py-1"><div class="col-md-4 text-muted">Monthly budget</div><div class="col-md-8">&pound;<?php echo number_format($capPence / 100, 2); ?></div></div>
            </div>
        <?php else: ?>
            <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/transcription/save'), ENT_QUOTES, 'UTF-8'); ?>" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="col-md-3">
                    <label class="form-label" for="tProvider">Company</label>
                    <select class="form-select" id="tProvider" name="provider">
                        <option value="openai"     <?php echo $provider === 'openai'     ? 'selected' : ''; ?>>OpenAI Whisper</option>
                        <option value="assemblyai" <?php echo $provider === 'assemblyai' ? 'selected' : ''; ?>>AssemblyAI</option>
                        <option value="local"      <?php echo $provider === 'local'      ? 'selected' : ''; ?>>Local whisper.cpp</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="tLanguage">Language</label>
                    <input type="text" class="form-control" id="tLanguage" name="language" value="<?php echo htmlspecialchars($language, ENT_QUOTES, 'UTF-8'); ?>" maxlength="10" placeholder="en">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="tBatch">Per run</label>
                    <input type="number" min="1" max="50" class="form-control" id="tBatch" name="batchSize" value="<?php echo (int) $batch; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="tCap">Monthly budget (pence)</label>
                    <input type="number" min="0" max="<?php echo (int) Transcription::HARD_MAX_CAP_PENCE; ?>" class="form-control" id="tCap" name="monthCapPence" value="<?php echo (int) $installCap; ?>">
                    <div class="form-text">
                        &pound;<?php echo number_format($installCap / 100, 2); ?> a month.
                        This is the MOST any organisation on the installation may spend; an organisation can
                        hold itself to less and can never give itself more.
                    </div>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="tEnabled" name="enabled" value="1" <?php echo $enabled === true ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="tEnabled">Switched on</label>
                    </div>
                </div>
                <div class="col-12"><hr class="my-1"></div>
                <div class="col-md-6">
                    <h6 class="text-muted">OpenAI Whisper</h6>
                    <label class="form-label small" for="tOaKey">Account key <?php echo $hasOaKey === true ? '<span class="badge bg-success">set</span>' : ''; ?></label>
                    <input type="password" class="form-control form-control-sm" id="tOaKey" name="oa_key" placeholder="<?php echo $hasOaKey === true ? 'Leave blank to keep the one already saved' : 'sk-…'; ?>" autocomplete="off">
                    <label class="form-label small mt-2" for="tOaModel">Model</label>
                    <input type="text" class="form-control form-control-sm" id="tOaModel" name="oa_model" value="<?php echo htmlspecialchars($oaModel, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-3">
                    <h6 class="text-muted">AssemblyAI</h6>
                    <label class="form-label small" for="tAaKey">Account key <?php echo $hasAaKey === true ? '<span class="badge bg-success">set</span>' : ''; ?></label>
                    <input type="password" class="form-control form-control-sm" id="tAaKey" name="aa_key" placeholder="<?php echo $hasAaKey === true ? 'Leave blank to keep the one already saved' : ''; ?>" autocomplete="off">
                </div>
                <div class="col-md-3">
                    <h6 class="text-muted">Local</h6>
                    <label class="form-label small" for="tLocalBin">whisper program on this server</label>
                    <input type="text" class="form-control form-control-sm" id="tLocalBin" name="local_bin" value="<?php echo htmlspecialchars($localBin, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit">Save</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header"><strong>Recent transcripts</strong></div>
    <div class="card-body">
        <?php if (count($recent) === 0): ?>
            <p class="text-muted mb-0">Nothing has been transcribed for your organisation yet.</p>
        <?php else: ?>
            <div class="portal-data-list">
                <?php foreach ($recent as $t):
                    $cls = match ((string) $t['status']) {
                        'completed'  => 'success',
                        'processing' => 'info',
                        'failed'     => 'danger',
                        default      => 'secondary',
                    };
                ?>
                    <div class="row py-1 border-bottom small">
                        <div class="col-md-4"><a href="<?php echo htmlspecialchars(Site::url('recordings/view'), ENT_QUOTES, 'UTF-8'); ?>?id=<?php echo (int) $t['recordingID']; ?>"><?php echo htmlspecialchars((string) $t['title'], ENT_QUOTES, 'UTF-8'); ?></a></div>
                        <div class="col-md-2"><span class="badge bg-<?php echo htmlspecialchars($cls, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(Transcription::statusWords((string) $t['status']), ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="col-md-2 text-muted"><?php echo htmlspecialchars((string) $t['provider'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-2 text-muted"><?php echo $t['durationSec'] !== null ? htmlspecialchars((string) round(((int) $t['durationSec']) / 60, 1), ENT_QUOTES, 'UTF-8') . ' min' : '&mdash;'; ?></div>
                        <div class="col-md-2 text-end text-muted"><?php echo $t['costPence'] !== null ? '&pound;' . number_format(((int) $t['costPence']) / 100, 2) : '&mdash;'; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
