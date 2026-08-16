<?php
// Path: _apps/assets/found-save.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Report Found Item (public POST handler) 🔍
 * -----------------------------------------------------------------------------
 * PUBLIC POST handler for tag.php's "I found this" form. Reachable by
 * anonymous visitors by design (the seeded `assets/found-save` route has
 * `isProtected = 0` — migration 159) — this file is the ONE place in the
 * whole Asset Tracker app that must assume EVERY input is hostile, because
 * unlike every other mutating controller in `_apps/assets/`, there is no
 * login and no manager gate standing in front of it.
 *
 * GATE CHAIN (read in order — every step can end the request):
 *   1. Method must be POST. Anything else is redirected to the portal
 *      home — no asset lookup has happened yet, so there is nothing to
 *      protect an oracle against at this point.
 *   2. The posted `token` is re-validated against the SAME
 *      `^[a-f0-9]{32}$` shape tag.php checks, then the asset is looked up
 *      by that token with the EXACT SAME query tag.php uses (NOT a
 *      numeric id — a public visitor never carries one).
 *   3. UNIFORM 404 (mirrors tag.php's own access model exactly — see that
 *      file's header for the full rationale): unknown token, OR the
 *      asset fails ANY of `assets.public_page_enabled` (global) /
 *      `publicPageEnabled` (per-asset) / NOT `isConfidential` → the same
 *      404 Router::renderError() every other branch below would also
 *      eventually reach some OTHER way, so a probe against found-save.php
 *      directly can't be used to learn anything tag.php itself wouldn't
 *      already reveal (or rather, wouldn't).
 *   4. Everything from here on redirects back to `/a/{token}` (never a
 *      404) with a flash message — the token is already confirmed public-
 *      eligible by step 3, so there is no oracle left to protect.
 *   5. Honeypot (`website`) — if a bot filled it in, silently behave as
 *      though the submission succeeded (redirect with the SAME success
 *      flash a genuine submission gets) WITHOUT writing a
 *      tblAssetFoundReports row. Never tell an automated submitter it
 *      failed.
 *   6. CSRF (`Auth::verifyCsrf()`) — the token tag.php issued via
 *      `Auth::ensureSession()` + `Auth::csrfToken()`.
 *   7. Captcha (`Portal\Core\Captcha::verify()`) — the SAME multi-provider
 *      helper `prayer-requests/anonymous-save.php` and
 *      `visitors/public-form.php` use. Gracefully allows through when no
 *      provider is configured (Captcha's own documented behaviour).
 *   8. Per-IP rate limit — `RateLimiter::tooMany()`/`recordHit()` (the
 *      generic sliding-window limiter, #323 Phase 2), bucketed on
 *      `AssetRegister::publicIpHash()` (the SAME salted-SHA-256 the
 *      found-report row's own `ipHash` column is populated with — see
 *      that method's own doc for why a second hashing scheme was NOT
 *      invented here).
 *   9. Field validation — reporterName/reporterContact/message
 *      trimmed+capped; at least ONE of reporterContact/message must be
 *      non-empty (a blank report with no name and no way to follow up
 *      helps nobody).
 *  10. `AssetRegister::createFoundReport()` — actorType 'public', no
 *      session user. Then a best-effort (never blocking) notification
 *      email to admins/asset_manager role-holders, and a redirect back to
 *      `/a/{token}` with a thank-you flash.
 *
 * PII: only `AssetRegister::publicIpHash()` (salted SHA-256) is ever
 * stored — see `createFoundReport()`'s own doc — the raw IP never reaches
 * `tblAssetFoundReports`.
 *
 * FIELD ALLOW-LIST: reporterName/reporterContact/message only — this
 * handler never reads, and `AssetRegister::createFoundReport()` never
 * accepts, any of the withheld fields (owner/cost/serial/location/
 * agreements) tag.php's own public view already keeps off the page.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/395
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/401
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\Logger;
use Portal\Core\Mailer;
use Portal\Core\RateLimiter;
use Portal\Core\Router;
use Portal\Core\Site;

// 🚦 1. Method gate — POST only. Nothing has been looked up yet, so a
// plain redirect home is safe (no oracle to protect here — see header).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /', true, 303);
    exit();
}

// 🔓 Public — no Auth::requireLogin(). Session started so the CSRF token
// tag.php issued can be verified, and so a flash message can be left for
// the redirect back to /a/{token}.
Auth::ensureSession();

// 🔍 2. Token shape — mirrors tag.php's own defensive re-validation. The
// form carries the TOKEN (see tag.php's hidden `token` field), never a
// numeric assetID — a public visitor never has one.
$token = trim((string) ($_POST['token'] ?? ''));
if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
    Router::renderError(404);
    return;
}

$db = App::db();

// 🔎 Asset lookup — the EXACT SAME query + column set tag.php uses, so
// this handler can never diverge from that file's own notion of "does
// this token resolve to something".
$stmt = $db->prepare(
    'SELECT assetID, name, isConfidential, publicPageEnabled '
    . 'FROM tblAssets WHERE publicToken = ? AND isDeleted = 0 LIMIT 1'
);
if ($stmt === false) {
    Router::renderError(404);
    return;
}
$stmt->bind_param('s', $token);
$stmt->execute();
$asset = $stmt->get_result()->fetch_assoc();
$stmt->close();

// 🚫 3. UNIFORM 404 — unknown token OR any one of the three public-
// eligibility gates failing. Deliberately the SAME response either way
// (see file header + tag.php's own access-model doc) — no oracle.
if ($asset === null || $asset === false) {
    Router::renderError(404);
    return;
}

$assetId          = (int) $asset['assetID'];
$assetName        = (string) $asset['name'];
$isConfidential   = (int) $asset['isConfidential'] === 1;
$assetPageEnabled = (int) $asset['publicPageEnabled'] === 1;

$globalPagesEnabled = (string) (App::settings('assets.public_page_enabled') ?? 'true') === 'true';
if ($globalPagesEnabled === false || $assetPageEnabled === false || $isConfidential === true) {
    Router::renderError(404);
    return;
}

// -----------------------------------------------------------------------------
// ✅ From here on, the token is CONFIRMED public-eligible — every further
// rejection redirects back to the token page with a flash message rather
// than a 404 (see file header, step 4).
// -----------------------------------------------------------------------------
$backToTag = '/a/' . $token;

/**
 * Redirect back to this asset's public page with a flash message, then
 * stop the request. Local closure so every rejection branch below bails
 * out identically — mirrors the `$fail` closure convention used by
 * save.php/other internal controllers, adapted for this public,
 * token-carrying redirect target.
 */
$bounce = static function (string $msg, string $type) use ($backToTag): never {
    $_SESSION['flash_msg']  = $msg;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . $backToTag, true, 303);
    exit();
};

// 🎉 The one success message every "let the visitor believe it worked"
// path (a genuine success AND the honeypot trap below) redirects with.
$thankYouMsg = 'Thank you — the team has been notified. If you shared a way to reach you, they may be in touch.';

// 🕳️ 5. Honeypot — a filled `website` field means a bot, not a human
// (tag.php's real form never shows or lets a human reach this field).
// Behave EXACTLY like a genuine success — same message, same redirect,
// same HTTP shape — so an automated submitter learns nothing about why
// it "failed". No row is written.
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    Logger::activity('AssetFoundReportHoneypot', 'Honeypot tripped on found-save for asset #' . $assetId);
    $bounce($thankYouMsg, 'success');
}

