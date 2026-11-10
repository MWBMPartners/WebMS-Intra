<?php
// Path: tools/audit-checks/redirect-targets-structure.php
/**
 * -----------------------------------------------------------------------------
 * Ask PHP which "send the browser to …" headers have a request value written
 * straight into them 🔍🔀 (#620)
 * -----------------------------------------------------------------------------
 * `check_redirect_targets_judged.py` asks ONE question of every PHP file under
 * `web/`, and this file answers it from PHP's own tokeniser:
 *
 *   In each `header(...)` call whose header text — the argument named
 *   `header:` if there is one, else the first argument — holds a fixed text
 *   beginning "Location:" (any capitals, spaces allowed before the colon),
 *   does a REQUEST VALUE appear in that argument anywhere but the FIRST
 *   argument of `Auth::safeRedirectUrl( … )` or of a harmless wrapper?
 *
 * A request value is the token `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE` or
 * `$_FILES` (also written `${_GET[…]}` inside a string), or `$_SERVER` with no
 * key, with a key that is not exactly ONE fixed text, or with one of the keys
 * set from the request (RT_SERVER_KEYS: `HTTP_…`, `REDIRECT_…`, `REQUEST_URI`,
 * `QUERY_STRING`, `PATH_INFO`, `ORIG_PATH_INFO`, `PATH_TRANSLATED`,
 * `PHP_SELF`, `SCRIPT_URL`, `SCRIPT_URI`, `SERVER_NAME` (the Host header under
 * Apache's `UseCanonicalName Off`), `UNENCODED_URL`, `argv`). It counts
 * wherever it is written in the argument, a "…{$_GET['x']}…" string or a
 * heredoc included.
 *
 * THE JUDGE is recognised only as `<class>::safeRedirectUrl(` (the method's
 * name in any capitals, as PHP reads it) where <class> RESOLVES to
 * `Portal\Core\Auth` through the file's own `namespace` and `use` lines (an
 * alias and a group `use` included), or is `self` / `static` inside the class
 * `Portal\Core\Auth` itself. A page's own function or class method of the
 * same name is not the judge.
 *
 * ONLY THE FIRST ARGUMENT IS COVERED, of the judge and of every wrapper. The
 * judge hands back its SECOND argument (the fallback) unchanged, so
 * `safeRedirectUrl(null, $_GET['u'])` sends the visitor anywhere; and
 * `http_build_query()` puts its second (a prefix) and third (a separator)
 * into the answer as they are — `http_build_query([0 => 'x'], '//evil.com/')`
 * is "//evil.com/0=x". A comma inside the bracket ends the cover.
 *
 * THE HARMLESS WRAPPERS, none of which can produce an address that leaves the
 * site from its first argument: an `(int)`, `(float)` or `(bool)` cast, the
 * built-in `intval()`, `urlencode()`, `rawurlencode()`, `http_build_query()`,
 * and `isset()` and `empty()`. Written as a method (`Other::urlencode(`,
 * `$o->urlencode(`), after `new`, or under a name this file brings in with
 * `use function` or declares itself, it is NOT the built-in and does not
 * count; `\urlencode(` always is the built-in. ("Inside the brackets" is read
 * from the brackets alone; nothing about the value is worked out.)
 *
 * WHY ONLY THAT (D-065, and #902's check, which took three rounds to learn
 * it). The first version of this file followed a value through assignments
 * and expressions to decide whether it reached the address. It cried wolf on
 * correct code — an allow-list written with `in_array()`, `match` or
 * `str_starts_with()`, a `count()` joined after `Site::url()` — and its own
 * self-test passed with one of its rules loosened. A gate that cries wolf is
 * switched off (#483). So this asks nothing about where a value GOES: it
 * reports a request value WRITTEN INTO the header text, and nothing else.
 * Whether the three pages of #620 really refuse what they should is
 * BEHAVIOUR, and is driven over HTTP by `tools/come-back-addresses-selftest.php`.
 *
 * WHAT IT REPORTS THAT IS HARMLESS, said plainly: ANY request value written
 * into the header text outside those wrappers, harmless or not — a test
 * (`$_GET['x'] === 'a'`), `count()`, `strlen()`, `sprintf('%d', …)` alike —
 * because telling them apart would mean reading the expression. Measured on
 * 10 November 2026: no header in `web/` has any such shape (1,272 Location
 * headers, none holding a request value at all). Work the value out above the
 * header, or name the call in the check's NOT_A_COME_BACK_ADDRESS.
 *
 * WHAT IT CANNOT SEE — each MEASURED as a self-test case that must stay green,
 * so the day it starts seeing one it says so:
 *   - a request value put into a VARIABLE first (`$x = $_GET['u']; header(
 *     'Location: ' . $x);`, and `$r = '/' . $_GET['u']` likewise) — two of the
 *     three pages #620 fixed had that shape; this file does not follow values;
 *   - a request value returned by a function or helper, or passed in as a
 *     function's parameter, or kept in an array element;
 *   - the WHOLE header text in a variable (`header($h)`);
 *   - a header name split across two fixed texts (`'Loc' . 'ation: '`);
 *   - a heredoc holding a variable (the variable is not followed);
 *   - a `Refresh` header;
 *   - `header()` called through `call_user_func()` or a variable (`$f(…)`);
 *   - `urlencode()` / `intval()` in a namespaced file, where they may be that
 *     namespace's OWN function declared in ANOTHER file (only this file's own
 *     declarations and `use function` lines are read).
 * DECLARED, NOT MEASURED: `$GLOBALS['_GET']`, `$_ENV`, `extract()`, variable
 * variables, a property, `filter_input()`, `getenv()`, a request body read
 * from `php://input`, `$_SESSION` (a value an earlier request put there), a
 * `$_SERVER` key set from the request that is NOT on RT_SERVER_KEYS (for
 * example one a host's own `SetEnvIf` rule copies from a header), a
 * `<meta http-equiv="refresh">`, and a script that sets `window.location`.
 *
 * It reads with `token_get_all(…, TOKEN_PARSE)`, so a file PHP cannot parse is
 * an error, never "nothing found" (#777's lesson).
 *
 * Usage: echo '<JSON list of paths>' | php redirect-targets-structure.php
 * Prints one JSON list, one entry per file in the same order:
 *   {"locations": N, "findings": [{"line": N, "statement": "<the header call,
 *    spacing and comments removed>", "values": ["$_GET", …]}]}
 *   or {"error": "..."}
 * Exit 0 when every file was read and parsed; 1 otherwise.
 *
 * @package   Portal\Tools\AuditChecks
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/620
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** 📥 Arrays the whole of which the request chooses. */
const RT_REQUEST_ARRAYS = ['$_get', '$_post', '$_request', '$_cookie', '$_files'];

