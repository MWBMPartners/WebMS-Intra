<?php
// Path: _apps/noticeboard/foyer-setup.php
/**
 * -----------------------------------------------------------------------------
 * Noticeboard — setting up the foyer screen 📺 (#493, package P2b; D-086)
 * -----------------------------------------------------------------------------
 * Address: /noticeboard/foyer/setup. Shows the organisation's secret foyer
 * link and its QR code, makes or replaces the link, and sets what the screen
 * shows.
 *
 * WHO MAY DO WHAT
 * ---------------
 *   - see, make and replace the link: whoever manages the noticeboard — the
 *     board's own gate, `App::isSiteAdmin()` (api/noticeboard/save.php);
 *   - choose what this organisation's screen shows: its global administrator
 *     (`App::isSiteRootAdmin()`), within what the installation permits;
 *   - choose what the installation permits: a global administrator of the
 *     whole installation (`App::isRootAdmin()`).
 * The last two follow D-086 ("the installation's global administrator sets
 * what is permitted, and an organisation's global administrator chooses
 * within that") and P1's rule card, which splits the same two levels.
 *
 * THE QR CODE IS DRAWN INTO THE PAGE (`Qr::dataUri()`), never fetched from an
 * address: the link is a secret, and an address ends up in logs (#623).
 *
 * @package   Portal\Apps\Noticeboard
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright All Rights Reserved — MWBM Partners Ltd (t/a MWservices)
 * @version   1.0.0
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\NoticeboardFoyer;
use Portal\Core\PortalAddress;
use Portal\Core\Qr;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if (App::isSiteAdmin() === false) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$self   = Site::url('noticeboard/foyer/setup');
$esc    = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $finish = static function (string $message, string $type) use ($self): never {
        $_SESSION['flash_msg']  = $message;
        $_SESSION['flash_type'] = $type;
        header('Location: ' . $self);
        exit();
    };
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $finish('Invalid or expired form token. Nothing was changed. Please try again.', 'danger');
    }
    $action = is_string($_POST['action'] ?? null) === true ? $_POST['action'] : '';
    $value  = is_string($_POST['value'] ?? null) === true ? $_POST['value'] : '';

    if ($action === 'replace') {
        $had = NoticeboardFoyer::tokenFor($mysqli, $siteId) !== null;
        NoticeboardFoyer::replaceToken($mysqli, $siteId);
        Logger::activity($had === true ? 'NoticeboardFoyerLinkReplaced' : 'NoticeboardFoyerLinkMade',
            'The noticeboard foyer screen link for organisation ' . $siteId . ' was ' . ($had === true ? 'replaced; the old link no longer works.' : 'made.'),
            $userId > 0 ? $userId : null);
        $finish($had === true ? 'A new link was made. The old one no longer works: open the new one on the foyer screen.' : 'The foyer screen link was made.', 'success');
    }
    if ($action === 'organisation') {
        $out = NoticeboardFoyer::saveForOrganisation($mysqli, $siteId, $value, App::isSiteRootAdmin());
    } elseif ($action === 'installation') {
        $out = NoticeboardFoyer::saveForInstallation($mysqli, $value, App::isRootAdmin());
    } else {
        $finish('That form was not recognised. Nothing was changed.', 'danger');
    }
    Logger::activity($out['saved'] === true ? 'NoticeboardFoyerChoiceSaved' : 'NoticeboardFoyerChoiceRefused',
        'What the noticeboard foyer screen shows, for ' . ($action === 'installation' ? 'the whole installation' : 'organisation ' . $siteId)
        . ': ' . $out['message'], $userId > 0 ? $userId : null);
    $finish($out['message'], $out['type']);
}

$token     = NoticeboardFoyer::tokenFor($mysqli, $siteId);
$link      = $token === null ? '' : PortalAddress::forPage('noticeboard/foyer?token=' . $token, $siteId);
$permitted = NoticeboardFoyer::permitted($mysqli);
$shows     = NoticeboardFoyer::showsFor($mysqli, $siteId);

$pageTitle   = 'Noticeboard: foyer screen';
$pageSection = 'noticeboard';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Noticeboard' => Site::url('noticeboard'), 'Foyer screen' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3">
    <h1 class="h3 mb-2"><i class="fa-solid fa-tv me-2"></i>Noticeboard: the foyer screen</h1>
    <p class="text-muted">
        A screen in your building can play the noticeboard's posters full-screen, one after another, with nobody
        signed in. Open the link below on that screen (or scan its QR code with it). Anybody who has the link can see
        the screen, so keep it to the screens it is for; if it reaches somebody it should not, make a new one and the
        old one stops working at once.
    </p>

    <?php if (isset($_SESSION['flash_msg']) === true): ?>
        <div class="alert alert-<?php echo $esc((string) ($_SESSION['flash_type'] ?? 'info')); ?>" role="alert"><?php echo $esc((string) $_SESSION['flash_msg']); ?></div>
        <?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); ?>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h5">The link</h2>
            <?php if ($token === null): ?>
                <p>There is no foyer screen link yet.</p>
            <?php else: ?>
                <p class="mb-2"><code id="foyer-link" class="text-break"><?php echo $esc($link); ?></code></p>
                <img src="<?php echo $esc(Qr::dataUri($link)); ?>" alt="QR code for the foyer screen link" width="180" height="180" class="mb-2">
            <?php endif; ?>
            <form method="post" action="<?php echo $esc($self); ?>" class="mt-2">
                <input type="hidden" name="csrf_token" value="<?php echo $esc(Auth::csrfToken()); ?>">
                <input type="hidden" name="action" value="replace">
                <button type="submit" class="btn btn-outline-primary btn-sm"><?php echo $token === null ? 'Make a link' : 'Make a new link (the old one stops working)'; ?></button>
            </form>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h5">What the screen shows</h2>
            <p>
                Now: <strong id="foyer-shows"><?php echo $shows === 'all' ? 'every poster on the noticeboard' : 'only posters on the public website'; ?></strong>.
                <?php if ($permitted === 'public'): ?>
                    This installation permits public posters only, because anybody walking in can read the screen.
                <?php endif; ?>
            </p>
            <?php if ($permitted === 'either' && App::isSiteRootAdmin() === true): ?>
                <form method="post" action="<?php echo $esc($self); ?>" class="mb-2">
                    <input type="hidden" name="csrf_token" value="<?php echo $esc(Auth::csrfToken()); ?>">
                    <input type="hidden" name="action" value="organisation">
                    <div class="form-check"><input class="form-check-input" type="radio" name="value" value="public" id="shows-public"<?php echo $shows === 'public' ? ' checked' : ''; ?>><label class="form-check-label" for="shows-public">Only posters on the public website</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="value" value="all" id="shows-all"<?php echo $shows === 'all' ? ' checked' : ''; ?>><label class="form-check-label" for="shows-all">Every poster on the noticeboard, including ones not on the public website</label></div>
                    <button type="submit" class="btn btn-primary btn-sm mt-2">Save</button>
                </form>
            <?php elseif ($permitted === 'either'): ?>
                <p class="small text-muted">Your organisation's global administrator can choose this.</p>
            <?php endif; ?>
            <?php if (App::isRootAdmin() === true): ?>
                <hr>
                <h3 class="h6">For the whole installation</h3>
                <form method="post" action="<?php echo $esc($self); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo $esc(Auth::csrfToken()); ?>">
                    <input type="hidden" name="action" value="installation">
                    <div class="form-check"><input class="form-check-input" type="radio" name="value" value="public" id="permit-public"<?php echo $permitted === 'public' ? ' checked' : ''; ?>><label class="form-check-label" for="permit-public">Every organisation's screen shows public posters only</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="value" value="either" id="permit-either"<?php echo $permitted === 'either' ? ' checked' : ''; ?>><label class="form-check-label" for="permit-either">Each organisation chooses: public posters only, or every poster</label></div>
                    <button type="submit" class="btn btn-primary btn-sm mt-2">Save</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
