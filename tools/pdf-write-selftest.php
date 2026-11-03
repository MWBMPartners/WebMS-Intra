<?php
// Path: tools/pdf-write-selftest.php
/**
 * -----------------------------------------------------------------------------
 * A PDF that was not written in full is not kept 🖨️ (#711)
 * -----------------------------------------------------------------------------
 * Exercises `Portal\Core\Pdf::writeOrWhyNot()` against a REAL filesystem.
 *
 * WHAT WAS WRONG, and why this test exists at all. `Pdf::create()` used to
 * discard `file_put_contents()`'s answer and decide success with
 * `is_file($filePath)`. **`is_file()` is true for a file that is empty or half
 * written**, which is exactly what a failed write leaves behind, so a truncated
 * giving statement, expense form, asset label or venue invoice was handed back
 * as a finished PDF with nothing recorded anywhere.
 *
 * AND THE FIRST FIX WAS WORSE THAN THE FAULT, which is why section F is here.
 * It wrote straight to the real path and, when the write failed, deleted
 * whatever was there — but `file_put_contents()` can fail at OPEN, leaving the
 * existing file untouched, and these paths use stable names recorded in the
 * database. So a working if stale statement was removed and the row went on
 * naming it: a broken download link. The writer now writes under a temporary
 * name in the same folder and renames into place. **Section F is the test that
 * would have caught that**, and it is the one worth keeping.
 *
 * WHAT IT NEEDS: php. Nothing else.
 *   - no database   — `writeOrWhyNot()` answers a reason and logs nothing; that
 *                     is why it answers a reason (see its own header).
 *   - no dompdf     — the rendered bytes arrive as an argument. dompdf is not in
 *                     this repository at all (`_libraries/` is never committed),
 *                     so a test that had to render could not run here.
 *   - no network, no web server, no bootstrap. The class file is loaded on its
 *                     own, which also proves this method depends on nothing.
 *
 * WHAT IT CANNOT PROVE, said plainly:
 *   - A genuine SHORT write — some bytes through, then the disk fills — cannot
 *     be forced on this machine without a quota or a tiny filesystem. Every
 *     write that fails here fails OUTRIGHT, returning `false`. So this test
 *     says nothing about a partial count, and that gap was measured rather
 *     than assumed: with the writer written in place, replacing the comparison
 *     with "did anything get written" left every check green.
 *     `tools/audit-checks/check_pdf_write_verified.py` is what guards that
 *     half, by pinning the whole condition. Neither guard catches the other's
 *     half, which is why there are two (#646).
 *   - The `PDF_EMPTY_RENDER` branch and the two log lines live in `create()`,
 *     which needs dompdf and a database. Not reached here.
 *   - Nothing here renders a PDF, so it says nothing about dompdf itself.
 *   - It does not prove a rename is ATOMIC. It proves the file that was there
 *     survives a failed write, and that no temporary file is left behind.
 *   - `tidyAway()`'s "COULD NOT BE REMOVED and is still on disk" branch is
 *     reached by nothing here. It needs a folder that is writable when the
 *     temporary file is created and unwritable by the time it is removed, which
 *     no fixture can arrange from inside one call.
 *   - A stray `.part-…` file left by a process killed between the write and the
 *     rename is not swept by anything, and `GdprEraser` would not match it
 *     either: #721.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/711
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

const PDF_EXPECTED_CHECKS = 35; // 23 before #721 added sections H and I. Twelve
                                // on the nightly sweep, four of them added after
                                // an independent check found the first version
                                // could not fail: the real PDF is now really OLD
                                // (the age guard was doing the mark test's work),
                                // a loosely-named file must survive, a member's
                                // own upload must survive, the folder list is
                                // pinned whole, the symlink case is a symlinked
                                // FOLDER rather than a file, and an unreadable
                                // folder must not stop the sweep. Two on the mark
                                // being in one place.

$root = dirname(__DIR__);
require_once $root . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core'
    . DIRECTORY_SEPARATOR . 'Pdf.php';

$passed = 0;
$failed = 0;

/** One check. The detail is printed on failure only, and on nothing else. */
function pdf_ok(string $what, bool $isTrue, string $detail = ''): void
{
    global $passed, $failed;
    if ($isTrue === true) {
        $passed++;
        echo '  ✓ ' . $what . PHP_EOL;

        return;
    }
    $failed++;
    echo '  ✗ ' . $what . ($detail === '' ? '' : '  —  ' . $detail) . PHP_EOL;
}

