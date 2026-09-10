<?php
// Path: _apps/admin/system-info/phpinfo.php
/**
 * -----------------------------------------------------------------------------
 * Admin — the full PHP report 📋
 * -----------------------------------------------------------------------------
 * A description of PHP on this server: its version, the settings that decide how
 * it behaves, and every optional part that is installed. This is what a hosting
 * company asks for when diagnosing an awkward problem, and the plain text
 * version of it is meant to be pasted straight into a support ticket.
 *
 * =============================================================================
 * READ THIS BEFORE CHANGING ANYTHING HERE
 * =============================================================================
 * This page has been rewritten three times, because three independent reviews
 * each got past the version before it. Every one of those attempts looked
 * reasonable when it was written. The history is short and worth knowing,
 * because the obvious change is the one that keeps failing.
 *
 * ATTEMPT 1 — call PHP's own phpinfo() and cut the dangerous parts out.
 *   Broken. phpinfo() prints the server's environment variables (which on shared
 *   hosting hold database passwords) and the current request (which holds the
 *   reader's own sign-in cookie). You can ask it to omit those two sections, but
 *   that is not enough: when PHP runs as an Apache module, the "apache2handler"
 *   entry inside the installed-parts section prints "Apache Environment" and
 *   "HTTP Headers Information" of its own accord, and the second carries the
 *   Cookie header. Worse, the filter written to remove them never matched
 *   anything at all, because PHP puts those headings in <h2> elements OUTSIDE
 *   the tables, not inside them.
 *
 * ATTEMPT 2 — build the report ourselves, and hide values that LOOK secret.
 *   Broken. There is always another shape. The one that got through was a Redis
 *   cache address written the documented way, with auth[]= array parameters,
 *   which no "auth=" pattern matches. A value can also be pulled in from the
 *   server's environment using PHP's ${NAME} syntax, arriving here as an
 *   ordinary string with nothing to mark it as a password.
 *
 * ATTEMPT 3 — only show settings on an approved list of NAMES.
 *   Still broken, in two ways. Approving whole families by prefix (opcache.,
 *   zend.) silently approved every future setting in those families, and several
 *   existing ones holding file paths and account names — opcache.preload_user
 *   and opcache.error_log among them. And approving a name says nothing about
 *   its value: a setting as innocent as arg_separator.output can be set to any
 *   string at all, and in testing it was.
 *
 * WHAT IS HERE NOW — approve the name AND check the value
 * -------------------------------------------------------
 * Every setting shown is named individually below, together with the KIND of
 * value it is expected to hold: a number, a switch, a size, a time zone, and so
 * on. The value is shown only if it actually looks like that kind of thing. A
 * number that is not a number, or a separator that is not one or two punctuation
 * marks, is withheld.
 *
 * That is what finally closes the hole, because it no longer depends on
 * recognising secrets. A secret placed in arg_separator.output fails the check
 * for "one to four punctuation marks" and never appears, without anybody having
 * had to imagine that particular hiding place.
 *
 * Rules for adding a setting here: name it individually, never by prefix; give
 * it the tightest kind that fits; and never add one whose natural value is a
 * file path, because on shared hosting a path contains the customer's account
 * name.
 *
 * WHO CAN SEE IT
 * --------------
 * Umbrella administrators only. This describes the whole SERVER rather than one
 * organisation on it. On an ordinary single-organisation install the owner is
 * the umbrella administrator, so nothing is taken away from anybody.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   3.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/489
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;

// 🔐 Umbrella administrators only. This runs before a single byte of output.
Auth::ensureSession();
Auth::requireLogin();
if (App::isUmbrellaAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

// 📄 Two ways to read the same report: a normal page, or plain text. The plain
//    text one is the version meant for sending to somebody else.
$asText = isset($_GET['format']) === true && $_GET['format'] === 'text';

// -----------------------------------------------------------------------------
// ✅ Which settings are reported, and what each one is allowed to look like
// -----------------------------------------------------------------------------
// The kinds:
//   int      a whole number. Written as int:min:max so each setting carries the
//            range that makes sense for it — a percentage is not a memory limit
//   bytes    a size, such as 512M or 64K or -1
//   bool     a switch: On, Off, 1, 0, empty, or one of PHP's word forms
//   sep      one to four punctuation marks (argument separators)
//   order    a short run of the letters G P C S E (which inputs PHP reads)
//   tz       a real time-zone name, checked against PHP's own list
//   charset  a character-set name
//   blocked  a list of blocked function or class names — reported by counting
//            them and naming only the ones from a fixed list we recognise,
//            never by printing the list back
//   samesite one of the four values PHP accepts for the cookie SameSite rule
//   savehandler  one of the session storage back-ends PHP knows about
//   mblang   one of the language names the mbstring extension accepts
//   jit      one of the accepted just-in-time compiler modes, or its number form
//
// There is deliberately NO general "short word" kind any more. It was used for
// session.name and session.cookie_path, on the argument that a cookie's own name
// and path are told to every browser anyway and so cannot be secret. That
// argument was wrong in one direction nobody had noticed: the SERVER'S DEFAULT
// for those settings is not the value in use — this portal overrides the path
// with "/" when it starts a session — so the default can be some other path
// entirely, and on shared hosting a path contains the account name. Both
// settings were dropped rather than special-cased. They were worth very little.
//
// Deliberately absent: anything whose value is naturally a file path. On shared
// hosting a path contains the customer's account name and private directory
// layout, which is nobody else's business and is not needed to diagnose
// anything.

const REPORT_SETTINGS = [
    // — Size and time limits. The usual explanation for a puzzling failure. —
    'memory_limit'                   => 'bytes',
    'upload_max_filesize'            => 'bytes',
    'post_max_size'                  => 'bytes',
    'max_execution_time'             => 'int:-1:86400',
    'max_input_time'                 => 'int:-1:86400',
    'max_file_uploads'               => 'int:0:100000',
    'max_input_vars'                 => 'int:0:10000000',
    'max_input_nesting_level'        => 'int:0:100000',
    'default_socket_timeout'         => 'int:-1:86400',
    'file_uploads'                   => 'bool',
    'enable_post_data_reading'       => 'bool',

    // — How errors are handled. Never where they are written, which is a path. —
    'display_errors'                 => 'bool',
    'display_startup_errors'         => 'bool',
    'log_errors'                     => 'bool',
    'error_reporting'                => 'int:-1:2147483647',
    'log_errors_max_len'             => 'int:0:1073741824',
    'ignore_repeated_errors'         => 'bool',
    'html_errors'                    => 'bool',
    'zend.exception_ignore_args'     => 'bool',
    'zend.assertions'                => 'int:-1:1',

    // — Output and language behaviour —
    'default_charset'                => 'charset',
    'internal_encoding'              => 'charset',
    'output_buffering'               => 'bytes',
    'implicit_flush'                 => 'bool',
    'precision'                      => 'int:-1:100',
    'serialize_precision'            => 'int:-1:100',
    'short_open_tag'                 => 'bool',
    'expose_php'                     => 'bool',
    'zlib.output_compression'        => 'boolorbytes',
    'arg_separator.input'            => 'sep',
    'arg_separator.output'           => 'sep',
    'variables_order'                => 'order',
    'request_order'                  => 'order',

    // — What the host has switched off. Important, and not a secret. —
    'allow_url_fopen'                => 'bool',
    'allow_url_include'              => 'bool',
    'disable_functions'              => 'blocked',
    'disable_classes'                => 'blocked',

    // — Dates —
    'date.timezone'                  => 'tz',

    // — Sessions. Never save_path: that is where a cache password lives. —
    'session.save_handler'           => 'savehandler',
    'session.auto_start'             => 'bool',
    'session.use_strict_mode'        => 'bool',
    'session.use_cookies'            => 'bool',
    'session.use_only_cookies'       => 'bool',
    'session.use_trans_sid'          => 'bool',
    'session.cookie_lifetime'        => 'int:0:315360000',
    'session.cookie_secure'          => 'bool',
    'session.cookie_httponly'        => 'bool',
    'session.cookie_samesite'        => 'samesite',
    'session.gc_probability'         => 'int:0:1000000',
    'session.gc_divisor'             => 'int:0:1000000',
    'session.gc_maxlifetime'         => 'int:0:315360000',
    'session.sid_length'             => 'int:22:256',
    'session.sid_bits_per_character' => 'int:4:6',
    'session.lazy_write'             => 'bool',

    // — Character handling —
    'mbstring.language'              => 'mblang',
    'mbstring.internal_encoding'     => 'charset',
    'mbstring.http_input'            => 'charset',
    'mbstring.http_output'           => 'charset',
    'mbstring.detect_order'          => 'charset',
    'iconv.input_encoding'           => 'charset',
    'iconv.output_encoding'          => 'charset',
    'iconv.internal_encoding'        => 'charset',

    // — Pattern-matching limits. These decide whether a big page can be
    //   processed at all, so they are worth seeing. —
    'pcre.backtrack_limit'           => 'int:0:1000000000000',
    'pcre.recursion_limit'           => 'int:0:1000000000000',
    'pcre.jit'                       => 'bool',

    // — The performance cache. Named one at a time on purpose: the same family
    //   also contains preload_user, error_log, file_cache, blacklist_filename
    //   and lockfile_path, every one of which is a path or an account name. —
    'opcache.enable'                  => 'bool',
    'opcache.enable_cli'              => 'bool',
    'opcache.memory_consumption'      => 'int:0:65536',
    'opcache.interned_strings_buffer' => 'int:0:65536',
    'opcache.max_accelerated_files'   => 'int:0:10000000',
    'opcache.max_wasted_percentage'   => 'int:0:100',
    'opcache.use_cwd'                 => 'bool',
    'opcache.validate_timestamps'     => 'bool',
    'opcache.revalidate_freq'         => 'int:0:86400',
    'opcache.save_comments'           => 'bool',
    'opcache.huge_code_pages'         => 'bool',
    'opcache.jit'                     => 'jit',
    'opcache.jit_buffer_size'         => 'bytes',

    // — Images and documents —
    'gd.jpeg_ignore_warning'         => 'bool',
    'soap.wsdl_cache_enabled'        => 'bool',
    'soap.wsdl_cache_ttl'            => 'int:0:31536000',
];

/**
 * 🚦 Does this value actually look like the kind of thing we expect?
 *
 * This is the check that finally makes the report safe, and it works because it
 * does not try to recognise a secret. It only asks whether the value is the
 * shape it is supposed to be. A password put into a setting that should hold a
 * number is not a number, so it is withheld — and nobody had to think of that
 * particular hiding place in advance.
 *
 * @param string $kind  One of the kinds listed above.
 * @param string $value The value as PHP reports it.
 *
 * @return bool True if the value may be displayed.
 */
