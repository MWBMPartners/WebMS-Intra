<?php
// Path: _core/MemberLimits.php
/**
 * -----------------------------------------------------------------------------
 * The three limits on Admin, Settings, Member limits 🚧 (#989, #992; D-171, D-172)
 * -----------------------------------------------------------------------------
 * The ONE list of the limits that page shows and saves, and the ONE answer to
 * whether the general settings editor (`/settings`) may touch them:
 *
 *   - `rota.swap.maxOpenPerDuty`    open swap requests waiting for one duty
 *   - `rota.swap.maxRequestsPerDay` swap requests one member may make in a day
 *   - `giving.online.maxGift`       the largest online gift, in hundredths
 *
 * The RULE for each lives in its own class (`RotaSwaps`, `Giving`); this file
 * reads their constants and never writes a key, default or hard maximum a
 * second time.
 *
 * WHY THE GENERAL EDITOR IS REFUSED THEM (round 1 of the independent check).
 * The Member limits page holds an organisation to the installation's figure,
 * refuses 0 and RECORDS each refusal. `/settings` did none of that: an
 * organisation's administrator stored 500 requests a day and a 99,999,999.99
 * gift ceiling on their own rows, and a global administrator stored 0 on the
 * installation's row — switching swap requests and online gifts off for
 * every organisation that follows it, with nothing recorded. The readers cap
 * a number above the installation's, so the 500 was never OBEYED, but it sat
 * in the database looking effective: the quiet clamp the limits rule exists
 * to prevent (#668, D-013). So the editor refuses change, add AND delete of
 * all three, at both levels, through `lockedInGeneralEditor()`, the same
 * shape the other lock methods in `web/_core/` use.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/989
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class MemberLimits
{
    /**
     * What somebody is told when they try one of these on the general settings
     * page. It names the page that DOES write them and never says who may.
     */
    public const LOCKED_MESSAGE = 'The rota swap request limits and the largest online gift are set on Admin, Settings, '
        . 'Member limits, where a figure is checked against what the installation allows and a refusal is recorded. '
        . 'They are not changed here.';

    /** The three keys, the ONE list `lockedInGeneralEditor()` reads. */
    public const KEYS = [RotaSwaps::OPEN_PER_DUTY_KEY, RotaSwaps::PER_DAY_KEY, Giving::ONLINE_GIFT_MAX_KEY];

    /**
     * The limits, as the page draws and its handler saves them, keyed by the
     * name the form posts. `min` is the least each may be: never 0, because
     * D-171 rules out "no limit" and D-172 rules out removing the ceiling;
     * the gift's least is 1.00, the online form's own smallest gift, because a
     * ceiling below that turns every gift away. `inForce` is what the
     * organisation being served is held to now, from the class's own reader.
     *
     * @return array<string, array{key:string, default:int, hardMax:int, money:bool, min:int, label:string, help:string, inForce:int}>
     */
    public static function all(): array
    {
        $rota = RotaSwaps::limits();

        return [
            'open-per-duty' => [
                'key'     => RotaSwaps::OPEN_PER_DUTY_KEY,
                'default' => RotaSwaps::OPEN_PER_DUTY_DEFAULT,
                'hardMax' => RotaSwaps::OPEN_PER_DUTY_HARD_MAX,
                'money'   => false,
                'min'     => 1,
                'label'   => 'Open swap requests waiting for one duty',
                'help'    => 'An open request is offered to every member, and an e-mail is attempted to each one who has rota swap e-mails switched on, so this stops one duty being offered to everybody again and again.',
                'inForce' => $rota['openPerDuty'],
            ],
            'per-day' => [
                'key'     => RotaSwaps::PER_DAY_KEY,
                'default' => RotaSwaps::PER_DAY_DEFAULT,
                'hardMax' => RotaSwaps::PER_DAY_HARD_MAX,
                'money'   => false,
                'min'     => 1,
                'label'   => 'Swap requests one member may make in a day',
                'help'    => 'Named and open requests together, counted on your organisation\'s own calendar day.',
                'inForce' => $rota['perDay'],
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
    }

    /**
     * 🔒 Is this settings key written only by Admin, Settings, Member limits?
     *
     * The general settings editor asks this before changing, adding, deleting
     * or drawing a row, and refuses when it answers true. Both levels, any
     * capitals (a server may store names in lower case).
     */
    public static function lockedInGeneralEditor(string $key): bool
    {
        foreach (self::KEYS as $locked) {
            if (strcasecmp($key, $locked) === 0) {
                return true;
            }
        }

        return false;
    }
}
