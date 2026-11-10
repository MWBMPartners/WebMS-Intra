<?php
// Path: _apps/newsletter/track-click.php
/**
 * -----------------------------------------------------------------------------
 * Newsletter — click counter 📰🔗
 * -----------------------------------------------------------------------------
 * A counted link in a newsletter comes here as
 *   newsletter/track/click?n=<newsletter>&r=<recipient>&l=<token>
 * and is sent on to the address that TOKEN stands for in THAT newsletter's own
 * list of links (`tblNewsletterLink`, written when the newsletter was sent;
 * see Newsletter::linkToken()). When click counting is switched on, the
 * recipient's row is marked as having clicked, exactly as before.
 *
 * Anything else — no token, a token from another newsletter, a made-up one,
 * or a link written before #620 that carries the address itself (`&u=…`) —
 * gets the portal's one "this link cannot be used" page
 * (`_core/templates/link-unusable.php`), saying "This link is no longer
 * valid", with HTTP 404, and is NEVER sent anywhere. Nothing is counted.
 *
 * WHAT WAS WRONG (#620). This page forwarded to any http or https address in
 * `u=`. So anybody could write a link that wore the portal's own address and
 * landed on any site they chose — an "open redirect", which is what makes a
 * phishing link look trustworthy. A click counter has to forward somewhere;
 * it now forwards only to an address the newsletter really contained.
 *
 * WHY 404 AND NOT 410 ("gone"). 410 says the address once worked and was
 * withdrawn. That is true of a link from an older newsletter and untrue of a
 * made-up one, and this page cannot tell them apart; 404 is true of both.
 *
 * LINKS ALREADY SENT. One written before #620 carries `u=` and no token, so it
 * gets this page. Chosen knowingly: honouring it means forwarding to an
 * address the REQUEST names, which is the fault itself. No customer
 * installation exists yet and click counting ships switched off, so no real
 * newsletter carries one (migration 268 says the same).
 *
 * NOTHING NEW IS RECORDED about the person. The address they were sent to is
 * not logged anywhere; only `clickedAt` on their own row, as before.
 *
 * The page is open to anybody (`isProtected = 0`): a person following a link
 * from an e-mail is usually not signed in.
 *
 * @package   Portal\Newsletter
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/269
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/620
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Newsletter;

$newsId = (int) ($_GET['n'] ?? 0);
$recId  = (int) ($_GET['r'] ?? 0);

// 🔗 The ONE source of the address: the newsletter's own list. The request
//    supplies only the token; Newsletter::linkTarget() refuses anything that
//    is not 32 lower-case hex characters, including a list (`l[]=x`).
$target = Newsletter::linkTarget($newsId, $_GET['l'] ?? null);

if ($target === null) {
    // The portal's ONE page for an e-mailed link it cannot act on
    // (link-unusable.php, #569): the same words whatever the reason, so the
    // answer says nothing about which tokens exist.
    http_response_code(404);
    header('Cache-Control: no-store');
    $linkPurpose = 'open a link in one of our newsletters';
    $linkAdvice  = 'This link is no longer valid. If you were expecting to reach something, open the newsletter again, or ask whoever sent it for the link.';
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'link-unusable.php';
    exit();
}

// 📊 Count the click, only for a link that really is this newsletter's.
$settings = App::settings()['newsletter'] ?? [];
if ((string) ($settings['trackClicks'] ?? '0') === '1' && $recId > 0) {
    $db = App::db();
    $stmt = $db->prepare('UPDATE `tblNewsletterRecipient` SET `clickedAt` = COALESCE(`clickedAt`, NOW()) WHERE `recipientID` = ? AND `newsletterID` = ?');
    if ($stmt !== false) {
        $stmt->bind_param('ii', $recId, $newsId);
        $stmt->execute();
        $stmt->close();
    }
}

header('Location: ' . $target, true, 302);
exit();
