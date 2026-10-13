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
use Portal\Core\Site;

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
// The zone box as posted; '' when it was left out. What it may hold is decided
// under "Validate timezone" below.
$timezone     = trim((string) ($_POST['timezone'] ?? ''));
// Added to "saved" while the form does not take a zone: see "BEFORE MIGRATION 217 HAS RUN" below.
$zoneNotTakenWords = ' Its time zone was NOT changed: this installation has not yet run its database upgrade. Run it at Admin, Upgrade, then choose the time zone here.';
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

// 🔍 Validate timezone (#604). The organisation's row is the ONE place its
//    zone is kept, and Site::timezone() answers UTC for anything stored here
//    that is not a zone name. So only a real name may be stored: one from
//    the list the form offers, by the same test (Site::isZoneName()).
//    WHAT WAS WRONG: anything else was quietly stored as "UTC". The form's
//    box shows nothing selected for an organisation whose stored zone is not
//    on the list, so saving any other change to such an organisation (its
//    name, say) silently replaced its zone with UTC and nobody was told.
//    Now it is refused, naming the box, and nothing is saved.
//
//    🚧 BEFORE MIGRATION 217 HAS RUN (new files in place, Upgrade not yet
//    pressed) THIS FORM DOES NOT TAKE A ZONE AT ALL. Until then the portal
//    still follows the old setting, and the migration writes what that
//    setting says over every organisation's row. A zone saved here in that
//    gap answered "updated successfully", changed nothing anybody could see,
//    and was then replaced (found by the independent check, 13 October
//    2026). So the row is left exactly as it is, a new organisation gets the
//    column's own "none chosen", and the message below says to run the
//    upgrade first. Everything else on the form is saved as usual.
$zoneNotTaken = Site::timezoneFacts()['inRow'] === false;
if ($zoneNotTaken === true) {
    $timezone  = Site::ZONE_WHEN_NONE;
    $zoneStmt  = $siteID > 0 ? $db->prepare('SELECT timezone FROM tblSites WHERE siteID = ? LIMIT 1') : false;
    if ($zoneStmt !== false) {
        $zoneStmt->bind_param('i', $siteID);
        $zoneStmt->execute();
        $timezone = (string) ($zoneStmt->get_result()->fetch_assoc()['timezone'] ?? Site::ZONE_WHEN_NONE);
        $zoneStmt->close();
    }
} elseif (Site::isZoneName($timezone) === false) {
    $_SESSION['flash_msg'] = $timezone === ''
        ? 'Choose the organisation\'s time zone in the "Time zone" box. Nothing was saved.'
        : 'The "Time zone" box holds "' . mb_substr($timezone, 0, 64) . '", which is not a time zone name the portal knows. '
            . 'Choose one from the list. Nothing was saved.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/sites'), true, 302);
    exit();
}

// 🔒 #502 — the organisation's OWN ADDRESS, used when each organisation has
// an address of its own. It used to be stored exactly as typed, with no check
// at all, and nothing stopped two organisations being given the same one.
// Either mistake fails silently: "https://north.example.org/" can never equal
// the host name a browser sends, so that organisation quietly came out as the
// default one; and with one address on two organisations, which of them
// answered was whichever the database happened to return first.
//
// So: only a plain host name is accepted (Site::cleanHostName() tidies
// capitals and a trailing dot, and refuses everything else rather than
// repairing it), and an address another organisation holds is refused, by
// name, before anything is written. Both run ONCE, before the create and the
// update branches, so neither path can skip them.
//
// The database has the same "one address each" rule since migration 212
// (the key uq_site_host), except on a database that already held a shared
// address when it was upgraded. This check does not depend on that key.
//
// An EMPTY address is a separate question, answered just below this block.
$cleanHost = Site::cleanHostName($hostPattern);
if ($cleanHost === null) {
    $_SESSION['flash_msg'] = 'The organisation\'s own address must be a plain host name, such as north.example.org: '
        . 'no "https://", no "/", no port number and no spaces. Nothing was saved.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/sites'), true, 302);
    exit();
}
$hostPattern = $cleanHost;
if ($hostPattern !== '') {
    $holderStmt = $db->prepare('SELECT siteName FROM tblSites WHERE hostPattern = ? AND siteID <> ? LIMIT 1');
    if ($holderStmt === false) {
        $_SESSION['flash_msg'] = 'The portal could not check whether that address is already in use, so nothing was saved. Try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/sites'), true, 302);
        exit();
    }
    $holderStmt->bind_param('si', $hostPattern, $siteID);
    $holderStmt->execute();
    $holder = $holderStmt->get_result()->fetch_assoc();
    $holderStmt->close();
    if ($holder !== null) {
        $_SESSION['flash_msg'] = 'The address ' . $hostPattern . ' already belongs to the organisation "' . $holder['siteName']
            . '". Each organisation needs an address of its own. Nothing was saved.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/sites'), true, 302);
        exit();
    }
}

