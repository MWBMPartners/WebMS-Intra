<?php
// Path: tools/audit-checks/redirect-targets-structure.php
/**
 * -----------------------------------------------------------------------------
 * Ask PHP which "send the browser to …" headers carry a value the REQUEST
 * chose 🔍🔀 (#620)
 * -----------------------------------------------------------------------------
 * `check_redirect_targets_judged.py` asks one question of every PHP file under
 * `web/`, and this file answers it from PHP's own tokeniser:
 *
 *   Does a `header('Location: …')` take any part of its address from the
 *   request — `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, or a `$_SERVER`
 *   value the visitor's browser sends (`HTTP_…`, `REQUEST_URI`,
 *   `QUERY_STRING`, `PATH_INFO`, `ORIG_PATH_INFO`, `PHP_SELF`) — WITHOUT that
 *   value having been through `Auth::safeRedirectUrl()`?
 *
 * WHY THIS MATTERS. A "come back to" address a page accepts from a form or a
 * link is how an attacker makes a link that wears the portal's own address
 * and lands somewhere else (an "open redirect"). `Auth::safeRedirectUrl()` is
 * the ONE judge of such an address; three older pages each had a judge of
 * their own and every one let something through (#620).
 *
 * HOW A VALUE IS FOLLOWED. Inside one function (or one file, outside any
 * function), a variable becomes "request-fed" when it is assigned (`=`, `.=`,
 * `??=`) from an expression in which a request value, or another request-fed
 * variable, appears as part of the VALUE. That is repeated until nothing
 * changes, so `$a = $_POST['x']; $b = $a;` makes both request-fed. A header
 * is reported when a request value or a request-fed variable appears as part
 * of its address.
 *
 * WHEN A VALUE DOES NOT COUNT, and why each is not a way round the rule:
 *   - Inside the arguments of `Auth::safeRedirectUrl()` (or `self::` /
 *     `static::` within Auth). That is the point. A function of any OTHER
 *     name, including a page's own function called `safeRedirectUrl`, counts
 *     as passing the value straight through.
 *   - Inside `urlencode()`, `rawurlencode()`, `http_build_query()`,
 *     `intval()`, `isset()`, `empty()`, or after an `(int)`, `(float)` or
 *     `(bool)` cast. None of those can produce `/`, `:`, `\` or a control
 *     character, so none can name another site. `(string)` is NOT among them.
 *   - Used only as a TEST: an operand of a comparison or of `&&` / `||`, or
 *     the condition of `? :`. The answer of a test is true or false, never
 *     part of an address. (`?:` with nothing between is the "Elvis"
 *     operator, which RETURNS its left side, so that does count.)
 *   - After a fixed text holding `?` or `#` in the same run of `.` joins: it
 *     is then in the query or the fragment, which cannot change which site
 *     the browser goes to.
 *   - After a fixed beginning that already names a page: `Site::url('x')` or
 *     `Router::url('x')` with a non-empty fixed argument, or a fixed text
 *     starting with "/" and a character that is not "/" or "\". Whatever is
 *     joined on after "/a/" stays on this site. `Site::url('')` is NOT such a
 *     beginning: it is "/" with nothing after it, and "/" followed by
 *     "/elsewhere.example" is another site.
 *
 * WHAT IT CANNOT SEE, said plainly (D-065: presence and order, nothing that
 * pretends to know whether code RUNS):
 *   - A value that reaches `header()` through a FUNCTION'S PARAMETER, or that
 *     a function or closure reads from the request itself and RETURNS. Only
 *     what is assigned in the same function is followed. (The Host Console's
 *     `$cameFrom` closure in `admin/livestream/index.php` is such a shape,
 *     and is correct: an exact allow-list.)
 *   - A value written into an array element or a property, taken apart by
 *     `list()` / `[...] =`, set by `foreach`, `extract()`, a reference, or
 *     brought into a closure with `use`.
 *   - A request body read with `file_get_contents('php://input')`, a value
 *     from `filter_input()`, `getenv()` or `$_SESSION` (a session value was
 *     put there by an EARLIER request — the sign-in pages judge theirs).
 *   - A `Location` header whose text is built by a function (`sprintf`), a
 *     `Refresh` header, a `<meta http-equiv="refresh">`, or a script that
 *     sets `window.location`.
 *   - Whether the code runs at all. A guard that never runs is a behaviour,
 *     and the behaviour is tested over HTTP by `tools/come-back-addresses-selftest.php`.
 *
 * WHY A PHP FILE AND NOT A PYTHON READER. A regular expression cannot tell a
 * call from a call quoted in a comment or a string, nor a `{` in `"{$x}"`
 * from a block. `token_get_all()` with TOKEN_PARSE can, and it also refuses a
 * file that does not parse — the #777 lesson: without TOKEN_PARSE a broken
 * file comes back as "nothing found".
 *
 * Usage: echo '<JSON list of paths>' | php redirect-targets-structure.php
 * Prints one JSON list, one entry per file in the same order:
 *   {"findings": [{"line": N, "statement": "<the header call, spacing
 *    removed>", "why": ["..."]}]}   or   {"error": "..."}
 * Exit 0 when every file was read and parsed; 1 otherwise.
 *
 * @package   Portal\Tools\AuditChecks
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/620
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** 📥 The request's own arrays: anything in them was chosen by whoever sent it. */
const RT_REQUEST_ARRAYS = ['$_GET', '$_POST', '$_REQUEST', '$_COOKIE'];

