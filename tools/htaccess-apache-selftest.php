<?php
// Path: tools/htaccess-apache-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The web root's .htaccess, run by a REAL Apache 🪶🧪 (#493 P4a; D-065)
 * -----------------------------------------------------------------------------
 * Serves a copy of `web/public_html/` — the repository's REAL `.htaccess`,
 * every static file as it is, and every PHP file replaced by a stub that
 * prints its own name — from a real Apache 2.4 with PHP (`php:8.4-apache`,
 * `AllowOverride All`, mod_rewrite on) in a Docker container, and sends it
 * raw HTTP requests. Then it plants each known way of making the deny rule
 * do nothing into the copy's `.htaccess` and requires every one to go red.
 *
 * WHY THIS EXISTS (round 8 of P4a's check). The deny rule refuses (404) every
 * address that would run a PHP file directly except the three entry points.
 * `tools/audit-checks/check_htaccess_php_denied.py` reads the file, and three
 * rounds running a static reading lost to the next shape, each measured on a
 * real Apache 2.4.69: a rule ABOVE `RewriteEngine On` (Apache collects rules
 * in file order whatever line the engine is switched on), and a comment
 * ending in a backslash, which Apache CONTINUES onto the next line and so
 * swallows the directive after it (before the deny rule, or before
 * `RewriteEngine On`). Whether the rule RUNS is behavioural, so it is driven
 * here (D-065). The static check stays as a cheap early warning; THIS is the
 * guard.
 *
 * WHAT IT ASKS
 *   A  every refused form answers 404 (where the rule is what decides) and
 *      NO stub runs for it: a stub's output is "RAN:<its path>:<status>",
 *      where the status is Apache's REDIRECT_STATUS — 404 only when error.php
 *      runs as the 404 page, which is how a refusal SHOULD look. A stub's
 *      SOURCE carries "SOURCE-OF:<its path>", so a file served as text (its
 *      source leaked) is caught too. Forms: every non-entry .php file, with
 *      extra path information, in capitals, the other endings a server may
 *      run as PHP (the copy's Apache is told to run .php5/.php7/.phtml/.phar,
 *      as many hosts do), the entry points WITH extra path information, and
 *      encoded or dotted spellings (%2e, %70, %2f, //, /./, /x/../, a query).
 *   B  every address that must work does, exactly as measured: / and the
 *      clean addresses the router answers (/robots.txt, /sitemap.xml,
 *      /manifest.json, /openapi.json, /assets/, /expenses/submit,
 *      /w/…/events.json, /w/…/door.js, /w/…/page.html and /page.html (#493 P4c), /door-check.txt (#493 P4d), and — since #493 P4b deleted the
 *      folder — /widget and /widget/countdown.js) reach index.php; /api-docs/ and /error.php run their
 *      own files; /offline/ runs the static offline page; /index.php, /assets,
 *      /offline and /api-docs redirect where the server sends them (see the
 *      list); and the static files (/assets/js/portal.js, /sw.js,
 *      /sitemap.xsd) are served byte for byte, with no stub run.
 *   P  the plants, each into the COPY: the three round-8 shapes (a comment
 *      ending in "\" before the deny rule, a pass-through rule above
 *      `RewriteEngine On`, a comment ending in "\" before `RewriteEngine
 *      On`), the round-6 rule (`\.php$`), the rule changed to `^never$`,
 *      `[OR]` on a condition, and `RewriteEngine Off`. Each must turn at
 *      least one A or B check red. One CONTROL must stay green: a rule above
 *      everything whose condition never matches — so "red" means the plant
 *      broke the rule, not that any edit is red.
 *
 * WHAT IT CANNOT SEE, said plainly: a host's own server configuration (it
 * uses php:8.4-apache's, plus AllowOverride All); a host whose Apache is a
 * different version, or that is not Apache at all; and anything the real
 * PHP files do, since they are stubs here (tools/public-door-selftest.php
 * drives the real portal behind PHP's own server, which ignores .htaccess).
 *
 * NEEDS Docker, running, and the php:8.4-apache image (pulled if absent).
 * It REFUSES (exit 2) without them — never a quietly skipped run.
 *
 * USAGE
 *     php tools/htaccess-apache-selftest.php
 *     HTACCESS_SELFTEST_PORT=9499 (the default) chooses the local port.
 *
 * @package   WebMS-Intra
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright All Rights Reserved
 * @version   1.0.0
 */

declare(strict_types=1);

const HAS_IMAGE = 'php:8.4-apache';
/** Every check, counted: a probe list that shrinks must fail, not pass quietly. */
const EXPECTED_CHECKS = 59; // 53, then three B probes for #493 P4b (door.js, /widget, /widget/countdown.js), two for P4c (/w/…/page.html, /page.html), and one for P4d (/door-check.txt)

$repo   = dirname(__DIR__);
$webDir = $repo . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'public_html';
$port   = (int) (getenv('HTACCESS_SELFTEST_PORT') ?: 9499);
$name   = 'htaccess-selftest-' . getmypid();

function refuse(string $why): never
{
    fwrite(STDERR, "REFUSED: {$why}\nNothing was tested. This test needs Docker; it never skips quietly.\n");
    exit(2);
}

// ── What this machine has ──────────────────────────────────────────────────
exec('docker info > /dev/null 2>&1', $o, $rc);
if ($rc !== 0) {
    refuse('docker is not installed or not running.');
}
exec('docker image inspect ' . escapeshellarg(HAS_IMAGE) . ' > /dev/null 2>&1', $o, $rc);
if ($rc !== 0) {
    passthru('docker pull ' . escapeshellarg(HAS_IMAGE), $rc);
    if ($rc !== 0) {
        refuse('the image ' . HAS_IMAGE . ' could not be pulled.');
    }
}
if (is_file($webDir . DIRECTORY_SEPARATOR . '.htaccess') === false) {
    refuse('web/public_html/.htaccess was not found.');
}
$probe = @fsockopen('127.0.0.1', $port, $en, $es, 0.5);
if ($probe !== false) {
    fclose($probe);
    refuse("something is already listening on port {$port}; set HTACCESS_SELFTEST_PORT.");
}

// ── The copy: real static files, real .htaccess, every PHP file a stub ─────
$work = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'htaccess-selftest-' . bin2hex(random_bytes(4));
$root = $work . DIRECTORY_SEPARATOR . 'root';
$conf = $work . DIRECTORY_SEPARATOR . 'zz-portal.conf';
$started = false;
$cleanUp = static function () use (&$started, $name, $work): void {
    if ($started === true) {
        // Stopped, never force-removed: --rm then removes it with nothing left behind.
        exec('docker stop ' . escapeshellarg($name) . ' > /dev/null 2>&1');
        $started = false;
    }
    if (is_dir($work) === true) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() === true ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($work);
    }
};
register_shutdown_function($cleanUp);
if (function_exists('pcntl_async_signals') === true) {
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM, SIGHUP] as $sig) {
        pcntl_signal($sig, static function () use ($cleanUp): void {
            $cleanUp();
            exit(130);
        });
    }
}

$stub = static function (string $rel): string {
    // The output ("RAN:<rel>:<status>") never appears in the source, and the
    // source marker never appears in the output, so a file that RAN and a
    // file SERVED AS TEXT are told apart.
    return "<?php /* SOURCE-OF:{$rel} */ echo 'RAN' . ':{$rel}:' . (\$_SERVER['REDIRECT_STATUS'] ?? '-') . \"\\n\";\n";
};
mkdir($root, 0755, true);
chmod($work, 0755);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($webDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($it as $f) {
    $rel  = str_replace(DIRECTORY_SEPARATOR, '/', substr($f->getPathname(), strlen($webDir) + 1));
    $dest = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if ($f->isDir() === true) {
        @mkdir($dest, 0755, true);
        continue;
    }
    preg_match('/\.(php[0-9]?|phtml|phar)$/i', $rel) === 1 ? file_put_contents($dest, $stub($rel)) : copy($f->getPathname(), $dest);
    chmod($dest, 0644);
}
// Files with the other endings a host may run as PHP.
foreach (['zz-probe.php5', 'zz-probe.php7', 'zz-probe.phtml', 'zz-probe.phar'] as $extra) {
    file_put_contents($root . DIRECTORY_SEPARATOR . $extra, $stub($extra));
    chmod($root . DIRECTORY_SEPARATOR . $extra, 0644);
}
file_put_contents($conf, "<Directory /var/www/html>\n    AllowOverride All\n    Options FollowSymLinks\n</Directory>\n"
    . "# Many hosts run these as PHP too; so does this copy, so the rule is tested against them RUNNING.\n"
    . "<FilesMatch \"\\.(php[0-9]|phtml|phar)$\">\n    SetHandler application/x-httpd-php\n</FilesMatch>\n");
chmod($conf, 0644);
$realHtaccess = (string) file_get_contents($root . DIRECTORY_SEPARATOR . '.htaccess');

// ── The server ─────────────────────────────────────────────────────────────
$cmd = 'docker run --rm -d --name ' . escapeshellarg($name) . ' -p 127.0.0.1:' . $port . ':80'
    . ' -v ' . escapeshellarg($root . ':/var/www/html:ro')
    . ' -v ' . escapeshellarg($conf . ':/etc/apache2/conf-enabled/zz-portal.conf:ro')
    . ' ' . escapeshellarg(HAS_IMAGE) . " sh -c 'a2enmod -q rewrite headers && exec apache2-foreground'";
exec($cmd . ' 2>&1', $out, $rc);
if ($rc !== 0) {
    refuse('the Apache container would not start: ' . implode(' ', $out));
}
$started = true;

/** One raw request: the path is sent EXACTLY as written (no client normalises it). */
$get = static function (string $path) use ($port): array {
    $s = @fsockopen('127.0.0.1', $port, $en, $es, 5.0);
    if ($s === false) {
        return ['status' => 0, 'body' => ''];
    }
    stream_set_timeout($s, 10);
    fwrite($s, "GET {$path} HTTP/1.0\r\nHost: localhost\r\nConnection: close\r\n\r\n");
    $raw = stream_get_contents($s);
    fclose($s);
    $parts = explode("\r\n\r\n", (string) $raw, 2);
    preg_match('#^HTTP/\S+ (\d{3})#', $parts[0], $m);
    preg_match('#^Location:\s*(\S+)#mi', $parts[0], $loc);
    return ['status' => (int) ($m[1] ?? 0), 'body' => $parts[1] ?? '',
        'location' => isset($loc[1]) === true ? (string) parse_url($loc[1], PHP_URL_PATH) : ''];
};
$ready = false;
for ($i = 0; $i < 120; $i++) {
    if ($get('/sitemap.xsd')['status'] === 200) {
        $ready = true;
        break;
    }
    usleep(250000);
}
if ($ready === false) {
    exec('docker logs ' . escapeshellarg($name) . ' 2>&1 | tail -20', $logs);
    refuse('Apache did not answer within 30 seconds: ' . implode(' | ', $logs));
}

// ── The probes ─────────────────────────────────────────────────────────────
// [path, the stub that must NOT run (or null), must the answer be 404?]
$refused = [
    ['/robots.php', 'robots.php', true], ['/robots.php/x', 'robots.php', true], ['/manifest.php/', 'manifest.php', true],
    ['/sitemap.php/a/b', 'sitemap.php', true], ['/openapi.php', 'openapi.php', true],
    ['/offline/index.php', 'offline/index.php', true], ['/offline/index.php/x', 'offline/index.php', true],
    ['/x/y.php', null, true], ['/ROBOTS.PHP', null, true], ['/Robots.Php/x', null, true],
    ['/zz-probe.php5', 'zz-probe.php5', true], ['/zz-probe.php7/x', 'zz-probe.php7', true],
    ['/zz-probe.phtml', 'zz-probe.phtml', true], ['/zz-probe.phtml/x', 'zz-probe.phtml', true], ['/zz-probe.phar', 'zz-probe.phar', true],
    ['/index.php/x', 'index.php', true], ['/api-docs/index.php/x', 'api-docs/index.php', true], ['/error.php/x', 'error.php', true],
    ['/robots%2ephp', 'robots.php', true], ['/robots.ph%70', 'robots.php', true], ['/robots.php%2fx', 'robots.php', false],
    ['//robots.php', 'robots.php', true], ['/./robots.php', 'robots.php', true], ['/assets/../robots.php', 'robots.php', true],
    ['/robots.php?x=1', 'robots.php', true], ['/robots.PHP/x', null, true],
];
// [path, what must answer: a stub's path, 'static:<file>' for a file served
// byte for byte, or 'redirect:<path>' for a redirect there]. MEASURED on
// php:8.4-apache, and each is what the real server does today:
//   /index.php  301 to / (the .htaccess rule that hides the front controller);
//   /assets, /offline, /api-docs  301 to the same with "/" — Apache's own
//     DirectorySlash, because each is a folder, which runs BEFORE the
//     .htaccess rule for the bare /assets address;
//   /assets/  the router (the Asset Tracker's register page);
//   /offline/  offline/index.php itself, through the folder's DirectoryIndex
//     (the deny rule sees /offline/, not /offline/index.php). It is the static
//     offline page; tools/public-door-selftest.php's section F runs it
//     directly and finds no session and nobody named.
$mustWork = [
    ['/', 'index.php'], ['/index.php', 'redirect:/'], ['/api-docs', 'redirect:/api-docs/'], ['/api-docs/', 'api-docs/index.php'],
    ['/api-docs/index.php', 'api-docs/index.php'], ['/error.php?code=404', 'error.php'],
    ['/robots.txt', 'index.php'], ['/sitemap.xml', 'index.php'], ['/manifest.json', 'index.php'],
    ['/openapi.json', 'index.php'], ['/assets', 'redirect:/assets/'], ['/assets/', 'index.php'],
    ['/offline', 'redirect:/offline/'], ['/offline/', 'offline/index.php'], ['/expenses/submit', 'index.php'],
    ['/w/0123456789abcdef01234567/events.json', 'index.php'],
    // #493 P4b: the door's script is never a file on disk (it is kept outside
    // the web root, so only the door's gate serves it), and the old widget
    // folder is DELETED (D-084): /widget and its old script now reach the
    // front controller, where the router has no address for them (the real
    // portal's 404; tools/public-door-selftest.php section W drives that).
    ['/w/0123456789abcdef01234567/door.js', 'index.php'], ['/widget', 'index.php'], ['/widget/countdown.js', 'index.php'],
    // #493 P4c: the page a website frames is never a file on disk either; an
    // address ending in .html must reach the front controller like any other.
    ['/w/0123456789abcdef01234567/page.html', 'index.php'],
    // ... and its form on a public address, which is the bare /page.html.
    ['/page.html', 'index.php'],
    // #493 P4d: the set-up check's address on a public address, never a file
    // on disk either; with a query string, as the check always sends one.
    ['/door-check.txt?n=' . str_repeat('ab', 32), 'index.php'],
    ['/assets/js/portal.js', 'static:assets/js/portal.js'], ['/sw.js', 'static:sw.js'], ['/sitemap.xsd', 'static:sitemap.xsd'],
];

/** Runs every probe; returns [label => why it failed] (empty when all pass). */
$runAll = static function () use ($get, $refused, $mustWork, $root): array {
    $fail = [];
    foreach ($refused as [$path, $target, $want404]) {
        $r   = $get($path);
        $why = [];
        if ($want404 === true && $r['status'] !== 404) {
            $why[] = "answered {$r['status']}, not 404";
        }
        if ($target !== null) {
            if (preg_match('/RAN:' . preg_quote($target, '/') . ':(\S+)/', $r['body'], $m) === 1 && $m[1] !== '404') {
                $why[] = "{$target} RAN (status {$m[1]})";
            }
            if (str_contains($r['body'], 'SOURCE-OF:' . $target) === true) {
                $why[] = "{$target}'s SOURCE was served";
            }
        }
        // Whatever stub ran, only error.php as the 404 page may.
        if (preg_match_all('/RAN:(\S+?):(\S+)/', $r['body'], $all, PREG_SET_ORDER) > 0) {
            foreach ($all as $one) {
                if (($one[1] === 'error.php' && $one[2] === '404') === false && ($target === null || $one[1] !== $target)) {
                    $why[] = "{$one[1]} ran (status {$one[2]})";
                }
            }
        }
        if ($why !== []) {
            $fail['refused ' . $path] = implode('; ', $why);
        }
    }
    foreach ($mustWork as [$path, $want]) {
        $r = $get($path);
        if (str_starts_with($want, 'redirect:') === true) {
            if (($r['status'] !== 301 && $r['status'] !== 302) || $r['location'] !== substr($want, 9)) {
                $fail['works ' . $path] = "answered {$r['status']} to '{$r['location']}', not a redirect to " . substr($want, 9);
            }
            continue;
        }
        if (str_starts_with($want, 'static:') === true) {
            $file = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, substr($want, 7));
            if ($r['status'] !== 200 || $r['body'] !== (string) file_get_contents($file)) {
                $fail['works ' . $path] = "answered {$r['status']}, not the file byte for byte";
            }
            continue;
        }
        if (preg_match('/RAN:' . preg_quote($want, '/') . ':(\S+)/', $r['body'], $m) !== 1 || $m[1] === '404') {
            $fail['works ' . $path] = "answered {$r['status']} without {$want} running ("
                . substr(str_replace("\n", ' ', $r['body']), 0, 60) . ')';
        }
    }
    return $fail;
};

$pass = 0;
$failN = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$failN): void {
    if ($ok === true) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }
    $failN++;
    echo "  ✗ {$label}\n" . ($detail === '' ? '' : "      {$detail}\n");
};

echo "A/B. The real .htaccess on a real Apache\n";
$real = $runAll();
foreach ($refused as [$path]) {
    $check("A refused, and no stub ran: {$path}", isset($real['refused ' . $path]) === false, $real['refused ' . $path] ?? '');
}
foreach ($mustWork as [$path, $want]) {
    $check("B works: {$path} → {$want}", isset($real['works ' . $path]) === false, $real['works ' . $path] ?? '');
}

echo "P. The plants, each into the copy's .htaccess, each required to go red\n";
$htPath = $root . DIRECTORY_SEPARATOR . '.htaccess';
$deny   = 'RewriteRule .* - [R=404,L]';
$first  = '\.(php[0-9]?|phtml|phar)(/|$) [NC]';
$plants = [
    'P1 (round 8, v1) a comment ending in "\" on the line before the deny rule swallows it'
        => [$deny, "# the deny rule \\\n" . $deny],
    'P2 (round 8, v2) a pass-through rule ABOVE RewriteEngine On'
        => [null, "RewriteRule ^ - [L]\n"],
    'P3 (round 8, v3) a comment ending in "\" before RewriteEngine On swallows it'
        => ["\nRewriteEngine On\n", "\n# switch it on \\\nRewriteEngine On\n"],
    'P4 (round 6) the rule matching only addresses ENDING in .php'
        => [$first, '\.php$ [NC]'],
    'P5 the deny rule changed to ^never$' => [$deny, 'RewriteRule ^never$ - [R=404,L]'],
    'P6 [OR] on the first condition' => [$first, '\.(php[0-9]?|phtml|phar)(/|$) [NC,OR]'],
    // Each plant names the DIRECTIVE's own line, never the bare words, which a
    // comment may quote (the .htaccess header does).
    'P7 RewriteEngine Off' => ["\nRewriteEngine On\n", "\nRewriteEngine Off\n"],
];
foreach ($plants as $label => [$old, $new]) {
    if ($old !== null && substr_count($realHtaccess, $old) !== 1) {
        $check($label, false, 'PLANT NOT APPLIED: its text is not in the real .htaccess exactly once');
        continue;
    }
    file_put_contents($htPath, $old === null ? $new . $realHtaccess : str_replace($old, $new, $realHtaccess));
    $red = $runAll();
    file_put_contents($htPath, $realHtaccess);
    $check($label . ' — goes red', $red !== [], $red === [] ? 'NOTHING went red: the test cannot see this dead rule'
        : 'e.g. ' . array_key_first($red) . ': ' . $red[array_key_first($red)]);
    if ($red !== []) {
        echo "      (" . count($red) . " probe(s) red, first: " . array_key_first($red) . ' — ' . $red[array_key_first($red)] . ")\n";
    }
}
// The control: a harmless edit must stay green, or "red" above means nothing.
file_put_contents($htPath, "RewriteCond %{REQUEST_URI} ^/never-requested-by-this-test$\nRewriteRule ^ - [L]\n" . $realHtaccess);
$control = $runAll();
file_put_contents($htPath, $realHtaccess);
$check('P0 CONTROL a rule above everything whose condition never matches — stays GREEN', $control === [],
    $control === [] ? '' : 'went red: ' . array_key_first($control) . ': ' . $control[array_key_first($control)]);

$total = $pass + $failN;
echo "\n{$total} checks: {$pass} passed, {$failN} failed.\n";
if ($total !== EXPECTED_CHECKS) {
    echo 'But ' . EXPECTED_CHECKS . " were expected: a probe or a plant was added or lost without the count changing.\n";
    exit(1);
}
exit($failN === 0 ? 0 : 1);