/** 📥 The `$_SERVER` keys set from the request (see the header for each). */
const RT_SERVER_KEYS = '/^(HTTP_|REDIRECT_|REQUEST_URI$|QUERY_STRING$|PATH_INFO$|ORIG_PATH_INFO$|PATH_TRANSLATED$|PHP_SELF$|SCRIPT_URL$|SCRIPT_URI$|SERVER_NAME$|UNENCODED_URL$|argv$)/';

/** 🧼 Calls whose answer cannot name another site, whatever goes in. */
const RT_HARMLESS_CALLS = ['intval', 'urlencode', 'rawurlencode', 'http_build_query'];

/** 🔢 Casts whose answer is a number or a yes/no. */
const RT_NUMBER_CASTS = [T_INT_CAST, T_DOUBLE_CAST, T_BOOL_CAST];

/** The one judge's class, as PHP names it once resolved (compared in small letters). */
const RT_JUDGE_CLASS = 'portal\\core\\auth';

function rt_text(mixed $token): string
{
    return is_array($token) === true ? (string) $token[1] : (string) $token;
}

function rt_is(mixed $token, array $kinds): bool
{
    return is_array($token) === true && in_array($token[0], $kinds, true);
}

/** The words a fixed text or a string's first piece holds, quotes removed. */
function rt_fixed_text(mixed $token): ?string
{
    if (rt_is($token, [T_CONSTANT_ENCAPSED_STRING]) === true) {
        return substr((string) $token[1], 1, -1);
    }
    if (rt_is($token, [T_ENCAPSED_AND_WHITESPACE]) === true) {
        return (string) $token[1];
    }

    return null;
}

