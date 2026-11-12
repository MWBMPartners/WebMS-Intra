<?php
// Path: tools/settings-locks-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Settings with a page of their own cannot be DELETED from /settings either 🔒🗑️
 * -----------------------------------------------------------------------------
 * #836. The general settings editor (`/settings`) refuses to change or add a
 * setting that has a page of its own — the giving-records rule, how
 * organisations are told apart, the mail server, the recorded version, the
 * reserved capabilities, how long registrations and transcripts are kept, what
 * outside companies may be sent. Each such family has ONE method that says
 * which keys are its own: a `public static function …LockedInGeneralEditor()`
 * in `web/_core/`. The save handler asks every one of them when a row is
 * changed and when one is added, and (#845) the Settings page asks every one
 * when it draws a row.
 *
 * WHAT WAS WRONG. The DELETE (in `web/_apps/settings/index.php`) asked eight
 * of the nine and not `TranscriptRetention::lockedInGeneralEditor()`, so a
 * global administrator could delete `transcription.retentionDays` there: the
 * clear-out then fell back to its built-in period with nothing on the
 * transcription pages to say a figure had gone, and nothing recorded who
 * removed it. The giving-records refusal there was a COPY of the prefix test
 * rather than a call to its method. Both were the same shape as #502, where
 * the two "how organisations are told apart" rows were refused on change and
 * not on delete.
 *
 * THE ONE LIST is the set of those methods, read out of `web/_core/` by PHP's
 * tokeniser — never typed into this file, and WHICH keys are pressed in
 * section B is decided by that list alone. What IS typed by hand, and why:
 *   - `audit.retentionDays` (#932), for the premise check B0b — a lock method
 *     answering `false` for everything drops its key from the enumeration
 *     and leaves the run green, so the key is named here on purpose;
 *   - `transcription.retentionDays`, the issue's own key: the premise check B0
 *     (the enumeration must reach it) and the scenarios of plants P1 and P6;
 *   - the exact source text each plant removes — the delete handler's
 *     transcription and giving-records conditions and its row look-up line,
 *     both of the save handler's transcription conditions, and its UPDATE's
 *     "run, then count the rows written" lines — because a plant has to name
 *     the text it takes out, and refuses (applied: false) if that text moves;
 *   - `GivingRetention::lockedInGeneralEditor`, to pick a giving-records key
 *     out of the enumerated ones for plant P2;
 *   - the missing-call sentence P4 and P4b expect;
 *   - the three markers the paths are cut at (section S);
 *   - for the one row a method leaves open (B3), the swap `true`/`false` (or
 *     `1`/`0`): an unknown value there makes the run refuse.
 *
 * WHAT IT COVERS
 *   S  every lock method is ASKED on all four paths: changing a row and
 *      adding one (`save.php`), deleting one and drawing one (`index.php`,
 *      the drawing since #845). Comments AND
 *      string literals are removed first (PHP's tokeniser), so a call that is
 *      only mentioned in a comment, or only written as text in a string,
 *      does not count.
 *   B  every key ANY lock method locks is refused on all three paths, over
 *      real HTTP, as a GLOBAL administrator (the one person the editor would
 *      otherwise obey):
 *        B2 DELETE, as the installation's row and as an organisation's own;
 *        B3 CHANGE (Save on the existing row), at both levels — except the one
 *           row a method deliberately leaves open for a global administrator
 *           (the giving-records hold, installation-wide), which must be
 *           ACCEPTED, so the exception is checked rather than skipped;
 *        B4 ADD, with both of the key's rows taken away first, so that only
 *           the lock — not the "already exists" check — can stop it.
 *      Each refusal must leave the rows as they were — the same key, value and
 *      level (the secret mark and the default are not compared) (for ADD: create
 *      nothing) AND be recorded naming the key. Controls prove the same
 *      request shapes DO delete, change and add an ordinary setting, so a
 *      refusal is not just a request that never worked.
 *   D  #845: the Settings page itself, read as the global administrator,
 *      draws no Delete button on any locked row and no Edit button except on
 *      the one row a method leaves open, and puts a note naming where the
 *      setting IS changed in their place. (It used to draw both on the
 *      transcription period and the currency, which were then refused.) The
 *      drawing is also the fourth path section S reads.
 *   P  plants, in a COPY of the portal: the transcription refusal taken out of
 *      the delete; the giving refusal taken out of the delete; every delete
 *      refusal taken out at once; the transcription refusal taken out of BOTH
 *      save paths (change and add); and, in memory, for section S, the
 *      transcription call taken out of the delete and replaced by a string
 *      holding the call's text, and taken out of the DRAWING (P4c); and, for
 *      section D, the drawing's transcription ask switched off (P8) and the
 *      currency's note taken away (P9). Each must go red.
 *
 * HOW THE KEYS ARE ENUMERATED
 *   The candidates are every setting name in the database (a fresh install
 *   seeds nearly all of them), plus every string and every `self::` constant
 *   written inside a lock method's body (which reaches names a fresh install
 *   does not seed, such as the old `site.timezone`). A string ending in a
 *   full stop is a PREFIX, so `selftest836` is put after it to make a name
 *   inside that family. Each candidate is asked of every lock method; a key
 *   is locked when any method says so. A method that takes more than the key
 *   is the giving-records one, `(key, installation's row, global admin)`, and
 *   is asked `(key, false, true)` — what the ADD path asks for a global
 *   administrator, and the strictest answer — and, for CHANGE, `(key, is the
 *   row the installation's, true)`, which is what the save handler asks. A
 *   lock method with any other signature makes the run REFUSE, so a person
 *   decides what it means.
 *
 *   SHAPES THE ENUMERATION CANNOT SEE. A key a method builds by joining text
 *   (`'mail.' . $x`); a key held in ANOTHER class's constant, or reached
 *   through `static::`, `parent::` or a method call; a key with no full stop
 *   (skipped, because every setting name here has one); and a key that is in
 *   neither the database nor written whole in a method's body. Any of those
 *   is locked, and simply not pressed here.
 *
 * WHAT IT CANNOT DO, said plainly
 *   - It proves the three paths refuse; it does not judge the WORDS of each
 *     refusal, which belong to each family's own test.
 *   - Section S is a READING: it proves each method is called on each path.
 *     Section B is what proves the call is acted on, by pressing the buttons.
 *   - A key locked only by a rule the methods do not express (for example a
 *     prefix written with a different function) is found only if its name is
 *     in the database or written in the method's body.
 *   - Section D reads the Settings page as a GLOBAL administrator only. What
 *     an administrator of one organisation is shown for an installation-wide
 *     row (an Edit button the save handler then refuses, for a reason that is
 *     not a lock) is not judged here.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_locks SELFTEST_DB_PASS=… SELFTEST_DB_PORT=… \
 *         [SELFTEST_WEB_PORT=9836] php tools/settings-locks-selftest.php
 *
 *         The database must already hold `web/_sql/full_schema.sql`. Needs
 *         PHP's curl extension and `proc_open()`. It REFUSES rather than
 *         skipping when anything is missing, because a skipped test reads
 *         exactly like a passing one.
 *
 * Exit:   0 only when every check ran and passed AND the number of checks is
 *         the number worked out from what was enumerated; 1 otherwise; 2 when
 *         it refused to run.
 *
 * WHAT IT CHANGES, AND PUTS BACK. One made-up person, numbered 936801, with a
 * sign-in of their own; settings rows it adds (each removed by its own number,
 * except the controls' `selftest836.ordinary.*` rows, which are removed by name);
 * rows section B takes away for the ADD presses, and rows a planted fault lets
 * it delete, change or add — each put back with its own number, value and mark,
 * or removed. That putting back also runs when the run stops early (a refusal,
 * an exception), from a shutdown function. Its activity records name that one
 * person and are removed with them.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/836
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** The made-up global administrator. Nothing else in tools/ uses 9368xx. */
const SL_ADMIN = 936801;

$sep  = DIRECTORY_SEPARATOR;
$repo = dirname(__DIR__);

$passed = 0;
$failed = 0;
$state  = ['proc' => null, 'child' => null];

function refuse(string $why): never
{
    echo "REFUSED: {$why}.\n";
    echo "Nothing more was checked, and this run fails. A skipped test reads exactly\n";
    echo "like a passing one, which is why this refuses rather than skipping.\n";
    exit(2);
}

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok === true) {
        $passed += 1;
        echo "  ✓ {$name}\n";
        return;
    }
    $failed += 1;
    echo "  ✗ {$name}\n";
    if ($detail !== '') {
        echo "      {$detail}\n";
    }
}

function head(string $text): void
{
    echo "\n{$text}\n";
}

// ── What it needs ────────────────────────────────────────────────────────────
$dbName = (string) getenv('SELFTEST_DB_NAME');
if (preg_match('/^selftest_[A-Za-z0-9_]+$/', $dbName) !== 1) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes rows");
}
foreach (['curl_init', 'proc_open', 'token_get_all'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("this test needs {$needed}(), which this PHP does not have");
    }
}
$dbHost = getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1';
$dbUser = getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root';
$dbPass = getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '';
$dbPort = getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
} catch (Throwable $e) {
    refuse("could not reach {$dbName} on {$dbHost}:{$dbPort}");
}
$db->set_charset('utf8mb4');
$tables = (int) ($db->query(
    'SELECT COUNT(*) AS `n` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE()'
)->fetch_assoc()['n'] ?? 0);
if ($tables < 100) {
    refuse("the database holds only {$tables} tables — load web/_sql/full_schema.sql first");
}

