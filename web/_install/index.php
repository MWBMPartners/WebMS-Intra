<?php
// Path: install/index.php
/**
 * -----------------------------------------------------------------------------
 * WebMS Intra — Installation Wizard
 * -----------------------------------------------------------------------------
 * Self-contained installation wizard for first-time portal setup. Guides the
 * administrator through database configuration, schema creation, initial user
 * setup, and encryption key generation.
 *
 * This file operates independently of the bootstrap/core framework since those
 * require a working database connection to function.
 *
 * Steps:
 *   1. Welcome & prerequisites check (PHP version, extensions)
 *   2. Database configuration (host, user, pass, name, port)
 *   3. Schema installation (tables + seed data from full_schema.sql)
 *   4. Admin user creation (first portal administrator)
 *   5. Encryption key generation
 *   6. Complete — redirect to login
 *
 * @package   Portal\Install
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/84
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Constants for this self-contained installer
// ---------------------------------------------------------------------------
define('INSTALL_ROOT', realpath(__DIR__ . DIRECTORY_SEPARATOR . '..'));
define('INSTALL_SQL', INSTALL_ROOT . DIRECTORY_SEPARATOR . '_sql');
define('INSTALL_AUTH_DIR', INSTALL_ROOT . DIRECTORY_SEPARATOR . '_auth_keys');
define('INSTALL_CREDS_FILE', INSTALL_AUTH_DIR . DIRECTORY_SEPARATOR . 'auth_creds.php');
define('INSTALL_ENC_KEY_FILE', INSTALL_AUTH_DIR . DIRECTORY_SEPARATOR . 'enc.key');
define('INSTALL_LOCK_FILE', INSTALL_AUTH_DIR . DIRECTORY_SEPARATOR . '.installed');
// 📌 Single source of truth — same file App.php and bootstrap.php read.
// Even though this installer is bootstrap-free, _core/version.php is a
// plain `return <string>` and safe to `require` independently.
define('INSTALL_VERSION', (string) (require INSTALL_ROOT . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'version.php'));

// 🏷️ Brand presets (issue #296) — loaded from _core/brand-defaults.php.
// Same bootstrap-free contract: plain `return <array>` so we can require
// it without the autoloader. We resolve the active preset from the session
// (set by the new Step 1.5 "organisation type" pick) and fall back to the
// generic preset for any earlier step where the user hasn't chosen yet.
$INSTALL_BRAND_PRESETS = (array) (require INSTALL_ROOT . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'brand-defaults.php');

// ---------------------------------------------------------------------------
// Block re-installation if lock file exists
// ---------------------------------------------------------------------------
if (is_file(INSTALL_LOCK_FILE) === true || is_file(INSTALL_CREDS_FILE) === true) {
    http_response_code(403);
    // 🎨 Themed lockout page — picks up the OS prefers-color-scheme and
    // uses the same indigo palette as the rest of the installer / portal,
    // so links / buttons are readable in both light and dark modes
    // without relying on Bootstrap or browser defaults.
    echo '<!doctype html><html><head><title>Already Installed</title>'
       . '<meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<style>'
       . ':root{'
       .   '--bg:#f7f8fa;--surface:#ffffff;--text:#1b2330;--muted:#6b7280;'
       .   '--border:#e5e7eb;--primary:#5e6ad2;--primary-hover:#4f5bbf;'
       .   '--code-bg:#eef0fb;--code-text:#1b2330;'
       . '}'
       . '@media (prefers-color-scheme: dark){:root{'
       .   '--bg:#0f1115;--surface:#161a22;--text:#e8eaf0;--muted:#9aa3b2;'
       .   '--border:#2c3441;--primary:#7b86e8;--primary-hover:#8f99eb;'
       .   '--code-bg:#1f2347;--code-text:#e8eaf0;'
       . '}}'
       . 'html,body{height:100%;}'
       . 'body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;'
       .   'background:var(--bg);color:var(--text);text-align:center;'
       .   'padding:4rem 1.25rem;margin:0;line-height:1.55;}'
       . '.card{max-width:560px;margin:0 auto;background:var(--surface);'
       .   'border:1px solid var(--border);border-radius:.75rem;padding:2.5rem 2rem;'
       .   'box-shadow:0 4px 6px -1px rgba(16,24,40,.08),0 2px 4px -2px rgba(16,24,40,.06);}'
       . 'h1{margin:0 0 1rem;font-weight:600;letter-spacing:-.01em;}'
       . 'p{margin:0 0 1rem;color:var(--text);}'
       . 'code{background:var(--code-bg);color:var(--code-text);'
       .   'padding:.125rem .4rem;border-radius:.375rem;font-size:.9em;'
       .   'font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;}'
       . '.btn{display:inline-block;padding:.625rem 1.25rem;background:var(--primary);'
       .   'color:#fff;text-decoration:none;border-radius:.375rem;margin-top:1rem;'
       .   'font-weight:500;transition:background 120ms ease;}'
       . '.btn:hover,.btn:focus{background:var(--primary-hover);color:#fff;}'
       . '</style></head>'
       . '<body><div class="card"><h1>Already Installed</h1>'
       . '<p>The portal has already been installed. To re-run the installer, '
       . 'remove both the lock file and credentials file from the <code>_auth_keys/</code> directory.</p>'
       . '<a class="btn" href="/">Go to Portal</a></div></body></html>';
    exit();
}

// ---------------------------------------------------------------------------
// Session for wizard state
// ---------------------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// ---------------------------------------------------------------------------
// Determine current step
// ---------------------------------------------------------------------------
// 🪞 Step is normally 1-6, with two fractional intermediate pages encoded as
//    string steps so they remain unambiguous in URLs without changing the
//    integer comparisons used throughout the file:
//      • '1.5' — organisation type (brand) picker (issue #296)
//      • '2.5' — existing-data choice (continue / drop)
//    Tracked via separate booleans so the integer $step still flows linearly.
$rawStep = (string) ($_GET['step'] ?? ($_POST['step'] ?? '1'));
$isIndustryStep = ($rawStep === '1.5');
$isChoiceStep   = ($rawStep === '2.5');
$step = $isChoiceStep ? 2 : ($isIndustryStep ? 1 : (int) $rawStep);
if ($step < 1 || $step > 6) {
    $step = 1;
    $isChoiceStep = false;
    $isIndustryStep = false;
}

// Enforce step progression — cannot skip ahead without completing prior steps
if (($step >= 3 || $isChoiceStep) && isset($_SESSION['install_db']) === false) {
    $step = 2; // DB credentials not yet configured
    $isChoiceStep = false;
}
if ($step === 6 && is_file(INSTALL_LOCK_FILE) === false) {
    $step = 5; // Finalization not yet completed
}

// 🏷️ Resolve the active brand preset from the session pick (Step 1.5).
//    Defaults to the generic preset for steps that haven't picked yet.
//    All hardcoded "WebMS Intra" references in the installer use these
//    variables, so the wizard rebrands the moment the admin chooses.
$selectedIndustry = (string) ($_SESSION['install_industry'] ?? '');
$INSTALL_BRAND = $INSTALL_BRAND_PRESETS[$selectedIndustry]
              ?? $INSTALL_BRAND_PRESETS['']
              ?? ['name' => 'WebMS Intra', 'tagline' => 'Internal Management System', 'publisher' => 'MWBM Partners Ltd (t/a MWservices)'];
$INSTALL_PRODUCT_NAME      = (string) ($INSTALL_BRAND['name']      ?? 'WebMS Intra');
$INSTALL_PRODUCT_TAGLINE   = (string) ($INSTALL_BRAND['tagline']   ?? 'Internal Management System');
$INSTALL_PRODUCT_PUBLISHER = (string) ($INSTALL_BRAND['publisher'] ?? 'MWBM Partners Ltd (t/a MWservices)');

// 🎨 Asset folder slug — points at web/public_html/assets/images/brandkit/assets/<slug>/.
//    Drives the favicon + apple-touch-icon in the installer head. The default
//    (webms-intra) ships first; once Step 1.5 saves the picked industry into
//    $_SESSION['install_industry'], the next render of the wizard automatically
//    swaps in that brand's icons (ChurchMS → churchms/icon.svg, etc.).
//    Stub presets (school/charity/community/business) still reference
//    webms-intra/ in brand-defaults.php until distinct artwork ships.
$INSTALL_BRAND_ASSET_FOLDER = (string) ($INSTALL_BRAND['assetFolder'] ?? 'webms-intra');
// Light defensive cleanup — only allow lowercase alphanumerics + dashes in the
// path segment, even though brand-defaults.php is trusted source. Avoids any
// chance of a malformed preset injecting /../ into the favicon URL.
if (preg_match('/^[a-z0-9\-]{1,40}$/', $INSTALL_BRAND_ASSET_FOLDER) !== 1) {
    $INSTALL_BRAND_ASSET_FOLDER = 'webms-intra';
}

// ---------------------------------------------------------------------------
// Handle POST submissions
// ---------------------------------------------------------------------------
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Step 2: Test and save database credentials
    if ($action === 'db_config') {
        $dbHost = trim($_POST['db_host'] ?? '');
        $dbUser = trim($_POST['db_user'] ?? '');
        $dbPass = $_POST['db_pass'] ?? '';
        $dbName = trim($_POST['db_name'] ?? '');
        $dbPort = (int) ($_POST['db_port'] ?? 3306);

        if ($dbHost === '' || $dbUser === '' || $dbName === '') {
            $error = 'Database host, username, and database name are required.';
            $step = 2;
        } else {
            // Test connection without database first
            mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
            try {
                $testConn = new mysqli($dbHost, $dbUser, $dbPass, '', $dbPort);
                $testConn->set_charset('utf8mb4');

                // Try to select the database
                $dbExists = $testConn->select_db($dbName);

                if ($dbExists === false) {
                    // Try to create the database
                    $createResult = $testConn->query(
                        'CREATE DATABASE `' . $testConn->real_escape_string($dbName) . '` '
                        . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
                    );
                    if ($createResult === false) {
                        $error = 'Database "' . $dbName
                               . '" does not exist and could not be created. '
                               . 'On shared hosting, you may need to create the database '
                               . 'manually via your hosting control panel (e.g. cPanel, DreamHost Panel) '
                               . 'before proceeding.';
                        $step = 2;
                    } else {
                        $testConn->select_db($dbName);
                    }
                }

                if ($error === '') {
                    // Save credentials to session for later steps
                    $_SESSION['install_db'] = [
                        'host' => $dbHost,
                        'user' => $dbUser,
                        'pass' => $dbPass,
                        'name' => $dbName,
                        'port' => $dbPort,
                    ];

                    // 🔍 Probe DB state so we can route the user appropriately.
                    //    See _install/db_state.php for the classification rules.
                    require_once INSTALL_ROOT
                        . DIRECTORY_SEPARATOR . '_install'
                        . DIRECTORY_SEPARATOR . 'db_state.php';
                    $policy = (array) (require INSTALL_ROOT
                        . DIRECTORY_SEPARATOR . '_install'
                        . DIRECTORY_SEPARATOR . 'upgrade-policy.php');
                    $state = detectDbState(
                        $testConn,
                        INSTALL_VERSION,
                        $policy,
                        is_file(INSTALL_LOCK_FILE)
                    );
                    $_SESSION['install_db_state'] = $state;
                    $testConn->close();

                    if ($state['state'] === DB_STATE_EMPTY) {
                        // Fresh install — straight to schema install.
                        $_SESSION['install_action'] = 'fresh';
                        header('Location: ?step=3');
                        exit();
                    }
                    if ($state['state'] === DB_STATE_INSTALLED_CURRENT) {
                        // Lock file + tables already at code version. The
                        // top-of-file lockout would have caught the lock
                        // file; this path means the lock was deleted but
                        // the DB is already current. Re-write the lock
                        // and redirect to portal.
                        @touch(INSTALL_LOCK_FILE);
                        header('Location: /');
                        exit();
                    }
                    // PARTIAL, INSTALLED_UPGRADE, or FRESH_REQUIRED →
                    // render the step-2.5 choice page.
                    header('Location: ?step=2.5');
                    exit();
                }

                $testConn->close();
            } catch (\mysqli_sql_exception $e) {
                $error = 'Database connection failed: ' . $e->getMessage();
                $step = 2;
            }
        }
    }

    // Step 2.5: Existing-data choice (Continue vs Drop)
    if ($action === 'install_action') {
        $choice = (string) ($_POST['install_action_choice'] ?? '');
        $state = $_SESSION['install_db_state'] ?? null;
        $allowContinue = is_array($state)
            && ($state['state'] ?? '') !== DB_STATE_FRESH_REQUIRED;

        if ($choice !== 'continue' && $choice !== 'drop') {
            $error = 'Please choose how to proceed.';
            $isChoiceStep = true;
        } elseif ($choice === 'continue' && $allowContinue === false) {
            $error = 'Continue is not available — this installation predates the '
                   . 'force-fresh threshold. You must drop and rebuild.';
            $isChoiceStep = true;
        } elseif ($choice === 'drop') {
            // 🛡️ Destructive — require the user to confirm by typing
            //    the configured hostname (or 'DROP' if no hostname is
            //    enforced by policy).
            $policy = (array) (require INSTALL_ROOT
                . DIRECTORY_SEPARATOR . '_install'
                . DIRECTORY_SEPARATOR . 'upgrade-policy.php');
            $needHost = (bool) ($policy['require_hostname_confirmation_for_drop'] ?? true);
            $expected = $needHost === true
                ? ($_SERVER['HTTP_HOST'] ?? 'DROP')
                : 'DROP';
            $typed = (string) ($_POST['drop_confirm'] ?? '');
            if (trim($typed) !== trim($expected)) {
                $error = 'Confirmation text didn\'t match. Type exactly: '
                       . htmlspecialchars($expected, ENT_QUOTES, 'UTF-8');
                $isChoiceStep = true;
            } else {
                $_SESSION['install_action'] = 'drop';
                header('Location: ?step=3');
                exit();
            }
        } else {
            $_SESSION['install_action'] = 'continue';
            header('Location: ?step=3');
            exit();
        }
    }

    // Step 1.5: Save organisation type (industry preset pick).
    //    Stores the choice in session only — actual tblSettings seeding
    //    happens in Step 3 after full_schema.sql runs.
    if ($action === 'select_industry') {
        $picked = (string) ($_POST['industry'] ?? '');
        // Defensive: only accept keys that exist in the brand presets file.
        if (isset($INSTALL_BRAND_PRESETS[$picked]) === false) {
            $picked = '';
        }
        $_SESSION['install_industry'] = $picked;
        header('Location: ?step=2');
        exit();
    }

    // Step 3: Install schema
    if ($action === 'install_schema') {
        $db = installGetDb();
        if ($db === null) {
            $error = 'Database connection lost. Please go back to Step 2.';
            $step = 2;
        } else {
            // 🪦 Drop-and-rebuild path. If the user explicitly opted for
            //    a fresh install from step 2.5, wipe every portal table
            //    before running full_schema.sql. We disable FK checks
            //    for the duration so dependent tables can drop in any
            //    order. This intentionally destroys data — the choice
            //    page has already confirmed the user's intent.
            if (($_SESSION['install_action'] ?? '') === 'drop') {
                try {
                    $db->query('SET FOREIGN_KEY_CHECKS = 0');
                    $rs = $db->query("SHOW TABLES LIKE 'tbl%'");
                    if ($rs !== false) {
                        while (($r = $rs->fetch_array(MYSQLI_NUM)) !== null) {
                            $t = (string) $r[0];
                            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $t) === 1) {
                                $db->query('DROP TABLE IF EXISTS `' . $t . '`');
                            }
                        }
                        $rs->free();
                    }
                    $db->query('SET FOREIGN_KEY_CHECKS = 1');
                } catch (\mysqli_sql_exception $e) {
                    $error = 'Drop-and-rebuild failed during table drop: '
                           . $e->getMessage();
                    $step = 3;
                }
                // 🪞 Step 5 will write portal.installed_version =
                //    INSTALL_VERSION; no extra state to clear here.
            }

            $schemaFile = INSTALL_SQL . DIRECTORY_SEPARATOR . 'full_schema.sql';
            if (is_readable($schemaFile) === false) {
                $error = 'Schema file not found: _sql/full_schema.sql';
                $step = 3;
            } else {
                $sql = file_get_contents($schemaFile);
                // 🛡️ Wrap multi_query + result-set walking in try/catch.
                // mysqli is in strict-exception mode (PHP 8.1+ default,
                // also re-asserted by installGetDb()), so ANY SQL error
                // in full_schema.sql — DDL conflict, version-specific
                // syntax, FK ordering issue, privilege denied, etc. —
                // would otherwise throw an uncaught mysqli_sql_exception
                // and fatal the request with a bare HTTP 500. Catching
                // here surfaces the real MySQL message to the user
                // instead. The $db->errno checks below remain in place
                // as belt-and-braces for the (now unreachable) silent-
                // failure mode on legacy PHP <8.1 hosts.
                try {
                    $db->multi_query($sql);

                    // Consume all result sets from multi_query
                    $queryError = '';
                    do {
                        $result = $db->store_result();
                        if ($result !== false) {
                            $result->free();
                        }
                        // 🪞 Keep the FIRST error — a later "Commands out
                        //    of sync" follow-up would otherwise overwrite
                        //    the useful root-cause message.
                        if ($db->errno !== 0 && $queryError === '') {
                            $queryError = $db->error;
                        }
                    } while ($db->more_results() === true && $db->next_result());

                    if ($queryError !== '') {
                        $error = 'Schema installation error: ' . $queryError;
                        $step = 3;
                    } else {
                        // 🪞 Apply all numbered migrations after full_schema.sql.
                        //
                        // Why this matters: full_schema.sql uses CREATE TABLE
                        // IF NOT EXISTS, which is a no-op when a table already
                        // exists. So if an earlier install attempt created
                        // tblLocalAccounts (or any other table) under an
                        // older shape — say without the `isVerified` column —
                        // and then the schema was updated, the existing
                        // table will NOT pick up the new column when the
                        // user retries the installer. Subsequent steps
                        // referencing that column fatal with
                        // "Unknown column 'isVerified' in 'field list'".
                        //
                        // Every numbered migration uses idempotent
                        // constructs (ADD COLUMN IF NOT EXISTS,
                        // INSERT ... ON DUPLICATE KEY UPDATE,
                        // DELETE FROM ... WHERE ...) — so on a true-fresh
                        // install they're harmless no-ops (the CREATE
                        // TABLE in full_schema.sql already contains every
                        // column the migrations would add), but on a
                        // stale DB they fill in the gaps and bring the
                        // schema up to date.
                        //
                        // We multi_query each migration file directly
                        // rather than going through Portal\Core\Migrator
                        // because (a) Migrator's `pending()` filter is
                        // tblMigrations-based, and full_schema.sql's seed
                        // block marks every migration as already-applied
                        // (so Migrator would see nothing pending on a
                        // stale DB), and (b) the installer is bootstrap-
                        // free — it can't autoload \Portal\Core\Migrator
                        // without pulling in the rest of the framework.
                        //
                        // See: https://github.com/MWBMPartners/WebMS-Intra/issues/218
                        $migrationFiles = glob(
                            INSTALL_SQL . DIRECTORY_SEPARATOR . '[0-9][0-9][0-9]_*.sql'
                        );
                        if ($migrationFiles === false) {
                            $migrationFiles = [];
                        }
                        sort($migrationFiles, SORT_STRING);
                        $migError = '';
                        foreach ($migrationFiles as $migFile) {
                            $migSql = file_get_contents($migFile);
                            if ($migSql === false || trim($migSql) === '') {
                                continue;
                            }
                            try {
                                $db->multi_query($migSql);
                                do {
                                    $r = $db->store_result();
                                    if ($r !== false) {
                                        $r->free();
                                    }
                                    if ($db->errno !== 0 && $migError === '') {
                                        $migError = basename($migFile)
                                            . ' — ' . $db->error;
                                    }
                                } while ($db->more_results() === true && $db->next_result());
                            } catch (\mysqli_sql_exception $e) {
                                $migError = basename($migFile)
                                    . ' — ' . $e->getMessage();
                                break;
                            }
                            if ($migError !== '') {
                                break;
                            }
                        }

                        if ($migError !== '') {
                            $error = 'Migration error: ' . $migError;
                            $step = 3;
                        } else {
                            // 🏷️ Seed the product brand preset chosen in Step 1.5
                            //    (issue #296). Writes the resolved name / tagline
                            //    / publisher plus portal.industry into tblSettings.
                            //    For the default (generic) pick we still upsert so
                            //    the rows exist with sensible values for /admin/settings.
                            $brandError = '';
                            try {
                                $brandSql = 'INSERT INTO `tblSettings` '
                                          . '(`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) '
                                          . 'VALUES (NULL, ?, ?, ?, 0) '
                                          . 'ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`), '
                                          . '`defaultValue` = VALUES(`defaultValue`)';
                                $brandStmt = $db->prepare($brandSql);
                                if ($brandStmt !== false) {
                                    foreach ([
                                        'portal.industry'   => $selectedIndustry,
                                        'product.name'      => $INSTALL_PRODUCT_NAME,
                                        'product.tagline'   => $INSTALL_PRODUCT_TAGLINE,
                                        'product.publisher' => $INSTALL_PRODUCT_PUBLISHER,
                                    ] as $key => $value) {
                                        $strValue = (string) $value;
                                        $brandStmt->bind_param('sss', $key, $strValue, $strValue);
                                        $brandStmt->execute();
                                    }
                                    $brandStmt->close();
                                }
                            } catch (\mysqli_sql_exception $e) {
                                // Non-fatal — the rows already have safe defaults
                                // from migration 108 / 073. Log a soft warning so
                                // the admin can re-pick via /admin/settings.
                                $brandError = $e->getMessage();
                                error_log('[WebMS-Intra] Installer brand seed: ' . $brandError);
                            }

                            $db->close();
                            header('Location: ?step=4');
                            exit();
                        }
                    }
                } catch (\mysqli_sql_exception $e) {
                    $error = 'Schema installation error: ' . $e->getMessage();
                    $step = 3;
                }
            }
            $db->close();
        }
    }

    // Step 4: Create admin user
    if ($action === 'create_admin') {
        $adminName  = trim($_POST['admin_name'] ?? '');
        $adminEmail = trim($_POST['admin_email'] ?? '');
        $adminPass  = $_POST['admin_pass'] ?? '';
        $adminPass2 = $_POST['admin_pass2'] ?? '';

        // 🛡️ Self-contained password policy — mirrors Auth::validatePassword() defaults
        // (the installer runs before bootstrap.php is available).
        $pwErrors = [];
        if (strlen($adminPass) < 12) {
            $pwErrors[] = 'Password must be at least 12 characters.';
        }
        if (strlen($adminPass) > 128) {
            $pwErrors[] = 'Password must be no more than 128 characters.';
        }
        if (preg_match('/[A-Z]/', $adminPass) !== 1) {
            $pwErrors[] = 'Password must contain at least one uppercase letter.';
        }
        if (preg_match('/[a-z]/', $adminPass) !== 1) {
            $pwErrors[] = 'Password must contain at least one lowercase letter.';
        }
        if (preg_match('/[0-9]/', $adminPass) !== 1) {
            $pwErrors[] = 'Password must contain at least one number.';
        }
        if (preg_match('/[^a-zA-Z0-9]/', $adminPass) !== 1) {
            $pwErrors[] = 'Password must contain at least one special character.';
        }

        if ($adminName === '' || $adminEmail === '' || $adminPass === '') {
            $error = 'All fields are required.';
            $step = 4;
        } elseif (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            $error = 'Please enter a valid email address.';
            $step = 4;
        } elseif (count($pwErrors) > 0) {
            $error = 'Password does not meet policy: ' . implode(' ', $pwErrors);
            $step = 4;
        } elseif ($adminPass !== $adminPass2) {
            $error = 'Passwords do not match.';
            $step = 4;
        } else {
            $db = installGetDb();
            if ($db === null) {
                $error = 'Database connection lost. Please go back to Step 2.';
                $step = 2;
            } else {
                // 🛡️ Wrap the whole admin-create flow in try/catch — see
                // step 3 above for the rationale. A duplicate-email INSERT,
                // an FK violation, or any other constraint failure would
                // otherwise throw an uncaught mysqli_sql_exception and
                // fatal the request. The `$stmt === false` and
                // `if ($stmtLocal !== false)` guards below are kept as
                // belt-and-braces — under strict mode they're effectively
                // dead code, but they keep the handler safe if a future
                // change toggles mysqli_report back to silent.
                try {
                    // Insert admin user. Note: tblUsers has NO siteID column —
                    // multi-site assignment is via tblUserSites (inserted
                    // below). An earlier version of this handler included
                    // `siteID = 1` directly on tblUsers, which fatalled the
                    // installer step 4 with "Unknown column 'siteID'" on every
                    // fresh install. See issue #200.
                    $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                    $stmt = $db->prepare(
                        'INSERT INTO tblUsers (fullName, emailAddress, isAdmin, isRootAdmin, isActive, createdAt) '
                        . 'VALUES (?, ?, 1, 1, 1, NOW())'
                    );
                    if ($stmt === false) {
                        $error = 'Failed to prepare user insert: ' . $db->error;
                        $step = 4;
                    } else {
                        $stmt->bind_param('ss', $adminName, $adminEmail);
                        $stmt->execute();
                        $newUserId = $stmt->insert_id;
                        $stmt->close();

                        // Create local auth account
                        $stmtLocal = $db->prepare(
                            'INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified, createdAt) '
                            . 'VALUES (?, ?, ?, 1, NOW())'
                        );
                        if ($stmtLocal !== false) {
                            $stmtLocal->bind_param('iss', $newUserId, $adminEmail, $hash);
                            $stmtLocal->execute();
                            $stmtLocal->close();
                        }

                        // Assign to default site
                        $stmtSite = $db->prepare(
                            'INSERT INTO tblUserSites (userID, siteID, isSiteAdmin, isSiteRootAdmin, isActive) '
                            . 'VALUES (?, 1, 1, 1, 1)'
                        );
                        if ($stmtSite !== false) {
                            $stmtSite->bind_param('i', $newUserId);
                            $stmtSite->execute();
                            $stmtSite->close();
                        }

                        // Update default site settings with the admin's email
                        $stmtSetting = $db->prepare(
                            'UPDATE tblSettings SET settingValue = ? WHERE settingKey = \'site.adminEmail\' AND siteID IS NULL'
                        );
                        if ($stmtSetting !== false) {
                            $stmtSetting->bind_param('s', $adminEmail);
                            $stmtSetting->execute();
                            $stmtSetting->close();
                        }

                        $db->close();
                        header('Location: ?step=5');
                        exit();
                    }
                } catch (\mysqli_sql_exception $e) {
                    $error = 'Failed to create administrator: ' . $e->getMessage();
                    $step = 4;
                }
                $db->close();
            }
        }
    }

    // Step 5: Generate encryption key and save config
    if ($action === 'finalize') {
        // Create _auth_keys directory if needed
        if (is_dir(INSTALL_AUTH_DIR) === false) {
            if (mkdir(INSTALL_AUTH_DIR, 0750, true) === false) {
                $error = 'Failed to create _auth_keys directory. Please create it manually '
                       . 'and ensure the web server can write to it.';
                $step = 5;
            }
        }

        if ($error === '') {
            $dbCreds = $_SESSION['install_db'] ?? null;
            if ($dbCreds === null) {
                $error = 'Database credentials lost from session. Please restart installation.';
                $step = 2;
            }
        }

        if ($error === '') {
            // Write auth_creds.php
            $credsContent = "<?php\n"
                          . "// Database credentials — auto-generated by installer\n"
                          . "// DO NOT commit this file to version control.\n"
                          . "return [\n"
                          . "    'db_host' => " . var_export($dbCreds['host'], true) . ",\n"
                          . "    'db_user' => " . var_export($dbCreds['user'], true) . ",\n"
                          . "    'db_pass' => " . var_export($dbCreds['pass'], true) . ",\n"
                          . "    'db_name' => " . var_export($dbCreds['name'], true) . ",\n"
                          . "    'db_port' => " . var_export($dbCreds['port'], true) . ",\n"
                          . "];\n";

            if (file_put_contents(INSTALL_CREDS_FILE, $credsContent) === false) {
                $error = 'Failed to write credentials file. Please check directory permissions for _auth_keys/.';
                $step = 5;
            }
        }

        if ($error === '') {
            // Generate encryption key (32 random bytes, hex-encoded)
            $encKey = bin2hex(random_bytes(32));
            if (file_put_contents(INSTALL_ENC_KEY_FILE, $encKey) === false) {
                $error = 'Failed to write encryption key file.';
                $step = 5;
            }
        }

        if ($error === '') {
            // 🆔 Record the version this DB is now at, AND clear any
            //    maintenance flag the upgrade flow might have left
            //    behind. We reconnect with the credentials just written
            //    rather than reuse the test connection (which has been
            //    closed several steps ago).
            $writeDb = installGetDb();
            if ($writeDb !== null) {
                try {
                    $stmt = $writeDb->prepare(
                        "INSERT INTO `tblSettings` "
                        . "(`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) "
                        . "VALUES (NULL, 'portal.installed_version', ?, '', 0) "
                        . "ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`)"
                    );
                    if ($stmt !== false) {
                        $stmt->bind_param('s', $v);
                        $v = INSTALL_VERSION;
                        $stmt->execute();
                        $stmt->close();
                    }
                    // 🚧 Clear maintenance flag in case a drop-and-rebuild
                    //    or interrupted upgrade left it on.
                    $writeDb->query(
                        "UPDATE `tblSettings` "
                        . "SET `settingValue` = '0' "
                        . "WHERE `settingKey` = 'portal.maintenance.active'"
                    );
                } catch (\mysqli_sql_exception $e) {
                    // 🛡️ Non-fatal — the install completes either way;
                    //    the maintenance gate just falls back to the
                    //    "legacy install — assume current" default.
                }
                $writeDb->close();
            }

            // Create lock file to prevent re-installation
            file_put_contents(INSTALL_LOCK_FILE, date('c') . "\n" . 'Installed by ' . $INSTALL_PRODUCT_NAME . ' installer v' . INSTALL_VERSION);

            // Set restrictive permissions
            chmod(INSTALL_CREDS_FILE, 0640);
            chmod(INSTALL_ENC_KEY_FILE, 0640);
            chmod(INSTALL_LOCK_FILE, 0640);

            // Clear session install data
            unset($_SESSION['install_db']);
            unset($_SESSION['install_action']);
            unset($_SESSION['install_db_state']);

            header('Location: ?step=6');
            exit();
        }
    }
}

/**
 * Helper: get a database connection from session credentials.
 *
 * @return mysqli|null
 */
