<?php
// Path: _apps/admin/integrations/email.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Email Deliverability 📧
 * -----------------------------------------------------------------------------
 * The e-mail page: which route the portal sends through and whether it is
 * ready, the choice of route, the SMTP mailbox's settings, "Send test", the
 * mail queue, the ways past a failed certificate check, the sender domain's
 * DNS records, and the last ten sends.
 *
 * WHO MAY DO WHAT (#592, 12 October 2026; the queue's seven, 13 October)
 * ---------------------------------------------------------------------
 * Any administrator may LOOK at this page. Everything it can CHANGE or DO is
 * for a global administrator only: saving the SMTP settings, choosing the
 * route, sending a test, and the seven actions of the mail queue and the
 * certificate (`_mail-queue.php`, beside this file). All of them act on the
 * whole installation (one mail route serves every organisation). Anybody
 * else who posts a form here is refused, is told why, and the refusal is
 * written to the activity log. The forms are also not shown to them, but it
 * is the refusal that enforces the rule.
 * THE ONE CHAIN BELOW (the form's token, then "is it one of this page's
 * actions", then "is this a global administrator") is the only place those
 * three are asked, for every action alike. Do not add an action beside it.
 *
 * Before this, "Send test" was open to an administrator of one organisation.
 *
 * WHAT AN ORGANISATION'S ADMINISTRATOR IS NOT SHOWN (#592, point 4): the
 * mail server's own words about a message that did not go (they can name
 * another person's address, or the mailbox), the mailbox's user name, the
 * certificate's details, and any button. They see that a message is
 * waiting, held or not sent, in the page's own words.
 *
 * THE ONE PLACE FOR THE SERVER'S NAME AND THE CERTIFICATE SETTING
 * -----------------------------------------------------------------
 * The mail server's name and the two certificate rows are changed HERE and
 * nowhere else: the general Settings page refuses them, for everybody
 * (MailRoutes::lockedInGeneralEditor()). A changed name or port needs the
 * password typed again and puts certificate checking back on.
 *
 * THE MAILBOX'S PASSWORD
 * ----------------------
 * It is typed here and handed to MailRoutes::saveSmtp(), which stores it
 * encrypted. (This used to say "here and nowhere else". Since #597 was fixed
 * the general Settings page stores a secret encrypted too, so a password typed
 * there is used as well; only this page can test it.) It is NEVER written
 * back into this page: the box is
 * always empty, and the page says only whether one is stored. Leaving the box
 * empty keeps the stored one. Do not add `value="…"` to that box.
 *
 * WHAT THIS PAGE CANNOT DO
 * ------------------------
 * - It cannot show that a message is DELIVERED. "Send test" shows that the
 *   mail service accepted it, which is as far as the portal can see.
 * - The certificate notice shows what the last attempt to reach the mail
 *   server found (the record MailRoutes keeps: see
 *   MailRoutes::certificateProblem()). Since the mail queue (#592, part 3)
 *   it goes when the certificate passes the check on a later attempt, or
 *   when the mail settings are changed; not at the moment the certificate
 *   is put right. A message stopped before the mail server is tried (the
 *   hourly limit, a missing setting) leaves it showing. (It used to go
 *   after any attempt that ended another way, because it was read from the
 *   newest line of the send log.)
 * - Tell whether a certificate that failed the check is the mail server's
 *   own: see `_mail-queue.php`, which shows it and offers the three ways
 *   past the check.
 * - The hourly limit is not exact for mail sent straight from a page:
 *   messages sent at the same moment are each counted before the others
 *   are recorded, so together they can pass it. The form says so.
 * - The Microsoft 365 and Google settings are not typed here; the notice at
 *   the top says where they are.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.3.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/234
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/592
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Mailer;
use Portal\Core\MailQueue;
use Portal\Core\MailRoutes;
use Portal\Core\Router;
use Portal\Core\Site;

// 📬 The mail queue's part of this page: its seven actions, and how it is drawn.
require_once __DIR__ . DIRECTORY_SEPARATOR . '_mail-queue.php';

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$isGlobalAdmin = App::isRootAdmin();
$selfUrl       = Router::url('admin/integrations/email');
$settings      = App::settings();

// 📋 A message left by the request before this one (a save redirects here).
$flash     = (string) ($_SESSION['flash_msg'] ?? '');
$flashType = (string) ($_SESSION['flash_type'] ?? 'info');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

/** @var list<string> $formErrors What was wrong with the SMTP form, when a save was refused. */
$formErrors = [];
/** @var array<string, string>|null $typed What was typed into the SMTP form, shown again after a refused save. Never the password. */
$typed = null;
/** @var array<string, mixed>|null $testResult What an SMTP "Send test" came to. Shown once; never stored. */
$testResult = null;

// =============================================================================
// 📨 The three things this page can do
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // The actions, and what each is called in a refusal. A closed list, so
    // nothing a visitor posts is ever written into the activity log.
    $actions = [
        'save_smtp' => 'saving the SMTP settings',
        'set_route' => 'choosing the mail route',
        'test_send' => 'sending a test message',
    ] + mail_queue_page_actions();
    $action = is_string($_POST['action'] ?? null) === true ? $_POST['action'] : '';
    // Who is asking, for the activity log. Logger::activity() does not work
    // this out for itself: left out, the log says a mail setting changed and
    // not who changed it.
    $actorId = isset($_SESSION['user_id']) === true ? (int) $_SESSION['user_id'] : null;

    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $flash     = 'This form had expired, so nothing was changed. Please try again.';
        $flashType = 'danger';
    } elseif (isset($actions[$action]) === false) {
        $flash     = 'That is not something this page can do. Nothing was changed.';
        $flashType = 'danger';
    } elseif ($isGlobalAdmin === false) {
        // 🚧 After the form-token check, so a forged request from another
        //    website cannot fill the activity log with refusals.
        Logger::activity(
            'SettingsGroupSaveRefused',
            'Refused: ' . $actions[$action] . ' on the e-mail page is for a global administrator only',
            $actorId
        );
        $flash = 'The mail settings are portal-wide: they apply to every organisation on this installation, not only yours. '
            . 'Only a global administrator can change them or send a test. Nothing was changed.';
        $flashType = 'danger';
    } elseif (isset(mail_queue_page_actions()[$action]) === true) {
        // 📬 One of the mail queue's seven. Reached only past the three tests
        //    above. Redirected afterwards, so that pressing "reload" cannot
        //    send a message, or switch checking off, a second time.
        try {
            [$_SESSION['flash_msg'], $_SESSION['flash_type']] = mail_queue_page_act($mysqli, $action, $_POST, $actorId);
        } catch (\Throwable $e) {
            // The database went away part way, most likely. Said in words,
            // and honestly: this cannot know how far the action got.
            error_log('The e-mail page could not finish "' . $actions[$action] . '": ' . get_class($e) . ': ' . $e->getMessage());
            $_SESSION['flash_msg']  = 'Something went wrong on the server while ' . $actions[$action] . ', and it may not have been finished. '
                . 'Look at the mail queue and the notices on this page before you try again. The reason is in the server\'s error log.';
            $_SESSION['flash_type'] = 'danger';
        }
        header('Location: ' . $selfUrl, true, 302);
        exit();
    } elseif ($action === 'save_smtp') {
        $saved = MailRoutes::saveSmtp([
            'host'           => $_POST['smtpHost'] ?? '',
            'port'           => $_POST['smtpPort'] ?? '',
            'security'       => $_POST['smtpSecurity'] ?? '',
            'username'       => $_POST['smtpUsername'] ?? '',
            'password'       => $_POST['smtpPassword'] ?? '',
            'fromAddress'    => $_POST['fromAddress'] ?? '',
            'fromName'       => $_POST['fromName'] ?? '',
            'replyTo'        => $_POST['replyTo'] ?? '',
            'perHour'        => $_POST['perHour'] ?? '',
        ] + (isset($_POST['removePassword']) === true ? ['removePassword' => '1'] : []));

        if ($saved['errors'] === []) {
            $passwordNote = ['kept' => '', 'stored' => ' A new mailbox password was stored.', 'removed' => ' The stored mailbox password was removed.'][$saved['password']];
            // The activity log says THAT the password changed, never what it is.
            Logger::activity('MailSettingsSaved', 'The SMTP mail settings were saved.' . $passwordNote, $actorId);
            // 🪪 A new server name or port put certificate checking back on
            //    (see MailRoutes::saveSmtp()). Said, and logged as its own
            //    line, naming which of the two changed.
            $checkNote = '';
            if ($saved['checkingBackOn'] === true) {
                $changed = $saved['serverChange'] . ($saved['serverChange'] === 'name and port' ? ' were' : ' was');
                Logger::activity(MailRoutes::LOG_ON, 'Certificate checking for the mail server went back ON because the mail server\'s ' . $changed . ' changed.', $actorId);
                $checkNote = ' Certificate checking is back ON: it had been switched off, or one certificate accepted, for the server set before.';
            }
            $_SESSION['flash_msg']  = 'The SMTP settings were saved.' . $passwordNote . $checkNote . ' Use "Send test" to check that they work.';
            $_SESSION['flash_type'] = 'success';
            header('Location: ' . $selfUrl, true, 302);
            exit();
        }
        $formErrors = $saved['errors'];
        // Shown again so the administrator does not have to type it all
        // twice. The password is left out on purpose.
        $typed = [];
        foreach (['host' => 'smtpHost', 'port' => 'smtpPort', 'security' => 'smtpSecurity', 'username' => 'smtpUsername',
            'fromAddress' => 'fromAddress', 'fromName' => 'fromName', 'replyTo' => 'replyTo', 'perHour' => 'perHour'] as $name => $field) {
            $typed[$name] = is_string($_POST[$field] ?? null) === true ? trim($_POST[$field]) : '';
        }
    } elseif ($action === 'set_route') {
        $chosen = is_string($_POST['route'] ?? null) === true ? strtolower(trim($_POST['route'])) : '';
        $before = Mailer::provider();
        if (MailRoutes::saveChoice($chosen, array_keys(Mailer::ROUTES)) === true) {
            Logger::activity('MailRouteChanged', 'The mail route was changed from ' . $before . ' to ' . $chosen . '.', $actorId);
            // Redirected, because this request read its settings before the
            // change: only the next one can say whether the new route is ready.
            $_SESSION['flash_msg']  = 'E-mail is now set to go through ' . Mailer::ROUTES[$chosen] . '. The notice below says whether that route is ready.';
            $_SESSION['flash_type'] = 'success';
            header('Location: ' . $selfUrl, true, 302);
            exit();
        }
        $flash     = 'Choose one of the routes listed. Nothing was changed.';
        $flashType = 'danger';
    } else {
        // 🧪 Send test.
        $to       = is_string($_POST['to'] ?? null) === true ? trim($_POST['to']) : '';
        $siteName = trim((string) ($settings['site']['name'] ?? ''));
        $subject  = 'Test message' . ($siteName !== '' ? ' from ' . $siteName : '');
        $text     = 'This is a test message' . ($siteName !== '' ? ' from ' . $siteName : '') . '. If you are reading it, the portal can send e-mail. '
            . 'Nobody needs to reply.';
        // "smtp" tests the SMTP mailbox's settings even when another route is
        // in use, so they can be proved before the portal is switched over.
        $throughSmtp = ($_POST['through'] ?? '') === MailRoutes::SMTP || Mailer::provider() === MailRoutes::SMTP;

        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $flash     = 'Type the e-mail address the test message should go to.';
            $flashType = 'danger';
        } elseif ($throughSmtp === true) {
            $testResult = Mailer::testSmtp($to, $subject, $text) + ['to' => $to];
        } else {
            $routeName = Mailer::sendingRoute()['label'];
            try {
                $ok        = Mailer::send($to, $subject, $text);
                // ⏳ "true" is also the answer for a message that was only
                //    KEPT in the mail queue (the hourly limit has no room,
                //    say). The route did not accept that one, and this used
                //    to say it had.
                $waiting   = $ok === true && Mailer::lastIsWaiting() === true;
                if ($waiting === true) {
                    $flash = 'The test message was NOT sent: the portal kept it in the mail queue to send later. The reason is under "Recent sends" below, '
                        . 'and the health page says whether waiting mail is being sent.';
                } else {
                    $flash = $ok === true
                        ? $routeName . ' accepted a test message for ' . $to . '. That is not the same as delivered: check that it arrives.'
                        : 'The test message was not sent: ' . $routeName . ' did not accept it. The reason is under "Recent sends" below.';
                }
                $flashType = $waiting === true ? 'warning' : ($ok === true ? 'success' : 'danger');
            } catch (\Throwable $e) {
                // The Microsoft 365 route says what it lacks by throwing.
                $flash     = 'The test message was not sent: ' . $e->getMessage() . '. The notice below says what ' . $routeName . ' still needs.';
                $flashType = 'danger';
            }
        }
    }
}