function portalValueMatchesKind(string $kind, string $value): bool
{
    // An empty value is always safe to show as "no value".
    if ($value === '') {
        return true;
    }

    // Nothing legitimate in this report is long. A very long value is a strong
    // sign that something unexpected has been put there.
    if (strlen($value) > 300) {
        return false;
    }

    // 🔢 A kind may carry its own range, written as "int:min:max". Each numeric
    //    setting has one, because the sensible range for a percentage is not the
    //    sensible range for a memory limit.
    //
    //    A single blanket cap was tried first and was wrong in both directions:
    //    too loose to be worth much, and tight enough to withhold real values —
    //    a 16 GiB memory limit and a three-billion backtrack limit are both
    //    perfectly ordinary on a 64-bit server, and both were being hidden.
    $bounds = explode(':', $kind);
    $kind   = $bounds[0];
    $min    = isset($bounds[1]) === true ? (int) $bounds[1] : null;
    $max    = isset($bounds[2]) === true ? (int) $bounds[2] : null;

    switch ($kind) {
        case 'int':
            if (preg_match('/^-?\d{1,19}$/', $value) !== 1) {
                return false;
            }
            $number = (int) $value;
            if ($min !== null && $number < $min) {
                return false;
            }
            if ($max !== null && $number > $max) {
                return false;
            }
            return true;

        case 'bytes':
            // A size, either a plain number of bytes or one with a K, M or G on
            // the end. -1 means "no limit" and is normal. The ceiling is one
            // terabyte, which no real setting reaches and which still leaves
            // every legitimate value — including a 16 GiB memory limit — visible.
            if (preg_match('/^(-?\d{1,19})([KMGkmg]?)$/', $value, $m) !== 1) {
                return false;
            }
            $multiplier = ['' => 1, 'k' => 1024, 'm' => 1048576, 'g' => 1073741824];
            $asBytes    = (int) $m[1] * $multiplier[strtolower($m[2])];
            return $asBytes >= -1 && $asBytes <= 1099511627776;

        case 'boolorbytes':
            // This one is genuinely either. Switching it on with a number sets
            // the buffer size, so 4096 is as valid as "On".
            return portalValueMatchesKind('bool', $value) === true
                || portalValueMatchesKind('bytes', $value) === true;

        case 'bool':
            return in_array(
                strtolower($value),
                ['0', '1', 'on', 'off', 'true', 'false', 'yes', 'no', 'stderr', 'stdout'],
                true
            );

        case 'sep':
            return preg_match('/^[&;,|]{1,4}$/', $value) === 1;

        case 'order':
            return preg_match('/^[GPCSE]{1,5}$/i', $value) === 1;

        case 'tz':
            // ALL_WITH_BC includes the older compatibility names that PHP still
            // accepts, such as US/Eastern. Without it a perfectly valid setting
            // was being withheld as though it were suspicious.
            return in_array($value, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true) === true
                || strtoupper($value) === 'UTC';

        case 'charset':
            // Checked against the list of character sets PHP itself knows,
            // rather than against a pattern. A pattern that merely allowed
            // "letters, digits and punctuation" also allowed
            // /home/{account}/private — an actual path, which is exactly what
            // must never appear. A name either is one of PHP's encodings or it
            // is not, and there is no argument about it.
            if (function_exists('mb_list_encodings') === false) {
                return false;
            }
            // mb_list_encodings() returns only the CANONICAL name of each
            // encoding. Everyday spellings like "utf8" are aliases and are not
            // in that list, so checking against it alone withheld perfectly
            // ordinary values. Ask for each encoding's aliases too.
            //
            // Built once and remembered. Without that, this walks about eighty
            // encodings on every single page view for no benefit.
            //
            // Four entries are skipped on purpose. mbstring's list includes some
            // things that are not really character sets — Base64, Uuencode, HTML
            // entities and quoted-printable — and asking for THEIR aliases
            // raises a deprecation notice on PHP 8.5. That notice would be
            // written to the server's error log every time this page was opened,
            // which is a silly thing to do to somebody's log file. None of the
            // four is a plausible value for a character-set setting anyway.
            static $known = null;

            if ($known === null) {
                // These are the names as mb_list_encodings() actually returns
                // them, lowercased. Note that the deprecation message calls the
                // last one "QPrint" while the list calls it "Quoted-Printable";
                // matching the message rather than the list is what left one
                // notice still firing the first time round.
                $notReallyEncodings = ['base64', 'uuencode', 'html-entities', 'quoted-printable'];
                $known = [];
                foreach (mb_list_encodings() as $enc) {
                    $known[] = strtolower($enc);
                    if (in_array(strtolower($enc), $notReallyEncodings, true) === true) {
                        continue;
                    }
                    foreach (mb_encoding_aliases($enc) as $alias) {
                        $known[] = strtolower($alias);
                    }
                }
                $known[] = 'auto';
                $known[] = 'pass';
                $known   = array_values(array_unique($known));
            }
            foreach (explode(',', $value) as $part) {
                if (in_array(strtolower(trim($part)), $known, true) === false) {
                    return false;
                }
            }
            return true;

        case 'blocked':
            // Never validated for display, because this value is never
            // displayed. See portalDescribeBlocked() below for what is shown
            // instead and why.
            return true;

        case 'samesite':
            return in_array(strtolower($value), ['lax', 'strict', 'none'], true);

        case 'savehandler':
            return in_array(
                strtolower($value),
                ['files', 'user', 'redis', 'rediscluster', 'memcached', 'memcache', 'sqlite', 'mm'],
                true
            );

        case 'mblang':
            return in_array(
                strtolower($value),
                [
                    'neutral', 'english', 'uni', 'japanese', 'ja', 'korean', 'ko',
                    'simplified chinese', 'zh-cn', 'traditional chinese', 'zh-tw',
                    'russian', 'ru', 'armenian', 'hy', 'turkish', 'tr', 'german', 'de',
                ],
                true
            );

        case 'jit':
            return in_array(strtolower($value), ['off', 'disable', 'on', 'tracing', 'function'], true)
                || preg_match('/^\d{1,4}$/', $value) === 1;


        default:
            // An unknown kind means somebody added a setting without saying what
            // it should look like. Withhold it rather than guess.
            return false;
    }
}

