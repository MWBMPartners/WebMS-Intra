<?php
// _apps/kids/checkout.php — Staff terminal: scan/enter badge → check out (#298)
declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$staffId = (int) ($_SESSION['user_id'] ?? 0);
$message = null;
$messageClass = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        http_response_code(400); exit('Bad request');
    }
    $badge = trim((string) ($_POST['badgeCode'] ?? ''));
    $pickupName = mb_substr(trim((string) ($_POST['pickupName'] ?? '')), 0, 120);

    if (preg_match('/^\d{6}$/', $badge) !== 1) {
        $message = 'Badge code must be 6 digits.';
        $messageClass = 'danger';
    } elseif ($pickupName === '') {
        $message = 'Pickup name required.';
        $messageClass = 'danger';
    } else {
        $stmt = $mysqli->prepare(
            'SELECT kc.checkinID, k.childID, k.fullName, k.pickupAuthorisedNames '
            . 'FROM tblKidCheckins kc JOIN tblKidProfiles k ON k.childID = kc.childID '
            . 'WHERE k.siteID = ? AND kc.badgeCode = ? AND kc.checkedOutAt IS NULL'
        );
        $stmt->bind_param('is', $siteId, $badge);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        if ($row === null) {
            $message = 'No active check-in with that badge code.';
            $messageClass = 'danger';
        } else {
            // 🛡️ If pickupAuthorisedNames is set, verify the typed name is one
            //     of the comma-separated allowed names (case-insensitive,
            //     trimmed). Empty allow-list = any name accepted.
            $authorised = (string) ($row['pickupAuthorisedNames'] ?? '');
            $ok = ($authorised === '');
            if ($ok === false) {
                $allowed = array_map(static fn($s) => mb_strtolower(trim($s)), explode(',', $authorised));
                if (in_array(mb_strtolower($pickupName), $allowed, true) === true) {
                    $ok = true;
                }
            }
            if ($ok === false) {
                $message = '"' . htmlspecialchars($pickupName, ENT_QUOTES, 'UTF-8') . '" is NOT on the authorised pickup list for ' . htmlspecialchars((string) $row['fullName'], ENT_QUOTES, 'UTF-8') . '. Refer to a leader.';
                $messageClass = 'danger';
                Logger::activity('KidCheckoutBlocked', 'Child #' . $row['childID'] . ' tried by "' . $pickupName . '"');
            } else {
                $stmt = $mysqli->prepare('UPDATE tblKidCheckins SET checkedOutAt = NOW(), checkedOutByID = ?, pickupName = ? WHERE checkinID = ?');
                $stmt->bind_param('isi', $staffId, $pickupName, $row['checkinID']);
                $stmt->execute();
                $stmt->close();
                Logger::activity('KidCheckedOut', 'Child #' . $row['childID'] . ' to "' . $pickupName . '"');
                $message = htmlspecialchars((string) $row['fullName'], ENT_QUOTES, 'UTF-8') . ' checked out to ' . htmlspecialchars($pickupName, ENT_QUOTES, 'UTF-8') . '.';
                $messageClass = 'success';
            }
        }
    }
}

$pageTitle = 'Kids Check-Out';
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:480px;">
    <h1 class="h4 mb-3"><i class="fa-solid fa-children me-2 text-primary"></i>Kids Check-Out</h1>

    <?php if ($message !== null): ?>
        <div class="alert alert-<?php echo $messageClass; ?>"><?php echo $message; ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <div class="mb-3">
            <label class="form-label">Badge code (6 digits)</label>
            <input type="text" name="badgeCode" required pattern="\d{6}" inputmode="numeric" maxlength="6" autofocus class="form-control form-control-lg text-center" style="font-family:monospace; letter-spacing:.3em; font-size:1.8em;">
        </div>
        <div class="mb-3">
            <label class="form-label">Pickup person's name</label>
            <input type="text" name="pickupName" required maxlength="120" class="form-control form-control-lg">
        </div>
        <button class="btn btn-success btn-lg w-100"><i class="fa-solid fa-door-open me-1"></i>Check out</button>
    </form>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
