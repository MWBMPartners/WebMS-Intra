<?php
// Path: public_html/admin/transcription/index.php
/**
 * Admin — Transcription provider config + queue status + monthly cost.
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/276
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Transcription;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$db       = App::db();
$settings = App::settings()['transcription'] ?? [];
$enabled  = (string) ($settings['enabled'] ?? '0') === '1';
$provider = (string) ($settings['provider'] ?? 'openai');
$language = (string) ($settings['language'] ?? 'en');
$batch    = (int) ($settings['batchSize'] ?? 5);
$hasOaKey = ((string) ($settings['openai']['apiKey'] ?? '')) !== '';
$oaModel  = (string) ($settings['openai']['model'] ?? 'whisper-1');
$hasAaKey = ((string) ($settings['assemblyai']['apiKey'] ?? '')) !== '';
$localBin = (string) ($settings['local']['binPath'] ?? '');

$queueStats = ['queued' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
$rs = $db->query('SELECT status, COUNT(*) AS n FROM tblTranscript GROUP BY status');
if ($rs !== false) {
    while ($r = $rs->fetch_assoc()) {
        $queueStats[(string) $r['status']] = (int) $r['n'];
    }
    $rs->free();
}

$monthSpend = Transcription::monthSpendPence();

$recent = [];
$rs = $db->query(
    'SELECT t.transcriptID, t.recordingID, t.provider, t.status, t.durationSec, t.costPence, '
    . '       t.generatedAt, t.errorMsg, r.title '
    . 'FROM tblTranscript t INNER JOIN tblRecording r ON r.recordingID = t.recordingID '
    . 'ORDER BY t.queuedAt DESC LIMIT 30'
);
if ($rs !== false) {
    while ($r = $rs->fetch_assoc()) {
        $recent[] = $r;
    }
    $rs->free();
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Transcription';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Transcription' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-closed-captioning me-2"></i>Transcription</h1>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card"><div class="card-body">
        <div class="small text-muted">Queued</div>
        <div class="display-6"><?php echo (int) $queueStats['queued']; ?></div>
    </div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body">
        <div class="small text-muted">Completed</div>
        <div class="display-6"><?php echo (int) $queueStats['completed']; ?></div>
    </div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body">
        <div class="small text-muted">Failed</div>
        <div class="display-6"><?php echo (int) $queueStats['failed']; ?></div>
    </div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body">
        <div class="small text-muted">Month spend</div>
        <div class="display-6">£<?php echo number_format($monthSpend / 100, 2); ?></div>
    </div></div></div>
</div>

<div class="card mb-3">
    <div class="card-header"><strong>Configuration</strong></div>
    <div class="card-body">
        <form method="post" action="/admin/transcription/save" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="col-md-3">
                <label class="form-label">Provider</label>
                <select class="form-select" name="provider">
                    <option value="openai"     <?php echo $provider === 'openai'     ? 'selected' : ''; ?>>OpenAI Whisper</option>
                    <option value="assemblyai" <?php echo $provider === 'assemblyai' ? 'selected' : ''; ?>>AssemblyAI</option>
                    <option value="local"      <?php echo $provider === 'local'      ? 'selected' : ''; ?>>Local whisper.cpp</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Language</label>
                <input type="text" class="form-control" name="language" value="<?php echo htmlspecialchars($language, ENT_QUOTES, 'UTF-8'); ?>" maxlength="10" placeholder="en">
            </div>
            <div class="col-md-2">
                <label class="form-label">Batch size</label>
                <input type="number" min="1" max="50" class="form-control" name="batchSize" value="<?php echo $batch; ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="tEnabled" name="enabled" value="1" <?php echo $enabled === true ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="tEnabled">Enable</label>
                </div>
            </div>
            <hr>
            <div class="col-md-6">
                <h6 class="text-muted">OpenAI Whisper</h6>
                <label class="form-label small">API key <?php echo $hasOaKey === true ? '<span class="badge bg-success">set</span>' : ''; ?></label>
                <input type="password" class="form-control form-control-sm" name="oa_key" placeholder="<?php echo $hasOaKey === true ? 'Leave blank to keep' : 'sk-…'; ?>" autocomplete="off">
                <label class="form-label small mt-2">Model</label>
                <input type="text" class="form-control form-control-sm" name="oa_model" value="<?php echo htmlspecialchars($oaModel, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-3">
                <h6 class="text-muted">AssemblyAI</h6>
                <label class="form-label small">API key <?php echo $hasAaKey === true ? '<span class="badge bg-success">set</span>' : ''; ?></label>
                <input type="password" class="form-control form-control-sm" name="aa_key" placeholder="<?php echo $hasAaKey === true ? 'Leave blank to keep' : ''; ?>" autocomplete="off">
            </div>
            <div class="col-md-3">
                <h6 class="text-muted">Local</h6>
                <label class="form-label small">whisper binary path</label>
                <input type="text" class="form-control form-control-sm" name="local_bin" value="<?php echo htmlspecialchars($localBin, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-12">
                <button class="btn btn-primary" type="submit">Save</button>
                <?php if ((int) $queueStats['queued'] > 0): ?>
                    <form method="post" action="/admin/transcription/run" class="d-inline ms-2">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <button class="btn btn-outline-success" type="submit">
                            <i class="fa-solid fa-play me-1"></i>Process next <?php echo (int) $batch; ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><strong>Recent transcripts</strong></div>
    <div class="card-body">
        <?php if (count($recent) === 0): ?>
            <p class="text-muted">No transcripts yet.</p>
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
                        <div class="col-md-4"><a href="/recordings/view?id=<?php echo (int) $t['recordingID']; ?>"><?php echo htmlspecialchars((string) $t['title'], ENT_QUOTES, 'UTF-8'); ?></a></div>
                        <div class="col-md-2"><span class="badge bg-<?php echo $cls; ?>"><?php echo htmlspecialchars((string) $t['status'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="col-md-2 text-muted"><?php echo htmlspecialchars((string) $t['provider'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-2 text-muted"><?php echo $t['durationSec'] !== null ? round(((int) $t['durationSec']) / 60, 1) . ' min' : '—'; ?></div>
                        <div class="col-md-2 text-end"><?php echo $t['costPence'] !== null ? 'p' . (int) $t['costPence'] : '—'; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