// 🔬 The method under test is private on purpose: `create()` is the only thing
//    that should call it. No setAccessible() — it has had no effect since PHP
//    8.1 and is deprecated in 8.5, where it prints a notice on every run.
$write = new ReflectionMethod(Portal\Core\Pdf::class, 'writeOrWhyNot');
$ask   = static fn (string $path, string $bytes): string
    => (string) $write->invoke(null, $path, $bytes);

$scratch = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf711-' . bin2hex(random_bytes(6));
mkdir($scratch, 0755, true);
$readOnly = $scratch . DIRECTORY_SEPARATOR . 'read-only';

// 🧹 Put every folder back to writable before removing it, or the tidy-up
//    cannot delete what section C and section E deliberately locked.
$tidy = static function () use ($scratch, $readOnly): void {
    // Walk it, making every folder and file writable on the way DOWN, because
    // sections C, E and F deliberately lock some of them and a locked folder
    // refuses both the unlink inside it and its own removal.
    $sweep = static function (string $dir) use (&$sweep): void {
        if (is_dir($dir) === false) {
            return;
        }
        @chmod($dir, 0755);
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) === true) {
                $sweep($path);
                continue;
            }
            @chmod($path, 0644);
            @unlink($path);
        }
        @rmdir($dir);
    };
    $sweep($readOnly);
    $sweep($scratch);
};
register_shutdown_function($tidy);

/** Any leftover `.part-*` file in a folder: the temporary name the writer uses. */
function pdf_parts(string $dir): array
{
    return array_values(array_map('basename', glob($dir . DIRECTORY_SEPARATOR . '*.part-*') ?: []));
}

echo PHP_EOL . 'A. A good write into a folder that is already there' . PHP_EOL;

$bytes  = "%PDF-1.4\n" . str_repeat('x', 5000) . "\n%%EOF\n";
$target = $scratch . DIRECTORY_SEPARATOR . 'plain.pdf';
$why    = $ask($target, $bytes);
pdf_ok('it answers an empty string', $why === '', 'answered: ' . $why);
pdf_ok('the file is there', is_file($target) === true);
pdf_ok('every byte arrived', is_file($target) === true && file_get_contents($target) === $bytes,
    'size on disk: ' . (is_file($target) === true ? (string) filesize($target) : 'no file')
    . ' of ' . strlen($bytes));
pdf_ok('no temporary file is left behind', pdf_parts($scratch) === [],
    implode(', ', pdf_parts($scratch)));

echo PHP_EOL . 'B. A good write where the folder has to be made, two levels deep' . PHP_EOL;

$nested = $scratch . DIRECTORY_SEPARATOR . 'a' . DIRECTORY_SEPARATOR . 'b'
    . DIRECTORY_SEPARATOR . 'nested.pdf';
$why    = $ask($nested, $bytes);
pdf_ok('it answers an empty string', $why === '', 'answered: ' . $why);
pdf_ok('both folders were made and the file holds every byte',
    is_file($nested) === true && file_get_contents($nested) === $bytes);

echo PHP_EOL . 'C. A GOOD write REPLACES what was there, even a READ-ONLY file' . PHP_EOL;

// 🪤 A draft of this section asked whether a shorter new PDF left the tail of a
//    longer old one behind. THAT CHECK COULD NOT FAIL: `file_put_contents()`
//    truncates, so writing in place leaves no tail either, and an independent
//    check measured it green against the very code this test exists to forbid.
//    The question that CAN fail is this one: an existing file at mode 0444 is
//    replaced, because `rename()` needs permission on the FOLDER and not on the
//    file it replaces. Writing in place would be refused by the file's own mode.
$readOnlyFile = $scratch . DIRECTORY_SEPARATOR . 'locked.pdf';
file_put_contents($readOnlyFile, "%PDF-1.4\nthe old one\n%%EOF\n");
chmod($readOnlyFile, 0444);
$shorter = "%PDF-1.4\nshort\n%%EOF\n";
$why     = @$ask($readOnlyFile, $shorter);
pdf_ok('it answers an empty string even though the old file was read-only',
    $why === '', 'answered: ' . $why);
pdf_ok('and the file now holds the new document',
    file_get_contents($readOnlyFile) === $shorter,
    'size on disk: ' . (string) filesize($readOnlyFile) . ' of ' . strlen($shorter));

