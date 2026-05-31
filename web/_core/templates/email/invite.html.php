<?php
// Path: _core/templates/email/invite.html.php
// Vars: $inviterName, $portalName, $inviteUrl, $expiresAt, $message
declare(strict_types=1);
$inviterName = $inviterName ?? '';
$portalName  = $portalName  ?? 'the portal';
$inviteUrl   = $inviteUrl   ?? '#';
$expiresAt   = $expiresAt   ?? '';
$message     = $message     ?? '';
?>
<h1 style="font-size:18px;margin:0 0 12px;font-weight:600;">You're invited to <?php echo htmlspecialchars((string) $portalName, ENT_QUOTES, 'UTF-8'); ?></h1>
<?php if ($inviterName !== ''): ?>
    <p><?php echo htmlspecialchars((string) $inviterName, ENT_QUOTES, 'UTF-8'); ?> has invited you to join <?php echo htmlspecialchars((string) $portalName, ENT_QUOTES, 'UTF-8'); ?>.</p>
<?php else: ?>
    <p>You've been invited to join <?php echo htmlspecialchars((string) $portalName, ENT_QUOTES, 'UTF-8'); ?>.</p>
<?php endif; ?>
<?php if ($message !== ''): ?>
    <p style="background:#f4f5f7;padding:12px;border-left:3px solid #5e6ad2;border-radius:4px;margin:16px 0;">
        <?php echo nl2br(htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8')); ?>
    </p>
<?php endif; ?>
<p style="margin:24px 0;">
    <a href="<?php echo htmlspecialchars((string) $inviteUrl, ENT_QUOTES, 'UTF-8'); ?>"
       style="display:inline-block;padding:10px 20px;background:#5e6ad2;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:500;">
        Accept invite
    </a>
</p>
<?php if ($expiresAt !== ''): ?>
    <p style="font-size:13px;color:#6b7280;">This invite expires <?php echo htmlspecialchars((string) $expiresAt, ENT_QUOTES, 'UTF-8'); ?>.</p>
<?php endif; ?>
<p style="font-size:12px;color:#6b7280;word-break:break-all;">If the button doesn't work, paste this URL into your browser:<br>
    <?php echo htmlspecialchars((string) $inviteUrl, ENT_QUOTES, 'UTF-8'); ?>
</p>
