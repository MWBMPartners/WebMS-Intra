<?php
// Path: _core/PhpRuntime.php
/**
 * -----------------------------------------------------------------------------
 * The PHP this site runs on — is it new enough, and does it carry the fix? 🐘
 * -----------------------------------------------------------------------------
 * Reads the version of PHP serving this request and says, in plain English,
 * whether it is a version this portal supports and whether it is old enough to
 * be missing the October 2026 security fix (the one the press called
 * "PHP2Shell").
 *
 * WHY THIS EXISTS
 * ---------------
 * Four places already read the PHP version and showed it: the installation
 * wizard's checks, the admin dashboard, the Server Information page and the
 * health page. Three of them printed it and left the reader to judge it. The
 * fourth judged it with its own rules written inline, and the Server
 * Information page said in a comment that those rules were "short enough to
 * keep here rather than build a second class for them" — which was true until
 * a security judgement had to be made as well.
 *
 * That judgement is exactly the kind that must not be written twice. If the
 * installer and the dashboard disagree about whether a version is safe, one of
 * them is telling a customer something untrue. So this class is the ONE place
 * that decides, in the same shape as `DbServer` does for the database: change
 * the constants below, and every page that asks changes with them.
 *
 * WHAT THIS CANNOT DO — READ THIS BEFORE WRITING ANY WORDING
 * ----------------------------------------------------------
 * **A version number cannot tell you whether a build is patched.** Debian,
 * Ubuntu, Red Hat, cPanel and most shared hosts backport security fixes
 * WITHOUT changing the version number PHP reports. A server reporting 8.4.11
 * may well carry the October 2026 fix, because the host applied it and left
 * the number alone.
 *
 * So this class never says "you are vulnerable". It says "this is older than
 * the release that fixed it — ask your host whether the fix has been applied".
 * That is the honest claim, and it is the only one the evidence supports.
 * Anyone tempted to shorten the wording to something more alarming should
 * know it was considered and rejected for this reason: a warning that is
 * sometimes wrong teaches people to ignore the next one.
 *
 * It also follows that this must NOT block an installation by default. On
 * shared hosting the customer usually cannot choose the PHP version at all, so
 * refusing to install would lock them out of their own portal over a number
 * they cannot change and that may already be patched. See
 * BLOCK_INSTALL_WHEN_UNPATCHED below, which exists so the owner can change
 * that decision in one place if they ever want to.
 *
 * A PRE-RELEASE IS NOT "NEWER" — THIS FILE GOT THAT WRONG ONCE
 * -----------------------------------------------------------
 * The first version of this file said that any release line newer than the
 * newest one it knew about must contain the fix, because it would have been
 * published later. **That was simply false, and an outside review caught it.**
 * PHP 8.6 had already shipped EIGHT pre-releases when the fix came out: Alpha
 * 1 to 3, Beta 1 to 3, RC2 and RC3. (There is no RC1 — PHP's own RC2
 * announcement says "a mistake was made while packaging RC1, so RC2 was
 * released instead". The count said "nine" until a review on 27 October 2026
 * checked it.) Alpha 1 was 2 July 2026 and RC3 was 8 October, both BEFORE the
 * 21 October fix. PHP's own RC4 announcement says "everyone running earlier
 * builds of PHP 8.6.0 is strongly encouraged to update immediately".
 *
 * So a server reporting 8.6.0RC3 was being shown a green panel saying it
 * carried a fix it did not have. That is the one direction this file calls
 * worse than having no check at all.
 *
 * The rule now: a line this file has never heard of is treated as carrying the
 * fix ONLY when it is a finished release. A PRE-RELEASE of an unknown line is
 * answered "cannot tell", because that is exactly the case that was wrong. Do
 * not "simplify" that back.
 *
 * IT ALSO WORKS DURING INSTALLATION
 * ---------------------------------
 * Written to the same "bootstrap-free" contract as `DbServer`, `version.php`
 * and `brand-defaults.php`: it depends on nothing at all — no App, no
 * Settings, no Site, no PORTAL_* constant, not even a database connection. The
 * wizard runs before the portal's start-up code and can simply
 * `require_once` this file. Do not add a dependency here without also fixing
 * the wizard.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/640
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

/**
 * 🐘 Judges the PHP version serving this request: supported or not, and
 *    carrying the October 2026 security fix or not.
 */
