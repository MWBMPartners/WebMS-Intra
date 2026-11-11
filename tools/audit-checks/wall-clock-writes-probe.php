<?php
/**
 * Follows a value from a moment function to a database write, using PHP's own
 * tokeniser.
 *
 * Path:      tools/audit-checks/wall-clock-writes-probe.php
 * Package:   WebMS-Intra / audit checks
 * Author:    Claude Opus 5.5 (for MWBM Partners Ltd, t/a MWservices)
 * Copyright: All Rights Reserved -- MWBM Partners Ltd
 * Version:   1.0.0
 *
 * WHAT THIS IS FOR (#785)
 * -----------------------
 * It is the half of `check_wall_clock_writes_not_moments.py` that reads PHP.
 * The Python side decides WHICH columns hold a reading from a clock (out of
 * the schema) and judges the answer; this side answers one question per file:
 *
 *     is a value that went THROUGH A MOMENT -- strtotime(), a DateTime
 *     constructor, createFromFormat(), mktime() ... -- handed to a database
 *     statement in the place that fills one of those columns?
 *
 * Both write-path faults #777 found were exactly that shape, and neither was
 * visible to anything automatic: `resources/book.php` and
 * `calendar/submit-save.php` took a posted value, built a moment from it,
 * formatted it back, and bound the result into an INSERT that named the
 * column far below. On the morning the clocks go forward the hour the value
 * names does not exist, so PHP moves it, and the moved hour is stored for ever.
 *
 * WHY THE TOKENISER AND NOT A PATTERN
 * -----------------------------------
 * Every file this check exists for QUOTES ITS OLD CODE IN A COMMENT, so a text
 * search reports the comment that records a fix as the fault. #763 spent six
 * rounds of regular expressions learning that the distinctions that matter
 * (a comment, a string, a heredoc) are in PHP's grammar and not in the text.
 *
 * HOW FAR IT FOLLOWS A VALUE -- and where it stops, said plainly (D-065)
 * ---------------------------------------------------------------------
 *   * Within ONE function body (or a file's top level), by VARIABLE NAME,
 *     through `=` and the compound operators (`.=`, `??=`, `+=` ...), and
 *     into an array (`$p[] = ...`, `$p['k'] = ...`):
 *     `$a = strtotime($x); $b = (int) $a; $c = date('Y-m-d H:i:s', $b - 60);`
 *     -- `$c` came through a moment.
 *   * Through operators, casts, `?:`/`??`, string joining, array literals,
 *     `foreach`, arrow functions (read as part of the function they sit in),
 *     a moment handed to another moment function (`strtotime('+1 day', $m)`),
 *     and through a SHORT list of functions that hand their argument back
 *     changed only in form (`PASS_THROUGH`: trim, substr ...).
 *   * EVERY construct in this list and in the two below has a case in
 *     `tools/wall-clock-writes-selftest.py` that was measured to go red when
 *     that construct's handling is switched off (39 of 39 in round 5 of #785).
 *     That was not true before round 4: switching `foreach` off left the
 *     self-test green, and an arrow function's body was never read at all
 *     although this list said it was -- `=>` ended the expression. And round
 *     4 deleted the "carried moment" branch for a constructor as
 *     unreachable, which it is not: a moment returned by a call with no
 *     variable in it reaches only that branch (round 5 restored it).
 *   * NOT into the arguments of any other call. That is deliberate and was
 *     MEASURED: the first version let a value flow through every call, and
 *     `import.php`'s `Events::alreadyInCalendar($db, ..., $storedStart)` --
 *     which returns a ROW -- made every value in the confirm loop look as
 *     though it came through a moment: twenty-six false reports in one file.
 *     A check that cries wolf gets switched off (#483).
 *   * Through a RETURN, one hop, inside the same file: a closure held in a
 *     variable, a named function, or a method called as `$this->m()` or
 *     `self::m()`, whose `return` hands back such a value makes every call to
 *     it in that file such a value too. That is how
 *     `calendar/manage/import.php`'s `$fmtStore()` is seen.
 *   * It is FLOW-INSENSITIVE: a variable is "through a moment" if ANY
 *     assignment to it in that function is. That over-approximates on purpose.
 *   * NOT through a function's PARAMETERS, NOT across files, NOT through
 *     `$this->property`, `list()`/`[...] =` destructuring, `extract()`,
 *     references or variable variables. Each is a stated blind spot. A check
 *     that tried to see all of them would be a PHP interpreter, and the
 *     repository's history (#763, #775) is a record of static guards that grew
 *     towards that and were defeated round after round.
 *
 * WHAT COUNTS AS "WENT THROUGH A MOMENT"
 * --------------------------------------
 * A moment function whose VALUE argument contains a variable. `strtotime(
 * '+60 days')` and `new DateTimeImmutable()` build a moment from nothing a
 * person wrote -- they ARE moments, and storing one in a moment column is
 * right -- so they start nothing. The value argument is the first for most
 * functions, the second for createFromFormat(), and any argument for mktime()
 * and the setTime()/setDate() family (which resolve through the same
 * transitions as a constructor does).
 *
 * WHAT COUNTS AS "IN THE PLACE THAT FILLS A CLOCK COLUMN"
 * -------------------------------------------------------
 * The statement is found at the most recent `prepare()` assigned to the
 * statement's variable earlier in the same function, and its SQL is read from
 * the string literals. Each `?` is matched to the column it fills -- the
 * column list of an INSERT/REPLACE against its VALUES, the left-hand side of
 * an UPDATE ... SET or ON DUPLICATE KEY UPDATE assignment -- and each value
 * handed to `bind_param()` / `execute([...])` / `execute_query()` to its `?`.
 *
 * Measured, that matching is NOT optional: judging the whole statement
 * reported `attendance/api/create.php`, whose DATE value (`sessionDate`) sits
 * in the same INSERT as a TIME column. A value pushed onto a run-time list
 * (`$params[] = $v` beside `$set[] = 'sessionDate = ?'`) is matched to the
 * `'<column> = ?'` literal written just before it in the same block. Where
 * the matching cannot be done -- a push with no such literal (a column named
 * by a variable), `execute($array)`, INSERT ... SELECT, an interpolated
 * `query()` -- the probe falls back to the STATEMENT and says so in the
 * finding, because guessing "it is probably the other column" is the
 * direction that hides a fault.
 *
 * A closure's `use` list is followed one level at a time into the function it
 * is written in, for its statement and its values alike: the three places
 * that create an event bind inside a closure handed to
 * `Events::saveUnderFreeSlug()`, and without this none of them was readable.
 *
 * SQL assembled at run time from variables cannot be read; the bind calls
 * whose statement could not be read are COUNTED and returned, so that limit is
 * a number on every run rather than a hope.
 *
 * INPUT  (stdin, JSON): {"columns": {"tblEvents": ["startDateTime", ...]},
 *                        "sources": {"strtotime": 0, "mktime": -1, ...},
 *                        "files": ["/abs/path.php", ...]}
 *        A source's number is the index of its VALUE argument; -1 means any.
 * OUTPUT (stdout, JSON): {"results": {path: {"error": null|string,
 *                        "findings": [...], "unread": N}}}
 */

declare(strict_types=1);

// ── Reading the request ──────────────────────────────────────────────────
$request = json_decode((string) stream_get_contents(STDIN), true);
if (is_array($request) === false) {
    fwrite(STDERR, "wall-clock-writes-probe: the request on stdin was not JSON\n");
    exit(2);
}

