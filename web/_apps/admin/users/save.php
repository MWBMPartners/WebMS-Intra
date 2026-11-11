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
 * -----------------------------------------------------------------------------
 * #586 FIX (3 November 2026): "update" reported a new password as saved
 * when the account had never had one. It looked for the account's sign-in
 * row and wrote the new password only if it found one, so an account made
 * by an invitation nobody accepted, or one that signs in with Microsoft,
 * Google or a passkey, had nothing stored while the page still said
 * "updated successfully". Step 10b now works out BEFORE anything is
 * written whether the password can be stored at all: it creates the
 * sign-in row where the account has no other way in, and refuses the
 * whole save, saying why, where it cannot. Step 11 reads back that a
 * password really is held before step 12 claims anything.
 *
 * -----------------------------------------------------------------------------
 * #586 FIX, PART TWO (3 November 2026): the SAME fault was in the "create"
 * branch, and an independent check of part one found it. The sign-in insert is
 * wrapped in `if ($username !== '')`, and the Add User form labels that box
 * "Leave blank to skip local account" — so a typed password with the box left
 * blank stored nothing and the page said "created successfully". It now
 * refuses, in words, before anything is written. Same shape, same remedy: a
 * success message must never sit over a write that did not happen.
 *
 * -----------------------------------------------------------------------------
 * #977 FIX (11 November 2026): "update" changed an account that had been
 * ERASED on request. A global administrator could tick Active or change its
 * address, and the account then stopped reading as erased, which took the
 * name kept for tax out of the Gift Aid claim file and switched off the
 * refusal that stops a new statement being written over the kept one. Step
 * 2b now refuses, in words, before anything is compared or written; see
 * AccountGuard's docblock, "AN ERASED ACCOUNT CANNOT BE CHANGED BY ANYBODY".
 * The list page no longer offers an Edit button for such an account either.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.5.0
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
    header('Location: ' . Site::url('admin/users'));
    exit();
}