// 🔐 6. CSRF.
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    Logger::activity('AssetFoundReportRejected', 'Invalid CSRF on found-save for asset #' . $assetId);
    $bounce('Security check failed — please reload the page and try again.', 'danger');
}

// 🤖 7. Captcha — same multi-provider helper prayer-requests/visitors use.
// Gracefully allows through when no provider is configured.
if (Captcha::verify($_POST) === false) {
    Logger::activity('AssetFoundReportRejected', 'Captcha failed on found-save for asset #' . $assetId);
    $bounce('Verification failed — please try again.', 'danger');
}

// 🚦 8. Per-IP rate limit — generic sliding-window limiter (#323 Phase 2),
// bucketed on the SAME salted-IP-hash the found-report row itself stores
// (AssetRegister::publicIpHash() — see that method's own doc). 5
// submissions per 15 minutes per IP — same order of magnitude as the
// login limiter's own default (RateLimiter::DEFAULT_MAX_ATTEMPTS).
$ipHash = AssetRegister::publicIpHash();
$rateBucket = 'assets:found-save:' . $ipHash;
$rateMax = 5;
$rateWindowSeconds = 900;
if (RateLimiter::tooMany($rateBucket, $rateMax, $rateWindowSeconds) === true) {
    Logger::activity('AssetFoundReportBlocked', 'Rate-limited found-save submission for asset #' . $assetId);
    // 🛟 Friendly, non-revealing message — never confirms/denies whether
    // OTHER submissions from this visitor succeeded.
    $bounce('Too many submissions from this connection — please try again later.', 'warning');
}
RateLimiter::recordHit($rateBucket, $rateWindowSeconds);

// -----------------------------------------------------------------------------
// 📥 9. Field validation. AssetRegister::createFoundReport() ALSO trims/
// caps these (see that method's own doc — it never trusts a caller the
// way createAsset()/updateAsset() do) — re-checking the "at least one of
// contact/message" rule HERE, before ever calling in, keeps that
// human-facing validation message on THIS side rather than silently
// dropping an empty report.
// -----------------------------------------------------------------------------
$reporterName    = trim((string) ($_POST['reporterName'] ?? ''));
$reporterContact = trim((string) ($_POST['reporterContact'] ?? ''));
$message         = trim((string) ($_POST['message'] ?? ''));

