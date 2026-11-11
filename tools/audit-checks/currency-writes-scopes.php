<?php
// Path: tools/audit-checks/currency-writes-scopes.php
/**
 * -----------------------------------------------------------------------------
 * Helper for check_currency_writes.py (#966, D-139): the CODE of one PHP file,
 * cut into scopes, with every comment removed by PHP's own tokeniser.
 * -----------------------------------------------------------------------------
 * Prints JSON: a list of {"name", "line", "code"}, one per NAMED function or
 * method (its body), plus one called "<file>" holding everything outside any
 * named function. A closure stays inside the function that holds it.
 *
 * Why PHP's tokeniser and not a pattern: the rule this serves is "a write of a
 * money record's currency must go through Currency::onEdit() in the same
 * function", and a comment that quotes the helper must not satisfy it. Every
 * guard in this repository that hand-parsed PHP was later found reading
 * comments as code (see check_static_calls.py's header); this one asks PHP.
 *
 * Usage: php currency-writes-scopes.php FILE
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/966
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$file = $argv[1] ?? '';
$src  = is_string($file) === true && is_file($file) === true ? file_get_contents($file) : false;
if ($src === false) {
    fwrite(STDERR, "cannot read {$file}\n");
    exit(2);
}

$tokens = token_get_all($src);
$scopes = [];
$outside = '';
$depth  = 0;
$stack  = [];          // open named functions: ['name', 'line', 'depth', 'code']
$pendingName = null;   // a named function seen, its body not yet opened
$pendingLine = 0;
$line = 1;

$count = count($tokens);
for ($i = 0; $i < $count; $i++) {
    $tok  = $tokens[$i];
    $text = is_array($tok) === true ? $tok[1] : $tok;
    $kind = is_array($tok) === true ? $tok[0] : null;
    if (is_array($tok) === true) {
        $line = $tok[2];
    }
    // Comments become their own line breaks, so nothing they say is code.
    if ($kind === T_COMMENT || $kind === T_DOC_COMMENT) {
        $text = str_repeat("\n", substr_count($text, "\n"));
    }
    if ($kind === T_FUNCTION) {
        // A NAMED function: the next meaningful token is its name.
        for ($j = $i + 1; $j < $count; $j++) {
            $n = $tokens[$j];
            if (is_array($n) === true && in_array($n[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === true) {
                continue;
            }
            if (is_array($n) === true && $n[0] === T_STRING) {
                $pendingName = $n[1];
                $pendingLine = $n[2];
            }
            break;
        }
    }
    $isOpen = $text === '{' || $kind === T_CURLY_OPEN || $kind === T_DOLLAR_OPEN_CURLY_BRACES;
    if ($isOpen === true) {
        $depth++;
        if ($pendingName !== null) {
            $stack[] = ['name' => $pendingName, 'line' => $pendingLine, 'depth' => $depth, 'code' => ''];
            $pendingName = null;
            continue;
        }
    } elseif ($text === ';' && $pendingName !== null) {
        $pendingName = null; // an abstract or interface method: no body
    }
    if ($text === '}') {
        if ($stack !== [] && $stack[count($stack) - 1]['depth'] === $depth) {
            $done = array_pop($stack);
            $scopes[] = ['name' => $done['name'], 'line' => $done['line'], 'code' => $done['code']];
            $depth--;
            continue;
        }
        $depth--;
    }
    if ($stack !== []) {
        $stack[count($stack) - 1]['code'] .= $text;
    } else {
        $outside .= $text;
    }
}
$scopes[] = ['name' => '<file>', 'line' => 1, 'code' => $outside];
echo json_encode($scopes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
