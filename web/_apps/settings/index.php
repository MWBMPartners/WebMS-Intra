<?php
// Path: public_html/settings/index.php
/**
 * -----------------------------------------------------------------------------
 * Settings Admin UI ⚙️
 * -----------------------------------------------------------------------------
 * Allows Admin / Global Admin users to view and edit portal & app settings
 * stored in tblSettings. Settings are grouped by their dot-notation prefix
 * (e.g. site.*, auth.*, expenses.*). Features:
 *   - Grouped accordion display by setting prefix
 *   - Secrets (rows whose isSensitive mark is on) are NEVER shown: the page
 *     says whether one is set, and lets it be replaced or removed (#597)
 *   - Add new settings via modal
 *   - Delete settings (root admin only)
 *   - Search/filter settings
 *   - Flash messages for success/error feedback
 *
 * SECRETS ARE NEVER SHOWN (#597, 12 October 2026)
 *   WHAT WAS HERE: a global administrator had an "eye" button beside each
 *   secret, and the stored text was already in the page behind it. That text
 *   was the scramble for a properly stored secret, and the REAL value for any
 *   secret the old edit box had stored unprotected.
 *   NOW a secret's value is not put into the page in any form: not in the
 *   list, not in the edit box, not in an attribute for a script to read. Every
 *   value that may appear goes through $shownValue below, which is empty for
 *   a secret. Please keep it that way: a value printed "only for a global
 *   administrator" is still in the page source, the browser's cache and any
 *   screen share.
 *   WHAT AN ADMINISTRATOR SEES INSTEAD: "Not set", "Set", or "Set, but needs
 *   entering again" (stored as readable text, or encrypted with a key file
 *   that has since changed), with a notice at the top naming every setting in
 *   that last state. The page cannot tell those two causes apart, so the
 *   notice describes both and says nothing that is true of only one. An
 *   administrator of ONE organisation is told nothing at all about an
 *   installation-wide secret, not even whether it is set.
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
use Portal\Core\Captcha;
use Portal\Core\GivingRetention;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\SecretSettings;
use Portal\Core\Site;

// 📌 Page metadata for the template system
$pageTitle   = 'Settings';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Settings' => ''];

// 🛡️ Admin access check (Router handles isProtected login enforcement)
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// 🛡️ Global administrator? App::isRootAdmin() is the one place that decides.
//    For a short while this line ALSO read the flag straight off App::user()
//    and converted it to a whole number. That was a stop-gap: App::isRootAdmin()
//    used to compare with the text '1' while the database hands back the whole
//    number 1, so it was false for every real global administrator, and nobody
//    could delete a setting here. (It also decided who saw a secret's stored
//    text; nobody does now. See "SECRETS ARE NEVER SHOWN" in the header.)
//    App.php now accepts both forms (App::flagIsOn()), so the extra check was
//    removed. Please do not put it back; the full note is beside the same line
//    in web/_apps/settings/save.php.
$isRoot = App::isRootAdmin();

// -----------------------------------------------------------------------------
// 🗑️ Handle delete action (POST, root admin only)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /settings');
        exit();
    }
    // -------------------------------------------------------------------------
    // 🚧 THE REFUSALS THAT MATCH THE SAVE HANDLER
    // -------------------------------------------------------------------------
    // Deleting a setting has always been reserved for a global administrator
    // on this page, so the rules below are already satisfied by that gate
    // alone: portal-wide rows, "public" and "public." names, and stored names
    // outside the safe characters. They are still written out, for two
    // reasons.
    //
    // First, the message. "Only root admins can delete settings" tells an
    // administrator nothing about WHY this particular row is different from
    // the rows they are allowed to change on the same screen. Saying that the
    // row is portal-wide, or that it controls the public website, is the
    // difference between an administrator who understands the rule and one
    // who thinks the portal is broken.
    //
    // Second, and more important: if anybody ever widens the gate below from
    // "global administrator" to "any administrator", these two checks are
    // what keeps the rule true. Deleting a portal-wide row is not a smaller
    // act than editing one — it removes the value every other organisation on
    // the installation was reading.
    //
    // Deliberately NOT done here: telling somebody a row exists that they
    // could not otherwise see. The lookup is scoped exactly like the delete,
    // so a row belonging to another organisation reads as "not found".
    $deleteId     = (int) ($_POST['settingID'] ?? 0);
    $deleteSiteId = Site::id();

    // 🔒 The giving-records rule cannot be deleted here by ANYBODY, a global
    //    administrator included (roll-out package A2b). Deleting an
    //    organisation's own "hold" row switches its hold off; deleting its
    //    year-end row moves every keep-until date. Either can put kept donor
    //    records past their date with no warning, and the next retention run
    //    then clears names for good. The rule is changed at Admin, Giving
    //    records, which checks for exactly that. Looked up for everybody, so
    //    it sits before the global-administrator branch below.
    if ($deleteId > 0) {
        $lockedKey = null;
        $lookup    = $mysqli->prepare('SELECT settingKey FROM tblSettings WHERE settingID = ? AND (siteID = ? OR siteID IS NULL) LIMIT 1');
        if ($lookup !== false) {
            $lookup->bind_param('ii', $deleteId, $deleteSiteId);
            $lookup->execute();
            $lockedKey = $lookup->get_result()->fetch_assoc();
            $lookup->close();
        }
        if ($lockedKey !== null && strncasecmp((string) $lockedKey['settingKey'], 'giving.retention.', 17) === 0) {
            Logger::activity(
                'SettingsDeleteRefused',
                'Refused: "' . (string) $lockedKey['settingKey'] . '" is part of the giving-records rule and cannot be deleted here',
                $_SESSION['user_id'] ?? null
            );
            $_SESSION['flash_msg']  = GivingRetention::LOCKED_MESSAGE;
            $_SESSION['flash_type'] = 'danger';
            header('Location: /settings');
            exit();
        }

        // 🔒 Nor can the two settings that decide how organisations are told
        //    apart be deleted here (#502), by anybody, a global administrator
        //    included. See Site::lockedInGeneralEditor().
        //
        //    WHAT WAS WRONG. The save handler refused to CHANGE them, but
        //    this delete did not ask. A global administrator could delete
        //    the portal-wide "multisite.enabled" row, and multi-organisation
        //    went off at once: every member of every organisation was in the
        //    default organisation, and the members-only gate was off (ran on
        //    12 October 2026). Deleting "multisite.detectionMode" dropped an
        //    installation using own addresses into sign-in session mode. So
        //    Site::LOCKED_MESSAGE, "It can only be changed at…", was untrue.
        //
        //    It uses the row looked up just above, so a row belonging to
        //    another organisation still reads as "not found" further down.
        if ($lockedKey !== null && Site::lockedInGeneralEditor((string) $lockedKey['settingKey']) === true) {
            Logger::activity(
                'SettingsDeleteRefused',
                'Refused: "' . (string) $lockedKey['settingKey'] . '" decides how organisations are told apart and cannot be deleted here',
                $_SESSION['user_id'] ?? null
            );
            $_SESSION['flash_msg']  = Site::LOCKED_MESSAGE;
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . Site::url('settings'));
            exit();
        }
    }

    if ($deleteId > 0 && $isRoot === false) {
        $target = null;
        $lookup = $mysqli->prepare(
            'SELECT settingKey, siteID FROM tblSettings '
            . 'WHERE settingID = ? AND (siteID = ? OR siteID IS NULL) LIMIT 1'
        );
        if ($lookup !== false) {
            $lookup->bind_param('ii', $deleteId, $deleteSiteId);
            $lookup->execute();
            $target = $lookup->get_result()->fetch_assoc();
            $lookup->close();
        }

        if ($target !== null) {
            $targetKey = (string) $target['settingKey'];

            if ($target['siteID'] === null) {
                $_SESSION['flash_msg']  = 'That setting is portal-wide: it is '
                    . 'the value every organisation on this installation uses '
                    . 'unless it has set its own. Only a global administrator '
                    . 'can delete it.';
                $_SESSION['flash_type'] = 'danger';
                header('Location: /settings');
                exit();
            }

            // 🔡 "public" on its own, or a name beginning "public.", in ANY
            //    letter case. The database compares setting names without
            //    regard to capital and small letters, so to it
            //    "PUBLIC.example" is the same name as "public.example". The
            //    first version of this test was case-sensitive and missed it.
            //    The second missed the bare name "public", which replaces
            //    every "public." setting when the portal loads its settings
            //    (the full note is in web/_apps/settings/save.php).
            if (strcasecmp($targetKey, 'public') === 0 || strncasecmp($targetKey, 'public.', 7) === 0) {
                $_SESSION['flash_msg']  = 'The setting called "public", and every '
                    . 'setting whose name begins "public.", control the public '
                    . 'website, which anybody on the internet can read without '
                    . 'signing in. Only a global administrator can delete those.';
                $_SESSION['flash_type'] = 'danger';
                header('Location: /settings');
                exit();
            }

            // 🔤 A stored name using anything other than A to Z, digits, full
            //    stops, hyphens and underscores is reserved too. The database
            //    can treat such a name as EQUAL to a protected one: an accent
            //    typed as a separate mark, full-width letters, or an invisible
            //    character all compare equal to "public.example" on MySQL
            //    8.0.36, while failing a character-by-character test. The same
            //    rule, and the reasons for it, are in web/_apps/settings/save.php.
            if (preg_match('/^[A-Za-z0-9._-]+\z/', $targetKey) !== 1) {
                $_SESSION['flash_msg']  = 'This setting\'s name contains characters '
                    . 'other than the letters A to Z, numbers, full stops (.), '
                    . 'hyphens (-) and underscores (_). The database can treat a '
                    . 'name like that as the same name as a protected setting, so '
                    . 'only a global administrator can delete it.';
                $_SESSION['flash_type'] = 'danger';
                header('Location: /settings');
                exit();
            }
        }
    }

    if ($isRoot === true) {
        if ($deleteId > 0) {
            // 🔒 THE RULES ARE WRITTEN INTO THE STATEMENT, NOT ONLY CHECKED ABOVE
            //
            //    WHAT WAS WRONG: the refusals above were decided in PHP from a
            //    row read a moment earlier, and the DELETE then ran with only
            //    the organisation scope in it. If the row changed in between,
            //    the delete went ahead on the strength of a check made against
            //    what the row used to be. Nothing was checked afterwards
            //    either: "Setting deleted." was shown even when nothing was.
            //
            //    NOW the statement carries the same rules as the UPDATE in
            //    web/_apps/settings/save.php, using $globalFlag (1 for a global
            //    administrator, 0 for anybody else — worked out from $isRoot,
            //    not written in as 1):
            //      - this organisation's own row is allowed; a portal-wide row
            //        only when $globalFlag is 1;
            //      - when $globalFlag is 0, the name must be made only of A to
            //        Z, digits, full stops, hyphens and underscores, must not
            //        be empty, and must not be "public" or begin "public.", in
            //        any letter case. This is the same text as $nameRuleSql in
            //        web/_apps/settings/save.php, which explains each part and
            //        what was checked on MySQL 8.0.36; if one changes, change
            //        both. WHAT WAS WRONG: this used to test only
            //        LOWER(settingKey) NOT LIKE 'public.%', which misses the
            //        bare name "public" and names the database treats as equal
            //        to a "public." name while LIKE does not (an accent typed as
            //        a separate mark, an invisible character).
            //    Today only a global administrator reaches this line, so both
            //    extra conditions pass. They are in the statement anyway, so
            //    that if the gate around this block is ever widened, the
            //    database still refuses what the refusals above refuse instead
            //    of quietly deleting it.
            //
            //    Then the number of rows actually removed is checked, and zero
            //    is reported as "nothing was deleted", never as success.
            $globalFlag  = (int) $isRoot;
            $rowsDeleted = 0;
            $stmt = $mysqli->prepare(
                'DELETE FROM tblSettings '
                . 'WHERE settingID = ? '
                . 'AND (siteID = ? OR (siteID IS NULL AND ? = 1)) '
                . "AND (? = 1 OR (CHAR_LENGTH(settingKey) = LENGTH(settingKey) "
                . "AND settingKey COLLATE utf8mb4_bin NOT REGEXP '[^A-Za-z0-9._-]' "
                . "AND settingKey <> '' "
                . "AND LOWER(settingKey) <> 'public' "
                . "AND LOWER(settingKey) NOT LIKE 'public.%'))"
            );
            if ($stmt !== false) {
                $stmt->bind_param('iiii', $deleteId, $deleteSiteId, $globalFlag, $globalFlag);
                $stmt->execute();
                $rowsDeleted = $stmt->affected_rows;
                $stmt->close();
            }
            if ($rowsDeleted > 0) {
                Logger::activity('SettingsDelete', 'Deleted setting ID ' . $deleteId, $_SESSION['user_id'] ?? null);
                $_SESSION['flash_msg']  = 'Setting deleted.';
                $_SESSION['flash_type'] = 'success';
            } else {
                $_SESSION['flash_msg']  = 'Nothing was deleted. That setting could not '
                    . 'be found (somebody may have removed it a moment ago), or it does '
                    . 'not belong to the organisation you are working in.';
                $_SESSION['flash_type'] = 'warning';
            }
        }
    } else {
        $_SESSION['flash_msg']  = 'Only a global administrator can delete a '
            . 'setting. An administrator of one organisation can change that '
            . 'organisation\'s own settings, but not remove them.';
        $_SESSION['flash_type'] = 'danger';
    }
    header('Location: /settings');
    exit();
}

// -----------------------------------------------------------------------------
// 📋 Fetch settings list
// -----------------------------------------------------------------------------
// 🌐 Multi-site: show settings for this site (siteID = current) plus global defaults (siteID IS NULL)
$siteId = Site::id();
$rows = [];
$stmt = $mysqli->prepare(
    'SELECT settingID, settingKey, settingValue, isSensitive, siteID, updatedAt '
    . 'FROM tblSettings '
    . 'WHERE siteID = ? OR siteID IS NULL '
    . 'ORDER BY settingKey, siteID DESC'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
}

// 📊 Group settings by first segment of dot-notation key
//
// 🔐 Each row's state is worked out here, once, and a secret's stored text is
//    dropped from the row at the same moment. Nothing further down this file
//    can print what it was never given.
//      secretState    one of SecretSettings' four states
//      stateHidden    true for an installation-wide secret when the person is
//                     an administrator of one organisation: they are told
//                     nothing about it, not even whether it is set
//      shownValue     the ONLY value the page may print: '' for a secret
$groups        = [];
$unprotected   = [];
foreach ($rows as $row) {
    $row['secretState'] = SecretSettings::state($row['isSensitive'], $row['settingValue']);
    $row['isSecret']    = $row['secretState'] !== SecretSettings::STATE_ORDINARY;
    $row['stateHidden'] = $row['isSecret'] === true && $row['siteID'] === null && $isRoot === false;
    $row['shownValue']  = $row['isSecret'] === true ? '' : (string) $row['settingValue'];
    if ($row['isSecret'] === true) {
        unset($row['settingValue']);
    }
    if ($row['secretState'] === SecretSettings::STATE_UNPROTECTED && $row['stateHidden'] === false) {
        $unprotected[] = (string) $row['settingKey'];
    }

    $parts = explode('.', $row['settingKey'], 2);
    $group = $parts[0] ?? 'other';
    if (isset($groups[$group]) === false) {
        $groups[$group] = [];
    }
    $groups[$group][] = $row;
}
ksort($groups);
// The rows as read still hold every secret's stored text. Only the count is
// needed from here on, so the list itself is dropped.
$settingsCount = count($rows);
unset($rows, $row);

// 📋 Flash message
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// 🔍 Check for success query param from old save handler
if (isset($_GET['success']) === true && $flashMsg === '') {
    $flashMsg  = 'Setting saved successfully.';
    $flashType = 'success';
}

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- ⚙️ Settings Management -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-gear me-2"></i>Settings</h1>
    <div class="d-flex gap-2">
        <span class="badge bg-secondary align-self-center"><?php echo $settingsCount; ?> settings</span>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="fa-solid fa-plus me-1"></i> Add Setting
        </button>
    </div>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (count($unprotected) > 0): ?>
    <!-- ⚠️ Secrets that are stored, but that this installation's key does not
         open. Names only, never a value. The usual cause is the old edit box
         on this page (#597), which stored an edited secret as readable text.
         The other is a key file replaced since the secret was saved. The page
         cannot tell which, so every sentence here is true of both. -->
    <div class="alert alert-warning" id="unprotected-secrets">
        <p class="mb-2">
            <i class="fa-solid fa-triangle-exclamation me-1"></i>
            <strong><?php echo count($unprotected); ?> secret
            <?php echo count($unprotected) === 1 ? 'setting needs' : 'settings need'; ?> entering again.</strong>
            <?php echo count($unprotected) === 1 ? 'It is' : 'They are'; ?> not encrypted with this
            installation's key, so <?php echo count($unprotected) === 1 ? 'it is' : 'each is'; ?> used exactly as stored.
        </p>
        <p class="mb-2">
            <?php foreach ($unprotected as $unprotectedKey): ?>
                <code class="me-2 text-break"><?php echo htmlspecialchars($unprotectedKey, ENT_QUOTES, 'UTF-8'); ?></code>
            <?php endforeach; ?>
        </p>
        <p class="mb-2 small">
            <strong>The usual cause:</strong> an older version of this page stored a
            secret as readable text whenever it was edited. Such a value still
            works, but it is stored unprotected: anybody who can read the database
            or a backup of it can read it.
            <strong>The other cause:</strong> the portal's key file has been replaced
            since the secret was saved. Then what is stored cannot be read, and
            whatever uses it will not work.
        </p>
        <?php
        // 🤖 If the bot check's own keys are among them AND cannot be read,
        //    sign-in is running without it (#497, the owner's decision of 13
        //    September 2026). An administrator has to be told that wherever
        //    they are likely to look; the words are Captcha's, so every page
        //    that says it says the same.
        $botCheckBroken = Captcha::brokenNotice();
        ?>
        <?php if ($botCheckBroken !== ''): ?>
            <p class="mb-2 small" id="settings-bot-check-unreadable">
                <strong>This affects sign-in.</strong> <?php echo htmlspecialchars($botCheckBroken, ENT_QUOTES, 'UTF-8'); ?>
            </p>
        <?php endif; ?>
        <p class="mb-0 small">
            <strong>What to do, either way:</strong> find each one below, press its edit
            button, and enter the value again. It is then stored encrypted. If you
            no longer have a value, make a new one where it came from (the
            provider's own website, or a new token for a scheduled job) and enter that.
        </p>
    </div>
<?php endif; ?>

<!-- 🔍 Search filter -->
<div class="mb-4">
    <input type="text" class="form-control" id="settingsSearch" placeholder="Search settings by key or value..." aria-label="Search settings">
    <div class="form-text">A secret's value is never on this page, so it cannot be searched for. Search for its name.</div>
</div>

<!-- ⚠️ No-JS: expand all accordion panels and show search note -->
<noscript>
    <style>.accordion-collapse { display: block !important; }</style>
    <p class="text-muted small mb-2"><i class="fa-solid fa-circle-info me-1"></i>JavaScript is disabled — all settings groups are expanded. Search filtering requires JavaScript.</p>
</noscript>

<!-- 📋 Grouped settings accordion -->
<div class="accordion" id="settingsAccordion">
    <?php $groupIndex = 0; ?>
    <?php foreach ($groups as $groupName => $groupRows): ?>
        <?php $groupIndex++; ?>
        <div class="accordion-item portal-settings-group" data-group="<?php echo htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8'); ?>">
            <h2 class="accordion-header">
                <button class="accordion-button <?php echo ($groupIndex > 1) ? 'collapsed' : ''; ?>" type="button"
                        data-bs-toggle="collapse" data-bs-target="#group-<?php echo htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8'); ?>"
                        aria-expanded="<?php echo ($groupIndex === 1) ? 'true' : 'false'; ?>">
                    <strong class="text-uppercase"><?php echo htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8'); ?></strong>
                    <span class="badge bg-secondary ms-2"><?php echo count($groupRows); ?></span>
                </button>
            </h2>
            <div id="group-<?php echo htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8'); ?>"
                 class="accordion-collapse collapse <?php echo ($groupIndex === 1) ? 'show' : ''; ?>"
                 data-bs-parent="#settingsAccordion">
                <div class="accordion-body p-0">
                    <div class="portal-data-list">
                        <!-- 🏷️ Header row -->
                        <div class="portal-data-row portal-data-header d-none d-md-flex">
                            <div class="col-md-4">Key</div>
                            <div class="col-md-4">Value</div>
                            <div class="col-md-2">Updated</div>
                            <div class="col-md-2 text-end">Actions</div>
                        </div>

                        <?php foreach ($groupRows as $row): ?>
                            <?php $isSensitive = $row['isSecret']; ?>
                            <div class="portal-data-row portal-setting-row"
                                 data-setting-key="<?php echo htmlspecialchars($row['settingKey'], ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="col-12 col-md-4">
                                    <span class="d-md-none fw-semibold">Key: </span>
                                    <code class="small"><?php echo htmlspecialchars($row['settingKey'], ENT_QUOTES, 'UTF-8'); ?></code>
                                    <?php if ($isSensitive === true): ?>
                                        <span class="badge bg-warning text-dark ms-1" title="A secret: never shown on this page">
                                            <i class="fa-solid fa-lock" aria-hidden="true"></i>
                                            <span class="visually-hidden">A secret: never shown on this page</span>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="col-12 col-md-4">
                                    <span class="d-md-none fw-semibold">Value: </span>
                                    <?php if ($row['stateHidden'] === true): ?>
                                        <span class="small text-secondary">Not shown. A global administrator looks after this one.</span>
                                    <?php elseif ($row['secretState'] === SecretSettings::STATE_NOT_SET): ?>
                                        <span class="badge bg-secondary">Not set</span>
                                    <?php elseif ($row['secretState'] === SecretSettings::STATE_PROTECTED): ?>
                                        <span class="badge bg-success">Set</span>
                                        <span class="small text-secondary">Stored encrypted. Never shown.</span>
                                    <?php elseif ($row['secretState'] === SecretSettings::STATE_UNPROTECTED): ?>
                                        <span class="badge bg-warning text-dark">Set, but needs entering again</span>
                                        <span class="small d-block">Not encrypted with this installation's key, so it is used as stored. See the notice at the top.</span>
                                    <?php else: ?>
                                        <span class="small"><?php echo htmlspecialchars(str_replace("\n", ' ', $row['shownValue']), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="col-12 col-md-2 small text-secondary">
                                    <span class="d-md-none fw-semibold">Updated: </span>
                                    <?php echo htmlspecialchars($row['updatedAt'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <div class="col-12 col-md-2 text-md-end mt-2 mt-md-0">
                                    <?php
                                    // 🔒 The giving-records rule is changed on its own page, not
                                    //    here: see GivingRetention::lockedInGeneralEditor(). No
                                    //    Edit or Delete button is shown for those rows, so nobody
                                    //    fills a form in only to be refused; the refusal itself is
                                    //    in the save and delete handlers, not in this hiding.
                                    $isGivingRule = strncasecmp((string) $row['settingKey'], 'giving.retention.', 17) === 0;
                                    $givingLocked = GivingRetention::lockedInGeneralEditor((string) $row['settingKey'], $row['siteID'] === null, $isRoot);
                                    // 🔒 The same for the two settings that decide how organisations
                                    //    are told apart (#502): no Edit and no Delete button, because
                                    //    both handlers refuse them. Only a global administrator can
                                    //    open the page that changes them, so only they get the link.
                                    $organisationsLocked = Site::lockedInGeneralEditor((string) $row['settingKey']);
                                    ?>
                                    <?php if ($organisationsLocked === true && $isRoot === true): ?>
                                        <a class="small" href="<?php echo htmlspecialchars(Site::url('admin/sites/setup'), ENT_QUOTES, 'UTF-8'); ?>">Changed at Admin, Organisations</a>
                                    <?php elseif ($organisationsLocked === true): ?>
                                        <span class="small text-secondary">Changed by a global administrator, at Admin, Organisations</span>
                                    <?php elseif ($givingLocked === true): ?>
                                        <a class="small" href="<?php echo htmlspecialchars(Site::url('admin/settings/giving-records'), ENT_QUOTES, 'UTF-8'); ?>">Changed at Admin, Giving records</a>
                                    <?php else: ?>
                                    <button class="btn btn-sm btn-outline-primary me-1" data-bs-toggle="modal" data-bs-target="#editModal"
                                            data-id="<?php echo (int) $row['settingID']; ?>"
                                            data-key="<?php echo htmlspecialchars($row['settingKey'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-value="<?php echo htmlspecialchars($row['shownValue'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-sensitive="<?php echo $isSensitive === true ? '1' : '0'; ?>"
                                            aria-label="Edit <?php echo htmlspecialchars($row['settingKey'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($isRoot === true && $isGivingRule === false && $organisationsLocked === false): ?>
                                        <form method="post" action="/settings" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="settingID" value="<?php echo (int) $row['settingID']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                                    data-confirm="Delete setting: <?php echo htmlspecialchars($row['settingKey'], ENT_QUOTES, 'UTF-8'); ?>?" data-confirm-destructive="true"
                                                    title="Delete">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- 📝 Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="post" action="/settings/save">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="settingID" id="edit-settingID">
                <div class="modal-header">
                    <h5 class="modal-title" id="editLabel"><i class="fa-solid fa-pen me-1"></i> Edit Setting</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Key</label>
                        <input type="text" class="form-control" name="settingKey" id="edit-settingKey" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Value</label>
                        <textarea class="form-control" name="settingValue" id="edit-settingValue" rows="4"></textarea>
                        <div class="form-text portal-sensitive-only d-none">
                            <i class="fa-solid fa-lock me-1"></i> This is a secret. It is stored encrypted and is never shown,
                            so this box starts empty. Type a new value to replace the stored one, or leave the box empty to keep it.
                        </div>
                    </div>
                    <!-- 🧹 Only shown for a secret (see the script below). An
                         ordinary setting is emptied by saving an empty box; for
                         a secret an empty box means "keep it", so removing one
                         needs its own tick. -->
                    <div class="form-check portal-sensitive-only d-none">
                        <input class="form-check-input" type="checkbox" value="1" name="clearValue" id="edit-clearValue">
                        <label class="form-check-label" for="edit-clearValue">
                            Remove the stored value
                        </label>
                        <div class="form-text">Whatever uses this setting stops working until a new value is entered. Anything typed above is ignored.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ➕ Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-labelledby="addLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="post" action="/settings/save">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="addLabel"><i class="fa-solid fa-plus me-1"></i> Add New Setting</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Key <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="settingKey" required placeholder="e.g. site.name or app.feature.enabled">
                        <div class="form-text">Use dot-notation for grouping (e.g. <code>site.name</code>)</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Value</label>
                        <textarea class="form-control" name="settingValue" rows="4"></textarea>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1" name="isSensitive" id="add-sensitive">
                        <label class="form-check-label" for="add-sensitive">
                            <i class="fa-solid fa-lock me-1"></i> Sensitive (encrypt in database)
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fa-solid fa-plus me-1"></i>Add Setting</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 📦 Settings page scripts -->
<script>
// 📝 Edit modal population
var editModal = document.getElementById('editModal');
editModal.addEventListener('show.bs.modal', function (event) {
    var button = event.relatedTarget;
    document.getElementById('edit-settingID').value    = button.getAttribute('data-id');
    document.getElementById('edit-settingKey').value   = button.getAttribute('data-key');
    document.getElementById('edit-settingValue').value = button.getAttribute('data-value');

    // 🔐 The two parts that only apply to a secret: the hint, and the
    //    "Remove the stored value" tick. The tick is cleared every time the
    //    box opens, and switched off for an ordinary setting, so that one
    //    left ticked for the last row can never be sent with this one.
    var isSecret   = button.getAttribute('data-sensitive') === '1';
    var clearValue = document.getElementById('edit-clearValue');
    clearValue.checked  = false;
    clearValue.disabled = !isSecret;
    editModal.querySelectorAll('.portal-sensitive-only').forEach(function (part) {
        part.classList.toggle('d-none', !isSecret);
    });
});

// 🔍 Search/filter settings
var searchInput = document.getElementById('settingsSearch');
searchInput.addEventListener('input', function () {
    var query = this.value.toLowerCase();
    var rows  = document.querySelectorAll('.portal-setting-row');
    var groups = document.querySelectorAll('.portal-settings-group');

    rows.forEach(function (row) {
        var key = (row.getAttribute('data-setting-key') || '').toLowerCase();
        var text = row.textContent.toLowerCase();
        if (query === '' || key.indexOf(query) !== -1 || text.indexOf(query) !== -1) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });

    // Show/hide groups based on whether they have visible rows
    groups.forEach(function (group) {
        var visibleRows = group.querySelectorAll('.portal-setting-row:not([style*="display: none"])');
        if (visibleRows.length === 0 && query !== '') {
            group.style.display = 'none';
        } else {
            group.style.display = '';
            // Auto-expand groups when searching
            if (query !== '') {
                var collapse = group.querySelector('.accordion-collapse');
                if (collapse !== null) {
                    collapse.classList.add('show');
                }
            }
        }
    });
});
</script>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
