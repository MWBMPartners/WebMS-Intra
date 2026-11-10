<?php
// Path: tools/audit-checks/sign-in-session-structure.php
/**
 * -----------------------------------------------------------------------------
 * Ask PHP where a sign-in writes the session, and what came before it 🔍🔑
 * -----------------------------------------------------------------------------
 * `check_sign_in_begins_session.py` asks three plain questions of every PHP
 * file under `web/`, and this file answers them from PHP's own tokeniser:
 *
 *   1. Where is a signed-in identity written into the session?
 *      `$_SESSION['user_id']` and `$_SESSION['2fa_user_id']` (whose session
 *      this is while somebody waits for their code — `Auth::sessionPerson()`
 *      counts it), the same through `$GLOBALS['_SESSION']`, by `=` or any
 *      compound assignment, as the target of a `foreach (… as …)`, or as a
 *      key in a whole-session assignment `$_SESSION = [... 'user_id' => ...]`.
 *      A write of the literal `null` is a sign-out, not a grant, and is not
 *      listed.
 *   2. For each one: is there a call to `Auth::beginSessionFor(` — or, for
 *      the one delegated grant, `Auth::loginLocal(` — EARLIER IN THE SAME
 *      FUNCTION (or the same file, outside any function)? Presence and order,
 *      nothing more.
 *   3. Every static call in each function (to follow the delegated grant), and
 *      every write through a key that is not one plain string, with the
 *      constants that might name it.
 *
 * WHY ONLY PRESENCE AND ORDER (D-065, and #902's own first round). The first
 * version also judged whether the call was a statement of its own, whether its
 * block enclosed the write, and whether it named the same value. Every one of
 * those is a way of asking "does this call RUN, for this person" — a
 * behavioural question — and each cried wolf on correct code (a named argument,
 * a cast on one side, a `do { } while (false)`, a label). This repository has
 * recorded twice (#763, #775) what that road costs. Part K of
 * `tools/two-step-selftest.php` DRIVES every sign-in; this file only says the
 * call is there, and before the write.
 *
 * WHY A PHP FILE RATHER THAN A PYTHON READER. A regular expression cannot tell
 * a call from a call quoted in a comment, a string or a heredoc, or a `{` in
 * `"{$x}"` from a block; `token_get_all()` can. The Python wrapper refuses,
 * with no fallback, when PHP cannot be asked (`member-language-structure.php`
 * is the precedent).
 *
 * Usage: echo '<JSON list of paths>' | php sign-in-session-structure.php
 * Prints one JSON list, one entry per file, in the same order. Exit 0 when
 * every file was read and parsed; 1 otherwise (the Python reports that as a
 * finding, never as "nothing found").
 *
 * @package   Portal\Tools\AuditChecks
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.0.0
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

/**
 * One file's answer.
 *
 * @return array<string,mixed>
 */
