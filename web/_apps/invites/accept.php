<?php
// Path: public_html/invites/accept.php  →  /auth/invite?token=...
/**
 * Invite Onboarding — public acceptance page.
 *
 * GET  shows the self-registration form pre-filled with the invite's email.
 * POST creates a tblUsers + tblLocalAccount row, marks the invitation
 *      accepted, signs the user in, redirects to dashboard.
 *
 * @package   Portal\Invites
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/239
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;

Auth::ensureSession();

$token = (string) ($_GET['token'] ?? ($_POST['token'] ?? ''));
$flash = '';
$flashType = 'info';

$invite = null;
if (preg_match('/^[a-f0-9]{64}$/i', $token) === 1) {
    $hash = hash('sha256', $token);
    $db = App::db();
    $stmt = $db->prepare(
        'SELECT invitationID, siteID, email, intendedRole, welcomeMessage, '
        . '       expiresAt, acceptedAt, revokedAt '
        . 'FROM tblInvitation WHERE tokenHash = ? LIMIT 1'
    );
    if ($stmt !== false) {
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $invite = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

$valid = $invite !== null
       && $invite['acceptedAt'] === null
       && $invite['revokedAt'] === null
       && strtotime((string) $invite['expiresAt']) > time();

if ($valid === false) {
    http_response_code(410);
    $message = $invite === null
        ? 'This invitation link is invalid.'
        : ($invite['acceptedAt'] !== null
            ? 'This invitation has already been accepted.'
            : ($invite['revokedAt'] !== null
                ? 'This invitation has been revoked.'
                : 'This invitation has expired.'));
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>Invitation</title></head>'
       . '<body style="font-family:system-ui;text-align:center;padding:4rem 1rem;">'
       . '<h1>Invitation unavailable</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
       . '<p><a href="/">Return to portal</a></p></body></html>';
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $flash = 'Form expired — please reload the page.';
        $flashType = 'danger';
    } else {
        $name     = trim((string) ($_POST['fullName'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['passwordConfirm'] ?? '');
        $username = trim((string) ($_POST['username'] ?? ''));

        if ($name === '' || $username === '') {
            $flash = 'Name and username required.';
            $flashType = 'danger';
        } elseif (strlen($password) < 12) {
            $flash = 'Password must be at least 12 characters.';
            $flashType = 'danger';
        } elseif ($password !== $confirm) {
            $flash = 'Passwords do not match.';
            $flashType = 'danger';
        } else {
            $db = App::db();
            try {
                $db->begin_transaction();

                // Create user
                $intendedRole = (string) ($invite['intendedRole'] ?? 'user');
                $isAdmin = ($intendedRole === 'admin' ? 1 : 0);
                $stmt = $db->prepare(
                    'INSERT INTO tblUsers (fullName, emailAddress, isActive, isAdmin) '
                    . 'VALUES (?, ?, 1, ?)'
                );
                if ($stmt === false) {
                    throw new \RuntimeException('User prepare failed');
                }
                $stmt->bind_param('ssi', $name, $invite['email'], $isAdmin);
                $stmt->execute();
                $newUserId = (int) $stmt->insert_id;
                $stmt->close();

                // Site membership (tblUserSites)
                $stmt = $db->prepare(
                    'INSERT INTO tblUserSites (userID, siteID, isActive) VALUES (?, ?, 1)'
                );
                if ($stmt !== false) {
                    $stmt->bind_param('ii', $newUserId, $invite['siteID']);
                    $stmt->execute();
                    $stmt->close();
                }

                // Local account
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare(
                    'INSERT INTO tblLocalAccounts (userID, username, passwordHash) VALUES (?, ?, ?)'
                );
                if ($stmt !== false) {
                    $stmt->bind_param('iss', $newUserId, $username, $hash);
                    $stmt->execute();
                    $stmt->close();
                }

                // Mark invitation accepted
                $stmt = $db->prepare(
                    'UPDATE tblInvitation SET acceptedAt = NOW(), acceptedByID = ? WHERE invitationID = ?'
                );
                if ($stmt !== false) {
                    $stmt->bind_param('ii', $newUserId, $invite['invitationID']);
                    $stmt->execute();
                    $stmt->close();
                }

                $db->commit();

                // Sign in (write session)
                $_SESSION['user_id'] = $newUserId;
                $_SESSION['site_id'] = (int) $invite['siteID'];

                header('Location: /');
                exit();
            } catch (\Throwable $e) {
                $db->rollback();
                $flash = 'Could not complete signup: ' . $e->getMessage();
                $flashType = 'danger';
            }
        }
    }
}

$portalName = (string) (App::settings()['site']['name'] ?? 'the portal');
$csrf = Auth::csrfToken();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Accept invitation — <?php echo htmlspecialchars($portalName, ENT_QUOTES, 'UTF-8'); ?></title>
<style>
:root{--bg:#f7f8fa;--surface:#fff;--text:#1b2330;--muted:#6b7280;--border:#e5e7eb;--primary:#5e6ad2;}
@media (prefers-color-scheme:dark){:root{--bg:#0f1115;--surface:#161a22;--text:#e8eaf0;--muted:#9aa3b2;--border:#2c3441;}}
body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--text);margin:0;padding:2rem 1rem;}
.card{max-width:480px;margin:0 auto;background:var(--surface);border:1px solid var(--border);border-radius:.75rem;padding:1.5rem;}
label{display:block;font-size:.875rem;margin:.75rem 0 .25rem;}
input{width:100%;padding:.5rem;border:1px solid var(--border);border-radius:.375rem;background:var(--surface);color:var(--text);box-sizing:border-box;}
button{margin-top:1rem;padding:.625rem 1.25rem;background:var(--primary);color:#fff;border:none;border-radius:.375rem;font-weight:500;cursor:pointer;}
.flash{padding:.5rem;border-radius:.375rem;margin-bottom:1rem;}
.flash-danger{background:#fee2e2;color:#991b1b;}
.muted{color:var(--muted);font-size:.875rem;}
</style>
</head>
<body>
<div class="card">
    <h1>Welcome to <?php echo htmlspecialchars($portalName, ENT_QUOTES, 'UTF-8'); ?></h1>
    <?php if (trim((string) ($invite['welcomeMessage'] ?? '')) !== ''): ?>
        <p><em><?php echo nl2br(htmlspecialchars((string) $invite['welcomeMessage'], ENT_QUOTES, 'UTF-8')); ?></em></p>
    <?php endif; ?>
    <p class="muted">Set up your account to accept this invitation.</p>
    <?php if ($flash !== ''): ?>
        <div class="flash flash-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
        <label>Email</label>
        <input type="email" value="<?php echo htmlspecialchars((string) $invite['email'], ENT_QUOTES, 'UTF-8'); ?>" disabled>
        <label>Your full name</label>
        <input type="text" name="fullName" required maxlength="255">
        <label>Choose a username</label>
        <input type="text" name="username" required maxlength="50" pattern="[a-zA-Z0-9._\-]+">
        <label>Password (min 12 chars)</label>
        <input type="password" name="password" required minlength="12" autocomplete="new-password">
        <label>Confirm password</label>
        <input type="password" name="passwordConfirm" required minlength="12" autocomplete="new-password">
        <button type="submit">Create my account</button>
    </form>
</div>
</body>
</html>
