<?php
// Path: _install/install_common.php
/**
 * -----------------------------------------------------------------------------
 * What the installer and the upgrade page both do and say 🧰 (#499, #500)
 * -----------------------------------------------------------------------------
 * Two things, each needed by BOTH `_install/index.php` (which runs with none of
 * the portal loaded) and `_install/upgrade.php` (which runs with all of it):
 *
 *   1. The "refuse everything" file in the three private folders the
 *      installation makes for itself: `_auth_keys` (the database login and the
 *      key every stored secret depends on), `_uploads` (everything anybody
 *      uploads) and `_backups` (database snapshots). None of the three is in
 *      the repository or in the installation package, so nothing ships a file
 *      into them. The installer makes the folders and writes the file at the
 *      finish; the upgrade page puts back any that has gone missing.
 *   2. The words for "which copy of the portal is this, and how was that
 *      decided". The decision itself is NOT made here: it is made in one
 *      place, `Portal\Core\Channel`, and this only puts its answer into
 *      sentences.
 *   3. (The installer only.) Writing the installation's own `.channel` file
 *      from the word the package carries, and ONLY when there is none. See
 *      installWriteChannelFile().
 *
 * It lives in its own file so that the two pages cannot drift apart, and it
 * uses nothing but PHP and `Portal\Core\Channel` (for the names it prints),
 * so the installer can load it before a database exists.
 *
 * WHY THE FILE MATTERS. On a correct installation these folders sit OUTSIDE
 * the web folder and no browser can reach them. They become reachable only
 * when a hosting panel is pointed at the wrong folder (the one that holds
 * `_core`, and not the web folder inside it). A check on 12 October 2026 did
 * exactly that and was handed `/_auth_keys/enc.key`.
 *
 * WHAT IT CANNOT DO
 *   - The file is obeyed only by Apache 2.4 or later (and servers that follow
 *     its rules files) with rules files switched on. On any other web server
 *     it does nothing, and the folders are kept private only by where they
 *     are.
 *   - It does not check that the website points at the right folder. The
 *     installer catches two signs of that mistake and refuses to run: it was
 *     opened directly, or the address asked for has the web folder's own name
 *     in it. It CANNOT catch the third and commonest sign, because no file of
 *     the portal runs then: at the website's plain address the web server
 *     shows a list of folders, "Forbidden" or "Not found". Any of those three
 *     at the plain address means the website points at the wrong folder. An
 *     installed portal does not check at all.
 *   - It never replaces a rules file that holds a rule. Somebody's own rules
 *     in one of these folders are left exactly as they are, whatever they
 *     say, and so may refuse nothing: installProtectFolder() reports whether
 *     the file that is there holds the refusal, so that no page claims a
 *     protection that is not there.
 *
 * @package   Portal\Install
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/499
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/500
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Channel;

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'Channel.php';

/** The three folders an installation makes for itself, beside `_core`. */
const INSTALL_PRIVATE_FOLDERS = ['_auth_keys', '_uploads', '_backups'];

/** The one rule a "refuse everything" file holds. */
const INSTALL_REFUSE_RULE = 'Require all denied';

/**
 * The lines of a rules file that are rules: not blank, and not a comment.
 *
 * @return list<string>
 */
function installRulesOf(string $text): array
{
    $rules = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '' && str_starts_with($line, '#') === false) {
            $rules[] = $line;
        }
    }

    return $rules;
}

/**
 * 📄 The text of the "refuse everything" file.
 *
 * The wording has ONE home: the copy that ships in `_core`. It is used only
 * when it is there AND its one rule is the refusal, so that a file somebody
 * has changed, or one a file manager cut short, is never copied onwards.
 * Otherwise two lines written here are used. That happens on a server whose
 * file manager dropped every file beginning with a full stop, which is
 * exactly the server that needs this file most.
 */
function installRefuseFileText(string $root): string
{
    $shipped = $root . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . '.htaccess';
    if (is_file($shipped) === true && is_readable($shipped) === true) {
        $text = (string) file_get_contents($shipped);
        if (installRulesOf($text) === [INSTALL_REFUSE_RULE]) {
            return $text;
        }
    }

    return "# Refuse every request for anything in this folder. Written by the portal,\n"
        . "# because the copy that ships in _core was missing or had been changed.\n"
        . INSTALL_REFUSE_RULE . "\n";
}

