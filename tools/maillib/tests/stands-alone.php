<?php
// Path: tools/maillib/tests/stands-alone.php
/**
 * -----------------------------------------------------------------------------
 * The stand-alone check: the mail library reaches for nothing outside itself 🧱
 * -----------------------------------------------------------------------------
 * `web/_core/maillib/` is written so that it can be lifted out and used in
 * other applications. That only works while NOTHING in it reaches for the
 * application around it: no class, constant or variable of the application, no
 * request data, no database, no environment, no output. Everything it needs
 * (settings, the clock, a log, the password) is handed to it.
 *
 *   php tools/maillib/tests/stands-alone.php                 check the library
 *   php tools/maillib/tests/stands-alone.php --root=FOLDER   check another folder (used when testing this check)
 *   php tools/maillib/tests/stands-alone.php --list          also print every function the library calls
 *
 * HOW IT READS: PHP'S OWN TOKENS, AND AN ALLOW-LIST. PHP can cut a file into
 * its "tokens" (each name, string, variable and symbol) without running it.
 * This check reads those, so a comment is exactly a comment and a string is
 * exactly a string. And it does not hunt for a list of bad words. It demands
 * that EVERYTHING the library names is accounted for:
 *   - every function it calls is on the short list below (`ALLOWED_FUNCTIONS`);
 *   - every class it names is the library's own, or on `ALLOWED_CLASSES`;
 *   - every other bare name is a type, or a constant PHP itself defines;
 *   - and nothing is reached by a name worked out while the program runs:
 *     no call through a variable (`$f()`), no `new $class`, no `$class::x`,
 *     no `$object->$name`, no `$$name`, and a function that takes a callback
 *     (`array_map` and the like) must be given a closure written on the spot.
 * With those four held, a name built up from pieces of text has nothing to be
 * handed to, which is what makes the list of functions mean something.
 *
 * WHY IT WAS REWRITTEN. The first version was a Python script that searched
 * each line of text for ten patterns and skipped any line that STARTED like a
 * comment. An independent check planted 27 ways of reaching outside; it caught
 * 7. It missed, among others, `getenv`, `error_log`, `header()`, a call
 * through a variable, a class named by a built-up string, `global` written
 * over two lines, and anything on a line that began with a short comment.
 *
 * WHAT IT STILL CANNOT SEE (said so that a pass is not read as more):
 *   - It does not follow what a value IS. It allows `file_get_contents`, so
 *     it cannot stop the library reading a file whose path it was handed, or
 *     built; and it allows `require $file;` once, in `autoload.php`, without
 *     proving how the loader arrives at `$file`.
 *   - It trusts the two lists. A function added to `ALLOWED_FUNCTIONS` is
 *     allowed. The functions that must never be added, because each is a way
 *     round the whole check, are named in `NEVER_ALLOW`, and the check fails
 *     if one of them is ever put on the list.
 *   - It checks what is written, not what an object handed in by the
 *     application does. The library calls methods on its `Clock` and
 *     `LogSink`; what those do is the application's own business.
 *
 * IT PROVES ITSELF FIRST. Before the real scan it reads the planted files in
 * `stands-alone-fixtures/`: each `planted_<rule>--<what>.php` must set off
 * that rule, `clean.php` must set off none, and every rule must have a planted
 * file. If that fails, the check fails, whatever the library holds. Those
 * files quote what the check hunts for on purpose and are never run.
 *
 * Exit code: 0 only when the check proved itself AND found nothing.
 *
 * @package   MWBM\Mail\Tests
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** The library's own namespace. Every class it declares or names must be in it, or on the list below. */
const OWN_NAMESPACE = 'MWBM\\Mail';

/**
 * Every function of PHP the library may call. Each was looked at: none can
 * call a function by name, read the application's state, or write anywhere
 * but to the socket it was given. Add to it only after the same look.
 */