/** @param list<mixed> $params @return list<array<string,mixed>> */
function rows(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $out    = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $out;
}

/** @param list<mixed> $params */
function run(mysqli $db, string $sql, string $types = '', array $params = []): int
{
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $n = $stmt->affected_rows;
    $stmt->close();

    return $n;
}

// ── The repository's own classes, for the lock methods and Site::preDetect() ─
spl_autoload_register(static function (string $class) use ($repo, $sep): void {
    if (str_starts_with($class, 'Portal\\Core\\') === false) {
        return;
    }
    $file = $repo . $sep . 'web' . $sep . '_core' . $sep . substr($class, strlen('Portal\\Core\\')) . '.php';
    if (is_file($file) === true) {
        require_once $file;
    }
});

/**
 * PHP code with comments, string literals and inline HTML removed, by PHP's own
 * tokeniser. A string becomes `''` so the code around it still reads as code.
 * Strings go too (round 1 of the check): a condition holding only the TEXT of a
 * call, `'…TranscriptRetention::lockedInGeneralEditor(' !== ''`, refuses
 * nothing and used to satisfy section S.
 */
function codeOnly(string $php): string
{
    $out = '';
    foreach (token_get_all($php) as $token) {
        if (is_array($token) === true) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT || $token[0] === T_INLINE_HTML) {
                $out .= ' ';
                continue;
            }
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $out .= "''";
                continue;
            }
            if ($token[0] === T_ENCAPSED_AND_WHITESPACE) {
                continue; // the text inside a "…{$x}…" string or a heredoc
            }
            $out .= $token[1];
        } else {
            $out .= $token;
        }
    }

    return $out;
}

// =============================================================================
// 🔎 THE ONE LIST: every lock method in web/_core, read by PHP's tokeniser
// =============================================================================
/**
 * Every `public static function …LockedInGeneralEditor()` declared in a file
 * directly inside web/_core. The class name is taken from the file's own
 * `class` declaration and confirmed by reflection, so a method in a class the
 * autoloader cannot reach makes the run refuse rather than be skipped.
 *
 * @return list<array{class: string, short: string, method: string, params: list<string>, body: string}>
 */
function lockMethods(string $coreDir): array
{
    $found = [];
    foreach (glob($coreDir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
        $tokens    = token_get_all((string) file_get_contents($file));
        $namespace = '';
        $class     = '';
        $count     = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (is_array($t) === false) {
                continue;
            }
            if ($t[0] === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < $count && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                    if (is_array($tokens[$j]) === true && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED], true) === true) {
                        $namespace .= $tokens[$j][1];
                    }
                }
                continue;
            }
            // A class declaration — not `Foo::class`, which is T_CLASS after T_DOUBLE_COLON.
            if ($t[0] === T_CLASS) {
                $prev = $i - 1;
                while ($prev >= 0 && is_array($tokens[$prev]) === true && $tokens[$prev][0] === T_WHITESPACE) {
                    $prev--;
                }
                if ($prev >= 0 && is_array($tokens[$prev]) === true && $tokens[$prev][0] === T_DOUBLE_COLON) {
                    continue;
                }
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) === true && $tokens[$j][0] === T_STRING) {
                        $class = $tokens[$j][1];
                        break;
                    }
                }
                continue;
            }
            if ($t[0] === T_FUNCTION && $class !== '') {
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) === true && $tokens[$j][0] === T_STRING) {
                        $name = $tokens[$j][1];
                        if (preg_match('/lockedInGeneralEditor$/i', $name) === 1) {
                            $fqcn = ($namespace === '' ? '' : $namespace . '\\') . $class;
                            if (class_exists($fqcn) === false) {
                                refuse("{$fqcn}::{$name}() is declared in " . basename($file) . ' and the class could not be loaded');
                            }
                            $ref = new ReflectionMethod($fqcn, $name);
                            if ($ref->isPublic() === true && $ref->isStatic() === true) {
                                $lines  = file($file) ?: [];
                                $body   = implode('', array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
                                $found[] = [
                                    'class'  => $fqcn,
                                    'short'  => $class,
                                    'method' => $name,
                                    'params' => array_map(static fn (ReflectionParameter $p): string => $p->getName(), $ref->getParameters()),
                                    'body'   => $body,
                                ];
                            }
                        }
                        break;
                    }
                    if ($tokens[$j] === '(') {
                        break; // an anonymous function
                    }
                }
            }
        }
    }

    return $found;
}

$methods = lockMethods($repo . $sep . 'web' . $sep . '_core');
if ($methods === []) {
    refuse('no …LockedInGeneralEditor() method was found in web/_core — the enumeration itself is broken');
}
foreach ($methods as $m) {
    if ($m['params'] !== ['key'] && $m['params'] !== ['key', 'portalWideRow', 'globalAdmin']) {
        refuse($m['class'] . '::' . $m['method'] . '(' . implode(', ', $m['params']) . ') takes arguments this test does not '
            . 'know how to ask; decide what it means for a delete, then teach this test');
    }
}

/** Is this key locked by any of the methods? The giving-records one is asked as the ADD path asks a global administrator. */
function lockedBy(array $methods, string $key): array
{
    $who = [];
    foreach ($methods as $m) {
        $callable = [$m['class'], $m['method']];
        $answer   = count($m['params']) === 1 ? $callable($key) : $callable($key, false, true);
        if ($answer === true) {
            $who[] = $m['short'] . '::' . $m['method'];
        }
    }

    return $who;
}

