<?php
// Path: _core/EventSuggestions.php
/**
 * -----------------------------------------------------------------------------
 * Telling somebody their event suggestion was approved ✉️📅 (#859, D-134)
 * -----------------------------------------------------------------------------
 * Somebody who is NOT signed in can suggest an event on the public form
 * (`/calendar/submit`). They give a name and an e-mail address, and the form
 * tells them they will be e-mailed when it is approved. Until #859 nothing
 * sent that e-mail: `admin/calendar/moderate.php` recorded the approval and
 * raised the webhook, and nothing more. D-134 (the owner, 11 November 2026):
 * send it, and use the address for nothing else.
 *
 * THIS CLASS IS THE ONE PLACE THAT DECIDES IT. `approvalMessage()` holds the
 * words; `tellSuggesterApproved()` reads the approved suggestion and sends.
 * The moderation handler calls the second and keeps no copy of either.
 *
 * WHAT MAKES IT "ONCE ONLY". Not this class: the handler's approval is ONE
 * conditional statement (`... WHERE submissionStatus = 'pending'`), and only
 * the request whose statement changed the row calls this. A second press, a
 * double click or a stale page changes nothing and so sends nothing; and
 * nothing in the portal ever puts a decided suggestion back to `pending`:
 * `git grep -n "submissionStatus" d420b561 -- web/ ':!web/_sql/*'` finds
 * `pending` written only by the form's INSERT, and `approved`/`rejected` only
 * by the moderation handler (series-repattern names the column only to leave
 * it OFF a date it adds). If that ever changes, a re-approval WOULD send
 * again, so whatever reopens a suggestion must say so.
 *
 * WHAT IT DOES NOT DO, on purpose:
 *   - It sends nothing on a REJECTION. D-134 covers approval only, and the
 *     form promises nothing else.
 *   - It sends nothing to a suggestion made by somebody SIGNED IN: the form
 *     takes no address from them (`submitterEmail` is stored NULL), and was
 *     never told it would write.
 *   - It never throws, and it is called AFTER the approval is stored, so a
 *     mail route that is broken or not set up never stops an approval.
 *   - It never writes the address into the activity log or the error log.
 *     The mail system's own send log records the recipient, as it does for
 *     every message; that is catalogued (`tblEmailLog`, `tblMailQueue`).
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/859
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class EventSuggestions
{
    /** The suggestion holds no usable address (none given, signed in, or not an address). Nothing was tried. */
    public const TOLD_NO_ADDRESS = 'no-address';

    /** A mail service took the message. Not the same as delivered. */
    public const TOLD_SENT = 'sent';

    /** The message is kept in the mail queue (queued or held) and will be tried again. */
    public const TOLD_WAITING = 'waiting';

    /** It did not go and is not kept: the route is not set up, refused it, or threw. */
    public const TOLD_NOT_SENT = 'not-sent';

    /**
     * ✉️ The approval e-mail's subject, and the ONE place it is written.
     *
     * It is also how an erasure finds this e-mail again (#964, D-140). The
     * send log and the mail queue keep no note of WHY a message was sent, so
     * `GdprEraser` asks for messages to the address carrying exactly this
     * subject: the one message the portal sends BECAUSE OF a suggestion made
     * without signing in (D-134). (The address may also be a member's, who is
     * sent other mail too; that mail has other subjects.) A member's own mail to the same address
     * has other subjects and is left alone.
     *
     * ⚠️ CHANGE IT AND THE ERASURE STOPS FINDING EVERY APPROVAL E-MAIL SENT
     *    BEFORE THE CHANGE. If it ever has to change, keep the old wording as a
     *    second subject the erasure looks for; do not just edit this line.
     *    `tools/gdpr-coverage-selftest.php` pins it.
     */
    public const APPROVAL_SUBJECT = 'Your event suggestion has been approved';

    /**
     * ➖ A stranger's one-line value, made one line (#972). The ONE rule for
     * the two things a visitor types that the approval e-mail repeats: the
     * event's name and their own name. `calendar/submit-save.php` applies it
     * before storing, and approvalMessage() applies it again to whatever the
     * row holds.
     *
     * WHY. Both boxes are single-line on the form, but a request made by hand
     * can carry line breaks, and the form only trimmed the ends. The e-mail is
     * plain text, so a stranger could lay it out: a name such as
     * "Pat\n\nClick https://… to confirm" became its own paragraph holding a
     * link, in a message from the organisation's own mailbox. The approving
     * administrator never saw that layout, because the moderation page shows
     * the same text inside one heading, where a browser folds every line break
     * and run of spaces into a single space.
     *
     * SO THIS DOES EXACTLY WHAT THE ADMINISTRATOR'S BROWSER DID: every run of
     * control characters (line breaks, tabs, every C0/C1 control, \p{Cc}) and
     * of Unicode space and line or paragraph separators (\p{Z}, which holds
     * U+2028 and U+2029) becomes ONE space, and the ends are trimmed. The
     * stored value, the moderation page and the e-mail then read the same,
     * word for word. A link stays as text: the administrator sees it, on one
     * line, before approving, which is the point.
     *
     * WHAT IT DOES NOT DO: remove the "format" characters (\p{Cf}), such as
     * the right-to-left override. They change how a line READS, not how many
     * lines there are, and stripping them would also break the zero-width
     * joiners some scripts and emoji need. Text that is not valid UTF-8 has
     * its single-byte controls replaced instead, because the Unicode pattern
     * refuses it outright.
     */
    public static function oneLine(string $text): string
    {
        $flat = preg_replace('/[\p{Cc}\p{Z}]+/u', ' ', $text);
        if ($flat === null) {
            $flat = (string) preg_replace('/[\x00-\x20\x7F]+/', ' ', $text);
        }

        return trim($flat);
    }

    /**
     * ✉️ The subject and the plain-text body of the e-mail.
     *
     * THE SUBJECT NAMES NOTHING, deliberately. The send log keeps a message's
     * subject for as long as it keeps the row, and an erasure empties the
     * recipient but NOT the subject (see `tblEmailLog` in
     * personal-data-catalogue.php). The event's name is the suggester's own
     * typing, so it goes in the body only.
     *
     * TWO WHOLE BODIES, one for each answer to "can people who are not signed
     * in see it?", spelled out in full rather than built from a clause in the
     * middle of a sentence (#694's lesson). Under D-092 approving keeps a
     * suggestion for members only, so the first is the usual one; the second
     * is for an administrator who ticked "Public" on it before approving.
     *
     * The body is PLAIN TEXT, and it holds two things a member of the public
     * typed: the event's name and their own name. It is therefore sent with
     * `Mailer::send()`'s `bodyIsText` option, which makes Mailer escape it and
     * wrap it in the organisation's branded layout WHATEVER it contains.
     *
     * ⚠️ ROUND 1 OF THE CHECK FOUND THIS SENTENCE UNTRUE. It said "Mailer
     *    escapes it", and without the option Mailer GUESSES: a body matching
     *    `<[a-zA-Z][^>]*>` anywhere is taken as HTML and sent exactly as
     *    written, with no escaping and no footer. So `Fish & Chips <Supper>`,
     *    a name carrying `<a href="https://evil.example/…">`, or an
     *    `<img src="https://tracker.example/…">` would have gone out from the
     *    organisation's own mailbox as a working link or a tracking image.
     *    Never drop the option from this send.
     *
     * @param string $eventName    The suggestion's title, as stored.
     * @param string $startReading `tblEvents.startDateTime`: a reading of the
     *                             event's own clock, 'Y-m-d H:i:s' (#777). It
     *                             is put into words without becoming a moment.
     * @param bool   $isAllDay     True: the time of day is left out.
     * @param bool   $isPublic     `tblEvents.isPublic` at the moment of approval.
     * @param string $organisation The organisation's name, as its footer says it.
     * @param string $name         The suggester's own name, as they typed it; '' for none.
     *
     * @return array{subject: string, body: string}
     */
    public static function approvalMessage(
        string $eventName,
        string $startReading,
        bool $isAllDay,
        bool $isPublic,
        string $organisation,
        string $name
    ): array {
        $when = I18n::formatClockDateTime($startReading, $isAllDay === true ? 'l j F Y' : 'l j F Y \a\t H:i');
        // ➖ #972: the two things a stranger typed, one line each, exactly as
        //    the moderation page showed them (see oneLine()). Asked here too,
        //    not only at the form, because a row can reach approval having been
        //    written some other way.
        $eventName = self::oneLine($eventName);
        $name      = self::oneLine($name);
        $hello = $name === '' ? 'Hello,' : 'Hello ' . $name . ',';

        if ($isPublic === true) {
            $body = $hello . "\n\n"
                . 'Thank you for suggesting "' . $eventName . '", on ' . $when . '. ' . $organisation
                . ' has approved it, and it is now on their calendar.' . "\n\n"
                . 'Anyone can see it on the calendar, without signing in.' . "\n\n"
                . 'We will not send you any other e-mail about this suggestion.';
        } else {
            $body = $hello . "\n\n"
                . 'Thank you for suggesting "' . $eventName . '", on ' . $when . '. ' . $organisation
                . ' has approved it, and it is now on their calendar.' . "\n\n"
                . 'For now it is shown only to ' . $organisation . '\'s members, who see it when they sign in. '
                . 'It will not appear on the calendar for anybody who is not signed in unless ' . $organisation
                . ' later chooses to make it public.' . "\n\n"
                . 'We will not send you any other e-mail about this suggestion.';
        }

        return ['subject' => self::APPROVAL_SUBJECT, 'body' => $body];
    }

    /**
     * 📮 E-mail the person who suggested an event that has JUST been approved.
     *
     * Call it only from the request whose statement moved the suggestion from
     * `pending` to `approved`, and only after that statement is stored: that
     * is what makes it once only (see the file header). It reads the row back
     * by its number AND its organisation AND `submissionStatus = 'approved'`,
     * so a wrong number cannot make it write to anybody.
     *
     * It NEVER THROWS. A failed read, a route that is not set up, and a route
     * that throws (the Microsoft 365 route does when it has no sender) all
     * come back as an answer, and the fault's own words are never recorded,
     * because a mail server's refusal can quote the recipient's address.
     *
     * @return string One of the TOLD_* constants.
     */
    public static function tellSuggesterApproved(\mysqli $db, int $eventId, int $siteId): string
    {
        try {
            $stmt = $db->prepare(
                'SELECT `eventName`, `startDateTime`, `isAllDay`, `isPublic`, `submitterName`, `submitterEmail` '
                . 'FROM `tblEvents` '
                // `externalFeedID IS NULL`: a suggestion is always the portal's
                // own event, never one copied in from an outside calendar, and
                // saying so is how check_event_visibility.py knows this read
                // shows nothing to a viewer that the visibility rule decides.
                . 'WHERE `eventID` = ? AND `siteID` = ? AND `submissionStatus` = \'approved\' AND `submittedByID` IS NULL '
                . '  AND `externalFeedID` IS NULL '
                // `isDeleted = 0` (round 1 of the check): a deleted suggestion
                // must never be told "it is now on their calendar". The
                // handler refuses to approve one too; this is the second ask.
                . '  AND `isDeleted` = 0 '
                . 'LIMIT 1'
            );
            if ($stmt === false) {
                throw new \RuntimeException('prepare');
            }
            $stmt->bind_param('ii', $eventId, $siteId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (\Throwable $e) {
            Logger::errorPlatform('Calendar', 'Warning', 'SUGGESTION_MAIL_READ', 'The approved suggestion could not be read, so its suggester was not told', '');

            return self::TOLD_NOT_SENT;
        }

        // 📭 No address, or not one: nothing is tried. `submit-save.php` checks
        //    the address when the suggestion is made, so "not an address" here
        //    means a row written some other way; it is still refused, because
        //    Mailer must never be handed something that is not one address.
        $address = trim((string) ($row['submitterEmail'] ?? ''));
        if ($row === null || $address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return self::TOLD_NO_ADDRESS;
        }

        $words = self::approvalMessage(
            (string) $row['eventName'],
            (string) $row['startDateTime'],
            (int) $row['isAllDay'] === 1,
            (int) $row['isPublic'] === 1,
            Mailer::organisationNameFor($siteId),
            (string) ($row['submitterName'] ?? '')
        );

        try {
            // 🏷️ `siteId`: the footer names the EVENT's organisation (#683),
            //    which on this page is also the request's — said rather than
            //    left to the merged settings, so it stays right if the caller
            //    ever changes.
            // 🛡️ `bodyIsText`: ALWAYS plain text, escaped, with the footer —
            //    see approvalMessage(). This is the security half of this send.
            $kept = Mailer::send($address, $words['subject'], $words['body'], [], ['siteId' => $siteId, 'bodyIsText' => true]);
            $outcome = $kept === false
                ? self::TOLD_NOT_SENT
                : (Mailer::lastIsWaiting() === true ? self::TOLD_WAITING : self::TOLD_SENT);
        } catch (\Throwable $e) {
            Logger::errorPlatform('Mailer', 'Warning', 'SUGGESTION_MAIL_FAIL', 'The "your event suggestion was approved" e-mail could not be sent', '');
            $outcome = self::TOLD_NOT_SENT;
        }

        // 📝 The record names the event and what became of the message, never
        //    the address: an activity record is read by every administrator,
        //    and D-134 allows the address to be used for this e-mail alone.
        Logger::activity('EventSuggesterTold', 'Event #' . $eventId . ': the e-mail telling the person who suggested it that it was approved: ' . $outcome);

        return $outcome;
    }

    /**
     * 🗣️ What the moderation page says after an approval about the e-mail,
     * one whole sentence per outcome. "Sent" is never said of a message that
     * was only kept (`Mailer::send()` answers true for both).
     *
     * It says nothing about who can see the event: the page's own
     * introduction says that approving keeps it for members (D-092), and a
     * sentence here would be untrue for the one an administrator had already
     * ticked "Public" on.
     */
    public static function approvedWords(string $outcome): string
    {
        return match ($outcome) {
            self::TOLD_SENT       => 'Approved. The person who suggested it has been e-mailed to say so.',
            self::TOLD_WAITING    => 'Approved. The e-mail telling the person who suggested it is kept in the mail queue to be tried again; Admin, Integrations, Email shows whether anything is holding it up.',
            self::TOLD_NO_ADDRESS => 'Approved. Nobody was e-mailed: the suggestion holds no e-mail address to write to.',
            default               => 'Approved, but the e-mail telling the person who suggested it could not be sent. Admin, Integrations, Email shows whether e-mail is set up.',
        };
    }
}
