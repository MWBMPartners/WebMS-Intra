<?php
// Path: tools/audit-checks/member-language-structure.php
/**
 * -----------------------------------------------------------------------------
 * Ask PHP what the PHP is 🔍
 * -----------------------------------------------------------------------------
 * #763's guard (`check_member_language_applied.py`) pins three regions of code
 * so that nobody can quietly make them stop running. Six check rounds went into
 * that guard, and **every single escape was the same mistake: a hand-written
 * parser being wrong about PHP.**
 *
 * The scoreboard, because it is the argument for this file existing:
 *
 *   round 1  `if (false) { … }`            answered with a brace count
 *   round 3  `if (false)` with no braces   answered with an allowed-character list
 *   round 4  `if (false): … endif;`        answered by removing two characters
 *            `function neverCalled() { … }`
 *            a dead duplicate above the live code
 *            a comment citing a safeguard that did not exist
 *   round 5  `if (false) { ;`              answered by counting nesting by hand
 *            `switch (0) { case 1: {}`
 *            `#` applied to raw markup
 *   round 6  `$endif = 1;` cancelling the hand-rolled `endif` counter
 *            a heredoc body containing `}` cancelling the brace counter
 *            `#[Deprecated]` read as a comment, hiding a `{`
 *            `'?>'` inside a string ending the PHP span early
 *            `<!-- <?php if (false) { ?> -->`, which PHP RUNS and the reader blanked
 *            `$exit = false;` counted as an exit, so the guard cried wolf
 *
 * Not one of those is a fault in #763's fix, which has been right since the
 * first round. They are all one fault in the guard, and it is not a shortage of
 * cleverness: **a regular expression cannot tell a keyword from a variable, a
 * brace in code from a brace in a heredoc, or an attribute from a comment,
 * because none of those distinctions is in the text — they are in the grammar.**
 *
 * So this file stops guessing and asks PHP, through `token_get_all()`. After
 * that, `$endif` is a `T_VARIABLE` and `endif` is `T_ENDIF`; a brace inside a
 * heredoc is part of `T_ENCAPSED_AND_WHITESPACE`; `#[` is `T_ATTRIBUTE`, not
 * `T_COMMENT`; a `'?>'` in a string is part of `T_CONSTANT_ENCAPSED_STRING`;
 * and PHP inside an HTML comment is PHP, because that is what PHP does with it.
 * None of round 6's spellings is expressible any more.
 *
 * ⚠️ AN EARLIER VERSION OF THIS SENTENCE ADDED "and neither are the ones nobody
 *    has thought of yet", AND THAT IS UNTRUE. The seventh round measured three
 *    ordinary statements, each one line above a region, each `php -l` clean,
 *    each killing the region with this probe reporting no change at all:
 *
 *        if (true) { exit; }
 *        { exit; }                 (a bare block, which runs unconditionally)
 *        while (true) { }
 *
 *    The first two are braced, so the `exit` sits one level deeper than the
 *    region and the depth test skips it. The third terminates nothing and
 *    exits nowhere: it simply never finishes.
 *
 *    **The braced ones are now caught** by `exitsAnywhere`, below. The third is
 *    not, and cannot be: "does this loop finish" is not a question a parser can
 *    answer. Neither can "is this condition ever true", which is why a
 *    legitimate `if (badRequest) { exit; }` guard above a region must not be
 *    read as a kill. That boundary is the honest end of what this file can do,
 *    and **#781 is the test that measures the property directly** by driving
 *    the page. All three of those wrappings fail it.
 *
 * WHAT IT PRINTS
 *   One JSON object per region asked for, on one line, with:
 *     text    the region's code, comments dropped, runs of whitespace freed
 *     braces  `{` open where the region starts, counting only real brace tokens
 *     alt     alternative-syntax blocks open (`if (…):` … `endif;`)
 *     exits   `return` / `exit` / `die` at the region's own brace depth before it
 *     before  the last code character before the region, for the cheap glue test
 *     count   how many times the anchor appears, so a dead duplicate is visible
 *
 * WHAT IT STILL CANNOT DO, said plainly rather than implied. It reports where a
 * region SITS; it cannot prove the region RUNS. A conditional `require` of the
 * whole file, a `goto` over it, an exception thrown above it, or an `exit`
 * inside a function called above it all leave these numbers unchanged. Proving
 * reachability needs a test that drives the page — which is why one is owed, and
 * is its own issue.
 *
 * @package   Portal\Tools\AuditChecks
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/763
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// 📥 Arguments: a JSON array of {file, anchor, mode}. `mode` is "block" (run to
//    the `}` that closes the first `{` at or after the anchor) or "endif" (run
//    to the next `endif;`, for a region that is markup rather than one block).
$raw = $argv[1] ?? '';
if ($raw === '') {
    fwrite(STDERR, "member-language-structure: no request given\n");
    exit(2);
}
$request = json_decode($raw, true);
if (is_array($request) === false) {
    fwrite(STDERR, "member-language-structure: the request is not JSON\n");
    exit(2);
}

/**
 * 🧱 Tokens that open an alternative-syntax block, as PHP itself labels them.
 *
 * ⚠️ THE WHOLE POINT IS THAT THESE ARE TOKEN IDS, NOT WORDS. A hand-rolled
 *    version matched the word `endif` with `\bendif\b`, and `$` is not a word
 *    character — so `$endif = 1;` decremented the counter and cancelled a real
 *    `if (false):` wrapper. Measured, with the guard green and the whole region
 *    dead. Here `$endif` is `T_VARIABLE` and cannot be mistaken for anything.
 */