echo PHP_EOL . 'D. The folder cannot be made' . PHP_EOL;

// 0500 = the owner may look inside and may not create anything in it. Running
// as root would defeat this, so the test says so rather than passing falsely.
mkdir($readOnly, 0755, true);
chmod($readOnly, 0500);
$blocked = $readOnly . DIRECTORY_SEPARATOR . 'cannot' . DIRECTORY_SEPARATOR . 'go.pdf';
$why     = @$ask($blocked, $bytes);
if (function_exists('posix_geteuid') === true && posix_geteuid() === 0) {
    echo '  ! running as root, so a read-only folder does not refuse anything:'
        . ' sections D, E and F prove nothing. Run as an ordinary user.' . PHP_EOL;
    $failed++;
} else {
    pdf_ok('it refuses', $why !== '');
    pdf_ok('the reason names the folder', str_contains($why, dirname($blocked)) === true, $why);
    pdf_ok('it says nothing was written', str_contains($why, 'nothing was written') === true, $why);
    pdf_ok('no file was left behind', is_file($blocked) === false);
}

echo PHP_EOL . 'E. The write fails, and nothing was there before' . PHP_EOL;

$refused = $readOnly . DIRECTORY_SEPARATOR . 'refused.pdf';
$why     = @$ask($refused, $bytes);
pdf_ok('it refuses', $why !== '', 'answered: ' . $why);
pdf_ok('the reason gives the byte count and says the write failed outright',
    str_contains($why, (string) strlen($bytes)) === true
    && str_contains($why, 'the write failed outright') === true, $why);
pdf_ok('nothing was created', is_file($refused) === false);
// Nothing was part-written, so the reason must NOT claim a clean-up. The first
// version of this code said "the part-written file was removed" here, about a
// file that never existed, and an administrator reading tblErrors was told a
// stub had been tidied away that was never there.
pdf_ok('and it does NOT claim to have removed a part-written file',
    str_contains($why, 'part-written') === false, $why);

echo PHP_EOL . 'F. THE REGRESSION TEST: a failed write must not touch the file that is there'
    . PHP_EOL;
echo '   The first version of this fix deleted it, so the database row went on' . PHP_EOL;
echo '   pointing at a file that had just been removed.' . PHP_EOL;

// 🪤 THE FIXTURE HAS TO MAKE THE WRITE FAIL IN A **WRITABLE** FOLDER, and the
//    first version of this section did not. It made the FOLDER read-only with
//    the existing file inside it at 0644 - and writing to a file that already
//    exists needs permission on THE FILE, so the write SUCCEEDED and the check
//    that names the harm ("the file is still there") could never go red. In
//    that fixture nothing can delete the file either, because `unlink()` needs
//    the folder. An independent check measured all of that, and measured the
//    consequence: adding one line, `@unlink($filePath);`, to the failure branch
//    put the whole round-1 fault back with every check in this test green.
//
//    So the folder stays WRITABLE and the write is made to fail at OPEN another
//    way: a basename long enough that the writer's 18-byte `.part-…` suffix
//    crosses the filesystem's limit on a name. The real file is created
//    perfectly well; only the temporary one is refused. That is the same class
//    of failure as a full disk or a quota - the open never succeeds - and it
//    needs no root, no quota and no special filesystem.
$longName = str_repeat('s', 241) . '.pdf';
$existing = $scratch . DIRECTORY_SEPARATOR . $longName;
$wasThere = "%PDF-1.4\nthe statement somebody can still download\n%%EOF\n";
file_put_contents($existing, $wasThere);

$why = @$ask($existing, $bytes);
pdf_ok('the fixture is honest: the write really did fail', $why !== '',
    'answered: ' . $why);
pdf_ok('THE FILE THAT WAS THERE IS STILL THERE', is_file($existing) === true);
pdf_ok('and it is byte-for-byte what it was',
    is_file($existing) === true && file_get_contents($existing) === $wasThere,
    'now ' . (is_file($existing) === true ? (string) filesize($existing) : 'GONE - DESTROYED')
    . ' bytes, was ' . strlen($wasThere));
pdf_ok('and no temporary file was left beside it', pdf_parts($scratch) === [],
    implode(', ', pdf_parts($scratch)));

echo PHP_EOL . 'G. The temporary file IS removed when the rename fails' . PHP_EOL;