/**
 * 🔒 Make sure ONE private folder exists and holds the "refuse everything"
 * file.
 *
 * A rules file that HOLDS A RULE is left alone (see "What it cannot do" at
 * the top). One that holds NO rule is written again: an empty file, a file
 * of comments only, or an upload cut short before its first rule. Such a
 * file refuses nothing. WHAT WAS WRONG: only a file of no bytes at all was
 * written again, so a file holding nothing but a comment was kept as
 * "somebody's own", and the installer's last page then said the folder
 * refused every request.
 *
 * WHAT IT CANNOT DO: judge a rule. A file whose one rule is half a word
 * (`Require all den`) holds a rule and is left alone.
 *
 * @param string $root The folder that holds `_core`.
 * @param string $name One of INSTALL_PRIVATE_FOLDERS.
 *
 * @return array{made: bool, written: bool, refuses: bool, problem: string}
 *         `problem` is '' when the folder now exists and holds a rules file
 *         with a rule in it; otherwise it says what could not be done, in
 *         words, with no path in it. `refuses` is true when that file holds
 *         the refusal itself, and false for a file of somebody's own that
 *         does not.
 */
function installProtectFolder(string $root, string $name): array
{
    $answer = ['made' => false, 'written' => false, 'refuses' => false, 'problem' => ''];
    $folder = $root . DIRECTORY_SEPARATOR . $name;

    clearstatcache(true, $folder);
    if (is_dir($folder) === false) {
        // 0750: the owner may do everything, the owner's group may read, and
        // nobody else may do anything. The same as the installer always used
        // for _auth_keys.
        if (file_exists($folder) === true || @mkdir($folder, 0750, true) === false) {
            clearstatcache(true, $folder);
            if (is_dir($folder) === false) {
                $answer['problem'] = 'the folder ' . $name . ' is not there and could not be made';

                return $answer;
            }
        }
        $answer['made'] = true;
    }

    $file = $folder . DIRECTORY_SEPARATOR . '.htaccess';
    clearstatcache(true, $file);
    if (is_file($file) === true) {
        // A file that cannot be read is treated as holding no rule: nothing
        // can be said for it, so it is written again, and if that fails too
        // the caller is told.
        $rules = installRulesOf((string) @file_get_contents($file));
        if ($rules !== []) {
            $answer['refuses'] = in_array(INSTALL_REFUSE_RULE, $rules, true);

            return $answer;
        }
    }

    $text = installRefuseFileText($root);
    if (@file_put_contents($file, $text, LOCK_EX) !== strlen($text)) {
        $answer['problem'] = 'the file .htaccess could not be written into the folder ' . $name;

        return $answer;
    }
    $answer['written'] = true;
    $answer['refuses'] = true;

    return $answer;
}

/**
 * 🔒 The same for all three private folders.
 *
 * @return array{made: list<string>, written: list<string>, own: list<string>, problems: list<string>}
 *         The folders made; the folders that were given the file; the
 *         folders whose rules file is somebody's own and does NOT hold the
 *         refusal; and what could not be done (empty when all three are in
 *         order).
 */
function installProtectPrivateFolders(string $root): array
{
    $report = ['made' => [], 'written' => [], 'own' => [], 'problems' => []];
    foreach (INSTALL_PRIVATE_FOLDERS as $name) {
        $one = installProtectFolder($root, $name);
        if ($one['made'] === true) {
            $report['made'][] = $name;
        }
        if ($one['written'] === true) {
            $report['written'][] = $name;
        }
        if ($one['problem'] === '' && $one['refuses'] === false) {
            $report['own'][] = $name;
        }
        if ($one['problem'] !== '') {
            $report['problems'][] = $one['problem'];
        }
    }

    return $report;
}

/**
 * 🧭 "Which copy of the portal is this, and how was that decided?", in words.
 *
 * It is handed the answer `Portal\Core\Channel` gave and decides nothing
 * itself. `assumed` is true only when NOTHING stated a channel. It must never
 * be shown as if something had: an assumed live portal that is really a test
 * copy is open to the public, and this sentence is one of the few places that
 * says so.
 *
 * A `.channel` FILE THAT WAS NOT UNDERSTOOD is said to be exactly that. It
 * used to be described as "nothing on this server says which copy this is",
 * to somebody who had made the file and could see it in the file manager.
 * `Channel` treats a file whose first line is not a word it knows as not
 * being there, and does not say that it did; so the caller looks for the
 * file (installChannelFileIgnored()) and tells this function.
 *
 * @param string      $channel       'prod', 'beta', 'alpha' or 'dev' (what PORTAL_ENV holds).
 * @param string      $source        One of the Channel::SOURCE_ constants.
 * @param string|null $webFolderName The web folder's own name (never its
 *                                   path), or null when it is not known.
 * @param bool        $fileIgnored   True when a `.channel` file is there
 *                                   but the answer did not come from it:
 *                                   see installChannelFileIgnored().
 *
 * @return array{copy: string, how: string, assumed: bool, advice: list<string>}
 *         `copy` finishes the sentence "This is …"; `how` is one sentence
 *         or two; `advice` is empty unless there is something worth doing.
 */
