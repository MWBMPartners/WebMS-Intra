<?php
// Path: tools/offsite-backup/read-offsite-settings.php
/**
 * -----------------------------------------------------------------------------
 * Off-site backup — hand the portal's own settings to the sync script 📤
 * -----------------------------------------------------------------------------
 * Called from sync-offsite.sh, once, before anything is packed. It prints the
 * six `backup.offsite.*` settings from the portal's database in a form the
 * script can read, so that the boxes on /admin/maintenance/offsite-backup
 * really decide what the scheduled copy does.
 *
 * WHY THIS FILE EXISTS AT ALL (#671)
 * -----------------------------------------------------------------------------
 * Until 30 October 2026 the script read NONE of those settings: it used the
 * variables written into its own copy on the server, and said so in its
 * header. So an administrator could set a destination, an rclone remote, how
 * many copies to keep and an alert address, press Save, be told "Off-site
 * backup settings saved." — and nothing anywhere acted on any of it, on any
 * installation, with or without a command line. Six settings were seeded and
 * read by nothing in the whole repository. The owner chose, on 30 October
 * 2026, to make them real rather than to delete them.
 *
 * WHY A SEPARATE PROGRAM AND NOT THE SHELL
 * -----------------------------------------------------------------------------
 * A shell script cannot read the portal's database without a client program
 * and the password, and `mysql` is not on every host. The portal already
 * bridges the other way for the same reason — log-offsite-result.php writes
 * the run's result — so this follows that shape exactly, and the two files are
 * copied to the server together.
 *
 * THE SAFETY RULE THAT MATTERS MOST, AND IT IS NOT OBVIOUS
 * -----------------------------------------------------------------------------
 * Every value printed here ends up in a shell script, and three of them end up
 * in a command line (the rclone remote, the alert address, the destination).
 * A settings box is free text. So anything printed from here MUST be safe to
 * put in a shell: this file is the boundary, and it refuses rather than
 * escapes. A value that does not match its pattern is NOT printed at all and a
 * WARN line is printed instead, which leaves the script using the value in its
 * own file. Nothing is ever "cleaned up" and passed on, because a cleaned-up
 * destination is a destination nobody chose.
 *
 * Because every printed value is checked against a pattern with no newline, no
 * space, no quote and no shell character in it, the `KEY=value` format below
 * cannot be broken by stored text, and the script never needs `eval` to read
 * it. The script checks each value again on its side: see "Read the portal's
 * settings" in sync-offsite.sh.
 *
 * WHAT IT CANNOT DO
 * -----------------------------------------------------------------------------
 *   - It cannot tell whether the address of an S3 bucket or an SFTP host is
 *     right, and it does not supply them: there is no box for either on the
 *     admin page, so those two stay in the script's own file (#688).
 *   - It cannot tell whether an rclone remote exists. Only rclone knows that.
 *   - It says nothing about whether the copy was sent. That is the other
 *     bridge, log-offsite-result.php.
 *   - It reads the INSTALLATION's rows only. There is one off-site copy for
 *     the whole installation, so there is nothing per-organisation to read.
 *
 * @package   Portal\Backups
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/671
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// 🚪 Never over the web. This prints an installation's backup destination and
//    an alert address, and it is copied into _backups/, which is outside the
//    web root — but the same guard is on log-offsite-result.php beside it, and
//    a file is only outside the web root until somebody moves it.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// 📂 Find the portal's own folder, the one holding _core.
//
//    THIS IS WHY log-offsite-result.php DID NOT WORK ON A REAL SERVER (#671).
//    That file requires '../../web/_core/bootstrap.php', which is only right
//    when the portal's folder is literally named "web". It is in a checkout of
//    the repository, which is where it was tested on 12 October 2026, and it
//    is NOT on a customer's server: the deploy mirrors the CONTENTS of web/
//    into a folder whose name the customer chooses (the SFTP_PATH_… secrets in
//    .github/workflows/deploy.yml). So on a real installation the recorder
//    could not load the portal, every run failed to be recorded, and
//    /admin/maintenance/offsite-backup showed "No runs logged yet" however
//    many copies had really been sent. Measured, not reasoned: a folder named
//    portal-live resolves to <parent>/web/_core/bootstrap.php, which does not
//    exist.
//
//    So both files now look for the portal rather than assuming its name. The
//    two places either file can be:
//      - copied to <portal>/_backups/        → the portal is one level up
//      - run from tools/offsite-backup/      → the portal is ../../web
$portalCandidates = [
    __DIR__ . DIRECTORY_SEPARATOR . '..',
    __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'web',
];
$bootstrap = '';
foreach ($portalCandidates as $candidate) {
    $try = $candidate . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';
    if (is_file($try) === true && is_readable($try) === true) {
        $bootstrap = $try;
        break;
    }
}
if ($bootstrap === '') {
    // ⚠️ Printed on the error channel and nothing on the normal one, so the
    //    script reading this cannot mistake the message for a setting.
    fwrite(STDERR, "ERROR: cannot find the portal's _core/bootstrap.php from " . __DIR__ . "\n");
    exit(2);
}

require $bootstrap;

use Portal\Core\InstallationSettings;
use Portal\Core\OffsiteSettings;

// 🏢 The installation's own rows, never the merged array.
//
//    An off-site copy is one copy of one database for every organisation on
//    the installation, so the settings are installation-wide rows (siteID
//    NULL) and the page saves them as such. layerOrNull() answers null when
//    the layer is not known at all — which is not the same as "no settings
//    stored" and must not be read as empty. Without that distinction a portal
//    whose settings could not be loaded would print nothing, no warning, and
//    the script would quietly use the values in its own file. That is the
//    exact shape this whole change exists to remove.
$layer = InstallationSettings::layerOrNull();
if ($layer === null) {
    fwrite(STDERR, "ERROR: the installation's settings could not be read.\n");
    exit(3);
}

$stored = $layer['backup']['offsite'] ?? [];
if (is_array($stored) === false) {
    $stored = [];
}

// 📏 Nothing absurdly long is even looked at.
//
//    WHY THIS EXISTS, because it is not obvious and it was measured (#671).
//    The settings boxes have no length limit — `settingValue` is MEDIUMTEXT
//    and the page stores what it is given — and the rclone-remote pattern
//    below happily matches 200,000 letters followed by a colon. The script
//    reads this output with a shell loop, and a value of about that size
//    OVERFLOWED THE PIPE it reads the first line through: bash printed
//    "printf: write error: Broken pipe" and, under `set -o pipefail`, the
//    whole run stopped before anything was packed — reporting "A step failed
//    near line …", which named the wrong line and said nothing about the
//    setting that caused it. Every scheduled run then failed the same way
//    until somebody edited the box.
//
//    The script no longer reads that line through a pipe, but do NOT read that
//    as "so this guard is spare" — an earlier version of this comment said
//    exactly that and it is false. Measured by raising the ceiling and leaving
//    the script's fix in place: the run then gets past the settings step and
//    rclone refuses the 200,000-character remote instead, so the backup still
//    does not happen. The two guards stop different things, and this is the
//    only one that keeps a copy going out.
//
//    256 characters is far more than any real value needs: an rclone remote
//    is a short name and a path, and an e-mail address has a 254-character
//    ceiling of its own. A value over that is held back like any other
//    value this file will not pass on, with a warning naming the setting.
//    It is MARKED here and acted on in each block below, rather than being
//    blanked here. Blanking was written first and was wrong: an empty alert
//    address is a REAL choice meaning "do not email", so emptying an
//    over-long one would turn "we cannot use this" into a decision the
//    administrator never made — the exact thing the house rules forbid. Each
//    value's own block knows what "leave it out" means for that value.
$tooLong = [];
foreach ($stored as $shortKey => $storedValue) {
    if (is_string($storedValue) === true && strlen($storedValue) > OffsiteSettings::MAX_LENGTH) {
        $tooLong[(string) $shortKey] = strlen($storedValue);
    }
}

/**
 * Whether this setting was held back for sheer length, warning once if so.
 *
 * @param array<string, int> $tooLong
 * @param string[]           $warn
 */