// The bytes write perfectly well; it is putting them in place that fails,
// because the target name is taken by a directory. That is the one path that
// reaches the removal, so it is the only place a never-remove fault shows.
$asFolder = $scratch . DIRECTORY_SEPARATOR . 'iam-a-folder.pdf';
mkdir($asFolder, 0755, true);
$why = @$ask($asFolder, $bytes);
pdf_ok('it refuses, and says the document could not be put in place',
    $why !== '' && str_contains($why, 'could not be put in place') === true, $why);
pdf_ok('it says the part-written file WAS removed, because this time there was one',
    str_contains($why, 'the part-written file was removed') === true, $why);
pdf_ok('and no temporary file is left in the folder', pdf_parts($scratch) === [],
    implode(', ', pdf_parts($scratch)));
// 🪦 A fourth check here asserted the directory in the way was untouched. It
//    COULD NOT FAIL - neither `rename()` nor `unlink()` can remove a directory -
//    so it was deleted rather than kept for the look of the thing.

// =============================================================================
echo PHP_EOL . 'H. The nightly sweep removes a part file a killed run left (#721)' . PHP_EOL;
// =============================================================================
// 🧠 WHY. Section G proves the writer tidies up after ITSELF. It cannot prove
//    anything about a process that was KILLED between the write and the
//    rename, because nothing then runs the tidy-up at all — and that is the
//    case #721 is about. `Pdf::sweepPartFiles()` is what deals with it.
//
// 🛑 THIS METHOD DELETES FILES, so every check below is written against a
//    fixture that would really be lost. Three of them exist only because an
//    independent check found the first version of this section could not fail:
//
//      - the "it leaves a real PDF alone" fixture was created and never
//        `touch`ed, so it was YOUNG and the AGE guard saved it, not the mark
//        test. Deleting the mark test left all 31 checks green while a day-old
//        giving statement, an expense PDF, a document and a recording were all
//        removed. (#645's I7d lesson verbatim: when a check exists to prove
//        something ORDINARY still works, make sure the fixture is ordinary.)
//      - the symlink check planted a link to a FILE, which the independent
//        `isLink()` guard skips whatever the iterator flags are — so it passed
//        with `FOLLOW_SYMLINKS` added. The dangerous case the comment names is
//        a symlinked DIRECTORY, and nothing exercised it.
//      - nothing exercised the FOLDER allow-list, which is what stops a
//        member's own upload being deleted.

$uploads = $scratch . DIRECTORY_SEPARATOR . 'uploads';
$statements = $uploads . DIRECTORY_SEPARATOR . 'giving' . DIRECTORY_SEPARATOR . 'statements'
    . DIRECTORY_SEPARATOR . '1';
$docs       = $uploads . DIRECTORY_SEPARATOR . 'documents';
$recordings = $uploads . DIRECTORY_SEPARATOR . 'recordings';
mkdir($statements, 0755, true);
mkdir($docs, 0755, true);
mkdir($recordings, 0755, true);

$mark = \Portal\Core\Pdf::PART_MARK;
/** A file of a given age, remembered so the checks can ask about it. */
$make = static function (string $path, int $ageSeconds) : string {
    file_put_contents($path, 'half a pdf');
    if ($ageSeconds > 0) {
        touch($path, time() - $ageSeconds);
    }

    return $path;
};

// In a swept folder, named exactly as the writer names one, and two hours old.
$old = $make($statements . DIRECTORY_SEPARATOR . 'statement-7-2026.pdf' . $mark . 'aabbccddeeff', 7200);
// The same, written THIS SECOND: it may be a write in flight.
$young = $make($statements . DIRECTORY_SEPARATOR . 'statement-8-2026.pdf' . $mark . '112233445566', 0);
// 🪤 A REAL PDF, AND REALLY OLD. The old fixture was young, so the age guard
//    was doing the work and the mark test was untested.
$innocent = $make($statements . DIRECTORY_SEPARATOR . 'statement-9-2026.pdf', 7200);
// 🪤 Rubble in a swept folder whose name merely CONTAINS the mark. The writer
//    never produces this, and a `str_contains` test would delete it.
$looseName = $make($statements . DIRECTORY_SEPARATOR . 'notes' . $mark . 'x', 7200);

$swept = \Portal\Core\Pdf::sweepPartFiles($uploads, 3600);

