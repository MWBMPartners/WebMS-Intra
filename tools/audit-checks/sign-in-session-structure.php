<?php
// Path: tools/audit-checks/sign-in-session-structure.php
/**
 * -----------------------------------------------------------------------------
 * Ask PHP where a sign-in writes the session, and what came before it 🔍🔑
 * -----------------------------------------------------------------------------
 * `check_sign_in_begins_session.py` needs to know, for every PHP file under
 * `web/`, three things that are in the GRAMMAR rather than the text:
 *
 *   1. every place a signed-in identity is written into the session —
 *      `$_SESSION['user_id']`, `$_SESSION['2fa_user_id']` (whose session this
 *      is while somebody waits for their code; `Auth::sessionPerson()` counts
 *      it), the same through `$GLOBALS['_SESSION']`, and a whole-session
 *      assignment `$_SESSION = [... 'user_id' => ...]`;
 *   2. for each one, the call to `Auth::beginSessionFor(` that comes before it
 *      IN THE SAME FUNCTION (or the same file, outside any function), if there
 *      is one that really counts — see "WHAT COUNTS AS A CALL" below;
 *   3. every static call in each function, so the Python can follow the one
 *      delegated grant (`Auth::loginLocal()` → `loginLocalOneAtATime()`).
 *
 * WHY A PHP FILE RATHER THAN A PYTHON READER. This repository has paid for
 * that lesson several times over (#763, #775): a regular expression cannot
 * tell a call from a call quoted in a comment, a string or a heredoc; a `{` in
 * `"{$x}"` from a block; `$endif` from `endif`. `token_get_all()` can, so this
 * file asks it, and the Python wrapper refuses outright — with no fallback —
 * when PHP cannot be asked. `member-language-structure.php` is the precedent.
 *
 * WHAT COUNTS AS A CALL (all four, or it does not count)
 *   a. `Auth::beginSessionFor(`, `\Portal\Core\Auth::beginSessionFor(`, or —
 *      inside class Auth only — `self::`/`static::`. Names are compared
 *      ignoring case, because PHP does.
 *   b. It is a STATEMENT OF ITS OWN: the token before it is `;`, `{`, `}`,
 *      `<?php`, or a colon that opens an alternative-syntax block or ends a
 *      `case`/`default` label; and the token after its closing bracket is `;`
 *      or `?>`. So `false && Auth::beginSessionFor($x);`,
 *      `if (false) Auth::beginSessionFor($x);` (no braces), a ternary arm and
 *      a call buried in an argument all do NOT count.
 *   c. The block it sits in ENCLOSES the write (the same block, or one the
 *      write is nested inside). So `if (false) { Auth::beginSessionFor($x); }`
 *      followed by the write does NOT count, and nor does the same in
 *      `if (false): … endif;`, a `try` block the write is outside, or a
 *      closure defined earlier.
 *   d. It is the NEAREST such call before the write, and its argument is
 *      token-for-token the value written (`$userId` and `$userId`), so a
 *      sign-in cannot begin a session for one person and grant another.
 *
 * WHAT IT CANNOT SEE, said plainly
 *   - Whether the code RUNS. A call that is its own statement, in an enclosing
 *     block, can still be dead: a `return;`, `exit;`, `goto` or a
 *     `while (true) {}` above it, a conditional `require` of the whole file,
 *     a throw. "Is this reached" is behavioural (D-065), and part K of
 *     `tools/two-step-selftest.php` drives every grant over HTTP for that.
 *   - Whether `Auth::beginSessionFor()` itself still does its job. Part K's
 *     plants are what prove that.
 *   - A write through a VARIABLE key (`$_SESSION[$key] = …`): reported as its
 *     own list, so the Python can require each one to be named on purpose.
 *   - A write through a reference (`$s = &$_SESSION; $s['user_id'] = …`),
 *     `extract()`, `session_decode()`, `array_merge()` of a variable into the
 *     whole session, or destructuring (`[$_SESSION['user_id']] = …`).
 *   - A class alias (`use Portal\Core\Auth as A;` then `A::beginSessionFor`):
 *     not recognised, so it is reported as missing — the safe direction.
 *   - A braceless `if`/`else` chain with a dangling `else`: the block tracking
 *     follows the common shapes, not every corner of the grammar.
 *
 * Usage: echo '<JSON list of paths>' | php sign-in-session-structure.php
 * Prints one JSON list, one entry per file, in the same order. Exit 0 when
 * every file was read and parsed; 1 otherwise (the Python treats that as a
 * finding, never as "nothing found").
 *
 * @package   Portal\Tools\AuditChecks
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/902
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** The keys that say whose session this is. */
const IDENTITY_KEYS = ['user_id', '2fa_user_id'];