const ALLOWED_FUNCTIONS = [
    // text and lists
    'addcslashes', 'array_column', 'array_keys', 'array_map', 'array_merge', 'array_push', 'array_slice',
    'base64_encode', 'bin2hex', 'ceil', 'chunk_split', 'count', 'explode', 'floor', 'get_class', 'implode',
    'in_array', 'is_array', 'is_int', 'is_resource', 'is_scalar', 'is_string', 'max', 'min', 'preg_match',
    'preg_match_all', 'preg_replace', 'preg_split', 'rawurlencode', 'rtrim', 'str_repeat', 'str_replace',
    'stripos', 'strlen', 'strncmp', 'strpos', 'strrpos', 'strtolower', 'strtoupper', 'substr',
    'substr_replace', 'trim',
    // time, chance and comparing
    'gmdate', 'hash_equals', 'hrtime', 'random_bytes', 'time', 'usleep',
    // addresses and names ("function_exists" only ever asks whether PHP can convert a non-English domain)
    'filter_var', 'function_exists', 'idn_to_ascii', 'inet_pton',
    // an attachment's file, by the path the application handed in
    'file_get_contents', 'filesize', 'is_file', 'is_readable',
    // how much memory is left
    'ini_get', 'memory_get_usage',
    // certificates
    'openssl_pkey_get_details', 'openssl_pkey_get_public', 'openssl_x509_fingerprint', 'openssl_x509_parse',
    'openssl_x509_verify',
    // the socket
    'fclose', 'feof', 'fread', 'fwrite', 'stream_context_create', 'stream_context_get_params',
    'stream_get_meta_data', 'stream_select', 'stream_set_blocking', 'stream_socket_client',
    'stream_socket_enable_crypto',
    // catching PHP's own warnings, and loading the library's own classes
    'restore_error_handler', 'set_error_handler', 'spl_autoload_register',
];

/**
 * Functions on the list above that take a callback, which for each of them is
 * the FIRST thing in its brackets. It must be a closure written on the spot.
 * A function that takes a callback may be allowed only if it is listed here
 * too: given a piece of text instead, it would call whatever that text names.
 */
const TAKES_A_CALLBACK = ['array_map', 'set_error_handler', 'spl_autoload_register'];

/**
 * Never to be allowed, whatever the reason given: each is a way round this
 * whole check, or a way to reach the application or the machine.
 */
const NEVER_ALLOW = [
    // call or name something by text
    'call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array', 'func_get_arg',
    'func_get_args', 'constant', 'define', 'defined', 'class_exists', 'get_defined_vars', 'extract', 'compact',
    'create_function', 'assert', 'unserialize', 'register_shutdown_function', 'ob_start',
    // the environment, the request and the response
    'getenv', 'putenv', 'ini_set', 'header', 'headers_sent', 'setcookie', 'session_start', 'error_log',
    'trigger_error', 'syslog', 'php_sapi_name', 'get_included_files',
    // writing files, and other ways to the network or the machine
    'file_put_contents', 'fopen', 'unlink', 'mkdir', 'rename', 'copy', 'tempnam', 'tmpfile', 'mail', 'exec',
    'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'fsockopen', 'pfsockopen', 'curl_init',
    'curl_exec', 'mysqli_connect', 'stream_socket_server', 'dl',
];

/** Classes of PHP itself the library may name. */
const ALLOWED_CLASSES = [
    'Throwable', 'LogicException', 'InvalidArgumentException', 'Closure', 'WeakMap', 'SensitiveParameter',
    'OpenSSLCertificate', 'OpenSSLAsymmetricKey',
];

/** Words that stand where a class name could, and name no class. */
const TYPE_WORDS = [
    'int', 'string', 'bool', 'float', 'array', 'mixed', 'void', 'never', 'null', 'true', 'false', 'callable',
    'iterable', 'object', 'self', 'static', 'parent',
];

/** Variables that hold the request, the session, the environment or every global. */
const REQUEST_VARIABLES = ['$_SERVER', '$_GET', '$_POST', '$_COOKIE', '$_SESSION', '$_ENV', '$_FILES', '$_REQUEST', '$GLOBALS'];

