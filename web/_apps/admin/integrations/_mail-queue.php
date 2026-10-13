<?php
// Path: _apps/admin/integrations/_mail-queue.php
/**
 * -----------------------------------------------------------------------------
 * Admin — the mail queue, and the ways past a failed certificate 📬🪪
 * -----------------------------------------------------------------------------
 * NOT A PAGE. No address points at this file. It is loaded by the e-mail page
 * (`email.php`, beside it) and gives that page two things:
 *
 *   mail_queue_page_act()   does ONE of the seven things a global
 *                           administrator can do here, and says what happened;
 *   mail_queue_page_draw()  draws the certificate that failed, the three ways
 *                           past the check, and the list of what is waiting.
 *
 * WHO MAY DO WHAT
 * ---------------
 * Every one of the seven actions is for a GLOBAL ADMINISTRATOR, by POST, with
 * the form's token. THE E-MAIL PAGE CHECKS ALL THREE, in one place, before it
 * calls mail_queue_page_act(): see the chain at the top of `email.php`.
 * Nothing in this file checks them again, exactly as MailRoutes::saveSmtp()
 * does not: do not call mail_queue_page_act() from anywhere that has not.
 * An organisation's administrator may LOOK: they are shown their own
 * organisation's waiting messages, with no button, and without the mail
 * server's own words (which can name another person's address, or the
 * mailbox).
 *
 * THE THREE WAYS PAST A FAILED CERTIFICATE (the owner's decision, 13 October
 * 2026). Each is a deliberate act, and each is written to the activity log
 * with who and when:
 *   1. Send ONE held message, accepting only the certificate this page
 *      showed. One message a press. There is no "send all".
 *   2. "Accept only this certificate", offered FIRST of the two that change
 *      a setting: mail goes while the server shows exactly that certificate.
 *   3. Switch checking off, confirmed by typing the mail server's name.
 * The fingerprint a form sends is the one THIS PAGE SHOWED, and it is only
 * used if it is still the one on record: see MailQueue::sendOneHeld() and
 * mail_queue_page_act(). Mail that goes by Microsoft 365 or Google gets
 * none of the three: nothing sent by those routes ever goes past a check.
 * THE SMTP MAILBOX'S OWN SETTING CAN BE CHANGED WHATEVER ROUTE IS IN USE:
 * switching checking back on (what is stored outlives a change of route:
 * see MailRoutes.php), and also "accept only this certificate" and "switch
 * off", so that a certificate a "Send test" finds failing can be decided
 * about BEFORE the portal is switched to the mailbox. Sending ONE held
 * message past the check stays for SMTP in use only: only then is any mail
 * held for the mailbox.
 *
 * THE LAST SENTENCE OF BOTH WARNINGS was changed after the package's check,
 * because it had become untrue. It said the warning stays on all three
 * pages "with your name and the date". The health page no longer names
 * anybody (every organisation's administrator and the outside monitor read
 * it), and a name can only be shown while the activity log keeps its line.
 *
 * WHAT IS NEVER SHOWN
 * -------------------
 * A message's body, or any part of it. MailQueue::listed() does not read the
 * body, so nothing here could print it. A body can hold a password-reset
 * link. The mailbox's password is not read by this file at all.
 *
 * WHAT THIS CANNOT DO
 * -------------------
 * - Tell whether a certificate that failed the check is really the mail
 *   server's own. It shows the fingerprint, and says how a person can find
 *   out: by comparing it with what whoever runs the mail server reads out.
 * - Send a FAILED message again. A failed row no longer says whether it
 *   might already have gone (a message held because "nobody can tell" is
 *   failed, with that reason gone, when it has been held too long), so
 *   sending it again from here could post a second copy with no warning. It
 *   can be deleted; to send it again, send it again from where it was written.
 * - Rule out a second copy of a message that is sent again. The page says so
 *   before the button can be pressed.
 * - Show more than 200 messages. It says when there are more.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/592
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Logger;
use Portal\Core\Mailer;
use Portal\Core\MailQueue;
use Portal\Core\MailRoutes;

/**
 * The seven actions, and what each is called in a refusal. A closed list: the
 * e-mail page adds it to its own, so nothing a visitor posts is ever written
 * into the activity log.
 *
 * @return array<string, string>
 */
function mail_queue_page_actions(): array
{
    return [
        'queue_run'             => 'sending waiting mail now',
        'queue_send_again'      => 'sending a held message again',
        'queue_send_past_check' => 'sending a held message with the certificate check skipped',
        'queue_delete'          => 'deleting a message from the mail queue',
        'cert_accept'           => 'accepting the mail server\'s certificate',
        'cert_off'              => 'switching certificate checking off',
        'cert_on'               => 'switching certificate checking back on',
    ];
}

/**
 * Do one of the seven actions.
 *
 * THE CALLER HAS CHECKED the form's token, that the action is one of the
 * seven, and that the person is a global administrator. This function does
 * not (see the file header).
 *
 * @param array<string, mixed> $post    What the form sent.
 * @param int|null             $actorId Who is asking, for the activity log.
 *
 * @return array{0: string, 1: string} What to tell the person, and the kind
 *         of notice to tell it in ('success', 'warning' or 'danger').
 */
