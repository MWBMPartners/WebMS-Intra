<?php
// Path: _install/db_state.php
/**
 * -----------------------------------------------------------------------------
 * WebMS Intra — Database State Detection 🔍
 * -----------------------------------------------------------------------------
 * Bootstrap-free helper. Given a working mysqli connection (database
 * already selected), report what the installer is looking at — empty
 * DB, partial install, current install, or upgrade-needed install.
 *
 * The installer uses this in step 2 (after DB credentials validate)
 * to route the user appropriately:
 *
 *   STATE_EMPTY              → step 3 (fresh schema install)
 *   STATE_PARTIAL            → step 2.5 (continue vs drop-and-rebuild)
 *   STATE_INSTALLED_CURRENT  → render "already installed" page
 *   STATE_INSTALLED_UPGRADE  → step 2.5 with upgrade messaging
 *   STATE_FRESH_REQUIRED     → step 2.5 with continue option DISABLED
 *
 * It also keeps THE CHOICE made for a database that is not empty ("carry on"
 * or "wipe"), tied to the one database it was made for: see
 * installDbIdentity(), installChoiceKeep() and installChoiceHeld() at the
 * foot of this file. They live here, and not in `_install/index.php`, so
 * that tools/installer-selftest.php can load them alone and hand them every
 * case in a table.
 *
 * @package   Portal\Install
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

const DB_STATE_EMPTY             = 'empty';
const DB_STATE_PARTIAL           = 'partial';
const DB_STATE_INSTALLED_CURRENT = 'installed_current';
const DB_STATE_INSTALLED_UPGRADE = 'installed_upgrade';
const DB_STATE_FRESH_REQUIRED    = 'fresh_required';

/**
 * Probe a live mysqli connection and classify the database state.
 *
 * @param mysqli  $db              Live, database-selected connection.
 * @param string  $codeVersion     Current code version (from version.php).
 * @param array   $policy          The upgrade-policy.php array.
 * @param bool    $lockFileExists  Whether _auth_keys/.installed exists.
 *
 * @return array{state: string, installed_version: ?string,
 *                table_count: int, has_settings: bool, notes: string[]}
 */
function detectDbState(
    mysqli $db,
    string $codeVersion,
    array $policy,
    bool $lockFileExists
): array {
    $notes = [];

    // 🔍 Count portal tables (tbl* prefix) — a freshly-created database
    //    that hasn't seen any schema yet has zero.
    $tableCount = 0;
    try {
        $rs = $db->query("SHOW TABLES LIKE 'tbl%'");
        if ($rs !== false) {
            $tableCount = $rs->num_rows;
            $rs->free();
        }
    } catch (\mysqli_sql_exception $e) {
        $notes[] = 'Table enumeration failed: ' . $e->getMessage();
    }

    // 📋 Does tblSettings exist? If not, no point trying to read it.
    $hasSettings = false;
    try {
        $rs = $db->query("SHOW TABLES LIKE 'tblSettings'");
        if ($rs !== false) {
            $hasSettings = $rs->num_rows > 0;
            $rs->free();
        }
    } catch (\mysqli_sql_exception $e) {
        // Treat as no settings — we'll fall through to other heuristics.
    }

    // 🆔 Read portal.installed_version if available.
    $installedVersion = null;
    if ($hasSettings === true) {
        try {
            $stmt = $db->prepare(
                "SELECT settingValue FROM tblSettings "
                . "WHERE settingKey = 'portal.installed_version' "
                . "  AND (siteID IS NULL OR siteID = 1) "
                . "ORDER BY siteID DESC LIMIT 1"
            );
            if ($stmt !== false) {
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row !== null && is_string($row['settingValue'])) {
                    $val = trim($row['settingValue']);
                    if ($val !== '') {
                        $installedVersion = $val;
                    }
                }
            }
        } catch (\mysqli_sql_exception $e) {
            $notes[] = 'Could not read portal.installed_version: ' . $e->getMessage();
        }
    }

    // 🚏 Routing logic.
    //
    //    Empty DB                                          → fresh
    //    Tables present, .installed exists                 → already installed
    //         AND installed_version < code_version         → upgrade
    //         AND installed_version < fresh_required_below → must reinstall
    //         AND installed_version == code_version        → already installed
    //    Tables present, .installed missing                → partial install
    //         (user got into step 3 successfully but never reached step 5)
    if ($tableCount === 0) {
        return [
            'state'             => DB_STATE_EMPTY,
            'installed_version' => null,
            'table_count'       => 0,
            'has_settings'      => false,
            'notes'             => $notes,
        ];
    }

    $freshFloor = $policy['fresh_required_below'] ?? '0.0.0';

    if ($lockFileExists === true) {
        // Completed install. Compare versions.
        if ($installedVersion !== null) {
            if (version_compare($installedVersion, $freshFloor, '<')) {
                return [
                    'state'             => DB_STATE_FRESH_REQUIRED,
                    'installed_version' => $installedVersion,
                    'table_count'       => $tableCount,
                    'has_settings'      => $hasSettings,
                    'notes'             => $notes,
                ];
            }
            if (version_compare($installedVersion, $codeVersion, '<')) {
                return [
                    'state'             => DB_STATE_INSTALLED_UPGRADE,
                    'installed_version' => $installedVersion,
                    'table_count'       => $tableCount,
                    'has_settings'      => $hasSettings,
                    'notes'             => $notes,
                ];
            }
            // installed_version >= code_version → fully current.
            return [
                'state'             => DB_STATE_INSTALLED_CURRENT,
                'installed_version' => $installedVersion,
                'table_count'       => $tableCount,
                'has_settings'      => $hasSettings,
                'notes'             => $notes,
            ];
        }

        // 🪞 Lock file present but no installed_version recorded — legacy
        //    install from a build before we tracked the version. Treat
        //    as current to avoid breaking existing deployments; the next
        //    upgrade pass will record the version.
        return [
            'state'             => DB_STATE_INSTALLED_CURRENT,
            'installed_version' => null,
            'table_count'       => $tableCount,
            'has_settings'      => $hasSettings,
            'notes'             => array_merge($notes, [
                'Lock file present but portal.installed_version not recorded — assuming legacy current.',
            ]),
        ];
    }

    // No lock file but tables exist → partial install (step 3 succeeded,
    // step 5 never completed).
    return [
        'state'             => DB_STATE_PARTIAL,
        'installed_version' => $installedVersion,
        'table_count'       => $tableCount,
        'has_settings'      => $hasSettings,
        'notes'             => $notes,
    ];
}

