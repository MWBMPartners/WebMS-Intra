<?php
// Path: _core/Channel.php
/**
 * -----------------------------------------------------------------------------
 * Release channel 🧭 — which copy of the portal is this?
 * -----------------------------------------------------------------------------
 * An installation is the live portal, or a pre-release copy (beta, alpha or
 * dev) running unfinished work. Two things hang on the answer, and both are
 * about what strangers get to see:
 *
 *   - whether PHP's own error text is printed into pages (file paths, parts
 *     of queries, names of settings), and
 *   - whether the staff sign-in gate (Gatekeeper) stands in front of every
 *     page.
 *
 * THE ANSWER COMES FROM, IN THIS ORDER (owner's final rule, 12 October 2026)
 *   1. the `PORTAL_ENV` setting (an environment variable), when it holds a
 *      known word;
 *   2. else the first line of the file `.channel`, in the folder that holds
 *      `_core` (so beside `_core`, outside the web folder), when it holds a
 *      known word;
 *   3. else the web folder's own name, ONLY when it is EXACTLY one of three
 *      names, each meaning what the last release took it to mean:
 *      `public_html` is live, `public_html_beta` is beta and
 *      `public_html_dev` is dev;
 *   4. else the portal ASSUMES IT IS LIVE: errors hidden, staff gate off.
 *
 * A word nobody knows, in the setting or in the file, counts as "not there":
 * the next step is tried. The answer also says HOW it was decided
 * (`environment`, `file`, `folder` or `assumed`), which `/health` reports
 * and the admin dashboard explains when the answer was not written down.
 *
 * The words understood, in the setting and in the file alike: `live`,
 * `beta`, `alpha`, `dev`, and `prod` (the older word for live, which is what
 * PORTAL_ENV holds for the live channel everywhere else in the code).
 *
 * WHAT THIS REPLACED, AND WHY
 * Until 12 October 2026 the channel was guessed from the web folder's path
 * as the web server reported it (DOCUMENT_ROOT), looking for the three names
 * ANYWHERE in that path, and anything unrecognised became `dev` with errors
 * shown and the gate off. A customer whose host calls the web folder
 * `httpdocs` or `www` was therefore a live portal printing PHP errors to the
 * public; and on hosting that builds the web folder from the site's name, a
 * visitor's own Host header could shape the path that was searched.
 *
 * TRIED FIRST AND REPLACED THE SAME DAY: "FAIL CLOSED"
 * The first build of this class (commit 7b30c99) had no step 3, and its last
 * resort was the opposite of step 4: an installation that said nothing was
 * treated as a `dev` copy with errors hidden, so the staff gate stood in
 * front of every page until somebody created the file. The owner replaced
 * it before it was released. The moment that code was uploaded to an
 * existing live portal, every member was refused, and so was everything that
 * arrives without a staff sign-in: scheduled jobs, payment notices, the API
 * with a key. The owner's rule is that nobody is ever locked out for want of
 * a file. Please do not bring "fail closed" back as a tidy-up.
 *
 * WHAT STEP 4 COSTS, SAID PLAINLY
 * A mistake now fails OPEN, and quietly. A pre-release copy in a folder of
 * some other name, whose file is lost or holds a mistyped word, becomes
 * "assumed live": no staff gate, and nothing on screen says so. Three things
 * are there so that somebody notices: `/health` says `"channelSource":
 * "assumed"`; the admin dashboard shows an amber note; and PHP's error log
 * gains one line per request (see Gatekeeper::shouldEnforce()). What an
 * assumed copy never does is show anything a pre-release copy would: no PHP
 * error text, no debug mode, no debug panel.
 *
 * CONSIDERED AND REJECTED
 *   - Taking the folder's name from DOCUMENT_ROOT, or looking for the three
 *     names anywhere in a path, as the last release did. See above. The name
 *     compared is the last part of the folder a front controller says it is
 *     in (`__DIR__` in index.php and error.php), whole and as it is written:
 *     `public_html_old`, `Public_HTML` and `my_public_html` are not known
 *     names. tools/channel-selftest.php fails if start-up reads DOCUMENT_ROOT.
 *   - Stopping at a setting or a file whose word is not understood (the
 *     first build did). That is how a typing mistake locked a portal's
 *     members out.
 *   - Trimming the whole file before taking its first line. That would lift a
 *     word on the second line up into first place when the first is blank.
 *
 * WHAT THIS CANNOT DO
 *   - Tell whether the file is telling the truth. A `.channel` saying `live`
 *     that is copied into a beta folder makes that copy live: open, no gate.
 *     Whatever uploads the code must write the right file for that channel.
 *   - Serve several web folders that share ONE `_core`. The file sits beside
 *     `_core`, so it would speak for every one of them, and it is asked
 *     before the folder's name. In that layout do NOT create the file: rely
 *     on the three folder names, or give each web folder its own PORTAL_ENV.
 *   - Know the web folder when no front controller ran. A command-line tool
 *     that loads start-up by itself skips step 3 (start-up's own stand-in
 *     name for the web folder is never used here), so with no setting and no
 *     file it is answered by step 4.
 *   - See through a link. Where the hosting's web folder is a link to
 *     another folder, the name compared is the real folder's.
 *   - Read a file saved as UTF-16. Its first line is then not a known word,
 *     which counts as "not there". (UTF-8 with or without a byte-order mark,
 *     and Windows, Unix or old Mac line ends, are all read.)
 *   - See a setting the web server does not pass on to PHP. It asks
 *     getenv(), as the portal always has, and nothing a visitor sends: no
 *     request header, address, cookie or form field is ever read here.
 *
 * This class reaches for nothing else in the portal, on purpose: start-up
 * loads it by name before the class loader exists, and
 * tools/channel-selftest.php loads it alone.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/499
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/500
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class Channel
{
    /** The environment variable that names the channel. */
    public const VARIABLE = 'PORTAL_ENV';

    /** The file that names the channel, in the folder that holds `_core`. */
    public const FILE_NAME = '.channel';

    /**
     * How the channel was decided: PORTAL_ENV_SOURCE is exactly one of these,
     * one for each step of the order at the top of this file. The first
     * three STATE a channel. `assumed` means nothing did, and it only ever
     * comes with the live channel.
     */
    public const SOURCE_ENVIRONMENT = 'environment';
    public const SOURCE_FILE        = 'file';
    public const SOURCE_FOLDER      = 'folder';
    public const SOURCE_ASSUMED     = 'assumed';

    /**
     * The pre-release channels, as PORTAL_ENV holds them. Gatekeeper gates
     * exactly these (it reads this list, so the two cannot drift apart).
     */
    public const PRE_RELEASE = ['alpha', 'beta', 'dev'];

    /**
     * Each word that may be written, and the value PORTAL_ENV gets for it.
     * `live` and `prod` both mean the live channel; the rest of the code has
     * always compared PORTAL_ENV with 'prod', so that is the value kept.
     */
    private const WORDS = [
        'live'  => 'prod',
        'prod'  => 'prod',
        'beta'  => 'beta',
        'alpha' => 'alpha',
        'dev'   => 'dev',
    ];

    /**
     * Step 3: the three web folder names that state a channel, and the value
     * PORTAL_ENV gets for each. These are the names the last release knew,
     * with the meaning it gave them, so an installation whose web folder has
     * one of these names carries on as it was without a new file.
     *
     * `public_html_dev` stays `dev` (not `alpha`) on purpose: the staff gate
     * reads its extra roles from a setting named after the channel
     * (`portal.devAccessRoles`), and a different word here would silently
     * stop reading the roles such an installation already has.
     *
     * The match is on the WHOLE name, as it is written. No other name is
     * known, however close.
     */
    public const FOLDER_NAMES = [
        'public_html'      => 'prod',
        'public_html_beta' => 'beta',
        'public_html_dev'  => 'dev',
    ];

    /** A byte-order mark: three bytes some Windows editors put at the start of a UTF-8 file. */
    private const BYTE_ORDER_MARK = "\xEF\xBB\xBF";

    /**
     * The first line of the file must END within this many bytes. The longest
     * known word is five letters; the rest is room for a mark, spaces and a
     * line end. One byte more than this is read, only to find out whether
     * the file goes on (see firstLine()).
     */
    private const MOST_BYTES_READ = 256;

    /**
     * Decide this installation's channel.
     *
     * @param string      $portalRoot The folder that holds `_core` (PORTAL_ROOT).
     * @param string|null $webFolder  The web folder, ONLY as a front
     *                                controller stated it (`__DIR__` in
     *                                index.php or error.php); null when no
     *                                front controller ran. Never a value the
     *                                web server reports, and never a made-up
     *                                default: either would let step 3 answer
     *                                from a name that is not this folder's.
     *
     * @return array{channel:string, source:string, showErrors:bool}
     *         `channel` is what PORTAL_ENV gets ('prod', 'beta', 'alpha' or
     *         'dev'); `source` is one of the SOURCE_ constants; `showErrors`
     *         says whether PHP's error text may be printed into pages.
     */
    public static function decide(string $portalRoot, ?string $webFolder): array
    {
        $variable = getenv(self::VARIABLE);

        return self::decideFrom(
            $variable === false ? null : $variable,
            self::fileText($portalRoot),
            $webFolder === null ? null : basename($webFolder)
        );
    }

    /**
     * The decision itself, from the three things it may be read from. Kept
     * apart from decide() so that a test can hand it every case in a table
     * without touching the environment or the disk.
     *
     * @param string|null $variable   What PORTAL_ENV holds, or null when it
     *                                is not set.
     * @param string|null $fileText   The start of the `.channel` file, or
     *                                null when there is no file that can be
     *                                read.
     * @param string|null $folderName The web folder's own name (the last
     *                                part of its path), or null when no
     *                                front controller stated it.
     *
     * @return array{channel:string, source:string, showErrors:bool}
     */
    public static function decideFrom(?string $variable, ?string $fileText, ?string $folderName): array
    {
        // 1️⃣ The setting. Empty, only spaces, or a word nobody knows: not
        //    there, so the file is asked next.
        if ($variable !== null) {
            $channel = self::channelFor($variable);
            if ($channel !== null) {
                return self::decided($channel, self::SOURCE_ENVIRONMENT);
            }
        }

        // 2️⃣ The file's first line. The same: a word nobody knows is not
        //    there, so the folder's name is asked next.
        if ($fileText !== null) {
            $channel = self::channelFor(self::firstLine($fileText));
            if ($channel !== null) {
                return self::decided($channel, self::SOURCE_FILE);
            }
        }

        // 3️⃣ The web folder's own name: the whole name, exactly as written.
        //    A list look-up and not a search inside the name, on purpose (see
        //    "Considered and rejected" at the top of this file).
        if ($folderName !== null && array_key_exists($folderName, self::FOLDER_NAMES) === true) {
            return self::decided(self::FOLDER_NAMES[$folderName], self::SOURCE_FOLDER);
        }

        // 4️⃣ Nothing says: assume live.
        return self::assumedLive();
    }

    /**
     * The full path of the file an installation's channel is written in.
     *
     * @param string $portalRoot The folder that holds `_core`.
     */
    public static function filePath(string $portalRoot): string
    {
        return rtrim($portalRoot, '/\\') . DIRECTORY_SEPARATOR . self::FILE_NAME;
    }

    /**
     * The start of the channel file, or null when there is no real file of
     * that name that can be read. A folder of that name, or a file the web
     * server's user may not read, is "no file": never an error on screen.
     */
    private static function fileText(string $portalRoot): ?string
    {
        $path = self::filePath($portalRoot);
        if (is_file($path) === false || is_readable($path) === false) {
            return null;
        }
        // One byte more than the limit, so firstLine() can tell a first line
        // that ends inside the limit from one that was cut off at its edge.
        $text = @file_get_contents($path, false, null, 0, self::MOST_BYTES_READ + 1);

        return $text === false ? null : $text;
    }

    /**
     * The first line of the file's text, without a byte-order mark and
     * without the spaces around it. ONLY the first line: a word further down
     * is never believed, and a blank first line is a blank answer.
     *
     * A first line that does not END within MOST_BYTES_READ bytes is a blank
     * answer too. WHAT WAS WRONG: exactly 256 bytes were read and whatever
     * came back was taken as the line, so a word cut off at the edge was
     * believed: 252 spaces and then `production` read as `prod`, and `live`,
     * 300 spaces and then more text read as `live`. Nothing here may guess
     * at a word it has not seen the end of.
     */
    private static function firstLine(string $text): string
    {
        // No more than the limit and one byte, whoever handed the text in.
        $text = substr($text, 0, self::MOST_BYTES_READ + 1);
        // Windows (\r\n), Unix (\n) and old Mac (\r) line ends all end the line.
        $lines = preg_split('/\r\n|\r|\n/', $text, 2);
        if (is_array($lines) === false) {
            return '';
        }
        // No line end anywhere in what was read, and the text goes on past
        // the limit: the first line was cut off, so it is not an answer.
        if (count($lines) === 1 && strlen($text) > self::MOST_BYTES_READ) {
            return '';
        }
        $line = (string) $lines[0];
        if (str_starts_with($line, self::BYTE_ORDER_MARK) === true) {
            $line = substr($line, strlen(self::BYTE_ORDER_MARK));
        }

        return trim($line);
    }

    /**
     * The PORTAL_ENV value for a written word, or null when the word is not
     * one this portal knows. Capital letters and spaces around the word are
     * forgiven (`Live`, ` beta `); nothing else is. A word that is merely
     * close (`production`, `staging`, `live!`) is not known, and counts as
     * "not there": the next step of the order is tried. Nothing guesses what
     * somebody meant.
     */
    private static function channelFor(string $word): ?string
    {
        return self::WORDS[strtolower(trim($word))] ?? null;
    }

    /**
     * A channel that was stated: by the setting, by the file, or by the web
     * folder's name. PHP's error text is printed into pages only on a
     * pre-release channel that was stated.
     *
     * WHAT THAT DOES NOT MEAN: that only staff can see it. The staff gate
     * leaves some addresses open on a pre-release copy (the sign-in pages,
     * Help, the offline page, `/health`), and a PHP warning raised on one of
     * those is printed to a visitor who is not signed in, file path
     * included. That was so before this class existed. (An earlier comment
     * here said the people who reach those pages are staff, behind the gate.
     * It was untrue.) Uncaught exceptions are different: a visitor gets the
     * error page, not the text.
     *
     * @return array{channel:string, source:string, showErrors:bool}
     */
    private static function decided(string $channel, string $source): array
    {
        return [
            'channel'    => $channel,
            'source'     => $source,
            'showErrors' => in_array($channel, self::PRE_RELEASE, true),
        ];
    }

    /**
     * 🟢 Step 4: nothing says which channel this is, so it is taken to be
     * the live portal. Always 'prod', and never with errors shown: nothing a
     * pre-release copy would show may appear on a copy whose channel was
     * only assumed. tools/channel-selftest.php fails if either changes.
     *
     * @return array{channel:string, source:string, showErrors:bool}
     */
    private static function assumedLive(): array
    {
        return [
            'channel'    => 'prod',
            'source'     => self::SOURCE_ASSUMED,
            'showErrors' => false,
        ];
    }
}
