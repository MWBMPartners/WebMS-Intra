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
 * WHY EVERY ANSWER IS ALSO PASSED THROUGH getIanaID() (issue #557)
 * -----------------------------------------------------------------
 * `getIDForWindowsID()` above is ICU's PREFERRED name for a Windows zone,
 * and for seven zones that preferred name is an OLD spelling. That is NOT
 * because ICU has moved on from it — ICU's OWN preferred name for
 * "Kolkata" is still `Asia/Calcutta` (`IntlTimeZone::getCanonicalID()`
 * says so, checked round 1 of #557's independent review, 25 September
 * 2026). It is the IANA time-zone database — the one ICU is built from —
 * that has moved on, and `IntlTimeZone::getIanaID()` is the method that
 * reports IANA's CURRENT name rather than ICU's own preferred one. The
 * seven affected: `Asia/Calcutta`, `Asia/Katmandu`, `Asia/Rangoon`,
 * `Europe/Kiev`, `America/Buenos_Aires`, `America/Godthab` and
 * `America/Indianapolis`. System zone data built without the optional
 * `tzdata-legacy` package (Ubuntu 24.04's own `tzdata` package is one such
 * build) does not carry those old names, so `new
 * DateTimeZone('Asia/Calcutta')` throws there even though the zone itself
 * is perfectly ordinary — it is only the SPELLING that is out of date.
 * Writing the old spelling into the committed list would NOT lose the
 * event's time zone silently — `Portal\Core\IcsReader` records a warning
 * when a zone name does not resolve — but the event's TIME would still
 * come out wrong: the reader falls back to the calendar's own zone
 * instead of the zone the event actually names. A 19:00 "India Standard
 * Time" event, read on a server without `tzdata-legacy`, would come out
 * as 19:00 in the calendar's own zone, not 13:30 there, which is what
 * 19:00 in India actually is. So every zone `getIDForWindowsID()` returns
 * is passed straight on to `IntlTimeZone::getIanaID()`, ICU's own answer
 * to "what is the CURRENT (IANA) name for this zone" — never a
 * hand-written substitution table, which would need updating by hand
 * every time a country changes its rules.
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
 * - `IntlTimeZone::getIanaID()` needs PHP 8.4 or newer built with ICU 74 or
 *   newer. This script relies on the GENERATING machine's ICU being new
 *   enough to know the current name for every zone it is asked about;
 *   without that it refuses outright rather than fall back to an old
 *   spelling — checked once, near the top of this script, before the loop
 *   that builds the list even starts (moved there in round 1 of #557's own
 *   independent check, so the refusal never names one zone in particular
 *   as if only that zone were affected). The version actually used to
 *   generate the committed file is recorded in that file's own header,
 *   because that is the fact that matters, not whatever machine happens to
 *   run this comment next.
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

// Used in every refusal message below, so the wording always says what this
// run actually is: `--check` only READS the committed list and compares, the
// plain run WRITES it.
$verb = $checkOnly === true ? 'check' : 'generate';

// -----------------------------------------------------------------------------
// 🛑 #557 round 1 (LOW-5): getIanaID() is a MACHINE capability, not a
// per-zone one — checked ONCE, here, before any Windows name is looked at.
// -----------------------------------------------------------------------------
// This used to be checked inside the per-Windows-name loop below, the first
// time a rename was attempted, and the refusal named THAT zone as if it in
// particular had an old spelling — "'{$windowsName}' ... could only be
// written using ICU's old spelling". That was misleading: the zone the loop
// happens to reach first might have no old spelling at all. The truth is
// simpler and applies to every zone equally: without this method, THIS
// MACHINE cannot tell a current spelling from an old one for ANY of them,
// so there is no point starting the loop at all.
if (method_exists('IntlTimeZone', 'getIanaID') === false) {
    fwrite(STDERR, "Refusing to {$verb} the list: this machine's intl extension has no\n");
    fwrite(STDERR, "IntlTimeZone::getIanaID() method (it needs PHP 8.4 or newer built with ICU\n");
    fwrite(STDERR, "74 or newer).\n");
    fwrite(STDERR, "Without it, this machine cannot confirm the CURRENT spelling of any\n");
    fwrite(STDERR, "Windows zone — some of ICU's preferred names are old spellings (#557),\n");
    fwrite(STDERR, "and this machine has no way to tell which. Run it on a newer machine\n");
    fwrite(STDERR, "instead.\n");
    exit(1);
}

/**
 * #557: answers 'same' when ICU's OWN identity check says two zone names
 * are the SAME zone — a pure rename — 'different' when ICU says they are two
 * different zones, and 'unknown' when ICU does not recognise one of them. `IntlTimeZone::getCanonicalID()` is
 * ICU's single, authoritative answer to "which zone does this name belong
 * to", and it is what this guard trusts: two names that canonicalise to the
 * SAME answer are the same zone, by ICU's own definition; two that
 * canonicalise to DIFFERENT answers are different zones, and the caller
 * refuses. Checked by hand on ICU 78.3 by the commissioning session for
 * round 2 of this fix's own independent check, 25 September 2026:
 * `America/Buenos_Aires` and `America/Argentina/Buenos_Aires` both
 * canonicalise to `America/Buenos_Aires`; `Asia/Calcutta` and
 * `Asia/Kolkata` both canonicalise to `Asia/Calcutta`; `Europe/Oslo` and
 * `Europe/Berlin` canonicalise to themselves — two different zones, even
 * though (see below) they have shared an identical offset history since
 * 1970.
 *
 * THIS USED TO ASK THIS MACHINE'S OWN PHP INSTEAD — comparing every
 * UTC-offset transition `DateTimeZone::getTransitions()` reports between
 * 1970 and 2100 — and treated "this machine's PHP cannot even load the old
 * name" the same as "these are different zones". Round 2 of this fix's own
 * independent check found that was wrong: on a server built without the
 * optional `tzdata-legacy` package (Ubuntu 24.04's own `tzdata` package is
 * one), PHP cannot load ANY of the seven #557 old spellings, so the old
 * guard refused every one of them — including the pure rename
 * `America/Buenos_Aires` -> `America/Argentina/Buenos_Aires` — with a
 * message that said "genuinely different zone" about a machine that had
 * done nothing wrong. Reproduced on Ubuntu 24.04 / PHP 8.4.26 / ICU 74.2
 * without `tzdata-legacy`.
 *
 * The offset-history comparison is kept below, but only as an EXTRA check,
 * run when this machine's PHP can load BOTH names — it can no longer be
 * the thing that decides accept or refuse. A rename ICU's own identity
 * check has already accepted is never refused because of it; a name this
 * machine's PHP cannot load is simply left uncompared by this second pass,
 * with a one-line note, rather than read as "different zone" the way it
 * used to be.
 *
 * WHAT THIS CANNOT SEE: `getCanonicalID()` reports ICU's OWN idea of which
 * zone a name belongs to. That is close to IANA's, but not identical: ICU
 * keeps some zones separate that IANA's main data now merges (for example
 * `Europe/Oslo` and `Europe/Berlin`), and it treats a renamed zone as one
 * zone. IANA's own rule for merging two zones is the same civil-time
 * history from 1970 onward, so two names that differ only before 1970 can
 * be treated as one zone, and this guard accepts that; it cannot, and does
 * not try to, second-guess it. It also cannot see
 * a real-world rule change that has not reached THIS machine's ICU tables
 * yet — either way it can only report what this machine's ICU currently
 * believes.
 */
function windowsTimeZonesRenameVerdict(string $oldName, string $newName): string
{
    // Three possible answers, so the caller can say exactly what happened:
    //   'same'      — ICU says both names belong to one zone (a pure rename);
    //   'different' — ICU says they belong to two different zones;
    //   'unknown'   — ICU does not recognise one of the names at all.
    // Both 'different' and 'unknown' make the caller refuse. They are kept
    // apart only so the refusal message never claims ICU said "different"
    // when what it really said was "I do not know this name".
    $oldCanonical = IntlTimeZone::getCanonicalID($oldName);
    $newCanonical = IntlTimeZone::getCanonicalID($newName);
    if (is_string($oldCanonical) === false || is_string($newCanonical) === false
        || $oldCanonical === '' || $newCanonical === ''
    ) {
        return 'unknown';
    }
    if ($oldCanonical !== $newCanonical) {
        // ICU's own identity check says these are different zones. This is
        // the ONLY thing that can refuse a rename ICU recognises; nothing
        // below this point can override it.
        return 'different';
    }

    // ---- Extra check only, from here on: it can never REFUSE something
    // the identity check above has already accepted. Kept for the added
    // confidence of a second, independent comparison — this machine's own
    // PHP zone data, transition by transition — but a name this machine's
    // PHP cannot load is common and expected (see the file header) and
    // must never, on its own, be read as "different zone".
    try {
        $oldZone = new DateTimeZone($oldName);
        $newZone = new DateTimeZone($newName);
    } catch (Exception $e) {
        fwrite(STDERR, "Note: this machine's PHP could not load '{$oldName}' to run the extra\n");
        fwrite(STDERR, "offset-history check on it; ICU's own identity check above already\n");
        fwrite(STDERR, "confirms it is the same zone as '{$newName}'.\n");
        return 'same';
    }

    // 1970 to 2100 comfortably covers every date this portal's calendars
    // are ever likely to hold. A genuinely fixed-offset zone such as
    // `Etc/GMT+5` never observes daylight saving and still returns one
    // transition at the start of the range, so the comparison below works
    // for it without a special case. A three-letter ABBREVIATION such as
    // `CET` is a different case again: PHP treats it as an alias with no
    // transition data of its own, so `getTransitions()` returns `false` for
    // it — handled below as "could not compare", never as a mismatch. (No
    // Windows default resolves to an abbreviation like this, and `CET`
    // itself observes daylight saving in ICU's own tables — it is not a
    // fixed-offset zone at all — so this is a defensive case, not one this
    // script expects to hit.)
    $begin = (new DateTimeImmutable('1970-01-01T00:00:00Z'))->getTimestamp();
    $end   = (new DateTimeImmutable('2100-01-01T00:00:00Z'))->getTimestamp();

    $oldTransitions = $oldZone->getTransitions($begin, $end);
    $newTransitions = $newZone->getTransitions($begin, $end);

    if ($oldTransitions === false || $newTransitions === false) {
        fwrite(STDERR, "Note: this machine's PHP has no clock-change list for '{$oldName}' or\n");
        fwrite(STDERR, "'{$newName}', so the extra offset-history check could not run; ICU's own\n");
        fwrite(STDERR, "identity check above already confirms they are the same zone.\n");
        return 'same';
    }

    // Any difference between 1970 and 2100 — a different NUMBER of clock
    // changes, or one change at a different moment or offset — means PHP's
    // own data disagrees with ICU about two names ICU calls one zone, in
    // years the portal really uses. (A difference only BEFORE 1970 cannot
    // show here: this comparison starts in 1970.) That deserves a person's
    // look, so it is always reported. It still does not refuse: ICU's
    // identity check above is what this guard trusts.
    $disagrees = count($oldTransitions) !== count($newTransitions);
    if ($disagrees === false) {
        foreach ($oldTransitions as $index => $oldTransition) {
            $newTransition = $newTransitions[$index];
            if ($oldTransition['ts'] !== $newTransition['ts']
                || $oldTransition['offset'] !== $newTransition['offset']
                || $oldTransition['isdst'] !== $newTransition['isdst']
            ) {
                $disagrees = true;
                break;
            }
        }
    }
    if ($disagrees === true) {
        fwrite(STDERR, "Note: PHP's own zone data gives '{$oldName}' and '{$newName}' different\n");
        fwrite(STDERR, "clock changes between 1970 and 2100, although ICU calls them one zone.\n");
        fwrite(STDERR, "The rename was accepted (ICU's identity check decides), but a person\n");
        fwrite(STDERR, "should look at this before the list is committed.\n");
    }

    return 'same';
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

    // -------------------------------------------------------------------
    // 🕰️ #557: turn ICU's answer into the CURRENT spelling of that zone.
    // -------------------------------------------------------------------
    // `getIDForWindowsID()` above is ICU's "preferred" name for a Windows
    // zone, and for seven zones that preferred name is an OLD spelling —
    // `Asia/Calcutta` for "India Standard Time", `Asia/Katmandu`,
    // `Asia/Rangoon`, `Europe/Kiev`, `America/Buenos_Aires`,
    // `America/Godthab`, `America/Indianapolis`. ICU's OWN preferred name
    // for each of these has NOT changed — `IntlTimeZone::getCanonicalID()`
    // still answers `Asia/Calcutta`, not `Asia/Kolkata`. It is the IANA
    // time-zone database that has moved on, to `Asia/Kolkata`,
    // `Asia/Kathmandu`, `Asia/Yangon`, `Europe/Kyiv`,
    // `America/Argentina/Buenos_Aires`, `America/Nuuk` and
    // `America/Indiana/Indianapolis` — and `getIanaID()` is the method
    // that reports IANA's current name. The old spellings still work on a
    // FULL set of system zone data, but a server built from the trimmed
    // set most Linux distributions ship by default (Ubuntu 24.04's `tzdata`
    // package is one) rejects them: only the OPTIONAL `tzdata-legacy`
    // package carries the old names. Writing the old spelling into this
    // list would NOT fail silently — the reader records a warning when a
    // zone name does not resolve — but it would still give the WRONG
    // time: an imported Microsoft 365 calendar in one of these seven
    // zones would fall back to the calendar's own zone on any such server,
    // which is not the zone the event was actually written in.
    //
    // `IntlTimeZone::getIanaID()` is ICU's own answer to "what is the
    // current name for this zone", so it is asked here, on the SAME
    // machine, straight after `getIDForWindowsID()` — never a hand-written
    // substitution table, which would need updating by hand every time a
    // country changes its mind. It needs PHP 8.4 or newer built with ICU
    // 74 or newer. This script relies on the GENERATING machine's ICU
    // being new enough to know the current names; the exact ICU version
    // that produced the committed list is recorded in that file's own
    // header (see `$icuVersion` below), because it is the file that
    // matters, not this comment.
    //
    // Case 1 — this machine's intl extension too old to have the method —
    // is checked ONCE, for the whole run, before this loop even starts
    // (see `$verb` and the refusal above); by the time execution reaches
    // here it cannot happen. Three more things can still go wrong, each
    // for an INDIVIDUAL zone, and each one REFUSES rather than guesses,
    // because writing the old name back in as a fallback is exactly the
    // fault #557 is about:
    //   2. ICU has the method but does not recognise the zone ICU's own
    //      previous answer gave (would mean an ICU internal inconsistency;
    //      not expected, but not assumed away either).
    //   3. ICU accepts the current name but THIS machine's PHP does not
    //      (system zone data can, in principle, lag ICU's own tables) —
    //      checked with a real `new DateTimeZone()`, not merely trusted.
    //   4. ICU's answer is not merely a different SPELLING of the same
    //      zone but a genuinely DIFFERENT one — checked below with
    //      `windowsTimeZonesRenameVerdict()`. Not expected today (checked
    //      by hand across the whole ICU rename set, not only these 139),
    //      but a future ICU release could still do it.
    $currentId = IntlTimeZone::getIanaID($ianaId);
    if (is_string($currentId) === false || $currentId === '') {
        fwrite(STDERR, "Refusing to {$verb} the list: IntlTimeZone::getIanaID('{$ianaId}') did not\n");
        fwrite(STDERR, "return a name for the Windows zone '{$windowsName}'. That zone name came\n");
        fwrite(STDERR, "from ICU's own getIDForWindowsID() a moment ago, so this is unexpected and\n");
        fwrite(STDERR, "needs looking at by hand rather than being written as-is.\n");
        exit(1);
    }
    // ICU can name a zone this machine's own system zone data does not
    // carry (a newer ICU tables release, older OS tzdata). Confirm PHP
    // itself, not just ICU, accepts the current name before it is written,
    // so the list this generator produces is never ahead of the machine
    // that produced it.
    try {
        new DateTimeZone($currentId);
    } catch (Exception $dtzException) {
        fwrite(STDERR, "Refusing to {$verb} the list: this machine's PHP does not accept\n");
        fwrite(STDERR, "'{$currentId}', the current name IntlTimeZone::getIanaID() gave for the\n");
        fwrite(STDERR, "Windows zone '{$windowsName}': " . $dtzException->getMessage() . "\n");
        exit(1);
    }

    // Case 4 (see the numbered list above): a rename must be a PURE one —
    // the same zone under a new name, not a different zone entirely. Only
    // worth asking when the names actually differ; when ICU's answer is
    // already current (`$currentId === $ianaId`, true for every one of
    // today's 139 Windows defaults except the seven #557 renames) there is
    // nothing to compare.
    $verdict = $currentId === $ianaId ? 'same' : windowsTimeZonesRenameVerdict($ianaId, $currentId);
    if ($verdict === 'different') {
        fwrite(STDERR, "Refusing to {$verb} the list: the Windows zone '{$windowsName}' would map\n");
        fwrite(STDERR, "to '{$currentId}' instead of ICU's own answer '{$ianaId}', and ICU's own\n");
        fwrite(STDERR, "identity check (IntlTimeZone::getCanonicalID()) says the two are NOT the\n");
        fwrite(STDERR, "same zone. That is not a pure rename — it is a genuinely different zone —\n");
        fwrite(STDERR, "so this needs a person to decide, not this script.\n");
        exit(1);
    }
    if ($verdict === 'unknown') {
        fwrite(STDERR, "Refusing to {$verb} the list: the Windows zone '{$windowsName}' would map\n");
        fwrite(STDERR, "to '{$currentId}' instead of ICU's own answer '{$ianaId}', but this\n");
        fwrite(STDERR, "machine's ICU does not recognise one of those two names\n");
        fwrite(STDERR, "(IntlTimeZone::getCanonicalID() gave no answer), so it cannot confirm\n");
        fwrite(STDERR, "they are the same zone. Run this script on a machine with a newer ICU.\n");
        exit(1);
    }

    $map[$windowsName] = $currentId;
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