// =============================================================================
// 🧭 What is true now
// =============================================================================
// Asked of Mailer, the one place that knows whether the portal can send
// (#580). It reads settings only, so "ready" is not proof that a message
// arrives; the notice says so and points at "Send test".
$mailRoute      = Mailer::sendingRoute();
$activeProvider = $mailRoute['route'];
$smtpInUse      = $activeProvider === MailRoutes::SMTP;
$stored         = MailRoutes::formValues();
$form           = $typed === null ? $stored : $typed + $stored;

// The address the route in use sends from. Shown only; the real choice is
// made in Mailer and MailRoutes.
if ($smtpInUse === true) {
    $senderEmail = $stored['fromAddress'];
} elseif ($activeProvider === 'google') {
    $senderEmail = (string) ($settings['mail']['google']['delegateUser'] ?? '');
} else {
    $sharedMailbox = trim((string) ($settings['mail']['ms365']['sharedMailbox'] ?? ''));
    $senderEmail   = $sharedMailbox !== '' ? $sharedMailbox : (string) ($settings['mail']['defaultFromAddress'] ?? '');
}
$senderDomain = '';
if ($senderEmail !== '' && str_contains($senderEmail, '@') === true) {
    $senderDomain = substr($senderEmail, strpos($senderEmail, '@') + 1);
}