function mail_queue_page_act(\mysqli $db, string $action, array $post, ?int $actorId): array
{
    $text    = static fn (string $field): string => is_string($post[$field] ?? null) === true ? trim($post[$field]) : '';
    $queueId = preg_match('/\A[0-9]{1,10}\z/', $text('queueId')) === 1 ? (int) $text('queueId') : 0;
    $ticked  = ($post['understood'] ?? '') === '1';
    $smtp    = Mailer::provider() === MailRoutes::SMTP;
    // The fingerprint this page showed when the button was drawn. Spaces and
    // colons are how people write one out; neither is part of it.
    $shown   = strtolower((string) preg_replace('/[\s:]/', '', $text('fingerprint')));

    // 🪪 SENDING A MESSAGE PAST THE CHECK is for the SMTP route only: with
    //    Microsoft 365 or Google in use, the message would go by that route,
    //    and those have no way past a failed check.
    //    The three that change the SMTP mailbox's own certificate setting
    //    are NOT refused here, whatever route is in use:
    //    - switching checking back ON is the safe direction, and "off"
    //      stays stored, and in force for a "Send test" through the SMTP
    //      mailbox, after another route is chosen;
    //    - "accept only this certificate" and "switch off" are decided
    //      about the SMTP mailbox's certificate, which a "Send test" can
    //      find failing BEFORE the portal is switched to that mailbox. An
    //      administrator must be able to decide then, and prove the mailbox
    //      with another test, and not switch all mail over first and have
    //      it held while they decide. Each still needs a certificate fault
    //      on record, and the fingerprint (or the typed server name) the
    //      page asked for. Neither lets Microsoft 365 or Google past
    //      anything: only MailRoutes::sendSmtp() reads the setting.
    //    (All four were in this list at first. With another route in use
    //    the standing warning's own button was then refused as "for an SMTP
    //    mailbox only", and a mailbox being set up could not be made to
    //    pass its own test.)
    if ($smtp === false && $action === 'queue_send_past_check') {
        return ['That is for an SMTP mailbox only. Microsoft 365 and Google have no way past a failed certificate check. Nothing was changed.', 'danger'];
    }
    // What a decision about the certificate comes to while another route
    // carries the mail: said in place of "held mail goes…", which would
    // not be true.
    $notInUse = ' The SMTP mailbox is not the route in use, so no mail was held for this. It is in force for a "Send test" through the SMTP mailbox now, and for every e-mail if the portal is switched to it.';

    // ---- 1. Send waiting mail now --------------------------------------------
    if ($action === 'queue_run') {
        // One batch, with a request's own budget, exactly as the scheduled
        // job runs it. It does NOT record that the job ran: only the job's
        // own page does that (MailQueue::jobIsRunning()), or one press here
        // would make the portal believe a job existed.
        $ran = MailQueue::run($db, Mailer::REQUEST_MESSAGES, Mailer::REQUEST_SECONDS);
        if ($ran['ran'] !== 1) {
            return ['The mail job is working through the queue at this moment, so nothing more was started. Look again in a minute.', 'warning'];
        }
        Logger::activity(
            'MailQueueRunByHand',
            'Send waiting mail now: ' . $ran['sent'] . ' sent, ' . $ran['waiting'] . ' still waiting after a try, ' . $ran['held'] . ' held, ' . $ran['failed'] . ' failed, ' . $ran['expired'] . ' expired.',
            $actorId
        );
        $left = $ran['leftQueued'] . ' waiting and ' . $ran['leftHeld'] . ' held are left.';
        if ($ran['stopped'] === 1) {
            return ['The run stopped early: the mail route cannot be used at the moment (the notices on this page say why). ' . $ran['sent'] . ' sent. ' . $left, 'warning'];
        }

        return [$ran['sent'] . ' sent in this run' . ($ran['failed'] > 0 ? ', ' . $ran['failed'] . ' given up on' : '') . '. ' . $left
            . ' One press sends at most ' . Mailer::REQUEST_MESSAGES . ' messages; press again for more, or leave the rest to the scheduled job.', 'success'];
    }

    // ---- 2. Delete one message -----------------------------------------------
    if ($action === 'queue_delete') {
        if ($queueId < 1 || $ticked === false) {
            return ['Nothing was deleted: tick the box to say you mean it, then press Delete.', 'danger'];
        }
        $gone = MailQueue::removeByHand($db, $queueId);
        if ($gone === null) {
            return ['Nothing was deleted: that message is being sent at this moment, or has already gone from the queue. Look again in a minute.', 'warning'];
        }
        // The number and what it was. Not its subject or who it was for:
        // the send log holds those, for as long as the send log is kept.
        Logger::activity('MailQueueDeleted', 'Deleted message ' . $queueId . ' from the mail queue. It was ' . $gone['status'] . ' and had not been sent.', $actorId);

        return ['The message was deleted from the queue. It was not sent' . ($gone['status'] === 'queued' || $gone['status'] === 'held' ? ', and "Recent sends" below says so' : '') . '.', 'success'];
    }

    // ---- 3. Send one held message again (nobody can tell whether it went) -----
    if ($action === 'queue_send_again') {
        if ($queueId < 1 || $ticked === false) {
            return ['Nothing was sent: tick the box to say you understand that the person may get a second copy, then press the button.', 'danger'];
        }
        $done = MailQueue::sendOneHeld($db, $queueId);
        if ($done['done'] !== 'not-tried') {
            Logger::activity('MailQueueSentAgain', 'Sent held message ' . $queueId . ' again, knowing it may be a second copy. What came of it: ' . $done['done'] . '.', $actorId);
        }

        return [$done['words'], $done['done'] === 'sent' ? 'success' : ($done['done'] === 'not-tried' ? 'danger' : 'warning')];
    }

    // 🪪 What is on record about the certificate. Three of the four actions
    //    below act on THAT certificate, and only if the form names the same
    //    one: a form drawn before the server changed its certificate must not
    //    approve a certificate nobody has looked at.
    $onRecord    = MailRoutes::certificateOnRecord($db, 'UTC');
    $sameAsShown = $onRecord !== null && $onRecord['sha256'] !== '' && $shown !== '' && hash_equals($onRecord['sha256'], $shown) === true;
    $changed     = 'The certificate on record is not the one this page showed you: it has changed, or the fault has cleared, since the page was drawn. '
        . 'Nothing was changed and nothing was sent. Look at the certificate below again before you decide.';

    // ---- 4. Send ONE held message with the check skipped ---------------------
    if ($action === 'queue_send_past_check') {
        if ($queueId < 1 || $ticked === false) {
            return ['Nothing was sent: tick the box to say you have read what this does, then press the button.', 'danger'];
        }
        if ($sameAsShown === false) {
            return [$changed, 'danger'];
        }
        // 🔒 The fingerprint goes all the way to the connection, which
        //    accepts that certificate and no other.
        $done = MailQueue::sendOneHeld($db, $queueId, $shown);
        if ($done['done'] !== 'not-tried') {
            Logger::activity(
                'MailQueueSentPastCheck',
                'Sent held message ' . $queueId . ' with the certificate check skipped, accepting only the certificate with fingerprint ' . $shown . '. What came of it: ' . $done['done'] . '.',
                $actorId
            );
        }
        if ($done['done'] === 'sent') {
            return ['That one message was sent: the mail server showed the certificate you were shown, and accepted the message. Every other held message is still held.', 'success'];
        }

        return [$done['words'], $done['done'] === 'not-tried' ? 'danger' : 'warning'];
    }

    // ---- 5. Accept only this certificate -------------------------------------
    if ($action === 'cert_accept') {
        if ($ticked === false) {
            return ['Nothing was changed: tick the box to say you have compared the fingerprint, or accept the risk described, then press the button.', 'danger'];
        }
        if ($sameAsShown === false) {
            return [$changed, 'danger'];
        }
        if (MailRoutes::setCertificateCheck($db, MailRoutes::CHECK_ONLY, $shown) === false) {
            return ['Nothing was changed: the setting could not be stored. The reason is in the server\'s error log; if the portal has just been updated, run the database upgrade first.', 'danger'];
        }
        Logger::activity(
            MailRoutes::LOG_ACCEPTED,
            'Accepted the mail server\'s certificate with fingerprint ' . $shown . ' WITHOUT the full check. Mail goes while the server shows exactly that certificate. What the check had found: '
            . mb_substr($onRecord['words'], 0, 600),
            $actorId
        );

        return ['The portal now accepts that one certificate.' . ($smtp === true
            ? ' Held mail goes at the mail job\'s next run, or press "Send waiting mail now". If the mail server shows any other certificate, mail is held again.'
            : $notInUse) . ' Checking switches itself back on when the server\'s certificate passes the full check.', 'success'];
    }

    // ---- 6. Switch certificate checking off ----------------------------------
    if ($action === 'cert_off') {
        $host = strtolower(MailRoutes::formValues()['host']);
        // 🔏 The server's name, typed. Compared in small letters, and with
        //    hash_equals() like every other confirmation on this page.
        if ($host === '' || hash_equals($host, strtolower($text('serverName'))) === false) {
            return ['Nothing was changed: to switch checking off, type the mail server\'s name exactly as it is under "SMTP mailbox" on this page.', 'danger'];
        }
        if ($onRecord === null) {
            // Offered only while a certificate is failing. Without this a
            // form kept open from an earlier fault could switch checking off
            // for a server whose certificate is perfectly good.
            return ['Nothing was changed: no certificate fault is on record, so there is nothing to go past. The mail server\'s certificate is being checked, and passes.', 'danger'];
        }
        if ($onRecord['sha256'] === '') {
            // The page offers nothing while the certificate could not be
            // read, and says why. The same rule for a form posted by hand.
            return ['Nothing was changed: the portal has not been able to read the certificate that failed, so nothing can be decided about it yet. Use "Send test", or wait for the mail job\'s next run, then look again.', 'danger'];
        }
        if (MailRoutes::setCertificateCheck($db, MailRoutes::CHECK_OFF) === false) {
            return ['Nothing was changed: the setting could not be stored. The reason is in the server\'s error log; if the portal has just been updated, run the database upgrade first.', 'danger'];
        }
        Logger::activity(MailRoutes::LOG_OFF, 'Switched certificate checking OFF for the mail server ' . $host . ', confirmed by typing its name. What the check had found: ' . mb_substr($onRecord['words'], 0, 600), $actorId);

        return ['Certificate checking is now OFF.' . ($smtp === true ? ' Held mail goes at the mail job\'s next run, or press "Send waiting mail now".' : $notInUse)
            . ' The warning at the top of this page stays, here and on the admin dashboard and the health page, until checking is back on. It switches itself back on when the server\'s certificate passes the check.', 'warning'];
    }

    // ---- 7. Switch certificate checking back on ------------------------------
    // (The safe direction, so it asks for nothing more than the press, and
    //  is allowed whatever mail route is in use: see the top of this function.)
    $before = MailRoutes::certificateCheck();
    if (MailRoutes::setCertificateCheck($db, MailRoutes::CHECK_ON) === false) {
        return ['Nothing was changed: the setting could not be stored. The reason is in the server\'s error log.', 'danger'];
    }
    Logger::activity(
        MailRoutes::LOG_ON,
        'Switched certificate checking for the mail server back ON. ' . ($before['mode'] === MailRoutes::CHECK_ONLY
            ? 'One certificate had been accepted (fingerprint ' . $before['fingerprint'] . ').'
            : ($before['mode'] === MailRoutes::CHECK_OFF ? 'Checking had been switched off.' : 'It was already on.')),
        $actorId
    );

    // (With another route in use, "mail is held from the next message on"
    //  would not be true: mail is going through that route.)
    return ['Certificate checking is ON: the mail server\'s certificate is checked in full again. ' . ($smtp === true
        ? 'If it still fails the check, mail is held from the next message on.'
        : 'That is for the SMTP mailbox, which is not the route in use now.'), 'success'];
}

