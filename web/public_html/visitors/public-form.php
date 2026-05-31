<?php
// Path: public_html/visitors/public-form.php
/**
 * Visitor Tracking — public self-capture form at /visit.
 *
 * Renders WITHOUT login when visitors.public_capture_enabled = '1'.
 * Captcha-gated (reuses existing captcha integration).
 *
 * @package   Portal\Visitors
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/258
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Site;

$siteId = Site::id();
$enabled = (string) (App::settings()['visitors']['public_capture_enabled'] ?? '0') === '1';

if ($enabled === false) {
    http_response_code(404);
    exit('Not found');
}

$flash = '';
$flashType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim((string) ($_POST['fullName'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));

    // 🛡️ Captcha — uses existing portal captcha if configured.
    $captchaOk = true;
    if (class_exists('Portal\\Core\\Captcha') === true
        && method_exists('Portal\\Core\\Captcha', 'verify') === true
    ) {
        $captchaOk = \Portal\Core\Captcha::verify($_POST);
    }

    if ($name === '' || ($email === '' && $phone === '')) {
        $flash = 'Please give your name and at least one of email/phone.';
        $flashType = 'danger';
    } elseif ($captchaOk === false) {
        $flash = 'Verification failed — please try again.';
        $flashType = 'danger';
    } else {
        try {
            $db = App::db();
            $source = 'public-form';
            $em = $email !== '' ? $email : null;
            $ph = $phone !== '' ? $phone : null;
            $nt = $notes !== '' ? $notes : null;
            $stmt = $db->prepare(
                'INSERT INTO tblVisitor (siteID, fullName, email, phone, source, notes) VALUES (?, ?, ?, ?, ?, ?)'
            );
            if ($stmt !== false) {
                $stmt->bind_param('isssss', $siteId, $name, $em, $ph, $source, $nt);
                $stmt->execute();
                $stmt->close();
            }
            $flash = 'Thanks! Someone will be in touch within the next week.';
            $flashType = 'success';
        } catch (\Throwable $e) {
            $flash = 'Sorry — something went wrong. Please try again later.';
            $flashType = 'danger';
        }
    }
}

$portalName = htmlspecialchars((string) (App::settings()['site']['name'] ?? 'our portal'), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Welcome — <?php echo $portalName; ?></title>
<style>
:root{--bg:#f7f8fa;--surface:#fff;--text:#1b2330;--muted:#6b7280;--border:#e5e7eb;--primary:#5e6ad2;}
@media (prefers-color-scheme: dark){:root{--bg:#0f1115;--surface:#161a22;--text:#e8eaf0;--muted:#9aa3b2;--border:#2c3441;}}
body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--text);margin:0;padding:2rem 1rem;}
.card{max-width:480px;margin:0 auto;background:var(--surface);border:1px solid var(--border);border-radius:.75rem;padding:1.5rem;}
h1{margin-top:0;}
label{display:block;font-size:.875rem;margin:.75rem 0 .25rem;}
input,textarea{width:100%;padding:.5rem;border:1px solid var(--border);border-radius:.375rem;background:var(--surface);color:var(--text);box-sizing:border-box;}
button{margin-top:1rem;padding:.625rem 1.25rem;background:var(--primary);color:#fff;border:none;border-radius:.375rem;font-weight:500;cursor:pointer;}
.flash{padding:.5rem;border-radius:.375rem;margin-bottom:1rem;}
.flash-success{background:#d1fae5;color:#065f46;}
.flash-danger{background:#fee2e2;color:#991b1b;}
.flash-info{background:#dbeafe;color:#1e40af;}
</style>
</head>
<body>
<div class="card">
    <h1>Welcome to <?php echo $portalName; ?></h1>
    <p>Glad to have you visit. Share a quick way to reach you and we'll be in touch.</p>
    <?php if ($flash !== ''): ?>
        <div class="flash flash-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($flashType !== 'success'): ?>
        <form method="post">
            <label>Your name</label>
            <input type="text" name="fullName" required maxlength="255">
            <label>Email</label>
            <input type="email" name="email" maxlength="255">
            <label>Phone</label>
            <input type="tel" name="phone" maxlength="50">
            <label>Anything you'd like us to know? (optional)</label>
            <textarea name="notes" rows="3"></textarea>
            <button type="submit">Send</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
