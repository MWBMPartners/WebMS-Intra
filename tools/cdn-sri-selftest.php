<?php
// Path: tools/cdn-sri-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Library fingerprint self-test 🔐🧪 (#568)
 * -----------------------------------------------------------------------------
 * Downloads every third-party library that the portal pins to a fingerprint
 * (in a PHP, HTML or JavaScript file under web/), works out the fingerprint
 * again, and compares it with the one written in the code. A wrong
 * fingerprint FAILS. So does a fingerprint this test could not match to a
 * file to download (see check 4).
 *
 * WHY. A web page may pin a library to a fingerprint (the "integrity"
 * attribute, called SRI). The browser downloads the file, works out its own
 * fingerprint, and REFUSES the file if the two differ. In #568 three of the
 * portal's fingerprints were wrong (Bootstrap's stylesheet, Bootstrap's
 * script, Font Awesome), so browsers rejected those files, pages lost their
 * styling and every page logged a console error. A fourth wrong copy sat in
 * the installation wizard, the first screen a new customer sees.
 * `tools/audit-checks/check_cdn_sri.py` only checks that a fingerprint is
 * PRESENT; it cannot tell a right one from a wrong one. This can.
 *
 * WHAT IT CHECKS
 *   1. Every `CDN_<NAME>` constant in web/_core/Asset.php against the matching
 *      `<NAME>_INTEGRITY` constant (found with reflection). A CDN address with
 *      no matching fingerprint, or the other way round, is a FAIL.
 *   2. Every literal `<link>` or `<script>` tag with an `integrity=` attribute
 *      anywhere under web/ (today: the installer's Bootstrap tag, and the two
 *      Leaflet tags in the map partial). Either kind of quote is read, and so
 *      is an address that starts `//` (it is fetched over https).
 *   3. Every `<NAME>_SRI = "..."` in a JavaScript file, against the matching
 *      `<NAME>_URL = "..."` in the same file (today: React and React DOM in
 *      the noticeboard script, which adds its own script tags at run time).
 *   4. NOTHING WAS SKIPPED. Every fingerprint written anywhere in those files
 *      must have been compared by check 1, 2 or 3. One that was not is a FAIL
 *      naming the file and line.
 *
 * WHY CHECK 4 EXISTS. This test used to step quietly past any tag it could
 * not read (single quotes, an address starting `//`) and never opened a
 * JavaScript file, yet still printed "every fingerprint was checked". A wrong
 * value planted in such a tag passed. Counting what was skipped, rather than
 * trusting the reading rules to be complete, is what makes "every" true.
 *
 * NEEDS THE INTERNET. A file that cannot be downloaded (no network, a refused
 * connection, an empty answer or an error status) is reported as
 * "NOT CHECKED", and the run EXITS 1. A check that did not run has proved
 * nothing, and a quiet pass would be worse than a failure. So this belongs
 * in a place with internet (a pull-request check, or your own machine), not
 * in a sealed build.
 *
 * WHAT IT CANNOT PROVE. That the CDN will keep serving the same bytes for
 * ever (that is exactly what the fingerprint is for). That a browser accepts
 * the file: it works the fingerprint out the way browsers do (SHA-384 of the
 * exact bytes, then base64) but is not a browser. A fingerprint that is not
 * written out in full (one put together by code while a page is built) cannot
 * be seen by check 4; there is none today. Only files ending .php, .phtml,
 * .html, .htm, .js or .mjs are read.
 *
 * Usage:  php tools/cdn-sri-selftest.php [--root <folder holding web/>]
 * Exit:   0 only when every fingerprint was checked and matched; 1 otherwise.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/568
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

set_exception_handler(static function (Throwable $e): void {
    echo 'FAIL — the self-test stopped early: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(1);
});

$root = dirname(__DIR__);
$at   = array_search('--root', $argv, true);
if ($at !== false && isset($argv[$at + 1]) === true) {
    $root = (string) realpath($argv[$at + 1]);
}
$assetFile = $root . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'Asset.php';
if (is_file($assetFile) === false) {
    echo "FAIL — no web/_core/Asset.php under '{$root}'.\n";
    exit(1);
}

