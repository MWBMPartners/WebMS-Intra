<?php
// Path: apps/auth/account/index.php
/**
 * -----------------------------------------------------------------------------
 * My Account Page 👤
 * -----------------------------------------------------------------------------
 * Displays the current user's profile information with three card sections:
 *   1. Profile Info   — editable full name, email, phone number
 *   2. Change Password — current + new password with policy requirements
 *   3. Account Info   — read-only created date, last login, roles
 *
 * Uses header.php / footer.php templates (protected route).
 * -----------------------------------------------------------------------------
 * @package    Portal\Auth
 * @author     MWBM Partners Ltd (t/a MWservices)
 * @copyright  2025-2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version    0.3.3
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Avatar;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\WebAuthn;

/**
 * 🏷️ Convert provider key to display label.
 */
function providerLabel(string $provider): string
{
    $labels = [
        'ms365'  => 'Microsoft 365',
        'google' => 'Google',
        'local'  => 'Local',
    ];
    return $labels[$provider] ?? ucfirst($provider);
}

/**
 * 🎨 Provider icon class for Font Awesome.
 */
function providerIcon(string $provider): string
{
    $icons = [
        'ms365'  => 'fa-brands fa-microsoft',
        'google' => 'fa-brands fa-google',
        'local'  => 'fa-solid fa-key',
    ];
    return $icons[$provider] ?? 'fa-solid fa-link';
}

// -----------------------------------------------------------------------------
// 1. 🔒 Require authentication
// -----------------------------------------------------------------------------

Auth::requireLogin();

// -----------------------------------------------------------------------------
// 2. 📊 Load user data
// -----------------------------------------------------------------------------

$user   = App::user();
$userId = (int) $_SESSION['user_id'];

// 🕐 Fetch last login from tblLocalAccounts
$lastLogin = null;
$stmt = $mysqli->prepare(
    'SELECT lastLogin FROM tblLocalAccounts WHERE userID = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $localRow = $result->fetch_assoc();
    $stmt->close();
    if ($localRow !== null && ($localRow['lastLogin'] ?? '') !== '') {
        $lastLogin = $localRow['lastLogin'];
    }
}

// 🏷️ Fetch user roles, across EVERY organisation this person belongs to
//    (#516 — a role now belongs to one organisation, so "Treasurer" alone
//    would no longer say which organisation it applies to). Shown as
//    "Treasurer — Organisation A" on this page.
$roles = [];
$roleStmt = $mysqli->prepare(
    'SELECT R.roleName, S.siteName FROM tblUserRoles UR '
    . 'JOIN tblRoles R ON R.roleID = UR.roleID AND R.siteID = UR.siteID '
    . 'JOIN tblSites S ON S.siteID = UR.siteID '
    . 'WHERE UR.userID = ? ORDER BY S.siteName, R.roleName'
);
if ($roleStmt !== false) {
    $roleStmt->bind_param('i', $userId);
    $roleStmt->execute();
    $roleResult = $roleStmt->get_result();
    while ($roleRow = $roleResult->fetch_assoc()) {
        $roles[] = $roleRow['roleName'] . ' — ' . $roleRow['siteName'];
    }
    $roleStmt->close();
}

// 🔗 Fetch linked accounts
$linkedAccounts = Auth::getLinkedAccounts($userId, $mysqli);

// 🔐 Fetch WebAuthn credentials
$webauthnCreds = [];
$waStmt = $mysqli->prepare(
    'SELECT credID, friendlyName, createdAt, lastUsedAt FROM tblWebAuthnCredentials WHERE userID = ? ORDER BY createdAt ASC'
);
if ($waStmt !== false) {
    $waStmt->bind_param('i', $userId);
    $waStmt->execute();
    $webauthnCreds = $waStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $waStmt->close();
}

// 🔢 Count total login methods (for safety check display)
$loginMethodCount = Auth::countLoginMethods($userId, $mysqli);

// 🔐 Fetch TOTP two-factor authentication status (#B5a — was previously
// wired up (setup.php / disable.php / verify.php all work) but nothing on
// this page linked to them, so nobody could discover or manage 2FA).
$totpEnabled = false;
$totpStmt = $mysqli->prepare('SELECT totpEnabled FROM tblUsers WHERE userID = ? LIMIT 1');
if ($totpStmt !== false) {
    $totpStmt->bind_param('i', $userId);
    $totpStmt->execute();
    $totpRow = $totpStmt->get_result()->fetch_assoc();
    $totpStmt->close();
    $totpEnabled = ($totpRow !== null && (int) ($totpRow['totpEnabled'] ?? 0) === 1);
}

// 🔑 How many backup codes this person has left (#613). A backup code signs
//    them in once if they lose their phone. Only the NUMBER can be shown: the
//    database holds a one-way fingerprint of each code, so the codes
//    themselves cannot be read back by anybody, this page included.
//    The number says how many are stored, not that the person has them
//    written down. Before #613 the set-up page made eight and never showed
//    them, so somebody who switched this on earlier is told "8" for codes
//    they have never seen. That is why the wording below tells them to make
//    a new set if they did not save theirs.
$totpCodesLeft = 0;
if ($totpEnabled === true) {
    $codesStmt = $mysqli->prepare('SELECT COUNT(*) AS codesLeft FROM tblTotpBackupCodes WHERE userID = ? AND isUsed = 0');
    if ($codesStmt !== false) {
        $codesStmt->bind_param('i', $userId);
        $codesStmt->execute();
        $totpCodesLeft = (int) ($codesStmt->get_result()->fetch_assoc()['codesLeft'] ?? 0);
        $codesStmt->close();
    }
}

// 🔑 CAN THIS PERSON ADD A PASSWORD TO THEIR OWN ACCOUNT (#731)?
//    Asked of `Portal\Core\SelfServicePassword`, which is the ONE place that
//    decides it; this page only draws a card pointing at the page that does it.
//
//    The card is drawn for the answer "yes" and for the answer "yes, once you
//    have signed in again" — the second is the ordinary case, because the proof
//    of identity is good for five minutes. Comparing the answer with the
//    class's own constant is recognising it, not deciding it again.
$addPasswordWhy  = \Portal\Core\SelfServicePassword::whyNotAllowed(
    \Portal\Core\SelfServicePassword::factsFor($mysqli, $userId)
);
$canAddPassword  = $addPasswordWhy === ''
    || $addPasswordWhy === \Portal\Core\SelfServicePassword::MSG_NEEDS_PROOF;