/** Every rule, with what it means. Each must have a planted file that sets it off. */
const RULES = [
    'function-not-allowed' => 'a call to a function that is not on the list of allowed functions',
    'class-not-allowed' => "a class that is neither the library's own nor on the list of allowed classes",
    'unknown-name' => 'a bare name that is not a type, a class of the library or a constant PHP itself defines',
    'call-by-variable' => 'a call through a variable, which can hold the NAME of any function',
    'class-by-variable' => 'a class or member reached through a variable or a built-up name',
    'variable-by-name' => 'a variable reached by a built-up name ($$name)',
    'callback-not-a-closure' => 'a function that takes a callback, given something other than a closure written on the spot',
    'request-variable' => 'a variable holding the request, the session, the environment or every global',
    'settings-variable' => 'the $SETTINGS of an application',
    'global-keyword' => 'the global keyword',
    'eval' => 'eval, or a command in backticks',
    'include' => 'a file loaded from anywhere but the one line in autoload.php',
    'output' => 'echo, print, text outside PHP tags, or exit',
    'outside-namespace' => 'something declared or imported outside the library\'s own namespace',
    'name-in-a-string' => "a piece of text naming something of an application (Portal\\, PORTAL_ or mysqli)",
    'stray-file' => 'a file that is not PHP (the library folder holds only PHP)',
];

if (PHP_SAPI !== 'cli') {
    exit(2);
}
exit(main(array_slice($argv, 1)));

/** @param list<string> $arguments */
function main(array $arguments): int
{
    $root = dirname(__DIR__, 3) . '/web/_core/maillib';
    $list = false;
    foreach ($arguments as $argument) {
        if (strncmp($argument, '--root=', 7) === 0) {
            $root = substr($argument, 7);
        } elseif ($argument === '--list') {
            $list = true;
        } else {
            fwrite(STDERR, "usage: php stands-alone.php [--root=FOLDER] [--list]\n");
            return 1;
        }
    }

    $problems = proveTheCheck(__DIR__ . '/stands-alone-fixtures', dirname(__DIR__, 3) . '/web/_core/maillib');
    if ($problems !== []) {
        echo "The check failed its own proof, so its answer about the library cannot be trusted:\n";
        foreach ($problems as $problem) {
            echo "  - $problem\n";
        }
        return 1;
    }
    echo 'The check proved itself: each of its ' . count(RULES) . " rules fires on a planted file, every planted file is caught, and clean.php sets off none.\n";

    $files = filesUnder($root);
    $php = array_filter($files, static fn (string $file): bool => substr($file, -4) === '.php');
    if ($php === []) {
        echo "No PHP files were found under $root. Nothing was checked, and that is a failure, not a pass.\n";
        return 1;
    }
    $total = 0;
    $called = [];
    foreach ($files as $file) {
        foreach (findingsIn($file, $root, $called) as [$rule, $line, $what]) {
            $total++;
            echo '  ' . substr($file, strlen($root) + 1) . ":$line: [$rule] $what\n";
        }
    }
    if ($list === true) {
        ksort($called);
        echo 'Functions the library calls (' . count($called) . '): ' . implode(' ', array_keys($called)) . "\n";
    }
    echo 'Read ' . count($files) . " files under $root (" . count($php) . " PHP). Findings: $total.\n";
    if ($total > 0) {
        echo "The mail library reaches for something outside itself. Hand it in through the library's own\n";
        echo "interfaces (its settings objects, Clock, LogSink, Secret) instead.\n";
    }
    echo "the check reached its end\n";
    return $total === 0 ? 0 : 1;
}

/**
 * Run the check over its planted files. Returns what is wrong with the check itself.
 *
 * @return list<string>
 */
