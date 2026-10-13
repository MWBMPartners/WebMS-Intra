<?php
// Path: _apps/cron/_health-probes.php
/**
 * -----------------------------------------------------------------------------
 * Shared — System Health probes (the checks both callers run) 🩺
 * -----------------------------------------------------------------------------
 * NOT a page. No address points at this file; it is only ever loaded with
 * require_once by:
 *
 *   - web/_apps/admin/maintenance/health.php — the staff page, which draws a
 *     card for each check;
 *   - web/_apps/cron/health.php — the address for uptime monitors,
 *     /cron/health?token=…, which returns the same checks as JSON.
 *
 * WHY THIS FILE EXISTS
 * The monitor used to call a "?cron=1" mode of the staff page itself. Once the
 * Router started enforcing sign-in (issue #497) that could not work: the staff
 * page is a protected address, so a monitor with a token and no session would
 * be redirected to the sign-in page. Worse, a redirect is not an error to most
 * monitors, so nobody would have been told. The monitor address therefore
 * moved to /cron/health, and the checks moved here so the page and the monitor
 * can never report different things.
 *
 * The checks below are moved unchanged from the staff page; only the
 * indentation changed, because they now sit inside a function.
 *
 * WHAT THIS FILE CANNOT DO
 * No permission check. Each caller decides who may see the result.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.2.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/228
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/497
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\DbBackup;

/**
 * Run every health check.
 *
 * @param \mysqli $db The portal's database connection.
 *
 * @return array{overall: string, probes: array<string, array{state: string, label: string, detail: string}>}
 *         overall is 'ok', 'warn' or 'crit' — the worst state of any check.
 */
