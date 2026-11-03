<?php
// Path: _apps/admin/roles/index.php
/**
 * -----------------------------------------------------------------------------
 * Roles 🏷️ (#516)
 * -----------------------------------------------------------------------------
 * Manage THIS organisation's own list of roles: rename the standard ones
 * (treasurer, expense approver, care team, and the rest of the standard
 * every organisation starts with), or add roles of the organisation's own.
 *
 * Granting a role to a specific person happens on the members page
 * (`/admin/users`, the "Roles" button on each row) — this page only
 * manages the LIST itself: what roles exist, what they are called, and
 * one sentence describing each.
 *
 * WHAT THIS PAGE DELIBERATELY DOES NOT SHOW
 * -------------------------------------------------------------------------
 * Leadership positions (elders, deacons, and so on) — those live under the
 * separate Leadership app, which is about who holds a POSITION in the
 * congregation, not who may open a confidential register or approve an
 * expense claim. The two are easy to confuse because both use the word
 * "role" in everyday speech; this page is only ever about ACCESS.
 *
 * WHAT THIS PAGE CANNOT DO
 * -------------------------------------------------------------------------
 * It cannot delete a STANDARD role (`isStandard = 1`) — every organisation
 * keeps the same keys every `App::hasRole()` call site relies on; rename one
 * instead if the standard wording does not fit. (This line used to say "across
 * 61 files" and the real figure was never checked again; count them with
 * `grep -rlE "App::hasRole\(" web --include='*.php' | wc -l`.) It
 * cannot delete any role, standard or not, while somebody still holds it —
 * remove it from them first, on the members page. Nor can it delete a role
 * that is the LAST one opening a capability (#727): the set would be left
 * empty without anybody confirming that, so it says which door to point
 * elsewhere first.
 *
 * WHO CAN OPEN WHAT (#727)
 * -------------------------------------------------------------------------
 * Below the role list, each gated "capability" named in
 * `Portal\Core\Capabilities` can be pointed at any of this organisation's
 * roles, its own ones included. Holding any ONE of the ticked roles opens the
 * door. Ticking none is allowed and needs its own confirmation, because what
 * it costs differs from door to door. A global administrator also sees which
 * doors the INSTALLATION reserves to itself.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/516
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Capabilities;
use Portal\Core\InstallationSettings;
use Portal\Core\Roles;
use Portal\Core\Router;
use Portal\Core\Site;

// 🛡️ Any administrator of the organisation currently open may manage its
//    own role list (owner decision, #516 plan Q7) — the same gate every
//    other admin/* page uses; a global administrator passes it everywhere
//    because App::isAdmin() already gives them that.
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();
$site   = Site::current();

// 📌 Page metadata
$pageTitle   = 'Roles';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Admin' => Site::url('admin'), 'Roles' => ''];

// 📋 Every role this organisation has, with how many people hold it.
$roles = Roles::forSite($mysqli, $siteId);
$holderCounts = [];
foreach ($roles as $role) {
    $holderCounts[$role['roleID']] = Roles::countHolders($mysqli, $role['roleID'], $siteId);
}

// 🚪 WHO CAN OPEN WHAT (#727).
//
// The installation-wide layer is read through `InstallationSettings`, NEVER
// through `App::settings()`. #674's fault is this exact shape: `$SETTINGS` is
// already merged by the time a page runs, so a box filled from it shows the
// OPEN ORGANISATION's value while a Save writing `siteID IS NULL` writes the
// INSTALLATION's — one press of Save on an untouched form and it is
// everybody's.
//
// `layerOrNull()` and not `layer()`, because `Capabilities::installationLocks()`
// treats "we cannot tell" as everything locked, which is the safe direction
// between two parties. An empty array would read as "nothing is locked".
$capInstallationLayer = InstallationSettings::layerOrNull();

// Asked once and used for the notice: the two tables arrive with migration 243,
// and files reach a server before anybody presses "Run migrations" (#694). Until
// then every capability is "as shipped" and the page says so plainly rather than
// offering a Save that could not store anything.
$capStorageReady = Capabilities::storageExists($mysqli);

$capRows = [];
foreach (Capabilities::keys() as $capKey) {
    $capDefinition = Capabilities::definition($capKey);
    // null = this organisation has chosen nothing, so the roles it ships with
    // are in force. An empty ARRAY is a different answer: nobody, deliberately.
    $capChosen  = Capabilities::chosenRoleKeys($mysqli, $siteId, $capKey);
    $capInForce = $capChosen !== null ? $capChosen : $capDefinition['standard'];
    $capRows[$capKey] = [
        'definition' => $capDefinition,
        'chosen'     => $capChosen,
        'inForce'    => $capInForce,
        'whyNot'     => Capabilities::whyNotChangeable($capKey, $capInstallationLayer),
        // People, not holdings: somebody holding two of the roles counts once.
        'holders'    => Capabilities::holderCount($mysqli, $siteId, $capInForce),
    ];
}

// 🏢 Only a global administrator sees the installation's own card, because the
//    two levels may be different parties (the standing rule of 29 October 2026).
$capIsGlobalAdmin = App::isRootAdmin();
$capLockedKeys    = Capabilities::installationLocks($capInstallationLayer);

// 📋 Flash message from the save handler
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 🏷️ Roles -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-user-tag me-2"></i>Roles</h1>
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addRoleModal">
        <i class="fa-solid fa-plus me-1"></i>Add Role
    </button>
</div>

<p class="text-muted">
    Access roles held per organisation<?php echo $site !== null ? ' — ' . htmlspecialchars((string) $site['siteName'], ENT_QUOTES, 'UTF-8') : ''; ?>.
    Give somebody a role from the <a href="<?php echo htmlspecialchars(Site::url('admin/users'), ENT_QUOTES, 'UTF-8'); ?>">Users</a> page.
    Leadership positions (elders, deacons) live under
    <a href="<?php echo htmlspecialchars(Site::url('leadership'), ENT_QUOTES, 'UTF-8'); ?>">Leadership</a> instead.
</p>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="portal-data-list">
    <div class="portal-data-row portal-data-header d-none d-md-flex">
        <div class="col-md-3">Label</div>
        <div class="col-md-2">Key</div>
        <div class="col-md-4">Description</div>
        <div class="col-md-1">Holders</div>
        <div class="col-md-2 text-end">Actions</div>
    </div>
    <?php foreach ($roles as $role): ?>
        <?php
        $rid     = (int) $role['roleID'];
        $holders = $holderCounts[$rid] ?? 0;
        ?>
        <div class="portal-data-row">
            <div class="col-12 col-md-3">
                <span class="d-md-none fw-semibold">Label: </span>
                <strong><?php echo htmlspecialchars($role['roleName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                <?php if ($role['isStandard'] === true): ?>
                    <span class="badge bg-secondary ms-1">Standard</span>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-2">
                <span class="d-md-none fw-semibold">Key: </span>
                <code><?php echo htmlspecialchars($role['roleKey'], ENT_QUOTES, 'UTF-8'); ?></code>
            </div>
            <div class="col-12 col-md-4">
                <span class="d-md-none fw-semibold">Description: </span>
                <small class="text-muted"><?php echo htmlspecialchars((string) ($role['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></small>
            </div>
            <div class="col-12 col-md-1">
                <span class="d-md-none fw-semibold">Holders: </span>
                <?php echo (int) $holders; ?>
            </div>
            <div class="col-12 col-md-2 text-md-end mt-2 mt-md-0">
                <button class="btn btn-sm btn-outline-primary portal-edit-role-btn"
                        data-bs-toggle="modal" data-bs-target="#editRoleModal"
                        data-roleid="<?php echo $rid; ?>"
                        data-rolekey="<?php echo htmlspecialchars($role['roleKey'], ENT_QUOTES, 'UTF-8'); ?>"
                        data-rolename="<?php echo htmlspecialchars($role['roleName'], ENT_QUOTES, 'UTF-8'); ?>"
                        data-description="<?php echo htmlspecialchars((string) ($role['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="fa-solid fa-pen me-1"></i>Edit
                </button>
                <?php if ($role['isStandard'] === false && $holders === 0): ?>
                    <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/roles/save'), ENT_QUOTES, 'UTF-8'); ?>" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="roleID" value="<?php echo $rid; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Delete the role &quot;<?php echo htmlspecialchars($role['roleName'], ENT_QUOTES, 'UTF-8'); ?>&quot;? This cannot be undone.">
                            <i class="fa-solid fa-trash me-1"></i>Delete
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- 🚪 Who can open what (#727) ------------------------------------------- -->
<!--
     A "capability" is a door, named in `Portal\Core\Capabilities`, that a role
     opens. Holding ANY ONE of the roles listed against it is enough — any-of,
     never all-of. An organisation that never touches this section sees no
     change at all: with nothing chosen, the roles in force are the ones the
     portal ships with, which are the roles the gate was written in PHP to
     before any of this existed.

     Only capabilities something really asks appear here, and that is enforced
     rather than remembered: a capability whose gate is still hard-coded lives
     in `Capabilities::NOT_YET_ASKED` instead, and the hard gate
     `tools/audit-checks/check_capability_registry.py` fails if either list
     becomes the other. A control that changes nothing anywhere is worse than
     no control (#671). That list is EMPTY today — all six doors below are
     really asked — so nothing is being held back.

     Five of the six were converted on 3 November 2026 (#727): the asset
     register, the pastoral care register, children's check-in, the expenses
     treasury and the livestream chat. Every one of those five ALSO lets an
     administrator of this organisation in without any role at all, which is
     unchanged and is why each one's "what an empty set does" sentence says
     so. #724's erasure review is the one door that does not.
-->
<h2 class="h4 mt-5 mb-2"><i class="fa-solid fa-door-open me-2"></i>Who can open what</h2>
<p class="text-muted">
    Each of these is a door in the portal, and the roles ticked against it are the ones that open it.
    Holding <strong>any one</strong> of them is enough. Leave a door alone and it keeps the role it
    came with.
</p>

<?php if ($capStorageReady === false): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-triangle-exclamation me-1"></i>
        This part of the database has not been upgraded yet, so nothing can be changed here.
        Every door below is opened by the role it came with. A global administrator can run the
        upgrade from
        <a href="<?php echo htmlspecialchars(Site::url('admin/upgrade'), ENT_QUOTES, 'UTF-8'); ?>">Admin, Upgrade</a>.
    </div>
<?php endif; ?>

<?php foreach ($capRows as $capKey => $capRow): ?>
    <?php
    $capDef     = $capRow['definition'];
    $capChosen  = $capRow['chosen'];
    $capInForce = $capRow['inForce'];
    $capWhyNot  = (string) $capRow['whyNot'];
    // Changeable only when the portal and the installation both allow it AND
    // there is somewhere to store the answer.
    $capMayEdit = $capWhyNot === '' && $capStorageReady === true;
    ?>
    <div class="card mb-3">
        <div class="card-body">
            <h3 class="h5 mb-1"><?php echo htmlspecialchars($capDef['label'], ENT_QUOTES, 'UTF-8'); ?></h3>
            <p class="text-muted mb-2"><?php echo htmlspecialchars($capDef['opens'], ENT_QUOTES, 'UTF-8'); ?></p>

            <p class="mb-2">
                <?php if ($capChosen === null): ?>
                    <span class="badge bg-secondary">As the portal ships it</span>
                <?php else: ?>
                    <span class="badge bg-info text-dark">Chosen by this organisation</span>
                <?php endif; ?>
                <?php if ($capDef['globalAdminFallsOpen'] === false): ?>
                    <span class="badge bg-dark">A global administrator needs one of these roles too</span>
                <?php endif; ?>
            </p>

            <?php if ($capDef['globalAdminFallsOpen'] === false): ?>
                <!--
                     Said out loud because it is the opposite of every other door
                     in the portal, and because somebody who cannot get in needs
                     to know it is deliberate rather than broken (#724).
                -->
                <p class="small text-muted mb-2">
                    This one does not open for a global administrator who has not been given one of
                    these roles. That is deliberate: whoever runs this installation may be a
                    different person from whoever runs this organisation, and this door leads to
                    members' own words.
                </p>
            <?php endif; ?>

            <?php if (count($capInForce) === 0): ?>
                <div class="alert alert-danger py-2 mb-2">
                    <i class="fa-solid fa-circle-exclamation me-1"></i>
                    <strong>No role opens this at the moment.</strong>
                    <?php echo htmlspecialchars(Capabilities::emptyMeans($capKey), ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php elseif ((int) $capRow['holders'] === 0): ?>
                <!--
                     The issue's own acceptance line: a mapping naming a role
                     nobody holds must not lock anybody out, and the page must
                     say what will happen. It is a WARNING and not a refusal,
                     because "make the mapping, then grant the role" is the
                     ordinary order of work.
                -->
                <div class="alert alert-warning py-2 mb-2">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>
                    Nobody here holds any of these roles yet, so nobody can open this door.
                    Give somebody one on the
                    <a href="<?php echo htmlspecialchars(Site::url('admin/users'), ENT_QUOTES, 'UTF-8'); ?>">Users</a>
                    page.
                </div>
            <?php endif; ?>

            <?php if ($capMayEdit === false): ?>
                <p class="mb-1"><strong>Opened by:</strong></p>
                <ul class="mb-2">
                    <?php foreach ($capInForce as $capRoleKey): ?>
                        <li><code><?php echo htmlspecialchars($capRoleKey, ENT_QUOTES, 'UTF-8'); ?></code></li>
                    <?php endforeach; ?>
                    <?php if (count($capInForce) === 0): ?>
                        <li class="text-muted">nobody, by role</li>
                    <?php endif; ?>
                </ul>
                <?php if ($capWhyNot !== ''): ?>
                    <p class="small text-muted mb-0">
                        <i class="fa-solid fa-lock me-1"></i>
                        <?php echo htmlspecialchars($capWhyNot, ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                <?php endif; ?>
            <?php else: ?>
                <!--
                     ⚠️ NO `disabled` CONTROLS HERE. A disabled control is not
                        submitted (#667/#677), so a form drawn for somebody who
                        may not change it would post an EMPTY set and read as a
                        deliberate "nobody". Where it may not be changed, the
                        form above is not drawn at all — and the handler asks
                        `whyNotChangeable()` again anyway, because the page is
                        never the boundary.
                -->
                <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/roles/save'), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="capability">
                    <input type="hidden" name="capability" value="<?php echo htmlspecialchars($capKey, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="row">
                        <?php foreach ($roles as $capRole): ?>
                            <?php
                            $capRoleKey  = strtolower($capRole['roleKey']);
                            $capTicked   = in_array($capRoleKey, $capInForce, true);
                            $capRoleHeld = $holderCounts[(int) $capRole['roleID']] ?? 0;
                            $capBoxId    = 'cap-' . md5($capKey . '|' . $capRoleKey);
                            ?>
                            <div class="col-12 col-md-6 col-lg-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           name="roleKeys[]"
                                           value="<?php echo htmlspecialchars($capRoleKey, ENT_QUOTES, 'UTF-8'); ?>"
                                           id="<?php echo htmlspecialchars($capBoxId, ENT_QUOTES, 'UTF-8'); ?>"
                                           <?php echo $capTicked === true ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="<?php echo htmlspecialchars($capBoxId, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($capRole['roleName'], ENT_QUOTES, 'UTF-8'); ?>
                                        <small class="text-muted">
                                            (<?php echo (int) $capRoleHeld; ?>
                                            <?php echo $capRoleHeld === 1 ? 'holder' : 'holders'; ?>)
                                        </small>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!--
                         🫙 THE EMPTY SET IS A DECISION, NOT AN ACCIDENT (the
                            owner, 3 November 2026). Saving with nothing ticked
                            needs this box ticked as well, and the handler
                            refuses without it — so it cannot be reached by a
                            mis-click or by a form that simply named no role.
                            The sentence is the capability's own
                            `emptyMeans`, not a general warning: what zero
                            roles costs differs from door to door.
                    -->
                    <div class="alert alert-light border mt-3 mb-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="confirm_empty" value="1"
                                   id="cap-empty-<?php echo htmlspecialchars(md5($capKey), ENT_QUOTES, 'UTF-8'); ?>">
                            <label class="form-check-label"
                                   for="cap-empty-<?php echo htmlspecialchars(md5($capKey), ENT_QUOTES, 'UTF-8'); ?>">
                                <strong>Tick none of the boxes above?</strong> Then tick this one too, to say you
                                mean it. <?php echo htmlspecialchars(Capabilities::emptyMeans($capKey), ENT_QUOTES, 'UTF-8'); ?>
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fa-solid fa-floppy-disk me-1"></i>Save who opens this
                    </button>
                </form>

                <?php if ($capChosen !== null): ?>
                    <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/roles/save'), ENT_QUOTES, 'UTF-8'); ?>" class="mt-2">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="action" value="capability_reset">
                        <input type="hidden" name="capability" value="<?php echo htmlspecialchars($capKey, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" class="btn btn-outline-secondary btn-sm"
                                data-confirm="Forget this organisation's choice and go back to the role this door came with?">
                            <i class="fa-solid fa-rotate-left me-1"></i>Back to the role it came with
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>

<?php if ($capIsGlobalAdmin === true): ?>
    <!--
         🏢 THE INSTALLATION'S OWN CARD, for a global administrator only.
            One installation may serve many organisations, and whoever runs it
            may be a different person from whoever runs each organisation (the
            standing rule of 29 October 2026). Reserving a door here stops every
            organisation on this installation pointing it anywhere else; it does
            not change who currently opens it.
    -->
    <div class="card mb-3 border-dark">
        <div class="card-body">
            <h3 class="h5 mb-1"><i class="fa-solid fa-building-lock me-2"></i>Reserved by this installation</h3>
            <p class="text-muted mb-2">
                You are seeing this because you are a global administrator. Anything ticked here cannot
                be changed by an organisation on this installation — including this one. Nothing is
                ticked to begin with.
            </p>
            <?php if ($capInstallationLayer === null): ?>
                <div class="alert alert-warning py-2 mb-0">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>
                    This installation's own settings were not available on this request, so this cannot
                    be shown or changed now. Nothing has been lost; try again.
                </div>
            <?php else: ?>
                <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/roles/save'), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="capability_locks">
                    <?php foreach ($capRows as $capKey => $capRow): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="lockedKeys[]"
                                   value="<?php echo htmlspecialchars($capKey, ENT_QUOTES, 'UTF-8'); ?>"
                                   id="cap-lock-<?php echo htmlspecialchars(md5($capKey), ENT_QUOTES, 'UTF-8'); ?>"
                                   <?php echo in_array($capKey, $capLockedKeys, true) === true ? 'checked' : ''; ?>>
                            <label class="form-check-label"
                                   for="cap-lock-<?php echo htmlspecialchars(md5($capKey), ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($capRow['definition']['label'], ENT_QUOTES, 'UTF-8'); ?>
                                <code class="ms-1"><?php echo htmlspecialchars($capKey, ENT_QUOTES, 'UTF-8'); ?></code>
                            </label>
                        </div>
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-dark btn-sm mt-2">
                        <i class="fa-solid fa-floppy-disk me-1"></i>Save what this installation reserves
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- ➕ Add Role Modal -->
<div class="modal fade" id="addRoleModal" tabindex="-1" aria-labelledby="addRoleLabel" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/roles/save'), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title" id="addRoleLabel"><i class="fa-solid fa-plus me-1"></i>Add Role</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Key <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="roleKey" pattern="[a-z][a-z0-9_]{1,49}" required>
                        <div class="form-text">2-50 characters: lower-case letters, digits and underscores, starting with a letter. This never changes once created.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Label <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="roleName" maxlength="100" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" class="form-control" name="description" maxlength="255">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fa-solid fa-plus me-1"></i>Add Role</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ✏️ Edit Role Modal -->
<div class="modal fade" id="editRoleModal" tabindex="-1" aria-labelledby="editRoleLabel" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/roles/save'), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="roleID" id="edit-roleID">
                <div class="modal-header">
                    <h5 class="modal-title" id="editRoleLabel"><i class="fa-solid fa-pen me-1"></i>Edit Role</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Key</label>
                        <input type="text" class="form-control" id="edit-roleKey" disabled>
                        <div class="form-text">Keys are fixed for life — the places in the code that check for a role compare against this, never the label below.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Label <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="roleName" id="edit-roleName" maxlength="100" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" class="form-control" name="description" id="edit-description" maxlength="255">
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

<script nonce="<?php echo htmlspecialchars(\Portal\Core\App::cspNonce(), ENT_QUOTES, 'UTF-8'); ?>">
var editRoleModal = document.getElementById('editRoleModal');
editRoleModal.addEventListener('show.bs.modal', function (event) {
    var btn = event.relatedTarget;
    document.getElementById('edit-roleID').value      = btn.getAttribute('data-roleid');
    document.getElementById('edit-roleKey').value      = btn.getAttribute('data-rolekey');
    document.getElementById('edit-roleName').value     = btn.getAttribute('data-rolename');
    document.getElementById('edit-description').value  = btn.getAttribute('data-description');
});
</script>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