/**
 * 🚫 Describe a list of blocked functions or classes WITHOUT printing it back.
 *
 * `disable_functions` is genuinely useful to see — knowing that `exec` is blocked
 * explains a great many failures. But PHP keeps whatever it is given in that
 * setting, including names that are not functions at all, so printing the list
 * back means printing arbitrary text somebody put there. In testing, a value of
 * `PRIVATE_SECRET_abc` survived and would have been shown.
 *
 * So the list is never printed. Instead each entry is checked against a fixed
 * list of the functions people actually block, and the answer is: which of those
 * are blocked, and how many other entries there are. That answers the real
 * question — "is this why my upload/shell/mail call is failing?" — and cannot
 * disclose anything, because every word that reaches the page comes from the
 * fixed list below rather than from the setting.
 *
 * @param string $value The raw comma-separated setting value.
 *
 * @return string A sentence describing it, safe to display.
 */
function portalDescribeBlocked(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'nothing is blocked';
    }

    // The functions hosting companies commonly switch off. Every word that can
    // reach the page comes from HERE, never from the setting itself.
    $wellKnown = [
        'exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open',
        'proc_close', 'proc_get_status', 'proc_nice', 'proc_terminate',
        'pcntl_exec', 'pcntl_fork', 'dl', 'putenv', 'getenv',
        'symlink', 'link', 'chown', 'chgrp', 'chmod',
        'posix_kill', 'posix_getpwuid', 'posix_uname', 'posix_setuid',
        'apache_child_terminate', 'apache_setenv', 'apache_get_modules',
        'ini_alter', 'ini_restore', 'openlog', 'syslog', 'escapeshellcmd',
        'escapeshellarg', 'show_source', 'highlight_file', 'phpinfo',
        'mail', 'curl_exec', 'curl_multi_exec', 'parse_ini_file',
    ];

    $entries = array_filter(array_map('trim', explode(',', $value)), static fn (string $e): bool => $e !== '');
    $named   = [];
    $others  = 0;

    foreach ($entries as $entry) {
        $lower = strtolower($entry);
        if (in_array($lower, $wellKnown, true) === true) {
            $named[$lower] = true;
            continue;
        }
        $others++;
    }

    $parts = [];
    if (count($named) > 0) {
        $list = array_keys($named);
        sort($list);
        $parts[] = 'blocked: ' . implode(', ', $list);
    }
    if ($others > 0) {
        $parts[] = sprintf('%d other entr%s not shown', $others, $others === 1 ? 'y' : 'ies');
    }
    if (count($parts) === 0) {
        return sprintf('%d entr%s, none recognised', count($entries), count($entries) === 1 ? 'y' : 'ies');
    }
    return implode('; ', $parts);
}

