<?php
// Path: public_html/privacy/policy.php
/**
 * Public — Privacy policy page surfacing the GDPR erasure policy.
 *
 * @package   Portal\Privacy
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/235
 */

declare(strict_types=1);

use Portal\Core\ActivityLogRetention;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\GivingRetention;
use Portal\Core\Site;

Auth::ensureSession();

$settings = App::settings();
$portalName = (string) ($settings['site']['name'] ?? 'Portal');
$contact    = (string) ($settings['privacy']['erasureContact'] ?? '');

// 🧾 How long THIS organisation keeps a donor's name on giving records
//    (roll-out package A2b). This used to read one installation-wide number,
//    `privacy.financialRetentionYears`, and print it beside "to comply with
//    HMRC requirements": one country's tax office, stated as fact to every
//    member of every customer. Each organisation now has its own figure, and
//    until it has confirmed one the page says so instead of naming a number
//    as a rule. A database not yet upgraded has no such settings and falls
//    back to the same cautious wording.
try {
    $givingRule = GivingRetention::ruleForSite(Site::id());
} catch (\Throwable $e) {
    $givingRule = null;
}

// #703 round 12: whether this organisation keeps the giver's name on every
// gift, for the "Financial records" sentence. (The exception's two branches
// below test the rule themselves, and a coverage check counts those two.)
$everyGiftKept = ($givingRule['scope'] ?? '') === GivingRetention::SCOPE_ALL;

// 📜 #940 (D-117, D-118): how long THIS organisation's activity log is kept, for
// the "Records kept for security" sentence. A failed read is its own answer
// and names no figure (ActivityLogRetention::memberFactsForSite()).
$activityLogFacts = ActivityLogRetention::memberFactsForSite($mysqli, Site::id());

$pageTitle   = 'Privacy policy';
$pageSection = 'privacy';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Privacy policy' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-3"><i class="fa-solid fa-shield-halved me-2"></i>Privacy policy</h1>

<p>This portal is operated by <strong><?php echo htmlspecialchars($portalName, ENT_QUOTES, 'UTF-8'); ?></strong>.
We handle personal data under the UK GDPR and the Data Protection Act 2018.</p>

<h3>Your rights</h3>
<ul>
    <?php // 🗣️ #703 round 7: this said you could "download everything we hold about you" from My data.
          //    Untrue: My data's download holds only its inventory (each kind of record, how many, and what
          //    an erasure would do with it). The copy of the data itself is Data Export.
          // 🗣️ #1012: still true, but no longer complete since #929 - both pages also show the records that
          //    carry the member's name because they MADE them (GdprEraser::recordsCarryingYourName(), D-142),
          //    listed by kind, day and organisation, and only counted in an organisation the member cannot
          //    open (D-151). Pinned as rendered ("privacy policy, your rights") by section P of
          //    tools/erasure-pages-selftest.php. ?>
    <li><strong>Article 15 — Right of access</strong>: download a copy of your data from <a href="<?php echo htmlspecialchars(Site::url('account/data-export'), ENT_QUOTES, 'UTF-8'); ?>">/account/data-export</a>; <a href="<?php echo htmlspecialchars(Site::url('account/my-data'), ENT_QUOTES, 'UTF-8'); ?>">/account/my-data</a> lists each kind of record we hold about you and what an erasure would do with it. Both also show the records that carry your name because you made them, such as a pastoral visit, or a DBS check you recorded for somebody else. Those are usually about somebody else, so each is listed only by its kind, the day it was made and the organisation that holds it, and one held by an organisation you cannot open here is only counted.</li>
    <li><strong>Article 17 — Right to erasure</strong>: file a request at <a href="<?php echo htmlspecialchars(Site::url('account/erasure-request'), ENT_QUOTES, 'UTF-8'); ?>">/account/erasure-request</a>. We respond within one month.</li>
    <li><strong>Article 16 — Right to rectification</strong>: edit your profile via <a href="<?php echo htmlspecialchars(Site::url('account'), ENT_QUOTES, 'UTF-8'); ?>">/account</a>.</li>
    <li><strong>Article 21 — Right to object</strong>: unsubscribe from any newsletter via the link in its footer.</li>
</ul>

<h3>Data we hold</h3>
<p>Sign in to see the exact inventory at <a href="<?php echo htmlspecialchars(Site::url('account/my-data'), ENT_QUOTES, 'UTF-8'); ?>">/account/my-data</a>. In summary we hold: profile information (name, email), authentication tokens, app-specific records (attendance, expenses, giving, etc.), and audit logs.</p>