const ALT_OPEN = [T_IF, T_WHILE, T_FOR, T_FOREACH, T_SWITCH];

/**
 * ⚠️ `else:` AND `elseif:` ARE NOT OPENERS, and counting them was a cry-wolf.
 *    An `if (…):` chain opens ONCE and closes once, with a single `endif;`
 *    however many branches it has — so counting each `else:` as another opener
 *    left `alt` permanently one too high per extra branch. Measured: adding an
 *    ordinary `<?php else: ?>` branch to a balanced block eighty-five lines
 *    ABOVE the pinned region reported that the region had MOVED, which was
 *    untrue, over a thoroughly ordinary template edit. A check that cries wolf
 *    gets switched off (#483).
 */
const ALT_BRANCH = [T_ELSEIF, T_ELSE];
const ALT_CLOSE = [T_ENDIF, T_ENDWHILE, T_ENDFOR, T_ENDFOREACH, T_ENDSWITCH];

/** Tokens that end a run of statements at the current depth. */
const EXIT_TOKENS = [T_RETURN, T_EXIT];

/**
 * Every token of a file, each as [id, text, offset], with comments dropped.
 *
 * `token_get_all()` gives ids and text but not offsets, so they are counted as
 * we go. Comments and doc comments go here rather than later, because the
 * pinned text must not change when somebody rewords a comment.
 */
function tokensOf(string $source): array
{
    $out    = [];
    $offset = 0;
    foreach (token_get_all($source) as $token) {
        if (is_array($token) === true) {
            [$id, $text] = $token;
            if ($id !== T_COMMENT && $id !== T_DOC_COMMENT) {
                $out[] = [$id, $text, $offset];
            }
            $offset += strlen($text);
            continue;
        }
        // A single-character token: `{`, `}`, `;`, `(` and so on.
        $out[] = [null, $token, $offset];
        $offset += strlen($token);
    }
    return $out;
}

