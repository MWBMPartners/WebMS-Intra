<?php
// Path: public_html/sitemap.php
/**
 * -----------------------------------------------------------------------------
 * Sitemap — the list of pages a search engine may look at 🗺️
 * -----------------------------------------------------------------------------
 * Served at /sitemap.xml (the address is registered in tblRoutes; this file is
 * never opened directly, because the web server refuses any address ending in
 * .php).
 *
 * WHAT A SITEMAP IS FOR
 * ---------------------
 * A sitemap is a plain list of the pages on a website that the site's owner
 * would like a search engine to look at, with a note of when each was last
 * changed. Search engines find pages by following links; a sitemap is a way of
 * saying "here they all are" so nothing is missed, and "this one changed
 * yesterday" so time is not wasted re-reading pages that have not.
 *
 * It is a request, not an instruction. A search engine is free to ignore it,
 * and being in a sitemap does not make a page appear in search results.
 *
 * THE MOST IMPORTANT THING ABOUT THIS PARTICULAR ONE
 * --------------------------------------------------
 * **This portal is private by default, and almost nothing in it should ever be
 * listed here.** It holds members' names, addresses, giving records, pastoral
 * notes and children's details. A sitemap that listed the wrong page would be
 * actively inviting a search engine to come and read it.
 *
 * So this file works the safe way round. It does NOT list every page that
 * happens to be reachable without signing in. It lists a SHORT, DELIBERATE set
 * of pages, named one at a time below, each one of which somebody has decided
 * is genuinely meant for the public.
 *
 * Of the 562 addresses this portal answers, 80 do not require signing in — and
 * most of those still have no business in a sitemap. They include the pages
 * that receive submitted forms, the scheduled jobs that run in the background,
 * tracking images inside newsletters, the addresses that serve photographs as
 * raw bytes, and the sign-in page itself. Listing any of those would be
 * pointless at best.
 *
 * IF INDEXING IS SWITCHED OFF, THIS RETURNS "NOT FOUND"
 * -----------------------------------------------------
 * A site only appears in search engines if an administrator has turned
 * `site.allowIndexing` on. It is off by default. While it is off this address
 * answers "page not found", rather than handing over a list of pages while
 * simultaneously telling crawlers to stay away. Saying two opposite things is
 * how mistakes happen.
 *
 * WHICH STANDARD THIS FOLLOWS
 * ---------------------------
 * The sitemaps.org protocol, version 0.9 — the format Google, Bing and the
 * others all read. Its rules, and how each is met here:
 *
 *   • UTF-8, and every value escaped for XML.        — htmlspecialchars() below
 *   • Web addresses written out in full, including
 *     https:// and the site's own name.              — absoluteUrl() below
 *   • At most 50,000 addresses, and at most 50MB.    — capped, and reported
 *   • <lastmod> in the format from the W3C's
 *     "Date and Time Formats" note.                  — date('c')
 *
 * A copy of the official schema for this format is kept beside this file, and
 * `tools/sitemap-selftest.php` checks what this produces against it.
 *
 * @package   Portal\Public
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://www.sitemaps.org/protocol.html
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Site;

// -----------------------------------------------------------------------------
// 🚦 Is this site meant to be found by search engines at all?
// -----------------------------------------------------------------------------
// Off by default. While it is off, there is no sitemap — not an empty one, not
// a partial one. An empty sitemap is a slightly odd thing to publish; "there is
// nothing here" is clearer and gives nothing away about what the site contains.

$allowIndexing = (string) (App::settings('site.allowIndexing') ?? 'false') === 'true';

if ($allowIndexing === false) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo "This site is not published to search engines, so it has no sitemap.\n"
       . "An administrator can change that under Admin settings.\n";
    exit();
}

// -----------------------------------------------------------------------------
// 🔗 Building a complete web address
// -----------------------------------------------------------------------------

/**
 * 🌐 Turn a path into the full web address a search engine needs.
 *
 * The sitemap standard requires every address to be written out in full —
 * "https://example.org/calendar", never "/calendar". A search engine reading
 * this file may have arrived from anywhere and has no way to fill in the rest.
 *
 * The site's own address is taken from the request, because this portal is
 * designed to be installed on somebody else's hosting under their own name, and
 * nothing in the code can know in advance what that will be.
 *
 * @param string $path A path beginning with a slash, such as "/calendar".
 *
 * @return string The complete address.
 */
function portalAbsoluteUrl(string $path): string
{
    $https  = isset($_SERVER['HTTPS']) === true && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https === true ? 'https' : 'http';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host . $path;
}

/**
 * 📅 Put a date into the format the sitemap standard asks for.
 *
 * The standard wants the format described in the W3C's "Date and Time Formats"
 * note. PHP's date('c') produces exactly that.
 *
 * @param string|null $stamp A date and time from the database, or null.
 *
 * @return string The formatted date, or an empty string if there was none or it
 *                could not be read. An empty string means the entry is written
 *                without a "last changed" note, which is allowed.
 */
