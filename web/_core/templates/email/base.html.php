<?php
// Path: _core/templates/email/base.html.php
/**
 * -----------------------------------------------------------------------------
 * Email base layout 📨
 * -----------------------------------------------------------------------------
 * Wraps all transactional emails in a portal-branded HTML shell. All CSS is
 * inline because most email clients strip <style>. Single-column layout
 * renders cleanly on both desktop and mobile.
 *
 * Variables expected from Mailer::renderBase():
 *   $content      string  — body HTML (already escaped/wrapped)
 *   $portalName   string  — site name
 *   $portalUrl    string  — site URL (omit footer link if empty)
 *   $supportEmail string  — support email (omit footer link if empty)
 *
 * @see   https://github.com/MWBMPartners/WebMS-Intra/issues/243
 * -----------------------------------------------------------------------------
 */
declare(strict_types=1);
// 🏷️ Resolve product brand if caller didn't supply one (#296).
//    Site::productName() reads from $SETTINGS['product']['name'] →
//    PORTAL_PRODUCT_NAME_DEFAULT → final hardcoded fallback.
$portalName   = $portalName   ?? (class_exists(\Portal\Core\Site::class) === true ? \Portal\Core\Site::productName() : 'WebMS Intra');
$portalUrl    = $portalUrl    ?? '';
$supportEmail = $supportEmail ?? '';
$content      = $content      ?? '';
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo htmlspecialchars($portalName, ENT_QUOTES, 'UTF-8'); ?></title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1b2330;line-height:1.55;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f4f5f7;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;background:#ffffff;border-radius:8px;border:1px solid #e5e7eb;box-shadow:0 1px 3px rgba(16,24,40,.06);">
                    <!-- Header -->
                    <tr>
                        <td style="padding:20px 24px;border-bottom:1px solid #eef0fb;">
                            <div style="font-size:18px;font-weight:600;color:#5e6ad2;letter-spacing:-.01em;">
                                <?php echo htmlspecialchars($portalName, ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style="padding:24px;font-size:15px;color:#1b2330;">
                            <?php echo $content; ?>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="padding:16px 24px;border-top:1px solid #eef0fb;font-size:12px;color:#6b7280;">
                            <?php echo htmlspecialchars($portalName, ENT_QUOTES, 'UTF-8'); ?>
                            <?php if ($portalUrl !== ''): ?>
                                &middot; <a href="<?php echo htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8'); ?>" style="color:#5e6ad2;text-decoration:none;">Visit the portal</a>
                            <?php endif; ?>
                            <?php if ($supportEmail !== ''): ?>
                                &middot; <a href="mailto:<?php echo htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8'); ?>" style="color:#5e6ad2;text-decoration:none;">Support</a>
                            <?php endif; ?>
                            <div style="margin-top:8px;">
                                This is an automated email. Please don't reply directly.
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
