<?php
// Path: _apps/noticeboard/foyer.php
/**
 * -----------------------------------------------------------------------------
 * The noticeboard's foyer slideshow 📺 (#493, package P2b; D-086)
 * -----------------------------------------------------------------------------
 * A screen in the building plays the organisation's posters full-screen, one
 * after another, with no sign-in. Address: /noticeboard/foyer?token=<the secret>.
 *
 * THE PARAMETER IS CALLED `token` ON PURPOSE (round 1 of the check):
 * `Logger::withoutJobToken()` blanks a parameter of that name before an
 * address is written to the error log, an alert e-mail or the outside error
 * service. Under any other name a fault on this page would record the secret.
 *
 * WHICH ORGANISATION, AND WHICH POSTERS
 * --------------------------------------
 * The organisation is taken from the secret ALONE (`NoticeboardFoyer::
 * siteForToken()`), never from the address the request arrived at, so one
 * organisation's screen can never show another's posters. Which posters is
 * `NoticeboardFoyer::posters()`: public posters only (decided by
 * `PublicWebsite::posterWhere()` and nothing else) unless the installation
 * permits and the organisation chose every poster (D-086).
 *
 * WHAT THIS PAGE SENDS, AND WHY
 * -----------------------------
 * It draws its own <head> (no menus, nothing of the portal around it), so it
 * sends its own rules, because a page that writes its own head gets none from
 * header.php (#623):
 *   - a script rule allowing only this page's own nonce'd script;
 *   - `Referrer-Policy: no-referrer`, because the secret is in this page's
 *     address and a poster's picture may come from another website, which
 *     would otherwise be told the address in the Referer header;
 *   - `X-Robots-Tag: noindex, nofollow` and the same in a meta tag, so a
 *     search engine that ever met the address does not list it;
 *   - `Cache-Control: no-store, private`. The `private` is load-bearing (round
 *     3 of the check, measured in a real browser): the portal's service
 *     worker keeps a copy of a signed-out page whatever `no-store` says, and
 *     refuses only `private`. A kept copy carries `X-Portal-Foyer: 1`, so
 *     during an outage the reload check below got the copy, reloaded into it,
 *     and dropped whatever the slides had loaded — and the copy outlived a
 *     replaced link.
 * An unknown, replaced or malformed secret answers a plain 404 with nothing
 * that says whether any foyer link exists — and so does a link whose
 * organisation has switched the Noticeboard off. That switch is asked HERE,
 * for the organisation the secret names (`AppRegistry::isEnabledForSite()`),
 * because the router's own test reads the organisation of the ADDRESS, which
 * for a signed-out screen is always the default one (round 1 of the check;
 * `AppRegistry::ROUTES_JUDGING_THEIR_OWN_ORGANISATION`).
 *
 * THE RELOAD ASKS FIRST. The page reloads itself every thirty minutes so
 * changes appear. It used to reload blindly, so one reload that met the
 * upgrade page (503), an error page or no connection left the screen on that
 * page for good. It now fetches its own address first, and reloads only on
 * an answer from THIS page — marked `X-Portal-Foyer: 1`, which every answer
 * this file gives carries, the 404 included — with status 200 or 404 (a
 * replaced link, or the noticeboard switched off, so the screen stops
 * showing posters as it should). Anything else — the upgrade page, an error,
 * the router's own 404, no answer, or the portal's offline page, which the
 * portal's service worker answers with status 200 when the network is down
 * (round 2 of the check) — keeps the slides playing and asks again a minute
 * later. The check sends the same cookies the reload will, so the two get
 * the same answer.
 *
 * @package   Portal\Apps\Noticeboard
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright All Rights Reserved — MWBM Partners Ltd (t/a MWservices)
 * @version   1.0.0
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AppRegistry;
use Portal\Core\NoticeboardFoyer;

header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
// Marks every answer this page gives, so the screen's reload can tell its own
// page from anything else answering at the same address (see above).
header('X-Portal-Foyer: 1');

$siteId = NoticeboardFoyer::siteForToken($mysqli, $_GET['token'] ?? null);
if ($siteId === null || AppRegistry::isEnabledForSite('noticeboard', $siteId) === false) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found.\n";
    return;
}

$posters = NoticeboardFoyer::posters($mysqli, $siteId);
$nonce   = App::cspNonce();
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-" . $nonce . "'; style-src 'unsafe-inline'; connect-src 'self'; "
    . "img-src 'self' https: data:; media-src 'self' https:; frame-src https://www.canva.com; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");

$esc      = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
// Only addresses a poster's own save already accepted (http(s) absolute or
// root-relative) are drawn; anything else is left out rather than trusted.
$safe = static fn (string $u): string => preg_match('#^(https?://|/)#i', $u) === 1 ? $u : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Noticeboard</title>
    <style>
        html, body { margin: 0; height: 100%; background: #0f1115; color: #e8eaf0; font-family: system-ui, -apple-system, sans-serif; overflow: hidden; }
        .slide { position: absolute; inset: 0; display: none; align-items: center; justify-content: center; flex-direction: column; text-align: center; padding: 4vh 4vw; box-sizing: border-box; }
        .slide.on { display: flex; }
        .slide img, .slide video { max-width: 100%; max-height: 82vh; object-fit: contain; }
        .slide iframe { width: 80vw; height: 80vh; border: 0; }
        .slide h1 { font-size: 5vh; margin: 2vh 0 1vh; }
        .slide .kicker { font-size: 2.6vh; letter-spacing: .1em; text-transform: uppercase; opacity: .85; }
        .slide .when { font-size: 3vh; margin-top: 1vh; }
        .empty { display: flex; height: 100%; align-items: center; justify-content: center; font-size: 3vh; }
    </style>
</head>
<body>
<?php if ($posters === []): ?>
    <div class="empty">There are no posters to show.</div>
<?php else: ?>
    <?php foreach ($posters as $i => $p): ?>
        <?php
        $when = $p['scheduleType'] === 'weekly'
            ? 'Every ' . ($weekdays[(int) ($p['weekday'] ?? 0)] ?? '')
            : (string) ($p['eventDate'] ?? '');
        $time = (string) ($p['eventTime'] ?? '');
        if ($time !== '') {
            $when .= ($when !== '' ? ', ' : '') . substr($time, 0, 5);
        }
        $media = $safe((string) ($p['mediaUrl'] ?? ''));
        $canva = preg_match('#^https://www\.canva\.com/#i', (string) ($p['canvaUrl'] ?? '')) === 1 ? (string) $p['canvaUrl'] : '';
        ?>
        <section class="slide<?php echo $i === 0 ? ' on' : ''; ?>" data-slide>
            <?php if ($p['mediaType'] === 'canva' && $canva !== ''): ?>
                <iframe src="<?php echo $esc($canva); ?>" title="<?php echo $esc((string) $p['title']); ?>" loading="lazy" allowfullscreen></iframe>
            <?php elseif ($p['mediaType'] === 'video' && $media !== ''): ?>
                <video src="<?php echo $esc($media); ?>" autoplay muted loop playsinline></video>
            <?php elseif ($p['mediaType'] === 'image' && $media !== ''): ?>
                <img src="<?php echo $esc($media); ?>" alt="<?php echo $esc((string) $p['title']); ?>">
            <?php else: ?>
                <?php if ((string) ($p['kicker'] ?? '') !== ''): ?><div class="kicker"><?php echo $esc((string) $p['kicker']); ?></div><?php endif; ?>
                <h1><?php echo $esc((string) $p['title']); ?></h1>
                <?php if ($when !== ''): ?><div class="when"><?php echo $esc($when); ?></div><?php endif; ?>
                <?php if ((string) ($p['location'] ?? '') !== ''): ?><div class="when"><?php echo $esc((string) $p['location']); ?></div><?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
<script nonce="<?php echo $esc($nonce); ?>">
(function () {
    // One poster at a time, twelve seconds each. Every thirty minutes the page
    // asks its own address first and reloads only on a 200 or a 404 that this
    // page itself gave (see the file header): anything else keeps the slides and asks again in a minute,
    // so one bad moment never leaves the screen stuck on an error page.
    var slides = document.querySelectorAll('[data-slide]');
    var at = 0;
    if (slides.length > 1) {
        setInterval(function () {
            slides[at].classList.remove('on');
            at = (at + 1) % slides.length;
            slides[at].classList.add('on');
        }, 12000);
    }
    function tryReload() {
        fetch(window.location.href, { cache: 'no-store', credentials: 'same-origin' }).then(function (r) {
            if (r.headers.get('X-Portal-Foyer') === '1' && (r.status === 200 || r.status === 404)) {
                window.location.reload();
            } else {
                setTimeout(tryReload, 60 * 1000);
            }
        }).catch(function () {
            setTimeout(tryReload, 60 * 1000);
        });
    }
    setTimeout(tryReload, 30 * 60 * 1000);
})();
</script>
</body>
</html>