<h3>Retention policy</h3>
<ul>
    <li><strong>Financial records</strong> (expenses, giving, payments) are kept to meet tax and accounting law. On erasure they are kept, not deleted, and the amounts and dates stay. On a payment, and on a gift that is not kept for tax, your name and the link to you are removed. An expense claim keeps your account number, because a claim must say who was paid; your account itself is emptied, but that number can still be matched back to you wherever a record keeps your name beside it: gifts kept for tax<?php echo $everyGiftKept === true ? ' (for this organisation, every gift)' : ''; ?> keep your name with your number until this organisation's keep-until date (see the exception below), and so do the records a worker or volunteer made (see the point about having worked or volunteered here).
        <?php if ($givingRule !== null && $givingRule['confirmed'] === true): ?>
            One exception: gifts covered by a tax declaration, a tax receipt or a giving statement<?php echo $givingRule['scope'] === GivingRetention::SCOPE_ALL ? ' (for this organisation, every gift)' : ''; ?> keep your name and home address for <?php echo (int) $givingRule['years']; ?> <?php echo (int) $givingRule['years'] === 1 ? 'year' : 'years'; ?> after the end of the financial year of your last such gift, so that this organisation can show them to the tax authority if it is asked to. They are then cleared automatically, by the portal's clear-out job (which runs each night once it has been set up) - but never while a hold is on, which this organisation, or whoever runs this installation, can switch on during an audit or a tax enquiry.
        <?php else: ?>
            One exception: gifts covered by a tax declaration, a tax receipt or a giving statement<?php echo $givingRule !== null && $givingRule['scope'] === GivingRetention::SCOPE_ALL ? ' (for this organisation, every gift)' : ''; ?> keep your name and home address for a number of years, so that this organisation can show them to the tax authority if it is asked to. This organisation has not yet confirmed how many years; until it has, they are kept. Once the period is set and has ended they are cleared automatically, by the portal's clear-out job (which runs each night once it has been set up) - but never while a hold is on, which this organisation, or whoever runs this installation, can switch on during an audit or a tax enquiry.
        <?php endif; ?>
        <?php // 🗣️ #703 round 12: "On a gift or a payment your name ... removed" was untrue of a gift
              //    kept for tax; "gifts kept for tax" left out "every gift" where the organisation keeps
              //    them all; and "They are then cleared automatically" left out the hold, and that the
              //    clearing is a scheduled job that has to be set up. The sentence still BEGINS "They are
              //    then cleared automatically", which an older check (section G) pins. ?>
    </li>
    <li><strong>Authentication artefacts</strong> (sessions, WebAuthn credentials, TOTP backup codes, OAuth tokens) are deleted on request.</li>
    <?php // 🗣️ #703 round 6: this said such content "is anonymised", and that "the body remains" -
          //    untrue on both counts: what it says can still mention you, the change history can hold
          //    your account number, and a prayer request's own words are emptied (#715). ?>
    <li><strong>User-generated content</strong> (announcements, recordings, etc.) is kept, with the link to you removed from it; what it says can still mention you, and the change history can still hold your account number (see below). A prayer request you made has its subject, its body, the name, e-mail address and network address you typed, and any prayer partner's note about it emptied.</li>
    <li><strong>Your user row</strong> is emptied and marked with the tombstone <code>[Deleted User]</code> rather than deleted, so historical foreign-key links don't cascade-blow attribution we want to keep. Most records that point at it lose your account number, but some keep it on purpose: your expense claims, your own DBS checks, your tax declarations, an approval step given to you by name and the record of each giving statement issued to you. Some keep your name as well: gifts kept for tax, offering envelopes that will become such gifts, your tax declarations and a giving statement's file at this organisation, if it is keeping your name, where all the gifts on it are kept or its period cannot be told from it, with any home address held for you (see Financial records, above); and the pastoral visits, care cases, DBS checks or children's check-ins you made (see the next point).</li>
    <?php // 🧾 #703, the owner's decisions D-016, D-110, D-111 and D-113: say plainly
          //    what a worker cannot expect, and what keeping the number costs them. ?>
    <li><strong>If you worked or volunteered here</strong>, the pastoral visits you made, the care cases you opened, the DBS (safeguarding) checks you recorded and the children you checked in or out keep your <strong>name and account number</strong> as the person who did it, even after you are erased. A safeguarding record must still say who carried it out. Because your account number stays on them, anyone who can read the database or a backup could use them to tell which of your other kept records (an expense claim, for instance) were yours.</li>
    <?php // 🧾 #703 round 5: the records kept for security and as a history of changes. Each
          //    clearing rule is read from the code (see GdprEraser's tblUsers reason), and the
          //    sentence does not claim to be every place a number can be written.
          //    #940 (D-117, D-118): the activity log's clause is THIS organisation's real
          //    period - a number of days, or kept for ever by this organisation's choice or
          //    the whole portal's - worded in ONE place, ActivityLogRetention::
          //    privacyNoticeClause(), from the reader the clear-out uses. It used to say
          //    "a year unless changed", which stopped being enough when "never" became a
          //    choice. The last sentence is #933's: an erasure leaves what a log record SAYS,
          //    and some records give a full name or an e-mail address, so "kept for ever"
          //    keeps those too. "Some", not a list - no list of those writers is complete. ?>
    <li><strong>Records kept for security and as a history of changes</strong> can mention your account number, in what they describe or in a stored copy of a record that was changed: <span data-activity-log-retention="<?php echo htmlspecialchars($activityLogFacts['state'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ActivityLogRetention::privacyNoticeClause($activityLogFacts), ENT_QUOTES, 'UTF-8'); ?></span>; the change history of records, which nothing clears; and rate-limit records, tidied away only when a later request of the same kind happens to trigger it, so one can stay for a long time. Neither of these lists is every place your number may be written: anything typed about you can mention it too. What a record in the activity log says is kept as it was written for as long as the record is, and some give a person's full name or e-mail address as well as an account number.</li>
</ul>

<h3>Contact</h3>
<p>Data protection enquiries: <?php if ($contact !== ''): ?><a href="mailto:<?php echo htmlspecialchars($contact, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($contact, ENT_QUOTES, 'UTF-8'); ?></a><?php else: ?><em>not configured — see your portal admin.</em><?php endif; ?></p>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