function readFileStructure(string $file): array
{
    $src = @file_get_contents($file);
    if ($src === false) {
        return ['file' => $file, 'error' => 'could not be read'];
    }
    try {
        $raw = token_get_all($src, TOKEN_PARSE);
    } catch (\ParseError $e) {
        // A file PHP will not parse cannot be judged; "nothing found" would be a lie.
        return ['file' => $file, 'error' => 'PHP will not parse it: ' . $e->getMessage() . ' (line ' . $e->getLine() . ')'];
    }

    // Significant tokens only: [id, text, line]. A one-character token's id is the character.
    $tok = [];
    foreach ($raw as $t) {
        if (is_array($t) === true) {
            if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === true) {
                continue;
            }
            $tok[] = [$t[0], $t[1], $t[2]];
        } else {
            $tok[] = [$t, $t, $tok === [] ? 1 : $tok[count($tok) - 1][2]];
        }
    }
    $n = count($tok);

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
    $text = static function (int $a, int $b) use ($tok): string {
        $out = '';
        for ($j = $a; $j <= $b; $j++) {
            $out .= $tok[$j][1];
        }

        return $out;
    };

    // --- One walk: which function each token is in. ------------------------
    // Only braces are followed. A function's body is the first `{` after the
    // `function` keyword at the same bracket depth; a class's body is the
    // first `{` after its name. Every other opener — including `{$x}` and
    // `${` inside a string, whose `}` is an ordinary token — is pushed too, so
    // each `}` pops the right thing.
    $stack     = [['scope' => '(file)', 'class' => '']];
    $depth     = 0;
    $pendScope = null;
    $pendAt    = -1;
    $pendClass = null;
    $scopeOf   = [];
    $classOf   = [];
    $calls     = [];
    $aliases   = [];   // `use X as Y;` / `use X;` at file level: short name → full name
    for ($i = 0; $i < $n; $i++) {
        $id          = $tok[$i][0];
        $top         = $stack[count($stack) - 1];
        $scopeOf[$i] = $top['scope'];
        $class       = '';
        for ($k = count($stack) - 1; $k >= 0; $k--) {
            if ($stack[$k]['class'] !== '') {
                $class = $stack[$k]['class'];
                break;
            }
        }
        $classOf[$i] = $class;

        if ($id === '(' || $id === '[' || $id === T_ATTRIBUTE) {
            // `#[` opens an attribute that `]` closes, so it counts as a bracket.
            $depth++;
        } elseif ($id === ')' || $id === ']') {
            $depth--;
        } elseif ($id === T_FUNCTION) {
            $k = $i + 1;
            if ($k < $n && $tok[$k][0] === '&') {
                $k++;
            }
            $pendScope = ($k < $n && $tok[$k][0] === T_STRING)
                ? (($class !== '' ? $class . '::' : '') . $tok[$k][1])
                : 'closure@' . $tok[$i][2];
            $pendAt = $depth;
        } elseif (in_array($id, [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true) === true && ($i === 0 || $tok[$i - 1][0] !== T_DOUBLE_COLON)) {
            $pendClass = ($i + 1 < $n && $tok[$i + 1][0] === T_STRING) ? $tok[$i + 1][1] : 'class@' . $tok[$i][2];
        } elseif ($id === T_USE && count($stack) === 1 && $depth === 0 && $i + 1 < $n
            && in_array($tok[$i + 1][0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true) === true) {
            // Only the plain one-name forms; a grouped `use A\{B, C}` is left
            // alone, and the name in a call is then read as written.
            $full  = ltrim($tok[$i + 1][1], '\\');
            $short = substr((string) strrchr('\\' . $full, '\\'), 1);
            if (($tok[$i + 2][0] ?? null) === T_AS && ($tok[$i + 3][0] ?? null) === T_STRING) {
                $short = $tok[$i + 3][1];
            }
            $aliases[strtolower($short)] = strtolower($full);
        } elseif ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $scope = $top['scope'];
            $cls   = '';
            if ($id === '{' && $pendScope !== null && $depth === $pendAt) {
                $scope     = $pendScope;
                $pendScope = null;
            } elseif ($id === '{' && $pendClass !== null) {
                $cls       = $pendClass;
                $pendClass = null;
            }
            $stack[] = ['scope' => $scope, 'class' => $cls];
        } elseif ($id === '}') {
            if (count($stack) > 1) {
                array_pop($stack);
            }
        } elseif ($id === ';' && $pendScope !== null && $depth === $pendAt) {
            $pendScope = null; // an abstract or interface method: no body
        }

        if ($id === T_DOUBLE_COLON && $i > 0 && ($tok[$i + 1][0] ?? null) === T_STRING && ($tok[$i + 2][0] ?? null) === '(') {
            $calls[$scopeOf[$i]][] = ltrim($tok[$i - 1][1], '\\') . '::' . $tok[$i + 1][1];
        }
    }

    /** Does the class name at $i mean Portal\Core\Auth here? */
    $isAuthClass = static function (int $i) use ($tok, $aliases, $classOf): bool {
        $name = strtolower(ltrim($tok[$i][1], '\\'));
        if (in_array($name, ['self', 'static'], true) === true) {
            return strcasecmp($classOf[$i] ?? '', 'Auth') === 0;
        }
        if ($tok[$i][0] === T_STRING && isset($aliases[$name]) === true) {
            return $aliases[$name] === 'portal\\core\\auth';
        }

        return in_array($name, ['auth', 'portal\\core\\auth'], true) === true;
    };

    // --- The calls that begin a session (and the delegated one). -----------
    $marks = [];   // [index, scope, kind 'begin'|'loginLocal']
    for ($i = 0; $i + 3 < $n; $i++) {
        if ($tok[$i + 1][0] !== T_DOUBLE_COLON || $tok[$i + 2][0] !== T_STRING || $tok[$i + 3][0] !== '(') {
            continue;
        }
        $method = strtolower($tok[$i + 2][1]);
        if (in_array($method, ['beginsessionfor', 'loginlocal'], true) === false || $isAuthClass($i) === false) {
            continue;
        }
        $marks[] = ['at' => $i, 'line' => $tok[$i][2], 'scope' => $scopeOf[$i], 'kind' => $method === 'beginsessionfor' ? 'begin' : 'loginLocal'];
    }

    // --- The writes. --------------------------------------------------------
    $writes  = [];
    $dynamic = [];
    for ($i = 0; $i < $n; $i++) {
        if ($tok[$i][0] !== T_VARIABLE) {
            continue;
        }
        if ($tok[$i][1] === '$_SESSION') {
            $at = $i + 1;
        } elseif ($tok[$i][1] === '$GLOBALS' && ($tok[$i + 1][0] ?? null) === '[' && ($tok[$i + 2][0] ?? null) === T_CONSTANT_ENCAPSED_STRING
            && trim($tok[$i + 2][1], '\'"') === '_SESSION' && ($tok[$i + 3][0] ?? null) === ']') {
            $at = $i + 4;
        } else {
            continue;
        }

        // The whole session at once: `$_SESSION = …` or `$_SESSION += …`.
        if ($at < $n && ($tok[$at][0] === '=' || $tok[$at][0] === T_PLUS_EQUAL)) {
            $d = 0;
            for ($end = $at + 1; $end < $n && !($d === 0 && in_array($tok[$end][0], [';', T_CLOSE_TAG], true) === true); $end++) {
                if (in_array($tok[$end][0], ['(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true) === true) {
                    $d++;
                } elseif (in_array($tok[$end][0], [')', ']', '}'], true) === true) {
                    $d--;
                }
                if ($tok[$end][0] === T_CONSTANT_ENCAPSED_STRING && in_array(trim($tok[$end][1], '\'"'), IDENTITY_KEYS, true) === true
                    && ($tok[$end + 1][0] ?? null) === T_DOUBLE_ARROW
                    && strtolower($tok[$end + 2][1] ?? '') !== 'null') {
                    $writes[] = ['at' => $i, 'key' => trim($tok[$end][1], '\'"'), 'form' => 'whole session'];
                }
            }
            continue;
        }
        if (($tok[$at][0] ?? null) !== '[') {
            continue;
        }
        $close = $match($at);
        if ($close < 0 || $close + 1 >= $n) {
            continue;
        }
        // Step over any further [..]: a write to `$_SESSION['user_id'][…]`
        // still changes the identity entry.
        $after = $close + 1;
        while ($after < $n && $tok[$after][0] === '[') {
            $m = $match($after);
            if ($m < 0) {
                break;
            }
            $after = $m + 1;
        }
        $isForeach = $i > 0 && $tok[$i - 1][0] === T_AS;
        $isAssign  = $after < $n && ($tok[$after][0] === '=' || in_array($tok[$after][0], ASSIGN_IDS, true) === true);
        if ($isForeach === false && $isAssign === false) {
            continue;
        }
        $simple = $close === $at + 2 && $tok[$at + 1][0] === T_CONSTANT_ENCAPSED_STRING;
        if ($simple === false) {
            // Anything but one plain string: a variable, a constant, a
            // concatenation. The Python resolves constants and requires the
            // rest to be named on purpose.
            $dynamic[] = ['line' => $tok[$i][2], 'scope' => $scopeOf[$i], 'key' => $text($at + 1, $close - 1)];
            continue;
        }
        $key = substr($tok[$at + 1][1], 1, -1);
        if (in_array($key, IDENTITY_KEYS, true) === false) {
            continue;
        }
        // `= null;` is a sign-out, not a grant.
        if ($isAssign === true && $tok[$after][0] === '=' && strtolower($tok[$after + 1][1] ?? '') === 'null'
            && in_array($tok[$after + 2][0] ?? null, [';', T_CLOSE_TAG], true) === true) {
            continue;
        }
        $writes[] = ['at' => $i, 'key' => $key, 'form' => $isForeach === true ? 'foreach target' : ($tok[$i][1] === '$GLOBALS' ? "\$GLOBALS['_SESSION']" : 'entry')];
    }

    // --- For each write: the nearest call of each kind before it, same function.
    $out = [];
    foreach ($writes as $w) {
        $scope = $scopeOf[$w['at']];
        $begin = null;
        $login = null;
        foreach ($marks as $m) {
            if ($m['at'] < $w['at'] && $m['scope'] === $scope) {
                if ($m['kind'] === 'begin') {
                    $begin = $m['line'];
                } else {
                    $login = $m['line'];
                }
            }
        }
        $out[] = ['line' => $tok[$w['at']][2], 'key' => $w['key'], 'form' => $w['form'], 'scope' => $scope,
            'beginLine' => $begin, 'loginLocalLine' => $login];
    }

    // --- Constants holding one plain string, so a constant key can be read.
    $consts = [];
    for ($i = 0; $i + 3 < $n; $i++) {
        if ($tok[$i][0] === T_CONST) {
            $k = $i + 1;
            while ($k + 2 < $n && $tok[$k][0] === T_STRING && $tok[$k + 1][0] === '=') {
                $end = $k + 2;
                if ($tok[$end][0] === T_CONSTANT_ENCAPSED_STRING && in_array($tok[$end + 1][0] ?? null, [';', ','], true) === true) {
                    $cls      = $classOf[$i] ?? '';
                    $consts[] = ['name' => ($cls !== '' ? $cls . '::' : '') . $tok[$k][1], 'value' => substr($tok[$end][1], 1, -1)];
                }
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

    return [
        'file'    => $file,
        'writes'  => $out,
        'dynamic' => $dynamic,
        'begins'  => array_values(array_map(static fn (array $m): array => ['line' => $m['line'], 'scope' => $m['scope']],
            array_filter($marks, static fn (array $m): bool => $m['kind'] === 'begin'))),
        'calls'   => $calls,
        'consts'  => $consts,
    ];
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
foreach ($request as $one) {
    $answer = readFileStructure((string) $one);
    if (isset($answer['error']) === true) {
        $bad = true;
    }
    $answers[] = $answer;
}
echo json_encode($answers, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
exit($bad === true ? 1 : 0);
