<?php
// Path: tools/audit-checks/activity-description-probe.php
/**
 * -----------------------------------------------------------------------------
 * What each activity record's WORDS are built from — the reader for
 * check_activity_descriptions_personal.py (#933) 🔎
 * -----------------------------------------------------------------------------
 * Run by the Python check, never on its own in a workflow. Reads every `.php`
 * file under the folder it is given with PHP's OWN tokeniser (never a regular
 * expression: this repository has paid for hand-written PHP readers many
 * times, #494 and #763 among them) and prints ONE JSON document: for every
 * call to `Logger::activity(` (written `Logger::`, `Portal\Core\Logger::` or
 * `\Portal\Core\Logger::`), the names its SECOND argument — the description —
 * is built from.
 *
 * WHAT A "NAME" IS HERE. Every variable (`$fullName`), every array key written
 * as a literal (`$row['emailAddress']` gives `row` AND `emailAddress`), every
 * property (`$user->email` gives `email`), and every function or method called
 * (`clientIp`). The Python side decides which of them are personal.
 *
 * WHAT IS LEFT OUT ON PURPOSE, because it cannot carry readable personal text:
 *   - anything after an `(int)`, `(float)` or `(bool)` cast — the cast's own
 *     operand only (a variable with its subscripts, or one bracketed group);
 *   - the arguments of count(), intval(), strlen(), mb_strlen(),
 *     number_format(), round(), abs(), max(), min() and array_sum(), which
 *     answer a number;
 *   - the arguments of RateLimiter::nameMark(), which turns a sign-in name
 *     into a salted fingerprint (#933) — that is the very cure;
 *   - the arguments of parse_url(..., PHP_URL_HOST), which answers only the
 *     host of an address. Any other parse_url() is NOT left out.
 *
 * ALSO REPORTED: whether the description is EXACTLY one variable that is a
 * parameter of the function or closure the call sits in. Such a call is a
 * pass-through wrapper (`$record = static function (string $type, string
 * $what) { Logger::activity($type, $what); }`), and what reaches it is decided
 * by its CALLERS, which this probe does not follow. The Python side requires
 * every such wrapper to be named, so a new one is a decision rather than a
 * hole.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/933
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

if ($argc < 2 || is_dir($argv[1]) === false) {
    fwrite(STDERR, "usage: php activity-description-probe.php <web folder>\n");
    exit(2);
}
$root = rtrim((string) realpath($argv[1]), DIRECTORY_SEPARATOR);

/** Function names whose arguments become a number, so cannot carry text. */
const NUMBER_FUNCTIONS = ['count', 'intval', 'strlen', 'mb_strlen', 'number_format', 'round', 'abs', 'max', 'min', 'array_sum'];

/** Tokens that are not code. */
function isNoise(mixed $t): bool
{
    return is_array($t) === true && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
}

/** The text of one token. */
function textOf(mixed $t): string
{
    return is_array($t) === true ? $t[1] : (string) $t;
}

/**
 * Index just after the bracketed group that opens at $i (which must be an
 * opening bracket). Counts (), [] and {} together, plus the two curly-opening
 * tokens PHP uses inside double-quoted strings.
 */
function skipGroup(array $toks, int $i): int
{
    $depth = 0;
    $n     = count($toks);
    for (; $i < $n; $i++) {
        $t = $toks[$i];
        if (in_array(textOf($t), ['(', '[', '{'], true) === true
            || (is_array($t) === true && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true) === true)) {
            $depth++;
        } elseif (in_array(textOf($t), [')', ']', '}'], true) === true) {
            $depth--;
            if ($depth === 0) {
                return $i + 1;
            }
        }
    }

    return $n;
}

/** Next index at or after $i that is code. */
function nextCode(array $toks, int $i): int
{
    $n = count($toks);
    while ($i < $n && isNoise($toks[$i]) === true) {
        $i++;
    }

    return $i;
}

/**
 * Index just after one cast operand starting at $i: a variable with every
 * subscript and property after it, or one bracketed group, or one literal.
 */
