<?php
// Path: _core/templates/translate-control.php
/**
 * -----------------------------------------------------------------------------
 * The "Translate" control — the ONE place it is drawn 🌐
 * -----------------------------------------------------------------------------
 * A page that shows a piece of the organisation's published words sets two
 * variables and requires this file:
 *
 *     $trTable = 'tblAnnouncements';
 *     $trId    = (int) $announcement['announcementID'];
 *     require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates'
 *           . DIRECTORY_SEPARATOR . 'translate-control.php';
 *
 * WHY THIS IS A SHARED PARTIAL AND NOT A FEW LINES ON EACH PAGE. The control
 * must not appear where the handler would refuse the press, and it must not be
 * absent where the handler would accept it. Two pages each holding their own
 * copy of that judgement is how they come to disagree — #647's lesson, where
 * the expenses payment page and its own test each kept a copy of one rule and
 * a change to the page passed every check. So the judgement is asked for
 * HERE, once, of `Portal\Core\ContentTranslation::whyNotOffered()`, which is
 * the same method the handler asks.
 *
 * It prints NOTHING when translation is not on offer, and that is deliberate
 * rather than a silent failure: the ordinary case is a member who has not
 * opted in, and a disabled button with an explanation on every announcement
 * would be nagging. The one place that explains the opt-in is the member's own
 * Account → Translation page.
 *
 * NO NONCE IS NEEDED HERE because there is no script in it. The behaviour is
 * in `/assets/js/portal-translate.js`, a file from the portal's own address,
 * and the element carries `data-` attributes only — never an `onclick` (#625).
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/485
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\ContentTranslation;
use Portal\Core\Site;

$trTable = isset($trTable) === true ? (string) $trTable : '';
$trId    = isset($trId) === true ? (int) $trId : 0;

$trUserId   = (int) (App::user()['userID'] ?? 0);
// 🗣️ The member's OWN stored language, not the interface's, and
//    `ContentTranslation::readerLanguage()` is the ONE place that decides it.
//    ⚠️ This comment used to say "for why those are different on this portal
//    today (#763)" — the interface language did not follow a member's choice
//    at all. #763 fixed that on 5 November 2026, so the reason is no longer
//    that one. They can still differ for a real reason: this is read straight
//    from the database, while the interface language is the answer the request
//    reached, so a member whose stored language has no translation file gets a
//    fallback interface and a translation in the language they asked for.
$trLanguage = ContentTranslation::readerLanguage(App::db(), $trUserId);

$trWhyNot = ContentTranslation::whyNotOffered([
    'signedIn'       => $trUserId > 0,
    // 🔌 The Translation app's own switch, asked through the ONE method the
    //    handler asks too, and the same switch the router refuses
    //    `api/translation/…` on (D-075, #792). This used to read
    //    `App::settings()['translation']['enabled']` here and compare it with
    //    '1' — a second reading that could disagree with the router's.
    'enabled'        => ContentTranslation::isSwitchedOn(),
    'optedIn'        => ContentTranslation::readerOptedIn($trUserId),
    'sourceTable'    => $trTable,
    'targetLanguage' => $trLanguage,
]);

// 🤫 Nothing at all when it is not on offer, and nothing when there is no row
//    to name. See the file comment for why this is silence rather than a
//    disabled button.
if ($trWhyNot === '' && $trId > 0):
?>
<div class="portal-translate mt-2"
     data-portal-translate
     data-url="<?php echo htmlspecialchars(Site::url('api/translation/content'), ENT_QUOTES, 'UTF-8'); ?>"
     data-table="<?php echo htmlspecialchars($trTable, ENT_QUOTES, 'UTF-8'); ?>"
     data-id="<?php echo $trId; ?>"
     data-language="<?php echo htmlspecialchars($trLanguage, ENT_QUOTES, 'UTF-8'); ?>"
     data-csrf="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <button type="button"
            class="btn btn-outline-secondary btn-sm"
            data-portal-translate-button
            data-hide-label="Hide the translation"
            data-show-label="Show the translation">
        <i class="fa-solid fa-language me-1"></i>Translate
    </button>
    <span class="small text-secondary ms-2" data-portal-translate-status></span>
    <?php
    // 📄 The translation lands here, as plain text, BELOW the original, which
    //    is never touched. The outside company's words are set with
    //    `textContent` by the script, so they can never become markup in this
    //    page. See the script's own file comment.
    ?>
    <div class="portal-translate-output border-start border-3 ps-3 mt-2 text-body-secondary"
         style="white-space: pre-wrap;"
         data-portal-translate-output
         hidden></div>
</div>
<?php
endif;