// -----------------------------------------------------------------------------
// 🔒 Last line of defence
// -----------------------------------------------------------------------------

/**
 * 🔎 Does the finished report contain the reader's own sign-in token?
 *
 * IT SEARCHES THE DECODED REPORT, NOT AN ENCODED TOKEN, and that distinction is
 * the whole point. An earlier version encoded the token and searched for that,
 * which does nothing: a session token is letters and digits, so encoding it
 * returns the identical string. Meanwhile the token can appear in a report
 * escaped, as %61bc... where the real one is abc... . Decoding the REPORT closes
 * that gap, whichever way it was escaped on the way in.
 *
 * @param string $haystack The finished report, exactly as it would be sent.
 *
 * @return bool True if the token appears in any form.
 */
function portalReportLeaksSessionToken(string $haystack): bool
{
    $token = session_id();
    if (is_string($token) === false || strlen($token) < 8) {
        return false;
    }

    foreach ([$haystack, rawurldecode($haystack), urldecode($haystack)] as $form) {
        if (strpos($form, $token) !== false) {
            return true;
        }
    }
    return false;
}

// -----------------------------------------------------------------------------
// 📊 Gather the report
// -----------------------------------------------------------------------------

// 🧾 General facts. Every one is chosen individually.
//
//    Note what is NOT here: the location of PHP's settings files. Those are full
//    paths, and on shared hosting a path such as /home/{account}/private/php.ini
//    gives away the customer's account name and directory layout. Whether the
//    files exist, and how many there are, answers the diagnostic question
//    without any of that.
$scanned      = php_ini_scanned_files();
$scannedCount = ($scanned === false || trim((string) $scanned) === '')
    ? 0
    : count(array_filter(array_map('trim', explode(',', (string) $scanned))));

