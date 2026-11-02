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

const PDF_EXPECTED_CHECKS = 23;

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
