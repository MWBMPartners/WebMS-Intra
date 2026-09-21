<?php
// Path: public_html/admin/sites/save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Save Site 🌐
 * -----------------------------------------------------------------------------
 * POST handler for creating or updating a site record in tblSites.
 * Umbrella admin only. Redirects back to admin/sites with flash message.
 *
 * @package   Portal\App\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.9.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/45
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\ReservedKeys;
use Portal\Core\Roles;

// 🛡️ POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/sites', true, 302);
    exit();
}

// 🛡️ Umbrella admin only
if (App::isUmbrellaAdmin() === false) {
    http_response_code(403);
    echo t('error.access_denied_inline');
    exit();
}

// 🛡️ CSRF
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/sites');
    exit();
}

$db = App::db();

// 📋 Sanitise inputs
$siteID       = (int) ($_POST['siteID'] ?? 0);
$siteName     = trim($_POST['siteName'] ?? '');
$siteKey      = trim(strtolower($_POST['siteKey'] ?? ''));
$hostPattern  = trim($_POST['hostPattern'] ?? '');
$logoPath     = trim($_POST['logoPath'] ?? '/assets/images/logo.svg');
$faviconPath  = trim($_POST['faviconPath'] ?? '');
$primaryColor = trim($_POST['primaryColor'] ?? '#5e6ad2');
$copyrightOrg = trim($_POST['copyrightOrg'] ?? '');
$timezone     = trim($_POST['timezone'] ?? 'UTC');
$isActive     = isset($_POST['isActive']) ? 1 : 0;

// 🔍 Validate primaryColor as #RGB or #RRGGBB hex; fall back to indigo default
if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $primaryColor) !== 1) {
    $primaryColor = '#5e6ad2';
}

// 🔍 Validate required fields
if ($siteName === '' || $siteKey === '') {
    $_SESSION['flash_msg'] = 'Site name and key are required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/sites', true, 302);
    exit();
}

// 🔍 Validate siteKey format (alphanumeric + hyphens only)
if (preg_match('/^[a-z0-9\-]+$/', $siteKey) !== 1) {
    $_SESSION['flash_msg'] = 'Site key must contain only lowercase letters, numbers, and hyphens.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/sites', true, 302);
    exit();
}

// 🔍 Validate timezone
if (in_array($timezone, timezone_identifiers_list(), true) === false) {
    $timezone = 'UTC';
}

// 🔒 #515 — refuse a key that would take over an address the portal itself
// already answers on. Before this existed, the only check here was the
// lowercase/digits/hyphen format above — nothing stopped an administrator
// keying an organisation "offline" or "login". That is not a cosmetic
// clash: measured on a real database, an active organisation keyed "login"
// turns the bare /login address into a redirect loop that never resolves
// (Site::detectFromPath() matches the key, strips it, and the sign-in
// redirect it produces has the key stripped straight back off again), and
// one keyed "offline" can make a signed-in visitor's browser store THAT
// organisation's dashboard as the offline fallback page — see Auth.php's
// oldServiceWorkerWouldStore()/logout() comments for the full case this
// closes.
//
// ReservedKeys::kindsFor() is asked ONCE here, before either the create or
// the update branch, so a reserved key can never be written by either path.
// If the check itself cannot run — the routes query breaks, say — nothing
// is saved. That is deliberate: proceeding on a check that never actually
// ran would defeat the whole point of having it, so this fails CLOSED
// rather than falling back to "let it through".
try {
    $reservedKinds = ReservedKeys::kindsFor($db, $siteKey);
} catch (\Throwable $e) {
    Logger::errorPlatform('ReservedKeys', 'Error', 'CHECK_FAIL', $e->getMessage(), 'siteKey=' . $siteKey);
    $_SESSION['flash_msg'] = 'The portal could not check whether that site key is reserved, so nothing was saved. Try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/sites', true, 302);
    exit();
}
// 📝 Not touched here: a key that is merely a DUPLICATE of another
// organisation's own (non-reserved) key still fails only with the
// database's raw "Duplicate entry" text further down. That is a separate,
// pre-existing rough edge — this package's job is the reserved-key clash,
// not every unfriendly message this page can produce.