function proveTheCheck(string $fixtures, string $library): array
{
    $problems = [];
    foreach (array_intersect(ALLOWED_FUNCTIONS, NEVER_ALLOW) as $function) {
        $problems[] = "$function() is on the list of allowed functions, and it is one of those that must never be";
    }
    if (is_dir($fixtures) !== true) {
        return ["the planted files are missing: $fixtures"];
    }
    $proved = [];
    $called = [];
    foreach (filesUnder($fixtures) as $file) {
        $name = basename($file);
        $hit = array_unique(array_column(findingsIn($file, $library, $called), 0));
        if ($name === 'clean.php') {
            if ($hit !== []) {
                $problems[] = 'clean.php set off [' . implode(', ', $hit) . '] though it holds nothing forbidden';
            }
            continue;
        }
        if (preg_match('/\Aplanted_([a-z-]+?)(?:--[a-z0-9-]+)?\.[a-z]+\z/', $name, $found) !== 1 || isset(RULES[$found[1]]) !== true) {
            $problems[] = "$name is neither clean.php nor a planted_<rule>--<what> file for a rule that exists";
            continue;
        }
        $proved[$found[1]] = true;
        if (in_array($found[1], $hit, true) !== true) {
            $problems[] = "$name did NOT set off the rule \"{$found[1]}\" (it set off [" . implode(', ', $hit) . '])';
        }
    }
    foreach (array_keys(RULES) as $rule) {
        if (isset($proved[$rule]) !== true) {
            $problems[] = "no planted file proves the rule \"$rule\"";
        }
    }
    if (is_file($fixtures . '/clean.php') !== true) {
        $problems[] = 'clean.php is missing, so nothing shows the check can stay quiet';
    }
    return $problems;
}

/**
 * Every file under a folder, sorted.
 *
 * @return list<string>
 */
function filesUnder(string $folder): array
{
    $files = [];
    if (is_dir($folder) === true) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() === true) {
                $files[] = $file->getPathname();
            }
        }
    }
    sort($files);
    return $files;
}

/**
 * Everything one file reaches for that it may not.
 *
 * @param string             $library The library's folder: where its loader is, and its classes under `src/`.
 * @param array<string,true> $called  Filled with every function the file calls, allowed or not.
 *
 * @return list<array{0:string,1:int,2:string}> Each finding as [rule, line, what was found].
 */
