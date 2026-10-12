<?php
// Path: web/_core/maillib/src/Secret.php
/**
 * -----------------------------------------------------------------------------
 * Secret — a password that cannot be printed by accident 🔒
 * -----------------------------------------------------------------------------
 * A mailbox password is handed to the library wrapped in one of these. The
 * wrapper exists so that the ordinary ways a value escapes — being printed,
 * dumped while debugging, written into a log with an object, saved in a
 * session, shown in the arguments of an error's stack trace — all show nothing.
 *
 * HOW. The password is NOT kept in a property of this object. It is kept in a
 * private table held by the class, with this object as the key. So a dump of
 * the object, of anything holding it, or a cast of it to an array, finds no
 * property to show. (A first draft kept it in a private property and hid it
 * with `__debugInfo()`. That covers `var_dump` and `print_r` but NOT
 * `var_export` or an array cast, which read private properties directly.)
 * When the object is no longer used, PHP removes its entry by itself.
 *
 * WHAT THIS CANNOT DO.
 *   - It does not stop code that deliberately calls `reveal()`. It stops
 *     accidents, not intent.
 *   - It does not scrub the computer's memory. PHP copies strings freely and
 *     gives no way to overwrite every copy, so `wipe()` forgets the password;
 *     it does not promise the bytes are gone from memory.
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

final class Secret
{
    /** What is shown in place of the password, everywhere. */
    public const HIDDEN = '[removed]';

    /**
     * The shortest piece of a password, or of its encoded form, that is still
     * removed when a server repeats back only part of what it was sent.
     */
    private const PIECE = 8;

    /** @var \WeakMap<Secret,string>|null The passwords, keyed by their wrapper. Only `vault()` touches this. */
    private static ?\WeakMap $vault = null;

    public function __construct(#[\SensitiveParameter] string $value)
    {
        self::vault()->offsetSet($this, $value);
    }

    /** The password itself. Call it as late as possible and keep the answer nowhere. */
    public function reveal(): string
    {
        return self::vault()[$this] ?? '';
    }

    public function isEmpty(): bool
    {
        return $this->reveal() === '';
    }

    /** Forget the password. After this the wrapper is empty for good. */
    public function wipe(): void
    {
        self::vault()->offsetSet($this, '');
    }

    /**
     * The one table of passwords, made the first time it is needed.
     *
     * Every use goes through here so that the table can never be missing.
     * This used to be made in the constructor and then used directly. An
     * object built WITHOUT its constructor (PHP allows that, through
     * reflection) in a program that had made no Secret yet then found no
     * table: reading it gave an empty password, which is right, but `wipe()`
     * stopped with a TypeError. Seen by running it; a static analyser had
     * pointed at the line. Such an object now simply behaves as an empty Secret.
     *
     * @return \WeakMap<Secret,string>
     */
    private static function vault(): \WeakMap
    {
        if (self::$vault === null) {
            /** @var \WeakMap<Secret,string> $fresh */
            $fresh = new \WeakMap();
            self::$vault = $fresh;
        }
        return self::$vault;
    }

    /**
     * Remove the password from a piece of text, in every form it travels in.
     *
     * 🔒 WHY THIS IS NEEDED. Some mail servers answer a line they did not
     * understand by repeating it. The sign-in line carries the password, so
     * the server's reply can carry it too, and the reply is exactly what gets
     * shown to an administrator and written to a log.
     *
     * The forms removed: the password as it is; its base64 form (how LOGIN
     * sign-in sends it); and the base64 of `$before` followed by the password
     * (how PLAIN sign-in sends it, where `$before` is the user name between
     * two zero bytes). A server may repeat only PART of a line, so any run of
     * 8 or more characters taken from one of those forms is removed as well.
     *
     * WHAT THIS CANNOT DO: it cannot recognise a password the server has
     * changed in some other way (reversed, or re-encoded). No real server does
     * that; it is said so nobody reads this as a promise it is not.
     */
    public function scrub(string $text, string $before = ''): string
    {
        $value = $this->reveal();
        if ($value === '' || $text === '') {
            return $text;
        }
        $forms = [$value, base64_encode($value)];
        if ($before !== '') {
            $forms[] = base64_encode($before . $value);
        }

        // Mark every position of the text that belongs to a leaked piece, then
        // replace each marked run once. Replacing piece by piece instead would
        // leave the tail of a long leak standing beside the first replacement.
        $marked = str_repeat('0', strlen($text));
        foreach ($forms as $form) {
            $pieceLength = min(self::PIECE, strlen($form));
            for ($start = 0; $start + $pieceLength <= strlen($form); $start++) {
                $piece = substr($form, $start, $pieceLength);
                $from = 0;
                while (($at = strpos($text, $piece, $from)) !== false) {
                    $marked = substr_replace($marked, str_repeat('1', $pieceLength), $at, $pieceLength);
                    $from = $at + 1;
                }
            }
        }
        if (strpos($marked, '1') === false) {
            return $text;
        }
        $clean = '';
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            if ($marked[$i] === '0') {
                $clean .= $text[$i];
            } elseif ($i === 0 || $marked[$i - 1] === '0') {
                $clean .= self::HIDDEN;
            }
        }
        return $clean;
    }

    /** Putting the wrapper into a string gives the placeholder, never the password. */
    public function __toString(): string
    {
        return self::HIDDEN;
    }

    /** @return array<string,string> What a debugging dump is allowed to see. */
    public function __debugInfo(): array
    {
        return ['value' => self::HIDDEN];
    }

    /**
     * A secret is never saved: not in a session, a cache or a queue.
     *
     * @return array<string,mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('A Secret cannot be saved or copied into storage.');
    }

    /** @param array<string,mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('A Secret cannot be rebuilt from storage.');
    }

    /** A copy would be an empty wrapper that looks like a full one, so there are no copies. */
    private function __clone()
    {
    }
}