// table => column => the column's own spelling, both keys lower-cased,
// because SQL ignores case in names and the report should not.
$COLUMNS = [];
foreach ((array) ($request['columns'] ?? []) as $table => $cols) {
    foreach ((array) $cols as $col) {
        $COLUMNS[strtolower((string) $table)][strtolower((string) $col)] = $table . '.' . $col;
    }
}
$SOURCES = [];
foreach ((array) ($request['sources'] ?? []) as $name => $arg) {
    $SOURCES[strtolower((string) $name)] = (int) $arg;
}
if (count($COLUMNS) === 0 || count($SOURCES) === 0) {
    fwrite(STDERR, "wall-clock-writes-probe: no columns or no sources were given\n");
    exit(2);
}

// 🧭 Spellings of PHP's own classes, fixed here rather than passed in because
//    nobody maintains them: `new DateTime(...)` / `new DateTimeImmutable(...)`
//    read their FIRST argument, `X::createFromFormat()` its SECOND.
const CONSTRUCTED = ['datetime', 'datetimeimmutable'];
// The methods that set a moment's parts and so resolve through the zone's
// transitions exactly as a constructor does -- `setTime(1, 30)` on the
// spring-forward morning gives 02:30. Only an argument holding a variable
// starts one: `->modify('+1 day')` changes a moment that already exists, and
// if THAT came through a moment the variable holding it carries it anyway.
const SETTERS = ['settime', 'setdate', 'setisodate', 'modify'];
// What turns a moment back into text. Which FORMAT is used is deliberately
// not asked: a moment built from a reading and formatted into ANYTHING that
// then fills a clock column is the fault -- even a date-only slice, the shape
// round 10 of #777 found storing a series a day late in two zones.
const FORMATTERS = ['date', 'gmdate', 'date_format', 'strftime', 'gmstrftime'];
// Functions that hand back their argument changed only in FORM, so a value
// that came through a moment is still one afterwards. Kept short on purpose:
// every other call is a wall the value does not cross (see the header).
const PASS_THROUGH = ['trim', 'ltrim', 'rtrim', 'substr', 'mb_substr', 'strval', 'intval',
    'sprintf', 'str_pad', 'str_replace', 'strtolower', 'strtoupper', 'max', 'min', 'abs'];
// The calls that hand values to a prepared statement.
const BINDERS = ['bind_param', 'execute', 'bindvalue', 'bindparam'];

$out = ['results' => []];
foreach ((array) ($request['files'] ?? []) as $path) {
    $path = (string) $path;
    $src = @file_get_contents($path);
    if ($src === false) {
        $out['results'][$path] = ['error' => 'could not be read', 'findings' => [], 'unread' => 0];
        continue;
    }
    try {
        $out['results'][$path] = examine($src, $COLUMNS, $SOURCES);
    } catch (\Throwable $problem) {
        // A file this probe cannot follow is reported as such -- never as
        // "nothing found", which would be a check passing because it could
        // not look (#763's rule).
        $out['results'][$path] = [
            'error'    => get_class($problem) . ': ' . $problem->getMessage(),
            'findings' => [],
            'unread'   => 0,
        ];
    }
}
echo json_encode($out, JSON_UNESCAPED_SLASHES);
exit(0);


/**
 * The state of one file while it is examined. Plain arrays rather than a
 * class, so the probe stays one readable file.
 *
 * @param array<string, array<string, string>> $columns
 * @param array<string, int>                   $sources
 * @return array<string, mixed>
 */