function findingsIn(string $file, string $library, array &$called): array
{
    if (substr($file, -4) !== '.php') {
        return [['stray-file', 0, 'only PHP files belong in the library folder']];
    }
    // The pieces that carry no meaning are dropped; what is left is the program.
    $tokens = [];
    foreach (PhpToken::tokenize((string) file_get_contents($file)) as $token) {
        if (in_array($token->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true) !== true) {
            $tokens[] = $token;
        }
    }
    $found = [];
    $add = static function (string $rule, PhpToken $at, string $what = '') use (&$found): void {
        $found[] = [$rule, $at->line, $what === '' ? $at->text : $what];
    };
    $isLoader = $file === $library . DIRECTORY_SEPARATOR . 'autoload.php';
    $constants = get_defined_constants(true);
    unset($constants['user']);
    $builtIn = array_merge(...array_values($constants));

    $namespace = '';
    $imports = [];     // the short name a file uses => the full name it stands for
    $inConstant = false;
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        $before = $tokens[$i - 1] ?? null;
        $after = $tokens[$i + 1] ?? null;
        $text = $token->text;

        if ($token->id === T_CONST) {
            $inConstant = true;
        } elseif ($text === ';') {
            $inConstant = false;
        }

        switch ($token->id) {
            case T_INLINE_HTML:
            case T_CLOSE_TAG:
            case T_ECHO:
            case T_PRINT:
            case T_EXIT:
            case T_HALT_COMPILER:
                $add('output', $token);
                continue 2;
            case T_GLOBAL:
                $add('global-keyword', $token);
                continue 2;
            case T_EVAL:
                $add('eval', $token);
                continue 2;
            case T_INCLUDE:
            case T_INCLUDE_ONCE:
            case T_REQUIRE:
            case T_REQUIRE_ONCE:
                // The one load allowed: `require $file;` in the library's own loader.
                $isTheOne = $isLoader === true && $token->id === T_REQUIRE && ($after->text ?? '') === '$file' && ($tokens[$i + 2]->text ?? '') === ';';
                if ($isTheOne !== true) {
                    $add('include', $token);
                }
                continue 2;
            case T_DOLLAR_OPEN_CURLY_BRACES:
                $add('variable-by-name', $token);
                continue 2;
            case T_CONSTANT_ENCAPSED_STRING:
            case T_ENCAPSED_AND_WHITESPACE:
                if (preg_match('/Portal\\\\|PORTAL_|mysqli/i', $text) === 1) {
                    $add('name-in-a-string', $token);
                }
                continue 2;
            case T_OBJECT_OPERATOR:
            case T_NULLSAFE_OBJECT_OPERATOR:
                if ($after !== null && ($after->id === T_VARIABLE || $after->text === '{')) {
                    $add('class-by-variable', $token, $text . $after->text);
                }
                continue 2;
            case T_NEW:
            case T_INSTANCEOF:
                if ($after !== null && ($after->id === T_VARIABLE || $after->text === '(')) {
                    $add('class-by-variable', $token, $text . ' ' . $after->text);
                }
                continue 2;
            case T_VARIABLE:
                if (in_array($text, REQUEST_VARIABLES, true) === true) {
                    $add('request-variable', $token);
                } elseif ($text === '$SETTINGS') {
                    $add('settings-variable', $token);
                }
                // `X::$name` alone is a class's own stored value; `X::$name(` is a call by a built-up name.
                if ($after !== null && $after->text === '(') {
                    $add('call-by-variable', $token, $text . '(');
                }
                if ($after !== null && $after->id === T_DOUBLE_COLON && $text !== '$this') {
                    $add('class-by-variable', $token, $text . '::');
                }
                continue 2;
            case T_NAMESPACE:
                $namespace = ($after->text ?? '');
                if ($namespace !== OWN_NAMESPACE && strncmp($namespace, OWN_NAMESPACE . '\\', strlen(OWN_NAMESPACE) + 1) !== 0) {
                    $add('outside-namespace', $token, 'namespace ' . $namespace);
                }
                $i++;
                continue 2;
            case T_CLASS:
            case T_INTERFACE:
            case T_TRAIT:
            case T_ENUM:
                // A declaration (not `X::class`, and not an anonymous `new class`).
                if ($before !== null && ($before->id === T_DOUBLE_COLON || $before->id === T_NEW)) {
                    continue 2;
                }
                if ($namespace === '') {
                    $add('outside-namespace', $token, $text . ' ' . ($after->text ?? '') . ' is declared in no namespace');
                }
                $i++;
                continue 2;
            case T_USE:
                if ($after !== null && $after->text === '(') {
                    continue 2; // a closure taking variables in: `function () use ($x)`
                }
                // An import, or a trait taken into a class: read to the end of the statement.
                $statement = [];
                for ($i++; $i < $count && $tokens[$i]->text !== ';'; $i++) {
                    $statement[] = $tokens[$i];
                }
                foreach (importsOf($statement) as $short => $full) {
                    $imports[$short] = $full;
                    if (isOwn($full) !== true && in_array($full, ALLOWED_CLASSES, true) !== true) {
                        $add('outside-namespace', $token, 'use ' . $full);
                    }
                }
                continue 2;
        }
        if ($text === '`') {
            $add('eval', $token, 'a command in backticks');
            continue;
        }
        if ($text === '$') {
            $add('variable-by-name', $token, '$$ or ${...}');
            continue;
        }
        if (in_array($token->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true) !== true) {
            continue;
        }

        // ---- A name. What is it the name OF? ----
        $beforeId = $before->id ?? 0;
        if (in_array($beforeId, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true) === true) {
            continue; // a member of something already accounted for, or a name being declared
        }
        if ($inConstant === true && ($after->text ?? '') === '=') {
            continue; // a second constant declared in one statement
        }
        if (($tokens[$i - 2]->id ?? 0) === T_DECLARE) {
            continue; // declare(strict_types=1)
        }
        $afterText = $after->text ?? '';
        $isClass = in_array($beforeId, [T_NEW, T_INSTANCEOF, T_ATTRIBUTE, T_EXTENDS, T_IMPLEMENTS], true) === true
            || ($after->id ?? 0) === T_DOUBLE_COLON;
        if ($afterText === '(' && $isClass !== true) {
            // A call to a function.
            $function = strtolower(ltrim($text, '\\'));
            $called[$function] = true;
            if (in_array($function, ALLOWED_FUNCTIONS, true) !== true) {
                $add('function-not-allowed', $token, $text . '()');
            } elseif (in_array($function, TAKES_A_CALLBACK, true) === true && givenAClosure($tokens, $i + 1) !== true) {
                $add('callback-not-a-closure', $token, $text . '() is not given a closure written on the spot');
            }
            continue;
        }
        if (in_array(strtolower($text), TYPE_WORDS, true) === true) {
            continue;
        }
        if ($isClass !== true && $afterText === ':' && in_array($before->text ?? '', ['(', ','], true) === true) {
            continue; // the name of an argument in a call: f(name: value)
        }
        if ($isClass !== true && $token->id !== T_NAME_QUALIFIED && isset($builtIn[ltrim($text, '\\')]) === true) {
            continue; // a constant PHP itself defines
        }
        // A class: written in full, imported, or in the file's own namespace.
        if ($token->id === T_NAME_FULLY_QUALIFIED) {
            $full = substr($text, 1);
        } else {
            $first = (string) strtok($text, '\\');
            $rest = (string) substr($text, strlen($first));
            $full = isset($imports[$first]) === true ? $imports[$first] . $rest : ($namespace === '' ? $text : $namespace . '\\' . $text);
        }
        if (in_array($full, ALLOWED_CLASSES, true) === true) {
            continue;
        }
        if (isOwn($full) === true && is_file($library . '/src/' . str_replace('\\', '/', substr($full, strlen(OWN_NAMESPACE) + 1)) . '.php') === true) {
            continue;
        }
        $add($isClass === true || strpos($text, '\\') !== false ? 'class-not-allowed' : 'unknown-name', $token);
    }
    return $found;
}

