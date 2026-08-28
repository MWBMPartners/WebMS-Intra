<?php
// Path: public_html/admin/integrations/email.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Email Deliverability 📧
 * -----------------------------------------------------------------------------
 * Active provider summary, test-send, and SPF/DKIM/DMARC DNS check for the
 * configured sender domain. Pairs with #234 (MS365 Graph shared-mailbox
 * sending) and #229 (Critical alerting). Active provider + effective
 * sender now read from Mailer::provider() / the real mail.* settings
 * (was reading dead email.provider/email.from keys — #234 fold-in fix);
 * recent-sends list added from the new tblEmailLog audit trail.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/234
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Mailer;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

$flash     = '';
$flashType = 'info';
$settings  = App::settings();

// 🩹 #234 fold-in fix — `email.provider` / `email.from` are a DEAD settings
// vocabulary (seeded but never written by any save handler in this
// codebase), so this page used to always report "smtp" while the portal
// actually sends via Microsoft Graph or the Gmail API. Report the REAL
// active provider + effective sender instead — mirrors
// Mailer::effectiveSender()'s resolution order (display-only here; the
// real resolution lives in _core/Mailer.php).
$activeProvider = Mailer::provider();
if ($activeProvider === 'google') {
    $senderEmail = (string) ($settings['mail']['google']['delegateUser'] ?? '');
} else {
    $sharedMailbox = trim((string) ($settings['mail']['ms365']['sharedMailbox'] ?? ''));
    $senderEmail   = $sharedMailbox !== '' ? $sharedMailbox : (string) ($settings['mail']['defaultFromAddress'] ?? '');
}
$senderDomain = '';
if ($senderEmail !== '' && str_contains($senderEmail, '@')) {
    $senderDomain = substr($senderEmail, strpos($senderEmail, '@') + 1);
}

// 📨 Test-send handler
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && Auth::verifyCsrf($_POST['csrf_token'] ?? '') === true
    && ($_POST['action'] ?? '') === 'test_send'
) {
    $to      = trim((string) ($_POST['to'] ?? ''));
    $subject = trim((string) ($_POST['subject'] ?? '[Test] WebMS Intra deliverability check'));
    $body    = trim((string) ($_POST['body'] ?? 'This is a test email. If you received this, deliverability for the configured provider is working.'));

    if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        $flash     = 'Provide a valid recipient email.';
        $flashType = 'danger';
    } else {
        $ok = false;
        try {
            if (class_exists('Portal\\Core\\Mailer') === true) {
                $ok = \Portal\Core\Mailer::send($to, $subject, $body);
            } else {
                $ok = @mail($to, $subject, $body);
            }
        } catch (\Throwable $e) {
            $flash     = 'Send failed: ' . $e->getMessage();
            $flashType = 'danger';
        }
        if ($flash === '') {
            $flash     = $ok === true ? 'Test email sent to ' . $to . '.' : 'Mailer returned false.';
            $flashType = $ok === true ? 'success' : 'warning';
        }
    }
}

// 🛡️ DNS posture probe — SPF / DKIM / DMARC
$dnsResults = [];
if ($senderDomain !== '') {
    // SPF
    $txt = @dns_get_record($senderDomain, DNS_TXT);
    $spf = '';
    if (is_array($txt) === true) {
        foreach ($txt as $r) {
            $t = (string) ($r['txt'] ?? '');
            if (stripos($t, 'v=spf1') === 0) {
                $spf = $t;
                break;
            }
        }
    }
    $dnsResults['SPF'] = [
        'state'  => $spf !== '' ? 'ok' : 'warn',
        'value'  => $spf !== '' ? $spf : 'Not found',
    ];

    // DMARC (lives at _dmarc.{domain})
    $dtxt = @dns_get_record('_dmarc.' . $senderDomain, DNS_TXT);
    $dmarc = '';
    if (is_array($dtxt) === true) {
        foreach ($dtxt as $r) {
            $t = (string) ($r['txt'] ?? '');
            if (stripos($t, 'v=DMARC1') === 0) {
                $dmarc = $t;
                break;
            }
        }
    }
    $dnsResults['DMARC'] = [
        'state'  => $dmarc !== '' ? 'ok' : 'warn',
        'value'  => $dmarc !== '' ? $dmarc : 'Not found',
    ];

    // DKIM — selector is provider-specific. We probe two common selectors
    // (`default` and `selector1`). If the provider uses a custom selector,
    // surface guidance rather than a green check.
    $dkimChecks = [];
    foreach (['default', 'selector1'] as $sel) {
        $dtxt = @dns_get_record($sel . '._domainkey.' . $senderDomain, DNS_TXT);
        $dkim = '';
        if (is_array($dtxt) === true) {
            foreach ($dtxt as $r) {
                $t = (string) ($r['txt'] ?? '');
                if (stripos($t, 'v=DKIM1') === 0 || stripos($t, 'k=') !== false) {
                    $dkim = $t;
                    break;
                }
            }
        }
        if ($dkim !== '') {
            $dkimChecks[] = $sel . ': present';
        }
    }
    $dnsResults['DKIM'] = [
        'state'  => count($dkimChecks) > 0 ? 'ok' : 'warn',
        'value'  => count($dkimChecks) > 0 ? implode(' · ', $dkimChecks) : 'No DKIM record at common selectors (default/selector1)',
    ];
}

