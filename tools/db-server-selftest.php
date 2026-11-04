<?php
// Path: tools/db-server-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Database version reading self-test 🛢️ (#475)
 * -----------------------------------------------------------------------------
 * Standalone and dependency-free — no database, no start-up code, no network.
 * It runs the real Portal\Core\DbServer class against a list of version strings
 * that real database servers actually report, and checks the answer.
 *
 * WHY THIS IS WORTH HAVING
 * ------------------------
 * Getting this wrong fails silently and looks fine. Two traps in particular:
 *
 *   1. MariaDB sometimes puts a fake `5.5.5-` on the front of its version
 *      string, purely so that very old MySQL client programs will agree to
 *      talk to it. Read that literally and every modern MariaDB install looks
 *      like ancient MySQL 5.5 — so the installation wizard would refuse to run
 *      on a perfectly good server, and nobody would understand why.
 *
 *   2. The version number decides whether the wizard STOPS or merely warns.
 *      A rule that is one step out in either direction either blocks a working
 *      install or lets a doomed one start and fail half-way through, leaving a
 *      part-built database behind.
 *
 * Neither mistake throws an error. Only a test like this catches them.
 *
 * The version strings below are real formats, not invented ones — Ubuntu's
 * packaged MySQL, MariaDB with and without the compatibility prefix, Percona
 * Server, and the two-part version some builds report.
 *
 * Usage:  php tools/db-server-selftest.php
 * Exit:   0 if every check passes, 1 if any check fails.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/475
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require __DIR__ . '/../web/_core/DbServer.php';

use Portal\Core\DbServer;

// -----------------------------------------------------------------------------
// 📋 The cases. Each one is: what VERSION() said, what @@version_comment said,
//    then the three things we expect back — product name, version number, and
//    whether it is fine ('ok'), worth mentioning ('warn'), or too old to run
//    on at all ('crit').
// -----------------------------------------------------------------------------

