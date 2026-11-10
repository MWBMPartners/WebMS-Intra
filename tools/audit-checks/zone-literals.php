<?php
// Path: tools/audit-checks/zone-literals.php
/**
 * -----------------------------------------------------------------------------
 * The PHP half of check_no_built_in_time_zone.py (#782) 🕰️
 * -----------------------------------------------------------------------------
 * Run by that check, never on its own. Given the repository's root, it prints
 * ONE line of JSON:
 *
 *   {"zones": [...every zone name PHP knows that has a "/" in it...],
 *    "hits":  [{"file": "web/...", "line": 12, "zone": "Europe/London"}, ...]}
 *
 * `hits` lists every zone name written as a QUOTED VALUE in the PHP under
 * `web/`: a string literal that is exactly a zone name ('Europe/London'), or a
 * zone name in quotes INSIDE a string or inside HTML outside the PHP tags
 * (`value="Europe/London"`, `DEFAULT ''Europe/London''`).
 *
 * WHY PHP'S OWN TOKENISER, AND NOT A TEXT SEARCH. Nearly every file that once
 * held such a literal now has a COMMENT recording that it used to ("this used
 * to be "Europe/London", typed in here"), because that is how this project
 * stops the next person reintroducing a fault. A text search reports those
 * comments as the fault they record. `token_get_all()` knows a comment from a
 * string, so comments (T_COMMENT, T_DOC_COMMENT) are simply never looked at.
 * This repository has learned the same lesson the hard way more than once
 * (#763: "when a guard has to understand a language, use that language's own
 * parser").
 *
 * WHY "A ZONE NAME PHP KNOWS" AND NOT A PATTERN. A pattern such as
 * Europe/[A-Za-z_]+ also matches a file path or prose; the list of real zone
 * names, legacy ones included (DateTimeZone::ALL_WITH_BC, so "US/Eastern" and
 * "GB" style names a customer might once have typed), matches only what it
 * should. Names without a "/" ("UTC", "GMT") are left out on purpose: UTC is
 * the portal's deliberate "nothing is known" answer (Site::ZONE_WHEN_NONE),
 * not one customer's place.
 *
 * WHAT IT CANNOT SEE, said plainly: a zone name BUILT from pieces
 * ('Europe/' . 'London'), one read from a file at run time, and one that is
 * not in quotes at all (inside prose in a string with no quotes round it).
 * Those are not the shape this fault has ever taken here.
 *
 * Exit 0 with the JSON on success; exit 2 with a reason on standard error if
 * the folder cannot be read. Never partial: it either reads every file or says
 * it could not.
 *
 * @package   WebMS-Intra (development tooling — never deployed; only web/ is)
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
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

// 🌍 Every zone name with a "/" in it, legacy names included.
$zones = [];
foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC) as $name) {
    if (str_contains($name, '/') === true) {
        $zones[$name] = true;
    }
}

// A zone name in quotes inside a longer piece of text. The quotes must MATCH
// and the name must be a real one, checked against $zones below.
$quotedPattern = '~(["\'])([A-Za-z]+(?:/[A-Za-z0-9_+\-]+)+)\1~';

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
        foreach (token_get_all($code) as $token) {
            if (is_array($token) === false) {
                continue;
            }
            [$kind, $text, $line] = $token;
            if ($kind !== T_CONSTANT_ENCAPSED_STRING && $kind !== T_ENCAPSED_AND_WHITESPACE && $kind !== T_INLINE_HTML) {
                continue;
            }
            // A literal that IS a zone name: 'Europe/London' or "Europe/London".
            if ($kind === T_CONSTANT_ENCAPSED_STRING) {
                $inner = substr($text, 1, -1);
                if (isset($zones[$inner]) === true) {
                    $hits[] = ['file' => $rel, 'line' => $line, 'zone' => $inner];
                    continue;
                }
            }
            // A zone name in quotes INSIDE the text: HTML outside the PHP tags,
            // SQL in a string, a heredoc. The line is counted from the token's start.
            if (preg_match_all($quotedPattern, $text, $found, PREG_OFFSET_CAPTURE) === false) {
                fwrite(STDERR, "zone-literals.php: the search could not run on {$rel}\n");
                exit(2);
            }
            foreach ($found[2] as [$name, $offset]) {
                if (isset($zones[$name]) === true) {
                    $hits[] = ['file' => $rel, 'line' => $line + substr_count(substr($text, 0, $offset), "\n"), 'zone' => $name];
                }
            }
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'zone-literals.php: the folder could not be walked: ' . $e->getMessage() . "\n");
    exit(2);
}

echo json_encode(['zones' => array_keys($zones), 'hits' => $hits], JSON_UNESCAPED_SLASHES), "\n";
exit(0);
