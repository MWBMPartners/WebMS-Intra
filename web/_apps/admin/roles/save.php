<?php
// Path: _apps/admin/roles/save.php
/**
 * -----------------------------------------------------------------------------
 * Create, rename or delete a role 🏷️ (#516)
 * -----------------------------------------------------------------------------
 * The write half of `admin/roles/index.php` — see that file's header for
 * what this page manages and what it deliberately does not.
 *
 * WHO MAY DO THIS: any administrator of the organisation currently open
 * (owner decision, #516 plan Q7) — `App::isAdmin()`, the same gate the
 * page itself uses. This is NOT an account-reach decision (nobody's
 * account is being changed), so `AccountGuard` is not involved here —
 * unlike `admin/users/roles-save.php`, which changes what a specific
 * ACCOUNT holds and does need it.
 *
 * THREE MORE ACTIONS SINCE #727: `capability` (which of this organisation's
 * roles open one gated capability), `capability_reset` (forget that choice and
 * go back to the role the portal ships with) and `capability_locks` (which
 * capabilities the INSTALLATION reserves to itself — a global administrator
 * only, because the two levels may be different parties). All three hand the
 * work to `Portal\Core\Capabilities`, which is the ONE place a capability's
 * roles are decided; there is no second test of what may be changed in this
 * file, because `save()` and `forget()` ask `whyNotChangeable()` themselves and
 * the page is never the boundary.
 *
 * AND THE DELETE BRANCH NOW REFUSES ONE MORE THING: a role that is the LAST one
 * opening a capability. Deleting it would leave that capability opened by
 * nobody — which is allowed, but only when somebody has confirmed it in as many
 * words on the page, never as a side effect of a tidy-up.
 *
 * AND D-093 (#493 P3): only a global administrator of this organisation, or of
 * the installation, may change which roles open "Set up the public website" —
 * on `capability` and `capability_reset` for that one capability, and on
 * `delete` of a role that currently opens it (deleting it takes it out of the
 * set). Asked of `PublicWebsiteSetup`, the ONE place the rule is; refused with
 * the reason and recorded. Every other action, and every other capability, is
 * untouched: an ordinary administrator keeps the rest of this page.
 *
 * WHY A ROLE'S KEY IS NEVER READ FROM AN UPDATE POST: keys are fixed for
 * life once a role exists, standard or not, because workflow steps,
 * newsletter segments, the pre-release channel gate and settings all name
 * a role by its key. Letting an update silently change the key would
 * break every one of those without any warning.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/516
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiAuth;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Capabilities;
use Portal\Core\InstallationSettings;
use Portal\Core\Logger;
use Portal\Core\PublicWebsiteSetup;
use Portal\Core\Roles;
use Portal\Core\Router;
use Portal\Core\Site;

if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . Site::url('admin/roles'), true, 302);
    exit();
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/roles'), true, 302);
    exit();
}

$siteId  = Site::id();
$actorId = ApiAuth::actorUserId();
$action  = (string) ($_POST['action'] ?? '');

/**
 * D-093's refusal, said and recorded the same way from every branch below.
 */
$refuseHandOut = static function (string $where) use ($actorId, $siteId): never {
    PublicWebsiteSetup::recordHandOutRefused($actorId, $siteId, $where);
    $_SESSION['flash_msg']  = PublicWebsiteSetup::HAND_OUT_REFUSED;
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/roles'), true, 302);
    exit();
};

if ($action === 'create') {
    $roleKey    = Roles::normaliseKey((string) ($_POST['roleKey'] ?? ''));
    $roleName   = trim((string) ($_POST['roleName'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    if (Roles::isValidNewKey($roleKey) === false) {
        $_SESSION['flash_msg']  = 'A key is 2-50 characters: lower-case letters, digits and underscores, starting with a letter.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/roles'), true, 302);
        exit();
    }
    if ($roleName === '') {
        $_SESSION['flash_msg']  = 'A label is required.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/roles'), true, 302);
        exit();
    }

    $stmt = $mysqli->prepare(
        'INSERT INTO tblRoles (siteID, roleKey, roleName, description, isStandard) VALUES (?, ?, ?, ?, 0)'
    );
    if ($stmt === false) {
        $_SESSION['flash_msg']  = t('error.database');
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/roles'), true, 302);
        exit();
    }
    $descriptionVal = $description !== '' ? $description : null;
    try {
        $stmt->bind_param('isss', $siteId, $roleKey, $roleName, $descriptionVal);
        $stmt->execute();
        $newId = (int) $stmt->insert_id;
        $stmt->close();
    } catch (\mysqli_sql_exception $e) {
        $stmt->close();
        if ($e->getCode() === 1062) {
            $_SESSION['flash_msg']  = 'A role with that key already exists here.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . Site::url('admin/roles'), true, 302);
            exit();
        }
        throw $e;
    }

    Logger::audit('tblRoles', $newId, 'create', null, ['siteID' => $siteId, 'roleKey' => $roleKey, 'roleName' => $roleName], $actorId);
    Logger::activity('RoleCreate', 'Created role "' . $roleName . '" (' . $roleKey . ')', $actorId);
    $_SESSION['flash_msg']  = 'Role added.';
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . Site::url('admin/roles'), true, 302);
    exit();
}