function maintenance_health_probes(\mysqli $db): array
{
    // 🧪 Probes — each returns ['state' => 'ok|warn|crit', 'label' => '', 'detail' => '']
    $probes = [];

    // 1. Database
    //    Two separate things are being reported here, and they used to be muddled
    //    together. "Can we reach the database at all?" is the health question. "Is
    //    the version we reached still supported?" is a different question, and this
    //    page used to answer it by printing the word "MySQL" in front of whatever
    //    version string came back — which was simply wrong on a MariaDB server, and
    //    said nothing about whether the version still receives security fixes.
    //
    //    Portal\Core\DbServer answers the second question, and is the SAME code the
    //    installation wizard and the Server Information page use, so all three
    //    always agree.
    //    On the traffic light itself, note what this probe does NOT do. A database
    //    that is past its security-fix date is worth knowing about, but it is not
    //    an incident: it will be equally true tomorrow and every day after. This
    //    page is polled by uptime monitors, so turning it amber for that would mean
    //    a permanent alert that everyone quickly learns to ignore — and a real
    //    problem arriving later would land in a warning nobody reads any more.
    //
    //    So the light stays green while the database is reachable, and the version
    //    and its support position are reported in the text beside it. The one
    //    exception is a database too old to run this portal properly, which is a
    //    real fault happening right now and does turn the light amber.
    try {
        // 🩺 Prove the connection actually works BEFORE asking what version it is.
        //
        //    This order matters and was got wrong once. DbServer::inspect() is
        //    deliberately forgiving: if a query fails it falls back to the version
        //    string the driver recorded when the connection was first opened, so it
        //    returns a sensible-looking answer even from a connection that has since
        //    died. Calling it first therefore turned a dead database into a green
        //    "Connected" light — the exact opposite of what this page is for.
        //
        //    A plain 'SELECT 1' has no such fallback. If the database is not there,
        //    this throws, and the catch below reports it as a real fault.
        $probe = $db->query('SELECT 1');
        if ($probe !== false) {
            $probe->free();
        }

        $dbInfo = \Portal\Core\DbServer::inspect($db);

        // 📣 Show DbServer's OWN wording rather than writing a shorter version here.
        //    That matters more than it looks. DbServer answers 'ok' both for a
        //    version it knows is supported AND for one newer than anything it has
        //    been told about — and in the second case its wording deliberately says
        //    it cannot vouch for the support position. Rewriting every 'ok' as "a
        //    supported version" here threw that distinction away and put back the
        //    false reassurance the whole class exists to remove.
        $probes['Database'] = [
            'state'  => $dbInfo['state'] === 'crit' ? 'warn' : 'ok',
            'label'  => 'Connected',
            'detail' => $dbInfo['state'] === 'ok'
                ? (string) $dbInfo['headline']
                : (string) $dbInfo['headline'] . ' — see Admin → Server Information',
        ];
    } catch (\Throwable $e) {
        $probes['Database'] = ['state' => 'crit', 'label' => 'Connection failed', 'detail' => $e->getMessage()];
    }

    // 1b. Database strictness (added 12 October 2026)
    //    The portal asks the database to REFUSE a value that does not fit its
    //    column (too long, too large, empty where one is needed, not on the
    //    column's list of choices) rather than store it changed. It asks every
    //    time it connects, in Portal\Core\DbServer::connect(). This check reads
    //    the setting BACK from the database instead of trusting that the request
    //    worked, so it also catches a server that said yes and did nothing.
    //
    //    Unlike an old database version (see 1 above), this one DOES change the
    //    light, and that is deliberate. A connection that is not strict is
    //    altering what people save right now, on every page, with no error:
    //    that is a fault in progress, not a fact that will be equally true
    //    tomorrow. 'crit' means not strict at all; 'warn' means strict but not
    //    the exact setting asked for, or that the database would not say.
    //    strictness() never throws, so no try block is needed here.
    //
    //    The detail line shows the setting READ BACK (`mode`), never the one
    //    asked for (`wanted`): on a refused request those differ, and the one
    //    asked for would read as if it were in force. An empty `mode` means
    //    either that the setting is empty or that the database would not say,
    //    and strictness() does not tell the two apart, so the wording covers
    //    both. It used to say "(nothing)", which was untrue for the second.
    $strictness = \Portal\Core\DbServer::strictness($db);
    $probes['Database strictness'] = [
        'state'  => $strictness['state'],
        'label'  => match ($strictness['state']) {
            'ok'    => 'Strict',
            'crit'  => 'NOT strict',
            default => 'Needs a look',
        },
        'detail' => $strictness['headline']
            . ' — running under: ' . ($strictness['mode'] !== '' ? $strictness['mode'] : '(empty, or the database would not say)')
            . ($strictness['state'] === 'ok' ? '' : ' — see Admin → Server Information'),
    ];

    // 2. Disk — _backups + _uploads
    $diskState = 'ok';
    $diskDetail = '';
    foreach (['_backups', '_uploads'] as $dir) {
        $path = PORTAL_ROOT . DIRECTORY_SEPARATOR . $dir;
        if (is_dir($path) === false) {
            continue;
        }
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        if ($free === false || $total === false || $total <= 0) {
            continue;
        }
        $pct = ($free / $total) * 100;
        $diskDetail .= sprintf(
            '%s: %.1f%% free (%.1f GB / %.1f GB) · ',
            $dir,
            $pct,
            $free / 1024 / 1024 / 1024,
            $total / 1024 / 1024 / 1024
        );
        if ($pct < 5) {
            $diskState = 'crit';
        } elseif ($pct < 15 && $diskState === 'ok') {
            $diskState = 'warn';
        }
    }
    $probes['Disk space'] = ['state' => $diskState, 'label' => $diskState === 'ok' ? 'Healthy' : ucfirst($diskState), 'detail' => rtrim($diskDetail, ' · ')];

    // 3. Backups
    //    Only a FINISHED snapshot counts. This used to read the first folder
    //    in the list, and the folder a FAILED snapshot leaves behind is the
    //    newest one there is, so a failed backup showed as "Fresh" (#577).
    //    DbBackup::newestFinished() is the one answer the freshness job uses
    //    too, so the two cannot disagree.
    $backup     = new DbBackup($db);
    $finished   = count(array_filter($backup->listSnapshots(), static fn (array $s): bool => $s['complete'] === true));
    $newest     = $backup->newestFinished();
    $unfinished = $backup->unfinishedCount();
    //    An unfinished folder means a snapshot failed or was cut off, which
    //    is worth a warning even while the last good one is still fresh.
    $unfinishedNote = $unfinished > 0 ? sprintf(' · %d unfinished (a snapshot failed)', $unfinished) : '';
    //    🚩 A finished snapshot can still be one that CANNOT BE PUT BACK
    //    WHOLE: it holds a value that is not on its column's list of choices,
    //    which a restore refuses (DbBackup::unlistedWarning() has the whole
    //    story, and its sentence is used here word for word so that this
    //    page, the backup page and the upgrade page all say the same thing).
    //    That is a warning even while the snapshot is fresh: "we have a
    //    backup" is not true in the sense people mean it.
    $unlistedNote = $newest !== null ? DbBackup::unlistedWarning($newest['unlisted_values'] ?? []) : '';
    if ($unlistedNote !== '') {
        $unlistedNote = ' · The newest snapshot (' . $newest['name'] . '): ' . $unlistedNote;
    }
    if ($newest === null) {
        $probes['Backups'] = ['state' => 'crit', 'label' => 'None', 'detail' => 'No finished snapshot in _backups/.' . $unfinishedNote];
    } else {
        $threshold = (int) (App::settings()['portal']['backups']['max_age_hours'] ?? 36);
        $createdAt = strtotime((string) $newest['created_at']);
        $ageHrs    = $createdAt !== false ? (int) round((time() - $createdAt) / 3600) : -1;
        if ($ageHrs < 0) {
            $probes['Backups'] = ['state' => 'warn', 'label' => 'Timestamp unparseable', 'detail' => $newest['name'] . $unfinishedNote];
        } elseif ($ageHrs > $threshold) {
            $probes['Backups'] = ['state' => 'warn', 'label' => 'Stale', 'detail' => sprintf('Last: %d hrs ago (threshold %d) · %d finished', $ageHrs, $threshold, $finished) . $unfinishedNote . $unlistedNote];
        } elseif ($unfinished > 0) {
            $probes['Backups'] = ['state' => 'warn', 'label' => 'Fresh, but a snapshot failed', 'detail' => sprintf('Last: %d hrs ago · %d finished', $ageHrs, $finished) . $unfinishedNote . $unlistedNote];
        } elseif ($unlistedNote !== '') {
            $probes['Backups'] = ['state' => 'warn', 'label' => 'Fresh, but cannot be put back whole', 'detail' => sprintf('Last: %d hrs ago · %d finished', $ageHrs, $finished) . $unlistedNote];
        } else {
            $probes['Backups'] = ['state' => 'ok', 'label' => 'Fresh', 'detail' => sprintf('Last: %d hrs ago · %d finished', $ageHrs, $finished)];
        }
    }

    // 4. Errors in last 24h
    try {
        $rs = $db->query("SELECT COUNT(*) AS c FROM tblErrors WHERE createdAt > DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $count24h = $rs !== false ? (int) ($rs->fetch_assoc()['c'] ?? 0) : 0;
        if ($rs !== false) {
            $rs->free();
        }
        $state = $count24h === 0 ? 'ok' : ($count24h > 50 ? 'crit' : 'warn');
        $probes['Errors (24h)'] = ['state' => $state, 'label' => sprintf('%d', $count24h), 'detail' => $count24h > 0 ? 'See /admin/errors' : 'All clear'];
    } catch (\Throwable $e) {
        $probes['Errors (24h)'] = ['state' => 'warn', 'label' => 'Query failed', 'detail' => $e->getMessage()];
    }

    // 4b. Email — can the portal send at all? (#580)
    //     Both pages that draw these checks have always said they check
    //     e-mail, and until now neither did. So an installation with no mail
    //     set up looked healthy while "Forgot password" told people a link
    //     had been sent and sent nothing.
    //
    //     Mailer::sendingRoute() is the one place that knows which mail route
    //     is chosen and what it still needs. It reads settings only, so "Set
    //     up" means the settings are filled in, NOT that a message arrives;
    //     the text says so.
    //
    //     Not set up is a WARNING, not a critical fault: the portal still
    //     works, and an installation may run without mail on purpose. It is a
    //     warning rather than a green light with a note because the harm is
    //     real today: nobody who forgets a password can get back in without
    //     an administrator. The count shows whether that has already happened.
    try {
        $mailRoute = \Portal\Core\Mailer::sendingRoute();
        if ($mailRoute['missing'] === []) {
            $probes['Email'] = [
                'state'  => 'ok',
                'label'  => 'Set up (' . $mailRoute['label'] . ')',
                'detail' => 'The settings are filled in. That does not prove a message arrives: use Send test at Admin → Integrations → Email.',
            ];
        } else {
            // People who asked for a reset link in the last 7 days and were
            // sent nothing. Only counted on this branch, so a healthy portal
            // never pays for the query.
            $rs = $db->query(
                "SELECT COUNT(*) AS c FROM tblActivityLogs WHERE activityType = 'PasswordResetNoMailer' "
                . 'AND timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY)'
            );
            $unsent = $rs !== false ? (int) ($rs->fetch_assoc()['c'] ?? 0) : 0;
            if ($rs !== false) {
                $rs->free();
            }
            $probes['Email'] = [
                'state'  => 'warn',
                'label'  => 'Not set up',
                'detail' => 'Password-reset e-mails cannot be sent. A person who asks for one is still told it was sent. '
                    . sprintf('Asked for and not sent in the last 7 days: %d. ', $unsent)
                    . 'Still needed for ' . $mailRoute['label'] . ': ' . implode('; ', $mailRoute['missing'])
                    // Admin → Settings is where these are typed. This used to
                    // say Admin → Integrations, which only shows them (#580).
                    // An SMTP mailbox is typed on the e-mail page, and its
                    // password must be: one typed at Admin → Settings is not
                    // used (see MailRoutes.php). The other two routes are typed
                    // where this line has always said.
                    . ($mailRoute['route'] === \Portal\Core\MailRoutes::SMTP
                        ? '. Fill these in at Admin → Integrations → Email, then use Send test there.'
                        : '. Fill these in at Admin → Settings (a shared mailbox is typed at Admin → Integrations instead), '
                            . 'then use Send test at Admin → Integrations → Email.'),
            ];
        }
    } catch (\Throwable $e) {
        $probes['Email'] = ['state' => 'warn', 'label' => 'Check failed', 'detail' => $e->getMessage()];
    }

    // 4c. Mail queue — is mail that could not be sent at once going? (#592, part 3)
    //     A line of its own, not folded into "Email" above: that line says
    //     whether the SETTINGS are filled in, this one says what is happening
    //     to mail. Critical when no mail is going at all (the mail server's
    //     certificate failed its check, or it refused the mailbox's sign-in);
    //     a warning when the scheduled job has not run lately, or a setting
    //     is being passed over. MailQueue::health() writes the words, which
    //     hold numbers and the mail route's own facts and never an address,
    //     a subject or any part of a message: this line is also in the
    //     report an outside monitor reads. The one moment it shows is in
    //     this organisation's time zone, with the zone's short name.
    //     Not shown while no mail route is set up AND nothing is waiting:
    //     "Email" above already says that nothing can be sent, and a line
    //     about a job nobody needs yet would be a warning to ignore.
    try {
        $mailQueue = \Portal\Core\MailQueue::health(
            $db,
            (string) (\Portal\Core\Site::current()['timezone'] ?? 'UTC'),
            ($probes['Email']['state'] ?? '') === 'ok'
        );
        if ($mailQueue !== null) {
            $probes['Mail queue'] = $mailQueue;
        }
    } catch (\Throwable $e) {
        $probes['Mail queue'] = ['state' => 'warn', 'label' => 'Check failed', 'detail' => 'The mail queue could not be looked at. The reason is in the server\'s error log.'];
        error_log('Health: the mail queue check failed: ' . get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 300));
    }

    // 5. Sessions — PHP native sessions, count files in the configured save_path
    $sessSavePath = (string) (session_save_path() ?: sys_get_temp_dir());
    $sessCount = 0;
    $sessDetail = '';
    if (is_dir($sessSavePath) === true && is_readable($sessSavePath) === true) {
        $entries = @scandir($sessSavePath);
        if (is_array($entries) === true) {
            // PHP names session files sess_<id>; count files modified in last 30 min.
            $cutoff = time() - (30 * 60);
            foreach ($entries as $e) {
                if (str_starts_with($e, 'sess_') === false) {
                    continue;
                }
                $mt = @filemtime($sessSavePath . DIRECTORY_SEPARATOR . $e);
                if ($mt !== false && $mt >= $cutoff) {
                    $sessCount++;
                }
            }
            $sessDetail = sprintf('Files in %s (last 30 min)', $sessSavePath);
        } else {
            $sessDetail = 'save_path not readable';
        }
    } else {
        $sessDetail = 'save_path unreadable: ' . $sessSavePath;
    }
    $probes['Active sessions'] = ['state' => 'ok', 'label' => sprintf('%d', $sessCount), 'detail' => $sessDetail];

    // 6. Migrations
    try {
        $rs = $db->query('SELECT COUNT(*) AS c FROM tblMigrations');
        $migCount = $rs !== false ? (int) ($rs->fetch_assoc()['c'] ?? 0) : 0;
        if ($rs !== false) {
            $rs->free();
        }
        $installed = (string) (App::settings()['portal']['installed_version'] ?? '?');
        $code = defined('PORTAL_VERSION') ? PORTAL_VERSION : '?';
        $drift = $installed !== '?' && $installed !== '' && version_compare($installed, (string) $code, '<');
        $probes['Migrations'] = [
            'state'  => $drift ? 'warn' : 'ok',
            'label'  => sprintf('%d applied', $migCount),
            'detail' => sprintf('DB %s / code %s%s', $installed, $code, $drift ? ' — upgrade needed' : ''),
        ];
    } catch (\Throwable $e) {
        $probes['Migrations'] = ['state' => 'warn', 'label' => 'Query failed', 'detail' => $e->getMessage()];
    }

    // 7. PHP
    $phpExts = ['curl', 'gd', 'mbstring', 'openssl', 'mysqli'];
    $missing = array_filter($phpExts, static fn (string $e) => extension_loaded($e) === false);
    $probes['PHP'] = [
        'state'  => count($missing) === 0 ? 'ok' : 'warn',
        'label'  => PHP_VERSION,
        'detail' => count($missing) === 0
            ? 'All required extensions loaded'
            : 'Missing: ' . implode(', ', $missing),
    ];

    // 8. Maintenance flag
    $maintFlag = (string) (App::settings()['portal']['maintenance']['active'] ?? '0');
    $probes['Maintenance mode'] = [
        'state'  => $maintFlag === '1' ? 'warn' : 'ok',
        'label'  => $maintFlag === '1' ? 'ACTIVE' : 'Off',
        'detail' => $maintFlag === '1' ? 'Public access is gated' : 'Portal is open',
    ];

    // 9. Organisation keys (#515) — an organisation whose site key is the
    //    same as an address the portal itself relies on (a route prefix, a
    //    router special address, or a real file/folder in the web root —
    //    see Portal\Core\ReservedKeys for the full reasoning). Existing
    //    clashes are deliberately left working rather than renamed (the
    //    issue's own answer to "what happens to one that already has a
    //    reserved key"), so this probe is the warning that tells staff one
    //    exists, not a gate that stops anything.
    //
    //    THE LIGHT ONLY GOES AMBER WHEN THE CLASH IS DOING HARM RIGHT NOW —
    //    multi-site switched on, path-based address prefixes in use, AND at
    //    least one clashing organisation ACTIVE. In every other case (session
    //    or subdomain detection, multi-site off, or every clashing
    //    organisation switched off) the same key sitting in tblSites is
    //    harmless today — measured: in session mode a clashing "login" key
    //    changes nothing at all; an inactive one never matches at all — so
    //    this probe reports 'ok' with a count rather than alarming. A light
    //    that turns amber for something that is currently fine is exactly
    //    the "crying wolf" this project's own rule warns against: a check
    //    people learn to ignore ends up protecting nothing. The count is
    //    still shown either way, because it is useful even when nothing is
    //    on fire — an administrator can act on it before it becomes urgent.
    try {
        $clashes = \Portal\Core\ReservedKeys::clashingSites($db);
        $keysLive = \Portal\Core\ReservedKeys::keysAreAddresses();
        $activeClashes = array_filter($clashes, static fn (array $c): bool => $c['isActive'] === 1);

        if ($clashes === []) {
            $probes['Organisation keys'] = ['state' => 'ok', 'label' => 'None', 'detail' => 'No organisation key is reserved.'];
        } else {
            $detailParts = [];
            foreach ($clashes as $clash) {
                $inactiveNote = $clash['isActive'] === 0 ? ', inactive' : '';
                $detailParts[] = sprintf(
                    '#%d "%s" (%s%s): %s',
                    $clash['siteID'],
                    $clash['siteName'],
                    $clash['siteKey'],
                    $inactiveNote,
                    \Portal\Core\ReservedKeys::describe($clash['siteKey'], $clash['kinds'])
                );
            }

            if ($keysLive === true && $activeClashes !== []) {
                // ⚠️ Real harm, right now — name the key so staff know which
                //    address is actually unreachable.
                $firstKey = (string) reset($activeClashes)['siteKey'];
                $detailParts[] = 'Its pages are answering /' . $firstKey . '/ now, so the portal\'s own '
                    . 'address is unreachable for everyone. Change the key at Admin → Sites (Edit). '
                    . 'Links already shared under /' . $firstKey . '/ will stop working when it changes.';
                $probes['Organisation keys'] = [
                    'state'  => 'warn',
                    'label'  => sprintf('%d reserved', count($clashes)),
                    'detail' => implode(' · ', $detailParts),
                ];
            } else {
                // 😌 Not doing any harm today — reported, not alarmed.
                $detailParts[] = 'Harmless while the portal is not using address prefixes and the '
                    . 'organisation is off; it would take over the address the moment that changes. The '
                    . 'save page now refuses to switch such an organisation on. Change the key at '
                    . 'Admin → Sites (Edit).';
                $probes['Organisation keys'] = [
                    'state'  => 'ok',
                    'label'  => sprintf('%d reserved (not in use)', count($clashes)),
                    'detail' => implode(' · ', $detailParts),
                ];
            }
        }
    } catch (\Throwable $e) {
        $probes['Organisation keys'] = ['state' => 'warn', 'label' => 'Query failed', 'detail' => $e->getMessage()];
    }

    // 10. Stored secrets — can the portal read what it has stored? (#497)
    //     Secret settings are kept encrypted with the key file in _auth_keys.
    //     SecretSettings wrote down, as this request's settings were loaded,
    //     the name of every secret it could not deal with in the ordinary way:
    //       - CANNOT BE READ: it looks encrypted and the key file does not
    //         open it. Whatever uses it is handed a scramble and does not
    //         work. That is an incident, so it is red, and the monitor is
    //         told. (A readable secret that happens to be long base64 is
    //         counted here too, though it works: the two cannot be told
    //         apart. The detail is worded to be true of both, and entering
    //         the value again ends it either way.) If the bot check's keys are among them the detail says,
    //         in Captcha::brokenNotice()'s words, that sign-in is running
    //         without it: an administrator has to be told that somewhere they
    //         will see it, and this is the page uptime monitors read.
    //       - STORED AS READABLE TEXT: it works, but anybody who can read the
    //         database can read it. Amber. Upgrade encrypts these by itself;
    //         one still here afterwards could not be encrypted then.
    //     Names only, never a value.
    //
    //     THE KEY FILE ITSELF is named too, when SecretSettings::keyFileNote()
    //     has something to say about it: missing, empty, not a file, not
    //     readable, or SHORTER than the installer writes. A short file still
    //     works and nothing is stopped; it is amber so that somebody compares
    //     it with their backup copy.
    //
    //     WHAT THIS CANNOT SEE: a secret belonging to an organisation other
    //     than the one this request is for. Only the installation's own rows
    //     and the current organisation's are loaded.
    try {
        $unreadable  = \Portal\Core\SecretSettings::unreadable();
        $unprotected = \Portal\Core\SecretSettings::unprotected();
        $keyFileNote = \Portal\Core\SecretSettings::keyFileNote();
        $keyFileNote = $keyFileNote === '' ? '' : ' ' . $keyFileNote;
        if ($unreadable !== []) {
            $botCheck = \Portal\Core\Captcha::brokenNotice();
            $probes['Stored secrets'] = [
                'state'  => 'crit',
                'label'  => sprintf('%d cannot be read', count($unreadable)),
                'detail' => implode(', ', $unreadable) . '. Each looks encrypted, but this installation\'s key file does not open it. '
                    . 'Whatever uses it is handed that text exactly as stored, which will not work if it was encrypted with another key '
                    . 'file. Put the original key file (enc.key, in the _auth_keys folder) back, or enter each value again: the Settings '
                    . 'page names them.' . ($botCheck === '' ? '' : ' ' . $botCheck) . $keyFileNote,
            ];
        } elseif ($unprotected !== []) {
            $probes['Stored secrets'] = [
                'state'  => 'warn',
                'label'  => sprintf('%d not encrypted', count($unprotected)),
                'detail' => implode(', ', $unprotected) . '. Each still works, but is stored as readable text. Upgrading encrypts these '
                    . 'by itself when it can; otherwise enter each value again on the Settings page.' . $keyFileNote,
            ];
        } elseif ($keyFileNote !== '') {
            $probes['Stored secrets'] = ['state' => 'warn', 'label' => 'Key file needs a look', 'detail' => trim($keyFileNote)];
        } else {
            $probes['Stored secrets'] = ['state' => 'ok', 'label' => 'Readable', 'detail' => 'Every stored secret is encrypted and can be read.'];
        }
    } catch (\Throwable $e) {
        $probes['Stored secrets'] = ['state' => 'warn', 'label' => 'Could not be checked', 'detail' => get_class($e)];
    }

    // 🚦 Overall
    $overall = 'ok';
    foreach ($probes as $p) {
        if ($p['state'] === 'crit') {
            $overall = 'crit';
            break;
        }
        if ($p['state'] === 'warn') {
            $overall = 'warn';
        }
    }

    return ['overall' => $overall, 'probes' => $probes];
}
