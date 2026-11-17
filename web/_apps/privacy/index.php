<?php
// Path: public_html/privacy/index.php
/**
 * -----------------------------------------------------------------------------
 * Privacy & Data Protection 🇪🇺
 * -----------------------------------------------------------------------------
 * Public-facing page listing the data the portal stores about visitors and
 * registered users, the legal basis, retention windows, and rights under
 * GDPR / UK Data Protection Act 2018. Reads admin-editable settings under
 * the `privacy.*` prefix so each site can drop in their own controller name
 * + contact email without code changes.
 *
 * Closes #47.
 *
 * @package   Portal\Privacy
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ActivityLogRetention;
use Portal\Core\ConsentRetention;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\GeocodeCacheRetention;
use Portal\Core\Site;

$pageTitle   = 'Privacy & Data Protection';
$pageSection = '';
$breadcrumbs = ['Home' => Site::url(''), 'Privacy' => ''];

Auth::ensureSession();

$controllerName  = (string) (App::settings('privacy.controllerName')    ?? '');
$contactEmail    = (string) (App::settings('privacy.contactEmail')      ?? '');
$externalURL     = (string) (App::settings('privacy.policyURL')         ?? '');
$allowDelete     = (App::settings('privacy.allowAccountDelete') ?? 'true') === 'true';

// 🔀 If the admin has set an external policy URL, just redirect.
if ($externalURL !== '') {
    header('Location: ' . $externalURL, true, 302);
    exit();
}

// 📜 How long THIS organisation's activity log is kept (#940; D-117, D-118):
//    a number of days, "never" chosen here, "never" chosen for the whole
//    portal, or "could not be read". Read after the redirect above, so a
//    site sending members to its own policy never asks.
$activityLogFacts = ActivityLogRetention::memberFactsForSite($mysqli, Site::id());
$consentFacts     = ConsentRetention::memberFactsForSite($mysqli, Site::id());

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-3"><i class="fa-solid fa-shield-halved me-2"></i>Privacy &amp; Data Protection</h1>

<?php // #1138 item 10: this notice is for the people who can fix it. A visitor or member cannot, and read it as a fault in the portal. ?>
<?php if ($controllerName === '' && $contactEmail === '' && App::isAdmin() === true): ?>
    <div class="alert alert-warning small">
        <i class="fa-solid fa-triangle-exclamation me-1"></i>
        <strong>Admin notice:</strong> only administrators see this. The Privacy page cannot name
        your organisation as the data controller until <code>privacy.controllerName</code> and
        <code>privacy.contactEmail</code> are set in Admin, Settings.
    </div>
<?php endif; ?>

<p class="lead">
    This page describes what personal data we hold, why we hold it, how long we keep it for,
    and the rights you have under the UK Data Protection Act 2018 / EU GDPR.
</p>

<h2 class="h4 mt-4">Data controller</h2>
<p>
    The data controller is
    <strong><?php echo $controllerName !== '' ? htmlspecialchars($controllerName, ENT_QUOTES, 'UTF-8') : 'the organisation that runs this portal'; ?></strong>.
    <?php if ($contactEmail !== ''): ?>
        Privacy enquiries:
        <a href="mailto:<?php echo htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8'); ?>
        </a>.
    <?php endif; ?>
</p>

<h2 class="h4 mt-4">What we store</h2>
<ul>
    <li><strong>Account data</strong> — name, email, optional phone, hashed password (we never store passwords in plain text).</li>
    <li><strong>Authentication events</strong> — login successes, failures, password-reset requests, 2FA verifications. IP address + user agent for security audit.</li>
    <li><strong>Activity logs</strong> — what you did inside the portal (e.g. submitted a prayer request, recorded attendance, approved an expense). Used for incident investigation and reverting accidental changes.</li>
    <?php // 🔑 #808 round 6: this said only "the provider's 'sub' identifier and the email", while Auth.php keeps the
          //    name and the photo's address too (the Outside companies list says so). ?>
    <li><strong>Linked sign-in accounts</strong> — if you sign in with a Microsoft or Google account, the identifier that company gives your account, and the name, e-mail address and (where it has one) photo address it returns.</li>
    <?php // 🏠 #1037, D-183: a remembered map position of a home is personal data. Worded in ONE
          //    place (GeocodeCacheRetention::privacyWords()), with the period the clear-out uses. ?>
    <li data-geocode-home-notice="1"><strong>Map positions of homes</strong> — <?php echo htmlspecialchars(GeocodeCacheRetention::privacyWords($mysqli), ENT_QUOTES, 'UTF-8'); ?></li>
    <li><strong>Uploaded content</strong> — anything you create inside the portal (event RSVPs, expense receipts, document library uploads, etc.).</li>
</ul>

<h2 class="h4 mt-4">Legal basis</h2>
<ul>
    <li><strong>Contract / Legitimate interest</strong> — your account exists because you're a member or staff member; running the portal is necessary to deliver the service.</li>
    <li><strong>Consent</strong> — for any analytics / marketing communications, we ask separately.</li>
    <li><strong>Legal obligation</strong> — financial records (e.g. expense audit trails) may be retained beyond the standard window where statute requires.</li>
</ul>

<h2 class="h4 mt-4">Retention</h2>
<?php // 📜 #940: the activity log's period for THIS organisation, worded in ONE place
      //    (ActivityLogRetention::privacyPageWords()), from the same reader the
      //    nightly clear-out uses. A read that fails says so and names no figure. ?>
<p data-activity-log-retention="<?php echo htmlspecialchars($activityLogFacts['state'], ENT_QUOTES, 'UTF-8'); ?>">
    <?php echo htmlspecialchars(ActivityLogRetention::privacyPageWords($activityLogFacts), ENT_QUOTES, 'UTF-8'); ?>
</p>
<p>
    <?php // 🗣️ #703 round 6: this printed `privacy.dataRetentionDays` as the logs' period, which is a
          //    different setting from the one the clear-out uses (`audit.retentionDays` /
          //    `errors.retentionDays`), and it ended "any logs that referenced you are detached",
          //    which is untrue: what a log record says is kept as written. Round 7: "your name is
          //    removed" was untrue too - worker records (D-016, D-110, D-111) and gifts kept for tax
          //    keep the name.
          //    #940 (D-117, D-118): the activity log may now be kept for ever, by
          //    the installation's choice or this organisation's, and this
          //    organisation's own administrator sets its period. So the activity
          //    log has its own paragraph above, with the REAL figure for this
          //    organisation from the reader the clear-out uses; the error log,
          //    which has no "never" (D-117 rules that out), keeps its own words
          //    here. The setting names were taken out: a member cannot act on
          //    them, and they read as jargon (the plain-English rule). ?>
    Error logs are deleted by the same nightly clear-out after the number of
    days set for them (a year unless changed), if that scheduled job has been
    set up. Account records remain while your account is active. When your account is erased it is emptied, but some
    records keep your account number on purpose, and some keep your name as
    well (records you made as a worker or volunteer, and gifts kept for tax);
    what a log record says is also kept as it was written &mdash; see the
    privacy notice for what stays.
</p>

<?php // 🍪 #934 (D-166, D-210): how long the record of privacy-notice and cookie choices is kept, from the
      //    reader the nightly clear-out uses. Its own heading, so the pinned "Retention" section above is unchanged. ?>
<h2 class="h4 mt-4">Your privacy-notice and cookie choices</h2>
<p data-consent-retention="<?php echo htmlspecialchars($consentFacts['state'], ENT_QUOTES, 'UTF-8'); ?>">
    <?php echo htmlspecialchars(ConsentRetention::privacyPageWords($consentFacts), ENT_QUOTES, 'UTF-8'); ?>
</p>

<?php // 🌐 #1019 (D-178): every place a network address is kept, and for how long, from the
      //    personal-data catalogue through ONE template shared with the privacy policy. Its own
      //    heading, so the pinned "Retention" section above is unchanged. ?>
<h2 class="h4 mt-4">Network addresses</h2>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'network-addresses.php'; ?>

<?php // 🛂 #808 (D-158): every outside company a page can reach, named, through ONE template shared
      //    with the privacy policy; the words are OutsideServices::SERVICES, ::NAMED and ::USED_BY_ORGANISATION (D-195). ?>
<h2 class="h4 mt-4">Outside companies</h2>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'outside-services-notice.php'; ?>

<h2 class="h4 mt-4">Your rights</h2>
<ul>
    <li><strong>Access</strong> — request a copy of your data via
        <a href="<?php echo htmlspecialchars(Site::url('account/data-export'), ENT_QUOTES, 'UTF-8'); ?>">Account → Data Export</a>.</li>
    <li><strong>Rectification</strong> — update inaccurate data via
        <a href="<?php echo htmlspecialchars(Site::url('account'), ENT_QUOTES, 'UTF-8'); ?>">your Account page</a> or by contacting an admin.</li>
    <li><strong>Erasure</strong> —
        <?php if ($allowDelete === true): ?>
            request deletion via <a href="<?php echo htmlspecialchars(Site::url('account/delete'), ENT_QUOTES, 'UTF-8'); ?>">Account → Delete my account</a>.
        <?php else: ?>
            currently disabled on this site; contact an admin.
        <?php endif; ?>
    </li>
    <li><strong>Portability</strong> — the data export above is a JSON document you can take to another provider.</li>
    <li><strong>Object / Withdraw consent</strong> — at any time, for any processing that's based on consent.</li>
    <li><strong>Complain</strong> — to the ICO (UK) at
        <a href="https://ico.org.uk/" target="_blank" rel="noopener noreferrer">ico.org.uk</a>
        if you believe we're handling your data improperly.</li>
</ul>

<h2 class="h4 mt-4">Cookies</h2>
<p>
    <?php // 🍪 #808 round 6: written from the cookies the code really sets (setcookie() in Auth.php for the
          //    trusted device, privacy/consent.php for the notice; PHP's own session cookie). The form token
          //    and a changed language live IN the session; the light/dark choice is localStorage. ?>
    The portal sets only the cookies it needs to work: a session cookie, which keeps you signed in
    and carries what the portal needs from page to page (such as the token that stops other websites
    sending forms in your name, and your language if you change it); the "trust this device" cookie for
    two-step sign-in, set only if you tick that box; and a cookie that remembers your answer to the
    cookie notice, kept for a year. Your browser's own storage (not a cookie) also holds, for this portal:
    your light or dark display, colour-blind-safe colours, reading mode, and whether you dismissed the offer
    to install the portal as an app, none of which is sent to the portal; the calendar view you last used,
    which the calendar puts into the page address and so is sent to the portal; a code for the livestream
    page in the current tab, sent to the portal while you watch and with each chat message, so that it can
    count who is watching and tell your messages apart; forms you send
    while offline, kept until you are back online and then sent; copies of pages, so they can be shown
    when you are offline; and, on the noticeboard, the poster size and reduced-motion choice you set there,
    which are not sent to the portal.
    The portal sets no tracking or analytics cookies of its own.
    <?php // 🍪 #808 round 1: this said "No third-party tracking cookies", a few headings above the
          //    Outside companies section, which lists companies the reader's browser contacts
          //    directly (video players, the robot check). The portal cannot promise what those
          //    companies do, so it says what it does itself and points at the list. ?>
    Some of the outside companies listed under <em>Outside companies</em> above are contacted by your
    browser directly, such as a video player or the check that you are not a robot; whether they keep
    anything in your browser is up to them, under your browser's settings, and is described in each
    company's own privacy notice.
</p>

<p class="text-muted small mt-5">
    This page is auto-generated from the portal's privacy settings. To customise
    the wording further, point <code>privacy.policyURL</code> at an external
    page and that will replace this one.
</p>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
