<?php
// Path: public_html/attendance/record.php
/**
 * -----------------------------------------------------------------------------
 * Attendance Tracker — Record / Edit Attendance 📝
 * -----------------------------------------------------------------------------
 * Form for recording headcounts for a service or event. Supports:
 *   - Selecting a service type (or linking to an existing calendar event)
 *   - Adding multiple count rows for different age groups/categories
 *   - Editing existing sessions via ?edit=<sessionID>
 *
 * @package   Portal\Attendance
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.3.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;

// 📌 Page metadata
$pageTitle   = 'Record Attendance';
$pageSection = 'attendance';
$breadcrumbs = ['Dashboard' => '/', 'Attendance' => '/attendance', 'Record' => ''];

// 🛡️ Auth check
Auth::ensureSession();
if (Auth::check() === false) {
    Auth::requireLogin();
    return;
}

// -----------------------------------------------------------------------------
// ✏️ Check if editing an existing session
// -----------------------------------------------------------------------------
// 🌐 Multi-site scope
$siteId = Site::id();

$editId      = (int) ($_GET['edit'] ?? 0);
$editSession = null;
$editCounts  = [];

if ($editId > 0) {
    $stmt = $mysqli->prepare(
        'SELECT s.*, st.typeName FROM tblAttendanceSessions s '
        . 'INNER JOIN tblAttendanceServiceTypes st ON st.serviceTypeID = s.serviceTypeID '
        . 'WHERE s.sessionID = ? AND s.isDeleted = 0 AND s.siteID = ? LIMIT 1'
    );
    if ($stmt !== false) {
        $stmt->bind_param('ii', $editId, $siteId);
        $stmt->execute();
        $editSession = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if ($editSession !== null) {
        // 📋 Fetch existing count rows
        $stmt = $mysqli->prepare(
            'SELECT countID, groupLabel, headcount, sortOrder '
            . 'FROM tblAttendanceCounts WHERE sessionID = ? ORDER BY sortOrder, countID'
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $editId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($r = $result->fetch_assoc()) {
                $editCounts[] = $r;
            }
            $stmt->close();
        }

        $pageTitle   = 'Edit Attendance — ' . $editSession['typeName'];
        $breadcrumbs = ['Dashboard' => '/', 'Attendance' => '/attendance', 'Edit' => ''];
    }
}

// 📋 Flash message
$flashMsg  = $_SESSION['admin_flash_msg']  ?? '';
$flashType = $_SESSION['admin_flash_type'] ?? 'info';
unset($_SESSION['admin_flash_msg'], $_SESSION['admin_flash_type']);

// -----------------------------------------------------------------------------
// 📊 Fetch reference data for form
// -----------------------------------------------------------------------------

// 🏷️ Service types — build hierarchical list
$allServiceTypes = [];
$stmtTypes = $mysqli->prepare(
    'SELECT serviceTypeID, parentID, typeName, typeSlug FROM tblAttendanceServiceTypes '
    . 'WHERE isActive = 1 AND siteID = ? ORDER BY sortOrder, typeName'
);
if ($stmtTypes !== false) {
    $stmtTypes->bind_param('i', $siteId);
    $stmtTypes->execute();
    $resultTypes = $stmtTypes->get_result();
    while ($r = $resultTypes->fetch_assoc()) {
        $allServiceTypes[] = $r;
    }
    $stmtTypes->close();
}

// 📅 Recent events for linking (optional — last 30 days + next 30 days)
$recentEvents = [];
$stmtEvents = $mysqli->prepare(
    'SELECT eventID, eventName, startDateTime FROM tblEvents '
    . "WHERE isDeleted = 0 AND status = 'published' AND siteID = ? "
    . 'AND startDateTime BETWEEN DATE_SUB(NOW(), INTERVAL 30 DAY) AND DATE_ADD(NOW(), INTERVAL 30 DAY) '
    . 'ORDER BY startDateTime DESC LIMIT 50'
);
if ($stmtEvents !== false) {
    $stmtEvents->bind_param('i', $siteId);
    $stmtEvents->execute();
    $resultEvents = $stmtEvents->get_result();
    while ($r = $resultEvents->fetch_assoc()) {
        $recentEvents[] = $r;
    }
    $stmtEvents->close();
}

// 🏷️ Default count groups — common categories to pre-populate
$defaultGroups = ['Adults', 'Children', 'Visitors'];

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- 📝 Attendance Form -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0">
        <i class="fa-solid fa-<?php echo $editSession !== null ? 'pen' : 'plus'; ?> me-2"></i>
        <?php echo $editSession !== null ? 'Edit Attendance' : 'Record Attendance'; ?>
    </h1>
    <a href="/attendance" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back
    </a>
</div>

<form method="post" action="/attendance/record/save" id="attendanceForm">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="action" value="<?php echo $editSession !== null ? 'update' : 'create'; ?>">
    <?php if ($editSession !== null): ?>
        <input type="hidden" name="sessionID" value="<?php echo (int) $editSession['sessionID']; ?>">
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Session Details</h5></div>
        <div class="card-body">
            <div class="row g-3">
                <!-- 🏷️ Service Type -->
                <div class="col-12 col-md-6">
                    <label for="serviceTypeID" class="form-label">Service Type <span class="text-danger">*</span></label>
                    <select name="serviceTypeID" id="serviceTypeID" class="form-select" required>
                        <option value="">— Select service type —</option>
                        <?php
                        // 📋 Build hierarchical options
                        $topLevel = array_filter($allServiceTypes, function ($t) {
                            return $t['parentID'] === null;
                        });
                        foreach ($topLevel as $parent) {
                            $selected = ($editSession !== null && (int) $editSession['serviceTypeID'] === (int) $parent['serviceTypeID']) ? 'selected' : '';
                            echo '<option value="' . (int) $parent['serviceTypeID'] . '" ' . $selected . '>'
                               . htmlspecialchars($parent['typeName'], ENT_QUOTES, 'UTF-8') . '</option>';

                            // 📋 Child types
                            $children = array_filter($allServiceTypes, function ($c) use ($parent) {
                                return $c['parentID'] !== null && (int) $c['parentID'] === (int) $parent['serviceTypeID'];
                            });
                            foreach ($children as $child) {
                                $childSelected = ($editSession !== null && (int) $editSession['serviceTypeID'] === (int) $child['serviceTypeID']) ? 'selected' : '';
                                echo '<option value="' . (int) $child['serviceTypeID'] . '" ' . $childSelected . '>'
                                   . '&nbsp;&nbsp;&nbsp;&mdash; ' . htmlspecialchars($child['typeName'], ENT_QUOTES, 'UTF-8') . '</option>';
                            }
                        }
                        ?>
                    </select>
                </div>

                <!-- 📅 Date -->
                <div class="col-12 col-md-3">
                    <label for="sessionDate" class="form-label">Date <span class="text-danger">*</span></label>
                    <input type="date" name="sessionDate" id="sessionDate" class="form-control" required
                           value="<?php echo htmlspecialchars($editSession['sessionDate'] ?? date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <!-- 🕐 Time -->
                <div class="col-12 col-md-3">
                    <label for="sessionTime" class="form-label">Time <small class="text-muted">(optional)</small></label>
                    <input type="time" name="sessionTime" id="sessionTime" class="form-control"
                           value="<?php echo htmlspecialchars($editSession['sessionTime'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <!-- 📅 Link to Event (optional) -->
                <div class="col-12 col-md-6">
                    <label for="eventID" class="form-label">Link to Event <small class="text-muted">(optional)</small></label>
                    <select name="eventID" id="eventID" class="form-select">
                        <option value="">— No linked event —</option>
                        <?php foreach ($recentEvents as $ev): ?>
                            <?php
                            $evSelected = ($editSession !== null && $editSession['eventID'] !== null
                                && (int) $editSession['eventID'] === (int) $ev['eventID']) ? 'selected' : '';
                            $evDate = (new DateTime($ev['startDateTime']))->format('j M Y');
                            ?>
                            <option value="<?php echo (int) $ev['eventID']; ?>" <?php echo $evSelected; ?>>
                                <?php echo htmlspecialchars($ev['eventName'] . ' (' . $evDate . ')', ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 📝 Notes -->
                <div class="col-12 col-md-6">
                    <label for="notes" class="form-label">Notes <small class="text-muted">(optional)</small></label>
                    <input type="text" name="notes" id="notes" class="form-control"
                           placeholder="e.g. Combined service, guest speaker"
                           value="<?php echo htmlspecialchars($editSession['notes'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- 🔢 Headcount Breakdown -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Headcount Breakdown</h5>
            <button type="button" class="btn btn-sm btn-outline-success" id="addCountRow">
                <i class="fa-solid fa-plus me-1"></i> Add Group
            </button>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">
                Enter headcounts by group/category. At minimum, enter a total count.
            </p>

            <div id="countRows">
                <?php if ($editSession !== null && count($editCounts) > 0): ?>
                    <!-- ✏️ Populate with existing counts -->
                    <?php foreach ($editCounts as $idx => $cnt): ?>
                        <div class="row g-2 mb-2 count-row">
                            <div class="col-6 col-md-5">
                                <input type="text" name="groups[]" class="form-control form-control-sm"
                                       placeholder="Group label (e.g. Adults)"
                                       value="<?php echo htmlspecialchars($cnt['groupLabel'], ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div class="col-4 col-md-3">
                                <input type="number" name="counts[]" class="form-control form-control-sm count-input"
                                       min="0" placeholder="0"
                                       value="<?php echo (int) $cnt['headcount']; ?>" required>
                            </div>
                            <div class="col-2 col-md-2">
                                <?php if ($idx > 0): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger remove-row" title="Remove">
                                        <i class="fa-solid fa-xmark"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <!-- ➕ Default groups for new session -->
                    <?php foreach ($defaultGroups as $idx => $group): ?>
                        <div class="row g-2 mb-2 count-row">
                            <div class="col-6 col-md-5">
                                <input type="text" name="groups[]" class="form-control form-control-sm"
                                       placeholder="Group label (e.g. Adults)"
                                       value="<?php echo htmlspecialchars($group, ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div class="col-4 col-md-3">
                                <input type="number" name="counts[]" class="form-control form-control-sm count-input"
                                       min="0" placeholder="0" value="" required>
                            </div>
                            <div class="col-2 col-md-2">
                                <?php if ($idx > 0): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger remove-row" title="Remove">
                                        <i class="fa-solid fa-xmark"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <noscript>
                <p class="text-muted small mt-2"><i class="fa-solid fa-circle-info me-1"></i>JavaScript is disabled — use the pre-filled group rows above. Adding/removing rows requires JavaScript. Total will be calculated on the server.</p>
            </noscript>

            <!-- 📊 Running total -->
            <div class="mt-3 p-2 bg-light rounded d-flex justify-content-between align-items-center">
                <strong>Total Headcount:</strong>
                <span class="badge bg-primary fs-5" id="totalHeadcount">0</span>
            </div>
        </div>
    </div>

    <!-- 💾 Submit -->
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-floppy-disk me-1"></i>
            <?php echo $editSession !== null ? 'Save Changes' : 'Save Attendance'; ?>
        </button>
        <a href="/attendance" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<!-- 📜 JavaScript for dynamic count rows -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var countRows       = document.getElementById('countRows');
    var addBtn          = document.getElementById('addCountRow');
    var totalDisplay    = document.getElementById('totalHeadcount');

    // 🔢 Update running total
    function updateTotal() {
        var inputs = countRows.querySelectorAll('.count-input');
        var total  = 0;
        for (var i = 0; i < inputs.length; i++) {
            var val = parseInt(inputs[i].value, 10);
            if (isNaN(val) === false) {
                total += val;
            }
        }
        totalDisplay.textContent = total.toLocaleString();
    }

    // ➕ Add new count row
    addBtn.addEventListener('click', function () {
        var row = document.createElement('div');
        row.className = 'row g-2 mb-2 count-row';
        row.innerHTML = '<div class="col-6 col-md-5">'
            + '<input type="text" name="groups[]" class="form-control form-control-sm" placeholder="Group label" required>'
            + '</div>'
            + '<div class="col-4 col-md-3">'
            + '<input type="number" name="counts[]" class="form-control form-control-sm count-input" min="0" placeholder="0" required>'
            + '</div>'
            + '<div class="col-2 col-md-2">'
            + '<button type="button" class="btn btn-sm btn-outline-danger remove-row" title="Remove">'
            + '<i class="fa-solid fa-xmark"></i></button>'
            + '</div>';
        countRows.appendChild(row);

        // 🎯 Focus the new label input
        row.querySelector('input[name="groups[]"]').focus();

        // 🔗 Bind events on new row
        row.querySelector('.count-input').addEventListener('input', updateTotal);
        row.querySelector('.remove-row').addEventListener('click', function () {
            row.remove();
            updateTotal();
        });
    });

    // 🗑️ Remove row handlers (initial rows)
    var removeBtns = countRows.querySelectorAll('.remove-row');
    for (var i = 0; i < removeBtns.length; i++) {
        removeBtns[i].addEventListener('click', function () {
            this.closest('.count-row').remove();
            updateTotal();
        });
    }

    // 🔢 Bind input events on existing count inputs
    var countInputs = countRows.querySelectorAll('.count-input');
    for (var j = 0; j < countInputs.length; j++) {
        countInputs[j].addEventListener('input', updateTotal);
    }

    // 🔢 Calculate initial total
    updateTotal();
});
</script>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
