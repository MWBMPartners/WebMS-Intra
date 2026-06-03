<?php
// Path: public_html/admin/email-templates/preview.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Email Template preview 👁️
 * -----------------------------------------------------------------------------
 * Renders the submitted body with sample-token substitution so admins can
 * see roughly what the email will look like. Output is sandboxed:
 *
 *   - <iframe srcdoc> with sandbox="allow-same-origin" → no scripts run
 *     even if the admin pastes <script>... into the body.
 *   - {{token}} substitution happens AFTER outer htmlspecialchars on the
 *     body so admin-typed content stays escaped, but the sample-token
 *     values themselves are inserted as raw text (also escaped, just
 *     post-substitution so they read correctly).
 *
 * Wait — we WANT the HTML body to render as HTML, not be escaped. That's
 * what makes it a template editor. So escaping happens on the TOKENS
 * (admin-controlled key name → sample value) only, not on the body.
 *
 * That means an admin with template-edit privilege can write arbitrary
 * HTML. They're already a site admin so this is intentional, but
 * sandbox="" on the iframe stops any <script> from executing.
 *
 * @package   Portal\Admin
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/email-templates', true, 302);
    exit();
}
Auth::ensureSession();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(403);
    echo t('error.csrf_failed');
    exit();
}

$subject  = (string) ($_POST['subject']  ?? '');
$bodyHtml = (string) ($_POST['bodyHtml'] ?? '');

// 🌱 Sample token map. Real send-time tokens come from the caller; here we
// use plausible illustrative values so the preview looks right.
$samples = [
    'siteName'          => 'Sample Church',
    'userName'          => 'Sample User',
    'approverName'      => 'Sample Approver',
    'submitterName'     => 'Sample Submitter',
    'resetLink'         => 'https://example.org/reset-password?token=...',
    'expiryMinutes'     => '60',
    'claimRef'          => 'EXP-2026-0123',
    'claimDescription'  => 'Travel — May trip',
    'statusLabel'       => 'Approved',
    'decisionNote'      => 'Looks fine — thanks!',
    'claimLink'         => 'https://example.org/expenses/view?id=123',
    'claimAmount'       => '£123.45',
];

$rendered = $bodyHtml;
foreach ($samples as $k => $v) {
    $rendered = str_replace('{{' . $k . '}}', htmlspecialchars($v, ENT_QUOTES, 'UTF-8'), $rendered);
}

// 🔒 Strict CSP — sandbox is the primary defence but we belt-and-brace
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data: https:; frame-ancestors 'self'");
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Preview: <?php echo htmlspecialchars($subject, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
               margin: 0; padding: 24px; background: #f7f8fa; color: #1b2330; }
        .meta { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        .preview-frame { width: 100%; min-height: 400px; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; }
        .subject { font-size: 18px; font-weight: 600; }
        small { color: #6b7280; }
    </style>
</head>
<body>
    <div class="meta">
        <small>Preview — token substitution with sample values. <a href="javascript:window.close()">Close</a></small>
        <div class="subject"><?php echo htmlspecialchars($subject, ENT_QUOTES, 'UTF-8'); ?></div>
    </div>
    <iframe class="preview-frame"
            sandbox="allow-same-origin"
            srcdoc="<?php echo htmlspecialchars($rendered, ENT_QUOTES, 'UTF-8'); ?>"></iframe>
</body>
</html>