// 🛡️ CSRF verification
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/users'));
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
        header('Location: ' . Site::url('admin/users'));
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
        header('Location: ' . Site::url('admin/users'));
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
        header('Location: ' . Site::url('admin/users'));
        exit();
    }

    // 🔍 If username provided, password is required
    if ($username !== '' && $password === '') {
        $_SESSION['flash_msg']  = 'Password is required when creating a local account.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/users'));
        exit();
    }

    // 🔑 AND THE OTHER WAY ROUND — #586's EXACT FAULT, IN THIS BRANCH (fixed
    //    3 November 2026, found by an independent check of #586).
    //
    //    The sign-in insert further down is wrapped in `if ($username !== '')`,
    //    and the Sign-in name box on the Add User form is labelled "Leave
    //    blank to skip local account". So typing a password and leaving that
    //    box blank stored NO PASSWORD AT ALL and the page said
    //    'User "…" created successfully.' — a success message over a write
    //    that did not happen, which is the whole of #586 one branch along.
    //    An administrator who did that had every reason to believe the person
    //    could sign in.
    //
    //    IT REFUSES RATHER THAN INVENTING A SIGN-IN NAME. Step 10b of the
    //    update branch does use the e-mail address when it has nobody to ask,
    //    but that is a CORRECTION to an account that already exists; here the
    //    administrator is filling a form in and can simply be told. Nothing
    //    has been written yet — this is before the transaction — so refusing
    //    costs a retype and the other answer costs a password nobody holds.
    if ($username === '' && $password !== '') {
        $_SESSION['flash_msg']  = 'No account was created. A password was typed but the sign-in name box was '
            . 'left blank, and a password cannot be stored without one — that box is what decides whether this '
            . 'person gets a password sign-in at all. Type a sign-in name to give them one, or clear both '
            . 'password boxes to add the account without a password (they can then be invited, or sign in with '
            . 'Microsoft, Google or a passkey).';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/users'));
        exit();
    }

    if ($password !== '' && $password !== $passConfirm) {
        $_SESSION['flash_msg']  = 'Passwords do not match.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/users'));
        exit();
    }

    // 🛡️ Enforce password policy when a password was supplied
    if ($password !== '') {
        $check = Auth::validatePassword($password);
        if ($check['valid'] === false) {
            $_SESSION['flash_msg']  = 'Password does not meet policy: ' . implode(' ', $check['errors']);
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . Site::url('admin/users'));
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

        // 🔑 Create the sign-in details if a name was given. ONE PLACE
        //    STORES A PASSWORD (#728): `Auth::setPassword()` hashes it,
        //    creates the row, refuses a name that is too long or already in
        //    use, and READS THE WRITE BACK — which this branch never did.
        //    `isVerified` is left at the column's default (0) deliberately: an
        //    administrator typing a password is not the person proving their
        //    e-mail address. The whole save is in a transaction, so a refusal
        //    takes the new account back with it.
        if ($username !== '') {
            $stored = Auth::setPassword($mysqli, (int) $newUserId, $password, $username);
            if ($stored['ok'] === false) {
                throw new \RuntimeException('The password could not be stored: ' . $stored['why']);
            }
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

        // #933: the new account's NUMBER, never its name or e-mail address.
        //    This record belongs to the ADMINISTRATOR (userID is who acted), so
        //    erasing the new member could never reach it: the name and address
        //    would have stayed in its words for as long as the log is kept.
        Logger::activity('UserCreated', 'Created user #' . (int) $newUserId, $_SESSION['user_id'] ?? null);

        $_SESSION['flash_msg']  = 'User "' . $fullName . '" created successfully.';
        $_SESSION['flash_type'] = 'success';
        header('Location: ' . Site::url('admin/users'));
        exit();

    } catch (\Throwable $ex) {
        App::rollback();
        Logger::exception($ex);
        $_SESSION['flash_msg']  = t('error.db_create_user');
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/users'));
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
        header('Location: ' . Site::url('admin/users'));
        exit();
    }

    // 🛡️ #518 step 2: is this administrator even allowed to SEE this
    //    account? A missing account and one that belongs to another
    //    organisation must look byte-for-byte identical here — see
    //    AccountGuard's own docblock for why.
    if (AccountGuard::check($userID, AccountGuard::REACH_VIEW, 'edit account #' . $userID) !== AccountGuard::ALLOW) {
        $_SESSION['flash_msg']  = AccountGuard::message(AccountGuard::NOT_FOUND, AccountGuard::REACH_VIEW);
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/users'));
        exit();
    }

    // 🗑️ Step 2b (#977): an account ERASED on request is never changed,
    //    by anybody — a global administrator included. Saving one used to
    //    work: ticking Active or changing its address made it stop reading
    //    as erased (GdprEraser::emptiedAccountSql()), so the name kept for
    //    tax dropped out of the Gift Aid claim file and nothing any longer
    //    stopped a new "[Deleted User]" statement being written over the
    //    kept one. Asked HERE, before anything else is compared, for two
    //    reasons: the person is told the real reason even when the form
    //    came back unchanged, and a global administrator ticking ONLY
    //    "global administrator" reaches no AccountGuard::check() below at
    //    all (they are allowed that without asking), so steps 7 and 8 alone
    //    would have let that one through. Steps 7 and 8 refuse an erased
    //    account too (row 3a of AccountGuard::decide()); this is the first
    //    of the two locks, not the only one.
    if (AccountGuard::checkNotErased($userID, 'edit account #' . $userID) !== AccountGuard::ALLOW) {
        $_SESSION['flash_msg']  = AccountGuard::message(AccountGuard::ERASED, AccountGuard::REACH_ACCOUNT);
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/users'));
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
        header('Location: ' . Site::url('admin/users'));
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
        header('Location: ' . Site::url('admin/users'));
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
        header('Location: ' . Site::url('admin/users'));
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
            header('Location: ' . Site::url('admin/users'));
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
            header('Location: ' . Site::url('admin/users'));
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
            header('Location: ' . Site::url('admin/users'));
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
            header('Location: ' . Site::url('admin/users'));
            exit();
        }
    }

    // 🔑 Step 10b (#586): CAN this account be given a password at all?
    //    Worked out here, before anything is written, for exactly the
    //    reason step 10 is: the old order changed the account and then
    //    silently did nothing about the password.
    //
    //    WHAT WAS WRONG, and it had been wrong since this page was first
    //    written. Step 11 looked for the account's sign-in row and set a
    //    new password ONLY if it found one. An account created by an
    //    invitation nobody accepted, or one that signs in with Microsoft,
    //    Google or a passkey, has no such row — so nothing at all was
    //    stored, and step 12 still said "updated successfully". An
    //    administrator setting somebody a password during an incident was
    //    told it had worked and that person still could not sign in.
    //    Proved to be the original shape with:
    //        git show a443d6c:web/public_html/admin/users/save.php
    //    (7 March 2026 — the same `if ($localRow !== null)` with nothing
    //    after it). The disaster-recovery runbook had to
    //    carry a warning about it; see docs/disaster-recovery-runbook.md,
    //    section 7.4.
    //
    //    $makeSignIn is the ONE answer this step produces: true means
    //    step 11 must CREATE the sign-in row, false means set the password
    //    on the row that is already there. Anything that stops the
    //    password being stored refuses the WHOLE save, in words, and
    //    writes nothing — a success message over a write that did not
    //    happen is the fault being fixed, so it must not be left anywhere
    //    near here.
    $makeSignIn = false;
    $signInName = '';
    if ($password !== '') {
        // 🛡️ Nothing has been written yet, so a database fault here
        //    refuses with the same wording a failed save uses, rather
        //    than throwing: there is no transaction to roll back and no
        //    try/catch around this step. It is belt and braces — this
        //    connection is strict (#593), so prepare() throws rather than
        //    answering false — but a quiet "false" must never be read as
        //    "there is no sign-in row", which would create a second one.
        $stmt = $mysqli->prepare('SELECT localID FROM tblLocalAccounts WHERE userID = ? LIMIT 1');
        if ($stmt === false) {
            $_SESSION['flash_msg']  = 'The account could not be saved. Nothing was changed.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . Site::url('admin/users'));
            exit();
        }
        $stmt->bind_param('i', $userID);
        $stmt->execute();
        $localRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($localRow === null) {
            // 🔗 Has this person another way in? Auth::countLoginMethods()
            //    counts the sign-in row, their linked Microsoft and Google
            //    accounts and their passkeys. There is no sign-in row
            //    here, so whatever it answers is the number of OTHER ways
            //    this person gets in.
            //
            //    DELIBERATELY NOT DECIDED HERE, and it is the owner's to
            //    decide: whether an administrator may ADD password sign-in
            //    to an account that already has one of those. That is a
            //    second way into the account rather than a correction, and
            //    the recovery runbook treats the three ways in as separate
            //    things to find and close one by one (section 7.3). So
            //    this refuses and says so plainly. It does NOT tell the
            //    administrator to use "Forgot password" instead: that
            //    page joins tblLocalAccounts, so it finds nothing for an
            //    account with no sign-in row either. There is no way round
            //    this today, which is the honest thing to say and is
            //    written up as its own issue rather than guessed at here.
            $otherWays = Auth::countLoginMethods($userID, $mysqli);
            if ($otherWays > 0) {
                $_SESSION['flash_msg']  = 'No password was set, and nothing else was saved either. This account has '
                    . 'no password of its own — it signs in another way, with Microsoft or Google or with a passkey. '
                    . 'This page does not add a password to such an account, because that would be a second way in '
                    . 'rather than a correction. Clear the New Password box and press Save again to store your other '
                    . 'changes.';
                $_SESSION['flash_type'] = 'warning';
                header('Location: ' . Site::url('admin/users'));
                exit();
            }

            // 🏷️ The sign-in name for the row that is about to be made. A
            //    row cannot exist without one: tblLocalAccounts.username
            //    is NOT NULL, holds 50 characters, and is unique across
            //    the whole installation. The account's e-mail address is
            //    used, which is what the installer and the users API both
            //    do when they create a row with nobody to ask. It matters
            //    less than it looks: Auth::loginLocal() accepts EITHER the
            //    sign-in name or the account's e-mail address, so the
            //    person signs in with the address they already know
            //    whatever this value is. The address as this save will
            //    store it is used, not the one on file, so the two agree.
            $signInName = $emailAddress;
            // 📏 mb_strlen, not strlen: VARCHAR(50) counts CHARACTERS, so
            //    counting bytes would refuse an address that fits. The
            //    installer has the same limit and the same wording for it.
            if (mb_strlen($signInName, 'UTF-8') > 50) {
                $_SESSION['flash_msg']  = 'No password was set, and nothing else was saved either. This account has '
                    . 'never had a password, so a sign-in has to be created for it, and its sign-in name would be '
                    . 'this e-mail address — which is longer than 50 characters, the most a sign-in name can hold. '
                    . 'Give the account a shorter e-mail address first, then set the password.';
                $_SESSION['flash_type'] = 'danger';
                header('Location: ' . Site::url('admin/users'));
                exit();
            }
            // 🔍 Is that sign-in name free? The unique key is what really
            //    guarantees it — two administrators saving at once would
            //    still be caught there, and the failure below rolls the
            //    whole save back — but asking first is what lets this say
            //    WHY rather than "the account could not be saved". The
            //    column's collation ignores capitals, and so does this
            //    comparison, which is what the unique key does too.
            //    Nothing is said about whose name it is: that is not this
            //    administrator's to know (#521).
            $stmt = $mysqli->prepare('SELECT localID FROM tblLocalAccounts WHERE username = ? LIMIT 1');
            if ($stmt === false) {
                $_SESSION['flash_msg']  = 'The account could not be saved. Nothing was changed.';
                $_SESSION['flash_type'] = 'danger';
                header('Location: ' . Site::url('admin/users'));
                exit();
            }
            $stmt->bind_param('s', $signInName);
            $stmt->execute();
            $nameTaken = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($nameTaken !== null) {
                $_SESSION['flash_msg']  = 'No password was set, and nothing else was saved either. This account has '
                    . 'never had a password, so a sign-in has to be created for it under its e-mail address, and '
                    . 'that sign-in name cannot be used. Ask a global administrator to look into it.';
                $_SESSION['flash_type'] = 'danger';
                header('Location: ' . Site::url('admin/users'));
                exit();
            }
            $makeSignIn = true;
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

        // 🔑 #586: the password is stored whichever of the two cases step
        //    10b found. There is no third case and no silent one: a
        //    password that cannot be stored never reaches here, because
        //    step 10b has already refused the whole save.
        // 📣 Whether a sign-in was really CREATED, as opposed to asked for.
        //    Defined before the branch so it is never undefined: the message
        //    below reads it whatever happened, and an undefined variable under
        //    `strict_types` is a warning on a page that has already begun
        //    printing — the shape that answers HTTP 200 with half a page.
        $signInWasCreated = false;
        // 🚪 D-108: null = no password was set, so nobody was signed out.
        $othersSignedOut  = null;
        // Is the administrator editing their OWN account? Then this browser
        // stays signed in (see the message below), and the words must say so.
        $setOwn = (int) $userID === (int) ($_SESSION['user_id'] ?? 0);

        if ($password !== '') {
            // 🔑 ONE PLACE STORES A PASSWORD (#728). This branch used to
            //    choose between an INSERT and an UPDATE itself, from
            //    `$makeSignIn`, and then read the write back with
            //    `LENGTH(passwordHash)`.
            //
            //    **Three things improve, and the third is a real fault going
            //    away.** The read-back now compares the HASH rather than its
            //    length, so "a hash is present" cannot stand in for "the hash
            //    we just made is present". The refusals for a too-long or
            //    already-used sign-in name are honest sentences instead of a
            //    duplicate-key exception. And the choice between creating and
            //    updating is made INSIDE the transaction, from what is there
            //    at the moment of writing.
            //
            //    **BE EXACT ABOUT WHAT WAS WRONG BEFORE — a draft of this said
            //    "rather than from a hidden form field", and that is FALSE.**
            //    `$makeSignIn` is not posted and there is no hidden input
            //    behind it: step 10b sets it from a `SELECT localID FROM
            //    tblLocalAccounts WHERE userID = ?` that found nothing (it is
            //    assigned at exactly two places, `= false` and `= true`, and
            //    read nowhere else). The real old exposure was the gap between
            //    that read and the write: a row created in between turned the
            //    INSERT into a SECOND row for one account — two sign-in names,
            //    two passwords, either of them working, because `userID`
            //    carries no unique key. Narrow, and now closed by the lock
            //    `Auth::setPassword()` takes inside this transaction.
            //
            //    `isVerified` is left at the column's default (0), as the Add
            //    User branch does. It gates nothing at sign-in —
            //    `Auth::loginLocal()` never reads it — and an administrator
            //    typing a password is not the person proving their e-mail
            //    address, so claiming otherwise would be untrue.
            $stored = Auth::setPassword(
                $mysqli,
                $userID,
                $password,
                $makeSignIn === true ? $signInName : null
            );
            if ($stored['ok'] === false) {
                throw new \RuntimeException($stored['why']);
            }
            // 📣 What the person is TOLD comes from what happened, not from
            //    what was asked for. `$makeSignIn` is a request; `created` is
            //    the outcome. Saying "a sign-in was created" over an update
            //    would tell an administrator this account could not be signed
            //    in to before, which may be false.
            $signInWasCreated = $stored['created'];

            // 🚪 D-108 (the owner, 10 November 2026; extends D-104): an
            //    administrator setting somebody's password signs that person
            //    out of EVERY browser, so anybody using the old password is put
            //    out at once. Inside this transaction, with the password, so
            //    the two stand or fall together. The administrator's own
            //    session is a different person and is not touched; if they are
            //    setting their OWN password here, their browser keeps going and
            //    their other browsers are signed out, exactly as D-104 does.
            //    It answers false only on a database still waiting for
            //    migration 270, and then the message below says so.
            $othersSignedOut = Auth::endOtherSessionsOf($mysqli, (int) $userID);

            // 🧾 #586's read-back now lives in `Auth::setPassword()`, where
            //    every caller gets it. It asks a STRICTLY STRONGER question
            //    than the code that was here: this page read
            //    `LENGTH(passwordHash)` and accepted any non-zero answer, so
            //    an old hash left in place by a write that never landed would
            //    have passed. The method compares the hash it just made.
        }

        App::commit();

        // 🖥️ D-108, after the commit: only if the administrator set their OWN
        //    password here does this browser take the account's new number
        //    and stay signed in. Setting somebody else's leaves the
        //    administrator's number exactly as it was (#598 check round 2).
        Auth::passwordChangeCommitted();
    } catch (\Throwable $ex) {
        App::rollback();
        Logger::exception($ex);
        $_SESSION['flash_msg']  = 'The account could not be saved. Nothing was changed.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/users'));
        exit();
    }

    // 📝 Step 12: success. #586: when a sign-in was CREATED the message
    //    says so, because "updated successfully" on its own would not
    //    tell an administrator that this account can now be signed in to
    //    with a password where before it could not. The sign-in name is
    //    named because the person can type either that or their e-mail
    //    address. Neither the message nor the record ever carries the
    //    password or any part of its hash.
    // 📣 What the record and the message say, written out WHOLE for each case
    //    (#598 check round 3). Setting your OWN password here is a different
    //    sentence from setting somebody else's: this browser stays signed in,
    //    and "the person" would be you. Each sentence is spelt out rather than
    //    assembled from pieces, because a piece written for one case read
    //    untrue in the other.
    $recordNote = '';
    $flashNote  = '';
    if ($setOwn === true) {
        if ($signInWasCreated === true) {
            $recordNote .= ' (a password sign-in was created for the administrator\'s own account)';
            $flashNote  .= ' A password sign-in was created for your account, under the sign-in name "' . $signInName . '"; '
                . 'you can sign in with that or with your e-mail address.';
        }
        if ($othersSignedOut === true) {
            $recordNote .= ' (the administrator set their own password; their other browsers were signed out)';
            $flashNote  .= ' Your new password is set. You are still signed in here, and you have been signed out of every other browser.';
        } elseif ($othersSignedOut === false) {
            $recordNote .= ' (the administrator set their own password, but their other browsers could NOT be signed out: database upgrade waiting)';
            $flashNote  .= ' Your new password is set, but your other browsers could NOT be signed out: a database upgrade is waiting (Admin, Upgrade).';
        }
    } else {
        if ($signInWasCreated === true) {
            $recordNote .= ' (a password sign-in was created for this account)';
            $flashNote  .= ' A password sign-in was created for this account, under the sign-in name "' . $signInName . '"; '
                . 'the person can sign in with that or with their e-mail address.';
        }
        if ($othersSignedOut === true) {
            $recordNote .= ' (the password was set, and the person was signed out of every browser)';
            $flashNote  .= ' The new password is set, and the person has been signed out of every browser where they were signed in.';
        } elseif ($othersSignedOut === false) {
            $recordNote .= ' (the password was set, but the person could NOT be signed out: database upgrade waiting)';
            $flashNote  .= ' The new password is set, but the person could NOT be signed out of the browsers where they are signed in: '
                . 'a database upgrade is waiting (Admin, Upgrade).';
        }
    }

    Logger::activity(
        'UserUpdated',
        // #933: the account's NUMBER only, never its name (see UserCreated).
        'Updated user #' . $userID . $recordNote,
        $_SESSION['user_id'] ?? null
    );

    $_SESSION['flash_msg']  = 'User "' . $fullName . '" updated successfully.' . $flashNote;
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . Site::url('admin/users'));
    exit();
}

// 🚫 Unknown action
$_SESSION['flash_msg']  = 'Unknown action.';
$_SESSION['flash_type'] = 'warning';
header('Location: ' . Site::url('admin/users'));
exit();