/**
 * Every finding for one file. Throws ParseError when PHP cannot read it.
 *
 * @return array{locations:int, findings:list<array{line:int, statement:string, values:list<string>}>}
 */
function rt_analyse(string $source): array
{
    $t = array_values(array_filter(
        token_get_all($source, TOKEN_PARSE),
        static fn ($x): bool => rt_is($x, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]) === false
    ));
    $n = count($t);

    // 🧭 Namespace, `use` lines, and which class each token is inside, so a
    //    class name in front of `::safeRedirectUrl` can be RESOLVED the way PHP
    //    resolves it. A `use` inside a class (a trait) or after a closure's `)`
    //    is not an import and is skipped.
    $namespace = '';
    $aliases   = []; // small-letter alias => small-letter full name
    $ownFunctions = []; // small-letter names a `use function` brings in, or this file declares outside a class
    $classAt   = array_fill(0, $n, ''); // the full name of the class a token is in, '' outside one
    $depth     = 0;
    $classes   = []; // stack of [full name, depth of its body]
    $pendingClass = null;
    for ($i = 0; $i < $n; $i++) {
        $c = rt_text($t[$i]);
        if (rt_is($t[$i], [T_NAMESPACE]) === true && rt_is($t[$i + 1] ?? null, [T_STRING, T_NAME_QUALIFIED]) === true) {
            $namespace = strtolower((string) $t[$i + 1][1]);
            $aliases   = [];
        } elseif (rt_is($t[$i], [T_USE]) === true && $classes === [] && rt_text($t[$i - 1] ?? '') !== ')') {
            // `use A\B;`  `use A\B as C;`  `use A\B, D\E;`  `use A\{B, C as D};` (not `use function` / `use const`)
            $j = $i + 1;
            if (rt_is($t[$j] ?? null, [T_FUNCTION]) === true) {
                // `use function A\b;` brings in `b`; `use function A\b as c;` brings in `c`.
                for ($k = $j + 1; $k < $n && rt_text($t[$k]) !== ';'; $k++) {
                    if (rt_is($t[$k], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) === true) {
                        $parts = explode('\\', strtolower((string) $t[$k][1]));
                        $ownFunctions[end($parts)] = true;
                    }
                }
            } elseif (rt_is($t[$j] ?? null, [T_CONST]) === false) {
                $prefix = '';
                $name   = '';
                while ($j < $n && rt_text($t[$j]) !== ';') {
                    $x = $t[$j];
                    if (rt_is($x, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) === true) {
                        $name = ltrim(strtolower((string) $x[1]), '\\');
                    } elseif (rt_text($x) === '{') {
                        $prefix = rtrim($name, '\\') . '\\';
                        $name   = '';
                    } elseif (rt_is($x, [T_NS_SEPARATOR]) === true) {
                        $name .= '\\';
                    } elseif (rt_is($x, [T_AS]) === true) {
                        $alias = strtolower(rt_text($t[$j + 1] ?? ''));
                        $aliases[$alias] = $prefix . $name;
                        $name = '';
                        $j++;
                    } elseif ((rt_text($x) === ',' || rt_text($x) === '}') && $name !== '') {
                        $aliases[substr($name, (int) strrpos('\\' . $name, '\\'))] = $prefix . $name;
                        $name = '';
                    }
                    $j++;
                }
                if ($name !== '') {
                    $aliases[substr($name, (int) strrpos('\\' . $name, '\\'))] = $prefix . $name;
                }
            }
        }
        if (rt_is($t[$i], [T_FUNCTION]) === true && $classes === [] && rt_is($t[$i + 1] ?? null, [T_STRING]) === true
            && rt_text($t[$i + 2] ?? '') === '(') {
            $ownFunctions[strtolower((string) $t[$i + 1][1])] = true;
        }
        if (rt_is($t[$i], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) === true
            && rt_is($t[$i - 1] ?? null, [T_DOUBLE_COLON, T_NEW]) === false && rt_is($t[$i + 1] ?? null, [T_STRING]) === true) {
            $pendingClass = rt_is($t[$i], [T_CLASS]) === true
                ? ltrim($namespace . '\\' . strtolower((string) $t[$i + 1][1]), '\\')
                : '(not a class)';
        }
        if ($c === '{' || rt_is($t[$i], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]) === true) {
            $depth++;
            if ($pendingClass !== null && $c === '{') {
                $classes[]    = [$pendingClass, $depth];
                $pendingClass = null;
            }
        } elseif ($c === '}') {
            if ($classes !== [] && end($classes)[1] === $depth) {
                array_pop($classes);
            }
            $depth--;
        }
        $classAt[$i] = $classes === [] ? '' : (string) end($classes)[0];
    }

    // ⚖️ Is the `(` at $j the judge's? `<class>::safeRedirectUrl(` with the class resolved.
    $isJudge = static function (int $j) use ($t, $aliases, $namespace, $classAt): bool {
        if (strtolower(rt_text($t[$j - 1] ?? '')) !== 'saferedirecturl' || rt_is($t[$j - 2] ?? null, [T_DOUBLE_COLON]) === false) {
            return false;
        }
        $class = $t[$j - 3] ?? null;
        $text  = strtolower(rt_text($class));
        if (rt_is($class, [T_STATIC]) === true || $text === 'self') {
            return $classAt[$j] === RT_JUDGE_CLASS;
        }
        if (rt_is($class, [T_NAME_FULLY_QUALIFIED]) === true) {
            return ltrim($text, '\\') === RT_JUDGE_CLASS;
        }
        if (rt_is($class, [T_NAME_RELATIVE]) === true) {
            return ltrim($namespace . '\\' . substr($text, strlen('namespace\\')), '\\') === RT_JUDGE_CLASS;
        }
        if (rt_is($class, [T_STRING, T_NAME_QUALIFIED]) === true) {
            $first = explode('\\', $text)[0];
            $full  = isset($aliases[$first]) === true
                ? $aliases[$first] . substr($text, strlen($first))
                : ltrim($namespace . '\\' . $text, '\\');

            return $full === RT_JUDGE_CLASS;
        }

        return false;
    };

    $locations = 0;
    $findings  = [];
    for ($i = 0; $i < $n; $i++) {
        if (rt_is($t[$i], [T_STRING, T_NAME_FULLY_QUALIFIED]) === false || strtolower(ltrim(rt_text($t[$i]), '\\')) !== 'header'
            || rt_text($t[$i + 1] ?? '') !== '(') {
            continue;
        }
        if (rt_is($t[$i - 1] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW]) === true) {
            continue; // a method, or the declaration of a function of that name
        }
        // Every argument [from, to), and the closing bracket of the call.
        $d     = 0;
        $args  = [];
        $from  = $i + 2;
        $close = -1;
        for ($j = $from; $j < $n; $j++) {
            $c = rt_text($t[$j]);
            if ($c === '(' || $c === '[' || $c === '{' || rt_is($t[$j], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]) === true) {
                $d++;
            } elseif ($c === ')' || $c === ']' || $c === '}') {
                if ($d === 0) {
                    $args[] = [$from, $j];
                    $close  = $j;
                    break;
                }
                $d--;
            } elseif ($c === ',' && $d === 0) {
                $args[] = [$from, $j];
                $from   = $j + 1;
            }
        }
        if ($close === -1 || $args === []) {
            continue;
        }
        // The header text is the argument NAMED `header` (`header(header: …)`,
        // in any position), or else the first argument.
        [$s, $e] = $args[0];
        foreach ($args as [$a, $b]) {
            if (rt_is($t[$a] ?? null, [T_STRING]) === true && rt_text($t[$a + 1] ?? '') === ':' && $a + 1 < $b) {
                if (strtolower((string) $t[$a][1]) === 'header') {
                    [$s, $e] = [$a + 2, $b];
                    break;
                }
                if ($a === $args[0][0]) {
                    [$s, $e] = [-1, -1]; // the first argument is a DIFFERENT named one
                }
            }
        }
        if ($s === -1) {
            continue;
        }
        // A Location header: some fixed text in the first argument begins "Location:".
        $isLocation = false;
        for ($j = $s; $j < $e; $j++) {
            $fixed = rt_fixed_text($t[$j]);
            if ($fixed !== null && preg_match('/^\s*location\s*:/i', $fixed) === 1) {
                $isLocation = true;
                break;
            }
        }
        if ($isLocation === false) {
            continue;
        }
        $locations++;

        // Walk the argument; every request value must sit inside a bracket the
        // judge (or a harmless wrapper) opened, or straight after a number cast.
        // Each open bracket is true while it is the FIRST argument of the judge
        // or of a harmless wrapper. A comma inside it ends that: the judge
        // hands back its SECOND argument (the fallback) unchanged, and
        // http_build_query()'s second and third go into the answer raw.
        $open   = [];
        $values = [];
        for ($j = $s; $j < $e; $j++) {
            $c = rt_text($t[$j]);
            if ($c === '(' || $c === '[' || $c === '{' || rt_is($t[$j], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]) === true) {
                $before    = $t[$j - 1] ?? null;
                $wrapper   = strtolower(ltrim(rt_text($before), '\\'));
                $isBuiltIn = rt_is($before, [T_NAME_FULLY_QUALIFIED]) === true
                    || (rt_is($before, [T_STRING]) === true && isset($ownFunctions[$wrapper]) === false);
                $open[] = $c === '(' && ($isJudge($j) === true || rt_is($before, array_merge(RT_NUMBER_CASTS, [T_ISSET, T_EMPTY])) === true
                    || ($isBuiltIn === true && in_array($wrapper, RT_HARMLESS_CALLS, true) === true
                        && rt_is($t[$j - 2] ?? null, [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_NEW]) === false));
                continue;
            }
            if ($c === ')' || $c === ']' || $c === '}') {
                array_pop($open);
                continue;
            }
            if ($c === ',' && $open !== []) {
                $open[count($open) - 1] = false;
                continue;
            }
            // `$_GET` as a variable, or `${_GET[…]}` inside a string.
            if (rt_is($t[$j], [T_VARIABLE]) === true) {
                $name = strtolower($c);
            } elseif (rt_is($t[$j], [T_STRING_VARNAME]) === true) {
                $name = '$' . strtolower($c);
            } else {
                continue;
            }
            $request = in_array($name, RT_REQUEST_ARRAYS, true);
            if ($name === '$_server') {
                // The key: exactly one fixed text (or one bare word inside a
                // string) is judged by its name; anything else counts.
                $request = true;
                if (rt_text($t[$j + 1] ?? '') === '[' && rt_text($t[$j + 3] ?? '') === ']') {
                    $key = $t[$j + 2];
                    if (rt_is($key, [T_CONSTANT_ENCAPSED_STRING]) === true) {
                        $request = preg_match(RT_SERVER_KEYS, trim((string) $key[1], '\'"')) === 1;
                    } elseif (rt_is($key, [T_STRING]) === true) {
                        $request = preg_match(RT_SERVER_KEYS, (string) $key[1]) === 1;
                    }
                }
            }
            if ($request === false) {
                continue;
            }
            if (in_array(true, $open, true) === true || rt_is($t[$j - 1] ?? null, RT_NUMBER_CASTS) === true) {
                continue;
            }
            $values[] = $c;
        }
        if ($values === []) {
            continue;
        }
        $statement = '';
        for ($j = $i; $j <= $close; $j++) {
            $statement .= rt_text($t[$j]);
        }
        $findings[] = ['line' => (int) $t[$i][2], 'statement' => $statement, 'values' => array_values(array_unique($values))];
    }

    return ['locations' => $locations, 'findings' => $findings];
}

// =============================================================================
// 🏁 Read the list, answer for each file
// =============================================================================
$paths = json_decode((string) stream_get_contents(STDIN), true);
if (is_array($paths) === false) {
    fwrite(STDERR, "expected a JSON list of paths on standard input\n");
    exit(1);
}
$answers = [];
$allRead = true;
foreach ($paths as $path) {
    $source = is_string($path) === true ? @file_get_contents($path) : false;
    if ($source === false) {
        $answers[] = ['error' => 'could not be read'];
        $allRead   = false;
        continue;
    }
    try {
        $answers[] = rt_analyse($source);
    } catch (\ParseError $e) {
        $answers[] = ['error' => 'PHP cannot parse it (' . $e->getMessage() . ' on line ' . $e->getLine() . ')'];
        $allRead   = false;
    }
}
echo json_encode($answers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit($allRead === true ? 0 : 1);