function examine(string $src, array $columns, array $sources): array
{
    // ── 1. Significant tokens only ───────────────────────────────────────
    // ⚠️ `TOKEN_PARSE`, NOT THE PLAIN CALL. Without it `token_get_all()` does
    //    not fail on PHP that does not parse -- it warns and hands back what
    //    it managed, so a broken file came back as "nothing found" and
    //    `check_clock_time_not_a_moment.py` PASSED on it (#777 round 10).
    //    With it, a file that does not parse throws, and is reported as a file
    //    this probe could not read.
    //
    // Comments and whitespace go: that is the whole reason for the tokeniser.
    // A closing tag or a run of HTML ends a statement, so each becomes `;`.
    $t = [];
    $line = 1;
    foreach (token_get_all($src, TOKEN_PARSE) as $raw) {
        if (is_array($raw) === true) {
            [$id, $text, $line] = $raw;
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT
                || $id === T_OPEN_TAG || $id === T_OPEN_TAG_WITH_ECHO) {
                continue;
            }
            if ($id === T_CLOSE_TAG || $id === T_INLINE_HTML) {
                $t[] = [';', ';', $line];
                continue;
            }
            $t[] = [$id, $text, $line];
        } else {
            $t[] = [$raw, $raw, $line];
        }
    }
    $n = count($t);

    // ── 2. Which bracket closes which ────────────────────────────────────
    $match = array_fill(0, $n, -1);
    $stack = [];
    for ($i = 0; $i < $n; $i++) {
        $k = $t[$i][0];
        if ($k === '(' || $k === '[' || $k === '{' || $k === T_CURLY_OPEN
            || $k === T_DOLLAR_OPEN_CURLY_BRACES || $k === T_ATTRIBUTE) {
            $stack[] = $i;
        } elseif ($k === ')' || $k === ']' || $k === '}') {
            if (count($stack) === 0) {
                throw new \RuntimeException('a closing bracket on line ' . $t[$i][2] . ' has no opener');
            }
            $open = array_pop($stack);
            $match[$open] = $i;
            $match[$i] = $open;
        }
    }
    if (count($stack) > 0) {
        throw new \RuntimeException('an opening bracket on line ' . $t[(int) end($stack)][2] . ' is never closed');
    }

    // ── 3. Function bodies, each its own scope ───────────────────────────
    // Scope 0 is the file's top level. An arrow function (`fn () =>`) is NOT
    // its own scope: it captures the enclosing variables by value, so it is
    // read as part of the function it sits in.
    $scopes = [0 => ['open' => -1, 'close' => $n, 'name' => null, 'at' => -1, 'uses' => [], 'parent' => 0]];
    for ($i = 0; $i < $n; $i++) {
        if ($t[$i][0] !== T_FUNCTION) {
            continue;
        }
        $j = $i + 1;
        $name = null;
        if ($j < $n && $t[$j][0] === '&') {
            $j++;
        }
        $named = $j < $n && $t[$j][0] === T_STRING;
        if ($named === true) {
            $name = strtolower($t[$j][1]);
        }
        // To the parameter list, over it, then to the body or to a `;`
        // (an abstract or interface method has none).
        while ($j < $n && $t[$j][0] !== '(') {
            $j++;
        }
        if ($j >= $n) {
            continue;
        }
        $j = $match[$j] + 1;
        $body = -1;
        $uses = [];
        while ($j < $n) {
            $k = $t[$j][0];
            if ($k === '{') {
                $body = $j;
                break;
            }
            if ($k === ';') {
                break;
            }
            if ($k === T_USE && ($j + 1) < $n && $t[$j + 1][0] === '(') {
                // `function (...) use ($a, &$b)`: what the closure takes from
                // the function it is written in.
                for ($u = $j + 2; $u < $match[$j + 1]; $u++) {
                    if ($t[$u][0] === T_VARIABLE) {
                        $uses[] = strtolower($t[$u][1]);
                    }
                }
            }
            if ($k === '(' || $k === '[') {
                $j = $match[$j];
            }
            $j++;
        }
        if ($body === -1) {
            continue;
        }
        if ($named === false) {
            // `$name = function ...` or `$name = static function ...`
            $b = $i - 1;
            if ($b >= 0 && $t[$b][0] === T_STATIC) {
                $b--;
            }
            if ($b >= 1 && $t[$b][0] === '=' && $t[$b - 1][0] === T_VARIABLE) {
                $name = strtolower($t[$b - 1][1]);
            }
        }
        $scopes[] = ['open' => $body, 'close' => $match[$body], 'name' => $name,
            'at' => $i, 'uses' => $uses, 'parent' => 0];
    }
    // The innermost body containing each token. Bodies are visited in order of
    // their opening, so a nested body overwrites its parent's claim.
    $owner = array_fill(0, $n, 0);
    $order = array_keys($scopes);
    usort($order, static fn (int $a, int $b): int => $scopes[$a]['open'] <=> $scopes[$b]['open']);
    $bodyAt = [];
    foreach ($order as $s) {
        if ($s === 0) {
            continue;
        }
        $bodyAt[$scopes[$s]['open']] = $s;
        for ($i = $scopes[$s]['open'] + 1; $i < $scopes[$s]['close']; $i++) {
            $owner[$i] = $s;
        }
    }
    // The function each closure is written in, for its `use` list.
    foreach ($scopes as $s => $scope) {
        if ($s !== 0) {
            $scopes[$s]['parent'] = $owner[$scope['at']];
        }
    }

    // Every string literal of each body, for a column list built at run time.
    $literals = [];
    for ($i = 0; $i < $n; $i++) {
        if ($t[$i][0] === T_CONSTANT_ENCAPSED_STRING) {
            $literals[$owner[$i]][] = strtolower(substr((string) $t[$i][1], 1, -1));
        }
    }

    $file = [
        't' => $t, 'n' => $n, 'match' => $match, 'scopes' => $scopes,
        'bodyAt' => $bodyAt, 'owner' => $owner, 'sources' => $sources,
        'literals' => $literals,
        'M' => [], 'F' => [], 'retM' => [], 'retF' => [],
    ];

    // ── 4. Every assignment, and every `foreach`, in each scope ──────────
    // An assignment is `$name`, any number of `[...]`, then `=` or a compound
    // operator. What is assigned runs to the end of the expression.
    $assignOps = ['=', T_CONCAT_EQUAL, T_COALESCE_EQUAL, T_PLUS_EQUAL, T_MINUS_EQUAL,
        T_MUL_EQUAL, T_DIV_EQUAL];
    $assigns = [];   // list of [scope, varName, from, to, line, operator]
    for ($i = 0; $i < $n; $i++) {
        if ($t[$i][0] !== T_VARIABLE) {
            continue;
        }
        if ($i > 0 && in_array($t[$i - 1][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, '$'], true) === true) {
            continue;
        }
        $j = $i + 1;
        while ($j < $n && $t[$j][0] === '[') {
            $j = $match[$j] + 1;
        }
        if ($j >= $n || in_array($t[$j][0], $assignOps, true) === false) {
            continue;
        }
        $from = $j + 1;
        $assigns[] = [$owner[$i], strtolower($t[$i][1]), $from, expressionEnd($file, $from), $t[$i][2],
            (string) $t[$j][1], $j > $i + 1, $i];
    }
    for ($i = 0; $i < $n; $i++) {
        if ($t[$i][0] !== T_FOREACH || ($i + 1) >= $n || $t[$i + 1][0] !== '(') {
            continue;
        }
        $open = $i + 1;
        $close = $match[$open];
        $as = -1;
        for ($j = $open + 1; $j < $close; $j++) {
            if ($t[$j][0] === T_AS) {
                $as = $j;
                break;
            }
            if ($t[$j][0] === '(' || $t[$j][0] === '[') {
                $j = $match[$j];
            }
        }
        if ($as === -1) {
            continue;
        }
        for ($j = $as + 1; $j < $close; $j++) {
            if ($t[$j][0] === T_VARIABLE) {
                $assigns[] = [$owner[$i], strtolower($t[$j][1]), $open + 1, $as, $t[$j][2], '=', false, $j];
            }
        }
    }

    // ── 5. Which variables (and which callables) came through a moment ───
    // Two kinds, kept apart because they mean different things:
    //   M -- holds a MOMENT built from something written (a timestamp or a
    //        DateTime object);
    //   F -- holds TEXT made by formatting such a moment: what gets stored.
    // Each records the line the moment was first built on, for the message.
    for ($round = 0; $round < 20; $round++) {
        $changed = false;
        foreach ($assigns as [$s, $var, $from, $to]) {
            $m = momentIn($file, $s, $from, $to);
            $f = formattedIn($file, $s, $from, $to);
            if ($m !== null && isset($file['M'][$s][$var]) === false) {
                $file['M'][$s][$var] = $m;
                $changed = true;
            }
            if ($f !== null && isset($file['F'][$s][$var]) === false) {
                $file['F'][$s][$var] = $f;
                $changed = true;
            }
        }
        // A `return` hands its kind to every caller in the file.
        for ($i = 0; $i < $n; $i++) {
            if ($t[$i][0] !== T_RETURN) {
                continue;
            }
            $s = $owner[$i];
            $name = $scopes[$s]['name'];
            if ($s === 0 || $name === null) {
                continue;
            }
            $to = expressionEnd($file, $i + 1);
            $m = momentIn($file, $s, $i + 1, $to);
            $f = formattedIn($file, $s, $i + 1, $to);
            if ($m !== null && isset($file['retM'][$name]) === false) {
                $file['retM'][$name] = $m;
                $changed = true;
            }
            if ($f !== null && isset($file['retF'][$name]) === false) {
                $file['retF'][$name] = $f;
                $changed = true;
            }
        }
        if ($changed === false) {
            break;
        }
    }

    // ── 6. The writes ────────────────────────────────────────────────────
    $findings = [];
    $unread = 0;
    $unreadAt = [];   // the lines of the bind calls whose statement could not be read
    $judged = [];     // the lines of the statements that write a clock column
    for ($i = 0; $i < $n; $i++) {
        if ($t[$i][0] !== T_STRING || ($i + 1) >= $n || $t[$i + 1][0] !== '(') {
            continue;
        }
        $method = strtolower($t[$i][1]);
        $isMethod = $i > 0 && in_array($t[$i - 1][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true) === true;
        $open = $i + 1;
        $s = $owner[$i];
        $args = arguments($file, $open);
        $sql = null;
        $values = [];          // list of [placeholder index or -1, from, to]
        $sqlScope = $s;

        if ($isMethod === true && in_array($method, BINDERS, true) === true) {
            if (count($args) === 0) {
                continue;   // `execute()` with nothing handed over
            }
            if ($i < 2 || $t[$i - 2][0] !== T_VARIABLE) {
                $unread++;
                $unreadAt[] = $t[$i][2];
                continue;
            }
            $found = statementSql($file, $assigns, strtolower($t[$i - 2][1]), $s, $i);
            $sql = $found === null ? null : $found[0];
            $sqlScope = $found === null ? $s : $found[1];
            if ($sql === null) {
                $unread++;
                $unreadAt[] = $t[$i][2];
                continue;
            }
            if ($method === 'bind_param') {
                // The first argument is the type string; the rest fill the
                // placeholders in order.
                foreach (array_slice($args, 1) as $at => [$from, $to]) {
                    $values[] = [$at, $from, $to];
                }
            } elseif ($method === 'execute') {
                $values = arrayElements($file, $args[0][0], $args[0][1]);
            } else {
                // PDO: bindValue(1, $v) -- a literal number is a 1-based place.
                $place = -1;
                if ($args[0][1] - $args[0][0] === 1 && $t[$args[0][0]][0] === T_LNUMBER) {
                    $place = (int) $t[$args[0][0]][1] - 1;
                }
                if (isset($args[1]) === true) {
                    $values[] = [$place, $args[1][0], $args[1][1]];
                }
            }
        } elseif (($isMethod === true && in_array($method, ['query', 'real_query', 'multi_query'], true) === true)
            || ($isMethod === false && in_array($method, ['mysqli_query', 'mysqli_real_query'], true) === true)) {
            // SQL with the values written INTO it. Which value fills which
            // column cannot be told from here, so this is judged as a whole.
            $sqlArg = $isMethod === true ? ($args[0] ?? null) : ($args[1] ?? null);
            if ($sqlArg === null) {
                continue;
            }
            $sql = literalText($file, $sqlArg[0], $sqlArg[1]);
            $values[] = [-1, $sqlArg[0], $sqlArg[1]];
        } elseif ($isMethod === true && $method === 'execute_query') {
            if (count($args) < 2) {
                continue;
            }
            $sql = literalText($file, $args[0][0], $args[0][1]);
            $values = arrayElements($file, $args[1][0], $args[1][1]);
        } else {
            continue;
        }

        $places = placeholderColumns($sql, $columns, scopeLiterals($file, $sqlScope));
        if ($places === null) {
            continue;   // not an INSERT, REPLACE or UPDATE of a clock column
        }
        $judged[] = $t[$i][2];
        foreach ($values as [$place, $from, $to]) {
            // ── An ARRAY of values: `...$params`, or `execute($params)` ──
            // Each value pushed onto the array is judged on its own, against
            // the `'<column> = ?'` literal written just before it -- the
            // house shape for a column list built at run time. Measured: the
            // statement-wide judgement reported `attendance/api/update.php`,
            // whose DATE value (`sessionDate = ?`) shares a run-time UPDATE
            // with a TIME column it does not fill.
            $arrayVar = null;
            if ($t[$from][0] === T_ELLIPSIS && $to - $from === 2 && $t[$from + 1][0] === T_VARIABLE) {
                $arrayVar = strtolower($t[$from + 1][1]);
            } elseif ($place === -1 && $to - $from === 1 && $t[$from][0] === T_VARIABLE) {
                $arrayVar = strtolower($t[$from][1]);
            }
            if ($arrayVar !== null) {
                foreach (arrayAssignments($file, $assigns, $s, $arrayVar, $i) as [$as, $atok, $afrom, $ato, $intoArray]) {
                    $builtOn = formattedIn($file, $as, $afrom, $ato);
                    if ($builtOn === null) {
                        continue;
                    }
                    $paired = $intoArray === true ? pairedColumn($file, $atok) : null;
                    if ($paired !== null) {
                        $column = '';
                        foreach ($places['tables'] as $table) {
                            $column = $columns[$table][$paired] ?? $column;
                        }
                        if ($column === '') {
                            continue;   // paired with a column that is not a clock reading
                        }
                        $hitColumns = [$column];
                        $how = 'paired with its SET literal';
                    } else {
                        $hitColumns = $places['written'];
                        $how = 'statement';
                    }
                    if (count($hitColumns) === 0) {
                        continue;
                    }
                    $findings[] = [
                        'line'     => $t[$i][2],
                        'variable' => (string) $t[$atok][1],
                        'builtOn'  => $builtOn,
                        'columns'  => $hitColumns,
                        'call'     => $method,
                        'matched'  => $how,
                    ];
                }
                continue;
            }
            $builtOn = formattedIn($file, $s, $from, $to);
            if ($builtOn === null) {
                continue;
            }
            if ($place >= 0 && $places['mapped'] === true && isset($places['byPlace'][$place]) === true) {
                $column = $places['byPlace'][$place];
                if ($column === '') {
                    continue;   // this value fills a column that is not a clock reading
                }
                $hitColumns = [$column];
                $how = 'placeholder ' . ($place + 1);
            } else {
                // Cannot tell which column it fills: judge the statement.
                $hitColumns = $places['written'];
                if (count($hitColumns) === 0) {
                    continue;
                }
                $how = 'statement';
            }
            $findings[] = [
                'line'     => $t[$i][2],
                'variable' => valueName($file, $from, $to),
                'builtOn'  => $builtOn,
                'columns'  => $hitColumns,
                'call'     => $method,
                'matched'  => $how,
            ];
        }
    }

    // Which function each moment was BUILT in, so an exemption can name the
    // place it forgives and not just a variable. Measured, a variable alone is
    // not enough: the CSV import before round 6 of #777 stored its round trip
    // under the SAME names (`$startSql`, `$endSql`) as today's deliberate
    // fallback, so a fault reintroduced at the INSERT would have been forgiven
    // by an exemption written for a different function.
    $lineOwner = [];
    for ($i = 0; $i < $n; $i++) {
        if (isset($lineOwner[$t[$i][2]]) === false) {
            $lineOwner[$t[$i][2]] = $owner[$i];
        }
    }
    foreach ($findings as $at => $found) {
        $where = $lineOwner[$found['builtOn']] ?? 0;
        $findings[$at]['builtIn'] = $where === 0
            ? '(top level)'
            : ($scopes[$where]['name'] ?? '(unnamed function)');
    }

    return ['error' => null, 'findings' => $findings, 'unread' => $unread,
        'unreadAt' => $unreadAt, 'judged' => $judged];
}