function skipOperand(array $toks, int $i): int
{
    $i = nextCode($toks, $i);
    $n = count($toks);
    if ($i >= $n) {
        return $n;
    }
    if (textOf($toks[$i]) === '(') {
        return skipGroup($toks, $i);
    }
    $i++;
    while (true) {
        $j = nextCode($toks, $i);
        if ($j < $n && textOf($toks[$j]) === '[') {
            $i = skipGroup($toks, $j);
            continue;
        }
        if ($j < $n && is_array($toks[$j]) === true && in_array($toks[$j][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true) === true) {
            $i = nextCode($toks, $j + 1) + 1;
            continue;
        }

        return $i;
    }
}

/**
 * The names one description is built from.
 *
 * @return list<string>
 */
function namesIn(array $toks): array
{
    $names = [];
    $n     = count($toks);
    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];
        if (is_array($t) === false) {
            continue;
        }
        if (in_array($t[0], [T_INT_CAST, T_DOUBLE_CAST, T_BOOL_CAST], true) === true) {
            $i = skipOperand($toks, $i + 1) - 1;
            continue;
        }
        if ($t[0] === T_VARIABLE) {
            $names[] = substr($t[1], 1);
            continue;
        }
        if ($t[0] === T_STRING_VARNAME) {
            $names[] = $t[1];
            continue;
        }
        if ($t[0] === T_CONSTANT_ENCAPSED_STRING || $t[0] === T_STRING) {
            // A literal key: $row['emailAddress'] — the literal right after '['.
            $p = $i - 1;
            while ($p >= 0 && isNoise($toks[$p]) === true) {
                $p--;
            }
            if ($p >= 0 && textOf($toks[$p]) === '[') {
                $names[] = trim($t[1], '\'"');
                continue;
            }
        }
        if ($t[0] === T_STRING) {
            $p = $i - 1;
            while ($p >= 0 && isNoise($toks[$p]) === true) {
                $p--;
            }
            $after = nextCode($toks, $i + 1);
            $isCall = $after < $n && textOf($toks[$after]) === '(';
            $viaArrow = $p >= 0 && is_array($toks[$p]) === true && in_array($toks[$p][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true) === true;
            $viaColons = $p >= 0 && is_array($toks[$p]) === true && $toks[$p][0] === T_DOUBLE_COLON;
            if ($isCall === true) {
                $fn = strtolower($t[1]);
                if (($viaArrow === false && $viaColons === false && in_array($fn, NUMBER_FUNCTIONS, true) === true)
                    || ($viaColons === true && $fn === 'namemark')) {
                    $i = skipGroup($toks, $after) - 1;
                    continue;
                }
                if ($viaArrow === false && $viaColons === false && $fn === 'parse_url') {
                    $end  = skipGroup($toks, $after);
                    $inside = '';
                    for ($k = $after; $k < $end; $k++) {
                        $inside .= textOf($toks[$k]);
                    }
                    if (preg_match('/,\s*\\\\?PHP_URL_HOST\s*\)$/', $inside) === 1) {
                        $i = $end - 1;
                        continue;
                    }
                }
                $names[] = $t[1];
                continue;
            }
            if ($viaArrow === true) {
                $names[] = $t[1]; // a property: $user->email
            }
        }
    }

    return $names;
}

