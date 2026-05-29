<?php
/**
 * -----------------------------------------------------------------------------
 * Welsh (cy) Translation File 🏴󠁧󠁢󠁷󠁬󠁳󠁿
 * -----------------------------------------------------------------------------
 * Proof-of-concept Welsh translation. Demonstrates the i18n framework with a
 * second language. Missing keys will fall back to the English baseline.
 *
 * @package   Portal\Lang
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.7.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

return [

    // =========================================================================
    // 🧭 Navigation
    // =========================================================================
    'nav.dashboard'         => 'Dangosfwrdd',
    'nav.admin'             => 'Gweinyddu',
    'nav.admin_dashboard'   => 'Dangosfwrdd Gweinyddu',
    'nav.error_log'         => 'Cofnod Gwallau',
    'nav.activity_log'      => 'Cofnod Gweithgaredd',
    'nav.user_management'   => 'Rheoli Defnyddwyr',
    'nav.migrations'        => 'Ymfudiadau',
    'nav.settings'          => 'Gosodiadau',
    'nav.my_account'        => 'Fy Nghyfrif',
    'nav.sign_in'           => 'Mewngofnodi',
    'nav.sign_out'          => 'Allgofnodi',
    'nav.toggle_navigation' => 'Toglo llywio',
    'nav.toggle_dark_mode'  => 'Toglo modd tywyll',
    'nav.change_language'   => 'Newid iaith',

    // 🌐 i18n meta (#210) — Welsh translation provided by the cy.php
    //    translator. If untranslated, the badge text falls back to
    //    English which is acceptable for a meta-message.
    'i18n.partial_coverage_tooltip' => 'Mae\'r iaith hon wedi\'i chyfieithu :percent%. Bydd peth testun yn ymddangos yn Saesneg lle nad oes cyfieithiad.',

    // =========================================================================
    // 🔐 Authentication
    // =========================================================================
    'auth.sign_in'                  => 'Mewngofnodi',
    'auth.sign_in_title'            => 'Mewngofnodi',
    'auth.username_or_email'        => 'Enw Defnyddiwr neu E-bost',
    'auth.password'                 => 'Cyfrinair',
    'auth.forgot_password'          => 'Wedi anghofio\'ch cyfrinair?',
    'auth.forgot_password_title'    => 'Anghofio Cyfrinair',
    'auth.reset_password_title'     => 'Ailosod Cyfrinair',
    'auth.sign_in_with_ms365'       => 'Mewngofnodi gyda Microsoft 365',
    'auth.sign_in_with_google'      => 'Mewngofnodi gyda Google',
    'auth.sign_in_with_passkey'     => 'Mewngofnodi gyda Passkey',
    'auth.or'                       => 'neu',
    'auth.or_use_passkey'           => 'neu defnyddiwch passkey',
    'auth.password_reset_success'   => 'Mae eich cyfrinair wedi\'i ddiweddaru. Mewngofnodwch os gwelwch yn dda.',
    'auth.invalid_session_token'    => 'Tocyn sesiwn annilys. Ceisiwch eto.',
    'auth.captcha_failed'           => 'Methwyd dilysu captcha.',
    'auth.enter_credentials'        => 'Nodwch eich enw defnyddiwr neu e-bost a chyfrinair.',
    'auth.invalid_credentials'      => 'Manylion annilys.',
    'auth.too_many_attempts'        => 'Gormod o ymgeisiau aflwyddiannus. Ceisiwch eto mewn :minutes munud.',
    'auth.new_password'             => 'Cyfrinair Newydd',
    'auth.confirm_password'         => 'Cadarnhau Cyfrinair',
    'auth.reset_password'           => 'Ailosod Cyfrinair',
    'auth.send_reset_link'          => 'Anfon Dolen Ailosod',
    'auth.email_address'            => 'Cyfeiriad E-bost',
    'auth.back_to_login'            => 'Yn ôl i Fewngofnodi',

    // =========================================================================
    // 🏠 Dashboard
    // =========================================================================
    'dashboard.title'               => 'Dangosfwrdd',

    // =========================================================================
    // 🚫 Error Pages
    // =========================================================================
    'error.page_not_found'          => 'Tudalen Heb ei Chanfod',
    'error.page_not_found_text'     => 'Nid yw\'r dudalen rydych yn chwilio amdani yn bodoli neu efallai ei bod wedi\'i symud.',
    'error.access_denied'           => 'Mynediad Wedi\'i Wrthod',
    'error.access_denied_text'      => 'Nid oes gennych ganiatâd i gael mynediad i\'r dudalen hon. Cysylltwch â\'ch gweinyddwr os credwch fod hyn yn wall.',
    'error.server_error'            => 'Gwall Gweinydd',
    'error.something_wrong'         => 'Aeth Rhywbeth o\'i Le',
    'error.server_error_text'       => 'Digwyddodd gwall annisgwyl. Mae\'r mater wedi\'i gofnodi a bydd ein tîm yn edrych arno. Ceisiwch eto yn nes ymlaen.',
    'error.return_to_dashboard'     => 'Dychwelyd i\'r Dangosfwrdd',

    // =========================================================================
    // 🔘 Common UI
    // =========================================================================
    'common.submit'                 => 'Cyflwyno',
    'common.save'                   => 'Cadw',
    'common.cancel'                 => 'Canslo',
    'common.delete'                 => 'Dileu',
    'common.edit'                   => 'Golygu',
    'common.add'                    => 'Ychwanegu',
    'common.close'                  => 'Cau',
    'common.confirm'                => 'Cadarnhau',
    'common.back'                   => 'Yn ôl',
    'common.next'                   => 'Nesaf',
    'common.previous'               => 'Blaenorol',
    'common.search'                 => 'Chwilio',
    'common.loading'                => 'Yn llwytho...',
    'common.no_results'             => 'Dim canlyniadau.',
    'common.yes'                    => 'Ie',
    'common.no'                     => 'Na',
    'common.all_rights_reserved'    => 'Cedwir pob hawl.',

    // =========================================================================
    // 📐 Formatting
    // =========================================================================
    'format.date.short'             => 'd/m/Y',
    'format.date.medium'            => 'j M Y',
    'format.date.long'              => 'l, j F Y',
    'format.datetime.short'         => 'd/m/Y H:i',
    'format.datetime.medium'        => 'j M Y, H:i',
    'format.datetime.long'          => 'l, j F Y \\a\\m H:i',
    'format.decimal_point'          => '.',
    'format.thousands_separator'    => ',',
    'format.currency_position'      => 'before',

];
