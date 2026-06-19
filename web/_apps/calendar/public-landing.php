<?php
// Path: _apps/calendar/public-landing.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Per-event public landing page 📣 (#346)
 * -----------------------------------------------------------------------------
 * Brand-aware short URL /e/<slug>. Renders a single-purpose marketing page:
 *   • Hero with event name + tagline + brand background
 *   • Live countdown widget (CSS + a tiny JS tick)
 *   • Date / time / location
 *   • Big "Register / RSVP" CTA (deep-link to /calendar/event?slug=…)
 *   • QR code (via Google Charts QR API for v1; self-hosted in v1.1)
 *   • Brand attribution footer
 *
 * Different from /calendar/event?slug=… (which is the data-rich detail
 * page inside the portal chrome). This one has NO portal nav — it's a
 * standalone landing target you can print on a flyer or share in WhatsApp.
 *
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/346
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Settings;
use Portal\Core\Site;

$slug = (string) ($_GET['slug'] ?? '');
if ($slug === '' || preg_match('/^[a-z0-9][a-z0-9\-]{0,79}$/i', $slug) !== 1) {
    http_response_code(400); exit('Invalid slug.');
}

$siteId = Site::id();

$stmt = $mysqli->prepare(
    'SELECT eventID, eventName, eventSlug, description, startDateTime, endDateTime, '
    . '       locationName, locationAddress, status, capacityCount '
    . 'FROM tblEvents WHERE eventSlug = ? AND siteID = ? AND isDeleted = 0 AND status = "published" LIMIT 1'
);
$stmt->bind_param('si', $slug, $siteId);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

if ($event === null) { http_response_code(404); exit('Event not found.'); }

$showQr        = ((string) Settings::get('public_landing.show_qr',        '1')) === '1';
$showCountdown = ((string) Settings::get('public_landing.show_countdown', '1')) === '1';
$brandName     = method_exists(Site::class, 'productName') === true ? (string) Site::productName() : 'Portal';
$siteName      = (string) Site::name();
$primaryColor  = (string) (Site::branding()['primaryColor'] ?? '#5e6ad2');

$startTs   = strtotime((string) $event['startDateTime']);
$startIso  = date('c', $startTs);
$startNice = date('l j F Y, H:i', $startTs);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$publicUrl = $scheme . '://' . $host . '/e/' . urlencode((string) $event['eventSlug']);
$rsvpUrl   = '/calendar/event?slug=' . urlencode((string) $event['eventSlug']);
$qrSrc     = 'https://chart.googleapis.com/chart?cht=qr&chs=200x200&choe=UTF-8&chl=' . urlencode($publicUrl);

