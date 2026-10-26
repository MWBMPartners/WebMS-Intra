<?php
// Path: web/_apps/auth/2fa/setup.php
/**
 * -----------------------------------------------------------------------------
 * Auth — Two-Factor Authentication: switching it on, and backup codes 🔐
 * -----------------------------------------------------------------------------
 * A signed-in person does two things here, both for their OWN account only:
 *
 *   1. Switches two-factor authentication on. The page shows a key for their
 *      authenticator app, as a QR picture to scan and as letters to type; they
 *      type the 6-digit code the app shows; the page switches it on and shows
 *      eight backup codes.
 *   2. Makes a new set of backup codes (the form is on the Account page and
 *      posts here with action=new_backup_codes). The old set stops working.
 *
 * A backup code signs a person in once, in place of the code from their
 * authenticator app. It is the way back in when a phone is lost.
 *
 * WHAT WAS WRONG (#613, found 13 October 2026 by running the pages)
 * ----------------------------------------------------------------
 * 1. The codes were never shown. This page made them, switched two-factor
 *    authentication on, and sent the person to itself with "?complete=1",
 *    where the codes were to be drawn. But the first thing the page did, on
 *    every request, was ask "is it already on?" and send the person to
 *    Account if so. By then it was. Nobody ever saw their codes, so a lost
 *    phone locked them out until an administrator stepped in.
 * 2. The codes were written into the activity log in readable text. They
 *    waited in the session for the page that never drew them, and
 *    Logger::activity() stores a copy of the whole session with every record
 *    it writes. The "TotpEnabled" record was written straight after the codes
 *    went into the session, so it carried all eight.
 * 3. The messages this page set ("Invalid code. Please try again.") were
 *    never drawn. They stayed in the session and came up later on some other
 *    page that had nothing to do with them.
 *
 * HOW THE CODES ARE SHOWN NOW
 * ---------------------------
 * In the answer to the very request that made them, and nowhere else. They
 * are never put in the session, never stored in readable form, and never
 * logged. That request has just proved who is asking: it carried a right code
 * from the authenticator app. The database keeps only a one-way fingerprint
 * of each code (password_hash), so the portal itself cannot show them again.
 * Opening this page a second time shows no codes, whatever is in the address.
 *
 * TRIED AND REJECTED: keeping the codes in the session and drawing them on
 * the next request (what the old page meant to do). Anybody holding the
 * session's cookie could then collect the codes with one plain request and no
 * proof of the second step, and every record written to the activity log in
 * between would carry them. Encrypting them in the session would have hidden
 * them from the log but not from the holder of the cookie.
 *
 * THE PICTURE FOR THE AUTHENTICATOR APP (#623, 14 October 2026)
 * ------------------------------------------------------------
 * The page said "Scan this QR code" above a picture that could never appear.
 * It asked another website (chart.googleapis.com) to draw it. The portal's own
 * rule for pages (`img-src 'self' data:`) refuses a picture from another
 * website, so the browser never asked. Had the rule allowed it, every
 * person's secret key would have been sent to that website inside the
 * picture's address. So the only way to set up an app was to type the 32
 * letters of the key by hand.
 *
 * Now the portal draws the picture itself (`Portal\Core\Qr`, the one place a
 * QR picture is made) and writes it INTO this page as a `data:` address. The
 * key therefore travels in this page and nowhere else. Under the picture the
 * key is shown in groups of four letters, for a person who cannot scan (for
 * one, somebody setting up the app on the same phone they are reading this
 * on).
 *
 * TRIED AND REJECTED: `<img src="/qr?content=...">`, the way other pages show
 * a QR picture. The key would then be in a web address, and web addresses are
 * written into server logs and browser histories. Also rejected: allowing the
 * outside website in the page's rule. That is the leak described above.
 *
 * THE KEY MUST NEVER BE: in a web address; in a log; in the session in
 * readable form (see below); in the picture's alt text; or handed to
 * `Qr::resolveContent()`, which can send what it is given to an outside
 * service.
 *
 * WHAT THIS CANNOT DO
 * -------------------
 * - It cannot make an authenticator app accept the picture. The picture is
 *   read back by a test (`tools/two-step-selftest.php`), but no test here
 *   holds a phone up to a screen.
 * - It cannot show a set of codes twice. If the answer is lost on the way
 *   (a dropped connection, a second click on the button), the codes are
 *   made and nobody has seen them. The person makes a new set from Account.
 * - It cannot stop somebody who is already signed in as the person AND holds
 *   their authenticator app. That person can switch everything off as well.
 * - Codes made before this fix were never shown to anybody, and a copy of
 *   each is still in old activity-log records. Nothing here removes those;
 *   making a new set stops the old ones working.
 *
 * @package   Portal\Auth
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.10.2
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/92
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/613
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/623
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Qr;
use Portal\Core\RateLimiter;
use Portal\Core\Site;
use Portal\Core\Totp;

/**
 * 🔑 Replace one person's backup codes with eight new ones.
 *
 * The old set is deleted and the new one stored, as fingerprints only. The
 * caller must have opened a transaction and must commit it: a set that is
 * half stored would leave the person with codes on paper that do not work.
 *
 * It throws when the database refuses anything (bootstrap.php makes every
 * database failure an exception). The caller rolls back.
 *
 * HOW THE OLD SET IS REMOVED, AND WHY NOT THE SHORT WAY
 * The short way is one statement: "DELETE … WHERE userID = ?". That is what
 * this did at first, and it refused people for no fault of theirs. When the
 * person has no rows (everybody, the first time), that statement makes the
 * database hold the empty stretch of the index where their new rows will go.
 * A second person doing the same at the same moment holds the same stretch.
 * Each then waits for the other before it can add a row, and the database
 * stops one of them (a "deadlock"). Found by the independent check: two
 * different people at the same moment, one refused, six rounds of six.
 * So, in this order:
 *   1. The person's OWN account row is locked. Two requests for one person
 *      (two windows, a second press) therefore take turns. Other people's
 *      requests do not touch that row, so they are not held up by it.
 *   2. Their old rows are found with a plain read, which holds nothing.
 *   3. Each old row is removed by its own number, which holds that row only.
 *
 * TRIED AND REJECTED: catching the deadlock and trying again. The second try
 * met the same hold, because the other request was still working out its
 * eight fingerprints: 8 of 12 got through (the independent check ran it).
 *
 * THE CALLER MUST NOT READ ANYTHING inside its transaction before calling
 * this. The plain read in step 2 sees the database as it was at the
 * transaction's FIRST plain read. If that happened before step 1 had waited
 * its turn, the read would miss a set stored meanwhile by the person's other
 * window, and that set would be left behind beside the new one.
 *
 * WHAT IT CANNOT DO: tell the person's other window that its codes have just
 * been replaced. Of two windows that ask at the same moment, both are shown
 * eight codes and only the later set is stored.
 *
 * @param \mysqli $db     The portal's connection, handed in by the page.
 * @param int     $userId The signed-in person. Never a number from the form.
 *
 * @return list<string> The eight codes in readable form, for showing ONCE in
 *                      this answer. Nothing else may keep them.
 */
