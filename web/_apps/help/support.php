<?php
// Path: apps/help/support.php
/**
 * -----------------------------------------------------------------------------
 * Help Centre — Getting Support / Reporting Problems 🆘
 * -----------------------------------------------------------------------------
 * User-facing summary of the day-2 support contract (docs/day2-support.md).
 * Tells users where to report problems and what to expect.
 *
 * @package    Portal\Help
 * @author     MWBM Partners Ltd (t/a MWservices)
 * @copyright  2025-present MWBM Partners Ltd (t/a MWservices)
 * @license    All Rights Reserved
 * @version    1.0.0
 * @link       https://github.com/MWBMPartners/WebMS-Intra/issues/226
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$pageTitle   = 'Help — Getting Support';
$pageSection = 'help';
$breadcrumbs = ['Dashboard' => '/', 'Help' => '/help', 'Support' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

$supportEmail = htmlspecialchars(
    (string) ($SETTINGS['portal']['support']['email'] ?? 'portal-support@millrdsdacambridge.uk'),
    ENT_QUOTES,
    'UTF-8'
);
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-life-ring me-2"></i>Getting Support</h1>
        <p class="text-secondary mb-0">Found a problem? Here's how to report it and what to expect next.</p>
    </div>
    <a href="/help" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
        <i class="fa-solid fa-arrow-left me-1"></i>Back to Help Centre
    </a>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5"><i class="fa-solid fa-bullhorn me-1"></i>How to report a problem</h2>
        <ol class="mb-0">
            <li><strong>Use the "Report a problem" link in the footer of any page</strong> — preferred. It captures what you were looking at, which browser you're using, and a screenshot automatically.</li>
            <li><strong>Email <a href="mailto:<?php echo $supportEmail; ?>"><?php echo $supportEmail; ?></a></strong> for account issues, password reset failures, or anything blocking you from signing in.</li>
            <li><strong>Direct message a portal administrator</strong> for true emergencies only — data loss, suspected security issue, or login broken for everyone.</li>
        </ol>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5"><i class="fa-solid fa-clock me-1"></i>What to expect</h2>
        <p>We'll get back to you. Honestly. Here's our rough commitment:</p>
        <ul>
            <li><strong>Critical</strong> (login down for everyone, data loss, security): we respond within 4 hours, fix within a day.</li>
            <li><strong>Important</strong> (a whole app not working): we respond within one working day, fix within a week.</li>
            <li><strong>Minor / cosmetic</strong>: we acknowledge within a few days; fixes batch into the next portal update.</li>
            <li><strong>Suggestions</strong>: we read every one and add it to the backlog.</li>
        </ul>
        <p class="text-muted small mb-0">These are realistic estimates from a small volunteer team — not enterprise SLAs. If something is more urgent than these targets allow, please say so when reporting.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5"><i class="fa-solid fa-calendar-check me-1"></i>Planned maintenance</h2>
        <p>Portal upgrades usually happen on weekday evenings (UK time). We avoid:</p>
        <ul>
            <li>Friday evening through Saturday evening (Sabbath observance).</li>
            <li>Sunday mornings (when many of us are busy and can't troubleshoot).</li>
        </ul>
        <p class="mb-0">You'll see a notice in the dashboard and receive an email 48 hours before any planned maintenance.</p>
    </div>
</div>

<div class="card mb-4 border-info">
    <div class="card-body">
        <h2 class="h5 text-info"><i class="fa-solid fa-circle-info me-1"></i>What helps us help you</h2>
        <p class="mb-2">When reporting a problem, even a single sentence is fine — but if you can include any of these, it speeds things up:</p>
        <ul class="mb-0">
            <li>What you were trying to do.</li>
            <li>What happened instead.</li>
            <li>A screenshot, if you can take one.</li>
            <li>The page address (URL) shown in your browser.</li>
            <li>Whether you're on a phone or computer.</li>
        </ul>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
