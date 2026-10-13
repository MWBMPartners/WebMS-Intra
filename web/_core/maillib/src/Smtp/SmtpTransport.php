<?php
// Path: web/_core/maillib/src/Smtp/SmtpTransport.php
/**
 * -----------------------------------------------------------------------------
 * SmtpTransport — send a message through an SMTP mailbox 📮
 * -----------------------------------------------------------------------------
 * The SMTP route: it holds the order of the conversation with a mail server
 * and turns whatever happens into one of the four outcomes of `SendResult`.
 * `Connection` does the reading and writing; this class decides what to say
 * next and what each answer means.
 *
 * THE CONVERSATION, in order:
 *   connect → (encryption, if "tls") → the server's greeting → introduce
 *   ourselves → (ask for encryption and switch it on, if "starttls", then
 *   introduce ourselves again) → sign in → for each message: sender,
 *   recipients, "here comes the message", the message, the server's verdict.
 *
 * THE RULES IT KEEPS
 *   - 🔒 Always encrypted. No sign-in line, no address and no message is
 *     written before encryption is on. A server that does not offer
 *     encryption gets nothing. The certificate is checked in full unless the
 *     caller asks otherwise for that send (`SendOptions`): for ONE named
 *     certificate, or, since 13 October 2026, for no check at all.
 *   - 🔓 "No check at all" is still two careful steps, on every new
 *     connection: the certificate is READ on a connection that cannot write
 *     (`CertificateReader`), and the sending connection then accepts exactly
 *     the certificate that was read. See `connect()`. It cannot tell the mail
 *     server from an impostor: that is what the caller gave up by asking.
 *   - 🪪 When the certificate check fails, nothing is sent, and the result
 *     says WHY (see `CertificateReader`), as `needs-person`. The certificate
 *     is blamed only when PHP says it refused the certificate, or this
 *     library's own name rule did. A handshake that failed any other way is
 *     `try-later`: an application may hold all mail on a certificate fault,
 *     so a passing fault on the line must never be reported as one.
 *   - 🚦 Refuse when unsure. Each step expects one exact reply number. Any
 *     other is a failure carrying the server's own words; it is never read as
 *     "probably fine".
 *   - 👥 All recipients or none. If the server refuses one recipient of
 *     several, the message is not sent to the others either, so the result
 *     never means "partly sent".
 *   - ♻️ One connection carries many messages. It is cleared (RSET) before
 *     each one. If the server has closed it in the meantime, one new
 *     connection is made. After a failure the connection is always left in
 *     one of two known states: cleared and ready, or closed.
 *   - The moment the whole message has been handed over is the line between
 *     two kinds of failure. Before it, nothing can have been sent: `try-later`.
 *     After it, a missing or garbled verdict means nobody can tell:
 *     `needs-person`, and it is NOT sent again here, because that could
 *     deliver it twice.
 *
 * WHAT IT CANNOT DO
 *   - It cannot rule out a second copy if the server accepts a message and
 *     its "accepted" reply is then lost. The message keeps one identifier, so
 *     many mail systems drop the duplicate; no more than that is promised.
 *   - "Accepted" means the mail server took the message, not that it reached
 *     an inbox.
 *   - It signs in with PLAIN or LOGIN only. A server offering neither, or no
 *     sign-in at all, cannot be used.
 *   - One message in all is held to `messageSeconds` (30 by default) up to the
 *     moment it is handed over, plus `bodyReplySeconds` for the verdict. A
 *     very large message over a slow line will not fit and comes back as
 *     `try-later`; whoever sets the limits must allow for the largest message.
 *     When a certificate is refused, reading it to say why takes one more
 *     connection, held to `connectSeconds` plus `handshakeSeconds`. So ONE
 *     SEND CAN LAST ABOUT 50 SECONDS with the default limits: 30 + 20 for
 *     the verdict, or 30 + 16 to read a refused certificate, or 30 + 10 for
 *     clearing the connection after a refused recipient. Never two of those.
 *
 * @package   MWBM\Mail\Smtp
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.2.0
 * @link      https://www.rfc-editor.org/rfc/rfc5321 (SMTP)
 * @link      https://www.rfc-editor.org/rfc/rfc4954 (signing in)
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace MWBM\Mail\Smtp;

use MWBM\Mail\CertificateFacts;
use MWBM\Mail\Clock;
use MWBM\Mail\LogSink;
use MWBM\Mail\Message;
use MWBM\Mail\MessageRefused;
use MWBM\Mail\Secret;
use MWBM\Mail\SendOptions;
use MWBM\Mail\SendResult;
use MWBM\Mail\SystemClock;
use MWBM\Mail\Transport;

final class SmtpTransport implements Transport
{
    /** What to say when a step failed without the server saying anything usable. */
    private const WORDS = [
        SendResult::REASON_TIMEOUT => 'The server did not answer in time.',
        SendResult::REASON_DROPPED => 'The connection was closed before the server answered.',
        SendResult::REASON_UNEXPECTED => 'The server sent something that is not a proper reply.',
        SendResult::REASON_INJECTED_TEXT => 'The server sent extra text before encryption began. That is how an '
            . 'eavesdropper tampers with a connection, so it was closed and nothing was sent.',
        SendResult::REASON_INTERNAL => 'The mail client stopped itself before sending.',
        SendResult::REASON_ENCRYPTION_FAILED => 'Encryption could not be switched on, so nothing was sent. PHP did not say '
            . 'the certificate was at fault, so this is most likely a passing fault on the line.',
        SendResult::REASON_NO_ENCRYPTION => 'No encrypted connection this client accepts could be made with the server (it '
            . 'needs TLS 1.2 or later). Nothing was sent.',
    ];

    private readonly Clock $clock;

    /**
     * What `$connectionRule` holds for a connection made with the certificate
     * not checked. It can never be mistaken for a fingerprint, which is 64
     * hexadecimal characters.
     */
    private const NOT_CHECKED = 'not-checked';

    private ?Connection $connection = null;

    /**
     * The certificate rule the open connection was made under: empty for
     * checked, `NOT_CHECKED`, or the fingerprint of the one certificate that
     * was asked for.
     */
    private string $connectionRule = '';

    private int $sentOnConnection = 0;

    /** @var array<string,string> What the server says it supports: KEYWORD => the rest of its line. */
    private array $offers = [];

    // The three below describe the send in progress and are reset by each send().
    private string $stage = SendResult::STAGE_CONFIG;
    private bool $handedOver = false;
    private float $deadline = 0.0;

    /** @var list<string> The conversation of the send in progress, for the result. */
    private array $steps = [];

    /**
     * @param Clock|null   $clock Leave out for the real clock.
     * @param LogSink|null $log   Leave out to log nothing.
     */
    public function __construct(
        private readonly SmtpConfig $config,
        ?Clock $clock = null,
        private readonly ?LogSink $log = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    public function name(): string
    {
        return 'smtp';
    }

    /** @return list<string> */
    public function ready(): array
    {
        return $this->config->missing();
    }

    public function send(Message $message, ?SendOptions $options = null): SendResult
    {
        $this->stage = SendResult::STAGE_CONFIG;
        $this->handedOver = false;
        $this->steps = [];
        try {
            $result = $this->attempt($message, $options ?? SendOptions::checked());
        } catch (\Throwable $fault) {
            // Nothing leaves a route as an exception. The connection is in an
            // unknown state, so it is closed; and if the message had already
            // been handed over, nobody can say whether it went.
            $this->hangUp(false);
            $result = $this->result(
                $this->handedOver === true ? SendResult::NEEDS_PERSON : SendResult::TRY_LATER,
                $this->handedOver === true ? SendResult::REASON_UNKNOWN_IF_SENT : SendResult::REASON_INTERNAL,
                0,
                'An unexpected fault inside the mail client: ' . get_class($fault) . ': ' . $fault->getMessage(),
            );
        }
        $this->report($result, $message);
        return $result;
    }

    public function close(): void
    {
        $this->hangUp(true);
        $this->steps = [];
    }

    // -------------------------------------------------------------------------
    // ✉️ One message
    // -------------------------------------------------------------------------

    private function attempt(Message $message, SendOptions $options): SendResult
    {
        $missing = $this->config->missing();
        if ($missing !== []) {
            return $this->result(SendResult::TRY_LATER, SendResult::REASON_NOT_SET_UP, 0, implode(' ', $missing));
        }

        // 📏 Too big for this route: refused before any connection is made.
        // Never sent with something left out to make it fit.
        $this->stage = SendResult::STAGE_MESSAGE;
        try {
            $size = $message->size();
            // 🧮 Writing the message to the server needs room for two more
            // copies of it. Refused in words here, because running out of
            // memory part way is an error nothing can catch.
            Message::needRoomFor(2 * $size);
        } catch (MessageRefused $refusal) {
            return $this->result(SendResult::REFUSED, SendResult::REASON_TOO_BIG, 0, $refusal->getMessage() . ' Nothing was sent.');
        }
        if ($size > $this->config->maxMessageBytes) {
            return $this->result(SendResult::REFUSED, SendResult::REASON_TOO_BIG, 0, 'The message is ' . $size
                . ' bytes. The most this route sends is ' . $this->config->maxMessageBytes . ' bytes. Nothing was sent.');
        }

        $this->deadline = $this->clock->ticks() + $this->config->messageSeconds;
        $rule = $options->notChecked === true ? self::NOT_CHECKED : $options->onlyCertificateSha256;

        // ♻️ Use the open connection if it was made under the same certificate
        // rule and has not carried its fill.
        // 🔒 The first test matters most: a connection opened for one approved
        // certificate, or with the certificate not checked, must never carry
        // a message that did not ask for exactly that.
        if (
            $this->connection !== null
            && ($this->connectionRule !== $rule || $this->sentOnConnection >= $this->config->messagesPerConnection)
        ) {
            $this->hangUp(true);
        }
        if ($this->connection !== null) {
            // RSET clears anything left from the last message, and at the same
            // time shows whether the server is still there.
            $this->stage = SendResult::STAGE_RESET;
            if ($this->isReply($this->connection->command('RSET', $this->replyBy()), 250) !== true) {
                $this->hangUp(false);
                $this->steps[] = 'The open connection could no longer be used. Connecting again, once.';
            }
        }
        if ($this->connection === null) {
            $failure = $this->connect($rule);
            if ($failure !== null) {
                return $failure;
            }
        }
        $connection = $this->connection;
        if ($connection === null) {
            throw new \LogicException('The connection was lost between opening it and using it.');
        }

        // 📏 The server may advertise a smaller limit than ours.
        $this->stage = SendResult::STAGE_MESSAGE;
        $advertised = $this->offers['SIZE'] ?? '';
        if (preg_match('/\A[0-9]{1,15}\z/', $advertised) === 1 && (int) $advertised > 0 && $size > (int) $advertised) {
            return $this->result(SendResult::REFUSED, SendResult::REASON_TOO_BIG, 0, 'The message is ' . $size
                . ' bytes. The mail server accepts at most ' . $advertised . ' bytes. Nothing was sent.');
        }

        // The envelope. Both kinds of address were checked by Message, and
        // Connection refuses any command holding a line break.
        $this->stage = SendResult::STAGE_SENDER;
        $reply = $connection->command('MAIL FROM:<' . $message->sender() . '>', $this->replyBy());
        if ($this->isReply($reply, 250) !== true) {
            return $this->failed($reply, true);
        }

        // 👥 All recipients or none.
        $this->stage = SendResult::STAGE_RECIPIENT;
        $refusals = [];
        $firstRefusal = 0;
        $forGood = false;
        foreach ($message->recipients() as $address) {
            $reply = $connection->command('RCPT TO:<' . $address . '>', $this->replyBy());
            if ($this->isReply($reply, 250) === true || $this->isReply($reply, 251) === true) {
                continue;
            }
            if ($reply['problem'] !== '' || $reply['code'] < 400) {
                return $this->failed($reply, true);
            }
            // (Made safe and cut to length in `result()`, with everything else that leaves this class.)
            $refusals[$address] = $reply['code'] . ' ' . $reply['text'];
            $firstRefusal = $firstRefusal === 0 ? $reply['code'] : $firstRefusal;
            $forGood = $forGood === true || $reply['code'] >= 500;
        }
        if ($refusals !== []) {
            $this->reset();
            return $this->result(
                $forGood === true ? SendResult::REFUSED : SendResult::TRY_LATER,
                $forGood === true ? SendResult::REASON_SERVER_SAID_NO : SendResult::REASON_SERVER_SAID_LATER,
                $firstRefusal,
                count($refusals) . ' of ' . count($message->recipients()) . ' recipients were not accepted by the server, '
                . 'so the message was sent to nobody. The server said: ' . implode(' | ', $refusals),
                null,
                $refusals,
            );
        }

        $this->stage = SendResult::STAGE_DATA;
        $reply = $connection->command('DATA', $this->replyBy());
        if ($this->isReply($reply, 354) !== true) {
            return $this->failed($reply, true);
        }

        $this->stage = SendResult::STAGE_BODY;
        $problem = $connection->sendBody($message->bytes(), $this->deadline);
        if ($problem === Connection::SPOKE_DURING_MESSAGE) {
            // ⚠️ The whole message went out, but the server had already said
            // something while it was going. That early text is not a verdict
            // on a message the server had not finished reading, and the real
            // verdict can no longer be told from it. Nobody knows.
            $this->handedOver = true;
            $this->hangUp(false);
            return $this->result(SendResult::NEEDS_PERSON, SendResult::REASON_UNKNOWN_IF_SENT, 0, 'The whole message '
                . 'was handed to the server, but the server spoke before it had all of it, so its answer cannot be '
                . 'trusted and nobody can tell whether the message was sent. It has not been sent again.');
        }
        if ($problem === SendResult::REASON_UNEXPECTED) {
            // The server spoke between "go ahead" and the message. Nothing of
            // the message was written, so nothing was sent.
            $this->hangUp(false);
            return $this->result(SendResult::TRY_LATER, SendResult::REASON_UNEXPECTED, 0, 'The server said "go ahead" and '
                . 'then said something more before the message was sent. The conversation was out of step, so it was '
                . 'closed and nothing was sent.');
        }
        if ($problem !== '') {
            // The line that ends the message never went out whole, so the
            // server cannot have taken the message. If it said why before the
            // connection ended ("552 too big", then it hangs up), that is its
            // answer, and it is kept: without it a message that can never go
            // would be retried for ever. Only what has ALREADY arrived is
            // read (the limit is "now"); a reply that was lost with the
            // connection leaves the plain "try later" below.
            $early = $connection->readReply($this->clock->ticks());
            $this->hangUp(false);
            if ($early['problem'] === '' && $early['code'] >= 400) {
                $later = $early['code'] <= 499;
                return $this->result(
                    $later === true ? SendResult::TRY_LATER : SendResult::REFUSED,
                    $later === true ? SendResult::REASON_SERVER_SAID_LATER : SendResult::REASON_SERVER_SAID_NO,
                    $early['code'],
                    $early['text'] . ' (The server said this part way through the message and stopped taking it in. Nothing was sent.)',
                );
            }
            return $this->result(
                SendResult::TRY_LATER,
                $problem === Connection::NOT_ALLOWED ? SendResult::REASON_INTERNAL : $problem,
                0,
                'The message could not be handed over whole, so it was not sent. '
                . ($problem === SendResult::REASON_TIMEOUT
                    ? 'The server stopped taking it in.'
                    : 'The connection was closed part way through.'),
            );
        }

        // ⚠️ From here the server holds the whole message. The verdict gets its
        // own full time limit, whatever is left of the message's: giving up
        // early here would turn a slow "accepted" into a "nobody knows".
        $this->handedOver = true;
        $reply = $connection->readReply($this->clock->ticks() + $this->config->bodyReplySeconds);
        if ($this->isReply($reply, 250) === true) {
            $this->sentOnConnection++;
            return $this->result(SendResult::ACCEPTED, SendResult::REASON_OK, 250, $reply['text']);
        }
        if ($reply['problem'] === '' && $reply['code'] >= 400) {
            // A clear "no" or "later". The server did not keep the message.
            $later = $reply['code'] <= 499;
            if ($reply['code'] === 421) {
                $this->hangUp(false);
            }
            return $this->result(
                $later === true ? SendResult::TRY_LATER : SendResult::REFUSED,
                $later === true ? SendResult::REASON_SERVER_SAID_LATER : SendResult::REASON_SERVER_SAID_NO,
                $reply['code'],
                $reply['text'],
            );
        }
        $this->hangUp(false);
        return $this->result(
            SendResult::NEEDS_PERSON,
            SendResult::REASON_UNKNOWN_IF_SENT,
            $reply['code'],
            'The whole message was handed to the server, but its answer '
            . ($reply['problem'] === '' ? 'was not one this client understands' : 'never arrived')
            . ', so nobody can tell whether it was sent. It has not been sent again. '
            . (self::WORDS[$reply['problem']] ?? '')
            . ($reply['text'] === '' ? '' : ' The server said: ' . $reply['text']),
        );
    }

    // -------------------------------------------------------------------------
    // 🔌 Setting a connection up
    // -------------------------------------------------------------------------

    /**
     * Open a connection and take it as far as "signed in".
     *
     * @param string $rule Empty for the full check, `NOT_CHECKED`, or the fingerprint of the one
     *                     certificate to accept.
     *
     * @return SendResult|null Null when signed in; otherwise why not, with the connection closed.
     */
    private function connect(string $rule): ?SendResult
    {
        $notChecked = $rule === self::NOT_CHECKED;
        $pin = $notChecked === true ? '' : $rule;
        if ($notChecked === true) {
            // 🔓 "The certificate is not checked", in two steps. FIRST read
            // the certificate the server shows, on a connection that can
            // write nothing once encryption is on. THEN open the connection
            // that will send, and let it accept exactly that certificate.
            // The sending connection is therefore the same "one certificate"
            // connection a named fingerprint gets, with every rule of that
            // mode still in force: TLS 1.2 or later, and nothing private
            // before encryption is on.
            // WHAT THIS DOES NOT DO: tell the mail server from an impostor.
            // Whoever answers is read, and then accepted.
            $this->stage = SendResult::STAGE_CONNECT;
            $reader = new CertificateReader($this->config, $this->clock);
            $read = $reader->read();
            array_push($this->steps, ...$reader->transcript());
            if ($read === null) {
                // No encrypted connection could be made to read it from. The
                // reader says where it stopped; nothing was sent, and no
                // sign-in was attempted, exactly as on a checked connection.
                $this->stage = $reader->stoppedAt() === '' ? SendResult::STAGE_TLS : $reader->stoppedAt();
                $why = $reader->stoppedWhy() === '' || $reader->stoppedWhy() === SendResult::REASON_CERTIFICATE
                    ? SendResult::REASON_ENCRYPTION_FAILED
                    : $reader->stoppedWhy();
                $said = $reader->stoppedWords();
                return $this->result(SendResult::TRY_LATER, $why, 0, 'No encrypted connection could be made to the mail '
                    . 'server, so nothing was sent and no sign-in was attempted. Encryption (TLS 1.2 or later) is required '
                    . 'even when the certificate is not being checked. ' . (self::WORDS[$why] ?? '')
                    . ($said === '' ? '' : ' PHP said: ' . $said));
            }
            $pin = $read->sha256;
            $this->steps[] = 'The certificate is NOT being checked. The one the server showed (fingerprint ' . $pin
                . ') is accepted as it is for this connection.';
        }
        $connection = new Connection(
            $this->config,
            $this->clock,
            $pin === '' ? Connection::MODE_CHECKED : Connection::MODE_ONE_CERTIFICATE,
            $pin,
        );
        $this->connection = $connection;
        $this->connectionRule = $rule;
        $this->sentOnConnection = 0;
        $this->offers = [];

        $this->stage = SendResult::STAGE_CONNECT;
        $problem = $connection->open($this->deadline);
        if ($problem !== '') {
            $said = $connection->lastWords();
            $this->hangUp(false);
            return $this->result(SendResult::TRY_LATER, $problem, 0, 'Could not reach ' . $this->config->host
                . ' on port ' . $this->config->port . '.' . ($said === '' ? '' : ' ' . $said));
        }

        // "tls": encryption comes before the server says anything at all.
        if ($this->config->security === SmtpConfig::TLS) {
            $failure = $this->encrypt($pin, $notChecked);
            if ($failure !== null) {
                return $failure;
            }
        }

        $this->stage = SendResult::STAGE_GREETING;
        $reply = $connection->readReply($this->replyBy());
        if ($this->isReply($reply, 220) !== true) {
            return $this->failed($reply, false);
        }
        $failure = $this->introduce();
        if ($failure !== null) {
            return $failure;
        }

        if ($this->config->security === SmtpConfig::STARTTLS) {
            $this->stage = SendResult::STAGE_STARTTLS;
            // 🔒 No offer of encryption means nothing more is said. An
            // attacker between us and the server can strip the offer out to
            // make a careless client carry on in the clear.
            if (isset($this->offers['STARTTLS']) !== true) {
                $this->hangUp(false);
                return $this->result(SendResult::TRY_LATER, SendResult::REASON_NO_ENCRYPTION, 0, 'The server did not offer '
                    . 'to encrypt the connection (STARTTLS). Nothing was sent and no sign-in was attempted.');
            }
            $reply = $connection->command('STARTTLS', $this->replyBy());
            if ($this->isReply($reply, 220) !== true) {
                return $this->failed($reply, false);
            }
            $failure = $this->encrypt($pin, $notChecked);
            if ($failure !== null) {
                return $failure;
            }
            // What the server offered before encryption could have been
            // altered on the way, so it is thrown away and asked for again
            // (RFC 3207 section 4.2).
            $failure = $this->introduce();
            if ($failure !== null) {
                return $failure;
            }
        }
        return $this->signIn();
    }

    /** Say EHLO and note what the server supports. */
    private function introduce(): ?SendResult
    {
        $this->stage = SendResult::STAGE_EHLO;
        $connection = $this->connection;
        if ($connection === null) {
            throw new \LogicException('There is no connection to introduce ourselves on.');
        }
        $reply = $connection->command('EHLO ' . $this->config->clientName, $this->replyBy());
        if ($this->isReply($reply, 250) !== true) {
            return $this->failed($reply, false);
        }
        $this->offers = [];
        // The first line is the server's name; each line after it is one thing
        // it supports, such as "SIZE 35882577" or "AUTH PLAIN LOGIN". Some old
        // servers write "AUTH=PLAIN LOGIN", hence the "=".
        foreach (array_slice($reply['lines'], 1) as $line) {
            $parts = preg_split('/[ =]+/', strtoupper(trim($line)), 2);
            if (is_array($parts) === true && $parts[0] !== '') {
                $this->offers[$parts[0]] = trim(($this->offers[$parts[0]] ?? '') . ' ' . ($parts[1] ?? ''));
            }
        }
        return null;
    }

    /**
     * Switch encryption on. When that is refused, find out why.
     *
     * @param string $pin        Empty for the full check; otherwise the fingerprint the connection accepts.
     * @param bool   $notChecked True when that fingerprint was read from the server a moment ago
     *                           (`SendOptions::certificateNotChecked()`), and not named by a person.
     *
     * @return SendResult|null Null when encryption is on and the certificate passed.
     */
    private function encrypt(string $pin, bool $notChecked = false): ?SendResult
    {
        $this->stage = SendResult::STAGE_TLS;
        $connection = $this->connection;
        if ($connection === null) {
            throw new \LogicException('There is no connection to encrypt.');
        }
        $problem = $connection->startEncryption($this->deadline);
        if ($problem === '') {
            return null;
        }
        $said = $connection->lastWords();
        $facts = $connection->refusedCertificate();
        $this->hangUp(false);
        if ($problem !== SendResult::REASON_CERTIFICATE) {
            // 🚦 Not the certificate: too slow, cut off, answered with an
            // alert, no shared version of TLS, or text injected before it.
            // No second connection is made, and no certificate is offered
            // for approval: there is nothing about it to decide.
            // (Every failed handshake used to be read as a refused
            // certificate, so one dropped connection raised a false alarm
            // beside a perfectly good certificate.)
            return $this->result(SendResult::TRY_LATER, $problem, 0, (self::WORDS[$problem] ?? 'Encryption could not be '
                . 'switched on.') . ($said === '' ? '' : ' PHP said: ' . $said));
        }
        if ($notChecked === true) {
            // 🔓 The certificate is not being checked, and the server showed
            // one certificate when it was read and ANOTHER a moment later.
            // That is not for a person to decide (nobody approved either), so
            // it is "try later": the next attempt reads again. It is NOT
            // quietly accepted here, because then the certificate this
            // connection used would be one that was never read.
            return $this->result(SendResult::TRY_LATER, SendResult::REASON_ENCRYPTION_FAILED, 0, 'The mail server showed one '
                . 'certificate when it was read and a different one a moment later, so nothing was sent. It will be tried '
                . 'again.' . ($said === '' ? '' : ' PHP said: ' . $said));
        }

        // 🪪 The certificate was refused. If this library refused it by its
        // own rule, its facts are already here. If PHP refused it, PHP says
        // only "verify failed" whatever the cause, so the certificate is read
        // on a second connection that can write nothing private.
        if ($facts === null) {
            $reader = new CertificateReader($this->config, $this->clock);
            $facts = $reader->read();
            array_push($this->steps, ...$reader->transcript());
            if ($facts === null) {
                // PHP refused a certificate, and it could not be read a
                // second time. Still the certificate, still for a person:
                // reporting "try later" here would let somebody in the middle
                // hide a bad certificate from the administrator for ever by
                // dropping every second connection.
                return $this->result(SendResult::NEEDS_PERSON, SendResult::REASON_CERTIFICATE, 0, "The mail server's "
                    . 'certificate was refused, so nothing was sent. It could not be read a second time to say what is '
                    . 'wrong with it.' . ($said === '' ? '' : ' PHP said: ' . $said));
            }
            if ($pin !== '') {
                if (hash_equals($pin, $facts->sha256) === true) {
                    return $this->result(SendResult::TRY_LATER, SendResult::REASON_ENCRYPTION_FAILED, 0, 'The server first '
                        . 'showed a certificate that is not the approved one, and then, when asked again, the approved '
                        . 'one. Nothing was sent.' . ($said === '' ? '' : ' PHP said: ' . $said));
                }
                $facts = $facts->withReason(CertificateFacts::NOT_APPROVED);
            } else {
                // Nothing certain wrong with the certificate itself, yet PHP
                // refused it: name the most likely cause, and say "most likely".
                $facts = $facts->withLikelyCause();
            }
        }
        return $this->result(
            SendResult::NEEDS_PERSON,
            SendResult::REASON_CERTIFICATE,
            0,
            "The mail server's certificate was not accepted, so nothing was sent. " . implode(' ', $facts->inWords()),
            $facts,
        );
    }

    /** Sign in with the first method both sides know. */
    private function signIn(): ?SendResult
    {
        $this->stage = SendResult::STAGE_AUTH;
        $connection = $this->connection;
        // 🔒 The second lock on the same door: `Connection::secretCommand()`
        // also refuses a sign-in line on a connection that is not encrypted.
        if ($connection === null || $connection->isEncrypted() !== true) {
            $this->hangUp(false);
            return $this->result(SendResult::TRY_LATER, SendResult::REASON_NO_ENCRYPTION, 0, 'Encryption is not on, so no '
                . 'sign-in was attempted and nothing was sent.');
        }
        $methods = preg_split('/\s+/', $this->offers['AUTH'] ?? '', -1, PREG_SPLIT_NO_EMPTY);
        $methods = is_array($methods) === true ? $methods : [];
        $shown = Secret::HIDDEN;

        if (in_array('PLAIN', $methods, true) === true) {
            // The password is taken out of its wrapper inside this one
            // expression and is never given a variable of its own.
            $reply = $connection->secretCommand(
                'AUTH PLAIN ' . base64_encode("\0" . $this->config->username . "\0" . $this->config->password->reveal()),
                $this->replyBy(),
                'AUTH PLAIN ' . $shown,
            );
        } elseif (in_array('LOGIN', $methods, true) === true) {
            // LOGIN asks for the user name and the password in turn. Each
            // step must be answered "334, go on" or the sign-in stops there:
            // the password is not offered to a server that did not ask.
            $reply = $connection->command('AUTH LOGIN', $this->replyBy());
            if ($this->isReply($reply, 334) !== true) {
                return $this->failed($reply, false);
            }
            $reply = $connection->secretCommand(base64_encode($this->config->username), $this->replyBy(), $shown);
            if ($this->isReply($reply, 334) !== true) {
                return $this->failed($reply, false);
            }
            $reply = $connection->secretCommand(
                base64_encode($this->config->password->reveal()),
                $this->replyBy(),
                $shown,
            );
        } else {
            $this->hangUp(false);
            return $this->result(SendResult::TRY_LATER, SendResult::REASON_NO_SIGN_IN_METHOD, 0, 'The server offers no way '
                . 'of signing in that this client knows (PLAIN or LOGIN). It offers: '
                . ($methods === [] ? 'none' : implode(', ', $methods)) . '.');
        }
        if ($this->isReply($reply, 235) !== true) {
            return $this->failed($reply, false);
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // 🧰 Small helpers
    // -------------------------------------------------------------------------

    /**
     * True when a whole reply was read and it carries exactly this number.
     *
     * @param array{problem:string,code:int,text:string,lines:list<string>} $reply
     */
    private function isReply(array $reply, int $code): bool
    {
        return $reply['problem'] === '' && $reply['code'] === $code;
    }

    /** The latest moment the next ordinary reply may arrive: its own limit, or the message's, whichever is sooner. */
    private function replyBy(): float
    {
        return min($this->deadline, $this->clock->ticks() + $this->config->replySeconds);
    }

    /**
     * Turn a reply that was not the one wanted into a result, and leave the
     * connection in a known state. Only for steps BEFORE the message is handed
     * over, where nothing can have been sent.
     *
     * - "4xx" is the server saying later: `try-later`.
     * - "5xx" is the server saying no: `refused`.
     * - No reply, a broken one, or a number that does not belong at this step:
     *   `try-later`, and the connection is closed, because what the server
     *   will do next can no longer be predicted.
     *
     * @param array{problem:string,code:int,text:string,lines:list<string>} $reply
     * @param bool                                                           $inMessage True when the connection is
     *                                                                                  signed in and worth keeping.
     */
    private function failed(array $reply, bool $inMessage): SendResult
    {
        if ($reply['problem'] !== '') {
            $this->hangUp(false);
            $reason = $reply['problem'] === Connection::NOT_ALLOWED ? SendResult::REASON_INTERNAL : $reply['problem'];
            return $this->result(SendResult::TRY_LATER, $reason, 0, trim((self::WORDS[$reason] ?? '')
                . ($reply['text'] === '' ? '' : ' It said: ' . $reply['text'])));
        }
        $code = $reply['code'];
        if ($code >= 400 && $code <= 499) {
            $outcome = SendResult::TRY_LATER;
            $reason = SendResult::REASON_SERVER_SAID_LATER;
        } elseif ($code >= 500) {
            $outcome = SendResult::REFUSED;
            $reason = SendResult::REASON_SERVER_SAID_NO;
        } else {
            $outcome = SendResult::TRY_LATER;
            $reason = SendResult::REASON_UNEXPECTED;
        }
        // 421 means "I am closing this connection".
        if ($inMessage === true && $reason !== SendResult::REASON_UNEXPECTED && $code !== 421) {
            $this->reset();
        } else {
            $this->hangUp(false);
        }
        return $this->result($outcome, $reason, $code, $reply['text']);
    }

    /** Clear the message in progress. If the server will not confirm that, close the connection. */
    private function reset(): void
    {
        if ($this->connection === null) {
            return;
        }
        $limit = $this->clock->ticks() + $this->config->replySeconds;
        if ($this->isReply($this->connection->command('RSET', $limit), 250) !== true) {
            $this->hangUp(false);
        }
    }

    /** Close the connection, keeping what was said on it for the result. */
    private function hangUp(bool $sayGoodbye): void
    {
        if ($this->connection === null) {
            return;
        }
        if ($sayGoodbye === true) {
            $this->connection->close(true);
        }
        array_push($this->steps, ...$this->connection->takeTranscript());
        $this->connection->close(false);
        $this->connection = null;
        $this->connectionRule = '';
        $this->sentOnConnection = 0;
        $this->offers = [];
    }

    /**
     * Build the result for the send in progress.
     *
     * @param array<string,string> $recipientReplies
     */
    private function result(
        string $outcome,
        string $reason,
        int $code,
        string $words,
        ?CertificateFacts $certificate = null,
        array $recipientReplies = [],
    ): SendResult {
        if ($this->connection !== null) {
            array_push($this->steps, ...$this->connection->takeTranscript());
        }
        // 🔒 Every word that leaves this class goes through the password
        // remover HERE, whichever path it took to get here: the words, each
        // line of the transcript, and each recipient's reply. `Connection`
        // already removes the password from every reply as it is read; this
        // is the second lock, so that neither depends on the other.
        // (The recipients' replies and the transcript used to skip this one,
        // and the comment here said they did not.)
        $safe = fn (string $text, int $most): string => CertificateFacts::clean($this->config->scrub($text), $most);
        $replies = [];
        foreach ($recipientReplies as $address => $reply) {
            // Cut to a sensible length: a server may answer at any length it likes.
            $replies[$address] = $safe->__invoke($reply, 500);
        }
        $steps = [];
        foreach ($this->steps as $step) {
            $steps[] = $safe->__invoke($step, 1000);
        }
        return new SendResult(
            $outcome,
            $this->stage,
            $reason,
            $code,
            $safe->__invoke($words, 1500),
            $certificate,
            $steps,
            $replies,
        );
    }

    /** Tell the application's log, if it gave us one. */
    private function report(SendResult $result, Message $message): void
    {
        if ($this->log === null) {
            return;
        }
        try {
            $this->log->record(
                $result->outcome,
                $result->stage,
                $result->reason . ($result->serverWords === '' ? '' : ': ' . $result->serverWords),
                [
                    'code' => $result->code,
                    'messageId' => $message->messageId(),
                    'recipients' => count($message->recipients()),
                    'attachments' => $message->attachmentCount(),
                ],
            );
        } catch (\Throwable) {
            // A log that fails must not turn a sent message into a failed one,
            // nor a failed one into a crash.
        }
    }
}
