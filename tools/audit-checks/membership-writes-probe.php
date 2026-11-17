<?php
// Path: tools/audit-checks/membership-writes-probe.php
/**
 * -----------------------------------------------------------------------------
 * Probe for check_one_membership_switch.py: the statements of PHP files 🔎
 * -----------------------------------------------------------------------------
 * Reads PHP source with PHP's OWN tokeniser and prints JSON, so the Python check
 * never has to guess where a comment, a string or a statement begins (a text
 * search finds the SQL that offboarding.php and rehire.php QUOTE in their
 * comments, and that is exactly what it must not count).
 *
 *   php tools/audit-checks/membership-writes-probe.php <file.php> [<file.php> ...]
 *   php tools/audit-checks/membership-writes-probe.php <folder>      (every .php under it)
 *
 * For every file: `statements`, one per `;`-separated piece of CODE, each with
 * its first line, the string literals of that piece joined in order (what a
 * statement built as 'UPDATE tblX ' . 'SET ...' says when put back together) and
 * the `Class::CONSTANT` names it uses; and `functions`, the names declared.
 * Comments are tokens of their own and are never part of a statement.
 *
 * WHAT IT CANNOT SEE: a table or column named in a variable, a statement built
 * with sprintf() or by a helper from pieces in other files, and a heredoc
 * (its body is one token and is joined whole, which is the right text but is
 * not split at `;`).
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/934
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** @return list<string> */
function mwp_files(string $arg): array
{
    if (is_dir($arg) === false) {
        return [$arg];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($arg, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() === true && substr($file->getFilename(), -4) === '.php') {
            $out[] = $file->getPathname();
        }
    }
    sort($out);

    return $out;
}

/** Turn a single-quoted or double-quoted PHP string token into its text. */
function mwp_unquote(int $id, string $text): string
{
    if ($id === T_CONSTANT_ENCAPSED_STRING) {
        $quote = $text[0];
        $body  = substr($text, 1, -1);
        if ($quote === "'") {
            return str_replace(["\\\\", "\\'"], ["\\", "'"], $body);
        }

        return stripcslashes($body);
    }

    return $text; // T_ENCAPSED_AND_WHITESPACE and T_INLINE_HTML: kept as written
}

$result = [];
foreach (array_slice($argv, 1) as $arg) {
    foreach (mwp_files($arg) as $path) {
        $source = file_get_contents($path);
        if ($source === false) {
            fwrite(STDERR, "cannot read $path\n");
            exit(2);
        }
        $tokens     = token_get_all($source);
        $statements = [];
        $functions  = [];
        $cur        = ['line' => 0, 'sql' => '', 'consts' => []];
        $flush      = static function () use (&$cur, &$statements): void {
            if ($cur['sql'] !== '' || $cur['consts'] !== []) {
                $statements[] = $cur;
            }
            $cur = ['line' => 0, 'sql' => '', 'consts' => []];
        };
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (is_array($t) === false) {
                if ($t === ';') {
                    $flush();
                }
                continue;
            }
            [$id, $text, $line] = $t;
            if ($id === T_COMMENT || $id === T_DOC_COMMENT || $id === T_WHITESPACE) {
                continue;
            }
            if ($id === T_FUNCTION) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) === true && $tokens[$j][0] === T_WHITESPACE) {
                        continue;
                    }
                    if (is_array($tokens[$j]) === true && $tokens[$j][0] === T_STRING) {
                        $functions[] = $tokens[$j][1];
                    }
                    break;
                }
            }
            if ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) {
                if ($cur['line'] === 0) {
                    $cur['line'] = $line;
                }
                $cur['sql'] .= mwp_unquote($id, $text);
                continue;
            }
            // Class::CONSTANT, as the tokeniser gives it: a name, '::', a name.
            if ($id === T_STRING || $id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED) {
                $k = $i + 1;
                while ($k < $count && is_array($tokens[$k]) === true && $tokens[$k][0] === T_WHITESPACE) {
                    $k++;
                }
                if ($k < $count && is_array($tokens[$k]) === true && $tokens[$k][0] === T_DOUBLE_COLON) {
                    $m = $k + 1;
                    while ($m < $count && is_array($tokens[$m]) === true && $tokens[$m][0] === T_WHITESPACE) {
                        $m++;
                    }
                    if ($m < $count && is_array($tokens[$m]) === true && $tokens[$m][0] === T_STRING) {
                        $parts = explode('\\', $text);
                        $cur['consts'][] = end($parts) . '::' . $tokens[$m][1];
                        if ($cur['line'] === 0) {
                            $cur['line'] = $line;
                        }
                    }
                }
            }
        }
        $flush();
        $result[$path] = ['statements' => $statements, 'functions' => $functions];
    }
}
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), "\n";