// 📋 Check if local account exists
$hasLocalAccount = false;
$laCheck = $mysqli->prepare('SELECT 1 FROM tblLocalAccounts WHERE userID = ? LIMIT 1');
if ($laCheck !== false) {
    $laCheck->bind_param('i', $userId);
    $laCheck->execute();
    $hasLocalAccount = ($laCheck->get_result()->num_rows > 0);
    $laCheck->close();
}

// 🔔 Load notification preferences. A choice with nothing stored reads as ON,
//    matching WebPush::notifyPrefAllows(). `emailDigest` and `expenseUpdates`
//    used to be listed here too; both are retired (#662, #667) and nothing
//    on this page draws them any more.
$notifyPrefs = [
    'eventReminders'   => true,
    'givingStatements' => true,
];
$npStmt = $mysqli->prepare('SELECT notifyPrefs FROM tblUsers WHERE userID = ? LIMIT 1');
if ($npStmt !== false) {
    $npStmt->bind_param('i', $userId);
    $npStmt->execute();
    $npRow = $npStmt->get_result()->fetch_assoc();
    $npStmt->close();
    if ($npRow !== null && $npRow['notifyPrefs'] !== null) {
        $decoded = json_decode($npRow['notifyPrefs'], true);
        if (is_array($decoded) === true) {
            $notifyPrefs = array_merge($notifyPrefs, $decoded);
        }
    }
}

// -----------------------------------------------------------------------------
// 3. 📋 Resolve password policy for display + the strength meter
// -----------------------------------------------------------------------------

$policy      = Auth::passwordPolicy();
$policyItems = $policy['rules'];

// -----------------------------------------------------------------------------
// 4. 📨 Flash messages from save handlers
// -----------------------------------------------------------------------------

$successMsg = '';
$errorMsg   = '';

if (isset($_GET['success']) === true && $_GET['success'] === '1') {
    $successMsg = 'Your profile has been updated.';
}
if (isset($_GET['pwchanged']) === true && $_GET['pwchanged'] === '1') {
    $successMsg = 'Your password has been changed.';
}
if (isset($_GET['unlinked']) === true && $_GET['unlinked'] === '1') {
    $successMsg = 'Account has been unlinked successfully.';
}
if (isset($_GET['wa_deleted']) === true && $_GET['wa_deleted'] === '1') {
    $successMsg = 'Passkey has been removed.';
}
if (isset($_GET['wa_registered']) === true && $_GET['wa_registered'] === '1') {
    $successMsg = 'Passkey registered successfully.';
}
if (isset($_GET['notify_saved']) === true && $_GET['notify_saved'] === '1') {
    $successMsg = 'Your notification preferences have been saved.';
}
if (isset($_GET['totp_disabled']) === true && $_GET['totp_disabled'] === '1') {
    $successMsg = 'Two-factor authentication has been disabled.';
}
// The set-up page sends a person here when it is already on (#613). It used
// to leave its message in the session, which this page never reads, so the
// message came up later on some unrelated page instead.
if (isset($_GET['totp_on']) === true && $_GET['totp_on'] === '1' && $totpEnabled === true) {
    $successMsg = 'Two-factor authentication is on for your account. If you did not save your backup codes, you can make a new set below.';
}
if (isset($_GET['error']) === true) {
    $errorMap = [
        'csrf'         => 'Invalid session token. Please try again.',
        'name'         => 'Full name is required.',
        'email'        => 'A valid email address is required.',
        'email_taken'  => 'That email address is already in use by another account.',
        'pw_current'   => 'Current password is incorrect.',
        'pw_match'     => 'New passwords do not match.',
        'pw_policy'    => 'New password does not meet the requirements.',
        'pw_empty'     => 'All password fields are required.',
        'db'           => 'A database error occurred. Please try again.',
        // The "Notification Preferences" card (#667). `notify_stale` is a post
        // that did not say which switches it drew — a page left open from
        // before the change — so nothing was changed, on purpose.
        'notify_stale' => 'This page was out of date, so your notification preferences were not changed. Please check them below and press Save again.',
        'notify_db'    => 'Your notification preferences could not be saved, and nothing was changed. Please try again.',
        'unlink_fail'  => 'Unable to unlink that account. You must keep at least one login method.',
        'unlink_csrf'  => 'Invalid session token. Please try again.',
        'wa_delete'    => 'Unable to remove that passkey.',
        'wa_csrf'      => 'Invalid session token. Please try again.',
        'totp_nopw'    => 'Your account has no password set, so 2FA cannot be disabled this way. Contact an administrator.',
        // "Make new backup codes" (#613); auth/2fa/setup.php sends each of these.
        'totp_code'    => 'That code is not right, so no new backup codes were made. Enter the 6-digit code your authenticator app shows now.',
        'totp_wait'    => 'Too many wrong codes. Please wait up to 15 minutes, then try again.',
        'totp_off'     => 'Two-factor authentication is not on for your account, so there are no backup codes to make.',
        // "Your old codes still work" is said only to somebody who has some.
        // It used to be said to everybody, and a person with none (anybody who
        // has used them all, and everybody after an upgrade that removes the
        // codes nobody was shown) was told something untrue.
        'totp_codes_failed' => ($totpCodesLeft > 0)
            ? 'New backup codes could not be made. Nothing was changed, and your old codes still work. Please try again.'
            : 'New backup codes could not be made. Nothing was changed. Please try again.',
        // The form was not this page's own, or had been sent already. The usual
        // cause is a second press of the button: the first press made a set and
        // used up the form, and the browser shows the second answer, so the
        // person holds codes they never saw. They are told what to do. (The
        // script under the form stops the second press where scripts run.)
        'totp_form'    => 'That form had already been sent, or had expired. If you were making new backup codes and did not see them, make a set again: enter the code your authenticator app shows now and press the button once.',
    ];
    $errorCode = $_GET['error'];
    $errorMsg  = $errorMap[$errorCode] ?? 'An error occurred.';
}

// -----------------------------------------------------------------------------
// 5. 🎨 Render page
// -----------------------------------------------------------------------------

