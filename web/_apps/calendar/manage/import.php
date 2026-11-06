<?php
// Path: public_html/calendar/manage/import.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Bulk Event Import via CSV 📥
 * -----------------------------------------------------------------------------
 * Admin-only. Upload a CSV with one event per row, preview parsed rows
 * + validation errors, then run the import. Excel users should
 * "Save As → CSV (Comma delimited)" first.
 *
 * Required columns: eventName, startDateTime
 * Optional columns: endDateTime, isAllDay, locationName, description,
 *                   status (draft|published|cancelled|postponed), category,
 *                   type, isPublic, isFeatured
 *
 * @package   Portal\Calendar
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;
// ⚠️ A class used by its SHORT name needs its `use` line in THIS file, and
//    the line only fails when it RUNS — `php -l` is happy without it (#536).
use Portal\Core\I18n;

$pageTitle   = 'Bulk Import Events';
$pageSection = 'calendar';
$breadcrumbs = [
    'Dashboard'       => Site::url(''),
    'Calendar'        => Site::url('calendar'),
    'Manage Events'   => Site::url('calendar/manage'),
    'Bulk Import'     => '',
];

Auth::ensureSession();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$siteId   = Site::id();
$userId   = (int) ($_SESSION['user_id'] ?? 0);
$preview  = [];
$results  = [];
$error    = '';
$imported = false;