$cases = [
    // --- MySQL 8.0. One of the lines tested here, not the only one: since
    //     3 November 2026 the migration harness runs on 8.0, 8.4 and 9.7, and on
    //     MariaDB 11.4 and 12.3 (#475). This comment used to say 8.0 was "the
    //     line everything here is tested against", which is the same stale
    //     sentence DbServer::TESTED_LINES exists to stop. Supported by this
    //     portal, but Oracle stopped issuing security fixes in April 2026, so
    //     it warns rather than passing silently.
    ['8.0.36', 'MySQL Community Server - GPL', 'MySQL', '8.0.36', 'warn'],

    // Ubuntu and Debian append their own packaging version. The numbers after
    // the first three must be ignored, or this reads as version 22.
    ['8.0.36-0ubuntu0.22.04.1', '', 'MySQL', '8.0.36', 'warn'],

    // --- MySQL long-term releases: the versions to move to.
    ['8.4.3', 'MySQL Community Server - GPL', 'MySQL', '8.4.3', 'ok'],
    ['9.7.0', 'MySQL Community Server - GPL', 'MySQL', '9.7.0', 'ok'],

    // --- The short-lived 8.1/8.2/8.3 releases. Each was replaced within about
    //     three months and none is supported now, so they warn.
    ['8.2.0', 'MySQL Community Server - GPL', 'MySQL', '8.2.0', 'warn'],

    // --- THE TRAP A REVIEW CAUGHT. A first version of this check said "8.4 or
    //     newer is fine", which told anyone on 9.0 they were still getting
    //     security fixes. They are not: 9.0 through 9.6 are short-lived
    //     releases, each replaced within months. Only 9.7 is long-term. A
    //     higher number is NOT automatically better supported, and these two
    //     cases exist to stop that rule ever coming back.
    ['9.0.1', 'MySQL Community Server - GPL', 'MySQL', '9.0.1', 'warn'],
    ['9.6.0', 'MySQL Community Server - GPL', 'MySQL', '9.6.0', 'warn'],

    // --- Newer than anything this code knows about. Must NOT warn — being
    //     newer than our list is not evidence of a problem — but the wording
    //     must not claim to know its support status either.
    ['10.2.0', 'MySQL Community Server - GPL', 'MySQL', '10.2.0', 'ok'],

    // --- Too old to run this portal at all. This is the ONLY case where the
    //     installation wizard stops rather than warning.
    ['5.7.44', 'MySQL Community Server (GPL)', 'MySQL', '5.7.44', 'crit'],

    // --- MariaDB WITH the fake compatibility prefix. If the prefix is not
    //     stripped this reads as MySQL 5.5.5 and is wrongly refused. This is
    //     the single most important case in this file.
    [
        '5.5.5-10.11.6-MariaDB-1:10.11.6+maria~ubu2204',
        'mariadb.org binary distribution',
        'MariaDB',
        '10.11.6',
        'ok',
    ],

    // --- The same MariaDB without the prefix, which is what a direct
    //     connection usually sees. Must reach the identical verdict.
    ['10.11.6-MariaDB', 'mariadb.org binary distribution', 'MariaDB', '10.11.6', 'ok'],

    // --- MariaDB long-term releases.
    ['11.4.2-MariaDB', 'mariadb.org binary distribution', 'MariaDB', '11.4.2', 'ok'],
    ['12.3.2-MariaDB', 'mariadb.org binary distribution', 'MariaDB', '12.3.2', 'ok'],
    ['12.3.1-MariaDB', 'mariadb.org binary distribution', 'MariaDB', '12.3.1', 'warn'],

    // --- The 10.6 line runs this portal fine, but its maintenance ended in
    //     July 2026, so it warns rather than passing silently.
    ['10.6.18-MariaDB', 'mariadb.org binary distribution', 'MariaDB', '10.6.18', 'warn'],

    // --- A short-lived MariaDB line between two long-term ones.
    ['11.2.3-MariaDB', 'mariadb.org binary distribution', 'MariaDB', '11.2.3', 'warn'],

    // --- Preview and release-candidate builds. Both projects publish these
    //     under the SAME version number as the finished release that follows,
    //     so the number alone cannot tell them apart. A server running a trial
    //     build must not be told it is on something supported.
    ['11.4.0-MariaDB-rc', 'mariadb.org binary distribution', 'MariaDB', '11.4.0', 'warn'],
    ['11.4.0-MariaDB-preview', 'mariadb.org binary distribution', 'MariaDB', '11.4.0', 'warn'],
    ['9.7.0-rc', 'MySQL Community Server - GPL', 'MySQL', '9.7.0', 'warn'],
    ['8.4.0-beta', 'MySQL Community Server - GPL', 'MySQL', '8.4.0', 'warn'],

    // --- But an ordinary release whose packaging string merely CONTAINS those
    //     letters inside a longer word must NOT be mistaken for a preview.
    ['8.4.3-1.el9', 'MySQL Community Server - GPL', 'MySQL', '8.4.3', 'ok'],

    // --- Pre-release builds that carry NO marker in the version string. This
    //     is the case a word-matching check cannot catch: MariaDB 11.4.0 and
    //     11.4.1 were release candidates of a supported line, published under
    //     ordinary-looking version numbers. Only knowing the first finished
    //     release of the line (11.4.2) separates them.
    ['11.4.0-MariaDB', 'mariadb.org binary distribution', 'MariaDB', '11.4.0', 'warn'],
    ['11.4.1-MariaDB', 'mariadb.org binary distribution', 'MariaDB', '11.4.1', 'warn'],
    ['10.11.1-MariaDB', 'mariadb.org binary distribution', 'MariaDB', '10.11.1', 'warn'],
    ['11.8.1-MariaDB', 'mariadb.org binary distribution', 'MariaDB', '11.8.1', 'warn'],
    ['11.8.2-MariaDB', 'mariadb.org binary distribution', 'MariaDB', '11.8.2', 'ok'],

    // --- ...and the first finished build of that same line must pass.
    ['11.4.2-MariaDB', 'mariadb.org binary distribution', 'MariaDB', '11.4.2', 'ok'],
    ['10.11.2-MariaDB', 'mariadb.org binary distribution', 'MariaDB', '10.11.2', 'ok'],

    // --- MariaDB too old for this portal's database changes.
    ['10.3.39-MariaDB', 'mariadb.org binary distribution', 'MariaDB', '10.3.39', 'crit'],

    // --- Percona Server is a MySQL fork and uses MySQL's own version numbers,
    //     so it is judged by the MySQL rules. The trailing release number is
    //     Percona's own and is not part of the MySQL version.
    ['8.0.36-28', 'Percona Server (GPL), Release 28', 'Percona Server', '8.0.36', 'warn'],

    // --- Some builds report only two parts. Treat as x.y.0 rather than
    //     failing to read it at all.
    ['11.4-MariaDB', 'mariadb.org binary distribution', 'MariaDB', '11.4.0', 'ok'],

    // --- PACKAGING SUFFIXES ON A TWO-PART VERSION. The nastiest case found in
    //     this whole exercise. Linux distributions append their own packaging
    //     version, so a server can report 11.4-MariaDB-0ubuntu0.24.04.1. There
    //     is no three-part number at the FRONT of that, and a search that looked
    //     anywhere in the string found "0.24.04" in Ubuntu's suffix instead —
    //     version zero, below every minimum, so the installer refused to install
    //     onto a perfectly good MariaDB 11.4 and gave a reason that made no
    //     sense. The version must be read from the front and nowhere else.
    ['11.4-MariaDB-0ubuntu0.24.04.1', 'mariadb.org binary distribution', 'MariaDB', '11.4.0', 'ok'],
    ['10.11-MariaDB-1:10.11+maria~deb12', 'mariadb.org binary distribution', 'MariaDB', '10.11.0', 'ok'],
    ['8.0-0ubuntu0.22.04.1', '', 'MySQL', '8.0.0', 'warn'],

    // --- Nothing readable. Must NOT guess a product, and must never block.
    ['', '', 'Unknown', '', 'warn'],
    ['some-custom-build', 'A database we have never heard of', 'Unknown', '', 'warn'],
];