function twoStepReplaceBackupCodes(\mysqli $db, int $userId): array
{
    $codes = Totp::generateBackupCodes(8);

    // 1. The person's own row. (When switching on, the caller's UPDATE of
    //    this row already holds it; asking again costs nothing.)
    $own = $db->prepare('SELECT userID FROM tblUsers WHERE userID = ? FOR UPDATE');
    $own->bind_param('i', $userId);
    $own->execute();
    $own->get_result();
    $own->close();

    // 2. Their old rows, by a plain read.
    $find = $db->prepare('SELECT codeID FROM tblTotpBackupCodes WHERE userID = ?');
    $find->bind_param('i', $userId);
    $find->execute();
    $oldRows = array_column($find->get_result()->fetch_all(MYSQLI_ASSOC), 'codeID');
    $find->close();

    // 3. Each one by its own number. (The account number is in the statement
    //    as well so that it can never remove another person's row.)
    $remove = $db->prepare('DELETE FROM tblTotpBackupCodes WHERE codeID = ? AND userID = ?');
    foreach ($oldRows as $oldRow) {
        $oldId = (int) $oldRow;
        $remove->bind_param('ii', $oldId, $userId);
        $remove->execute();
    }
    $remove->close();

    $store = $db->prepare('INSERT INTO tblTotpBackupCodes (userID, codeHash) VALUES (?, ?)');
    foreach ($codes as $code) {
        $hash = password_hash($code, PASSWORD_BCRYPT);
        $store->bind_param('is', $userId, $hash);
        $store->execute();
    }
    $store->close();

    return $codes;
}