/** Parse + validate CSV. Returns array{rows:list<array>, errors:list<string>} */
// 🕐 ONE PLACE PUTS A CELL INTO THE COLUMN'S SHAPE, used by the row CHECK and
//    by the row STORE (#777 round 9).
//
// ⚠️ IT USED TO BE DEFINED DOWN IN THE CONFIRM BLOCK, so the check compared
//    something ELSE. Round 8 made the check compare readings as text where
//    BOTH cells parsed and fall back to moments otherwise -- and the fallback
//    fires when EITHER cell is loose, so a clean ISO cell was dragged into a
//    moment comparison with its loose neighbour. Measured in Europe/London:
//
//      start "2026-03-29 01:30", end "29 March 2026 2:15am"
//          -> REFUSED "endDateTime is before startDateTime"
//          -> but the readings it would STORE are 01:30 and 02:15: a
//             legitimate 45-minute event, refused. The exact harm round 8's
//             own finding 3 describes, surviving in the looser half.
//
//      start "29 March 2026 2:15am", end "2026-03-29 01:30"
//          -> ACCEPTED, and stored 02:15 -> 01:30, an end before its start.
//
//    So the check compares THE VALUES THAT WILL ACTUALLY BE STORED. That is
//    the one comparison that cannot disagree with storage, in either format,
//    and it needs no branch at all -- which is also why the "kept FOR THOSE
//    ONLY" claim that used to sit here has gone rather than been reworded.
$fmtClock = static function (string $text): ?string {
    $trimmed = trim($text);

    // ⚠️ READ THIS BEFORE ADDING ANY RULE HERE. This closure has been
    //    rewritten three times and TWO of those rewrites broke formats that
    //    had always imported (#777 rounds 12, 13 and 14). The cause was the
    //    same both times and it is a shape, not a slip:
    //
    //      **a strict rule written for ISO values leaked into the permissive
    //      path, and refused things only the permissive path ever handled.**
    //
    //    So steps 1 and 2 below are ATTEMPTS, not GATES. Each one takes the
    //    value if it recognises it and otherwise FALLS THROUGH. Nothing in
    //    this closure may refuse a value because of the SHAPE it is written
    //    in; the only refusals are for a value that is genuinely wrong.

    // ── 1. AN OFFSET IN THE VALUE MEANS IT IS AN INSTANT ────────────────────
    //
    // A value carrying `+02:00`, `-0500` or a trailing `Z` is a moment, so it
    // is converted onto the organisation's clock. `Site::timezone()` is the
    // ONE place that answers that zone, and it is the same value `$importZone`
    // holds further down.
    //
    // 🚨 IT FALLS THROUGH ON REFUSAL, AND THAT IS ROUND 14's REPAIR. Round 13
    //    returned null here, and `instantFromInput()` requires the date half
    //    to be strict zero-padded ISO -- so every offset-bearing cell whose
    //    date was written any other way was refused outright, and the
    //    spreadsheet fallback below was never reached for it. Measured, these
    //    had all imported correctly before and became "startDateTime not
    //    parseable":
    //
    //      Mon, 01 Jun 2026 10:00:00 +0100   (RFC 2822 -- an e-mail Date:
    //                                         header, an RSS pubDate)
    //      Tue, 3 Mar 2026 19:00 +0100
    //      1 June 2026 10:00 +01:00
    //      Jun 1 2026 10:00 +0100
    //
    //    The first is PHP's own `DATE_RFC2822`. Worse, the SAME format was
    //    accepted or refused depending on how its zone was spelled, because
    //    `GMT` does not match `inputCarriesOffset()` while `+0000` does.
    if (I18n::inputCarriesOffset($trimmed) === true) {
        $zoneName = Site::timezone();
        try {
            $instant = I18n::instantFromInput($trimmed, new \DateTimeZone($zoneName));
        } catch (\Throwable $e) {
            // An unreadable stored zone is not this cell's fault. Fall
            // through rather than blaming the row.
            $instant = null;
        }
        if ($instant !== null) {
            return $instant;
        }
    }

    // ── 2. A READING, AND ONE PLACE DECIDES WHAT ONE IS ─────────────────────
    //
    // 📌 `I18n::wallClockFromInput()` is that place. Nothing here second
    //    guesses it, and a refusal from it is not final -- see step 3.
    $clock = I18n::wallClockFromInput($trimmed);
    if ($clock !== null) {
        return $clock;
    }

    // ── 3. THE SPREADSHEET FALLBACK ─────────────────────────────────────────
    //
    // A spreadsheet holds whatever somebody typed -- "1/3/26 7pm", "March 3
    // 2026", "Tue, 3 Mar 2026 19:00" -- and refusing all of it would turn
    // imports that work today into failures. Normalising the file's own
    // formats is #777 part two's to settle.
    //
    // 🚨 THE RAW TEXT, NEVER A `str_replace('T', ' ')` OF IT. An earlier
    //    version replaced EVERY capital `T`, so `"Tue,"` became `" ue,"`
    //    (refused) and `"UTC"` became `"U C"`, which `strtotime()` read as
    //    half past TEN -- eight hours out, a value the old code could not
    //    produce. Excel's long date format starts with a weekday name, so the
    //    commonest spreadsheet in the world stopped importing, under a comment
    //    promising nothing had changed.

    // (a) A MISPLACED FRACTION, and NOTHING WIDER THAN THAT. In ISO 8601 a
    //     fractional part belongs to the lowest component written, so
    //     `2026-06-01T10.5` means 10:30 and `T10:00.5` means 10:00:30. Step 2
    //     refuses both on purpose (#777 round 10, where truncating to 10:00
    //     lost up to 59 minutes silently), and `strtotime()` reads the `.5` as
    //     five MINUTES or five SECONDS -- neither reading. So this one shape
    //     must not reach it.
    //
    // 🚨 ROUND 13 WROTE THIS AS "ANY ISO `T`-FORM VALUE STEP 2 DECLINED",
    //    which refused three legitimate forms NEITHER step handles:
    //    `2026-6-1T10:00`, `2026-6-1T10:00:00` and `20260601T10` (a bare hour,
    //    which `wallClockFromInput()`'s own comment calls a legal ISO time).
    //    So `2026-6-1 10:00` imported and the identical date with a `T` in it
    //    did not. Its justifying sentence -- "steps 1 and 2 between them
    //    handle every legitimate machine form" -- was false, and the comment
    //    thirty lines below named `2026-6-1` as a format the fallback exists
    //    to support. **The rule is about the FRACTION, so it says so.**
    if (preg_match('/^(?:\d{4}-\d{2}-\d{2}|\d{8})[Tt][\d:]*\.\d/', $trimmed) === 1) {
        return null;
    }

    // (b) AN IMPOSSIBLE CALENDAR DAY, IN ANY SPELLING. `strtotime()` rolls one
    //     forward silently, so `2026-02-31 10:00` was stored as 2026-03-03
    //     10:00:00 -- three days out -- and the row reported `ok => true`,
    //     "Imported as event #N", while the preview screen printed the cell
    //     raw so nothing warned anybody.
    //
    //     Round 12 guarded it with `checkdate()` on a zero-padded ISO prefix,
    //     which is a spelling list wearing a rule's clothes: `2026-2-31`,
    //     `02/31/2026`, `2/31/2026`, `Feb 31 2026` and `31 February 2026` all
    //     still rolled. Asking what PHP PARSED covers every spelling.
    //
    // 🚨 BUT `date_parse()` IS A DIFFERENT PARSER FROM `strtotime()`, AND
    //    ROUND 13 MISSED THAT. It does not understand an ISO ordinal date:
    //    `2026-152` comes back as day 152 of month 1 with "The parsed date was
    //    invalid", while `strtotime()` reads it correctly as 1 June. Refusing
    //    on any warning therefore refused a value the fallback handles -- a
    //    check crying wolf (#483) inside the rule written to stop a check
    //    crying wolf.
    //
    //    So the test is the HARM, stated exactly: a day somebody could have
    //    typed (1 to 31) in a month that does not have it. Day 152 is not
    //    such a day, so an ordinary parser disagreement falls through.
    $parsed = date_parse($trimmed);
    if (count($parsed['errors']) > 0) {
        return null;
    }
    if (is_int($parsed['year']) === true
        && is_int($parsed['month']) === true
        && is_int($parsed['day']) === true
        && $parsed['day'] >= 1 && $parsed['day'] <= 31
        && $parsed['month'] >= 1 && $parsed['month'] <= 12
        && checkdate($parsed['month'], $parsed['day'], $parsed['year']) === false) {
        return null;
    }

    $moment = strtotime($trimmed);
    return $moment === false ? null : date('Y-m-d H:i:s', $moment);
};