/** 📥 The `$_SERVER` keys a browser (or anybody) can choose. */
const RT_SERVER_KEYS = '/^(HTTP_|REQUEST_URI$|QUERY_STRING$|PATH_INFO$|ORIG_PATH_INFO$|PHP_SELF$)/';

/** 🧼 Calls whose answer cannot name another site, whatever goes in. */
const RT_SAFE_CALLS = ['urlencode', 'rawurlencode', 'http_build_query', 'intval'];

/** 🔢 Casts whose answer is a number or a yes/no. */
const RT_NUMBER_CASTS = [T_INT_CAST, T_DOUBLE_CAST, T_BOOL_CAST];

/** ⚖️ Operators whose answer is a yes/no (or -1/0/1), never an address. */
const RT_TEST_OPERATORS = [
    T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_EQUAL, T_IS_NOT_IDENTICAL, T_IS_SMALLER_OR_EQUAL,
    T_IS_GREATER_OR_EQUAL, T_SPACESHIP, T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR,
    T_LOGICAL_XOR, T_INSTANCEOF,
];

/** The text of a token. */
function rt_text(mixed $token): string
{
    return is_array($token) === true ? (string) $token[1] : (string) $token;
}

/** Is the token of one of these kinds? */
function rt_is(mixed $token, array $kinds): bool
{
    return is_array($token) === true && in_array($token[0], $kinds, true);
}

/**
 * Everything one file says. Throws ParseError when PHP cannot read it.
 *
 * @return list<array{line:int, statement:string, why:list<string>}>
 */
