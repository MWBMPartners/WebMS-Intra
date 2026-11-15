<?php
// Path: web/_core/templates/network-addresses.php
/**
 * -----------------------------------------------------------------------------
 * The privacy pages' "Network addresses" section 🌐 (#1019, D-178)
 * -----------------------------------------------------------------------------
 * Drawn on the Privacy & Data Protection page and the privacy policy, under a
 * heading each page writes at its own level. Says what a network address is,
 * why the portal keeps them, and then every place one is kept with how long,
 * each sentence taken as it is from the personal-data catalogue through
 * Portal\Core\NetworkAddresses::places(). Above the list it says the
 * network-address period for the reader's organisation
 * (Portal\Core\NetworkAddressRetention, #1019 round 2), which several of
 * those sentences say they follow. This file adds no words about any
 * one place, so the two pages cannot say different things.
 *
 * If the list cannot be read it SAYS so rather than drawing an empty list,
 * which would read as "no addresses are kept".
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1019
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\NetworkAddressRetention;
use Portal\Core\NetworkAddresses;
use Portal\Core\Site;

$networkAddressPlaces = NetworkAddresses::places();
// ⏳ #1019 round 2: the period the places below say they follow, for THIS
//    organisation, from the reader the nightly clear-out uses (#647). A page
//    including this template inherits `$mysqli` (the two variables a page has).
$networkAddressFacts = NetworkAddressRetention::memberFactsForSite($mysqli, Site::id());
?>
<p>
    A network address (also called an IP address) is the number the internet uses to send a page to the device
    asking for it. The portal records the address a visit or a request came from, to keep the portal secure,
    to look into misuse and to help when something goes wrong. Beside an account and a time, an address can
    identify a person, so it is handled as personal data. Where one is kept, and for how long:
</p>
<p data-network-address-retention="<?php echo htmlspecialchars($networkAddressFacts['state'], ENT_QUOTES, 'UTF-8'); ?>">
    <?php echo htmlspecialchars(NetworkAddressRetention::privacyPageWords($networkAddressFacts), ENT_QUOTES, 'UTF-8'); ?>
</p>
<?php if ($networkAddressPlaces === []): ?>
    <p data-network-addresses="unreadable">
        The list of places could not be read just now. Please ask the organisation for it.
    </p>
<?php else: ?>
    <ul data-network-addresses="listed">
        <?php foreach ($networkAddressPlaces as $networkAddressPlace): ?>
            <li><?php echo htmlspecialchars($networkAddressPlace['words'], ENT_QUOTES, 'UTF-8'); ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