function installGetDb(): ?mysqli
{
    $creds = $_SESSION['install_db'] ?? null;
    if ($creds === null) {
        return null;
    }

    mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
    try {
        $db = new mysqli(
            $creds['host'],
            $creds['user'],
            $creds['pass'],
            $creds['name'],
            $creds['port']
        );
        $db->set_charset('utf8mb4');
        return $db;
    } catch (\mysqli_sql_exception) {
        return null;
    }
}

// ---------------------------------------------------------------------------
// Prerequisites check (used in step 1)
// ---------------------------------------------------------------------------
$prereqs = [
    'php_version' => [
        'label' => 'PHP 8.4+',
        'pass'  => version_compare(PHP_VERSION, '8.4.0', '>='),
        'value' => PHP_VERSION,
    ],
    'mysqli' => [
        'label' => 'MySQLi extension',
        'pass'  => extension_loaded('mysqli'),
        'value' => extension_loaded('mysqli') ? 'Loaded' : 'Missing',
    ],
    'sodium' => [
        'label' => 'Sodium extension (encryption)',
        'pass'  => extension_loaded('sodium'),
        'value' => extension_loaded('sodium') ? 'Loaded' : 'Missing',
    ],
    'json' => [
        'label' => 'JSON extension',
        'pass'  => extension_loaded('json'),
        'value' => extension_loaded('json') ? 'Loaded' : 'Missing',
    ],
    'mbstring' => [
        'label' => 'Multibyte String extension',
        'pass'  => extension_loaded('mbstring'),
        'value' => extension_loaded('mbstring') ? 'Loaded' : 'Missing',
    ],
    'sql_dir' => [
        'label' => '_sql/ directory readable',
        'pass'  => is_dir(INSTALL_SQL) && is_readable(INSTALL_SQL),
        'value' => is_dir(INSTALL_SQL) ? 'Found' : 'Missing',
    ],
    'schema_file' => [
        'label' => 'full_schema.sql exists',
        'pass'  => is_readable(INSTALL_SQL . DIRECTORY_SEPARATOR . 'full_schema.sql'),
        'value' => is_readable(INSTALL_SQL . DIRECTORY_SEPARATOR . 'full_schema.sql') ? 'Found' : 'Missing',
    ],
    'auth_dir_writable' => [
        'label' => '_auth_keys/ writable (or creatable)',
        'pass'  => (is_dir(INSTALL_AUTH_DIR) && is_writable(INSTALL_AUTH_DIR))
                   || is_writable(INSTALL_ROOT),
        'value' => is_dir(INSTALL_AUTH_DIR)
                   ? (is_writable(INSTALL_AUTH_DIR) ? 'Writable' : 'Not writable')
                   : (is_writable(INSTALL_ROOT) ? 'Can create' : 'Not writable'),
    ],
];
$allPrereqsPassed = true;
foreach ($prereqs as $p) {
    if ($p['pass'] === false) {
        $allPrereqsPassed = false;
        break;
    }
}