// 🕰️ ONE time zone for EVERY moment this page shows (MailQueue::shown()
//    converts once, and names the zone beside the moment): the
//    organisation's, from Site::timezone(), the one place that answers it
//    (#604).
//
//    WHAT WAS HERE. The portal kept an organisation's zone in two places
//    that could differ. This page first showed the same moment twice, in
//    two zones; then it used the organisation's own row unless that was
//    UTC, and PHP's zone otherwise, which still left it disagreeing with the
//    admin dashboard and the health page for some installations. Migration
//    217 brought the two places into line and Site::timezone() answers for
//    all of them, so the three pages now tell a moment on the same clock.
$zone = Site::timezone();
/** A moment stored in UTC, as this page shows it: in that one zone, with the zone's short name beside it. */
$showMoment = static fn (string $utc): string => MailQueue::shown($utc, $zone);
// ⚠️ Certificate checking is off, or one certificate is accepted. Read from
//    what is STORED, on every load, for whoever opens the page, whatever
//    mail route is in use: see MailRoutes::standingWarning().
$standingWarning = MailRoutes::standingWarning($mysqli, $zone);

// 🪪 The certificate problem on record for the SMTP mailbox, WHATEVER ROUTE IS
//    IN USE. With SMTP in use it is what holds mail. With another route in use
//    it is what a "Send test" of the mailbox found: nothing is held for it,
//    and the notice below says so. (It used to be read only while SMTP was the
//    route in use. A test made before switching then failed with nothing on
//    the page to say why, and nothing to decide about it with.)
$certificateProblem = null;
// 📜 The last ten sends. A global administrator sees every organisation's,
//    because the route is the installation's. An administrator of one
//    organisation sees only that organisation's: the list names recipients
//    and subjects. (It used to show everybody's to both.)
$recentSends   = [];
$logUnreadable = false;
try {
    $certificateProblem = MailRoutes::certificateProblem($mysqli);
    $siteId = Site::id();
    $rsStmt = $mysqli->prepare(
        'SELECT provider, toRecipients, subject, status, errorCode, errorDetail, sentAt, sentAtUtc FROM tblEmailLog '
        . ($isGlobalAdmin === true ? '' : 'WHERE siteID = ? ')
        . 'ORDER BY emailLogID DESC LIMIT 10'
    );
    if ($isGlobalAdmin === false) {
        $rsStmt->bind_param('i', $siteId);
    }
    $rsStmt->execute();
    $rsResult = $rsStmt->get_result();
    while ($row = $rsResult->fetch_assoc()) {
        $recentSends[] = $row;
    }
    $rsStmt->close();
} catch (\Throwable $e) {
    // Most likely the portal's files are newer than its database (the two
    // columns of migration 213 are not there yet). Said on the page below.
    $logUnreadable = true;
    error_log('The e-mail page could not read the send log: ' . $e->getMessage());
}

// 🛡️ The sender domain's DNS records: SPF, DKIM and DMARC.
$dnsResults = [];
if ($senderDomain !== '') {
    /** The first TXT record at a name that starts with (or, for DKIM, holds) the marker; '' when there is none. */
    $txtRecord = static function (string $name, string $startsWith, string $orHolds = ''): string {
        $records = @dns_get_record($name, DNS_TXT);
        foreach (is_array($records) === true ? $records : [] as $record) {
            $value = (string) ($record['txt'] ?? '');
            if (stripos($value, $startsWith) === 0 || ($orHolds !== '' && stripos($value, $orHolds) !== false)) {
                return $value;
            }
        }

        return '';
    };
    $spf   = $txtRecord($senderDomain, 'v=spf1');
    $dmarc = $txtRecord('_dmarc.' . $senderDomain, 'v=DMARC1');
    // DKIM lives under a name the mail provider chooses. Two common ones are
    // tried; finding neither is not proof that there is none.
    $dkimFound = [];
    foreach (['default', 'selector1'] as $selector) {
        if ($txtRecord($selector . '._domainkey.' . $senderDomain, 'v=DKIM1', 'k=') !== '') {
            $dkimFound[] = $selector . ': present';
        }
    }
    $dnsResults = [
        'SPF'   => ['state' => $spf !== '' ? 'ok' : 'warn', 'value' => $spf !== '' ? $spf : 'Not found'],
        'DMARC' => ['state' => $dmarc !== '' ? 'ok' : 'warn', 'value' => $dmarc !== '' ? $dmarc : 'Not found'],
        'DKIM'  => [
            'state' => $dkimFound !== [] ? 'ok' : 'warn',
            'value' => $dkimFound !== [] ? implode(' · ', $dkimFound) : 'None found under the two usual names (default, selector1). Your mail provider may use another.',
        ],
    ];
}