pdf_ok('it removes a part file two hours old from a folder Pdf::create() writes to',
    is_file($old) === false && $swept['removed'] === 1,
    json_encode(['still there' => is_file($old), 'answer' => $swept]));
pdf_ok('IT LEAVES A PART FILE WRITTEN THIS SECOND, because that may be a write in flight',
    is_file($young) === true, 'the young part file was deleted');
pdf_ok('and it leaves a TWO-HOUR-OLD real PDF alone, so the mark test is what saves it and not the age',
    is_file($innocent) === true, 'the old finished PDF was deleted');
pdf_ok('and it leaves a name that merely CONTAINS the mark: only the exact shape the writer builds is swept',
    is_file($looseName) === true, 'a loosely-named file was deleted');
pdf_ok('nothing is reported as un-removable when everything could be removed',
    $swept['left'] === 0, json_encode($swept));

// 🛡️ THE FOLDER ALLOW-LIST, which is what stops a member losing their own
//    upload. `_apps/documents/upload.php` and `_apps/recordings/upload.php`
//    take the stored extension STRAIGHT FROM THE CLIENT FILENAME, so a member
//    who uploads `report.part-aabbccddeeff` has it stored with that exact
//    suffix. Both files below are named the way the writer names one AND are
//    two hours old, so ONLY the folder list saves them.
$memberDoc = $make($docs . DIRECTORY_SEPARATOR . '20261103_082700_aabbccdd.pdf' . $mark . 'deadbeefcafe', 7200);
$memberRec = $make($recordings . DIRECTORY_SEPARATOR . '20261103_082700_11223344.mp3' . $mark . 'feedfacefeed', 7200);
$sweptAgain = \Portal\Core\Pdf::sweepPartFiles($uploads, 3600);
pdf_ok('A MEMBER\'S OWN UPLOAD IS NEVER SWEPT, however it is named: documents and recordings are not on PART_FOLDERS',
    is_file($memberDoc) === true && is_file($memberRec) === true && $sweptAgain['removed'] === 0,
    json_encode(['document' => is_file($memberDoc), 'recording' => is_file($memberRec), 'answer' => $sweptAgain]));
pdf_ok('...and the folders it may touch are exactly the four Pdf::create() writes to',
    \Portal\Core\Pdf::PART_FOLDERS === [
        'giving' . DIRECTORY_SEPARATOR . 'statements',
        'pdfs',
        'venues' . DIRECTORY_SEPARATOR . 'tmp',
        'assets' . DIRECTORY_SEPARATOR . 'labels',
    ],
    json_encode(\Portal\Core\Pdf::PART_FOLDERS));

// 🚫 A folder that is not there is not a fault: `_uploads` may legitimately
//    hold none of these yet on a new installation, and the nightly job must
//    not fall over on it.
$missing = \Portal\Core\Pdf::sweepPartFiles($scratch . DIRECTORY_SEPARATOR . 'no-uploads-here', 3600);
pdf_ok('a root with none of those folders answers nothing rather than failing',
    $missing === ['removed' => 0, 'left' => 0], json_encode($missing));

// 🔗 A SYMLINKED DIRECTORY, which is the dangerous case. A link to a FILE is
//    skipped by `isLink()` whatever the iterator flags are, so planting one
//    proves nothing about the flags; a link to a FOLDER is only skipped
//    because `RecursiveDirectoryIterator` is built WITHOUT `FOLLOW_SYMLINKS`.
//    Adding that flag left all 31 checks green while the sweep escaped its
//    root and deleted two outside files, one a level deeper.
$outside = $scratch . DIRECTORY_SEPARATOR . 'outside' . DIRECTORY_SEPARATOR . 'deeper';
mkdir($outside, 0755, true);
$prizeTop   = $make($scratch . DIRECTORY_SEPARATOR . 'outside' . DIRECTORY_SEPARATOR . 'keep.pdf' . $mark . '0123456789ab', 7200);
$prizeDeep  = $make($outside . DIRECTORY_SEPARATOR . 'keep-too.pdf' . $mark . 'ba9876543210', 7200);
$linkMade   = @symlink($scratch . DIRECTORY_SEPARATOR . 'outside', $statements . DIRECTORY_SEPARATOR . 'escape');
if ($linkMade === true) {
    $afterLink = \Portal\Core\Pdf::sweepPartFiles($uploads, 3600);
    pdf_ok('A SYMLINKED FOLDER inside a swept folder is NOT followed, so nothing outside the root is deleted',
        is_file($prizeTop) === true && is_file($prizeDeep) === true && $afterLink['removed'] === 0,
        json_encode(['top' => is_file($prizeTop), 'deeper' => is_file($prizeDeep), 'answer' => $afterLink]));
} else {
    // Said out loud rather than skipped silently: a check that did not run is
    // not a check that passed.
    echo '   THIS FILESYSTEM WOULD NOT MAKE A SYMBOLIC LINK, so the escape case'
        . ' is UNPROVEN here.' . PHP_EOL;
    pdf_ok('A SYMLINKED FOLDER inside a swept folder is NOT followed, so nothing outside the root is deleted',
        false, 'symlink() failed, so this was not tested — treat as unproven');
}

