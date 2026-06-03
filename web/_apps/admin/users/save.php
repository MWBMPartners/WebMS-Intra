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
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.3.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;

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

    // 🔍 Check for duplicate email
    $stmt = $mysqli->prepare('SELECT userID FROM tblUsers WHERE emailAddress = ? LIMIT 1');
    if ($stmt !== false) {
        $stmt->bind_param('s', $emailAddress);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing !== null) {
            $_SESSION['flash_msg']  = 'A user with that email address already exists.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: /admin/users');
            exit();
        }
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
    $userID       = (int) ($_POST['userID'] ?? 0);
    $fullName     = trim($_POST['fullName'] ?? '');
    $emailAddress = trim($_POST['emailAddress'] ?? '');
    $phoneNumber  = trim($_POST['phoneNumber'] ?? '');
    $password     = $_POST['password'] ?? '';
    $isActive     = isset($_POST['isActive']) === true ? 1 : 0;
    $isAdmin      = isset($_POST['isAdmin']) === true ? 1 : 0;
    $isRootAdmin  = (App::isRootAdmin() === true && isset($_POST['isRootAdmin']) === true) ? 1 : 0;

    if ($userID <= 0 || $fullName === '' || $emailAddress === '') {
        $_SESSION['flash_msg']  = 'Invalid user data.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/users');
        exit();
    }

    // 🔍 Check email uniqueness (excluding this user)
    $stmt = $mysqli->prepare('SELECT userID FROM tblUsers WHERE emailAddress = ? AND userID != ? LIMIT 1');
    if ($stmt !== false) {
        $stmt->bind_param('si', $emailAddress, $userID);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing !== null) {
            $_SESSION['flash_msg']  = 'Another user with that email address already exists.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: /admin/users');
            exit();
        }
    }

    // 📝 Update user record
    // 🛡️ Non-root admins cannot change isRootAdmin flag
    if (App::isRootAdmin() === true) {
        $stmt = $mysqli->prepare(
            'UPDATE tblUsers SET fullName = ?, emailAddress = ?, phoneNumber = ?, '
            . 'isActive = ?, isAdmin = ?, isRootAdmin = ? WHERE userID = ?'
        );
        if ($stmt !== false) {
            $stmt->bind_param('sssiiii', $fullName, $emailAddress, $phoneNumber, $isActive, $isAdmin, $isRootAdmin, $userID);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        $stmt = $mysqli->prepare(
            'UPDATE tblUsers SET fullName = ?, emailAddress = ?, phoneNumber = ?, '
            . 'isActive = ?, isAdmin = ? WHERE userID = ?'
        );
        if ($stmt !== false) {
            $stmt->bind_param('sssiii', $fullName, $emailAddress, $phoneNumber, $isActive, $isAdmin, $userID);
            $stmt->execute();
            $stmt->close();
        }
    }

    // 🔑 Update password if provided
    if ($password !== '') {
        // 🛡️ Enforce password policy before hashing
        $check = Auth::validatePassword($password);
        if ($check['valid'] === false) {
            $_SESSION['flash_msg']  = 'Password does not meet policy: ' . implode(' ', $check['errors']);
            $_SESSION['flash_type'] = 'danger';
            header('Location: /admin/users');
            exit();
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        // Check if local account exists
        $stmt = $mysqli->prepare('SELECT localID FROM tblLocalAccounts WHERE userID = ? LIMIT 1');
        if ($stmt !== false) {
            $stmt->bind_param('i', $userID);
            $stmt->execute();
            $localRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($localRow !== null) {
                // Update existing local account password
                $stmt = $mysqli->prepare('UPDATE tblLocalAccounts SET passwordHash = ? WHERE userID = ?');
                if ($stmt !== false) {
                    $stmt->bind_param('si', $hash, $userID);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
    }

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
