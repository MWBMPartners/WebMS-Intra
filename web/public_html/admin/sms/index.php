<?php
// Path: public_html/admin/sms/index.php
/**
 * Admin — SMS usage dashboard + provider config.
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/272
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\Sms;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$db       = App::db();
$siteId   = Site::id();
$settings = App::settings()['sms'] ?? [];
$enabled  = (string) ($settings['enabled'] ?? '0') === '1';
$provider = (string) ($settings['provider'] ?? 'twilio');
$dailyCap = (int) ($settings['dailyCap'] ?? 100);
$fromNum  = (string) ($settings['fromNumber'] ?? '');

$twilioSid   = (string) ($settings['twilio']['sid'] ?? '');
$hasTwToken  = ((string) ($settings['twilio']['token'] ?? '')) !== '';
$hasMbKey    = ((string) ($settings['messagebird']['apiKey'] ?? '')) !== '';
$awsKey      = (string) ($settings['aws']['accessKey'] ?? '');
$hasAwsSec   = ((string) ($settings['aws']['secret'] ?? '')) !== '';
$awsRegion   = (string) ($settings['aws']['region'] ?? 'eu-west-1');

$sentToday   = Sms::sentTodayCount($siteId);
$monthSpend  = Sms::monthSpendPence($siteId);

$recent = [];
$stmt = $db->prepare(
    'SELECT m.messageID, m.recipientNumber, m.body, m.category, m.status, m.costPence, m.createdAt, '
    . '       u.fullName '
    . 'FROM tblSmsMessage m LEFT JOIN tblUsers u ON u.userID = m.recipientUserID '
    . 'WHERE m.siteID = ? ORDER BY m.createdAt DESC LIMIT 50'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $recent[] = $r;
    }
    $stmt->close();
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'SMS';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'SMS' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-comment-sms me-2"></i>SMS</h1>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card"><div class="card-body">
            <div class="small text-muted">Sent today</div>
            <div class="display-6"><?php echo (int) $sentToday; ?> <span class="fs-6 text-muted">/ <?php echo (int) $dailyCap; ?> cap</span></div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card"><div class="card-body">
            <div class="small text-muted">This month's spend</div>
            <div class="display-6">£<?php echo number_format($monthSpend / 100, 2); ?></div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card"><div class="card-body">
            <div class="small text-muted">Provider</div>
            <div class="display-6"><?php echo htmlspecialchars($provider, ENT_QUOTES, 'UTF-8'); ?></div>
        </div></div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><strong>Provider configuration</strong></div>
    <div class="card-body">
        <form method="post" action="/admin/sms/save" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="col-md-3">
                <label class="form-label">Provider</label>
                <select class="form-select" name="provider">
                    <option value="twilio"      <?php echo $provider === 'twilio'      ? 'selected' : ''; ?>>Twilio</option>
                    <option value="messagebird" <?php echo $provider === 'messagebird' ? 'selected' : ''; ?>>MessageBird</option>
                    <option value="aws"         <?php echo $provider === 'aws'         ? 'selected' : ''; ?>>AWS SNS</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">From number</label>
                <input type="text" class="form-control" name="fromNumber" value="<?php echo htmlspecialchars($fromNum, ENT_QUOTES, 'UTF-8'); ?>" placeholder="+447700000000">
            </div>
            <div class="col-md-2">
                <label class="form-label">Daily cap</label>
                <input type="number" min="1" class="form-control" name="dailyCap" value="<?php echo (int) $dailyCap; ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="smsEnabled" name="enabled" value="1" <?php echo $enabled === true ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="smsEnabled">Enable SMS</label>
                </div>
            </div>
            <hr>
            <div class="col-md-6">
                <h6 class="text-muted">Twilio</h6>
                <label class="form-label small">Account SID</label>
                <input type="text" class="form-control form-control-sm" name="twilio_sid" value="<?php echo htmlspecialchars($twilioSid, ENT_QUOTES, 'UTF-8'); ?>">
                <label class="form-label small mt-2">Auth Token <?php echo $hasTwToken === true ? '<span class="badge bg-success">set</span>' : ''; ?></label>
                <input type="password" class="form-control form-control-sm" name="twilio_token" placeholder="<?php echo $hasTwToken === true ? 'Leave blank to keep' : ''; ?>" autocomplete="off">
            </div>
            <div class="col-md-6">
                <h6 class="text-muted">MessageBird</h6>
                <label class="form-label small">API key <?php echo $hasMbKey === true ? '<span class="badge bg-success">set</span>' : ''; ?></label>
                <input type="password" class="form-control form-control-sm" name="mb_key" placeholder="<?php echo $hasMbKey === true ? 'Leave blank to keep' : ''; ?>" autocomplete="off">
            </div>
            <div class="col-md-6">
                <h6 class="text-muted">AWS SNS</h6>
                <label class="form-label small">Access key</label>
                <input type="text" class="form-control form-control-sm" name="aws_key" value="<?php echo htmlspecialchars($awsKey, ENT_QUOTES, 'UTF-8'); ?>">
                <label class="form-label small mt-2">Secret <?php echo $hasAwsSec === true ? '<span class="badge bg-success">set</span>' : ''; ?></label>
                <input type="password" class="form-control form-control-sm" name="aws_secret" placeholder="<?php echo $hasAwsSec === true ? 'Leave blank to keep' : ''; ?>" autocomplete="off">
                <label class="form-label small mt-2">Region</label>
                <input type="text" class="form-control form-control-sm" name="aws_region" value="<?php echo htmlspecialchars($awsRegion, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-12">
                <button class="btn btn-primary" type="submit">Save</button>
                <a href="/admin/sms/send" class="btn btn-outline-secondary ms-2">Compose broadcast →</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><strong>Recent messages</strong></div>
    <div class="card-body">
        <?php if (count($recent) === 0): ?>
            <p class="text-muted">No SMS sent yet.</p>
        <?php else: ?>
            <div class="portal-data-list">
                <?php foreach ($recent as $m):
                    $cls = match ((string) $m['status']) {
                        'sent','delivered' => 'success',
                        'failed'           => 'danger',
                        default            => 'secondary',
                    };
                ?>
                    <div class="row py-1 border-bottom small">
                        <div class="col-md-2 text-muted"><?php echo htmlspecialchars(date('d/m H:i', (int) strtotime((string) $m['createdAt'])), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-3"><?php echo htmlspecialchars((string) ($m['fullName'] ?? $m['recipientNumber']), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-2"><span class="badge bg-light text-dark"><?php echo htmlspecialchars((string) $m['category'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="col-md-3 text-truncate" title="<?php echo htmlspecialchars((string) $m['body'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(mb_substr((string) $m['body'], 0, 80), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-1"><span class="badge bg-<?php echo $cls; ?>"><?php echo htmlspecialchars((string) $m['status'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="col-md-1 text-end small text-muted"><?php echo $m['costPence'] !== null ? 'p' . (int) $m['costPence'] : '—'; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
