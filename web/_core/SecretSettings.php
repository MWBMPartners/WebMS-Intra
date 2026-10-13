<?php
// Path: _core/SecretSettings.php
/**
 * -----------------------------------------------------------------------------
 * Secret settings: what a stored one means 🔐
 * -----------------------------------------------------------------------------
 * A row of tblSettings is a SECRET when its `isSensitive` column is 1 (the
 * "mark"). A secret is meant to be stored encrypted and never shown to
 * anybody. This class is the ONE place that answers the three questions that
 * follow from that, so the places that ask cannot disagree:
 *
 *   - the settings load in bootstrap.php asks "what value do I hand to the
 *     code that uses this setting?"            → forReader()
 *   - the Settings page asks "what may I say about this row?"  → state()
 *   - the Settings save asks "what do I store?"                → seal()
 *   - the bot check and the health page ask "which stored secrets can the
 *     portal not read at all?"                  → unreadable(), cannotBeRead()
 *   - the Upgrade page asks "encrypt whatever is still stored as readable
 *     text"                                     → repairStoredRows()
 *   - the health page asks "is the key file itself in order?"  → keyFileNote()
 *
 * WHAT WAS WRONG (#497 and #597, both run on 12 October 2026)
 *   1. The load compared the mark with the TEXT '1'. A prepared statement
 *      hands it back as the NUMBER 1, so the comparison was never true and no
 *      secret was ever decrypted. Every reader was handed the scramble.
 *   2. The Settings page's edit box sent no "this is a secret" field, and the
 *      save read its absence as "not a secret". So editing a secret stored it
 *      as plain text AND cleared its mark, and the page then showed it to
 *      every administrator. Mail through Microsoft 365 and Google only worked
 *      because of that second fault: the plain text was the only form the
 *      first fault let through.
 *
 * WHY NOT JUST CORRECT THE COMPARISON
 *   A marked row does not always hold something this installation can
 *   decrypt. A fresh install seeds `auth.ms365.tenantOnly` as the plain text
 *   `true` with the mark on, and decrypt_setting() THROWS on that (the text
 *   is too short to hold what libsodium needs). The load runs before any
 *   error handler exists, so every page would have stopped. Rows that #597
 *   left as plain text, and rows encrypted with a key file that has since
 *   been replaced, are in the same position. So the rule here is:
 *
 *       a marked row that this installation's key does not open
 *       is used exactly as it is stored.
 *
 *   That is what every reader is handed today, so nothing that works today
 *   stops, and nothing here can throw.
 *
 * WHY THIS DOES ITS OWN DECRYPTING INSTEAD OF CALLING decrypt_setting()
 *   decrypt_setting() answers '' both for "this cannot be decrypted" and for
 *   "this is a real encrypted value that happens to be empty", and it throws
 *   on short text. The load has to tell those apart: the first must fall back
 *   to the stored text, the second really is empty. Guessing from the length
 *   was considered and rejected: a plain secret that is 40 bytes of base64
 *   looks exactly like an encrypted empty value. Only the encryption's own
 *   check can tell, so open() runs it and answers null for "not ours".
 *   The key is worked out exactly as encrypt_setting() does it; the self-test
 *   (tools/settings-secrets-selftest.php) proves the two agree both ways.
 *
 * VALUES ENCRYPTED MORE THAN ONCE
 *   Admin, Captcha used to fill its boxes with the scramble and encrypt it
 *   again on every save. open() therefore keeps opening while the result is
 *   still this installation's own encryption. That is safe: text passes the
 *   encryption's check only if this key made it. Something else that merely
 *   looks like base64 fails the check and is left alone.
 *
 * READABLE TEXT, OR TEXT NOBODY CAN READ? (added with the owner's two decisions
 * of 13 September 2026 on #497)
 *   A marked row that does not open is one of two things, and two callers
 *   have to know which. Text that is NOT base64 of at least 40 bytes cannot
 *   have come out of this portal's encryption, whatever key was used, so it
 *   is certainly READABLE TEXT: a secret the old Settings page stored
 *   unprotected. Text that does have that form is treated as CANNOT BE READ:
 *   encrypted with a key file this installation no longer has. See
 *   looksEncrypted() for where that test can be wrong, and which way.
 *   - The bot check goes by it: with keys that cannot be read it refuses
 *     public forms and lets sign-in and password reset through (Captcha.php).
 *   - The repair at Upgrade goes by it: readable text is encrypted in place,
 *     and text that cannot be read is left alone and named.
 *
 * WHAT IT CANNOT DO
 *   - It cannot be SURE that text which looks encrypted is not a readable
 *     secret that happens to be long base64 (a 64-character token made of
 *     the letters a to f and digits is one). Such a row is used as stored,
 *     which is right either way, but it is reported as "cannot be read" and
 *     is not encrypted by the repair unless the Upgrade page saw for itself
 *     that it was readable (see repairStoredRows()).
 *   - Except in repairStoredRows(), it never writes to the database. A row
 *     that the repair leaves is put right when somebody saves it again.
 *   - It does not know which settings OUGHT to be secrets. The mark is the
 *     only record of that. Migration 214 puts back the marks #597 cleared.
 *   - The key is read once per request. A key file swapped part way through
 *     a request is not noticed until the next one.
 *   - It does not judge a key file by its length. A file of any length is a
 *     working key, and only an EMPTY one (or something that is not a file)
 *     is "no key": see keyFileContents() for why the line is there and no
 *     higher.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.2.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/597
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/497
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class SecretSettings
{
    /** Not a secret. Its value may be shown. */
    public const STATE_ORDINARY = 'ordinary';

    /** A secret with nothing stored. */
    public const STATE_NOT_SET = 'not-set';

    /** A secret stored encrypted with this installation's key. */
    public const STATE_PROTECTED = 'protected';

    /** A secret that is stored, but that this installation's key does not open. */
    public const STATE_UNPROTECTED = 'unprotected';

    /**
     * The key, once worked out: null before the first look, false when
     * there is no key (see keyFileContents()).
     *
     * @var string|false|null
     */
    private static string|false|null $key = null;

    /**
     * The smallest thing this portal's encryption can produce, in bytes: 24
     * in front (the "nonce") and a 16-byte check, around nothing at all.
     */
    private const SMALLEST_ENCRYPTED = 40;

    /**
     * Names of the secrets loaded for this request that CANNOT BE READ: they
     * look encrypted and this installation's key does not open them. Filled
     * by forReader() as the settings load runs.
     *
     * @var array<string, true>
     */
    private static array $unreadable = [];

    /**
     * Names of the secrets loaded for this request that are stored as
     * READABLE TEXT although they are marked. Filled the same way.
     *
     * @var array<string, true>
     */
    private static array $unprotected = [];

    /**
     * 🔢 Is this row marked as a secret?
     *
     * The mark arrives as the number 1 from a prepared statement and as the
     * text '1' from an ordinary query. Both mean yes and nothing else does. A
     * cast was rejected: it would also accept true, '01' and 1.5.
     *
     * @param mixed $flag The row's `isSensitive` value, as the database gave it.
     */
    public static function isMarked(mixed $flag): bool
    {
        return $flag === 1 || $flag === '1';
    }

    /**
     * 🔓 The real value inside text this installation encrypted, or null when
     * the text is not that. Never throws.
     *
     * An empty text answers null: there is nothing to open.
     *
     * @param string $stored The text as it is stored.
     *
     * @return string|null The real value ('' is a real answer), or null.
     */
    public static function open(string $stored): ?string
    {
        $value = self::openOnce($stored);
        if ($value === null) {
            return null;
        }

        // 🧅 Keep going while what came out is still our own encryption (see
        //    "VALUES ENCRYPTED MORE THAN ONCE" above). Each layer is shorter
        //    than the one around it, so this always ends.
        $inner = self::openOnce($value);
        while ($inner !== null) {
            $value = $inner;
            $inner = self::openOnce($value);
        }

        return $value;
    }

    /**
     * 📖 What the settings load hands to the code that uses a setting.
     *
     * An ordinary row, and an empty secret, come back untouched (null stays
     * null). A secret that opens comes back as its real value. A secret that
     * does not open comes back exactly as stored. Never throws.
     *
     * When the load passes the setting's NAME, a secret that does not open is
     * also written down under it, as "cannot be read" or as "readable text"
     * (see unreadable() and unprotected()). A later row of the same name, an
     * organisation's own for example, replaces what was written for the
     * earlier one, as it replaces the value.
     *
     * @param mixed  $flag   The row's `isSensitive` value.
     * @param mixed  $stored The row's `settingValue`.
     * @param string $name   The row's `settingKey`; '' writes nothing down.
     */
    public static function forReader(mixed $flag, mixed $stored, string $name = ''): mixed
    {
        if ($name !== '') {
            unset(self::$unreadable[$name], self::$unprotected[$name]);
        }
        if (self::isMarked($flag) === false || is_string($stored) === false || $stored === '') {
            return $stored;
        }
        $opened = self::open($stored);
        if ($opened !== null) {
            return $opened;
        }
        if ($name !== '' && self::looksEncrypted($stored) === true) {
            self::$unreadable[$name] = true;
        } elseif ($name !== '') {
            self::$unprotected[$name] = true;
        }

        return $stored;
    }

    /**
     * 🧐 Does this text have the form of something this portal encrypted?
     *
     * That form is base64, written the one standard way, of at least 40
     * bytes. It says nothing about WHICH key: use open() for that.
     *
     * WHICH WAY IT CAN BE WRONG. "No" is certain: nothing shorter, and nothing
     * holding a character base64 does not use, ever came out of the
     * encryption. "Yes" is not certain: a readable secret can be long base64
     * too. Every caller is written so that a wrong "yes" loses nothing: the
     * value is still used as stored, and the repair leaves the row alone.
     *
     * @param string $stored The text as it is stored.
     */
    public static function looksEncrypted(string $stored): bool
    {
        $bytes = base64_decode($stored, true);

        return $bytes !== false && strlen($bytes) >= self::SMALLEST_ENCRYPTED && base64_encode($bytes) === $stored;
    }

    /**
     * 🚫 Is this stored secret one the portal CANNOT READ: it looks encrypted,
     * and this installation's key does not open it? (An empty value, readable
     * text, and anything the key opens all answer no.)
     *
     * @param string $stored The text as it is stored, from a MARKED row.
     */
    public static function cannotBeRead(string $stored): bool
    {
        return $stored !== '' && self::open($stored) === null && self::looksEncrypted($stored) === true;
    }

    /**
     * The names of the secrets loaded for this request that cannot be read,
     * in alphabetical order. Empty before the settings load has run.
     *
     * @return list<string>
     */
    public static function unreadable(): array
    {
        $names = array_keys(self::$unreadable);
        sort($names);

        return $names;
    }

    /** Was the secret of this name, as loaded for this request, one that cannot be read? */
    public static function isUnreadable(string $name): bool
    {
        return isset(self::$unreadable[$name]) === true;
    }

    /**
     * The names of the secrets loaded for this request that are marked but
     * stored as readable text, in alphabetical order.
     *
     * @return list<string>
     */
    public static function unprotected(): array
    {
        $names = array_keys(self::$unprotected);
        sort($names);

        return $names;
    }

    /**
     * 🏷️ Which of the four states a row is in, for a page that must describe
     * it without showing it. Never throws.
     *
     * A secret encrypted as an EMPTY value counts as "not set". The Settings
     * page's "Add" form used to store one when the box was left empty, and
     * calling that "set" would be untrue.
     *
     * @param mixed $flag   The row's `isSensitive` value.
     * @param mixed $stored The row's `settingValue`.
     *
     * @return string One of the STATE_ constants.
     */
    public static function state(mixed $flag, mixed $stored): string
    {
        if (self::isMarked($flag) === false) {
            return self::STATE_ORDINARY;
        }
        if (is_string($stored) === false || $stored === '') {
            return self::STATE_NOT_SET;
        }
        $opened = self::open($stored);
        if ($opened === null) {
            return self::STATE_UNPROTECTED;
        }

        return $opened === '' ? self::STATE_NOT_SET : self::STATE_PROTECTED;
    }

    /**
     * 🔒 The text to store for a secret somebody has just typed, or null when
     * it cannot be encrypted. Never throws.
     *
     * Null means "store nothing": the caller must refuse the save and say so.
     * Storing the plain text instead is exactly the fault this class exists
     * to end.
     *
     * An empty value comes back as '' and is not encrypted, so that "not set"
     * stays true for it.
     *
     * WHY IT CATCHES EVERYTHING: encrypt_setting() throws when there is no
     * key (the key file is missing, empty, or not a file), and PHP writes
     * the first characters of each argument into the record of an uncaught
     * error. The typed secret is that argument. So nothing is allowed to
     * escape from here, and the parameter is marked so that PHP leaves it
     * out of any record made further up.
     *
     * The result is opened again before it is returned. A value that could
     * not be read back would be a secret lost without anybody being told.
     *
     * @param string $plain The value as typed.
     */
    public static function seal(#[\SensitiveParameter] string $plain): ?string
    {
        if ($plain === '') {
            return '';
        }
        try {
            if (function_exists('encrypt_setting') === false) {
                return null;
            }
            $sealed = \encrypt_setting($plain);
            // One layer only: open() would go on through a typed value that
            // was itself our own encryption, and then never equal it.
            if (self::openOnce($sealed) !== $plain) {
                return null;
            }

            return $sealed;
        } catch (\Throwable) {
            return null;
        }
    }

    /** The code the error log carries for "a stored secret cannot be read". */
    public const ERROR_CODE = 'SECRET_SETTING_UNREADABLE';

    /**
     * 📣 Tell the people who run the portal that a stored secret cannot be
     * read. Called once per request by bootstrap.php, after the settings load.
     *
     * Costs nothing when every secret reads (the usual case): it returns at
     * the first line. When one does not:
     *   1. one SHORT line to the web server's error log, on every request:
     *      how many, and where to read more. It is cheap, and it is the
     *      only record that survives a broken database;
     *   2. one row in the portal's own error log (Admin, Error log), marked
     *      Critical so the alert e-mail goes out where one is set up, but AT
     *      MOST ONE EVERY SIX HOURS. Without that limit a portal left broken
     *      over a weekend would fill the table.
     * Names only (in the row; the line carries a count), never a value.
     *
     * NOT WHILE THE PORTAL IS CLOSED FOR AN UPGRADE. The health monitor's
     * address answers then, and it must write nothing to a database that may
     * be half way through a migration. The line in the web server's log is
     * still written.
     *
     * WHAT IT CANNOT DO: tell anybody who never looks at the error log, has
     * no alert address set and no monitor on the health address. Admin,
     * Captcha, the Settings page and the health page each say it too.
     *
     * @param \mysqli $db The portal's database connection.
     */
    public static function reportUnreadable(\mysqli $db): void
    {
        $names = self::unreadable();
        if ($names === []) {
            return;
        }
        $list = implode(', ', $names);
        // ONE SHORT LINE, with a count and where to read more. WHAT WAS HERE:
        // every name, on every request. With the key file missing that is
        // every stored secret, about 1.6 KB a request, and a busy portal
        // left like that over a weekend fills the host's log with one
        // sentence. The names are on the health page and in the Critical
        // record below, which is written at most once every six hours.
        error_log('[WebMS-Intra] ' . count($names) . (count($names) === 1 ? ' stored secret' : ' stored secrets')
            . ' cannot be read with this installation\'s key file (enc.key). Admin, Maintenance, Health names them and says what to do.');

        try {
            if (Maintenance::isActive() === true) {
                return;
            }
            $code = self::ERROR_CODE;
            $seen = $db->prepare('SELECT 1 FROM tblErrors WHERE errorCode = ? AND createdAt > (NOW() - INTERVAL 6 HOUR) LIMIT 1');
            if ($seen === false) {
                return;
            }
            $seen->bind_param('s', $code);
            $seen->execute();
            $already = $seen->get_result()->fetch_row() !== null;
            $seen->close();
            if ($already === true) {
                return;
            }

            $botCheck = Captcha::brokenNotice();
            Logger::errorPlatformForSite(
                null,
                'Settings',
                'Critical',
                $code,
                count($names) . (count($names) === 1 ? ' stored secret' : ' stored secrets') . ' cannot be read by this installation'
                    . ($botCheck === '' ? '' : ', and sign-in is running without its bot check'),
                'These settings are marked as secrets and hold text that looks encrypted, but this installation\'s key file '
                    . '(enc.key, in the _auth_keys folder) does not open them: ' . $list . '. Whatever uses them is handed that text '
                    . 'exactly as it is stored, which will not work if it was encrypted with another key file. The usual causes: '
                    . 'the key file was replaced or is missing, or the database was restored from a backup of a different '
                    . 'installation. To put it right: put the original key file back, or enter each value again (the Settings '
                    . 'page names them).'
                    . ($botCheck === '' ? '' : ' ' . $botCheck)
            );
        } catch (\Throwable) {
            // Saying that something is wrong must never be what breaks the page.
        }
    }

    /**
     * 📸 Before an upgrade's migrations run: which settings are stored as
     * READABLE TEXT because they are not marked as secrets at all?
     *
     * Migration 214 puts the mark back on the secrets the old Settings page
     * cleared it from. Once it has, such a row looks exactly like any other
     * marked row that does not open, and a long token made of hex digits
     * cannot then be told from text encrypted with another key. This list is
     * taken BEFORE the migrations so that repairStoredRows(), run after
     * them, knows which newly marked rows it saw as readable text with its
     * own eyes. Fingerprints, not values.
     *
     * @return array<int, string> settingID => SHA-256 of the stored value.
     *         Empty when the table cannot be read, which only means the
     *         repair goes by the form of each value alone.
     */
    public static function readableBefore(\mysqli $db): array
    {
        $seen = [];
        try {
            $rows = $db->query("SELECT settingID, settingValue FROM tblSettings WHERE isSensitive = 0 AND settingValue IS NOT NULL AND settingValue <> ''");
            if ($rows instanceof \mysqli_result) {
                while (is_array($row = $rows->fetch_assoc()) === true) {
                    $seen[(int) $row['settingID']] = hash('sha256', (string) $row['settingValue']);
                }
                $rows->free();
            }
        } catch (\Throwable) {
            return [];
        }

        return $seen;
    }

    /**
     * 🩹 Encrypt, in place, every secret that is still stored as readable
     * text. Run by the Upgrade page straight after the migrations (the owner's
     * decision of 13 September 2026 on #497). Never throws.
     *
     * THIS REWRITES STORED SECRETS, SO IT IS WRITTEN NOT TO BE ABLE TO LOSE ONE:
     *   - A value this installation's key already opens is NEVER touched, so
     *     nothing is ever encrypted twice. That includes a value some older
     *     page encrypted more than once: it reads correctly as it is.
     *   - Only a value that is CERTAINLY readable text is encrypted: one that
     *     does not have the form of encrypted text (see looksEncrypted()), or
     *     one `$readableBefore` shows was unmarked, unchanged, before this
     *     upgrade's migrations ran.
     *   - A value that looks encrypted and does not open is left exactly as
     *     it is and named. Encrypting it would only wrap a scramble, and make
     *     a secret that does not work look as if it were in order.
     *   - What is about to be stored is opened again first (seal() does
     *     that). If it does not come back as the same value, nothing is written.
     *   - One statement per row, and the OLD value is in its WHERE, compared
     *     byte for byte. If somebody saved the setting while this was
     *     running, the statement changes nothing and the row is named.
     *   - Each row is on its own. One that fails is named and the rest go on.
     *   - With no key (the key file missing, EMPTY, or not a file: see
     *     keyFileContents()), nothing at all is written.
     * It is safe to run again: a second run finds everything it encrypted
     * already opening, and touches none of it.
     *
     * WHAT IT CANNOT DO: encrypt a readable secret that looks encrypted and
     * that it did not see unmarked before the migrations (a second Upgrade
     * after a first one that had no key file, for example). The Settings page
     * names such a row; entering the value again there encrypts it.
     *
     * @param \mysqli            $db             The portal's database connection.
     * @param array<int, string> $readableBefore From readableBefore(), taken before the migrations.
     *
     * @return array{keyFileMissing: bool, encrypted: list<string>, alreadyProtected: int, cannotBeRead: list<string>,
     *               leftReadable: list<string>, changedMeanwhile: list<string>, unchecked: int, couldNotRun: bool}
     *         Lists hold setting NAMES (with the organisation for a row that
     *         belongs to one), never values.
     */
    public static function repairStoredRows(\mysqli $db, array $readableBefore = []): array
    {
        $report = [
            'keyFileMissing' => false, 'encrypted' => [], 'alreadyProtected' => 0, 'cannotBeRead' => [],
            'leftReadable' => [], 'changedMeanwhile' => [], 'unchecked' => 0, 'couldNotRun' => false,
        ];
        try {
            $rows   = [];
            $marked = $db->query("SELECT settingID, settingKey, siteID, settingValue FROM tblSettings WHERE isSensitive = 1 AND settingValue IS NOT NULL AND settingValue <> '' ORDER BY settingKey, settingID");
            while (is_array($row = $marked->fetch_assoc()) === true) {
                $rows[] = $row;
            }
            $marked->free();
        } catch (\Throwable) {
            $report['couldNotRun'] = true;

            return $report;
        }
        $report['keyFileMissing'] = self::key() === null;

        foreach ($rows as $row) {
            $name = (string) $row['settingKey'] . ($row['siteID'] === null ? '' : ' (organisation ' . (int) $row['siteID'] . ')');
            try {
                $id    = (int) $row['settingID'];
                $value = (string) $row['settingValue'];
                // Seen unmarked, and unchanged since, before the migrations ran?
                $seenReadable = isset($readableBefore[$id]) === true && hash_equals($readableBefore[$id], hash('sha256', $value)) === true;
                $isReadable   = $seenReadable === true || self::looksEncrypted($value) === false;

                if ($report['keyFileMissing'] === true) {
                    // Nothing can be opened or encrypted. Say which rows are
                    // known to be readable text; the rest cannot be judged.
                    if ($isReadable === true) {
                        $report['leftReadable'][] = $name;
                    } else {
                        $report['unchecked']++;
                    }
                    continue;
                }
                if (self::open($value) !== null) {
                    $report['alreadyProtected']++;
                    continue;
                }
                if ($isReadable === false) {
                    $report['cannotBeRead'][] = $name;
                    continue;
                }
                $sealed = self::seal($value);
                if ($sealed === null || $sealed === '') {
                    $report['leftReadable'][] = $name;
                    continue;
                }
                // CAST … AS BINARY on both sides: the column's own comparison
                // ignores capitals, accents and spaces at the end, so without
                // it a value changed only in one of those ways would count as
                // "the same" and be overwritten with the old one.
                $write = $db->prepare('UPDATE tblSettings SET settingValue = ? WHERE settingID = ? AND isSensitive = 1 AND CAST(settingValue AS BINARY) = CAST(? AS BINARY)');
                $write->bind_param('sis', $sealed, $id, $value);
                $write->execute();
                $written = $write->affected_rows;
                $write->close();
                if ($written === 1) {
                    $report['encrypted'][] = $name;
                } else {
                    $report['changedMeanwhile'][] = $name;
                }
            } catch (\Throwable) {
                // The reason is deliberately not kept: a database's message
                // about a refused value can quote part of that value.
                $report['leftReadable'][] = $name;
            }
        }

        return $report;
    }

    /**
     * Open ONE layer of this installation's encryption; null when the text
     * is not that. Never throws.
     *
     * The cheap tests come first, so an ordinary plain value is turned away
     * before the key file is touched.
     */
    private static function openOnce(string $text): ?string
    {
        try {
            if ($text === '') {
                return null;
            }
            $bytes = base64_decode($text, true);
            // The encryption puts 24 bytes in front (the "nonce") and adds a
            // 16-byte check. Anything shorter cannot be ours, and handing it
            // to libsodium anyway is what made decrypt_setting() throw.
            if ($bytes === false
                || strlen($bytes) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
                return null;
            }
            $key = self::key();
            if ($key === null) {
                return null;
            }
            $plain = sodium_crypto_secretbox_open(
                substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                $key
            );

            return $plain === false ? null : $plain;
        } catch (\Throwable) {
            // A PHP without libsodium, or anything else unexpected: "not
            // ours", so the caller falls back to the stored text.
            return null;
        }
    }

    /**
     * 🔑 This installation's key, worked out the way encrypt_setting() and
     * decrypt_setting() in bootstrap.php work it out; null when there is NO
     * KEY (see keyFileContents()). If one changes, change all three.
     */
    private static function key(): ?string
    {
        if (self::$key === null) {
            self::$key = false;
            $contents  = self::keyFileContents();
            if ($contents !== null) {
                self::$key = sodium_hex2bin(hash('sha256', $contents));
            }
        }

        return self::$key === false ? null : self::$key;
    }

    /** How many bytes the installer writes into the key file: 64 letters and digits (web/_install/index.php). */
    public const KEY_FILE_BYTES = 64;

    /** Where the key file is. */
    private static function keyFilePath(): string
    {
        return PORTAL_ROOT . DIRECTORY_SEPARATOR . '_auth_keys' . DIRECTORY_SEPARATOR . 'enc.key';
    }

    /**
     * 🗝️ What is in the key file, or null when there is NO KEY: the file is
     * missing, cannot be read, is not a file at all (a folder of that name,
     * say), or is EMPTY.
     *
     * WHAT WAS WRONG (found by the check of 13 October 2026, by running it):
     * an empty file counted as a key. The key is the SHA-256 of whatever the
     * file holds, and the SHA-256 of nothing is a number printed in every
     * textbook. So with a key file cut to nothing by a failed upload, Upgrade
     * "encrypted" the readable secrets with a key anybody can work out, said
     * it had protected them, and once the real file was put back those
     * secrets were a scramble. A folder in the file's place did the same:
     * PHP reads a folder as nothing.
     *
     * WHERE THE LINE IS, AND WHY NOT HIGHER. The check proposed "under 32
     * bytes is no key". REJECTED: because the key is the SHA-256 of the WHOLE
     * file, a file of ANY length makes a working key today. An installation
     * whose file is one byte long, or 63, has every secret encrypted under
     * exactly that, and turning the file away would lose all of them at
     * once, for good. So the only contents refused are NONE AT ALL. A file
     * that is not empty is used exactly as before, whatever its length; a
     * short one is named on the health page (keyFileNote()) and nothing more.
     *
     * WHAT THIS COSTS: on an installation whose key file is ALREADY empty,
     * anything saved since it became empty stops being read, and has to be
     * entered again once a real key file is there. It was protected by
     * nothing, and it would have turned into a scramble anyway the moment
     * the real file came back.
     *
     * The same test is written out in bootstrap.php (portal_key_file_contents()),
     * which the two older helper functions use. tools/settings-secrets-selftest.php
     * holds the two to the same answer for a file of every kind.
     */
    private static function keyFileContents(): ?string
    {
        $path = self::keyFilePath();
        // is_file() first: is_readable() says yes to a folder, and reading one
        // gives nothing at all plus a PHP notice on every request.
        if (is_file($path) === false || is_readable($path) === false) {
            return null;
        }
        $contents = file_get_contents($path);

        return ($contents === false || $contents === '') ? null : $contents;
    }

    /**
     * 🩺 One plain paragraph about the key file when something about it needs
     * an administrator's eye; '' when it is as the installer wrote it (or
     * longer). For the health page. Never the file's contents.
     *
     * It STOPS NOTHING. In particular a key file that is shorter than the
     * installer writes still encrypts and decrypts exactly as before (see
     * keyFileContents() for why it must). It is named because a file that
     * short was either cut short on the way to the server, in which case
     * the secrets stored before that cannot be read and the health page is
     * already saying so, or was written by hand.
     *
     * WHAT IT CANNOT DO: tell a key file that is the right length but the
     * WRONG ONE. Only a stored secret that fails to open shows that, and
     * unreadable() is what reports it.
     */
    public static function keyFileNote(): string
    {
        $path  = self::keyFilePath();
        $where = 'The portal\'s key file (enc.key, in the _auth_keys folder) ';
        clearstatcache(true, $path);
        if (file_exists($path) === false) {
            return $where . 'is missing. No stored secret can be read and none can be saved until it is put back from your backup.';
        }
        if (is_file($path) === false) {
            return $where . 'is not a file: something else of that name is in its place. No stored secret can be read and none can be '
                . 'saved until the file is put back from your backup.';
        }
        if (is_readable($path) === false) {
            return $where . 'is there, but the web server is not allowed to read it. No stored secret can be read and none can be saved '
                . 'until its permissions are put right.';
        }
        $bytes = (int) filesize($path);
        if ($bytes === 0) {
            return $where . 'is empty, so it is not used as a key. No stored secret can be read and none can be saved until the real '
                . 'file is put back from your backup.';
        }
        if ($bytes < self::KEY_FILE_BYTES) {
            return $where . 'is ' . $bytes . ($bytes === 1 ? ' byte' : ' bytes') . ' long; the installer writes ' . self::KEY_FILE_BYTES
                . '. It is still used exactly as before and nothing has been stopped. Compare it with your backup copy: a file this '
                . 'short may have been cut short on its way to the server. Do not replace it unless you are sure, because '
                . 'everything stored with it would stop being readable.';
        }

        return '';
    }
}
