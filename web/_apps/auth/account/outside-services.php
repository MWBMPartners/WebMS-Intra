<?php
// Path: _apps/auth/account/outside-services.php
/**
 * -----------------------------------------------------------------------------
 * My account — Outside services: my own choices 🛂 (#778)
 * -----------------------------------------------------------------------------
 * A member's third of the owner's rule (D-058): they may switch an outside
 * service OFF for themselves at any time, and ON only where every organisation
 * they belong to — and the installation — allows it.
 *
 * For each service the page says what is sent and to whom, what is in force
 * for this member now and why, and offers "follow my organisations", "on" and
 * "off". Where the service is not allowed at all, the sentence names who
 * refuses it — the installation, or the member's own organisations by name —
 * and "on" is never offered. If the member has NO stored choice there, no
 * control is drawn; if they HAVE one, a control is drawn offering "keep my
 * earlier choice" (selected), "follow my organisations" and "off", so a
 * recorded choice can always be withdrawn (#778, round 2). A member in several
 * organisations is held to the strictest of all of them, whichever
 * organisation's page this is.
 *
 * Every word about the decision comes from `Portal\Core\OutsideServices`; the
 * same facts are read here as by the reader that draws the pictures, so the
 * page and the top bar cannot disagree (#647).
 *
 * @package   Portal\Auth
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/778
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\OutsideServices;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$userId = (int) ($_SESSION['user_id'] ?? 0);
$facts  = OutsideServices::factsFor($mysqli, $userId, Site::id());

$flashMsg  = (string) ($_SESSION['flash_msg'] ?? '');
$flashType = (string) ($_SESSION['flash_type'] ?? '');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$e    = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$csrf = $e(Auth::csrfToken());

$pageTitle       = 'Outside services';
$pageDescription = 'Choose whether outside companies may be sent something about you, such as your profile picture.';
$pageSection     = 'account';
$breadcrumbs     = ['Dashboard' => Site::url(''), 'My Account' => Site::url('account'), 'Outside services' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo $e($flashType !== '' ? $flashType : 'info'); ?>" role="status"><?php echo $e($flashMsg); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-shield-halved me-2" aria-hidden="true"></i>Outside services</h1>

<p class="text-secondary">
    Some pictures are not stored on this portal but fetched from another company's servers, and fetching one
    tells that company something about you. Nothing is fetched unless it is switched on. You can always switch a
    service off for yourself. You can switch one on only where your organisations allow it; if you belong to
    several, the strictest of them decides.
</p>

<?php if (($facts['readable'] ?? false) !== true): ?>
    <div class="alert alert-danger" role="alert">
        The settings that decide this could not be read, so every outside service is switched off for you until
        they can be. Nothing can be changed here right now.
    </div>
<?php else: ?>
    <form method="post" action="<?php echo $e(Site::url('account/outside-services-save')); ?>" class="card mb-4">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <div class="card-body">
            <div class="portal-data-list">
                <?php foreach (OutsideServices::SERVICES as $service => $about): ?>
                    <?php
                    $decision = OutsideServices::decide($service, $facts);
                    $stored   = $decision['choice'];
                    $selected = $stored ?? OutsideServices::FOLLOW;
                    ?>
                    <div class="row g-2 py-3 border-bottom">
                        <div class="col-12 col-md-7">
                            <label class="fw-semibold" for="choice-<?php echo $e($service); ?>"><?php echo $e($about['name']); ?></label>
                            <div class="small text-secondary">Sent to <?php echo $e($about['company']); ?>: <?php echo $e($about['sends']); ?></div>
                            <div class="small mt-1">
                                <?php if ($decision['ceiling'] === 0): ?>
                                    <strong>Off.</strong> It cannot be switched on, because <?php echo $e(OutsideServices::refusalReason($decision)); ?>
                                    <?php if ($stored === OutsideServices::ON): ?>
                                        Your earlier choice, <strong>on</strong>, is kept and would apply again if
                                        <?php echo OutsideServices::refuserCount($decision) > 1 ? 'those changed' : 'that changed'; ?>.
                                        To withdraw it, choose <strong>Off</strong>.
                                    <?php endif; ?>
                                <?php elseif ($decision['effective'] === true): ?>
                                    <strong>On</strong> for you<?php echo $stored === null ? ', because your organisations switch it on unless you switch it off' : ', because you switched it on'; ?>.
                                <?php else: ?>
                                    <strong>Off</strong> for you<?php echo $stored === OutsideServices::OFF ? ', because you switched it off' : ', until you switch it on'; ?>.
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-12 col-md-5">
                            <?php
                            // 🔓 "YOU CAN ALWAYS SWITCH IT OFF" MUST BE TRUE (#778,
                            //    round 2). A member who chose "on" and whose
                            //    organisation then said "no" had NO control, so the
                            //    recorded choice could not be withdrawn and came back
                            //    by itself when the organisation relaxed. So whenever
                            //    a choice is STORED, a control is drawn — offering
                            //    "Off" and "Follow my organisations", and never "On"
                            //    above what is allowed. Its first, selected option is
                            //    "keep", which the writer reads as "leave alone": a
                            //    <select> with no selected option is a vote for its
                            //    first option (#763), and an untouched Save must not
                            //    quietly change somebody's recorded choice.
                            ?>
                            <?php
                            // 🪟 #815: the value this control is drawn with goes back
                            //    beside it, so a Save from an older tab that left it
                            //    untouched writes nothing for it (see the handler).
                            //    Only where a control is drawn; "keep" when the
                            //    control's selected option is "keep".
                            ?>
                            <?php if ($decision['ceiling'] === 0 && $stored !== null): ?>
                                <input type="hidden" name="<?php echo $e(OutsideServices::drawnMarkerName($service)); ?>" value="<?php echo $e(OutsideServices::KEEP); ?>">
                                <select class="form-select form-select-sm" id="choice-<?php echo $e($service); ?>" name="choice_<?php echo $e($service); ?>">
                                    <option value="<?php echo $e(OutsideServices::KEEP); ?>" selected>Keep my earlier choice: <?php echo $e($stored); ?> (the service is not allowed for you at the moment)</option>
                                    <option value="<?php echo $e(OutsideServices::FOLLOW); ?>">Follow my organisations</option>
                                    <option value="<?php echo $e(OutsideServices::OFF); ?>">Off</option>
                                </select>
                            <?php elseif ($decision['ceiling'] > 0): ?>
                                <input type="hidden" name="<?php echo $e(OutsideServices::drawnMarkerName($service)); ?>" value="<?php echo $e($selected); ?>">
                                <select class="form-select form-select-sm" id="choice-<?php echo $e($service); ?>" name="choice_<?php echo $e($service); ?>">
                                    <option value="<?php echo $e(OutsideServices::FOLLOW); ?>"<?php echo $selected === OutsideServices::FOLLOW ? ' selected' : ''; ?>>Follow my organisations (<?php echo $decision['ceiling'] >= 2 ? 'on' : 'off'; ?>)</option>
                                    <option value="<?php echo $e(OutsideServices::ON); ?>"<?php echo $selected === OutsideServices::ON ? ' selected' : ''; ?>>On</option>
                                    <option value="<?php echo $e(OutsideServices::OFF); ?>"<?php echo $selected === OutsideServices::OFF ? ' selected' : ''; ?>>Off</option>
                                </select>
                            <?php else: ?>
                                <span class="small text-secondary">Not allowed</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="small text-secondary mt-3 mb-0">
                <strong>Maps of a member's home</strong> involve two people. Your own map is drawn for somebody else only
                when you have switched maps on and they have not switched them off. Switching maps off also stops other
                members' maps being drawn for you.
            </p>
            <button type="submit" class="btn btn-primary btn-sm mt-3">Save</button>
        </div>
    </form>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
