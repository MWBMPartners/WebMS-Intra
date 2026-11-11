<?php
// _apps/kids/checkout.php — Staff terminal: scan/enter badge → check out (#298)
declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Capabilities;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

// 🛡️ Safeguarding gate (#298 gap fix) — the checkout terminal resolves
//     ANY badge code at the site to a child's full name + authorised
//     pickup list, and releases the child on a typed name match, so it
//     must be staff-only. The same capability checkin.php asks (#727).
if (App::isAdmin() === false && App::can(Capabilities::KIDS_CHECKIN) === false) {
    Router::renderError(403);
    return;
}

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
                // 🛡️ The name typed for the adult who tried to collect the child
                //    stays READABLE here, on purpose (#933, the D-137 reasoning
                //    applied to a safeguarding record): a refused collection is
                //    exactly what a children's worker or the safeguarding lead
                //    must be able to look into afterwards, and the name is the
                //    one thing that says who it was. A refused attempt is not
                //    stored anywhere else — the check-in row records only the
                //    adult who DID collect — so this line is the only record.
                //    The cost, knowingly: that person usually has no account
                //    here, so no erasure can reach their name in these words.
                //    (A collection that went ahead is recorded by numbers only,
                //    below: its name is on the check-in row.)
                Logger::activity('KidCheckoutBlocked', 'Child #' . $row['childID'] . ' tried by "' . $pickupName . '"');
            } else {
                // 🧾 Who checked the child OUT is written by name as well as
                //    by number (#703, D-111, migration 272): an erasure keeps
                //    it and empties the helper's own account, so the number
                //    alone would point at "[Deleted User]". Read from the
                //    account in the same statement, never from the session.
                $stmt = $mysqli->prepare(
                    'UPDATE `tblKidCheckins` SET `checkedOutAt` = NOW(), `checkedOutByID` = ?, '
                    . '`checkedOutByName` = (SELECT `u`.`fullName` FROM `tblUsers` `u` WHERE `u`.`userID` = ?), '
                    . '`pickupName` = ? WHERE `checkinID` = ?'
                );
                $checkinId = (int) $row['checkinID'];
                $stmt->bind_param('iisi', $staffId, $staffId, $pickupName, $checkinId);
                $stmt->execute();
                $stmt->close();
                // #933: numbers only; the collecting adult's name is on the
                //    check-in row (`pickupName`), not in the log's words.
                Logger::activity('KidCheckedOut', 'Child #' . $row['childID'] . ' checked out (check-in #' . $checkinId . ')');
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
