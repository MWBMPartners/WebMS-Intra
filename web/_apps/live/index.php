<?php
// Path: public_html/live/index.php
/**
 * Livestream — public/member live view. Shows embedded player when a channel
 * is currently scheduled to be live; otherwise shows a countdown to the next
 * scheduled stream.
 *
 * @package   Portal\Livestream
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/273
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Livestream;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$live   = Livestream::currentlyLive($siteId);
$next   = $live === null ? Livestream::nextScheduled($siteId) : null;

$displayName = (string) (App::settings()['livestream']['displayName'] ?? 'Livestream');

$pageTitle   = $displayName;
$pageSection = 'livestream';
$breadcrumbs = ['Dashboard' => '/', $displayName => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex align-items-center mb-3">
    <h1 class="mb-0"><i class="fa-solid fa-tower-broadcast me-2"></i><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></h1>
    <?php if ($live !== null): ?>
        <span class="badge bg-danger ms-3"><i class="fa-solid fa-circle me-1" style="font-size:0.6em;"></i>LIVE NOW</span>
    <?php endif; ?>
</div>

<?php if ($live !== null):
    $override = trim((string) ($live['embedHtmlOverride'] ?? ''));
    $platform = (string) $live['platform'];
    $vid      = (string) ($live['channelOrVideoId'] ?? '');
    $url      = $platform === 'custom' ? null : Livestream::embedUrl($platform, $vid);
?>
    <div class="card mb-3">
        <div class="card-body p-0">
            <div class="ratio ratio-16x9">
                <?php if ($platform === 'custom' && $override !== ''):
                    // Custom embeds are admin-authored HTML; only injected when the
                    // platform is explicitly 'custom' (controlled in /admin/livestream).
                    echo $override;
                elseif ($url !== null): ?>
                    <iframe src="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>"
                            title="<?php echo htmlspecialchars((string) $live['name'], ENT_QUOTES, 'UTF-8'); ?>"
                            frameborder="0"
                            allow="autoplay; encrypted-media; picture-in-picture"
                            allowfullscreen></iframe>
                <?php else: ?>
                    <div class="d-flex align-items-center justify-content-center bg-light text-muted">Stream unavailable.</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-footer small text-muted">
            <?php echo htmlspecialchars((string) $live['name'], ENT_QUOTES, 'UTF-8'); ?>
            &middot;
            <?php echo htmlspecialchars((string) $live['platform'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
    </div>
<?php elseif ($next !== null):
    $nextAt = (string) $next['nextAt'];
    $when   = strtotime($nextAt) ?: null;
?>
    <div class="card mb-3">
        <div class="card-body text-center py-5">
            <i class="fa-regular fa-clock fa-2x text-secondary mb-3"></i>
            <h4>No stream right now.</h4>
            <p class="text-secondary mb-1">Next scheduled stream:</p>
            <p class="lead mb-2">
                <strong><?php echo htmlspecialchars((string) $next['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
            </p>
            <?php if ($when !== null): ?>
                <p class="mb-3 text-muted">
                    <?php echo htmlspecialchars(date('l, j F Y · H:i', $when), ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <div class="d-inline-block px-4 py-3 bg-body-tertiary rounded">
                    <span class="fs-3 fw-bold" id="ls-countdown" data-target="<?php echo htmlspecialchars($nextAt, ENT_QUOTES, 'UTF-8'); ?>">--:--:--</span>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script>
    (function () {
        var el = document.getElementById('ls-countdown');
        if (el === null) { return; }
        var target = Date.parse(el.getAttribute('data-target'));
        if (isNaN(target) === true) { return; }
        function pad(n) { return n < 10 ? '0' + n : String(n); }
        function tick() {
            var diff = Math.max(0, Math.floor((target - Date.now()) / 1000));
            var d = Math.floor(diff / 86400);
            var h = Math.floor((diff % 86400) / 3600);
            var m = Math.floor((diff % 3600) / 60);
            var s = diff % 60;
            el.textContent = (d > 0 ? d + 'd ' : '') + pad(h) + ':' + pad(m) + ':' + pad(s);
            if (diff === 0) { window.location.reload(); }
        }
        tick();
        setInterval(tick, 1000);
    })();
    </script>
<?php else: ?>
    <div class="alert alert-info">
        No livestream channels are configured.
        <?php if (App::isAdmin() === true): ?>
            <a href="/admin/livestream">Configure now →</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