/** The assignment operators: `=` and every compound form PHP has. */
const ASSIGN_IDS = [
    T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL, T_MOD_EQUAL,
    T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_POW_EQUAL, T_COALESCE_EQUAL,
];

/** Control keywords whose body is a block (or a single braceless statement). */
const CONTROL_WITH_HEADER = [T_IF, T_ELSEIF, T_WHILE, T_FOR, T_FOREACH, T_SWITCH, T_DECLARE, T_CATCH];

/** The words that close an alternative-syntax block. */
const ALT_CLOSERS = [T_ENDIF, T_ENDWHILE, T_ENDFOR, T_ENDFOREACH, T_ENDSWITCH, T_ENDDECLARE];

/**
 * One file's answer.
 *
 * @return array<string,mixed>
 */
function readFileStructure(string $path): array
{
    $src = @file_get_contents($path);
    if ($src === false) {
        return ['file' => $path, 'error' => 'could not be read'];
    }
    try {
        $raw = token_get_all($src, TOKEN_PARSE);
    } catch (\ParseError $e) {
        // A file PHP will not parse cannot be judged; "nothing found" would be
        // a lie, so it is an error the Python reports.
        return ['file' => $path, 'error' => 'PHP will not parse it: ' . $e->getMessage() . ' (line ' . $e->getLine() . ')'];
    }

    // Significant tokens only: [id, text, line]. A single-character token has
    // its character as its id.
    $tok = [];
    foreach ($raw as $t) {
        if (is_array($t) === true) {
            if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === true) {
                continue;
            }
            $tok[] = [$t[0], $t[1], $t[2]];
        } else {
            $line = $tok === [] ? 1 : $tok[count($tok) - 1][2];
            $tok[] = [$t, $t, $line];
        }
    }
    $n = count($tok);

    $isChar = static fn (int $i, string $c): bool => $i >= 0 && $i < $n && $tok[$i][0] === $c;
    $isId   = static fn (int $i, int $id): bool => $i >= 0 && $i < $n && $tok[$i][0] === $id;

    /** Index of the bracket matching the opener at $i ('(' or '['), or -1. */
    $match = static function (int $i) use ($tok, $n): int {
        $open  = $tok[$i][0];
        $close = $open === '(' ? ')' : ']';
        $depth = 0;
        for ($j = $i; $j < $n; $j++) {
            if ($tok[$j][0] === $open) {
                $depth++;
            } elseif ($tok[$j][0] === $close) {
                $depth--;
                if ($depth === 0) {
                    return $j;
                }
            }
        }

        return -1;
    };

    /** The tokens from $a to $b inclusive, as text with no whitespace. */
    $text = static function (int $a, int $b) use ($tok): string {
        $out = '';
        for ($j = $a; $j <= $b; $j++) {
            $out .= $tok[$j][1];
        }

        return $out;
    };

    // --- One walk: blocks, scopes, statement starts. -------------------------
    // Each block: id, scope name, kind (brace|alt|stmt), ctrl word, header
    // span, the bracket depth when it opened, and for a braceless body the
    // flag that its one statement has begun.
    $nextId     = 1;
    $stack      = [['id' => 0, 'scope' => '(file)', 'kind' => 'file', 'ctrl' => '', 'hdr' => null, 'depth' => 0, 'class' => '']];
    $depth      = 0;          // ( and [ nesting
    $pendScope  = null;       // a function name waiting for its {
    $pendFnAt   = -1;         // bracket depth at that function keyword
    $pendClass  = null;       // a class name waiting for its {
    $pendCtrl   = null;       // ['ctrl'=>…, 'hdr'=>[a,b]] waiting for { or : or a statement
    $colonStart = [];         // token indexes of colons that start a statement
    $caseOpen   = false;      // inside a case/default label, waiting for its colon
    $ternary    = 0;          // ? seen inside that label
    $blockOf    = [];         // token index → list of block ids (the path)
    $scopeOf    = [];         // token index → scope name
    $classOf    = [];         // token index → enclosing class name
    $calls      = [];         // scope → list of static calls "Class::method"

    $blocks = [0 => ['ctrl' => '', 'hdr' => null, 'scope' => '(file)']];
    $push   = static function (array $b) use (&$stack, &$blocks): void {
        $stack[]          = $b;
        $blocks[$b['id']] = ['ctrl' => $b['ctrl'], 'hdr' => $b['hdr'], 'scope' => $b['scope']];
    };
    $pathNow = static function () use (&$stack): array {
        return array_map(static fn (array $b): int => $b['id'], $stack);
    };
    $currentClass = static function () use (&$stack): string {
        for ($k = count($stack) - 1; $k >= 0; $k--) {
            if ($stack[$k]['class'] !== '') {
                return $stack[$k]['class'];
            }
        }

        return '';
    };
    // Close braceless bodies whose one statement has just ended.
    $closeStatements = static function () use (&$stack, &$depth): void {
        while (count($stack) > 1 && $stack[count($stack) - 1]['kind'] === 'stmt' && $stack[count($stack) - 1]['depth'] === $depth) {
            array_pop($stack);
        }
    };

    for ($i = 0; $i < $n; $i++) {
        $id = $tok[$i][0];

        // A control header we were waiting on decides what kind of body follows.
        if ($pendCtrl !== null && $pendCtrl['ready'] === true) {
            $ctrl = $pendCtrl;
            $pendCtrl = null;
            if ($id === ':') {
                $top = $stack[count($stack) - 1];
                if (in_array($ctrl['ctrl'], ['elseif', 'else'], true) === true && $top['kind'] === 'alt') {
                    array_pop($stack); // the previous branch of the same alt-syntax if
                }
                $push(['id' => $nextId++, 'scope' => $stack[count($stack) - 1]['scope'], 'kind' => 'alt', 'ctrl' => $ctrl['ctrl'], 'hdr' => $ctrl['hdr'], 'depth' => $depth, 'class' => '']);
                $colonStart[$i] = true;
                $blockOf[$i]  = $pathNow();
                $scopeOf[$i]  = $stack[count($stack) - 1]['scope'];
                continue;
            }
            if ($id === '{') {
                $push(['id' => $nextId++, 'scope' => $stack[count($stack) - 1]['scope'], 'kind' => 'brace', 'ctrl' => $ctrl['ctrl'], 'hdr' => $ctrl['hdr'], 'depth' => $depth, 'class' => '']);
                $blockOf[$i] = $pathNow();
                $scopeOf[$i] = $stack[count($stack) - 1]['scope'];
                continue;
            }
            if ($ctrl['ctrl'] === 'else' && $id === T_IF) {
                // `else if (...)` — the if makes its own block.
            } elseif ($id !== ';') {
                // A braceless body: one statement, which is conditional.
                $push(['id' => $nextId++, 'scope' => $stack[count($stack) - 1]['scope'], 'kind' => 'stmt', 'ctrl' => $ctrl['ctrl'], 'hdr' => $ctrl['hdr'], 'depth' => $depth, 'class' => '']);
            }
        }

        $blockOf[$i] = $pathNow();
        $scopeOf[$i] = $stack[count($stack) - 1]['scope'];
        $classOf[$i] = $currentClass();

        if ($id === '(' || $id === '[' || $id === T_ATTRIBUTE) {
            // `#[` opens an attribute that `]` closes, so it counts as a bracket.
            $depth++;
        } elseif ($id === ')' || $id === ']') {
            $depth--;
            if ($pendCtrl !== null && $pendCtrl['ready'] === false && $pendCtrl['close'] === $i) {
                $pendCtrl['ready'] = true;
            }
        } elseif (in_array($id, CONTROL_WITH_HEADER, true) === true && $isChar($i + 1, '(') === true) {
            $close    = $match($i + 1);
            $pendCtrl = ['ctrl' => strtolower($tok[$i][1]), 'hdr' => [$i + 1, $close], 'close' => $close, 'ready' => false];
        } elseif ($id === T_ELSE || $id === T_DO || $id === T_TRY || $id === T_FINALLY) {
            $pendCtrl = ['ctrl' => strtolower($tok[$i][1]), 'hdr' => null, 'close' => -1, 'ready' => true];
        } elseif (in_array($id, ALT_CLOSERS, true) === true) {
            if ($stack[count($stack) - 1]['kind'] === 'alt') {
                array_pop($stack);
            }
        } elseif ($id === T_FUNCTION) {
            $k = $i + 1;
            if ($isChar($k, '&') === true) {
                $k++;
            }
            $pendScope = ($k < $n && $tok[$k][0] === T_STRING)
                ? (($currentClass() !== '' ? $currentClass() . '::' : '') . $tok[$k][1])
                : 'closure@' . $tok[$i][2];
            $pendFnAt  = $depth;
        } elseif (in_array($id, [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true) === true && !$isId($i - 1, T_DOUBLE_COLON)) {
            $pendClass = ($i + 1 < $n && $tok[$i + 1][0] === T_STRING) ? $tok[$i + 1][1] : 'class@' . $tok[$i][2];
        } elseif ($id === T_CASE || $id === T_DEFAULT) {
            if ($isId($i + 1, T_DOUBLE_ARROW) === false) {
                $caseOpen = true;
                $ternary  = 0;
            }
        } elseif ($id === '?' && $caseOpen === true) {
            $ternary++;
        } elseif ($id === ':' && $caseOpen === true) {
            if ($ternary > 0) {
                $ternary--;
            } else {
                $caseOpen       = false;
                $colonStart[$i] = true;
            }
        } elseif ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            // Every opener is pushed, including `{$x}` in a string, so its
            // closing `}` pops the right thing.
            $scope = $stack[count($stack) - 1]['scope'];
            $class = '';
            if ($id === '{' && $pendScope !== null && $depth === $pendFnAt) {
                $scope     = $pendScope;
                $pendScope = null;
            } elseif ($id === '{' && $pendClass !== null) {
                $class     = $pendClass;
                $pendClass = null;
            }
            $push(['id' => $nextId++, 'scope' => $scope, 'kind' => 'brace', 'ctrl' => '', 'hdr' => null, 'depth' => $depth, 'class' => $class]);
        } elseif ($id === '}') {
            if (count($stack) > 1) {
                // Pop braceless bodies opened inside this block first (a
                // stmt body whose statement was a block ends with it).
                while (count($stack) > 1 && $stack[count($stack) - 1]['kind'] === 'stmt') {
                    array_pop($stack);
                }
                array_pop($stack);
            }
            $closeStatements();
        } elseif ($id === ';' || $id === T_CLOSE_TAG) {
            if ($pendScope !== null && $depth === $pendFnAt) {
                $pendScope = null; // an abstract or interface method: no body
            }
            $closeStatements();
        }

        // Static calls, per scope, for following a delegated grant.
        if ($id === T_DOUBLE_COLON && $i > 0 && $isId($i + 1, T_STRING) === true && $isChar($i + 2, '(') === true) {
            $calls[$scopeOf[$i]][] = ltrim($tok[$i - 1][1], '\\') . '::' . $tok[$i + 1][1];
        }
    }

    // --- Constants holding a plain string, so a key written as a constant ----
    //     (`$_SESSION[self::PROOF_KEY]`) can be resolved by the Python rather
    //     than having to be listed by hand. `const X = '…';` (file or class)
    //     and `define('X', '…');`. Anything else is not collected, and a key
    //     naming it stays unresolved — the safe direction.
    $consts = [];
    for ($i = 0; $i + 3 < $n; $i++) {
        if ($tok[$i][0] === T_CONST) {
            $k = $i + 1;
            while ($k + 2 < $n && $tok[$k][0] === T_STRING && $tok[$k + 1][0] === '=') {
                $end = $k + 2;
                if ($tok[$end][0] === T_CONSTANT_ENCAPSED_STRING && in_array($tok[$end + 1][0] ?? null, [';', ','], true) === true) {
                    $cls = $classOf[$i] ?? '';
                    $consts[] = ['name' => ($cls !== '' ? $cls . '::' : '') . $tok[$k][1], 'value' => substr($tok[$end][1], 1, -1)];
                }
                // Next `NAME = value` in the same `const A = 1, B = 2;` list.
                while ($end < $n && $tok[$end][0] !== ',' && $tok[$end][0] !== ';') {
                    $end++;
                }
                if ($end >= $n || $tok[$end][0] === ';') {
                    break;
                }
                $k = $end + 1;
            }
        } elseif ($tok[$i][0] === T_STRING && strcasecmp($tok[$i][1], 'define') === 0 && $tok[$i + 1][0] === '('
            && $tok[$i + 2][0] === T_CONSTANT_ENCAPSED_STRING && $tok[$i + 3][0] === ','
            && ($tok[$i + 4][0] ?? null) === T_CONSTANT_ENCAPSED_STRING && ($tok[$i + 5][0] ?? null) === ')') {
            $consts[] = ['name' => substr($tok[$i + 2][1], 1, -1), 'value' => substr($tok[$i + 4][1], 1, -1)];
        }
    }

    // --- Is the call at $i a statement of its own? ---------------------------
    $statementStart = static function (int $i) use ($tok, $colonStart): bool {
        if ($i === 0) {
            return true;
        }
        $prev = $tok[$i - 1][0];
        if (in_array($prev, [';', '{', '}'], true) === true || $prev === T_OPEN_TAG || $prev === T_CLOSE_TAG) {
            return true;
        }

        return $prev === ':' && isset($colonStart[$i - 1]) === true;
    };

    // --- The calls to beginSessionFor. ---------------------------------------
    $begins = [];
    for ($i = 0; $i + 3 < $n; $i++) {
        if ($tok[$i + 1][0] !== T_DOUBLE_COLON || $tok[$i + 2][0] !== T_STRING || strcasecmp($tok[$i + 2][1], 'beginSessionFor') !== 0 || $tok[$i + 3][0] !== '(') {
            continue;
        }
        $cls   = $tok[$i][1];
        $plain = strtolower(ltrim($cls, '\\'));
        $isAuth = in_array($plain, ['auth', 'portal\\core\\auth'], true) === true
            || (in_array($plain, ['self', 'static'], true) === true && strcasecmp($classOf[$i] ?? '', 'Auth') === 0);
        $close   = $match($i + 3);
        $whole   = $close > 0 && $close + 1 < $n && ($tok[$close + 1][0] === ';' || $tok[$close + 1][0] === T_CLOSE_TAG);
        $alone   = $statementStart($i) === true && $whole === true;
        $begins[] = [
            'at'     => $i,
            'line'   => $tok[$i][2],
            'scope'  => $scopeOf[$i],
            'path'   => $blockOf[$i],
            'isAuth' => $isAuth,
            'alone'  => $alone,
            'arg'    => $close > $i + 4 ? $text($i + 4, $close - 1) : '',
            'callee' => $cls,
        ];
    }

    // --- The writes. ---------------------------------------------------------
    $writes  = [];
    $dynamic = [];
    for ($i = 0; $i < $n; $i++) {
        if ($tok[$i][0] !== T_VARIABLE) {
            continue;
        }
        $at = -1;     // index of the [ that opens the key
        if ($tok[$i][1] === '$_SESSION') {
            $at = $i + 1;
        } elseif ($tok[$i][1] === '$GLOBALS' && $isChar($i + 1, '[') === true && $isId($i + 2, T_CONSTANT_ENCAPSED_STRING) === true
            && trim($tok[$i + 2][1], '\'"') === '_SESSION' && $isChar($i + 3, ']') === true) {
            $at = $i + 4;
        } else {
            continue;
        }

        // The whole session at once: `$_SESSION = …` or `$_SESSION += …`.
        if ($at < $n && ($tok[$at][0] === '=' || $tok[$at][0] === T_PLUS_EQUAL)) {
            $end = $at + 1;
            $d   = 0;
            while ($end < $n && !($d === 0 && ($tok[$end][0] === ';' || $tok[$end][0] === T_CLOSE_TAG))) {
                if (in_array($tok[$end][0], ['(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true) === true) {
                    $d++;
                } elseif (in_array($tok[$end][0], [')', ']', '}'], true) === true) {
                    $d--;
                }
                if ($tok[$end][0] === T_CONSTANT_ENCAPSED_STRING && in_array(trim($tok[$end][1], '\'"'), IDENTITY_KEYS, true) === true
                    && $isId($end + 1, T_DOUBLE_ARROW) === true) {
                    $writes[] = ['line' => $tok[$i][2], 'key' => trim($tok[$end][1], '\'"'), 'form' => 'whole session', 'at' => $i, 'value' => ''];
                }
                $end++;
            }
            continue;
        }

        if ($isChar($at, '[') === false) {
            continue;
        }
        $close = $match($at);
        if ($close < 0 || $close + 1 >= $n) {
            continue;
        }
        // Step over any further [..] (a write to `$_SESSION['user_id'][…]`
        // still changes the identity entry).
        $after = $close + 1;
        while ($after < $n && $tok[$after][0] === '[') {
            $m = $match($after);
            if ($m < 0) {
                break;
            }
            $after = $m + 1;
        }
        if ($after >= $n || !($tok[$after][0] === '=' || in_array($tok[$after][0], ASSIGN_IDS, true) === true)) {
            continue;
        }
        // (`= &…` is a reference assignment, and still a write: nothing
        //  special is needed for it.)
        $keyTok = $tok[$at + 1];
        if ($close === $at + 2 && $keyTok[0] === T_CONSTANT_ENCAPSED_STRING) {
            $key = substr($keyTok[1], 1, -1);
            if (in_array($key, IDENTITY_KEYS, true) === false) {
                continue;
            }
            // The value: up to the end of the statement at this depth.
            $end = $after + 1;
            $d   = 0;
            while ($end < $n && !($d === 0 && in_array($tok[$end][0], [';', T_CLOSE_TAG, ')', ']', ','], true) === true)) {
                if (in_array($tok[$end][0], ['(', '['], true) === true) {
                    $d++;
                } elseif (in_array($tok[$end][0], [')', ']'], true) === true) {
                    $d--;
                }
                $end++;
            }
            // Inside an arrow function the "statement" is not a statement.
            $inArrow = false;
            for ($b = $i - 1; $b >= 0; $b--) {
                if (in_array($tok[$b][0], [';', '{', '}', T_OPEN_TAG], true) === true) {
                    break;
                }
                if ($tok[$b][0] === T_FN) {
                    $inArrow = true;
                    break;
                }
            }
            $writes[] = ['line' => $tok[$i][2], 'key' => $key, 'form' => $tok[$i][1] === '$GLOBALS' ? "\$GLOBALS['_SESSION']" : 'entry',
                'at' => $i, 'value' => $end - 1 >= $after + 1 ? $text($after + 1, $end - 1) : '', 'op' => $tok[$after][1], 'arrow' => $inArrow];
        } elseif ($keyTok[0] !== T_CONSTANT_ENCAPSED_STRING) {
            $dynamic[] = ['line' => $tok[$i][2], 'scope' => $scopeOf[$i], 'key' => $text($at + 1, $close - 1)];
        }
    }

    // --- Judge each write against the calls before it. -----------------------
    $isPrefix = static function (array $outer, array $inner): bool {
        return count($outer) <= count($inner) && array_slice($inner, 0, count($outer)) === $outer;
    };
    $out = [];
    foreach ($writes as $w) {
        $wi     = $w['at'];
        $scope  = $scopeOf[$wi];
        $wPath  = $blockOf[$wi];
        $chosen = null;
        $passed = [];   // calls before it in this scope that did NOT count, and why
        foreach ($begins as $c) {
            // (Comparing the scope is belt and braces, said so on purpose: a
            //  function body is a block, and no function body encloses code
            //  outside it, so the enclosing-block rule below already refuses
            //  a call made in another function. Weakening this line alone
            //  turns nothing in the self-test red, and that is why.)
            if ($c['at'] >= $wi || $c['scope'] !== $scope) {
                continue;
            }
            $why = '';
            if ($c['isAuth'] === false) {
                $why = 'it is not Auth::beginSessionFor() (' . $c['callee'] . '::)';
            } elseif ($c['alone'] === false) {
                $why = 'it is not a statement of its own (something before it in the statement decides whether it runs, or its answer is used)';
            } elseif ($isPrefix($c['path'], $wPath) === false) {
                $why = 'it sits in a block that does not enclose the write (an if, loop, try or other block the write is outside)';
            }
            if ($why === '') {
                $chosen = $c; // keep the NEAREST: a later one replaces it
            } else {
                $passed[] = ['line' => $c['line'], 'why' => $why];
            }
        }

        // The one delegated shape: the write sits inside
        // `if (Auth::loginLocal(...) === true) { … }`, and nothing else in
        // that condition. Auth::loginLocal() makes its own call (the Python
        // follows it), so the page that calls it need not.
        $delegated = '';
        if ($chosen === null) {
            foreach (array_reverse($wPath) as $blockId) {
                $b = $blocks[$blockId] ?? null;
                if ($b === null || $b['scope'] !== $scope) {
                    break;
                }
                if (in_array($b['ctrl'], ['if', 'elseif'], true) === false || $b['hdr'] === null) {
                    continue;
                }
                [$ha, $hb] = $b['hdr'];
                // ( Auth :: loginLocal ( … ) === true )
                $cls = strtolower(ltrim($tok[$ha + 1][1] ?? '', '\\'));
                if (in_array($cls, ['auth', 'portal\\core\\auth'], true) === true
                    && ($tok[$ha + 2][0] ?? null) === T_DOUBLE_COLON
                    && strcasecmp($tok[$ha + 3][1] ?? '', 'loginLocal') === 0
                    && ($tok[$ha + 4][0] ?? null) === '('
                ) {
                    $argEnd = $match($ha + 4);
                    if ($argEnd > 0 && $argEnd + 3 === $hb
                        && $tok[$argEnd + 1][0] === T_IS_IDENTICAL
                        && strtolower($tok[$argEnd + 2][1]) === 'true'
                    ) {
                        $delegated = 'Auth::loginLocal';
                        break;
                    }
                }
            }
        }
        $out[] = [
            'line'      => $w['line'],
            'key'       => $w['key'],
            'form'      => $w['form'],
            'scope'     => $scope,
            'value'     => $w['value'],
            'arrow'     => $w['arrow'] ?? false,
            'begin'     => $chosen === null ? null : ['line' => $chosen['line'], 'arg' => $chosen['arg']],
            'delegated' => $delegated,
            'passedOver'=> $passed,
        ];
    }

    return ['file' => $path, 'writes' => $out, 'dynamic' => $dynamic,
        'begins' => array_map(static fn (array $c): array => ['line' => $c['line'], 'scope' => $c['scope'], 'counts' => $c['isAuth'] && $c['alone']], $begins),
        'calls' => $calls, 'consts' => $consts];
}

// --- Entry point. -------------------------------------------------------------
// The list comes on STANDARD INPUT, not as an argument: every PHP file under
// web/ is nearly a thousand paths, and Linux limits ONE argument to 128 KiB.
$request = json_decode((string) stream_get_contents(STDIN), true);
if (is_array($request) === false) {
    fwrite(STDERR, "usage: echo '<JSON list of paths>' | php sign-in-session-structure.php\n");
    exit(1);
}
$answers = [];
$bad     = false;
foreach ($request as $file) {
    $one = readFileStructure((string) $file);
    if (isset($one['error']) === true) {
        $bad = true;
    }
    $answers[] = $one;
}
echo json_encode($answers, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
exit($bad === true ? 1 : 0);