$general = [
    'PHP version'          => PHP_VERSION,
    'How PHP is being run' => PHP_SAPI,
    'Operating system'     => PHP_OS_FAMILY,
    'Engine version'       => zend_version(),
    'Integer size (bytes)' => (string) PHP_INT_SIZE,
    'Main settings file'   => php_ini_loaded_file() !== false ? 'loaded' : 'none found',
    'Extra settings files' => (string) $scannedCount,
    'Default time zone'    => date_default_timezone_get(),
];

// 📦 Which optional parts of PHP are installed, and their versions.
$extensions = [];
if (function_exists('get_loaded_extensions') === true) {
    $names = get_loaded_extensions();
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($names as $ext) {
        $ver = phpversion($ext);
        $extensions[(string) $ext] = ($ver === false || $ver === '') ? '(no version reported)' : (string) $ver;
    }
}

// ⚙️ The settings, filtered as described at the top of this file.
$settingsAvailable = function_exists('ini_get_all');
$settings          = [];
$withheldCount     = 0;

if ($settingsAvailable === true) {
    $raw = ini_get_all(null, true);
    if (is_array($raw) === true) {
        foreach (REPORT_SETTINGS as $name => $kind) {
            if (array_key_exists($name, $raw) === false) {
                continue;
            }

            $local = $raw[$name]['local_value'] ?? '';
            $globl = $raw[$name]['global_value'] ?? '';
            $local = is_array($local) === true ? implode(', ', $local) : (string) $local;
            $globl = is_array($globl) === true ? implode(', ', $globl) : (string) $globl;

            // 🚫 The blocked-function lists are summarised, never echoed.
            if ($kind === 'blocked') {
                $settings[$name] = [
                    'local'  => portalDescribeBlocked($local),
                    'global' => portalDescribeBlocked($globl),
                    'shown'  => true,
                ];
                continue;
            }

            if (portalValueMatchesKind($kind, $local) === false
                || portalValueMatchesKind($kind, $globl) === false
            ) {
                $settings[$name] = ['local' => '', 'global' => '', 'shown' => false];
                $withheldCount++;
                continue;
            }

            $settings[$name] = ['local' => $local, 'global' => $globl, 'shown' => true];
        }
    }
}

