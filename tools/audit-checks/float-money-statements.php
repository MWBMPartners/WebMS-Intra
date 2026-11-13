<?php
// Path: tools/audit-checks/float-money-statements.php
/**
 * -----------------------------------------------------------------------------
 * Helper for check_no_float_money.py (#1004): PHP's own reading of PHP files
 * -----------------------------------------------------------------------------
 * Prints, as JSON, every PHP file named on the command line cut into its
 * statements, with comments and docblocks REMOVED and every string literal's
 * contents replaced by nothing ('' stays as ''), so a comment or a message that
 * QUOTES the forbidden pattern is never reported. Statements are cut at `;`,
 * `{` and `}`, so the body of a closure or an `if` is its own statement.
 * A float cast is written "(float)" whatever its spelling ((double), (real),
 * any capitals), and a whole-number cast "(int)" ((integer), any capitals),
 * and inline HTML (a page's own markup and scripts) is left out: it is not PHP.
 *
 * Output: {"<path>": [[<line the statement starts on>, "<its code>"], …], …}
 * A file PHP cannot read is reported as {"<path>": null}, and the caller fails.
 *
 * It asks PHP's tokeniser rather than a regular expression for the reason
 * this repository has recorded many times: a text search cannot tell code from
 * a comment or a string, and every handler #1004 changed QUOTES the old code
 * in a comment.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1004
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$out = [];
foreach (array_slice($argv, 1) as $path) {
    $source = @file_get_contents($path);
    if ($source === false) {
        $out[$path] = null;
        continue;
    }
    try {
        $tokens = token_get_all($source, TOKEN_PARSE);
    } catch (\Throwable $e) {
        $out[$path] = null;
        continue;
    }
    $statements = [];
    $code       = '';
    $startLine  = 0;
    $line       = 1;
    $flush = static function () use (&$statements, &$code, &$startLine): void {
        $text = trim((string) preg_replace('/\s+/', ' ', $code));
        if ($text !== '') {
            $statements[] = [$startLine, $text];
        }
        $code      = '';
        $startLine = 0;
    };
    foreach ($tokens as $token) {
        if (is_array($token) === true) {
            [$id, $text, $line] = $token;
            if ($id === T_COMMENT || $id === T_DOC_COMMENT || $id === T_INLINE_HTML || $id === T_OPEN_TAG || $id === T_CLOSE_TAG) {
                if ($id === T_CLOSE_TAG) {
                    $flush();
                }
                continue;
            }
            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $text = $text[0] . $text[0];
            } elseif ($id === T_ENCAPSED_AND_WHITESPACE) {
                $text = '';
            } elseif ($id === T_DOUBLE_CAST) {
                $text = '(float)';
            } elseif ($id === T_INT_CAST) {
                $text = '(int)';
            }
            if ($startLine === 0 && trim($text) !== '') {
                $startLine = $line;
            }
            $code .= $text;
            continue;
        }
        if ($token === ';' || $token === '{' || $token === '}') {
            $code .= $token;
            $flush();
            continue;
        }
        if ($startLine === 0) {
            $startLine = $line;
        }
        $code .= $token;
    }
    $flush();
    $out[$path] = $statements;
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