// 🧪 The four steps of an SMTP test, worked out from what the mail library
//    recorded of the conversation, so a step is shown as done only when the
//    record shows it happening.
$testSteps    = [];
$testLastLine = '';
if ($testResult !== null && $testResult['steps'] !== []) {
    $recorded = static function (string $pattern) use ($testResult): bool {
        foreach ($testResult['steps'] as $line) {
            if (preg_match($pattern, (string) $line) === 1) {
                return true;
            }
        }

        return false;
    };
    // 🪪 How the certificate was treated for this test, said truthfully: a
    //    test sent while checking is off, or one certificate is accepted,
    //    did NOT check the certificate.
    $encryptedAs = [
        MailRoutes::RULE_CHECKED     => "Encryption switched on, and the server's certificate checked",
        MailRoutes::RULE_ONE         => 'Encryption switched on, through the ONE certificate that is accepted (it was not checked in full)',
        MailRoutes::RULE_NOT_CHECKED => "Encryption switched on. The server's certificate was NOT checked: checking is switched off",
    ][(string) ($testResult['rule'] ?? MailRoutes::RULE_CHECKED)] ?? "Encryption switched on, and the server's certificate checked";
    $done = [
        'Reached the mail server'                              => $recorded('/\AConnected to /'),
        $encryptedAs                                           => $recorded('/\AEncryption is on /'),
        'Signed in to the mailbox'                             => $recorded('/\AS: 235[ -]/'),
        'The mail server accepted the message'                 => $testResult['sent'] === true,
    ];
    $stopped = false;
    foreach ($done as $label => $isDone) {
        $state = 'done';
        if ($isDone === false) {
            $state   = $stopped === false ? 'stopped' : 'not-reached';
            $stopped = true;
        }
        $testSteps[] = ['label' => $label, 'state' => $state];
    }
    foreach ($testResult['steps'] as $line) {
        if (str_starts_with((string) $line, 'S: ') === true || str_starts_with((string) $line, 'S (out of turn): ') === true) {
            $testLastLine = (string) $line;
        }
    }
}

$pageTitle   = 'Email Deliverability';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Admin' => Site::url('admin'), 'Integrations' => Site::url('admin/integrations'), 'Email' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
/** Escape for HTML. Every value printed below goes through this. */
$esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-envelope me-2" aria-hidden="true"></i>Email Deliverability</h1>
        <p class="text-secondary mb-0">How the portal sends e-mail, whether it is ready, and a test.</p>
    </div>
    <a href="<?php echo $esc(Router::url('admin/integrations')); ?>" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">&larr; Integrations</a>
</div>

<?php if ($flash !== ''): ?>
    <div class="alert alert-<?php echo $esc($flashType); ?>" data-mail-flash="<?php echo $esc($flashType); ?>"><?php echo $esc($flash); ?></div>
<?php endif; ?>

<?php if ($standingWarning !== null): ?>
    <!-- ⚠️ Certificate checking is off, or one certificate is accepted. Shown to every administrator, from what is stored, whatever route is in use. -->
    <?php // 🧑 Who set it is named to a GLOBAL administrator only. Everybody else reads the same warning with nobody named (`publicLine`): the person is a global administrator, and an organisation's administrator has no need of their name. ?>
    <div class="alert alert-<?php echo $standingWarning['mode'] === MailRoutes::CHECK_OFF ? 'danger' : 'warning'; ?>" role="alert" data-mail-warning="<?php echo $standingWarning['mode'] === MailRoutes::CHECK_OFF ? 'checking-off' : 'one-certificate'; ?>">
        <h2 class="h5 alert-heading"><i class="fa-solid fa-shield-halved me-1" aria-hidden="true"></i><?php echo $esc($standingWarning['headline']); ?></h2>
        <p class="text-break<?php echo $isGlobalAdmin === true ? '' : ' mb-0'; ?>"><?php echo $esc($isGlobalAdmin === true ? $standingWarning['line'] : $standingWarning['publicLine']); ?></p>
        <?php if ($isGlobalAdmin === true): ?>
            <form method="post" action="<?php echo $esc($selfUrl); ?>" class="mb-0">
                <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf); ?>">
                <input type="hidden" name="action" value="cert_on">
                <button type="submit" class="btn btn-sm btn-<?php echo $standingWarning['mode'] === MailRoutes::CHECK_OFF ? 'danger' : 'dark'; ?>">Switch certificate checking back on now</button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($mailRoute['missing'] !== []): ?>
    <!-- 🚫 No mail route can send. Said plainly, with what to do about it. -->
    <div class="alert alert-danger" role="alert" data-mail-status="not-ready">
        <h2 class="h5 alert-heading"><i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>This portal cannot send e-mail yet</h2>
        <p>
            Password-reset links are not being sent. A person who asks for one is still told it was sent,
            so that nobody can use that page to find out who has an account. Until this is put right,
            somebody who forgets their password needs an administrator to reset it for them.
        </p>
        <p class="mb-1">
            E-mail is set to go through <strong><?php echo $esc($mailRoute['label']); ?></strong>.
            Still needed:
        </p>
        <ul>
            <?php foreach ($mailRoute['missing'] as $missingItem): ?>
                <li><?php echo $esc($missingItem); ?></li>
            <?php endforeach; ?>
        </ul>
        <?php if ($smtpInUse === true): ?>
            <?php // 📍 The SMTP mailbox is typed on THIS page, where it can be tested. The page used to add that a password typed on the general Settings page is not used; since #597 was fixed it is. See MailRoutes.php. ?>
            <p class="mb-0">
                Fill these in under &ldquo;SMTP mailbox&rdquo; further down this page<?php echo $isGlobalAdmin === true ? '' : ' (a global administrator has to do this)'; ?>.
                Type the mailbox&rsquo;s password there, where it can be tested before the portal relies on it.
                Then use &ldquo;Send test&rdquo;.
            </p>
        <?php else: ?>
            <?php
            // 📍 Where these are really typed (#580). This notice used to send the
            //    administrator to the Integrations page for all of them. That page
            //    SHOWS the mail settings, but the only ones it lets anybody type
            //    are the shared mailbox and its name. The rest are typed on the
            //    Settings page, and a Google key file is not typed anywhere: it is
            //    a file put on the server.
            ?>
            <p class="mb-0">
                Fill these in on the <a href="<?php echo $esc(Router::url('admin/settings')); ?>" class="alert-link">Settings page</a>.
                Two exceptions: a shared mailbox to send from is typed on the
                <a href="<?php echo $esc(Router::url('admin/integrations')); ?>" class="alert-link">Integrations page</a>,
                and a Google key file is a file that has to be put in the portal&rsquo;s <code>_auth_keys</code> folder on the server.
                Which route the portal sends through (Microsoft 365, Google or an SMTP mailbox) is chosen under
                &ldquo;Route in use&rdquo; below. Then use &ldquo;Send test&rdquo;.
            </p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <!-- ✅ Everything the chosen route needs is filled in. That is all this can know. -->
    <div class="alert alert-success" role="status" data-mail-status="ready">
        <i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>
        E-mail is set to go through <strong><?php echo $esc($mailRoute['label']); ?></strong>, and its settings are filled in.
        That does not prove a message arrives: use &ldquo;Send test&rdquo; below to check.
    </div>