// ---------------------------------------------------------------------------
// Render the page
// ---------------------------------------------------------------------------
$stepTitles = [
    1 => 'Welcome',
    2 => 'Database Configuration',
    3 => 'Install Schema',
    4 => 'Create Admin User',
    5 => 'Finalize Setup',
    6 => 'Installation Complete',
];
$pageTitle = 'Install — ' . ($stepTitles[$step] ?? $INSTALL_PRODUCT_NAME);

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <?php
        // 🎨 Brand-aware favicons. Default is WebMS Intra; once the admin picks
        // "Church" (or any other industry) at Step 1.5, the session-backed
        // $INSTALL_BRAND_ASSET_FOLDER swaps in that brand's icon folder on the
        // very next render — no JS needed, the form POST → redirect cycle
        // re-resolves brand on the way back to the next step.
        //
        // 📐 Format fallback order matters: browsers pick the FIRST format
        // they understand. Order is therefore:
        //   1. image/svg+xml  → Chrome 80+, Firefox 41+, Safari 9+, Edge.
        //   2. image/png      → everything else with multi-size hinting.
        //   3. apple-touch-icon (PNG) → iOS/iPadOS home-screen.
        //   4. alternate icon (favicon.ico) → legacy IE, very old Safari.
        // SVG sources are vector; PNG variants are rendered from the same
        // SVG at build time via rsvg-convert and committed to the repo so
        // no runtime image processing is needed on DreamHost shared.
        $faviconBase = '/assets/images/brandkit/assets/' . htmlspecialchars($INSTALL_BRAND_ASSET_FOLDER, ENT_QUOTES, 'UTF-8');
    ?>
    <link rel="icon"             type="image/svg+xml"            href="<?php echo $faviconBase; ?>/icon.svg">
    <link rel="icon"             type="image/png" sizes="32x32"  href="<?php echo $faviconBase; ?>/icon-32.png">
    <link rel="icon"             type="image/png" sizes="16x16"  href="<?php echo $faviconBase; ?>/icon-16.png">
    <link rel="apple-touch-icon" sizes="192x192"                 href="<?php echo $faviconBase; ?>/icon-192.png">
    <link rel="apple-touch-icon" sizes="512x512"                 href="<?php echo $faviconBase; ?>/icon-512.png">
    <link rel="alternate icon"   type="image/x-icon"             href="<?php echo $faviconBase; ?>/favicon.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YcnS/NPqOGZ2eLNphkfv02LPMoJiDFhNSz7K" crossorigin="anonymous">
    <style>
        /* =====================================================================
         * Installer styles — comprehensive across all 6 step layouts.
         * Tokens mirror web/public_html/assets/css/portal.css (kept in sync
         * manually since the installer runs standalone, before bootstrap.php
         * loads the portal). Kept here inline so the installer has zero
         * external CSS dependencies beyond Bootstrap.
         * =================================================================*/

        :root {
            --portal-primary:        #5e6ad2;
            --portal-primary-rgb:    94, 106, 210;
            --portal-primary-hover:  #4f5bbf;
            --portal-primary-subtle: #eef0fb;
            --portal-success:        #16a34a;
            --portal-warning:        #d97706;
            --portal-danger:         #dc2626;
            --portal-bg:             #f7f8fa;
            --portal-surface:        #ffffff;
            --portal-border:         #e5e7eb;
            --portal-border-strong:  #d1d5db;
            --portal-text:           #1b2330;
            --portal-text-muted:     #6b7280;
            --portal-text-subtle:    #9ca3af;
            --portal-radius-sm:      0.375rem;
            --portal-radius-md:      0.5rem;
            --portal-radius-lg:      0.75rem;
            --portal-shadow-md:      0 4px 6px -1px rgba(16,24,40,0.08),
                                     0 2px 4px -2px rgba(16,24,40,0.06);
            --portal-shadow-lg:      0 12px 20px -8px rgba(16,24,40,0.12),
                                     0 4px 8px -4px rgba(16,24,40,0.08);
            --portal-easing:         cubic-bezier(0.4,0,0.2,1);

            /* 🔗 Bind Bootstrap link variables to the indigo brand colour so
             * anchors don't fall back to the browser-default blue (which
             * clashes hard in dark mode — see issue / screenshot). */
            --bs-link-color:           var(--portal-primary);
            --bs-link-color-rgb:       var(--portal-primary-rgb);
            --bs-link-hover-color:     var(--portal-primary-hover);
            --bs-link-hover-color-rgb: var(--portal-primary-rgb);
        }

        /* =====================================================================
         * Dark mode — mirrors portal.css's [data-bs-theme="dark"] block
         * (applied to <html> by the FOUC script below).
         * =================================================================*/
        [data-bs-theme="dark"] {
            --portal-primary:        #7b86e8;
            --portal-primary-rgb:    123, 134, 232;
            --portal-primary-hover:  #8f99eb;
            --portal-primary-subtle: #1f2347;
            --portal-success:        #4ade80;
            --portal-warning:        #fbbf24;
            --portal-danger:         #f87171;
            --portal-bg:             #0f1115;
            --portal-surface:        #161a22;
            --portal-border:         #2c3441;
            --portal-border-strong:  #3a4252;
            --portal-text:           #e8eaf0;
            --portal-text-muted:     #9aa3b2;
            --portal-text-subtle:    #6b7280;
            --portal-shadow-md:      0 4px 6px -1px rgba(0,0,0,0.4),
                                     0 2px 4px -2px rgba(0,0,0,0.3);
            --portal-shadow-lg:      0 12px 20px -8px rgba(0,0,0,0.5),
                                     0 4px 8px -4px rgba(0,0,0,0.4);

            /* 🔗 Brighter link colour for dark surfaces, matched to portal.css. */
            --bs-link-color:           #9aa5f0;
            --bs-link-color-rgb:       154, 165, 240;
            --bs-link-hover-color:     #b3bcf6;
            --bs-link-hover-color-rgb: 179, 188, 246;
        }

        /* =====================================================================
         * Colour-blind safe palette — opt-in via [data-portal-cb="on"].
         * Mirrors portal.css; Wong-based palette distinguishable for
         * deutan + protan colour blindness (~95% of CB cases).
         * =================================================================*/
        [data-portal-cb="on"] {
            --portal-success: #009e73;
            --portal-danger:  #d55e00;
            --portal-warning: #e69f00;
        }
        [data-portal-cb="on"][data-bs-theme="dark"] {
            --portal-success: #5dd1a8;
            --portal-danger:  #ff8a4d;
            --portal-warning: #ffc04d;
        }

        /* =====================================================================
         * Theme / accessibility toggle buttons in the installer header
         * =================================================================*/
        .install-toggles {
            position: absolute;
            top: 1rem;
            right: 1rem;
            display: flex;
            gap: 0.375rem;
        }
        .install-toggle {
            background: var(--portal-surface);
            border: 1px solid var(--portal-border);
            color: var(--portal-text-muted);
            border-radius: var(--portal-radius-md);
            width: 2.25rem;
            height: 2.25rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1rem;
            transition: background 120ms var(--portal-easing),
                        color      120ms var(--portal-easing),
                        border-color 120ms var(--portal-easing);
        }
        .install-toggle:hover {
            background: var(--portal-bg);
            color: var(--portal-text);
            border-color: var(--portal-border-strong);
        }
        .install-toggle[aria-pressed="true"] {
            color: var(--portal-primary);
            background: var(--portal-primary-subtle);
            border-color: var(--portal-primary);
        }

        /* =====================================================================
         * Page shell
         * =================================================================*/
        html, body { height: 100%; }
        body {
            background: var(--portal-bg);
            color: var(--portal-text);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto,
                         "Helvetica Neue", Arial, sans-serif;
            font-size: 0.9375rem;
            line-height: 1.5;
        }
        .install-shell {
            max-width: 720px;
            margin: 0 auto;
            padding: 2.5rem 1.25rem 3rem;
        }

        /* =====================================================================
         * Header (above the wizard card)
         * =================================================================*/
        .install-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .install-header h1 {
            color: var(--portal-text);
            letter-spacing: -0.01em;
            font-weight: 600;
            font-size: 1.875rem;
            margin: 0;
        }
        .install-header .install-tagline {
            color: var(--portal-text-muted);
            margin: 0.375rem 0 0;
            font-size: 0.9375rem;
        }

        /* =====================================================================
         * Step indicator row
         * =================================================================*/
        .step-row {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 2rem;
        }
        .step-badge {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.875rem;
            transition: background 200ms var(--portal-easing),
                        color      200ms var(--portal-easing),
                        box-shadow 200ms var(--portal-easing);
        }
        .step-active {
            background: var(--portal-primary);
            color: #fff;
            box-shadow: 0 0 0 4px rgba(var(--portal-primary-rgb), 0.18);
        }
        .step-done {
            background: var(--portal-success);
            color: #fff;
        }
        .step-pending {
            background: var(--portal-surface);
            color: var(--portal-text-muted);
            border: 1px solid var(--portal-border-strong);
        }

        /* =====================================================================
         * Wizard card
         * =================================================================*/
        .install-card {
            background: var(--portal-surface);
            border: 1px solid var(--portal-border);
            border-radius: var(--portal-radius-lg);
            box-shadow: var(--portal-shadow-md);
            overflow: hidden;
        }
        .install-card .card-body { padding: 2.5rem; }
        @media (max-width: 575.98px) {
            .install-card .card-body { padding: 1.5rem; }
            .install-shell { padding: 1.5rem 1rem 2rem; }
        }

        /* Step heading + lead paragraph */
        .install-card h2 {
            color: var(--portal-text);
            font-weight: 600;
            letter-spacing: -0.01em;
            font-size: 1.5rem;
            line-height: 1.3;
            margin: 0 0 0.5rem;
        }
        .install-card h2 + p {
            color: var(--portal-text);
            font-size: 0.9375rem;
            line-height: 1.55;
            margin: 0 0 0.75rem;
        }
        .install-card h2 + p + p.text-muted,
        .install-card h2 + p + p.small {
            color: var(--portal-text-muted) !important;
            font-size: 0.875rem;
            margin: 0 0 1.5rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--portal-border);
        }
        /* If h2 is followed by only ONE p (no "small" follow-up), still pad it */
        .install-card h2 + p:not(:has(+ p)) {
            margin-bottom: 1.5rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--portal-border);
        }
        .install-card h3 {
            color: var(--portal-text);
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin: 1.75rem 0 0.875rem;
        }

        /* =====================================================================
         * Forms (steps 2 and 4)
         * =================================================================*/
        .install-card .mb-3 { margin-bottom: 1.25rem !important; }
        .install-card .row  { --bs-gutter-x: 1rem; }

        .install-card .form-label {
            font-weight: 500;
            color: var(--portal-text);
            font-size: 0.875rem;
            margin-bottom: 0.375rem;
        }
        .install-card .form-control,
        .install-card .form-select {
            border: 1px solid var(--portal-border-strong);
            border-radius: var(--portal-radius-md);
            padding: 0.5625rem 0.75rem;
            font-size: 0.9375rem;
            line-height: 1.4;
            color: var(--portal-text);
            background-color: var(--portal-surface);
            transition: border-color 150ms var(--portal-easing),
                        box-shadow   150ms var(--portal-easing);
        }
        .install-card .form-control:hover:not(:focus),
        .install-card .form-select:hover:not(:focus) {
            border-color: var(--portal-text-subtle);
        }
        .install-card .form-control:focus,
        .install-card .form-select:focus {
            border-color: var(--portal-primary);
            box-shadow: 0 0 0 3px rgba(var(--portal-primary-rgb), 0.18);
            outline: none;
        }
        .install-card .form-control::placeholder { color: var(--portal-text-subtle); }
        .install-card .form-text {
            color: var(--portal-text-muted);
            font-size: 0.8125rem;
            margin-top: 0.375rem;
            line-height: 1.4;
        }
        .install-card .form-text code,
        .install-card p code,
        .install-card li code {
            background: var(--portal-bg);
            color: var(--portal-text);
            padding: 0.125rem 0.375rem;
            border-radius: var(--portal-radius-sm);
            font-size: 0.875em;
            font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Monaco, Consolas, monospace;
        }

        /* =====================================================================
         * Button row (step navigation: Back / Continue)
         * =================================================================*/
        .install-card .d-flex.justify-content-between {
            margin-top: 1.75rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--portal-border);
            align-items: center;
        }
        .install-card .btn {
            font-weight: 500;
            transition: background 120ms var(--portal-easing),
                        border-color 120ms var(--portal-easing),
                        color 120ms var(--portal-easing),
                        box-shadow 120ms var(--portal-easing);
        }
        .install-card .btn-primary {
            --bs-btn-bg:                  var(--portal-primary);
            --bs-btn-border-color:        var(--portal-primary);
            --bs-btn-hover-bg:            var(--portal-primary-hover);
            --bs-btn-hover-border-color:  var(--portal-primary-hover);
            --bs-btn-active-bg:           var(--portal-primary-hover);
            --bs-btn-active-border-color: var(--portal-primary-hover);
            padding: 0.5625rem 1.25rem;
            border-radius: var(--portal-radius-sm);
        }
        .install-card .btn-primary:hover {
            box-shadow: 0 2px 8px -2px rgba(var(--portal-primary-rgb), 0.4);
        }
        .install-card .btn-success {
            --bs-btn-bg:                  var(--portal-success);
            --bs-btn-border-color:        var(--portal-success);
            --bs-btn-hover-bg:            #15803d;
            --bs-btn-hover-border-color:  #15803d;
            --bs-btn-active-bg:           #166534;
            --bs-btn-active-border-color: #166534;
            padding: 0.5625rem 1.25rem;
            border-radius: var(--portal-radius-sm);
        }
        /* Back is a quiet secondary — text-link style */
        .install-card .btn-outline-secondary {
            --bs-btn-color:               var(--portal-text-muted);
            --bs-btn-bg:                  transparent;
            --bs-btn-border-color:        transparent;
            --bs-btn-hover-color:         var(--portal-text);
            --bs-btn-hover-bg:            var(--portal-bg);
            --bs-btn-hover-border-color:  transparent;
            --bs-btn-active-color:        var(--portal-text);
            --bs-btn-active-bg:           var(--portal-bg);
            --bs-btn-active-border-color: transparent;
            padding: 0.5rem 0.875rem;
            border-radius: var(--portal-radius-sm);
            font-size: 0.875rem;
        }
        .install-card .btn-lg {
            padding: 0.75rem 1.75rem;
            font-size: 1rem;
            border-radius: var(--portal-radius-sm);
        }

        /* =====================================================================
         * Prerequisites table (step 1) — kept readable but tighter
         * =================================================================*/
        .install-card .table {
            margin-bottom: 1.5rem;
            font-size: 0.9375rem;
        }
        .install-card .table thead th {
            color: var(--portal-text-muted);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border-bottom: 1px solid var(--portal-border);
            padding: 0.625rem 0.5rem;
            background: transparent;
        }
        .install-card .table td {
            padding: 0.6875rem 0.5rem;
            vertical-align: middle;
            border-color: var(--portal-border);
        }
        .prereq-pass { color: var(--portal-success); font-weight: 500; }
        .prereq-fail { color: var(--portal-danger);  font-weight: 500; }

        /* =====================================================================
         * 🔗 Defensive anchor styling — bind <a> directly to the brand link
         * colour. `--bs-link-color` is already pointed at the indigo palette
         * above, but if Bootstrap fails to load (CDN outage / SRI mismatch)
         * Bootstrap's own `a { color: var(--bs-link-color); }` rule never
         * runs and anchors fall back to the browser default (underlined
         * blue, visited purple — particularly ugly in dark mode). This
         * rule guarantees readability even with zero external CSS.
         * =================================================================*/
        a {
            color: var(--bs-link-color);
            text-decoration: underline;
            text-underline-offset: 0.15em;
        }
        a:hover,
        a:focus {
            color: var(--bs-link-hover-color);
        }
        a:visited {
            color: var(--bs-link-color);
        }
        /* Buttons-as-links keep their button look, never link-blue */
        a.btn,
        a.btn:hover,
        a.btn:focus,
        a.btn:visited {
            text-decoration: none;
            color: var(--bs-btn-color, inherit);
        }

        /* =====================================================================
         * Alerts (steps 3 / 5 / 6 use these heavily)
         * =================================================================*/
        .install-card .alert {
            border-radius: var(--portal-radius-md);
            border: 1px solid;
            padding: 1rem 1.125rem;
            font-size: 0.9375rem;
            line-height: 1.5;
            margin-bottom: 1.5rem;
        }
        .install-card .alert strong { font-weight: 600; }
        .install-card .alert ul,
        .install-card .alert ol {
            padding-left: 1.25rem;
            margin: 0.5rem 0 0;
        }
        .install-card .alert li { margin: 0.25rem 0; }
        /* Code chips inside alerts: tonal-on-tonal so they read clearly on
         * both the light-mode pastel and the dark-mode deep-shade backgrounds,
         * without the bright white pill that previously washed out the text. */
        .install-card .alert code {
            background:    rgba(0, 0, 0, 0.08);
            color:         currentColor;
            padding:       0.125rem 0.4rem;
            border-radius: var(--portal-radius-sm);
            font-size:     0.875em;
            font-family:   ui-monospace, SFMono-Regular, "SF Mono", Menlo,
                           Monaco, Consolas, monospace;
        }
        [data-bs-theme="dark"] .install-card .alert code {
            background: rgba(255, 255, 255, 0.12);
        }
        /* Links inside alerts — pull from currentColor so the underline
         * inherits the alert's own tonal foreground (dark-on-light or
         * light-on-dark) and is always readable against the tinted bg. */
        .install-card .alert a {
            color: currentColor;
            text-decoration: underline;
            font-weight: 600;
        }
        .install-card .alert a:hover,
        .install-card .alert a:focus {
            color: currentColor;
            opacity: 0.8;
        }

        /* Light-mode alert palettes — pastel backgrounds, deep tonal text */
        .install-card .alert-info {
            background:    #eef2ff;     /* indigo-tinted */
            border-color:  #c7d2fe;
            color:         #312e81;
        }
        .install-card .alert-warning {
            background:    #fffbeb;
            border-color:  #fde68a;
            color:         #92400e;
        }
        .install-card .alert-success {
            background:    #f0fdf4;
            border-color:  #bbf7d0;
            color:         #166534;
        }
        .install-card .alert-danger {
            background:    #fef2f2;
            border-color:  #fecaca;
            color:         #991b1b;
        }
        /* Dark-mode alert palettes — mirrors portal.css so the installer
         * stays consistent with the runtime portal. Deep-shade bg, light
         * tonal text — readable, no white "punch" in dark mode. */
        [data-bs-theme="dark"] .install-card .alert-info {
            background:    #1e1b4b;
            border-color:  #312e81;
            color:         #c7d2fe;
        }
        [data-bs-theme="dark"] .install-card .alert-warning {
            background:    #451a03;
            border-color:  #92400e;
            color:         #fde68a;
        }
        [data-bs-theme="dark"] .install-card .alert-success {
            background:    #052e16;
            border-color:  #166534;
            color:         #bbf7d0;
        }
        [data-bs-theme="dark"] .install-card .alert-danger {
            background:    #450a0a;
            border-color:  #991b1b;
            color:         #fecaca;
        }
        /* Top-of-card error/success messages (different markup: alert + install-card class) */
        .alert.install-card { box-shadow: none; margin-bottom: 1.5rem; }

        /* =====================================================================
         * Step 6 (Complete) — success heading + final CTA
         * =================================================================*/
        .install-card h2.text-success {
            color: var(--portal-success) !important;
        }
        .install-card .text-center {
            margin-top: 0.5rem;
        }
        .install-card .text-center .btn-lg {
            min-width: 220px;
        }

        /* =====================================================================
         * Footer
         * =================================================================*/
        .install-footer {
            text-align: center;
            color: var(--portal-text-muted);
            font-size: 0.8125rem;
            margin-top: 2rem;
            padding-top: 1rem;
        }

        /* =====================================================================
         * Focus indicators (WCAG)
         * =================================================================*/
        :focus-visible {
            outline: 3px solid var(--portal-primary);
            outline-offset: 2px;
        }
    </style>

    <!-- 🌙 Prevent FOUC: apply saved theme + CB prefs before first paint -->
    <script>
    (function(){
        var html = document.documentElement;
        var t = localStorage.getItem('portal-theme');
        if (t === 'auto' || t === null) {
            var prefersDark = window.matchMedia
                && window.matchMedia('(prefers-color-scheme: dark)').matches;
            html.setAttribute('data-bs-theme', prefersDark ? 'dark' : 'light');
        } else if (t === 'dark' || t === 'light') {
            html.setAttribute('data-bs-theme', t);
        }
        if (localStorage.getItem('portal-cb') === 'on') {
            html.setAttribute('data-portal-cb', 'on');
        }
    })();
    </script>