final class PhpRuntime
{
    // 🚧 The oldest PHP this portal's own code can run on. Below this the
    //    portal genuinely breaks — the code uses language features that older
    //    versions do not have — so the installation wizard stops.
    //
    //    This is NOT a security line. It is a "will the code run at all" line,
    //    and it was 8.4.0 before this class existed. Keeping the two kinds of
    //    judgement apart is the whole point of this file.
    public const MIN_PHP = '8.4.0';

    // 🎯 The release line this portal is written for and tested on.
    public const PREFERRED_SERIES = '8.5';

    // 🔒 The first release of each line that carries the fix for the October
    //    2026 remote-code-execution flaws, CVE-2026-109618 and
    //    CVE-2026-109619 — the pair widely reported as "PHP2Shell".
    //
    //    Published by the PHP project on 21 October 2026. Both flaws score 9.2
    //    out of 10 and allow code to be run on the server by somebody who has
    //    not signed in to anything. Attempts to exploit them were recorded in
    //    the wild on 17 October 2026, four days BEFORE the flaws were made
    //    public, so "nobody knows about it yet" was never true.
    //
    //    8.3 and 8.2 are listed although they are below MIN_PHP. A server
    //    reporting one of those fails the MIN_PHP check first and is told it
    //    cannot run the portal — but if that check is ever relaxed, the
    //    security answer for those lines should still be right rather than
    //    silently absent. Lines older than 8.2 received no official release at
    //    all, only source patches, which is itself worth saying out loud.
    //
    //    ⏳ THIS LIST GOES OUT OF DATE. Last checked: 27 October 2026, against
    //    the PHP project's own announcement. Going stale makes this LESS
    //    informative rather than wrong: a line this list has never heard of is
    //    treated as carrying the fix (see NEWEST_KNOWN_SERIES), because every
    //    release line published after October 2026 necessarily contains it.
    //    8.6 is listed although it had no finished release when this was
    //    written, and its entry is a RELEASE CANDIDATE number on purpose.
    //    PHP 8.6.0 was in pre-release from 2 July 2026, so EIGHT of its builds
    //    predate the fix (Alpha 1-3, Beta 1-3, RC2, RC3 — there was no RC1);
    //    RC4, of 21 October 2026, is the first that carries it.
    //    `version_compare()` understands pre-release markers — it ranks
    //    dev < alpha < beta < RC < the finished release — so 8.6.0RC3 compares
    //    BELOW 8.6.0RC4 and the finished 8.6.0 compares above it, which is
    //    exactly right. That is also why the comparison below must be made on
    //    the version WITH its marker and not on a tidied three-part number.
    public const PHP2SHELL_FIXED_IN = [
        '8.6' => '8.6.0RC4',
        '8.5' => '8.5.12',
        '8.4' => '8.4.27',
        '8.3' => '8.3.36',
        '8.2' => '8.2.35',
    ];

    // 📅 The newest release line this file knows about. A FINISHED release of a
    //    line above this is reported as carrying the fix; a PRE-RELEASE of such
    //    a line is reported as "cannot tell". See the file header: treating
    //    every build of an unknown newer line as safe is the mistake this file
    //    already made once, about PHP 8.6.
    public const NEWEST_KNOWN_SERIES = '8.6';

    // 🔗 The PHP project's own announcement. Deliberately the official page
    //    rather than a security company's write-up: it is the one source that
    //    cannot be out of date about PHP's own releases, and it links on to
    //    the full technical detail for anyone who wants it.
    public const PHP2SHELL_ADVISORY = 'https://www.php.net/archive/2026.php#2026-10-21-5';

    // 🔗 Where a customer actually goes to see which versions exist.
    public const SUPPORTED_VERSIONS_PAGE = 'https://www.php.net/supported-versions.php';

    // 🏷️ The identifiers, so that a customer's hosting company recognises what
    //    is being asked about. Hosts answer "CVE-2026-109618" far faster than
    //    they answer "that PHP thing in the news".
    public const PHP2SHELL_CVES = 'CVE-2026-109618 and CVE-2026-109619';