$pageTitle   = 'My Account';
$pageSection = 'account';
$breadcrumbs = ['Dashboard' => Site::url(''), 'My Account' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- ✅ Success message -->
<?php if ($successMsg !== ''): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check me-1"></i>
        <?php echo htmlspecialchars($successMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- ❌ Error message -->
<?php if ($errorMsg !== ''): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-exclamation me-1"></i>
        <?php echo htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="row g-4">

    <!-- ========================================================== -->
    <!-- 📝 Profile Information Card                                -->
    <!-- ========================================================== -->
    <div class="col-12 col-lg-6">
        <div class="card portal-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fa-solid fa-user me-1"></i> Profile Information
                </h5>
            </div>
            <div class="card-body">
                <!-- 🖼️ Avatar display -->
                <div class="text-center mb-3">
                    <?php
                    if ($user !== null) {
                        echo Avatar::img($user, 96, 'portal-avatar portal-avatar-xl');
                    } else {
                        echo '<img src="/assets/images/avatar-placeholder.svg" class="portal-avatar portal-avatar-xl" alt="" width="96" height="96">';
                    }
                    ?>
                    <?php
                    // 🛂 #778: a picture from Gravatar or the sign-in provider is
                    //    shown only where it is allowed. It ships off, so most members
                    //    see a silhouette (D-073), but an administrator may make it on
                    //    by default — so the words must not promise either. Say where
                    //    it is decided.
                    ?>
                    <div class="small text-secondary mt-1">
                        A picture from an outside company is shown only where it is allowed, and may start on or off;
                        see or change yours at
                        <a href="<?php echo htmlspecialchars(Site::url('account/outside-services'), ENT_QUOTES, 'UTF-8'); ?>">Outside services</a>.
                    </div>
                </div>

                <form method="post" action="<?php echo htmlspecialchars(Site::url('account/save'), ENT_QUOTES, 'UTF-8'); ?>" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="mb-3">
                        <label for="fullName" class="form-label">Full Name</label>
                        <input type="text" class="form-control" id="fullName" name="fullName"
                               value="<?php echo htmlspecialchars($user['fullName'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                               required>
                    </div>

                    <div class="mb-3">
                        <label for="emailAddress" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="emailAddress" name="emailAddress"
                               value="<?php echo htmlspecialchars($user['emailAddress'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                               required>
                    </div>

                    <div class="mb-3">
                        <label for="phoneNumber" class="form-label">Phone Number <span class="text-muted small">(optional)</span></label>
                        <input type="tel" class="form-control" id="phoneNumber" name="phoneNumber"
                               value="<?php echo htmlspecialchars($user['phoneNumber'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <?php
                    // 🌍 LANGUAGE (#763, the owner's decision D-062).
                    //    There is a language menu in the top bar of every page,
                    //    and it works — but it only CHANGES the language. It is
                    //    not a place a member can see what the portal has stored
                    //    about them beside their name and telephone number, and
                    //    it offers no way back to "follow my browser". Both are
                    //    why this is here.
                    //
                    //    `App::user()` does not select `locale`, and widening that
                    //    shared, cached read for one control would reach every
                    //    page, so `I18n::storedChoice()` is asked — the one place
                    //    the stored choice is read, which the resolver also asks.
                    //
                    //    An EMPTY value means "forget my choice". That is not a
                    //    nicety: on this portal an empty stored language IS "not
                    //    chosen" (D-060), so without it a member who once picked
                    //    a language could never go back to being asked.
                    // 🚨 THREE ANSWERS, AND THE THIRD MUST NOT BE GUESSED AT.
                    //    `storedChoice()` answers a code, or null for "has not
                    //    chosen", or FALSE for "could not be read". A first
                    //    version wrote `?? ''`, which folded false into "has
                    //    not chosen" — so after a failed read the select drew
                    //    "Follow my browser" as selected, and the member's next
                    //    Save, for any unrelated reason, posted an empty
                    //    language and wrote SQL NULL over the choice they still
                    //    had. Silently. So when we do not know, the control is
                    //    left out and the page says why: a member who cannot
                    //    see their language is far better off than one whose
                    //    language is quietly deleted.
                    $langRead    = \Portal\Core\I18n::storedChoice($userId, $mysqli);
                    $langUnknown = $langRead === false;
                    $langStored  = is_string($langRead) === true ? $langRead : '';
                    $langChoices = \Portal\Core\I18n::enabledLocales();

                    // 🚨 THE STORED LANGUAGE IS ALWAYS AN OPTION, EVEN WHEN IT
                    //    HAS NO TRANSLATION FILE, AND LEAVING IT OUT SILENTLY
                    //    DELETED IT. `enabledLocales()` lists readable
                    //    `web/_lang/*.php` files — two on a stock installation —
                    //    while `isSupported()` ACCEPTS any of the thirteen the
                    //    code knows. So a member who had stored `ar` matched no
                    //    option, and `$langStored !== ''` meant the "Follow my
                    //    browser" option got no `selected` either: the whole
                    //    `<select>` carried no `selected` attribute, every
                    //    browser pre-selected the first option, `value=""`, and
                    //    pressing "Save Changes" to correct a TELEPHONE NUMBER
                    //    posted an empty language and wiped their choice. The
                    //    page also stated something untrue about what the portal
                    //    holds. Measured on the real class: stored `ar`, rendered
                    //    options `"" / en / cy`, not one of them `selected`.
                    //
                    //    That is the #667 and #677 shape, which this very package
                    //    cites twice in its own comments. Reachable by hand-typing
                    //    `?lang=ar`, and by a deployment that later removes a
                    //    `_lang` file members had already chosen.
                    //
                    //    So it is offered, and labelled honestly rather than
                    //    hidden: the member can see what they chose and change it.
                    if ($langStored !== '' && isset($langChoices[$langStored]) === false) {
                        // ⚠️ NO SUFFIX HERE. A first version added
                        //    "— not translated yet, so pages read in English",
                        //    and the coverage suffix below was then appended ON
                        //    TOP of it, so the one control a member uses to pick
                        //    a language read "Deutsch — not translated yet, so
                        //    pages read in English — 0% translated, so some pages
                        //    read in English". Two clauses contradicting each
                        //    other, and "some pages" is untrue at 0%, where EVERY
                        //    page reads in English. The suffix below says the same
                        //    thing with a number, and says it once.
                        $langStoredMeta = \Portal\Core\I18n::localeMeta($langStored);
                        $langChoices[$langStored] = [
                            'native' => ($langStoredMeta['native'] ?? $langStored),
                        ];
                    }

                    // 🌍 What "follow my browser" would REALLY give, which is NOT
                    //    `I18n::locale()`. That is the language this page is being
                    //    drawn in, and for anybody who HAS a stored choice it IS
                    //    that choice — so a first version of this label told a
                    //    member whose browser asks for French that following their
                    //    browser meant Welsh. It is only ever read by somebody with
                    //    a choice to undo, so it was wrong for every single person
                    //    who would look at it.
                    $langBrowser     = \Portal\Core\I18n::browserLanguage();
                    $langBrowserMeta = $langBrowser === null
                        ? null
                        : \Portal\Core\I18n::localeMeta($langBrowser);
                    $langBrowserWords = $langBrowser === null
                        ? 'your browser asks for nothing this portal offers, so the default is used'
                        : ((string) ($langBrowserMeta['native'] ?? $langBrowser)) . ' today';
                    ?>
                    <?php if ($langUnknown === true): ?>
                    <?php
                    // ⚠️ NO `name="locale"` FIELD AT ALL HERE, AND THAT IS THE
                    //    POINT. The handler asks whether the field ARRIVED, so
                    //    leaving it out means "do not touch the language" —
                    //    whereas an empty value would mean "forget my choice".
                    //    That distinction is the whole reason the handler is
                    //    written the way it is (#677).
                    ?>
                    <div class="mb-3">
                        <label class="form-label">Language</label>
                        <div class="form-text">
                            We could not read your language setting just now, so it
                            is not shown and nothing here will change it. Your
                            language is unaffected. Please try again in a moment,
                            or use the globe menu at the top of the page.
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="mb-3">
                        <label for="locale" class="form-label">Language</label>
                        <select class="form-select" id="locale" name="locale">
                            <option value=""<?php echo $langStored === '' ? ' selected' : ''; ?>>
                                Follow my browser (<?php echo htmlspecialchars($langBrowserWords, ENT_QUOTES, 'UTF-8'); ?>)
                            </option>
                            <?php foreach ($langChoices as $langCode => $langMeta): ?>
                                <?php
                                // 🈳 HOW THOROUGHLY IT IS TRANSLATED, because the
                                //    globe menu badges this and leaving it off
                                //    here meant a member could pick Welsh — 30%
                                //    translated, measured — and get a mostly
                                //    English page with nothing saying so. Shown
                                //    only below 95%, so a complete language is
                                //    not labelled with a number nobody needs.
                                $langPart = '';
                                if ((string) $langCode !== ''
                                    && isset($langMeta['native']) === true
                                ) {
                                    $langCover = \Portal\Core\I18n::coverage((string) $langCode);
                                    $langPct   = (int) round($langCover * 100);
                                    // ⚠️ "SOME pages" IS UNTRUE AT 0%, where EVERY
                                    //    page reads in English — and 0% is the
                                    //    ordinary case for a stored language with
                                    //    no translation file, which is exactly the
                                    //    member this control exists to help.
                                    if ($langPct <= 0) {
                                        $langPart = ' — not translated yet, so every'
                                            . ' page reads in English';
                                    } elseif ($langCover < 0.95) {
                                        $langPart = ' — ' . $langPct
                                            . '% translated, so some pages read in English';
                                    }
                                }
                                ?>
                                <option value="<?php echo htmlspecialchars((string) $langCode, ENT_QUOTES, 'UTF-8'); ?>"
                                    <?php echo $langStored === (string) $langCode ? ' selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) $langMeta['native'] . $langPart, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            The language the portal is shown in. It follows you to
                            any device you sign in on. The globe menu at the top of
                            every page changes it too; that menu can offer fewer
                            languages than this list, because an administrator may
                            hide the ones that are only part-translated.
                        </div>
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-success w-100">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Save Changes
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- ========================================================== -->
    <!-- 🔐 Change Password Card                                    -->
    <!-- ========================================================== -->
    <div class="col-12 col-lg-6">
        <div class="card portal-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fa-solid fa-lock me-1"></i> Change Password
                </h5>
            </div>
            <div class="card-body">
                <form method="post" action="<?php echo htmlspecialchars(Site::url('account/change-password'), ENT_QUOTES, 'UTF-8'); ?>" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="mb-3">
                        <label for="current_password" class="form-label">Current Password</label>
                        <input type="password" class="form-control" id="current_password" name="current_password"
                               autocomplete="current-password" required>
                    </div>

                    <div class="mb-3">
                        <label for="new_password" class="form-label">New Password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password"
                               autocomplete="new-password" required
                               minlength="<?php echo (int) $policy['minLength']; ?>"
                               maxlength="<?php echo (int) $policy['maxLength']; ?>"
                               data-portal-password-input>
                        <div class="portal-password-strength mt-2" data-portal-password-meter hidden>
                            <div class="progress" style="height:6px;">
                                <div class="progress-bar" role="progressbar" style="width:0%"></div>
                            </div>
                            <small class="form-text" data-portal-password-meter-label>Password strength</small>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label for="new_password_confirm" class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" id="new_password_confirm" name="new_password_confirm"
                               autocomplete="new-password" required
                               minlength="<?php echo (int) $policy['minLength']; ?>"
                               maxlength="<?php echo (int) $policy['maxLength']; ?>">
                    </div>

                    <!-- 📋 Password requirements -->
                    <div class="small text-muted mb-3">
                        <strong>Password requirements:</strong>
                        <ul class="mb-0 ps-3">
                            <?php foreach ($policyItems as $item): ?>
                                <li><?php echo htmlspecialchars($item, ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <button type="submit" class="btn btn-warning w-100">
                        <i class="fa-solid fa-key me-1"></i> Update Password
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- ========================================================== -->
    <!-- 🔑 "Add a password" card (#731)                            -->
    <!-- ========================================================== -->
    <?php
    // 🔑 WHY THIS IS A CARD OF ITS OWN, beside "Change Password" rather than
    //    inside it. An account that signs in only through Microsoft, Google or
    //    a passkey has NO password, so "Change Password" has nothing to change
    //    — and such a person had no route to one at all: an administrator may
    //    not type one for them (D-018, #586), "forgot password" finds nothing
    //    because it joins the sign-in table, and losing the Microsoft account
    //    or the one device holding the passkey locked them out for good.
    //
    //    It is ADDITIVE on purpose: the "Change Password" card above is left
    //    exactly as it was, so nothing that works today stops working.
    ?>
    <?php if ($canAddPassword === true): ?>
    <div class="col-12 col-lg-6">
        <div class="card portal-card border-warning">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fa-solid fa-key me-1" aria-hidden="true"></i> Add a password
                </h5>
            </div>
            <div class="card-body">
                <p class="mb-2">
                    Your account has no password. You sign in another way, which works well — but if you
                    lost that way in, nobody could let you back in, not even an administrator.
                </p>
                <p class="small text-muted">
                    Adding a password gives you a second way in and changes nothing about the way you sign
                    in now. You will be asked to sign in again first.
                </p>
                <a href="<?php echo htmlspecialchars(Site::url('account/add-password'), ENT_QUOTES, 'UTF-8'); ?>"
                   class="btn btn-warning w-100">
                    <i class="fa-solid fa-plus me-1" aria-hidden="true"></i> Add a password
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ========================================================== -->
    <!-- 🔐 Two-Factor Authentication Card (#B5a)                   -->
    <!-- ========================================================== -->
    <div class="col-12 col-lg-6">
        <div class="card portal-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fa-solid fa-shield-halved me-1"></i> Two-Factor Authentication
                </h5>
            </div>
            <div class="card-body">
                <?php if ($totpEnabled === true): ?>
                    <p class="mb-3">
                        <span class="badge bg-success"><i class="fa-solid fa-circle-check me-1"></i>Enabled</span>
                        <span class="text-muted small ms-2">Your account requires a code from your authenticator app at sign-in.</span>
                    </p>

                    <?php
                    // 🔑 Backup codes (#613). The form posts to the set-up page
                    //    (auth/2fa/setup.php), which makes the codes and shows them
                    //    once, in its own answer. It asks for a code from the
                    //    authenticator app and not the password, as "Disable" below
                    //    does, because an account that signs in through Microsoft or
                    //    Google has no password here and needs backup codes as well.
                    ?>
                    <!-- 🔑 Backup codes -->
                    <h6>Backup codes</h6>
                    <p class="small text-muted mb-2">
                        A backup code signs you in once if you lose your phone.
                        <?php if ($totpCodesLeft > 0): ?>
                            <strong>You have <?php echo (int) $totpCodesLeft; ?> unused backup <?php echo $totpCodesLeft === 1 ? 'code' : 'codes'; ?>.</strong>
                        <?php else: ?>
                            <strong>You have no unused backup codes, so a lost phone would lock you out. Make a set now.</strong>
                        <?php endif; ?>
                        Codes are shown only once, when they are made. If you did not save yours, make a new set: the old ones then stop working.
                    </p>
                    <form method="post" action="<?php echo htmlspecialchars(Site::url('auth/2fa/setup'), ENT_QUOTES, 'UTF-8'); ?>" class="mb-3"
                          id="totpNewCodesForm"
                          data-confirm="Make a new set of backup codes? Any backup codes you already have will stop working.">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="new_backup_codes">
                        <div class="mb-3">
                            <label for="totp_new_codes_code" class="form-label">Enter the 6-digit code from your authenticator app</label>
                            <input type="text" class="form-control" id="totp_new_codes_code" name="code"
                                   maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" required>
                        </div>
                        <button type="submit" class="btn btn-outline-primary w-100">
                            <i class="fa-solid fa-key me-1" aria-hidden="true"></i> Make New Backup Codes
                        </button>
                    </form>
                    <?php
                    // 🖱️ The form above is sent ONCE (#613, found by the independent check).
                    //
                    //    Pressed twice, it was sent twice. The first sending made a new
                    //    set and used up the form's token; the second was refused; and the
                    //    browser shows the answer to the LAST thing it sent. So the old
                    //    codes were gone, the new ones had never been on screen, and the
                    //    person read "Invalid session token".
                    //
                    //    Two ways the form is really sent, and both are watched:
                    //    - by the browser itself (the confirm box did not load, or the
                    //      person presses the button again after saying OK): that raises
                    //      a "submit" event. The confirm box (portal-confirm.js) stops
                    //      the FIRST such event to ask its question, so the event is
                    //      looked at again once every listener has run, to see whether
                    //      it was stopped;
                    //    - by the confirm box after OK: it marks the form
                    //      (data-confirm-accepted) and calls form.submit(), which raises
                    //      no event at all. The mark is the signal.
                    //
                    //    The nonce is not decoration. The page's own rules (the
                    //    Content-Security-Policy header in templates/header.php) refuse
                    //    a script written into the page without it.
                    //
                    //    WHAT IT CANNOT DO: anything where scripts are switched off, or
                    //    for two windows open on this page. The person then lands back
                    //    here with the "totp_form" message above, which says what to do.
                    //    NOT RUN IN A BROWSER: read, and its grammar checked with Node.
                    ?>
                    <script nonce="<?php echo htmlspecialchars(App::cspNonce(), ENT_QUOTES, 'UTF-8'); ?>">
                    (function () {
                        'use strict';
                        var form = document.getElementById('totpNewCodesForm');
                        if (form === null) {
                            return;
                        }
                        var button = form.querySelector('button[type="submit"]');
                        var sent = false;
                        function markSent() {
                            sent = true;
                            if (button !== null) {
                                button.disabled = true;
                            }
                        }
                        form.addEventListener('submit', function (event) {
                            if (sent === true) {
                                event.preventDefault();
                                return;
                            }
                            window.setTimeout(function () {
                                if (event.defaultPrevented === false) {
                                    markSent();
                                }
                            }, 0);
                        });
                        if (typeof MutationObserver === 'function') {
                            new MutationObserver(function () {
                                if (form.getAttribute('data-confirm-accepted') === '1') {
                                    markSent();
                                }
                            }).observe(form, { attributes: true, attributeFilter: ['data-confirm-accepted'] });
                        }
                        // A page brought back by the browser's "Back" button keeps what
                        // this script did. The form there is a new chance, not a sent one.
                        window.addEventListener('pageshow', function (event) {
                            if (event.persisted === true) {
                                sent = false;
                                if (button !== null) {
                                    button.disabled = false;
                                }
                            }
                        });
                    })();
                    </script>

                    <hr>

                    <!-- 🔓 Disable — requires password re-confirmation (#B5b) -->
                    <form method="post" action="<?php echo htmlspecialchars(Site::url('auth/2fa/disable'), ENT_QUOTES, 'UTF-8'); ?>"
                          data-confirm="Disable two-factor authentication? This removes an important layer of protection from your account."
                          data-confirm-destructive="true">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="mb-3">
                            <label for="totp_current_password" class="form-label">Confirm your password to disable</label>
                            <input type="password" class="form-control" id="totp_current_password" name="current_password"
                                   autocomplete="current-password" required>
                        </div>
                        <button type="submit" class="btn btn-outline-danger w-100">
                            <i class="fa-solid fa-shield-halved me-1"></i> Disable Two-Factor Authentication
                        </button>
                    </form>
                <?php else: ?>
                    <p class="text-muted small">
                        Two-factor authentication adds a second step at sign-in — a 6-digit code from an
                        authenticator app — so a leaked password alone isn't enough to sign in as you.
                    </p>
                    <a href="<?php echo htmlspecialchars(Site::url('auth/2fa/setup'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-primary">
                        <i class="fa-solid fa-plus me-1"></i> Set Up Two-Factor Authentication
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ========================================================== -->
    <!-- ℹ️ Account Information Card (read-only)                    -->
    <!-- ========================================================== -->
    <div class="col-12">
        <div class="card portal-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fa-solid fa-circle-info me-1"></i> Account Information
                </h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <div class="small text-muted mb-1">Account Created</div>
                        <div><?php echo htmlspecialchars($user['createdAt'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="small text-muted mb-1">Last Login</div>
                        <div><?php echo htmlspecialchars($lastLogin ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="small text-muted mb-1">Account Type</div>
                        <div>
                            <?php if ($hasLocalAccount === true): ?>
                                <span class="badge bg-secondary me-1">Local Account</span>
                            <?php endif; ?>
                            <?php foreach ($linkedAccounts as $la): ?>
                                <span class="badge bg-info me-1"><?php echo htmlspecialchars(providerLabel($la['provider']), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endforeach; ?>
                            <?php if ($hasLocalAccount === false && count($linkedAccounts) === 0): ?>
                                <span class="badge bg-secondary">Unknown</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (count($roles) > 0): ?>
                    <div class="col-12">
                        <div class="small text-muted mb-1">Roles</div>
                        <div>
                            <?php foreach ($roles as $role): ?>
                                <span class="badge bg-primary me-1"><?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php
                    // 🔢 These three checks used to be `($user['isAdmin'] ?? '0') === '1'`
                    //    and the same for isRootAdmin. App::user() reads the row through a
                    //    prepared statement, which hands these flags back as the whole
                    //    number 1, never the text '1'. `1 === '1'` is false in PHP, so the
                    //    Privileges badge never showed for anybody. The test now accepts
                    //    exactly 1 or '1'. A cast was not used because it would also
                    //    accept true, '01' and 1.5.
                    $accountRootFlag    = $user['isRootAdmin'] ?? null;
                    $accountAdminFlag   = $user['isAdmin'] ?? null;
                    $accountIsRootAdmin = ($accountRootFlag === 1 || $accountRootFlag === '1');
                    $accountIsAdmin     = ($accountAdminFlag === 1 || $accountAdminFlag === '1');
                    ?>
                    <?php if ($accountIsAdmin === true || $accountIsRootAdmin === true): ?>
                    <div class="col-12">
                        <div class="small text-muted mb-1">Privileges</div>
                        <div>
                            <?php if ($accountIsRootAdmin === true): ?>
                                <span class="badge bg-danger me-1">Root Admin</span>
                            <?php elseif ($accountIsAdmin === true): ?>
                                <span class="badge bg-warning text-dark me-1">Admin</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================== -->
    <!-- 🔗 Linked Accounts Card                                    -->
    <!-- ========================================================== -->
    <div class="col-12 col-lg-6">
        <div class="card portal-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fa-solid fa-link me-1"></i> Linked Accounts
                </h5>
            </div>
            <div class="card-body">
                <?php if (count($linkedAccounts) === 0 && $hasLocalAccount === false): ?>
                    <p class="text-muted small mb-0">No linked accounts found.</p>
                <?php else: ?>
                    <div class="portal-data-list">
                        <?php if ($hasLocalAccount === true): ?>
                            <div class="portal-data-row align-items-center">
                                <div class="col">
                                    <i class="fa-solid fa-key me-2 text-secondary"></i>
                                    <strong>Local Account</strong>
                                    <div class="small text-muted">Password-based login</div>
                                </div>
                                <div class="col-auto">
                                    <span class="badge bg-success">Active</span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php foreach ($linkedAccounts as $la): ?>
                            <div class="portal-data-row align-items-center">
                                <div class="col">
                                    <i class="<?php echo htmlspecialchars(providerIcon($la['provider']), ENT_QUOTES, 'UTF-8'); ?> me-2"></i>
                                    <strong><?php echo htmlspecialchars(providerLabel($la['provider']), ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <?php if (($la['providerEmail'] ?? '') !== ''): ?>
                                        <div class="small text-muted"><?php echo htmlspecialchars($la['providerEmail'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                    <div class="small text-muted">Linked <?php echo \Portal\Core\I18n::formatDate($la['linkedAt']); ?></div>
                                </div>
                                <div class="col-auto">
                                    <?php if ($loginMethodCount > 1): ?>
                                        <form method="post" action="<?php echo htmlspecialchars(Site::url('account/unlink'), ENT_QUOTES, 'UTF-8'); ?>" class="d-inline"
                                              data-confirm="Unlink this <?php echo htmlspecialchars(providerLabel($la['provider']), ENT_QUOTES, 'UTF-8'); ?> account?"
                                              data-confirm-destructive="true">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="linkID" value="<?php echo (int) $la['linkID']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="fa-solid fa-unlink me-1"></i> Unlink
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge bg-secondary" title="Cannot unlink your only login method">Only method</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- 🔗 Link new account buttons -->
                <div class="mt-3">
                    <?php
                    // 📋 Determine which providers are already linked
                    $linkedProviders = array_column($linkedAccounts, 'provider');
                    ?>
                    <?php if (Auth::isGoogleConfigured() === true && in_array('google', $linkedProviders, true) === false): ?>
                        <a href="<?php echo htmlspecialchars(Site::url('login/google'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-danger me-1">
                            <i class="fa-brands fa-google me-1"></i> Link Google
                        </a>
                    <?php endif; ?>
                    <?php if (Auth::isMS365Configured() === true && in_array('ms365', $linkedProviders, true) === false): ?>
                        <a href="<?php echo htmlspecialchars(Site::url('login/ms365'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-primary me-1">
                            <i class="fa-brands fa-microsoft me-1"></i> Link Microsoft 365
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================== -->
    <!-- 🔐 PassKeys / WebAuthn Card                                -->
    <!-- ========================================================== -->
    <div class="col-12 col-lg-6">
        <div class="card portal-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fa-solid fa-fingerprint me-1"></i> Passkeys
                </h5>
            </div>
            <div class="card-body">
                <?php if (count($webauthnCreds) === 0): ?>
                    <p class="text-muted small">No passkeys registered yet. Passkeys let you sign in with your fingerprint, face, or security key.</p>
                <?php else: ?>
                    <div class="portal-data-list mb-3">
                        <?php foreach ($webauthnCreds as $cred): ?>
                            <div class="portal-data-row align-items-center">
                                <div class="col">
                                    <i class="fa-solid fa-key me-2 text-primary"></i>
                                    <strong><?php echo htmlspecialchars($cred['friendlyName'] ?? 'Passkey', ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <div class="small text-muted">
                                        Registered <?php echo \Portal\Core\I18n::formatDate($cred['createdAt']); ?>
                                        <?php if (($cred['lastUsedAt'] ?? '') !== ''): ?>
                                            &bull; Last used <?php echo \Portal\Core\I18n::formatDateTime($cred['lastUsedAt']); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <?php if ($loginMethodCount > 1): ?>
                                        <form method="post" action="<?php echo htmlspecialchars(Site::url('account/webauthn/delete'), ENT_QUOTES, 'UTF-8'); ?>" class="d-inline"
                                              data-confirm="Remove this passkey?"
                                              data-confirm-destructive="true">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="credID" value="<?php echo (int) $cred['credID']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="fa-solid fa-trash me-1"></i> Remove
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge bg-secondary" title="Cannot remove your only login method">Only method</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- ➕ Register new passkey button -->
                <button type="button" class="btn btn-sm btn-outline-primary" id="btnRegisterPasskey">
                    <i class="fa-solid fa-plus me-1"></i> Register New Passkey
                </button>
                <noscript>
                    <p class="text-muted small mt-2"><i class="fa-solid fa-circle-info me-1"></i>Passkey registration requires JavaScript and a compatible browser. Enable JavaScript to register passkeys.</p>
                </noscript>

                <!-- 🔐 Passkey registration modal -->
                <div class="modal fade" id="passkeyModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-fullscreen-sm-down">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title"><i class="fa-solid fa-fingerprint me-1"></i> Register Passkey</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label for="passkeyName" class="form-label">Passkey Name</label>
                                    <input type="text" class="form-control" id="passkeyName" placeholder="e.g. YubiKey 5C, MacBook Touch ID">
                                </div>
                                <div id="passkeyStatus" class="small"></div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-primary" id="btnStartRegistration">
                                    <i class="fa-solid fa-fingerprint me-1"></i> Register
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- 📦 WebAuthn registration script -->
<script nonce="<?php echo htmlspecialchars(\Portal\Core\App::cspNonce(), ENT_QUOTES, 'UTF-8'); ?>">
(function() {
    'use strict';

    const registerBtn = document.getElementById('btnRegisterPasskey');
    const startBtn    = document.getElementById('btnStartRegistration');
    const statusEl    = document.getElementById('passkeyStatus');
    const nameInput   = document.getElementById('passkeyName');

    // 🪟 The box is made when it is first NEEDED, not when this script is read.
    //    It used to be made right here: `new bootstrap.Modal(...)`. But this
    //    script sits in the middle of the page and Bootstrap is loaded in the
    //    footer, after it, so there was no `bootstrap` yet. The line threw
    //    "bootstrap is not defined", the script stopped, and the button below
    //    never got its listener. Nobody saw it, because until #625 browsers
    //    refused this whole script for having no nonce (#144). With the nonce
    //    it ran, and failed on that line: found by pressing the button in a
    //    real browser, 14 October 2026. Do not move the `new` back up here.
    //    Returns null when Bootstrap is not there at all (the content delivery
    //    network is blocked and this installation holds no copy of its own).
    function passkeyBox() {
        if (typeof bootstrap === 'undefined' || typeof bootstrap.Modal !== 'function') {
            return null;
        }
        return bootstrap.Modal.getOrCreateInstance(document.getElementById('passkeyModal'));
    }

    if (!window.PublicKeyCredential) {
        registerBtn.disabled = true;
        registerBtn.title    = 'WebAuthn is not supported in this browser';
        return;
    }

    registerBtn.addEventListener('click', function() {
        statusEl.textContent = '';
        nameInput.value      = '';
        const box = passkeyBox();
        if (box === null) {
            // Say so, rather than be a button that does nothing.
            if (window.Portal && typeof window.Portal.toast === 'function') {
                window.Portal.toast('This box cannot be opened because part of the page did not load. Reload the page and try again.', 'warning');
            }
            return;
        }
        box.show();
    });

    startBtn.addEventListener('click', async function() {
        const friendlyName = nameInput.value.trim() || 'Passkey';
        statusEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Requesting registration options...';
        startBtn.disabled  = true;

        try {
            // 📋 Step 1: Get registration options from server
            const optResp = await fetch('<?php echo Router::urlForScript('account/webauthn'); ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=register_options&csrf_token=' + encodeURIComponent(document.querySelector('[name=csrf_token]').value)
            });
            const optData = await optResp.json();
            // 🔑 STEP 1 SPENT THE TOKEN, SO STEP 2 MUST NOT REUSE IT (#770).
            //    `Auth::verifyCsrf()` rotates on acceptance, and both requests
            //    read the token out of the same hidden field — so without this
            //    the second one sent a spent token and a member could not add
            //    a passkey AT ALL. `Portal.rememberCsrf()` writes the
            //    replacement into every carrier the page has (#768), including
            //    that field.
            if (window.Portal && typeof window.Portal.rememberCsrf === 'function') {
                window.Portal.rememberCsrf(optData);
            }
            if (!optData.success) {
                throw new Error(optData.error || 'Failed to get registration options');
            }

            const options = optData.options;

            // 🔐 Convert base64url strings to ArrayBuffers
            options.challenge = base64urlToBuffer(options.challenge);
            options.user.id   = base64urlToBuffer(options.user.id);
            if (options.excludeCredentials) {
                options.excludeCredentials = options.excludeCredentials.map(function(c) {
                    c.id = base64urlToBuffer(c.id);
                    return c;
                });
            }

            statusEl.innerHTML = '<i class="fa-solid fa-fingerprint fa-beat me-1"></i> Waiting for authenticator...';

            // 🔐 Step 2: Create credential via WebAuthn API
            const credential = await navigator.credentials.create({publicKey: options});

            statusEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Verifying...';

            // 📤 Step 3: Send credential to server for verification and storage
            const attestation = {
                id:       credential.id,
                rawId:    bufferToBase64url(credential.rawId),
                type:     credential.type,
                response: {
                    clientDataJSON:    bufferToBase64url(credential.response.clientDataJSON),
                    attestationObject: bufferToBase64url(credential.response.attestationObject)
                }
            };

            if (credential.response.getTransports) {
                attestation.transports = credential.response.getTransports();
            }

            const verifyResp = await fetch('<?php echo Router::urlForScript('account/webauthn'); ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    action:       'register_verify',
                    csrf_token:   document.querySelector('[name=csrf_token]').value,
                    credential:   attestation,
                    friendlyName: friendlyName
                })
            });
            const verifyData = await verifyResp.json();
            // The same again: this answer carries the next replacement, and a
            // member who adds a second passkey without reloading needs it.
            if (window.Portal && typeof window.Portal.rememberCsrf === 'function') {
                window.Portal.rememberCsrf(verifyData);
            }

            if (verifyData.success) {
                const box = passkeyBox();
                if (box !== null) { box.hide(); }
                window.location.href = '<?php echo Router::urlForScript('account'); ?>?wa_registered=1';
            } else {
                throw new Error(verifyData.error || 'Verification failed');
            }
        } catch (err) {
            if (err.name === 'NotAllowedError') {
                statusEl.innerHTML = '<span class="text-warning"><i class="fa-solid fa-triangle-exclamation me-1"></i> Registration was cancelled.</span>';
            } else {
                statusEl.innerHTML = '<span class="text-danger"><i class="fa-solid fa-circle-exclamation me-1"></i> ' + escapeHtml(err.message) + '</span>';
            }
        } finally {
            startBtn.disabled = false;
        }
    });

    // 🔧 Utility: base64url → ArrayBuffer
    function base64urlToBuffer(b64url) {
        var b64 = b64url.replace(/-/g, '+').replace(/_/g, '/');
        while (b64.length % 4 !== 0) b64 += '=';
        var bin  = atob(b64);
        var buf  = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
        return buf.buffer;
    }

    // 🔧 Utility: ArrayBuffer → base64url
    function bufferToBase64url(buffer) {
        var bytes = new Uint8Array(buffer);
        var bin   = '';
        for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
    }

    // 🔧 Utility: HTML escape
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }
})();
</script>

<!-- 🔔 Notification Preferences Card -->
<?php
// 🔔 This card OVERLAPS the Notification preferences page. Both write
//    `tblUsers.notifyPrefs`, and until #667 they fought over it:
//    `account/notifications-save.php` REBUILT the whole column from its own
//    list and wrote it over the top, dropping any key this card owned, and
//    this card's handler had no lock.
//
//    SINCE #667 BOTH GO THROUGH `NotifyPrefs::merge()`, the one writer of a
//    member's choices (an erasure empties the column; a restore replaces it),
//    which changes only the switches a form marked as drawn and keeps
//    every other stored choice. So beside each switch below sits
//    `NotifyPrefs::shownMarker()`, and the switch is named `prefs[<key>]`
//    exactly as on the full page. Without the marker, pressing Save here would
//    change NOTHING — deliberately, because an unticked box and an absent box
//    look the same in a post (#677).
//
//    The two switches nothing ever read — "Weekly email digest" and "Expense
//    claim updates" — came off this card in #569 (#662), and #667 RETIRED
//    their keys: nothing writes `emailDigest` or `expenseUpdates` now, and a
//    member's stored value is left where it is.
//
//    THE FORM ITSELF IS DELIBERATELY KEPT. An earlier attempt removed the
//    whole card, which broke section F of `tools/go-live-button-selftest.php`
//    — the regression guard proving that pressing Save here does not throw
//    away a member's other stored choices (#612). Removing a feature must not
//    quietly remove the test that guards a past fault.
?>
<!-- 🔔 Notification Preferences Card -->
<div class="card shadow-sm mt-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="fa-solid fa-bell me-2" aria-hidden="true"></i>Notification Preferences</h5>
    </div>
    <div class="card-body">
        <p class="text-muted small">
            More choices, including push notifications, are on the
            <a href="<?php echo htmlspecialchars(Site::url('account/notifications'), ENT_QUOTES, 'UTF-8'); ?>">Notification preferences</a>
            page.
        </p>
        <form method="post" action="<?php echo htmlspecialchars(Site::url('account/save'), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="update_notifications">

            <div class="form-check form-switch mb-3">
                <?php echo \Portal\Core\NotifyPrefs::shownMarker('eventReminders'); ?>
                <input class="form-check-input" type="checkbox" id="notifyEvents" name="prefs[eventReminders]" value="1"
                       <?php echo ($notifyPrefs['eventReminders'] === true) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="notifyEvents">
                    <strong>Event reminders</strong>
                    <div class="text-muted small">Reminders for upcoming events you've registered for</div>
                </label>
            </div>

            <button type="submit" class="btn btn-primary btn-sm">
                <i class="fa-solid fa-save me-1" aria-hidden="true"></i>Save Preferences
            </button>
        </form>
    </div>
</div>

<!-- ============================================================== -->
<!-- 🇪🇺 Privacy & Data Rights (GDPR #47)                            -->
<!-- ============================================================== -->
<?php
$accAllowDelete = (\Portal\Core\App::settings('privacy.allowAccountDelete') ?? 'true') === 'true';
?>
<div class="row g-3 mt-4">
    <div class="col-12 col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5"><i class="fa-solid fa-shield-halved me-1"></i>Your data &amp; privacy</h2>
                <p class="text-muted small">
                    Download a copy of everything we hold about you, read the privacy policy, or close your account.
                </p>
                <div class="d-flex flex-wrap gap-2">
                    <a href="<?php echo htmlspecialchars(Site::url('account/notifications'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-primary">
                        <i class="fa-solid fa-bell me-1"></i> Notification preferences
                    </a>
                    <a href="<?php echo htmlspecialchars(Site::url('account/outside-services'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-primary">
                        <i class="fa-solid fa-shield-halved me-1"></i> Outside services
                    </a>
                    <a href="<?php echo htmlspecialchars(Site::url('account/data-export'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-primary">
                        <i class="fa-solid fa-download me-1"></i> Download my data (JSON)
                    </a>
                    <a href="<?php echo htmlspecialchars(Site::url('privacy'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="fa-solid fa-file-shield me-1"></i> Privacy policy
                    </a>
                    <?php if ($accAllowDelete === true): ?>
                        <a href="<?php echo htmlspecialchars(Site::url('account/delete'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-danger">
                            <i class="fa-solid fa-trash me-1"></i> Delete my account
                        </a>
                    <?php endif; ?>
                    <?php
                    // 🔗 Two pages about YOU that nothing linked to (#569).
                    //    Both worked, both had addresses, and the only way to
                    //    reach either was to type the address — which means
                    //    nobody ever did. Both already handle having nothing
                    //    to show, so an empty one is a sentence rather than a
                    //    blank page.
                    //
                    //    "My giving" is shown only where money is actually
                    //    being taken. Offering a page about recurring
                    //    payments on an installation that takes none would be
                    //    a link to a permanent "nothing here".
                    //
                    //    `account/offline-queue` is deliberately NOT linked:
                    //    the offline queue captures nothing at all today
                    //    because no form opts into it, so its page is empty
                    //    for everybody, for ever. Linking it would be
                    //    advertising a dead end. See #661.
                    $accMoneyOn = ((string) (App::settings('giving.enabled') ?? '0')) === '1'
                        || ((string) (App::settings('giving.enabled') ?? 'false')) === 'true'
                        || ((string) (App::settings('payments.enabled') ?? '0')) === '1'
                        || ((string) (App::settings('payments.enabled') ?? 'false')) === 'true';
                    ?>
                    <?php if ($accMoneyOn === true): ?>
                        <a href="<?php echo htmlspecialchars(Site::url('account/recurring'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-primary">
                            <i class="fa-solid fa-arrows-rotate me-1"></i> My recurring payments
                        </a>
                    <?php endif; ?>
                    <a href="<?php echo htmlspecialchars(Site::url('account/safeguarding'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="fa-solid fa-user-shield me-1"></i> My safeguarding record
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