$bad        = 0; // fingerprints that did not match, or pairs that did not line up
$notChecked = 0; // files that could not be downloaded
$matched    = 0;
$cache      = []; // address => bytes (or null when the download failed)
$compared   = []; // file (relative to the root) => [fingerprint => true], filled by verify()

/** Download one address; null when it could not be fetched properly. */
function fetch(string $url): ?string
{
    global $cache;
    if (array_key_exists($url, $cache) === true) {
        return $cache[$url];
    }
    $context = stream_context_create(['http' => [
        'timeout'         => 60,
        'follow_location' => 1,
        'ignore_errors'   => true, // so a 404 body can be seen and rejected below, not hidden as a PHP warning
        'user_agent'      => 'WebMS-Intra-sri-selftest',
    ]]);
    $body   = @file_get_contents($url, false, $context);
    $status = 0;
    // PHP 8.4 added http_get_last_response_headers() and PHP 8.5 deprecates the
    // old $http_response_header variable, so use the function when it exists.
    $headers = function_exists('http_get_last_response_headers') === true
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    foreach ($headers as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
            $status = (int) $m[1]; // the LAST status line wins (after redirects)
        }
    }
    $cache[$url] = ($body !== false && $body !== '' && $status === 200) ? $body : null;

    return $cache[$url];
}

/** The fingerprint a browser works out: "sha384-" + base64 of the raw SHA-384. */
function fingerprint(string $bytes): string
{
    return 'sha384-' . base64_encode(hash('sha384', $bytes, true));
}

/**
 * Compare one pinned value (it may hold several, separated by spaces: a
 * browser accepts the file if ANY of them matches) with the real file.
 */
function verify(string $label, string $url, string $pinned, string $inFile): void
{
    global $bad, $notChecked, $matched, $compared;
    // Recorded BEFORE the download, so a value that could not be downloaded is
    // reported once as "NOT CHECKED" and not a second time by check 4.
    foreach (preg_split('/\s+/', trim($pinned)) ?: [] as $one) {
        $compared[$inFile][$one] = true;
    }
    $body = fetch($url);
    if ($body === null) {
        $notChecked++;
        echo "NOT CHECKED — {$label}: could not download {$url}\n";

        return;
    }
    $real = fingerprint($body);
    if (in_array($real, preg_split('/\s+/', trim($pinned)) ?: [], true) === true) {
        $matched++;
        echo "PASS — {$label}: matches ({$real})\n";

        return;
    }
    $bad++;
    echo "FAIL — {$label}: the code says {$pinned}\n         but {$url} is {$real}\n";
}

// =============================================================================
// 1. Asset.php: CDN_<NAME> paired with <NAME>_INTEGRITY, by reflection
// =============================================================================

require $assetFile;

$constants = (new ReflectionClass(Portal\Core\Asset::class))->getConstants();
$names = [];
foreach ($constants as $name => $value) {
    if (str_starts_with($name, 'CDN_') === true) {
        $names[substr($name, 4)] = true;
    } elseif (str_ends_with($name, '_INTEGRITY') === true) {
        $names[substr($name, 0, -strlen('_INTEGRITY'))] = true;
    }
}
ksort($names);
foreach (array_keys($names) as $base) {
    $urlKey = 'CDN_' . $base;
    $hashKey = $base . '_INTEGRITY';
    if (array_key_exists($urlKey, $constants) === false || array_key_exists($hashKey, $constants) === false) {
        $bad++;
        echo "FAIL — Asset::{$base}: needs both {$urlKey} and {$hashKey}, and one of them is missing\n";
        continue;
    }
    verify('Asset::' . $hashKey, (string) $constants[$urlKey], (string) $constants[$hashKey], substr($assetFile, strlen($root) + 1));
}
echo 'Asset.php: ' . count($names) . " library/libraries paired.\n";

// =============================================================================
// 2. Literal tags with integrity= anywhere under web/
// =============================================================================

