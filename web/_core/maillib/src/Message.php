<?php
// Path: web/_core/maillib/src/Message.php
/**
 * -----------------------------------------------------------------------------
 * Message — one e-mail, checked and built ✉️
 * -----------------------------------------------------------------------------
 * Takes what an application wants to send (who from, who to, a subject, the
 * same content as plain text and as HTML, any attachments) and either builds a
 * correct e-mail from it or refuses, by throwing `MessageRefused`. It never
 * touches the network, so it can be tested by itself.
 *
 * THE RULES IT KEEPS
 *   - 🚫 A line break, a tab or any other control character in a subject, a
 *     name, an address, a reply-to, a file name or a message identifier
 *     REFUSES the whole message. It is not removed and sent anyway: a line
 *     break there is how extra lines are smuggled into an e-mail, and cleaning
 *     one out would still deliver the rest of what was smuggled.
 *   - A plain-text part AND an HTML part, always. Both are required.
 *   - An attachment is sent whole or the message is refused. Nothing is ever
 *     left out quietly.
 *   - Every part of the body is base64 in lines of 76 characters. So what goes
 *     on the wire is plain ASCII, every line ends CR LF, no line is long, and
 *     no body line can begin with a full stop. Content therefore cannot fake
 *     the "end of message" marker.
 *   - Names and subjects with non-English letters are written as "encoded
 *     words" (RFC 2047), split only between characters, never inside one.
 *   - The identifier (Message-ID) and the part separators are chosen once,
 *     when the object is made. A retry of the same object sends the very same
 *     bytes, which lets a receiving system spot a duplicate.
 *
 * WHAT IT CANNOT DO
 *   - It cannot make a receiving system trust the message. That depends on
 *     the sender's DNS records (SPF, DKIM, DMARC), which no code here can set.
 *   - A header line stays within 78 characters wherever there is a place to
 *     fold it. An address or identifier longer than that has no such place and
 *     sits on a longer line of its own (never over the hard limit of 998).
 *   - Non-English letters before the "@" of an address are refused: sending
 *     to them needs a server feature (SMTPUTF8) this library does not use.
 *     A non-English domain is converted to its ASCII form where PHP can.
 *   - 🧮 It cannot send a message without holding several copies of it in
 *     memory: about FIVE TIMES the size of its content (attachments and
 *     text), on top of the content itself and whatever the application
 *     already uses. See `needRoomFor()`: a message there is no room for is
 *     refused in words before anything is read or built. On a PHP with no
 *     memory limit, or one that hides its limit, that check cannot be made.
 *
 * @package   MWBM\Mail
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * @link      https://www.rfc-editor.org/rfc/rfc5322 (the format of a message)
 * @link      https://www.rfc-editor.org/rfc/rfc2047 (non-English letters in headers)
 * @link      https://www.rfc-editor.org/rfc/rfc2231 (non-English letters in file names)
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace MWBM\Mail;

final class Message
{
    /** The largest single attachment, before encoding: 10 MB. */
    public const MAX_ATTACHMENT_BYTES = 10485760;

    /**
     * 🧮 How many times its content a message needs in memory, beyond the
     * content itself, to be built and written to a server. Measured at about
     * 4.1 for one 10 MB attachment (base64 makes it a third bigger, and at
     * the worst moment there are three copies of that), then rounded UP.
     * `BUILD_COPIES` is the part of it needed just to build the bytes.
     */
    private const COPIES = 5;
    private const BUILD_COPIES = 3;

    /** The most recipients one message may have. Every mail server must accept at least this many. */
    public const MAX_RECIPIENTS = 100;

    /** A header line is folded to stay within this many characters (RFC 5322 section 2.1.1). */
    private const LINE = 78;

    /**
     * How many bytes of text one encoded word carries. 39 bytes become 52
     * base64 characters, 64 with the wrapping, which fits after the longest
     * label used here ("Reply-To: ") inside 78. An encoded word may be at most
     * 75 characters whatever the label.
     */
    private const WORD_BYTES = 39;

    /** A name or file name this short and this plain is written as it is. */
    private const PLAIN_MAX = 60;

    /**
     * The characters allowed before the "@" of an address and in an identifier
     * (RFC 5322 "atext"), for use inside a regular expression's [ ] brackets.
     * It ENDS in a hyphen, so anything added to the brackets goes in front of
     * it: after it, the hyphen would be read as "from ... to ..." and the
     * pattern would fail to compile.
     */
    private const ATEXT = "A-Za-z0-9!#$%&'*+\\/=?^_`{|}~-";

    private int $date;
    private string $fromAddress;
    private string $fromName;
    /** @var list<array{0:string,1:string}> Each recipient as [address, name]. */
    private array $to = [];
    private string $subject;
    private string $text;
    private string $html;
    private string $replyTo;
    /** @var list<array{name:string,type:string,content:string}> */
    private array $attachments = [];
    private string $messageId;
    private string $outerBoundary;
    private string $innerBoundary;
    /** The built message, kept so that a size check and a send do not build it twice. */
    private ?string $bytes = null;

    /**
     * @param int                        $date        The moment the message was written, as seconds since 1970 UTC
     *                                                (take it from the `Clock`). A retry keeps the original moment.
     * @param string                     $fromAddress The sender's address. Also the envelope sender, so that bounces
     *                                                come back to it and the sender's SPF record lines up.
     * @param string                     $fromName    The sender's display name; may be empty.
     * @param array<int|string,string>   $to          The recipients: a list of addresses, or address => display name.
     * @param string                     $subject     The subject; may be empty.
     * @param string                     $text        The content as plain text. Required.
     * @param string                     $html        The same content as HTML. Required.
     * @param string                     $replyTo     An address replies should go to; empty for none.
     * @param list<array<string,string>> $attachments Each: `name`, `content` (the file's bytes) and optionally `type`.
     *                                                `Message::fileAttachment()` makes one from a file.
     * @param string                     $messageId   Leave empty for a new identifier; pass a stored one to send the
     *                                                same message again under the same identifier.
     *
     * @throws MessageRefused When anything given cannot safely become an e-mail.
     */
    public function __construct(
        int $date,
        string $fromAddress,
        string $fromName,
        array $to,
        string $subject,
        string $text,
        string $html,
        string $replyTo = '',
        array $attachments = [],
        string $messageId = '',
    ) {
        if ($date <= 0) {
            throw new MessageRefused('date', 'The message has no date.');
        }
        $this->date = $date;
        $this->fromAddress = self::address('fromAddress', $fromAddress);
        $this->fromName = self::headerText('fromName', $fromName);

        // 👥 Recipients.
        if ($to === []) {
            throw new MessageRefused('to', 'The message has nobody to go to.');
        }
        if (count($to) > self::MAX_RECIPIENTS) {
            throw new MessageRefused('to', 'The message has more than ' . self::MAX_RECIPIENTS . ' recipients.');
        }
        foreach ($to as $key => $value) {
            if (is_string($value) !== true) {
                throw new MessageRefused('to', 'A recipient is not text.');
            }
            // A whole-number key means "a plain list of addresses"; a text key
            // means "address => display name".
            if (is_int($key) === true) {
                $this->to[] = [self::address('to', $value), ''];
            } else {
                $this->to[] = [self::address('to', $key), self::headerText('toName', $value)];
            }
        }

        $this->subject = self::headerText('subject', $subject);
        $this->text = self::bodyText('text', $text);
        $this->html = self::bodyText('html', $html);
        $this->replyTo = trim($replyTo, ' ') === '' ? '' : self::address('replyTo', $replyTo);

        // 📎 Attachments. Each one is kept whole or the message is refused.
        foreach ($attachments as $attachment) {
            if (
                is_array($attachment) !== true
                || is_string($attachment['name'] ?? null) !== true
                || is_string($attachment['content'] ?? null) !== true
                || is_string($attachment['type'] ?? '') !== true
            ) {
                throw new MessageRefused('attachment', 'An attachment is not in the expected shape.');
            }
            $this->attachments[] = [
                'name' => self::fileName($attachment['name']),
                'type' => self::contentType($attachment['type'] ?? ''),
                'content' => self::attachmentContent($attachment['content']),
            ];
        }

        // 🧮 Is there room to build and send it? Asked now, in words, because
        // running out of memory later is an error nothing can catch, and
        // PHP's own message for it prints the start of whatever it was
        // holding: here, the first bytes of an attachment.
        self::needRoomFor(self::COPIES * $this->contentBytes());

        // 🆔 The identifier ends in the sender's own domain, as mail systems expect.
        $domain = substr($this->fromAddress, (int) strrpos($this->fromAddress, '@') + 1);
        if ($messageId === '') {
            $this->messageId = '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>';
        } else {
            if (preg_match('/\A<[.' . self::ATEXT . ']{1,64}@[A-Za-z0-9.-]{1,253}>\z/', $messageId) !== 1) {
                throw new MessageRefused('messageId', 'The message identifier is not in the form <something@domain>.');
            }
            $this->messageId = $messageId;
        }

        // Random separators between the parts. Every part is base64, whose
        // lines can never begin with "--", so a separator cannot appear inside
        // one by chance or by design.
        $this->outerBoundary = '=_mixed_' . bin2hex(random_bytes(12));
        $this->innerBoundary = '=_alt_' . bin2hex(random_bytes(12));
    }

    /**
     * Read a file into the shape `$attachments` expects.
     *
     * @param string $path The file to attach.
     * @param string $name The name the recipient sees; empty to use the file's own name.
     * @param string $type Its content type, such as "application/pdf"; empty for "a file of some kind".
     *
     * @return array{name:string,type:string,content:string}
     *
     * @throws MessageRefused If the file is missing, unreadable, empty or too big. The caller must not
     *                        send without it: a statement with no statement attached is worse than no e-mail.
     */
    public static function fileAttachment(string $path, string $name = '', string $type = ''): array
    {
        if (is_file($path) !== true || is_readable($path) !== true) {
            throw new MessageRefused('attachment', 'An attachment could not be read.');
        }
        // Look at the size BEFORE reading, so an enormous file is refused
        // without being loaded into memory first.
        $size = filesize($path);
        if ($size === false || $size > self::MAX_ATTACHMENT_BYTES) {
            throw new MessageRefused('attachment', 'An attachment is bigger than 10 MB.');
        }
        // 🧮 And is there room for it, BEFORE it is read: the file itself,
        // plus the copies a message needs to carry it.
        self::needRoomFor((self::COPIES + 1) * $size);
        $content = file_get_contents($path);
        if ($content === false) {
            throw new MessageRefused('attachment', 'An attachment could not be read.');
        }
        return [
            'name' => self::fileName($name === '' ? $path : $name),
            'type' => self::contentType($type),
            'content' => self::attachmentContent($content),
        ];
    }

    /**
     * Refuse, in words, when PHP does not have this many bytes of memory left.
     *
     * 🧮 WHY. A 10 MB attachment needs about 53 MB to build and send. On a
     * PHP limited to 32 MB that used to end in "Allowed memory size
     * exhausted": a fatal error that no code can catch, that leaves no
     * result behind, and whose text shows the first bytes of the attachment.
     *
     * WHAT IT CANNOT DO. It compares an estimate with what PHP reports as
     * free at this moment. It cannot see a limit that is switched off ("-1")
     * or hidden (a host may forbid `ini_get`): then it lets everything
     * through, as before. And it cannot know what the application will use
     * next, so an application holding many built messages at once can still
     * run out. Build, send and let go of one message at a time.
     *
     * @throws MessageRefused Named as the field `attachment`, which is what makes a message big.
     */
    public static function needRoomFor(int $bytes): void
    {
        // A shared host may switch either function off, and calling a
        // function that is switched off stops PHP dead. Then the check
        // cannot be made, and everything is let through as before.
        if (function_exists('ini_get') !== true || function_exists('memory_get_usage') !== true) {
            return;
        }
        $limit = strtoupper(trim((string) ini_get('memory_limit')));
        if (preg_match('/\A([0-9]+)([KMG]?)\z/', $limit, $found) !== 1) {
            return;
        }
        $most = (int) $found[1] * match ($found[2]) {
            'K' => 1024,
            'M' => 1048576,
            'G' => 1073741824,
            default => 1,
        };
        // PHP holds its limit against the memory it has taken from the
        // system, which is what the "true" asks for here.
        $left = $most - memory_get_usage(true);
        if ($bytes > $left) {
            throw new MessageRefused('attachment', 'There is not enough memory to build and send this message: it needs '
                . 'about ' . (int) ceil($bytes / 1048576) . ' MB more, and PHP has about ' . max(0, (int) floor($left / 1048576))
                . ' MB left of its limit of ' . (int) floor($most / 1048576) . ' MB.');
        }
    }

    /** The bytes of content this message carries: both versions of the text and every attachment. */
    private function contentBytes(): int
    {
        $bytes = strlen($this->text) + strlen($this->html);
        foreach ($this->attachments as $attachment) {
            $bytes += strlen($attachment['content']);
        }
        return $bytes;
    }

    /** The message's identifier, in angle brackets. The same on every retry of this object. */
    public function messageId(): string
    {
        return $this->messageId;
    }

    /** The address given to the mail server as the sender (the "envelope sender"). */
    public function sender(): string
    {
        return $this->fromAddress;
    }

    /** @return list<string> The addresses given to the mail server as recipients. */
    public function recipients(): array
    {
        return array_column($this->to, 0);
    }

    public function attachmentCount(): int
    {
        return count($this->attachments);
    }

    /**
     * How many bytes the built message is.
     *
     * @throws MessageRefused See `bytes()`.
     */
    public function size(): int
    {
        return strlen($this->bytes());
    }

    /**
     * The whole message as it is handed to a mail service: headers, a blank
     * line, then the parts. Plain ASCII, every line ending CR LF.
     *
     * @throws MessageRefused Only if memory has run short since the object was made (see `needRoomFor()`).
     */
    public function bytes(): string
    {
        if ($this->bytes !== null) {
            return $this->bytes;
        }
        self::needRoomFor(self::BUILD_COPIES * $this->contentBytes());

        // 🏷️ Headers. No line names the software that wrote the message: that
        // would only tell a stranger what to attack.
        $headers = [
            // The date is written in UTC ("+0000"), so it is right whatever
            // time zone the server or the reader is in.
            'Date: ' . gmdate('D, d M Y H:i:s', $this->date) . ' +0000',
            self::mailboxLine('From', [[$this->fromAddress, $this->fromName]]),
            self::mailboxLine('To', $this->to),
        ];
        if ($this->replyTo !== '') {
            $headers[] = self::mailboxLine('Reply-To', [[$this->replyTo, '']]);
        }
        $headers[] = self::subjectLine($this->subject);
        $headers[] = 'Message-ID: ' . $this->messageId;
        $headers[] = 'MIME-Version: 1.0';

        // 📝 The two versions of the content, plain text first. A mail program
        // shows the LAST version it can display, so HTML goes second.
        $alternatives = '--' . $this->innerBoundary . "\r\n"
            . self::part(['Content-Type: text/plain; charset=UTF-8'], $this->text)
            . '--' . $this->innerBoundary . "\r\n"
            . self::part(['Content-Type: text/html; charset=UTF-8'], $this->html)
            . '--' . $this->innerBoundary . "--\r\n";
        $alternativesType = "Content-Type: multipart/alternative;\r\n boundary=\"" . $this->innerBoundary . '"';

        if ($this->attachments === []) {
            $headers[] = $alternativesType;
            $body = $alternatives;
        } else {
            // 📎 With attachments, the two versions become the first part of
            // an outer wrapper and each attachment follows as its own part.
            $headers[] = "Content-Type: multipart/mixed;\r\n boundary=\"" . $this->outerBoundary . '"';
            $body = '--' . $this->outerBoundary . "\r\n" . $alternativesType . "\r\n\r\n" . $alternatives;
            foreach ($this->attachments as $attachment) {
                $body .= '--' . $this->outerBoundary . "\r\n" . self::part(
                    ['Content-Type: ' . $attachment['type'], self::dispositionLine($attachment['name'])],
                    $attachment['content'],
                );
            }
            $body .= '--' . $this->outerBoundary . "--\r\n";
        }

        $this->bytes = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        return $this->bytes;
    }

    // -------------------------------------------------------------------------
    // 🚫 Checking what was given
    // -------------------------------------------------------------------------

    /**
     * A subject or a display name: trimmed of spaces at each end, then refused
     * if it holds anything that is not ordinary text.
     *
     * Refused: every control character (line breaks, tabs, the zero byte),
     * the Unicode line and paragraph separators, and bytes that are not valid
     * UTF-8. Only SPACES are trimmed. Trimming line breaks too would quietly
     * accept a value that ends in one, and "quietly accept" is the habit this
     * check exists to break.
     */
    private static function headerText(string $field, string $value): string
    {
        $value = trim($value, ' ');
        if (preg_match('//u', $value) !== 1) {
            throw new MessageRefused($field, self::label($field) . ' is not valid UTF-8 text.');
        }
        if (preg_match('/[\x{00}-\x{1F}\x{7F}-\x{9F}\x{2028}\x{2029}]/u', $value) === 1) {
            throw new MessageRefused($field, self::label($field) . ' contains a line break or another control character.');
        }
        if (strlen($value) > 998) {
            throw new MessageRefused($field, self::label($field) . ' is too long.');
        }
        return $value;
    }

    /**
     * An e-mail address, held to a stricter rule than the standard allows:
     * ordinary characters before the "@", a real domain after it, and nothing
     * else. The standard's quoted forms (which may hold spaces and brackets)
     * are refused, because the address is later written into a command to the
     * mail server and must not be able to end that command early.
     */
    private static function address(string $field, string $value): string
    {
        $value = trim($value, ' ');
        $label = self::label($field);
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new MessageRefused($field, $label . ' contains a line break or another control character.');
        }
        $at = strrpos($value, '@');
        if ($at === false || $at === 0) {
            throw new MessageRefused($field, $label . ' is not an e-mail address.');
        }
        $local = substr($value, 0, $at);
        $domain = substr($value, $at + 1);

        // 🌍 A domain with non-English letters is converted to the ASCII form
        // mail servers use. Where PHP cannot do that, it is refused, not guessed.
        if (preg_match('/[^\x20-\x7E]/', $domain) === 1) {
            // Called with its own defaults, which since PHP 7.4 are the current
            // standard (UTS #46). Naming those two defaults as constants gave the
            // same answers (compared on six domains) but made a static analyser
            // running on a PHP without the "intl" extension report them as
            // undefined: they belong to that extension, like the function itself.
            $ascii = function_exists('idn_to_ascii') === true ? idn_to_ascii($domain) : false;
            if (is_string($ascii) !== true || $ascii === '') {
                throw new MessageRefused($field, $label . ' has a domain with letters this server cannot convert.');
            }
            $domain = $ascii;
        }

        $atom = '[' . self::ATEXT . ']+';
        $domainLabel = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?';
        if (
            preg_match('/\A' . $atom . '(?:\.' . $atom . ')*\z/', $local) !== 1
            || preg_match('/\A' . $domainLabel . '(?:\.' . $domainLabel . ')+\z/', $domain) !== 1
            || strlen($local) > 64
            || strlen($local . '@' . $domain) > 254
            || filter_var($local . '@' . $domain, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new MessageRefused($field, $label . ' is not an e-mail address this library can send to.');
        }
        return $local . '@' . $domain;
    }

    /**
     * The plain-text or the HTML content. Any bytes are safe here, because the
     * part is base64 on the wire; what is checked is that it exists and is
     * valid UTF-8, which is what the part says it is. Line breaks are made
     * CR LF, the form the e-mail standard asks for inside a text part.
     */
    private static function bodyText(string $field, string $value): string
    {
        if (trim($value) === '') {
            throw new MessageRefused($field, self::label($field) . ' is empty. A message needs both a plain-text and an HTML version.');
        }
        if (preg_match('//u', $value) !== 1) {
            throw new MessageRefused($field, self::label($field) . ' is not valid UTF-8 text.');
        }
        return str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $value);
    }

    /** An attachment's name: the last part of a path only, checked like any other header text. */
    private static function fileName(string $value): string
    {
        // Cut at the last slash of either kind by hand: PHP's basename() treats
        // "\" as a separator only on Windows.
        foreach (['/', '\\'] as $separator) {
            $cut = strrpos($value, $separator);
            if ($cut !== false) {
                $value = substr($value, $cut + 1);
            }
        }
        $value = self::headerText('attachmentName', $value);
        if ($value === '' || strlen($value) > 255) {
            throw new MessageRefused('attachmentName', 'An attachment has no usable file name.');
        }
        return $value;
    }

    private static function contentType(string $value): string
    {
        $value = trim($value, ' ');
        if ($value === '') {
            return 'application/octet-stream';
        }
        $token = '[A-Za-z0-9!#$&^_.+-]{1,60}';
        if (preg_match('/\A' . $token . '\/' . $token . '\z/', $value) !== 1) {
            throw new MessageRefused('attachmentType', 'An attachment has a content type that is not in the form kind/detail.');
        }
        return strtolower($value);
    }

    private static function attachmentContent(string $content): string
    {
        // An empty attachment is almost always a file that failed to be made.
        if ($content === '') {
            throw new MessageRefused('attachment', 'An attachment is empty.');
        }
        if (strlen($content) > self::MAX_ATTACHMENT_BYTES) {
            throw new MessageRefused('attachment', 'An attachment is bigger than 10 MB.');
        }
        return $content;
    }

    /** A field's name as a person would say it, for the refusal text. */
    private static function label(string $field): string
    {
        return match ($field) {
            'subject' => 'The subject',
            'fromName' => "The sender's name",
            'fromAddress' => "The sender's address",
            'to' => "A recipient's address",
            'toName' => "A recipient's name",
            'replyTo' => 'The reply-to address',
            'text' => 'The plain-text version',
            'html' => 'The HTML version',
            'attachmentName' => "An attachment's file name",
            default => 'A part of the message',
        };
    }

    // -------------------------------------------------------------------------
    // 🧱 Writing the lines
    // -------------------------------------------------------------------------

    /** True for text made only of printable ASCII that no mail program could mistake for an encoded word. */
    private static function isPlain(string $value): bool
    {
        return preg_match('/\A[\x20-\x7E]*\z/', $value) === 1 && strpos($value, '=?') === false;
    }

    /**
     * Text as RFC 2047 "encoded words": `=?UTF-8?B?...?=`, each carrying a
     * whole number of characters. A mail program joins neighbouring encoded
     * words back together and ignores the line breaks between them.
     *
     * @return list<string>
     */
    private static function encodedWords(string $value): array
    {
        // Split into characters first. Cutting the bytes at a fixed length
        // instead could cut a multi-byte letter in half, and each half would
        // then show as a broken character.
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        $words = [];
        $chunk = '';
        foreach ($characters === false ? [] : $characters as $character) {
            if ($chunk !== '' && strlen($chunk) + strlen($character) > self::WORD_BYTES) {
                $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
            }
            $chunk .= $character;
        }
        if ($chunk !== '') {
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }
        return $words;
    }

    /**
     * Lay pieces out after a label, starting a new line (which begins with a
     * space, as the standard requires) whenever the next piece would pass 78
     * characters. The first piece always shares the label's line.
     *
     * @param list<string> $pieces
     */
    private static function fold(string $label, array $pieces): string
    {
        $lines = [];
        $line = $label . ':';
        foreach ($pieces as $index => $piece) {
            if ($index > 0 && strlen($line) + 1 + strlen($piece) > self::LINE) {
                $lines[] = $line;
                $line = '';
            }
            $line .= ' ' . $piece;
        }
        $lines[] = $line;
        return implode("\r\n", $lines);
    }

    private static function subjectLine(string $subject): string
    {
        if ($subject === '') {
            return 'Subject:';
        }
        // A short, plain subject goes as it is. Anything else (non-English
        // letters, or too long for one line) is written as encoded words,
        // which fold cleanly and come back exactly as they went in.
        if (self::isPlain($subject) === true && strlen('Subject: ' . $subject) <= self::LINE) {
            return 'Subject: ' . $subject;
        }
        return self::fold('Subject', self::encodedWords($subject));
    }

    /**
     * A From, To or Reply-To line.
     *
     * @param list<array{0:string,1:string}> $mailboxes Each as [address, name].
     */
    private static function mailboxLine(string $label, array $mailboxes): string
    {
        $pieces = [];
        $lastIndex = count($mailboxes) - 1;
        foreach ($mailboxes as $index => [$address, $name]) {
            $comma = $index === $lastIndex ? '' : ',';
            if ($name === '') {
                $pieces[] = $address . $comma;
                continue;
            }
            if (self::isPlain($name) === true && strlen($name) <= self::PLAIN_MAX) {
                // A simple name goes as it is. One with a comma, a quote mark
                // or other punctuation goes inside quote marks, or a mail
                // program would read the comma as the start of a second
                // recipient.
                $pieces[] = preg_match('/\A[ ' . self::ATEXT . ']+\z/', $name) === 1
                    ? $name
                    : '"' . addcslashes($name, '"\\') . '"';
            } else {
                array_push($pieces, ...self::encodedWords($name));
            }
            $pieces[] = '<' . $address . '>' . $comma;
        }
        return self::fold($label, $pieces);
    }

    /**
     * The line that names an attachment's file.
     *
     * A short plain name is written directly. Any other is written the
     * RFC 2231 way: percent-encoded UTF-8, in numbered pieces, so a long
     * Chinese file name survives and no line is long.
     */
    private static function dispositionLine(string $name): string
    {
        if (self::isPlain($name) === true && strlen($name) <= self::PLAIN_MAX) {
            return "Content-Disposition: attachment;\r\n filename=\"" . addcslashes($name, '"\\') . '"';
        }
        // Up to 18 units a piece, where a unit is one plain character or one
        // whole "%XX" (so at most 54 characters, which keeps every line inside
        // 78). A piece is therefore never cut inside a "%XX".
        preg_match_all('/(?:%[0-9A-F]{2}|[^%]){1,18}/', rawurlencode($name), $found);
        $pieces = $found[0];
        if (count($pieces) === 1) {
            return "Content-Disposition: attachment;\r\n filename*=UTF-8''" . $pieces[0];
        }
        $lines = [];
        foreach ($pieces as $index => $piece) {
            $lines[] = ' filename*' . $index . '*=' . ($index === 0 ? "UTF-8''" : '') . $piece;
        }
        return "Content-Disposition: attachment;\r\n" . implode(";\r\n", $lines);
    }

    /**
     * One part of the body: its own header lines, a blank line, then the
     * content as base64 in 76-character lines.
     *
     * @param list<string> $headers
     */
    private static function part(array $headers, string $content): string
    {
        return implode("\r\n", $headers) . "\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($content), 76, "\r\n");
    }
}
