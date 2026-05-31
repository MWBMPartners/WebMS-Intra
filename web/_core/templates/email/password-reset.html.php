<?php
// Path: _core/templates/email/password-reset.html.php
// Vars: $name, $resetUrl, $expiresInMinutes
declare(strict_types=1);
$name             = $name             ?? '';
$resetUrl         = $resetUrl         ?? '#';
$expiresInMinutes = $expiresInMinutes ?? 60;
?>
<h1 style="font-size:18px;margin:0 0 12px;font-weight:600;">Reset your password</h1>
<?php if ($name !== ''): ?>
    <p>Hi <?php echo htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8'); ?>,</p>
<?php endif; ?>
<p>We received a request to reset your portal password. To set a new password, click the button below.</p>
<p style="margin:24px 0;">
    <a href="<?php echo htmlspecialchars((string) $resetUrl, ENT_QUOTES, 'UTF-8'); ?>"
       style="display:inline-block;padding:10px 20px;background:#5e6ad2;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:500;">
        Reset password
    </a>
</p>
<p style="font-size:13px;color:#6b7280;">
    This link expires in <?php echo (int) $expiresInMinutes; ?> minutes.
    If you didn't request this, you can safely ignore this email — your password won't change.
</p>
<p style="font-size:12px;color:#6b7280;word-break:break-all;">
    If the button doesn't work, paste this URL into your browser:<br>
    <?php echo htmlspecialchars((string) $resetUrl, ENT_QUOTES, 'UTF-8'); ?>
</p>