$literal = 0;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . DIRECTORY_SEPARATOR . 'web', FilesystemIterator::SKIP_DOTS));
$files = [];
foreach ($iterator as $file) {
    if ($file->isFile() === true && in_array(strtolower($file->getExtension()), ['php', 'phtml', 'html', 'htm', 'js', 'mjs'], true) === true) {
        $files[] = $file->getPathname();
    }
}
sort($files);
$texts = []; // file => contents, kept for check 4
foreach ($files as $path) {
    $texts[$path] = (string) file_get_contents($path);
    if ($path === $assetFile) {
        continue; // read through reflection above
    }
    $text = $texts[$path];
    $rel  = substr($path, strlen($root) + 1);
    if (preg_match_all('/<(?:link|script)\b[^>]*>/is', $text, $tags, PREG_OFFSET_CAPTURE) === 0) {
        continue;
    }
    foreach ($tags[0] as [$tag, $offset]) {
        // A tag that cannot be read here is NOT failed here: if it carries a
        // fingerprint written out in full, check 4 below fails it by name.
        if (preg_match('/\bintegrity\s*=\s*(["\'])(.+?)\1/is', $tag, $i) !== 1) {
            continue;
        }
        if (preg_match('/\b(?:src|href)\s*=\s*(["\'])((?:https:)?\/\/.+?)\1/is', $tag, $u) !== 1) {
            continue;
        }
        $literal++;
        $line = substr_count($text, "\n", 0, $offset) + 1;
        $url  = str_starts_with($u[2], '//') === true ? 'https:' . $u[2] : $u[2];
        verify($rel . ':' . $line, $url, $i[2], $rel);
    }
}
echo "Literal tags: {$literal} found.\n";

// =============================================================================
// 3. JavaScript: <NAME>_SRI paired with <NAME>_URL in the same file
// =============================================================================

$scripted = 0;
foreach ($texts as $path => $text) {
    if (in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['js', 'mjs'], true) === false) {
        continue;
    }
    if (preg_match_all('/\b([A-Za-z_$][\w$]*)_SRI\s*=\s*(["\'])(sha(?:256|384|512)-.+?)\2/', $text, $pins, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
        continue;
    }
    $rel = substr($path, strlen($root) + 1);
    foreach ($pins as $pin) {
        if (preg_match('/\b' . preg_quote($pin[1][0], '/') . '_URL\s*=\s*(["\'])(https:\/\/.+?)\1/', $text, $u) !== 1) {
            continue; // no address beside it: check 4 fails it by name
        }
        $scripted++;
        $line = substr_count($text, "\n", 0, $pin[0][1]) + 1;
        verify($rel . ':' . $line, $u[2], $pin[3][0], $rel);
    }
}
echo "JavaScript pairs: {$scripted} found.\n";

// =============================================================================
// 4. Nothing was skipped: every fingerprint written in these files was compared
// =============================================================================

$written = 0;
foreach ($texts as $path => $text) {
    if (preg_match_all('/sha(?:256|384|512)-[A-Za-z0-9+\/]{40,}={0,2}/', $text, $found, PREG_OFFSET_CAPTURE) === 0) {
        continue;
    }
    $rel = substr($path, strlen($root) + 1);
    foreach ($found[0] as [$value, $offset]) {
        $written++;
        if (isset($compared[$rel][$value]) === true) {
            continue;
        }
        $bad++;
        $line = substr_count($text, "\n", 0, $offset) + 1;
        echo "FAIL — {$rel}:{$line}: the fingerprint {$value} was never compared with a real file, because this test could not tell which file it belongs to. Write the tag in a form checks 1 to 3 can read, or teach this test the new form.\n";
    }
}
echo "Fingerprints written in the code: {$written}.\n";

// A run that found nothing to check proved nothing.
if (count($names) === 0 || $literal === 0) {
    $bad++;
    echo "FAIL — found " . count($names) . " Asset pairs and {$literal} literal tags; expected both to be above zero, so the scan itself is broken\n";
}

echo "Summary: {$matched} matched, {$bad} wrong, {$notChecked} not checked.\n";
if ($bad > 0) {
    echo "FAIL — at least one fingerprint is wrong (or a pair is incomplete).\n";
    exit(1);
}
if ($notChecked > 0) {
    echo "NOT CHECKED — {$notChecked} file(s) could not be downloaded, so this run proved nothing about them. Run it again with internet access.\n";
    exit(1);
}
echo "PASS — every fingerprint was checked against its real file, and the run reached its end.\n";
exit(0);