$out   = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $path => $info) {
    if ($info->isFile() === false || strtolower($info->getExtension()) !== 'php') {
        continue;
    }
    $rel  = str_replace(DIRECTORY_SEPARATOR, '/', substr((string) $path, strlen($root) + 1));
    $toks = token_get_all((string) file_get_contents((string) $path));
    $n    = count($toks);

    // 🧭 Functions and closures: where each body starts and ends, and its
    //    parameters, so a call can be placed in the innermost one.
    $scopes = [];
    for ($i = 0; $i < $n; $i++) {
        if (is_array($toks[$i]) === false || in_array($toks[$i][0], [T_FUNCTION, T_FN], true) === false) {
            continue;
        }
        $open = $i + 1;
        while ($open < $n && textOf($toks[$open]) !== '(') {
            $open++;
        }
        $close  = skipGroup($toks, $open);
        $params = [];
        for ($k = $open; $k < $close; $k++) {
            if (is_array($toks[$k]) === true && $toks[$k][0] === T_VARIABLE) {
                $params[] = substr($toks[$k][1], 1);
            }
        }
        if ($toks[$i][0] === T_FN) {
            // An arrow function has no braces: its body runs from `=>` to the
            // first `;` or `,` (or unmatched closing bracket) at its own depth.
            $arrow = $close;
            while ($arrow < $n && (is_array($toks[$arrow]) === false || $toks[$arrow][0] !== T_DOUBLE_ARROW)) {
                $arrow++;
            }
            $depth = 0;
            for ($k = $arrow + 1; $k < $n; $k++) {
                $s = textOf($toks[$k]);
                if (in_array($s, ['(', '[', '{'], true) === true) { $depth++; }
                if (in_array($s, [')', ']', '}'], true) === true) { if ($depth === 0) { break; } $depth--; }
                if (($s === ';' || $s === ',') && $depth === 0) { break; }
            }
            $end = $k;
            $scopes[] = ['start' => $arrow, 'end' => $end, 'params' => $params, 'name' => 'fn'];
            continue;
        }
        $body = $close;
        while ($body < $n && textOf($toks[$body]) !== '{' && textOf($toks[$body]) !== ';') {
            $body++;
        }
        if ($body >= $n || textOf($toks[$body]) === ';') {
            continue; // an abstract or interface method: no body
        }
        $name = 'closure';
        $nm   = nextCode($toks, $i + 1);
        if ($nm < $n && is_array($toks[$nm]) === true && $toks[$nm][0] === T_STRING) {
            $name = $toks[$nm][1];
        }
        $scopes[] = ['start' => $body, 'end' => skipGroup($toks, $body), 'params' => $params, 'name' => $name];
    }

    for ($i = 0; $i < $n; $i++) {
        if (is_array($toks[$i]) === false || $toks[$i][0] !== T_STRING || $toks[$i][1] !== 'activity') {
            continue;
        }
        $p = $i - 1;
        while ($p >= 0 && isNoise($toks[$p]) === true) {
            $p--;
        }
        if ($p < 1 || is_array($toks[$p]) === false || $toks[$p][0] !== T_DOUBLE_COLON) {
            continue;
        }
        $c = $p - 1;
        while ($c >= 0 && isNoise($toks[$c]) === true) {
            $c--;
        }
        if (preg_match('/(^|\\\\)Logger$/', textOf($toks[$c])) !== 1) {
            continue;
        }
        $open = nextCode($toks, $i + 1);
        if ($open >= $n || textOf($toks[$open]) !== '(') {
            continue;
        }
        // The arguments, split at top-level commas.
        $args  = [[]];
        $depth = 0;
        for ($k = $open; $k < $n; $k++) {
            $t = $toks[$k];
            $s = textOf($t);
            $opens = in_array($s, ['(', '[', '{'], true) === true
                || (is_array($t) === true && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true) === true);
            if ($opens === true) {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif (in_array($s, [')', ']', '}'], true) === true) {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($s === ',' && $depth === 1) {
                $args[] = [];
                continue;
            }
            if (isNoise($t) === true) {
                continue;
            }
            $args[count($args) - 1][] = $t;
        }
        $desc  = $args[1] ?? [];
        $scope = null;
        foreach ($scopes as $sc) {
            if ($sc['start'] < $i && $i < $sc['end'] && ($scope === null || $sc['start'] > $scope['start'])) {
                $scope = $sc;
            }
        }
        $bare = count($desc) === 1 && is_array($desc[0]) === true && $desc[0][0] === T_VARIABLE;
        $out[] = [
            'file'        => $rel,
            'line'        => $toks[$i][2],
            'names'       => array_values(array_unique(namesIn($desc))),
            'passThrough' => $bare === true && $scope !== null && in_array(substr($desc[0][1], 1), $scope['params'], true) === true,
            'scope'       => $scope === null ? '' : $scope['name'],
        ];
    }
}

echo json_encode(['calls' => $out], JSON_UNESCAPED_SLASHES), "\n";
