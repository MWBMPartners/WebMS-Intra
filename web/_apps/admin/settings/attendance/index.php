<?php
// Path: _apps/admin/settings/attendance/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Who may see the anonymous check-in counts, and the reports page ⚙️
 * (#525, plus #529 added 20 September 2026)
 * -----------------------------------------------------------------------------
 * Three settings on one small page:
 *
 *   attend.anonCounts.visibleTo   who may see the door figures
 *   attend.detailRetentionDays    how long the scrambled sender address is
 *                                 kept (#530: a browser-description column
 *                                 used to be kept on the same timer; it was
 *                                 dropped by migration 201, since nothing
 *                                 ever read it)
 *   attend.reports.visibleTo      who may open the attendance reports page
 *                                 at all (#529) — a SEPARATE question from
 *                                 the door figures above: a member can be
 *                                 let onto the page without being shown the
 *                                 anonymous section, and the anonymous
 *                                 section's own setting can never widen who
 *                                 may open the page in the first place.
 *
 * WHY THIS PAGE EXISTS AT ALL
 * ---------------------------
 * The owner asked for the visibility choice to be settable by each
 * organisation. The portal's ordinary settings editor at /settings cannot do
 * that. Its duplicate check is "is there already a row with this key for this
 * organisation OR for the whole installation" — and because this key IS seeded
 * for the whole installation, that check matches and it refuses with "already
 * exists". Separately, its edit branch refuses an installation-wide row to
 * anybody who is not a global administrator.
 *
 * So without this page the honest description of the setting would be "one
 * value for the whole installation, changeable only by a global administrator",
 * which is not what was asked for. This page writes a real (non-NULL) siteID
 * row, which the settings table's unique key handles perfectly well — the same
 * proven pattern /venues/settings uses.
 *
 * WHO MAY CHANGE WHAT
 * -------------------
 * An administrator of an organisation sets that organisation's own value. Only
 * a GLOBAL administrator changes the installation-wide default, because that
 * default is what every other organisation on the server inherits. Anybody else
 * sees it on the page, read-only, with the reason. The owner confirmed this
 * split on 18 September 2026.
 *
 * WHAT THIS PAGE CANNOT DO
 * ------------------------
 * Once an organisation has set its own value it keeps it; there is no button
 * here to throw that choice away and go back to inheriting the
 * installation-wide default. That is a real limitation, said plainly on the
 * page rather than left to be discovered. Issue #526 rebuilds this same
 * resolver with venue and event levels underneath it, and is the right place to
 * add "follow the level above" properly rather than bolting it on here.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/525
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\AnonymousCheckins;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\AttendanceAccess;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();

// 🌍 Only a global administrator may change the installation-wide default.
//    Worked out once and used by both the page and the save handler's own
//    copy of the same test, so the two can never disagree about who is shown a
//    control they would then be refused.
$mayChangeInstallationWide = App::isRootAdmin();

/**
 * Read one settings row at one exact level.
 *
 * `App::settingForSite()` deliberately hides which level answered, because
 * almost every caller only wants the effective value. This page has to SHOW
 * the difference, so it asks for each level separately.
 *
 * @param \mysqli $db     An open connection.
 * @param string  $key    The settings key.
 * @param int|null $siteId The organisation, or null for the installation-wide row.
 *
 * @return string|null The stored value, or null when there is no row at that level.
 */
function attendance_settings_read_level(\mysqli $db, string $key, ?int $siteId): ?string
{
    if ($siteId === null) {
        $stmt = $db->prepare(
            'SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('s', $key);
    } else {
        $stmt = $db->prepare(
            'SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID = ? LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('si', $key, $siteId);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row !== null ? (string) $row['settingValue'] : null;
}

$ownVisibility    = attendance_settings_read_level($mysqli, AnonymousCheckins::VISIBILITY_KEY, $siteId);
$globalVisibility = attendance_settings_read_level($mysqli, AnonymousCheckins::VISIBILITY_KEY, null);
$ownRetention     = attendance_settings_read_level($mysqli, AnonymousCheckins::RETENTION_KEY, $siteId);
$globalRetention  = attendance_settings_read_level($mysqli, AnonymousCheckins::RETENTION_KEY, null);

// 🔒 #529 — the SEPARATE "who may open the reports page at all" setting.
$ownReportsVisibility    = attendance_settings_read_level($mysqli, AttendanceAccess::VISIBILITY_KEY, $siteId);
$globalReportsVisibility = attendance_settings_read_level($mysqli, AttendanceAccess::VISIBILITY_KEY, null);

// What is actually in force for this organisation right now, and where it came
// from. Both are shown, because "it says administrators only" and "it says
// administrators only because nobody here has chosen anything" are different
// things to know.
$effectiveVisibility = AnonymousCheckins::readVisibilityChoice($siteId);
$effectiveRetention  = AnonymousCheckins::readRetentionDays($siteId);
$visibilityIsOwn     = ($ownVisibility !== null);
$retentionIsOwn      = ($ownRetention !== null);

$effectiveReportsVisibility = AttendanceAccess::readChoice($siteId);
$reportsVisibilityIsOwn     = ($ownReportsVisibility !== null);

// A short sentence under each choice. Kept here beside the choices themselves
// so a future fourth choice cannot be added without somebody having to write
// what it means.
$choiceExplanations = [
    AnonymousCheckins::VISIBLE_ADMINS =>
        'Nobody but an administrator sees the door figures. This is the narrowest choice and the one '
        . 'a new installation starts with.',
    AnonymousCheckins::VISIBLE_ADMINS_COORDINATORS =>
        'Administrators, plus the people set as coordinators of that particular event. A coordinator '
        . 'sees the figures for their own event only. On a page that covers the whole organisation '
        . 'there is no single event, so this behaves the same as "administrators only" there.',
    AnonymousCheckins::VISIBLE_PAGE =>
        'Whoever can already open the page sees the figures on it. The figures never widen who can '
        . 'open a page — they only follow the rule that page already has.',
];

// 🔒 #529 — explanations for the SEPARATE "who may open the reports page"
// choices. Kept beside the choices themselves for the same reason as above.
$reportsChoiceExplanations = [
    AttendanceAccess::VISIBLE_ADMINS =>
        'Nobody but an administrator can open the attendance reports page at all. This is the '
        . 'narrowest choice, and the one a new installation starts with.',
    AttendanceAccess::VISIBLE_ADMINS_COORDINATORS =>
        'Administrators, plus anyone who currently coordinates one of your organisation\'s events. '
        . 'This cannot tell a coordinator of an event happening next week from one that ended two '
        . 'years ago — any event they coordinate that has not been deleted counts.',
    AttendanceAccess::VISIBLE_MEMBERS =>
        'Any member of your organisation can open the reports page.',
];

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');

$pageTitle   = 'Anonymous Check-in Counts';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Attendance' => '/attendance', 'Anonymous check-in counts' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-door-open me-2"></i>Anonymous check-in counts</h1>
    <a href="/attendance" class="btn btn-outline-secondary btn-sm">
        <i class="fa-solid fa-arrow-left me-1"></i>Back to attendance
    </a>
</div>

<p class="text-secondary">
    Somebody can check in to an event without signing in, by scanning a QR code or pressing a button
    on a kiosk at the door. These settings decide who in your organisation may see the resulting
    figures, and how long the technical detail behind them is kept.
</p>

<!-- 📊 What is in force right now, and where it came from. -->
<div class="card mb-4 border-primary">
    <div class="card-body">
        <h2 class="h6 mb-2"><i class="fa-solid fa-circle-info me-1"></i>In force for your organisation now</h2>
        <p class="mb-1">
            Who may see the figures:
            <strong><?php echo htmlspecialchars(
                AnonymousCheckins::VISIBILITY_CHOICES[$effectiveVisibility] ?? $effectiveVisibility,
                ENT_QUOTES,
                'UTF-8'
            ); ?></strong>
            &mdash;
            <?php echo $visibilityIsOwn === true
                ? 'your organisation\'s own choice.'
                : 'inherited from the installation-wide default, because your organisation has not chosen anything.'; ?>
        </p>
        <p class="mb-1">
            Technical detail kept for:
            <strong><?php echo $effectiveRetention === 0
                ? 'for ever'
                : ((int) $effectiveRetention) . ' days'; ?></strong>
            &mdash;
            <?php echo $retentionIsOwn === true
                ? 'your organisation\'s own choice.'
                : 'inherited from the installation-wide default.'; ?>
        </p>
        <p class="mb-0">
            Who may open the reports page at all:
            <strong><?php echo htmlspecialchars(
                AttendanceAccess::VISIBILITY_CHOICES[$effectiveReportsVisibility] ?? $effectiveReportsVisibility,
                ENT_QUOTES,
                'UTF-8'
            ); ?></strong>
            &mdash;
            <?php echo $reportsVisibilityIsOwn === true
                ? 'your organisation\'s own choice.'
                : 'inherited from the installation-wide default, because your organisation has not chosen anything.'; ?>
        </p>
    </div>
</div>

<!-- 🏢 This organisation's own choice. -->
<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3">Your organisation's setting</h2>

        <form method="post" action="/admin/settings/attendance/save">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="scope" value="site">

            <fieldset class="mb-4">
                <legend class="h6">Who may see the figures</legend>
                <?php foreach (AnonymousCheckins::VISIBILITY_CHOICES as $value => $label): ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio"
                               name="visibleTo"
                               id="visibleTo_<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"
                               value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"
                               <?php echo $effectiveVisibility === $value ? 'checked' : ''; ?>>
                        <label class="form-check-label"
                               for="visibleTo_<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>">
                            <strong><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></strong>
                            <span class="d-block small text-muted">
                                <?php echo htmlspecialchars($choiceExplanations[$value] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </fieldset>

            <div class="mb-4" style="max-width: 24rem;">
                <label class="form-label" for="retentionDays">
                    Keep the technical detail for this many days
                </label>
                <input type="number" min="0" max="3650" class="form-control form-control-sm"
                       id="retentionDays" name="retentionDays"
                       value="<?php echo (int) $effectiveRetention; ?>">
                <div class="form-text">
                    The technical detail is a scrambled version of the sender's internet address. It
                    is never shown anywhere. After this many days it is emptied out, while the counts,
                    the headcounts, how each check-in arrived and when it happened all stay exactly as
                    they are. Enter <strong>0</strong> to keep the detail for ever.
                </div>
            </div>

            <fieldset class="mb-4">
                <legend class="h6">Who can see the attendance reports page (#529)</legend>
                <p class="small text-muted">
                    A SEPARATE question from the door figures above: this decides who may open the
                    reports page at all. Somebody refused by this choice never sees the door figures
                    setting either, whatever it says.
                </p>
                <?php foreach (AttendanceAccess::VISIBILITY_CHOICES as $value => $label): ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio"
                               name="reportsVisibleTo"
                               id="reportsVisibleTo_<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"
                               value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"
                               <?php echo $effectiveReportsVisibility === $value ? 'checked' : ''; ?>>
                        <label class="form-check-label"
                               for="reportsVisibleTo_<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>">
                            <strong><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></strong>
                            <span class="d-block small text-muted">
                                <?php echo htmlspecialchars($reportsChoiceExplanations[$value] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </fieldset>

            <button type="submit" class="btn btn-primary btn-sm">
                <i class="fa-solid fa-check me-1"></i>Save for my organisation
            </button>
        </form>

        <p class="small text-muted mt-3 mb-0">
            Once your organisation has a setting of its own it keeps it. There is no button here to
            throw that choice away and go back to following the installation-wide default; adding one
            properly belongs with issue #526, which is making these settings work at venue and event
            level too.
        </p>
    </div>
</div>

<!-- 🌍 The installation-wide default. -->
<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5 mb-2">The installation-wide default</h2>
        <p class="small text-muted">
            This is what every organisation on this installation follows until it chooses something of
            its own. Changing it affects other organisations, not only yours, which is why only a
            global administrator can.
        </p>

        <?php if ($mayChangeInstallationWide === true): ?>
            <form method="post" action="/admin/settings/attendance/save">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="scope" value="global">

                <fieldset class="mb-3">
                    <legend class="h6">Who may see the figures</legend>
                    <?php foreach (AnonymousCheckins::VISIBILITY_CHOICES as $value => $label): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="radio"
                                   name="visibleTo"
                                   id="globalVisibleTo_<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"
                                   value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"
                                   <?php echo AnonymousCheckins::visibilityChoice($globalVisibility) === $value ? 'checked' : ''; ?>>
                            <label class="form-check-label"
                                   for="globalVisibleTo_<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </fieldset>

                <div class="mb-3" style="max-width: 24rem;">
                    <label class="form-label" for="globalRetentionDays">
                        Keep the technical detail for this many days
                    </label>
                    <input type="number" min="0" max="3650" class="form-control form-control-sm"
                           id="globalRetentionDays" name="retentionDays"
                           value="<?php echo $globalRetention !== null ? (int) $globalRetention : AnonymousCheckins::DEFAULT_RETENTION_DAYS; ?>">
                </div>

                <fieldset class="mb-3">
                    <legend class="h6">Who can see the attendance reports page (#529)</legend>
                    <?php foreach (AttendanceAccess::VISIBILITY_CHOICES as $value => $label): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="radio"
                                   name="reportsVisibleTo"
                                   id="globalReportsVisibleTo_<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"
                                   value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"
                                   <?php echo AttendanceAccess::choice($globalReportsVisibility) === $value ? 'checked' : ''; ?>>
                            <label class="form-check-label"
                                   for="globalReportsVisibleTo_<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </fieldset>

                <button type="submit" class="btn btn-outline-warning btn-sm">
                    <i class="fa-solid fa-globe me-1"></i>Save the installation-wide default
                </button>
            </form>
        <?php else: ?>
            <!-- 👀 Shown in place of the form, so nobody fills it in only to be
                 refused afterwards. Hiding the form is a courtesy, not the
                 control: the refusal in the save handler is what enforces the
                 rule. -->
            <p class="mb-1">
                Who may see the figures:
                <strong><?php echo htmlspecialchars(
                    AnonymousCheckins::VISIBILITY_CHOICES[AnonymousCheckins::visibilityChoice($globalVisibility)],
                    ENT_QUOTES,
                    'UTF-8'
                ); ?></strong>
            </p>
            <p class="mb-2">
                Technical detail kept for:
                <strong><?php
                    $globalDaysShown = $globalRetention !== null
                        ? (int) $globalRetention
                        : AnonymousCheckins::DEFAULT_RETENTION_DAYS;
                    echo $globalDaysShown <= 0 ? 'for ever' : $globalDaysShown . ' days';
                ?></strong>
            </p>
            <p class="mb-2">
                Who can see the attendance reports page:
                <strong><?php echo htmlspecialchars(
                    AttendanceAccess::VISIBILITY_CHOICES[AttendanceAccess::choice($globalReportsVisibility)],
                    ENT_QUOTES,
                    'UTF-8'
                ); ?></strong>
            </p>
            <div class="alert alert-info small mb-0">
                <i class="fa-solid fa-circle-info me-2"></i>
                This default is shared by <strong>every organisation</strong> on this installation, not
                only yours, so only a global administrator can change it. Your own organisation's
                setting above is unaffected.
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ℹ️ The two things this setting does NOT do. Both have caught people out. -->
<div class="card border-0 bg-body-tertiary">
    <div class="card-body">
        <h2 class="h6 mb-2"><i class="fa-solid fa-triangle-exclamation me-1"></i>Two things worth knowing</h2>

        <p class="small mb-2">
            <strong>Administrators always see the figures</strong>, whatever is chosen above. None of
            these choices hides anything from an administrator.
        </p>

        <p class="small mb-2">
            <strong>Seeing a figure and being able to download it are two different permissions.</strong>
            The spreadsheet download of the anonymous counts stays administrators-only whatever is
            chosen here. A file leaves the building, gets forwarded, and is still sitting in somebody's
            downloads folder after a setting has been tightened again &mdash; and unlike the screens,
            it names the events.
        </p>

        <!-- Wording corrected again on 20 September 2026, now that #529 has shipped: the reports
             page's own gate NOW checks membership of this organisation (plus its OWN "who may open
             the reports page" setting, above), so "anyone who can already open the page" is narrower
             than it used to be. It is still not automatically the same group as "members of your
             organisation" — the reports page's own setting can narrow it further, to administrators
             only or to administrators-and-coordinators — so the wording below still has to say what
             it actually means rather than assume the two settings are always in step. -->
        <p class="small mb-0">
            <strong>On the attendance reports page, "anyone who can already open the page" means
            whoever the reports page's OWN "who can see the attendance reports page" setting above
            currently admits</strong> &mdash; which is always at least a member of your organisation,
            and may be narrower still (administrators only, for example) depending on that setting.
            Choosing this door-figures option here then shows your organisation's totals and a line
            per month to everyone that reaches. It never shows an event name, and the download stays
            administrators-only.
        </p>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