<?php endif; ?>

<?php if ($certificateProblem !== null && $smtpInUse === false): ?>
    <!-- 🪪 A "Send test" of the SMTP mailbox failed the certificate check while ANOTHER route is in use. Nothing is held for it: said first, in bold. -->
    <div class="alert alert-info" role="alert" data-mail-warning="certificate-not-in-use">
        <h2 class="h5 alert-heading"><i class="fa-solid fa-certificate me-1" aria-hidden="true"></i>The SMTP mailbox&rsquo;s mail server did not pass the certificate check</h2>
        <p>
            <strong>No e-mail is held because of this.</strong> The SMTP mailbox is not the route in use: e-mail is going out through <?php echo $esc($mailRoute['label']); ?>.
            The last time the SMTP mailbox was tried<?php echo $certificateProblem['at'] !== '' ? ' (' . $esc($showMoment($certificateProblem['at'])) . ')' : ''; ?>, by a
            &ldquo;Send test&rdquo;, its mail server&rsquo;s certificate was not accepted, so that test was not sent.
            If the portal is switched to the SMTP mailbox while the certificate stays as it is, mail will be held until it passes the check.
        </p>
        <p class="text-break"><strong>What the check found:</strong> <?php echo $esc($certificateProblem['words']); ?></p>
        <p class="mb-0">
            What to do: ask whoever runs the mail server to put its certificate right, or check that the server name under
            &ldquo;SMTP mailbox&rdquo; below is the name the certificate is for. Then use &ldquo;Send test&rdquo; through the SMTP mailbox again.
            <?php if ($isGlobalAdmin === true): ?>
                If the mailbox has to be used before then, <a href="#mail-certificate" class="alert-link">the certificate itself, and the ways to let mail past the check</a>,
                are further down this page. They can be decided before the portal is switched to the SMTP mailbox.
            <?php else: ?>
                Only a global administrator can let mail past a failed check.
            <?php endif; ?>
        </p>
        <p class="mb-0 mt-2 small">
            This notice shows what the last test of the SMTP mailbox found. It goes when a later test passes the check, or when the mail settings
            below are changed. It also goes when the portal is switched to the SMTP mailbox: the first message sent then finds the certificate again, and is held.
        </p>
    </div>
<?php elseif ($certificateProblem !== null): ?>
    <!-- 🪪 The mail server's certificate failed its check, and that is on record for the mail route. -->
    <div class="alert alert-warning" role="alert" data-mail-warning="certificate">
        <h2 class="h5 alert-heading"><i class="fa-solid fa-certificate me-1" aria-hidden="true"></i>The mail server&rsquo;s certificate did not pass the check</h2>
        <p>
            The last time the portal tried to send through the SMTP mailbox<?php echo $certificateProblem['at'] !== '' ? ' (' . $esc($showMoment($certificateProblem['at'])) . ')' : ''; ?>,
            the mail server&rsquo;s certificate was not accepted, so nothing was sent.
            While the certificate stays as it is, no e-mail goes out, password-reset links included.
            Mail is not lost: every message is held in the mail queue and goes by itself once the certificate passes the check.
            A held message is kept for a limited time, and one with a use-by time (a password-reset link, for one) is never sent after it.
        </p>
        <p class="text-break"><strong>What the check found:</strong> <?php echo $esc($certificateProblem['words']); ?></p>
        <p class="mb-0">
            What to do: ask whoever runs the mail server to put its certificate right, or check that the server name under
            &ldquo;SMTP mailbox&rdquo; below is the name the certificate is for. Then use &ldquo;Send test&rdquo;.
            <?php if ($isGlobalAdmin === true): ?>
                If mail has to go before then, <a href="#mail-certificate" class="alert-link">the certificate itself, and three deliberate ways past the check</a>, are further down this page.
            <?php else: ?>
                Only a global administrator can let mail past a failed check.
            <?php endif; ?>
        </p>
        <p class="mb-0 mt-2 small">
            This notice shows what the last attempt to reach the mail server found. It goes when the certificate passes the check
            on a later attempt (the mail queue&rsquo;s job tries once each time it runs, and so does &ldquo;Send test&rdquo;), or when the
            mail settings below are changed. A message stopped before the mail server is tried
            (by the hourly limit, or by a missing setting) leaves it showing.
        </p>
    </div>
<?php endif; ?>