// =============================================================================
head('Section S — every lock method is asked on all four paths (read; comments and strings removed)');
// =============================================================================
$saveRaw  = (string) file_get_contents($repo . $sep . 'web' . $sep . '_apps' . $sep . 'settings' . $sep . 'save.php');
$indexRaw = (string) file_get_contents($repo . $sep . 'web' . $sep . '_apps' . $sep . 'settings' . $sep . 'index.php');

/**
 * The four paths, cut from the RAW files at markers that are comments (so they
 * must be cut before comments are removed), then reduced to code only.
 *
 * @return array<string, string> path name => its code, '' when a marker is missing
 */
function threePaths(string $saveRaw, string $indexRaw): array
{
    $cut = static function (string $text, string $from, string $to): string {
        $a = strpos($text, $from);
        if ($a === false) {
            return '';
        }
        $b = $to === '' ? strlen($text) : strpos($text, $to, $a + strlen($from));
        if ($b === false) {
            return '';
        }

        return codeOnly('<?php ' . substr($text, $a, $b - $a));
    };

    return [
        'changing a row (save.php)' => $cut($saveRaw, 'if ($settingId > 0) {', '// ➕ Insert new.'),
        'adding a row (save.php)'   => $cut($saveRaw, '// ➕ Insert new.', ''),
        'deleting a row (index.php)' => $cut($indexRaw, "(\$_POST['action'] ?? '') === 'delete'", '// 📋 Fetch settings list'),
        // #845: the page's own DRAWING of each row asks the same methods, so it
        // offers no Edit or Delete button the handlers would then refuse.
        'drawing a row (index.php)'  => $cut($indexRaw, '// 📋 Fetch settings list', ''),
    ];
}

/** @return list<string> "path: Class::method" for every method a path does not ask. */
function missingAsks(array $methods, array $paths): array
{
    $missing = [];
    foreach ($paths as $path => $code) {
        foreach ($methods as $m) {
            $pattern = '/(?<![A-Za-z0-9_\\\\])(?:\\\\?Portal\\\\Core\\\\)?' . preg_quote($m['short'], '/') . '::' . preg_quote($m['method'], '/') . '\s*\(/';
            if ($code === '' || preg_match($pattern, $code) !== 1) {
                $missing[] = $path . ': ' . $m['short'] . '::' . $m['method'] . '()';
            }
        }
    }

    return $missing;
}

$paths = threePaths($saveRaw, $indexRaw);
echo '  The lock methods found in web/_core (' . count($methods) . '): '
    . implode(', ', array_map(static fn (array $m): string => $m['short'] . '::' . $m['method'] . '()', $methods)) . "\n";
foreach ($paths as $path => $code) {
    check("S1 the path for {$path} was found", $code !== '', 'a marker this test cuts at has moved; nothing below can be trusted for that path');
}
$missing = missingAsks($methods, $paths);
check('S2 every lock method is asked on every one of the four paths', $missing === [], 'not asked: ' . implode('; ', $missing));

// =============================================================================
// 🏗️ The keys: every candidate asked of every method
// =============================================================================
$candidates = [];
foreach (rows($db, 'SELECT DISTINCT `settingKey` AS `k` FROM `tblSettings`') as $row) {
    $candidates[(string) $row['k']] = true;
}
foreach ($methods as $m) {
    $ref = new ReflectionClass($m['class']);
    foreach (token_get_all('<?php ' . $m['body']) as $i => $tok) {
        if (is_array($tok) === true && $tok[0] === T_CONSTANT_ENCAPSED_STRING) {
            $candidates[stripslashes(substr($tok[1], 1, -1))] = true;
        }
    }
    if (preg_match_all('/self::([A-Z][A-Z0-9_]*)\b/', $m['body'], $hits) > 0) {
        foreach ($hits[1] as $constName) {
            if ($ref->hasConstant($constName) === false) {
                continue;
            }
            $value = $ref->getConstant($constName);
            foreach (is_array($value) === true ? $value : [$value] as $one) {
                if (is_string($one) === true) {
                    $candidates[$one] = true;
                }
            }
        }
    }
}
$locked = [];
$seen   = [];
foreach (array_keys($candidates) as $name) {
    $name = (string) $name;
    // The database compares setting names ignoring capitals (utf8mb4_unicode_ci),
    // so `mail.smtp.certcheck` from a lower-cased list IS the stored
    // `mail.smtp.certCheck`. One spelling each, the first met — and the names
    // read out of the database are met first, so it is the stored spelling.
    if (isset($seen[strtolower($name)]) === true) {
        continue;
    }
    $seen[strtolower($name)] = true;
    // A string ending in a full stop is a prefix: make a name inside its family.
    if (str_ends_with($name, '.') === true) {
        $name .= 'selftest836';
    }
    // Only names the editor itself would accept are worth asking: a setting name.
    if (preg_match('/^[A-Za-z0-9._-]+\z/', $name) !== 1 || str_contains($name, '.') === false) {
        continue;
    }
    $who = lockedBy($methods, $name);
    if ($who !== []) {
        $locked[$name] = $who;
    }
}
ksort($locked);
echo '  ' . count($candidates) . ' candidate names; ' . count($locked) . ' locked: ' . implode(', ', array_keys($locked)) . "\n";

// =============================================================================
// 🏗️ A copy of web/, so a planted fault never touches the repository
// =============================================================================
$child = sys_get_temp_dir() . $sep . 'settings-locks-' . bin2hex(random_bytes(6));
$state['child'] = $child;
register_shutdown_function(static function () use (&$state): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
    }
    if ($state['child'] !== null && is_dir($state['child']) === true && is_file($state['child'] . DIRECTORY_SEPARATOR . 'settings-locks.marker') === true) {
        // A recursive delete of a folder THIS run made (it still holds this run's marker).
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($state['child'], FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $entry) {
            $entry->isDir() === true ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($state['child']);
    }
});
mkdir($child, 0700, true);
file_put_contents($child . $sep . 'settings-locks.marker', 'made by tools/settings-locks-selftest.php');
$copied = 0;
$walk = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($repo . $sep . 'web', FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($walk as $entry) {
    $relative = substr($entry->getPathname(), strlen($repo . $sep . 'web'));
    if (str_starts_with($relative, $sep . '_auth_keys') === true) {
        continue; // never read or copied: this run writes its own
    }
    $to = $child . $sep . 'web' . $relative;
    if ($entry->isDir() === true) {
        @mkdir($to, 0700, true);
        continue;
    }
    copy($entry->getPathname(), $to);
    $copied++;
}
if ($copied < 100) {
    refuse("the copy of web/ holds only {$copied} files");
}
@mkdir($child . $sep . 'web' . $sep . '_auth_keys', 0700);
mkdir($child . $sep . 'sessions', 0700);
file_put_contents(
    $child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php',
    "<?php\n// Written by tools/settings-locks-selftest.php for ONE run.\nreturn "
    . var_export(['db_host' => $dbHost, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $dbPort], true)
    . ";\n"
);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// The router imitates web/public_html/.htaccess, as the other page tests do.
file_put_contents($child . $sep . 'router.php', <<<'ROUTER'
<?php
// Written by tools/settings-locks-selftest.php for ONE run. Test only.
declare(strict_types=1);
$root = (string) realpath(__DIR__ . '/web/public_html');
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#(^|/)\.(?!well-known)#', $uri) === 1) { http_response_code(403); exit('403'); }
if (preg_match('#\.(php\d?|phtml|phar)$#i', $uri) === 1 && in_array($uri, ['/index.php', '/api-docs/index.php', '/error.php'], true) === false) { http_response_code(404); exit('404'); }
$target = $root . '/index.php';
$real   = ($uri === '/' || preg_match('#^/assets/?$#', $uri) === 1) ? false : realpath($root . $uri);
if ($real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR) === true && is_file($real) === true) {
    if (strtolower(pathinfo($real, PATHINFO_EXTENSION)) === 'php') { $target = $real; } else { readfile($real); return true; }
}
$_SERVER['SCRIPT_NAME']     = substr($target, strlen($root));
$_SERVER['PHP_SELF']        = $_SERVER['SCRIPT_NAME'];
$_SERVER['SCRIPT_FILENAME'] = $target;
chdir(dirname($target));
require $target;
return true;
ROUTER);

