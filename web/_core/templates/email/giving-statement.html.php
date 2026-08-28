<?php
// Path: _core/templates/email/giving-statement.html.php
// Vars: $donorName, $firstName, $periodLabel, $charityName, $charityNumber, $portalUrl
declare(strict_types=1);
$donorName     = $donorName     ?? '';
$firstName     = $firstName     ?? '';
$periodLabel   = $periodLabel   ?? '';
$charityName   = $charityName   ?? '';
$charityNumber = $charityNumber ?? '';
$portalUrl     = $portalUrl     ?? '#';
?>
<h1 style="font-size:18px;margin:0 0 12px;font-weight:600;">Your <?php echo htmlspecialchars((string) $periodLabel, ENT_QUOTES, 'UTF-8'); ?> giving statement</h1>
<p>Dear <?php echo htmlspecialchars((string) ($firstName !== '' ? $firstName : $donorName), ENT_QUOTES, 'UTF-8'); ?>,</p>
<p>
    Thank you for your generosity. Attached is your giving statement for
    <strong><?php echo htmlspecialchars((string) $periodLabel, ENT_QUOTES, 'UTF-8'); ?></strong>,
    covering every gift recorded against your name<?php echo $charityName !== '' ? ' at ' . htmlspecialchars((string) $charityName, ENT_QUOTES, 'UTF-8') : ''; ?>.
</p>
<p>
    The statement shows your total giving for the period and, separately, the
    portion covered by an active Gift Aid declaration on file — please retain
    a copy for your own records.
</p>
<?php if ($charityNumber !== ''): ?>
    <p style="font-size:13px;color:#6b7280;">
        <?php echo htmlspecialchars((string) $charityName, ENT_QUOTES, 'UTF-8'); ?> — Registered Charity No. <?php echo htmlspecialchars((string) $charityNumber, ENT_QUOTES, 'UTF-8'); ?>
    </p>
<?php endif; ?>
<p style="margin:24px 0;">
    <a href="<?php echo htmlspecialchars((string) $portalUrl, ENT_QUOTES, 'UTF-8'); ?>"
       style="display:inline-block;padding:10px 20px;background:#5e6ad2;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:500;">
        View Giving
    </a>
</p>
<p style="font-size:12px;color:#6b7280;">
    If you believe this statement is incorrect, or you'd rather not receive
    these in future, you can change your preference at any time from your
    account's notification settings.
</p>
