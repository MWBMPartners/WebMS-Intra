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
use Portal\Core\Router;
use Portal\Core\SecretSettings;
use Portal\Core\Site;

// 🛡️ Admin access check
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// 🛡️ Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /settings');
    exit();
}

// 🛡️ CSRF verification
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /settings');
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
    header('Location: /settings');
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
//    one organisation? The difference decides the two refusals below.
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
// 🚧 THE REFUSALS, AND WHY THEY EXIST
// -----------------------------------------------------------------------------
// Refusals 1 and 2 are described here. Refusal 3 (a stored name outside the
// safe characters) is explained beside $isSafeName and applied in the edit
// branch. Refusal 4 (a name that would be both a value and a group) is
// explained where it runs, in the "Insert new" branch. Refusals 1 to 3 apply
// to everybody except a global administrator; refusal 4 applies to everybody.
//
// Getting in here needs App::isAdmin(), and that is true for an administrator
// of ONE organisation as well as for a global administrator (see
// web/_core/App.php — isAdmin() accepts isSiteAdmin and isSiteRootAdmin too).
// So "an administrator" on this page does NOT mean "somebody in charge of the
// whole installation".
//
// REFUSAL 1 — portal-wide rows.
//   A settings row with no organisation against it (siteID is empty) is the
//   fallback EVERY organisation on the installation reads when it has not set
//   its own value. Until now the update ran
//       WHERE settingID = ? AND (siteID = ? OR siteID IS NULL)
//   so an administrator of one organisation could change a row that every
//   other organisation then inherits — quietly, from a page that looks like it
//   is about their own settings. Changing one of those is now reserved for a
//   global administrator. Changing their OWN organisation's row is untouched
//   and still works exactly as before.
//
// REFUSAL 2 — the key "public" on its own, and keys beginning "public.".
//   (The bare name is included because a row called just "public" replaces
//   every "public." setting when settings are loaded; see $isPublicKey.)
//   Those keys will control a genuinely public website that anybody on the
//   internet can read, with no sign-in at all. None of them exist yet. The
//   gate is being put in place BEFORE the feature that needs it, so that the
//   feature cannot arrive into a page that already lets the wrong people
//   switch it on. Note this is checked against the key STORED IN THE DATABASE
//   for an edit, never the key sent with the form — otherwise somebody could
//   send a harmless-looking key alongside the row number of a "public." row
//   and slip straight past.
//
// Both refusals explain themselves on screen. A bare "forbidden" with no
// reason leaves an administrator believing the portal is broken, and the next
// thing they do is try again.
// WHAT WAS WRONG with the first wording: it also suggested "add a setting of
// the same name for your own organisation instead". This page refuses exactly
// that. Its duplicate check (in the "Insert new" branch below, and already
// there before this change) counts a portal-wide row as an existing name. The
// advice sent administrators to something they could not do, so it was removed.
$refusalGlobalRow = 'That setting is portal-wide: it is the value every '
    . 'organisation on this installation uses unless it has set its own. '
    . 'Only a global administrator can change it. If you need it changed, '
    . 'please ask one.';
$refusalPublicKey = 'The setting called "public", and every setting whose name begins '
    . '"public.", control the public website, which anybody on the internet can '
    . 'read without signing in. Only a global administrator can change those.';
$refusalUnsafeName = 'This setting\'s name contains characters other than the letters '
    . 'A to Z, numbers, full stops (.), hyphens (-) and underscores (_). The '
    . 'database can treat a name like that as the same name as a protected '
    . 'setting, so only a global administrator can change it. If you need it '
    . 'changed, please ask one.';

// 🔡 Is a setting name reserved for the public website — "public" on its own,
//    or anything beginning "public." — in ANY letter case?
//
//    WHAT WAS WRONG, TWICE:
//    1. The first version tested with str_starts_with(), which is
//       case-sensitive. The database is not. The settingKey column compares
//       names with utf8mb4_unicode_ci, which ignores the difference between
//       capital and small letters, so a database lookup for "public.example"
//       finds a row saved as "PUBLIC.example". A row named that way walked
//       straight past the refusal and was then read as the public-website
//       setting anyway.
//    2. The second version only looked for "public." WITH the full stop, so
//       the bare name "public" slipped through, and it passed the new-name
//       check as well. That one name does more damage than any single
//       "public." setting. When the portal loads its settings it turns the
//       full stops into nesting (assign_setting() in web/_core/bootstrap.php):
//       "public.website.enabled" becomes "enabled" inside "website" inside a
//       group called "public". A row called just "public" is loaded as a
//       plain value in the very place that group lives, and because an
//       organisation's own rows are loaded after the portal-wide ones, it
//       replaces the whole group — every public-website setting — for that
//       organisation at once. Checked 13 September 2026 on MySQL 8.0.36 with
//       the real loader: a site administrator added "public = disabled" and
//       {"public":{"website":{"enabled":"true"}}} became {"public":"disabled"}.
//    Setting names in this portal are NOT all lower case (portal.trustedProxies,
//    for one), so the name itself is never changed to lower case. Only this
//    test ignores case.
$isPublicKey = static fn (string $key): bool =>
    strcasecmp($key, 'public') === 0 || strncasecmp($key, 'public.', 7) === 0;