$port = getenv('SELFTEST_WEB_PORT') !== false ? (int) getenv('SELFTEST_WEB_PORT') : 9836;
if ($port < 1024 || $port > 65535) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65535');
}
$probe = @fsockopen('127.0.0.1', $port, $errNo, $errText, 0.5);
if (is_resource($probe) === true) {
    fclose($probe);
    refuse("something already answers on port {$port}; choose another with SELFTEST_WEB_PORT");
}
$jar = $child . $sep . 'cookies.txt';

$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';
$state['proc'] = proc_open(
    [
        PHP_BINARY,
        '-d', 'session.save_path=' . $child . $sep . 'sessions',
        '-d', 'error_log=' . $child . $sep . 'php-errors.log',
        '-d', 'log_errors=1', '-d', 'display_errors=0',
        '-d', 'max_execution_time=120',
        // No opcache, so a planted file is read afresh by the very next request.
        '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
        '-S', '127.0.0.1:' . $port,
        '-t', $child . $sep . 'web' . $sep . 'public_html',
        $child . $sep . 'router.php',
    ],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server.log', 'a'], 2 => ['file', $child . $sep . 'server.log', 'a']],
    $pipes,
    $child,
    $env
);
if (is_resource($state['proc']) === false) {
    refuse('could not start PHP\'s web server');
}

/** One request: status, Location and body. Redirects are NOT followed. */
$http = static function (string $method, string $path, array $post = []) use ($port, $jar): array {
    $handle = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
    ]);
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = curl_exec($handle);
    if ($raw === false) {
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle)];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', substr((string) $raw, 0, $headSize), $mm) === 1 ? $mm[1] : '',
        'body'     => substr((string) $raw, $headSize),
    ];
};
$tokenOn = static function (string $html): string {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $mm) === 1) {
        return html_entity_decode($mm[1], ENT_QUOTES, 'UTF-8');
    }
    if (preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/i', $html, $mm) === 1) {
        return html_entity_decode($mm[1], ENT_QUOTES, 'UTF-8');
    }
    return '';
};
$answered = false;
for ($i = 0; $i < 80; $i++) {
    if ($http('GET', '/login')['status'] > 0) {
        $answered = true;
        break;
    }
    usleep(250000);
}
if ($answered === false) {
    refuse('the test server never answered');
}

// ── The organisation the portal serves, asked of the portal's own rule (#842) ─
$site = \Portal\Core\Site::preDetect($db);
if (\Portal\Core\Site::detectionFailed() === true) {
    refuse('the portal could not say which organisation it serves (Site::preDetect() failed)');
}
if (rows($db, 'SELECT `siteID` FROM `tblSites` WHERE `siteID` = ? AND `isActive` = 1', 'i', [$site]) === []) {
    refuse("the portal serves organisation {$site}, and the database has no such organisation switched on");
}

// ── The person ──────────────────────────────────────────────────────────────
/** Remove the made-up person and everything naming them, children first. */
$removePerson = static function () use ($db): void {
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach (rows($db, "SELECT c.`TABLE_NAME` AS `t` FROM `information_schema`.`COLUMNS` c JOIN `information_schema`.`TABLES` t "
            . "ON t.`TABLE_SCHEMA` = c.`TABLE_SCHEMA` AND t.`TABLE_NAME` = c.`TABLE_NAME` AND t.`TABLE_TYPE` = 'BASE TABLE' "
            . "WHERE c.`TABLE_SCHEMA` = DATABASE() AND c.`COLUMN_NAME` = 'userID'") as $row) {
            run($db, 'DELETE FROM `' . $row['t'] . '` WHERE `userID` = ?', 'i', [SL_ADMIN]);
        }
    } finally {
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }
};
$added    = []; // settingIDs this run added, removed at the end
$snapshot = []; // every row section B points at, as it was, so a press or a plant can be undone

/**
 * Put every snapshot row back exactly: any OTHER row now holding the same name
 * at the same level is removed first (a planted fault's ADD makes one), then
 * the snapshot row is written back with its own number, value and mark.
 */
$restore = static function () use ($db, &$snapshot): void {
    foreach ($snapshot as $row) {
        if ($row['siteID'] === null) {
            run($db, 'DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL AND `settingID` <> ?', 'si', [$row['settingKey'], (int) $row['settingID']]);
        } else {
            run($db, 'DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` = ? AND `settingID` <> ?', 'sii', [$row['settingKey'], (int) $row['siteID'], (int) $row['settingID']]);
        }
        run($db, 'DELETE FROM `tblSettings` WHERE `settingID` = ?', 'i', [(int) $row['settingID']]);
        run($db, 'INSERT INTO `tblSettings` (`settingID`, `siteID`, `settingKey`, `settingValue`, `updatedAt`, `isSensitive`, `defaultValue`) VALUES (?, ?, ?, ?, ?, ?, ?)',
            'iisssis', [(int) $row['settingID'], $row['siteID'], $row['settingKey'], $row['settingValue'], $row['updatedAt'], (int) $row['isSensitive'], $row['defaultValue']]);
    }
};

/**
 * Everything this run made goes, and everything it touched is put back. Also
 * registered as a shutdown function, because `refuse()` exits — and an exit
 * part-way through a press (rows taken away for an ADD) or a plant would
 * otherwise leave the database changed. Safe to run twice.
 */
$cleanedUp = false;
$cleanUp = static function () use (&$cleanedUp, $restore, &$added, $db, $removePerson): void {
    if ($cleanedUp === true) {
        return;
    }
    $cleanedUp = true;
    $restore();
    foreach ($added as $id) {
        run($db, 'DELETE FROM `tblSettings` WHERE `settingID` = ?', 'i', [$id]);
    }
    run($db, "DELETE FROM `tblSettings` WHERE `settingKey` LIKE 'selftest836.ordinary.%'");
    $removePerson();
    run($db, 'DELETE FROM `tblLocalAccounts` WHERE `username` = ?', 's', ['selftest-settings-locks']);
};
register_shutdown_function(static function () use ($cleanUp): void {
    try {
        $cleanUp();
    } catch (Throwable $e) {
        echo "  ! the clean-up could not finish ({$e->getMessage()}); rows numbered for this run may be left.\n";
    }
});

$removePerson();
$password = 'Sl' . bin2hex(random_bytes(8)) . '!9x';
run($db, 'INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`, `createdAt`) VALUES (?, ?, ?, 1, 1, 1, NOW())',
    'iss', [SL_ADMIN, 'Selftest settings locks', 'settings-locks@selftest.invalid']);