// -----------------------------------------------------------------------------
// 📝 Render
// -----------------------------------------------------------------------------

if ($asText === true) {
    $lines   = [];
    $lines[] = 'PHP report — ' . date('Y-m-d H:i:s T');
    $lines[] = str_repeat('=', 70);
    $lines[] = '';
    $lines[] = 'GENERAL';
    foreach ($general as $k => $v) {
        $lines[] = sprintf('  %-28s %s', $k, $v);
    }
    $lines[] = '';
    $lines[] = 'INSTALLED PARTS OF PHP (' . count($extensions) . ')';
    foreach ($extensions as $k => $v) {
        $lines[] = sprintf('  %-28s %s', $k, $v);
    }
    $lines[] = '';
    if ($settingsAvailable === false) {
        $lines[] = 'SETTINGS: not available — this hosting has switched off the function that lists them.';
    } else {
        $lines[] = 'SETTINGS (' . count($settings) . ') — value here | server default';
        foreach ($settings as $k => $v) {
            if ($v['shown'] === false) {
                $lines[] = sprintf('  %-40s [value not shown]', $k);
                continue;
            }
            $lines[] = sprintf(
                '  %-40s %s | %s',
                $k,
                $v['local'] === '' ? '(no value)' : $v['local'],
                $v['global'] === '' ? '(no value)' : $v['global']
            );
        }
    }
    $lines[] = '';
    $lines[] = 'WHAT THIS REPORT LEAVES OUT, ON PURPOSE';
    $lines[] = '  This is a chosen list of settings, not every setting PHP has. Each one is';
    $lines[] = '  also checked against the kind of value it should hold, and withheld if it';
    $lines[] = '  does not match. File locations are left out entirely, because on shared';
    $lines[] = '  hosting a file path gives away the account name.';
    if ($withheldCount > 0) {
        $lines[] = sprintf('  %d setting(s) did not match their expected kind and were withheld.', $withheldCount);
    }
    $lines[] = '  If your hosting provider needs something that is not here, they can ask you';
    $lines[] = '  for that one setting.';

    $body = implode("\n", $lines);

    if (portalReportLeaksSessionToken($body) === true) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit("This report was not shown, because it turned out to contain your own sign-in token.\n"
           . "That should not be possible and is worth reporting.\n");
    }

    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    echo $body;
    exit();
}

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, no-cache, must-revalidate, private');