/**
 * What a variable holds in scope $s -- and, for a closure, what a variable in
 * its `use` list held in the function it is written in. One level of `use` at
 * a time, following each enclosing closure in turn.
 *
 * Measured, this is not optional: the three places that create an event
 * (`calendar/manage/save.php` twice and `series-repattern.php`) all bind
 * their values INSIDE a `static function (...) use ($stmt, $values)` handed
 * to `Events::saveUnderFreeSlug()`, so without it the probe could not see the
 * statement or the values of any of them.
 */
function heldBy(array $file, string $kind, int $s, string $v): ?int
{
    for ($depth = 0; $depth < 8; $depth++) {
        if (isset($file[$kind][$s][$v]) === true) {
            return $file[$kind][$s][$v];
        }
        if ($s === 0 || in_array($v, $file['scopes'][$s]['uses'], true) === false) {
            return null;
        }
        $s = $file['scopes'][$s]['parent'];
    }
    return null;
}


/**
 * Every assignment that can have put a value into array $var before token
 * $before in scope $s: its own assignments, a whole copy of another array
 * (`$values = $insertValues;`), and -- when $var came in through a closure's
 * `use` list -- the assignments in the function the closure is written in.
 *
 * @return list<array{0:int,1:int,2:int,3:int,4:bool}> scope, token, from, to, intoArray
 */
