<?php
// Path: public_html/robots.php
/**
 * -----------------------------------------------------------------------------
 * robots.txt — what crawlers are asked to do 🤖
 * -----------------------------------------------------------------------------
 * Served at /robots.txt (the address is registered in tblRoutes; this file is
 * never opened directly, because the web server refuses any address ending in
 * .php).
 *
 * WHY THIS IS NOW GENERATED RATHER THAN A PLAIN FILE
 * --------------------------------------------------
 * It used to be a plain text file, and that plain text file said:
 *
 *     User-agent: *
 *     Disallow: /
 *
 * which means "no crawler may look at anything here". Correct for a private
 * portal, and the right default.
 *
 * But there is a setting, `site.allowIndexing`, that an administrator can turn
 * on to publish their site to search engines. Turning it on changed the
 * instructions written INTO each page — and could not change the plain file,
 * because a plain file cannot know about a setting.
 *
 * So a site that opted in ended up saying two opposite things at once: every
 * page said "please index me", while robots.txt said "do not come in at all".
 * Crawlers read robots.txt first and obey it, so **the opt-in did not work**.
 * Somebody could switch indexing on, wait weeks, and never appear anywhere,
 * with nothing to explain why.
 *
 * Generating the file fixes that. The setting now controls both, together.
 *
 * TWO SEPARATE PERMISSIONS, AND THEY ARE NOT THE SAME QUESTION
 * ------------------------------------------------------------
 *   site.allowIndexing    — may ordinary search engines list this site?
 *   site.allowAiIndexing  — may crawlers that gather text to train language
 *                           models take this site's content?
 *
 * Both are off by default, and the second stays off even when the first is
 * turned on. Wanting to be findable on Google is a completely different
 * decision from wanting your members' words used as training material, and an
 * organisation should never be treated as having agreed to the second because
 * it agreed to the first.
 *
 * WHAT IS STILL REFUSED EVEN WHEN A SITE IS PUBLISHED
 * ---------------------------------------------------
 * Being findable does not mean being wide open. The list further down keeps
 * crawlers away from the parts of the portal that are for members and staff,
 * from the addresses that receive submitted forms, and from the scheduled jobs
 * that run in the background. None of those would be useful in a search result,
 * and some would leak who the organisation's members are.
 *
 * A NOTE ON WHAT robots.txt ACTUALLY DOES
 * ---------------------------------------
 * It is a polite request, not a lock. Well-behaved crawlers obey it; nothing
 * makes a badly-behaved one do so. It must never be the only thing keeping
 * something private — that is what signing in is for. Everything genuinely
 * private in this portal is behind a sign-in as well.
 *
 * @package   Portal\Public
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://www.robotstxt.org/robotstxt.html
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=3600');

$allowIndexing   = (string) (App::settings('site.allowIndexing')   ?? 'false') === 'true';
$allowAiIndexing = (string) (App::settings('site.allowAiIndexing') ?? 'false') === 'true';

/**
 * 🤖 Crawlers that gather text to train language models, or to answer questions
 *    directly rather than sending somebody to the site.
 *
 * Kept as a named list rather than a wildcard, because a wildcard cannot
 * distinguish "the search engine that sends people to us" from "the system that
 * reads our content and answers on our behalf". The organisation may reasonably
 * want the first and not the second.
 *
 * This list needs revisiting from time to time. New ones appear; some change
 * their name. Being out of date here means one of them is treated as an
 * ordinary crawler, which is the same position every site was in before any of
 * this existed — not a new exposure, but worth keeping current.
 */
const AI_CRAWLERS = [
    'GPTBot', 'ChatGPT-User', 'OAI-SearchBot',
    'Google-Extended', 'Applebot-Extended',
    'anthropic-ai', 'ClaudeBot', 'Claude-Web',
    'CCBot', 'FacebookBot', 'Meta-ExternalAgent',
    'Bytespider', 'Amazonbot', 'PerplexityBot',
    'YouBot', 'Diffbot', 'ImagesiftBot',
    'Omgilibot', 'Omgili', 'cohere-ai',
    'cohere-training-data-crawler', 'TimpiBot',
];

/**
 * 🚧 Parts of the portal no crawler should look at, even on a published site.
 *
 * Three kinds, and each is here for its own reason:
 *   - Members' and staff areas. Signing in already protects these; this simply
 *     saves a crawler the wasted journey and keeps the addresses out of sight.
 *   - Addresses that RECEIVE something rather than showing a page — forms being
 *     submitted, files being served as raw bytes, tracking pixels in emails.
 *   - Scheduled jobs that run in the background. Never meant for a visitor.
 */
