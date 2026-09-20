<?php
// Path: public_html/admin/users/save.php
/**
 * -----------------------------------------------------------------------------
 * User Management Save Handler 👥
 * -----------------------------------------------------------------------------
 * Handles create and update actions for user management. Processes POST data
 * from the add/edit modals, updates tblUsers and tblLocalAccounts, and
 * redirects back to the user management page with a flash message.
 *
 * -----------------------------------------------------------------------------
 * #518 FIX (17 September 2026): before this, "update" trusted the posted
 * userID completely — an administrator of ORGANISATION A, viewing this
 * page while A was the open organisation, could set a new password, a
 * new email address, or the portal-wide isAdmin flag on ANY account in
 * ANY organisation, including a global administrator's own account. There
 * was no ownership check anywhere in this file. Every change here goes
 * through Portal\Core\AccountGuard, which is the one place that now
 * decides how far a change is allowed to reach; see that class's own
 * docblock for the full story and what it cannot do.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.4.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\AccountGuard;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

// 🛡️ Admin access check
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// 🛡️ Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/users');
    exit();
}

// 🛡️ CSRF verification
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/users');
    exit();
}

$action = $_POST['action'] ?? '';

// -----------------------------------------------------------------------------
// ➕ Create new user
// -----------------------------------------------------------------------------
if ($action === 'create') {
    // 🛡️ #518: only a global administrator may create an account that
    //    already carries the portal-wide isAdmin flag — App::isAdmin()
    //    treats anyone holding that flag as an administrator of EVERY
    //    organisation they later open, so handing it out from inside one
    //    organisation's own admin page would be a portal-wide grant.
    //    Checked before any other validation runs, so nothing is created
    //    (and no other error message can leak past this one) when this
    //    refuses.
    if (isset($_POST['isAdmin']) === true && AccountGuard::actorIsGlobal() === false) {
        AccountGuard::logRefusal(
            'create a new account with portal-wide administrator rights',
            AccountGuard::GLOBAL_ONLY,
            'portal_grant',
            null
        );
        $_SESSION['flash_msg']  = AccountGuard::message(AccountGuard::GLOBAL_ONLY, AccountGuard::REACH_PORTAL);
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/users');
        exit();
    }

    $fullName     = trim($_POST['fullName'] ?? '');
    $emailAddress = trim($_POST['emailAddress'] ?? '');
    $phoneNumber  = trim($_POST['phoneNumber'] ?? '');
    $username     = trim($_POST['username'] ?? '');
    $password     = $_POST['password'] ?? '';
    $passConfirm  = $_POST['password_confirm'] ?? '';
    $isActive     = isset($_POST['isActive']) === true ? 1 : 0;
    $isAdmin      = isset($_POST['isAdmin']) === true ? 1 : 0;
    $isRootAdmin  = (App::isRootAdmin() === true && isset($_POST['isRootAdmin']) === true) ? 1 : 0;

    // 🔍 Validation
    if ($fullName === '' || $emailAddress === '') {
        $_SESSION['flash_msg']  = 'Full name and email address are required.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/users');
        exit();
    }

    // 🔍 #521 (20 September 2026): addresses must stay unique across the
    //    WHOLE installation (one local-login row per address), so the old
    //    flat "a user with that email address already exists" message
    //    cannot simply be removed — but it used to tell a non-global
    //    administrator whether an address belongs to an account in ANOTHER
    //    organisation, which is not theirs to know. AccountGuard::
    //    emailAvailability() bounds that: an address in reach reads back
    //    plainly, one outside reach is refused with vaguer wording, rate
    //    limited (5/hour per administrator) and recorded for a global
    //    administrator — see that method's own docblock for the full design
    //    and what it deliberately still allows.
    $emailVerdict = AccountGuard::emailAvailability($emailAddress, null, 'create account');
    if ($emailVerdict !== AccountGuard::EMAIL_FREE) {
        $_SESSION['flash_msg']  = AccountGuard::emailMessage($emailVerdict);
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/users');
        exit();
    }

    // 🔍 If username provided, password is required
    if ($username !== '' && $password === '') {
        $_SESSION['flash_msg']  = 'Password is required when creating a local account.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/users');
        exit();
    }

    if ($password !== '' && $password !== $passConfirm) {
        $_SESSION['flash_msg']  = 'Passwords do not match.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/users');
        exit();
    }

    // 🛡️ Enforce password policy when a password was supplied
    if ($password !== '') {
        $check = Auth::validatePassword($password);
        if ($check['valid'] === false) {
            $_SESSION['flash_msg']  = 'Password does not meet policy: ' . implode(' ', $check['errors']);
            $_SESSION['flash_type'] = 'danger';
            header('Location: /admin/users');
            exit();
        }
    }

    // 🔄 Wrap multi-table insert in a transaction for atomicity
    App::beginTransaction();

    try {
        // 📝 Insert user
        $stmt = $mysqli->prepare(
            'INSERT INTO tblUsers (fullName, emailAddress, phoneNumber, isActive, isAdmin, isRootAdmin) '
            . 'VALUES (?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare user insert: ' . $mysqli->error);
        }
        $stmt->bind_param('sssiii', $fullName, $emailAddress, $phoneNumber, $isActive, $isAdmin, $isRootAdmin);
        $stmt->execute();
        $newUserId = $stmt->insert_id;
        $stmt->close();

        if ($newUserId <= 0) {
            throw new \RuntimeException('User insert returned invalid ID.');
        }

        // 🔑 Create local account if username provided
        if ($username !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $mysqli->prepare(
                'INSERT INTO tblLocalAccounts (userID, username, passwordHash) VALUES (?, ?, ?)'
            );
            if ($stmt === false) {
                throw new \RuntimeException('Failed to prepare local account insert: ' . $mysqli->error);
            }
            $stmt->bind_param('iss', $newUserId, $username, $hash);
            $stmt->execute();
            $stmt->close();
        }

        // 🛡️ #518: "Add User" used to create an account with NO
        //    membership row at all. Under the new rule that hides the
        //    very account the administrator who created it just made —
        //    an account with no tblUserSites row belongs to no
        //    organisation, so a non-global administrator would never see
        //    it again. This also closes the blocker #511 named: a brand
        //    new account is now, from the moment it exists, a member of
        //    the organisation it was created from.
        $stmt = $mysqli->prepare(
            'INSERT INTO tblUserSites (userID, siteID, isActive) VALUES (?, ?, 1)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare site membership insert: ' . $mysqli->error);
        }
        $newSiteId = Site::id();
        $stmt->bind_param('ii', $newUserId, $newSiteId);
        $stmt->execute();
        $stmt->close();

        App::commit();

        Logger::activity('UserCreated', 'Created user: ' . $fullName . ' (' . $emailAddress . ')', $_SESSION['user_id'] ?? null);

        $_SESSION['flash_msg']  = 'User "' . $fullName . '" created successfully.';
        $_SESSION['flash_type'] = 'success';
        header('Location: /admin/users');
        exit();

    } catch (\Throwable $ex) {
        App::rollback();
        Logger::exception($ex);
        $_SESSION['flash_msg']  = t('error.db_create_user');
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/users');
        exit();
    }
}

// -----------------------------------------------------------------------------
// ✏️ Update existing user
// -----------------------------------------------------------------------------
if ($action === 'update') {
    // 🛡️ #518: step 1 only reads the posted account number. NOTHING else
    //    may run before step 2's AccountGuard check — not the email-in-use
    //    lookup, not the password rules — because either of those could
    //    otherwise reveal something about an account this administrator
    //    has no business changing, before the guard has had a chance to
    //    say no.
    $userID = (int) ($_POST['userID'] ?? 0);
    if ($userID <= 0) {
        $_SESSION['flash_msg']  = AccountGuard::message(AccountGuard::NOT_FOUND, AccountGuard::REACH_VIEW);
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/users');
        exit();
    }

    // 🛡️ #518 step 2: is this administrator even allowed to SEE this
    //    account? A missing account and one that belongs to another
    //    organisation must look byte-for-byte identical here — see
    //    AccountGuard's own docblock for why.
    if (AccountGuard::check($userID, AccountGuard::REACH_VIEW, 'edit account #' . $userID) !== AccountGuard::ALLOW) {
        $_SESSION['flash_msg']  = AccountGuard::message(AccountGuard::NOT_FOUND, AccountGuard::REACH_VIEW);
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/users');
        exit();
    }

    // 📖 Step 3: read the account exactly as it stands today, so "what
    //    changed" (step 6) is judged against the REAL stored values, not
    //    against whatever the form happened to submit.
    $stmt = $mysqli->prepare(
        'SELECT fullName, emailAddress, phoneNumber, isActive, isAdmin, isRootAdmin '
        . 'FROM tblUsers WHERE userID = ? LIMIT 1'
    );
    $stored = null;
    if ($stmt !== false) {
        $stmt->bind_param('i', $userID);
        $stmt->execute();
        $stored = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if ($stored === null) {
        // The AccountGuard check above already confirmed the account
        // exists and belongs here; this can only happen if it was
        // deleted in the instant between that check and this read.
        $_SESSION['flash_msg']  = AccountGuard::message(AccountGuard::NOT_FOUND, AccountGuard::REACH_VIEW);
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/users');
        exit();
    }

    // 📝 Step 4: the posted values.
    $fullName     = trim($_POST['fullName'] ?? '');
    $emailAddress = trim($_POST['emailAddress'] ?? '');
    $phoneNumber  = trim($_POST['phoneNumber'] ?? '');
    $password     = $_POST['password'] ?? '';
    $isActive     = isset($_POST['isActive']) === true ? 1 : 0;

    if ($fullName === '' || $emailAddress === '') {
        $_SESSION['flash_msg']  = 'Full name and email address are required.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/users');
        exit();
    }

    // 🔢 Step 5: the stored values, read with the same two-value flag
    //    test App::flagIsOn() uses (1 or '1' — see #497). A prepared
    //    statement hands a TINYINT column back as a PHP whole number, so
    //    comparing only against the text '1' would silently be wrong.
    $storedIsAdmin  = ($stored['isAdmin'] === 1 || $stored['isAdmin'] === '1') ? 1 : 0;
    $storedIsRoot   = ($stored['isRootAdmin'] === 1 || $stored['isRootAdmin'] === '1') ? 1 : 0;
    $storedIsActive = ($stored['isActive'] === 1 || $stored['isActive'] === '1') ? 1 : 0;

    $actorGlobal = AccountGuard::actorIsGlobal();
    if ($actorGlobal === true) {
        $newIsAdmin = isset($_POST['isAdmin']) === true ? 1 : 0;
        $newIsRoot  = isset($_POST['isRootAdmin']) === true ? 1 : 0;
    } else {
        // 🛡️ #518: a missing checkbox means "not asked" here, so an
        //    ordinary edit (the form never draws this box for a
        //    non-global administrator — see admin/users/index.php) keeps
        //    the account's isAdmin flag exactly as it was, rather than
        //    silently clearing it. A PRESENT value can only arrive from a
        //    hand-made request, since the box is not drawn; that request
        //    is caught in step 7 below.
        $newIsAdmin = isset($_POST['isAdmin']) === true ? 1 : $storedIsAdmin;
        // isRootAdmin is never written by anyone who is not global.
        $newIsRoot  = $storedIsRoot;
    }

    // 🔍 Step 6: work out what actually changed, against the REAL stored
    //    row — not against whatever the form re-submitted unchanged.
    $portalChanged = ($newIsAdmin !== $storedIsAdmin);
    $rootChanged   = ($actorGlobal === true && $newIsRoot !== $storedIsRoot);
    $detailsChanged = (
        $fullName !== trim((string) ($stored['fullName'] ?? ''))
        || $emailAddress !== trim((string) ($stored['emailAddress'] ?? ''))
        || $phoneNumber !== trim((string) ($stored['phoneNumber'] ?? ''))
        || $isActive !== $storedIsActive
        || $password !== ''
    );

    if ($portalChanged === false && $rootChanged === false && $detailsChanged === false) {
        $_SESSION['flash_msg']  = 'Nothing was changed.';
        $_SESSION['flash_type'] = 'info';
        header('Location: /admin/users');
        exit();
    }

    // 🛡️ Step 7: portal-wide administrator rights are checked FIRST and
    //    on their own — giving or removing isAdmin is always a
    //    global-administrator-only action (AccountGuard::REACH_PORTAL),
    //    whatever else was also submitted in the same form post.
    if ($portalChanged === true) {
        $verdict = AccountGuard::check(
            $userID,
            AccountGuard::REACH_PORTAL,
            'change portal-wide administrator rights on account #' . $userID
        );
        if ($verdict !== AccountGuard::ALLOW) {
            $_SESSION['flash_msg']  = AccountGuard::message($verdict, AccountGuard::REACH_PORTAL);
            $_SESSION['flash_type'] = 'danger';
            header('Location: /admin/users');
            exit();
        }
    }

    // 🛡️ Step 8: everything else stored on the account — name, email,
    //    phone, password, on/off switch — is a AccountGuard::REACH_ACCOUNT
    //    change: refused for a global administrator's account, a
    //    portal-wide administrator's account, or an account that also
    //    belongs (or used to belong) to another organisation.
    if ($detailsChanged === true) {
        $verdict = AccountGuard::check($userID, AccountGuard::REACH_ACCOUNT, 'change account #' . $userID);
        if ($verdict !== AccountGuard::ALLOW) {
            $_SESSION['flash_msg']  = AccountGuard::message($verdict, AccountGuard::REACH_ACCOUNT);
            $_SESSION['flash_type'] = 'danger';
            header('Location: /admin/users');
            exit();
        }
    }

    // 🔍 Step 9: email uniqueness — only worth checking when the email
    //    address genuinely changed. #521 (20 September 2026): this used to
    //    be a flat "another user with that email address already exists",
    //    the same installation-wide oracle the create branch had — see
    //    AccountGuard::emailAvailability()'s own docblock for the bounded
    //    design that replaces it.
    if ($emailAddress !== trim((string) ($stored['emailAddress'] ?? ''))) {
        $emailVerdict = AccountGuard::emailAvailability(
            $emailAddress,
            $userID,
            'change email on account #' . $userID
        );
        if ($emailVerdict !== AccountGuard::EMAIL_FREE) {
            $_SESSION['flash_msg']  = AccountGuard::emailMessage($emailVerdict);
            $_SESSION['flash_type'] = 'danger';
            header('Location: /admin/users');
            exit();
        }
    }

    // 🔑 Step 10: password policy is checked BEFORE anything is written —
    //    the old order let every other field save even when the password
    //    was then rejected, which silently changed the account halfway.
    if ($password !== '') {
        $check = Auth::validatePassword($password);
        if ($check['valid'] === false) {
            $_SESSION['flash_msg']  = 'Password does not meet policy: ' . implode(' ', $check['errors']);
            $_SESSION['flash_type'] = 'danger';
            header('Location: /admin/users');
            exit();
        }
    }

    // 💾 Step 11: save everything in one transaction. Nothing above this
    //    point has written anything.
    App::beginTransaction();
    try {
        if ($actorGlobal === true) {
            $stmt = $mysqli->prepare(
                'UPDATE tblUsers SET fullName = ?, emailAddress = ?, phoneNumber = ?, '
                . 'isActive = ?, isAdmin = ?, isRootAdmin = ? WHERE userID = ?'
            );
            if ($stmt === false) {
                throw new \RuntimeException('Failed to prepare user update: ' . $mysqli->error);
            }
            $stmt->bind_param('sssiiii', $fullName, $emailAddress, $phoneNumber, $isActive, $newIsAdmin, $newIsRoot, $userID);
        } else {
            $stmt = $mysqli->prepare(
                'UPDATE tblUsers SET fullName = ?, emailAddress = ?, phoneNumber = ?, isActive = ? WHERE userID = ?'
            );
            if ($stmt === false) {
                throw new \RuntimeException('Failed to prepare user update: ' . $mysqli->error);
            }
            $stmt->bind_param('sssii', $fullName, $emailAddress, $phoneNumber, $isActive, $userID);
        }
        $stmt->execute();
        $stmt->close();

        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $mysqli->prepare('SELECT localID FROM tblLocalAccounts WHERE userID = ? LIMIT 1');
            if ($stmt === false) {
                throw new \RuntimeException('Failed to prepare local account lookup: ' . $mysqli->error);
            }
            $stmt->bind_param('i', $userID);
            $stmt->execute();
            $localRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($localRow !== null) {
                $stmt = $mysqli->prepare('UPDATE tblLocalAccounts SET passwordHash = ? WHERE userID = ?');
                if ($stmt === false) {
                    throw new \RuntimeException('Failed to prepare password update: ' . $mysqli->error);
                }
                $stmt->bind_param('si', $hash, $userID);
                $stmt->execute();
                $stmt->close();
            }
        }

        App::commit();
    } catch (\Throwable $ex) {
        App::rollback();
        Logger::exception($ex);
        $_SESSION['flash_msg']  = 'The account could not be saved. Nothing was changed.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/users');
        exit();
    }

    // 📝 Step 12: success.
    Logger::activity('UserUpdated', 'Updated user #' . $userID . ': ' . $fullName, $_SESSION['user_id'] ?? null);

    $_SESSION['flash_msg']  = 'User "' . $fullName . '" updated successfully.';
    $_SESSION['flash_type'] = 'success';
    header('Location: /admin/users');
    exit();
}

// 🚫 Unknown action
$_SESSION['flash_msg']  = 'Unknown action.';
$_SESSION['flash_type'] = 'warning';
header('Location: /admin/users');
exit();
