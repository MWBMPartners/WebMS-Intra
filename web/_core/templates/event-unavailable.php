<?php
// Path: _core/templates/event-unavailable.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Event Unavailable Page 📅🚫
 * -----------------------------------------------------------------------------
 * The ONE answer for an event a visitor may not see, whatever the reason —
 * missing entirely, deleted, still a draft, or internal and the viewer is
 * not a member who may see it. Deliberately the SAME page for all of them,
 * so nobody can tell which reason applies just by looking at the answer
 * (that is exactly what #532 closed).
 *
 * WHY 404 UNDERNEATH. Before 20 September 2026, a refused event sent a
 * signed-out visitor to sign in (302), while only a truly missing event
 * answered 404. That closed the "does this exist" leak, but it also meant
 * that following a DEAD link — an old shared link, a search result, a
 * bookmark for an event since deleted — asked the visitor to sign in for
 * something that no longer exists at all, and told a search engine nothing
 * about the address being dead. This page answers 404 either way, so a
 * search engine drops the address, and carries a sign-in link only for a
 * visitor who is not yet signed in (a signed-in visitor who is refused does
 * not need to be told to sign in — they already are).
 *
 * WHAT THIS PAGE CANNOT DO: say which of the reasons applies. That is the
 * entire point — see Router::renderEventUnavailable(), the only place that
 * requires this file.
 *
 * @package   Portal\Core\Templates
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Site;

$pageTitle   = t('event.unavailable_title');
$pageSection = 'calendar';
$breadcrumbs = [];
$signedIn    = Auth::check();

// The sign-in link carries the address the visitor asked for, exactly the
// way Auth::requireLogin() builds its own redirect target, so an emailed
// link still lands back on the event once the visitor has signed in.
// urlencode(), not rawurlencode(), on purpose — matching requireLogin()
// byte for byte matters here because proof 1 in the settled plan diffs
// this page's body against the sign-in page's own redirect target.
$signInHref = Site::url('login') . '?redirect=' . urlencode((string) ($_SERVER['REQUEST_URI'] ?? '/'));

require __DIR__ . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="portal-error-page">
    <div class="portal-error-code"><i class="fa-solid fa-calendar-xmark text-muted"></i></div>
    <h1 class="portal-error-title"><?php echo htmlspecialchars(t('event.unavailable_title'), ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="portal-error-text"><?php echo htmlspecialchars(t($signedIn === true ? 'event.unavailable_signed_in' : 'event.unavailable_signed_out'), ENT_QUOTES, 'UTF-8'); ?></p>
    <?php if ($signedIn === false): ?>
        <a href="<?php echo htmlspecialchars($signInHref, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary me-2">
            <i class="fa-solid fa-right-to-bracket me-1"></i> <?php echo htmlspecialchars(t('auth.sign_in'), ENT_QUOTES, 'UTF-8'); ?>
        </a>
    <?php endif; ?>
    <a href="<?php echo htmlspecialchars(Site::url('calendar'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">
        <i class="fa-solid fa-calendar-days me-1"></i> <?php echo htmlspecialchars(t('event.unavailable_calendar'), ENT_QUOTES, 'UTF-8'); ?>
    </a>
</div>
<?php require __DIR__ . DIRECTORY_SEPARATOR . 'footer.php'; ?>