<?php if ($testResult !== null): ?>
    <!-- 🧪 What the SMTP test came to. Shown once; nothing of it is stored except the usual line in the send log. -->
    <div class="card mb-3 border-<?php echo $testResult['sent'] === true ? 'success' : 'danger'; ?>" data-mail-test="<?php echo $testResult['sent'] === true ? 'sent' : 'not-sent'; ?>">
        <div class="card-body">
            <?php if ($testResult['sent'] === true): ?>
                <h2 class="h5 text-success"><i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>The mail server accepted the test message</h2>
                <p class="text-break">
                    It was handed to the mail server for <strong><?php echo $esc((string) $testResult['to']); ?></strong>.
                    That is not the same as delivered: check that it arrives, and look in the junk folder too.
                </p>
            <?php else: ?>
                <h2 class="h5 text-danger"><i class="fa-solid fa-circle-xmark me-1" aria-hidden="true"></i>The test message was not sent</h2>
                <p class="text-break" data-mail-test-reason><?php echo $esc((string) $testResult['words']); ?></p>
            <?php endif; ?>

            <?php if ($testSteps !== []): ?>
                <ol class="list-unstyled mb-3">
                    <?php foreach ($testSteps as $step): ?>
                        <li data-step-state="<?php echo $esc($step['state']); ?>">
                            <?php if ($step['state'] === 'done'): ?>
                                <i class="fa-solid fa-check text-success me-1" aria-hidden="true"></i><?php echo $esc($step['label']); ?>
                                <span class="visually-hidden">(done)</span>
                            <?php elseif ($step['state'] === 'stopped'): ?>
                                <i class="fa-solid fa-xmark text-danger me-1" aria-hidden="true"></i><strong><?php echo $esc($step['label']); ?></strong>
                                <span class="text-danger">&mdash; it stopped here</span>
                            <?php else: ?>
                                <i class="fa-solid fa-minus text-muted me-1" aria-hidden="true"></i><span class="text-muted"><?php echo $esc($step['label']); ?> &mdash; not reached</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <?php if ($testLastLine !== ''): ?>
                    <p class="small text-break">The mail server&rsquo;s last line: <code data-mail-test-last-line><?php echo $esc($testLastLine); ?></code></p>
                <?php endif; ?>
                <details class="small">
                    <summary>The conversation with the mail server, line by line</summary>
                    <p class="text-muted mt-2 mb-1">&ldquo;C&rdquo; is the portal and &ldquo;S&rdquo; is the mail server. The sign-in lines are replaced, so the password is not here.</p>
                    <ol class="font-monospace mb-0">
                        <?php foreach ($testResult['steps'] as $line): ?>
                            <li class="text-break"><?php echo $esc((string) $line); ?></li>
                        <?php endforeach; ?>
                    </ol>
                </details>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-body">
                <h2 class="h5">Route in use</h2>
                <p class="mb-1"><strong data-mail-route="<?php echo $esc($activeProvider); ?>"><?php echo $esc(ucfirst($mailRoute['label'])); ?></strong></p>
                <p class="small text-muted mb-0 text-break">
                    From: <code><?php echo $esc($senderEmail !== '' ? $senderEmail : '(no address set yet)'); ?></code>
                </p>
                <?php if ($isGlobalAdmin === true): ?>
                    <form method="post" action="<?php echo $esc($selfUrl); ?>" class="mt-3">
                        <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf); ?>">
                        <input type="hidden" name="action" value="set_route">
                        <label class="form-label small" for="mail-route">Send the portal&rsquo;s e-mail through</label>
                        <div class="d-flex flex-column flex-sm-row gap-2">
                            <select name="route" id="mail-route" class="form-select form-select-sm">
                                <?php foreach (Mailer::ROUTES as $routeKey => $routeLabel): ?>
                                    <option value="<?php echo $esc($routeKey); ?>"<?php echo $routeKey === $activeProvider ? ' selected' : ''; ?>><?php echo $esc(ucfirst($routeLabel)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-outline-primary btn-sm text-nowrap">Use this route</button>
                        </div>
                        <p class="form-text mb-0">
                            One route serves every organisation on this installation. An SMTP mailbox is set up further down this page;
                            Microsoft 365 and Google are set up on the Settings and Integrations pages.
                        </p>
                    </form>
                <?php else: ?>
                    <p class="small text-muted mt-3 mb-0">
                        One route serves every organisation on this installation, so only a global administrator can change it or send a test.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-body">
                <h2 class="h5">Send test</h2>
                <?php if ($isGlobalAdmin === true): ?>
                    <form method="post" action="<?php echo $esc($selfUrl); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf); ?>">
                        <input type="hidden" name="action" value="test_send">
                        <div class="mb-2">
                            <label class="form-label small" for="mail-test-to">Send a test message to</label>
                            <input type="email" name="to" id="mail-test-to" class="form-control form-control-sm" required autocomplete="email">
                        </div>
                        <?php if ($smtpInUse === false): ?>
                            <div class="mb-2">
                                <label class="form-label small" for="mail-test-through">Through</label>
                                <select name="through" id="mail-test-through" class="form-select form-select-sm">
                                    <option value="in-use">The route in use (<?php echo $esc($mailRoute['label']); ?>)</option>
                                    <option value="<?php echo $esc(MailRoutes::SMTP); ?>">The SMTP mailbox set up below, without switching to it</option>
                                </select>
                            </div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary btn-sm">Send test</button>
                        <p class="form-text mb-0">A real message is sent. A test through the SMTP mailbox can take up to half a minute if the mail server does not answer.</p>
                    </form>
                <?php else: ?>
                    <p class="small text-muted mb-0">A test sends a real message from the installation&rsquo;s mailbox, so it is for a global administrator.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($isGlobalAdmin === true): ?>
<div class="card mt-3" id="smtp-mailbox">
    <div class="card-body">
        <h2 class="h5">SMTP mailbox</h2>
        <p class="small text-muted">
            An ordinary mailbox on a mail server (SMTP is the usual way a program hands mail to one), of the kind most hosting and e-mail providers include.
            Your provider&rsquo;s help pages give the server name, the port and the kind of encryption.
            These settings are for the whole installation. Saving them does not switch the portal over to this mailbox:
            that is &ldquo;Route in use&rdquo;, above.
        </p>
        <?php if ($formErrors !== []): ?>
            <div class="alert alert-danger" role="alert" data-mail-form-errors>
                <p class="mb-1"><strong>Nothing was saved.</strong></p>
                <ul class="mb-0">
                    <?php foreach ($formErrors as $formError): ?>
                        <li><?php echo $esc($formError); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <form method="post" action="<?php echo $esc($selfUrl); ?>" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf); ?>">
            <input type="hidden" name="action" value="save_smtp">
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label" for="smtp-host">Mail server&rsquo;s name</label>
                    <input type="text" name="smtpHost" id="smtp-host" class="form-control" maxlength="253" value="<?php echo $esc($form['host']); ?>" autocapitalize="none" spellcheck="false">
                    <?php // (This used to say "the portal does not send a stored password to a server it was not saved for", which was more than is true: see MailRoutes::saveSmtp().) ?>
                    <div class="form-text" id="smtp-host-help">
                        The name only, as your mail provider gives it. It must be the name the server&rsquo;s certificate is for.
                        <strong>If you change this name or the port, type the mailbox&rsquo;s password again</strong> (or tick the box to remove it): this page does not
                        keep a stored password for a different server name or port. Certificate checking also goes back on if it had been switched off, or one
                        certificate accepted.
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label" for="smtp-port">Port</label>
                    <input type="text" inputmode="numeric" name="smtpPort" id="smtp-port" class="form-control" maxlength="5" value="<?php echo $esc($form['port']); ?>" aria-describedby="smtp-port-help">
                    <div class="form-text" id="smtp-port-help">A changed port counts as a changed server: type the password again.</div>
                </div>
                <div class="col-6 col-md-4">
                    <label class="form-label" for="smtp-security">Encryption</label>
                    <select name="smtpSecurity" id="smtp-security" class="form-select">
                        <option value="starttls"<?php echo strtolower($form['security']) !== 'tls' ? ' selected' : ''; ?>>STARTTLS (usually port 587)</option>
                        <option value="tls"<?php echo strtolower($form['security']) === 'tls' ? ' selected' : ''; ?>>TLS from the start (usually port 465)</option>
                    </select>
                    <div class="form-text">The portal does not send without encryption.</div>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label" for="smtp-username">Mailbox&rsquo;s user name</label>
                    <input type="text" name="smtpUsername" id="smtp-username" class="form-control" maxlength="320" value="<?php echo $esc($form['username']); ?>" autocapitalize="none" spellcheck="false" autocomplete="off">
                    <div class="form-text">Often the mailbox&rsquo;s whole e-mail address.</div>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label" for="smtp-password">Mailbox&rsquo;s password</label>
                    <?php // 🔑 No value attribute, ever: the stored password is never sent back to a browser. ?>
                    <input type="password" name="smtpPassword" id="smtp-password" class="form-control" maxlength="1000" autocomplete="new-password" aria-describedby="smtp-password-help">
                    <div class="form-text" id="smtp-password-help" data-password-state="<?php echo $esc($stored['passwordState']); ?>">
                        <?php if ($stored['passwordState'] === 'stored'): ?>
                            A password is stored. It is never shown. Leave this box empty to keep it, or type a new one to replace it.
                        <?php elseif ($stored['passwordState'] === 'none'): ?>
                            No password is stored yet.
                        <?php elseif ($stored['passwordState'] === 'not-secret'): ?>
                            <span class="text-danger">The password stored was not saved by this page, so it is not used. Type it in again here.</span>
                        <?php else: ?>
                            <span class="text-danger">The stored password cannot be read (the installation&rsquo;s key file may have changed). Type it in again.</span>
                        <?php endif; ?>
                        Use a long password that is not an ordinary word: the portal takes it out of anything it shows or logs, and it cannot do that reliably for a short or everyday one.
                    </div>
                    <?php if ($stored['passwordState'] !== 'none'): ?>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" name="removePassword" id="smtp-remove-password" value="1">
                            <label class="form-check-label" for="smtp-remove-password">Remove the stored password</label>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label" for="mail-from-address">&ldquo;From&rdquo; address</label>
                    <input type="email" name="fromAddress" id="mail-from-address" class="form-control" maxlength="254" value="<?php echo $esc($form['fromAddress']); ?>">
                    <div class="form-text">The address mail is sent from. The mailbox must be allowed to send as it. Microsoft 365 uses this address too.</div>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label" for="mail-from-name">&ldquo;From&rdquo; name</label>
                    <input type="text" name="fromName" id="mail-from-name" class="form-control" maxlength="200" value="<?php echo $esc($form['fromName']); ?>">
                    <div class="form-text">The name people see beside the address.</div>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label" for="mail-reply-to">Reply-to address</label>
                    <input type="email" name="replyTo" id="mail-reply-to" class="form-control" maxlength="254" value="<?php echo $esc($form['replyTo']); ?>">
                    <div class="form-text">Where replies go, if not to the &ldquo;from&rdquo; address. May be left empty.</div>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label" for="mail-per-hour">Most recipients an hour</label>
                    <input type="text" inputmode="numeric" name="perHour" id="mail-per-hour" class="form-control" maxlength="7" value="<?php echo $esc($form['perHour']); ?>">
                    <div class="form-text">
                        Leave empty for no limit. Many hosting companies allow only so many an hour. This limit covers every route.
                        A message that would pass it is not sent at once: it waits in the mail queue and goes when the hour has room
                        (it is listed under &ldquo;Recent sends&rdquo; as not sent until then). The last tenth of the limit, and at least 5
                        where the limit is 10 or more, is kept for urgent mail such as password-reset links.
                        The limit is not exact for mail sent straight from a page: messages sent at the same moment can together
                        go a little over it, so set it a little below what your hosting company allows.
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary mt-3">Save the SMTP settings</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php
// 📬 The certificate that failed (a global administrator only) and the mail
//    queue. Drawn by `_mail-queue.php`; every button in it comes back to the
//    chain at the top of this file.
mail_queue_page_draw($mysqli, [
    'isGlobalAdmin' => $isGlobalAdmin, 'smtpInUse' => $smtpInUse, 'csrf' => $csrf, 'selfUrl' => $selfUrl,
    'zone' => $zone, 'siteId' => Site::id(), 'host' => $stored['host'],
]);
?>