/**
 * The file rendered as CODE ONLY, plus a map back to token indexes.
 *
 * 🚨 THE ANCHOR USED TO BE FOUND WITH `strpos()` OVER THE RAW SOURCE — the one
 *    place in this file that did not ask PHP, in a file whose whole argument is
 *    that raw text cannot be trusted. Measured consequences, both on correct
 *    code:
 *
 *      // Note: Auth::ensureSession(); must stay above this.
 *          -> "appears 2 times, so this pin is worthless", and the message sent
 *             the reader hunting for dead code that does not exist. `index.php`
 *             ALREADY carries two comments naming that call and was saved only
 *             by their missing semicolon.
 *
 *      $note = "Auth::ensureSession(); runs below";
 *          -> three failures, because the region was relocated INSIDE the
 *             string: the token before it "ends '='", and the pinned text was
 *             nonsense.
 *
 *    The probe already drops comments from the token list so that rewording one
 *    cannot change the pinned text. This path denied that intention.
 *
 *    So the anchor is searched for in a rendering built from TOKENS: comments
 *    gone, every string literal masked to `x` of the same length. A comment or
 *    a string that quotes the anchor cannot be found, and cannot be counted.
 *
 * @return array{0: string, 1: array<int,int>} the code-only text, and for each
 *         of its byte offsets the index of the token it came from.
 */
function codeOnly(array $tokens): array
{
    $text = '';
    $map  = [];
    foreach ($tokens as $i => [$id, $piece]) {
        if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
            continue;
        }
        /* ⚠️ STRINGS ARE KEPT HERE, and a first version masked them. The
         *    handler's anchor IS `if (array_key_exists('locale', $_POST)` --
         *    it contains a string -- so masking made it unfindable and the
         *    probe answered "anchor not found" for a region that is plainly
         *    there. That is the same trap as round 5's masked-copy search,
         *    which returned a neutral sentinel and nearly pinned an inert
         *    check. Instead, a match that LANDS INSIDE a string token is
         *    rejected below, which is what finding 2's string case needs. */
        $start = strlen($text);
        $text .= $piece;
        for ($k = $start; $k < strlen($text); $k += 1) {
            $map[$k] = $i;
        }
    }
    return [$text, $map];
}

/** The index of the token at which the anchor begins, searched in CODE only. */
function anchorIndex(array $tokens, string $codeText, array $map, string $anchor): ?int
{
    foreach (anchorOffsets($tokens, $codeText, $map, $anchor) as $at) {
        return $map[$at];
    }
    return null;
}

/**
 * Every offset in the code-only text where the anchor really begins AS CODE.
 *
 * A match whose first byte belongs to a string literal is not code: it is a
 * string that happens to quote the anchor. `index.php` already carries two
 * comments naming `Auth::ensureSession()`, and a string naming it relocated the
 * whole region INSIDE that string, so the token before it "ended `=`" and the
 * pinned text was nonsense. Comments are dropped before we get here; strings
 * are kept, because an anchor may legitimately contain one, and rejected here.
 *
 * @return list<int>
 */
function anchorOffsets(array $tokens, string $codeText, array $map, string $anchor): array
{
    $out = [];
    $from = 0;
    while (true) {
        $at = strpos($codeText, $anchor, $from);
        if ($at === false) {
            break;
        }
        $from = $at + 1;
        $tokenId = $tokens[$map[$at] ?? -1][0] ?? null;
        if ($tokenId === T_CONSTANT_ENCAPSED_STRING || $tokenId === T_ENCAPSED_AND_WHITESPACE) {
            continue;
        }
        $out[] = $at;
    }
    return $out;
}

/**
 * Is this `if` / `while` / `for` / `foreach` / `switch` / `else` the
 * alternative-syntax form — a `:` where a `{` would otherwise be?
 *
 * `else:` and `endif;` carry no bracket; the rest are `keyword ( … ) :`.
 */