    // 🛑 Should an installation be REFUSED on a PHP older than the fix?
    //
    //    No, and the reason is in the file header: a version number cannot
    //    tell a patched build from an unpatched one, and on shared hosting the
    //    customer cannot change the number anyway. Refusing would lock people
    //    out of their own portal over something that may already be fixed.
    //
    //    This is a constant rather than an inline `false` so that the decision
    //    has one home. If the owner ever decides an install should stop, this
    //    is the only line to change: the installer reads it.
    public const BLOCK_INSTALL_WHEN_UNPATCHED = false;

    /**
     * 🐘 Judge a PHP version.
     *
     * Two judgements are made and both are returned, because they answer
     * different questions and a page may want to show them separately:
     *
     *   - `support`  Can this portal run here at all, and is the line still
     *                getting fixes from the PHP project?
     *   - `security` Is this older than the release that fixed the October
     *                2026 flaws?
     *
     * `state` is the worse of the two, for a page that wants one answer. The
     * three values mean the same as they do in `DbServer`: 'ok' is fine,
     * 'warn' deserves attention but never blocks anything, and 'crit' means
     * the portal cannot actually run here.
     *
     * @param string $raw The version to judge. Defaults to the running PHP.
     *                    Passed in so this can be tested against versions the
     *                    test machine is not running.
     *
     * @return array<string, mixed>
     */
    public static function classify(string $raw = PHP_VERSION): array
    {
        $version = self::parseVersion(trim($raw));

        // ❓ Nothing readable. Say so plainly rather than guessing — a wrong
        //    "you are safe" is worse than an admitted "cannot tell".
        if ($version === '') {
            return [
                'raw'      => trim($raw),
                'version'  => '',
                'series'   => '',
                'state'    => 'warn',
                'headline' => 'The PHP version could not be read',
                'detail'   => 'This portal could not make sense of the version PHP reported, so it '
                            . 'cannot tell you whether that version is supported or whether it '
                            . 'carries the October 2026 security fix. Everything else will still '
                            . 'work. Ask your hosting company which version of PHP this website '
                            . 'uses.',
                'support'  => [
                    'state'    => 'warn',
                    'headline' => 'The PHP version could not be read',
                    'detail'   => 'Nothing could be judged from it.',
                ],
                'security' => self::unknownSecurity(),
            ];
        }

        $series = self::series($version);

        // 🔬 The version AS REPORTED, from its first digit onwards, keeping any
        //    pre-release marker and any packaging label. version_compare()
        //    needs this rather than the tidied three-part number: without the
        //    marker, 8.6.0RC3 is indistinguishable from the finished 8.6.0, and
        //    telling those two apart is the whole point (see the file header).
        $comparable = self::comparableVersion(trim($raw));
        $preRelease = self::looksPreRelease(trim($raw));

        $support  = self::judgeSupport($version, $series, $preRelease);
        $security = self::judgeSecurity($version, $series, $comparable, $preRelease);

        // 🥇 The overall answer is the worse of the two. 'crit' only ever comes
        //    from the support side: the security side cannot be certain enough
        //    to stop anything (see the file header).
        $state = self::worseOf($support['state'], $security['state']);

        // 📣 Which of the two speaks for the whole answer? The worse one. When
        //    they are equally bad the security one is shown, because "ask your
        //    host about the security fix" is the more urgent errand.
        $speaker = $security['state'] === $state ? $security : $support;

        return [
            'raw'      => trim($raw),
            'version'  => $version,
            'series'   => $series,
            'state'    => $state,
            'headline' => $speaker['headline'],
            'detail'   => $speaker['detail'],
            'support'  => $support,
            'security' => $security,
        ];
    }

