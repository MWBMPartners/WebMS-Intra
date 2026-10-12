<?php
// Path: tools/maillib/tests/stands-alone-fixtures/clean.php
/**
 * THE CLEAN FILE for stands-alone.php. It is never run and is not part of the
 * library. It holds only what the library MAY do, written in the ways most
 * likely to fool a careless check, and must set off no rule at all.
 *
 * A comment may name anything: $_SERVER, global $SETTINGS, getenv('HOME'),
 * Portal\Core\Site, PORTAL_ROOT, eval() and `backticks` are only words here.
 */

declare(strict_types=1);

namespace MWBM\Mail;

use MWBM\Mail\Smtp\{Connection, SmtpConfig};
use MWBM\Mail\Smtp\SmtpTransport as Route;

final class CleanExample implements Clock
{
    public const FIRST = 'first', SECOND = 'second';

    private static ?\WeakMap $kept = null;

    /** @var list<string> */
    private array $lines = [];

    public function __construct(private readonly SmtpConfig $config, private readonly ?LogSink $log = null)
    {
        // Text is only text: none of these is a call or a variable.
        $this->lines[] = 'the words getenv( and $_SERVER and global $x inside single quotes';
        $this->lines[] = "a new line\n and a name in double quotes: {$this->config->host}";
        self::$kept ??= new \WeakMap();
    }

    public function now(): int
    {
        return time();
    }

    public function ticks(): float
    {
        return hrtime(true) / 1000000000;
    }

    /** @return list<string> */
    public function shout(#[\SensitiveParameter] string $secret, \Closure $each): array
    {
        // A closure written on the spot, handed to a function that takes a callback.
        $loud = array_map(static fn (string $line): string => strtoupper($line) . ' (' . 'text in the closure' . ')', $this->lines);
        $quiet = array_map(strtolower(...), $loud);
        set_error_handler(static function (int $level, string $message): bool {
            return $level > 0 && $message !== '';
        });
        try {
            // A closure handed in is called as a method, never as `$each(...)`.
            $each->__invoke(implode(', ', $quiet), strlen($secret));
        } catch (MessageRefused | \InvalidArgumentException $refusal) {
            $this->log?->record(SendResult::REFUSED, SendResult::STAGE_MESSAGE, $refusal->getMessage(), ['code' => 0]);
        } catch (\Throwable) {
            throw new \LogicException('a class of PHP itself, written in full');
        } finally {
            restore_error_handler();
        }
        $kind = match (true) {
            $this->config->port === 465 => SmtpConfig::TLS,
            $this instanceof Clock => Connection::MODE_CHECKED,
            default => Route::class,
        };
        $padded = str_repeat(string: $kind, times: PHP_INT_SIZE > 4 ? 2 : 1);
        return [$padded, DIRECTORY_SEPARATOR, (string) STREAM_CLIENT_CONNECT, self::FIRST, static::SECOND];
    }
}
