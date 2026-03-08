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
 * @version   0.8.1
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/84
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Constants for this self-contained installer
// ---------------------------------------------------------------------------
define('INSTALL_ROOT', realpath(__DIR__ . DIRECTORY_SEPARATOR . '..'));
define('INSTALL_SQL', INSTALL_ROOT . DIRECTORY_SEPARATOR . 'sql');
define('INSTALL_AUTH_DIR', INSTALL_ROOT . DIRECTORY_SEPARATOR . '_auth_keys');
define('INSTALL_CREDS_FILE', INSTALL_AUTH_DIR . DIRECTORY_SEPARATOR . 'auth_creds.php');
define('INSTALL_ENC_KEY_FILE', INSTALL_AUTH_DIR . DIRECTORY_SEPARATOR . 'enc.key');
define('INSTALL_LOCK_FILE', INSTALL_AUTH_DIR . DIRECTORY_SEPARATOR . '.installed');
define('INSTALL_VERSION', '0.8.1');

// ---------------------------------------------------------------------------
// Block re-installation if lock file exists
// ---------------------------------------------------------------------------
if (is_file(INSTALL_LOCK_FILE) === true || is_file(INSTALL_CREDS_FILE) === true) {
    http_response_code(403);
    echo '<!doctype html><html><head><title>Already Installed</title>'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<style>body{font-family:system-ui;text-align:center;padding:4rem;}'
       . '.btn{display:inline-block;padding:.6rem 1.5rem;background:#0d6efd;color:#fff;'
       . 'text-decoration:none;border-radius:.4rem;margin-top:1rem;}</style></head>'
       . '<body><h1>Already Installed</h1>'
       . '<p>WebMS Intra has already been installed. To re-run the installer, '
       . 'remove both the lock file and credentials file from the <code>_auth_keys/</code> directory.</p>'
       . '<a class="btn" href="/">Go to Portal</a></body></html>';
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
$step = (int) ($_GET['step'] ?? ($_POST['step'] ?? 1));
if ($step < 1 || $step > 6) {
    $step = 1;
}

// Enforce step progression — cannot skip ahead without completing prior steps
if ($step >= 3 && isset($_SESSION['install_db']) === false) {
    $step = 2; // DB credentials not yet configured
}
if ($step === 6 && is_file(INSTALL_LOCK_FILE) === false) {
    $step = 5; // Finalization not yet completed
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
                    $testConn->close();
                    header('Location: ?step=3');
                    exit();
                }

                $testConn->close();
            } catch (\mysqli_sql_exception $e) {
                $error = 'Database connection failed: ' . $e->getMessage();
                $step = 2;
            }
        }
    }

    // Step 3: Install schema
    if ($action === 'install_schema') {
        $db = installGetDb();
        if ($db === null) {
            $error = 'Database connection lost. Please go back to Step 2.';
            $step = 2;
        } else {
            $schemaFile = INSTALL_SQL . DIRECTORY_SEPARATOR . 'full_schema.sql';
            if (is_readable($schemaFile) === false) {
                $error = 'Schema file not found: sql/full_schema.sql';
                $step = 3;
            } else {
                $sql = file_get_contents($schemaFile);
                $db->multi_query($sql);

                // Consume all result sets from multi_query
                $queryError = '';
                do {
                    $result = $db->store_result();
                    if ($result !== false) {
                        $result->free();
                    }
                    if ($db->errno !== 0) {
                        $queryError = $db->error;
                    }
                } while ($db->more_results() === true && $db->next_result());

                if ($queryError !== '') {
                    $error = 'Schema installation error: ' . $queryError;
                    $step = 3;
                } else {
                    header('Location: ?step=4');
                    exit();
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

        if ($adminName === '' || $adminEmail === '' || $adminPass === '') {
            $error = 'All fields are required.';
            $step = 4;
        } elseif (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            $error = 'Please enter a valid email address.';
            $step = 4;
        } elseif (strlen($adminPass) < 8) {
            $error = 'Password must be at least 8 characters.';
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
                // Insert admin user
                $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                $stmt = $db->prepare(
                    'INSERT INTO tblUsers (fullName, emailAddress, isAdmin, isRootAdmin, isActive, createdAt, siteID) '
                    . 'VALUES (?, ?, 1, 1, 1, NOW(), 1)'
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
            // Create lock file to prevent re-installation
            file_put_contents(INSTALL_LOCK_FILE, date('c') . "\n" . 'Installed by WebMS Intra installer v' . INSTALL_VERSION);

            // Set restrictive permissions
            chmod(INSTALL_CREDS_FILE, 0640);
            chmod(INSTALL_ENC_KEY_FILE, 0640);
            chmod(INSTALL_LOCK_FILE, 0640);

            // Clear session install data
            unset($_SESSION['install_db']);

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
        'label' => 'sql/ directory readable',
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
$pageTitle = 'Install — ' . ($stepTitles[$step] ?? 'WebMS Intra');

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YcnS/1PTgCq0l0vD8pVNSNZS1p0H084UB" crossorigin="anonymous">
    <style>
        body { background: #f0f2f5; }
        .install-card { max-width: 640px; margin: 2rem auto; }
        .step-badge { width: 2rem; height: 2rem; border-radius: 50%; display: inline-flex;
                      align-items: center; justify-content: center; font-weight: 600; font-size: .85rem; }
        .step-active { background: #0d6efd; color: #fff; }
        .step-done { background: #198754; color: #fff; }
        .step-pending { background: #dee2e6; color: #6c757d; }
        .prereq-pass { color: #198754; }
        .prereq-fail { color: #dc3545; }
    </style>
</head>
<body>
<div class="container py-4">

    <!-- Header -->
    <div class="text-center mb-4">
        <h1 class="h3 fw-bold">WebMS Intra</h1>
        <p class="text-muted">Installation Wizard &mdash; v<?php echo INSTALL_VERSION; ?></p>
    </div>

    <!-- Step indicator -->
    <div class="d-flex justify-content-center gap-2 mb-4">
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

<?php if ($step === 1): ?>
            <!-- STEP 1: Welcome & Prerequisites -->
            <h2 class="h5 mb-3">Welcome</h2>
            <p>This wizard will guide you through installing WebMS Intra. Before proceeding, let's check that your server meets the requirements.</p>

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
                <a href="?step=2" class="btn btn-primary">Continue &rarr;</a>
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
                        <input type="password" class="form-control" id="admin_pass" name="admin_pass" required minlength="8">
                        <div class="form-text">Minimum 8 characters.</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="admin_pass2" class="form-label">Confirm Password</label>
                        <input type="password" class="form-control" id="admin_pass2" name="admin_pass2" required minlength="8">
                    </div>
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
            <p>WebMS Intra has been successfully installed. Your portal is ready to use.</p>

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
    <div class="text-center text-muted small mt-4">
        WebMS Intra v<?php echo INSTALL_VERSION; ?> &copy; <?php echo date('Y'); ?> MWBM Partners Ltd (t/a MWservices)
    </div>

</div>
</body>
</html>