// 🛑 A guard around everything drawn below. It throws away whatever it is given
//    unless the page has been explicitly approved.
//
//    Why it is built this way. The check that matters happens AFTER the page is
//    drawn. If drawing it fails half way — an exception, or an error that stops
//    PHP altogether — that check is never reached, and PHP sends whatever is
//    sitting in the buffer as it shuts down. A partly-drawn, unchecked report
//    would go out. Wrapping the drawing in try/finally does not help, because a
//    fatal error runs neither.
//
//    This does hold, because the buffer's own handler decides what is allowed
//    out and refuses by default. It runs during shutdown as well, so the failure
//    path sends nothing rather than something unchecked.
// 🛑 One guard around everything drawn below, which decides — for the WHOLE
//    page at once — whether any of it may be sent.
//
//    Two things had to be true, and an earlier version only managed one.
//
//    First, it must refuse by default. The check that matters happens after the
//    page is drawn; if drawing fails half way, that check is never reached and
//    PHP flushes whatever is in the buffer as it shuts down. Wrapping the
//    drawing in try/finally does not help, because a fatal error runs neither.
//
//    Second, it must check what is ACTUALLY going to be sent. The earlier
//    version captured the page in a second, inner buffer and checked that. A
//    review showed the gap: if anything during rendering closed that inner
//    buffer and opened another, the already-flushed part sat unchecked in the
//    outer buffer and went out with the approval. Nothing in this page does
//    that today, but relying on "nothing does that today" is how the previous
//    three attempts failed.
//
//    Doing the check inside the guard itself settles both. Whatever ends up
//    here, however it got here, is inspected as one piece before anything
//    leaves. There is no inner buffer to outmanoeuvre.
$reportApproved    = false;
$guardLevelBefore  = ob_get_level();
ob_start(static function (string $buffer) use (&$reportApproved): string {
    // Not approved — the page did not finish drawing. Send nothing.
    if ($reportApproved !== true) {
        return '';
    }

    // Approved, but check the finished article before it goes anywhere.
    if (portalReportLeaksSessionToken($buffer) === true) {
        return 'This report was not shown, because it turned out to contain your own '
             . 'sign-in token. That should not be possible and is worth reporting.';
    }

    return $buffer;
}, 0, 0);

// 🛑 Note the two zeros above, because they are doing real work.
//
//    The second is the chunk size: 0 means "do not hand me the page in pieces,
//    give me the whole thing once", which is what makes checking it as one piece
//    possible.
//
//    The third is the flags, and 0 there means this buffer cannot be flushed,
//    cleaned or REMOVED by anything else. Without that, a single ob_end_flush()
//    anywhere in the shared page template would delete this guard and everything
//    printed afterwards would go straight out unchecked — a review reproduced
//    exactly that. Nothing in the template does it today, but "nothing does that
//    today" is the reasoning that has already failed here four times.
//
//    And if the buffer could not be created at all, there is no protection, so
//    nothing is drawn.
if (ob_get_level() === $guardLevelBefore) {
    http_response_code(500);
    exit('This report could not be shown safely: the protection around it could '
       . 'not be set up. Nothing has been displayed.');
}

$pageTitle   = 'Full PHP report';
$pageSection = 'admin';
$breadcrumbs = [
    'Dashboard'          => '/',
    'Admin'              => '/admin',
    'Server Information' => '/admin/system-info',
    'Full PHP report'    => '',
];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-file-lines me-2"></i>Full PHP report</h1>
        <p class="text-secondary mb-0">
            How PHP is set up on this server. This is what a hosting company usually
            asks to see.
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="/admin/system-info/phpinfo?format=text" class="btn btn-outline-primary btn-sm" target="_blank" rel="noopener">
            <i class="fa-solid fa-align-left me-1"></i>Plain text
        </a>
        <a href="/admin/system-info" class="btn btn-outline-secondary btn-sm">&larr; Server Information</a>
    </div>