function portalSitemapDate(?string $stamp): string
{
    if ($stamp === null || trim($stamp) === '') {
        return '';
    }
    $time = strtotime($stamp);
    if ($time === false) {
        return '';
    }
    return date('c', $time);
}

// -----------------------------------------------------------------------------
// 📋 The pages that are always listed
// -----------------------------------------------------------------------------
// Named ONE AT A TIME, deliberately. This is the whole point of the file: it is
// an allowlist, not a filter over everything that happens to be public.
//
// Before adding an address here, ask three questions:
//   1. Is it genuinely meant for people who are not members?
//   2. Does it show a page, rather than receive a form or serve a file?
//   3. Would it be reasonable for it to appear in a search result?
// If any answer is no, it does not belong here.
//
// "changefreq" and "priority" are deliberately absent. Both are part of the
// standard, and both are ignored by every major search engine — Google has said
// so publicly. Including them adds bytes and implies a precision nobody honours.

const SITEMAP_STATIC_PAGES = [
    // What is on, and when
    '/calendar',
    '/calendar/submit',

    // Guides. These are genuinely useful to somebody deciding whether to join,
    // and they contain nothing private.
    '/help',
    '/help/getting-started',
    '/help/faq',

    // Public-facing parts of the organisation's own activity
    '/photos',
    '/projects',
    '/recordings/podcast',
    '/prayer-requests/anonymous',
    '/decision-card',

    // Things somebody might reasonably search for
    '/visit',
    '/attend',

    // The privacy information. Worth being findable — somebody looking for how
    // their information is handled should not have to hunt for it.
    '/privacy',
    '/privacy/policy',
];

// -----------------------------------------------------------------------------
// 🗃️ Gather everything, static and from the database
// -----------------------------------------------------------------------------

/** @var array<int, array{loc: string, lastmod: string}> */
$entries = [];

foreach (SITEMAP_STATIC_PAGES as $path) {
    $entries[] = ['loc' => portalAbsoluteUrl($path), 'lastmod' => ''];
}

$db     = App::db();
$siteId = Site::id();

// 📅 Public events, each at its own page.
//
//    Three conditions, and every one matters:
//      isPublic = 1        the organisation marked it as public. An internal
//                          meeting must never reach a search engine.
//      status = published  drafts are not finished and may say anything.
//      isDeleted = 0       an event somebody removed.
//
//    Only events that have not yet finished. A sitemap full of last year's
//    events wastes a search engine's time and the reader's.
try {
    $stmt = $db->prepare(
        'SELECT eventSlug, updatedAt '
        . 'FROM tblEvents '
        . 'WHERE siteID = ? AND isPublic = 1 AND status = "published" AND isDeleted = 0 '
        . '  AND eventSlug IS NOT NULL AND eventSlug <> "" '
        . '  AND (endDateTime IS NULL OR endDateTime >= NOW()) '
        . 'ORDER BY startDateTime ASC '
        . 'LIMIT 5000'
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($row = $rs->fetch_assoc()) {
            $entries[] = [
                'loc'     => portalAbsoluteUrl('/e/' . rawurlencode((string) $row['eventSlug'])),
                'lastmod' => portalSitemapDate($row['updatedAt'] ?? null),
            ];
        }
        $stmt->close();
    }
} catch (\Throwable $e) {
    // A missing column or an older database must not break the sitemap. Fewer
    // entries is a small loss; a sitemap that returns an error teaches a search
    // engine to stop asking for it altogether, which is a much bigger one.
    // Deliberately swallowed, and deliberately not logged as an error — this is
    // an expected shape of failure on an older database, not a fault.
    unset($e);
}

// -----------------------------------------------------------------------------
// ✂️ The size limits in the standard
// -----------------------------------------------------------------------------
// At most 50,000 addresses in one file. A site large enough to exceed that needs
// several files and an index pointing at them, which this does not yet do — so
// the list is cut short and a note is written into the file saying so. Silently
// dropping pages would be worse: nobody would ever know why they were missing.

$limitReached = count($entries) > 50000;
if ($limitReached === true) {
    $entries = array_slice($entries, 0, 50000);
}

// -----------------------------------------------------------------------------
// 📝 Write it out
// -----------------------------------------------------------------------------

header('Content-Type: application/xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=3600');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

if ($limitReached === true) {
    echo '<!-- This site has more public pages than one sitemap may hold '
       . '(the standard allows 50,000). The list below is the first 50,000. -->' . "\n";
}

echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

foreach ($entries as $entry) {
    echo '  <url>' . "\n";
    echo '    <loc>' . htmlspecialchars($entry['loc'], ENT_QUOTES | ENT_XML1, 'UTF-8') . '</loc>' . "\n";
    if ($entry['lastmod'] !== '') {
        echo '    <lastmod>' . htmlspecialchars($entry['lastmod'], ENT_QUOTES | ENT_XML1, 'UTF-8') . '</lastmod>' . "\n";
    }
    echo '  </url>' . "\n";
}

echo '</urlset>' . "\n";
