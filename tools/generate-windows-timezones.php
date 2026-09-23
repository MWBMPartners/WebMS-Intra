<?php
// Path: tools/generate-windows-timezones.php
/**
 * -----------------------------------------------------------------------------
 * Build (or check) the Windows-to-standard time-zone list 🕰️🪟 (#514, part P5)
 * -----------------------------------------------------------------------------
 * Microsoft Outlook and Microsoft 365 do not write time zones the way the rest
 * of the world does. A calendar file from Google says
 * `DTSTART;TZID=Europe/London:...`. The same event exported from Microsoft 365
 * says `DTSTART;TZID="GMT Standard Time":...`. "GMT Standard Time" is a
 * Windows-only name; PHP's DateTimeZone has never heard of it, and an
 * unrecognised zone means every time in that calendar is read in the wrong
 * zone — silently, and often only wrong for half the year.
 *
 * So the portal needs a list that turns each Windows name into the ordinary
 * (IANA) name PHP understands. This script builds that list.
 *
 * WHY THE LIST IS GENERATED HERE AND THEN COMMITTED
 * -------------------------------------------------
 * The list comes from ICU, the internationalisation library behind PHP's
 * `intl` extension. `intl` is NOT installed on every shared-hosting account,
 * and this portal has to run where it is missing. Building the list at run
 * time was therefore rejected: on a host without `intl` every Microsoft
 * calendar would quietly fall back to the wrong zone.
 *
 * Instead the list is worked out once, here, on a machine that does have
 * `intl`, and written into `web/_core/WindowsTimeZones.php` as a plain PHP
 * array. At run time the portal only reads that array, so it needs nothing
 * beyond PHP itself.
 *
 * HOW THE LIST IS WORKED OUT
 * --------------------------
 * 1. Ask PHP for every time-zone name it knows, INCLUDING the older names it
 *    keeps for backward compatibility
 *    (`DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC)`).
 * 2. For each one, ask ICU which Windows name Microsoft would use
 *    (`IntlTimeZone::getWindowsID`). That collects the set of Windows names.
 * 3. For each Windows name, ask ICU for the one standard name that Windows
 *    name means in the "world" region, `001`
 *    (`IntlTimeZone::getIDForWindowsID`). A Windows name covers several
 *    countries — "GMT Standard Time" is the United Kingdom, Ireland,
 *    Portugal and more — and `001` is the one ICU itself calls the default.
 *
 * Step 3 is what makes the list usable: a Windows name has to come out as
 * exactly one standard name, because a calendar file gives us nothing else
 * to choose with.
 *
 * HOW TO USE IT
 * -------------
 *   php tools/generate-windows-timezones.php           rewrite the list
 *   php tools/generate-windows-timezones.php --check   compare, change nothing
 *
 * `--check` exits 0 when the committed list is exactly what this machine
 * would generate now, and 1 when it differs (it then prints every
 * difference). It is meant to be run by hand, or by a person reviewing a
 * change, when ICU has been updated.
 *
 * WHAT THIS SCRIPT CANNOT DO
 * --------------------------
 * - It cannot run without the `intl` extension. Without it there is nothing
 *   to generate and nothing to compare against, so BOTH modes stop with
 *   exit code 1 and say so. It deliberately does not exit 0 in that case:
 *   "no check was possible" must never look like "the check passed".
 * - It cannot tell you that the committed list is RIGHT — only that it
 *   matches this machine's ICU. A different ICU version can legitimately
 *   give a different answer (countries change their rules). When `--check`
 *   reports differences, read them: they are usually a real-world change,
 *   not a fault.
 * - It knows nothing about calendars. Reading a calendar file is
 *   `Portal\Core\IcsReader`; this script only builds a lookup list.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$targetFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core'
    . DIRECTORY_SEPARATOR . 'WindowsTimeZones.php';

$checkOnly = in_array('--check', array_slice($argv, 1), true);

// -----------------------------------------------------------------------------
// 🛑 No intl extension means no list can be built OR checked. Stop loudly.
// -----------------------------------------------------------------------------
if (extension_loaded('intl') !== true || class_exists('IntlTimeZone') !== true) {
    fwrite(STDERR, "This script needs the PHP 'intl' extension, which is not installed here.\n");
    fwrite(STDERR, "Nothing was generated and nothing was checked. Run it on a machine with intl.\n");
    exit(1);
}

// -----------------------------------------------------------------------------
// 🧮 Build the list from ICU
// -----------------------------------------------------------------------------
/** @var array<string,string> $map Windows name => ordinary (IANA) name. */
$map = [];
/** @var list<string> $problems Windows names ICU could not turn back into a standard name. */
$problems = [];