// 🔒 #502 — while organisations are told apart by their own addresses, an
// organisation that is switched on must HAVE an address.
//
// WHAT WAS WRONG. Clearing the address of an active organisation was saved
// without a word. From then on no address opened it: its own members were
// refused everywhere (the members-only gate), and nothing on screen said
// why (found by the builder and confirmed by the independent check,
// 12 October 2026). The set-up page already refuses to switch this way ON
// in that state; this is the same rule for a change made afterwards. It runs
// before the create and the update branches, so neither can skip it.
//
// THE DEFAULT ORGANISATION (number 1) IS LEFT ALONE, on purpose. It is not
// unreachable without an address: it answers at every address that no other
// organisation holds (Site::detectFromSubdomain()). An installation put into
// this way straight in the database, before the set-up page existed, runs
// like that today, and refusing here would stop its administrator saving
// that organisation's name or logo.
//
// WHAT IT DOES NOT DO: mend an organisation that is already active with no
// address. It says so the next time that organisation is saved, and until
// then nothing warns anybody. Switching an organisation off with no address
// is always allowed.
if ($hostPattern === '' && $isActive === 1 && $siteID !== 1
    && Site::isMultisiteEnabled() === true && Site::detectionMode() === Site::MODE_HOST) {
    $_SESSION['flash_msg'] = 'The organisations on this installation are told apart by their own addresses, so an organisation '
        . 'that is switched on needs one: without an address nobody can open it, its own members included. '
        . 'Give "' . $siteName . '" an address, or switch it off. Nothing was saved.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/sites'), true, 302);
    exit();
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
    // 🔒 #502 — at least one organisation must stay switched on.
    //
    // WHAT WAS WRONG. Nothing stopped the last active organisation being
    // switched off. On an installation that serves several organisations,
    // everybody who signed in was then refused on every page, global
    // administrators included, the upgrade and set-up pages too, and only
    // somebody with access to the database could put it right (ran by the
    // independent check, 12 October 2026).
    //
    // If the count cannot be made, nothing is saved: a switch-off is never
    // allowed on a check that did not run.
    //
    // WHAT IT CANNOT DO: stop two administrators each switching off one of
    // the last two organisations in the same instant; each would see the
    // other's still on. And it does not stop the DEFAULT organisation being
    // switched off while another stays on.
    if ($isActive === 0 && $storedActive === 1) {
        $othersOn  = null;
        $countStmt = $db->prepare('SELECT COUNT(*) AS n FROM tblSites WHERE isActive = 1 AND siteID <> ?');
        if ($countStmt !== false) {
            $countStmt->bind_param('i', $siteID);
            $countStmt->execute();
            // 🔢 A count from a prepared statement is a number; cast, never compare with text.
            $othersOn = (int) ($countStmt->get_result()->fetch_assoc()['n'] ?? 0);
            $countStmt->close();
        }
        if ($othersOn === null) {
            $_SESSION['flash_msg'] = 'The portal could not check whether another organisation is switched on, so nothing was saved. Try again.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . Site::url('admin/sites'), true, 302);
            exit();
        }
        if ($othersOn === 0) {
            $_SESSION['flash_msg'] = 'At least one organisation must stay switched on, and "' . $siteName . '" is the only one that is. '
                . 'If an installation that serves several organisations has none switched on, nobody who signs in can open any page, '
                . 'administrators included, and only somebody with access to the database can put that right. '
                . 'Switch another organisation on first. Nothing was saved.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . Site::url('admin/sites'), true, 302);
            exit();
        }
    }

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
        $_SESSION['flash_msg'] = 'Site "' . $siteName . '" updated successfully.' . ($zoneNotTaken === true ? $zoneNotTakenWords : '');
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
        $_SESSION['flash_msg'] = 'Site "' . $siteName . '" created successfully.' . ($zoneNotTaken === true ? $zoneNotTakenWords : '');
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