function arrayAssignments(array $file, array $assigns, int $s, string $var, int $before, int $depth = 0): array
{
    if ($depth > 8) {
        return [];
    }
    $t = $file['t'];
    $out = [];
    foreach ($assigns as [$as, $av, $afrom, $ato, $aline, $aop, $intoArray, $atok]) {
        if ($as !== $s || $av !== $var || $atok >= $before) {
            continue;
        }
        // A whole copy (`$values = $insertValues;`) is followed INTO the array
        // it copies and is not itself reported -- otherwise one value would be
        // reported twice, once under each name.
        if ($intoArray === false && $ato - $afrom === 1 && $t[$afrom][0] === T_VARIABLE
            && strtolower($t[$afrom][1]) !== $var) {
            $out = array_merge($out, arrayAssignments(
                $file, $assigns, $s, strtolower($t[$afrom][1]), $atok, $depth + 1
            ));
            continue;
        }
        $out[] = [$as, $atok, $afrom, $ato, $intoArray];
    }
    if ($s !== 0 && in_array($var, $file['scopes'][$s]['uses'], true) === true) {
        $out = array_merge($out, arrayAssignments(
            $file, $assigns, $file['scopes'][$s]['parent'], $var, $file['scopes'][$s]['at'], $depth + 1
        ));
    }
    return $out;
}


/**
 * The column a value pushed onto an array is paired with: the nearest
 * `'<column> = ?'` literal BEFORE the push in the same block, as in
 *
 *     $set[]    = 'sessionDate = ?';
 *     $types   .= 's';
 *     $params[] = $value;
 *
 * Null when the walk back meets the edge of the block (`{` or `}`) first --
 * for instance when the column name is itself a variable (`$col . ' = ?'`),
 * which is how the events API's update endpoint built its list until round 6
 * of #777. Null makes the caller judge the whole statement.
 */
function pairedColumn(array $file, int $at): ?string
{
    $t = $file['t'];
    for ($j = $at - 1, $steps = 0; $j >= 0 && $steps < 400; $j--, $steps++) {
        $k = $t[$j][0];
        if ($k === ')' || $k === ']') {
            $j = $file['match'][$j];
            continue;
        }
        if ($k === '{' || $k === '}' || $k === '(' || $k === '[' || $k === T_CURLY_OPEN) {
            return null;
        }
        if ($k === T_CONSTANT_ENCAPSED_STRING) {
            $text = str_replace('`', '', substr((string) $t[$j][1], 1, -1));
            if (preg_match('/^\s*(?:[a-z0-9_]+\.)?([a-z0-9_]+)\s*=\s*\?\s*$/i', $text, $m) === 1) {
                return strtolower($m[1]);
            }
        }
    }
    return null;
}


/**
 * Every string literal in one function body, lower-cased. Used only when a
 * statement's column list is assembled at run time: the clock columns of its
 * table that the same function NAMES are taken to be the ones it may write.
 *
 * @return list<string>
 */
function scopeLiterals(array $file, int $s): array
{
    return $file['literals'][$s] ?? [];
}


/** How a handed-over value is named in a report: its variable, or "(inline)". */
function valueName(array $file, int $from, int $to): string
{
    $t = $file['t'];
    for ($j = $from; $j < $to; $j++) {
        if ($t[$j][0] === T_VARIABLE) {
            return (string) $t[$j][1];
        }
    }
    return '(inline)';
}


/**
 * Where an expression that starts at $from ends: the first `;` at its own
 * level, or a `,` / closing bracket that would leave that level. A nested
 * function body is stepped over whole.
 */
function expressionEnd(array $file, int $from): int
{
    $t = $file['t'];
    for ($j = $from; $j < $file['n']; $j++) {
        if (isset($file['bodyAt'][$j]) === true) {
            $j = $file['scopes'][$file['bodyAt'][$j]]['close'];
            continue;
        }
        $k = $t[$j][0];
        // ⚠️ AN ARROW FUNCTION'S OWN `=>` IS NOT THE END. `=>` ends an array
        //    key or a `foreach` key, so the first version stopped there and
        //    cut `$fmt = fn ($x) => date(..., strtotime($x));` off at the
        //    arrow -- the body was never read, while the header said arrow
        //    functions are read as part of the function they sit in (found
        //    by plant P21 in round 4 of #785). Step over the parameters, any
        //    return type and the arrow, and read the body as part of this
        //    expression.
        if ($k === T_FN) {
            $p = $j + 1;
            while ($p < $file['n'] && $t[$p][0] !== '(') {
                $p++;
            }
            if ($p < $file['n']) {
                $p = $file['match'][$p] + 1;
                while ($p < $file['n'] && $t[$p][0] !== T_DOUBLE_ARROW) {
                    $p++;
                }
                $j = $p;
                continue;
            }
        }
        if ($k === '(' || $k === '[' || $k === '{' || $k === T_CURLY_OPEN || $k === T_DOLLAR_OPEN_CURLY_BRACES) {
            $j = $file['match'][$j];
            continue;
        }
        if ($k === ';' || $k === ',' || $k === ')' || $k === ']' || $k === '}' || $k === T_AS || $k === T_DOUBLE_ARROW) {
            return $j;
        }
    }
    return $file['n'];
}


/**
 * The arguments of the call whose `(` is at $open, as [from, to) ranges.
 *
 * @return list<array{0:int,1:int}>
 */
function arguments(array $file, int $open): array
{
    $t = $file['t'];
    $close = $file['match'][$open];
    $args = [];
    $start = $open + 1;
    for ($j = $open + 1; $j < $close; $j++) {
        $k = $t[$j][0];
        if ($k === '(' || $k === '[' || $k === '{' || $k === T_CURLY_OPEN || $k === T_DOLLAR_OPEN_CURLY_BRACES) {
            $j = $file['match'][$j];
            continue;
        }
        if ($k === ',') {
            $args[] = [$start, $j];
            $start = $j + 1;
        }
    }
    if ($start < $close) {
        $args[] = [$start, $close];
    }
    return $args;
}


/**
 * The elements of an array literal `[a, b]` / `array(a, b)` in [$from, $to),
 * each as [index, from, to]. Anything else -- a variable holding an array --
 * cannot be matched to places, so it comes back as ONE value at place -1.
 *
 * @return list<array{0:int,1:int,2:int}>
 */
function arrayElements(array $file, int $from, int $to): array
{
    $t = $file['t'];
    $open = -1;
    if ($t[$from][0] === '[' && $file['match'][$from] === $to - 1) {
        $open = $from;
    } elseif ($t[$from][0] === T_ARRAY && ($from + 1) < $to && $t[$from + 1][0] === '('
        && $file['match'][$from + 1] === $to - 1) {
        $open = $from + 1;
    }
    if ($open === -1) {
        return [[-1, $from, $to]];
    }
    $out = [];
    foreach (arguments($file, $open) as $at => [$a, $b]) {
        $out[] = [$at, $a, $b];
    }
    return $out;
}


/** Whether a range holds a variable, nested function bodies excluded. */
function holdsVariable(array $file, int $from, int $to): bool
{
    for ($j = $from; $j < $to; $j++) {
        if (isset($file['bodyAt'][$j]) === true) {
            $j = $file['scopes'][$file['bodyAt'][$j]]['close'];
            continue;
        }
        if ($file['t'][$j][0] === T_VARIABLE) {
            return true;
        }
    }
    return false;
}


/** A name token's last segment, lower-cased: `\Foo\DateTime` is `datetime`. */
function bareName(array $tok): string
{
    $text = ltrim((string) $tok[1], '\\');
    $at = strrpos($text, '\\');
    return strtolower($at === false ? $text : substr($text, $at + 1));
}


