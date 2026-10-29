<?php
// Path: _core/BackupArchive.php
/**
 * -----------------------------------------------------------------------------
 * One snapshot, as one encrypted file somebody can keep 📦🔐
 * -----------------------------------------------------------------------------
 * `Portal\Core\DbBackup` writes a snapshot as a FOLDER under `web/_backups/`:
 * one `.jsonl` file per table, and `_manifest.json` written last. That is right
 * for the server and useless for a customer who wants a copy somewhere else.
 * This class is the ONE place that turns such a folder into a single file and
 * back again, and the ONE place that encrypts or decrypts one.
 *
 * WHY IT IS ENCRYPTED AT ALL, AND WHY THAT IS NOT OPTIONAL
 * -----------------------------------------------------------------------------
 * A snapshot holds every name, address, telephone number, giving record, care
 * note and prayer request in the database. Downloading one is the single moment
 * all of that leaves the server in one piece. A plain file in somebody's
 * Downloads folder, or an e-mail attachment, is a data breach waiting for a lost
 * laptop. So there is no "download it unencrypted" option, and the key is never
 * stored: if it is lost, the file cannot be opened, which is the honest cost of
 * the guarantee.
 *
 * HOW IT IS PROTECTED (the owner's decision, 29 October 2026)
 * -----------------------------------------------------------------------------
 * A key the global administrator types, by default. Optionally AND the
 * installation's own key file (`_auth_keys/enc.key`), which makes the file
 * useless to anybody who does not also hold that file.
 *
 *   KEY_PASSPHRASE  the typed key alone. The default, because it is the one
 *                   that still works when the server has been lost — which is
 *                   the case a backup is FOR.
 *   KEY_BOTH        the typed key AND the key file. Safer against a stolen
 *                   download; useless if the server is gone and the key file
 *                   with it. The administrator is told exactly that before
 *                   choosing it.
 *
 * WHICH ONE WAS USED IS WRITTEN IN THE FILE'S OWN HEADER, so an import can ask
 * for the right things instead of failing with "wrong key".
 *
 * WHAT IT IS BUILT ON, AND WHY NOT SOMETHING HAND-ROLLED
 * -----------------------------------------------------------------------------
 * libsodium's `secretstream`, which exists for exactly this job: a long file
 * encrypted in chunks, where every chunk is authenticated AND tied to its place
 * in the order, so a file cannot be truncated, reordered or spliced without the
 * read failing. The portal already uses libsodium for settings
 * (`encrypt_setting()` in bootstrap.php), so this is the same library, not a
 * second one.
 *
 * A hand-rolled "encrypt each block and add a checksum" was considered and
 * rejected. Chunked authenticated encryption has a short list of ways to be
 * subtly wrong — a reused nonce, a chunk that can be moved, a truncation that
 * reads as a complete file — and `secretstream` is the primitive that already
 * gets all of them right.
 *
 * The typed key becomes a 32-byte key through `sodium_crypto_pwhash` (Argon2id),
 * which is deliberately slow so that guessing is slow. Its salt and its cost
 * settings are written into the header, so a later release can raise the cost
 * without making every existing file unreadable.
 *
 * STREAMED, NEVER HELD IN MEMORY
 * -----------------------------------------------------------------------------
 * This portal runs on shared hosting where 128 MB is a normal memory limit and
 * a database can be far larger than that. So:
 *
 *   • packing walks the folder and writes as it goes, in 64 KB pieces. There is
 *     no temporary archive on disk and no whole table in memory.
 *   • unpacking reads the same way.
 *   • the entry list is INSIDE the encrypted part, so a file's own contents
 *     cannot be identified without the key.
 *
 * WHAT THE FILE LOOKS LIKE
 * -----------------------------------------------------------------------------
 *   line 1   a plain JSON envelope, then "\n". Readable without any key, and
 *            deliberately so: an import has to know what to ask for before it
 *            can ask. It names the format, the snapshot, the key mode, the salt
 *            and the cost settings. It holds NOTHING secret.
 *   then     24 bytes, libsodium's own stream header.
 *   then     chunks, each: 4 bytes big-endian length, then that many bytes.
 *            The last chunk carries libsodium's FINAL tag, which is what proves
 *            the file is whole rather than cut short.
 *
 *   Inside the encrypted stream, entries run one after another:
 *            a JSON line {"name":"tblX.jsonl","bytes":123} then "\n" then those
 *            bytes. A final line {"end":true,"entries":N} closes the list.
 *
 * WHAT IT WILL NOT DO
 * -----------------------------------------------------------------------------
 *   • It does not restore anything. Unpacking gives a snapshot folder; putting
 *     it back is `DbBackup::restoreAll()`, unchanged, with every guard it
 *     already has (including `WHOLE_DATABASE_ONLY`).
 *   • It does not check that the snapshot inside is any good. `DbBackup` judges
 *     that, and an unpacked folder goes through exactly the same checks as one
 *     the server made itself.
 *   • It cannot tell a wrong key from a damaged file. libsodium answers "this
 *     did not authenticate" to both, and guessing between them for a nicer
 *     message would mean trusting something unauthenticated. The wording says
 *     both possibilities.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/569
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use RuntimeException;

final class BackupArchive
{
    /** The form this release writes. An envelope with another number is refused. */
    public const FORMAT = 1;

    /** 🔑 The typed key alone. */
    public const KEY_PASSPHRASE = 'passphrase';

    /** 🔑 The typed key AND the installation's key file. */
    public const KEY_BOTH = 'passphrase+keyfile';

    /** Every key mode this release understands. */
    public const KEY_MODES = [self::KEY_PASSPHRASE, self::KEY_BOTH];

    /**
     * 📏 How much plaintext goes into one chunk.
     *
     * 64 KB. Small enough that packing a 2 GB database never needs more than a
     * breath of memory; large enough that the 17 bytes libsodium adds per chunk
     * are noise (0.03%) rather than a tax.
     */
    private const CHUNK = 65536;

    /**
     * 🧷 The shortest typed key this will accept.
     *
     * Twelve characters. Not a password-strength opinion: it is the point below
     * which Argon2's slowness stops being the thing protecting the file. The
     * page says so rather than scolding.
     */
    public const MIN_PASSPHRASE = 12;

    /** 📛 The extension a packed snapshot is given. */
    public const EXTENSION = 'portalbackup';

    /**
     * 🛑 The most key work a file may ASK for, whoever wrote it.
     *
     * The envelope that carries these is plaintext and unauthenticated, and the
     * work is done BEFORE the stream is authenticated, so these numbers are the
     * only thing standing between a crafted file and a server that stops
     * answering. Measured by the independent check on a good file with only the
     * envelope rewritten: 2 GB of key work took 3.2 seconds, 16 GB took 30.8,
     * and 1 TB never returned at all. Argon2 asks the operating system for that
     * memory directly, so PHP's `memory_limit` does not apply and on shared
     * hosting it is the customer's account that pays.
     *
     * 1 GB is libsodium's own SENSITIVE preset, so it is as much as any sane
     * file could want, and far above what this release writes (64 MB).
     */
    public const MAX_PWHASH_MEM = 1073741824;

    /** 🛑 And the most passes. See MAX_PWHASH_MEM. */
    public const MAX_PWHASH_OPS = 10;

    /**
     * 🛑 The least key work a file may ask for.
     *
     * 8 KB is Argon2's own floor. There is no `SODIUM_CRYPTO_PWHASH_MEMLIMIT_MIN`
     * in PHP — only the INTERACTIVE, MODERATE and SENSITIVE presets — so this is
     * named here rather than reached for and found missing, which is what
     * happened first.
     *
     * A floor matters as well as a ceiling: a file claiming trivial key work
     * would make guessing the typed key cheap for whoever holds the file. Such
     * a file cannot have been written by this portal, so it is refused rather
     * than opened quickly.
     */
    public const MIN_PWHASH_MEM = 8192;

    /**
     * 🔑 Turn a typed key into the 32 bytes libsodium wants.
     *
     * @param string      $passphrase What the administrator typed.
     * @param string      $salt       16 bytes, from the envelope.
     * @param int         $ops        Argon2 time cost, from the envelope.
     * @param int         $mem        Argon2 memory cost in bytes, from the envelope.
     * @param string|null $keyFile    The key file's contents for KEY_BOTH, else null.
     *
     * @throws RuntimeException When the key cannot be worked out at all.
     */
    private static function keyFrom(
        string $passphrase,
        string $salt,
        int $ops,
        int $mem,
        ?string $keyFile
    ): string {
        try {
            $derived = sodium_crypto_pwhash(
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
                $passphrase,
                $salt,
                $ops,
                $mem,
                SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
            );
        } catch (\Throwable $e) {
            // Argon2 asks the operating system for its memory directly rather
            // than through PHP's limit, so a host with little free memory can
            // refuse it. Saying which setting was asked for is what makes that
            // diagnosable; the typed key is NOT in the message.
            throw new RuntimeException(
                'The key could not be worked out on this server (Argon2id asked for '
                . (int) round($mem / 1048576) . ' MB of memory and ' . $ops . ' passes).'
            );
        }

        if ($keyFile === null) {
            return $derived;
        }

        // 🔐 BOTH: a keyed hash of the key file, keyed with the typed key's
        //    result. Either one alone is useless. generichash is used rather
        //    than joining the two and hashing, because a keyed hash is what
        //    this is, and writing it as concatenation invites somebody to
        //    "simplify" it into something with a length-extension shape.
        $combined = sodium_crypto_generichash(
            $keyFile,
            $derived,
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES
        );
        sodium_memzero($derived);

        return $combined;
    }

    /**
     * 📦 Pack a snapshot folder into one encrypted file.
     *
     * @param string      $snapshotDir The folder, as `DbBackup::pathOf()` gives it.
     * @param string      $intoFile    Where to write. Must not exist.
     * @param string      $passphrase  The typed key.
     * @param string      $keyMode     One of KEY_MODES.
     * @param string|null $keyFile     The key file's contents, required for KEY_BOTH.
     *
     * @return array{entries: int, bytes: int} What went in, and the file's size.
     *
     * @throws RuntimeException On anything that would otherwise write a file
     *                          nobody can open, or a half-written one.
     */
    public static function pack(
        string $snapshotDir,
        string $intoFile,
        string $passphrase,
        string $keyMode,
        ?string $keyFile = null
    ): array {
        if (in_array($keyMode, self::KEY_MODES, true) === false) {
            throw new RuntimeException('That is not a way of protecting a backup.');
        }
        if (strlen($passphrase) < self::MIN_PASSPHRASE) {
            throw new RuntimeException('The key must be at least ' . self::MIN_PASSPHRASE . ' characters.');
        }
        if ($keyMode === self::KEY_BOTH && ($keyFile === null || $keyFile === '')) {
            throw new RuntimeException(
                'This backup was to include the installation\'s key file, and there is no key file to include.'
            );
        }
        if (is_dir($snapshotDir) === false) {
            throw new RuntimeException('There is no such snapshot.');
        }
        if (file_exists($intoFile) === true) {
            throw new RuntimeException('There is already a file of that name.');
        }

        $entries = self::entriesIn($snapshotDir);
        if ($entries === []) {
            throw new RuntimeException('That snapshot holds no files.');
        }

        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $ops  = SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE;
        $mem  = SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;
        $key  = self::keyFrom($passphrase, $salt, $ops, $mem, $keyMode === self::KEY_BOTH ? $keyFile : null);

        // 📝 The envelope. Nothing secret is in it, on purpose: an import must
        //    be able to read what to ask for before it can ask.
        $envelope = [
            'format'    => self::FORMAT,
            'snapshot'  => basename($snapshotDir),
            'keyMode'   => $keyMode,
            'salt'      => base64_encode($salt),
            'pwhashOps' => $ops,
            'pwhashMem' => $mem,
            'written'   => gmdate('Y-m-d\TH:i:s\Z'),
            'portal'    => defined('PORTAL_VERSION') === true ? (string) PORTAL_VERSION : '',
        ];
        $envelopeLine = json_encode($envelope, JSON_UNESCAPED_SLASHES);
        if ($envelopeLine === false) {
            sodium_memzero($key);
            throw new RuntimeException('The backup\'s own description could not be written.');
        }

        // 🧯 'xb': refuses to open a file that already exists, so two runs at
        //    once cannot write over each other. The existence test above is for
        //    a clear message; this is the guarantee.
        $out = fopen($intoFile, 'xb');
        if ($out === false) {
            sodium_memzero($key);
            throw new RuntimeException('The backup file could not be created.');
        }

        try {
            [$stream, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            self::write($out, $envelopeLine . "\n");
            self::write($out, $header);

            $buffer = '';
            $count  = 0;

            foreach ($entries as $name => $path) {
                $size = filesize($path);
                if ($size === false) {
                    throw new RuntimeException('A file in the snapshot could not be measured.');
                }
                $line = json_encode(['name' => $name, 'bytes' => $size], JSON_UNESCAPED_SLASHES);
                if ($line === false) {
                    throw new RuntimeException('A file name in the snapshot could not be written.');
                }
                $buffer .= $line . "\n";

                $in = fopen($path, 'rb');
                if ($in === false) {
                    throw new RuntimeException('A file in the snapshot could not be read.');
                }
                try {
                    $written = 0;
                    while (feof($in) === false) {
                        $piece = fread($in, self::CHUNK);
                        if ($piece === false) {
                            throw new RuntimeException('A file in the snapshot could not be read to the end.');
                        }
                        if ($piece === '') {
                            continue;
                        }
                        $written += strlen($piece);
                        $buffer  .= $piece;
                        // Push whole chunks out as they fill, so the buffer
                        // never grows past one chunk plus one read.
                        while (strlen($buffer) >= self::CHUNK) {
                            self::pushChunk($out, $stream, substr($buffer, 0, self::CHUNK), false);
                            $buffer = substr($buffer, self::CHUNK);
                        }
                    }
                } finally {
                    fclose($in);
                }

                // 🧮 A file that changed size while being read would make the
                //    entry list disagree with the bytes that follow it, and the
                //    unpack would then read into the next entry. A snapshot is
                //    finished before it is packed, so this cannot normally
                //    happen — which is exactly why it is checked rather than
                //    assumed.
                if ($written !== $size) {
                    throw new RuntimeException(
                        'A file in the snapshot changed while it was being packed, so the backup was not written.'
                    );
                }
                $count++;
            }

            $end = json_encode(['end' => true, 'entries' => $count], JSON_UNESCAPED_SLASHES);
            if ($end === false) {
                throw new RuntimeException('The backup\'s closing line could not be written.');
            }
            $buffer .= $end . "\n";

            // Everything left, in chunk-sized pieces, the last one tagged FINAL.
            while (strlen($buffer) > self::CHUNK) {
                self::pushChunk($out, $stream, substr($buffer, 0, self::CHUNK), false);
                $buffer = substr($buffer, self::CHUNK);
            }
            self::pushChunk($out, $stream, $buffer, true);

            if (fflush($out) === false) {
                throw new RuntimeException('The backup file could not be flushed to disk.');
            }
        } catch (\Throwable $e) {
            fclose($out);
            // 🧹 A half-written backup is worse than none: somebody would keep
            //    it believing it was a copy of their data.
            @unlink($intoFile);
            sodium_memzero($key);
            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage());
        }

        fclose($out);
        sodium_memzero($key);

        $bytes = filesize($intoFile);

        return ['entries' => $count, 'bytes' => $bytes === false ? 0 : $bytes];
    }

    /**
     * 📂 Read the envelope of a packed backup, without any key.
     *
     * This is what lets a page ask for the right things. It authenticates
     * NOTHING — the envelope is plaintext and an attacker could have written
     * anything in it — so what comes back is for deciding what to ask the
     * administrator for, and for showing them. Do not TRUST it.
     *
     * BE PRECISE, because an earlier version of this note said "never act on
     * it" while the code beneath it did: `unpack()` necessarily acts on the
     * salt and the two cost settings, because they are what turn a typed key
     * into the key that opens the file, and they have to be readable before any
     * key exists. What makes that safe is that a changed salt or cost simply
     * produces a different key and the stream then fails to authenticate — and
     * that the costs are BOUNDED here, so a crafted file cannot ask for a
     * terabyte of work.
     *
     * @return array<string,mixed> The envelope.
     *
     * @throws RuntimeException When this is not a backup this release can read.
     */
    public static function envelopeOf(string $file): array
    {
        if (is_file($file) === false || is_readable($file) === false) {
            throw new RuntimeException('There is no such backup file, or it cannot be read.');
        }
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The backup file could not be opened.');
        }
        // A backup's envelope is one short line. Reading a bounded amount means
        // a file that is one enormous line without a newline — which is what a
        // wrong file usually is — cannot be read into memory whole.
        $line = stream_get_line($handle, 8192, "\n");
        fclose($handle);
        if ($line === false || $line === '') {
            throw new RuntimeException('That file does not start like a portal backup.');
        }

        $envelope = json_decode($line, true);
        if (is_array($envelope) === false) {
            throw new RuntimeException('That file does not start like a portal backup.');
        }
        if ((int) ($envelope['format'] ?? 0) !== self::FORMAT) {
            throw new RuntimeException(
                'That backup was written in form ' . (int) ($envelope['format'] ?? 0)
                . ', and this release reads form ' . self::FORMAT . '.'
            );
        }
        if (in_array((string) ($envelope['keyMode'] ?? ''), self::KEY_MODES, true) === false) {
            throw new RuntimeException('That backup is protected in a way this release does not know.');
        }
        $salt = base64_decode((string) ($envelope['salt'] ?? ''), true);
        if ($salt === false || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES) {
            throw new RuntimeException('That backup\'s description is damaged.');
        }

        // 🛑 THE COST SETTINGS ARE BOUNDED, and this is the one check here that
        //    an attacker needs no key at all to reach: the envelope is plaintext,
        //    so anybody who can touch the file sets the cost, and the cost is
        //    paid before the stream is authenticated. See MAX_PWHASH_MEM for
        //    what that measured.
        $ops = (int) ($envelope['pwhashOps'] ?? 0);
        $mem = (int) ($envelope['pwhashMem'] ?? 0);
        if ($ops < 1 || $ops > self::MAX_PWHASH_OPS) {
            throw new RuntimeException(
                'That backup asks for ' . $ops . ' passes of key work, and this release allows at most '
                . self::MAX_PWHASH_OPS . '. It was not opened.'
            );
        }
        if ($mem < self::MIN_PWHASH_MEM || $mem > self::MAX_PWHASH_MEM) {
            throw new RuntimeException(
                'That backup asks for ' . (int) round($mem / 1048576) . ' MB of key work, and this release '
                . 'allows between ' . (int) round(self::MIN_PWHASH_MEM / 1024) . ' KB and '
                . (int) round(self::MAX_PWHASH_MEM / 1048576) . ' MB. It was not opened.'
            );
        }

        return $envelope;
    }

    /**
     * 📂 Unpack an encrypted backup into a folder.
     *
     * @param string      $file       The packed backup.
     * @param string      $intoDir    A folder to write into. Must not exist.
     * @param string      $passphrase The typed key.
     * @param string|null $keyFile    The key file's contents, for a KEY_BOTH backup.
     *
     * @return array{entries: int, snapshot: string}
     *
     * @throws RuntimeException On a wrong key, a damaged file, a file cut short,
     *                          or anything in it that is not a snapshot's file.
     */
    public static function unpack(
        string $file,
        string $intoDir,
        string $passphrase,
        ?string $keyFile = null
    ): array {
        $envelope = self::envelopeOf($file);
        $keyMode  = (string) $envelope['keyMode'];
        if ($keyMode === self::KEY_BOTH && ($keyFile === null || $keyFile === '')) {
            throw new RuntimeException(
                'That backup also needs this installation\'s key file, and there is none to use.'
            );
        }
        if (file_exists($intoDir) === true) {
            throw new RuntimeException('There is already something of that name to unpack into.');
        }

        $salt = base64_decode((string) $envelope['salt'], true);
        $key  = self::keyFrom(
            $passphrase,
            (string) $salt,
            (int) $envelope['pwhashOps'],
            (int) $envelope['pwhashMem'],
            $keyMode === self::KEY_BOTH ? $keyFile : null
        );

        $in = fopen($file, 'rb');
        if ($in === false) {
            sodium_memzero($key);
            throw new RuntimeException('The backup file could not be opened.');
        }

        if (@mkdir($intoDir, 0700, true) === false) {
            fclose($in);
            sodium_memzero($key);
            throw new RuntimeException('The folder to unpack into could not be made.');
        }

        try {
            // Past the envelope line, then the stream header.
            if (stream_get_line($in, 8192, "\n") === false) {
                throw new RuntimeException('That file does not start like a portal backup.');
            }
            $header = self::readExactly($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $stream = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            if ($stream === false) {
                throw new RuntimeException('That backup could not be opened: the wrong key, or a damaged file.');
            }

            $state = self::readEntries($in, $stream, $intoDir);
        } catch (\Throwable $e) {
            fclose($in);
            sodium_memzero($key);
            // 🧹 A half-unpacked folder must not be left where something might
            //    mistake it for a snapshot. `_manifest.json` is written last by
            //    DbBackup, so a partial folder would not pass its own checks —
            //    but leaving it would still be litter nobody asked for.
            self::removeFolder($intoDir);
            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage());
        }

        fclose($in);
        sodium_memzero($key);

        return ['entries' => $state, 'snapshot' => (string) $envelope['snapshot']];
    }

    /**
     * 📜 Walk the encrypted entry stream, writing each file out.
     *
     * @param resource $in
     * @param mixed    $stream libsodium's pull state.
     *
     * @return int How many files were written.
     */
    private static function readEntries($in, mixed $stream, string $intoDir): int
    {
        $buffer   = '';
        $finished = false;
        $count    = 0;
        $expected = null;

        // What we are doing right now: reading an entry's line, or its bytes.
        $pendingName  = null;
        $pendingBytes = 0;
        $handle       = null;

        try {
            while (true) {
                if ($finished === false) {
                    $chunk = self::pullChunk($in, $stream, $finished);
                    if ($chunk === null) {
                        // The stream ended without a FINAL tag.
                        throw new RuntimeException(
                            'That backup file is cut short, so it is not a complete copy and was not used.'
                        );
                    }
                    $buffer .= $chunk;
                }

                // Work through whatever is in hand.
                while (true) {
                    if ($pendingName !== null) {
                        $take = min(strlen($buffer), $pendingBytes);
                        if ($take > 0) {
                            if (fwrite($handle, substr($buffer, 0, $take)) !== $take) {
                                throw new RuntimeException('A file from the backup could not be written out.');
                            }
                            $buffer        = substr($buffer, $take);
                            $pendingBytes -= $take;
                        }
                        if ($pendingBytes > 0) {
                            break;   // need more from the stream
                        }
                        fclose($handle);
                        $handle      = null;
                        $pendingName = null;
                        $count++;
                        continue;
                    }

                    $newline = strpos($buffer, "\n");
                    if ($newline === false) {
                        // 🛑 An entry LINE is short — a name and a size. A stream of
                        //    chunks with no newline would otherwise grow this
                        //    buffer without limit; bounding it costs nothing and
                        //    closes the same family of fault as the hang above.
                        if (strlen($buffer) > self::CHUNK * 4) {
                            throw new RuntimeException(
                                'That backup\'s list of files is damaged: one entry has no end to it.'
                            );
                        }
                        break;   // need more from the stream
                    }
                    $line   = substr($buffer, 0, $newline);
                    $buffer = substr($buffer, $newline + 1);
                    $meta   = json_decode($line, true);
                    if (is_array($meta) === false) {
                        throw new RuntimeException('That backup\'s list of files is damaged.');
                    }
                    if (($meta['end'] ?? false) === true) {
                        $expected = (int) ($meta['entries'] ?? -1);
                        break 2;
                    }


                    // `is_string` first: `(string)` on an array yields the word
                    // "Array", which `isSafeEntryName()` would happily accept,
                    // so a crafted entry wrote a file called `Array` and raised
                    // a PHP warning. Inside the folder, so harmless, and still
                    // wrong.
                    $nameRaw = $meta['name'] ?? null;
                    $name    = is_string($nameRaw) === true ? $nameRaw : '';
                    if (self::isSafeEntryName($name) === false) {
                        // A name with a slash or a dot-dot in it could write
                        // outside the folder. Refused rather than cleaned up:
                        // this portal does not write such names, so one here
                        // means the file is not ours.
                        throw new RuntimeException('That backup names a file this portal would never write.');
                    }
                    $pendingBytes = (int) ($meta['bytes'] ?? -1);
                    if ($pendingBytes < 0) {
                        throw new RuntimeException('That backup gives a file an impossible size.');
                    }
                    $pendingName = $name;
                    $handle      = fopen($intoDir . DIRECTORY_SEPARATOR . $name, 'xb');
                    if ($handle === false) {
                        throw new RuntimeException('A file from the backup could not be created.');
                    }
                }

                // 🛑 ONCE THE STREAM HAS ENDED, NOTHING MORE WILL COME. Every way
                //    of still wanting data has to end the loop here, or the
                //    outer `while (true)` spins for ever on a file that asks
                //    for more than it carries.
                //
                //    THIS WAS A REAL HANG, reproduced by the independent check:
                //    an entry line saying `{"bytes":1000}` followed by five
                //    bytes, in one FINAL chunk. The old condition required
                //    `$pendingName === null`, so the one case that mattered — an
                //    entry still waiting for bytes — fell straight through it,
                //    pulled nothing (the stream had finished) and went round
                //    again at 100% CPU. On a host with a time limit that burns
                //    a worker until it is killed, and the half-unpacked folder
                //    was left behind as well, because `unpack()`'s clean-up
                //    lives in a `catch` that never ran.
                //
                //    The message about being cut short in the middle of a file
                //    was dead code for the same reason; it is reachable now.
                if ($finished === true) {
                    if ($pendingName !== null) {
                        throw new RuntimeException(
                            'That backup file is cut short in the middle of ' . $pendingName
                            . ', so it is not a complete copy and was not used.'
                        );
                    }
                    if (strpos($buffer, "\n") === false) {
                        throw new RuntimeException(
                            'That backup file ends without its closing line, so it is not a complete copy.'
                        );
                    }
                }
            }
        } finally {
            if ($handle !== null) {
                fclose($handle);
            }
        }

        if ($pendingName !== null) {
            throw new RuntimeException('That backup file is cut short in the middle of a file.');
        }

        // 🛡️ THE CLOSING LINE IS NOT ENOUGH ON ITS OWN. It arrives inside the
        //    encrypted stream, so it is authentic — but libsodium's FINAL tag is
        //    a separate statement, made by the encryption itself, that there is
        //    nothing after this chunk. Requiring both means a file cannot be
        //    presented as complete unless the writer said so in both ways.
        //
        //    This was NOT checked at first, and a planted packer that never
        //    emitted FINAL passed all 36 checks: the closing line was found and
        //    the stream was never asked whether it had ended. The class header
        //    claimed FINAL was "what proves the file is whole", which was then
        //    untrue — the overstated-guarantee shape this project keeps finding.
        if ($finished === false) {
            throw new RuntimeException(
                'That backup file does not say it is finished, so it may have been cut short or '
                . 'changed, and was not used.'
            );
        }
        if ($expected !== null && $expected !== $count) {
            throw new RuntimeException(
                'That backup says it holds ' . $expected . ' files and holds ' . $count . '.'
            );
        }

        return $count;
    }

    /**
     * 🧾 Every file in a snapshot folder, by the name it will carry.
     *
     * Sorted so that two packs of the same snapshot give the same file, and
     * `_manifest.json` last, as `DbBackup` writes it — an unpacked folder is
     * then unfinished until the moment it is finished, exactly like one the
     * server made.
     *
     * @return array<string,string> name => full path
     */
    private static function entriesIn(string $dir): array
    {
        $found = [];
        $items = scandir($dir);
        if ($items === false) {
            throw new RuntimeException('That snapshot\'s folder could not be read.');
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            // A link is not followed: a snapshot folder holding one is not
            // something to copy the far end of into somebody's download.
            if (is_link($path) === true || is_file($path) === false) {
                continue;
            }
            if (self::isSafeEntryName($item) === false) {
                continue;
            }
            $found[$item] = $path;
        }
        ksort($found);

        if (array_key_exists('_manifest.json', $found) === true) {
            $manifest = $found['_manifest.json'];
            unset($found['_manifest.json']);
            $found['_manifest.json'] = $manifest;
        }

        return $found;
    }

    /** 🏷️ A file name a snapshot may hold: no folders, no dot-dot, nothing odd. */
    private static function isSafeEntryName(string $name): bool
    {
        return $name !== ''
            && strlen($name) <= 200
            && preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_.\-]*\z/', $name) === 1
            && str_contains($name, '..') === false;
    }

    /**
     * @param resource $out
     * @param mixed    $stream
     */
    private static function pushChunk($out, mixed $stream, string $plain, bool $final): void
    {
        $cipher = sodium_crypto_secretstream_xchacha20poly1305_push(
            $stream,
            $plain,
            '',
            $final === true
                ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
        );
        self::write($out, pack('N', strlen($cipher)));
        self::write($out, $cipher);
    }

    /**
     * @param resource $in
     * @param mixed    $stream
     *
     * @return string|null The plaintext, or null when the stream ran out with
     *                     no FINAL tag.
     */
    private static function pullChunk($in, mixed $stream, bool &$final): ?string
    {
        $lengthBytes = fread($in, 4);
        if ($lengthBytes === false || $lengthBytes === '') {
            return null;
        }
        if (strlen($lengthBytes) !== 4) {
            throw new RuntimeException('That backup file is cut short.');
        }
        $unpacked = unpack('N', $lengthBytes);
        $length   = $unpacked === false ? 0 : (int) $unpacked[1];
        // 🛡️ A LENGTH IS BOUNDED, and this is the one line standing between a
        //    hostile file and the server's memory. The four bytes giving it are
        //    read BEFORE anything is authenticated — they have to be, because
        //    they say how much to read — so a file can claim any size at all. A
        //    claim of 4 GB would be believed by `readExactly()` and the request
        //    would die. One chunk plus libsodium's own overhead is the most a
        //    well-formed file ever carries.
        //
        //    Nothing covered this until it was planted: removing the upper bound
        //    left all 36 checks passing. Check C08 covers it now.
        if ($length <= 0 || $length > self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
            throw new RuntimeException('That backup file is damaged: a piece of it has an impossible size.');
        }
        $cipher = self::readExactly($in, $length);

        $result = sodium_crypto_secretstream_xchacha20poly1305_pull($stream, $cipher);
        if ($result === false) {
            // A wrong key and a damaged file are the same answer here, and
            // guessing between them would mean trusting something that did not
            // authenticate. Both are named.
            throw new RuntimeException('That backup could not be opened: the wrong key, or a damaged file.');
        }
        [$plain, $tag] = $result;
        if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
            $final = true;
        }

        return $plain;
    }

    /**
     * @param resource $handle
     */
    private static function readExactly($handle, int $bytes): string
    {
        $got = '';
        while (strlen($got) < $bytes) {
            $piece = fread($handle, $bytes - strlen($got));
            if ($piece === false || $piece === '') {
                throw new RuntimeException('That backup file is cut short.');
            }
            $got .= $piece;
        }

        return $got;
    }

    /**
     * @param resource $handle
     */
    private static function write($handle, string $bytes): void
    {
        if ($bytes === '') {
            return;
        }
        $written = fwrite($handle, $bytes);
        // A short write means the disk is full or the quota is reached. Writing
        // on regardless would leave a file that opens and then fails half way.
        if ($written !== strlen($bytes)) {
            throw new RuntimeException('The backup file could not be written (the disk may be full).');
        }
    }

    /** 🧹 Remove a folder this class made, one level deep, never following a link. */
    private static function removeFolder(string $dir): void
    {
        if (is_dir($dir) === false || is_link($dir) === true) {
            return;
        }
        $items = scandir($dir);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $dir . DIRECTORY_SEPARATOR . $item;
                if (is_link($path) === true || is_file($path) === true) {
                    @unlink($path);
                }
            }
        }
        @rmdir($dir);
    }
}