    /**
     * 🚧 Can the portal run here, and is the line still maintained?
     *
     * @param string $version Three-part version number.
     * @param string $series  The release line, such as '8.4'.
     *
     * @return array<string, string>
     */
    private static function judgeSupport(string $version, string $series, bool $preRelease = false): array
    {
        if (version_compare($version, self::MIN_PHP, '<') === true) {
            return [
                'state'    => 'crit',
                'headline' => 'PHP ' . $version . ' is too old to run this portal',
                'detail'   => 'This portal needs PHP ' . self::MIN_PHP . ' or newer. Parts of the code use '
                            . 'features that older versions of PHP do not have, so things break in ways '
                            . 'that are hard to diagnose. In your hosting panel, find the PHP version '
                            . 'setting for this website and choose ' . self::PREFERRED_SERIES . ' if it is '
                            . 'offered. If it is not, ask your hosting company.',
            ];
        }

        if (version_compare($series, self::PREFERRED_SERIES, '<') === true) {
            return [
                'state'    => 'warn',
                'headline' => 'PHP ' . $version . ' runs this portal, but its active support ends soon',
                'detail'   => 'PHP ' . $series . ' runs this portal perfectly well. Be aware that its active '
                            . 'support ends on 31 December 2026, after which it receives security fixes '
                            . 'only. PHP ' . self::PREFERRED_SERIES . ' is the version to move to when your '
                            . 'hosting company offers it. You can see the dates for every version at '
                            . self::SUPPORTED_VERSIONS_PAGE . '.',
            ];
        }

        // 🧪 A test build is a test build whatever its line, so this is asked
        //    BEFORE the "newer than we know" branch below. Asked after it, a
        //    pre-release of an UNKNOWN line was told it was "almost certainly
        //    fine" and never warned about being a test build (noted by a review
        //    on 27 October 2026).
        if ($preRelease === true) {
            return [
                'state'    => 'warn',
                'headline' => 'PHP ' . $version . ' is a test build, not a finished release',
                'detail'   => 'This server is running a pre-release of PHP — an alpha, a beta or a '
                            . 'release candidate. Those are for trying things out, not for a portal '
                            . 'people rely on: they can contain faults that the finished release does '
                            . 'not, and they stop receiving fixes as soon as the next one appears. Ask '
                            . 'your hosting company to move this website to a finished release.',
            ];
        }

        // 🆕 A line newer than anything listed here. Do NOT assert that it is
        //    supported and receiving fixes: this file knows nothing about it.
        //    Saying so was flagged as over-claiming by a review on 27 October
        //    2026, and it is the same fault as the pre-release one — stating a
        //    comfortable thing the evidence does not support.
        if (version_compare($series, self::NEWEST_KNOWN_SERIES, '>') === true) {
            return [
                'state'    => 'ok',
                'headline' => 'PHP ' . $version . ' is newer than this portal knows about',
                'detail'   => 'PHP ' . $series . ' came out after this version of the portal, so nobody '
                            . 'here has checked the portal against it and we cannot tell you where it '
                            . 'stands for support. It is almost certainly fine. Your hosting company, '
                            . 'and ' . self::SUPPORTED_VERSIONS_PAGE . ', are the places to confirm it.',
            ];
        }

        return [
            'state'    => 'ok',
            'headline' => 'PHP ' . $version . ' is a supported version',
            'detail'   => 'This is a supported version of PHP and it is still receiving security fixes '
                        . 'from the PHP project.',
        ];
    }

