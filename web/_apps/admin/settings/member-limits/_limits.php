<?php
// Path: _apps/admin/settings/member-limits/_limits.php
/**
 * -----------------------------------------------------------------------------
 * The three limits Admin, Settings, Member limits shows and saves 🚧
 * (#989, #992; D-171, D-172)
 * -----------------------------------------------------------------------------
 * Required by `index.php` and `save.php` so the page and its handler name the
 * same settings with the same bounds; it is not an address (no route points
 * at it). The RULE for each lives in its own class — `RotaSwaps` and
 * `Giving` — and this file only reads their constants: the keys, defaults and
 * hard maxima are never written here a second time.
 *
 * `min` is the least each may be. 0 is never allowed: D-171 rules out "no
 * limit" and D-172 rules out removing the ceiling, so 0 is refused rather
 * than read as "none". The gift's least is 1.00 (100 hundredths), the online
 * form's own smallest gift, because a ceiling below that turns every gift
 * away.
 * `inForce` is what the organisation being served is held to now, read
 * through the class's own reader.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/989
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Giving;
use Portal\Core\RotaSwaps;

$rotaInForce = RotaSwaps::limits();

return [
    'open-per-duty' => [
        'key'     => RotaSwaps::OPEN_PER_DUTY_KEY,
        'default' => RotaSwaps::OPEN_PER_DUTY_DEFAULT,
        'hardMax' => RotaSwaps::OPEN_PER_DUTY_HARD_MAX,
        'money'   => false,
        'min'     => 1,
        'label'   => 'Open swap requests waiting for one duty',
        'help'    => 'An open request is e-mailed to every member who has rota swap e-mails switched on, so this stops one duty being offered to everybody again and again.',
        'inForce' => $rotaInForce['openPerDuty'],
    ],
    'per-day' => [
        'key'     => RotaSwaps::PER_DAY_KEY,
        'default' => RotaSwaps::PER_DAY_DEFAULT,
        'hardMax' => RotaSwaps::PER_DAY_HARD_MAX,
        'money'   => false,
        'min'     => 1,
        'label'   => 'Swap requests one member may make in a day',
        'help'    => 'Named and open requests together, counted on your organisation\'s own calendar day.',
        'inForce' => $rotaInForce['perDay'],
    ],
    'online-gift' => [
        'key'     => Giving::ONLINE_GIFT_MAX_KEY,
        'default' => Giving::ONLINE_GIFT_MAX_DEFAULT,
        'hardMax' => Giving::ONLINE_GIFT_MAX_HARD_MAX,
        'money'   => true,
        'min'     => 100,
        'label'   => 'Largest single gift through the online giving form',
        'help'    => 'A larger gift is turned away with a message asking the giver to contact the office. Pledges are paid at their own amount and are not affected.',
        'inForce' => Giving::onlineGiftCeiling(),
    ],
];