Auth::ensureSession();
Auth::requireLogin();

$userId = (int) ($_SESSION['user_id'] ?? 0);

// 🧹 Before #613 this page left the readable codes in the session under this
//    name, and nothing ever took them out. A session that began before the
//    upgrade may still hold them, and every activity record written from it
//    would carry them. Nothing reads the name any more.
unset($_SESSION['totp_backup_codes']);

// 🔍 The person's own row: is it on, and the stored key (still encrypted).
$userStmt = $mysqli->prepare('SELECT totpEnabled, totpSecret, emailAddress FROM tblUsers WHERE userID = ? LIMIT 1');
if ($userStmt === false) {
    header('Location: ' . Site::url('account') . '?error=db');
    exit();
}
$userStmt->bind_param('i', $userId);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

$twoStepOn = ((int) ($user['totpEnabled'] ?? 0) === 1);

// 📋 The codes to draw in THIS answer. They live in this one variable and in
//    the page sent back, and nowhere else.
$shownCodes  = [];
$codesAreNew = false; // true: a new set replaced an old one; false: just switched on

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (is_string($_POST['action'] ?? null) === true) ? $_POST['action'] : '';

    if ($action === 'new_backup_codes') {
        // =====================================================================
        // 🔑 A new set of backup codes, asked for from the Account page
        // =====================================================================
        // Every refusal goes back to Account with "?error=…": that is where
        // the form is, and Account draws its messages from the address (the
        // same way disable.php answers).
        if (Auth::verifyCsrf((is_string($_POST['csrf_token'] ?? null) === true) ? $_POST['csrf_token'] : '') === false) {
            // "totp_form", not the general "csrf": the usual way to arrive
            // here is a second press of the button. The first press has
            // already made a set and used up the form, and the browser
            // shows THIS answer, so the person has new codes they never
            // saw. Account's message for "totp_form" tells them so and
            // what to do; "Invalid session token" told them nothing.
            header('Location: ' . Site::url('account') . '?error=totp_form');
            exit();
        }
        if ($twoStepOn === false) {
            header('Location: ' . Site::url('account') . '?error=totp_off');
            exit();
        }

        // 🚦 Five wrong codes in fifteen minutes, counted for the account.
        //    The form token only proves the form came from this browser. It
        //    does not prove the person at the keyboard is the account's
        //    owner (an unlocked computer, a stolen cookie). Without a limit
        //    such a visitor could try all million codes here, and backup
        //    codes are a way past the second step for good. The count is for
        //    the account alone, not the visitor's address, because the
        //    address comes from headers a visitor can change.
        //    It is kept apart from the sign-in page's own count, so wrong
        //    codes typed here cannot block the person's next sign-in.
        $limitBucket = 'totp_newcodes:' . $userId;
        $limitTries  = 5;
        $limitWindow = 900;
        if (RateLimiter::tooMany($limitBucket, $limitTries, $limitWindow) === true) {
            header('Location: ' . Site::url('account') . '?error=totp_wait');
            exit();
        }

        // 🔐 The proof: a code the authenticator app shows NOW.
        //    Why not the password, as disable.php asks: an account that
        //    signs in through Microsoft or Google has no password here, and
        //    it needs backup codes as much as any other. Everybody with
        //    two-factor authentication on has an authenticator.
        $typed  = (is_string($_POST['code'] ?? null) === true) ? preg_replace('/\s+/', '', $_POST['code']) : '';
        $stored = Auth::decrypt((string) ($user['totpSecret'] ?? ''));
        // Six digits and nothing else is tested first, so nothing odd ever
        // reaches the comparison. An empty $stored means the key could not
        // be read back; no code can be right then.
        if ($stored === '' || preg_match('/^[0-9]{6}$/', (string) $typed) !== 1 || Totp::verify($stored, (string) $typed) === false) {
            RateLimiter::recordHit($limitBucket, $limitWindow);
            Logger::activity('TotpBackupCodesFailed', 'New backup codes refused: the authenticator code was not right', $userId);
            header('Location: ' . Site::url('account') . '?error=totp_code');
            exit();
        }

        try {
            $mysqli->begin_transaction();
            $shownCodes = twoStepReplaceBackupCodes($mysqli, $userId);
            $mysqli->commit();
        } catch (\Throwable $problem) {
            // Rolled back, so the old set is still there and still works.
            try {
                $mysqli->rollback();
            } catch (\Throwable $ignored) {
                // The connection is gone; the database rolls back by itself.
            }
            // The class of the fault only: a database message can quote values.
            error_log('auth/2fa/setup: new backup codes could not be stored for account ' . $userId . ' (' . get_class($problem) . ')');
            header('Location: ' . Site::url('account') . '?error=totp_codes_failed');
            exit();
        }

        // 📝 Written with no code in the session, so the record's copy of the
        //    session cannot carry one.
        Logger::activity('TotpBackupCodesMade', 'Made a new set of backup codes. The old set no longer works.', $userId);
        $codesAreNew = true;
    } else {
        // =====================================================================
        // 📱 Switching two-factor authentication on
        // =====================================================================
        // Already on: nothing to do. This is asked BEFORE the form token on
        // purpose. A second click on "Enable" arrives after the first has
        // switched it on and used up the token; answering "invalid form"
        // there would leave a message about a fault the person did not make.
        // Nothing is changed on this path, so no token is needed for it.
        if ($twoStepOn === true) {
            header('Location: ' . Site::url('account') . '?totp_on=1');
            exit();
        }
        if (Auth::verifyCsrf((is_string($_POST['csrf_token'] ?? null) === true) ? $_POST['csrf_token'] : '') === false) {
            $_SESSION['flash_msg']  = 'That form had expired. Please enter the code again.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . Site::url('auth/2fa/setup'));
            exit();
        }

        // The key this page showed, read back out of the session (see below
        // for why it is kept encrypted there).
        $pending = (is_string($_SESSION['totp_setup_secret'] ?? null) === true) ? Auth::decrypt($_SESSION['totp_setup_secret']) : '';
        $typed   = (is_string($_POST['code'] ?? null) === true) ? preg_replace('/\s+/', '', $_POST['code']) : '';

        if ($pending === '') {
            // No key is waiting (the session ended, or the key was one the
            // old code left). The page is about to make a NEW key, so the
            // one already in the person's app is no use: say so, or every
            // code they type from it will be "not right" with no reason given.
            $_SESSION['flash_msg']  = 'This page had been open too long, so it has made a new key. Add the key below to your authenticator app, then enter the code it shows.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: ' . Site::url('auth/2fa/setup'));
            exit();
        }
        if ((string) $typed === '') {
            $_SESSION['flash_msg']  = 'Please enter the 6-digit code from your authenticator app.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . Site::url('auth/2fa/setup'));
            exit();
        }
        if (preg_match('/^[0-9]{6}$/', (string) $typed) !== 1 || Totp::verify($pending, (string) $typed) === false) {
            $_SESSION['flash_msg']  = 'That code is not right. Enter the 6-digit code your authenticator app shows now.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . Site::url('auth/2fa/setup'));
            exit();
        }

        // 📋 Switch it on AND store the codes, both or neither.
        //    This used to be two separate writes with nothing checked: if the
        //    codes failed to store, two-factor authentication was on with no
        //    way back in, and the page still said "Save your backup codes".
        //    "AND totpEnabled = 0" lets only ONE request switch it on, so two
        //    requests at the same moment cannot each make a set.
        try {
            $mysqli->begin_transaction();
            $encSecret = Auth::encrypt($pending);
            $switchOn  = $mysqli->prepare('UPDATE tblUsers SET totpSecret = ?, totpEnabled = 1 WHERE userID = ? AND totpEnabled = 0');
            $switchOn->bind_param('si', $encSecret, $userId);
            $switchOn->execute();
            $changed = $switchOn->affected_rows;
            $switchOn->close();
            if ($changed !== 1) {
                // Another request switched it on a moment ago. That request
                // made the codes and has the page that shows them; this one
                // changes nothing and makes none. No message is left in the
                // session: Account does not draw those, and "could not be
                // switched on" would be untrue.
                $mysqli->rollback();
                header('Location: ' . Site::url('account') . '?totp_on=1');
                exit();
            }
            $shownCodes = twoStepReplaceBackupCodes($mysqli, $userId);
            $mysqli->commit();
        } catch (\Throwable $problem) {
            try {
                $mysqli->rollback();
            } catch (\Throwable $ignored) {
                // The connection is gone; the database rolls back by itself.
            }
            error_log('auth/2fa/setup: two-factor authentication could not be switched on for account ' . $userId . ' (' . get_class($problem) . ')');
            $_SESSION['flash_msg']  = 'Two-factor authentication could not be switched on. Nothing was changed. Please try again.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . Site::url('auth/2fa/setup'));
            exit();
        }

        // 🧹 The key is in the database now. Take it out of the session
        //    BEFORE the record is written, so the record cannot carry it.
        unset($_SESSION['totp_setup_secret']);
        Logger::activity('TotpEnabled', 'Enabled two-factor authentication', $userId);
    }
}