// 🔤 Is a name made ONLY of the letters A to Z (either case), digits, full
//    stops, hyphens and underscores?
//
//    Used twice: a NEW name must pass it (see "Insert new" below), and a name
//    ALREADY STORED must pass it before an administrator of one organisation
//    may change that row (refusal 3 below).
//
//    WHY THE STORED NAME IS CHECKED TOO — WHAT WAS WRONG: only new names were
//    checked, and the "public." test on an existing row compared one
//    character at a time. The database does not compare names that way.
//    Checked 13 September 2026 on MySQL 8.0.36, using this column's own rules
//    (utf8mb4_unicode_ci): each of these is EQUAL to "public.example" in an
//    ordinary lookup —
//      - "públic.example" with the accent typed as a separate mark after "u"
//      - "ｐｕｂｌｉｃ.example" in full-width letters
//      - "public．example" with a full-width full stop
//      - "pu", an invisible zero-width space, then "blic.example"
//      - "pu", the invisible control character number 1, then "blic.example"
//        (plain ASCII: one byte per character)
//    — yet the separate-accent, zero-width and control-character ones all
//    FAIL the test LIKE 'public.%', which the UPDATE used to rely on. A row
//    already stored under such a name (from before the new-name check, or
//    written by a script) could be edited by an administrator of one
//    organisation, and would still be found by a lookup for the real name.
//
//    WHAT WAS CONSIDERED AND REJECTED:
//      - Converting names to one standard Unicode form before comparing. That
//        needs PHP's Normalizer class, which shared hosting may not have, and
//        it would only ever cover the tricks somebody thought of in advance.
//      - "Refuse any name containing a character wider than one byte". It
//        catches accents and full-width letters but NOT the control-character
//        example above, which is a single byte.
//    So the rule is written the other way round: allow only the few characters
//    that cannot be confused with anything, and treat every other stored name
//    as reserved for a global administrator. None of the 567 settings a fresh
//    installation seeds breaks this rule (checked against full_schema.sql on
//    MySQL 8.0.36), so no real setting is locked away by it.
$isSafeName = static fn (string $key): bool => preg_match('/^[A-Za-z0-9._-]+\z/', $key) === 1;