function offsite_too_long(array $tooLong, string $shortKey, string $describe, array &$warn): bool
{
    if (array_key_exists($shortKey, $tooLong) === false) {
        return false;
    }
    $warn[] = 'the stored ' . $describe . ' is ' . $tooLong[$shortKey]
        . ' characters long, which is far longer than any real value, so the one in this script file is used instead';
    return true;
}

// 📝 What is printed, and what is held back.
//
//    $out  — the settings that passed, printed as KEY=value for the script.
//    $warn — one line per value held back or brought into range. These are
//            printed too: a value silently dropped is how somebody comes to
//            believe a box is in force when it is not, which is the fault this
//            whole file is fixing.
$out  = [];
$warn = [];

// 🔘 Enabled. The one value with no safe "keep what the file says" answer, so
//    it is always printed: 1 only for exactly '1', 0 for anything else,
//    including a missing row. The script refuses to send when it is 0 and
//    records the run as skipped.
$out['ENABLED'] = ((string) ($stored['enabled'] ?? '0')) === '1' ? '1' : '0';

// 🎯 Destination. One of three words, and the script has a case for each. A
//    stored value that is not one of them is held back rather than passed on:
//    the script's case statement would refuse an unknown word anyway, but
//    refusing here means the run carries on with the destination in the
//    script's file instead of stopping, and says why.
$destination = (string) ($stored['destination'] ?? '');
if (offsite_too_long($tooLong, 'destination', 'destination', $warn) === true) {
    $destination = '';
} elseif (OffsiteSettings::destinationIsKnown($destination) === true) {
    $out['DESTINATION'] = $destination;
} elseif ($destination !== '') {
    $warn[] = 'the stored destination "' . offsite_shorten($destination) . '" is not one of rclone, s3 or sftp, so the destination in this script file is used instead';
}

