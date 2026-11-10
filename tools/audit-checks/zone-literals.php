<?php
// Path: tools/audit-checks/zone-literals.php
/**
 * -----------------------------------------------------------------------------
 * The PHP half of check_no_built_in_time_zone.py (#782) 🕰️
 * -----------------------------------------------------------------------------
 * Run by that check, never on its own. Given the repository's root, it prints
 * ONE line of JSON:
 *
 *   {"zones": {"europe/london": "Europe/London", ...},
 *    "hits":  [{"file": "web/...", "line": 12, "zone": "Europe/London",
 *               "written": "europe/london "}, ...]}
 *
 * `zones` maps every zone name PHP knows WITH a "/" in it, lower-cased, to
 * its proper spelling, so the JavaScript half can match the same way.
 * `zone` is that proper spelling; `written` is exactly what the code says.
 *
 * WHAT IT REPORTS, in the PHP under `web/`:
 *   1. A zone name with a "/" in it, written as a QUOTED VALUE, in ANY
 *      capitalisation and with spaces round it: a string literal that is the
 *      name ('Europe/London', 'europe/london', ' Europe/London'), or the name
 *      in quotes inside a string or inside HTML outside the PHP tags
 *      (`value="Europe/London"`, `DEFAULT ''Europe/London''`). PHP opens
 *      'europe/london' and ' Europe/London' as London (measured on 8.5), so a
 *      check matching only the proper spelling would let both through.
 *   2. A zone name WITHOUT a "/" ("GB", "Turkey", "EST", "WET", "Japan")
 *      written as a quoted literal IMMEDIATELY after the opening bracket of
 *      `new DateTimeZone(`, `timezone_open(` or `date_default_timezone_set(`,
 *      in any capitalisation and with spaces — UNLESS PHP itself shows that
 *      zone IS UTC (see the measured test below: exactly nine names, "UTC",
 *      "Universal", "Zulu", "GMT" among them). UTC is the portal's deliberate
 *      "nothing is known" answer (Site::ZONE_WHEN_NONE), not a place.
 *
 * WHY RULE 2 IS NARROWER THAN RULE 1, and the measurement behind it. Every
 * one of the 45 names without a "/" is ALSO something else: a country name
 * ("Japan", "Poland"), a country code ("GB", "NZ"), or an abbreviation used in
 * dates and mail headers ("GMT", "EST"). Measured on 10 November 2026 over
 * every string literal under web/: "UTC" 38 times and "GB" 13 times, every
 * "GB" a country code. Reporting every literal "GB" would cry wolf thirteen
 * times on the day this was written (#483: a check that cries wolf is
 * switched off). Passed straight to a zone constructor, only "UTC" appears
 * (30 times), so that position can be checked with nothing to excuse.
 *
 * WHY PHP'S OWN TOKENISER, AND NOT A TEXT SEARCH. Nearly every file that once
 * held such a literal now has a COMMENT recording that it used to ("this used
 * to be "Europe/London", typed in here"), because that is how this project
 * stops the next person reintroducing a fault. A text search reports those
 * comments as the fault they record. `token_get_all()` knows a comment from a
 * string, so comments (T_COMMENT, T_DOC_COMMENT) are never looked at (#763:
 * "when a guard has to understand a language, use that language's own
 * parser").
 *
 * WHAT IT CANNOT SEE, said plainly:
 *   - a zone name without a "/" anywhere EXCEPT immediately after the
 *     opening bracket of one of those three calls: `$zone = 'GB'; new
 *     DateTimeZone($zone);` passes, and so does `?? 'GB'` as a fallback
 *     (rule 2's measurement is why). So do `new DateTimeZone(timezone: 'GB')`
 *     (a NAMED argument) and `new DateTimeZone(('GB'))` (extra brackets),
 *     because the token before the literal is then not the call's bracket;
 *   - a zone name BUILT from pieces ('Europe/' . 'London');
 *   - a zone name read from a file or the database at run time;
 *   - a zone name that is not in quotes (inside prose in a string);
 *   - a zone name passed to a constructor through a constant or a variable.
 * Those are not the shape this fault has taken here, and the self-tests that
 * drive the readers (part N of tools/time-zones-selftest.php) are the cover
 * for behaviour.
 *
 * Exit 0 with the JSON on success; exit 2 with a reason on standard error if
 * the folder cannot be read. Never partial: it either reads every file or says
 * it could not.
 *
 * @package   WebMS-Intra (development tooling — never deployed; only web/ is)
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.2.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/782
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$root = (string) ($argv[1] ?? '');
$web  = $root . DIRECTORY_SEPARATOR . 'web';
if ($root === '' || is_dir($web) === false) {
    fwrite(STDERR, "zone-literals.php: give the repository's root folder; no web/ folder was found there.\n");
    exit(2);
}

// 🌍 Every zone name PHP knows, legacy names included, keyed lower-case.
//    $withSlash: rule 1. $withoutSlash: rule 2, minus the names that ARE UTC.
$withSlash    = [];
$withoutSlash = [];
foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC) as $name) {
    if (str_contains($name, '/') === true) {
        $withSlash[strtolower($name)] = $name;
        continue;
    }
    // "IS UTC ITSELF", measured with PHP on 10 November 2026 rather than
    // guessed: offset 0 in January AND July, shown as UTC, UCT or GMT (or a
    // plain +00:00 offset), and NO past or future offset other than 0. That
    // passes exactly nine names — UTC, UCT, Universal, Zulu, GMT, GMT0, GMT+0,
    // GMT-0, Greenwich — in any capitalisation. The first version asked only
    // "offset 0 twice and at most one transition", and that let through:
    //   - "WET": PHP reads it as a FIXED abbreviation at +00, so a developer
    //     meaning Western Europe silently gets UTC all year; and
    //     getTransitions() answers false, which `count((array) false)` counts
    //     as 1;
    //   - "Factory": offset 0 but shown "-00", which means "place unknown".
    // "Iceland" fails the last part (it once had an offset of -968 seconds):
    // it is a place that happens to be on GMT today, not UTC.
    $zone  = new DateTimeZone($name);
    $steps = $zone->getTransitions(-2147483648, 4102444800);
    $isUtcItself = true;
    foreach (['2026-01-15 12:00:00', '2026-07-15 12:00:00'] as $moment) {
        $at = (new DateTimeImmutable($moment, new DateTimeZone('UTC')))->setTimezone($zone);
        if ($at->getOffset() !== 0 || in_array($at->format('T'), ['UTC', 'UCT', 'GMT', 'GMT+0000'], true) === false) {
            $isUtcItself = false;
        }
    }
    if (is_array($steps) === true) {
        foreach ($steps as $step) {
            if ((int) $step['offset'] !== 0) {
                $isUtcItself = false;
            }
        }
    }
    if ($isUtcItself === false) {
        $withoutSlash[strtolower($name)] = $name;
    }
}

// The three calls rule 2 looks inside, lower-case, without a leading "\".
$zoneCalls = ['datetimezone' => true, 'timezone_open' => true, 'date_default_timezone_set' => true];

// A zone name with a "/" in quotes INSIDE a longer piece of text. The quotes
// must MATCH; spaces inside them are allowed and the name is checked against
// $withSlash, case-insensitively, below.
$quotedPattern = '~(["\'])\s*([A-Za-z]+(?:/[A-Za-z0-9_+\-]+)+)\s*\1~';

$hits = [];
try {
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($web, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($walk as $file) {
        if ($file->isFile() === false || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        $code = file_get_contents($path);
        if ($code === false) {
            fwrite(STDERR, "zone-literals.php: could not read {$path}\n");
            exit(2);
        }
        $rel = 'web/' . str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($web) + 1));
        // The code without what cannot change its meaning, so "the token
        // before this one" is the real one: `new \DateTimeZone ( 'GB' )`.
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn ($t): bool => is_array($t) === false || in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === false
        ));
        foreach ($tokens as $i => $token) {
            if (is_array($token) === false) {
                continue;
            }
            [$kind, $text, $line] = $token;
            if ($kind !== T_CONSTANT_ENCAPSED_STRING && $kind !== T_ENCAPSED_AND_WHITESPACE && $kind !== T_INLINE_HTML) {
                continue;
            }
            if ($kind === T_CONSTANT_ENCAPSED_STRING) {
                $inner = substr($text, 1, -1);
                $key   = strtolower(trim($inner));
                // Rule 1: a literal that IS a zone name with a "/".
                if (isset($withSlash[$key]) === true) {
                    $hits[] = ['file' => $rel, 'line' => $line, 'zone' => $withSlash[$key], 'written' => $inner];
                    continue;
                }
                // Rule 2: a zone name without a "/", passed straight to a zone call.
                if (isset($withoutSlash[$key]) === true && $i >= 2 && $tokens[$i - 1] === '(' && is_array($tokens[$i - 2]) === true) {
                    $callee = strtolower(ltrim((string) $tokens[$i - 2][1], '\\'));
                    $callee = (string) substr($callee, (int) strrpos('\\' . $callee, '\\'));
                    if (isset($zoneCalls[$callee]) === true) {
                        $hits[] = ['file' => $rel, 'line' => $line, 'zone' => $withoutSlash[$key], 'written' => $inner];
                        continue;
                    }
                }
            }
            // Rule 1 again: a zone name in quotes INSIDE the text — HTML outside
            // the PHP tags, SQL in a string, a heredoc. The line is counted from
            // the token's start.
            if (preg_match_all($quotedPattern, $text, $found, PREG_OFFSET_CAPTURE) === false) {
                fwrite(STDERR, "zone-literals.php: the search could not run on {$rel}\n");
                exit(2);
            }
            foreach ($found[2] as [$name, $offset]) {
                $key = strtolower($name);
                if (isset($withSlash[$key]) === true) {
                    $hits[] = [
                        'file' => $rel, 'line' => $line + substr_count(substr($text, 0, $offset), "\n"),
                        'zone' => $withSlash[$key], 'written' => $name,
                    ];
                }
            }
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'zone-literals.php: the folder could not be walked: ' . $e->getMessage() . "\n");
    exit(2);
}

echo json_encode(['zones' => $withSlash, 'hits' => $hits], JSON_UNESCAPED_SLASHES), "\n";
exit(0);