if ($reporterContact === '' && $message === '') {
    $bounce('Please share a way to reach you (email/phone) or a short message.', 'danger');
}

// -----------------------------------------------------------------------------
// 💾 10. Create the report.
// -----------------------------------------------------------------------------
$reportId = AssetRegister::createFoundReport(
    $assetId,
    [
        'reporterName'    => $reporterName,
        'reporterContact' => $reporterContact,
        'message'         => $message,
    ],
    $ipHash
);

if ($reportId <= 0) {
    $bounce('Sorry — something went wrong submitting your report. Please try again.', 'danger');
}

// -----------------------------------------------------------------------------
// 📧 Best-effort notification to admins + asset_manager role-holders on
// this site. NEVER allowed to break the visitor-facing thank-you redirect
// — wrapped defensively; a mail-provider hiccup is logged, not surfaced.
// Recipients: active, site-linked users who are either an admin OR hold
// the asset_manager role — mirrors cron/event-reminders.php's own
// "admins + role/coordinator holders" recipient query, adapted to a role
// JOIN. Uses `emailAddress` (the REAL tblUsers column, aliased to
// `email` for the fetch — see directory/index.php's own convention);
// NOT `u.email`, which some other call sites in this codebase reference
// but does not exist as a column.
// -----------------------------------------------------------------------------
try {
    // 🌐 Site::id() — already correctly resolved for this request by
    // bootstrap (host/subdomain detection runs before Router ever reaches
    // this handler — same "already correct for a public route" convention
    // every other public form in this codebase relies on; see this file's
    // "FOR OPUS SECURITY REVIEW" note in the delivery report for the one
    // documented edge case).
    $siteIdForNotify = Site::id();
    $roleKey = 'asset_manager';
    $mStmt = $db->prepare(
        'SELECT DISTINCT u.emailAddress AS email, u.fullName FROM tblUsers u '
        . 'INNER JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
        . 'LEFT JOIN tblUserRoles ur ON ur.userID = u.userID '
        . 'LEFT JOIN tblRoles r ON r.roleID = ur.roleID '
        . 'WHERE u.isActive = 1 AND u.emailAddress IS NOT NULL AND u.emailAddress != "" '
        . 'AND (u.isAdmin = 1 OR r.roleKey = ?)'
    );
    if ($mStmt !== false) {
        $mStmt->bind_param('is', $siteIdForNotify, $roleKey);
        $mStmt->execute();
        $mResult = $mStmt->get_result();

        // 🧼 Build the HTML body ONCE — every recipient gets the same
        // content. Every attacker-supplied value (reporterName/
        // reporterContact/message) is htmlspecialchars()'d before it ever
        // reaches this HTML email body.
        $subject = 'Someone found: ' . $assetName;
        $safeAssetName = htmlspecialchars($assetName, ENT_QUOTES, 'UTF-8');
        $safeName      = $reporterName !== '' ? htmlspecialchars($reporterName, ENT_QUOTES, 'UTF-8') : 'Someone';
        $safeContact   = $reporterContact !== '' ? htmlspecialchars($reporterContact, ENT_QUOTES, 'UTF-8') : 'no contact details given';
        $safeMessage   = $message !== '' ? nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) : '';
        $host          = htmlspecialchars((string) ($_SERVER['HTTP_HOST'] ?? ''), ENT_QUOTES, 'UTF-8');

        $body = '<p><strong>' . $safeName . '</strong> reported finding <strong>' . $safeAssetName
              . '</strong> via its public lost-and-found page.</p>'
              . '<p><strong>Contact:</strong> ' . $safeContact . '</p>'
              . ($safeMessage !== '' ? '<p><strong>Message:</strong><br>' . $safeMessage . '</p>' : '')
              . ($host !== '' ? '<p><a href="https://' . $host . '/assets/found-reports">Review found-item reports</a></p>' : '');

        while ($mRow = $mResult->fetch_assoc()) {
            $recipientEmail = (string) ($mRow['email'] ?? '');
            if (filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) !== false) {
                Mailer::send($recipientEmail, $subject, $body);
            }
        }
        $mStmt->close();
    }
} catch (\Throwable $e) {
    // 🛟 A mail-provider failure must never break the visitor-facing flow.
    Logger::errorPlatform('Mailer', 'Warning', 'ASSET_FOUND_NOTIFY_FAIL', $e->getMessage(), 'reportID=' . $reportId);
}

$bounce($thankYouMsg, 'success');
