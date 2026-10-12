<?php
// Path: web/_core/maillib/src/SendResult.php
/**
 * -----------------------------------------------------------------------------
 * SendResult — what happened to one message 📬
 * -----------------------------------------------------------------------------
 * Every send ends in exactly one of FOUR outcomes. They are kept to four
 * because each one asks the caller to do something different:
 *
 *   accepted      The mail service took the message. Nothing more to do.
 *                 (Accepted is not the same as delivered to the inbox.)
 *   try-later     Nothing was sent, and trying again may work: the server
 *                 could not be reached, was too slow, or said "later".
 *   refused       Nothing was sent, and trying the same thing again will not
 *                 work: the server said no (a reply beginning with 5), or the
 *                 message is too big to go.
 *   needs-person  A person must look before anything else happens. Either the
 *                 server's certificate failed its check, or something failed
 *                 AFTER the whole message had been handed over, so nobody can
 *                 tell whether it was sent. (A handshake that failed for any
 *                 reason but the certificate is `try-later`, not this.)
 *
 * Beside the outcome: the STAGE of the conversation where it ended, a short
 * REASON, the server's reply number and its own words, the certificate's facts
 * when the certificate is the problem, and the conversation step by step (with
 * the sign-in lines replaced) for a "send a test" page to show.
 *
 * ⚠️ ONE READING TO BE CAREFUL WITH: a refused SIGN-IN is `refused` at stage
 * `AUTH`. That is about the mailbox's password, not about the message, so a
 * caller that drops refused messages should look at the stage first.
 * `isAboutTheMessage()` answers exactly that question.
 *
 * @package   MWBM\Mail
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace MWBM\Mail;

final class SendResult
{
    // 📬 The four outcomes.
    public const ACCEPTED = 'accepted';
    public const TRY_LATER = 'try-later';
    public const REFUSED = 'refused';
    public const NEEDS_PERSON = 'needs-person';

    // 🪜 The stages, in the order a conversation passes through them.
    public const STAGE_CONFIG = 'CONFIG';       // the settings, before anything is tried
    public const STAGE_MESSAGE = 'MESSAGE';     // the message itself (its size)
    public const STAGE_CONNECT = 'CONNECT';     // reaching the server
    public const STAGE_GREETING = 'GREETING';   // the server's first line
    public const STAGE_EHLO = 'EHLO';           // the client introducing itself
    public const STAGE_STARTTLS = 'STARTTLS';   // asking for encryption
    public const STAGE_TLS = 'TLS';             // switching encryption on, and the certificate
    public const STAGE_AUTH = 'AUTH';           // signing in
    public const STAGE_RESET = 'RESET';         // clearing an open connection for the next message
    public const STAGE_SENDER = 'SENDER';       // naming who the message is from
    public const STAGE_RECIPIENT = 'RECIPIENT'; // naming who it is for
    public const STAGE_DATA = 'DATA';           // asking to send the message
    public const STAGE_BODY = 'BODY';           // the message itself, and the server's answer to it

    // 🏷️ The reasons.
    public const REASON_OK = 'ok';
    public const REASON_NOT_SET_UP = 'not-set-up';
    public const REASON_TOO_BIG = 'too-big';
    public const REASON_CANNOT_CONNECT = 'cannot-connect';
    public const REASON_TIMEOUT = 'timeout';
    public const REASON_DROPPED = 'dropped';
    public const REASON_UNEXPECTED = 'unexpected-reply';
    public const REASON_SERVER_SAID_LATER = 'server-said-later';
    public const REASON_SERVER_SAID_NO = 'server-said-no';
    public const REASON_NO_ENCRYPTION = 'no-encryption-offered';
    public const REASON_ENCRYPTION_FAILED = 'encryption-failed';
    public const REASON_INJECTED_TEXT = 'injected-text';
    public const REASON_CERTIFICATE = 'certificate';
    public const REASON_NO_SIGN_IN_METHOD = 'no-sign-in-method';
    public const REASON_UNKNOWN_IF_SENT = 'unknown-if-sent';
    public const REASON_NOT_ATTEMPTED = 'not-attempted';
    public const REASON_INTERNAL = 'internal-error';

    /** The stages at which a failure is about the route to the server, not about the message. */
    private const ROUTE_STAGES = [
        self::STAGE_CONFIG, self::STAGE_CONNECT, self::STAGE_GREETING, self::STAGE_EHLO,
        self::STAGE_STARTTLS, self::STAGE_TLS, self::STAGE_AUTH, self::STAGE_RESET,
    ];

    /**
     * @param string                $outcome          One of the four outcomes above.
     * @param string                $stage            A `STAGE_` name.
     * @param string                $reason           A `REASON_` name.
     * @param int                   $code             The server's reply number, or 0 when there was none.
     * @param string                $serverWords      The server's own words, or this library's, safe to store.
     * @param CertificateFacts|null $certificate      The certificate's facts, when it is the problem. Null for
     *                                                every other reason, and in ONE case of `certificate`: PHP
     *                                                refused it, and it could not be read a second time to say why.
     * @param list<string>          $transcript       The conversation step by step. Never holds a password or a body.
     * @param array<string,string>  $recipientReplies For a refused recipient: address => the server's reply.
     */
    public function __construct(
        public readonly string $outcome,
        public readonly string $stage,
        public readonly string $reason,
        public readonly int $code = 0,
        public readonly string $serverWords = '',
        public readonly ?CertificateFacts $certificate = null,
        public readonly array $transcript = [],
        public readonly array $recipientReplies = [],
    ) {
        $outcomes = [self::ACCEPTED, self::TRY_LATER, self::REFUSED, self::NEEDS_PERSON];
        if (in_array($outcome, $outcomes, true) !== true) {
            // A fifth outcome would be a fault in the library itself. Better a
            // loud stop here than a caller quietly treating it as "sent".
            throw new \LogicException('A send can only end in one of the four known outcomes.');
        }
    }

    public function accepted(): bool
    {
        return $this->outcome === self::ACCEPTED;
    }

    /**
     * True when nothing was sent because the server could not be reached or
     * the session could not be set up. `Client` counts these, and may try a
     * second route for them.
     */
    public function isConnectionFailure(): bool
    {
        return $this->outcome === self::TRY_LATER && in_array($this->stage, self::ROUTE_STAGES, true) === true;
    }

    /**
     * True when a refusal is about this message (its sender, a recipient, its
     * size or content) and false when it is about the route (the settings, the
     * connection or the sign-in), which would refuse every message alike.
     */
    public function isAboutTheMessage(): bool
    {
        return in_array($this->stage, self::ROUTE_STAGES, true) !== true;
    }
}