/**
 * The line a moment is built on inside [$from, $to) of scope $s, or null.
 *
 * A moment is BUILT by a moment function whose value argument holds a
 * variable, and CARRIED by a variable or a call already known to hold one.
 * The arguments of any other call are a wall (see the header).
 */
function momentIn(array $file, int $s, int $from, int $to): ?int
{
    $t = $file['t'];
    $names = [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED];
    for ($j = $from; $j < $to; $j++) {
        if (isset($file['bodyAt'][$j]) === true) {
            $j = $file['scopes'][$file['bodyAt'][$j]]['close'];
            continue;
        }
        $k = $t[$j][0];
        if ($k === T_VARIABLE) {
            $v = strtolower($t[$j][1]);
            $held = heldBy($file, 'M', $s, $v);
            if ($held !== null) {
                return $held;
            }
            // A closure held in a variable and called here.
            if (($j + 1) < $to && $t[$j + 1][0] === '(') {
                if (isset($file['retM'][$v]) === true) {
                    return $file['retM'][$v];
                }
                $j = $file['match'][$j + 1];
            }
            continue;
        }
        if (in_array($k, $names, true) === false || ($j + 1) >= $file['n'] || $t[$j + 1][0] !== '(') {
            continue;
        }
        $prev = $j > 0 ? $t[$j - 1][0] : null;
        $name = bareName($t[$j]);
        $open = $j + 1;
        $close = $file['match'][$open];
        $args = arguments($file, $open);
        $argsCarry = momentIn($file, $s, $open + 1, $close);
        if ($prev === T_NEW) {
            // `new DateTime(...)` / `new \DateTimeImmutable(...)`
            // ⚠️ THE "CARRIED MOMENT" TEST IS REACHABLE, AND ROUND 4 OF #785
            //    DELETED IT AS UNREACHABLE. A moment in the value argument is
            //    NOT always a variable there: `new \DateTimeImmutable('@' .
            //    postedTs())`, where `postedTs()` returns `strtotime($_POST
            //    ...)`, holds no variable at all, and is a moment carried in by
            //    a call. Round 3 reported it; round 4 did not (found by the
            //    independent check's round 4). "Measured unreachable" meant
            //    only that no case I had written could reach it. Plants
            //    P31-P33.
            //
            //    The same for a FORMATTED moment re-read: `new
            //    \DateTimeImmutable(postedStart())`, where `postedStart()`
            //    returns `date(..., strtotime($_POST ...))`, rebuilds a moment
            //    from text that already went through one. That was missed
            //    before round 4 too; it is cheap to see, so it is seen.
            if (in_array($name, CONSTRUCTED, true) === true) {
                if (isset($args[0]) === true && holdsVariable($file, $args[0][0], $args[0][1]) === true) {
                    return $t[$j][2];
                }
                if ($argsCarry !== null) {
                    return $argsCarry;
                }
                $reread = formattedIn($file, $s, $open + 1, $close);
                if ($reread !== null) {
                    return $reread;
                }
            }
            $j = $close;
            continue;
        }
        if ($prev === T_DOUBLE_COLON) {
            // `X::createFromFormat(format, VALUE)` -- and, as for `new`, a
            // moment carried in by a call (`createFromFormat('U', (string)
            // postedTs())`, plant P32) or formatted text re-read.
            if ($name === 'createfromformat') {
                if (isset($args[1]) === true && holdsVariable($file, $args[1][0], $args[1][1]) === true) {
                    return $t[$j][2];
                }
                if ($argsCarry !== null) {
                    return $argsCarry;
                }
                $reread = formattedIn($file, $s, $open + 1, $close);
                if ($reread !== null) {
                    return $reread;
                }
            }
            if (isset($file['retM'][$name]) === true) {
                return $file['retM'][$name];
            }
            $j = $close;
            continue;
        }
        if ($prev === T_OBJECT_OPERATOR || $prev === T_NULLSAFE_OBJECT_OPERATOR) {
            // The object in front was read on the way here. `->setTime($h,
            // $m)` and its family resolve a reading of their own.
            if (in_array($name, SETTERS, true) === true && holdsVariable($file, $open + 1, $close) === true) {
                return $t[$j][2];
            }
            if (isset($file['retM'][$name]) === true) {
                return $file['retM'][$name];
            }
            $j = $close;
            continue;
        }
        if ($prev === T_FUNCTION) {
            $j = $close;
            continue;
        }
        if (isset($file['sources'][$name]) === true) {
            $want = $file['sources'][$name];
            if ($want === -1 && holdsVariable($file, $open + 1, $close) === true) {
                return $t[$j][2];
            }
            if ($want >= 0 && isset($args[$want]) === true
                && holdsVariable($file, $args[$want][0], $args[$want][1]) === true) {
                return $t[$j][2];
            }
            // `strtotime('+1 day', $moment)` carries the moment it is given.
            if ($argsCarry !== null) {
                return $argsCarry;
            }
            $j = $close;
            continue;
        }
        if (isset($file['retM'][$name]) === true) {
            return $file['retM'][$name];
        }
        if (in_array($name, PASS_THROUGH, true) === true) {
            continue;   // read the arguments as part of this expression
        }
        $j = $close;
    }
    return null;
}


/**
 * The line a moment was built on, if [$from, $to) FORMATS one back into text
 * -- or carries text already made that way -- else null.
 */
function formattedIn(array $file, int $s, int $from, int $to): ?int
{
    $t = $file['t'];
    $names = [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED];
    for ($j = $from; $j < $to; $j++) {
        if (isset($file['bodyAt'][$j]) === true) {
            $j = $file['scopes'][$file['bodyAt'][$j]]['close'];
            continue;
        }
        $k = $t[$j][0];
        if ($k === T_VARIABLE) {
            $v = strtolower($t[$j][1]);
            $held = heldBy($file, 'F', $s, $v);
            if ($held !== null) {
                return $held;
            }
            if (($j + 1) < $to && $t[$j + 1][0] === '(') {
                if (isset($file['retF'][$v]) === true) {
                    return $file['retF'][$v];
                }
                $j = $file['match'][$j + 1];
            }
            continue;
        }
        if (in_array($k, $names, true) === false || ($j + 1) >= $file['n'] || $t[$j + 1][0] !== '(') {
            continue;
        }
        $prev = $j > 0 ? $t[$j - 1][0] : null;
        $name = bareName($t[$j]);
        $open = $j + 1;
        $close = $file['match'][$open];
        if ($prev === T_OBJECT_OPERATOR || $prev === T_NULLSAFE_OBJECT_OPERATOR) {
            // `$moment->format(...)`: the moment is the chain in front.
            if ($name === 'format') {
                $m = momentIn($file, $s, receiverStart($file, $j - 1), $j - 1);
                if ($m !== null) {
                    return $m;
                }
            }
            if (isset($file['retF'][$name]) === true) {
                return $file['retF'][$name];
            }
            $j = $close;
            continue;
        }
        if ($prev === T_DOUBLE_COLON) {
            if (isset($file['retF'][$name]) === true) {
                return $file['retF'][$name];
            }
            $j = $close;
            continue;
        }
        if ($prev === T_NEW || $prev === T_FUNCTION) {
            $j = $close;
            continue;
        }
        if (in_array($name, FORMATTERS, true) === true) {
            $m = momentIn($file, $s, $open + 1, $close);
            if ($m !== null) {
                return $m;
            }
            $j = $close;
            continue;
        }
        if (isset($file['retF'][$name]) === true) {
            return $file['retF'][$name];
        }
        if (in_array($name, PASS_THROUGH, true) === true) {
            continue;
        }
        $j = $close;
    }
    return null;
}