</div>

<div class="alert alert-info">
    <p class="mb-1"><strong>Send the plain text version, not this page.</strong></p>
    <p class="mb-0 small">
        The <strong>Plain text</strong> button gives you a copy built to be safe to hand
        to somebody else. This web page is an ordinary signed-in admin screen, so a saved
        copy of it carries the same hidden security details every admin page does &mdash;
        harmless while it stays with you, but not something to email or paste into a
        public forum. The plain text version has none of that.
    </p>
</div>

<div class="alert alert-secondary">
    <p class="mb-1"><strong>This is a chosen list of settings, not every setting.</strong></p>
    <p class="mb-0 small">
        Each setting below is also checked against the kind of value it ought to hold
        &mdash; a number, a switch, a size &mdash; and withheld if it does not match.
        That is deliberately strict: it means a password stored somewhere unexpected
        cannot appear here, without anybody having to guess where somebody might hide
        one. File locations are left out entirely, because on shared hosting a file path
        gives away your account name.
    </p>
</div>

<!-- 🧾 General -->
<div class="card mb-4">
    <div class="card-header"><h2 class="h5 mb-0">General</h2></div>
    <div class="card-body">
        <div class="portal-data-list">
            <?php foreach ($general as $label => $value): ?>
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold"><?php echo htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-12 col-md-8"><code><?php echo htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); ?></code></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- 📦 Installed parts -->
<div class="card mb-4">
    <div class="card-header">
        <h2 class="h5 mb-0">Installed parts of PHP (<?php echo htmlspecialchars((string) count($extensions), ENT_QUOTES, 'UTF-8'); ?>)</h2>
    </div>
    <div class="card-body">
        <div class="portal-data-list">
            <?php foreach ($extensions as $name => $ver): ?>
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold"><code><?php echo htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8'); ?></code></div>
                <div class="col-12 col-md-8"><?php echo htmlspecialchars((string) $ver, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ⚙️ Settings -->
<div class="card mb-4">
    <div class="card-header">
        <h2 class="h5 mb-0">
            Settings<?php echo $settingsAvailable === true ? ' (' . htmlspecialchars((string) count($settings), ENT_QUOTES, 'UTF-8') . ')' : ''; ?>
        </h2>
    </div>
    <div class="card-body">
        <?php if ($settingsAvailable === false): ?>
            <p class="mb-0 text-muted">
                Your hosting provider has switched off the part of PHP that lists its own
                settings, so this section cannot be shown. That is a server-wide choice and
                cannot be changed from inside this portal. Everything else on this page and
                on the Server Information page still works.
            </p>
        <?php else: ?>
            <p class="text-secondary small">
                The first value is what is in force for this portal. The second is the
                server's own default, which can differ when a setting has been changed for
                this site only.
            </p>
            <div class="portal-data-list">
                <?php foreach ($settings as $name => $row): ?>
                <div class="portal-data-row">
                    <div class="col-12 col-md-4 fw-semibold"><code><?php echo htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8'); ?></code></div>
                    <?php if ($row['shown'] === false): ?>
                        <div class="col-12 col-md-8 text-muted small">
                            <i class="fa-solid fa-eye-slash me-1"></i>Value not shown &mdash; it is not the kind of value this setting should hold
                        </div>
                    <?php else: ?>
                        <div class="col-12 col-md-4">
                            <?php if ($row['local'] === ''): ?>
                                <span class="text-muted">no value</span>
                            <?php else: ?>
                                <code><?php echo htmlspecialchars($row['local'], ENT_QUOTES, 'UTF-8'); ?></code>
                            <?php endif; ?>
                        </div>
                        <div class="col-12 col-md-4 text-muted">
                            <?php if ($row['global'] === ''): ?>
                                <span class="small">no value</span>
                            <?php else: ?>
                                <code class="small"><?php echo htmlspecialchars($row['global'], ENT_QUOTES, 'UTF-8'); ?></code>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';

// ✅ The page finished drawing. Approving it lets the guard above run its check
//    and, if that passes, release the page. Nothing has reached the browser
//    before this line.
$reportApproved = true;
