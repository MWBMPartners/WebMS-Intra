<?php
// Path: _apps/admin/system-info/phpinfo.php
/**
 * -----------------------------------------------------------------------------
 * Admin — the full PHP report 📋
 * -----------------------------------------------------------------------------
 * Prints PHP's own complete description of itself: every setting, every
 * installed component, the version of each one, and the paths PHP is working
 * from. It is the report a hosting provider asks for when diagnosing an awkward
 * problem, and until now the only way to get it was to upload a stray file to
 * the server by hand — which people then forget to delete.
 *
 * WHY THIS IS A SEPARATE PAGE, AND MORE TIGHTLY RESTRICTED
 * -------------------------------------------------------
 * The Server Information page next to this one describes THIS SITE. This page
 * describes THE WHOLE SERVER. On an install hosting several organisations, an
 * administrator of one of them is not necessarily the person who runs the
 * server, and this report would tell them a great deal about it that has
 * nothing to do with their own organisation.
 *
 * So this page is limited to umbrella administrators — the people who
 * administer the whole installation rather than one organisation within it. On
 * an ordinary single-organisation install the owner IS the umbrella
 * administrator, so in practice nothing is taken away from anybody.
 *
 * -----------------------------------------------------------------------------
 * THREE THINGS ARE REMOVED FROM THE REPORT, AND WHY
 * -----------------------------------------------------------------------------
 * PHP will happily print everything if asked. Three parts of "everything" must
 * never appear on a screen — and the third one is not obvious, so please read
 * it before changing anything here.
 *
 * 1. THE SERVER'S ENVIRONMENT VARIABLES.
 *    Shared hosting frequently passes database passwords and service keys to a
 *    site through environment variables. Printing them would put live
 *    credentials on a web page. Left out by not asking for that section.
 *
 * 2. THE CURRENT REQUEST.
 *    This section prints the browser's cookies, which include the reader's own
 *    session token. Anyone who saw that screen — over a shoulder, or in a
 *    screenshot pasted into a support ticket — could then sign in as them.
 *    That is not a far-fetched worry: pasting this exact report into a support
 *    ticket is the main reason the page exists. Left out by not asking for
 *    that section.
 *
 * 3. THE APACHE SECTION'S OWN TABLES — the non-obvious one.
 *    Not asking for sections 1 and 2 is NOT enough. When PHP runs as an Apache
 *    module, the "apache2handler" entry in the list of installed components
 *    prints two extra tables of its own: "Apache Environment" and "HTTP Headers
 *    Information". Those belong to the components section, so they arrive even
 *    though the environment and request sections were never asked for — and
 *    "HTTP Headers Information" contains the request's Cookie header, which is
 *    the reader's session token. Exactly the leak that leaving out section 2
 *    was supposed to prevent, arriving by a different door.
 *
 *    So the report is captured and those tables are removed from it before
 *    anything is sent to the browser. On hosting where PHP does not run as an
 *    Apache module the tables are simply not there and nothing is removed.
 *
 * ALSO REDACTED, as a second line of defence: the value of any setting whose
 * NAME looks like a secret. PHP prints the value of every configuration setting
 * exactly as it is, masking nothing at all — that was checked against a running
 * server, not assumed. A setting such as `mysqli.default_pw`, or a
 * `sendmail_path` whose arguments carry a password, would otherwise be printed
 * in full. Those values are replaced; the setting NAMES stay visible, since
 * knowing a setting exists is useful and harmless.
 *
 * Be clear about what that second line of defence can and cannot do. Matching
 * on the name catches the settings that announce what they hold, which is most
 * of them. It cannot catch a secret placed in a setting with an innocent name.
 * The reliable protection is the removal of whole sections above, which is
 * structural and cannot miss; this is the backstop, not the main defence.
 *
 * WHY IT DRAWS ITS OWN PAGE
 * -------------------------
 * PHP's report is a complete web page in itself, with its own styling. It
 * cannot be placed inside the portal's normal page frame, so this file sets its
 * own security headers instead of relying on the shared ones. It refuses to be
 * shown inside a frame on another site, and asks search engines to ignore it.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/475
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;

// 🔐 Umbrella administrators only. See the reasoning in the header above.
//    This runs before a single byte of output.
Auth::ensureSession();
Auth::requireLogin();
if (App::isUmbrellaAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

// 🛡️ This page draws its own document rather than using the portal's shared
//    page frame, so it has to set its own security headers.
//
//    PHP's report styles itself with a block of rules written into the page, so
//    the policy below has to permit that ('unsafe-inline' for styles). It
//    contains no scripts at all, so scripts are refused outright — a stricter
//    rule than any other page in this portal manages.
header("Content-Security-Policy: default-src 'none'; "
     . "style-src 'unsafe-inline'; "
     . "img-src data:; "
     . "base-uri 'none'; "
     . "form-action 'none'; "
     . "frame-ancestors 'none'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Content-Type: text/html; charset=utf-8');

/**
 * 🧾 Draw a small standalone page. Used for the two cases where there is no
 *    report to show.
 *
 * @param string $heading The heading, as plain text.
 * @param string $body    One paragraph of explanation, as plain text.
 *
 * @return void
 */
