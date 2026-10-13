<?php
// Path: _install/owner_code.php
/**
 * -----------------------------------------------------------------------------
 * The installer's ownership code 🔑 (#499, #500)
 * -----------------------------------------------------------------------------
 * WHAT WAS WRONG. The installer's only guard was "already installed". Anybody
 * who reached a freshly uploaded portal before its owner did could type the
 * details of a database of THEIR OWN, make themselves its administrator, and
 * end up owning a portal that runs on the customer's address and server. The
 * customer was then shut out by the lock file.
 *
 * THE CURE. The first time the installer is opened it writes a random code
 * into a file on the server, `_auth_keys/install-code.php`, and asks for it.
 * Only somebody who can open the hosting account's files can read it, which
 * is the one thing the real owner can do and a passer-by cannot. (Jenkins
 * unlocks a new server the same way.) The installer asks before the database
 * step and again at the finish.
 *
 * THE FILE prints nothing when a browser asks for it: it is a PHP file holding
 * a comment and one `return`. A person reading it in a file manager sees the
 * code in the comment. The installer reads it as TEXT and never runs it, so a
 * copy somebody has edited by hand can at worst be "not a code file", which
 * makes the installer write a new one.
 *
 * THE CODE is sixteen letters, shown in four groups of four. Letters only,
 * and never I, L or O, so nothing in it can be mistaken for a digit and a
 * digit can never be part of it. That leaves 23 letters: 23 to the power 16
 * is about 6 followed by 21 noughts.
 *
 * WRONG TRIES are counted IN THE FILE, not in the visitor's session: a
 * session is a cookie, and whoever is guessing simply throws the cookie away.
 * The tenth wrong try writes a new code. A right code sets the count back to
 * nought.
 *
 * WHAT IT CANNOT DO
 *   - Stop somebody who can already read or write the server's files. That
 *     person owns the installation in every way that matters.
 *   - Count wrong tries when the file cannot be written to (the file made
 *     read-only after the code was written). The code is still needed, and
 *     guessing sixteen letters is out of reach either way; only the "new code
 *     after ten" rule stops working. The code page tells an owner to do
 *     exactly this when somebody keeps sending wrong codes.
 *   - Stay private on a web server that hands out `.php` files as text AND is
 *     pointed at the folder that holds `_core`. On such a server the database
 *     login is readable too; nothing in the portal can cure that.
 *   - Tell two people apart who both hold the code.
 *   - Stop somebody who keeps sending wrong codes from keeping the code
 *     changing. Every tenth one writes a new code and ends the owner's
 *     session, so the owner cannot finish for as long as that goes on. The
 *     sender gains nothing by it: they are no nearer a code they cannot
 *     read. What the owner can do is make the code file read-only once they
 *     have read it; the code page says so. (Counting tries per visitor
 *     instead was REJECTED: a visitor is only a cookie or an address, and
 *     both are free to change.)
 *
 * Nothing here touches the session. What a session is allowed to do is
 * decided in `_install/index.php`, from installCodeFingerprint().
 *
 * Test: tools/installer-selftest.php
 *
 * @package   Portal\Install
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/499
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'install_common.php';

/** The file the code is kept in, inside `_auth_keys`. */
const INSTALL_CODE_FILE = 'install-code.php';

/** The letters a code is made of: A to Z without I, L and O. */
const INSTALL_CODE_LETTERS = 'ABCDEFGHJKMNPQRSTUVWXYZ';

/** How many letters a code has. Shown in groups of four. */
const INSTALL_CODE_LENGTH = 16;

/** The wrong try that writes a new code. */
const INSTALL_CODE_MOST_WRONG = 10;

/** What installCodeJudge() can answer. */
const INSTALL_CODE_RIGHT    = 'right';    // the code typed is the one in the file
const INSTALL_CODE_WRONG    = 'wrong';    // it is not, and the try was counted where it could be
const INSTALL_CODE_REPLACED = 'replaced'; // it is not, it was the tenth wrong try, and the file holds a NEW code now
const INSTALL_CODE_BLANK    = 'blank';    // nothing was typed; not counted
const INSTALL_CODE_NO_FILE  = 'no-file';  // there is no code file that can be read