if ($action === 'update') {
    $roleId      = (int) ($_POST['roleID'] ?? 0);
    $roleName    = trim((string) ($_POST['roleName'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    // 🎯 Scoped to (roleID, siteID) — a roleID belonging to another
    //    organisation costs one lookup that finds nothing, exactly like a
    //    genuinely wrong number, never a distinguishable "not yours" answer.
    $lookup = $mysqli->prepare('SELECT isStandard FROM tblRoles WHERE roleID = ? AND siteID = ? LIMIT 1');
    $found  = null;
    if ($lookup !== false) {
        $lookup->bind_param('ii', $roleId, $siteId);
        $lookup->execute();
        $found = $lookup->get_result()->fetch_assoc();
        $lookup->close();
    }
    if ($found === null) {
        $_SESSION['flash_msg']  = 'That role could not be found.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/roles'), true, 302);
        exit();
    }
    if ($roleName === '') {
        $_SESSION['flash_msg']  = 'A label is required.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/roles'), true, 302);
        exit();
    }

    // ⚠️ roleKey is NEVER read from this POST — see the file header for why.
    $descriptionVal = $description !== '' ? $description : null;
    $stmt = $mysqli->prepare('UPDATE tblRoles SET roleName = ?, description = ? WHERE roleID = ? AND siteID = ?');
    if ($stmt === false) {
        $_SESSION['flash_msg']  = t('error.database');
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/roles'), true, 302);
        exit();
    }
    $stmt->bind_param('ssii', $roleName, $descriptionVal, $roleId, $siteId);
    $stmt->execute();
    $stmt->close();

    Logger::audit('tblRoles', $roleId, 'update', null, ['roleName' => $roleName, 'description' => $description], $actorId);
    Logger::activity('RoleUpdate', 'Updated role #' . $roleId . ' (' . $roleName . ')', $actorId);
    $_SESSION['flash_msg']  = 'Role updated.';
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . Site::url('admin/roles'), true, 302);
    exit();
}