run($db, 'INSERT INTO `tblUserSites` (`siteID`, `userID`, `isActive`) VALUES (?, ?, 1)', 'ii', [$site, SL_ADMIN]);
run($db, 'DELETE FROM `tblLocalAccounts` WHERE `username` = ?', 's', ['selftest-settings-locks']);
run($db, 'INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)',
    'iss', [SL_ADMIN, 'selftest-settings-locks', password_hash($password, PASSWORD_DEFAULT)]);

$signIn = static function () use ($http, $tokenOn, $jar, $password): void {
    @unlink($jar);
    $token = $tokenOn($http('GET', '/login')['body']);
    if ($token === '') {
        refuse('the sign-in page carried no form token');
    }
    $in = $http('POST', '/login', ['csrf_token' => $token, 'username' => 'selftest-settings-locks', 'password' => $password]);
    if ($in['status'] !== 302 && $in['status'] !== 303) {
        refuse("signing in did not answer a redirect (HTTP {$in['status']})");
    }
};
$signIn();

// ── Rows ────────────────────────────────────────────────────────────────────
/** The row for (key, level), or null. Level null = the installation's. */
$rowOf = static function (string $key, ?int $level) use ($db): ?array {
    $found = $level === null
        ? rows($db, 'SELECT * FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL', 's', [$key])
        : rows($db, 'SELECT * FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` = ?', 'si', [$key, $level]);

    return $found[0] ?? null;
};
foreach (array_keys($locked) as $key) {
    $key = (string) $key;
    $installation = $rowOf($key, null);
    if ($installation === null) {
        run($db, "INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, ?, 'selftest836', '', 0)", 's', [$key]);
        $added[] = (int) $db->insert_id;
        $installation = $rowOf($key, null);
    }
    if ($rowOf($key, $site) === null) {
        // The installation's own value, so what the portal reads does not change.
        run($db, "INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, ?, ?, '', ?)",
            'issi', [$site, $key, $installation['settingValue'], (int) $installation['isSensitive']]);
        $added[] = (int) $db->insert_id;
    }
    $snapshot[] = $rowOf($key, null);
    $snapshot[] = $rowOf($key, $site);
}

/** Would the save handler refuse to CHANGE this row? Asked of the methods exactly as the handler asks them. */
$changeLocked = static function (array $row) use ($methods): bool {
    foreach ($methods as $m) {
        $callable = [$m['class'], $m['method']];
        $answer   = count($m['params']) === 1 ? $callable((string) $row['settingKey']) : $callable((string) $row['settingKey'], $row['siteID'] === null, true);
        if ($answer === true) {
            return true;
        }
    }

    return false;
};

/** The newest activity record number, so "was a refusal recorded" is asked of this request only. */
$lastLog = static function () use ($db): int {
    return (int) (rows($db, 'SELECT COALESCE(MAX(`logID`), 0) AS `n` FROM `tblActivityLogs`')[0]['n'] ?? 0);
};
/** Was a refusal of one of these types, naming this key, recorded since $since? */
$refusalNamed = static function (int $since, array $types, string $key) use ($db): bool {
    foreach (rows($db, 'SELECT `activityType` AS `t`, `activityDescription` AS `d` FROM `tblActivityLogs` WHERE `logID` > ? AND `userID` = ?', 'ii', [$since, SL_ADMIN]) as $log) {
        if (in_array((string) $log['t'], $types, true) === true && str_contains((string) $log['d'], '"' . $key . '"') === true) {
            return true;
        }
    }

    return false;
};
/** A fresh form token: the editor replaces it on every accepted post. */
$freshToken = static function () use ($http, $tokenOn): string {
    $token = $tokenOn($http('GET', '/settings')['body']);
    if ($token === '') {
        refuse('the Settings page carried no form token');
    }

    return $token;
};
/** The row with this number now, or null. */
$rowNow = static function (int $id) use ($db): ?array {
    return rows($db, 'SELECT `settingKey`, `settingValue`, `siteID` FROM `tblSettings` WHERE `settingID` = ?', 'i', [$id])[0] ?? null;
};
$sameAs = static fn (?array $now, array $row): bool => $now !== null && $now['settingKey'] === $row['settingKey']
    && $now['settingValue'] === $row['settingValue'] && $now['siteID'] === $row['siteID'];

/**
 * Press Delete on one row as the global administrator.
 *
 * @return array{gone: bool, unchanged: bool, logged: bool, status: int}
 */
$pressDelete = static function (array $row) use ($http, $freshToken, $lastLog, $refusalNamed, $rowNow, $sameAs): array {
    $token  = $freshToken();
    $since  = $lastLog();
    $answer = $http('POST', '/settings', ['csrf_token' => $token, 'action' => 'delete', 'settingID' => (string) $row['settingID']]);
    $now    = $rowNow((int) $row['settingID']);

    return [
        'gone'      => $now === null,
        'unchanged' => $sameAs($now, $row),
        'logged'    => $refusalNamed($since, ['SettingsDeleteRefused'], (string) $row['settingKey']),
        'status'    => $answer['status'],
    ];
};

/**
 * Press Save on one existing row (the edit box), as the global administrator.
 *
 * @return array{unchanged: bool, changedTo: bool, logged: bool, status: int}
 */
$pressChange = static function (array $row, string $value) use ($http, $freshToken, $lastLog, $refusalNamed, $rowNow, $sameAs): array {
    $token  = $freshToken();
    $since  = $lastLog();
    $answer = $http('POST', '/settings/save', ['csrf_token' => $token, 'settingID' => (string) $row['settingID'],
        'settingKey' => (string) $row['settingKey'], 'settingValue' => $value]);
    $now    = $rowNow((int) $row['settingID']);

    return [
        'unchanged' => $sameAs($now, $row),
        'changedTo' => $now !== null && $now['settingValue'] === $value,
        'logged'    => $refusalNamed($since, ['SettingsUpdateRefused'], (string) $row['settingKey']),
        'status'    => $answer['status'],
    ];
};

/**
 * Press Add for a key, as the global administrator, with BOTH of the key's
 * rows taken away first — the installation's row too, because the add path's
 * "already exists" check counts it, and would refuse the add whether or not the
 * lock were there. Only the lock can stop it now. The rows are put back after.
 * (The add path records its lock refusals under two types, for historical
 * reasons, so either counts — what matters is that one NAMES the key.)
 *
 * @return array{created: bool, logged: bool, status: int}
 */
$pressAdd = static function (string $key) use ($http, $freshToken, $lastLog, $refusalNamed, $db, $restore, $site): array {
    $token = $freshToken();
    // Only the two rows the snapshot holds (the installation's and this
    // organisation's), never another organisation's row of the same name,
    // which nothing here would put back.
    run($db, 'DELETE FROM `tblSettings` WHERE `settingKey` = ? AND (`siteID` IS NULL OR `siteID` = ?)', 'si', [$key, $site]);
    $since  = $lastLog();
    $answer = $http('POST', '/settings/save', ['csrf_token' => $token, 'settingID' => '0', 'settingKey' => $key, 'settingValue' => 'selftest836-added']);
    $made   = rows($db, 'SELECT `settingID` FROM `tblSettings` WHERE `settingKey` = ? AND (`siteID` IS NULL OR `siteID` = ?)', 'si', [$key, $site]) !== [];
    $logged = $refusalNamed($since, ['SettingsInsertRefused', 'SettingsUpdateRefused'], $key);
    $restore();

    return ['created' => $made, 'logged' => $logged, 'status' => $answer['status']];
};

