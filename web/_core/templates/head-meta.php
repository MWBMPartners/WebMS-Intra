<?php
// Path: _core/templates/head-meta.php
/**
 * -----------------------------------------------------------------------------
 * Shared <head> description, canonical address and preview card 🔗
 * -----------------------------------------------------------------------------
 * Writes the tags that decide what a page looks like when somebody shares or
 * saves its address: a description, a canonical address, and the Open Graph
 * and Twitter tags that chat apps, e-mail clients and collaboration tools read
 * to draw a preview card. Until #569 the portal wrote none of them, so a link
 * pasted into a conversation showed as a bare address and nothing else.
 *
 * HOW TO USE IT. Set $pageTitle, and $pageDescription where the page can say
 * something better than the fallback, then include this file inside <head>:
 *
 *     $pageTitle       = 'Sign in';
 *     $pageDescription = 'Sign in to the member portal.';
 *     require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates'
 *           . DIRECTORY_SEPARATOR . 'head-meta.php';
 *
 * WHY IT IS ITS OWN FILE, AND NOT PART OF header.php
 * -----------------------------------------------------------------------------
 * `header.php` draws the whole page furniture — navbar, breadcrumbs, the
 * content container — so a page that must not have any of that prints its own
 * <head> instead. Those pages are the ones that matter most here: the sign-in
 * page, the forgotten-password pages, the public visitor form, anonymous
 * prayer requests, accepting an invitation and unsubscribing are all pages a
 * STRANGER sees, and every one of them prints its own <head>. Putting these
 * tags only in header.php would therefore have missed every page whose
 * preview card anybody is likely to see.
 *
 * The alternative — pasting the same twelve tags into each of those files —
 * was rejected: the next person to change the wording would have had to find
 * them all, and the check in tools/audit-checks/check_page_head_tags.py could
 * only have compared copies against each other rather than against one
 * original.
 *
 * NO COUNT IS GIVEN ON PURPOSE. Counting them by searching the code gives a
 * different answer depending on how the search is written, and three
 * reasonable attempts gave 27, 15 and 12. A file's doctype may sit inside an
 * e-mail body built as a PHP string (which is not a page head at all), and
 * several pages print their own <head> for the main view AND include
 * header.php for an error branch, so "own head" is not even a property of a
 * whole file. The list that matters is the allow-list in the check script,
 * which names each exempt file and why.
 *
 * It is written to be SELF-CONTAINED. Every value it needs it works out
 * itself, using `??=` so that nothing is computed twice when header.php has
 * already worked it out. That is what lets a page with no furniture include it
 * without first setting up anything.
 *
 * WHAT IT CANNOT DO
 *   - It cannot write a good description. The fallback names the page and the
 *     organisation, which is honest and useful, but a page that can say
 *     something specific should set $pageDescription.
 *   - It cannot help a page that never includes it. That is what the check
 *     script is for.
 *   - It does not write <title>. The pages that print their own <head>
 *     already write their own, and moving those here would have changed the
 *     wording on 27 pages in a package about appearance.
 *
 * @package   Portal\Core\Templates
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// 🏷️ The page and the organisation. `??=` so that header.php, which has
//    already worked these out for its own tags, is not asked twice.
$pageTitle ??= 'Portal';
$siteName  ??= \Portal\Core\Site::branding('name')
    ?? \Portal\Core\App::settings('site.name')
    ?? 'Portal';

// 📝 One line saying what this page is.
//
// THE FALLBACK IS BUILT FROM THE PAGE'S OWN TITLE on purpose, rather than
// being one sentence about the portal: "Expenses on Example Church" tells a
// reader which page they are looking at, whereas one description repeated
// across every page tells them nothing — and a description that is identical
// everywhere is one of the things that makes a site look generated.
$pageDescription = trim((string) ($pageDescription ?? ''));
if ($pageDescription === '') {
    $pageDescription = $pageTitle . ' at ' . $siteName . '.';
}

// 🏠 The whole address of THIS page, for <link rel="canonical"> and og:url.
//
// Built by PortalAddress, which is the ONE place allowed to decide the address
// in a link that leaves the portal (#500), from Router::extractPath(), which
// has ALREADY had the organisation's first part taken off it. That matters:
// passing an address that still carried the first part would print it twice,
// which is the "never join two built addresses" trap of #536.
//
// WHY IT IS LEFT OUT WHEN A VISITOR CAN CHOOSE THE HOST NAME: with no address
// set, PortalAddress falls back to the Host header of the request, and whoever
// sent the request chose that. A canonical address built from it would tell
// search engines — and every tool drawing a preview card — that this page
// really lives at a name an outsider picked, which is worth more to them than
// to us. No tag is better than a tag pointing somewhere else.
// PortalAddress::visitorCanChooseHost() is the one place that answers this; it
// is deliberately not worked out again here (#500).
$canonicalAddress = \Portal\Core\PortalAddress::visitorCanChooseHost() === false
    ? \Portal\Core\PortalAddress::absolute(\Portal\Core\Router::extractPath())
    : '';

// 🖼️ The picture in the preview card.
//
// It must be a RASTER picture: every service that draws these cards refuses
// SVG, and this portal's icons are mostly SVG. So the tag is written only
// where the brand in use really has a PNG on disk, and left out completely
// otherwise. A card with no picture looks ordinary; a card with a picture that
// will not load looks broken, which is the opposite of what #569 is for.
//
// BE EXACT ABOUT THE COUNT, because a first version of this note was not:
// THREE of the seven brand folders hold a PNG of some kind (churchms,
// webms-intra and webms), but only TWO hold the `icon-512.png` this code
// looks for — webms's single PNG is an animated emblem, which is not it. So
// five brands get no picture in a preview card. They are #656, to be drawn
// properly rather than machine-converted from the SVGs, because a badly
// converted logo is exactly the kind of detail this package exists to remove.
//
// It is a WHOLE address and nothing else. The card is drawn by a service
// somewhere else, which has no page to read a relative address against, so
// "/assets/…" is ignored by all of them. Where there is no address to build
// from, the preview block is left out altogether rather than written with half
// of it missing.
//
// Joining base() to a path typed here is NOT the "two built addresses" trap of
// #536: that trap is gluing together two addresses which each already carry
// the organisation's first part, and a file under /assets/ carries none — the
// web server answers it directly, the same way, for every organisation.
//
// WHAT IT CANNOT DO: tell whether the picture is a sensible SHAPE. These cards
// are usually drawn at about 1200 by 630 and the portal's picture is a square
// 512, so it appears as a small square thumbnail rather than a wide banner.
// That is why twitter:card below says "summary" and not "summary_large_image":
// asking for the wide card with a square picture is what produces a stretched,
// half-empty banner.
// `??=` again: header.php works this out already, for the favicon.
$activeAssetFolder ??= (static function (): string {
    $industry = (string) (\Portal\Core\App::settings('portal.industry') ?? '');
    $presets  = (array) (require PORTAL_CORE . DIRECTORY_SEPARATOR . 'brand-defaults.php');
    $preset   = $presets[$industry] ?? $presets[''] ?? [];
    return (string) ($preset['assetFolder'] ?? 'webms-intra');
})();
$headMetaImagePath = '/assets/images/brandkit/assets/' . $activeAssetFolder . '/icon-512.png';
$headMetaImage     = (
    $canonicalAddress !== ''
    && is_file(PORTAL_WEBROOT . str_replace('/', DIRECTORY_SEPARATOR, $headMetaImagePath)) === true
)
    ? \Portal\Core\PortalAddress::base() . $headMetaImagePath
    : '';
?>
    <!-- 📝 What this page is, in one line (#569). -->
    <meta name="description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
<?php if ($canonicalAddress !== ''): ?>
    <!-- 🏠 The one address this page should be known by (#569). Left out
         altogether while a visitor could choose the host name — see the note
         beside $canonicalAddress in this file. -->
    <link rel="canonical" href="<?php echo htmlspecialchars($canonicalAddress, ENT_QUOTES, 'UTF-8'); ?>">

    <!-- 🔗 The preview card a chat app, e-mail client or collaboration tool
         draws when somebody shares this address (#569). Written only when
         there is a trustworthy whole address to put in og:url, because a card
         with no address in it is worse than no card at all. og:image is left
         out where the brand in use has no raster picture (#656). -->
    <meta property="og:type"        content="website">
    <meta property="og:site_name"   content="<?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:title"       content="<?php echo htmlspecialchars($pageTitle . ' - ' . $siteName, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:url"         content="<?php echo htmlspecialchars($canonicalAddress, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:locale"      content="<?php echo htmlspecialchars(str_replace('-', '_', \Portal\Core\I18n::locale()), ENT_QUOTES, 'UTF-8'); ?>">
<?php   if ($headMetaImage !== ''): ?>
    <meta property="og:image"       content="<?php echo htmlspecialchars($headMetaImage, ENT_QUOTES, 'UTF-8'); ?>">
<?php   endif; ?>
    <!-- "summary" and not "summary_large_image": the portal's picture is a
         square icon, and the wide card stretches a square into a half-empty
         banner. -->
    <meta name="twitter:card"        content="summary">
    <meta name="twitter:title"       content="<?php echo htmlspecialchars($pageTitle . ' - ' . $siteName, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
<?php   if ($headMetaImage !== ''): ?>
    <meta name="twitter:image"       content="<?php echo htmlspecialchars($headMetaImage, ENT_QUOTES, 'UTF-8'); ?>">
<?php   endif; ?>
<?php endif; ?>

<?php
// 🔖 ---------------------------------------------------------------------------
// THE BROWSER-TAB AND HOME-SCREEN ICONS (#680)
// -----------------------------------------------------------------------------
// WHY THEY ARE HERE AND NOT IN header.php, WHERE THEY LIVED UNTIL 30 OCTOBER
// 2026. A page that draws its own `<head>` never reaches header.php, so it got
// no icon link at all — and with none, a browser falls back to `/favicon.ico`
// by convention. There is no such file in the web root and nothing answers
// that address, so every such page cost its visitor a 404.
//
// That was live on the SIGN-IN PAGE, which is the first page any visitor sees:
// `/` redirects to `/login`, and `web/_apps/auth/login/index.php` draws its
// own head. Found by the browser audit (`tools/visual-audit.php`) on its first
// real run, as a console error nobody had been looking for.
//
// Putting them here rather than in a new partial is the whole point: an
// own-head page that is NOT on the exemption list already requires this file,
// and `tools/audit-checks/check_page_head_tags.py` already fails one that does
// not. So one require that is already in place, already guarded, closes the
// class — instead of a second thing for the next own-head page to forget.
//
// THESE ARE OUTSIDE THE BLOCK ABOVE ON PURPOSE. The sharing tags are skipped
// when there is no trustworthy whole address to put in them; an icon needs no
// address and belongs on every page either way.
//
// `??=`: header.php works both of these out at the top of itself, before
// requiring this file, so an ordinary page reuses its answer and a page with
// its own head gets it worked out here. Keep the two spellings identical —
// there are TWO of them, here and in header.php, and no third.
//
// WHAT IS DELIBERATELY NOT FIXED HERE: the pages named in that check's EXEMPT
// list still have no icon, and should not get one from this file. (No count is
// written down on purpose — count them from the code; a number here went stale
// within the hour, saying 13 when there were 12.) They are printouts, e-mail
// previews, wall displays and kiosk screens — no browser tab anybody looks at.
//
// `admin/maintenance/backup.php` is the careful one, and the first version of
// this comment got BOTH halves of it wrong. It is not the whole file that must
// avoid this partial: that file requires `header.php` on both of its ordinary
// paths, so it reaches this one every normal request. It is its CLOSED-DATABASE
// RECOVERY BRANCH, which prints its own `<head>`, that must not. And the reason
// is not the icon code below — `Site::branding()` reads `self::$currentSite`,
// the row already in memory from bootstrap, and touches no database. The reason
// is the PRE-EXISTING read higher up this file: `PortalAddress` queries
// `tblSettings`, which is exactly what that branch exists for the absence of.
// So do not "simplify" by adding the icon lines to that branch on the strength
// of them being harmless — the require as a whole is what it must avoid.
// `$activeAssetFolder` is NOT worked out again here. It is already set
// unconditionally further up this same file, for the sharing picture, so a
// second copy of that closure could never run — and a divergence in dead code
// is invisible. (It was written twice in the first version of this block, which
// is how that was noticed.)
// address-check: allow — the end of a file name under /assets/, which the web
// server answers itself; not a page.
$brandIconPath ??= '/assets/images/brandkit/assets/' . $activeAssetFolder . '/icon.svg';
$siteFavicon   ??= \Portal\Core\Site::branding('favicon') ?? $brandIconPath;
?>
    <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars($siteFavicon, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars($brandIconPath, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="apple-touch-icon" href="/assets/images/icon-192.svg">