// -----------------------------------------------------------------------------
// 🔗 A check on the CODE ITSELF, not on any one version string.
// -----------------------------------------------------------------------------
// Three separate reviews found the same fault three times: a release line was
// added to the list of supported ones, and nobody added its first finished
// release to the other table. Each time, a release candidate of that line was
// then reported as a supported version.
//
// Adding the missing row fixes it once. This stops it happening a fourth time,
// by failing here the moment the two tables disagree. It is the only test in
// this file that checks the shape of the code rather than an answer it gives.
// -----------------------------------------------------------------------------

$structuralFailures = 0;

foreach (DbServer::MARIADB_SUPPORTED_SERIES as $series) {
    if (array_key_exists($series, DbServer::MARIADB_FIRST_STABLE) === false) {
        printf(
            "  FAIL  MariaDB %s is listed as supported, but no first finished release is\n"
            . "        recorded for it. Until one is, a release candidate of that line will\n"
            . "        be reported as a supported version. Add it to MARIADB_FIRST_STABLE.\n",
            $series
        );
        $structuralFailures++;
    }
}

if ($structuralFailures === 0) {
    echo "  PASS  every supported MariaDB line has a first finished release recorded\n\n";
}

// -----------------------------------------------------------------------------
// 🏃 Run them.
// -----------------------------------------------------------------------------

$failures = $structuralFailures;
$passes   = 0;

echo "Database version reading — self-test\n";
echo str_repeat('=', 78) . "\n\n";