$eventNameSafe = htmlspecialchars((string) $event['eventName'], ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $eventNameSafe; ?> &middot; <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars(mb_substr((string) ($event['description'] ?? ''), 0, 160), ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:title" content="<?php echo $eventNameSafe; ?>">
    <meta property="og:type"  content="event">
    <meta property="og:url"   content="<?php echo htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, sans-serif; background: #f8f9fa; }
        .landing-hero {
            background: linear-gradient(135deg, <?php echo htmlspecialchars($primaryColor, ENT_QUOTES, 'UTF-8'); ?> 0%,
                        color-mix(in srgb, <?php echo htmlspecialchars($primaryColor, ENT_QUOTES, 'UTF-8'); ?> 70%, #000 30%) 100%);
            color: #fff; padding: 4rem 1rem; text-align: center;
        }
        .landing-hero h1 { font-size: clamp(2rem, 6vw, 4rem); margin: 0 0 .5rem; font-weight: 800; }
        .landing-hero p.tagline { font-size: 1.25rem; opacity: .9; margin: 0 0 2rem; }
        .countdown { display: flex; gap: 1rem; justify-content: center; margin: 2rem 0; flex-wrap: wrap; }
        .countdown .box { background: rgba(255,255,255,.15); padding: 1rem 1.5rem; border-radius: 8px; min-width: 80px; }
        .countdown .num { font-size: 2.5rem; font-weight: 800; display: block; line-height: 1; }
        .countdown .label { font-size: .75rem; text-transform: uppercase; letter-spacing: 1px; opacity: .8; }
        .cta-btn {
            display: inline-block; background: #fff;
            color: <?php echo htmlspecialchars($primaryColor, ENT_QUOTES, 'UTF-8'); ?>;
            padding: 1rem 2.5rem; border-radius: 50px; text-decoration: none;
            font-size: 1.25rem; font-weight: 700; margin-top: 1.5rem;
            box-shadow: 0 4px 12px rgba(0,0,0,.15);
        }
        .cta-btn:hover { transform: translateY(-2px); transition: transform .15s; }
        .landing-body { max-width: 720px; margin: 2rem auto; padding: 0 1rem; }
        .info-card { background: #fff; padding: 1.5rem; border-radius: 8px; margin-bottom: 1rem; box-shadow: 0 1px 4px rgba(0,0,0,.05); }
        .info-card h2 { margin-top: 0; font-size: 1.1rem; color: <?php echo htmlspecialchars($primaryColor, ENT_QUOTES, 'UTF-8'); ?>; }
        .qr-block { text-align: center; padding: 2rem; }
        .qr-block img { width: 180px; height: 180px; }
        .landing-footer { text-align: center; padding: 2rem 1rem; color: #6c757d; font-size: .85rem; }
    </style>
</head>
<body>

<section class="landing-hero">
    <h1><?php echo $eventNameSafe; ?></h1>
    <p class="tagline"><?php echo htmlspecialchars($startNice, ENT_QUOTES, 'UTF-8'); ?></p>

    <?php if ($showCountdown === true && $startTs > time()): ?>
    <div class="countdown" data-target="<?php echo htmlspecialchars($startIso, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="box"><span class="num" data-unit="days">--</span><span class="label">Days</span></div>
        <div class="box"><span class="num" data-unit="hours">--</span><span class="label">Hours</span></div>
        <div class="box"><span class="num" data-unit="mins">--</span><span class="label">Mins</span></div>
        <div class="box"><span class="num" data-unit="secs">--</span><span class="label">Secs</span></div>
    </div>
    <?php endif; ?>

    <a href="<?php echo htmlspecialchars($rsvpUrl, ENT_QUOTES, 'UTF-8'); ?>" class="cta-btn">
        <i class="fa-solid fa-arrow-right"></i> Register now
    </a>
</section>

<main class="landing-body">
    <?php if (!empty($event['description'])): ?>
        <div class="info-card">
            <h2>About</h2>
            <p><?php echo nl2br(htmlspecialchars((string) $event['description'], ENT_QUOTES, 'UTF-8')); ?></p>
        </div>
    <?php endif; ?>

    <?php if (!empty($event['locationName'])): ?>
        <div class="info-card">
            <h2><i class="fa-solid fa-location-dot me-1"></i>Where</h2>
            <p>
                <strong><?php echo htmlspecialchars((string) $event['locationName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                <?php if (!empty($event['locationAddress'])): ?>
                    <br><?php echo nl2br(htmlspecialchars((string) $event['locationAddress'], ENT_QUOTES, 'UTF-8')); ?>
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($showQr === true): ?>
        <div class="info-card qr-block">
            <h2>Share this event</h2>
            <p class="small">Anyone with a phone camera can scan to open this page.</p>
            <img src="<?php echo htmlspecialchars($qrSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="QR code linking to <?php echo $eventNameSafe; ?>" loading="lazy">
            <p class="small" style="word-break:break-all; margin-top:1rem;"><?php echo htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
    <?php endif; ?>
</main>

<footer class="landing-footer">
    <p>Hosted by <strong><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></strong>
       &middot; Powered by <?php echo htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8'); ?></p>
</footer>

<?php if ($showCountdown === true && $startTs > time()): ?>
<script>
(function() {
    const el = document.querySelector('.countdown');
    if (!el) return;
    const target = new Date(el.dataset.target).getTime();
    function tick() {
        const diff = Math.max(0, target - Date.now());
        const s = Math.floor(diff / 1000);
        const d = Math.floor(s / 86400);
        const h = Math.floor((s % 86400) / 3600);
        const m = Math.floor((s % 3600) / 60);
        const sec = s % 60;
        el.querySelector('[data-unit=days]').textContent  = String(d).padStart(2, '0');
        el.querySelector('[data-unit=hours]').textContent = String(h).padStart(2, '0');
        el.querySelector('[data-unit=mins]').textContent  = String(m).padStart(2, '0');
        el.querySelector('[data-unit=secs]').textContent  = String(sec).padStart(2, '0');
    }
    tick();
    setInterval(tick, 1000);
})();
</script>
<?php endif; ?>

</body>
</html>
