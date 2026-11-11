<?php
// Path: public_html/admin/maintenance/retention.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Audit-Log Retention Sweeper 🧹
 * -----------------------------------------------------------------------------
 * Hard-deletes rows from tblActivityLogs + tblErrors older than the configured
 * retention window. Without this sweeper, both tables grow forever — disk
 * usage on shared hosting becomes a real problem after a year or two.
 *
 * This file is the staff page only: a global administrator sees a per-table
 * preview of how many rows would be deleted, then confirms.
 *
 * The scheduled version of the same sweep is at /cron/retention-sweep?token=…
 * (web/_apps/cron/retention-sweep.php). Until 14 September 2026 it was a
 * "?cron=1&token=…" mode of this page. It moved because this address is seeded
 * as protected, and once the Router really enforced that (issue #497) a
 * scheduler with a token but no session would only have been redirected to
 * the sign-in page, so the sweep would have stopped without any error. The
 * page and the job run the same code, in web/_apps/cron/_retention-sweep.php.
 *
 * WHO MAY USE THE WEB UI, AND WHY IT IS A GLOBAL ADMINISTRATOR ONLY — WITH ONE
 * EXCEPTION SINCE #932 (D-118): an organisation's own administrator may open
 * this page and set THEIR organisation's activity-log figure, within the
 * installation's limit, and nothing else
 * -----------------------------------------------------------------------------
 * Both the sweep and the preview counts above it act on the WHOLE
 * installation, not the one organisation the visiting administrator belongs
 * to: every clear-out here loops over every organisation on the server in turn
 * (see registration_sweep_sites() and log_sweep()).
 *
 * THIS PARAGRAPH USED TO SAY "the activity-log and error deletes have no
 * organisation column to limit them to", which was wrong twice over. Both
 * tables DO carry a `siteID`; what they had was a delete that ignored it while
 * reading one organisation's setting (#491, fixed 3 November 2026). The
 * conclusion — a global administrator only — is unchanged, because the sweep
 * still reaches every organisation's rows whoever presses the button.
 *
 * Until 13 September 2026 the only check was App::isAdmin(), which is true for
 * an administrator of a SINGLE organisation as well as for a global
 * administrator (see web/_core/App.php). So an administrator of one
 * organisation could press "Run Sweep Now" and hard-delete activity logs,
 * error rows and children's event-registration records — including
 * safeguarding details such as allergies and medical notes — belonging to
 * every OTHER organisation on the installation, without their knowledge or
 * consent. This matches the fix already made to backup.php for the same
 * reason (issue #495): an action with no organisation boundary needs a
 * permission with no organisation boundary.
 *
 * The scheduled job (now /cron/retention-sweep, see above) is untouched by
 * this: it was never gated by App::isAdmin() in the first place, and it
 * authenticates with a shared secret token instead of a session. (This
 * paragraph used to end "per-organisation retention is a separate,
 * not-yet-decided question tracked in issue #491". It was decided and built on
 * 3 November 2026: each organisation's own figure clears its own rows.)
 *
 * Settings:
 *
 *   audit.retentionDays        (default 365) — activity logs. Each
 *                               organisation has its own, held to what the
 *                               installation allows; records belonging to no
 *                               organisation follow the installation's own
 *                               figure (#491). 0 means "never delete" since
 *                               #932 (D-117), and an organisation may hold 0
 *                               only where the installation holds 0. SET ON
 *                               THIS PAGE through Portal\Core\ActivityLogRetention
 *                               — the installation's by a global administrator,
 *                               an organisation's by its own administrator
 *                               (D-118); an installation figure below an
 *                               organisation's is refused, naming them (D-119).
 *                               The general settings editor refuses it.
 *   errors.retentionDays       (default 365) — error logs, the same way,
 *                               EXCEPT that a stored 0 still falls back to a
 *                               year: "never delete" is the activity log's
 *                               alone (D-117 rules out extending it). Still
 *                               changed in the general settings editor.
 *   attend.detailRetentionDays (default 90) — how long the scrambled sender
 *                               address is kept on an anonymous check-in
 *                               (#530: a browser-description column used to
 *                               sit on the same timer; migration 201 dropped
 *                               it, since nothing ever read it). Each
 *                               organisation has its own; 0 means keep for
 *                               ever. Changed at /admin/settings/attendance.
 *   maintenance.cronToken      ('' by default — used by /cron/retention-sweep;
 *                               an empty value switches the job off)
 *
 * Deletions are hard (no soft-delete column on these tables). This page shows
 * an HTML report; the scheduled job returns JSON.
 *
 * ONE PART OF THIS SWEEP DELETES NOTHING, and it is worth saying here because
 * everything else on this page does. The anonymous check-in clear-out (#525)
 * empties two columns out of rows that stay exactly where they are. Its two
 * numbers are reported separately and are deliberately NOT added to the
 * "deleted N rows" total.
 *
 * NOR DOES ANOTHER (roll-out package A2b). A donor who asked to be erased may
 * have their name kept on some giving records for tax reasons, until their
 * organisation's keep-until date. This sweep removes the name once that date
 * has passed. It clears nothing for an organisation that has not confirmed
 * its number of years, or that has a hold switched on; those two tests are
 * made inside GdprEraser::clearKeptGivingIdentity(), not on this page.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * THIS PAGE NOW HAS TWO KINDS OF POST (#932), told apart by `action`:
 *
 *   - no `action` at all, or `action=sweep`: the sweep, exactly as before.
 *     "No action" stays the sweep because the sweep form has never carried
 *     one, and tests that press it post nothing else.
 *   - `action=activity-retention-installation` / `-organisation`: how long the
 *     activity log is kept. Saved through ActivityLogRetention and answered
 *     with a redirect back here, so the figures drawn afterwards are read
 *     fresh rather than from the request that changed them.
 *
 *   Any OTHER action is refused and changes nothing: a value nobody sent on
 *   purpose must never fall through to the one button that deletes.
 *
 * @version   1.3.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/491
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/495
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/932
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ActivityLogRetention;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

// -----------------------------------------------------------------------------
// 🧹 The sweep's rules and the delete itself are shared with the scheduled job
//    at /cron/retention-sweep, so the numbers this page previews and the rows
//    the job deletes are always decided by the same code.
//
//    This page used to BE the scheduled job as well, through a "?cron=1&token="
//    mode handled right here, before any sign-in check. That mode was removed on
//    14 September 2026 (issue #497). This address is seeded as protected, and
//    now that the Router really enforces that, a scheduler with a token but no
//    session would only ever be redirected to the sign-in page.
// -----------------------------------------------------------------------------
require_once PORTAL_APPS . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . '_retention-sweep.php';

// -----------------------------------------------------------------------------
// 🛡️ Web mode — admin-only
// -----------------------------------------------------------------------------
$pageTitle   = 'Audit Retention Sweeper';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Admin' => Site::url('admin'), 'Maintenance' => '', 'Retention' => ''];

Auth::ensureSession();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// 🚧 WHO MAY DO WHAT HERE (D-118, 10 November 2026). Everything on this page
//    acts on the WHOLE installation and is a global administrator's — the
//    sweep, its counts, the installation's activity-log figure — EXCEPT one
//    form: an organisation's own activity-log figure, which that organisation's
//    own administrator may set, within the installation's limit. So:
//      - not an administrator at all: refused above, for everything;
//      - an organisation's administrator: their own organisation's form (the
//        POST below, and a page drawing ONLY that form), and nothing else — any
//        other POST, the sweep included, meets the global-administrator gate
//        further down and is refused (403) and recorded;
//      - a global administrator: everything.
$isGlobalAdmin = App::isRootAdmin();

// 📝 #491 — THE TWO FIGURES SHOWN ON THIS PAGE ARE NOT THE FIGURES THE SWEEP
//    USES, because there is no longer one figure for either. These four lines
//    used to be the whole story:
//
//      $activityDays = (int) (App::settings('audit.retentionDays') ?? '365');
//
//    and that single number then decided what was deleted for every
//    organisation on the installation. The clear-out now asks
//    `log_retention_days()` once per organisation. So this page reads the
//    VISITING administrator's own organisation through the same function the
//    sweep uses — never the merged settings array, which would print a figure
//    the clear-out does not use (the #685 mistake on the check-in card below)
//    — and prints the installation's own figure beside it, so the two levels
//    are visible rather than described.
$activityDays            = log_retention_days('audit.retentionDays', Site::id());
$errorDays               = log_retention_days('errors.retentionDays', Site::id());
$activityInstallationDays = log_retention_installation_days('audit.retentionDays');
$errorInstallationDays    = log_retention_installation_days('errors.retentionDays');

// 💬 A message left by the activity-log forms below, which answer with a
//    redirect back here (#932). The sweep, further down, sets its own.
$flashMsg  = (string) ($_SESSION['flash_msg'] ?? '');
$flashType = (string) ($_SESSION['flash_type'] ?? '');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// 🧭 Which form was posted. A non-string (`action[]=x`) is "not understood",
//    never the sweep.
$postedAction = array_key_exists('action', $_POST) === false
    ? 'sweep'
    : (is_string($_POST['action']) === true ? $_POST['action'] : '');

// -----------------------------------------------------------------------------
// 📜 How long the activity log is kept (#932, D-117)
// -----------------------------------------------------------------------------
// ASK WHETHER A FIELD ARRIVED, never only what it contains (#677). `choice`
// says which of the radio buttons was picked; `days` is read only when the
// choice is a number of days. REFUSE WITH THE REASON, never clamp (#668), and
// RECORD every refusal: a request to keep the log longer than the installation
// allows crosses a line between two parties who may be different people. The
// record carries the organisation's NUMBER and the figures, nothing else.
//
// WHO (D-118): the organisation's own figure by an administrator of THAT
// organisation (App::isAdmin() answers for the organisation this page is open
// in, Site::id(), and a global administrator passes it too); the installation's
// figure by a global administrator only. An organisation's administrator who
// posts the installation's form is not let in here at all: the request falls
// through to the global-administrator gate below, which refuses it (403) and
// records it. Both save methods ALSO refuse the wrong person themselves.
$alrBack   = Site::url('admin/maintenance/retention');
$alrFinish = static function (string $message, string $type) use ($alrBack): never {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . $alrBack);
    exit();
};
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($postedAction === 'activity-retention-organisation'
        || ($postedAction === 'activity-retention-installation' && $isGlobalAdmin === true))
) {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $alrFinish('Invalid or expired form token. Nothing was changed. Please try again.', 'danger');
    }

    $alrWho       = isset($_SESSION['user_id']) === true ? (int) $_SESSION['user_id'] : null;
    $alrForSite   = $postedAction === 'activity-retention-organisation';
    $alrSiteId    = Site::id();
    $alrLevel     = $alrForSite === true ? 'organisation ' . $alrSiteId : 'the installation';
    $alrChoice    = array_key_exists('choice', $_POST) === true && is_string($_POST['choice']) === true ? $_POST['choice'] : null;
    $alrAllowed   = $alrForSite === true ? ['days', 'never', 'follow'] : ['days', 'never'];

    // 🚫 No choice, or one this form does not offer: change nothing.
    if ($alrChoice === null || in_array($alrChoice, $alrAllowed, true) === false) {
        Logger::activity(
            'ActivityLogRetentionRefused',
            'A change to how long ' . $alrLevel . ' keeps the activity log was refused: no recognised choice was sent. Nothing was saved.',
            $alrWho
        );
        $alrFinish('The form did not say what to keep the activity log for, so nothing was changed.', 'danger');
    }

    // 🔢 The number of days, read only for that choice.
    $alrDays = null;
    if ($alrChoice === 'never') {
        $alrDays = ActivityLogRetention::NEVER;
    } elseif ($alrChoice === 'days') {
        $alrRaw = array_key_exists('days', $_POST) === true && is_string($_POST['days']) === true ? trim($_POST['days']) : '';
        // ⚠️ 0 typed as a NUMBER is refused, not read as "never": never is its
        //    own choice (D-117), and a box left at 0 is not a decision to keep
        //    a record of everybody's actions for ever.
        if (preg_match('/^\d{1,6}$/', $alrRaw) !== 1 || (int) $alrRaw < 1) {
            Logger::activity(
                'ActivityLogRetentionRefused',
                'A change to how long ' . $alrLevel . ' keeps the activity log was refused: the number of days was not a whole number of 1 or more. Nothing was saved.',
                $alrWho
            );
            // 🗣️ #940 round 4: worded PER FORM. The installation's form may
            //    choose 1 to 3650 or "Never delete", so its words are fixed. The
            //    organisation's form may choose only what the installation allows,
            //    and is offered "Never delete" only where the installation has
            //    chosen it, so its words come from ActivityLogRetention, the one
            //    place that already words the organisation's refusals.
            $alrFinish($alrForSite === true
                ? ActivityLogRetention::refusalWordsForSite('not-a-number', (string) $activityInstallationDays, 0)
                : 'A number of days has to be a whole number from 1 to ' . ActivityLogRetention::HARD_MAX_DAYS
                    . '. For no time limit, choose "Never delete" instead. Nothing was saved.', 'danger');
        }
        $alrDays = (int) $alrRaw;
    }

    $alrResult = $alrForSite === true
        ? ActivityLogRetention::saveForSite($mysqli, $alrSiteId, $alrDays, App::isAdmin())
        : ActivityLogRetention::saveInstallation($mysqli, (int) $alrDays, $isGlobalAdmin);

    $alrAsked = $alrDays === null
        ? 'to follow the installation'
        : 'for ' . ActivityLogRetention::inWords($alrDays);
    if ($alrResult['outcome'] === 'refused') {
        Logger::activity(
            'ActivityLogRetentionRefused',
            'A change to how long ' . $alrLevel . ' keeps the activity log (' . $alrAsked . ') was refused'
            . ($alrResult['refusal'] === '' ? '' : ' (' . $alrResult['refusal'] . ')')
            // D-119: a COUNT of the organisations that blocked it, never their
            // names — the log is read later and by others; the names are in
            // the message only the person who pressed Save reads.
            . (($alrResult['blockedOrganisations'] ?? 0) > 0
                ? '; ' . (int) $alrResult['blockedOrganisations'] . ' organisation(s) have chosen a longer figure'
                : '')
            . '. Nothing was saved.',
            $alrWho
        );
    } elseif ($alrResult['outcome'] !== 'unchanged') {
        Logger::activity(
            'ActivityLogRetentionSaved',
            'How long ' . $alrLevel . ' keeps the activity log was changed (' . $alrAsked . ').',
            $alrWho
        );
    }

    $alrFinish($alrResult['message'], $alrResult['type']);
}

// -----------------------------------------------------------------------------
// 📜 The activity-log figures every view of this page draws (#932)
// -----------------------------------------------------------------------------
//    PREFILLED FROM THE RIGHT LAYER (#674, #677). The installation's choice
//    comes from start-up's installation-wide layer, through the same reader
//    the clear-out uses; it is fresh because a save answers with a redirect.
//    The organisation's choice is ITS OWN row only, never the merged settings,
//    which fall back to the installation's row and would make one untouched
//    Save turn the installation's figure into the organisation's own.
//
//    NO BOX IS PREFILLED ABOVE ITS OWN `max` (#668). The installation's figure
//    is already held to the ten-year maximum by its reader. An organisation's
//    own figure above it can only come from SQL or a restore; `max` is widened
//    to it rather than the figure altered, so an untouched Save matches what is
//    stored and changes nothing (#687). The HANDLER is the boundary, not the box.
//
//    "NEVER" IS OFFERED TO THE ORGANISATION ONLY WHERE IT CAN BE SAVED: where
//    the installation itself never deletes, or where the organisation already
//    holds never (so the page shows what is stored and an untouched Save
//    passes). Offering it otherwise would be a choice whose own Save always
//    refuses (#675).
$alrOwn       = ActivityLogRetention::ownDays($mysqli, Site::id());
$alrInstNever = $activityInstallationDays === ActivityLogRetention::NEVER;
$alrOwnMax    = max(ActivityLogRetention::HARD_MAX_DAYS, $alrOwn ?? 0);
$alrSiteName  = (string) (Site::current()['siteName'] ?? '');
// What "never" costs, in words no stronger than is true: an erasure unlinks a
// person's records but does not rewrite descriptions. Most descriptions name
// nobody; of those that name somebody, most do so by account number, and a few
// by name or e-mail address. Since #933 the deliberate ones are exactly the
// name-bearing entries of ON_PURPOSE in
// tools/audit-checks/check_activity_descriptions_personal.py, plus D-138: a
// failed or rate-limited sign-in and its "count cleared" record keep the typed
// sign-in name (D-137), a refused child collection keeps the typed name, a
// change to the organisation's support e-mail address records the address
// (PortalAddressSaved; it can be a person's own mailbox), and an
// organisation's labels such as a task's title stay (D-138). That is the
// deliberate list, not a promise that nothing else does: the gate's header
// says what it cannot see. (UserCreated, the password-reset records,
// EventInviteSent, the children's-profile records and the provider link and
// unlink records named people before #933 and are numbers only now; EventSubmitted
// carried an address only before #963.) ActivityLogRetention's header says the
// same. Because not every description names anybody, the sentence the page
// shows is general — and still true.
$alrNeverCost = 'The log then grows without limit. Some records describe a person — usually by account number, '
    . 'and for some kinds of record by name or e-mail address — and those descriptions stay for ever, even after '
    . 'that person has asked to be erased.';

/**
 * The organisation's own form, drawn in ONE place for both views of this page:
 * the global administrator's whole page and an organisation administrator's
 * page holding nothing else (D-118). Two copies would drift.
 */
$alrOrganisationForm = static function () use ($alrOwn, $alrInstNever, $alrOwnMax, $alrSiteName, $activityInstallationDays): void {
    ?>
                        <h3 class="h6">For this organisation<?php echo $alrSiteName !== '' ? ' (' . htmlspecialchars($alrSiteName, ENT_QUOTES, 'UTF-8') . ')' : ''; ?></h3>
                        <p class="small text-muted">
                            Its own figure may be shorter than the installation's, never longer.
                            <?php if ($alrInstNever === false): ?>
                                <strong>&ldquo;Never delete&rdquo; is not available</strong>, because this installation
                                deletes records after <?php echo (int) $activityInstallationDays; ?> days.
                            <?php endif; ?>
                        </p>
                        <?php if ($alrOwn === ActivityLogRetention::NEVER && $alrInstNever === false): ?>
                            <p class="small text-warning-emphasis" data-activity-log-never-held="1">
                                <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                                This organisation has &ldquo;Never delete&rdquo; stored, but this installation does not
                                allow it, so its records are deleted after <?php echo (int) $activityInstallationDays; ?> days
                                like any longer figure.
                            </p>
                        <?php endif; ?>
                        <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/maintenance/retention'), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="activity-retention-organisation">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="choice" value="follow" id="alrOwnFollow"
                                       <?php echo $alrOwn === null ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="alrOwnFollow">Follow the installation
                                    (<?php echo htmlspecialchars(ActivityLogRetention::inWords($activityInstallationDays), ENT_QUOTES, 'UTF-8'); ?>)</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="choice" value="days" id="alrOwnDays"
                                       <?php echo $alrOwn !== null && $alrOwn !== ActivityLogRetention::NEVER ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="alrOwnDays">Delete records older than</label>
                                <input type="number" min="1" max="<?php echo (int) $alrOwnMax; ?>"
                                       class="form-control form-control-sm d-inline-block ms-1" style="width: 7rem;"
                                       name="days" aria-label="Number of days, for this organisation"
                                       value="<?php echo $alrOwn !== null && $alrOwn !== ActivityLogRetention::NEVER ? (int) $alrOwn : ''; ?>">
                                <span class="small">days</span>
                            </div>
                            <?php if ($alrInstNever === true || $alrOwn === ActivityLogRetention::NEVER): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="choice" value="never" id="alrOwnNever"
                                           <?php echo $alrOwn === ActivityLogRetention::NEVER ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="alrOwnNever"><strong>Never delete</strong></label>
                                </div>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-outline-primary btn-sm mt-2">Save for this organisation</button>
                        </form>
    <?php
};

// 🚧 THE GLOBAL-ADMINISTRATOR GATE — see "WHO MAY USE THE WEB UI" in the file
//    header. It sits BEFORE the sweep and BEFORE the preview counts are worked
//    out, so nothing installation-wide runs for anybody else: no sweep, no
//    installation-wide save, and the installation-wide row counts (which cover
//    every organisation, not just the visitor's own) are not shown either.
//
//    Matches how backup.php refuses (issue #495 fix, 13 September 2026): the
//    same wording style, the same 403 status, and a refusal recorded in the
//    activity log — but ONLY for a POST with a genuine form token, otherwise
//    a forged request from another website could fill the log with
//    refusals just by loading images from it. Simply opening the page is not
//    recorded.
//
//    #932 / D-118: an organisation's administrator OPENING the page now gets a
//    page (200) with their own organisation's activity-log form and nothing
//    else; any POST that reaches here from them is still refused (403).
if ($isGlobalAdmin === false) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === true) {
            Logger::activity(
                $postedAction === 'activity-retention-installation' ? 'ActivityLogRetentionRefused' : 'RetentionSweepRefused',
                $postedAction === 'activity-retention-installation'
                    ? 'Refused: how long the installation keeps the activity log may only be changed by a global administrator. Nothing was saved.'
                    : 'Refused: the audit-log retention sweep may only be run by a global administrator',
                $_SESSION['user_id'] ?? null
            );
        }

        http_response_code(403);
        // $pageTitle / $pageSection / $breadcrumbs are already set above, before
        // Auth::ensureSession() runs — reused here rather than repeated.
        require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
        ?>
        <h1 class="mb-3"><i class="fa-solid fa-broom me-2"></i>Audit Retention Sweeper</h1>
        <div class="alert alert-danger">
            This sweep deletes activity-log and error rows, and event
            registrations — including children's names, dates of birth, allergies
            and medical notes — across every organisation on this installation,
            not only yours. It also empties the scrambled sender address out of
            old anonymous check-ins, and removes erased donors' names from giving
            records past their keep-until date, again across every organisation.
            <strong>Where the installation is set to carry out offboarding erasures
            automatically, it also erases the personal details of people who have
            left and whose window for being brought back has passed</strong> &mdash;
            that part cannot be undone. So only a global administrator can run it,
            or change anything that applies to every organisation. Nothing has been changed.
        </div>
        <?php
        require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
        return;
    }

    // 🏢 An organisation's administrator opening the page: their own
    //    organisation's activity-log figure, and nothing installation-wide.
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
    ?>
    <h1 class="mb-3"><i class="fa-solid fa-list-check me-2"></i>How long the activity log is kept</h1>
    <?php if ($flashMsg !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
            <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <div class="card shadow-sm border-primary" id="activity-log-retention" data-activity-log-retention-card="organisation">
        <div class="card-body" data-activity-log-in-force="<?php echo (int) $activityDays; ?>">
            <?php // 🗣️ #940 round 3: "keeps every record for ever" said whose: this organisation's. ?>
            <p class="small text-muted" data-activity-log-explained="organisation">
                The activity log records who did what, and when. The nightly clear-out deletes records
                older than the figure below, if that scheduled job has been set up. <strong>&ldquo;Never delete&rdquo;</strong> keeps this
                organisation&rsquo;s records for ever, where this installation allows it. <?php echo htmlspecialchars($alrNeverCost, ENT_QUOTES, 'UTF-8'); ?>
            </p>
            <?php $alrOrganisationForm(); ?>
        </div>
    </div>
    <p class="small text-muted mt-3">
        Everything else on this page &mdash; the clear-out itself, and the figure every organisation is
        held to &mdash; acts across the whole installation, so only a global administrator can use it.
    </p>
    <?php
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
    return;
}

// -----------------------------------------------------------------------------
// 🚀 Execute on POST (with CSRF)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postedAction !== 'sweep') {
    // 🚫 An action this page does not know. Never the sweep, never a save.
    $flashMsg  = 'That request was not understood, so nothing was changed.';
    $flashType = 'danger';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $flashMsg  = 'Invalid or expired form token. Please try again.';
        $flashType = 'danger';
    } else {
        $result = run_retention_sweep();
        Logger::activity('AuditRetentionSweep', 'Admin sweep deleted ' . $result['totalDeleted'] . ' rows');
        // 🧾 EVERY FIGURE IN `totalDeleted` IS ITEMISED HERE, and transcripts
        //    joined the total when #714 gave them a period. The logged line
        //    above says "deleted N rows", so a breakdown that does not reach N
        //    leaves somebody unable to account for the difference — which is
        //    exactly why the emptied figures below are a separate sentence
        //    with the word "emptied", and why webhook payloads are NOT in the
        //    total at all. An independent check caught the total and the
        //    breakdown having come apart.
        $flashMsg  = 'Sweep complete. Deleted ' . $result['activityDeleted']
                   . ' activity log row(s), ' . $result['errorsDeleted'] . ' error row(s), '
                   . $result['registrationsDeleted'] . ' event registration(s) and '
                   . $result['transcriptsDeleted'] . ' transcript(s). '
                   // 🚪 Said as a separate sentence, with the word "emptied"
                   //    rather than "deleted", because nothing was deleted here
                   //    and the difference matters: the check-in rows and every
                   //    count on them are still there.
                   . 'Also stored the sender figure for ' . $result['checkinDaysStored']
                   . ' event-day(s) of anonymous check-ins and then emptied the scrambled address '
                   . 'out of ' . $result['checkinDetailCleared']
                   . ' check-in row(s). Those rows and their counts were not deleted.'
                   // 🧾 Its own sentence, and "removed the name", never
                   //    "deleted": every gift, declaration and statement
                   //    record is still there with its amount and date.
                   . ' Giving records: removed the name and address of ' . $result['givingIdentityCleared']
                   . ' erased donor(s) whose keep-until date had passed. Left alone: ' . $result['givingIdentityOnHold']
                   . ' because their organisation has a hold switched on, and ' . $result['givingIdentityWaiting']
                   . ' because their organisation has not yet confirmed its number of years.'
                   . ($result['givingIdentityFailed'] > 0
                       ? ' ' . $result['givingIdentityFailed'] . ' could NOT be cleared; nothing was changed for them, '
                           . 'the reason is in the server\'s error log, and the next run tries again.'
                       : '')
                   // 🗑️ Offboarding erasures, said plainly and ALWAYS, even
                   //    when the answer is none. This sweep carries out
                   //    irreversible erasures when the installation is set to
                   //    'after_undo_window', and the first version of this
                   //    message said nothing about them at all: a global
                   //    administrator pressed Run and was not told that people
                   //    had been erased. Saying "none" is also the quickest
                   //    way to see that the mode is not switched on.
                   . ' Offboarding erasures: '
                   . ($result['offboardErasureMode'] !== 'after_undo_window'
                       ? 'not carried out here &mdash; this installation leaves them waiting for somebody to run by hand.'
                       : 'carried out for ' . $result['offboardErasuresRun'] . ' person(s)'
                           . ($result['offboardErasuresWaiting'] > 0
                               ? ', ' . $result['offboardErasuresWaiting'] . ' left for later (a run was already in flight)'
                               : '')
                           . ($result['offboardErasuresFailed'] > 0
                               ? ', and ' . $result['offboardErasuresFailed'] . ' could NOT be carried out; '
                                   . 'those requests have been put back for the next run.'
                               : '.'))
                   // 🧹 Part-written PDFs (#721). FILES, so its own sentence
                   //    and never folded into the row figures above — mixing
                   //    the two is what made the total and the breakdown come
                   //    apart once already. Said only when there was something
                   //    to say, because on a healthy installation this is
                   //    always nothing and a standing "0 files" line trains
                   //    people to stop reading the message.
                   . ($result['partFilesRemoved'] > 0 || $result['partFilesLeft'] > 0
                       ? ' Part-written PDF files left behind by an interrupted run: removed '
                           . $result['partFilesRemoved']
                           . ($result['partFilesLeft'] > 0
                               ? ', and ' . $result['partFilesLeft'] . ' could NOT be removed '
                                   . '(check the folder\'s permissions under _uploads).'
                               : '.')
                       : '');
        // A sweep that could not do part of its work is not a plain success.
        $flashType = ($result['givingIdentityFailed'] > 0 || $result['offboardErasuresFailed'] > 0
            || $result['partFilesLeft'] > 0)
            ? 'warning'
            : 'success';
    }
}

