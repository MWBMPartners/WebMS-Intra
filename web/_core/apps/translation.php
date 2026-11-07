<?php
// Path: _core/apps/translation.php
/**
 * -----------------------------------------------------------------------------
 * The Translation app's entry in Admin → Apps 🌐
 * -----------------------------------------------------------------------------
 * `settingKey` is the ONE on/off switch for content translation (D-075, #792):
 * Admin → Apps writes it, `AppRegistry::API_FOLDER_APPS` makes the router
 * refuse `api/translation/…` while it is off, and
 * `ContentTranslation::isSwitchedOn()` asks it before the Translate control is
 * drawn. It is seeded '0' in `web/_sql/full_schema.sql`, like every other app
 * that spends the customer's money.
 *
 * ⚠️ THE DESCRIPTION USED TO SAY "(prayer requests, announcements, …)", and the
 *    first half of that is untrue on purpose: a prayer request is deliberately
 *    NOT translatable (#485, D-049), because it is a member's own words, often
 *    about somebody else who cannot agree to them being sent to an outside
 *    company. This sentence is what an administrator reads on Admin → Apps when
 *    deciding whether to switch the app on, and on the "Translation is
 *    disabled" page, so it must say what the app really does.
 *    `ContentTranslation::SOURCES` is the list it describes.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/792
 * -----------------------------------------------------------------------------
 */
declare(strict_types=1);
return [
    'slug'        => 'translation',
    'name'        => 'Translation',
    'description' => 'Offers members a Translate button on the organisation\'s own published words '
                   . '(announcements, project descriptions and project updates), through an outside '
                   . 'translation company chosen for the whole installation by a global administrator. Each member switches it on for '
                   . 'their own account first. Prayer requests are never translated.',
    'icon'        => 'fa-solid fa-language',
    'color'       => '#9333ea',
    'category'    => 'communications',
    'industries'  => ['church', 'community', 'school', 'nonprofit'],
    'route'       => 'admin/translation',
    'settingKey'  => 'translation.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
