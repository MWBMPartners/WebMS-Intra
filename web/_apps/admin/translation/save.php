<?php
// Path: public_html/admin/translation/save.php
/**
 * Admin — Translation settings save handler.
 *
 * WHO MAY SAVE HERE
 * -----------------------------------------------------------------------------
 * Every setting this handler saves is portal-wide: it is written with nothing
 * in the siteID column, so it is the value EVERY organisation on this
 * installation uses. That includes the translation provider, its account keys,
 * the address of a self-hosted translation server, and the two monthly limits
 * (money, and the number of requests sent to the provider — #762).
 *
 * Until 11 September 2026 the only check was App::isAdmin(), which is true for
 * an administrator of a SINGLE organisation as well as for a global
 * administrator (see web/_core/App.php). So an administrator of any one
 * organisation could send every organisation's text to a translation account or
 * server of their choosing, or lift the monthly spending limit. The owner
 * decided that settings affecting every organisation are for a global
 * administrator only. Anybody else is now refused, is told why on screen, and
 * nothing is saved.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/278
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\Translation;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

// 🚧 Portal-wide settings are for a global administrator only — see "WHO MAY
//    SAVE HERE" in the file header. The refusal gives the reason rather than a
//    bare "forbidden": an administrator who presses Save and is told nothing
//    assumes the portal is broken and tries again. It comes after the
//    form-token check, so a forged request from another website cannot fill
//    the activity log with refusals.
//
//    What this cannot do: the page that shows the form
//    (web/_apps/admin/translation/index.php) was not changed, so an
//    administrator of one organisation still sees a Save button there. This
//    refusal is what actually enforces the rule.
if (App::isRootAdmin() === false) {
    Logger::activity(
        'SettingsGroupSaveRefused',
        'Refused: portal-wide settings group "translation" may only be changed by a global administrator',
        $_SESSION['user_id'] ?? null
    );
    $_SESSION['flash_msg']  = 'These settings are portal-wide: they apply to every '
        . 'organisation on this installation, not only yours. Only a global '
        . 'administrator can change them. Your own organisation\'s settings '
        . 'are on the main Settings page and are unaffected.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/translation'));
    exit();
}

$db = App::db();
$upsert = static function (mysqli $db, string $key, string $value, bool $isSensitive): void {
    if ($isSensitive === true && $value !== '' && function_exists('encrypt_setting') === true) {
        $value = encrypt_setting($value);
    }
    $sens = $isSensitive === true ? 1 : 0;
    $stmt = $db->prepare('SELECT settingID FROM tblSettings WHERE settingKey = ? AND siteID IS NULL LIMIT 1');
    if ($stmt === false) {
        return;
    }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->bind_result($id);
    $exists = $stmt->fetch() === true;
    $stmt->close();
    if ($exists === true) {
        $u = $db->prepare('UPDATE tblSettings SET settingValue = ?, isSensitive = ?, updatedAt = NOW() WHERE settingID = ?');
        if ($u !== false) {
            $u->bind_param('sii', $value, $sens, $id);
            $u->execute();
            $u->close();
        }
    } else {
        $u = $db->prepare('INSERT INTO tblSettings (settingKey, settingValue, isSensitive, siteID, updatedAt) VALUES (?, ?, ?, NULL, NOW())');
        if ($u !== false) {
            $u->bind_param('ssi', $key, $value, $sens);
            $u->execute();
            $u->close();
        }
    }
};

$provider = (string) ($_POST['provider'] ?? 'anthropic');
if (in_array($provider, ['anthropic','openai','google','deepl','libre'], true) === false) {
    $provider = 'anthropic';
}

// 💰 A FIGURE THAT CANNOT BE READ IS REFUSED, NOT COERCED — BECAUSE THE
//    VALUE IT WOULD BECOME IS "NO LIMIT", THE MOST EXPENSIVE OUTCOME THERE IS.
//    The first version did `(int) round(((float) ($_POST['monthCap'] ?? 0)) * 100)`
//    and then clamped a negative to 0. `(float) 'abc'` and `(float) ''` are
//    both 0.0, and 0 means NO LIMIT here — so an empty box, a negative number
//    and a typo ALL switched the cap off, silently, with no refusal and no line
//    in the activity log. Measured through the real page: "-5", "abc" and ""
//    each stored 0.
//
//    **An empty box needs no crafted request.** The field is prefilled, and
//    `min="0"` does not stop an EMPTY field being submitted, so an
//    administrator who clears it to start again and presses Save turns the
//    limit off. D-049 makes the limit's trustworthiness a condition of
//    shipping this feature, so coercion is not good enough.
//
//    Typing `0` deliberately is still how "no limit" is said, and that is why
//    the pattern below accepts it: what is refused is text that is not a
//    number at all.
//
//    **NINE DIGITS, not seven, so that a FIGURE always meets the figure's own
//    refusal.** With seven, `99999999` was refused by this test — "it has to
//    be a figure in pounds" — when it plainly is one, and what is wrong with
//    it is that it is above the maximum, which the next gate says properly.
//    Nothing in that range can overflow: nine digits of pounds is under
//    PHP_INT_MAX in pence by a wide margin.
// ⚠️ ASK WHETHER IT IS A STRING FIRST. A request chooses the TYPE of what it
//    sends as well as the value, so `monthCap[]=7` arrives as an ARRAY — and
//    `(string)` on an array is "Array" plus a PHP warning in the error log
//    (714 bytes of it, measured). The refusal below was already correct for
//    it, because "Array" is not a figure; this only stops the noise, and it is
//    the same shape as the 27 October sign-in fault where a request chose an
//    array and something threw before the gate.
$capPosted = $_POST['monthCap'] ?? '';
$capRaw    = is_string($capPosted) === true ? trim($capPosted) : '';
if (preg_match('/^\d{1,9}(\.\d{1,2})?$/', $capRaw) !== 1) {
    Logger::activity(
        'TranslationCapRefused',
        'A monthly translation limit that could not be read as a figure was refused.'
    );
    $_SESSION['flash_msg'] = 'Nothing was saved. The monthly translation limit has to be a '
        . 'figure in pounds — for example 50, or 12.50. Enter 0 if you want no limit at all.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/translation'));
    exit();
}
$capPence = (int) round(((float) $capRaw) * 100);

// 💰 THE MONTHLY LIMIT IS REFUSED ABOVE THE HARD MAXIMUM, NEVER REDUCED.
//    #668's rule, and the reason is that quietly reducing a number somebody
//    else typed is how a person comes to believe a limit is in force when it is
//    not. The refusal happens BEFORE anything is written, so a bad figure does
//    not take the provider and the API keys down with it.
//
//    Zero is allowed and means NO LIMIT, so it is the LARGEST value, not the
//    smallest — #668's zero trap. **Be exact about where that is handled,
//    because an earlier version of this comment was not:** nothing in THIS
//    file treats zero specially, and it does not need to. The pattern above
//    makes a deliberate `0` the only way to reach it, and the comparison here
//    is only against the hard maximum, which zero can never exceed. The
//    three-tier arithmetic that reads zero as the largest value lives in
//    `Portal\Core\SettingLimit`, which `Translation::capPence()` asks with
//    `$zeroIsLargest` set.
if ($capPence > Translation::MAX_CAP_PENCE) {
    Logger::activity(
        'TranslationCapRefused',
        'A monthly translation limit above the most this portal allows was refused.'
    );
    // 💷 In the installation's currency (#966): one API key pays for every
    //    organisation, so the limit is the installation's money, not "£".
    $_SESSION['flash_msg'] = 'Nothing was saved. The monthly translation limit cannot be more than '
        . \Portal\Core\Currency::format(Translation::MAX_CAP_PENCE, \Portal\Core\Currency::ofInstallation())
        . '. Enter 0 for no limit, or a smaller figure.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/translation'));
    exit();
}
// ⚠️ NO CLAMP HERE ANY MORE. There was a `if ($capPence < 0) { $capPence = 0; }`
//    on this line, and it was the fault above in miniature: it turned a
//    negative figure into "no limit" rather than refusing it. The pattern
//    above cannot match a negative, so a clamp here would now be a clause that
//    cannot fail — which is a shape this repository has had to delete more
//    than once.

// 🔢 THE LIMIT ON THE NUMBER OF REQUESTS, judged exactly as the money one is
//    and for the same reasons (#762, D-059). It is the limit that still works
//    when a provider reports no cost, so a figure that silently became "no
//    limit" here would remove the only limit LibreTranslate has.
//
//    • A STRING FIRST: `monthCalls[]=7` arrives as an array (see the money
//      field above for the 27 October shape).
//    • WHOLE NUMBERS ONLY. A request is not divisible, and `12.5` read as 12
//      or 13 is the portal choosing a figure nobody typed. Refused with the
//      reason, like `abc`, an empty box and a minus sign — each of which
//      `(int)` would have made 0, which is NO LIMIT.
//    • NINE DIGITS for the same reason as the money field: so that a FIGURE
//      that is merely too large meets the "too large" refusal, which says what
//      the maximum is, rather than being called "not a number".
//    • A DIFFERENT activity type from the money refusal, so the log says which
//      of the two limits somebody tried to set wrongly.
//    • Both fields are judged BEFORE anything is written, so a bad count does
//      not take the provider, the keys or the money limit down with it.
$callsPosted = $_POST['monthCalls'] ?? '';
$callsRaw    = is_string($callsPosted) === true ? trim($callsPosted) : '';
if (preg_match('/^\d{1,9}$/', $callsRaw) !== 1) {
    Logger::activity(
        'TranslationCallCapRefused',
        'A monthly limit on the number of translations that could not be read as a whole number was refused.'
    );
    $_SESSION['flash_msg'] = 'Nothing was saved. The monthly limit on the number of translations has to be '
        . 'a whole number — for example 1000. Enter 0 if you want no limit on the number at all.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/translation'));
    exit();
}
$callCap = (int) $callsRaw;
if ($callCap > Translation::MAX_CALL_CAP) {
    Logger::activity(
        'TranslationCallCapRefused',
        'A monthly limit on the number of translations above the most this portal allows was refused.'
    );
    $_SESSION['flash_msg'] = 'Nothing was saved. The monthly limit on the number of translations cannot be more than '
        . number_format(Translation::MAX_CALL_CAP)
        . '. Enter 0 for no limit, or a smaller number.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/translation'));
    exit();
}

$upsert($db, 'translation.enabled',        isset($_POST['enabled']) === true ? '1' : '0', false);
$upsert($db, 'translation.provider',       $provider, false);
$upsert($db, 'translation.monthCapPence',  (string) $capPence, false);
$upsert($db, 'translation.monthCallCap',   (string) $callCap, false);
$upsert($db, 'translation.anthropic.model', trim((string) ($_POST['anth_model']  ?? 'claude-haiku-4-5-20251001')), false);
$upsert($db, 'translation.openai.model',    trim((string) ($_POST['oa_model']    ?? 'gpt-4o-mini')), false);
$upsert($db, 'translation.libre.baseUrl',   trim((string) ($_POST['libre_base']  ?? 'https://libretranslate.com')), false);

foreach (
    [
        'translation.anthropic.apiKey' => 'anth_key',
        'translation.openai.apiKey'    => 'oa_key',
        'translation.google.apiKey'    => 'goog_key',
        'translation.deepl.apiKey'     => 'deepl_key',
        'translation.libre.apiKey'     => 'libre_key',
    ] as $settingKey => $postKey
) {
    $val = trim((string) ($_POST[$postKey] ?? ''));
    if ($val !== '') {
        $upsert($db, $settingKey, $val, true);
    }
}

$_SESSION['flash_msg']  = 'Translation settings saved.';
$_SESSION['flash_type'] = 'success';
header('Location: ' . Site::url('admin/translation'));
exit();