// -----------------------------------------------------------------------------
// 📊 Preview counts (what WOULD be deleted right now)
// -----------------------------------------------------------------------------
$preview = preview_retention_counts();

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-broom me-2"></i>Audit Retention Sweeper</h1>
        <?php // 📜 #940: this said "older than the configured window", which ignored an activity log
              //    whose period is "never delete" (D-117): the clear-out deletes none of those. ?>
        <p class="text-secondary mb-0" data-retention-subtitle="1">Hard-delete activity-log and error rows older than the period that applies to each: its organisation&rsquo;s, or the installation&rsquo;s for a row that belongs to none. Activity-log rows whose period is &ldquo;Never delete&rdquo; are not deleted.</p>
    </div>
    <a href="<?php echo htmlspecialchars(Site::url('admin'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back to Admin
    </a>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h6"><i class="fa-solid fa-list-check me-1"></i>Activity Logs (<code>tblActivityLogs</code>)</h2>
                <?php /* 📜 #932: either figure may be "never" (0). Said in words, never as
                         "0 days", which would read as "deleted at once" — the opposite. */ ?>
                <p class="text-muted small mb-2" data-activity-log-in-force="<?php echo (int) $activityDays; ?>">
                    <?php if ($activityDays === ActivityLogRetention::NEVER): ?>
                        <strong>Never deleted</strong> for your organisation
                    <?php else: ?>
                        Kept for <strong><?php echo (int) $activityDays; ?> days</strong> for your organisation
                    <?php endif; ?>
                    (setting: <code>audit.retentionDays</code>, set
                    <a href="#activity-log-retention">below</a>).
                    Each organisation uses its own figure, held to what this installation allows:
                    <?php if ($activityInstallationDays === ActivityLogRetention::NEVER): ?>
                        <strong>any number of days, or never deleted</strong>.
                    <?php else: ?>
                        <strong>at most <?php echo (int) $activityInstallationDays; ?> days</strong>.
                    <?php endif; ?>
                    <?php // 📜 #940: this named "written before the organisation was decided, or by
                          //    a scheduled job" as the records with no organisation. Read from the code,
                          //    not measured: Logger::activity() writes Site::id(), which is a whole
                          //    number on every request and job (it starts at 1), so such records are
                          //    put under an organisation. What leaves a record with none is its
                          //    organisation being removed (`fk_logs_site` is ON DELETE SET NULL), an
                          //    older record, or a copy ActivityLogCleaner makes of one. ?>
                    Records that belong to no organisation &mdash; for example one whose
                    organisation has since been removed &mdash; follow the installation's figure.
                </p>
                <p class="mb-0">Rows that would be deleted now, across every organisation: <strong><?php echo number_format($preview['activity']); ?></strong></p>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h6"><i class="fa-solid fa-triangle-exclamation me-1"></i>Error Log (<code>tblErrors</code>)</h2>
                <p class="text-muted small mb-2">
                    Kept for <strong><?php echo $errorDays; ?> days</strong> for your organisation
                    (setting: <code>errors.retentionDays</code>).
                    Each organisation uses its own figure, held to the
                    <strong><?php echo $errorInstallationDays; ?> days</strong> this installation
                    allows. Faults recorded with no organisation follow the installation's figure.
                </p>
                <p class="mb-0">Rows that would be deleted now, across every organisation: <strong><?php echo number_format($preview['errors']); ?></strong></p>
            </div>
        </div>
    </div>
    <div class="col-12" id="activity-log-retention">
        <div class="card shadow-sm border-primary" data-activity-log-retention-card="1">
            <div class="card-body">
                <h2 class="h6"><i class="fa-solid fa-list-check me-1"></i>How long the activity log is kept</h2>
                <?php // 🗣️ #940 round 3: this said "'Never delete' keeps every record for ever". Untrue of
                      //    the installation's form below: there "Never delete" is the most an organisation may
                      //    choose (D-117), and an organisation with its own number of days is still cleared
                      //    after it (measured by the independent check: installation never, organisation 30,
                      //    and that organisation's 40- to 5000-day rows were deleted). ?>
                <p class="small text-muted" data-activity-log-explained="installation">
                    The activity log records who did what, and when. The nightly clear-out deletes records
                    older than the figures below, if that scheduled job has been set up. <strong>&ldquo;Never delete&rdquo;</strong> for every
                    organisation is the most an organisation may choose, not a rule for all: it keeps for
                    ever the records that belong to no organisation and those of every organisation that has
                    not chosen a number of days of its own, while an organisation that has chosen a number of
                    days is still cleared after it. &ldquo;Never delete&rdquo; for one organisation keeps that
                    organisation&rsquo;s records for ever, where the installation allows it. <?php echo htmlspecialchars($alrNeverCost, ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <div class="row g-4">
                    <div class="col-lg-6">
                        <h3 class="h6">For every organisation on this installation</h3>
                        <p class="small text-muted">
                            Records that belong to no organisation follow this figure, and it is the most any
                            organisation may choose. An organisation may choose &ldquo;Never delete&rdquo; only
                            when this is &ldquo;Never delete&rdquo; too. A figure shorter than one an organisation
                            has already chosen for itself is refused and those organisations are named: they lower
                            their own figure first, so nothing of theirs is deleted sooner than they chose.
                        </p>
                        <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/maintenance/retention'), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="activity-retention-installation">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="choice" value="days" id="alrInstDays"
                                       <?php echo $alrInstNever === false ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="alrInstDays">Delete records older than</label>
                                <input type="number" min="1" max="<?php echo ActivityLogRetention::HARD_MAX_DAYS; ?>"
                                       class="form-control form-control-sm d-inline-block ms-1" style="width: 7rem;"
                                       name="days" aria-label="Number of days, for every organisation"
                                       value="<?php echo $alrInstNever === true ? '' : (int) $activityInstallationDays; ?>">
                                <span class="small">days (1 to <?php echo ActivityLogRetention::HARD_MAX_DAYS; ?>)</span>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="choice" value="never" id="alrInstNever"
                                       <?php echo $alrInstNever === true ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="alrInstNever"><strong>Never delete</strong></label>
                            </div>
                            <button type="submit" class="btn btn-outline-primary btn-sm">Save for every organisation</button>
                        </form>
                    </div>
                    <div class="col-lg-6">
                        <?php $alrOrganisationForm(); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- 🧒 Event registrations. Given the full width and a warning colour
         deliberately: this is the only one of the three that holds information
         about a named child - their date of birth, allergies and medical notes,
         beside a parent's telephone number and email address. An administrator
         should not be able to run this sweep without having seen it. -->
    <div class="col-12">
        <div class="card shadow-sm border-warning">
            <div class="card-body">
                <h2 class="h6"><i class="fa-solid fa-child-reaching me-1"></i>Event Registrations (<code>tblEventRegistrations</code>)</h2>
                <p class="text-muted small mb-2">
                    <?php // 🧒 #816: asked of the one reader the clear-out uses, so this
                          //    card and the job cannot disagree about this organisation's period. ?>
                    <?php $regDays = \Portal\Core\RegistrationRetention::daysForSite(\Portal\Core\Site::id()); ?>
                    <?php $regRuns = \Portal\Core\RegistrationRetention::clearOutRunsForSite(\Portal\Core\Site::id()); ?>
                    <?php if ($regRuns === false): ?>
                        <strong data-registration-clear-out-off="1">The clear-out is switched off for your organisation, so none are deleted</strong>
                        (setting: <code>events.registrationRetentionRun</code>). When it is on, they are
                    <?php endif; ?>
                    <?php echo $regDays === 0 ? '<strong>kept indefinitely</strong> for your organisation' : '<strong>kept for ' . $regDays . ' days</strong> after the event for your organisation'; ?>
                    (set at
                    <a href="<?php echo htmlspecialchars(\Portal\Core\Site::url('admin/settings/registrations'), ENT_QUOTES, 'UTF-8'); ?>">Admin, Registration retention</a>).
                    A single event can have its own number of days, which wins over this one.
                    Each organisation uses its own period.
                </p>
                <p class="mb-2">Registrations currently eligible for deletion: <strong><?php echo number_format($preview['registrations']); ?></strong></p>
                <p class="small text-muted mb-0">
                    These hold a child's name, date of birth, allergies and medical notes, with a
                    parent's telephone number and email address. Most are submitted by people with no
                    account, so they cannot be found by a &ldquo;delete everything you hold about
                    me&rdquo; request &mdash; nobody should have to ask, which is why they are removed
                    on a time limit instead.
                </p>
            </div>
        </div>
    </div>
    <!-- 🚪 Anonymous check-ins. Given its own card because it is the one part
         of this sweep that does NOT delete anything, and somebody pressing the
         button should know that before they press it rather than afterwards. -->
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h6"><i class="fa-solid fa-door-open me-1"></i>Anonymous check-in detail (<code>tblAnonymousCheckins</code>)</h2>
                <p class="text-muted small mb-2">
                    <?php /* 🚧 #685 — ASKED OF THE READER THE SWEEP ITSELF USES, not of the
                             merged settings array. `readRetentionDays()` holds an organisation
                             to what the installation allows, so an organisation that stored a
                             longer period keeps the stored number in `$SETTINGS` while the
                             sweep uses the shorter one. Printing the merged value here would
                             have made this card state a period the clear-out does not use —
                             on the very page whose job is to say what the clear-out will do. */ ?>
                    <?php
                    /* 🚧 #685, THE SECOND HALF — AND THE SENTENCE THAT USED TO SIT HERE WAS
                           FALSE ON EVERY INSTALLATION A CUSTOMER CAN HAVE. It read "Each
                           organisation uses its own setting, and 0 means keep for ever",
                           which was true before #685 capped the reader and was left inside
                           the paragraph #685 rewrote.

                           Measured: 0 is the LARGEST value for this setting, so it survives
                           ONLY where the installation itself allows for ever. With the
                           installation at 90 — which is what `full_schema.sql` seeds, so it
                           is every fresh installation — an organisation storing 0 is cleared
                           at 90 DAYS, not never. (Installation 90 / own 0 -> 90;
                           installation 0 / own 1000 -> 1000.)

                           That is this repository's named "a hedge that covers one condition
                           and ASSERTS the other" shape, on the one page whose job is to say
                           what the clear-out will do. Both conditions are stated now, and the
                           installation's own figure is PRINTED rather than described, so the
                           sentence cannot drift away from it. */
                    $acInstallationAllows = \Portal\Core\SettingLimit::installationAllows(
                        \Portal\Core\AnonymousCheckins::RETENTION_KEY,
                        \Portal\Core\AnonymousCheckins::DEFAULT_RETENTION_DAYS,
                        \Portal\Core\AnonymousCheckins::MAX_RETENTION_DAYS,
                        true
                    );
                    $acForEverAllowed = \Portal\Core\SettingLimit::installationAllowsForEver(
                        \Portal\Core\AnonymousCheckins::RETENTION_KEY,
                        \Portal\Core\AnonymousCheckins::DEFAULT_RETENTION_DAYS,
                        \Portal\Core\AnonymousCheckins::MAX_RETENTION_DAYS
                    );
                    ?>
                    Kept for <strong><?php echo (int) \Portal\Core\AnonymousCheckins::readRetentionDays(Site::id()); ?> days</strong>
                    for your organisation (setting: <code>attend.detailRetentionDays</code>,
                    held to what this installation allows).
                    Each organisation uses its own setting.
                    <?php if ($acForEverAllowed === true) { ?>
                        This installation allows &ldquo;keep for ever&rdquo;, so an organisation
                        that stores <code>0</code> really does keep this detail indefinitely.
                    <?php } else { ?>
                        <strong>This installation allows at most
                        <?php echo (int) $acInstallationAllows; ?> days, so <code>0</code> does
                        NOT mean keep for ever here</strong> &mdash; an organisation that stores
                        <code>0</code> has its detail emptied after
                        <?php echo (int) $acInstallationAllows; ?> days, like any longer figure.
                        &ldquo;For ever&rdquo; is the largest value this setting can hold, so it
                        is allowed only where the installation itself allows it.
                    <?php } ?>
                </p>
                <p class="mb-2">Check-in rows whose detail would be emptied now: <strong><?php echo number_format($preview['checkinDetail']); ?></strong></p>
                <p class="small text-muted mb-0">
                    <strong>Nothing is deleted here.</strong> The rows stay, with their counts, their
                    headcounts, how each check-in arrived and when. What is emptied is the browser
                    description and a scrambled version of the sender's internet address &mdash; two
                    things nothing in the portal ever shows. An anonymous check-in has no link to any
                    person, so a &ldquo;delete everything you hold about me&rdquo; request cannot reach
                    one; the time limit is the answer instead. Before each day's detail goes, the
                    &ldquo;probably how many different senders&rdquo; figure for that day is worked out
                    and stored, so the number an organisation sees does not change afterwards.
                </p>
            </div>
        </div>
    </div>
    <!-- 🧾 Giving records that kept an erased donor's name. Its own card,
         like the check-ins above, because nothing is deleted here either,
         and because the three "left alone" figures are how an administrator
         finds out that an organisation still has to confirm its figure. -->
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h6"><i class="fa-solid fa-receipt me-1"></i>Giving records that kept an erased donor's name (<code>tblGivingKeptDonors</code>)</h2>
                <p class="text-muted small mb-2">
                    Each organisation sets how long, at
                    <a href="<?php echo htmlspecialchars(Site::url('admin/settings/giving-records'), ENT_QUOTES, 'UTF-8'); ?>">Admin, Settings, Giving records</a>.
                    Counted from the end of the financial year of the donor's last kept gift.
                </p>
                <p class="mb-1">Erased donors whose name would be removed now: <strong><?php echo number_format($preview['givingIdentityDue']); ?></strong></p>
                <p class="mb-1">Left alone because their organisation has a hold switched on: <strong><?php echo number_format($preview['givingIdentityOnHold']); ?></strong></p>
                <p class="mb-1">Left alone because their organisation has not yet confirmed its number of years: <strong><?php echo number_format($preview['givingIdentityWaiting']); ?></strong></p>
                <p class="mb-1">Not yet due: <strong><?php echo number_format($preview['givingIdentityNotDue']); ?></strong></p>
                <p class="mb-2">Left alone because the person's account has not been emptied yet (run that erasure request again at Admin, Erasure requests): <strong><?php echo number_format($preview['givingIdentityUnfinished']); ?></strong></p>
                <p class="small text-muted mb-0">
                    <strong>Nothing is deleted here.</strong> When a donor asks to be erased, most
                    organisations still have to keep some giving records with the donor's name for a
                    number of years. When that time is up, this sweep removes the name, the home
                    address and the link to the donor from those records, and deletes the statement
                    files kept with them. Every gift, declaration and statement record stays, with its
                    amount and date. A name is never removed while its organisation has a hold on, or
                    before an administrator there has confirmed the number of years.
                </p>
            </div>
        </div>
    </div>
</div>

<form method="post" action="<?php echo htmlspecialchars(Site::url('admin/maintenance/retention'), ENT_QUOTES, 'UTF-8'); ?>"
      data-confirm="About <?php echo number_format($preview['activity'] + $preview['errors']); ?> log row(s) and <?php echo number_format($preview['registrations']); ?> event registration(s) are currently eligible, including any children's details they hold, and <?php echo number_format($preview['checkinDetail']); ?> anonymous check-in row(s) would have their scrambled sender address emptied out (those rows and their counts are kept). <?php echo number_format($preview['givingIdentityDue']); ?> erased donor(s) would have their name and address removed from giving records whose keep-until date has passed (the gifts and their amounts are kept). The exact numbers may differ slightly, because more records become eligible as time passes. This cannot be undone. Continue?"
      data-confirm-destructive="true">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <button type="submit" class="btn btn-warning" <?php echo ($preview['activity'] + $preview['errors'] + $preview['registrations'] + $preview['checkinDetail'] + $preview['givingIdentityDue']) === 0 ? 'disabled' : ''; ?>>
        <i class="fa-solid fa-broom me-1"></i> Run Sweep Now
    </button>
    <?php /* #932: the activity log's period is no longer changed in the general
             settings editor (it refuses the key); it is set in the card above.
             The error log's period still is, so that link stays, named for it. */ ?>
    <a href="#activity-log-retention" class="btn btn-outline-secondary">Activity-log period</a>
    <a href="<?php echo htmlspecialchars(Site::url('settings'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">Error-log period and other settings</a>
</form>

<div class="card mt-4 border-0 bg-body-tertiary">
    <div class="card-body">
        <h2 class="h6 mb-2"><i class="fa-solid fa-clock me-1"></i>Cron-style execution</h2>
        <p class="small mb-2">
            For scheduled execution (every night, say), set
            <code>maintenance.cronToken</code> in Settings to a long random string, then have your
            cron / scheduled task hit:
        </p>
        <pre class="small mb-0"><code>curl -s "https://&lt;your-portal&gt;/cron/retention-sweep?token=&lt;TOKEN&gt;"</code></pre>
        <p class="small text-muted mb-0 mt-2">
            The token is checked with <code>hash_equals</code> (constant-time compare). An empty token switches the scheduled job off.
            The old address, this page with <code>?cron=1</code> added, no longer runs the sweep: this page now always asks for a sign-in.
        </p>
    </div>
</div>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