/**
 * Where the object a `->` is applied to begins: back over variables, names,
 * calls, indexes and further arrows, and over one `new`.
 */
function receiverStart(array $file, int $arrow): int
{
    $t = $file['t'];
    $start = $arrow;
    $j = $arrow - 1;
    while ($j >= 0) {
        $k = $t[$j][0];
        if ($k === ')' || $k === ']' || $k === '}') {
            $start = $file['match'][$j];
            $j = $file['match'][$j] - 1;
            continue;
        }
        if (in_array($k, [T_VARIABLE, T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_STATIC], true) === true) {
            $start = $j;
            $j--;
            if ($j >= 0 && in_array($t[$j][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true) === true) {
                $j--;
                continue;
            }
            if ($j >= 0 && $t[$j][0] === T_NEW) {
                $start = $j;
            }
            break;
        }
        break;
    }
    return $start;
}


/**
 * The text of the string literals in [$from, $to), joined as PHP would join
 * them. A variable written into the text stands where a value would, as `?`.
 */
function literalText(array $file, int $from, int $to): string
{
    $t = $file['t'];
    $text = '';
    for ($j = $from; $j < $to; $j++) {
        $k = $t[$j][0];
        if ($k === T_CONSTANT_ENCAPSED_STRING) {
            $text .= substr((string) $t[$j][1], 1, -1);
        } elseif ($k === T_ENCAPSED_AND_WHITESPACE) {
            $text .= (string) $t[$j][1];
        } elseif ($k === T_VARIABLE || $k === T_CURLY_OPEN) {
            $text .= ' ? ';
        }
    }
    return $text;
}


/**
 * The SQL of the statement held in $stmt at token $at: the most recent
 * `prepare()` assigned to that variable earlier in the same function. When
 * `prepare()` is handed a variable, that variable's own assignments before it
 * are read (`=` starts the text again, `.=` adds to it). A statement that
 * came into a closure through its `use` list is looked for in the function
 * the closure is written in. Null when the SQL cannot be read from the source.
 *
 * @return array{0:string,1:int}|null the SQL, and the scope it was found in
 */
function statementSql(array $file, array $assigns, string $stmt, int $scope, int $at, int $depth = 0): ?array
{
    $t = $file['t'];
    $best = null;
    foreach ($assigns as [$s, $var, $from, $to]) {
        if ($s !== $scope || $var !== $stmt || $from >= $at) {
            continue;
        }
        for ($j = $from; $j < $to; $j++) {
            if ($t[$j][0] === T_STRING && in_array(strtolower($t[$j][1]), ['prepare', 'mysqli_prepare'], true) === true
                && ($j + 1) < $file['n'] && $t[$j + 1][0] === '(') {
                $best = $j + 1;
            }
        }
    }
    if ($best === null) {
        if ($depth < 8 && $scope !== 0 && in_array($stmt, $file['scopes'][$scope]['uses'], true) === true) {
            return statementSql($file, $assigns, $stmt, $file['scopes'][$scope]['parent'],
                $file['scopes'][$scope]['at'], $depth + 1);
        }
        return null;
    }
    $args = arguments($file, $best);
    if (count($args) === 0) {
        return null;
    }
    // `mysqli_prepare($link, $sql)` puts the SQL last.
    $range = $args[count($args) - 1];
    if ($range[1] - $range[0] === 1 && $t[$range[0]][0] === T_VARIABLE) {
        $sqlVar = strtolower($t[$range[0]][1]);
        $text = null;
        foreach ($assigns as [$s, $var, $from, $to, $line, $op]) {
            if ($s !== $scope || $var !== $sqlVar || $from >= $best) {
                continue;
            }
            $piece = literalText($file, $from, $to);
            $text = $op === '.=' ? (string) $text . $piece : $piece;
        }
        return $text === null ? null : [$text, $scope];
    }
    $text = literalText($file, $range[0], $range[1]);
    return trim($text) === '' ? null : [$text, $scope];
}


/**
 * For an INSERT, REPLACE or UPDATE: which clock column each `?` fills.
 *
 * Returns null when the SQL writes no clock column at all. Otherwise:
 *   written -- every clock column the statement writes, as `table.column`;
 *   byPlace -- for each `?` in order, the clock column it fills, or '' when
 *              it fills something else (a WHERE value, a DATE column ...);
 *   mapped  -- false when some clock column is written by something this
 *              could not match to a `?` (INSERT ... SELECT), in which case the
 *              caller judges the whole statement.
 *
 * A column list ASSEMBLED AT RUN TIME -- `'UPDATE tblEvents SET ' .
 * implode(', ', $set)`, which is exactly how the events API's update endpoint
 * stored the moved hour until round 6 of #777 -- leaves nothing to read
 * between SET and WHERE. For that shape the clock columns of the table that
 * the same function NAMES in a string literal (its list of allowed fields) are
 * taken as written, and `mapped` is false so the statement is judged whole.
 * Measured: without this, that historical fault was invisible here.
 *
 * @param array<string, array<string, string>> $columns
 * @param list<string> $literals the function's string literals, lower-cased
 * @return array{written: list<string>, byPlace: list<string>, tables: list<string>, mapped: bool}|null
 */
function placeholderColumns(string $sql, array $columns, array $literals = []): ?array
{
    $sql = strtolower(str_replace('`', '', $sql));
    $len = strlen($sql);
    // Where each `?` is, outside quoted text.
    $marks = [];
    $quote = '';
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($quote !== '') {
            if ($c === '\\') {
                $i++;
            } elseif ($c === $quote) {
                $quote = '';
            }
            continue;
        }
        if ($c === "'" || $c === '"') {
            $quote = $c;
        } elseif ($c === '?') {
            $marks[] = $i;
        }
    }
    $clock = static function (string $table, string $col) use ($columns): string {
        $col = trim($col);
        $dot = strrpos($col, '.');
        if ($dot !== false) {
            $col = substr($col, $dot + 1);
        }
        return $columns[$table][$col] ?? '';
    };
    $regions = [];   // list of [from, to, clockColumn or '']
    $tables = [];
    $written = [];
    $coveredWritten = [];
    $runTime = false;
    // The clock columns of $table that this function names in a literal.
    $named = static function (string $table) use ($columns, $literals): array {
        $out = [];
        foreach ($columns[$table] ?? [] as $col => $label) {
            foreach ($literals as $text) {
                if (preg_match('/(?<![a-z0-9_])' . preg_quote($col, '/') . '(?![a-z0-9_])/', $text) === 1) {
                    $out[] = $label;
                    break;
                }
            }
        }
        return $out;
    };

    // INSERT/REPLACE INTO t (a, b) VALUES (x, y), (x, y) [ON DUPLICATE KEY UPDATE ...]
    if (preg_match_all('/\b(?:insert(?:\s+ignore)?|replace)\s+into\s+([a-z0-9_]+)\s*\(([^)]*)\)/', $sql, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
        foreach ($all as $one) {
            $table = $one[1][0];
            $tables[$table] = true;
            $cols = array_map('trim', explode(',', $one[2][0]));
            if (str_contains($one[2][0], '?') === true) {
                // `INSERT INTO t (` . implode(...) . `)`: built at run time.
                foreach ($named($table) as $label) {
                    $written[$label] = true;
                }
                $runTime = true;
            }
            foreach ($cols as $col) {
                $name = $clock($table, $col);
                if ($name !== '') {
                    $written[$name] = true;
                }
            }
            $p = $one[0][1] + strlen($one[0][0]);
            if (preg_match('/\G\s*values\s*/', $sql, $v, 0, $p) === 1) {
                $p += strlen($v[0]);
                while ($p < $len && $sql[$p] === '(') {
                    $tuple = splitTopLevel($sql, $p);
                    if ($tuple === null) {
                        break;
                    }
                    foreach ($tuple['parts'] as $at => [$a, $b]) {
                        $name = isset($cols[$at]) === true ? $clock($table, $cols[$at]) : '';
                        $regions[] = [$a, $b, $name];
                        if ($name !== '') {
                            $coveredWritten[$name] = true;
                        }
                    }
                    $p = $tuple['end'] + 1;
                    if (preg_match('/\G\s*,\s*/', $sql, $v, 0, $p) === 1) {
                        $p += strlen($v[0]);
                        continue;
                    }
                    break;
                }
            }
            if (preg_match('/\G\s*on\s+duplicate\s+key\s+update\s+/', $sql, $v, 0, $p) === 1) {
                foreach (assignments($sql, $p + strlen($v[0])) as [$lhs, $a, $b]) {
                    $name = $clock($table, $lhs);
                    $regions[] = [$a, $b, $name];
                    if ($name !== '') {
                        $written[$name] = true;
                        $coveredWritten[$name] = true;
                    }
                }
            }
        }
    }
    // INSERT INTO t SET a = ?, ...  and  UPDATE t [alias] [JOIN ...] SET a = ?, ...
    if (preg_match_all('/\b(?:insert(?:\s+ignore)?\s+into|update)\s+(.*?)\s+set\s+/', $sql, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
        foreach ($all as $one) {
            $aliases = [];
            if (preg_match_all('/([a-z0-9_]+)(?:\s+as)?(?:\s+([a-z0-9_]+))?/', $one[1][0], $parts, PREG_SET_ORDER) > 0) {
                foreach ($parts as $part) {
                    if (isset($columns[$part[1]]) === false && str_starts_with($part[1], 'tbl') === false) {
                        continue;
                    }
                    $aliases[$part[1]] = $part[1];
                    $tables[$part[1]] = true;
                    if (isset($part[2]) === true && $part[2] !== ''
                        && in_array($part[2], ['join', 'inner', 'left', 'right', 'on', 'set'], true) === false) {
                        $aliases[$part[2]] = $part[1];
                    }
                }
            }
            $list = assignments($sql, $one[0][1] + strlen($one[0][0]));
            $set = substr($sql, $one[0][1] + strlen($one[0][0]));
            if (count($list) === 0 || preg_match('/^\s*\?/', $set) === 1) {
                // `UPDATE t SET ` . implode(', ', $set): built at run time.
                foreach (array_unique(array_values($aliases)) as $table) {
                    foreach ($named($table) as $label) {
                        $written[$label] = true;
                    }
                }
                $runTime = true;
            }
            foreach ($list as [$lhs, $a, $b]) {
                $lhs = trim($lhs);
                $name = '';
                $dot = strpos($lhs, '.');
                if ($dot !== false) {
                    $alias = substr($lhs, 0, $dot);
                    if (isset($aliases[$alias]) === true) {
                        $name = $clock($aliases[$alias], $lhs);
                    }
                } else {
                    foreach (array_unique(array_values($aliases)) as $table) {
                        if ($clock($table, $lhs) !== '') {
                            $name = $clock($table, $lhs);
                        }
                    }
                }
                $regions[] = [$a, $b, $name];
                if ($name !== '') {
                    $written[$name] = true;
                    $coveredWritten[$name] = true;
                }
            }
        }
    }
    if (count($written) === 0) {
        return null;
    }
    $byPlace = [];
    foreach ($marks as $mark) {
        $name = '';
        foreach ($regions as [$a, $b, $col]) {
            if ($mark >= $a && $mark < $b) {
                $name = $col;
                break;
            }
        }
        $byPlace[] = $name;
    }
    return [
        'written' => array_keys($written),
        'byPlace' => $byPlace,
        'tables'  => array_keys($tables),
        'mapped'  => $runTime === false && count($coveredWritten) === count($written),
    ];
}


/**
 * The parts of a bracketed list starting with `(` at $p, split at commas at
 * its own level and outside quoted text. Null when it never closes.
 *
 * @return array{parts: list<array{0:int,1:int}>, end: int}|null
 */
function splitTopLevel(string $sql, int $p): ?array
{
    $len = strlen($sql);
    $depth = 0;
    $quote = '';
    $parts = [];
    $start = $p + 1;
    for ($i = $p; $i < $len; $i++) {
        $c = $sql[$i];
        if ($quote !== '') {
            if ($c === '\\') {
                $i++;
            } elseif ($c === $quote) {
                $quote = '';
            }
            continue;
        }
        if ($c === "'" || $c === '"') {
            $quote = $c;
        } elseif ($c === '(') {
            $depth++;
        } elseif ($c === ')') {
            $depth--;
            if ($depth === 0) {
                $parts[] = [$start, $i];
                return ['parts' => $parts, 'end' => $i];
            }
        } elseif ($c === ',' && $depth === 1) {
            $parts[] = [$start, $i];
            $start = $i + 1;
        }
    }
    return null;
}


/**
 * The assignments of a SET list starting at $p: `a = x, b = f(y, z)`, up to
 * WHERE / ORDER BY / LIMIT / the end, as [left-hand side, valueFrom, valueTo].
 *
 * @return list<array{0:string,1:int,2:int}>
 */
function assignments(string $sql, int $p): array
{
    $len = strlen($sql);
    $end = $len;
    $depth = 0;
    $quote = '';
    for ($i = $p; $i < $len; $i++) {
        $c = $sql[$i];
        if ($quote !== '') {
            if ($c === '\\') {
                $i++;
            } elseif ($c === $quote) {
                $quote = '';
            }
            continue;
        }
        if ($c === "'" || $c === '"') {
            $quote = $c;
        } elseif ($c === '(') {
            $depth++;
        } elseif ($c === ')') {
            $depth--;
            if ($depth < 0) {
                $end = $i;
                break;
            }
        } elseif ($depth === 0 && ($c === ';'
            || preg_match('/\G\s(?:where|order\s+by|limit)\b/', $sql, $m, 0, $i) === 1)) {
            $end = $i;
            break;
        }
    }
    $out = [];
    $start = $p;
    $depth = 0;
    $quote = '';
    for ($i = $p; $i <= $end; $i++) {
        $c = $i < $end ? $sql[$i] : ',';
        if ($quote !== '') {
            if ($c === '\\') {
                $i++;
            } elseif ($c === $quote) {
                $quote = '';
            }
            continue;
        }
        if ($c === "'" || $c === '"') {
            $quote = $c;
        } elseif ($c === '(') {
            $depth++;
        } elseif ($c === ')') {
            $depth--;
        } elseif ($c === ',' && $depth === 0) {
            $piece = substr($sql, $start, $i - $start);
            $eq = strpos($piece, '=');
            if ($eq !== false) {
                $out[] = [substr($piece, 0, $eq), $start + $eq + 1, $i];
            }
            $start = $i + 1;
        }
    }
    return $out;
}