function installChannelWords(string $channel, string $source, ?string $webFolderName, bool $fileIgnored = false): array
{
    $copy = $channel === 'prod'
        ? 'the live portal'
        : 'a ' . $channel . ' copy (a test copy, not the live portal)';

    $fileAdvice = 'To settle it, create a plain text file named ' . Channel::FILE_NAME . ' in the folder that holds _core '
        . '(beside _core, not inside it), with one word on its first line. For the live portal that word is: live. '
        . 'For a test copy it is beta, alpha or dev.';
    // The words the portal knows, said the same way wherever they are said:
    // `live`, then Channel's own list of test copies. (`prod` is known too,
    // as another spelling of `live`; one word for it is enough on a page.)
    // tools/installer-selftest.php hands each of these to Channel and fails
    // if any is not understood.
    $knownWords = 'live, ' . implode(', ', array_slice(Channel::PRE_RELEASE, 0, -1)) . ' and ' . Channel::PRE_RELEASE[count(Channel::PRE_RELEASE) - 1];
    $ignoredHow = 'There is a file named ' . Channel::FILE_NAME . ' beside the _core folder, but it was not used: its first '
        . 'line is not a word the portal knows, or the file could not be read. The words the portal knows are '
        . $knownWords . '.';
    if ($fileIgnored === true) {
        $fileAdvice = 'To settle it, open the file ' . Channel::FILE_NAME . ' in the folder that holds _core and put one word '
            . 'on its first line, alone. For the live portal that word is: live. For a test copy it is beta, alpha or dev.';
    }
    // The dashboard's note ends with the same warning, and for the same
    // reason: where several copies share one _core folder a file would speak
    // for all of them, so "create the file" must never stand alone.
    $sharedAdvice = 'Where several copies share one _core folder, do not create the file, because it would speak for '
        . 'every copy: keep the web folders named ' . implode(', ', array_keys(Channel::FOLDER_NAMES))
        . ', or give each its own ' . Channel::VARIABLE . ' setting.';

    if ($source === Channel::SOURCE_ENVIRONMENT) {
        return ['copy' => $copy, 'how' => 'The server\'s ' . Channel::VARIABLE . ' setting says so.', 'assumed' => false, 'advice' => []];
    }
    if ($source === Channel::SOURCE_FILE) {
        return ['copy' => $copy, 'how' => 'The file ' . Channel::FILE_NAME . ', beside the _core folder, says so.', 'assumed' => false, 'advice' => []];
    }
    if ($source === Channel::SOURCE_FOLDER) {
        return [
            'copy'    => $copy,
            'how'     => 'That was worked out from the name of the web folder' . ($webFolderName === null ? '' : ' (' . $webFolderName . ')')
                . '. It holds only for as long as the folder keeps that exact name.' . ($fileIgnored === true ? ' ' . $ignoredHow : ''),
            'assumed' => false,
            'advice'  => [$fileAdvice, $sharedAdvice],
        ];
    }

    // Anything else is "nothing said". Channel only ever pairs that with the
    // live channel; the wording here does not lean on the pairing.
    return [
        'copy'    => $copy,
        'how'     => 'That is ASSUMED: ' . ($fileIgnored === true ? lcfirst($ignoredHow) . ' So nothing' : 'nothing')
            . ' on this server says which copy this is. Error details are hidden, and the '
            . 'staff-only sign-in that protects a test copy is off. If this is a test copy, anybody who has its address can reach it.',
        'assumed' => true,
        'advice'  => [$fileAdvice, $sharedAdvice],
    ];
}

/** The package's own description of itself, beside `_core`. Five "Key: value" lines; tools/package/build.sh writes it. */
const INSTALL_PACKAGE_FILE = 'VERSION.txt';

/**
 * 📦 The word the package says it is (live, beta or alpha): the `Channel:`
 * line of VERSION.txt, beside `_core`. '' when there is no such file or
 * line, or the word is not one the portal knows.
 *
 * "Knows" is asked of `Channel` itself, by handing it the word as if it were
 * the `.channel` file's first line. No second list of words is kept here.
 *
 * @param string $root The folder that holds `_core`.
 */
function installPackageChannelWord(string $root): string
{
    $file = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . INSTALL_PACKAGE_FILE;
    clearstatcache(true, $file);
    if (is_file($file) === false || is_readable($file) === false) {
        return '';
    }
    // The file is five short lines; nothing past its start is looked at.
    $text = (string) file_get_contents($file, false, null, 0, 2000);
    if (preg_match('/^Channel:[ \t]*(\S+)[ \t]*\r?$/m', $text, $found) !== 1) {
        return '';
    }

    return Channel::decideFrom(null, $found[1], null)['source'] === Channel::SOURCE_FILE ? $found[1] : '';
}

