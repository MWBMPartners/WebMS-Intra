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
 * @package   Portal\Install
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
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