$windowsNames = [];
// ALL_WITH_BC, not ALL. `ALL` leaves out the older zone names PHP keeps only
// for backward compatibility, and a few Windows names are reachable ONLY
// through one of those. This used to say `ALL`, and the list it produced was
// missing `Dateline Standard Time` (the one zone it names, `Etc/GMT+12`, is a
// backward-compatibility name) — a Microsoft calendar written in that zone
// would have fallen back to the wrong zone with a warning. Nothing caught it,
// because `--check` below enumerates exactly the same way, so it compared a
// short list against a short list and agreed.
//
// Widening the enumeration can only ADD Windows names, never change one that
// is already there: the standard name each Windows name maps to comes from
// `getIDForWindowsID()` in the next loop, which is asked about the Windows
// name alone and knows nothing about how the name was discovered. Measured on
// this machine (ICU 78.3): 138 names with `ALL`, 139 with `ALL_WITH_BC`, the
// one addition being `Dateline Standard Time`, and no value different.
foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC) as $ianaId) {
    $windowsName = IntlTimeZone::getWindowsID($ianaId);
    if (is_string($windowsName) === true && $windowsName !== '') {
        $windowsNames[$windowsName] = true;
    }
}

foreach (array_keys($windowsNames) as $windowsName) {
    $ianaId = IntlTimeZone::getIDForWindowsID($windowsName, '001');
    if (is_string($ianaId) === false || $ianaId === '') {
        // ICU knows the Windows name but has no "world" default for it. Such a
        // name is left out rather than guessed: guessing would put every event
        // in that calendar in a zone nobody chose.
        $problems[] = $windowsName;
        continue;
    }
    $map[$windowsName] = $ianaId;
}

// Sorted by the Windows name so that two runs of this script produce the same
// file, and so a change in the committed file is readable in a diff.
ksort($map, SORT_STRING);

$icuVersion = defined('INTL_ICU_VERSION') === true ? (string) INTL_ICU_VERSION : 'unknown';

if ($problems !== []) {
    fwrite(STDERR, 'Note: ICU gave no "world" default for ' . count($problems) . ' Windows name(s): '
        . implode(', ', $problems) . "\n");
}

// -----------------------------------------------------------------------------
// 🔍 --check: compare with the committed file and change nothing
// -----------------------------------------------------------------------------
if ($checkOnly === true) {
    if (is_file($targetFile) !== true) {
        fwrite(STDERR, 'The committed list is missing: ' . $targetFile . "\n");
        exit(1);
    }
    require_once $targetFile;
    if (class_exists('Portal\\Core\\WindowsTimeZones') !== true) {
        fwrite(STDERR, "The committed file does not define Portal\\Core\\WindowsTimeZones.\n");
        exit(1);
    }
    /** @var array<string,string> $committed */
    $committed = Portal\Core\WindowsTimeZones::MAP;
    ksort($committed, SORT_STRING);

    $differences = [];
    foreach ($map as $windowsName => $ianaId) {
        if (array_key_exists($windowsName, $committed) === false) {
            $differences[] = 'missing from the committed list: ' . $windowsName . ' => ' . $ianaId;
            continue;
        }
        if ($committed[$windowsName] !== $ianaId) {
            $differences[] = 'different: ' . $windowsName . ' is committed as ' . $committed[$windowsName]
                . ' but this machine says ' . $ianaId;
        }
    }
    foreach ($committed as $windowsName => $ianaId) {
        if (array_key_exists($windowsName, $map) === false) {
            $differences[] = 'in the committed list but not on this machine: ' . $windowsName . ' => ' . $ianaId;
        }
    }

    if ($differences !== []) {
        fwrite(STDERR, 'The committed list does NOT match this machine (ICU ' . $icuVersion . "):\n");
        foreach ($differences as $line) {
            fwrite(STDERR, '  - ' . $line . "\n");
        }
        fwrite(STDERR, "Re-run without --check to rewrite it, then read the change before committing.\n");
        exit(1);
    }

    echo 'The committed list matches this machine exactly: ' . count($map) . ' Windows names (ICU '
        . $icuVersion . ").\n";
    exit(0);
}

