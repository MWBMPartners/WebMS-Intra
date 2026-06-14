<?php
// Path: _apps/widget/countdown-preview.php
/**
 * -----------------------------------------------------------------------------
 * Embeddable Countdown Widget — Preview + How-to-Embed page 📋 (#319)
 * -----------------------------------------------------------------------------
 * Public page that demonstrates the countdown widget AND shows the
 * copy-paste embed snippet a church admin needs for their own website.
 *
 * @package   Portal\Widget
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/319
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Site;

$pageTitle   = 'Embed: Countdown Widget';
$pageSection = 'widget';

$portalUrl    = (isset($_SERVER['HTTPS']) === true && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://')
              . (string) ($_SERVER['HTTP_HOST'] ?? 'portal.example.org');
$productName  = Site::productName();
$nonce        = htmlspecialchars(App::cspNonce(), ENT_QUOTES, 'UTF-8');

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-4">
    <h1 class="h3 mb-3"><i class="fa-solid fa-clock-rotate-left me-2 text-primary"></i>Countdown widget</h1>
    <p class="text-muted">
        Drop this little widget anywhere on your church's website and visitors
        see a live countdown to your next scheduled service. Updates itself
        automatically &mdash; no plugin, no account, no maintenance.
    </p>

    <h2 class="h5 mt-4 mb-3">Preview</h2>
    <div id="webms-countdown" style="max-width: 480px;"></div>

    <h2 class="h5 mt-5 mb-3">Embed code</h2>
    <p class="text-muted small">
        Copy and paste this anywhere in your website's HTML. The countdown
        reads its data from this portal at <code><?php echo htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8'); ?></code>.
    </p>
    <pre class="bg-light p-3 rounded border" style="font-size:.85rem;overflow-x:auto;"><code>&lt;div id="webms-countdown"&gt;&lt;/div&gt;
&lt;script src="<?php echo htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8'); ?>/widget/countdown.js"
        data-portal="<?php echo htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8'); ?>"
        data-target="#webms-countdown"
        data-theme="auto"
        defer&gt;&lt;/script&gt;</code></pre>

    <h2 class="h6 mt-4 mb-2">Options</h2>
    <ul class="small">
        <li><code>data-portal</code> &mdash; required. The base URL of this install.</li>
        <li><code>data-target</code> &mdash; optional. CSS selector for the container. Defaults to <code>#webms-countdown</code>.</li>
        <li><code>data-theme</code> &mdash; <code>light</code> / <code>dark</code> / <code>auto</code>. Defaults to <code>auto</code> (matches the host page's prefers-color-scheme).</li>
        <li><code>data-poll-min</code> &mdash; how often to re-fetch the next event from the portal, in minutes. Defaults to 15.</li>
    </ul>

    <div class="alert alert-info mt-4">
        <i class="fa-solid fa-circle-info me-1"></i>
        The widget reads from the public events feed at
        <a href="/widget/countdown.json"><code>/widget/countdown.json</code></a>
        and only shows events with public-or-members visibility &mdash;
        leadership-only events are never exposed.
    </div>
</div>

<!-- 📡 Load the widget script targeting the preview container on this page. -->
<script nonce="<?php echo $nonce; ?>"
        src="/widget/countdown.js"
        data-portal="<?php echo htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8'); ?>"
        data-target="#webms-countdown"
        data-theme="auto"
        defer></script>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