/** A new code: sixteen letters picked by PHP's own secure random source. */
function installCodeNew(): string
{
    $code = '';
    $last = strlen(INSTALL_CODE_LETTERS) - 1;
    for ($i = 0; $i < INSTALL_CODE_LENGTH; $i++) {
        $code .= INSTALL_CODE_LETTERS[random_int(0, $last)];
    }

    return $code;
}

/** A code as a person reads it: ABCD-EFGH-JKMN-PQRS. */
function installCodeAsShown(string $code): string
{
    return implode('-', str_split($code, 4));
}

/**
 * What somebody typed, made comparable: capitals, and nothing but letters.
 * Spaces, dashes and line ends go, so the code can be typed or pasted any way
 * it is shown. Only the first 100 characters are looked at: no honest code is
 * longer, and nothing here should chew through a megabyte of text.
 */
function installCodeTidy(string $typed): string
{
    return (string) preg_replace('/[^A-Z]/', '', strtoupper(substr($typed, 0, 100)));
}

/**
 * ⚖️ Is the code typed the code in the file? THE ONE COMPARISON.
 *
 * hash_equals() and nothing else. It takes the same time whether the first
 * letter is wrong or the last, so the time an answer takes says nothing about
 * how close a guess was. And it compares the two as TEXT: PHP's `==` treats
 * two pieces of text that both look like numbers as numbers, so "0e12" and
 * "0e99" are "equal" to it (both are nought). tools/installer-selftest.php
 * hands this function pairs like that and fails if any is accepted.
 */
function installCodesMatch(string $inFile, string $typed): bool
{
    return hash_equals($inFile, $typed);
}

/**
 * The whole text of the code file. The comment is for the person who opens
 * it; the last line is what the installer reads back.
 */
function installCodeFileText(string $code, int $wrongTries): string
{
    return "<?php\n"
        . "// The portal's installation code.\n"
        . "//\n"
        . "// The installer asks for this code, to make sure that the person installing\n"
        . "// the portal is somebody who can open the files on this hosting account.\n"
        . "//\n"
        . "//     YOUR CODE IS:   " . installCodeAsShown($code) . "\n"
        . "//\n"
        . "// Type it into the installer as it is written above. Small letters are fine,\n"
        . "// and so is leaving out the dashes. There are no digits in it.\n"
        . "//\n"
        . "// After ten wrong codes the installer writes a NEW code into this file: if\n"
        . "// yours is refused, open the file again. The installer deletes this file when\n"
        . "// the installation is finished. Opened in a browser, this file shows nothing.\n"
        . "return ['code' => '" . $code . "', 'wrongTries' => " . max(0, $wrongTries) . "];\n";
}

/**
 * The code and the count held in a code file's text, or null when the text is
 * not a code file as installCodeFileText() writes one.
 *
 * @return array{code: string, wrongTries: int}|null
 */
function installCodeParse(string $text): ?array
{
    $line = '/^return \[\'code\' => \'([' . INSTALL_CODE_LETTERS . ']{' . INSTALL_CODE_LENGTH . '})\', \'wrongTries\' => (\d{1,3})\];\s*$/m';
    if (preg_match($line, $text, $found) !== 1) {
        return null;
    }

    return ['code' => $found[1], 'wrongTries' => (int) $found[2]];
}

/** The full path of the code file in the `_auth_keys` folder given. */
function installCodePath(string $authDir): string
{
    return $authDir . DIRECTORY_SEPARATOR . INSTALL_CODE_FILE;
}