// ☁️ The rclone remote. This goes straight into an rclone command line, so it
//    is the value most worth refusing on.
//
//    An rclone remote is a name, a colon, then an optional path —
//    "b2:portal-backups". The pattern allows exactly the characters that need
//    to be in one and nothing else: no space, no quote, no backtick, no
//    dollar, no semicolon, no newline. A value that starts with a dash is
//    refused separately, because rclone would read it as an option rather than
//    a remote.
//
//    Not escaped and passed on: an escaped destination is still a destination
//    nobody chose, and a copy of the whole database going somewhere unintended
//    is the worst outcome this feature has.
$remote = trim((string) ($stored['rcloneRemote'] ?? ''));
if (offsite_too_long($tooLong, 'rcloneRemote', 'rclone remote', $warn) === true) {
    $remote = '';
}
if ($remote !== '') {
    // ONE question, asked of Portal\Core\OffsiteSettings, which the admin page
    // asks too — so the page can warn at the moment somebody types a remote
    // this will refuse, instead of the refusal first showing up in a run's log
    // a week later (#671).
    $whyNot = OffsiteSettings::whyRemoteUnusable($remote);
    if ($whyNot !== '') {
        $warn[] = 'the stored rclone remote cannot be used because ' . $whyNot
            . ', so the remote in this script file is used instead';
    } else {
        $out['RCLONE_REMOTE'] = $remote;
    }
}

// 🗂️ How many copies to keep. These two only decide how many old copies are
//    deleted at the destination, so a figure out of range is brought into
//    range with a warning rather than refused: refusing would cost the
//    installation its backup over a tidying-up detail. A figure that is not a
//    whole number at all is held back, because "0 copies" and "not a number"
//    are different things and guessing between them is how a destination ends
//    up emptied.
//
//    The ranges are the same ones the admin page allows, so a figure outside
//    them can only have come from SQL by hand, or from a restored database.
//    The page clamping on save is not a cap: this is the reader, and a reader
//    that trusts a stored figure is how #668 went wrong.
// An over-long keep figure cannot be a whole number, so offsite_keep_number()
// would hold it back anyway — but it would quote 256 characters of nonsense in
// its warning, so the length is reported first and the value never looked at.
$out['KEEP_WEEKLY']  = offsite_too_long($tooLong, 'keepWeekly', 'number of weekly copies to keep', $warn) === true
    ? '' : offsite_keep_number($stored['keepWeekly'] ?? null, OffsiteSettings::KEEP_WEEKLY_MIN, OffsiteSettings::KEEP_WEEKLY_MAX, 'weekly', $warn);
$out['KEEP_MONTHLY'] = offsite_too_long($tooLong, 'keepMonthly', 'number of monthly copies to keep', $warn) === true
    ? '' : offsite_keep_number($stored['keepMonthly'] ?? null, OffsiteSettings::KEEP_MONTHLY_MIN, OffsiteSettings::KEEP_MONTHLY_MAX, 'monthly', $warn);
foreach (['KEEP_WEEKLY', 'KEEP_MONTHLY'] as $keepKey) {
    if ($out[$keepKey] === '') {
        unset($out[$keepKey]);
    }
}

// ✉️ The alert address. It becomes an argument of the server's "mail" program.
//
//    Note, because a reader would otherwise assume the length check above is
//    what holds a very long address back: it is NOT the only thing. An address
//    over about 64 characters before the "@" is refused by filter_var anyway,
//    so the length branch here is belt and braces. That was measured, not
//    assumed — raising MAX_LENGTH does not make a 300-character address
//    acceptable.
//    An address starting with a dash would be read as an option, and anything
//    with a space or a shell character in it has no business being an address,
//    so both are refused. An EMPTY stored address is printed as empty on
//    purpose: that is a real choice meaning "do not email", and it has to be
//    able to override an address left in the script's file.
$alert = trim((string) ($stored['alertEmail'] ?? ''));
if (offsite_too_long($tooLong, 'alertEmail', 'alert address', $warn) === true) {
    // Deliberately NOT printed as empty: empty means "do not email", which is
    // a choice, and this is "we cannot use what is stored".
    $alert = null;
}
if ($alert === null) {
    // nothing printed; the script keeps the address in its own file
} elseif ($alert === '') {
    $out['ALERT_EMAIL'] = '';
} elseif (OffsiteSettings::whyAlertUnusable($alert) !== '') {
    // Two tests, not one, and the narrow one decides.
    //
    // filter_var accepts forms that are valid addresses and still awkward to
    // pass to a program: a quoted local part may hold a space, and the marks
    // an address is allowed to use include the backtick and the dollar. None
    // of those is actually dangerous here, because bash does not re-read the
    // CONTENT of a variable it has expanded — that was checked rather than
    // assumed — but the pattern is kept narrow anyway so that this file and
    // the matching test in sync-offsite.sh can be read side by side and seen
    // to agree. Two guards that differ are worse than one.
    //
    // So: an address refused here is not necessarily a bad address. It is one
    // this bridge will not put on a command line, and the script then uses the
    // address in its own file and says so.
    $warn[] = 'the stored alert address cannot be used because '
        . OffsiteSettings::whyAlertUnusable($alert) . ', so the address in this script file is used instead';
} else {
    $out['ALERT_EMAIL'] = $alert;
}