if ($action === 'delete') {
    $roleId = (int) ($_POST['roleID'] ?? 0);

    $lookup = $mysqli->prepare('SELECT roleName, isStandard FROM tblRoles WHERE roleID = ? AND siteID = ? LIMIT 1');
    $found  = null;
    if ($lookup !== false) {
        $lookup->bind_param('ii', $roleId, $siteId);
        $lookup->execute();
        $found = $lookup->get_result()->fetch_assoc();
        $lookup->close();
    }
    if ($found === null) {
        $_SESSION['flash_msg']  = 'That role could not be found.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/roles'), true, 302);
        exit();
    }
    // #497: this flag arrives as the whole number 1, not the text '1'.
    $isStandard = $found['isStandard'] === 1 || $found['isStandard'] === '1';
    if ($isStandard === true) {
        $_SESSION['flash_msg']  = 'Standard roles cannot be deleted. Rename it instead.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/roles'), true, 302);
        exit();
    }

    $holders = Roles::countHolders($mysqli, $roleId, $siteId);
    if ($holders > 0) {
        $_SESSION['flash_msg']  = $holders . ' ' . ($holders === 1 ? 'person holds' : 'people hold')
            . ' this role here. Remove it from them first.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/roles'), true, 302);
        exit();
    }

    // 🚪 #727. `fk_capmaprole_role` removes a deleted role from every
    //    capability it opened, which is right — a set naming a role that no
    //    longer exists would be a door nobody can see is shut. But if it was
    //    the LAST role in a set, this organisation would end up with an EMPTY
    //    set it never confirmed, and confirming an empty set in as many words
    //    is the one thing the page insists on. So the delete is refused and
    //    says which door to point elsewhere first.
    //
    //    Asked BEFORE the DELETE and never after: afterwards the rows are
    //    already gone and the question cannot be answered.
    //    ⚠️ AND IT REFUSES WHEN IT CANNOT ASK. The first version read the key
    //       into `$roleKeyRow`, left it null when the `prepare()` or the row
    //       lookup failed, and then wrapped the whole question in
    //       `if ($roleKeyRow !== null)` — with the DELETE immediately after it,
    //       outside the `if`. So a transient database failure SKIPPED the guard
    //       and deleted the role anyway, emptying a capability's set with
    //       nobody confirming it: exactly the outcome the guard exists to
    //       prevent, reached by the guard not running.
    //
    //       "We could not tell" must never become "nobody has, go ahead" —
    //       #647's rule, in a new place. A delete is undoable only by
    //       re-creating the role AND re-pointing the door, and the role's key
    //       is what the capability remembers, so refusing is cheap and the
    //       alternative is not.
    $roleKeyStmt = $mysqli->prepare('SELECT `roleKey` FROM `tblRoles` WHERE `roleID` = ? AND `siteID` = ? LIMIT 1');
    $roleKeyRow  = null;
    if ($roleKeyStmt !== false) {
        $roleKeyStmt->bind_param('ii', $roleId, $siteId);
        $roleKeyStmt->execute();
        $roleKeyRow = $roleKeyStmt->get_result()->fetch_assoc();
        $roleKeyStmt->close();
    }
    if ($roleKeyRow === null) {
        Logger::activity(
            'RoleDeleteRefused',
            'Refused: could not read role #' . $roleId . '\'s key, so whether deleting it would empty a '
            . 'capability could not be answered. Nothing was deleted.',
            $actorId
        );
        $_SESSION['flash_msg']  = 'We could not check whether deleting this role would leave a door under '
            . '"Who can open what" with no role opening it, so nothing was deleted. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/roles'), true, 302);
        exit();
    }
    // 🛠️ D-093: deleting a role that opens "Set up the public website" takes
    //    it out of that set, which only a global administrator may do.
    if (PublicWebsiteSetup::whyRoleChangeRefused($mysqli, (int) ($actorId ?? 0), $siteId, [(string) $roleKeyRow['roleKey']]) !== '') {
        $refuseHandOut('Roles page, deleting role #' . $roleId);
    }

    $wouldEmpty = Capabilities::capabilitiesLeftEmptyByRemoving(
        $mysqli,
        $siteId,
        (string) $roleKeyRow['roleKey']
    );
    if (count($wouldEmpty) > 0) {
        $labels = [];
        foreach ($wouldEmpty as $capKey) {
            $labels[] = Capabilities::definition($capKey)['label'];
        }
        $_SESSION['flash_msg']  = 'This is the only role that opens "' . implode('", "', $labels)
            . '" under "Who can open what". Point that at another role first, then delete this one.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/roles'), true, 302);
        exit();
    }

    $stmt = $mysqli->prepare('DELETE FROM tblRoles WHERE roleID = ? AND siteID = ? AND isStandard = 0');
    if ($stmt !== false) {
        $stmt->bind_param('ii', $roleId, $siteId);
        $stmt->execute();
        $stmt->close();
    }

    // 🗒️ The storage's own `ON DELETE CASCADE` has just taken this role out of
    //    every capability's set, and `Capabilities` remembers "which roles open
    //    this door" for the rest of the request. It is not told, because the
    //    delete happened here rather than through one of its own writers — so
    //    this is the one place outside the class that has to say so.
    Capabilities::forgetMemo();

    Logger::audit('tblRoles', $roleId, 'delete', ['roleName' => (string) $found['roleName']], null, $actorId);
    Logger::activity('RoleDelete', 'Deleted role #' . $roleId . ' (' . $found['roleName'] . ')', $actorId);
    $_SESSION['flash_msg']  = 'Role deleted.';
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . Site::url('admin/roles'), true, 302);
    exit();
}

// =============================================================================
// 🚪 Who can open what (#727)
// =============================================================================
// Three actions, and `Portal\Core\Capabilities` does every piece of thinking in
// all three: this file reads the form, says what happened, and nothing else.
// There is no second test of whether a capability may be changed here — the
// class's `whyNotChangeable()` is asked by `save()` and `forget()` themselves,
// so a posted request cannot get past a check the page merely drew.

