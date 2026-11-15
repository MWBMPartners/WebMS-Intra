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
 * Portal\Core\NetworkAddresses::places(). This file adds no words about any
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

use Portal\Core\NetworkAddresses;

$networkAddressPlaces = NetworkAddresses::places();
?>
<p>
    A network address (also called an IP address) is the number the internet uses to send a page to the device
    asking for it. The portal records the address a visit or a request came from, to keep the portal secure,
    to look into misuse and to help when something goes wrong. Beside an account and a time, an address can
    identify a person, so it is handled as personal data. Where one is kept, and for how long:
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
