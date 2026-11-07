<?php
// Path: public_html/account/translation.php
/**
 * Account — user opts in to auto-translate user-generated content into
 * their UI locale. Off by default.
 *
 * @package   Portal\Account
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/278
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
// ⚠️ A class used by its SHORT name needs its `use` line in THIS file, and
//    the line only fails when it RUNS — `php -l` is happy without it (#536).
use Portal\Core\ContentTranslation;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        http_response_code(400);
        exit('Bad request');
    }
    $auto = isset($_POST['autoTranslate']) === true ? 1 : 0;
    $stmt = $db->prepare(
        'INSERT INTO tblUserTranslationPref (userID, autoTranslate) VALUES (?, ?) '
        . 'ON DUPLICATE KEY UPDATE autoTranslate = VALUES(autoTranslate)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('ii', $userId, $auto);
        $stmt->execute();
        $stmt->close();
    }
    $_SESSION['flash_msg']  = 'Translation preferences saved.';
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . Site::url('account/translation'));
    exit();
}

$row = null;
$stmt = $db->prepare('SELECT autoTranslate FROM tblUserTranslationPref WHERE userID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
$on = $row !== null && (int) $row['autoTranslate'] === 1;

// 🗣️ THE LANGUAGE THIS SENTENCE NAMES MUST BE THE ONE A TRANSLATION WOULD
//    REALLY BE ASKED FOR, and it was not. This page used to read the
//    `tblUsers.locale` column itself and print `?? 'en'` when it was empty —
//    so after migration 251 emptied that column for every account (#763,
//    D-060) it said "en" to everybody, while the real target is the member's
//    browser language. That is the consent sentence on the one page whose job
//    is informed permission to send text to an outside company, so a wrong
//    language here is worse than a wrong language anywhere else on the portal.
//
//    `ContentTranslation::readerLanguage()` is the ONE place that decides, and
//    it is what `translate-control.php` and the handler both ask. Never read
//    the column here again: two readers can disagree, and this one only has to
//    be wrong once.
$readerLanguage = ContentTranslation::readerLanguage($db, $userId);

// 🔌 IS THE ORGANISATION'S OWN SWITCH ON? (D-075, #792) The Translation app can
//    be switched off in Admin → Apps, and then no Translate button is drawn
//    and nothing is sent, whatever this member chooses. This page stays OPEN
//    while it is off, on purpose:
//      • the choice is the member's own permission, and switching the app off
//        must not take it away — an organisation that switches translation
//        back on next month should not have silently reset everybody's answer;
//      • and the member must still be able to WITHDRAW their permission at
//        any time, which a closed page would stop.
//    What it must not do is go on saying "With this on, a Translate button
//    appears" while none can, so it says the switch is off instead. Asked of
//    the ONE method the control and the handler ask, never read here.
$appSwitchedOn = ContentTranslation::isSwitchedOn();

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Translation preferences';
$pageSection = 'account';
$breadcrumbs = ['Dashboard' => Site::url(''), 'My Account' => Site::url('account'), 'Translation' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-language me-2"></i>Translation preferences</h1>

<?php if ($appSwitchedOn === false): ?>
    <div class="alert alert-info" role="status" id="translation-switched-off">
        <strong>Translation is switched off for this organisation at the moment.</strong>
        No Translate button appears and nothing is sent to a translation company,
        whatever you choose below. Your choice is kept, and applies if your
        organisation switches translation on again.
    </div>
<?php endif; ?>

<form method="post" class="card"><div class="card-body">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
    <?php
    // 🔒 THIS IS A CONSENT BOX, AND THE WORDS HAVE TO SAY SO (#485, D-049).
    //    It used to read "Auto-translate posts written in other languages into
    //    your UI locale", which described something the portal never did — the
    //    tick-box was saved and read by nothing at all until 4 November 2026 —
    //    and, worse, it did not say where the text GOES. Translating sends what
    //    you are reading to an outside company (whichever one your
    //    organisation has chosen), and the owner's decision of 4 November makes
    //    your own permission the thing that allows that. So the box has to
    //    explain it, or the permission is not informed.
    //
    //    Three facts a member needs and cannot get anywhere else, which is why
    //    all three are here rather than in the help pages:
    //      • nothing is sent until you press Translate yourself;
    //      • it only ever applies to what the organisation has published;
    //      • a prayer request is never translated, whatever this says.
    ?>
    <p class="text-secondary">
        With this on, a <strong>Translate</strong> button appears on announcements,
        project descriptions and project updates, offering them in your own language
        (<strong><?php
            // A NAME, not a code. This printed a bare `en` before #763 and
            // would now print a bare `fr` — on the one page whose job is
            // informed permission, where "Français" reads as a language and
            // "fr" reads as a typing error. `localeMeta()` answers null for a
            // code the portal does not know, and the code itself is then the
            // honest fallback.
            $rlMeta = \Portal\Core\I18n::localeMeta($readerLanguage);
            echo htmlspecialchars(
                (string) ($rlMeta['native'] ?? $readerLanguage),
                ENT_QUOTES,
                'UTF-8'
            );
        ?></strong>).
        The original always stays on the page, unchanged, above the translation.
    </p>
    <p class="text-secondary">
        <strong>Why we ask first.</strong> Translating sends the text to an outside
        translation company, so nothing is sent until you press the button, and nothing
        at all is sent while this is switched off. Your own messages are not affected,
        and <strong>prayer requests are never translated</strong> &mdash; a prayer request
        is somebody&rsquo;s own words, often about another person who has no way to agree
        to that.
    </p>
    <div class="form-check">
        <input type="checkbox" class="form-check-input" id="auto" name="autoTranslate" value="1" <?php echo $on === true ? 'checked' : ''; ?>>
        <label class="form-check-label" for="auto">
            Offer to translate things I am reading, and allow the text to be sent to the
            translation company for that
        </label>
    </div>
    <button class="btn btn-primary btn-sm mt-3" type="submit">Save</button>
</div></form>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
