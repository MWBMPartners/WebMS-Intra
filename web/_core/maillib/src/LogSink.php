<?php
// Path: web/_core/maillib/src/LogSink.php
/**
 * -----------------------------------------------------------------------------
 * LogSink — where the mail library reports what happened 📝
 * -----------------------------------------------------------------------------
 * The library writes no log of its own and knows nothing about the logging of
 * the application using it. If the application wants to hear about each send,
 * it hands in something that implements this.
 *
 * WHAT IS NEVER PASSED HERE: a password, a sign-in line or a message body. The
 * words passed are the mail server's own reply and fixed phrases of this
 * library, with the password removed first in case a server repeats back what
 * it was sent.
 *
 * WHAT MAY BE IN THE WORDS: an e-mail address, when the server itself names
 * one in its reply ("no such user: someone@example.org"). The library adds no
 * address of its own, but it does not edit the server's sentence, so whoever
 * stores these words should treat them as possibly holding personal data.
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

interface LogSink
{
    /**
     * One event.
     *
     * @param string               $outcome One of the four `SendResult` outcomes.
     * @param string               $stage   Where in the conversation it happened (a `SendResult::STAGE_` name).
     * @param string               $words   The reason and the server's own words, already made safe to store.
     * @param array<string,scalar> $facts   Small facts: the reply code, the message's identifier, and how many
     *                                      recipients and attachments it has. (No size, and never an address.)
     */
    public function record(string $outcome, string $stage, string $words, array $facts = []): void;
}