// 🧱 The same name rules, written for the database, so the UPDATE below can
//    carry them inside the statement. web/_apps/settings/index.php repeats
//    this text in its DELETE; if one changes, change both.
//    It has ONE placeholder, $globalFlag: 1 lets a global administrator
//    through whatever the name. For anybody else ALL of these must hold:
//      - CHAR_LENGTH = LENGTH: every character is a single byte, so no accent,
//        full-width letter or invisible Unicode mark;
//      - COLLATE utf8mb4_bin NOT REGEXP '[^A-Za-z0-9._-]': no character outside
//        the safe set, compared byte for byte. Two details are deliberate, and
//        both were checked on MySQL 8.0.36. Without utf8mb4_bin the pattern
//        ignores letter case, and then the Kelvin sign (a separate Unicode
//        character that looks like K) is NOT flagged. And the pattern looks
//        for one BAD character instead of matching a whole good name between
//        ^ and $, because $ also matches just before a final line break, so
//        "abc" followed by a new line would pass;
//      - settingKey <> '': an empty name fails $isSafeName too;
//      - not "public", and not beginning "public.", in any letter case.
//    The first two between them do the job of $isSafeName. Both are kept, so
//    that the rule does not rest on one regular-expression engine alone.
$nameRuleSql = "(? = 1 OR (CHAR_LENGTH(settingKey) = LENGTH(settingKey) "
    . "AND settingKey COLLATE utf8mb4_bin NOT REGEXP '[^A-Za-z0-9._-]' "
    . "AND settingKey <> '' "
    . "AND LOWER(settingKey) <> 'public' "
    . "AND LOWER(settingKey) NOT LIKE 'public.%'))";

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
        header('Location: /settings');
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
        header('Location: /settings');
        exit();
    }

    // 🔒 Nor are the two settings that decide how organisations are told
    //    apart changed here (#502), by anybody, global administrator
    //    included. They have their own page, which refuses a way that would
    //    leave an organisation unreachable; here one typed word would change
    //    which organisation every address on the installation opens. See
    //    Site::lockedInGeneralEditor().
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

    if ($isPortalWideRow === true && $isGlobalAdmin === false) {
        Logger::activity(
            'SettingsUpdateRefused',
            'Refused: portal-wide setting "' . $storedKey . '" may only be changed by a global administrator',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = $refusalGlobalRow;
        $_SESSION['flash_type'] = 'danger';
        header('Location: /settings');
        exit();
    }

    if ($isPublicKey($storedKey) === true && $isGlobalAdmin === false) {
        Logger::activity(
            'SettingsUpdateRefused',
            'Refused: public-website setting "' . $storedKey . '" may only be changed by a global administrator',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = $refusalPublicKey;
        $_SESSION['flash_type'] = 'danger';
        header('Location: /settings');
        exit();
    }

    // 🚧 Refusal 3 — a stored name outside the safe characters. The database
    //    can treat such a name as equal to a reserved one, so the letter-case
    //    test above cannot be trusted to recognise it. See $isSafeName.
    if ($isSafeName($storedKey) === false && $isGlobalAdmin === false) {
        Logger::activity(
            'SettingsUpdateRefused',
            'Refused: setting "' . $storedKey . '" has a name outside the safe characters and may only be changed by a global administrator',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = $refusalUnsafeName;
        $_SESSION['flash_type'] = 'danger';
        header('Location: /settings');
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
        $_SESSION['flash_msg']  = $ownNumber['words'];
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('settings'));
        exit();
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
        header('Location: /settings');
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
            header('Location: /settings');
            exit();
        }
        $valueToStore = $sealed;
        $secretChange = 'replaced';
    }

    // ✏️ Update existing.
    // -------------------------------------------------------------------------
    // 🔒 THE RULES ARE WRITTEN INTO THE STATEMENT, NOT ONLY CHECKED ABOVE
    // -------------------------------------------------------------------------
    // The two refusals above decide what to SAY to the person. The conditions
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
    //   - when $globalFlag is 0, the stored name must pass $nameRuleSql: only
    //     safe characters, and not "public" or "public." in any letter case.
    //     WHAT WAS WRONG with the version before that: it tested only
    //     LOWER(settingKey) NOT LIKE 'public.%'. That missed the bare name
    //     "public", and it missed names the database treats as EQUAL to a
    //     "public." name while LIKE, comparing one character at a time, does
    //     not (see the note above $isSafeName for the cases checked).
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
        'UPDATE tblSettings SET settingValue = ?, updatedAt = NOW() '
        . 'WHERE settingID = ? AND isSensitive = ? '
        . 'AND (siteID = ? OR (siteID IS NULL AND ? = 1)) '
        . 'AND ' . $nameRuleSql
    );
    if ($stmt === false) {
        $_SESSION['flash_msg']  = t('error.db_update_setting');
        $_SESSION['flash_type'] = 'danger';
        header('Location: /settings');
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
            'SELECT settingValue FROM tblSettings '
            . 'WHERE settingID = ? AND isSensitive = ? '
            . 'AND (siteID = ? OR (siteID IS NULL AND ? = 1)) '
            . 'AND ' . $nameRuleSql . ' LIMIT 1'
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
    //    (siteID below is Site::id(), never empty), so refusal 1 cannot apply
    //    here — nobody can create a portal-wide row from this page.
    //
    //    Refusal 2 does apply, and it matters: without it an administrator of
    //    one organisation could add "public.something.enabled = true" for
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
    //    The same test ($isSafeName) is now ALSO applied to the name already
    //    stored when an administrator of one organisation edits a row; see
    //    refusal 3 in the edit branch above.
    if ($isSafeName($settingKey) === false) {
        $_SESSION['flash_msg']  = 'A setting name can only contain the letters A to Z, '
            . 'numbers, full stops (.), hyphens (-) and underscores (_). Spaces, '
            . 'accented letters and other symbols are not allowed.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /settings');
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
        header('Location: /settings');
        exit();
    }

    // 🔒 Nor may either of the two "how organisations are told apart"
    //    settings be ADDED here as one organisation's own row (#502).
    //    Nothing reads such a row, so it would only mislead whoever found
    //    it. See Site::lockedInGeneralEditor().
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

    if ($isPublicKey($settingKey) === true && $isGlobalAdmin === false) {
        Logger::activity(
            'SettingsInsertRefused',
            'Refused: public-website setting "' . $settingKey . '" may only be added by a global administrator',
            $_SESSION['user_id'] ?? null
        );
        $_SESSION['flash_msg']  = $refusalPublicKey;
        $_SESSION['flash_type'] = 'danger';
        header('Location: /settings');
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
            header('Location: /settings');
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
        header('Location: /settings');
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
        header('Location: /settings');
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
            header('Location: /settings');
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
        header('Location: /settings');
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
header('Location: /settings');
exit();