function opensAlternativeSyntax(array $tokens, int $i): bool
{
    $id = $tokens[$i][0];
    $n  = count($tokens);
    $j  = $i + 1;
    // Step over whitespace.
    while ($j < $n && $tokens[$j][0] === T_WHITESPACE) {
        $j += 1;
    }
    if ($j >= $n || $tokens[$j][1] !== '(') {
        return false;
    }
    $depth = 0;
    while ($j < $n) {
        if ($tokens[$j][1] === '(') {
            $depth += 1;
        } elseif ($tokens[$j][1] === ')') {
            $depth -= 1;
            if ($depth === 0) {
                $j += 1;
                break;
            }
        }
        $j += 1;
    }
    while ($j < $n && $tokens[$j][0] === T_WHITESPACE) {
        $j += 1;
    }
    return $j < $n && $tokens[$j][1] === ':';
}

$answers = [];

foreach ($request as $want) {
    $file   = (string) ($want['file'] ?? '');
    $anchor = (string) ($want['anchor'] ?? '');
    $mode   = (string) ($want['mode'] ?? 'block');

    if (is_file($file) === false) {
        $answers[] = ['error' => 'no such file: ' . $file];
        continue;
    }
    $source = (string) file_get_contents($file);
    $tokens = tokensOf($source);

    // 📄 `mode: "file"` asks for the WHOLE file's code rather than a region:
    //    every token's text, comments dropped, string literals masked to runs
    //    of `x` (or kept, for `file_keys`, where the thing being looked for IS
    //    a string such as `$_GET['lang']`).
    //
    // ⚠️ THIS EXISTS BECAUSE THE PLAIN-TEXT CHECKS WERE READING COMMENTS AS
    //    CODE. They used a Python comment-stripper, which the six rounds proved
    //    cannot be trusted with PHP — and simplifying it so it could not be
    //    wrong about `#[` left it not stripping `//` at all, so a COMMENT
    //    mentioning `I18n::switchLocale` answered the check that looks for the
    //    call. One source of truth for "what is this PHP", and it is PHP.
    if ($mode === 'file' || $mode === 'file_keys') {
        $keep = $mode === 'file_keys';
        $pieces = [];
        foreach ($tokens as [$id, $text]) {
            if ($id === T_WHITESPACE) {
                $pieces[] = $text;
                continue;
            }
            if ($keep === false
                && ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE)
            ) {
                // Mask the contents, keep the length and any line breaks, so a
                // `)` or the word `switchLocale` inside a message cannot answer
                // a search falsely.
                $pieces[] = preg_replace('/[^\n]/', 'x', $text);
                continue;
            }
            $pieces[] = $text;
        }
        $answers[] = ['text' => implode('', $pieces)];
        continue;
    }

    [$codeText, $codeMap] = codeOnly($tokens);
    $start = anchorIndex($tokens, $codeText, $codeMap, $anchor);
    if ($start === null) {
        $answers[] = ['error' => 'anchor not found', 'count' => 0];
        continue;
    }

    // How many times the anchor appears, so a dead duplicate above the live
    // code cannot satisfy a pin that takes the first match. Counted in CODE
    // only — a comment or a string that quotes it is not a duplicate, and
    // saying it is was this check's worst cry-wolf.
    $count = count(anchorOffsets($tokens, $codeText, $codeMap, $anchor));

    // ── Where the region sits ────────────────────────────────────────────
    $braces = 0;
    $alt    = 0;
    for ($i = 0; $i < $start; $i += 1) {
        $text = $tokens[$i][1];
        $id   = $tokens[$i][0];
        if ($id === null && $text === '{') {
            $braces += 1;
        } elseif ($id === null && $text === '}') {
            $braces -= 1;
        } elseif ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            // `"{$x}"` inside a string: PHP opens a brace token here and closes
            // it with a plain `}`, so it must be counted or the two disagree.
            $braces += 1;
        } elseif (in_array($id, ALT_CLOSE, true) === true) {
            $alt -= 1;
        } elseif (in_array($id, ALT_OPEN, true) === true
            && opensAlternativeSyntax($tokens, $i) === true
        ) {
            $alt += 1;
        }
    }

    // `return` / `exit` / `die` at the region's OWN depth before it. A bare
    // `return;` above the region changes no count and hides it just as surely.
    //
    // ⚠️ `$exit` IS NOT AN EXIT. A hand-rolled version matched the word, and
    //    `$exit = false;` — a variable name this repository really uses, twelve
    //    times — made the guard report that the region had MOVED when nothing
    //    had. A check that cries wolf gets switched off (#483).
    $exits = 0;
    $depth = 0;
    for ($i = 0; $i < $start; $i += 1) {
        $text = $tokens[$i][1];
        $id   = $tokens[$i][0];
        if ($id === null && $text === '{') {
            $depth += 1;
        } elseif ($id === null && $text === '}') {
            $depth -= 1;
        } elseif ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth += 1;
        } elseif (in_array($id, EXIT_TOKENS, true) === true && $depth === $braces) {
            $exits += 1;
        }
        /* ⚠️ THERE IS NO `die`-AS-A-NAME BRANCH HERE, AND AN EARLIER VERSION HAD
         *    ONE "because counting both is the safe direction". It was the
         *    cry-wolf direction. Measured on PHP 8.5.11 with `token_get_all()`:
         *    `die` is `T_EXIT` in EVERY context where it exits — `die();`,
         *    `die;`, `@die()`, `(die)`, even `Foo::DIE` and `const DIE = 1` —
         *    and `T_STRING` only as a method or property name after `->`. So
         *    the branch caught no real exit and fired on `$log->die();`, which
         *    reported the region had MOVED when nothing had. */
    }

    // ── Where the region ends ────────────────────────────────────────────
    $end = null;
    if ($mode === 'endif') {
        /* ⚠️ IT MUST BALANCE. An earlier version stopped at the FIRST
         *    alternative-syntax closer of any kind, and the account page's
         *    region contains a `foreach (…):` of its own — so the region ended
         *    at that inner `endforeach;` and the last ten lines of the control
         *    (the closing `</select>`, the whole help text, and the control's
         *    real `endif;`) were OUTSIDE the pin, while the docstring said
         *    "any change to any statement in them fails". Measured by printing
         *    the region: it ended `?> </option> <?php endforeach` and contained
         *    no `endif` at all. */
        $open = 0;
        for ($i = $start; $i < count($tokens); $i += 1) {
            $id = $tokens[$i][0];
            if (in_array($id, ALT_OPEN, true) === true
                && opensAlternativeSyntax($tokens, $i) === true
            ) {
                $open += 1;
                continue;
            }
            if (in_array($id, ALT_CLOSE, true) === true) {
                /* The region BEGINS inside the block this closes, so the closer
                 * that brings the count back to zero is the region's end. A
                 * first version ended on the first closer seen at zero, which
                 * for the account page meant its inner `endforeach;` and left
                 * the last ten lines of the control outside the pin. */
                if ($open <= 1) {
                    $end = $i;
                    break;
                }
                $open -= 1;
            }
        }
    } else {
        $opened = null;
        $d      = 0;
        for ($i = $start; $i < count($tokens); $i += 1) {
            $text = $tokens[$i][1];
            $id   = $tokens[$i][0];
            $isOpen  = ($id === null && $text === '{')
                || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES;
            $isClose = ($id === null && $text === '}');
            if ($isOpen === true) {
                $opened = $opened ?? $i;
                $d += 1;
            } elseif ($isClose === true && $opened !== null) {
                $d -= 1;
                if ($d === 0) {
                    $end = $i;
                    break;
                }
            }
        }
    }
    if ($end === null) {
        $answers[] = ['error' => 'could not find the end of the region', 'count' => $count];
        continue;
    }

    // ── The pinned text ─────────────────────────────────────────────────
    // Token text, comments already dropped, runs of whitespace freed to one
    // space. PHP does not care about whitespace outside a string or a heredoc,
    // and a heredoc is refused separately, so nothing meaningful is discarded.
    $pieces = [];
    $heredoc = false;
    for ($i = $start; $i <= $end; $i += 1) {
        [$id, $text] = $tokens[$i];
        if ($id === T_START_HEREDOC) {
            $heredoc = true;
        }
        if ($id === T_WHITESPACE) {
            $pieces[] = ' ';
            continue;
        }
        if ($id === T_INLINE_HTML) {
            // 🧹 A COMPLETE HTML comment inside one markup token is a comment,
            //    and rewording it must not fail the pin — a check that cries
            //    wolf gets switched off (#483). Measured: before this, adding
            //    `<!-- new comment -->` to the account page's markup turned the
            //    guard red over correct code.
            //
            /* ⚠️ ONLY A COMPLETE PAIR INSIDE ONE TOKEN, and that is the whole
             *    care needed here. An HTML comment with a PHP block inside it is
             *    NOT one token: PHP splits it into markup, a real open tag, real
             *    code and more markup, because **PHP executes PHP inside an HTML
             *    comment**. That spelling got past round 5's reader, and
             *    stripping only complete pairs leaves it fully visible.
             *
             * 🙃 AND THE FIRST VERSION OF THIS VERY COMMENT BROKE THE FILE, in
             *    the most fitting way available: it wrote the example out in a
             *    `//` comment, and **a closing PHP tag inside a single-line
             *    comment ENDS PHP MODE** — so everything below it became markup
             *    and PHP reported an unclosed brace sixty lines later. The whole
             *    argument for this file is that PHP's grammar holds surprises a
             *    reader will not guess; it holds them for the author too. Hence
             *    a block comment, and no closing tag written out anywhere here. */
            $pieces[] = (string) preg_replace('/<!--.*?-->/s', ' ', $text);
            continue;
        }
        $pieces[] = $text;
    }
    $text = trim((string) preg_replace('/\s+/', ' ', implode('', $pieces)));

    // The last code character before the region, for the cheap glue test. Taken
    // from TOKENS, so a `;` inside a comment or a string cannot answer for it.
    $before = '';
    for ($i = $start - 1; $i >= 0; $i -= 1) {
        if ($tokens[$i][0] === T_WHITESPACE) {
            continue;
        }
        if ($tokens[$i][0] === T_OPEN_TAG || $tokens[$i][0] === T_OPEN_TAG_WITH_ECHO) {
            $before = '<?php';
            break;
        }
        if ($tokens[$i][0] === T_INLINE_HTML) {
            $trimmed = rtrim($tokens[$i][1]);
            $before = $trimmed === '' ? 'html' : substr($trimmed, -1);
            break;
        }
        $trimmed = rtrim($tokens[$i][1]);
        $before = $trimmed === '' ? '' : substr($trimmed, -1);
        break;
    }

    /* 🚪 EXITS AT ANY DEPTH ABOVE THE REGION, pinned separately from `exits`.
     *
     *    `exits` counts only the region's OWN level, so that a legitimate
     *    guard — `if (the form token is wrong) { exit; }`, of which the handler
     *    has several above the region — is not read as killing it. That is
     *    right, and it is also how `if (true) { exit; }` walked through: one
     *    pair of braces put the exit a level deeper.
     *
     *    Counting them all catches that. **The cost, said plainly: adding a new
     *    guard above the region now needs a `--repin`.** That is a visible,
     *    deliberate act, and an exit newly placed above the code that stores
     *    somebody's language is a thing worth having to acknowledge. */
    $exitsAnywhere = 0;
    for ($i = 0; $i < $start; $i += 1) {
        if (in_array($tokens[$i][0], EXIT_TOKENS, true) === true) {
            $exitsAnywhere += 1;
        }
    }

    $answers[] = [
        'text'    => $text,
        'braces'  => $braces,
        'alt'     => $alt,
        'exits'   => $exits,
        'anyexit' => $exitsAnywhere,
        'before'  => $before,
        'count'   => $count,
        'heredoc' => $heredoc,
    ];
}

echo json_encode($answers), "\n";
exit(0);