// 🧯 AN UNREADABLE SUBFOLDER MUST NOT STOP THE SWEEP. Without
//    `CATCH_GET_CHILD` the walk THROWS on one, and the nightly job then
//    removes nothing at all and reports nothing — measured.
$blocked = $statements . DIRECTORY_SEPARATOR . 'locked';
mkdir($blocked, 0755, true);
$behind = $make($statements . DIRECTORY_SEPARATOR . 'statement-11-2026.pdf' . $mark . 'cafebabe0001', 7200);
@chmod($blocked, 0000);
if (is_readable($blocked) === false) {
    $afterBlocked = \Portal\Core\Pdf::sweepPartFiles($uploads, 3600);
    pdf_ok('an UNREADABLE folder does not stop the sweep: the rest is still tidied',
        is_file($behind) === false && $afterBlocked['removed'] === 1,
        json_encode(['the other file still there' => is_file($behind), 'answer' => $afterBlocked]));
    @chmod($blocked, 0755);
} else {
    echo '   RUNNING AS A USER WHO CAN READ A 0000 FOLDER (root?), so the'
        . ' unreadable-folder case is UNPROVEN here.' . PHP_EOL;
    pdf_ok('an UNREADABLE folder does not stop the sweep: the rest is still tidied',
        false, 'chmod 0000 did not make it unreadable — treat as unproven');
    @chmod($blocked, 0755);
}

// =============================================================================
echo PHP_EOL . 'I. The mark is in ONE place, so the writer and the sweep agree (#721)' . PHP_EOL;
// =============================================================================
// 🧠 WHY THIS IS A CHECK AND NOT A COMMENT. The writer builds its temporary
//    name from `PART_MARK` and the sweep looks for the same constant. If they
//    were two literals and one changed, the SWEEP would be the half that
//    broke — and a sweep that matches nothing looks exactly like a folder with
//    nothing to sweep. There is no error, no log line and no symptom.
pdf_ok('PART_MARK is a non-empty mark', is_string($mark) === true && $mark !== '', var_export($mark, true));

// A name built the way the WRITER builds one, from the constant itself, in a
// folder on the allow-list.
$markDir = $uploads . DIRECTORY_SEPARATOR . 'pdfs';
mkdir($markDir, 0755, true);
$builtLikeTheWriter = $make($markDir . DIRECTORY_SEPARATOR . 'claim-412.pdf' . $mark . 'abcdef123456', 7200);
$markSweep = \Portal\Core\Pdf::sweepPartFiles($uploads, 3600);
pdf_ok('a name built from PART_MARK the way the writer builds one IS swept',
    is_file($builtLikeTheWriter) === false && $markSweep['removed'] === 1,
    json_encode(['still there' => is_file($builtLikeTheWriter), 'answer' => $markSweep]));

echo PHP_EOL . str_repeat('=', 78) . PHP_EOL;
$ran = $passed + $failed;
echo $passed . ' passed, ' . $failed . ' failed, ' . $ran . ' ran of '
    . PDF_EXPECTED_CHECKS . ' expected.' . PHP_EOL;
if ($failed === 0 && $ran === PDF_EXPECTED_CHECKS) {
    echo 'PASS — a PDF that was not written in full is never kept. REACHED THE END.' . PHP_EOL;
    exit(0);
}
echo 'FAIL' . ($ran !== PDF_EXPECTED_CHECKS
    ? ' — the run did not reach its end, or a check was added without changing'
        . ' PDF_EXPECTED_CHECKS.'
    : '.') . PHP_EOL;
exit(1);