$secret       = '';
$secretGroups = []; // the key in groups of four letters, for the page
$qrPicture    = ''; // the QR picture as a "data:" address, or '' when it could not be drawn
if ($shownCodes === []) {
    // 🔍 Already on, and nothing to show: back to Account. This is every
    //    plain visit to this page once it is on, with or without
    //    "?complete=1" in the address. That part of the address means
    //    nothing now; it never proved anything.
    if ($twoStepOn === true) {
        header('Location: ' . Site::url('account') . '?totp_on=1');
        exit();
    }

    // 📋 The key for the authenticator app, kept for this person until they
    //    confirm it.
    //
    //    It is kept in the session ENCRYPTED (the same way the database keeps
    //    it). It used to be kept as it is shown. Logger::activity() copies
    //    the session into every record it writes, so anything the person did
    //    in another tab while this page was open wrote their key into the
    //    activity log in readable text, and that key then became the one
    //    their account depends on. A key left by the old code does not
    //    decrypt, so it is thrown away here and a new one made.
    $secret = (is_string($_SESSION['totp_setup_secret'] ?? null) === true) ? Auth::decrypt($_SESSION['totp_setup_secret']) : '';
    if ($secret === '') {
        $secret = Totp::generateSecret();
        $_SESSION['totp_setup_secret'] = Auth::encrypt($secret);
    }

    $issuer = App::settings('auth.totpIssuer') ?? 'WebMS Portal';
    $email  = $user['emailAddress'] ?? '';
    $uri    = Totp::getUri($secret, $email, $issuer);

    // 🔳 The picture an authenticator app scans. It holds $uri, which holds
    //    the key, so it is drawn HERE and written into this page as a "data:"
    //    address (see the header for why never an address such as /qr?...).
    //    $uri is used on this one line and is never logged, kept or shown.
    //    Level M is the usual level: a screen has no scratches to correct.
    try {
        $qrPicture = Qr::dataUri($uri, ['size' => 250, 'ecc' => 'M']);
    } catch (\Throwable $problem) {
        // The address was too long to draw (an organisation name of over two
        // thousand letters), or the drawing failed. The page still works: it
        // shows the key to type, and says there is no picture. Only the kind
        // of fault is logged, because its words could quote the address.
        $qrPicture = '';
        error_log('auth/2fa/setup: the QR picture could not be drawn for account ' . $userId . ' (' . get_class($problem) . ')');
    }
    $secretGroups = str_split($secret, 4);

    // 🚫 The browser must not keep this page either: it shows the key the
    //    person's sign-in will depend on. PHP usually says this by itself for
    //    a signed-in page, but a host can switch that off.
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
}