const ALWAYS_DISALLOWED = [
    '/admin/', '/account/', '/settings/', '/auth/',
    '/login', '/logout', '/forgot-password', '/reset-password',
    '/api/', '/api-docs', '/openapi.json',
    '/cron/',
    '/expenses/', '/giving/', '/care/', '/kids/', '/directory/',
    '/leadership/', '/offboarding/', '/small-groups/', '/rota/',
    '/newsletter/track/', '/unsubscribe',
    '/photos/serve', '/noticeboard/media', '/recordings/podcast-media',
    '/widget/', '/worship/display', '/assets/kiosk',
];

echo "# =============================================================================\n";
echo "# What crawlers are asked to do on this site\n";
echo "# =============================================================================\n";
echo "# This file is generated, not typed. It follows the site's own settings, so\n";
echo "# that what it says here always matches what each page says about itself.\n";
echo "#\n";
echo "# Listed in search engines : " . ($allowIndexing === true ? 'yes' : 'no') . "\n";
echo "# Available for AI training: " . ($allowAiIndexing === true ? 'yes' : 'no') . "\n";
echo "#\n";
echo "# An administrator changes these under Admin settings:\n";
echo "#   site.allowIndexing     lets ordinary search engines list this site\n";
echo "#   site.allowAiIndexing   separately allows AI training crawlers\n";
echo "#\n";
echo "# Please note this file is a request, not a lock. Anything genuinely private\n";
echo "# here is behind a sign-in as well, and does not rely on crawlers being polite.\n";
echo "# =============================================================================\n\n";

// -----------------------------------------------------------------------------
// 🤖 The AI crawlers, handled first so their answer is unambiguous.
// -----------------------------------------------------------------------------
echo "# ---------------------------------------------------------------------------\n";
echo "# Crawlers that gather text to train language models, or answer on our behalf\n";
echo "# ---------------------------------------------------------------------------\n";
if ($allowAiIndexing === false) {
    echo "# Refused. This stays refused even when the site IS listed in search\n";
    echo "# engines — being findable and being training material are different\n";
    echo "# decisions, and agreeing to one is not agreeing to the other.\n";
}
foreach (AI_CRAWLERS as $bot) {
    echo 'User-agent: ' . $bot . "\n";

    if ($allowAiIndexing === false) {
        echo "Disallow: /\n\n";
        continue;
    }

    // ⚠️ A crawler named in its own group does NOT also follow the
    //    "User-agent: *" group further down. That is how robots.txt works: a
    //    crawler obeys the most specific group that names it, and ignores the
    //    rest entirely.
    //
    //    So simply allowing these bots here would have let them into the
    //    members' areas, the addresses that receive forms, and the background
    //    jobs - all of which the general group carefully keeps everybody else
    //    out of. Permission to read the public pages is not permission to read
    //    everything.
    //
    //    The exclusions therefore have to be repeated inside each permitted
    //    group.
    foreach (ALWAYS_DISALLOWED as $path) {
        echo 'Disallow: ' . $path . "\n";
    }
    echo "Allow: /\n\n";
}

// -----------------------------------------------------------------------------
// 🌐 Everybody else.
// -----------------------------------------------------------------------------
echo "# ---------------------------------------------------------------------------\n";
echo "# Everybody else\n";
echo "# ---------------------------------------------------------------------------\n";
echo "User-agent: *\n";

if ($allowIndexing === false) {
    echo "# This is a private portal and has not been published to search engines.\n";
    echo "Disallow: /\n";
} else {
    echo "# Published. The public pages may be looked at; the members' and staff\n";
    echo "# areas, the addresses that receive forms, and the background jobs may not.\n";
    foreach (ALWAYS_DISALLOWED as $path) {
        echo 'Disallow: ' . $path . "\n";
    }
    echo "Allow: /\n";
}

// -----------------------------------------------------------------------------
// 🗺️ Where the list of pages lives.
// -----------------------------------------------------------------------------
// Only mentioned when the site is actually published. Pointing at a sitemap
// while also saying "do not come in" would be contradictory, and the sitemap
// answers "not found" in that state anyway.
if ($allowIndexing === true) {
    $https  = isset($_SERVER['HTTPS']) === true && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https === true ? 'https' : 'http';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    echo "\n";
    echo "# The list of pages we would like looked at.\n";
    echo 'Sitemap: ' . $scheme . '://' . $host . "/sitemap.xml\n";
}
