<?php
// Path: public_html/settings/index.php
/**
 * -----------------------------------------------------------------------------
 * Settings Admin UI ⚙️
 * -----------------------------------------------------------------------------
 * Allows Admin / Global Admin users to view and edit portal & app settings
 * stored in tblSettings. Settings are grouped by their dot-notation prefix
 * (e.g. site.*, auth.*, expenses.*). Features:
 *   - Grouped accordion display by setting prefix
 *   - Sensitive value masking (root admin can view, all admins can edit)
 *   - Add new settings via modal
 *   - Delete settings (root admin only)
 *   - Search/filter settings
 *   - Flash messages for success/error feedback
 *
 * @package   Portal\Settings
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.4.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

// 📌 Page metadata for the template system
$pageTitle   = 'Settings';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Settings' => ''];

// 🛡️ Admin access check (Router handles isProtected login enforcement)
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// 🛡️ Global administrator? App::isRootAdmin() is the one place that decides.
//    For a short while this line ALSO read the flag straight off App::user()
//    and converted it to a whole number. That was a stop-gap: App::isRootAdmin()
//    used to compare with the text '1' while the database hands back the whole
//    number 1, so it was false for every real global administrator, and nobody
//    could delete a setting here or see a sensitive setting's stored text.
//    App.php now accepts both forms (App::flagIsOn()), so the extra check was
//    removed. Please do not put it back; the full note is beside the same line
//    in web/_apps/settings/save.php.
$isRoot = App::isRootAdmin();

// -----------------------------------------------------------------------------
// 🗑️ Handle delete action (POST, root admin only)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /settings');
        exit();
    }
    // -------------------------------------------------------------------------
    // 🚧 THE REFUSALS THAT MATCH THE SAVE HANDLER
    // -------------------------------------------------------------------------
    // Deleting a setting has always been reserved for a global administrator
    // on this page, so the rules below are already satisfied by that gate
    // alone: portal-wide rows, "public" and "public." names, and stored names
    // outside the safe characters. They are still written out, for two
    // reasons.
    //
    // First, the message. "Only root admins can delete settings" tells an
    // administrator nothing about WHY this particular row is different from
    // the rows they are allowed to change on the same screen. Saying that the
    // row is portal-wide, or that it controls the public website, is the
    // difference between an administrator who understands the rule and one
    // who thinks the portal is broken.
    //
    // Second, and more important: if anybody ever widens the gate below from
    // "global administrator" to "any administrator", these two checks are
    // what keeps the rule true. Deleting a portal-wide row is not a smaller
    // act than editing one — it removes the value every other organisation on
    // the installation was reading.
    //
    // Deliberately NOT done here: telling somebody a row exists that they
    // could not otherwise see. The lookup is scoped exactly like the delete,
    // so a row belonging to another organisation reads as "not found".
    $deleteId     = (int) ($_POST['settingID'] ?? 0);
    $deleteSiteId = Site::id();

    if ($deleteId > 0 && $isRoot === false) {
        $target = null;
        $lookup = $mysqli->prepare(
            'SELECT settingKey, siteID FROM tblSettings '
            . 'WHERE settingID = ? AND (siteID = ? OR siteID IS NULL) LIMIT 1'
        );
        if ($lookup !== false) {
            $lookup->bind_param('ii', $deleteId, $deleteSiteId);
            $lookup->execute();
            $target = $lookup->get_result()->fetch_assoc();
            $lookup->close();
        }

        if ($target !== null) {
            $targetKey = (string) $target['settingKey'];

            if ($target['siteID'] === null) {
                $_SESSION['flash_msg']  = 'That setting is portal-wide: it is '
                    . 'the value every organisation on this installation uses '
                    . 'unless it has set its own. Only a global administrator '
                    . 'can delete it.';
                $_SESSION['flash_type'] = 'danger';
                header('Location: /settings');
                exit();
            }

            // 🔡 "public" on its own, or a name beginning "public.", in ANY
            //    letter case. The database compares setting names without
            //    regard to capital and small letters, so to it
            //    "PUBLIC.example" is the same name as "public.example". The
            //    first version of this test was case-sensitive and missed it.
            //    The second missed the bare name "public", which replaces
            //    every "public." setting when the portal loads its settings
            //    (the full note is in web/_apps/settings/save.php).
            if (strcasecmp($targetKey, 'public') === 0 || strncasecmp($targetKey, 'public.', 7) === 0) {
                $_SESSION['flash_msg']  = 'The setting called "public", and every '
                    . 'setting whose name begins "public.", control the public '
                    . 'website, which anybody on the internet can read without '
                    . 'signing in. Only a global administrator can delete those.';
                $_SESSION['flash_type'] = 'danger';
                header('Location: /settings');
                exit();
            }

            // 🔤 A stored name using anything other than A to Z, digits, full
            //    stops, hyphens and underscores is reserved too. The database
            //    can treat such a name as EQUAL to a protected one: an accent
            //    typed as a separate mark, full-width letters, or an invisible
            //    character all compare equal to "public.example" on MySQL
            //    8.0.36, while failing a character-by-character test. The same
            //    rule, and the reasons for it, are in web/_apps/settings/save.php.
            if (preg_match('/^[A-Za-z0-9._-]+\z/', $targetKey) !== 1) {
                $_SESSION['flash_msg']  = 'This setting\'s name contains characters '
                    . 'other than the letters A to Z, numbers, full stops (.), '
                    . 'hyphens (-) and underscores (_). The database can treat a '
                    . 'name like that as the same name as a protected setting, so '
                    . 'only a global administrator can delete it.';
                $_SESSION['flash_type'] = 'danger';
                header('Location: /settings');
                exit();
            }
        }
    }

    if ($isRoot === true) {
        if ($deleteId > 0) {
            // 🔒 THE RULES ARE WRITTEN INTO THE STATEMENT, NOT ONLY CHECKED ABOVE
            //
            //    WHAT WAS WRONG: the refusals above were decided in PHP from a
            //    row read a moment earlier, and the DELETE then ran with only
            //    the organisation scope in it. If the row changed in between,
            //    the delete went ahead on the strength of a check made against
            //    what the row used to be. Nothing was checked afterwards
            //    either: "Setting deleted." was shown even when nothing was.
            //
            //    NOW the statement carries the same rules as the UPDATE in
            //    web/_apps/settings/save.php, using $globalFlag (1 for a global
            //    administrator, 0 for anybody else — worked out from $isRoot,
            //    not written in as 1):
            //      - this organisation's own row is allowed; a portal-wide row
            //        only when $globalFlag is 1;
            //      - when $globalFlag is 0, the name must be made only of A to
            //        Z, digits, full stops, hyphens and underscores, must not
            //        be empty, and must not be "public" or begin "public.", in
            //        any letter case. This is the same text as $nameRuleSql in
            //        web/_apps/settings/save.php, which explains each part and
            //        what was checked on MySQL 8.0.36; if one changes, change
            //        both. WHAT WAS WRONG: this used to test only
            //        LOWER(settingKey) NOT LIKE 'public.%', which misses the
            //        bare name "public" and names the database treats as equal
            //        to a "public." name while LIKE does not (an accent typed as
            //        a separate mark, an invisible character).
            //    Today only a global administrator reaches this line, so both
            //    extra conditions pass. They are in the statement anyway, so
            //    that if the gate around this block is ever widened, the
            //    database still refuses what the refusals above refuse instead
            //    of quietly deleting it.
            //
            //    Then the number of rows actually removed is checked, and zero
            //    is reported as "nothing was deleted", never as success.
            $globalFlag  = (int) $isRoot;
            $rowsDeleted = 0;
            $stmt = $mysqli->prepare(
                'DELETE FROM tblSettings '
                . 'WHERE settingID = ? '
                . 'AND (siteID = ? OR (siteID IS NULL AND ? = 1)) '
                . "AND (? = 1 OR (CHAR_LENGTH(settingKey) = LENGTH(settingKey) "
                . "AND settingKey COLLATE utf8mb4_bin NOT REGEXP '[^A-Za-z0-9._-]' "
                . "AND settingKey <> '' "
                . "AND LOWER(settingKey) <> 'public' "
                . "AND LOWER(settingKey) NOT LIKE 'public.%'))"
            );
            if ($stmt !== false) {
                $stmt->bind_param('iiii', $deleteId, $deleteSiteId, $globalFlag, $globalFlag);
                $stmt->execute();
                $rowsDeleted = $stmt->affected_rows;
                $stmt->close();
            }
            if ($rowsDeleted > 0) {
                Logger::activity('SettingsDelete', 'Deleted setting ID ' . $deleteId, $_SESSION['user_id'] ?? null);
                $_SESSION['flash_msg']  = 'Setting deleted.';
                $_SESSION['flash_type'] = 'success';
            } else {
                $_SESSION['flash_msg']  = 'Nothing was deleted. That setting could not '
                    . 'be found (somebody may have removed it a moment ago), or it does '
                    . 'not belong to the organisation you are working in.';
                $_SESSION['flash_type'] = 'warning';
            }
        }
    } else {
        $_SESSION['flash_msg']  = 'Only a global administrator can delete a '
            . 'setting. An administrator of one organisation can change that '
            . 'organisation\'s own settings, but not remove them.';
        $_SESSION['flash_type'] = 'danger';
    }
    header('Location: /settings');
    exit();
}

// -----------------------------------------------------------------------------
// 📋 Fetch settings list
// -----------------------------------------------------------------------------
// 🌐 Multi-site: show settings for this site (siteID = current) plus global defaults (siteID IS NULL)
$siteId = Site::id();
$rows = [];
$stmt = $mysqli->prepare(
    'SELECT settingID, settingKey, settingValue, isSensitive, siteID, updatedAt '
    . 'FROM tblSettings '
    . 'WHERE siteID = ? OR siteID IS NULL '
    . 'ORDER BY settingKey, siteID DESC'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
}

// 📊 Group settings by first segment of dot-notation key
$groups = [];
foreach ($rows as $row) {
    $parts = explode('.', $row['settingKey'], 2);
    $group = $parts[0] ?? 'other';
    if (isset($groups[$group]) === false) {
        $groups[$group] = [];
    }
    $groups[$group][] = $row;
}
ksort($groups);

// 📋 Flash message
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// 🔍 Check for success query param from old save handler
if (isset($_GET['success']) === true && $flashMsg === '') {
    $flashMsg  = 'Setting saved successfully.';
    $flashType = 'success';
}

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- ⚙️ Settings Management -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-gear me-2"></i>Settings</h1>
    <div class="d-flex gap-2">
        <span class="badge bg-secondary align-self-center"><?php echo count($rows); ?> settings</span>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="fa-solid fa-plus me-1"></i> Add Setting
        </button>
    </div>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- 🔍 Search filter -->
<div class="mb-4">
    <input type="text" class="form-control" id="settingsSearch" placeholder="Search settings by key or value..." aria-label="Search settings">
</div>

<!-- ⚠️ No-JS: expand all accordion panels and show search note -->
<noscript>
    <style>.accordion-collapse { display: block !important; }</style>
    <p class="text-muted small mb-2"><i class="fa-solid fa-circle-info me-1"></i>JavaScript is disabled — all settings groups are expanded. Search filtering requires JavaScript.</p>
</noscript>

<!-- 📋 Grouped settings accordion -->
<div class="accordion" id="settingsAccordion">
    <?php $groupIndex = 0; ?>
    <?php foreach ($groups as $groupName => $groupRows): ?>
        <?php $groupIndex++; ?>
        <div class="accordion-item portal-settings-group" data-group="<?php echo htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8'); ?>">
            <h2 class="accordion-header">
                <button class="accordion-button <?php echo ($groupIndex > 1) ? 'collapsed' : ''; ?>" type="button"
                        data-bs-toggle="collapse" data-bs-target="#group-<?php echo htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8'); ?>"
                        aria-expanded="<?php echo ($groupIndex === 1) ? 'true' : 'false'; ?>">
                    <strong class="text-uppercase"><?php echo htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8'); ?></strong>
                    <span class="badge bg-secondary ms-2"><?php echo count($groupRows); ?></span>
                </button>
            </h2>
            <div id="group-<?php echo htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8'); ?>"
                 class="accordion-collapse collapse <?php echo ($groupIndex === 1) ? 'show' : ''; ?>"
                 data-bs-parent="#settingsAccordion">
                <div class="accordion-body p-0">
                    <div class="portal-data-list">
                        <!-- 🏷️ Header row -->
                        <div class="portal-data-row portal-data-header d-none d-md-flex">
                            <div class="col-md-4">Key</div>
                            <div class="col-md-4">Value</div>
                            <div class="col-md-2">Updated</div>
                            <div class="col-md-2 text-end">Actions</div>
                        </div>

                        <?php foreach ($groupRows as $row): ?>
                            <?php $isSensitive = $row['isSensitive'] === '1' || (int) $row['isSensitive'] === 1; ?>
                            <div class="portal-data-row portal-setting-row"
                                 data-setting-key="<?php echo htmlspecialchars($row['settingKey'], ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="col-12 col-md-4">
                                    <span class="d-md-none fw-semibold">Key: </span>
                                    <code class="small"><?php echo htmlspecialchars($row['settingKey'], ENT_QUOTES, 'UTF-8'); ?></code>
                                    <?php if ($isSensitive === true): ?>
                                        <span class="badge bg-warning text-dark ms-1" title="Encrypted in database">
                                            <i class="fa-solid fa-lock"></i>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="col-12 col-md-4">
                                    <span class="d-md-none fw-semibold">Value: </span>
                                    <?php if ($isSensitive === true): ?>
                                        <?php if ($isRoot === true): ?>
                                            <span class="portal-masked-value" data-revealed="false">
                                                <span class="masked-text text-muted">••••••••</span>
                                                <span class="revealed-text d-none"><?php echo htmlspecialchars(str_replace("\n", ' ', $row['settingValue']), ENT_QUOTES, 'UTF-8'); ?></span>
                                                <button type="button" class="btn btn-sm btn-link portal-reveal-btn p-0 ms-1" title="Toggle visibility">
                                                    <i class="fa-solid fa-eye"></i>
                                                </button>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">••••••••</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="small"><?php echo htmlspecialchars(str_replace("\n", ' ', $row['settingValue']), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="col-12 col-md-2 small text-secondary">
                                    <span class="d-md-none fw-semibold">Updated: </span>
                                    <?php echo htmlspecialchars($row['updatedAt'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <div class="col-12 col-md-2 text-md-end mt-2 mt-md-0">
                                    <button class="btn btn-sm btn-outline-primary me-1" data-bs-toggle="modal" data-bs-target="#editModal"
                                            data-id="<?php echo (int) $row['settingID']; ?>"
                                            data-key="<?php echo htmlspecialchars($row['settingKey'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-value="<?php echo $isSensitive === true ? '' : htmlspecialchars($row['settingValue'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-sensitive="<?php echo $isSensitive ? '1' : '0'; ?>">
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <?php if ($isRoot === true): ?>
                                        <form method="post" action="/settings" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="settingID" value="<?php echo (int) $row['settingID']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                                    data-confirm="Delete setting: <?php echo htmlspecialchars($row['settingKey'], ENT_QUOTES, 'UTF-8'); ?>?" data-confirm-destructive="true"
                                                    title="Delete">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- 📝 Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="post" action="/settings/save">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="settingID" id="edit-settingID">
                <div class="modal-header">
                    <h5 class="modal-title" id="editLabel"><i class="fa-solid fa-pen me-1"></i> Edit Setting</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Key</label>
                        <input type="text" class="form-control" name="settingKey" id="edit-settingKey" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Value</label>
                        <textarea class="form-control" name="settingValue" id="edit-settingValue" rows="4"></textarea>
                        <div class="form-text portal-sensitive-hint d-none">
                            <i class="fa-solid fa-lock me-1"></i> This is a sensitive/encrypted setting. Enter the new plaintext value.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ➕ Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-labelledby="addLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="post" action="/settings/save">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="addLabel"><i class="fa-solid fa-plus me-1"></i> Add New Setting</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Key <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="settingKey" required placeholder="e.g. site.name or app.feature.enabled">
                        <div class="form-text">Use dot-notation for grouping (e.g. <code>site.name</code>)</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Value</label>
                        <textarea class="form-control" name="settingValue" rows="4"></textarea>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1" name="isSensitive" id="add-sensitive">
                        <label class="form-check-label" for="add-sensitive">
                            <i class="fa-solid fa-lock me-1"></i> Sensitive (encrypt in database)
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fa-solid fa-plus me-1"></i>Add Setting</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 📦 Settings page scripts -->
<script>
// 📝 Edit modal population
var editModal = document.getElementById('editModal');
editModal.addEventListener('show.bs.modal', function (event) {
    var button = event.relatedTarget;
    document.getElementById('edit-settingID').value    = button.getAttribute('data-id');
    document.getElementById('edit-settingKey').value   = button.getAttribute('data-key');
    document.getElementById('edit-settingValue').value = button.getAttribute('data-value');

    var sensitiveHint = editModal.querySelector('.portal-sensitive-hint');
    if (button.getAttribute('data-sensitive') === '1') {
        sensitiveHint.classList.remove('d-none');
    } else {
        sensitiveHint.classList.add('d-none');
    }
});

// 🔍 Search/filter settings
var searchInput = document.getElementById('settingsSearch');
searchInput.addEventListener('input', function () {
    var query = this.value.toLowerCase();
    var rows  = document.querySelectorAll('.portal-setting-row');
    var groups = document.querySelectorAll('.portal-settings-group');

    rows.forEach(function (row) {
        var key = (row.getAttribute('data-setting-key') || '').toLowerCase();
        var text = row.textContent.toLowerCase();
        if (query === '' || key.indexOf(query) !== -1 || text.indexOf(query) !== -1) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });

    // Show/hide groups based on whether they have visible rows
    groups.forEach(function (group) {
        var visibleRows = group.querySelectorAll('.portal-setting-row:not([style*="display: none"])');
        if (visibleRows.length === 0 && query !== '') {
            group.style.display = 'none';
        } else {
            group.style.display = '';
            // Auto-expand groups when searching
            if (query !== '') {
                var collapse = group.querySelector('.accordion-collapse');
                if (collapse !== null) {
                    collapse.classList.add('show');
                }
            }
        }
    });
});

// 👁️ Reveal/hide sensitive values (root admin only)
document.querySelectorAll('.portal-reveal-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var container = this.closest('.portal-masked-value');
        var masked    = container.querySelector('.masked-text');
        var revealed  = container.querySelector('.revealed-text');
        var icon      = this.querySelector('i');

        if (container.getAttribute('data-revealed') === 'false') {
            masked.classList.add('d-none');
            revealed.classList.remove('d-none');
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
            container.setAttribute('data-revealed', 'true');
        } else {
            masked.classList.remove('d-none');
            revealed.classList.add('d-none');
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
            container.setAttribute('data-revealed', 'false');
        }
    });
});
</script>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