$plainPage = static function (string $heading, string $body): void {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<title>Full PHP report</title></head><body>'
       . '<h1>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h1>'
       . '<p>' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</p>'
       . '<p><a href="/admin/system-info">Back to Server Information</a></p>'
       . '</body></html>';
};

// 🚫 Some hosting providers switch this function off across the whole server.
//    Say so plainly instead of returning a blank page.
if (function_exists('phpinfo') === false) {
    $plainPage(
        'The full PHP report is not available',
        'Your hosting provider has switched off the PHP function that produces this '
        . 'report. That is a server-wide setting and cannot be changed from inside '
        . 'this portal. Everything on the Server Information page still works; only '
        . 'this one report is unavailable.'
    );
    exit();
}

// 📋 Produce the report into memory rather than straight to the browser, so the
//    unsafe parts described in the file header can be taken out of it first.
//
//    Listing the wanted sections explicitly, rather than subtracting from
//    INFO_ALL, means a future version of PHP that adds a new section cannot
//    quietly include it here.
ob_start();
phpinfo(INFO_GENERAL | INFO_CONFIGURATION | INFO_MODULES | INFO_LICENSE);
$report = (string) ob_get_clean();

// 🛑 Make sure we got the HTML form of the report, which is the only form the
//    filtering below understands. A few ways of running PHP produce a plain
//    text report instead, and against plain text the filters would quietly
//    match nothing and pass the whole thing through — including the parts that
//    must never be shown. Refuse rather than guess.
if (strpos($report, '<td class="e">') === false) {
    $plainPage(
        'The full PHP report could not be shown safely',
        'This server produced the report in a plain-text form that this portal cannot '
        . 'safely filter. Parts of that report can contain passwords and your own '
        . 'sign-in token, so nothing is shown rather than risk displaying them. '
        . 'Everything on the Server Information page still works.'
    );
    exit();
}

// 🧹 1. Remove whole tables that must never be shown. See point 3 in the file
//       header — this is what catches the Apache section's own request-headers
//       table, which carries the reader's session cookie.
$forbiddenTables = [
    'Apache Environment',
    'HTTP Headers Information',
    'Environment',
    'Environment Variables',
    'PHP Variables',
];

$filtered = preg_replace_callback(
    '#<table\b.*?</table>#is',
    static function (array $m) use ($forbiddenTables): string {
        foreach ($forbiddenTables as $heading) {
            // phpinfo writes a section heading as its own cell, so the heading
            // text sits between a '>' and a '<'. Matching it that way avoids
            // catching a setting that merely mentions the word.
            if (stripos($m[0], '>' . $heading . '<') !== false) {
                return '<p><em>This section has been left out on purpose. It can contain '
                     . 'passwords and your own sign-in token.</em></p>';
            }
        }
        return $m[0];
    },
    $report
);

// 🧹 2. Redact the value of any setting whose NAME looks like a secret. PHP
//       masks nothing itself — that was checked against a real server, not
//       assumed.
if (is_string($filtered) === true) {
    //    Note honestly what this can and cannot do. Matching on the NAME of a
    //    setting catches the ones that announce themselves, and that is most of
    //    them. It cannot catch a secret sitting in a setting with an innocent
    //    name. It is a second line of defence, not the main one — the main one
    //    is leaving out whole sections, which is structural and cannot miss.
    //
    //    'sendmail' is in the list rather than 'sendmail_path' because the mail
    //    section prints the very same value again under the friendlier label
    //    "Path to sendmail". A pattern written around the setting name alone
    //    redacted one copy and left the other on the page. Match both.
    $secretish = '/(pass|passwd|pwd|_pw\b|\.pw\b|secret|token|credential|apikey|api[_ ]key|private[_ ]?key|sendmail)/i';

    $filtered = preg_replace_callback(
        '#<tr>\s*<td class="e">(.*?)</td>(.*?)</tr>#is',
        static function (array $m) use ($secretish): string {
            if (preg_match($secretish, strip_tags($m[1])) !== 1) {
                return $m[0];
            }
            // Keep the setting name; replace every value cell beside it.
            $values = preg_replace(
                '#<td class="v">.*?</td>#is',
                '<td class="v"><em>hidden</em></td>',
                $m[2]
            );
            if (is_string($values) === false) {
                $values = '<td class="v"><em>hidden</em></td>';
            }
            return '<tr><td class="e">' . $m[1] . '</td>' . $values . '</tr>';
        },
        $filtered
    );
}

// 🛑 Fail closed. If either pass could not run — a report so large it exhausted
//    the pattern matcher's working limits, for instance — show nothing rather
//    than falling back to the unfiltered report. An unfiltered report is
//    exactly the thing this file exists to prevent.
if (is_string($filtered) === false) {
    $plainPage(
        'The full PHP report could not be shown safely',
        'The report was produced, but this portal could not finish removing the parts '
        . 'of it that must not be displayed — the server environment and your own '
        . 'sign-in token. Rather than risk showing those, nothing is shown at all. '
        . 'Everything on the Server Information page still works.'
    );
    exit();
}

echo $filtered;