// 📜 #234 — recent tblEmailLog rows (both providers) for the audit-trail
// list below. Global mail settings, so no site filter — matches the
// existing pattern for the mail integration cards on this page.
$recentSends = [];
$rsStmt = $mysqli->prepare(
    'SELECT provider, fromAddress, toRecipients, subject, status, httpCode, errorCode, sentAt ' .
    'FROM tblEmailLog ORDER BY emailLogID DESC LIMIT 10'
);
if ($rsStmt !== false) {
    $rsStmt->execute();
    $rsResult = $rsStmt->get_result();
    while ($row = $rsResult->fetch_assoc()) {
        $recentSends[] = $row;
    }
    $rsStmt->close();
}

$pageTitle   = 'Email Deliverability';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Integrations' => '/admin/integrations', 'Email' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-envelope me-2"></i>Email Deliverability</h1>
        <p class="text-secondary mb-0">Active provider, test send, SPF/DKIM/DMARC posture.</p>
    </div>
    <a href="/admin/integrations" class="btn btn-outline-secondary btn-sm">&larr; Integrations</a>
</div>

<?php if ($flash !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-body">
                <h2 class="h5">Active provider</h2>
                <p class="mb-1"><strong><?php echo htmlspecialchars(strtoupper($activeProvider), ENT_QUOTES, 'UTF-8'); ?></strong></p>
                <p class="small text-muted mb-0">
                    From: <code><?php echo htmlspecialchars($senderEmail !== '' ? $senderEmail : '(not configured)', ENT_QUOTES, 'UTF-8'); ?></code>
                </p>
                <p class="small text-muted mt-2 mb-0">
                    Change the mail provider at <code>mail.provider</code> in <a href="/admin/settings">/admin/settings</a>,
                    or the shared-mailbox identity at <a href="/admin/integrations">/admin/integrations</a> (#234).
                </p>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-body">
                <h2 class="h5">Send test</h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="test_send">
                    <div class="mb-2">
                        <label class="form-label small">To</label>
                        <input type="email" name="to" class="form-control form-control-sm" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Subject</label>
                        <input type="text" name="subject" class="form-control form-control-sm" value="[Test] WebMS Intra deliverability check">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Send test</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-body">
        <h2 class="h5">DNS posture (<?php echo htmlspecialchars($senderDomain !== '' ? $senderDomain : '(no sender domain configured)', ENT_QUOTES, 'UTF-8'); ?>)</h2>
        <?php if ($senderDomain === ''): ?>
            <p class="text-muted mb-0">Configure <code>email.from</code> before this probe can run.</p>
        <?php else: ?>
            <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th>Record</th><th>Status</th><th>Value</th></tr></thead>
                <tbody>
                    <?php foreach ($dnsResults as $name => $r):
                        $cls = $r['state'] === 'ok' ? 'success' : 'warning';
                    ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                            <td><span class="badge bg-<?php echo $cls; ?>"><?php echo strtoupper($r['state']); ?></span></td>
                            <td class="small"><code><?php echo htmlspecialchars($r['value'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <p class="small text-muted mb-0">Missing SPF/DKIM/DMARC causes mail to land in spam. DreamHost docs cover the SPF and DKIM records to add for shared hosting.</p>
        <?php endif; ?>
    </div>
</div>

<!-- 📜 #234 — recent sends, both providers. House rule: no <table> for data
     display (portal-data-list); the DNS table above predates that rule. -->
<div class="card mt-3">
    <div class="card-body">
        <h2 class="h5">Recent sends (last 10)</h2>
        <?php if (count($recentSends) === 0): ?>
            <p class="small text-muted mb-0">No sends logged yet.</p>
        <?php else: ?>
            <div class="portal-data-list">
                <?php foreach ($recentSends as $row): ?>
                    <div class="portal-data-row">
                        <div class="col-12 col-md-2">
                            <span class="badge bg-<?php echo ((string) $row['status'] === 'sent') ? 'success' : 'danger'; ?>">
                                <?php echo htmlspecialchars(strtoupper((string) $row['status']), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                        <div class="col-12 col-md-2 small text-muted"><?php echo htmlspecialchars((string) $row['provider'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-12 col-md-3 small text-truncate" title="<?php echo htmlspecialchars((string) $row['toRecipients'], ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars((string) $row['toRecipients'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <div class="col-12 col-md-3 small text-truncate"><?php echo htmlspecialchars((string) $row['subject'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-12 col-md-2 small text-muted">
                            <?php echo htmlspecialchars((string) $row['sentAt'], ENT_QUOTES, 'UTF-8'); ?>
                            <?php if ((string) $row['status'] === 'failed' && (string) $row['errorCode'] !== ''): ?>
                                <br><code class="small"><?php echo htmlspecialchars((string) $row['errorCode'], ENT_QUOTES, 'UTF-8'); ?></code>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-3">
    <div class="card-body">
        <h2 class="h5">Critical-error alert recipients (#229)</h2>
        <p class="small text-muted">
            Comma-separated email addresses receive automated alerts when the Logger writes a Critical or Fatal error.
            Rate-limited per fingerprint (default 30-minute cooldown).
            Edit at <code>portal.alerts.recipients</code> + <code>portal.alerts.severities</code> + <code>portal.alerts.cooldown_minutes</code> in <a href="/admin/settings">/admin/settings</a>.
        </p>
        <p class="small mb-0">Current: <code><?php echo htmlspecialchars((string) ($settings['portal']['alerts']['recipients'] ?? '(empty — alerts disabled)'), ENT_QUOTES, 'UTF-8'); ?></code></p>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