// -----------------------------------------------------------------------------
// ✍️ Write the file
// -----------------------------------------------------------------------------
$entries = '';
foreach ($map as $windowsName => $ianaId) {
    $entries .= "        '" . str_replace("'", "\\'", $windowsName) . "' => '"
        . str_replace("'", "\\'", $ianaId) . "',\n";
}

$generatedOn = date('j F Y');
$count       = count($map);

$fileText = <<<PHP_TEMPLATE
<?php
// Path: _core/WindowsTimeZones.php
/**
 * -----------------------------------------------------------------------------
 * Windows time-zone names, turned into ordinary ones 🕰️🪟 (#514, part P5)
 * -----------------------------------------------------------------------------
 * THIS FILE IS GENERATED. Do not edit the list by hand.
 * It is written by `tools/generate-windows-timezones.php`; run that script
 * again to rebuild it, and `--check` to see whether it is still current.
 *
 * Generated on {$generatedOn} from ICU version {$icuVersion}
 * ({$count} Windows names).
 *
 * WHY IT EXISTS
 * -------------
 * A calendar exported from Microsoft Outlook or Microsoft 365 writes its time
 * zones with Windows' own names: `DTSTART;TZID="GMT Standard Time"` where
 * Google would write `DTSTART;TZID=Europe/London`. PHP only understands the
 * ordinary names, so without this list every Microsoft calendar would be read
 * in the wrong zone — and, because "GMT Standard Time" happens to look right
 * in winter, wrong in a way that only shows up for half the year.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 * - It does not cover Windows names invented by one organisation (Outlook can
 *   write `Customized Time Zone` for a zone somebody made up). Those are not
 *   in ICU, so `toIana()` answers null and the caller falls back to the
 *   calendar's own zone and records a warning. That is deliberate: a made-up
 *   name cannot be guessed at safely.
 * - One Windows name covers several countries ("GMT Standard Time" is the
 *   United Kingdom, Ireland, Portugal and others). The answer here is the one
 *   ICU itself calls the default for that name. A calendar file gives us
 *   nothing else to choose with, so there is no better answer available.
 * - It is a lookup list and nothing more. It never reads a calendar, and it
 *   never decides what a time means; `Portal\\Core\\IcsReader` does that.
 *
 * @package   Portal\\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\\Core;

final class WindowsTimeZones
{
    /**
     * Windows time-zone name => ordinary (IANA) time-zone name.
     *
     * Generated; see the file header. Sorted by the Windows name so that a
     * change to this list is readable in a diff.
     *
     * @var array<string,string>
     */
    public const MAP = [
{$entries}    ];

    /**
     * Turn a Windows time-zone name into the ordinary name PHP understands.
     *
     * Returns null when the name is not a Windows name we know. The caller
     * must then do something safe — the calendar reader falls back to the
     * calendar's own zone and records a warning — and must NOT treat null as
     * "UTC", which would move every event in that calendar by the offset of
     * whatever zone was really meant.
     *
     * Matching is done first exactly, then ignoring capital letters. Microsoft
     * writes these names in a fixed spelling, but a file that has been through
     * another program on the way can arrive with different capitals, and
     * refusing it for that alone would help nobody.
     *
     * What this cannot do: it cannot recognise a name that is not in the list
     * at all (see the file header), and it does not accept an ordinary IANA
     * name — pass those straight to DateTimeZone instead.
     */
    public static function toIana(string \$windowsId): ?string
    {
        \$name = trim(\$windowsId);
        if (\$name === '') {
            return null;
        }

        if (isset(self::MAP[\$name]) === true) {
            return self::MAP[\$name];
        }

        // Built once per request, and only when an exact match failed, so the
        // ordinary case costs nothing.
        static \$lowerIndex = null;
        if (\$lowerIndex === null) {
            \$lowerIndex = [];
            foreach (self::MAP as \$windowsName => \$ianaId) {
                \$lowerIndex[strtolower(\$windowsName)] = \$ianaId;
            }
        }

        return \$lowerIndex[strtolower(\$name)] ?? null;
    }
}

PHP_TEMPLATE;

$written = file_put_contents($targetFile, $fileText);
if ($written === false) {
    fwrite(STDERR, 'Could not write ' . $targetFile . "\n");
    exit(1);
}

echo 'Wrote ' . $targetFile . ': ' . $count . ' Windows names (ICU ' . $icuVersion . ").\n";
exit(0);