/**
 * Press Save on a row the methods leave OPEN, with the other of its two
 * values, then follow the redirect to read what the page tells the person.
 *
 * @return array{stored: bool, logged: bool, status: int, said: bool}
 */
$openRows  = [];
$pressOpen = static function (array $row) use ($http, $freshToken, $lastLog, $refusalNamed, $rowNow): array {
    $other = ['true' => 'false', 'false' => 'true', '1' => '0', '0' => '1'][(string) $row['settingValue']] ?? null;
    if ($other === null) {
        refuse('"' . $row['settingKey'] . '" is left open by its method and holds "' . $row['settingValue']
            . '"; this test knows the other value only for true/false and 1/0');
    }
    $token  = $freshToken();
    $since  = $lastLog();
    $answer = $http('POST', '/settings/save', ['csrf_token' => $token, 'settingID' => (string) $row['settingID'],
        'settingKey' => (string) $row['settingKey'], 'settingValue' => $other]);
    $now    = $rowNow((int) $row['settingID']);
    $page   = html_entity_decode($http('GET', '/settings')['body'], ENT_QUOTES, 'UTF-8');

    return [
        'stored' => $now !== null && $now['settingValue'] === $other,
        'logged' => $refusalNamed($since, ['SettingsUpdateRefused'], (string) $row['settingKey']),
        'status' => $answer['status'],
        'said'   => str_contains($page, 'Setting "' . $row['settingKey'] . '" updated.'),
    ];
};

/** Every locked row pressed Delete once. @return array{refused: int, of: int, wrong: list<string>} */
$sweep = static function () use (&$snapshot, $pressDelete, $site): array {
    $refused = 0;
    $wrong   = [];
    foreach ($snapshot as $row) {
        $result = $pressDelete($row);
        $level  = $row['siteID'] === null ? 'installation' : 'organisation ' . $site;
        if ($result['unchanged'] === true && $result['logged'] === true) {
            $refused++;
        } else {
            $wrong[] = $row['settingKey'] . ' (' . $level . '): ' . ($result['gone'] === true ? 'DELETED' : 'changed') . ($result['logged'] === true ? '' : ', no refusal recorded');
        }
    }

    return ['refused' => $refused, 'of' => count($snapshot), 'wrong' => $wrong];
};

// =============================================================================
head('Section B — every locked key is refused on DELETE, CHANGE and ADD, as a global administrator');
// =============================================================================
check('B0 PREMISE: the issue\'s own key is among those the methods lock (so the enumeration reaches it)',
    isset($locked['transcription.retentionDays']) === true, 'locked: ' . implode(', ', array_keys($locked)));
// 📜 #932: the same premise for how long the ACTIVITY LOG is kept, BY NAME and
//    by its own method. The enumeration only tests what a method says it locks,
//    so a method answering `false` for everything simply drops its key and the
//    run stays green with five fewer checks (measured: 194 -> 189). The harm is
//    concrete: a global administrator deletes the installation's row here,
//    every organisation that chose "never delete" falls back to a year, and the
//    next clear-out deletes their records.
check('B0b PREMISE (#932): audit.retentionDays is locked, by ActivityLogRetention::lockedInGeneralEditor()',
    in_array('ActivityLogRetention::lockedInGeneralEditor', $locked['audit.retentionDays'] ?? [], true) === true,
    'locked by: ' . json_encode($locked['audit.retentionDays'] ?? null));

// The controls: the very same request shapes DO delete, change and add an ordinary setting.
foreach ([null, $site] as $level) {
    $key = 'selftest836.ordinary.' . ($level === null ? 'installation' : 'organisation');
    run($db, 'DELETE FROM `tblSettings` WHERE `settingKey` = ?', 's', [$key]);
    run($db, "INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, ?, 'x', '', 0)", 'is', [$level, $key]);
    $ordinary = $rowOf($key, $level);
    $changed  = $pressChange($ordinary, 'changed');
    check('B1a PREMISE: the same Save DOES change an ordinary ' . ($level === null ? 'installation-wide' : 'organisation') . ' row',
        $changed['changedTo'] === true && $changed['logged'] === false, 'HTTP ' . $changed['status']);
    $result = $pressDelete($ordinary);
    check('B1b PREMISE: the same Delete DOES remove an ordinary ' . ($level === null ? 'installation-wide' : 'organisation') . ' row',
        $result['gone'] === true && $result['logged'] === false, 'HTTP ' . $result['status'] . ($result['gone'] === true ? '' : ', the row is still there'));
}
$addedOrdinary = $pressAdd('selftest836.ordinary.added');
check('B1c PREMISE: the same Add DOES create an ordinary setting', $addedOrdinary['created'] === true && $addedOrdinary['logged'] === false,
    'HTTP ' . $addedOrdinary['status']);
run($db, "DELETE FROM `tblSettings` WHERE `settingKey` = 'selftest836.ordinary.added'");

foreach ($snapshot as $row) {
    $result = $pressDelete($row);
    $level  = $row['siteID'] === null ? 'the installation\'s row' : 'organisation ' . $site . '\'s own row';
    check(
        'B2 DELETE "' . $row['settingKey'] . '", ' . $level . ' (' . implode(', ', $locked[$row['settingKey']]) . '): refused, unchanged, and recorded',
        $result['unchanged'] === true && $result['logged'] === true,
        ($result['gone'] === true ? 'the row was DELETED' : ($result['unchanged'] === true ? 'the row is unchanged' : 'the row CHANGED'))
            . ($result['logged'] === true ? '' : '; no SettingsDeleteRefused naming it was recorded')
    );
}
$restore();

foreach ($snapshot as $row) {
    $level = $row['siteID'] === null ? 'the installation\'s row' : 'organisation ' . $site . '\'s own row';
    if ($changeLocked($row) === false) {
        // The one deliberate exception (a global administrator may change the
        // installation-wide giving-records hold, which has no page of its own,
        // so this editor is the ONLY way to switch it). Round 2 of the check:
        // pressing the value it already held proved nothing, because a save
        // silently thrown away also leaves it as it was. So the OTHER value is
        // pressed and must really be STORED; the restore puts it back.
        $openRows[] = $row;
        $result     = $pressOpen($row);
        $restore();
        check('B3 CHANGE "' . $row['settingKey'] . '", ' . $level . ': left open on purpose by its own method — the other value is STORED, HTTP 302, and the page says so',
            $result['stored'] === true && $result['logged'] === false && $result['status'] === 302 && $result['said'] === true,
            'stored: ' . var_export($result['stored'], true) . ', HTTP ' . $result['status'] . ', said "updated": ' . var_export($result['said'], true)
            . ($result['logged'] === true ? ', and it was REFUSED' : ''));
        continue;
    }
    $result = $pressChange($row, 'selftest836-changed');
    check(
        'B3 CHANGE "' . $row['settingKey'] . '", ' . $level . ': refused, unchanged, and recorded',
        $result['unchanged'] === true && $result['logged'] === true,
        ($result['unchanged'] === true ? 'the row is unchanged' : 'the row CHANGED') . ($result['logged'] === true ? '' : '; no SettingsUpdateRefused naming it was recorded')
    );
}
$restore();

foreach (array_keys($locked) as $key) {
    $result = $pressAdd((string) $key);
    check('B4 ADD "' . $key . '" (with both its rows taken away first): refused, nothing created, and recorded',
        $result['created'] === false && $result['logged'] === true,
        ($result['created'] === true ? 'a row was CREATED' : 'nothing created') . ($result['logged'] === true ? '' : '; no refusal naming it was recorded'));
}