// 🖨️ Print it.
//
//    The first line names the format and its number, so the script can refuse
//    output it does not understand rather than half-reading it, and so a
//    future change to this file can be told apart from this one.
//
//    A WARN line can hold anything, including an "=", so it is marked at the
//    start of the line and the script ignores every line that is not
//    KEY=value. Nothing printed below can hold a newline: every value was
//    checked against a pattern that has none, and the two keep figures are
//    whole numbers.
echo "# OFFSITE-SETTINGS 1\n";
foreach ($warn as $line) {
    echo 'WARN: ' . $line . "\n";
}
foreach ($out as $key => $value) {
    echo $key . '=' . $value . "\n";
}
exit(0);

/**
 * One of the two "how many copies to keep" figures, brought into range.
 *
 * Returns the figure as text, or an empty string when the stored value is not
 * a whole number at all — the caller then leaves the key out entirely and the
 * script keeps the figure in its own file.
 *
 * @param mixed    $raw   Exactly as stored.
 * @param int      $min   Lowest the admin page allows.
 * @param int      $max   Highest the admin page allows.
 * @param string   $which The word for this one in a warning.
 * @param string[] $warn  Collected warnings, added to in place.
 */
function offsite_keep_number(mixed $raw, int $min, int $max, string $which, array &$warn): string
{
    $text = trim((string) ($raw ?? ''));
    if ($text === '') {
        // No row at all. Not a fault and not worth a warning: the script's own
        // figure stands, exactly as it did before this change.
        return '';
    }
    // 🔢 Whole digits only, so "8.5", "-4", "1e3" and " 8" are all refused
    //    rather than rounded or part-read.
    //
    //    A LEADING NOUGHT IS ACCEPTED, AND DELIBERATELY SO. "08" is read as
    //    eight and printed back as "8". An earlier version of this comment
    //    claimed it was refused, and the code never did — the self-test caught
    //    the comment, not the code. Accepting it is right, for a reason worth
    //    writing down: the script's own note records that a leading nought
    //    once made bash read "08" as base eight and skip the whole tidy-up
    //    while still reporting success. That cannot happen through this
    //    bridge, because what is printed here is always a canonical decimal
    //    with no leading nought, and the script reads it with an explicit
    //    "10#" prefix as well. So the danger that made "08" worth refusing is
    //    gone, and refusing it would throw away a figure whose meaning is
    //    perfectly clear.
    if (preg_match('/^[0-9]+$/', $text) !== 1) {
        $warn[] = 'the stored number of ' . $which . ' copies to keep ("' . offsite_shorten($text) . '") is not a whole number, so the figure in this script file is used instead';
        return '';
    }
    $number = (int) $text;
    if ($number < $min) {
        $warn[] = 'the stored number of ' . $which . ' copies to keep (' . $number . ') is below ' . $min . ', so ' . $min . ' was used';
        return (string) $min;
    }
    if ($number > $max) {
        $warn[] = 'the stored number of ' . $which . ' copies to keep (' . $number . ') is above ' . $max . ', so ' . $max . ' was used';
        return (string) $max;
    }
    return (string) $number;
}

/**
 * A stored value cut short and stripped of anything awkward, for a warning.
 *
 * A warning line is printed, kept in the run's log and read by a person, so
 * the value that caused it is worth showing — but it is the untrusted text
 * this file exists to hold back, so it never travels whole. Everything but
 * letters, digits and a few marks becomes a dot, which keeps a newline or a
 * quote out of the line the script reads.
 */
function offsite_shorten(string $value): string
{
    $safe = preg_replace('/[^A-Za-z0-9_.:@\/-]/', '.', $value);
    if (is_string($safe) === false) {
        return '(unprintable)';
    }
    return mb_strimwidth($safe, 0, 60, '…');
}