$parseCsv = static function (string $tmpFile) use ($siteId, $mysqli, $fmtClock): array {
    $handle = fopen($tmpFile, 'r');
    if ($handle === false) {
        return ['rows' => [], 'errors' => ['Could not open uploaded file.']];
    }
    // The separator, the quote and the escape character are each written out,
    // as the values PHP has always used here. Left to its default, PHP 8.4 and
    // later log a notice for every file read, because that default is going to
    // change; written out, the file is read exactly as before on every version.
    $header = fgetcsv($handle, null, ',', '"', '\\');
    if ($header === false || count($header) < 2) {
        fclose($handle);
        return ['rows' => [], 'errors' => ['CSV must have at least 2 columns including eventName + startDateTime.']];
    }
    // 🧹 Normalise headers — strip BOM, lowercase, trim
    $header = array_map(static function ($h): string {
        return strtolower(trim(str_replace(["\xEF\xBB\xBF", '"'], '', (string) $h)));
    }, $header);

    $colMap = array_flip($header);
    if (isset($colMap['eventname']) === false || isset($colMap['startdatetime']) === false) {
        fclose($handle);
        return ['rows' => [], 'errors' => ['CSV must contain eventName and startDateTime columns. Found: ' . implode(', ', $header)]];
    }

    // 🗂️ Existing category + type lookup (case-insensitive on name)
    $catMap = [];
    $stmt = $mysqli->prepare('SELECT categoryID, LOWER(categoryName) AS n FROM tblEventCategories WHERE siteID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $catMap[$r['n']] = (int) $r['categoryID'];
        }
        $stmt->close();
    }
    $typeMap = [];
    $stmt = $mysqli->prepare('SELECT typeID, LOWER(typeName) AS n FROM tblEventTypes WHERE siteID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('i', $siteId);
        $stmt->execute();


        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $typeMap[$r['n']] = (int) $r['typeID'];
        }
        $stmt->close();
    }

    $rows   = [];
    $errors = [];
    $rowNum = 1;  // header is row 1
    while (($data = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
        $rowNum++;
        if (count(array_filter($data, fn ($v) => trim((string) $v) !== '')) === 0) {
            continue;  // empty row
        }

        $get = static function (string $key) use ($data, $colMap): string {
            $idx = $colMap[$key] ?? null;
            if ($idx === null || array_key_exists($idx, $data) === false) {
                return '';
            }
            return trim((string) $data[$idx]);
        };

        $name      = $get('eventname');
        $start     = $get('startdatetime');
        $end       = $get('enddatetime');
        $allDay    = strtolower($get('isallday'));
        $location  = $get('locationname');
        $desc      = $get('description');
        $status    = strtolower($get('status'));
        $catName   = strtolower($get('category'));
        $typeName  = strtolower($get('type'));
        $isPubStr  = strtolower($get('ispublic'));
        $isFeatStr = strtolower($get('isfeatured'));

        $rowErr = [];
        if ($name === '')  { $rowErr[] = 'eventName required'; }
        if ($start === '') { $rowErr[] = 'startDateTime required'; }
        // 🕐 THE CHECK COMPARES WHAT WILL ACTUALLY BE STORED (#777 round 9).
        //    `$fmtClock()` is the one thing that decides a cell's stored value,
        //    so asking IT both questions is the only comparison that cannot
        //    disagree with storage. See its definition above for the two
        //    measured faults that got us here.
        //
        //    `Y-m-d H:i:s` sorts the way a clock does, which is #435's own fix,
        //    so once both cells are in that shape the comparison is text and
        //    nothing is resolved into a moment.
        //    ⚠️ ROUND 9 WROTE THE PARAGRAPH ABOVE AND LEFT `strtotime()` ON
        //       THE LINE BELOW IT, where it stayed until round 11 (#777). So
        //       the comment promising one decider sat directly on top of two,
        //       which is worse than no comment: the next reader checks the
        //       claim, not the code. Measured, the two really did disagree in
        //       both directions --
        //
        //         `2026-06-01 10`    strtotime REFUSES, `$fmtClock` stores it
        //                            as 10:00:00. So that text was refused in
        //                            the START column as "not parseable",
        //                            which was untrue, while the identical
        //                            text in the END column was accepted.
        //         `2026-02-31 10:00` strtotime ACCEPTS it, rolling an
        //                            impossible date three days forward.
        //
        //       ⚠️ THE SECOND EXAMPLE'S CONCLUSION WAS WRONG WHEN I WROTE IT,
        //          and round 12 measured it. I said `$fmtClock` refused
        //          `2026-02-31` and the row "was then refused further down
        //          under a different message". It did NOT refuse it: the
        //          fallback rolled the date forward too, so the row was
        //          IMPORTED as a success with the start three days out. The
        //          claim is exactly the kind that stops the next reader
        //          looking, in the comment written to record a fault of the
        //          same shape. `$fmtClock` refuses it NOW -- see the two
        //          narrowings at the end of that closure.
        //
        //       ⚠️ A SENTENCE HERE SAID "Nothing was ever stored wrongly,
        //          because the store step guards both cells" -- FOUR LINES
        //          BELOW the admission, in this same block, that a row WAS
        //          imported with its start three days out. Round 12 wrote the
        //          correction and left the sentence it falsifies standing.
        //          The false half was the reassuring one, and it was the
        //          first general claim a reader reached -- exactly what the
        //          lines above warn about. Removed rather than reworded.
        //
        //       What was wrong was WHICH rows were refused, what they were
        //       told, and -- for an impossible date -- what got stored.
        $startStored = $start !== '' ? $fmtClock($start) : null;
        if ($start !== '' && $startStored === null) { $rowErr[] = 'startDateTime not parseable'; }
        if ($end !== '') {
            $endStored = $fmtClock($end);
            if ($endStored === null) {
                $rowErr[] = 'endDateTime not parseable';
            } elseif ($startStored !== null && $endStored < $startStored) {
                $rowErr[] = 'endDateTime is before startDateTime';
            }
        }

        // ⚠️ Exactly `tblEvents.status`'s ENUM — see #676. `archived` was
        //    accepted here and the column has never had it, so a CSV row
        //    carrying it passed validation and then failed on the insert.
        if ($status !== '' && in_array($status, ['draft', 'published', 'cancelled', 'postponed'], true) === false) {
            $rowErr[] = 'status must be draft|published|cancelled|postponed';
        }
        $catId  = $catName !== ''  ? ($catMap[$catName]  ?? null) : null;
        $typeId = $typeName !== '' ? ($typeMap[$typeName] ?? null) : null;
        if ($catName !== ''  && $catId  === null) { $rowErr[] = 'category not found: ' . $catName; }
        if ($typeName !== '' && $typeId === null) { $rowErr[] = 'type not found: ' . $typeName; }

        $rows[] = [
            'rowNum'        => $rowNum,
            'eventName'     => $name,
            'startDateTime' => $start,
            'endDateTime'   => $end !== '' ? $end : null,
            'isAllDay'      => in_array($allDay,    ['1', 'true', 'yes', 'y'], true) ? 1 : 0,
            'locationName'  => $location,
            'description'   => $desc,
            'status'        => $status !== '' ? $status : 'draft',
            'categoryID'    => $catId,
            'typeID'        => $typeId,
            'isPublic'      => in_array($isPubStr,  ['', '1', 'true', 'yes', 'y'], true) ? 1 : 0,
            'isFeatured'    => in_array($isFeatStr, ['1', 'true', 'yes', 'y'], true) ? 1 : 0,
            'errors'        => $rowErr,
            // 🧹 `_startTs` and `_endTs` were carried here and `unset()` on
            //    confirm without ever being read -- `grep -n "_startTs"` found
            //    only the write and the unset. They were the only readers of
            //    `$endTs`, which #777 round 9 removed when the row check
            //    stopped comparing moments, so keeping them would have left an
            //    undefined variable in a session payload.
        ];
    }
    fclose($handle);
    return ['rows' => $rows, 'errors' => $errors];
};