if ($action === 'capability') {
    $capability = (string) ($_POST['capability'] ?? '');

    // 🛠️ D-093, asked BEFORE anything else so that even the "you ticked
    //    nothing" message is not shown to somebody who may not change it.
    if (PublicWebsiteSetup::whyMappingChangeRefused($mysqli, (int) ($actorId ?? 0), $siteId, $capability) !== '') {
        $refuseHandOut('Roles page, which roles open it');
    }

    // ⚠️ ASK WHETHER THE FIELD ARRIVED, never only what it holds (#677).
    //    Nothing ticked means the boxes were not submitted AT ALL, which is
    //    exactly how a browser posts an empty set — so an absent `roleKeys`
    //    IS the empty set and must go through the confirmation below rather
    //    than being read as "no change".
    $posted = $_POST['roleKeys'] ?? [];
    if (is_array($posted) === false) {
        // `?roleKeys=x` rather than `roleKeys[]=x`: a request chooses the TYPE
        // of what it sends as well as the value (the 27 October 2026 lesson),
        // and a string here would make `foreach` throw.
        $posted = [];
    }
    $roleKeys = [];
    foreach ($posted as $value) {
        if (is_string($value) === true) {
            $roleKeys[] = $value;
        }
    }

    $confirmedEmpty = ($_POST['confirm_empty'] ?? '') === '1';
    if (count($roleKeys) === 0 && $confirmedEmpty === false) {
        $_SESSION['flash_msg'] = Capabilities::isKnown($capability) === true
            ? 'Nothing was saved. You ticked no roles, which means nobody will be able to open this. '
                . Capabilities::emptyMeans($capability)
                . ' Tick the confirmation box as well if that is what you want.'
            : 'Nothing was saved: that is not something this portal can point at a role.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/roles'), true, 302);
        exit();
    }

    $outcome = Capabilities::save(
        $mysqli,
        $siteId,
        $capability,
        $roleKeys,
        $actorId,
        InstallationSettings::layerOrNull()
    );

    $words = [
        'ok'          => ['Saved. ', 'success'],
        'unchanged'   => ['Those were already the roles that open it, so nothing changed.', 'info'],
        'unknown'     => ['That is not something this portal can point at a role.', 'danger'],
        'not_allowed' => ['That one cannot be changed here.', 'danger'],
        'no_storage'  => ['This part of the database has not been upgraded yet, so nothing could be saved.', 'danger'],
        'bad_role'    => ['One of those roles does not belong to this organisation, so nothing was saved.', 'danger'],
        'failed'      => ['The database refused the change, so nothing was saved.', 'danger'],
    ];
    [$message, $type] = $words[$outcome] ?? ['Nothing was saved.', 'danger'];

    // On a real save, say what is in force now — including the honest answer
    // when it is nobody. A message reading only "Saved." after somebody has
    // just emptied a door would be the quietest possible way to lose access.
    if ($outcome === 'ok') {
        $nowOpen = Capabilities::roleKeysInForce($mysqli, $siteId, $capability);
        $message .= count($nowOpen) > 0
            ? 'It is now opened by: ' . implode(', ', $nowOpen) . '.'
            : 'Nobody can open it by role now. ' . Capabilities::emptyMeans($capability);
    }

    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . Site::url('admin/roles'), true, 302);
    exit();
}

if ($action === 'capability_reset') {
    $capability = (string) ($_POST['capability'] ?? '');
    if (PublicWebsiteSetup::whyMappingChangeRefused($mysqli, (int) ($actorId ?? 0), $siteId, $capability) !== '') {
        $refuseHandOut('Roles page, back to the role it came with');
    }
    $outcome    = Capabilities::forget(
        $mysqli,
        $siteId,
        $capability,
        $actorId,
        InstallationSettings::layerOrNull()
    );

    $words = [
        'ok'          => ['Back to the role this door came with.', 'success'],
        'unchanged'   => ['This organisation had chosen nothing for that one, so nothing changed.', 'info'],
        'unknown'     => ['That is not something this portal can point at a role.', 'danger'],
        'not_allowed' => ['That one cannot be changed here.', 'danger'],
        'no_storage'  => ['This part of the database has not been upgraded yet, so nothing could be changed.', 'danger'],
        'failed'      => ['The database refused the change, so nothing was changed.', 'danger'],
    ];
    [$message, $type] = $words[$outcome] ?? ['Nothing was changed.', 'danger'];
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . Site::url('admin/roles'), true, 302);
    exit();
}

if ($action === 'capability_locks') {
    // 🏢 A GLOBAL ADMINISTRATOR ONLY, and this is the one place in this file
    //    where `App::isAdmin()` at the top is not enough: what the
    //    INSTALLATION reserves is not an organisation's business, and the two
    //    levels may be different parties (the standing rule of 29 October
    //    2026). An organisation's administrator deciding what the installation
    //    reserves would be the whole point inverted.
    if (App::isRootAdmin() === false) {
        Router::renderError(403);
        return;
    }

    $posted = $_POST['lockedKeys'] ?? [];
    if (is_array($posted) === false) {
        $posted = [];
    }
    $keys = [];
    foreach ($posted as $value) {
        if (is_string($value) === true) {
            $keys[] = $value;
        }
    }

    $outcome = Capabilities::saveInstallationLocks($mysqli, $keys, $actorId);
    $words   = [
        'ok'        => ['Saved what this installation reserves.', 'success'],
        'unchanged' => ['That was already what this installation reserves, so nothing changed.', 'info'],
        'failed'    => ['The database refused the change, so nothing was saved.', 'danger'],
    ];
    [$message, $type] = $words[$outcome] ?? ['Nothing was saved.', 'danger'];
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . Site::url('admin/roles'), true, 302);
    exit();
}

$_SESSION['flash_msg']  = 'Unknown action.';
$_SESSION['flash_type'] = 'danger';
header('Location: ' . Site::url('admin/roles'), true, 302);
exit();
