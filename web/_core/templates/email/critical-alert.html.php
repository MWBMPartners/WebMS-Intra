<?php
// Path: _core/templates/email/critical-alert.html.php
// Vars: $severity, $platform, $code, $title, $detail, $url
declare(strict_types=1);
$severity = $severity ?? 'Critical';
$platform = $platform ?? '';
$code     = $code     ?? '';
$title    = $title    ?? '';
$detail   = $detail   ?? '';
$url      = $url      ?? '';
?>
<h1 style="font-size:18px;margin:0 0 12px;font-weight:600;color:#b42318;">
    <?php echo htmlspecialchars((string) $severity, ENT_QUOTES, 'UTF-8'); ?> alert
</h1>
<p><strong>Title:</strong> <?php echo htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8'); ?></p>
<table role="presentation" cellspacing="0" cellpadding="6" border="0" style="border-collapse:collapse;margin:12px 0;font-size:13px;">
    <tr><td style="color:#6b7280;">Platform</td><td><?php echo htmlspecialchars((string) $platform, ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><td style="color:#6b7280;">Code</td><td><?php echo htmlspecialchars((string) $code, ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <?php if ($url !== ''): ?>
        <tr><td style="color:#6b7280;">URL</td><td><?php echo htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <?php endif; ?>
</table>
<?php if (trim((string) $detail) !== ''): ?>
    <p style="margin-bottom:6px;color:#6b7280;font-size:13px;">Detail:</p>
    <pre style="background:#f4f5f7;padding:10px;border-radius:6px;font-size:12px;white-space:pre-wrap;word-break:break-word;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">
<?php echo htmlspecialchars(substr((string) $detail, 0, 2000), ENT_QUOTES, 'UTF-8'); ?>
    </pre>
<?php endif; ?>
<p style="font-size:12px;color:#6b7280;">Rate-limited by error fingerprint. Repeated occurrences won't re-send within the configured cooldown.</p>