    /**
     * 🔒 Is this older than the release that fixed the October 2026 flaws?
     *
     * Read the file header before changing a single word of the wording here.
     * The claim is deliberately "older than the fix, ask your host", never
     * "you are vulnerable", because a version number cannot tell a
     * vendor-patched build from an unpatched one.
     *
     * @param string $version Three-part version number.
     * @param string $series  The release line, such as '8.4'.
     *
     * @return array<string, mixed>
     */
    private static function judgeSecurity(
        string $version,
        string $series,
        string $comparable,
        bool $preRelease
    ): array {
        // 🆕 A line newer than anything this file knows about.
        if (version_compare($series, self::NEWEST_KNOWN_SERIES, '>') === true) {
            // 🚨 A PRE-RELEASE of such a line cannot be vouched for. This is
            //    the exact case this file got wrong about PHP 8.6, whose
            //    pre-releases began in July 2026 — three months BEFORE the fix.
            //    A finished release of a line later than any listed here really
            //    did come out after October 2026; a test build of it may not
            //    have. Read the file header before changing this.
            if ($preRelease === true) {
                return self::unknownSecurity();
            }

            return [
                'state'      => 'ok',
                'fixedIn'    => '',
                'headline'   => 'PHP ' . $version . ' is newer than the October 2026 security fix',
                'detail'     => 'This is a finished release of a line that came out after the October '
                              . '2026 fix for ' . self::PHP2SHELL_CVES . ', so it contains that fix. '
                              . 'This portal was released before PHP ' . $series . ' existed, so '
                              . 'nobody here has checked it against this version.',
                'advisoryUrl' => self::PHP2SHELL_ADVISORY,
                'cves'        => self::PHP2SHELL_CVES,
            ];
        }

        // ❓ A line older than any this file lists. Those received no official
        //    release carrying the fix — only source patches — so we genuinely
        //    cannot name a safe version number for them.
        if (isset(self::PHP2SHELL_FIXED_IN[$series]) === false) {
            return self::unknownSecurity();
        }

        $fixedIn = self::PHP2SHELL_FIXED_IN[$series];

        // ⚖️ Compared on the version WITH its pre-release marker, so that
        //    8.5.12RC1 does NOT count as 8.5.12, and 8.6.0RC3 does not count as
        //    8.6.0RC4. A NUMBERED packaging label works too: version_compare
        //    ranks "8.4.27-1~deb13u1" above "8.4.27", which is right — that
        //    really is a build of 8.4.27. Checked against the shapes real
        //    distributions use: "-1ubuntu1", "-0+deb13u1", "+pl1", "-5.el9".
        //
        //    A purely ALPHABETIC label ("8.4.27-cpanel") ranks BELOW the plain
        //    release, so it is warned about when it need not be. That is the
        //    cautious direction, it never blocks anything, and the alternative
        //    — stripping labels we do not recognise — would risk reading a
        //    pre-release as its finished release, which is the fault this whole
        //    arrangement exists to prevent. Left as it is on purpose; noted by
        //    a review on 27 October 2026.
        if (version_compare($comparable, $fixedIn, '>=') === true) {
            return [
                'state'       => 'ok',
                'fixedIn'     => $fixedIn,
                'headline'    => 'PHP ' . $version . ' carries the October 2026 security fix',
                'detail'      => 'The serious flaws found in PHP in October 2026 ('
                               . self::PHP2SHELL_CVES . ') were fixed in PHP ' . $fixedIn
                               . ', and this website runs that release or a later one.',
                'advisoryUrl' => self::PHP2SHELL_ADVISORY,
                'cves'        => self::PHP2SHELL_CVES,
            ];
        }

        return [
            'state'       => 'warn',
            'fixedIn'     => $fixedIn,
            'headline'    => 'PHP ' . $version . ' is older than the October 2026 security fix',
            'detail'      => 'In October 2026 two serious flaws were found in PHP ('
                           . self::PHP2SHELL_CVES . '). They let somebody who has not signed in to '
                           . 'anything run their own code on the server, so they matter a great deal. '
                           . 'The PHP project fixed them in PHP ' . $fixedIn . ', and this website '
                           . 'reports PHP ' . $version . '.'
                           . "\n\n"
                           . 'That does NOT necessarily mean this website is at risk. Many hosting '
                           . 'companies apply security fixes without changing the version number PHP '
                           . 'reports, so a server saying ' . $version . ' may already be fixed. There '
                           . 'is no way for this portal to tell from the inside.'
                           . "\n\n"
                           . 'What to do: ask your hosting company whether the fixes for '
                           . self::PHP2SHELL_CVES . ' have been applied to this website, or move the '
                           . 'website to PHP ' . $fixedIn . ' or newer in your hosting panel. The PHP '
                           . 'project explains the problem in its own words at '
                           . self::PHP2SHELL_ADVISORY . '.',
            'advisoryUrl' => self::PHP2SHELL_ADVISORY,
            'cves'        => self::PHP2SHELL_CVES,
        ];
    }

    /**
     * ❓ The security answer when no safe version number can be named.
     *
     * @return array<string, mixed>
     */
    private static function unknownSecurity(): array
    {
        return [
            'state'       => 'warn',
            'fixedIn'     => '',
            'headline'    => 'Whether this PHP carries the October 2026 security fix is unknown',
            'detail'      => 'Two serious flaws were found in PHP in October 2026 ('
                           . self::PHP2SHELL_CVES . '). The PHP project published fixed releases for '
                           . 'the versions it still supports, but not for older ones, so there is no '
                           . 'version number this portal can check against for the version here. Ask '
                           . 'your hosting company whether those fixes have been applied, and ask to '
                           . 'be moved to PHP ' . self::PREFERRED_SERIES . '. The PHP project explains '
                           . 'the problem at ' . self::PHP2SHELL_ADVISORY . '.',
            'advisoryUrl' => self::PHP2SHELL_ADVISORY,
            'cves'        => self::PHP2SHELL_CVES,
        ];
    }