// =============================================================================
head('Section D — #845: the Settings page draws no Edit or Delete button for a locked row, and names where it is set');
// =============================================================================
/**
 * Read /settings as the global administrator and say, for each row drawn, whether
 * it carries an Edit button, a Delete form, and the note naming the page where
 * the setting is changed (its text, tags removed; null when there is no note).
 * Cut at the Edit modal first, so the last row's block holds nothing after it.
 *
 * @return array{status: int, whole: bool, rows: array<int, array{edit: bool, delete: bool, note: ?string}>}
 */
$drawn = static function () use ($http): array {
    $answer = $http('GET', '/settings');
    $body   = $answer['body'];
    $end    = strpos($body, '<!-- 📝 Edit Modal -->');
    $list   = $end === false ? $body : substr($body, 0, $end);
    $rows   = [];
    foreach (array_slice(explode('<div class="portal-data-row portal-setting-row"', $list), 1) as $block) {
        if (preg_match('/^\s*data-setting-id="(\d+)"/', $block, $m) !== 1) {
            continue;
        }
        $note = null;
        if (preg_match('/<span class="d-inline-block" data-locked-note="1">(.*?)<\/span>\s*(?:<form|<\/div>)/s', $block, $n) === 1) {
            $note = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($n[1]), ENT_QUOTES, 'UTF-8')) ?? '');
        }
        $rows[(int) $m[1]] = [
            'edit'   => str_contains($block, 'data-bs-target="#editModal"'),
            'delete' => str_contains($block, 'name="action" value="delete"'),
            'note'   => $note,
        ];
    }

    return ['status' => $answer['status'], 'whole' => str_contains($body, '</html>'), 'rows' => $rows];
};
/** Every snapshot row judged against what was drawn. @return list<string> one line per row drawn wrongly */
$drawnWrong = static function (array $page) use (&$snapshot, $changeLocked, $site): array {
    $wrong = [];
    foreach ($snapshot as $row) {
        $id    = (int) $row['settingID'];
        $level = $row['siteID'] === null ? 'installation' : 'organisation ' . $site;
        $seen  = $page['rows'][$id] ?? null;
        if ($seen === null) {
            $wrong[] = $row['settingKey'] . " ({$level}): not drawn at all";
            continue;
        }
        if ($seen['delete'] === true) {
            $wrong[] = $row['settingKey'] . " ({$level}): a DELETE button is drawn";
        }
        if ($changeLocked($row) === false) {
            // The one row a method leaves open to a global administrator: Edit IS offered.
            if ($seen['edit'] === false) {
                $wrong[] = $row['settingKey'] . " ({$level}): left open by its method, yet no Edit button";
            }
            continue;
        }
        if ($seen['edit'] === true) {
            $wrong[] = $row['settingKey'] . " ({$level}): an EDIT button is drawn";
        }
        if ($seen['note'] === null || $seen['note'] === '') {
            $wrong[] = $row['settingKey'] . " ({$level}): no note naming where it is changed";
        }
    }

    return $wrong;
};
// The control: an ordinary row of this organisation's DOES draw both buttons, and no note.
run($db, "DELETE FROM `tblSettings` WHERE `settingKey` = 'selftest836.ordinary.drawn'");
run($db, "INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, 'selftest836.ordinary.drawn', 'x', '', 0)", 'i', [$site]);
$controlId = (int) $db->insert_id;
$page      = $drawn();
$control   = $page['rows'][$controlId] ?? null;
check('D0 PREMISE: the page is drawn whole (HTTP 200, </html>), and an ordinary row DOES carry an Edit button and a Delete button, and no note',
    $page['status'] === 200 && $page['whole'] === true && $control !== null && $control['edit'] === true && $control['delete'] === true && $control['note'] === null,
    'HTTP ' . $page['status'] . ', control row: ' . json_encode($control));
$wrongDrawn = $drawnWrong($page);
check('D1 every one of the ' . count($snapshot) . ' locked rows draws no Delete button, no Edit button (except the one row its method leaves open), and a note naming where it is changed',
    $wrongDrawn === [], implode('; ', $wrongDrawn));
check('D2 PREMISE (#845\'s own key): transcription.retentionDays and the currency are among the rows judged',
    isset($locked['transcription.retentionDays']) === true && array_filter(array_keys($locked), static fn ($k): bool => \Portal\Core\Currency::lockedInGeneralEditor((string) $k)) !== [],
    'locked: ' . implode(', ', array_keys($locked)));
run($db, 'DELETE FROM `tblSettings` WHERE `settingID` = ?', 'i', [$controlId]);

// =============================================================================
head('Section P — the plants: each refusal taken out of a COPY must turn this red');
// =============================================================================
$copyOf = static fn (string $name): string => $child . $sep . 'web' . $sep . '_apps' . $sep . 'settings' . $sep . $name;
/**
 * Plant replacements in one file of the copy, run, put the file and the rows
 * back. Every `find` must occur exactly once, or nothing is planted and the
 * result says so — a plant that did not apply proves nothing.
 *
 * @param array<string, string> $swaps find => replace
 * @return array{applied: bool, result: mixed}
 */
$plant = static function (string $file, array $swaps, callable $scenario) use ($restore): array {
    $original = (string) file_get_contents($file);
    $planted  = $original;
    foreach ($swaps as $find => $replace) {
        if (substr_count($planted, $find) !== 1) {
            return ['applied' => false, 'result' => null];
        }
        $planted = str_replace($find, $replace, $planted);
    }
    file_put_contents($file, $planted);
    try {
        $result = $scenario();
    } finally {
        file_put_contents($file, $original);
        $restore();
    }

    return ['applied' => true, 'result' => $result];
};
$installationRow = static function (string $wanted) use (&$snapshot): ?array {
    foreach ($snapshot as $row) {
        if ($row['siteID'] === null && $row['settingKey'] === $wanted) {
            return $row;
        }
    }

    return null;
};
$givingKey = '';
foreach (array_keys($locked) as $key) {
    if (in_array('GivingRetention::lockedInGeneralEditor', $locked[$key], true) === true) {
        $givingKey = (string) $key;
        break;
    }
}

$p1 = $plant($copyOf('index.php'),
    ["if (\$lockedKey !== null && \\Portal\\Core\\TranscriptRetention::lockedInGeneralEditor((string) \$lockedKey['settingKey']) === true) {" => 'if (false) {'],
    static fn (): array => $pressDelete($installationRow('transcription.retentionDays'))
);
check('P1 with the transcription refusal taken out of the delete, the plant applied AND transcription.retentionDays is DELETED',
    $p1['applied'] === true && ($p1['result']['gone'] ?? false) === true, 'applied: ' . var_export($p1['applied'], true));

$p2 = $plant($copyOf('index.php'),
    ["if (\$lockedKey !== null && GivingRetention::lockedInGeneralEditor((string) \$lockedKey['settingKey'], false, \$isRoot) === true) {" => 'if (false) {'],
    static fn (): array => $pressDelete($installationRow($givingKey) ?? [])
);
check('P2 with the giving-records refusal taken out of the delete, the plant applied AND "' . $givingKey . '" is DELETED',
    $givingKey !== '' && $p2['applied'] === true && ($p2['result']['gone'] ?? false) === true, 'applied: ' . var_export($p2['applied'], true));