/**
 * 📝 Give the installation its own `.channel` file, from the package's word,
 * ONLY WHEN THERE IS NONE. Called once, by the installer's finish.
 *
 * WHY THE INSTALLER WRITES IT AND THE PACKAGE DOES NOT CARRY IT (#499,
 * 13 October 2026). Every package used to hold a `.channel` file, so
 * unpacking a newer package over an installation REPLACED the installation's
 * own word with the package's. A test copy given a live package called
 * itself live and lost its staff sign-in gate; a live portal given a beta
 * package shut out every member. An upgrade unpacks files and never runs the
 * installer, so now nothing an upgrade does can touch the file.
 *
 * A file that is already there is never replaced, whatever it holds (a word
 * of somebody's own, a word nobody knows, nothing at all), and neither is a
 * folder or a link of that name. A package word that is missing or unknown
 * writes nothing: the portal then assumes it is the live one, and the last
 * page says so.
 *
 * WHAT IT CANNOT DO: settle the channel where the server's PORTAL_ENV setting
 * is in use (that setting is asked first and wins), or where several copies
 * share this folder (the file then speaks for all of them).
 *
 * @param string $root The folder that holds `_core`.
 *
 * @return array{state: string, word: string} `state` is 'kept' (a file was
 *         there), 'no-word' (the package says nothing usable), 'written', or
 *         'not-written' (it could not be written). `word` is the package's
 *         word, '' when it has none.
 */
function installWriteChannelFile(string $root): array
{
    $file = Channel::filePath($root);
    clearstatcache(true, $file);
    if (file_exists($file) === true || is_link($file) === true) {
        return ['state' => 'kept', 'word' => installPackageChannelWord($root)];
    }
    $word = installPackageChannelWord($root);
    if ($word === '') {
        return ['state' => 'no-word', 'word' => ''];
    }
    // "x": make the file only if no file of that name exists, in one step,
    // so that a file which appears in the same moment is still not replaced.
    $handle = @fopen($file, 'x');
    if ($handle === false) {
        return ['state' => 'not-written', 'word' => $word];
    }
    $written = fwrite($handle, $word . "\n");
    fclose($handle);
    clearstatcache(true, $file);
    if ($written !== strlen($word) + 1) {
        // Half a word must not be left to decide which copy this is.
        @unlink($file);

        return ['state' => 'not-written', 'word' => $word];
    }

    return ['state' => 'written', 'word' => $word];
}

/**
 * 💬 What installWriteChannelFile() did, or (state 'will-write') is about to
 * do, as one or two sentences for the installer's pages. '' for a file that
 * was already there: the page's own sentence then says the file decided.
 *
 * @param array{state: string, word: string} $report
 */
function installChannelFileWords(array $report): string
{
    $word = $report['word'];
    $file = 'the file ' . Channel::FILE_NAME . ', beside the _core folder';
    // "the live portal" or "a beta copy", in the words installChannelWords() uses.
    $copy = Channel::decideFrom(null, $word, null)['channel'] === 'prod' ? 'the live portal' : 'a ' . $word . ' copy (a test copy, not the live portal)';

    return match ($report['state']) {
        'will-write'  => 'This package says it is ' . $copy . '. When the installation finishes, the installer writes the word '
            . $word . ' into ' . $file . ', which settles it. There is nothing for you to create.',
        'written'     => 'The installer has just written the word ' . $word . ' into ' . $file . ', because this package says it is '
            . $copy . '. Unpacking a newer package never replaces that file.',
        'not-written' => 'This package says it is ' . $copy . ', but the installer could not write the word ' . $word . ' into ' . $file
            . '. Create the file yourself, with the one word ' . $word . ' on its first line.',
        'no-word'     => 'The installer wrote no ' . Channel::FILE_NAME . ' file, because these files do not say which copy they '
            . 'are: there is no ' . INSTALL_PACKAGE_FILE . ' beside the _core folder with a Channel line the portal knows.',
        default       => '',
    };
}

/**
 * 📄 Is there a `.channel` file that the decision did NOT come from?
 *
 * True when the file is there and the channel was worked out from the web
 * folder's name or only assumed: the file's first line was then not a word
 * the portal knows, or the file could not be read. False when the server's
 * setting decided, because the file is never asked then and may be perfectly
 * good.
 *
 * @param string $root   The folder that holds `_core`.
 * @param string $source One of the Channel::SOURCE_ constants: how the
 *                       channel WAS decided.
 */
function installChannelFileIgnored(string $root, string $source): bool
{
    if ($source !== Channel::SOURCE_FOLDER && $source !== Channel::SOURCE_ASSUMED) {
        return false;
    }
    $file = Channel::filePath($root);
    clearstatcache(true, $file);

    return is_file($file) === true;
}
