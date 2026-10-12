<?php
// Path: web/_core/maillib/src/Transport.php
/**
 * -----------------------------------------------------------------------------
 * Transport — one way of getting a message to a mail service 📮
 * -----------------------------------------------------------------------------
 * Every route the library can send by implements this: its own SMTP client
 * today; a web-service route (such as Microsoft 365 or Google) or another SMTP
 * engine later. The rest of the library, and the application, talk only to
 * this, so a route can be added or swapped without anything else changing.
 *
 * THE ONE RULE EVERY ROUTE KEEPS: nothing leaves `send()` as an exception.
 * Whatever goes wrong comes back as a `SendResult` saying which of four things
 * happened, so a caller sending to three hundred people cannot be ended half
 * way by one bad connection.
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

interface Transport
{
    /** A short name for logs and pages, such as "smtp". */
    public function name(): string;

    /**
     * What is missing before this route can be used, judged from its settings
     * alone. An empty list means "set up". It cannot promise the mail service
     * will accept a message: only a send can show that.
     *
     * @return list<string> Plain sentences, one per missing or wrong setting.
     */
    public function ready(): array;

    /**
     * Send one message. Never throws.
     *
     * @param SendOptions|null $options Leave out for the normal, fully checked send.
     */
    public function send(Message $message, ?SendOptions $options = null): SendResult;

    /** Close anything held open. Safe to call twice; a later send opens it again. */
    public function close(): void;
}
