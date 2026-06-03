<?php
// Path: public_html/leadership/assign.php
/**
 * -----------------------------------------------------------------------------
 * Leadership — Assign / Edit Role Assignment 📝
 * -----------------------------------------------------------------------------
 * Form for assigning a person to a leadership role, or editing an existing
 * assignment. Supports both portal users (dropdown) and external people
 * (freetext name + email). Admin-only.
 *
 * @package   Portal\Leadership
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.9.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/38
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;

// 📌 Page metadata
$pageTitle   = 'Assign Leadership Role';
$pageSection = 'leadership';
$breadcrumbs = ['Dashboard' => '/', 'Leadership' => '/leadership', 'Assign' => ''];

// 🛡️ Admin access check
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// 🌐 Multi-site scope
$siteId = Site::id();

// 📋 Check for edit mode (assignmentID in query string)
$editAssignment = null;
$editID = (int) ($_GET['id'] ?? 0);
if ($editID > 0) {
    $stmtEdit = $mysqli->prepare(
        'SELECT a.*, u.fullName AS userName, u.emailAddress AS userEmail '
        . 'FROM tblLeadershipAssignments a '
        . 'LEFT JOIN tblUsers u ON u.userID = a.userID '
        . 'WHERE a.assignmentID = ? AND a.siteID = ? LIMIT 1'
    );
    if ($stmtEdit !== false) {
        $stmtEdit->bind_param('ii', $editID, $siteId);
        $stmtEdit->execute();
        $editAssignment = $stmtEdit->get_result()->fetch_assoc();
        $stmtEdit->close();
    }
    if ($editAssignment !== null) {
        $pageTitle = 'Edit Assignment';
        $breadcrumbs = ['Dashboard' => '/', 'Leadership' => '/leadership', 'Edit Assignment' => ''];
    }
}

// 📋 Pre-selected role from query string
$preSelectedRole = (int) ($_GET['roleID'] ?? 0);
if ($editAssignment !== null) {
    $preSelectedRole = (int) $editAssignment['roleID'];
}

// 📋 Fetch active roles for dropdown
$roles = [];
$stmtRoles = $mysqli->prepare(
    'SELECT roleID, roleName FROM tblLeadershipRoles '
    . 'WHERE siteID = ? AND isActive = 1 ORDER BY sortOrder, roleName'
);
if ($stmtRoles !== false) {
    $stmtRoles->bind_param('i', $siteId);
    $stmtRoles->execute();
    $resultRoles = $stmtRoles->get_result();
    while ($r = $resultRoles->fetch_assoc()) {
        $roles[] = $r;
    }
    $stmtRoles->close();
}

// 📋 Fetch active users for dropdown
$users = [];
$stmtUsers = $mysqli->prepare(
    'SELECT u.userID, u.fullName, u.emailAddress '
    . 'FROM tblUsers u '
    . 'INNER JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
    . 'WHERE u.isActive = 1 '
    . 'ORDER BY u.fullName'
);
if ($stmtUsers !== false) {
    $stmtUsers->bind_param('i', $siteId);
    $stmtUsers->execute();
    $resultUsers = $stmtUsers->get_result();
    while ($u = $resultUsers->fetch_assoc()) {
        $users[] = $u;
    }
    $stmtUsers->close();
}

// 📋 Fetch current active holders per role (for transition UI)
$roleHolders = [];
$stmtHolders = $mysqli->prepare(
    'SELECT a.assignmentID, a.roleID, a.userID, a.personName, '
    . 'u.fullName AS userName, a.startDate '
    . 'FROM tblLeadershipAssignments a '
    . 'LEFT JOIN tblUsers u ON u.userID = a.userID '
    . 'WHERE a.siteID = ? AND a.isActive = 1 '
    . 'AND (a.endDate IS NULL OR a.endDate >= CURDATE()) '
    . 'ORDER BY a.roleID, a.startDate'
);
if ($stmtHolders !== false) {
    $stmtHolders->bind_param('i', $siteId);
    $stmtHolders->execute();
    $resultHolders = $stmtHolders->get_result();
    while ($h = $resultHolders->fetch_assoc()) {
        $rid = (int) $h['roleID'];
        if (isset($roleHolders[$rid]) === false) {
            $roleHolders[$rid] = [];
        }
        $roleHolders[$rid][] = [
            'id'    => (int) $h['assignmentID'],
            'name'  => $h['userName'] ?? $h['personName'] ?? 'Unknown',
            'since' => $h['startDate'],
        ];
    }
    $stmtHolders->close();
}

// 📋 Determine person type for edit mode
$personType = 'user';
if ($editAssignment !== null && $editAssignment['userID'] === null) {
    $personType = 'external';
}

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 📝 Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0">
        <i class="fa-solid fa-user-plus me-2"></i>
        <?php echo $editAssignment !== null ? 'Edit Assignment' : 'Assign Leadership Role'; ?>
    </h1>
    <a href="/leadership" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back
    </a>
</div>

<form method="post" action="/leadership/save">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="action" value="<?php echo $editAssignment !== null ? 'update' : 'create'; ?>">
    <?php if ($editAssignment !== null): ?>
        <input type="hidden" name="assignmentID" value="<?php echo (int) $editAssignment['assignmentID']; ?>">
    <?php endif; ?>

    <!-- 🏷️ Card 1: Role selection -->
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Role</h5></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label for="roleID" class="form-label">Leadership Role <span class="text-danger">*</span></label>
                    <select name="roleID" id="roleID" class="form-select" required>
                        <option value="">— Select role —</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?php echo (int) $role['roleID']; ?>"
                                <?php echo ($preSelectedRole === (int) $role['roleID']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($role['roleName'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- 🔄 Card 1b: Transition — current holders (shown only for create mode) -->
    <?php if ($editAssignment === null): ?>
    <div class="card mb-4" id="transitionCard" style="display:none;">
        <div class="card-header bg-warning-subtle">
            <h5 class="mb-0"><i class="fa-solid fa-right-left me-1"></i> Current Holders</h5>
        </div>
        <div class="card-body">
            <div id="currentHoldersList" class="mb-3"></div>
            <div class="form-check">
                <input type="checkbox" name="endCurrentHolders" value="1" id="endCurrentHolders" class="form-check-input">
                <label for="endCurrentHolders" class="form-check-label">
                    End current holder(s) term when this assignment starts
                </label>
            </div>
            <div class="mt-2" id="transitionDateGroup" style="display:none;">
                <label for="transitionDate" class="form-label">Transition Date</label>
                <input type="date" name="transitionDate" id="transitionDate" class="form-control"
                       value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>"
                       style="max-width:250px;">
                <small class="text-muted">Current holder(s) end date will be set to the day before this date.</small>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 👤 Card 2: Person selection -->
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Person</h5></div>
        <div class="card-body">
            <div class="row g-3">
                <!-- 🔀 Person type selector -->
                <div class="col-12">
                    <label class="form-label">Person Type <span class="text-danger">*</span></label>
                    <div class="form-check form-check-inline">
                        <input type="radio" name="personType" id="personTypeUser" value="user"
                               class="form-check-input" <?php echo $personType === 'user' ? 'checked' : ''; ?>>
                        <label for="personTypeUser" class="form-check-label">Portal User</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input type="radio" name="personType" id="personTypeExternal" value="external"
                               class="form-check-input" <?php echo $personType === 'external' ? 'checked' : ''; ?>>
                        <label for="personTypeExternal" class="form-check-label">External Person</label>
                    </div>
                </div>

                <!-- 👤 Portal user dropdown -->
                <div class="col-12 col-md-6" id="userFieldGroup">
                    <label for="userID" class="form-label">Select User</label>
                    <select name="userID" id="userID" class="form-select">
                        <option value="">— Select user —</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo (int) $user['userID']; ?>"
                                <?php echo ($editAssignment !== null && (int) ($editAssignment['userID'] ?? 0) === (int) $user['userID']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(($user['fullName'] ?? '') . ' (' . $user['emailAddress'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 👤 External person fields -->
                <div class="col-12 col-md-4" id="nameFieldGroup">
                    <label for="personName" class="form-label">Full Name</label>
                    <input type="text" name="personName" id="personName" class="form-control"
                           placeholder="e.g. John Smith"
                           value="<?php echo htmlspecialchars($editAssignment['personName'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-12 col-md-4" id="emailFieldGroup">
                    <label for="personEmail" class="form-label">Email <small class="text-muted">(optional)</small></label>
                    <input type="email" name="personEmail" id="personEmail" class="form-control"
                           placeholder="e.g. john@example.com"
                           value="<?php echo htmlspecialchars($editAssignment['personEmail'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- 📅 Card 3: Term dates -->
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Term</h5></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label for="startDate" class="form-label">Start Date <small class="text-muted">(optional)</small></label>
                    <input type="date" name="startDate" id="startDate" class="form-control"
                           value="<?php echo htmlspecialchars($editAssignment['startDate'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label for="endDate" class="form-label">End Date <small class="text-muted">(optional — leave blank for ongoing)</small></label>
                    <input type="date" name="endDate" id="endDate" class="form-control"
                           value="<?php echo htmlspecialchars($editAssignment['endDate'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-12">
                    <label for="notes" class="form-label">Notes <small class="text-muted">(optional)</small></label>
                    <textarea name="notes" id="notes" class="form-control" rows="3"
                              placeholder="Additional information about this assignment..."><?php echo htmlspecialchars($editAssignment['notes'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- 💾 Submit -->
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-floppy-disk me-1"></i>
            <?php echo $editAssignment !== null ? 'Save Changes' : 'Assign Role'; ?>
        </button>
        <a href="/leadership" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<!-- 🔀 Toggle person type fields (progressive enhancement) -->
<script>
(function () {
    'use strict';
    var radioUser     = document.getElementById('personTypeUser');
    var radioExternal = document.getElementById('personTypeExternal');
    var userGroup     = document.getElementById('userFieldGroup');
    var nameGroup     = document.getElementById('nameFieldGroup');
    var emailGroup    = document.getElementById('emailFieldGroup');

    if (!radioUser || !radioExternal) { return; }

    function toggle() {
        var isUser = radioUser.checked;
        userGroup.style.display  = isUser ? '' : 'none';
        nameGroup.style.display  = isUser ? 'none' : '';
        emailGroup.style.display = isUser ? 'none' : '';
    }

    radioUser.addEventListener('change', toggle);
    radioExternal.addEventListener('change', toggle);
    toggle();
})();
</script>

<!-- 🔄 Role transition — show current holders when role changes (create mode only) -->
<?php if ($editAssignment === null): ?>
<script>
(function () {
    'use strict';

    // 📋 Role holders data (pre-rendered from PHP)
    var roleHolders = <?php echo json_encode($roleHolders, JSON_HEX_TAG | JSON_HEX_AMP); ?>;

    var roleSelect       = document.getElementById('roleID');
    var transitionCard   = document.getElementById('transitionCard');
    var holdersList      = document.getElementById('currentHoldersList');
    var endCheckbox      = document.getElementById('endCurrentHolders');
    var transitionDate   = document.getElementById('transitionDateGroup');

    if (!roleSelect || !transitionCard) { return; }

    // 🔄 Show/hide transition card based on selected role
    function onRoleChange() {
        var rid = roleSelect.value;
        var holders = roleHolders[rid] || [];

        if (holders.length === 0) {
            transitionCard.style.display = 'none';
            endCheckbox.checked = false;
            transitionDate.style.display = 'none';
            return;
        }

        // 📋 Build holder list HTML
        var html = '<ul class="list-unstyled mb-0">';
        for (var i = 0; i < holders.length; i++) {
            var h = holders[i];
            html += '<li class="mb-1"><i class="fa-solid fa-user me-1 text-muted"></i> ';
            html += '<strong>' + escapeHtml(h.name) + '</strong>';
            if (h.since) {
                html += ' <small class="text-muted">(since ' + escapeHtml(h.since) + ')</small>';
            }
            html += '</li>';
        }
        html += '</ul>';
        holdersList.innerHTML = html;
        transitionCard.style.display = '';
    }

    // 🔄 Show/hide transition date based on checkbox
    endCheckbox.addEventListener('change', function () {
        transitionDate.style.display = endCheckbox.checked ? '' : 'none';
    });

    roleSelect.addEventListener('change', onRoleChange);
    onRoleChange();

    // 🛡️ Simple HTML escape
    function escapeHtml(str) {
        if (!str) { return ''; }
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }
})();
</script>
<?php endif; ?>

<noscript>
    <style>
        #userFieldGroup, #nameFieldGroup, #emailFieldGroup { display: block !important; }
    </style>
</noscript>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
