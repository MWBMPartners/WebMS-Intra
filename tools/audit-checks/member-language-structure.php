<?php
// Path: tools/audit-checks/member-language-structure.php
/**
 * -----------------------------------------------------------------------------
 * Ask PHP what the PHP is 🔍
 * -----------------------------------------------------------------------------
 * `check_member_language_applied.py` needs to ask plain questions of four PHP
 * files — "does this file call `I18n::switchLocale`?", "does the account page
 * offer a `<select name="locale">`?" — and it must not be fooled by a COMMENT
 * or a STRING that happens to contain the answer.
 *
 * WHY A PHP FILE RATHER THAN A PYTHON READER, which is the lesson here.
 * Six independent review rounds got a one-edit change past a hand-written
 * reader, and every one was the reader being wrong about PHP rather than the
 * rule being too narrow:
 *
 *   `$endif = 1;`               matched `\bendif\b`, cancelling a real wrapper
 *   a `}` in a heredoc body     counted as a real brace
 *   `#[Deprecated]`             read as a `#` comment, hiding a `{`
 *   a closing tag in a string   ended the PHP span early
 *   PHP inside an HTML comment  PHP RUNS this; the reader blanked it
 *   `$exit = false;`            counted as an exit, so it cried wolf
 *
 * **None of those distinctions is in the text; they are in the grammar.**
 * `token_get_all()` knows that `$endif` is `T_VARIABLE` and `endif` is
 * `T_ENDIF`, that `#[` is an attribute, that a brace in a heredoc is part of an
 * encapsed string, and that PHP in an HTML comment is still PHP.
 *
 * WHAT IT DOES NOW, AND WHAT IT USED TO DO
 * This file used to measure much more: each guarded region's exact text, how
 * deeply it was nested, how many exits sat above it, which token preceded it.
 * All of that existed to prove the code was REACHED, and it was removed on the
 * owner's decision (**D-065**) after eight review rounds and 52 findings —
 * every one after the first in the guard rather than in the fix. "Is this code
 * reached" is behavioural, and it is measured now by
 * `tools/i18n-member-language-http-selftest.php`, which drives the real pages.
 * **Where the property is "this code RUNS", drive it; where it is "this code
 * LOOKS like this", pin it.**
 *
 * So two modes are left, and nothing else:
 *   `file`       the whole file's code, comments dropped, string literals
 *                masked to runs of `x` of the same length
 *   `file_keys`  the same, with strings KEPT — for a question whose answer IS
 *                a string, such as `$_GET['lang']` or `name="locale"`
 *
 * WHAT IT STILL CANNOT DO, said plainly. Inline markup (`T_INLINE_HTML`) is
 * passed through whole in both modes, so a `<select name="locale">` written
 * inside an HTML comment would satisfy a question asked of `file_keys`. That is
 * why the checks reading markup are a courtesy and the HTTP test is the
 * guarantee: it asks the drawn page, where a commented-out control is absent.
 *
 * @package   Portal\Tools\AuditChecks
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/763
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// 📥 Arguments: a JSON array of {file, mode}, mode being "file" or "file_keys".
//    An `anchor` key is accepted and ignored, so an older caller still works.
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

$answers = [];

foreach ($request as $want) {
    $file = (string) ($want['file'] ?? '');
    $mode = (string) ($want['mode'] ?? 'file');

    if (is_file($file) === false) {
        $answers[] = ['error' => 'no such file: ' . $file];
        continue;
    }
    if ($mode !== 'file' && $mode !== 'file_keys') {
        $answers[] = ['error' => 'unknown mode: ' . $mode];
        continue;
    }

    $keep   = $mode === 'file_keys';
    $pieces = [];

    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        if (is_array($token) === false) {
            // A single-character token: `{`, `}`, `;`, `(` and so on.
            $pieces[] = $token;
            continue;
        }
        [$id, $text] = $token;

        // 🧹 Comments go, so that rewording one cannot change an answer.
        if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
            continue;
        }

        // 🎭 A string literal is masked unless the caller needs strings. The
        //    mask keeps the LENGTH and every line break, so a `)` or the word
        //    `switchLocale` inside a message cannot answer a search falsely
        //    while reported line numbers stay right.
        if ($keep === false
            && ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE)
        ) {
            $pieces[] = (string) preg_replace('/[^\n]/', 'x', $text);
            continue;
        }

        $pieces[] = $text;
    }

    $answers[] = ['text' => implode('', $pieces)];
}

echo json_encode($answers), "\n";
exit(0);