// 📨 A message this page left for itself before sending the person back
//    here. Taken out of the session as it is read, so it shows once.
$noticeText = (is_string($_SESSION['flash_msg'] ?? null) === true) ? $_SESSION['flash_msg'] : '';
$noticeKind = (is_string($_SESSION['flash_type'] ?? null) === true) ? $_SESSION['flash_type'] : 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
if (in_array($noticeKind, ['success', 'danger', 'warning', 'info'], true) === false) {
    $noticeKind = 'info';
}

// 📌 Page metadata
$pageTitle   = ($shownCodes !== []) ? 'Your Backup Codes' : 'Set Up Two-Factor Authentication';
$pageSection = 'account';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Account' => Site::url('account'), 'Two-Factor Authentication' => ''];

if ($shownCodes !== []) {
    // 🚫 The browser must not keep this page: "Back" or a reopened tab would
    //    show the codes again from its own store, to whoever sits there next.
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
}

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 🔐 Two-factor authentication -->
<h1 class="mb-4"><i class="fa-solid fa-shield-halved me-2" aria-hidden="true"></i>Two-Factor Authentication</h1>

<?php if ($shownCodes !== []): ?>
    <!-- ✅ The backup codes, shown once -->
    <div class="alert alert-success" role="status">
        <i class="fa-solid fa-check-circle me-2" aria-hidden="true"></i>
        <?php if ($codesAreNew === true): ?>
            Your new backup codes are ready. Your old codes no longer work.
        <?php else: ?>
            Two-factor authentication is now on.
        <?php endif; ?>
    </div>

    <div class="card mb-4">
        <div class="card-header bg-warning text-dark">
            <h2 class="h5 mb-0"><i class="fa-solid fa-key me-2" aria-hidden="true"></i>Your backup codes: save them now</h2>
        </div>
        <div class="card-body">
            <p class="fw-bold">This is the only time these codes are shown.</p>
            <p>
                Each code signs you in once, in place of the code from your authenticator app.
                Use one if you lose your phone or cannot open the app.
                Write the codes down or print this page, and keep them somewhere safe, apart from your phone.
            </p>
            <ul class="list-unstyled row g-2 mb-3">
                <?php foreach ($shownCodes as $bc): ?>
                    <li class="col-6 col-md-3">
                        <code class="d-block p-2 bg-body-tertiary text-center rounded fs-5 user-select-all"><?php echo htmlspecialchars($bc, ENT_QUOTES, 'UTF-8'); ?></code>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="small text-muted mb-0">
                If you lose them, make a new set from your Account page.
                That needs a code from your authenticator app, so do it while you still have your phone.
            </p>
        </div>
    </div>

    <a href="<?php echo htmlspecialchars(Site::url('account'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary">
        <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>Back to Account
    </a>