/**
 * What the code file holds now, or null when there is no code file that can
 * be read. A folder of that name, or a file that is not a code file, is "no
 * code file".
 *
 * 🔒 READ UNDER A SHARED LOCK, so that this never sees a file another
 * request is half way through writing. WHAT WAS WRONG: it read with no lock.
 * installCodeJudge() empties the file and then writes it again, and a page
 * view arriving between those two moments read an EMPTY file, took that for
 * "no code file", and wrote a new code (installCodeEnsure()). With many wrong
 * codes sent at the same instant the count was lost and the code changed
 * early: 200 at once gave 13 new codes where 20 were due. Nobody got extra
 * guesses by it, but the owner's code changed sooner than the rule says.
 * Every writer of this file holds the whole-file lock while the file is
 * empty (see installCodeJudge(), and PHP's own file_put_contents() with
 * LOCK_EX in installCodeEnsure()), so a reader that waits for the shared
 * lock always sees a whole file.
 *
 * REJECTED: writing a new file beside it and renaming it into place. The
 * judging locks the file it has open, and a rename swaps the file under the
 * requests still waiting for that lock: they would then count against the
 * old file, and tries would be lost the other way.
 *
 * WHAT IT CANNOT DO: wait on a disk that has no locks (some network disks).
 * flock() then answers "no" at once and the file is read as it is, as before.
 *
 * @return array{code: string, wrongTries: int}|null
 */
function installCodeRead(string $authDir): ?array
{
    $file = installCodePath($authDir);
    clearstatcache(true, $file);
    if (is_file($file) === false || is_readable($file) === false) {
        return null;
    }
    $handle = @fopen($file, 'r');
    if ($handle === false) {
        return null;
    }
    @flock($handle, LOCK_SH);
    $text = stream_get_contents($handle);
    @flock($handle, LOCK_UN);
    fclose($handle);

    return $text === false ? null : installCodeParse($text);
}

/**
 * 🗝️ Make sure there is a code to ask for: the `_auth_keys` folder, its
 * "refuse everything" file, and the code file. Called on every visit to the
 * installer; when a usable code file is already there it changes nothing.
 *
 * The refuse file is written BEFORE the code, so the code is never in the
 * folder without it. A refuse file that cannot be written does not stop the
 * code being written: the code file prints nothing when it is fetched, and
 * the finish refuses to complete while any of the three private folders has
 * no rules file with a rule in it. (A rules file of somebody's own that
 * holds a rule is left alone and does not stop the finish; the last page
 * says when such a file does not hold the refusal.)
 *
 * @param string $root    The folder that holds `_core`.
 * @param string $authDir Its `_auth_keys` folder.
 *
 * @return string '' when a usable code file is there; otherwise what could
 *                not be done, in words, with no path in it.
 */
function installCodeEnsure(string $root, string $authDir): string
{
    $folder = installProtectFolder($root, basename($authDir));
    clearstatcache(true, $authDir);
    if (is_dir($authDir) === false) {
        return $folder['problem'];
    }
    if (installCodeRead($authDir) !== null) {
        return '';
    }

    $file = installCodePath($authDir);
    $text = installCodeFileText(installCodeNew(), 0);
    if (@file_put_contents($file, $text, LOCK_EX) !== strlen($text) || installCodeRead($authDir) === null) {
        return 'the installation code could not be written into the folder ' . basename($authDir);
    }
    // Readable by the owner and the owner's group only, like the other files
    // the installer writes here. A host that refuses the change loses nothing.
    @chmod($file, 0640);

    return '';
}

/**
 * ⚖️ Judge a code somebody typed, count a wrong try, and write a new code on
 * the tenth.
 *
 * The file is locked, whole, while it is read, judged and written, and every
 * other read of it waits for that lock (installCodeRead()). So wrong codes
 * sent at the same moment are each counted once: two hundred at once are two
 * hundred tries and twenty new codes, and no code ever gets more than ten
 * guesses. tools/installer-selftest.php sends them from several processes at
 * once, with others reading the file all the while. (Until 13 October 2026
 * this comment claimed the same and it was untrue: see installCodeRead().)
 *
 * WHAT IT CANNOT DO: count when the file cannot be opened for writing (the
 * owner made it read-only, as the code page suggests against somebody who
 * keeps sending wrong codes), or lock on a disk that has no locks (some
 * network disks). See the top of this file. It then judges without counting;
 * it never treats "could not count" as a reason to accept.
 *
 * @return string One of the INSTALL_CODE_ answers.
 */