function rt_analyse(string $source): array
{
    $all = token_get_all($source, TOKEN_PARSE);
    // Comments and spacing say nothing about where a value goes; inline HTML
    // is kept, because it ends an expression.
    $t = array_values(array_filter($all, static fn ($x): bool => rt_is($x, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG]) === false));
    $n = count($t);

    // 🗂️ Which function each token belongs to. A named function, a method
    //    and a closure each start a scope of their own at their body's `{`.
    //    An arrow function (`fn`) shares its parent's, as PHP does by value.
    $scope   = array_fill(0, $n, 0);
    $next    = 0;
    $open    = [0];
    $opens   = [];
    $depth   = 0;
    $pending = false;
    for ($i = 0; $i < $n; $i++) {
        $c = rt_text($t[$i]);
        if (rt_is($t[$i], [T_FUNCTION]) === true) {
            $pending = true;
        }
        $scope[$i] = (int) end($open);
        if ($c === '{' || rt_is($t[$i], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]) === true) {
            $depth++;
            if ($pending === true) {
                $next++;
                $open[]  = $next;
                $opens[] = $depth;
                $pending = false;
                $scope[$i] = $next;
            }
        } elseif ($c === '}') {
            if ($opens !== [] && end($opens) === $depth) {
                array_pop($opens);
                array_pop($open);
            }
            $depth--;
        } elseif ($c === ';' && $pending === true) {
            $pending = false; // an abstract method or an interface's: no body
        }
    }

    // 📥 Is the variable token at $k a request value?
    $isRequest = static function (int $k) use ($t): bool {
        $c = rt_text($t[$k]);
        if (in_array($c, RT_REQUEST_ARRAYS, true) === true) {
            return true;
        }
        if ($c === '$_SERVER' && rt_text($t[$k + 1] ?? '') === '[' && rt_is($t[$k + 2] ?? null, [T_CONSTANT_ENCAPSED_STRING]) === true) {
            return preg_match(RT_SERVER_KEYS, trim((string) $t[$k + 2][1], '\'"')) === 1;
        }
        // `$_SERVER[$key]`: the key cannot be read, so it counts.
        return $c === '$_SERVER' && rt_text($t[$k + 1] ?? '') === '[';
    };

    // ⚖️ Is the judge's call opened at $j (the `(`)? `Auth::safeRedirectUrl(`,
    //    `\Portal\Core\Auth::…(`, or `self::` / `static::` (only Auth defines it).
    $isJudge = static function (int $j) use ($t): bool {
        if (strtolower(rt_text($t[$j - 1] ?? '')) !== 'saferedirecturl' || rt_text($t[$j - 2] ?? '') !== '::') {
            return false;
        }
        $class = ltrim(strtolower(rt_text($t[$j - 3] ?? '')), '\\');

        return in_array($class, ['auth', 'portal\\core\\auth', 'self', 'static'], true);
    };

    /**
     * 🔎 Does the value token at $k, inside the expression [$s, $e), take
     * part in the VALUE that expression produces in a way that could choose
     * where the browser goes? See the file header for each "no".
     */
    $countsAt = static function (int $s, int $k, int $e) use ($t, $isJudge): bool {
        // Walk the expression up to $k, one bracket level at a time.
        //   'safe'  — a fixed beginning (or a fixed `?`/`#`) already decides
        //             the site for the rest of this run of joins;
        //   'inherit' — what the level started with, restored by `?`, `:`,
        //             `??` and `,`, which each start a new alternative;
        //   'start' — the next operand is the first of its run;
        //   'opener' — what came just before this level's bracket.
        $levels = [['safe' => false, 'inherit' => false, 'start' => true, 'opener' => null]];
        for ($j = $s; $j < $k; $j++) {
            $c   = rt_text($t[$j]);
            $top = count($levels) - 1;
            if ($c === '(' || $c === '[') {
                $levels[] = ['safe' => $levels[$top]['safe'], 'inherit' => $levels[$top]['safe'], 'start' => true, 'opener' => $j];
                continue;
            }
            if ($c === ')' || $c === ']') {
                if ($top > 0) {
                    $closed = array_pop($levels);
                    $top--;
                    // `Site::url('x')` / `Router::url('x')` as the FIRST operand of a run.
                    $o = $closed['opener'];
                    if ($o !== null && $c === ')' && strtolower(rt_text($t[$o - 1] ?? '')) === 'url'
                        && rt_text($t[$o - 2] ?? '') === '::'
                        && in_array(ltrim(strtolower(rt_text($t[$o - 3] ?? '')), '\\'), ['site', 'router', 'portal\\core\\site', 'portal\\core\\router'], true) === true
                        && rt_is($t[$o + 1] ?? null, [T_CONSTANT_ENCAPSED_STRING]) === true
                        && strlen(trim((string) $t[$o + 1][1], '\'"')) > 0
                        && $o + 2 === $j
                        && ($levels[$top]['start'] === true)
                    ) {
                        $levels[$top]['safe'] = true;
                    }
                }
                $levels[$top]['start'] = false;
                continue;
            }
            if ($c === '?' || $c === ':' || $c === ',' || rt_is($t[$j], [T_COALESCE, T_DOUBLE_ARROW]) === true) {
                $levels[$top]['safe']  = $levels[$top]['inherit'];
                $levels[$top]['start'] = true;
                continue;
            }
            if (rt_is($t[$j], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]) === true) {
                $text = rt_is($t[$j], [T_CONSTANT_ENCAPSED_STRING]) === true ? substr((string) $t[$j][1], 1, -1) : (string) $t[$j][1];
                if (str_contains($text, '?') === true || str_contains($text, '#') === true) {
                    $levels[$top]['safe'] = true;
                } elseif ($levels[$top]['start'] === true && preg_match('#^/[^/\\\\]#', $text) === 1) {
                    $levels[$top]['safe'] = true;
                }
            }
            // The class name and `::` and `url` of a call are not yet an operand.
            if (rt_is($t[$j], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_DOUBLE_COLON, T_STATIC]) === true) {
                continue;
            }
            if ($c !== '.' && $c !== '"') {
                $levels[$top]['start'] = false;
            }
        }
        if ($levels[count($levels) - 1]['safe'] === true) {
            return false;
        }
        // A number cast, or a test, directly in front of it.
        $before = $t[$k - 1] ?? null;
        if (rt_is($before, RT_NUMBER_CASTS) === true || rt_is($before, RT_TEST_OPERATORS) === true || rt_text($before) === '!') {
            return false;
        }
        // Inside a call or a cast that cannot make an address.
        foreach ($levels as $level) {
            $o = $level['opener'];
            if ($o === null || rt_text($t[$o]) !== '(') {
                continue;
            }
            $in = $t[$o - 1] ?? null;
            if ($isJudge($o) === true || rt_is($in, RT_NUMBER_CASTS) === true || rt_is($in, [T_ISSET, T_EMPTY]) === true
                || in_array(strtolower(ltrim(rt_text($in), '\\')), RT_SAFE_CALLS, true) === true) {
                return false;
            }
        }
        // Forward: is it only a TEST? Stop at the end of this alternative.
        $level = 0;
        for ($j = $k + 1; $j < $e; $j++) {
            $c = rt_text($t[$j]);
            if ($c === '(' || $c === '[') {
                $level++;
                continue;
            }
            if ($c === ')' || $c === ']') {
                $level = max(0, $level - 1);
                continue;
            }
            if ($level > 0) {
                continue;
            }
            if (rt_is($t[$j], RT_TEST_OPERATORS) === true || $c === '<' || $c === '>') {
                return false;
            }
            if ($c === '?') {
                return rt_text($t[$j + 1] ?? '') === ':'; // `?:` returns the left side
            }
            if ($c === ':' || $c === ',' || $c === ';') {
                return true;
            }
        }

        return true;
    };

    // ✍️ Every assignment to a plain variable: [scope, name, from, to, line].
    $assignments = [];
    for ($i = 0; $i < $n; $i++) {
        if (rt_is($t[$i], [T_VARIABLE]) === false || in_array(rt_text($t[$i + 1] ?? ''), ['='], true) === false
            && rt_is($t[$i + 1] ?? null, [T_CONCAT_EQUAL, T_COALESCE_EQUAL]) === false) {
            continue;
        }
        if (rt_is($t[$i - 1] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON]) === true) {
            continue; // a property, not a variable
        }
        $d = 0;
        $j = $i + 2;
        for (; $j < $n; $j++) {
            $c = rt_text($t[$j]);
            if ($c === '(' || $c === '[' || $c === '{' || rt_is($t[$j], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]) === true) {
                $d++;
            } elseif ($c === ')' || $c === ']' || $c === '}') {
                if ($d === 0) {
                    break;
                }
                $d--;
            } elseif (($c === ';' || $c === ',') && $d === 0) {
                break;
            } elseif (rt_is($t[$j], [T_CLOSE_TAG, T_INLINE_HTML]) === true && $d === 0) {
                break;
            }
        }
        $assignments[] = [$scope[$i], (string) $t[$i][1], $i + 2, $j, (int) $t[$i][2]];
    }

    // 🔁 Follow request values through assignments until nothing changes.
    $fed = [];
    do {
        $changed = false;
        foreach ($assignments as [$sc, $name, $from, $to, $line]) {
            if (isset($fed[$sc . ':' . $name]) === true) {
                continue;
            }
            for ($m = $from; $m < $to; $m++) {
                if ($scope[$m] !== $sc || rt_is($t[$m], [T_VARIABLE]) === false) {
                    continue; // a closure's body inside the expression is its own scope
                }
                $why = $isRequest($m) === true ? rt_text($t[$m]) : ($fed[$sc . ':' . $t[$m][1]] ?? null);
                if ($why !== null && $countsAt($from, $m, $to) === true) {
                    $fed[$sc . ':' . $name] = $name . ' (line ' . $line . ', from ' . (str_starts_with($why, '$_') === true ? $why : explode(' ', $why)[0]) . ')';
                    $changed = true;
                    break;
                }
            }
        }
    } while ($changed === true);

    // 🔀 Every `header('Location: …')`.
    $findings = [];
    for ($i = 0; $i < $n; $i++) {
        $name = strtolower(ltrim(rt_text($t[$i]), '\\'));
        if (rt_is($t[$i], [T_STRING, T_NAME_FULLY_QUALIFIED]) === false || $name !== 'header' || rt_text($t[$i + 1] ?? '') !== '(') {
            continue;
        }
        if (rt_is($t[$i - 1] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW]) === true) {
            continue; // a method, or the declaration of a function of that name
        }
        // The first argument: [$s, $e). The whole call: [$i, $close].
        $d     = 0;
        $s     = $i + 2;
        $e     = -1;
        $close = -1;
        for ($j = $s; $j < $n; $j++) {
            $c = rt_text($t[$j]);
            if ($c === '(' || $c === '[' || $c === '{' || rt_is($t[$j], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]) === true) {
                $d++;
            } elseif ($c === ')' || $c === ']' || $c === '}') {
                if ($d === 0) {
                    $e     = $e === -1 ? $j : $e;
                    $close = $j;
                    break;
                }
                $d--;
            } elseif ($c === ',' && $d === 0 && $e === -1) {
                $e = $j;
            }
        }
        if ($e === -1 || $close === -1) {
            continue;
        }
        // Is it a Location header? The first thing is fixed text beginning "Location:".
        $first = $t[$s];
        $head  = '';
        if (rt_is($first, [T_CONSTANT_ENCAPSED_STRING]) === true) {
            $head = substr((string) $first[1], 1, -1);
        } elseif (rt_text($first) === '"' && rt_is($t[$s + 1] ?? null, [T_ENCAPSED_AND_WHITESPACE]) === true) {
            $head = (string) $t[$s + 1][1];
        }
        if (preg_match('/^\s*location\s*:\s*(.*)$/is', $head, $m) !== 1) {
            continue;
        }
        // Where the ADDRESS begins: after the fixed "Location: " when it is
        // a separate piece joined with `.`, else the whole first argument
        // (the fixed text then counts as the address's own beginning).
        $from = $s;
        if (rt_is($first, [T_CONSTANT_ENCAPSED_STRING]) === true && $m[1] === '' && rt_text($t[$s + 1] ?? '') === '.') {
            $from = $s + 2;
        } elseif ($m[1] !== '' && preg_match('#^/[^/\\\\]#', $m[1]) === 1) {
            continue; // "Location: /page…" — the site is already decided
        }
        $why = [];
        for ($k = $from; $k < $e; $k++) {
            if (rt_is($t[$k], [T_VARIABLE]) === false) {
                continue;
            }
            $reason = $isRequest($k) === true ? 'the request value ' . rt_text($t[$k]) . ' directly' : ($fed[$scope[$k] . ':' . $t[$k][1]] ?? null);
            if ($reason !== null && $countsAt($from, $k, $e) === true) {
                $why[] = $reason;
            }
        }
        if ($why === []) {
            continue;
        }
        $statement = '';
        for ($j = $i; $j <= $close; $j++) {
            $statement .= rt_text($t[$j]);
        }
        $findings[] = ['line' => (int) $t[$i][2], 'statement' => $statement, 'why' => array_values(array_unique($why))];
    }

    return $findings;
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
        $answers[] = ['findings' => rt_analyse($source)];
    } catch (\ParseError $e) {
        $answers[] = ['error' => 'PHP cannot parse it (' . $e->getMessage() . ' on line ' . $e->getLine() . ')'];
        $allRead   = false;
    }
}
echo json_encode($answers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit($allRead === true ? 0 : 1);