/**
 * How long until a moment, in words: "42 minutes", "3 hours 5 minutes".
 * From a number of exact seconds the DATABASE worked out; nothing here adds
 * to a clock.
 */
function mail_queue_page_time_left(int $seconds): string
{
    if ($seconds < 60) {
        return 'less than a minute';
    }
    $hours   = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $parts   = [];
    if ($hours > 0) {
        $parts[] = $hours . ' hour' . ($hours === 1 ? '' : 's');
    }
    if ($minutes > 0) {
        $parts[] = $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    }

    return implode(' ', $parts);
}

/**
 * Draw the certificate that failed (with the three ways past the check) and
 * the list of what is waiting.
 *
 * @param array{isGlobalAdmin: bool, smtpInUse: bool, csrf: string, selfUrl: string, zone: string, siteId: int, host: string} $with
 *        zone: the organisation's time zone, for every moment shown.
 *        siteId: this organisation, for an administrator who is not global.
 *        host: the mail server's name as stored, shown in the warnings.
 */
function mail_queue_page_draw(\mysqli $db, array $with): void
{
    $esc      = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $isGlobal = $with['isGlobalAdmin'];
    $zone     = $with['zone'];
    $csrf     = $esc($with['csrf']);
    $self     = $esc($with['selfUrl']);
    $shown    = static fn (mixed $utc): string => MailQueue::shown(is_string($utc) === true ? $utc : '', $zone);

    // 🪪 The certificate on record, and whether its fingerprint could be
    //    read. Only a global administrator is shown it in full and offered
    //    anything; everybody else has the notice at the top of the page.
    //    WHATEVER ROUTE IS IN USE: with another route in use it is what a
    //    "Send test" of the SMTP mailbox found, shown so that it can be
    //    decided about before the portal is switched over. `$inUse` chooses
    //    the words, because "mail is being held" is true only for SMTP.
    $inUse    = $with['smtpInUse'] === true;
    $onRecord = $isGlobal === true ? MailRoutes::certificateOnRecord($db, $zone) : null;
    $print    = $onRecord === null ? '' : $onRecord['sha256'];
    // Written in groups of four so that it can be read out and compared.
    $printShown = trim(chunk_split($print, 4, ' '));
    ?>

<?php if ($onRecord !== null): ?>
<div class="card mt-3 border-warning" id="mail-certificate" data-mail-certificate="<?php echo $print !== '' ? 'readable' : 'unreadable'; ?>">
    <div class="card-body">
        <h2 class="h5"><i class="fa-solid fa-certificate me-1" aria-hidden="true"></i>The certificate the mail server showed</h2>
        <p class="small text-muted">
            A certificate is the document a mail server shows to prove it is the server it claims to be. The portal checks it before it sends the mailbox&rsquo;s
            password or any message.
            <?php if ($inUse === true): ?>
                This one did not pass, so mail is being held.
            <?php else: ?>
                This one did not pass when the SMTP mailbox was last tested. <strong>No mail is held for it</strong>: the SMTP mailbox is not the route in use.
            <?php endif; ?>
        </p>
        <div class="portal-data-list mb-3">
            <div class="portal-data-row">
                <div class="col-12 col-md-3"><strong>What the check found</strong></div>
                <div class="col-12 col-md-9 text-break" data-mail-certificate-found><?php echo $esc($onRecord['words']); ?></div>
            </div>
            <div class="portal-data-row">
                <div class="col-12 col-md-3"><strong>It is for</strong></div>
                <div class="col-12 col-md-9 text-break"><?php echo $esc($onRecord['names'] !== '' ? str_replace(',', ', ', $onRecord['names']) : '(no name is written in it)'); ?></div>
            </div>
            <div class="portal-data-row">
                <div class="col-12 col-md-3"><strong>Issued by</strong></div>
                <div class="col-12 col-md-9 text-break"><?php echo $esc($onRecord['issuer'] !== '' ? $onRecord['issuer'] : '(not recorded)'); ?></div>
            </div>
            <div class="portal-data-row">
                <div class="col-12 col-md-3"><strong>Valid</strong></div>
                <div class="col-12 col-md-9">
                    <?php echo $onRecord['validFrom'] !== '' ? 'from ' . $esc($onRecord['validFrom']) . ' until ' . $esc($onRecord['validTo']) : '(not recorded)'; ?>
                </div>
            </div>
            <div class="portal-data-row">
                <div class="col-12 col-md-3"><strong>Fingerprint</strong> <span class="small text-muted">(SHA-256)</span></div>
                <div class="col-12 col-md-9 text-break">
                    <?php if ($print !== ''): ?>
                        <code data-mail-certificate-fingerprint="<?php echo $esc($print); ?>"><?php echo $esc($printShown); ?></code>
                        <div class="small text-muted">A fingerprint is a short code worked out from the whole certificate. No two certificates share one, so it names this certificate exactly.</div>
                    <?php else: ?>
                        (could not be read)
                    <?php endif; ?>
                </div>
            </div>
            <div class="portal-data-row">
                <div class="col-12 col-md-3"><strong>Seen</strong></div>
                <div class="col-12 col-md-9">first on <?php echo $esc($onRecord['since']); ?>, last on <?php echo $esc($onRecord['seenAt']); ?></div>
            </div>
        </div>

        <p>
            <strong>The right fix is to put the certificate right</strong>: ask whoever runs the mail server, or correct the server&rsquo;s name under &ldquo;SMTP mailbox&rdquo;
            below if it is the name that is wrong.
            <?php echo $inUse === true ? 'Held mail then goes by itself, and nobody has to press anything here.' : 'A &ldquo;Send test&rdquo; through the SMTP mailbox then passes, and nobody has to press anything here.'; ?>
        </p>

        <?php if ($print === ''): ?>
            <p class="mb-0" data-mail-ways="none">
                The portal could not read the certificate a second time to describe it, so there is no fingerprint, and nothing can be offered here yet:
                every way past the check names one certificate by its fingerprint. Use &ldquo;Send test&rdquo;, or wait for the mail job&rsquo;s next run, then look again.
            </p>
        <?php else: ?>
            <?php if ($inUse === true): ?>
            <h3 class="h6 mt-4">If mail has to go before the certificate is put right</h3>
            <p class="small">
                There are three ways, from the narrowest to the widest. Each is recorded in the activity log with your name and the time.
                Mail is encrypted in all three. What each gives up is being sure <em>who</em> it is encrypted to.
            </p>
            <?php else: ?>
            <?php // 🧭 Another route is in use. The two ways that change the mailbox's setting can be decided now; the first (one held message) cannot, because nothing is held for this mailbox. They keep their numbers, 2 and 3, so the page reads the same whichever route is in use. ?>
            <h3 class="h6 mt-4">If the SMTP mailbox has to be used before the certificate is put right</h3>
            <p class="small" data-mail-ways="before-switching">
                Two of the three ways past the check can be decided here, before the portal is switched to the SMTP mailbox. What you decide comes into force at once for a
                &ldquo;Send test&rdquo; through the SMTP mailbox, and for every e-mail if the portal is switched to it. The first way, sending one held message, is offered
                only while the SMTP mailbox is the route in use: only then is any mail held for it. Each is recorded in the activity log with your name and the time.
                Mail is encrypted in both. What each gives up is being sure <em>who</em> it is encrypted to.
            </p>
            <?php endif; ?>

            <?php // ---- 1: only while SMTP carries the mail ---- ?>
            <?php if ($inUse === true): ?>
            <div class="border rounded p-3 mb-3" data-mail-way="one-message">
                <h4 class="h6">1. Send one held message</h4>
                <p class="small mb-0">
                    For a message that cannot wait, such as somebody&rsquo;s password-reset link. Find it in the mail queue below and use
                    &ldquo;Send this one with the check skipped&rdquo;. It is sent only if the mail server still shows exactly the certificate above.
                    One message a press; every other message stays held. Nothing about how the portal sends is changed.
                </p>
            </div>
            <?php endif; ?>

            <?php // ---- 2: offered before switching off, on purpose ---- ?>
            <div class="border border-warning rounded p-3 mb-3" data-mail-way="accept">
                <h4 class="h6">2. Accept only this certificate</h4>
                <div data-mail-warning-text="accept">
                    <p class="small">
                        The portal will send mail through this mail server for as long as it shows <strong>exactly the certificate above</strong>, and will hold mail again
                        the moment it shows any other.
                    </p>
                    <p class="small">
                        <strong>What you are deciding:</strong> that the certificate above really is your mail server&rsquo;s own, although it failed the check.
                        The portal cannot tell you that. If it is not, and somebody between this portal and your mail server is showing a certificate of their own,
                        then for as long as this stays in force that person receives the mailbox&rsquo;s user name and password, and can read and change every e-mail
                        the portal sends. That includes password-reset links, which let whoever holds one take over the account it is for, an administrator&rsquo;s included.
                    </p>
                    <p class="small">
                        <strong>How to be sure first:</strong> ask whoever runs the mail server to read you the SHA-256 fingerprint of its certificate, by telephone or
                        from their own website, and compare it with the one above. If they are the same, it is your server&rsquo;s certificate.
                    </p>
                    <p class="small">
                        Checking switches itself back on as soon as the mail server shows a certificate that passes the full check. Until then a warning stays on this
                        page, the admin dashboard and the health page, with the date. On this page and the dashboard it also shows your name to global
                        administrators, for as long as the activity log keeps its line.
                    </p>
                </div>
                <form method="post" action="<?php echo $self; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="cert_accept">
                    <input type="hidden" name="fingerprint" value="<?php echo $esc($print); ?>">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="understood" id="cert-accept-understood" value="1" required>
                        <label class="form-check-label small" for="cert-accept-understood">I have compared the fingerprint, or I accept the risk described above.</label>
                    </div>
                    <button type="submit" class="btn btn-warning btn-sm">Accept only this certificate</button>
                </form>
            </div>

            <?php // ---- 3 ---- ?>
            <div class="border border-danger rounded p-3" data-mail-way="off">
                <h4 class="h6 text-danger">3. Switch certificate checking off</h4>
                <div data-mail-warning-text="off">
                    <p class="small">
                        With checking off, <strong>the portal cannot tell your mail server from an impostor</strong>. It will send through whatever answers at
                        <code><?php echo $esc($with['host']); ?></code>, whatever certificate it shows, including one that is different every time.
                    </p>
                    <p class="small">
                        <strong>What could happen:</strong> somebody on the network path between this portal and the mail server (at the hosting company, at the mail
                        provider, or anywhere the connection passes) could answer in the mail server&rsquo;s place. They could then read or change every e-mail the portal
                        sends, including password-reset links, which let whoever holds one take over the account it is for, an administrator&rsquo;s included. They would
                        also capture the mailbox&rsquo;s user name and password. Nothing on any page would show that it had happened.
                    </p>
                    <p class="small">
                        <strong>Use &ldquo;Accept only this certificate&rdquo; instead if you can.</strong> It lets mail go and still refuses an impostor. Switch checking off
                        only while the mail server&rsquo;s certificate is being put right, and only if that certificate keeps changing so that accepting one is no use.
                    </p>
                    <p class="small">
                        Checking switches itself back on as soon as the mail server shows a certificate that passes the check. Until then a warning stays on this page,
                        the admin dashboard and the health page, with the date. On this page and the dashboard it also shows your name to global
                        administrators, for as long as the activity log keeps its line.
                    </p>
                </div>
                <form method="post" action="<?php echo $self; ?>" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="cert_off">
                    <div class="mb-2">
                        <label class="form-label small" for="cert-off-server-name">To confirm, type the mail server&rsquo;s name</label>
                        <input type="text" name="serverName" id="cert-off-server-name" class="form-control form-control-sm" maxlength="253" required autocapitalize="none" spellcheck="false" autocomplete="off">
                    </div>
                    <button type="submit" class="btn btn-danger btn-sm">Switch certificate checking off</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

    <?php
    // 📋 What is waiting. A global administrator sees every organisation's;
    //    an organisation's administrator sees their own organisation's only.
    $rows       = [];
    $unreadable = false;
    try {
        $rows = MailQueue::listed($db, $isGlobal === true ? null : $with['siteId'], 201);
    } catch (\Throwable $e) {
        // Most likely the portal's files are newer than its database.
        $unreadable = true;
        error_log('The e-mail page could not read the mail queue: ' . $e->getMessage());
    }
    $more = count($rows) > 200;
    $rows = array_slice($rows, 0, 200);
    $toGo = array_values(array_filter($rows, static fn (array $row): bool => in_array((string) $row['status'], ['queued', 'sending', 'held'], true) === true));
    $done = array_values(array_filter($rows, static fn (array $row): bool => in_array((string) $row['status'], ['failed', 'expired'], true) === true));

    /** The kind of message, in a word or two. From what the page that wrote it said, never from its content. */
    $kind = static function (array $row): string {
        $origin = (string) $row['origin'];
        if (str_starts_with($origin, 'reset:') === true) {
            return 'Password reset';
        }
        if (str_starts_with($origin, 'newsletter:') === true) {
            return 'Newsletter';
        }
        if (str_starts_with($origin, 'statement:') === true) {
            return 'Giving statement';
        }

        return (int) $row['priority'] <= MailQueue::URGENT ? 'Urgent message' : ((int) $row['priority'] >= MailQueue::BULK ? 'Mailing' : 'Message');
    };
    /** Why a held message is held, in plain words. Fixed text: none of it comes from the mail server. */
    $heldBecause = [
        MailQueue::HELD_CERTIFICATE => 'the mail server\'s certificate failed its check. It goes by itself once the certificate passes.',
        MailQueue::HELD_INTERRUPTED => 'the mail job was interrupted while handing it to the mail server, so nobody can tell whether it went.',
        MailQueue::HELD_UNKNOWN     => 'the whole message was handed to the mail server and no answer came back, so nobody can tell whether it went.',
        MailQueue::HELD_RESTORED    => 'it came back with a restored backup. It was waiting when the backup was taken, and may have been sent since.',
    ];
    $labels = ['queued' => ['Waiting', 'info text-dark'], 'sending' => ['Being sent', 'primary'], 'held' => ['Held', 'warning text-dark'], 'failed' => ['Not sent', 'danger'], 'expired' => ['Expired, not sent', 'secondary']];
    ?>

<div class="card mt-3" id="mail-queue">
    <div class="card-body">
        <h2 class="h5"><i class="fa-solid fa-inbox me-1" aria-hidden="true"></i>Mail queue</h2>
        <p class="small text-muted">
            Mail that could not be sent at once waits here and is tried again by the scheduled mail job. A message that is sent leaves this list.
            <?php echo $isGlobal === true ? 'Every organisation&rsquo;s messages are listed.' : 'Only this organisation&rsquo;s messages are listed. Only a global administrator can send or delete one.'; ?>
            The content of a message is never shown here.
        </p>

        <?php if ($unreadable === true): ?>
            <p class="small text-danger mb-0" data-mail-queue="unreadable">
                The mail queue could not be read. If the portal has just been updated, the database upgrade has probably not been run yet:
                <a href="<?php echo $esc(\Portal\Core\Site::url('admin/upgrade')); ?>">run it at Admin, Upgrade</a>, then come back.
            </p>
        <?php elseif ($toGo === [] && $done === []): ?>
            <p class="mb-0" data-mail-queue="empty">Nothing is waiting.</p>
        <?php else: ?>
            <?php if ($isGlobal === true && $toGo !== []): ?>
                <form method="post" action="<?php echo $self; ?>" class="mb-3">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="queue_run">
                    <button type="submit" class="btn btn-outline-primary btn-sm">Send waiting mail now</button>
                    <span class="form-text d-block">
                        Tries what is waiting and due, once, without waiting for the scheduled job. It never sends a message that is held for a person to decide about.
                        Mail held for the certificate goes only if the certificate now passes the check. It can take up to half a minute.
                    </span>
                </form>
            <?php endif; ?>
            <?php if ($more === true): ?>
                <p class="small text-muted">More than 200 messages are in the queue. The first 200 are shown.</p>
            <?php endif; ?>

            <?php foreach (['Still to go' => $toGo, 'Given up on (kept for a while, then removed)' => $done] as $heading => $group): ?>
                <?php if ($group === []) { continue; } ?>
                <h3 class="h6 mt-3"><?php echo $esc($heading); ?> <span class="text-muted">(<?php echo count($group); ?>)</span></h3>
                <div class="portal-data-list">
                    <div class="portal-data-row portal-data-header d-none d-md-flex">
                        <div class="col-md-2">Message</div>
                        <div class="col-md-4">For, and its subject</div>
                        <div class="col-md-3">Why it waits</div>
                        <div class="col-md-3">When</div>
                    </div>
                    <?php foreach ($group as $row): ?>
                        <?php
                        $id        = (int) $row['queueID'];
                        $status    = (string) $row['status'];
                        $reason    = (string) $row['holdReason'];
                        $attempts  = (int) $row['attempts'];
                        $left      = $row['secondsLeft'] === null ? null : (int) $row['secondsLeft'];
                        $tooOld    = $row['tooOld'] === true;
                        $people    = (int) $row['recipientCount'];
                        $isReset   = str_starts_with((string) $row['origin'], 'reset:') === true;
                        // Whether nobody can tell if this one already went.
                        $mayHaveGone = $status === 'held' && in_array($reason, [MailQueue::HELD_INTERRUPTED, MailQueue::HELD_UNKNOWN, MailQueue::HELD_RESTORED], true) === true;
                        $pastUseBy   = $left !== null && $left <= 0;
                        ?>
                        <div class="portal-data-row" data-mail-queue-row="<?php echo $id; ?>" data-mail-queue-status="<?php echo $esc($status); ?>" data-mail-queue-reason="<?php echo $esc($reason); ?>">
                            <div class="col-12 col-md-2">
                                <strong><?php echo $esc($kind($row)); ?></strong><br>
                                <span class="badge bg-<?php echo $esc($labels[$status][1] ?? 'secondary'); ?>"><?php echo $esc($labels[$status][0] ?? $status); ?></span>
                            </div>
                            <div class="col-12 col-md-4 small text-break">
                                <?php echo $esc((string) $row['toRecipients']); ?><br>
                                <span class="text-muted"><?php echo $esc((string) $row['subject']); ?></span>
                                <?php if ((int) $row['attachmentCount'] > 0): ?>
                                    <br><span class="text-muted"><?php echo (int) $row['attachmentCount']; ?> file<?php echo (int) $row['attachmentCount'] === 1 ? '' : 's'; ?> attached</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-12 col-md-3 small text-break" data-mail-queue-why>
                                <?php if ($status === 'held'): ?>
                                    Held: <?php echo $esc($heldBecause[$reason] ?? 'for a reason the portal has no words for.'); ?>
                                <?php elseif ($status === 'sending'): ?>
                                    The mail job is sending it at this moment.
                                <?php elseif ($status === 'queued'): ?>
                                    <?php echo $attempts === 0 ? 'Waiting for its first try.' : 'It did not go and will be tried again.'; ?>
                                <?php elseif ($status === 'expired'): ?>
                                    Its use-by time passed before it could go.
                                <?php else: ?>
                                    It was given up on.
                                <?php endif; ?>
                                <br><span class="text-muted">Tried <?php echo $attempts; ?> time<?php echo $attempts === 1 ? '' : 's'; ?>.</span>
                                <?php if ($isGlobal === true && $status !== 'held' && $status !== 'sending' && (string) $row['lastError'] !== ''): ?>
                                    <?php // The mail server's own words: for a global administrator only (they can name an address, or the mailbox). ?>
                                    <br><span class="text-muted" data-mail-queue-said><?php echo $esc((string) $row['lastError']); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="col-12 col-md-3 small">
                                Kept <?php echo $esc($shown($row['createdAt'])); ?>
                                <?php if ($status === 'queued'): ?>
                                    <br>Next try not before <?php echo $esc($shown($row['nextAttemptAt'])); ?>
                                <?php endif; ?>
                                <?php if ($row['expiresAt'] !== null && $status !== 'expired' && $status !== 'failed'): ?>
                                    <br>
                                    <?php if ($pastUseBy === true): ?>
                                        <span class="text-danger">Its use-by time has passed: it will not be sent.</span>
                                    <?php else: ?>
                                        <span<?php echo $isReset === true ? ' class="fw-bold"' : ''; ?> data-mail-queue-use-by>
                                            <?php echo $isReset === true ? 'Its link stops working in ' : 'Not sent after '; ?><?php echo $isReset === true ? $esc(mail_queue_page_time_left((int) $left)) : $esc($shown($row['expiresAt'])); ?><?php echo $isReset === true ? ' (' . $esc($shown($row['expiresAt'])) . ')' : ''; ?>
                                        </span>
                                    <?php endif; ?>
                                <?php elseif ($row['expiresAt'] === null && ($status === 'queued' || $status === 'held')): ?>
                                    <br><span class="text-muted">No use-by time<?php echo $tooOld === true ? '' : ', but nothing older than the longest wait is sent'; ?>.</span>
                                <?php endif; ?>
                                <?php if ($tooOld === true && ($status === 'queued' || $status === 'held')): ?>
                                    <br><span class="text-danger" data-mail-queue-too-old>It is now too old to be sent, whoever asks.</span>
                                <?php endif; ?>
                            </div>

                            <?php if ($isGlobal === true && $status !== 'sending'): ?>
                                <div class="col-12 mt-2 d-flex flex-column flex-md-row flex-wrap gap-2 align-items-start">
                                    <?php // (Only while SMTP carries the mail: with another route in use the press would be refused, and the job releases such a message at its next run.) ?>
                                    <?php if ($inUse === true && $status === 'held' && $reason === MailQueue::HELD_CERTIFICATE && $print !== '' && $tooOld === false && $pastUseBy === false): ?>
                                        <details class="small border rounded p-2" data-mail-queue-action="send-past-check">
                                            <summary>Send this one with the check skipped</summary>
                                            <p class="mt-2 mb-2">
                                                This sends <strong>this one message</strong> now, and only if the mail server still shows exactly the certificate described above
                                                (fingerprint ending <code><?php echo $esc(substr($print, -8)); ?></code>). If it shows any other, nothing is sent and the message stays held.
                                                If that certificate is not really your mail server&rsquo;s, whoever is showing it receives the mailbox&rsquo;s user name and password, and this message.
                                                Every other message stays held.
                                            </p>
                                            <form method="post" action="<?php echo $self; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                                <input type="hidden" name="action" value="queue_send_past_check">
                                                <input type="hidden" name="queueId" value="<?php echo $id; ?>">
                                                <input type="hidden" name="fingerprint" value="<?php echo $esc($print); ?>">
                                                <div class="form-check mb-2">
                                                    <input class="form-check-input" type="checkbox" name="understood" id="past-check-<?php echo $id; ?>" value="1" required>
                                                    <label class="form-check-label" for="past-check-<?php echo $id; ?>">I have read this, and I want to send this one message.</label>
                                                </div>
                                                <button type="submit" class="btn btn-warning btn-sm">Send this one message</button>
                                            </form>
                                        </details>
                                    <?php endif; ?>

                                    <?php if ($mayHaveGone === true && $tooOld === false && $pastUseBy === false): ?>
                                        <details class="small border rounded p-2" data-mail-queue-action="send-again">
                                            <summary>Send it again</summary>
                                            <p class="mt-2 mb-2" data-mail-queue-second-copy>
                                                <strong><?php echo $people === 1 ? 'The person this is for may get a second copy.' : 'The ' . $people . ' people this is for may each get a second copy.'; ?></strong>
                                                Nobody can tell whether the first one arrived, and sending it again cannot take a first copy back.
                                                For a statement, a receipt or an invitation, a second copy can confuse or worry people.
                                                If you are not sure, ask whether it arrived before you send it again; if it did, delete this one.
                                            </p>
                                            <form method="post" action="<?php echo $self; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                                <input type="hidden" name="action" value="queue_send_again">
                                                <input type="hidden" name="queueId" value="<?php echo $id; ?>">
                                                <div class="form-check mb-2">
                                                    <input class="form-check-input" type="checkbox" name="understood" id="send-again-<?php echo $id; ?>" value="1" required>
                                                    <label class="form-check-label" for="send-again-<?php echo $id; ?>">I understand that <?php echo $people === 1 ? 'the person' : 'each person'; ?> may get a second copy.</label>
                                                </div>
                                                <button type="submit" class="btn btn-outline-primary btn-sm">Send it again</button>
                                            </form>
                                        </details>
                                    <?php endif; ?>

                                    <details class="small border rounded p-2" data-mail-queue-action="delete">
                                        <summary>Delete</summary>
                                        <p class="mt-2 mb-2">
                                            <?php if ($status === 'queued' || $status === 'held'): ?>
                                                The message is removed and will <strong>not</strong> be sent. This cannot be undone: the content of a deleted message is gone.
                                            <?php else: ?>
                                                This only clears the line away. The message was not sent and is not being tried again; it would be removed by itself in a few days.
                                            <?php endif; ?>
                                        </p>
                                        <form method="post" action="<?php echo $self; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="action" value="queue_delete">
                                            <input type="hidden" name="queueId" value="<?php echo $id; ?>">
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="checkbox" name="understood" id="delete-<?php echo $id; ?>" value="1" required>
                                                <label class="form-check-label" for="delete-<?php echo $id; ?>">Yes, delete it.</label>
                                            </div>
                                            <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                                        </form>
                                    </details>

                                    <?php if ($status === 'failed'): ?>
                                        <span class="small text-muted">A message that was given up on is not sent again from here. To send it again, send it again from the page it was written on.</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
    <?php
}