foreach ($cases as [$raw, $comment, $wantEngine, $wantVersion, $wantState]) {
    $got = DbServer::classify($raw, $comment);

    $problems = [];
    if ($got['engine'] !== $wantEngine) {
        $problems[] = sprintf('product: expected "%s", got "%s"', $wantEngine, $got['engine']);
    }
    if ($got['version'] !== $wantVersion) {
        $problems[] = sprintf('version: expected "%s", got "%s"', $wantVersion, $got['version']);
    }
    if ($got['state'] !== $wantState) {
        $problems[] = sprintf('verdict: expected "%s", got "%s"', $wantState, $got['state']);
    }

    // 📣 Every answer must carry a headline and an explanation. A blank one
    //    would leave the wizard and the admin page showing an empty box.
    if (trim((string) $got['headline']) === '') {
        $problems[] = 'the one-line summary was empty';
    }
    if (trim((string) $got['detail']) === '') {
        $problems[] = 'the explanation was empty';
    }

    // 🍃 THE SENTENCE ABOUT WHAT IS TESTED MUST BE THERE, AND MUST MATCH THE
    //    LINE. Two things were wrong here until 4 November 2026, both found by
    //    an independent check rather than by reading:
    //
    //    1. The comment above this said every MariaDB answer must "admit that
    //       nothing here is tested against MariaDB". That stopped being true on
    //       28 October 2026, when the migration harness began replaying every
    //       database change on MariaDB 11.4 (#487). It was one of five copies
    //       of the same stale claim, and the one the #475 sweep missed.
    //
    //    2. The test itself COULD NOT FAIL. It asked whether
    //       `$got['untested'] !== true`, and `'untested' => true` is set in
    //       `$base` for every MariaDB answer, so for `family === 'mariadb'` the
    //       condition was unfalsifiable — and it was never about the sentence
    //       anyway. Planting `return '';` at the top of `testedLinesCaveat()`
    //       removed the caveat from EVERY answer and this test still passed
    //       36 of 36.
    //
    //    So it now reads the sentence itself, and asks the one question that
    //    matters: does what the customer is told match `TESTED_LINES`? A line
    //    the harness runs on must say so; a line it does not must say THAT.
    //    `TESTED_LINES` is read from the class, never copied here.
    if ($got['family'] === 'mariadb' || $got['family'] === 'mysql') {
        $series   = implode('.', array_slice(explode('.', (string) $got['version']), 0, 2));
        $isTested = in_array(
            $series,
            DbServer::TESTED_LINES[$got['family']] ?? [],
            true
        );
        $detail = (string) $got['detail'];

        // 📌 A `crit` answer is about a version too old to install at all, and
        //    a sentence about what is tested would be noise on it. Likewise the
        //    answers that say nothing about testing by design. So the demand is
        //    made only where the caveat is supposed to appear — which is itself
        //    recorded in DbServer's docblock, deliberately narrowly.
        //    WHICH ANSWERS ARE REQUIRED TO CARRY IT, and the reason each
        //    other one is let off. DbServer's docblock lists the same three.
        //    A PREVIEW BUILD is deliberately exempt: its whole message is "do
        //    not put real data on this, ask for a finished release", and
        //    telling somebody that the finished line is well tested invites
        //    them to stay. A `crit` answer is about a version too old to
        //    install at all, so the sentence would be noise. A database this
        //    class could not identify has no line to speak about.
        $isPreRelease = str_contains($detail, 'marked as a preview or a');
        $shouldCarryIt = $got['state'] !== 'crit'
            && $isPreRelease === false
            && ($got['family'] === 'mariadb' || $isTested === true
                || str_contains($detail, 'reached the end of its support life'));

        if ($shouldCarryIt === true) {
            if ($isTested === true) {
                if (str_contains($detail, 'replay every one of its database changes') === false) {
                    $problems[] = 'a TESTED line (' . $series . ') did not say so: ' . mb_substr($detail, -90);
                }
                if (str_contains($detail, 'no test here proves it') === true) {
                    $problems[] = 'a TESTED line (' . $series . ') claimed nothing had tested it';
                }
            } else {
                if (str_contains($detail, 'do not run on the') === false) {
                    $problems[] = 'an UNTESTED line (' . $series . ') did not say so: ' . mb_substr($detail, -90);
                }
                if (str_contains($detail, 'replay every one of its database changes') === true) {
                    $problems[] = 'an UNTESTED line (' . $series . ') claimed the tests run on it';
                }
            }
        }
    }

    $label = $raw === '' ? '(nothing returned)' : $raw;

    if (count($problems) === 0) {
        $passes++;
        printf("  PASS  %s\n", $label);
        continue;
    }

    $failures++;
    printf("  FAIL  %s\n", $label);
    foreach ($problems as $p) {
        printf("          %s\n", $p);
    }
}

echo "\n" . str_repeat('=', 78) . "\n";
printf("%d passed, %d failed\n", $passes, $failures);

if ($failures > 0) {
    echo "\nSomething about how this portal reads the database version has changed.\n";
    echo "Check web/_core/DbServer.php before releasing.\n";
    exit(1);
}

echo "\nAll good. The database version is read and judged correctly.\n";
exit(0);