<?php else: ?>
    <?php if ($noticeText !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($noticeKind, ENT_QUOTES, 'UTF-8'); ?>" role="alert">
            <?php echo htmlspecialchars($noticeText, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <!-- 📱 Setup Steps -->
    <div class="row g-4">
        <div class="col-12 col-md-6">
            <div class="card">
                <div class="card-header"><h2 class="h5 mb-0">Step 1: Add the key to your authenticator app</h2></div>
                <div class="card-body text-center">
                    <?php if ($qrPicture !== ''): ?>
                        <p>Scan this picture with your authenticator app (Google Authenticator, Microsoft Authenticator, Authy and others):</p>
                        <?php // The picture is drawn by the portal and written into the page (a "data:" address), so
                              // the key inside it goes nowhere else. The alt text says what the picture is FOR and
                              // must never hold the key: alt text is read aloud and copied about freely. ?>
                        <img src="<?php echo htmlspecialchars($qrPicture, ENT_QUOTES, 'UTF-8'); ?>"
                             alt="QR code to scan with your authenticator app" class="img-fluid mb-3" width="250" height="250">
                        <p class="small text-muted">Cannot scan it? Type this key into the app instead:</p>
                    <?php else: ?>
                        <p>The picture to scan could not be drawn, so type this key into your authenticator app (Google Authenticator, Microsoft Authenticator, Authy and others):</p>
                    <?php endif; ?>
                    <?php // The key in groups of four, so that 32 letters can be typed without losing the place.
                          // The gap between groups is a margin, NOT a space character, so a key that is selected
                          // and copied is the 32 letters and nothing else, ready to paste into an app. The <wbr>
                          // after each group lets a narrow screen break the line there; it adds no character.
                          // NOT RUN IN A BROWSER: the page's text was read by the test, not looked at. ?>
                    <code class="d-block p-2 bg-body-tertiary rounded user-select-all" id="totpKey"><?php
                        foreach ($secretGroups as $group) {
                            echo '<span class="mx-1">' . htmlspecialchars($group, ENT_QUOTES, 'UTF-8') . '</span><wbr>';
                        }
                    ?></code>
                    <p class="small text-muted mt-2 mb-0">The key is 32 letters and digits, shown here in groups of four.</p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="card">
                <div class="card-header"><h2 class="h5 mb-0">Step 2: Verify Code</h2></div>
                <div class="card-body">
                    <p>Enter the 6-digit code from your authenticator app to confirm setup:</p>
                    <form method="post" action="<?php echo htmlspecialchars(Site::url('auth/2fa/setup'), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="mb-3">
                            <label for="code" class="form-label">Verification Code</label>
                            <input type="text" class="form-control form-control-lg text-center" id="code" name="code"
                                   maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fa-solid fa-check me-1" aria-hidden="true"></i>Enable 2FA
                        </button>
                    </form>
                    <p class="small text-muted mt-3 mb-0">
                        When it is on, this page shows you eight backup codes, once.
                        Have a pen or a printer ready.
                    </p>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
