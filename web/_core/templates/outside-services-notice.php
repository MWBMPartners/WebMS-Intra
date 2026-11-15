<?php
// Path: web/_core/templates/outside-services-notice.php
/**
 * -----------------------------------------------------------------------------
 * The privacy pages' "Outside companies" section 🛂 (#808, D-158)
 * -----------------------------------------------------------------------------
 * Drawn on the Privacy & Data Protection page and the privacy policy, under a
 * heading each page writes at its own level, exactly as network-addresses.php
 * is, so the two pages cannot say different things.
 *
 * It lists every outside company a page of this portal can reach, in two
 * groups, and writes NO words of its own about any one service:
 *
 *   1. the three a person can switch on and off (Gravatar, the sign-in photo,
 *      maps of homes and meeting places), from OutsideServices::SERVICES --
 *      the same words the Outside services pages show (#778, D-058, D-072);
 *   2. the six the owner decided must be NAMED before the first install and
 *      put under the on/off rules after it (D-158), from
 *      OutsideServices::NAMED, the ONE place their words are written.
 *
 * Why the second group is here at all: until #808 the privacy pages said
 * nothing about any outside company, while every page fetched its layout and
 * icons from two of them and a livestream could be Facebook's player. A list
 * that names only what can be switched off would read as complete and is not.
 *
 * WHAT IT DOES NOT LIST, said on the page itself: the companies an
 * organisation may use to do a job for it on its server (sending e-mail and
 * text messages, taking payments, video meetings, signing in with Microsoft or
 * Google, transcribing and translating). D-158 did not name them; whether they
 * belong here is a question for the owner, recorded on #808. The page says so
 * rather than staying silent, because silence would read as "there are none".
 *
 * It reads nothing from the database and nothing from $SETTINGS: the words do
 * not change from one organisation to another, and "only if it is set up" is
 * said in the words themselves rather than worked out here, because a wrong
 * "this is not in use" would be worse than none.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/808
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\OutsideServices;
use Portal\Core\Site;
?>
<div data-outside-services-notice="1">
<p>
    Some parts of this portal use another company's service. Using one can tell that company something about
    you: at the least your internet address (the number that identifies your connection, which can show roughly
    where you are) and your browser (the program you are reading this in, and its version).
</p>
<p>
    <strong>You can switch these three on or off for yourself</strong>, where this portal allows them at all, on
    <a href="<?php echo htmlspecialchars(Site::url('account/outside-services'), ENT_QUOTES, 'UTF-8'); ?>">Account &rarr; Outside services</a>:
</p>
<ul data-outside-services="switchable">
    <?php foreach (OutsideServices::SERVICES as $outsideKey => $outsideService): ?>
        <li data-outside-service="<?php echo htmlspecialchars($outsideKey, ENT_QUOTES, 'UTF-8'); ?>">
            <strong><?php echo htmlspecialchars($outsideService['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
            (<?php echo htmlspecialchars($outsideService['company'], ENT_QUOTES, 'UTF-8'); ?>).
            <?php echo htmlspecialchars($outsideService['sends'], ENT_QUOTES, 'UTF-8'); ?>
        </li>
    <?php endforeach; ?>
</ul>
<p>
    <strong>These have no switch yet.</strong> They are listed so that you know who receives what; a way to
    switch each one off is planned.
</p>
<ul data-outside-services="named">
    <?php foreach (OutsideServices::NAMED as $outsideKey => $outsideNamed): ?>
        <li data-outside-service="<?php echo htmlspecialchars($outsideKey, ENT_QUOTES, 'UTF-8'); ?>">
            <strong><?php echo htmlspecialchars($outsideNamed['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
            &mdash; <?php echo htmlspecialchars($outsideNamed['companies'], ENT_QUOTES, 'UTF-8'); ?>
            <br><em>When:</em> <?php echo htmlspecialchars($outsideNamed['when'], ENT_QUOTES, 'UTF-8'); ?>
            <br><em>What it receives:</em> <?php echo htmlspecialchars($outsideNamed['sends'], ENT_QUOTES, 'UTF-8'); ?>
            <br><em>Why:</em> <?php echo htmlspecialchars($outsideNamed['why'], ENT_QUOTES, 'UTF-8'); ?>
        </li>
    <?php endforeach; ?>
</ul>
<p data-outside-services="not-listed">
    Not listed here: companies this organisation may use to do a job for it from this portal's own server, such
    as sending e-mail and text messages, taking payments, video meetings, signing in with a Microsoft or Google
    account, and transcribing or translating. Each receives what that job needs. Ask the organisation which it
    uses.
</p>
</div>