// ---------------------------------------------------------------------------
// 🪪 The choice made for ONE database, and which database that was
// ---------------------------------------------------------------------------
// WHAT WAS WRONG (found by the check of 13 October 2026). The choice was kept
// in the session as one bare word, 'drop' or 'continue', with nothing to say
// which database it had been made for. Somebody chose "wipe" and confirmed it
// for database ONE, went Back, typed database TWO, opened the build step by
// its address and pressed "Build the tables": database TWO was wiped, and
// its uploaded files with it, though no choice had ever been made for it.
//
// THE CURE has three parts, and each stands without the others:
//   1. The choice is kept TOGETHER WITH the identity of the database it was
//      made for, and installChoiceHeld() answers "none" the moment the
//      database the installer holds is not that one.
//   2. The database step forgets any earlier choice whenever it lets a
//      database through (in `_install/index.php`).
//   3. The build step wipes only on a wipe choice held for the database it
//      is about to touch, and builds nothing into a database that is not
//      empty while no choice is held for it (also there).
//
// WHAT THIS CANNOT DO: tell two databases apart that are reached by the same
// server name, port, database name and user (a server that was swapped for
// another behind one address). And it knows nothing of what a database holds:
// the build step looks at that for itself.

/** The session key the choice is kept under. An older session may still hold a bare word there: that is "no choice". */
const INSTALL_CHOICE_KEY = 'install_action';

/** The three choices: built into an empty database, carried on with, or wiped first. */
const INSTALL_CHOICES = ['fresh', 'continue', 'drop'];

/**
 * 🪪 Which database a set of connection details means: the server, the port,
 * the database's name and the user. NOT the password: the same database
 * reached with a corrected password is still the same database.
 *
 * The four are compared exactly as they were typed. `LOCALHOST` and
 * `localhost` are the same server, and this calls them different: that only
 * ever means a choice has to be made again, which is the safe mistake.
 *
 * @param mixed $details What step 2 kept (`host`, `user`, `name`, `port`), or
 *                       anything else when it kept nothing.
 *
 * @return string '' when the details are not a database's; otherwise text
 *                that is the same for the same four values and for no others.
 */
function installDbIdentity(mixed $details): string
{
    if (is_array($details) === false) {
        return '';
    }
    foreach (['host', 'user', 'name', 'port'] as $part) {
        if (isset($details[$part]) === false || is_scalar($details[$part]) === false) {
            return '';
        }
    }
    // JSON keeps the four apart whatever characters they hold: joined with a
    // plain separator, host "a" with name "b|c" would equal host "a|b" with
    // name "c".
    $identity = json_encode([(string) $details['host'], (int) $details['port'], (string) $details['name'], (string) $details['user']]);

    return is_string($identity) === true ? $identity : '';
}

/**
 * ✍️ Keep a choice FOR THE DATABASE THE INSTALLER HOLDS NOW (`install_db` in
 * the session). With no database held, or a word that is not a choice,
 * nothing is kept and any earlier choice is forgotten.
 */
function installChoiceKeep(string $choice): void
{
    $identity = installDbIdentity($_SESSION['install_db'] ?? null);
    if ($identity === '' || in_array($choice, INSTALL_CHOICES, true) === false) {
        installChoiceForget();

        return;
    }
    $_SESSION[INSTALL_CHOICE_KEY] = ['choice' => $choice, 'database' => $identity];
}

/** 🧽 Forget the choice, whatever database it was for. */
function installChoiceForget(): void
{
    unset($_SESSION[INSTALL_CHOICE_KEY]);
}

/**
 * 🔎 The choice held for the database the installer holds NOW, or '' when
 * there is none: no choice was made, no database is held, or the choice was
 * made for a database whose server, port, name or user differs.
 *
 * Every reader of the choice goes through here. Nothing in the installer may
 * read the session's entry directly: that is how a wipe chosen for one
 * database once reached another.
 */
function installChoiceHeld(): string
{
    $kept     = $_SESSION[INSTALL_CHOICE_KEY] ?? null;
    $identity = installDbIdentity($_SESSION['install_db'] ?? null);
    if (is_array($kept) === false || $identity === ''
        || is_string($kept['choice'] ?? null) === false || is_string($kept['database'] ?? null) === false
        || in_array($kept['choice'], INSTALL_CHOICES, true) === false
        || hash_equals($identity, $kept['database']) === false
    ) {
        return '';
    }

    return $kept['choice'];
}