// Every delete refusal at once: the row the refusals look at is never read.
$p3 = $plant($copyOf('index.php'), ['$lockedKey = $lookup->get_result()->fetch_assoc();' => '$lockedKey = null;'], $sweep);
check('P3 with every lock refusal taken out of the delete, the plant applied AND every one of the '
    . ($p3['result']['of'] ?? 0) . ' DELETE presses goes red',
    $p3['applied'] === true && ($p3['result']['refused'] ?? -1) === 0 && ($p3['result']['of'] ?? 0) === count($snapshot),
    'still refused: ' . ($p3['result']['refused'] ?? 'n/a'));

// Round 1 of the check: with BOTH save-path refusals of transcription.retentionDays
// planted out, every other test in tools/ stayed green, because nothing else
// posts that key to the editor. Here it must go red on change AND on add.
$p6 = $plant($copyOf('save.php'), [
    'if (\\Portal\\Core\\TranscriptRetention::lockedInGeneralEditor($storedKey) === true) {'  => 'if (false) {',
    'if (\\Portal\\Core\\TranscriptRetention::lockedInGeneralEditor($settingKey) === true) {' => 'if (false) {',
], static function () use ($pressChange, $pressAdd, $installationRow): array {
    return [
        'change' => $pressChange($installationRow('transcription.retentionDays'), 'selftest836-changed'),
        'add'    => $pressAdd('transcription.retentionDays'),
    ];
});
check('P6a with the transcription refusal taken out of BOTH save paths, the plant applied AND a CHANGE goes through',
    $p6['applied'] === true && ($p6['result']['change']['changedTo'] ?? false) === true, 'applied: ' . var_export($p6['applied'], true));
check('P6b ...AND an ADD goes through', $p6['applied'] === true && ($p6['result']['add']['created'] ?? false) === true);

// Round 2 of the check: a save that is silently thrown away, while the page
// still says "updated". B3's open-row check must go red on it.
$p7 = $plant($copyOf('save.php'), [
    "\$stmt->execute();\n    \$rowsWritten = \$stmt->affected_rows;" => '$rowsWritten = 1;',
], static function () use ($pressOpen, &$openRows): ?array {
    return $openRows === [] ? null : $pressOpen($openRows[0]);
});
check('P7 with the UPDATE silently skipped (the page still says "updated"), the plant applied AND the open row\'s check goes red: nothing STORED',
    $p7['applied'] === true && $openRows !== [] && ($p7['result']['stored'] ?? true) === false && ($p7['result']['said'] ?? false) === true,
    'applied: ' . var_export($p7['applied'], true) . ', open rows: ' . count($openRows));

// #845: the drawing's transcription ask taken out — the row offers Edit and Delete again.
$p8 = $plant($copyOf('index.php'), [
    "\$transcriptLocked = \\Portal\\Core\\TranscriptRetention::lockedInGeneralEditor((string) \$row['settingKey']);" => '$transcriptLocked = false;',
], static fn (): array => $drawnWrong($drawn()));
check('P8 with the drawing\'s transcription ask taken out, the plant applied AND section D names transcription.retentionDays drawn with buttons',
    $p8['applied'] === true && count(array_filter($p8['result'] ?? [], static fn (string $l): bool => str_starts_with($l, 'transcription.retentionDays '))) > 0,
    'applied: ' . var_export($p8['applied'], true) . '; D reported: ' . implode('; ', $p8['result'] ?? []));
// #845: a lock with no branch in the note — the row hides its buttons and says nothing.
$p9 = $plant($copyOf('index.php'), [
    "<?php elseif (\$currencyLocked === true): ?>" => "<?php elseif (false): ?>",
], static fn (): array => $drawnWrong($drawn()));
check('P9 with the currency\'s note branch taken out, the plant applied AND section D names a row with no note',
    $p9['applied'] === true && count(array_filter($p9['result'] ?? [], static fn (string $l): bool => str_contains($l, 'no note naming'))) > 0,
    'applied: ' . var_export($p9['applied'], true) . '; D reported: ' . implode('; ', $p9['result'] ?? []));

// Section S's own plants, in memory.
$plantedIndex = str_replace(
    "\\Portal\\Core\\TranscriptRetention::lockedInGeneralEditor((string) \$lockedKey['settingKey'])",
    'false',
    $indexRaw
);
$plantedMissing = missingAsks($methods, threePaths($saveRaw, $plantedIndex));
check('P4 with the transcription call taken out of the delete, section S names exactly that one missing ask',
    $plantedIndex !== $indexRaw && $plantedMissing === ['deleting a row (index.php): TranscriptRetention::lockedInGeneralEditor()'],
    'section S reported: ' . implode('; ', $plantedMissing));
$textOnly = str_replace(
    "\\Portal\\Core\\TranscriptRetention::lockedInGeneralEditor((string) \$lockedKey['settingKey']) === true",
    "'\\Portal\\Core\\TranscriptRetention::lockedInGeneralEditor(' !== ''",
    $indexRaw
);
$textMissing = missingAsks($methods, threePaths($saveRaw, $textOnly));
$drawingPlanted = str_replace(
    "\\Portal\\Core\\TranscriptRetention::lockedInGeneralEditor((string) \$row['settingKey'])",
    'false',
    $indexRaw
);
$drawingMissing = missingAsks($methods, threePaths($saveRaw, $drawingPlanted));
check('P4c with the transcription call taken out of the DRAWING, section S names exactly that one missing ask',
    $drawingPlanted !== $indexRaw && $drawingMissing === ['drawing a row (index.php): TranscriptRetention::lockedInGeneralEditor()'],
    'section S reported: ' . implode('; ', $drawingMissing));
check('P4b with the call replaced by a STRING holding its text (refuses nothing), section S still names it missing',
    $textOnly !== $indexRaw && $textMissing === ['deleting a row (index.php): TranscriptRetention::lockedInGeneralEditor()'],
    'section S reported: ' . ($textMissing === [] ? 'nothing missing' : implode('; ', $textMissing)));

// After the presses and plants, every row is back exactly as it was.
$back = 0;
foreach ($snapshot as $row) {
    $now = $rowOf((string) $row['settingKey'], $row['siteID'] === null ? null : (int) $row['siteID']);
    if ($now !== null && (int) $now['settingID'] === (int) $row['settingID'] && $now['settingValue'] === $row['settingValue']) {
        $back++;
    }
}
check('P5 every row a press or a plant took away or changed is back, with its own number and value', $back === count($snapshot), "{$back} of " . count($snapshot));

// ── Tidy up: only what this run made (the shutdown function would do it too) ─
$cleanUp();

// S1 four times, S2, B0, B0b, B1a/b twice, B1c, B2 and B3 once per row, B4 once
// per key, D0 to D2, P1 to P3, P6a/b, P7, P8, P9, P4, P4c, P4b, P5.
$expected = 4 + 1 + 1 + 1 + 4 + 1 + count($snapshot) * 2 + count($locked) + 3 + 3 + 2 + 1 + 2 + 1 + 2 + 1;
printf("\n%d passed, %d failed, %d ran of %d expected (%d lock methods, %d locked keys, %d rows)\n",
    $passed, $failed, $passed + $failed, $expected, count($methods), count($locked), count($snapshot));
if ($passed + $failed !== $expected) {
    echo "The number of checks is not the number expected, so a block was skipped or ran twice.\n";
    exit(1);
}
exit($failed === 0 ? 0 : 1);
