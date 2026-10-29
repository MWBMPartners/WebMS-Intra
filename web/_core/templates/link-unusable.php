<?php
// Path: _core/templates/link-unusable.php
/**
 * -----------------------------------------------------------------------------
 * "This link cannot be used" 🔗🚫
 * -----------------------------------------------------------------------------
 * The ONE answer for a link from an e-mail that the portal cannot act on: the
 * address is missing its token, the token is the wrong shape, it has expired,
 * or it has already been used. Shown as a proper page rather than a line of
 * bare text.
 *
 * WHY IT EXISTS (#569). Two public addresses used to answer a bad link with
 * `header('Content-Type: text/plain'); exit('Invalid unsubscribe link.');` —
 * unstyled black text on white, no heading, no navigation, nothing to do next.
 * They are reached from e-mails, which is exactly where a link gets broken by
 * a mail client wrapping it, by being forwarded, or by being followed months
 * later. So the shape of page a stranger is most likely to meet was the one
 * that looked least finished.
 *
 * DELIBERATELY THE SAME PAGE FOR EVERY REASON, and the same words. "Expired",
 * "already used" and "never existed" are different facts about somebody else's
 * personal data, and telling them apart would let anybody with a guessed token
 * learn which. The caller passes only what the link was FOR, so the page can
 * say what to do next.
 *
 * HOW TO USE IT. Set the HTTP code yourself first — this file does not, because
 * the right code differs (400 for a malformed address, 410 for something that
 * has genuinely expired) and only the caller knows which:
 *
 *     http_response_code(400);
 *     $linkPurpose = 'unsubscribe from our newsletter';
 *     $linkAdvice  = 'Use the unsubscribe link in a recent newsletter.';
 *     require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates'
 *           . DIRECTORY_SEPARATOR . 'link-unusable.php';
 *     exit();
 *
 * WHAT IT CANNOT DO
 *   - It cannot send a new link. There is nobody to send one to: the whole
 *     point is that the portal does not know who is asking.
 *   - It cannot say why. See above; that is on purpose.
 *
 * @package   Portal\Core\Templates
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// 🏷️ What the link was for, in words that finish the sentence "the link you
//    followed was for somebody to …". The caller sets it; a safe general
//    wording is used when it does not, so this page can never be blank.
$linkPurpose = isset($linkPurpose) === true && is_string($linkPurpose) === true && trim($linkPurpose) !== ''
    ? trim($linkPurpose)
    : 'do something on this portal';
$linkAdvice = isset($linkAdvice) === true && is_string($linkAdvice) === true && trim($linkAdvice) !== ''
    ? trim($linkAdvice)
    : '';

$pageTitle       = 'This link cannot be used';
$pageDescription = 'The link you followed is not one this portal can use.';
$pageSection     = '';
$breadcrumbs     = [];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <i class="fa-solid fa-link-slash fa-2x text-secondary mb-3" aria-hidden="true"></i>
                <h1 class="h4 mb-3">This link cannot be used</h1>
                <p class="text-body-secondary mb-3">
                    The link you followed was for somebody to
                    <?php echo htmlspecialchars($linkPurpose, ENT_QUOTES, 'UTF-8'); ?>,
                    but it is not one this portal can use. Links like this stop working
                    after a while, and they can also be broken in half by an e-mail
                    program before they reach you.
                </p>
                <?php if ($linkAdvice !== ''): ?>
                    <p class="mb-4"><?php echo htmlspecialchars($linkAdvice, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
                <p class="small text-body-secondary mb-0">
                    Nothing has been changed, and nothing has been sent.
                </p>
            </div>
        </div>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