if ($siteID > 0) {
    // 📖 #515 — read the row exactly as it is stored NOW, before anything is
    // written, so the refusal below can tell "this key was ALREADY like
    // this" (leave it alone — the issue asks that an existing clash is not
    // broken or renamed automatically) apart from "this edit is ABOUT to
    // create a new clash, or switch on an organisation that already has
    // one" (refuse it). A row that no longer exists (deleted by someone else
    // mid-edit) leaves $storedKey as '', so the comparison below treats it
    // exactly like a brand-new key rather than silently skipping the check.
    $storedKey = '';
    $storedActive = 0;
    $rowStmt = $db->prepare('SELECT siteKey, isActive FROM tblSites WHERE siteID = ?');
    if ($rowStmt !== false) {
        $rowStmt->bind_param('i', $siteID);
        $rowStmt->execute();
        $storedRow = $rowStmt->get_result()->fetch_assoc();
        $rowStmt->close();
        if ($storedRow !== null) {
            $storedKey = strtolower((string) $storedRow['siteKey']);
            // 🔢 Arrives from this prepared statement as the whole NUMBER 1
            //    or 0, never the text '1' (#497) — cast with (int), never
            //    compared with === '1'.
            $storedActive = (int) $storedRow['isActive'];
        }
    }

    // 🔒 #515 — refuse only the two edits that would actually CHANGE how
    // much harm a reserved key does: changing the key itself (to, or onto, a
    // reserved value), or switching on an organisation whose key is already
    // reserved (one checkbox turning a harmless-while-off row into a live
    // takeover of the address it clashes with). Everything else — the
    // name, colours, host pattern, timezone, or switching such an
    // organisation OFF — stays editable with the reserved key untouched.
    // That is the issue's own answer to "what happens to an organisation
    // that already has a reserved key": leave it working, warn until an
    // administrator changes it (see the health probe, admin dashboard and
    // organisations-page badge this package also adds), never rename or
    // break it automatically.
    $keyChanged  = ($siteKey !== $storedKey);
    $switchingOn = ($isActive === 1 && $storedActive === 0);
    if ($reservedKinds !== [] && ($keyChanged === true || $switchingOn === true)) {
        if ($keyChanged === true) {
            $_SESSION['flash_msg'] = ReservedKeys::describe($siteKey, $reservedKinds)
                . ' An organisation with that key would take over the address /' . $siteKey . '/ '
                . 'whenever the portal uses address prefixes. Choose a different key.';
        } else {
            $_SESSION['flash_msg'] = ReservedKeys::describe($siteKey, $reservedKinds)
                . ' The organisation cannot be switched on until its key is changed, because as soon '
                . 'as it is on its pages would take over /' . $siteKey . '/. Change the key, then '
                . 'switch it on.';
        }
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/sites', true, 302);
        exit();
    }

    // ♻️ UPDATE existing site
    $stmt = $db->prepare(
        'UPDATE tblSites SET siteName = ?, siteKey = ?, hostPattern = ?, logoPath = ?, '
        . 'faviconPath = ?, primaryColor = ?, copyrightOrg = ?, timezone = ?, isActive = ? '
        . 'WHERE siteID = ?'
    );
    if ($stmt === false) {
        $_SESSION['flash_msg'] = t('error.db_with_detail', ['detail' => $db->error]);
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/sites', true, 302);
        exit();
    }

    $hostPatternVal = ($hostPattern !== '') ? $hostPattern : null;
    $copyrightVal   = ($copyrightOrg !== '') ? $copyrightOrg : null;
    $faviconVal     = ($faviconPath !== '') ? $faviconPath : null;

    $stmt->bind_param(
        'ssssssssii',
        $siteName, $siteKey, $hostPatternVal, $logoPath, $faviconVal,
        $primaryColor, $copyrightVal, $timezone, $isActive, $siteID
    );

    if ($stmt->execute() === true) {
        Logger::activity('SiteUpdate', 'Updated site #' . $siteID . ' (' . $siteName . ')');
        $_SESSION['flash_msg'] = 'Site "' . $siteName . '" updated successfully.';
        $_SESSION['flash_type'] = 'success';
    } else {
        $_SESSION['flash_msg'] = 'Failed to update site: ' . $stmt->error;
        $_SESSION['flash_type'] = 'danger';
    }
    $stmt->close();
} else {
    // 🔒 #515 — a brand-new organisation has no existing row to compare
    // against, so any reserved key at all is refused outright: there is no
    // "it was already like this" case for a create.
    if ($reservedKinds !== []) {
        $_SESSION['flash_msg'] = ReservedKeys::describe($siteKey, $reservedKinds)
            . ' An organisation with that key would take over the address /' . $siteKey . '/ '
            . 'whenever the portal uses address prefixes. Choose a different key.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/sites', true, 302);
        exit();
    }

    // ➕ INSERT new site, and seed its standard role set, IN ONE
    //    TRANSACTION (#516). An organisation that existed with no roles at
    //    all would make every role-gated feature (Expenses treasury,
    //    Care, Kids check-in, and the rest) unusable there from the
    //    moment it was created, until somebody happened to notice and add
    //    roles by hand — so the two writes succeed or fail together.
    $db->begin_transaction();
    try {
        $stmt = $db->prepare(
            'INSERT INTO tblSites (siteName, siteKey, hostPattern, logoPath, faviconPath, primaryColor, copyrightOrg, timezone, isActive) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('tblSites prepare failed: ' . $db->error);
        }

        $hostPatternVal = ($hostPattern !== '') ? $hostPattern : null;
        $copyrightVal   = ($copyrightOrg !== '') ? $copyrightOrg : null;
        $faviconVal     = ($faviconPath !== '') ? $faviconPath : null;

        $stmt->bind_param(
            'ssssssssi',
            $siteName, $siteKey, $hostPatternVal, $logoPath, $faviconVal,
            $primaryColor, $copyrightVal, $timezone, $isActive
        );
        $stmt->execute();
        $newSiteId = (int) $stmt->insert_id;
        $stmt->close();

        // 🏷️ #516: the fourteen standard roles, for this organisation only.
        Roles::seedStandardSet($db, $newSiteId);

        $db->commit();

        Logger::activity('SiteCreate', 'Created site #' . $newSiteId . ' (' . $siteName . ')');
        $_SESSION['flash_msg'] = 'Site "' . $siteName . '" created successfully.';
        $_SESSION['flash_type'] = 'success';
    } catch (\Throwable $e) {
        $db->rollback();
        Logger::errorPlatform('Sites', 'Error', 'ROLE_SEED_FAILED', 'Failed to create site and seed its roles', $e->getMessage());
        $_SESSION['flash_msg'] = t('error.db_with_detail', ['detail' => $e->getMessage()]);
        $_SESSION['flash_type'] = 'danger';
    }
}

header('Location: /admin/sites', true, 302);
exit();
