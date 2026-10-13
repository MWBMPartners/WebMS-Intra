<?php
// Path: _apps/admin/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin Dashboard 🛡️
 * -----------------------------------------------------------------------------
 * Central admin hub showing summary cards for key system metrics:
 *   - Recent errors count
 *   - Active users count
 *   - Recent activity entries
 *   - Pending migrations
 *   - System information (PHP version, DB version, environment)
 *
 * Only accessible to users with isAdmin=1 or isRootAdmin=1.
 *
 * WHAT WAS WRONG BEFORE (issue #522, fixed 20 September 2026)
 * -------------------------------------------------------------
 * On a portal with more than one organisation, every one of the five counts
 * below queried the WHOLE installation with no organisation condition at all
 * — so an administrator of organisation A, looking at their own dashboard, saw
 * organisation B's errors, activity and members mixed in with their own, with
 * nothing on screen to say so. `admin/errors/index.php` and
 * `admin/activity/index.php` already scope a non-umbrella administrator to
 * their own organisation; this page simply never learned to.
 *
 * THE FIX
 * -------
 * On a SINGLE-organisation portal the installation IS the organisation, so
 * nothing changes — the unscoped queries are already correct and scoping them
 * "for tidiness" would be actively wrong: `tblErrors.siteID` and
 * `tblActivityLogs.siteID` are NULL for pre-bootstrap rows and for
 * AccountGuard's own security records, and adding `siteID = ?` would silently
 * drop those from the only administrator who exists to see them.
 *
 * On a MULTI-organisation portal every card counts the OPEN organisation, the
 * card subtitles say which organisation that is, and — only for a global
 * ("umbrella") administrator, so the two figures can never be confused — a
 * small "All organisations" line appears underneath using today's old,
 * unscoped queries. `App::isUmbrellaAdmin()` is used (not `App::isRootAdmin()`)
 * because that is the same word `errors/index.php` and `activity/index.php`
 * already use for the same distinction, so all three pages agree on who is
 * "global" here.
 *
 * WHY NOT `AccountGuard::memberScopeSql()`
 * -----------------------------------------
 * That helper deliberately returns an EMPTY scope for a global administrator
 * (so they can see everyone when the page is asking "may this administrator
 * reach this account"). Used here it would put installation-wide numbers
 * under one organisation's name on the global administrator's own dashboard —
 * exactly the mislabelling #522 says must not happen. So this page writes its
 * own `siteID = ?` conditions instead of reusing that helper.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.5.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/522
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\AccountGuard;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Channel;
use Portal\Core\Mailer;
use Portal\Core\MailQueue;
use Portal\Core\MailRoutes;
use Portal\Core\PortalAddress;
use Portal\Core\ReservedKeys;
use Portal\Core\Router;
use Portal\Core\Site;

// 📌 Page metadata for the template system
$pageTitle   = 'Admin Dashboard';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => ''];

// 🛡️ Admin access check
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// -----------------------------------------------------------------------------
// 🌐 Which organisation this dashboard is about, and who is looking at it.
//    Read once, at the top, because every card below depends on both.
// -----------------------------------------------------------------------------
$siteId     = Site::id();
$singleOrg  = AccountGuard::isSingleOrganisation();
$isUmbrella = App::isUmbrellaAdmin();
$orgName    = (string) (Site::branding('name') ?? '');

// -----------------------------------------------------------------------------
// 📊 Gather summary data for dashboard cards
// -----------------------------------------------------------------------------

// 🔴 Recent errors (last 24 hours) — this organisation on a multi-org portal,
//    the whole installation on a single-org one (see the file header).
$errorCount24h = 0;
if ($singleOrg === true) {
    $stmt = $mysqli->prepare(
        'SELECT COUNT(*) AS cnt FROM tblErrors WHERE createdAt >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
    );
} else {
    $stmt = $mysqli->prepare(
        'SELECT COUNT(*) AS cnt FROM tblErrors WHERE siteID = ? AND createdAt >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
    );
}
if ($stmt !== false) {
    if ($singleOrg === false) {
        $stmt->bind_param('i', $siteId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $errorCount24h = (int) ($row['cnt'] ?? 0);
    $stmt->close();
}

// 🔴 Total errors
$errorCountTotal = 0;
if ($singleOrg === true) {
    $stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM tblErrors');
} else {
    $stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM tblErrors WHERE siteID = ?');
}
if ($stmt !== false) {
    if ($singleOrg === false) {
        $stmt->bind_param('i', $siteId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $errorCountTotal = (int) ($row['cnt'] ?? 0);
    $stmt->close();
}

// 👥 Active members — on a multi-org portal, an account with an ACTIVE
//    membership row for THIS organisation; on a single-org portal, every
//    active account on the installation (unchanged from before #522).
$userCountActive = 0;
if ($singleOrg === true) {
    $stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM tblUsers WHERE isActive = 1');
} else {
    $stmt = $mysqli->prepare(
        'SELECT COUNT(*) AS cnt FROM tblUsers u WHERE u.isActive = 1 AND EXISTS ('
        . 'SELECT 1 FROM tblUserSites us WHERE us.userID = u.userID AND us.siteID = ? AND us.isActive = 1)'
    );
}
if ($stmt !== false) {
    if ($singleOrg === false) {
        $stmt->bind_param('i', $siteId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $userCountActive = (int) ($row['cnt'] ?? 0);
    $stmt->close();
}

// 👥 Total members — "everyone who has ever belonged here" on a multi-org
//    portal (any membership row, active or ended), every account on the
//    installation on a single-org one.
$userCountTotal = 0;
if ($singleOrg === true) {
    $stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM tblUsers');
} else {
    $stmt = $mysqli->prepare(
        'SELECT COUNT(*) AS cnt FROM tblUsers u WHERE EXISTS ('
        . 'SELECT 1 FROM tblUserSites us WHERE us.userID = u.userID AND us.siteID = ?)'
    );
}
if ($stmt !== false) {
    if ($singleOrg === false) {
        $stmt->bind_param('i', $siteId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $userCountTotal = (int) ($row['cnt'] ?? 0);
    $stmt->close();
}

// 📋 Recent activity (last 24 hours)
$activityCount24h = 0;
if ($singleOrg === true) {
    $stmt = $mysqli->prepare(
        'SELECT COUNT(*) AS cnt FROM tblActivityLogs WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
    );
} else {
    $stmt = $mysqli->prepare(
        'SELECT COUNT(*) AS cnt FROM tblActivityLogs WHERE siteID = ? AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
    );
}
if ($stmt !== false) {
    if ($singleOrg === false) {
        $stmt->bind_param('i', $siteId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $activityCount24h = (int) ($row['cnt'] ?? 0);
    $stmt->close();
}

// -----------------------------------------------------------------------------
// 🌍 "All organisations" figures — a GLOBAL administrator on a MULTI-org
//    portal only. Run only when they can matter, so a scoped card is never
//    even tempted to fall back to these by accident.
// -----------------------------------------------------------------------------
$allOrgErrors24h   = null;
$allOrgErrorsTotal = null;
$allOrgActive       = null;
$allOrgTotal        = null;
$allOrgActivity24h  = null;
if ($isUmbrella === true && $singleOrg === false) {
    $stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM tblErrors WHERE createdAt >= DATE_SUB(NOW(), INTERVAL 24 HOUR)');
    if ($stmt !== false) {
        $stmt->execute();
        $allOrgErrors24h = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();
    }
    $stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM tblErrors');
    if ($stmt !== false) {
        $stmt->execute();
        $allOrgErrorsTotal = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();
    }
    $stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM tblUsers WHERE isActive = 1');
    if ($stmt !== false) {
        $stmt->execute();
        $allOrgActive = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();
    }
    $stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM tblUsers');
    if ($stmt !== false) {
        $stmt->execute();
        $allOrgTotal = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();
    }
    $stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM tblActivityLogs WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)');
    if ($stmt !== false) {
        $stmt->execute();
        $allOrgActivity24h = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();
    }
}

// 🔧 Pending migrations count
$pendingMigrations = 0;
$appliedMigrations = [];
$stmt = $mysqli->prepare('SELECT filename AS migrationFile FROM tblMigrations');
if ($stmt !== false) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $appliedMigrations[] = $r['migrationFile'];
    }
    $stmt->close();
}

// 📂 Count SQL files in the migrations directory
$sqlDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_sql';
$sqlFiles = [];
if (is_dir($sqlDir) === true) {
    $scan = scandir($sqlDir);
    if ($scan !== false) {
        foreach ($scan as $file) {
            if (str_ends_with($file, '.sql') === true && $file !== 'full_schema.sql') {
                $sqlFiles[] = $file;
            }
        }
    }
}
$pendingMigrations = 0;
foreach ($sqlFiles as $file) {
    if (in_array($file, $appliedMigrations, true) === false) {
        $pendingMigrations++;
    }
}

// 🖥️ System info
//    The database line used to read the raw version string straight off the
//    connection and print it with the word "MySQL" in front. That was wrong on
//    a MariaDB server, and it said nothing about whether the version still
//    receives security fixes. Portal\Core\DbServer answers both, and is the
//    same code the installation wizard, the health page and the Server
//    Information page use — so all four always agree.
$phpVersion = PHP_VERSION;
$dbInfo     = \Portal\Core\DbServer::inspect($mysqli);
$dbVersion  = trim($dbInfo['engine'] . ' ' . $dbInfo['version']);

// 🔒 Is the database connection strict, as the portal asks each time it
//    connects? (Strict means a value that does not fit its column is refused
//    with an error, not stored changed.) Read back from the database here
//    rather than assumed. When it is not what was asked for, the alert below
//    says so in words: the portal keeps running in that case, so this page is
//    one of the places that has to make sure somebody notices.
$dbStrictness = \Portal\Core\DbServer::strictness($mysqli);
// 🧭 Which copy of the portal this is, and HOW that was decided
//    (_core/Channel.php). WHAT WAS WRONG: this read `PORTAL_ENV ??
//    'production'` and the badge below compared it with 'production'. The
//    channel is never that word (the live channel is 'prod'), so the live
//    portal never got its green badge.
$portalEnv       = App::env();
$channelSource   = defined('PORTAL_ENV_SOURCE') === true ? (string) PORTAL_ENV_SOURCE : '';
$channelInWords  = $portalEnv === 'prod' ? 'live' : $portalEnv;
// The web folder's own name, for the note below. Only its last part: never
// the path, which would tell a reader how the server is laid out.
$webFolderName   = basename((string) PORTAL_WEBROOT);

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 🛡️ Admin Dashboard -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-shield-halved me-2"></i>Admin Dashboard</h1>
</div>

<?php
// ⬆️ "Finish the upgrade" (#499). When new files have been uploaded and NO
// database upgrade is waiting, the portal stays open, so nothing else on
// screen says there is a button to press; and until it is pressed every
// request asks the database whether an upgrade is waiting. The words have
// ONE home, Maintenance::upgradeNotice(), which the health page and the
// Upgrade page also use, so the three cannot come to differ. It is empty
// when the recorded version is not behind, and asks the database nothing
// then.
//
// WHO SEES IT: a global administrator only. The notice says "open the
// Upgrade page", and that page refuses everybody else, so shown to an
// administrator of one organisation it would be advice they cannot follow.
$upgradeNotice = $isUmbrella === true ? \Portal\Core\Maintenance::upgradeNotice() : '';
?>
<?php if ($upgradeNotice !== ''): ?>
<div class="alert alert-warning" role="alert" id="admin-upgrade-notice"><?php echo htmlspecialchars($upgradeNotice, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<?php
// 🔒 #515 — warn staff when an organisation's key is the same as an address
// the portal itself relies on (Portal\Core\ReservedKeys explains what
// "reserved" means and why an existing clash is left working rather than
// renamed automatically). Shown in EVERY detection mode, not only when
// address prefixes are actually in use — this alert is advice for the one
// person who can act on it, not a monitor light, so it is useful even
// before a portal ever switches multisite on. A failure here must NEVER
// break this page (#508 was exactly this shape of fault — an admin page
// crashing part way through drawing itself) — any exception simply leaves
// the alert absent, same as "nothing is reserved".
$keyClashes = [];
try {
    $keyClashes = ReservedKeys::clashingSites($mysqli);
} catch (\Throwable) {
    $keyClashes = [];
}
// 🔭 A site administrator (not a global one) only ever sees their OWN
// organisation's figures on this page — the #522 scoping this file already
// applies to every card below — so only their own organisation's clash, if
// it has one, belongs here too. A global administrator sees every clash,
// because only a global administrator can do anything about any of them.
if ($isUmbrella === false) {
    $keyClashes = array_values(array_filter(
        $keyClashes,
        static fn (array $clash): bool => $clash['siteID'] === $siteId
    ));
}
$keysLiveNow = ReservedKeys::keysAreAddresses();
?>
<?php
// 🧭 The channel note. Shown only when nobody WROTE DOWN which copy of the
// portal this is: it was worked out from the web folder's name, or simply
// assumed to be live (owner's rule, 12 October 2026: see _core/Channel.php).
// Amber, not red: the portal is working, and on a real live portal the
// assumption is right. It is here because the same assumption on a test copy
// leaves that copy open to the public, and nothing else on screen says so.
//
// WHO SEES IT: everybody who can open this page, which refuses anybody who
// is not an administrator at the top of this file. No narrower than that,
// because /health already tells any visitor the same one word. It names the
// web folder but never its path.
//
// The last sentence, about a shared _core folder, is load-bearing: where
// several copies share one, a file would speak for all of them, so "create
// the file" is the wrong advice there and must not stand alone.
$showChannelNote = ($channelSource === Channel::SOURCE_ASSUMED || $channelSource === Channel::SOURCE_FOLDER);
?>
<?php if ($showChannelNote === true): ?>
<div class="alert alert-warning" role="alert" id="portal-channel-note" data-channel-source="<?php echo htmlspecialchars($channelSource, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($channelSource === Channel::SOURCE_ASSUMED): ?>
    <h2 class="h6 alert-heading mb-2"><i class="fa-solid fa-triangle-exclamation me-1"></i> This portal has not been told which copy it is</h2>
    <p class="mb-1 small">Nothing says whether this is the live portal or a test copy (beta, alpha or dev), so it is being treated as the <strong>live</strong> portal: error details are hidden, and the staff-only sign-in for test copies is off. If this is a test copy, anybody who has its address can reach it.</p>
    <?php else: ?>
    <h2 class="h6 alert-heading mb-2"><i class="fa-solid fa-triangle-exclamation me-1"></i> Which copy this is was worked out from the web folder&rsquo;s name</h2>
    <p class="mb-1 small">This is being treated as the <strong><?php echo htmlspecialchars($channelInWords, ENT_QUOTES, 'UTF-8'); ?></strong> copy because its web folder is named <code><?php echo htmlspecialchars($webFolderName, ENT_QUOTES, 'UTF-8'); ?></code>. That works, but only for as long as the folder keeps that exact name: renamed, or moved to hosting that calls the folder something else, this copy would be treated as the live portal.</p>
    <?php endif; ?>
    <p class="mb-1 small">To settle it, ask whoever looks after the hosting to create a plain text file named <code>.channel</code> beside the <code>_core</code> folder, holding one word on its first line: <code>live</code>, <code>beta</code>, <code>alpha</code> or <code>dev</code>. Setting <code>PORTAL_ENV</code> to that word on the server does the same. A word the portal does not know counts as not being there.</p>
    <p class="mb-0 small"><strong>Where several copies share one <code>_core</code> folder, do not create the file:</strong> it would speak for every copy. Keep the web folders named <code>public_html</code>, <code>public_html_beta</code> and <code>public_html_dev</code>, or give each its own <code>PORTAL_ENV</code>.</p>
</div>
<?php endif; ?>
<?php
// 🌐 The portal's own web address (#500). Two notices, and at most one of
// them at a time.
//
//   NOT SET (or stored as something unusable, which the portal ignores):
//   every link in an e-mail is then built from the address of whatever
//   request caused the e-mail. This is the notice that gets the address
//   set; until then a password-reset link can be pointed at a stranger's
//   site.
//
//   SET, BUT NOT WHERE YOU ARE: links for this organisation begin with one
//   address and this page was reached at another. That is how a portal that
//   has moved, or a mistyped address, shows itself: the e-mails still go and
//   their links open nothing.
//
// WHO SEES THEM: everybody who can open this page (administrators only, see
// the top of the file), because every organisation's e-mails are affected.
// Only a global administrator can change the address, so only they are
// offered a way to; anybody else is told whom to ask.
//
// WHAT THE FIRST NOTICE SAYS, AND WHY IT IS BUILT FROM PARTS. It has to read
// true in every state, and it did not (independent check, 12 October 2026):
//   - "a visitor can choose that" was left out in "own address each" mode
//     whatever the default organisation held, although a visitor CAN choose
//     the host name there when the default organisation has no address of
//     its own. PortalAddress::visitorCanChooseHost() now decides, beside the
//     code that builds the links.
//   - the "not usable" wording ran straight into "Until it is, a link…",
//     a sentence written for "has not been set". Each wording now has its
//     own "until": set, or corrected.
//
// THE "USE THIS ADDRESS" BUTTON ends the risk in one press: it sends the
// address this page was reached at to the card's own save handler
// (admin/settings/organisation/save), which checks the form token, that the
// sender is a global administrator and the address itself, and writes the
// activity-log line. Nothing is saved from this page. The button is left
// out, with the reason in words, when:
//   - the reader is not a global administrator (the handler would refuse);
//   - the address this page was reached at is not one PortalAddress accepts
//     (fromRequest() gives '' for a Host header that is not a plain host
//     name), so there is nothing that could be saved;
//   - in "own address each" mode, the default organisation has no address of
//     its own and this page was reached at ANOTHER organisation's own
//     address. The default organisation uses the portal's address as its
//     own, so saving this one would point its links at the wrong
//     organisation, and nothing afterwards would notice.
// WHAT THE BUTTON CANNOT KNOW: whether the address in use is the one people
// should reach the portal at. Somebody who reached this page by an odd name
// (a server's own name, an address from inside the building) would save
// that. The notice shows the address beside the button for that reason, and
// the second notice below appears afterwards for anybody who arrives by a
// different one.
//
// Everything comes from PortalAddress, which reads the setting and judges
// it. It does not throw: a read that failed is the state "unreadable", and
// that shows no notice here (the Errors card and the log carry it).
$addressState    = PortalAddress::state();
$addressHere     = PortalAddress::fromRequest();
$addressForOrg   = PortalAddress::base($siteId);
$ownAddressEach  = Site::isMultisiteEnabled() === true && Site::detectionMode() === Site::MODE_HOST;
$addressUnusable = ($addressState === PortalAddress::STATE_UNUSABLE);
$addressNotSet   = ($addressState === PortalAddress::STATE_NOT_SET || $addressUnusable === true);
$addressDiffers  = ($addressState === PortalAddress::STATE_SET && $addressHere !== '' && $addressForOrg !== $addressHere);
$addressPage     = htmlspecialchars(Router::url('admin/settings/organisation') . '#web-addresses', ENT_QUOTES, 'UTF-8');
$hostCanBeChosen = PortalAddress::visitorCanChooseHost();
// "Until it is set" or "Until it is corrected": the two wordings of the first sentence each need their own.
$addressUntil    = $addressUnusable === true ? 'Until it is corrected' : 'Until it is set';
$addressVerb     = $addressUnusable === true ? 'correct' : 'set';
$addressDone     = $addressUnusable === true ? 'corrected' : 'set';
// Another organisation's own address, where saving it would be wrong (the third case above).
$addressIsAnothers = ($ownAddressEach === true && $hostCanBeChosen === true && $siteId !== PortalAddress::DEFAULT_SITE_ID);
$addressOffer      = ($isUmbrella === true && $addressHere !== '' && $addressIsAnothers === false);
?>
<?php if ($addressNotSet === true): ?>
<?php // No <div> inside this one: tools/portal-address-selftest.php reads the notice up to its first closing </div>. ?>
<div class="alert alert-warning small text-break" role="alert" id="portal-address-not-set">
    <i class="fa-solid fa-triangle-exclamation me-1"></i>
    <?php if ($addressUnusable === true): ?>
    <strong>What is stored as the portal's web address is not a usable address, so it is being ignored.</strong>
    <?php else: ?>
    <strong>The portal's web address has not been set.</strong>
    <?php endif; ?>
    <?php if ($hostCanBeChosen === true): ?>
    <?php echo $addressUntil; ?>, a link in an e-mail (a password reset, an invitation) is built from the address of whatever request caused the e-mail, and a visitor can choose that.
    <?php else: ?>
    <?php echo $addressUntil; ?>, links in e-mails carry each organisation's own address, but whether they begin with http or https, and any port, comes from whatever request caused the e-mail.
    <?php endif; ?>
    <?php if ($isUmbrella === false): ?>
    Ask a global administrator to <?php echo $addressVerb; ?> it at Settings, Organisation.
    <?php elseif ($addressOffer === true): ?>
    You reached this page at <code><?php echo htmlspecialchars($addressHere, ENT_QUOTES, 'UTF-8'); ?></code>. If that is the address people should use, one press sets it.
    <form method="post" action="<?php echo htmlspecialchars(Router::url('admin/settings/organisation/save'), ENT_QUOTES, 'UTF-8'); ?>" class="d-flex flex-wrap align-items-center gap-2 mt-2 mb-0" id="portal-address-use-this">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="form" value="web-addresses">
        <input type="hidden" name="portalAddress" value="<?php echo htmlspecialchars($addressHere, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" class="btn btn-warning btn-sm"><i class="fa-solid fa-check me-1"></i>Use this address</button>
        <a href="<?php echo $addressPage; ?>" class="alert-link">or type a different one at Settings, Organisation</a>
    </form>
    <?php elseif ($addressHere === ''): ?>
    It cannot be <?php echo $addressDone; ?> in one press from here, because the address you reached this page at is not a plain host name the portal can store.
    <a href="<?php echo $addressPage; ?>" class="alert-link">Type it at Settings, Organisation</a>.
    <?php else: ?>
    You reached this page at <code><?php echo htmlspecialchars($addressHere, ENT_QUOTES, 'UTF-8'); ?></code>, which is this organisation's own address and not the portal's. The default organisation has no address of its own and uses the portal's, so it cannot be <?php echo $addressDone; ?> in one press from here.
    <a href="<?php echo $addressPage; ?>" class="alert-link">Type it at Settings, Organisation</a>.
    <?php endif; ?>
</div>
<?php elseif ($addressDiffers === true): ?>
<div class="alert alert-warning small text-break" role="alert" id="portal-address-differs">
    <i class="fa-solid fa-triangle-exclamation me-1"></i>
    <?php if ($addressForOrg === ''): ?>
    <strong>This organisation has no address of its own stored</strong>, so e-mails about it are sent without links.
    <?php else: ?>
    <strong>Links in e-mails begin with <code><?php echo htmlspecialchars($addressForOrg, ENT_QUOTES, 'UTF-8'); ?></code></strong>,
    but you reached this page at <code><?php echo htmlspecialchars($addressHere, ENT_QUOTES, 'UTF-8'); ?></code>. If the second one is right, the first needs correcting.
    <?php endif; ?>
    <?php if ($isUmbrella === true): ?>
    <a href="<?php echo $addressPage; ?>" class="alert-link">Check it at Settings, Organisation</a><?php echo $ownAddressEach === true ? ', and this organisation\'s own address at Admin, Organisations' : ''; ?>.
    <?php else: ?>
    Ask a global administrator to check it.
    <?php endif; ?>
</div>
<?php endif; ?>
<?php
// ✉️ Two notices about the mail server's certificate (#592, part 4), for a
// GLOBAL administrator only: the mail route is the installation's, and only
// they can do anything about either.
//
//   MAIL IS HELD: the certificate failed its check, so nothing is going out,
//   password-reset links included. This is how the global administrator is
//   told WITHOUT e-mail, which is the one thing that cannot reach them now.
//
//   CHECKING IS OFF, OR ONE CERTIFICATE IS ACCEPTED: the standing warning.
//   It is read from what is STORED (MailRoutes::standingWarning()), on every
//   load, so it shows to every global administrator, whoever set it and
//   however, until checking is back on. It says when it was set, and who
//   set it when the activity log still vouches for that. It is drawn
//   WHATEVER MAIL ROUTE IS IN USE: "off" stays stored, and in force for a
//   "Send test" through the SMTP mailbox, after another route is chosen.
//   (It used to vanish then, with checking still off.)
//
// Both can show at once: one certificate is accepted, and the server has
// started showing another. "Mail is held" is drawn only while SMTP is the
// route in use. A record that cannot be read shows nothing here; the health
// page says so.
$mailHeld        = null;
$mailUnchecked   = null;
$mailHeldCount   = 0;
if ($isUmbrella === true) {
    try {
        $mailZone      = (string) (Site::current()['timezone'] ?? 'UTC');
        $mailUnchecked = MailRoutes::standingWarning($mysqli, $mailZone);
        if (Mailer::provider() === MailRoutes::SMTP) {
            $mailHeld = MailRoutes::certificateOnRecord($mysqli, $mailZone);
        }
        $mailHeldCount = $mailHeld === null ? 0 : MailQueue::counts($mysqli)['held'];
    } catch (\Throwable $e) {
        error_log('The admin dashboard could not read the mail route\'s record: ' . $e->getMessage());
    }
}
$mailPage = htmlspecialchars(Router::url('admin/integrations/email'), ENT_QUOTES, 'UTF-8');
?>
<?php if ($mailHeld !== null): ?>
<div class="alert alert-danger small text-break" role="alert" id="mail-certificate-failed">
    <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
    <strong>No e-mail is going out: the mail server's certificate failed its check.</strong>
    Every message is being held (<?php echo (int) $mailHeldCount; ?> so far), password-reset links included. This notice is here because an e-mail could not reach you.
    What the check found: <?php echo htmlspecialchars($mailHeld['words'], ENT_QUOTES, 'UTF-8'); ?>
    First seen <?php echo htmlspecialchars($mailHeld['since'], ENT_QUOTES, 'UTF-8'); ?>.
    <a href="<?php echo $mailPage; ?>" class="alert-link">See the certificate, and what can be done, at Admin, Integrations, Email</a>.
</div>
<?php endif; ?>
<?php if ($mailUnchecked !== null): ?>
<div class="alert alert-<?php echo $mailUnchecked['mode'] === MailRoutes::CHECK_OFF ? 'danger' : 'warning'; ?> small text-break" role="alert" id="mail-certificate-unchecked" data-mail-warning="<?php echo $mailUnchecked['mode'] === MailRoutes::CHECK_OFF ? 'checking-off' : 'one-certificate'; ?>">
    <i class="fa-solid fa-shield-halved me-1" aria-hidden="true"></i>
    <strong><?php echo htmlspecialchars($mailUnchecked['headline'], ENT_QUOTES, 'UTF-8'); ?>.</strong>
    <?php echo htmlspecialchars($mailUnchecked['line'], ENT_QUOTES, 'UTF-8'); ?>
    <a href="<?php echo $mailPage; ?>" class="alert-link">Open the e-mail page</a>.
</div>
<?php endif; ?>
<?php if ($dbStrictness['state'] !== 'ok'): ?>
<!-- 🔒 Shown only when the database connection is not running under the
     strictness the portal asks for. Red when it is not strict at all (values
     are being changed quietly); amber when it is strict but not the exact
     setting, or the database would not say. -->
<div class="alert alert-<?php echo $dbStrictness['state'] === 'crit' ? 'danger' : 'warning'; ?>" role="alert">
    <h2 class="h6 alert-heading mb-2"><i class="fa-solid fa-triangle-exclamation me-1"></i> <?php echo htmlspecialchars($dbStrictness['headline'], ENT_QUOTES, 'UTF-8'); ?></h2>
    <p class="mb-1 small"><?php echo htmlspecialchars($dbStrictness['detail'], ENT_QUOTES, 'UTF-8'); ?></p>
    <p class="mb-0 small"><a href="<?php echo htmlspecialchars(Router::url('admin/system-info'), ENT_QUOTES, 'UTF-8'); ?>">Admin &rarr; Server Information</a> shows the setting this connection is running under.</p>
</div>
<?php endif; ?>
<?php if ($keyClashes !== []): ?>
<div class="alert alert-warning" role="alert">
    <h2 class="h6 alert-heading mb-2"><i class="fa-solid fa-triangle-exclamation me-1"></i> An organisation key is reserved</h2>
    <?php foreach ($keyClashes as $clash): ?>
    <?php $clashKey = htmlspecialchars($clash['siteKey'], ENT_QUOTES, 'UTF-8'); ?>
    <p class="mb-1 small">
        <strong><?php echo htmlspecialchars($clash['siteName'], ENT_QUOTES, 'UTF-8'); ?></strong>
        (<code><?php echo $clashKey; ?></code>)
        — <?php echo htmlspecialchars(ReservedKeys::describe($clash['siteKey'], $clash['kinds']), ENT_QUOTES, 'UTF-8'); ?>
        <?php if ($keysLiveNow === true): ?>
        Its pages are taking over /<?php echo $clashKey; ?>/ now.
        <?php else: ?>
        It would take over /<?php echo $clashKey; ?>/ if the portal were switched to address prefixes.
        <?php endif; ?>
        <?php if ($isUmbrella === true): ?>
        Change the key at <a href="<?php echo htmlspecialchars(Router::url('admin/sites'), ENT_QUOTES, 'UTF-8'); ?>">Admin &rarr; Sites</a> (Edit).
        Links already shared under /<?php echo $clashKey; ?>/ will stop working when it changes.
        <?php else: ?>
        Ask a global administrator to change it.
        <?php endif; ?>
    </p>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- 📊 Summary Cards -->
<div class="row g-4 mb-4">
    <!-- 🔴 Errors Card -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card border-danger h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="card-subtitle mb-2 text-danger">
                            Errors (24h)<?php echo $singleOrg === false ? ' — ' . htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8') : ''; ?>
                        </h6>
                        <h2 class="card-title mb-0"><?php echo $errorCount24h; ?></h2>
                        <small class="text-muted"><?php echo number_format($errorCountTotal); ?> total</small>
                        <?php if ($allOrgErrors24h !== null): ?>
                            <div class="text-muted small mt-1">All organisations: <?php echo number_format($allOrgErrors24h); ?> / <?php echo number_format((int) $allOrgErrorsTotal); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="fs-1 text-danger opacity-25">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-transparent border-danger">
                <a href="/admin/errors" class="text-danger text-decoration-none small">
                    View Error Log <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- 👥 Users Card -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card border-primary h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="card-subtitle mb-2 text-primary">
                            Active Members<?php echo $singleOrg === false ? ' — ' . htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8') : ''; ?>
                        </h6>
                        <h2 class="card-title mb-0"><?php echo $userCountActive; ?></h2>
                        <small class="text-muted"><?php echo number_format($userCountTotal); ?> total</small>
                        <?php if ($allOrgActive !== null): ?>
                            <div class="text-muted small mt-1">All organisations: <?php echo number_format($allOrgActive); ?> / <?php echo number_format((int) $allOrgTotal); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="fs-1 text-primary opacity-25">
                        <i class="fa-solid fa-users"></i>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-transparent border-primary">
                <a href="/admin/users" class="text-primary text-decoration-none small">
                    Manage Users <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- 📋 Activity Card -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card border-success h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="card-subtitle mb-2 text-success">
                            Activity (24h)<?php echo $singleOrg === false ? ' — ' . htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8') : ''; ?>
                        </h6>
                        <h2 class="card-title mb-0"><?php echo number_format($activityCount24h); ?></h2>
                        <small class="text-muted">audit log entries</small>
                        <?php if ($allOrgActivity24h !== null): ?>
                            <div class="text-muted small mt-1">All organisations: <?php echo number_format($allOrgActivity24h); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="fs-1 text-success opacity-25">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-transparent border-success">
                <a href="/admin/activity" class="text-success text-decoration-none small">
                    View Activity Log <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    <?php
    // 🔧 Migrations card. Shown to a global administrator only (#499): since
    //    13 October 2026 Admin, Migrations refuses everybody else, and a card
    //    that leads to a refusal is a dead end. The same goes for the
    //    "Migrations" button further down and the entry in the menu.
    ?>
    <?php if ($isUmbrella === true): ?>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card border-warning h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="card-subtitle mb-2 text-warning">Pending Migrations</h6>
                        <h2 class="card-title mb-0"><?php echo $pendingMigrations; ?></h2>
                        <small class="text-muted"><?php echo count($appliedMigrations); ?> applied</small>
                    </div>
                    <div class="fs-1 text-warning opacity-25">
                        <i class="fa-solid fa-database"></i>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-transparent border-warning">
                <a href="/admin/migrations" class="text-warning text-decoration-none small">
                    Run Migrations <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- 🖥️ System Information -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="fa-solid fa-server me-2"></i>System Information</h5>
    </div>
    <div class="card-body">
        <div class="portal-data-list">
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold">Environment</div>
                <div class="col-12 col-md-8">
                    <span class="badge bg-<?php echo ($portalEnv === 'prod') ? 'success' : (($portalEnv === 'dev') ? 'warning' : 'info'); ?>">
                        <?php echo htmlspecialchars($portalEnv, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
            </div>
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold">PHP Version</div>
                <div class="col-12 col-md-8"><?php echo htmlspecialchars($phpVersion, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold">Database</div>
                <div class="col-12 col-md-8">
                    <?php echo htmlspecialchars($dbVersion, ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ($dbInfo['state'] !== 'ok'): ?>
                        <a href="/admin/system-info" class="badge bg-warning text-decoration-none ms-1">Needs a look</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold">Portal Version</div>
                <div class="col-12 col-md-8"><?php echo htmlspecialchars(App::version(), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold">Server Time</div>
                <div class="col-12 col-md-8"><?php echo htmlspecialchars(date('Y-m-d H:i:s T'), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- 🔗 Quick Links -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><i class="fa-solid fa-link me-2"></i>Admin Quick Links</h5>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/errors" class="btn btn-outline-danger w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-triangle-exclamation fa-lg"></i>
                    <span class="small">Error Log</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/activity" class="btn btn-outline-success w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-clock-rotate-left fa-lg"></i>
                    <span class="small">Activity Log</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/users" class="btn btn-outline-primary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-users fa-lg"></i>
                    <span class="small">Users</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <!-- 🏷️ #516: roles held per organisation — grant on the Users
                     page, rename/add here. New link, so it uses Site::url()
                     rather than the bare "/admin/roles" the tiles around it
                     still use (#536, not fixed by this package). -->
                <a href="<?php echo htmlspecialchars(Site::url('admin/roles'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-primary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-user-tag fa-lg"></i>
                    <span class="small">Roles</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <!-- 👥 #517: this organisation's user groups (committees and
                     working groups) — create, rename, retire, and who is in
                     each. Site::url(), as the Roles tile above. -->
                <a href="<?php echo htmlspecialchars(Site::url('admin/groups'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-primary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-people-group fa-lg"></i>
                    <span class="small">Groups</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <!-- 🏢 #517: this organisation's departments — what expense
                     claims are charged to, and who approves them. -->
                <a href="<?php echo htmlspecialchars(Site::url('admin/departments'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-primary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-building fa-lg"></i>
                    <span class="small">Departments</span>
                </a>
            </div>
            <?php // A global administrator only: the page refuses everybody else (#499). ?>
            <?php if ($isUmbrella === true): ?>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/migrations" class="btn btn-outline-warning w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-database fa-lg"></i>
                    <span class="small">Migrations</span>
                </a>
            </div>
            <?php endif; ?>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/settings" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-gear fa-lg"></i>
                    <span class="small">Settings</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/integrations" class="btn btn-outline-info w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-plug-circle-check fa-lg"></i>
                    <span class="small">Integrations</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/captcha" class="btn btn-outline-warning w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-robot fa-lg"></i>
                    <span class="small">Captcha</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/release-notes" class="btn btn-outline-info w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-clipboard-list fa-lg"></i>
                    <span class="small">Release Notes</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/email-templates" class="btn btn-outline-primary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-envelope fa-lg"></i>
                    <span class="small">Email Templates</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/maintenance/retention" class="btn btn-outline-warning w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-broom fa-lg"></i>
                    <span class="small">Retention</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <!-- 🧾 Roll-out package A2b: each organisation's rule for
                     how long giving records keep a donor's name after the
                     donor has asked to be erased. Beside "Retention" because
                     that sweep is what acts on the rule. A new link, so it
                     uses Site::url(), as the Roles tile above does. -->
                <a href="<?php echo htmlspecialchars(Site::url('admin/settings/giving-records'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-primary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-receipt fa-lg"></i>
                    <span class="small">Giving records</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/system-info" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-server fa-lg"></i>
                    <span class="small">Server Info</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/api-docs" class="btn btn-outline-info w-100 d-flex flex-column align-items-center gap-1 py-3" target="_blank" rel="noopener">
                    <i class="fa-solid fa-book fa-lg"></i>
                    <span class="small">API Docs</span>
                </a>
            </div>
            <?php if (App::isUmbrellaAdmin() === true): ?>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/sites" class="btn btn-outline-dark w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-sitemap fa-lg"></i>
                    <span class="small">Sites</span>
                </a>
            </div>
            <?php endif; ?>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/settings" class="btn btn-outline-info w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-sliders fa-lg"></i>
                    <span class="small">Old Settings</span>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- 🔗 More Admin Screens — the AppRegistry marketplace + reporting/workflow/
     compliance screens below have no other entry point in the app (they were
     reachable only by typing the URL directly). Sectioned into its own card
     so the primary Quick Links grid above stays short (#gap-fix D3). -->
<div class="card mt-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="fa-solid fa-ellipsis me-2"></i>More Admin Screens</h5>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/apps" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-cubes fa-lg"></i>
                    <span class="small">Apps</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/reports" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-chart-bar fa-lg"></i>
                    <span class="small">Reports</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/reports/builder" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-table-list fa-lg"></i>
                    <span class="small">Report Builder</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/workflows" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-diagram-project fa-lg"></i>
                    <span class="small">Workflows</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/audit" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-shield-halved fa-lg"></i>
                    <span class="small">Audit Trail</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/sms" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-comment-sms fa-lg"></i>
                    <span class="small">SMS</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/transcription" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-closed-captioning fa-lg"></i>
                    <span class="small">Transcription</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/safeguarding/dbs" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-user-shield fa-lg"></i>
                    <span class="small">Safeguarding</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/decision-cards" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-hand-holding-heart fa-lg"></i>
                    <span class="small">Decision Cards</span>
                </a>
            </div>
        </div>
    </div>
</div>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