<div class="card mt-3">
    <div class="card-body">
        <h2 class="h5 text-break">DNS records for the sender&rsquo;s domain<?php echo $senderDomain !== '' ? ' (' . $esc($senderDomain) . ')' : ''; ?></h2>
        <?php if ($senderDomain === ''): ?>
            <p class="text-muted mb-0">Nothing to check yet: the route in use has no &ldquo;from&rdquo; address. Once it has one, this shows whether the address&rsquo;s domain has the three records that keep mail out of junk folders.</p>
        <?php else: ?>
            <div class="portal-data-list">
                <?php // The list component as the other pages write it: this header row, then one plain row per record. ?>
                <div class="portal-data-row portal-data-header d-none d-md-flex">
                    <div class="col-md-2">Record</div>
                    <div class="col-md-2">Found?</div>
                    <div class="col-md-8">Value</div>
                </div>
                <?php foreach ($dnsResults as $name => $r): ?>
                    <div class="portal-data-row">
                        <div class="col-12 col-md-2"><strong><?php echo $esc((string) $name); ?></strong></div>
                        <div class="col-12 col-md-2">
                            <span class="badge bg-<?php echo $r['state'] === 'ok' ? 'success' : 'warning text-dark'; ?>"><?php echo $r['state'] === 'ok' ? 'Found' : 'Not found'; ?></span>
                        </div>
                        <div class="col-12 col-md-8 small text-break"><code><?php echo $esc($r['value']); ?></code></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="small text-muted mt-2 mb-0">
                These three records tell other mail systems that the portal may send as this domain. Without them mail tends to land in junk folders.
                They are set where the domain is managed; your mail provider&rsquo;s help pages say what to put in each.
            </p>
        <?php endif; ?>
    </div>
