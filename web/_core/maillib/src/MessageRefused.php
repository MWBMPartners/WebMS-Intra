<?php
// Path: web/_core/maillib/src/MessageRefused.php
/**
 * -----------------------------------------------------------------------------
 * MessageRefused — a message that will not be built 🚫
 * -----------------------------------------------------------------------------
 * Thrown by `Message` when what it was given cannot safely become an e-mail: a
 * line break hidden in a subject, an address that is not an address, an
 * attachment that cannot be read or is too big.
 *
 * WHY REFUSE INSTEAD OF TIDYING UP. A line break inside a subject or a name is
 * how an attacker adds lines of their own to an e-mail (extra recipients, a
 * second message). Removing it and sending anyway would still deliver whatever
 * else they typed. A fault like that is also a sign something upstream is
 * wrong, and the caller should hear about it.
 *
 * WHAT THE TEXT NEVER CONTAINS: the offending value. The text names the field
 * and says what is wrong with it, so it is safe to log and to show.
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

final class MessageRefused extends \InvalidArgumentException
{
    /**
     * @param string $field Which part was wrong: `subject`, `fromName`, `fromAddress`, `to`,
     *                      `toName`, `replyTo`, `text`, `html`, `attachment`, `attachmentName`,
     *                      `attachmentType`, `messageId` or `date`.
     * @param string $why   What is wrong with it, in plain words, without quoting it.
     */
    public function __construct(public readonly string $field, string $why)
    {
        parent::__construct($why);
    }
}