// -----------------------------------------------------------------------------
// 🚦 Handle POST
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $error = 'Invalid or expired form token.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'preview' && isset($_FILES['csv_file']) === true) {
            $file = $_FILES['csv_file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $error = 'File upload failed.';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $error = 'File too large (2 MB max).';
            } else {
                $parsed = $parseCsv($file['tmp_name']);
                $preview = $parsed['rows'];
                if (count($parsed['errors']) > 0) {
                    $error = implode('; ', $parsed['errors']);
                }

                // 🧊 Stash parsed rows in the session so the confirm step
                //    doesn't need a second upload. Trim to JSON-safe shape.
                $_SESSION['eventsImportPreview'] = array_map(static function ($r) {
                    // (`_startTs`/`_endTs` are gone -- see the parse loop.)
                    return $r;
                }, $preview);
            }
        }

        if ($action === 'confirm') {
            $rows = $_SESSION['eventsImportPreview'] ?? [];
            if (count($rows) === 0) {
                $error = 'No preview data — re-upload the file.';
            } else {
                // 🕰️ THE ZONE (#604, reworded by #777 round 7). Each row's
                //    start and end are stored as the reading they already are,
                //    and `Site::timezone()` is the zone recorded beside them
                //    because **the spreadsheet belongs to this organisation**
                //    — somebody here typed those times on this
                //    organisation's clock.
                //
                // ⚠️ THAT REASON USED TO BE "they are read with strtotime()
                //    and written with date(), both on PHP's clock, which
                //    start-up sets to this organisation's zone". That
                //    mechanism was REMOVED in round 6 for the plain shapes,
                //    and the comment was left saying it — in the very commit
                //    whose finding 2 quoted this comment as part of the
                //    fault. The conclusion was right throughout; the stated
                //    reason now sends the next reader to a mechanism that is
                //    only the fallback. WHAT WAS WRONG: this
                //    statement named no zone at all, so every imported event
                //    took the column's default, "Europe/London", wherever the
                //    organisation was. WHAT IT CANNOT DO: take a zone per row.
                //    The file has no column for one.
                $importZone = Site::timezone();
                $stmt = $mysqli->prepare(
                    'INSERT INTO tblEvents '
                    . '(siteID, eventName, eventSlug, description, startDateTime, endDateTime, timezone, '
                    . 'isAllDay, locationName, status, isPublic, isFeatured, categoryID, typeID, createdByID) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if ($stmt === false) {
                    $error = t('error.db_import_prepare');
                } else {
                    $okCount = 0;
                    foreach ($rows as $r) {
                        if (count($r['errors'] ?? []) > 0) {
                            $results[] = ['ok' => false, 'row' => $r['rowNum'], 'msg' => implode('; ', $r['errors'])];
                            continue;
                        }
                        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', (string) $r['eventName']), '-'));
                        $slug = substr($slug !== '' ? $slug : 'event-' . bin2hex(random_bytes(4)), 0, 100);

                        // 🕐 STORE THE READING, DO NOT ROUND-TRIP IT (#777).
                        //    `tblEvents.startDateTime` holds a WALL-CLOCK
                        //    reading. This was
                        //    `date('Y-m-d H:i:s', strtotime($r['startDateTime']))`
                        //    -- a moment built and formatted back -- so on the
                        //    morning the clocks go forward a row reading 01:30
                        //    was imported as 02:30, permanently, and the
                        //    comment above described that round trip as the
                        //    design. Measured in Europe/London: `2026-03-29
                        //    01:30` came out `2026-03-29 02:30`.
                        //
                        //    A spreadsheet column has no offset in it, so
                        //    every value here is a reading on the clock named
                        //    by `$importZone` -- which is exactly why building
                        //    a moment was never needed. `$fmtClock()` puts the
                        //    text into the column's shape without resolving
                        //    anything; a row whose value cannot be read at all
                        //    was already refused upstream (line 133).
                        // 📌 `$fmtClock` is defined ONCE near the top of this file, so
                        //    the row CHECK and the row STORE cannot disagree (#777 round 9).

                        $startSql = $fmtClock((string) $r['startDateTime']);
                        $hasEnd   = $r['endDateTime'] !== null && $r['endDateTime'] !== '';
                        $endSql   = $hasEnd === true ? $fmtClock((string) $r['endDateTime']) : null;
                        if ($startSql === null) {
                            $results[] = ['ok' => false, 'row' => $r['rowNum'], 'msg' => 'startDateTime could not be read'];
                            continue;
                        }
                        // 🚨 AN END TIME THAT CANNOT BE READ REFUSES THE ROW.
                        //    It used to be impossible to reach: the row check
                        //    upstream guaranteed `strtotime($end) !== false`.
                        //    The first version of this closure could answer
                        //    null for a value that check had passed, and only
                        //    the START was guarded -- so the row imported with
                        //    `ok => true` and the message "Imported as event
                        //    #N", **with no end time at all**, bound as SQL
                        //    NULL. Of the three harms that version caused, this
                        //    was the one nobody could see afterwards: the
                        //    preview screen prints the cell raw, so the
                        //    administrator reviewing the file sees exactly what
                        //    they typed and nothing warns them.
                        //
                        //    A refused row is visible and recoverable. A
                        //    silently emptied column is neither.
                        if ($hasEnd === true && $endSql === null) {
                            $results[] = ['ok' => false, 'row' => $r['rowNum'], 'msg' => 'endDateTime could not be read'];
                            continue;
                        }

                        $name    = (string) $r['eventName'];
                        $desc    = (string) $r['description'];
                        $allDay  = (int) $r['isAllDay'];
                        $loc     = (string) $r['locationName'];
                        $status  = (string) $r['status'];
                        $isPub   = (int) $r['isPublic'];
                        $isFeat  = (int) $r['isFeatured'];
                        $catId   = $r['categoryID'] !== null ? (int) $r['categoryID'] : null;
                        $typeId  = $r['typeID']     !== null ? (int) $r['typeID']     : null;

                        $stmt->bind_param(
                            'issssssisssiiii',
                            $siteId, $name, $slug, $desc, $startSql, $endSql, $importZone,
                            $allDay, $loc, $status, $isPub, $isFeat,
                            $catId, $typeId, $userId
                        );
                        $okThis = $stmt->execute();
                        $results[] = [
                            'ok'  => $okThis,
                            'row' => $r['rowNum'],
                            'msg' => $okThis ? 'Imported as event #' . $stmt->insert_id : 'DB error: ' . $stmt->error,
                        ];
                        if ($okThis === true) {
                            $okCount++;
                        }
                    }
                    $stmt->close();
                    $imported = true;
                    Logger::activity('EventsImported', 'Bulk import: ' . $okCount . ' events created');
                    unset($_SESSION['eventsImportPreview']);
                }
            }
        }
    }
}

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-file-csv me-2"></i>Bulk Import Events</h1>
    <a href="<?php echo htmlspecialchars(Site::url('calendar/manage'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back to Manage
    </a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation me-1"></i><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<?php if ($imported === true): ?>
    <div class="card shadow-sm mb-3">
        <div class="card-header"><h2 class="h6 mb-0">Import results</h2></div>
        <div class="card-body p-0">
            <div class="portal-data-list">
                <div class="portal-data-row portal-data-header d-none d-md-flex">
                    <div class="col-md-2">Row</div>
                    <div class="col-md-2">Status</div>
                    <div class="col-md-8">Detail</div>
                </div>
                <?php foreach ($results as $r): ?>
                    <div class="portal-data-row">
                        <div class="col-12 col-md-2"><?php echo (int) $r['row']; ?></div>
                        <div class="col-12 col-md-2">
                            <?php if ($r['ok'] === true): ?>
                                <span class="badge bg-success">OK</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Skipped</span>
                            <?php endif; ?>
                        </div>
                        <div class="col-12 col-md-8 small">
                            <?php echo htmlspecialchars((string) $r['msg'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <a href="<?php echo htmlspecialchars(Site::url('calendar/manage/import'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-primary">Import another file</a>
    <a href="<?php echo htmlspecialchars(Site::url('calendar/manage'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">Back to Manage Events</a>

<?php elseif (count($preview) > 0): ?>
    <div class="alert alert-info">
        <strong><?php echo count($preview); ?></strong> row(s) parsed. Review and confirm to import.
    </div>
    <div class="card shadow-sm mb-3">
        <div class="card-body p-0">
            <div class="portal-data-list">
                <div class="portal-data-row portal-data-header d-none d-md-flex">
                    <div class="col-md-1">Row</div>
                    <div class="col-md-3">Name</div>
                    <div class="col-md-2">Start</div>
                    <div class="col-md-2">End</div>
                    <div class="col-md-2">Status</div>
                    <div class="col-md-2">Validation</div>
                </div>
                <?php foreach ($preview as $r): ?>
                    <div class="portal-data-row">
                        <div class="col-6 col-md-1"><?php echo (int) $r['rowNum']; ?></div>
                        <div class="col-6 col-md-3"><?php echo htmlspecialchars((string) $r['eventName'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-6 col-md-2 small"><?php echo htmlspecialchars((string) $r['startDateTime'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-6 col-md-2 small"><?php echo htmlspecialchars((string) ($r['endDateTime'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-6 col-md-2 small"><?php echo htmlspecialchars((string) $r['status'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-12 col-md-2 small">
                            <?php if (count($r['errors']) === 0): ?>
                                <span class="badge bg-success">Ready</span>
                            <?php else: ?>
                                <span class="badge bg-danger" title="<?php echo htmlspecialchars(implode('; ', $r['errors']), ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo count($r['errors']); ?> error(s)
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <form method="post" action="<?php echo htmlspecialchars(Site::url('calendar/manage/import'), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="confirm">
        <button type="submit" class="btn btn-success">
            <i class="fa-solid fa-upload me-1"></i> Confirm &amp; import valid rows
        </button>
        <a href="<?php echo htmlspecialchars(Site::url('calendar/manage/import'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">Cancel</a>
    </form>

<?php else: ?>
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h5">CSV format</h2>
            <p class="small">Required columns: <code>eventName</code>, <code>startDateTime</code>. Optional: <code>endDateTime</code>, <code>isAllDay</code>, <code>locationName</code>, <code>description</code>, <code>status</code> (draft|published|cancelled|postponed), <code>category</code>, <code>type</code>, <code>isPublic</code>, <code>isFeatured</code>. First row must be the header.</p>
            <p class="small text-muted mb-0">
                Excel users: open your file in Excel, choose <em>File → Save As → CSV (Comma delimited) (.csv)</em>.
            </p>
        </div>
    </div>

    <form method="post" action="<?php echo htmlspecialchars(Site::url('calendar/manage/import'), ENT_QUOTES, 'UTF-8'); ?>" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="preview">
        <div class="mb-3">
            <label for="csv_file" class="form-label">CSV file (max 2 MB)</label>
            <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required class="form-control">
        </div>
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-upload me-1"></i> Upload &amp; preview
        </button>
    </form>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