</div>

<!-- 📜 The last ten sends, every route. House rule: no <table> for data display (portal-data-list). -->
<?php // (A page that lists what is WAITING is above: the mail queue. This list is what has been tried, newest first.) ?>
<div class="card mt-3">
    <div class="card-body">
        <h2 class="h5">Recent sends (last 10)</h2>
        <?php if ($logUnreadable === true): ?>
            <p class="small text-danger mb-0">
                The send log could not be read. If the portal has just been updated, the database upgrade has probably not been run yet:
                <a href="<?php echo $esc(Router::url('admin/upgrade')); ?>">run it at Admin, Upgrade</a>, then come back.
            </p>
        <?php elseif (count($recentSends) === 0): ?>
            <p class="small text-muted mb-0">Nothing has been sent yet. Use &ldquo;Send test&rdquo; above to try the route in use; every attempt, sent or not, is then listed here.</p>
        <?php else: ?>
            <?php
            // 🏷️ Each kind of line has its own plain label (#592). A waiting,
            //    held or expired message used to read "Not sent" in red,
            //    which is true and frightening: a waiting one is on its way.
            //    Only a message that was given up on is red now.
            $sendLabels = [
                'sent'    => ['Sent', 'success', ''],
                'queued'  => ['Waiting', 'info text-dark', 'Waiting in the mail queue: it has not gone yet, and will be tried again.'],
                'held'    => ['Held', 'warning text-dark', 'Held in the mail queue: it has not gone, and waits for a person or for the mail route to be put right.'],
                'expired' => ['Expired, not sent', 'secondary', 'Not sent: its use-by time passed before it could go.'],
                'failed'  => ['Not sent', 'danger', 'Not sent. A global administrator can see why.'],
            ];
            ?>
            <div class="portal-data-list">
                <?php foreach ($recentSends as $row): ?>
                    <?php
                    $wasSent = (string) $row['status'] === 'sent';
                    [$sendLabel, $sendColour, $sendPlain] = $sendLabels[(string) $row['status']] ?? $sendLabels['failed'];
                    ?>
                    <div class="portal-data-row" data-mail-log-status="<?php echo $esc((string) $row['status']); ?>">
                        <div class="col-12 col-md-2">
                            <span class="badge bg-<?php echo $esc($sendColour); ?>"><?php echo $esc($sendLabel); ?></span>
                            <span class="small text-muted ms-1"><?php echo $esc((string) $row['provider']); ?></span>
                        </div>
                        <div class="col-12 col-md-3 small text-break"><?php echo $esc((string) $row['toRecipients']); ?></div>
                        <div class="col-12 col-md-4 small text-break">
                            <?php echo $esc((string) $row['subject']); ?>
                            <?php if ($wasSent === false && $isGlobalAdmin === true && (string) $row['errorDetail'] !== ''): ?>
                                <?php // The reason as recorded. It can quote the mail server, which can name an address or the mailbox: a global administrator only. ?>
                                <br><span class="<?php echo (string) $row['status'] === 'failed' ? 'text-danger' : 'text-muted'; ?>"><?php echo $esc((string) $row['errorDetail']); ?></span>
                            <?php elseif ($wasSent === false): ?>
                                <br><span class="text-muted" data-mail-log-plain><?php echo $esc($sendPlain); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="col-12 col-md-3 small text-muted">
                            <?php
                            // A moment in UTC where the row has one (every row since
                            // migration 213), shown in this organisation's zone. An
                            // older row has only the database server's own clock,
                            // which is shown as it is and labelled.
                            echo is_string($row['sentAtUtc'] ?? null) === true
                                ? $esc($showMoment($row['sentAtUtc']))
                                : $esc((string) $row['sentAt']) . ' (the database server&rsquo;s clock)';
                            ?>
                            <?php if ($wasSent === false && $isGlobalAdmin === true && (string) $row['errorCode'] !== ''): ?>
                                <br><code class="small"><?php echo $esc((string) $row['errorCode']); ?></code>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-3">
    <div class="card-body">
        <h2 class="h5">Critical-error alert recipients (#229)</h2>
        <p class="small text-muted">
            Comma-separated email addresses receive automated alerts when the Logger writes a Critical or Fatal error.
            Rate-limited per fingerprint (default 30-minute cooldown).
            Edit at <code>portal.alerts.recipients</code> + <code>portal.alerts.severities</code> + <code>portal.alerts.cooldown_minutes</code> in <a href="<?php echo htmlspecialchars(Site::url('admin/settings'), ENT_QUOTES, 'UTF-8'); ?>">/admin/settings</a>.
        </p>
        <p class="small mb-0">Current: <code><?php echo htmlspecialchars((string) ($settings['portal']['alerts']['recipients'] ?? '(empty — alerts disabled)'), ENT_QUOTES, 'UTF-8'); ?></code></p>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