    /**
     * ⚖️ The worse of two states.
     *
     * @param string $a One state word.
     * @param string $b The other.
     *
     * @return string The worse of the two.
     */
    private static function worseOf(string $a, string $b): string
    {
        $rank = ['ok' => 0, 'warn' => 1, 'crit' => 2];
        $aRank = $rank[$a] ?? 1;
        $bRank = $rank[$b] ?? 1;

        return $aRank >= $bRank ? $a : $b;
    }

    /**
     * 🔢 The release line a version belongs to — '8.4.27' gives '8.4'.
     *
     * @param string $version Three-part version number.
     *
     * @return string The first two parts.
     */
    private static function series(string $version): string
    {
        $parts = explode('.', $version);

        if (count($parts) < 2) {
            return $version;
        }

        return $parts[0] . '.' . $parts[1];
    }

    /**
     * 🔬 The version as reported, from its first digit onwards.
     *
     * Everything after the numbers is KEPT — a pre-release marker (`RC3`,
     * `beta1`) and a distribution's packaging label (`-1~deb13u1`) alike —
     * because `version_compare()` uses both to good effect: it ranks a release
     * candidate below its finished release, and a packaged build above the
     * plain version it was built from.
     *
     * Only the leading rubbish is dropped, so that a string like
     * "PHP 8.5.11" does not confuse the comparison.
     *
     * @param string $raw Whatever PHP reported.
     *
     * @return string Something version_compare() can use, or '' if no number
     *                could be found at all.
     */
    private static function comparableVersion(string $raw): string
    {
        if (preg_match('/\d.*$/', $raw, $m) !== 1) {
            return '';
        }

        return trim($m[0]);
    }

    /**
     * 🧪 Is this a test build rather than a finished release?
     *
     * Matches PHP's own pre-release spellings, which is what PHP puts in
     * `PHP_VERSION` for them: `8.6.0alpha1`, `8.6.0beta3`, `8.6.0RC4`, and the
     * `-dev` suffix a build from source carries.
     *
     * This matters for more than tidiness. A pre-release of a release line
     * newer than this file knows about may well PREDATE the October 2026 fix —
     * PHP 8.6's pre-releases began in July 2026 — so it must not be waved
     * through as "newer, therefore fixed".
     *
     * @param string $raw Whatever PHP reported.
     *
     * @return bool True when it names itself a test build.
     */
    private static function looksPreRelease(string $raw): bool
    {
        return preg_match('/\d(?:alpha|beta|RC|dev)|[-_.](?:alpha|beta|RC|dev)\b/i', $raw) === 1;
    }

    /**
     * 🔢 Pull a plain three-part version number out of whatever PHP reports.
     *
     * PHP_VERSION is usually a clean '8.4.27', but a build can append its own
     * label — '8.4.27-1~deb13u1' on Debian, '8.4.27RC1' for a release
     * candidate. Everything after the three numbers is dropped, which is
     * correct for judging: a Debian build of 8.4.27 is 8.4.27 for our purposes.
     *
     * A two-part version ('8.5') is padded with a zero so version_compare has
     * three parts to work with. That padding makes a bare '8.5' compare as
     * '8.5.0', which is the OLDEST release of that line — the cautious reading,
     * and therefore the right one for a security check.
     *
     * @param string $raw Whatever PHP reported.
     *
     * @return string A three-part version number, or '' if none could be read.
     */
    private static function parseVersion(string $raw): string
    {
        if (preg_match('/(\d+)\.(\d+)(?:\.(\d+))?/', $raw, $m) !== 1) {
            return '';
        }

        return $m[1] . '.' . $m[2] . '.' . (isset($m[3]) === true && $m[3] !== '' ? $m[3] : '0');
    }
}
