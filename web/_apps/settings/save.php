<?php
// Path: public_html/settings/save.php
/**
 * -----------------------------------------------------------------------------
 * Settings Save Handler 💾
 * -----------------------------------------------------------------------------
 * Processes POST from settings add/edit forms. Performs:
 *   - CSRF verification
 *   - Role check (requires Admin or Root Admin)
 *   - Three extra refusals that only a GLOBAL administrator can pass, and one
 *     that nobody passes (see the note at the top of the save section below)
 *   - Settings that have a page of their own are refused here, for everybody:
 *     the giving-records rule, how organisations are told apart, and the mail
 *     server's name and certificate setting (each is explained where it runs)
 *   - Secrets (rows whose isSensitive mark is on) are stored encrypted, and
 *     an edit can never take the mark off (#597; see "SECRETS" below)
 *   - INSERT (new) or UPDATE (existing) row in tblSettings
 *   - Logs activity and redirects back with flash message
 *
 * SECRETS (#597, 12 October 2026)
 *   WHAT WAS WRONG: whether a value was a secret was read from the form
 *   (`isSensitive` present or not). The edit box never sent that field, so
 *   editing a secret stored it as PLAIN TEXT and cleared its mark, and the
 *   Settings page then showed it to every administrator.
 *   NOW, for an edit, the STORED row decides. A marked row stays marked
 *   whatever the form says, and:
 *     - a typed value is encrypted, then stored;
 *     - an empty box changes nothing (the page never shows a secret, so the
 *       box always starts empty, and "empty" cannot mean "wipe it");
 *     - the "Remove the stored value" box, ticked, stores an empty value.
 *   If the value cannot be encrypted, NOTHING is stored and the person is
 *   told why. Storing it readable instead is the fault being fixed.
 *   WHAT THIS CANNOT DO: turn an ordinary setting into a secret, or a secret
 *   into an ordinary one. That is chosen once, when a setting is added.
 *
 * @package   Portal\Settings
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.5.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\GivingRetention;
use Portal\Core\Logger;
use Portal\Core\MailQueue;
use Portal\Core\MailRoutes;
use Portal\Core\PasskeyAddress;
use Portal\Core\Router;
use Portal\Core\SecretSettings;
use Portal\Core\SettingsRowRule;
use Portal\Core\Site;

// 🛡️ Admin access check
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// 🛡️ Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . Site::url('settings'));
    exit();
}

// 🛡️ CSRF verification
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('settings'));
    exit();
}

// -----------------------------------------------------------------------------
// 📋 Collect and validate form fields
// -----------------------------------------------------------------------------
$settingId   = (int) ($_POST['settingID'] ?? 0);
$settingKey  = trim($_POST['settingKey'] ?? '');
$settingVal  = trim($_POST['settingValue'] ?? '');
// 🔒 The "this is a secret" tick. It is honoured ONLY when a setting is
//    ADDED. On an edit the stored row decides; see "SECRETS" in the header.
$isSensitive = isset($_POST['isSensitive']) === true ? 1 : 0;
// 🧹 The "Remove the stored value" tick, which the edit box shows for a secret.
$clearValue  = isset($_POST['clearValue']) === true;

if ($settingKey === '') {
    $_SESSION['flash_msg']  = 'Setting key is required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('settings'));
    exit();
}

// 🔒 WHAT USED TO BE HERE: the value was encrypted at this point whenever the
//    form said "secret", before anything was known about the row. For an edit
//    the form never said so, and for an add an EMPTY value was encrypted too,
//    which stored 56 characters of scramble for "nothing" and made every "is
//    it set?" test answer yes. Encrypting now happens in each branch below,
//    after the refusals, through SecretSettings::seal().
$refusalCannotEncrypt = static fn (string $key): string =>
    'Nothing was saved. "' . $key . '" is a secret, and the portal could not '
    . 'encrypt it: its key file (enc.key, in the _auth_keys folder) is missing, '
    . 'is empty or cannot be read. Put that file back and try again. Until '
    . 'then no secret can be saved.';

// -----------------------------------------------------------------------------
// 💾 Insert or update DB row
// -----------------------------------------------------------------------------
// 🌐 Multi-site: settings are scoped to the current site
$siteId = Site::id();

// 🛡️ Is this person a GLOBAL administrator, as opposed to an administrator of
//    one organisation? The difference decides the row refusals below.
//
//    App::isRootAdmin() (web/_core/App.php) is the one place that answers it.
//
//    WHAT WAS HERE BEFORE, AND WHY IT HAS GONE
//    For a short while this line ALSO read the isRootAdmin flag straight off
//    App::user() and converted it to a whole number. That was a stop-gap.
//    App::isRootAdmin() used to compare the flag with the text '1', but
//    App::user() reads the row through a prepared statement, which hands the
//    flag back as the whole number 1, so it answered "no" for every real global
//    administrator (checked 13 September 2026 on MySQL 8.0.36 with PHP 8.5).
//    Asking it alone locked EVERYBODY out of changing a portal-wide setting
//    here, including the way out of the pre-release gate
//    (portal.gatekeeper.enabled).
//
//    App.php now recognises the flag whether it arrives as 1 or as '1' (see
//    App::flagIsOn()), so the extra check could only ever repeat the answer
//    App::isRootAdmin() already gives. It was removed rather than left in,
//    because two tests for the same thing invite somebody to change one and
//    not the other. Please do not put it back: if App::isRootAdmin() is ever
//    wrong, fix it there, and every page that asks gets the fix at once.
//    The same stop-gap was removed from web/_apps/settings/index.php and
//    web/_apps/admin/settings/group.php.
$isGlobalAdmin = App::isRootAdmin();

// -----------------------------------------------------------------------------
// 🚧 THE ROW REFUSALS (#1021: decided in ONE place, Portal\Core\SettingsRowRule)
// -----------------------------------------------------------------------------
// Getting in here needs App::isAdmin(), and that is true for an administrator
// of ONE organisation as well as for a global administrator (see
// web/_core/App.php — isAdmin() accepts isSiteAdmin and isSiteRootAdmin too).
// So "an administrator" on this page does NOT mean "somebody in charge of the
// whole installation".
//
// For anybody but a global administrator, three kinds of ROW are refused
// whatever the setting is: a portal-wide row (every other organisation reads
// it as its fallback), a "public" / "public." name (the public website), and a
// stored name outside the safe characters (the database can treat it as equal
// to a protected one). Refusal 4 (a name that would be both a value and a
// group) applies to everybody and is explained where it runs, in "Insert new".
//
// WHAT WAS WRONG (#1021): those three rules were written HERE, as closures and
// three message strings, with a copy in the delete branch of index.php and the
// SQL text repeated in both files. The Settings page's drawing asked none of
// them, so it offered an organisation's own administrator an Edit button on
// every portal-wide row, and pressing it ended in a refusal. They now live in
// SettingsRowRule — the rules, the words, the SQL, and the reasons for each
// (moved there whole, with what was measured) — and this handler, the delete
// handler and the drawing all ask it, so the three cannot disagree. Note the
// rule is asked with the key STORED in the database for an edit, never the
// key sent with the form: otherwise somebody could send a harmless-looking key
// beside the row number of a "public." row and slip straight past.

if ($settingId > 0) {
    // 🔎 Read the row FIRST, so the decision is made on what is really stored
    //    rather than on what the form said. Scoped exactly like the update
    //    below — this organisation's own row, or a portal-wide one — so a row
    //    belonging to a different organisation simply comes back as "not
    //    found" and this page cannot be used to discover that it exists.
    $existing = null;
    $lookup = $mysqli->prepare(
        'SELECT settingKey, siteID, isSensitive FROM tblSettings '
        . 'WHERE settingID = ? AND (siteID = ? OR siteID IS NULL) LIMIT 1'
    );
    if ($lookup !== false) {
        $lookup->bind_param('ii', $settingId, $siteId);
        $lookup->execute();
        $existing = $lookup->get_result()->fetch_assoc();
        $lookup->close();
    }

    if ($existing === null) {
        $_SESSION['flash_msg']  = 'That setting could not be found, or it does '
            . 'not belong to the organisation you are working in.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    // A row with nothing in the siteID column is the portal-wide one.
    $isPortalWideRow = ($existing['siteID'] === null);
    $storedKey       = (string) $existing['settingKey'];

    // 🔒 The giving-records rule is changed on its own page, never here
    //    (roll-out package A2b). That page un-ticks "confirmed" when the
    //    figure changes and refuses a change that would clear kept donor
    //    records until a second box is ticked. Here an administrator could
    //    have shortened a confirmed figure, or switched a hold off, in one
    //    click with no warning, and the next retention run would have
    //    cleared names for good. The one exception, and the reasons, are in
    //    GivingRetention::lockedInGeneralEditor().
    if (GivingRetention::lockedInGeneralEditor($storedKey, $isPortalWideRow, $isGlobalAdmin) === true) {
        Logger::activity(
            'SettingsUpdateRefused',
            'Refused: "' . $storedKey . '" is part of the giving-records rule and can only be changed on its own page',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = GivingRetention::LOCKED_MESSAGE;
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    // 🔒 Nor are the two settings that decide how organisations are told
    //    apart changed here (#502), by anybody, global administrator
    //    included. They have their own page, which refuses a way that would
    //    leave an organisation unreachable; here one typed word would change
    //    which organisation every address on the installation opens. See
    //    Site::lockedInGeneralEditor().
    // 🔒 Nor the rule for copied-in events on the public website (#493 P1,
    //    round 7): its own card re-works every outside calendar when it is
    //    saved, and saving it here would not. See
    //    PublicWebsite::lockedInGeneralEditor().
    if (\Portal\Core\PublicWebsite::lockedInGeneralEditor($storedKey) === true) {
        Logger::activity(
            'SettingsUpdateRefused',
            'Refused: "' . $storedKey . '" is changed only on ' . (string) (\Portal\Core\PublicWebsite::lockedPageFor($storedKey)['name'] ?? ''),
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = \Portal\Core\PublicWebsite::lockedMessageFor($storedKey);
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }
    // 🔒 Nor what the noticeboard's foyer screen shows (#493 P2b, D-086): its
    //    own page holds an organisation's choice to what the installation
    //    permits, which this editor cannot judge. See
    //    NoticeboardFoyer::lockedInGeneralEditor().
    if (\Portal\Core\NoticeboardFoyer::lockedInGeneralEditor($storedKey) === true) {
        Logger::activity(
            'SettingsUpdateRefused',
            'Refused: "' . $storedKey . '" is changed only on Noticeboard, Foyer screen',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = \Portal\Core\NoticeboardFoyer::LOCKED_MESSAGE;
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }
    // 💷 The currency (#966) is changed only on the organisation settings
    //    page, which refuses a change under money already recorded. This
    //    editor has no such check, so it refuses the key outright.
    if (\Portal\Core\Currency::lockedInGeneralEditor($storedKey) === true) {
        Logger::activity(
            'SettingsUpdateRefused',
            'Refused: "' . $storedKey . '" is the currency and can only be changed on the organisation settings page',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = \Portal\Core\Currency::LOCKED_MESSAGE;
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }
    if (Site::lockedInGeneralEditor($storedKey) === true) {
        Logger::activity(
            'SettingsUpdateRefused',
            'Refused: "' . $storedKey . '" decides how organisations are told apart and can only be changed on its own page',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = Site::LOCKED_MESSAGE;
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    // 🔒 Nor is an organisation's time zone changed here (#604), by anybody.
    //    It used to be this page's "site.timezone" setting, kept as well as
    //    the zone on the organisation itself, and the two could differ.
    //    Migration 217 brought them into line and the organisation's own row
    //    is now the only place (Admin, Organisations), where the box offers
    //    real zone names. Nothing reads "site.timezone" any more, and
    //    changing that migration's mark would send every page back to UTC.
    //    See Site::zoneLockedInGeneralEditor().
    if (Site::zoneLockedInGeneralEditor($storedKey) === true) {
        Logger::activity(
            'SettingsUpdateRefused',
            'Refused: "' . $storedKey . '": an organisation\'s time zone is chosen at Admin, Organisations and nowhere else',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = Site::zoneLockedMessage();
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    // 🔒 Nor are the mail server's name and the two certificate rows changed
    //    here (#592), by anybody, a global administrator included. They are
    //    changed on the e-mail page (Admin, Integrations, Email), which asks
    //    for the mailbox's password again when the name changes, and shows a
    //    warning and asks for a confirmation before certificate checking is
    //    changed. WHAT WAS WRONG: this page changed them with neither. With
    //    checking off, a name changed here sent the next message, and the
    //    stored password, to the new server. See
    //    MailRoutes::lockedInGeneralEditor().
    //    It sits BEFORE the portal-wide refusal below, so that an
    //    organisation's administrator is told where the setting IS changed,
    //    and not only that they may not change it.
    if (MailRoutes::lockedInGeneralEditor($storedKey) === true) {
        Logger::activity(
            'SettingsUpdateRefused',
            'Refused: "' . $storedKey . '" is changed on the e-mail page (Admin, Integrations, Email) and nowhere else',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = MailRoutes::LOCKED_MESSAGE;
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    // 🔒 Nor is the version the database is recorded at changed here (#499),
    //    by anybody, a global administrator included. The portal decides
    //    from it whether to close for an upgrade: one number typed too high
    //    opened it with a database upgrade waiting. The Upgrade page's
    //    button records it. See Maintenance::lockedInGeneralEditor().
    if (\Portal\Core\Maintenance::lockedInGeneralEditor($storedKey) === true) {
        Logger::activity(
            'SettingsUpdateRefused',
            'Refused: "' . $storedKey . '" records the version the database is at and is written only by the installer and the Upgrade page',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = \Portal\Core\Maintenance::LOCKED_MESSAGE;
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    // 🔒 Nor is the list of capabilities this installation reserves changed
    //    here (#727), by anybody, a global administrator included. It is
    //    ticked on the Roles page, which VALIDATES each capability key and
    //    writes both an audit record and an activity record;
    //    `Capabilities::installationLocks()` ignores a key it does not
    //    recognise, so a row typed here as "assets.manag" would reserve
    //    nothing while reading as a reservation. Not a privilege question —
    //    the same global administrator may set it either way — but the one
    //    writer is where the validation and the two records are. See
    //    Capabilities::lockedInGeneralEditor().
    if (\Portal\Core\Capabilities::lockedInGeneralEditor($storedKey) === true) {
        Logger::activity(
            'SettingsUpdateRefused',
            'Refused: "' . $storedKey . '" lists the capabilities this installation reserves and is written only by the Roles page',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = \Portal\Core\Capabilities::LOCKED_MESSAGE;
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    // 🎙️ And how long transcripts are kept (#722). Refused here for the same
    //    reason as the line above, and the reason is NOT that the consequence
    //    is small: an independent check noted the setting had no refusal, and
    //    the reply "the reader caps it anyway" explains the consequence rather
    //    than the rule. The gate on its own page is where the three-tier rule
    //    lives (#668) — an organisation's figure is refused above the
    //    installation's, the refusal is RECORDED, and the person is told why.
    //    The general editor skips all three, so a figure written here is a
    //    number nobody judged and nobody recorded, which the clear-out then
    //    silently holds down. An unrecorded refusal is indistinguishable from
    //    no refusal to whoever reads the log afterwards.
    // 🧒 #816: and how long event registrations are kept, with the
    //    installation's optional ceiling. Both are written only on Admin,
    //    Registration retention, where a period above the ceiling is refused
    //    and a ceiling below a period somebody already holds is refused and
    //    named (D-074, following D-032). Here neither gate exists, so a number
    //    typed here would be one nobody judged — and the clear-out keeps a
    //    period above the ceiling rather than shortening it, so nothing would
    //    ever hold it down. Refused for everybody, a global administrator
    //    included. See RegistrationRetention::lockedInGeneralEditor().
    if (\Portal\Core\RegistrationRetention::lockedInGeneralEditor($storedKey) === true) {
        Logger::activity(
            'SettingsUpdateRefused',
            'Refused: "' . $storedKey . '" is how long event registrations are kept and is written only on its own page',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = \Portal\Core\RegistrationRetention::LOCKED_MESSAGE;
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    // 📜 #932: and how long the activity log is kept, where 0 now means "never
    //    delete" (D-117). Its page (Admin, Maintenance, Retention) refuses an
    //    organisation's "never" under an installation with a number of days and
    //    records the refusal; this editor judges nothing, so a figure typed here
    //    would be one nobody checked. See ActivityLogRetention::lockedInGeneralEditor().
    if (\Portal\Core\ActivityLogRetention::lockedInGeneralEditor($storedKey) === true) {
        Logger::activity(
            'SettingsUpdateRefused',
            'Refused: "' . $storedKey . '" is how long the activity log is kept and is written only on its own page',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = \Portal\Core\ActivityLogRetention::LOCKED_MESSAGE;
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    if (\Portal\Core\TranscriptRetention::lockedInGeneralEditor($storedKey) === true) {
        Logger::activity(
            'SettingsUpdateRefused',
            'Refused: "' . $storedKey . '" is how long transcripts are kept and is written only by its own pages',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = \Portal\Core\TranscriptRetention::LOCKED_MESSAGE;
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    // 🛂 #778: and what outside companies may be sent about a member. Its own
    //    page holds an organisation to what the installation allows (D-058: an
    //    organisation may tighten, never loosen) and RECORDS each change; this
    //    editor does neither, so a looser value typed here would be a number
    //    nobody judged. The reader holds such a row down anyway, but an
    //    unrecorded change is indistinguishable from none. See
    //    OutsideServices::lockedInGeneralEditor().
    if (\Portal\Core\OutsideServices::lockedInGeneralEditor($storedKey) === true) {
        Logger::activity(
            'SettingsUpdateRefused',
            'Refused: "' . $storedKey . '" decides what outside companies may be sent about a member and is written only by its own page',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = \Portal\Core\OutsideServices::LOCKED_MESSAGE;
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    // 🚪 #615, D-102: which port numbers a webhook may use. An INSTALLATION
    //    setting, changed by a global administrator on the Webhooks page, where
    //    the change is recorded; this editor would neither check who is asking
    //    nor record it. See WebhookPorts::lockedInGeneralEditor().
    if (\Portal\Core\WebhookPorts::lockedInGeneralEditor($storedKey) === true) {
        Logger::activity(
            'SettingsUpdateRefused',
            'Refused: "' . $storedKey . '" decides which port numbers a webhook may use and is written only by the Webhooks page',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = \Portal\Core\WebhookPorts::LOCKED_MESSAGE;
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    // 🚧 The three row refusals (portal-wide, "public", unsafe stored name),
    //    asked of the ONE place that decides them (#1021). The Settings page
    //    asks the same method before it draws Edit, so a refusal here is now
    //    only reachable by a request the page never offered (a stale tab, a
    //    hand-made post). It sits AFTER every setting-level refusal above, so
    //    a row that is both is explained by its own page, as before.
    $rowRefusal = SettingsRowRule::whyNotChangeable($storedKey, $isPortalWideRow, $isGlobalAdmin);
    if ($rowRefusal !== null) {
        Logger::activity(
            'SettingsUpdateRefused',
            SettingsRowRule::logWords($rowRefusal, $storedKey),
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = SettingsRowRule::refusalWords($rowRefusal, 'change');
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    // ✉️ ONE ORGANISATION'S OWN VALUE FOR ONE OF THE MAIL QUEUE'S FOUR NUMBERS
    //    (#592): how long mail is tried for, the age past which it is never
    //    sent, and how long a failed and a held message are kept. The owner
    //    (Salem874) decided that each can be set for one organisation, by
    //    that organisation's administrator or a global administrator.
    //    $ownNumber is null for every other setting, and for the
    //    INSTALLATION'S row of these four, so nothing else on this page
    //    changes. It comes after every refusal above, which all still hold.
    //      - A value must be what the queue will accept when it reads the
    //        row (MailQueue::ownNumberCheck() applies the queue's own test),
    //        or nothing is saved, and the person is told the range and what
    //        the installation's value is.
    //      - An EMPTY value REMOVES the organisation's own row, so that the
    //        organisation goes back to the installation's value. That is how
    //        an organisation's administrator removes it: the Delete button on
    //        the Settings page is for global administrators only, and stays so.
    $ownNumber = $isPortalWideRow === true ? null : MailQueue::ownNumberCheck($mysqli, $storedKey, $settingVal);
    if ($ownNumber !== null && $settingVal === '') {
        // Only this organisation's own row, named again in the statement.
        $remove = $mysqli->prepare('DELETE FROM tblSettings WHERE settingID = ? AND siteID = ? AND settingKey = ?');
        $remove->bind_param('iis', $settingId, $siteId, $storedKey);
        $remove->execute();
        $removed = $remove->affected_rows;
        $remove->close();
        if ($removed > 0) {
            Logger::activity('SettingsDelete', 'Removed this organisation\'s own value of: ' . $storedKey, $_SESSION['user_id'] ?? null);
        }
        $_SESSION['flash_msg']  = ($removed > 0
            ? 'This organisation\'s own value of "' . $storedKey . '" was removed. '
            : 'Nothing was removed: this organisation\'s own value of "' . $storedKey . '" was already gone. ')
            . $ownNumber['installation'];
        $_SESSION['flash_type'] = $removed > 0 ? 'success' : 'warning';
        header('Location: ' . Site::url('settings'));
        exit();
    }
    if ($ownNumber !== null && $ownNumber['ok'] === false) {
        // 📝 RECORD IT. A request to exceed the installation's limit crosses a
        //    line between two parties who may be two different people — a
        //    hosting provider and its customer — which is why #668 asks for
        //    the refusal to be on record and not only on screen. Every other
        //    refusal on this page logs `SettingsUpdateRefused`; these two were
        //    the exception until 30 October 2026.
        //
        //    It names the KEY and the ceiling, never the value that was asked
        //    for: the number itself is in the message the person sees, and a
        //    record that repeats it adds nothing an administrator would act on.
        Logger::activity(
            'SettingsUpdateRefused',
            'Refused: "' . $storedKey . '" was not saved for this organisation. ' . $ownNumber['installation'],
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = $ownNumber['words'];
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    // 🔑 THE NAME A PASSKEY IS TIED TO must be a bare host name (#903).
    //    `PasskeyAddress::check()` is the ONE test, the same one its reader
    //    applies, so this page cannot accept what the passkey pages would
    //    ignore. A browser refuses a scheme, a port, a path, capitals, a
    //    closing dot and a number (all measured), and a value it refuses means
    //    no member can add or use a passkey — so it is refused HERE with the
    //    reason, rather than saved and quietly ignored. Capitals and a closing
    //    dot are tidied, not refused. An EMPTY value is allowed: it means "use
    //    the portal's own address", which is the seeded state.
    //    It sits after every refusal above, so somebody who may not change
    //    the row is told the real reason, and before the secret handling,
    //    because this row is never a secret.
    if (PasskeyAddress::isTheSetting($storedKey) === true && $settingVal !== '') {
        $passkeyCheck = PasskeyAddress::check($settingVal);
        if ($passkeyCheck['problem'] !== '') {
            Logger::activity(
                'SettingsUpdateRefused',
                'Refused: "' . $storedKey . '" was not saved, because a passkey cannot be tied to what was typed',
                $_SESSION['user_id'] ?? null
            );
            $_SESSION['flash_msg']  = 'Nothing was saved. ' . $passkeyCheck['problem'];
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . Site::url('settings'));
            exit();
        }
        $settingVal = $passkeyCheck['id'];
    }

    // 🔐 A secret is stored encrypted, and stays a secret (#597).
    //    Decided from the mark on the STORED row, never from the form: see
    //    "SECRETS" in the header. This sits after every refusal above, so a
    //    person who may not change the row never has a value encrypted for
    //    them, and is told the real reason.
    $rowIsSecret  = SecretSettings::isMarked($existing['isSensitive']);
    $markFlag     = $rowIsSecret === true ? 1 : 0;
    $valueToStore = $settingVal;
    $secretChange = '';

    if ($rowIsSecret === true && $clearValue === true) {
        $valueToStore = '';
        $secretChange = 'cleared';
    } elseif ($rowIsSecret === true && $settingVal === '') {
        // 🤲 Nothing typed and nothing ticked: leave the row exactly as it is.
        //    The page never shows a secret, so its box always starts empty.
        //    Treating that as "store an empty value" would wipe a secret every
        //    time somebody opened the box and pressed Save.
        $_SESSION['flash_msg']  = 'Nothing was changed. "' . $storedKey . '" is a secret, and '
            . 'the box was empty, so the stored value was kept. Type a new value to replace '
            . 'it, or tick "Remove the stored value" to remove it.';
        $_SESSION['flash_type'] = 'info';
        header('Location: ' . Site::url('settings'));
        exit();
    } elseif ($rowIsSecret === true) {
        $sealed = SecretSettings::seal($settingVal);
        if ($sealed === null) {
            // The log line names the setting and never the value.
            Logger::activity(
                'SettingsUpdateRefused',
                'Refused: secret setting "' . $storedKey . '" could not be encrypted, so nothing was stored',
                $_SESSION['user_id'] ?? null
            );
            $_SESSION['flash_msg']  = $refusalCannotEncrypt($storedKey);
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . Site::url('settings'));
            exit();
        }
        $valueToStore = $sealed;
        $secretChange = 'replaced';
    }

    // ✏️ Update existing.
    // -------------------------------------------------------------------------
    // 🔒 THE RULES ARE WRITTEN INTO THE STATEMENT, NOT ONLY CHECKED ABOVE
    // -------------------------------------------------------------------------
    // The row refusals above decide what to SAY to the person. The conditions
    // in this UPDATE decide what actually HAPPENS, and they are the same rules.
    //
    // WHAT WAS WRONG: the first version made the decision in PHP from the row
    // it had just read, then ran the UPDATE with only
    //     WHERE settingID = ? AND (siteID = ? OR siteID IS NULL)
    // If the row changed between the read and the write, the write went ahead
    // anyway, on the strength of a check made against what the row used to
    // be. Nothing in the statement itself stopped an administrator of one
    // organisation reaching a portal-wide row, and nothing checked afterwards
    // whether anything had been written: "updated" was shown regardless.
    //
    // NOW the statement carries the rules, using $globalFlag (1 for a global
    // administrator, 0 for anybody else):
    //   - `siteID = ?`, this organisation's own row, is always allowed; a
    //     portal-wide row (`siteID IS NULL`) only when $globalFlag is 1;
    //   - when $globalFlag is 0, the stored name must pass
    //     SettingsRowRule::nameRuleSql(): only safe characters, and not
    //     "public" or "public." in any letter case.
    //     WHAT WAS WRONG with the version before that: it tested only
    //     LOWER(settingKey) NOT LIKE 'public.%'. That missed the bare name
    //     "public", and it missed names the database treats as EQUAL to a
    //     "public." name while LIKE, comparing one character at a time, does
    //     not (see SettingsRowRule::isSafeName() for the cases checked).
    // Neither half of the siteID condition lets anybody reach another
    // organisation's row, even by guessing or forging its settingID.
    //
    // THE MARK IS NOT IN THE "SET" LIST ANY MORE (#597). It used to be written
    // from the form on every save, which is how an edit cleared it. It is in
    // the WHERE instead: the row must still carry the mark the decision above
    // was made on. If it changed in the instant between the read and this
    // write, nothing is written, so a value prepared as plain text can never
    // land in a row that has just become a secret, nor the other way round.
    //
    // Then the number of rows actually written is checked. Zero means nothing
    // happened, and the person is told exactly that.
    $globalFlag = $isGlobalAdmin === true ? 1 : 0;
    $stmt = $mysqli->prepare(
        'UPDATE `tblSettings` SET `settingValue` = ?, `updatedAt` = NOW() '
        . 'WHERE `settingID` = ? AND `isSensitive` = ? '
        . 'AND (`siteID` = ? OR (`siteID` IS NULL AND ? = 1)) '
        . 'AND ' . SettingsRowRule::nameRuleSql()
    );
    if ($stmt === false) {
        $_SESSION['flash_msg']  = t('error.db_update_setting');
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }
    $stmt->bind_param('siiiii', $valueToStore, $settingId, $markFlag, $siteId, $globalFlag, $globalFlag);
    $stmt->execute();
    $rowsWritten = $stmt->affected_rows;
    $stmt->close();

    // 🔎 When nothing was written, find out WHY before saying anything.
    //    MySQL counts a row only when something in it actually changes. So zero
    //    rows has two quite different causes:
    //      (a) the row already held exactly this value, and updatedAt could not
    //          move either because the last write was in the same second. A
    //          double-click on Save does this: the second click arrives
    //          straight after the first has saved, and its answer is the one
    //          the browser shows;
    //      (b) the row no longer matches the rules in the UPDATE, because
    //          somebody renamed, moved or removed it a moment ago.
    //    WHAT WAS WRONG: the first version showed "Nothing was changed" for
    //    both. After a double-click, a save that had worked looked like a
    //    failure. This read uses EXACTLY the conditions of the UPDATE above, so
    //    it only reports "already held that value" for a row the UPDATE was
    //    allowed to write. It only chooses the message. It decides nothing about
    //    who may write what; the UPDATE's own conditions already did that.
    //    For a secret that was REPLACED this can never say "already held":
    //    every encryption of the same value comes out different, so zero rows
    //    there always means cause (b). For one that was CLEARED it can.
    $alreadyHeldValue = false;
    if ($rowsWritten < 1) {
        $recheck = $mysqli->prepare(
            'SELECT `settingValue` FROM `tblSettings` '
            . 'WHERE `settingID` = ? AND `isSensitive` = ? '
            . 'AND (`siteID` = ? OR (`siteID` IS NULL AND ? = 1)) '
            . 'AND ' . SettingsRowRule::nameRuleSql() . ' LIMIT 1'
        );
        if ($recheck !== false) {
            $recheck->bind_param('iiiii', $settingId, $markFlag, $siteId, $globalFlag, $globalFlag);
            $recheck->execute();
            $current = $recheck->get_result()->fetch_assoc();
            $recheck->close();
            $alreadyHeldValue = is_array($current) === true
                && (string) $current['settingValue'] === $valueToStore;
        }
    }

    if ($rowsWritten < 1 && $alreadyHeldValue === true && $secretChange === 'cleared') {
        $_SESSION['flash_msg']  = 'Setting "' . $storedKey . '" had nothing stored, so there was nothing to remove.';
        $_SESSION['flash_type'] = 'success';
    } elseif ($rowsWritten < 1 && $alreadyHeldValue === true) {
        $_SESSION['flash_msg']  = 'Setting "' . $storedKey . '" saved. It already held that value, so nothing needed changing.';
        $_SESSION['flash_type'] = 'success';
    } elseif ($rowsWritten < 1) {
        // 🤷 Nothing was written, and the row does not hold this value under
        //    rules this person may write to. The true thing to say is "nothing
        //    changed".
        $_SESSION['flash_msg']  = 'Nothing was changed. The setting "' . $storedKey
            . '" may have been changed or removed by somebody else a moment ago. '
            . 'Please reload the page and check it.';
        $_SESSION['flash_type'] = 'warning';
    } elseif ($secretChange === 'cleared') {
        Logger::activity('SettingsUpdate', 'Removed the stored value of secret setting: ' . $storedKey, $_SESSION['user_id'] ?? null);
        $_SESSION['flash_msg']  = 'The stored value of "' . $storedKey . '" was removed.';
        $_SESSION['flash_type'] = 'success';
    } elseif ($secretChange === 'replaced') {
        Logger::activity('SettingsUpdate', 'Updated secret setting: ' . $storedKey, $_SESSION['user_id'] ?? null);
        $_SESSION['flash_msg']  = 'Setting "' . $storedKey . '" updated. It is stored encrypted and will not be shown again.';
        $_SESSION['flash_type'] = 'success';
    } else {
        Logger::activity('SettingsUpdate', 'Updated setting: ' . $storedKey, $_SESSION['user_id'] ?? null);
        $_SESSION['flash_msg']  = 'Setting "' . $storedKey . '" updated.';
        $_SESSION['flash_type'] = 'success';
    }
    // ✉️ For one of the mail queue's four numbers: say what the value now
    //    means and what the installation's is. Only after a save that worked.
    if ($ownNumber !== null && $_SESSION['flash_type'] === 'success') {
        $_SESSION['flash_msg'] .= ' ' . $ownNumber['words'];
    }
} else {
    // ➕ Insert new.
    //    This branch always writes the row against the CURRENT organisation
    //    (siteID below is Site::id(), never empty), so the portal-wide row
    //    refusal cannot apply here — nobody can create a portal-wide row from
    //    this page.
    //
    //    The "public" refusal does apply, and it matters: without it an
    //    administrator of one organisation could add "public.something.enabled = true" for
    //    their own organisation and switch a public website on for themselves.
    //    Turning a public surface on is a decision for whoever runs the whole
    //    installation, not for one tenant. Here the key can only come from the
    //    form, because the row does not exist yet, so the posted key is the
    //    right thing to test.
    // 🔤 A NEW setting name may only use the letters A to Z (either case),
    //    digits, full stops, hyphens and underscores.
    //
    //    WHY: ignoring capital and small letters is not the only thing the
    //    database does when it compares names. Its comparison rules
    //    (utf8mb4_unicode_ci) also ignore accents, so to the database
    //    "públic.x" is the same name as "public.x" — yet the letter-case test
    //    below would see nothing wrong with it, and a site administrator could
    //    create what the database treats as a public-website setting. Every
    //    setting name the portal ships already fits this pattern, so nothing
    //    real is lost, and the whole family of look-alike names is shut out at
    //    once instead of being chased one trick at a time.
    //    The same test (SettingsRowRule::isSafeName()) is ALSO applied to the
    //    name already stored when an administrator of one organisation edits
    //    a row; see the row refusals in the edit branch above.
    if (SettingsRowRule::isSafeName($settingKey) === false) {
        $_SESSION['flash_msg']  = 'A setting name can only contain the letters A to Z, '
            . 'numbers, full stops (.), hyphens (-) and underscores (_). Spaces, '
            . 'accented letters and other symbols are not allowed.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    // 🔑 Nor may the name a passkey is tied to be ADDED as one organisation's
    //    own value (#903). Its reader takes the installation-wide row ONLY
    //    (`PasskeyAddress`, which says why: a wider name lets more sites ask
    //    members' authenticators for answers, a decision for whoever runs the
    //    whole installation). A row added here would be saved, shown on this
    //    page and never used — a setting that says one thing and does nothing.
    //    It runs BEFORE the duplicate check below, so it is what anybody
    //    adding this key is told, whether or not the installation-wide row
    //    exists (the install script seeds it; the duplicate check would refuse
    //    with "already exists", which does not say why). Where that row has
    //    been removed, it is also what stops an organisation's row appearing.
    if (PasskeyAddress::isTheSetting($settingKey) === true) {
        Logger::activity(
            'SettingsUpdateRefused',
            'Refused: "' . PasskeyAddress::SETTING . '" was not added for one organisation, because only the installation-wide value is ever used',
            $_SESSION['user_id'] ?? null
        );
        // The message does not say "a global administrator sets it here":
        // where the installation-wide row has been removed, this page cannot
        // create one (this branch always writes an organisation's row).
        $_SESSION['flash_msg']  = 'Nothing was saved. "' . PasskeyAddress::SETTING . '" is used only as '
            . 'one value for the whole installation, never as one organisation\'s own, so a row added '
            . 'here would have no effect. Passkeys follow the portal\'s own address while it is not set.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    // 🔒 Nor may one of the giving-records settings be ADDED here for this
    //    organisation: adding its own "confirmed" row is exactly how an
    //    organisation that has confirmed nothing would start having kept
    //    donor records cleared. See the note in the edit branch above. (This
    //    branch only ever writes a row for the current organisation, so the
    //    installation-wide exception does not arise.)
    if (GivingRetention::lockedInGeneralEditor($settingKey, false, $isGlobalAdmin) === true) {
        Logger::activity(
            'SettingsUpdateRefused',
            'Refused: "' . $settingKey . '" is part of the giving-records rule and can only be set on its own page',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = GivingRetention::LOCKED_MESSAGE;
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    // 🔒 Nor may either of the two "how organisations are told apart"
    //    settings be ADDED here as one organisation's own row (#502).
    //    Nothing reads such a row, so it would only mislead whoever found
    //    it. See Site::lockedInGeneralEditor().
    // 🔒 Nor the copied-in events rule (#493 P1, round 7): ADDING it here
    //    would skip the re-work its own card runs. tools/settings-locks-selftest.php
    //    found this path and the delete path missing when it first ran.
    if (\Portal\Core\PublicWebsite::lockedInGeneralEditor($settingKey) === true) {
        Logger::activity(
            'SettingsUpdateRefused',
            'Refused: "' . $settingKey . '" is set only on ' . (string) (\Portal\Core\PublicWebsite::lockedPageFor($settingKey)['name'] ?? ''),
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = \Portal\Core\PublicWebsite::lockedMessageFor($settingKey);
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }
    // 🔒 Nor what the noticeboard's foyer screen shows (#493 P2b, D-086): its
    //    own page holds an organisation's choice to what the installation
    //    permits, which this editor cannot judge. See
    //    NoticeboardFoyer::lockedInGeneralEditor().
    if (\Portal\Core\NoticeboardFoyer::lockedInGeneralEditor($settingKey) === true) {
        Logger::activity(
            'SettingsUpdateRefused',
            'Refused: "' . $settingKey . '" is set only on Noticeboard, Foyer screen',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = \Portal\Core\NoticeboardFoyer::LOCKED_MESSAGE;
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }
    // 💷 Nor the currency (#966): adding a row for it here would skip the
    //    organisation page's check for money already recorded.
    if (\Portal\Core\Currency::lockedInGeneralEditor($settingKey) === true) {
        Logger::activity(
            'SettingsUpdateRefused',
            'Refused: "' . $settingKey . '" is the currency and can only be set on the organisation settings page',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = \Portal\Core\Currency::LOCKED_MESSAGE;
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }
    if (Site::lockedInGeneralEditor($settingKey) === true) {
        Logger::activity(
            'SettingsUpdateRefused',
            'Refused: "' . $settingKey . '" decides how organisations are told apart and can only be set on its own page',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = Site::LOCKED_MESSAGE;
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    // 🔒 Nor may the old time-zone setting, or migration 217's mark, be
    //    ADDED here (#604). Nothing reads "site.timezone" once that migration
    //    has run, so a row typed in would only mislead whoever found it: the
    //    organisation's zone is chosen at Admin, Organisations. See
    //    Site::zoneLockedInGeneralEditor().
    if (Site::zoneLockedInGeneralEditor($settingKey) === true) {
        Logger::activity(
            'SettingsInsertRefused',
            'Refused: "' . $settingKey . '": an organisation\'s time zone is chosen at Admin, Organisations and nowhere else',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = Site::zoneLockedMessage();
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    // 🔒 Nor may the mail server's name or either certificate row be ADDED
    //    here (#592). This branch only ever writes a row for the current
    //    organisation, and nothing reads an organisation's own row of these
    //    (one mail route serves every organisation), so it would only
    //    mislead whoever found it. It also covers an installation whose own
    //    row has gone missing: the row is then put back by the e-mail page
    //    or the database upgrade, not typed in here with no warning. See
    //    MailRoutes::lockedInGeneralEditor().
    if (MailRoutes::lockedInGeneralEditor($settingKey) === true) {
        Logger::activity(
            'SettingsInsertRefused',
            'Refused: "' . $settingKey . '" is set on the e-mail page (Admin, Integrations, Email) and nowhere else',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = MailRoutes::LOCKED_MESSAGE;
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    // 🔒 Nor may the recorded version be ADDED here (#499): not as one
    //    organisation's own row, which start-up does not read and which
    //    would only mislead whoever found it, and not as the installation's
    //    row where an older installation has none (the Upgrade page's button
    //    writes that one). See Maintenance::lockedInGeneralEditor().
    if (\Portal\Core\Maintenance::lockedInGeneralEditor($settingKey) === true) {
        Logger::activity(
            'SettingsUpdateRefused',
            'Refused: "' . $settingKey . '" records the version the database is at and is written only by the installer and the Upgrade page',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = \Portal\Core\Maintenance::LOCKED_MESSAGE;
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    // 🔒 Nor may the reserved-capability list be ADDED here (#727), as the
    //    installation's row where there is none, nor as an organisation's own
    //    row — `installationLocks()` reads the installation's layer only, so
    //    an organisation's row of that name would reserve nothing and merely
    //    mislead whoever found it. See Capabilities::lockedInGeneralEditor().
    if (\Portal\Core\Capabilities::lockedInGeneralEditor($settingKey) === true) {
        Logger::activity(
            'SettingsInsertRefused',
            'Refused: "' . $settingKey . '" lists the capabilities this installation reserves and is written only by the Roles page',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = \Portal\Core\Capabilities::LOCKED_MESSAGE;
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    // 🎙️ #722, the same refusal on the ADD path. Both paths are needed: a key
    //    refused on update and allowed on insert is a key anybody can set once.
    // 🧒 #816, the same refusal on the ADD path: a key refused on update and
    //    allowed on insert is a key anybody can set once.
    if (\Portal\Core\RegistrationRetention::lockedInGeneralEditor($settingKey) === true) {
        Logger::activity(
            'SettingsInsertRefused',
            'Refused: "' . $settingKey . '" is how long event registrations are kept and is written only on its own page',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = \Portal\Core\RegistrationRetention::LOCKED_MESSAGE;
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    // 📜 #932, the same refusal on the ADD path: a key refused on update and
    //    allowed on insert is a key anybody can set once.
    if (\Portal\Core\ActivityLogRetention::lockedInGeneralEditor($settingKey) === true) {
        Logger::activity(
            'SettingsInsertRefused',
            'Refused: "' . $settingKey . '" is how long the activity log is kept and is written only on its own page',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = \Portal\Core\ActivityLogRetention::LOCKED_MESSAGE;
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    if (\Portal\Core\TranscriptRetention::lockedInGeneralEditor($settingKey) === true) {
        Logger::activity(
            'SettingsInsertRefused',
            'Refused: "' . $settingKey . '" is how long transcripts are kept and is written only by its own pages',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = \Portal\Core\TranscriptRetention::LOCKED_MESSAGE;
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    // 🛂 #778, the same refusal on the ADD path: an organisation's row of one of
    //    these keys is exactly what its own page refuses when it is looser than
    //    the installation, so adding one here would step round that refusal.
    if (\Portal\Core\OutsideServices::lockedInGeneralEditor($settingKey) === true) {
        Logger::activity(
            'SettingsInsertRefused',
            'Refused: "' . $settingKey . '" decides what outside companies may be sent about a member and is written only by its own page',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = \Portal\Core\OutsideServices::LOCKED_MESSAGE;
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    // 🚪 #615, D-102, the same refusal on the ADD path: an organisation's row
    //    would be ignored by the reader (it reads the installation's row only),
    //    and an installation row added here would be a change nobody recorded.
    if (\Portal\Core\WebhookPorts::lockedInGeneralEditor($settingKey) === true) {
        Logger::activity(
            'SettingsInsertRefused',
            'Refused: "' . $settingKey . '" decides which port numbers a webhook may use and is written only by the Webhooks page',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = \Portal\Core\WebhookPorts::LOCKED_MESSAGE;
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    if (SettingsRowRule::isPublicName($settingKey) === true && $isGlobalAdmin === false) {
        Logger::activity(
            'SettingsInsertRefused',
            'Refused: public-website setting "' . $settingKey . '" may only be added by a global administrator',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = SettingsRowRule::refusalWords(SettingsRowRule::PUBLIC_NAME, 'change');
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    // ✉️ ONE OF THE MAIL QUEUE'S FOUR NUMBERS, ADDED AS THIS ORGANISATION'S
    //    OWN (#592; see the same heading in the edit branch above).
    //    WHAT WAS WRONG: the duplicate check just below counts the
    //    installation's row as "already exists", and the installation has a
    //    row for each of the four. So nobody, not even a global
    //    administrator, could give one organisation its own value through
    //    a page: it could only be put there with SQL.
    //    For exactly these four names (letter for letter; any other
    //    spelling is null here and meets the duplicate check as before):
    //      - the value must be one the queue will accept, or nothing is
    //        saved;
    //      - only THIS organisation's own row counts as a duplicate;
    //      - it is never stored as a secret, whatever the form says: the
    //        queue reads the number as written, and an encrypted one would
    //        read as "not a number".
    //    Every refusal above this line has already been applied.
    $ownNumber = MailQueue::ownNumberCheck($mysqli, $settingKey, $settingVal);
    if ($ownNumber !== null && $ownNumber['ok'] === false) {
        // 📝 RECORD IT. A request to exceed the installation's limit crosses a
        //    line between two parties who may be two different people — a
        //    hosting provider and its customer — which is why #668 asks for
        //    the refusal to be on record and not only on screen. Every other
        //    refusal on this page logs `SettingsUpdateRefused`; these two were
        //    the exception until 30 October 2026.
        //
        //    It names the KEY and the ceiling, never the value that was asked
        //    for: the number itself is in the message the person sees, and a
        //    record that repeats it adds nothing an administrator would act on.
        Logger::activity(
            'SettingsUpdateRefused',
            'Refused: "' . $settingKey . '" was not saved for this organisation. ' . $ownNumber['installation'],
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = $ownNumber['words'];
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }
    if ($ownNumber !== null) {
        $isSensitive = 0;
    }
    $onlyOwnRowClashes = $ownNumber !== null ? 1 : 0;

    // 🔑 Refuse a name the database would treat as one that already exists,
    //    for this organisation or portal-wide.
    //
    //    The lookup deliberately compares with the column's own rules
    //    (`settingKey = ?`), which ignore letter case: the trap being avoided
    //    is exactly "two names the DATABASE treats as one", so the database is
    //    the right judge of it.
    //
    //    WHY A NEAR-DUPLICATE IS REFUSED, WITH ITS OWN MESSAGE: two rows whose
    //    names differ only in capital and small letters are ONE name to every
    //    database lookup (App::settingForSite() finds either), but TWO names
    //    to the settings list PHP builds at start-up, where "Site.name" and
    //    "site.name" sit in different places. Which value is used would then
    //    depend on how the setting happens to be read — a trap for whoever
    //    comes next. The message names the existing row, so the administrator
    //    knows what to edit instead of wondering why "it already exists" when
    //    they can see it does not, letter for letter.
    //    (For one of the mail queue's four numbers the installation's row
    //    is left out of this lookup: see $onlyOwnRowClashes above.)
    $stmt = $mysqli->prepare('SELECT settingKey FROM tblSettings WHERE settingKey = ? AND (siteID = ? OR (siteID IS NULL AND ? = 0)) LIMIT 1');
    if ($stmt !== false) {
        $stmt->bind_param('sii', $settingKey, $siteId, $onlyOwnRowClashes);
        $stmt->execute();
        $clash = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (is_array($clash) === true) {
            $existingKey = (string) $clash['settingKey'];
            if ($existingKey === $settingKey) {
                $_SESSION['flash_msg'] = 'Setting key "' . $settingKey . '" already exists.';
            } else {
                $_SESSION['flash_msg'] = 'A setting called "' . $existingKey . '" already '
                    . 'exists. "' . $settingKey . '" differs from it only in capital and '
                    . 'small letters, and the database treats those as the same name, so '
                    . 'it cannot be added as a separate setting. To change the value, edit "'
                    . $existingKey . '" instead.';
            }
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . Site::url('settings'));
            exit();
        }
    }

    // 🌳 Refusal 4 — a name that would be both a single VALUE and a GROUP.
    //
    //    WHY: when the portal loads its settings it turns the full stops in a
    //    name into nesting (assign_setting() in web/_core/bootstrap.php). A
    //    name, and a longer name made of it plus a full stop and more, cannot
    //    both fit: "portal" would be one value, but "portal.headers.coop"
    //    needs "portal" to be a group. Whichever row is loaded LAST wins, and
    //    it wipes out the other without a word:
    //      - a row called "portal" replaces the WHOLE "portal." group;
    //      - a row called "site.name.extra" turns the value of "site.name"
    //        into a group, so "site.name" itself disappears.
    //    An organisation's own rows are loaded after the portal-wide ones, so
    //    a single row added here quietly changes that organisation's copy of
    //    settings it does not own. Checked 13 September 2026 on MySQL 8.0.36
    //    with the real loader: after a site administrator added "portal",
    //    every "portal." setting read through App::settings() came back empty
    //    for that organisation, maintenance mode and the security-header
    //    values included. Two were NOT affected, because they are read
    //    straight from the portal-wide row so that no organisation's rows can
    //    touch them: portal.trustedProxies (RateLimiter) and
    //    portal.gatekeeper.enabled (Gatekeeper).
    //
    //    WHO IT APPLIES TO: everybody, a global administrator included. This is
    //    not about permission. Nobody can make the loaded settings hold both,
    //    so allowing it could only ever break something. Among portal-wide
    //    rows the loading order is not even fixed, so which one won could
    //    change without anybody touching anything.
    //
    //    HOW: every name this organisation can see (its own rows and the
    //    portal-wide ones) is compared in PHP, ignoring capital and small
    //    letters as the duplicate check above does. PHP rather than SQL,
    //    because LIKE reads "_", which names may contain, as "any character",
    //    and escaping that correctly is easy to get wrong. After lower-casing,
    //    the comparison is byte for byte, which is how the loader itself
    //    splits names. strtolower() changes only A to Z (PHP 8.2 onwards),
    //    whatever the server's language settings. If the list cannot be read,
    //    nothing is added: a check that silently did not run would look
    //    exactly like one that passed.
    //
    //    WHAT IT CANNOT DO: it only sees the rows that exist NOW. If a
    //    portal-wide "myorg.colour" is added later, an organisation's older row
    //    called "myorg" will still hide it, and clashes stored before this
    //    check existed are not removed. Closing those would need a change to
    //    the loader in bootstrap.php.
    $shapeClash = null;
    $shapeStmt  = $mysqli->prepare('SELECT settingKey FROM tblSettings WHERE siteID = ? OR siteID IS NULL');
    if ($shapeStmt === false) {
        $_SESSION['flash_msg']  = t('error.db_add_setting');
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }
    $newKeyLower = strtolower($settingKey);
    $shapeStmt->bind_param('i', $siteId);
    $shapeStmt->execute();
    $shapeRows = $shapeStmt->get_result();
    while (is_array($shapeRow = $shapeRows->fetch_assoc()) === true) {
        $otherKey      = (string) $shapeRow['settingKey'];
        $otherKeyLower = strtolower($otherKey);
        if (str_starts_with($otherKeyLower, $newKeyLower . '.') === true) {
            $shapeClash = 'A setting called "' . $otherKey . '" already exists, so "'
                . $settingKey . '" is already the name of a group of settings.';
            break;
        }
        if (str_starts_with($newKeyLower, $otherKeyLower . '.') === true) {
            $shapeClash = '"' . $otherKey . '" is already a setting with its own value, so "'
                . $settingKey . '" cannot be added inside it.';
            break;
        }
    }
    $shapeStmt->close();

    if ($shapeClash !== null) {
        $_SESSION['flash_msg']  = $shapeClash . ' A setting cannot be both a single value '
            . 'and a group: when the portal loads its settings, one would silently '
            . 'replace the other. Please choose a different name.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }

    // 🔐 A new secret is encrypted here, after every refusal above. An empty
    //    one is stored empty (seal() leaves '' alone), so "not set" stays true.
    $valueToStore = $settingVal;
    if ($isSensitive === 1) {
        $sealed = SecretSettings::seal($settingVal);
        if ($sealed === null) {
            Logger::activity(
                'SettingsInsertRefused',
                'Refused: secret setting "' . $settingKey . '" could not be encrypted, so nothing was stored',
                $_SESSION['user_id'] ?? null
            );
            $_SESSION['flash_msg']  = $refusalCannotEncrypt($settingKey);
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . Site::url('settings'));
            exit();
        }
        $valueToStore = $sealed;
    }

    $stmt = $mysqli->prepare(
        'INSERT INTO tblSettings (settingKey, settingValue, isSensitive, siteID, updatedAt) VALUES (?, ?, ?, ?, NOW())'
    );
    if ($stmt === false) {
        $_SESSION['flash_msg']  = t('error.db_add_setting');
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
    }
    $stmt->bind_param('ssii', $settingKey, $valueToStore, $isSensitive, $siteId);
    $stmt->execute();
    $stmt->close();
    Logger::activity('SettingsInsert', 'Added setting: ' . $settingKey, $_SESSION['user_id'] ?? null);
    $_SESSION['flash_msg'] = 'Setting "' . $settingKey . '" added.' . ($ownNumber !== null ? ' ' . $ownNumber['words'] : '');
    if ($isSensitive === 1 && $settingVal !== '') {
        $_SESSION['flash_msg'] .= ' It is stored encrypted and will not be shown again.';
    } elseif ($isSensitive === 1) {
        $_SESSION['flash_msg'] .= ' It is a secret with no value yet.';
    }
    $_SESSION['flash_type'] = 'success';
}

// 🔄 Redirect back (PRG pattern)
header('Location: ' . Site::url('settings'));
exit();
