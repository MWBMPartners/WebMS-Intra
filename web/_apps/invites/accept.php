<?php
// Path: public_html/invites/accept.php  →  /auth/invite?token=...
/**
 * -----------------------------------------------------------------------------
 * Invite Onboarding — public acceptance page 🎟️
 * -----------------------------------------------------------------------------
 * GET  shows the self-registration form pre-filled with the invite's email.
 * POST creates a tblUsers + tblLocalAccount row, marks the invitation
 *      accepted, signs the user in, redirects to dashboard.
 *
 * Security notes (#B6): password is checked against the admin-configured
 * policy via Auth::validatePassword() (not a hardcoded length check); the
 * session ID is regenerated before granting identity (fixation defence,
 * matching every other login path on a public pre-auth page); the site
 * context is set via Auth::initSessionSite() so multi-site invitees land
 * in the invite's site rather than session key `site_id` that Site::id()
 * never reads.
 *
 * #518 FIX (17 September 2026): an "admin" invitation used to set the
 * new account's PORTAL-WIDE isAdmin flag — a full takeover of every
 * organisation on the installation, from a single accepted invitation.
 * It now sets isSiteAdmin on the membership row for the invitation's own
 * organisation only.
 *
 * @package   Portal\Invites
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.3.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/239
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;

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

        // 🔐 Password policy (#B6a). This was previously a hardcoded
        // `strlen < 12` check that silently ignored whatever password
        // policy an admin had configured (uppercase/lowercase/number/
        // special-char requirements, a raised minimum, etc.) — the register
        // and reset-password flows both go through Auth::validatePassword(),
        // so this primary onboarding path needs to as well.
        $pwCheck = ($password !== '') ? Auth::validatePassword($password) : ['valid' => false, 'errors' => ['Password is required.']];

        if ($name === '' || $username === '') {
            $flash = 'Name and username required.';
            $flashType = 'danger';
        } elseif ($password !== $confirm) {
            $flash = 'Passwords do not match.';
            $flashType = 'danger';
        } elseif ($pwCheck['valid'] === false) {
            $flash = 'Password does not meet policy: ' . implode(' ', $pwCheck['errors']);
            $flashType = 'danger';
        } else {
            $db = App::db();
            try {
                $db->begin_transaction();

                // 🛡️ #518: role "admin" used to give the new account the
                //    PORTAL-WIDE isAdmin flag — administrator of EVERY
                //    organisation on the installation, not only the one
                //    that sent the invitation (proven on a real database
                //    while investigating #518). It now makes the person an
                //    administrator of THIS organisation only, via the
                //    site-scoped isSiteAdmin flag on their membership row.
                //    This also covers an invitation that was SENT before
                //    this fix shipped and is only now being accepted —
                //    deliberate, since intendedRole is read fresh here, at
                //    acceptance time, not frozen at send time.
                $intendedRole = (string) ($invite['intendedRole'] ?? 'user');
                $stmt = $db->prepare(
                    'INSERT INTO tblUsers (fullName, emailAddress, isActive, isAdmin) '
                    . 'VALUES (?, ?, 1, 0)'
                );
                if ($stmt === false) {
                    throw new \RuntimeException('User prepare failed');
                }
                $stmt->bind_param('ss', $name, $invite['email']);
                $stmt->execute();
                $newUserId = (int) $stmt->insert_id;
                $stmt->close();

                // Site membership (tblUserSites) — isSiteAdmin=1 only for
                // an "admin" invitation, and only for THIS site.
                $isSiteAdmin = ($intendedRole === 'admin') ? 1 : 0;
                $inviteSiteId = (int) $invite['siteID'];
                $stmt = $db->prepare(
                    'INSERT INTO tblUserSites (userID, siteID, isSiteAdmin, isActive) VALUES (?, ?, ?, 1)'
                );
                if ($stmt !== false) {
                    $stmt->bind_param('iii', $newUserId, $inviteSiteId, $isSiteAdmin);
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

                // 🔄 Regenerate the session ID before granting identity on
                // this public, pre-auth page (#B6b) — session fixation
                // defence, identical to every other place a session gets
                // promoted to an authenticated one (Auth::loginLocal(),
                // the OAuth callbacks, WebAuthn login).
                // See: https://owasp.org/www-community/attacks/Session_fixation
                session_regenerate_id(true);

                // Sign in (write session). `active_site_id` — NOT `site_id`
                // — is the key Site::id() actually reads (#B6c); the old
                // key silently landed multi-site invitees in the wrong
                // site on every request after signup. Auth::initSessionSite()
                // is the same site-context initializer the password/SSO/
                // WebAuthn login paths all call.
                $_SESSION['user_id'] = $newUserId;
                Auth::initSessionSite($newUserId, $db);

                header('Location: /');
                exit();
            } catch (\Throwable $e) {
                $db->rollback();
                // 🔐 Public pre-auth page — never reflect the raw exception
                // message (table/column names on a duplicate-key, etc.) back
                // to the client. Log it server-side and show a generic
                // message instead, mirroring auth/reset-password/save.php.
                Logger::errorPlatform('Invites', 'Error', 'INVITE_ACCEPT_FAIL', $e->getMessage(), '');
                $flash = 'That username or email is already in use.';
                $flashType = 'danger';
            }
        }
    }
}

$portalName = (string) (App::settings()['site']['name'] ?? 'the portal');
$csrf = Auth::csrfToken();

// 🔐 Reflect the SAME configured password policy the server enforces above
// (#B6a) so the client-side hints never disagree with what the site
// actually requires — a hardcoded "min 12" here would mislead an admin
// who raised (or, on maxLength, could contradict) the real policy.
$passwordPolicy = Auth::passwordPolicy();
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
        <label>Password (min <?php echo (int) $passwordPolicy['minLength']; ?> chars)</label>
        <input type="password" name="password" required
               minlength="<?php echo (int) $passwordPolicy['minLength']; ?>"
               maxlength="<?php echo (int) $passwordPolicy['maxLength']; ?>"
               autocomplete="new-password">
        <p class="muted" style="margin:.25rem 0 0;"><?php echo htmlspecialchars(implode(' · ', $passwordPolicy['rules']), ENT_QUOTES, 'UTF-8'); ?></p>
        <label>Confirm password</label>
        <input type="password" name="passwordConfirm" required
               minlength="<?php echo (int) $passwordPolicy['minLength']; ?>"
               maxlength="<?php echo (int) $passwordPolicy['maxLength']; ?>"
               autocomplete="new-password">
        <button type="submit">Create my account</button>
    </form>
</div>
</body>
</html>