function installCodeJudge(string $authDir, string $typed): string
{
    $tidy = installCodeTidy($typed);
    if ($tidy === '') {
        return installCodeRead($authDir) === null ? INSTALL_CODE_NO_FILE : INSTALL_CODE_BLANK;
    }

    $file = installCodePath($authDir);
    clearstatcache(true, $file);
    if (is_file($file) === false) {
        return INSTALL_CODE_NO_FILE;
    }
    $canWrite = true;
    $handle   = @fopen($file, 'r+');
    if ($handle === false) {
        $canWrite = false;
        $handle   = @fopen($file, 'r');
    }
    if ($handle === false) {
        return INSTALL_CODE_NO_FILE;
    }

    @flock($handle, LOCK_EX);
    $now = installCodeParse((string) stream_get_contents($handle));
    if ($now === null) {
        @flock($handle, LOCK_UN);
        fclose($handle);

        return INSTALL_CODE_NO_FILE;
    }

    $answer = INSTALL_CODE_WRONG;
    $next   = null;
    if (installCodesMatch($now['code'], $tidy) === true) {
        $answer = INSTALL_CODE_RIGHT;
        if ($now['wrongTries'] !== 0) {
            $next = installCodeFileText($now['code'], 0);
        }
    } else {
        $wrong = $now['wrongTries'] + 1;
        if ($wrong >= INSTALL_CODE_MOST_WRONG && $canWrite === true) {
            $answer = INSTALL_CODE_REPLACED;
            $next   = installCodeFileText(installCodeNew(), 0);
        } else {
            $next = installCodeFileText($now['code'], $wrong);
        }
    }

    if ($next !== null && $canWrite === true) {
        $written = ftruncate($handle, 0) === true && rewind($handle) === true && fwrite($handle, $next) === strlen($next);
        fflush($handle);
        // "A new code was written" must be true before anybody is told so.
        if ($written === false && $answer === INSTALL_CODE_REPLACED) {
            $answer = INSTALL_CODE_WRONG;
        }
    }
    @flock($handle, LOCK_UN);
    fclose($handle);

    return $answer;
}

/**
 * A fingerprint of the code in the file NOW (SHA-256), or '' when there is no
 * code file. The installer keeps this in the session of somebody who typed
 * the right code, and compares it on every later request: so a code that has
 * been replaced, or a code file that has gone, ends every session that was
 * let in by the old one. The code itself is never put in a session.
 */
function installCodeFingerprint(string $authDir): string
{
    $now = installCodeRead($authDir);

    return $now === null ? '' : hash('sha256', $now['code']);
}

/**
 * Remove the code file. Done once, when the installation is finished.
 *
 * The code page tells an owner who is being pestered with wrong codes to make
 * this file read-only. On Linux and macOS hosting that does not stop it being
 * removed (removing a file is a change to its FOLDER). On Windows hosting it
 * does, so the file is made writable first; a host that refuses that change
 * loses nothing. Checked on macOS on 13 October 2026: a read-only code file
 * is removed.
 *
 * WHAT IT CANNOT DO: remove a file the hosting will not let go of at all (one
 * that belongs to another account, or is marked unchangeable). It then says
 * so, and the installer's last page tells the owner to delete it by hand. By
 * then the code opens nothing: the installer is locked.
 *
 * @return bool True when no code file is left in the folder.
 */
function installCodeRemove(string $authDir): bool
{
    $file = installCodePath($authDir);
    clearstatcache(true, $file);
    if (is_file($file) === false) {
        return true;
    }
    @chmod($file, 0640);
    @unlink($file);
    clearstatcache(true, $file);

    return file_exists($file) === false;
}
