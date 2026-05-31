<?php
// Path: public_html/error.php
/**
 * -----------------------------------------------------------------------------
 * Themed Apache-level error dispatcher 🚦
 * -----------------------------------------------------------------------------
 * Apache's ErrorDocument directives route 403/404/500/503 here. We translate
 * the requested code into a Router::renderError() call so the themed
 * templates (web/_core/templates/error-{code}.php) get rendered.
 *
 * Lives outside the framework's routing because Apache hits us BEFORE the
 * front controller can intervene — bootstrap may not have run, or the
 * triggering error may be ABOUT the bootstrap path.
 *
 * @package   Portal
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/243
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$code = (int) ($_GET['code'] ?? 500);
if (in_array($code, [403, 404, 500, 503], true) === false) {
    $code = 500;
}

// 🛡️ Try to bootstrap the framework so we can use the themed templates.
//    If bootstrap itself is broken (which is why we'd be here for 500),
//    fall back to a minimal self-contained HTML page.
$bootstrap = dirname(__DIR__) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';
$rendered = false;

if (is_readable($bootstrap) === true) {
    try {
        require_once $bootstrap;
        if (class_exists('Portal\\Core\\Router') === true) {
            \Portal\Core\Router::renderError($code);
            $rendered = true;
        }
    } catch (\Throwable $e) {
        // 🪞 Bootstrap broken — fall through to the minimal page below so
        //    the user still gets SOMETHING, not a blank screen.
    }
}

if ($rendered === false) {
    http_response_code($code);
    // 🤖 Apache-direct error pages bypass bootstrap so they wouldn't get
    //    the global X-Robots-Tag from there. Explicit denial here (#247).
    header('X-Robots-Tag: noindex, nofollow, noai, noimageai');
    $titles = [
        403 => 'Access Denied',
        404 => 'Page Not Found',
        500 => 'Server Error',
        503 => 'Service Unavailable',
    ];
    $title = $titles[$code] ?? 'Error ' . $code;
    echo '<!doctype html><html lang="en"><head><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
       . '<meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<style>'
       . ':root{--bg:#f7f8fa;--surface:#fff;--text:#1b2330;--muted:#6b7280;--border:#e5e7eb;--primary:#5e6ad2;}'
       . '@media (prefers-color-scheme: dark){:root{--bg:#0f1115;--surface:#161a22;--text:#e8eaf0;--muted:#9aa3b2;--border:#2c3441;--primary:#7b86e8;}}'
       . 'body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--text);text-align:center;padding:4rem 1.25rem;margin:0;line-height:1.55;}'
       . '.card{max-width:480px;margin:0 auto;background:var(--surface);border:1px solid var(--border);border-radius:.75rem;padding:2.5rem 2rem;}'
       . '.code{font-size:3rem;font-weight:600;color:var(--primary);margin-bottom:.5rem;}'
       . 'a{color:var(--primary);text-decoration:none;font-weight:500;}'
       . '</style></head>'
       . '<body><div class="card"><div class="code">' . $code . '</div>'
       . '<h1 style="font-weight:600;margin:0 0 1rem">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
       . '<p style="color:var(--muted);">'
       . ($code === 503 ? 'The portal is temporarily unavailable. Please try again shortly.' : 'Something went wrong.')
       . '</p><p style="margin-top:1.5rem"><a href="/">&larr; Return to Portal</a></p>'
       . '</div></body></html>';
}