/** True for a name inside the library's own namespace. */
function isOwn(string $full): bool
{
    return strncmp($full, OWN_NAMESPACE . '\\', strlen(OWN_NAMESPACE) + 1) === 0;
}

/**
 * The names a `use` statement brings in: short name => full name.
 * Handles `use A\B;`, `use A\B as C;`, `use A\{B, C};` and several separated by commas.
 * `use function` and `use const` are reported by giving them a name nothing allows.
 *
 * @param list<PhpToken> $statement The tokens between `use` and the `;`.
 *
 * @return array<string,string>
 */
function importsOf(array $statement): array
{
    $text = '';
    foreach ($statement as $token) {
        $text .= in_array($token->id, [T_AS, T_FUNCTION, T_CONST], true) === true ? ' ' . $token->text . ' ' : $token->text;
    }
    if (preg_match('/\A\s*(function|const)\s/', $text) === 1) {
        return ['(use function or const)' => trim($text)];
    }
    $prefix = '';
    if (preg_match('/\A(.*)\{(.*)\}\z/s', $text, $found) === 1) {
        [$prefix, $text] = [$found[1], $found[2]];
    }
    $imports = [];
    foreach (explode(',', $text) as $entry) {
        $parts = preg_split('/\s+as\s+/', trim($entry));
        $full = ltrim($prefix . trim((string) ($parts[0] ?? '')), '\\');
        if ($full === '') {
            continue;
        }
        $short = isset($parts[1]) === true ? trim($parts[1]) : (string) substr((string) strrchr('\\' . $full, '\\'), 1);
        $imports[$short] = $full;
    }
    return $imports;
}

/**
 * Is the call whose opening bracket is at `$open` given, as the FIRST thing
 * in its brackets, a closure written on the spot: `function`, `fn` (either
 * may have `static` before it), or an allowed function turned into a closure
 * with `name(...)`? A piece of text, a list or a variable there is not one.
 *
 * @param list<PhpToken> $tokens
 */
function givenAClosure(array $tokens, int $open): bool
{
    $first = $tokens[$open + 1] ?? null;
    if ($first !== null && $first->id === T_STATIC) {
        $first = $tokens[$open + 2] ?? null;
    }
    if ($first === null) {
        return false;
    }
    if ($first->id === T_FUNCTION || $first->id === T_FN) {
        return true;
    }
    return $first->id === T_STRING
        && in_array(strtolower($first->text), ALLOWED_FUNCTIONS, true) === true
        && ($tokens[$open + 2]->text ?? '') === '('
        && ($tokens[$open + 3]->id ?? 0) === T_ELLIPSIS
        && ($tokens[$open + 4]->text ?? '') === ')';
}
