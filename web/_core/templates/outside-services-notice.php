<?php
// Path: web/_core/templates/outside-services-notice.php
/**
 * -----------------------------------------------------------------------------
 * The privacy pages' "Outside companies" section 🛂 (#808; D-158, D-195)
 * -----------------------------------------------------------------------------
 * Drawn on the Privacy & Data Protection page and the privacy policy, under a
 * heading each page writes at its own level, exactly as network-addresses.php
 * is, so the two pages cannot say different things.
 *
 * It lists every outside company the portal's code can contact, in three
 * groups, and writes NO words of its own about any one service:
 *
 *   1. the three a person can switch on and off (Gravatar, the sign-in photo,
 *      maps of homes and meeting places), from OutsideServices::SERVICES --
 *      the same words the Outside services pages show (#778, D-058, D-072);
 *   2. the ones the owner decided must be NAMED before the first install and
 *      put under the on/off rules after it (D-158, #1085), from
 *      OutsideServices::NAMED;
 *   3. the companies an organisation may use to do a job for it, as a second
 *      short list headed "only if this organisation uses it" (D-195), from
 *      OutsideServices::USED_BY_ORGANISATION.
 *
 * Every entry in groups 2 and 3 says WHO contacts the company -- the reader's
 * browser, this portal's server, or both -- because that decides whether the
 * company learns the reader's internet address. The first version opened with
 * "using one can tell that company ... at the least your internet address",
 * which was untrue of what3words, the address look-up and error reports, all
 * of which are reached from the server alone (#808, round 1).
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
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/808
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\OutsideServices;
use Portal\Core\Site;

/** The words for who sets a service up (#808 round 4). An unknown value prints nothing rather than a guess. */
$outsideSetUpWords = [
    'installation' => 'Whoever runs this whole installation, for every organisation on it.',
    'organisation' => 'This organisation, for itself.',
    'both'         => 'Partly whoever runs this whole installation, partly this organisation for itself.',
];

/** The words for who contacts the company. An unknown value prints nothing rather than a guess. */
$outsideFromWords = [
    'browser' => 'Your browser.',
    'server'  => 'This portal\'s own server, not your browser.',
    'both'    => 'Your browser, and this portal\'s own server.',
];
?>
<div data-outside-services-notice="1">
<p>
    Some parts of this portal use another company's service. Each entry below says what that company receives,
    and who contacts it. When <strong>your browser</strong> contacts a company, the company also learns your
    internet address (the number that identifies your connection, which can show roughly where you are) and
    your browser (the program you are reading this in, and its version), because that is how the internet
    works. When only <strong>this portal's own server</strong> contacts it, the company learns the server's
    address, not yours, unless the entry says that yours is passed on. Where both do, both apply.
</p>
<p>
    <strong>You can switch these three on or off for yourself</strong>, where this portal allows them at all, on
    <a href="<?php echo htmlspecialchars(Site::url('account/outside-services'), ENT_QUOTES, 'UTF-8'); ?>">Account &rarr; Outside services</a>.
    Each is reached by your browser.
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
<?php foreach (['named' => OutsideServices::NAMED, 'organisation' => OutsideServices::USED_BY_ORGANISATION] as $outsideGroup => $outsideList): ?>
    <?php if ($outsideGroup === 'named'): ?>
        <p>
            <strong>You cannot switch these off for yourself yet.</strong> They are listed so that you know who
            receives what; a way to switch each one off is planned.
        </p>
    <?php else: ?>
        <p>
            <strong>Only if this organisation uses it.</strong> An organisation may use other companies to do a job
            for it. Each entry below says who sets it up: this organisation for itself, or whoever runs this whole
            installation, in which case it covers the members of every organisation on the installation, whether or
            not this organisation chose it. Ask the organisation which are in use.
        </p>
    <?php endif; ?>
    <ul data-outside-services="<?php echo htmlspecialchars($outsideGroup, ENT_QUOTES, 'UTF-8'); ?>">
        <?php foreach ($outsideList as $outsideKey => $outsideNamed): ?>
            <li data-outside-service="<?php echo htmlspecialchars($outsideKey, ENT_QUOTES, 'UTF-8'); ?>">
                <strong><?php echo htmlspecialchars($outsideNamed['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                &mdash; <?php echo htmlspecialchars($outsideNamed['companies'], ENT_QUOTES, 'UTF-8'); ?>
                <?php if (isset($outsideNamed['setUp']) === true): ?>
                    <br><em>Set up by:</em> <?php echo htmlspecialchars($outsideSetUpWords[$outsideNamed['setUp']] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                <?php endif; ?>
                <br><em>Who contacts it:</em> <?php echo htmlspecialchars($outsideFromWords[$outsideNamed['from']] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                <br><em>When:</em> <?php echo htmlspecialchars($outsideNamed['when'], ENT_QUOTES, 'UTF-8'); ?>
                <br><em>What it receives:</em> <?php echo htmlspecialchars($outsideNamed['sends'], ENT_QUOTES, 'UTF-8'); ?>
                <br><em>Why:</em> <?php echo htmlspecialchars($outsideNamed['why'], ENT_QUOTES, 'UTF-8'); ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endforeach; ?>
</div>