</head>
<body>
<div class="install-shell" style="position: relative;">

    <!-- 🌙 / 🎨 Theme + accessibility toggles
         Inline SVG icons so the installer doesn't need Font Awesome loaded. -->
    <div class="install-toggles">
        <button type="button" class="install-toggle" id="installThemeToggle"
                title="Theme: auto (system) — click for light"
                aria-label="Toggle theme">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="9"/>
                <path d="M12 3v18" />
                <path d="M12 3a9 9 0 0 1 0 18 9 9 0 0 1 0-18z" fill="currentColor" opacity="0.3"/>
            </svg>
        </button>
        <button type="button" class="install-toggle" id="installCbToggle"
                title="Colour-blind safe palette: off — click to turn on"
                aria-label="Toggle colour-blind safe palette"
                aria-pressed="false">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/>
                <circle cx="12" cy="12" r="3"/>
            </svg>
        </button>
    </div>

    <!-- Header -->
    <div class="install-header">
        <h1><?php echo htmlspecialchars($INSTALL_PRODUCT_NAME, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="install-tagline">Installation Wizard &mdash; v<?php echo INSTALL_VERSION; ?></p>
    </div>

    <!-- Step indicator -->
    <div class="step-row">
        <?php for ($i = 1; $i <= 6; $i++): ?>
            <?php
            $cls = 'step-pending';
            if ($i < $step) {
                $cls = 'step-done';
            } elseif ($i === $step) {
                $cls = 'step-active';
            }
            ?>
            <span class="step-badge <?php echo $cls; ?>"><?php echo $i; ?></span>
        <?php endfor; ?>
    </div>

    <!-- Error / success messages -->
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger install-card"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
        <div class="alert alert-success install-card"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <!-- Step content -->
    <div class="card install-card shadow-sm">
        <div class="card-body p-4">

<?php if ($isIndustryStep === true): ?>
            <!-- STEP 1.5: Organisation Type — picks the product brand preset (#296) -->
            <h2 class="h5 mb-3">What kind of organisation is this?</h2>
            <p>The portal can ship as a generic management system, or as a tailored sub-brand (e.g. <strong>ChurchMS</strong> for places of worship). Your choice affects only the visible product name, tagline, and default assets — every app and feature remains available.</p>
            <p class="text-muted small">You can change this later via Admin &rarr; Settings &rarr; <code>portal.industry</code>.</p>

            <form method="post" autocomplete="off">
                <input type="hidden" name="action" value="select_industry">
                <input type="hidden" name="step" value="1.5">

                <div class="mb-3">
                    <label for="industry" class="form-label">Organisation type</label>
                    <select id="industry" name="industry" class="form-select" required>
                        <?php foreach ($INSTALL_BRAND_PRESETS as $key => $preset):
                            // Skip the unnamed alias of generic — '' and 'generic' map to the same preset
                            if ($key === '') { continue; }
                            $isSelected = ($key === $selectedIndustry);
                        ?>
                            <option value="<?php echo htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8'); ?>"
                                    <?php echo $isSelected === true ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string) ($preset['displayLabel'] ?? $key), ENT_QUOTES, 'UTF-8'); ?>
                                — will install as <?php echo htmlspecialchars((string) ($preset['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">
                        Picking <em>Generic</em> keeps the historical <strong>WebMS Intra</strong> branding.
                        Picking <em>Church / Place of Worship</em> rebrands the install to <strong>ChurchMS</strong>.
                        Other sub-brands (School, Charity, Community, Small Business) are placeholders for v1.x — pick them only if you're previewing.
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <a href="?step=1" class="btn btn-outline-secondary">&larr; Back</a>
                    <button type="submit" class="btn btn-primary">Continue &rarr;</button>
                </div>
            </form>

<?php elseif ($step === 1): ?>
            <!-- STEP 1: Welcome & Prerequisites -->
            <h2 class="h5 mb-3">Welcome</h2>
            <p>This wizard will guide you through installing <?php echo htmlspecialchars($INSTALL_PRODUCT_NAME, ENT_QUOTES, 'UTF-8'); ?>. Before proceeding, let's check that your server meets the requirements.</p>

            <h3 class="h6 mt-4 mb-2">Server Prerequisites</h3>
            <table class="table table-sm">
                <thead><tr><th>Requirement</th><th>Status</th><th>Value</th></tr></thead>
                <tbody>
                <?php foreach ($prereqs as $p): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($p['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php if ($p['pass'] === true): ?>
                                <span class="prereq-pass">&#10004; Pass</span>
                            <?php else: ?>
                                <span class="prereq-fail">&#10008; Fail</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted"><?php echo htmlspecialchars($p['value'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($allPrereqsPassed === true): ?>
                <a href="?step=1.5" class="btn btn-primary">Continue &rarr;</a>
            <?php else: ?>
                <div class="alert alert-warning mt-3 mb-0">
                    Some prerequisites are not met. Please resolve the issues above before continuing.
                </div>
            <?php endif; ?>

<?php elseif ($step === 2): ?>
            <!-- STEP 2: Database Configuration -->
            <h2 class="h5 mb-3">Database Configuration</h2>
            <p>Enter your MySQL database credentials. If the database does not exist, the installer will attempt to create it.</p>
            <p class="text-muted small">On shared hosting (e.g. DreamHost), you may need to create the database via your hosting control panel first.</p>

            <form method="post" autocomplete="off">
                <input type="hidden" name="action" value="db_config">
                <input type="hidden" name="step" value="2">

                <div class="mb-3">
                    <label for="db_host" class="form-label">Database Host</label>
                    <input type="text" class="form-control" id="db_host" name="db_host"
                           value="<?php echo htmlspecialchars($_POST['db_host'] ?? 'localhost', ENT_QUOTES, 'UTF-8'); ?>" required>
                    <div class="form-text">Usually <code>localhost</code> or a hostname provided by your host.</div>
                </div>

                <div class="row">
                    <div class="col-md-8 mb-3">
                        <label for="db_user" class="form-label">Database Username</label>
                        <input type="text" class="form-control" id="db_user" name="db_user"
                               value="<?php echo htmlspecialchars($_POST['db_user'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="db_port" class="form-label">Port</label>
                        <input type="number" class="form-control" id="db_port" name="db_port"
                               value="<?php echo (int) ($_POST['db_port'] ?? 3306); ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label for="db_pass" class="form-label">Database Password</label>
                    <input type="password" class="form-control" id="db_pass" name="db_pass"
                           value="<?php echo htmlspecialchars($_POST['db_pass'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="mb-3">
                    <label for="db_name" class="form-label">Database Name</label>
                    <input type="text" class="form-control" id="db_name" name="db_name"
                           value="<?php echo htmlspecialchars($_POST['db_name'] ?? 'webms_intra', ENT_QUOTES, 'UTF-8'); ?>" required>
                    <div class="form-text">If this database does not exist, the installer will try to create it.</div>
                </div>

                <div class="d-flex justify-content-between">
                    <a href="?step=1" class="btn btn-outline-secondary">&larr; Back</a>
                    <button type="submit" class="btn btn-primary">Test Connection &amp; Continue &rarr;</button>
                </div>
            </form>

<?php elseif ($isChoiceStep === true): ?>
            <!-- STEP 2.5: Existing-data choice -->
            <?php
            $stateInfo  = $_SESSION['install_db_state'] ?? ['state' => DB_STATE_PARTIAL];
            $stateCode  = (string) ($stateInfo['state'] ?? DB_STATE_PARTIAL);
            $tableCount = (int) ($stateInfo['table_count'] ?? 0);
            $installedV = $stateInfo['installed_version'] ?? null;
            $canContinue = ($stateCode !== DB_STATE_FRESH_REQUIRED);
            $isUpgrade  = ($stateCode === DB_STATE_INSTALLED_UPGRADE);
            $isPartial  = ($stateCode === DB_STATE_PARTIAL);
            $isForce    = ($stateCode === DB_STATE_FRESH_REQUIRED);
            $policyArr  = (array) (require INSTALL_ROOT
                . DIRECTORY_SEPARATOR . '_install'
                . DIRECTORY_SEPARATOR . 'upgrade-policy.php');
            $confirmExpected = ((bool) ($policyArr['require_hostname_confirmation_for_drop'] ?? true))
                ? ($_SERVER['HTTP_HOST'] ?? 'DROP')
                : 'DROP';
            ?>
            <h2 class="h5 mb-3">Existing Database Detected</h2>

            <?php if ($isUpgrade === true): ?>
                <div class="alert alert-info">
                    <strong>This portal is already installed</strong> at version
                    <code><?php echo htmlspecialchars((string) $installedV, ENT_QUOTES, 'UTF-8'); ?></code>.
                    The code on disk is at
                    <code><?php echo htmlspecialchars(INSTALL_VERSION, ENT_QUOTES, 'UTF-8'); ?></code>
                    — an upgrade is needed.
                </div>
            <?php elseif ($isPartial === true): ?>
                <div class="alert alert-warning">
                    <strong>A previous installation didn't complete.</strong>
                    The database has <?php echo $tableCount; ?> portal tables
                    but the installation lock file is missing.
                    You can either continue from where you left off (your data
                    will be preserved) or drop the existing tables and start fresh.
                </div>
            <?php elseif ($isForce === true): ?>
                <div class="alert alert-danger">
                    <strong>In-place upgrade not available.</strong>
                    The installed version
                    (<code><?php echo htmlspecialchars((string) $installedV, ENT_QUOTES, 'UTF-8'); ?></code>)
                    is older than the upgrade policy's
                    <code>fresh_required_below</code> threshold. You must drop
                    and rebuild to install this version. Any data you wish to
                    preserve should be backed up first.
                </div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="action" value="install_action">
                <input type="hidden" name="step"   value="2.5">

                <div class="install-card mb-3" style="padding:1rem;border:1px solid var(--bs-border-color);border-radius:.5rem;">
                    <div class="form-check">
                        <input class="form-check-input" type="radio"
                               name="install_action_choice" id="choice_continue"
                               value="continue"
                               <?php echo $canContinue === false ? 'disabled' : 'checked'; ?>>
                        <label class="form-check-label" for="choice_continue">
                            <strong>Continue with existing data</strong>
                            <?php if ($isUpgrade === true): ?>
                                <span class="badge bg-info text-dark ms-1">Upgrade</span>
                            <?php elseif ($isPartial === true): ?>
                                <span class="badge bg-success text-dark ms-1">Recommended</span>
                            <?php endif; ?>
                            <div class="text-muted small mt-1">
                                <?php if ($isUpgrade === true): ?>
                                    A full JSON backup of every table will be written to
                                    <code>web/_backups/</code> before any migration runs.
                                    Migrations are idempotent (additive) — no data is lost.
                                <?php else: ?>
                                    Existing tables will pick up any missing columns via
                                    idempotent migrations. Your data is preserved.
                                <?php endif; ?>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="install-card mb-3" style="padding:1rem;border:1px solid var(--bs-border-color);border-radius:.5rem;">
                    <div class="form-check">
                        <input class="form-check-input" type="radio"
                               name="install_action_choice" id="choice_drop"
                               value="drop"
                               <?php echo $isForce === true ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="choice_drop">
                            <strong>Drop existing tables and start fresh</strong>
                            <span class="badge bg-danger ms-1">Destructive</span>
                            <div class="text-muted small mt-1">
                                Every portal table will be <code>DROP</code>ped,
                                then recreated from scratch. <strong>All existing
                                data will be lost.</strong> Type the portal
                                hostname below to confirm.
                            </div>
                            <div class="mt-2">
                                <label class="form-label small" for="drop_confirm">
                                    Confirmation (type
                                    <code><?php echo htmlspecialchars($confirmExpected, ENT_QUOTES, 'UTF-8'); ?></code>):
                                </label>
                                <input type="text" class="form-control form-control-sm"
                                       id="drop_confirm" name="drop_confirm"
                                       placeholder="Hostname or 'DROP'">
                            </div>
                        </label>
                    </div>
                </div>

                <div class="d-flex justify-content-between">
                    <a href="?step=2" class="btn btn-outline-secondary">&larr; Back</a>
                    <button type="submit" class="btn btn-primary">Continue &rarr;</button>
                </div>
            </form>

<?php elseif ($step === 3): ?>
            <!-- STEP 3: Install Schema -->
            <h2 class="h5 mb-3">Install Database Schema</h2>
            <p>The database connection was successful. Click the button below to create all tables and seed initial data.</p>

            <div class="alert alert-info">
                <strong>What will happen:</strong>
                <ul class="mb-0 mt-1">
                    <li>All portal tables will be created (using <code>CREATE TABLE IF NOT EXISTS</code>)</li>
                    <li>Default settings, routes, and seed data will be inserted</li>
                    <li>Existing data will not be overwritten</li>
                </ul>
            </div>

            <form method="post">
                <input type="hidden" name="action" value="install_schema">
                <input type="hidden" name="step" value="3">
                <div class="d-flex justify-content-between">
                    <a href="?step=2" class="btn btn-outline-secondary">&larr; Back</a>
                    <button type="submit" class="btn btn-primary">Install Schema &rarr;</button>
                </div>
            </form>

<?php elseif ($step === 4): ?>
            <!-- STEP 4: Create Admin User -->
            <h2 class="h5 mb-3">Create Administrator Account</h2>
            <p>Create the first portal administrator. This account will have full access to all portal features.</p>

            <form method="post" autocomplete="off">
                <input type="hidden" name="action" value="create_admin">
                <input type="hidden" name="step" value="4">

                <div class="mb-3">
                    <label for="admin_name" class="form-label">Full Name</label>
                    <input type="text" class="form-control" id="admin_name" name="admin_name"
                           value="<?php echo htmlspecialchars($_POST['admin_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="mb-3">
                    <label for="admin_email" class="form-label">Email Address</label>
                    <input type="email" class="form-control" id="admin_email" name="admin_email"
                           value="<?php echo htmlspecialchars($_POST['admin_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    <div class="form-text">This will also be used as the login username for local authentication.</div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="admin_pass" class="form-label">Password</label>
                        <input type="password" class="form-control" id="admin_pass" name="admin_pass"
                               required minlength="12" maxlength="128"
                               data-portal-password-input>
                        <div class="portal-password-strength mt-2" data-portal-password-meter hidden>
                            <div class="progress" style="height:6px;">
                                <div class="progress-bar" role="progressbar" style="width:0%"></div>
                            </div>
                            <small class="form-text" data-portal-password-meter-label>Password strength</small>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="admin_pass2" class="form-label">Confirm Password</label>
                        <input type="password" class="form-control" id="admin_pass2" name="admin_pass2"
                               required minlength="12" maxlength="128">
                    </div>
                </div>
                <div class="alert alert-light border small mb-3">
                    <strong>Password must include:</strong>
                    <ul class="mb-0 ps-3">
                        <li>At least 12 characters</li>
                        <li>At least one uppercase letter (A-Z)</li>
                        <li>At least one lowercase letter (a-z)</li>
                        <li>At least one number (0-9)</li>
                        <li>At least one special character (e.g. !@#$%^&amp;*)</li>
                    </ul>
                </div>

                <div class="d-flex justify-content-between">
                    <a href="?step=3" class="btn btn-outline-secondary">&larr; Back</a>
                    <button type="submit" class="btn btn-primary">Create Account &rarr;</button>
                </div>
            </form>

<?php elseif ($step === 5): ?>
            <!-- STEP 5: Finalize -->
            <h2 class="h5 mb-3">Finalize Setup</h2>
            <p>Final step: generate the encryption key and save the configuration files.</p>

            <div class="alert alert-info">
                <strong>This will create:</strong>
                <ul class="mb-0 mt-1">
                    <li><code>_auth_keys/auth_creds.php</code> &mdash; Database credentials</li>
                    <li><code>_auth_keys/enc.key</code> &mdash; Encryption key for sensitive settings</li>
                    <li><code>_auth_keys/.installed</code> &mdash; Lock file to prevent re-installation</li>
                </ul>
            </div>

            <div class="alert alert-warning">
                <strong>Important:</strong> These files contain sensitive data. Ensure the <code>_auth_keys/</code>
                directory is not accessible via the web server. The default <code>.htaccess</code> should block access.
            </div>

            <form method="post">
                <input type="hidden" name="action" value="finalize">
                <input type="hidden" name="step" value="5">
                <div class="d-flex justify-content-between">
                    <a href="?step=4" class="btn btn-outline-secondary">&larr; Back</a>
                    <button type="submit" class="btn btn-success">Finalize Installation</button>
                </div>
            </form>

<?php elseif ($step === 6): ?>
            <!-- STEP 6: Complete -->
            <h2 class="h5 mb-3 text-success">Installation Complete!</h2>
            <p><?php echo htmlspecialchars($INSTALL_PRODUCT_NAME, ENT_QUOTES, 'UTF-8'); ?> has been successfully installed. Your portal is ready to use.</p>

            <div class="alert alert-success">
                <ul class="mb-0">
                    <li>Database tables and seed data created</li>
                    <li>Administrator account created</li>
                    <li>Encryption key generated</li>
                    <li>Configuration files saved</li>
                </ul>
            </div>

            <div class="alert alert-info">
                <strong>Next steps:</strong>
                <ol class="mb-0 mt-1">
                    <li>Log in with the administrator account you just created</li>
                    <li>Configure your site settings (name, timezone, branding)</li>
                    <li>Set up authentication providers (MS365, Google) if needed</li>
                    <li>Create additional user accounts</li>
                </ol>
            </div>

            <div class="text-center mt-4">
                <a href="/" class="btn btn-primary btn-lg">Go to Portal &rarr;</a>
            </div>

<?php endif; ?>

        </div>
    </div>

    <!-- Footer -->
    <div class="install-footer">
        <?php echo htmlspecialchars($INSTALL_PRODUCT_NAME, ENT_QUOTES, 'UTF-8'); ?> v<?php echo INSTALL_VERSION; ?> &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($INSTALL_PRODUCT_PUBLISHER, ENT_QUOTES, 'UTF-8'); ?>
    </div>

</div>

<script>
// Theme + CB toggle wiring for the installer (standalone — doesn't load portal.js)
(function(){
    var html = document.documentElement;
    var themeBtn = document.getElementById('installThemeToggle');
    var cbBtn    = document.getElementById('installCbToggle');
    var mql      = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function savedTheme() {
        var v = localStorage.getItem('portal-theme');
        return (v === 'light' || v === 'dark' || v === 'auto') ? v : 'auto';
    }
    function applyTheme(pref) {
        if (pref === 'auto') {
            html.setAttribute('data-bs-theme', (mql && mql.matches) ? 'dark' : 'light');
        } else {
            html.setAttribute('data-bs-theme', pref);
        }
        updateThemeBtn(pref);
    }
    function updateThemeBtn(pref) {
        if (!themeBtn) return;
        var titles = {
            light: 'Theme: light — click for dark',
            dark:  'Theme: dark — click for auto',
            auto:  'Theme: auto (system) — click for light'
        };
        themeBtn.setAttribute('title', titles[pref] || titles.auto);
    }
    function savedCb() { return localStorage.getItem('portal-cb') === 'on'; }
    function applyCb(on) {
        if (on) {
            html.setAttribute('data-portal-cb', 'on');
        } else {
            html.removeAttribute('data-portal-cb');
        }
        if (cbBtn) {
            cbBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
            cbBtn.setAttribute('title', on
                ? 'Colour-blind safe palette: on — click to turn off'
                : 'Colour-blind safe palette: off — click to turn on');
        }
    }

    if (themeBtn) {
        themeBtn.addEventListener('click', function(){
            var cur = savedTheme();
            var next = (cur === 'light') ? 'dark' : (cur === 'dark' ? 'auto' : 'light');
            localStorage.setItem('portal-theme', next);
            applyTheme(next);
        });
    }
    if (cbBtn) {
        cbBtn.addEventListener('click', function(){
            var next = !savedCb();
            if (next) { localStorage.setItem('portal-cb', 'on'); }
            else      { localStorage.removeItem('portal-cb'); }
            applyCb(next);
        });
    }
    if (mql && typeof mql.addEventListener === 'function') {
        mql.addEventListener('change', function(){
            if (savedTheme() === 'auto') applyTheme('auto');
        });
    }

    // Initial state — FOUC script already applied the data-* attrs; just sync the icons/titles.
    updateThemeBtn(savedTheme());
    applyCb(savedCb());
})();
</script>

<script>
// Password strength meter for the installer (standalone — doesn't load portal.js)
(function () {
    function score(value, minLength) {
        if (!value) { return 0; }
        var s = 0;
        if (value.length >= minLength)  { s += 1; }
        if (/[a-z]/.test(value))        { s += 1; }
        if (/[A-Z]/.test(value))        { s += 1; }
        if (/[0-9]/.test(value))        { s += 1; }
        if (/[^a-zA-Z0-9]/.test(value)) { s += 1; }
        return s;
    }
    function labelFor(s) {
        if (s <= 1)  { return ['bg-danger',  'Very weak']; }
        if (s === 2) { return ['bg-danger',  'Weak']; }
        if (s === 3) { return ['bg-warning', 'Fair']; }
        if (s === 4) { return ['bg-info',    'Strong']; }
        return ['bg-success', 'Very strong'];
    }
    document.querySelectorAll('input[data-portal-password-input]').forEach(function (input) {
        var scope = input.closest('.col-md-6, .mb-3, form') || input.parentNode;
        var meter = scope ? scope.querySelector('[data-portal-password-meter]') : null;
        if (!meter) { return; }
        var bar = meter.querySelector('.progress-bar');
        var lbl = meter.querySelector('[data-portal-password-meter-label]');
        if (!bar) { return; }
        var minLength = parseInt(input.getAttribute('minlength'), 10);
        if (!minLength || minLength < 1) { minLength = 12; }
        input.addEventListener('input', function () {
            var v = input.value || '';
            if (v === '') {
                meter.hidden = true;
                bar.style.width = '0%';
                bar.className = 'progress-bar';
                if (lbl) { lbl.textContent = 'Password strength'; }
                return;
            }
            meter.hidden = false;
            var sc = score(v, minLength);
            var pct = Math.round((sc / 5) * 100);
            var sl = labelFor(sc);
            bar.style.width = pct + '%';
            bar.className = 'progress-bar ' + sl[0];
            bar.setAttribute('aria-valuenow', String(pct));
            if (lbl) { lbl.textContent = 'Password strength: ' + sl[1]; }
        });
    });
})();
</script>

</body>
</html>
