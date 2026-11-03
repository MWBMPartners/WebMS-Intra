<?php
// Path: web/core/Pdf.php
/**
 * -----------------------------------------------------------------------------
 * PDF Generator 🖨️ (dompdf wrapper)
 * -----------------------------------------------------------------------------
 * Lightweight helper around self-hosted dompdf (no Composer).  Handles:
 *   • HTML → PDF rendering
 *   • Optional diagonal watermark text (e.g., NOT APPROVED / COMPLETE)
 *   • Saves to given file path and returns that path, or false
 *
 * `create()` answers the file path on success and `false` on every failure it
 * REPORTS - not a boolean, which this header said until #711. (It can still
 * THROW: `random_bytes()` and dompdf itself both can, and dompdf always could,
 * so a caller that must not fail wraps the call. "False on every failure" would
 * be too strong.) A caller must therefore
 * test `!== false` rather than treating the answer as truthy.
 *
 * Counted rather than asserted - and the first two attempts at this paragraph
 * were still wrong, which is why the names are given exactly. FIVE call sites:
 * `ExpensePdf::generate()` (ExpensePdf.php:240), `Giving::renderStatementPdf()`
 * (Giving.php:349), `venues/invoice-pdf.php:134`, `venues/schedule-pdf.php:180`
 * and `assets/labels-pdf.php:192`. FOUR test `!== false` on the spot, and
 * THREE of those four then fall back to rendering the same HTML as a print-CSS
 * page rather than failing (both venue pages and the asset labels). The fifth,
 * `Giving::renderStatementPdf()`, passes the answer straight up without testing
 * it - correct delegation - and both of ITS callers test it:
 * `Giving::renderStatementBatch()` (Giving.php:692) and
 * `giving/my-statement.php:32`, which also re-checks `is_file()`. So nothing
 * anywhere treats `false` as a path.
 *
 * dompdf must be manually uploaded to _libraries/dompdf/ on the server.
 * If it is not present, create() will log an error and return false.
 *
 * A PDF THAT WAS NOT WRITTEN IN FULL IS NEVER KEPT (#711). `writeOrWhyNot()`
 * compares the byte count; the reasoning, and what the old `is_file()` test got
 * wrong, is in that method's own header. Test: tools/pdf-write-selftest.php.
 *
 * @see       https://github.com/dompdf/dompdf
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.3.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Pdf
{
    /** @var bool Whether dompdf has been loaded */
    private static bool $loaded = false;

    /**
     * Attempt to load the dompdf autoloader from _libraries/.
     * Returns true if dompdf is available, false otherwise.
     *
     * @return bool
     */
    private static function loadDompdf(): bool
    {
        if (self::$loaded === true) {
            return true;
        }

        // 📂 dompdf is stored in _libraries/dompdf/ (outside web root, manually uploaded)
        $autoloadPath = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_libraries'
                      . DIRECTORY_SEPARATOR . 'dompdf' . DIRECTORY_SEPARATOR . 'autoload.inc.php';

        if (is_readable($autoloadPath) === false) {
            Logger::errorPlatform(
                'dompdf',
                'Warning',
                'NOT_INSTALLED',
                'dompdf autoloader not found',
                'Expected at: ' . $autoloadPath . ' — Upload dompdf to _libraries/dompdf/'
            );
            return false;
        }

        require_once $autoloadPath;
        self::$loaded = true;
        return true;
    }

    /**
     * Render HTML to a PDF file and save it to the given path.
     * Optionally overlays a diagonal watermark (e.g. "NOT APPROVED", "COMPLETE").
     *
     * @param string $html      The HTML content to render
     * @param string $filePath  Absolute path where the PDF should be saved
     * @param string $watermark Optional diagonal watermark text
     *
     * @return string|false The saved file path on success, or false on failure
     */
    public static function create(string $html, string $filePath, string $watermark = ''): string|false
    {
        // 📦 Ensure dompdf is available
        if (self::loadDompdf() === false) {
            return false;
        }

        // 🏷️ Inject watermark CSS if provided
        if ($watermark !== '') {
            $html .= '<style>'
                . '@page { margin:0 } '
                . 'body::before{'
                . 'content:"' . htmlspecialchars($watermark, ENT_QUOTES, 'UTF-8') . '"; '
                . 'position:fixed; top:45%; left:50%; '
                . 'transform:translate(-50%,-50%) rotate(-45deg); '
                . 'font-size:72px; color:rgba(200,0,0,0.15); z-index:-1;'
                . '}'
                . '</style>';
        }

        // 🖨️ Render the PDF using dompdf
        $opts = new \Dompdf\Options();
        $opts->set('isRemoteEnabled', false); // 🛡️ SSRF prevention
        $dompdf = new \Dompdf\Dompdf($opts);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // 💾 Write it out. The rendering is held in a variable on purpose
        //    (see writeOrWhyNot()'s header for why) and an empty or missing
        //    one is never a valid PDF - `strlen()` on null would throw under
        //    `strict_types` rather than say what was wrong.
        $bytes = $dompdf->output();
        if (is_string($bytes) === false || $bytes === '') {
            Logger::errorPlatform(
                'dompdf',
                'Error',
                'PDF_EMPTY_RENDER',
                'dompdf returned nothing to write, so no PDF was saved',
                'Intended file: ' . $filePath
            );

            return false;
        }

        $whyNot = self::writeOrWhyNot($filePath, $bytes);
        if ($whyNot !== '') {
            Logger::errorPlatform(
                'dompdf',
                'Error',
                'PDF_NOT_WRITTEN',
                'A PDF was not written, so it was not kept',
                'Intended file: ' . $filePath . ' — ' . $whyNot
            );

            return false;
        }

        return $filePath;
    }

    /**
     * Make the folder, write the bytes, and prove they all arrived. Answers an
     * empty string when the file is written in full, or the reason it was not.
     *
     * -------------------------------------------------------------------------
     * WHAT WAS WRONG BEFORE (#711), because the old code looked right
     * -------------------------------------------------------------------------
     * `create()` used to end:
     *
     *     file_put_contents($filePath, $dompdf->output());
     *     if (is_file($filePath) === true) {
     *         return $filePath;
     *     }
     *
     * The write's answer was discarded and success was decided by `is_file()`.
     * **`is_file()` is true for a file that is empty or half written**, which
     * is exactly what a failed write leaves behind. On a full disk or over a
     * hosting quota `file_put_contents()` writes some of the bytes, or none,
     * and returns a short count or `false` - and `is_file()` then said "yes",
     * so `create()` handed back the path and the caller treated a truncated
     * file as a finished PDF.
     *
     * Every caller was ALREADY written correctly: four of the five test
     * `!== false` on the spot, and the fifth (`Giving::renderStatementPdf()`)
     * hands the answer up to two callers that both test it - the file header
     * names each one. The fault was that `create()` never returned `false` for
     * a short write, so none of that could be reached. This class makes
     * giving statements, expense paperwork, asset labels and venue invoices: a
     * donor's annual giving statement that is half a page, served as though it
     * were correct, is worse than one that failed outright, because nothing
     * anywhere recorded a problem. Same reasoning as #705, where `fclose()`'s
     * answer was being thrown away.
     *
     * The house pattern existed and this one place did not follow it:
     * `DbBackup::snapshot()` compares the count inline where it writes its
     * manifest, and so do the installer's THREE writers -
     * `installProtectFolder()` in `_install/install_common.php`,
     * `installWriteFile()` in `_install/index.php`, and `installCodeEnsure()`
     * in `_install/owner_code.php`.
     *
     * Named by FUNCTION and never by line, and every name opened before it was
     * written here - which took three goes. A draft invented
     * `DbBackup::writeManifest()` and said "two"; the line numbers that
     * replaced it had already drifted by two; and the correction to THAT
     * invented `installWriteFileOrFail()`, in the sentence explaining why not
     * to invent names. Open the file.
     *
     * -------------------------------------------------------------------------
     * WHY IT ANSWERS A REASON RATHER THAN LOGGING
     * -------------------------------------------------------------------------
     * So that it can be TESTED. Logging needs a database and a request; this
     * method needs neither, which lets `tools/pdf-write-selftest.php` exercise
     * its branches against a real filesystem with no database, no dompdf and no
     * network - dompdf is not in this repository (`_libraries/` is never
     * committed), so a test that had to render could not run here at all. The
     * caller above turns the reason into the log line. Same shape as
     * `ExpenseRules::whyNotEditable()`, which takes facts for the same reason.
     *
     * WHAT NO TEST HERE CAN PROVE, so that nobody assumes it does: a genuine
     * SHORT write - the open succeeding and the disk filling part way through -
     * cannot be forced without a quota or a tiny filesystem, so that test
     * exercises the comparison only through the `false` case. (An earlier
     * version of this comment said "every branch", which was not true.)
     * `tools/audit-checks/check_pdf_write_verified.py` is what stops the
     * comparison being weakened back to "did anything get written" - measured
     * to leave every check in that test green.
     *
     * -------------------------------------------------------------------------
     * WHY A TEMPORARY NAME AND A RENAME, AND NOT A WRITE IN PLACE
     * -------------------------------------------------------------------------
     * Because the FIRST version of this fix introduced a worse fault than the
     * one it repaired, and an independent check measured it. That version wrote
     * straight to the real path and, when the write failed, deleted whatever
     * was there:
     *
     *     $removed = is_file($filePath) === false || unlink($filePath) === true;
     *
     * `file_put_contents()` can fail at OPEN - the target file read-only, a
     * read-only mount, the name taken by a directory - and then the existing
     * contents are untouched. That code deleted them anyway. These paths use
     * STABLE names (`_uploads/pdfs/claim_<id>.pdf`, recorded in
     * `tblExpenseClaims.fileName` and in the HMRC-retained
     * `tblExpenseClaimFiles`; and
     * `_uploads/giving/statements/<siteId>/statement-<donor>-<period>.pdf`), so
     * the database row went on pointing at a file that had just been removed: a
     * broken download link where a moment before there had been a working, if
     * stale, PDF. Measured - an existing 28-byte PDF at mode 0444 was gone
     * after the call.
     *
     * Writing under a temporary name and renaming makes that impossible. The
     * real file is replaced only once a COMPLETE one is ready, in one step, and
     * a failure anywhere leaves the old file exactly as it was. It is also the
     * house pattern: `DbBackup::snapshot()` writes its manifest this way and
     * gives the same reason. The temporary name is in the SAME folder on
     * purpose, because `rename()` is only atomic within one filesystem.
     *
     * WHAT IT COSTS, since the upsides are easy to list and these are not:
     *   - A process killed between the write and the rename leaves a
     *     `.part-…` file. BOTH HALVES OF THAT ARE CLOSED NOW (#721, the owner,
     *     3 November 2026), and they were two separate problems: `GdprEraser`'s
     *     glob for a donor's statements did not match one, so a killed run
     *     could leave a donor's name and home address in a file an erasure
     *     could not reach — the glob is widened AND `statementFileIsKept()`
     *     now refuses to treat a part-written file as tax evidence, which it
     *     takes BOTH, because widening the glob alone made the erasure FIND
     *     the file and then deliberately keep it, telling the administrator so
     *     (measured by an independent check); and nothing swept them, so they
     *     accumulated — `sweepPartFiles()` below is called nightly. The
     *     privacy half was the one that mattered: the housekeeping half only
     *     wasted disk.
     *   - `rename()` replaces the inode, so a file an administrator had
     *     tightened by hand (say to 0600) comes back at the umask default.
     *     Writing in place kept the old mode. New files are 0644 either way.
     *   - `rename()` needs permission on the FOLDER, not on the file it
     *     replaces, so a read-only existing PDF is now replaced where an
     *     in-place write would have been refused. That is the intent here -
     *     these documents are regenerated - but it is a change in behaviour.
     *
     * -------------------------------------------------------------------------
     * TWO MORE TRAPS, BOTH DELIBERATE
     * -------------------------------------------------------------------------
     * 1. **The bytes arrive as an argument.** Calling `$dompdf->output()`
     *    inside the `file_put_contents()` arguments and AGAIN for `strlen()`
     *    would render the document twice and compare the length of the second
     *    rendering against the bytes of the first - which can differ, because
     *    a PDF carries its own creation timestamp.
     * 2. **The folder question is "is it there?", never "did my `mkdir()`
     *    win?".** Two requests can make the same folder at once, and the loser
     *    gets `false` for a folder that now exists and is perfectly usable.
     *
     * @param string $filePath Absolute path the PDF should end up at
     * @param string $bytes    The rendered document, already in hand
     *
     * @return string '' when the file is written in full; otherwise why not
     */
    private static function writeOrWhyNot(string $filePath, string $bytes): string
    {
        $dir = dirname($filePath);
        if (is_dir($dir) === false) {
            mkdir($dir, 0755, true);
            if (is_dir($dir) === false) {
                // Reported here rather than left to the write below, which
                // would have failed too and called it a write problem - sending
                // whoever diagnosed it to the wrong place.
                return 'the folder ' . $dir . ' could not be made, so nothing was written';
            }
        }

        // 📄 Written under a temporary name IN THE SAME FOLDER, then renamed.
        //    The folder matters: `rename()` is only atomic within one
        //    filesystem, and a temporary directory can be on another.
        $temporary = $filePath . self::PART_MARK . bin2hex(random_bytes(6));

        $written = file_put_contents($temporary, $bytes);
        if ($written !== strlen($bytes)) {
            return self::tidyAway($temporary)
                . 'it was not written in full (' . strlen($bytes) . ' bytes to write, '
                . ($written === false ? 'the write failed outright' : $written . ' written')
                . ')';
        }

        if (rename($temporary, $filePath) === false) {
            return self::tidyAway($temporary)
                . 'it was written in full but could not be put in place as ' . basename($filePath);
        }

        return '';
    }

    /**
     * 🏷️ The mark a part-written file carries, and the ONE place it is named.
     *
     * `writeOrWhyNot()` builds a temporary name from it and `sweepPartFiles()`
     * looks for it. Two literals would be two places to change, and the one
     * that mattered would be the sweep — it would simply stop finding
     * anything, silently, which is the shape this repository keeps meeting.
     */
    public const PART_MARK = '.part-';

    /**
     * 📂 The folders `Pdf::create()` writes into, relative to `_uploads`, and
     *    the ONE list the sweep below is allowed to touch.
     *
     * ⚠️ THIS LIST IS WHAT STOPS THE SWEEP DELETING A MEMBER'S OWN UPLOAD, and
     *    it was added after an independent check measured exactly that
     *    happening. The first version swept the whole of `_uploads` for any
     *    name containing `PART_MARK`, under a docblock claiming "nothing else
     *    uses this mark today". That was the reassurance, and it was false:
     *    `_apps/documents/upload.php` and `_apps/recordings/upload.php` take
     *    the stored file's extension STRAIGHT FROM THE CLIENT FILENAME with no
     *    allow-list, and `pathinfo('report.part-x', PATHINFO_EXTENSION)` is
     *    `part-x` — so a member who uploaded `report.part-x` had it stored as
     *    `20261103_082700_aabbccdd.part-x` and DELETED an hour later, while the
     *    database row survived and the portal went on offering a file that was
     *    no longer there, with nothing logged. `.part` is what download
     *    managers call an unfinished file, so an accident was as likely as an
     *    attack.
     *
     *    Every one of the five callers of `Pdf::create()` was read to build
     *    this, not grepped for: `Giving::renderStatementPdf()` →
     *    `giving/statements/<siteId>`, `ExpensePdf` → `pdfs`, the two venue
     *    pages → `venues/tmp`, the asset labels page → `assets/labels`.
     *    `documents` and `recordings` are DELIBERATELY ABSENT and must stay so.
     *
     *    A NEW CALLER WRITING ELSEWHERE IS NOT SWEPT and nothing here will
     *    notice. That is the right way round: a folder missing from this list
     *    leaves litter, and a folder wrongly on it deletes somebody's work.
     */
    public const PART_FOLDERS = [
        'giving' . DIRECTORY_SEPARATOR . 'statements',
        'pdfs',
        'venues' . DIRECTORY_SEPARATOR . 'tmp',
        'assets' . DIRECTORY_SEPARATOR . 'labels',
    ];

    /**
     * 🧹 Remove part-written PDFs a killed process left behind (#721).
     *
     * Walks the folders in `PART_FOLDERS` under `$uploadsRoot` and removes
     * every file whose name ENDS with `PART_MARK` followed by exactly twelve
     * hexadecimal characters — the shape `writeOrWhyNot()` builds, and nothing
     * else — and whose last-changed time is older than `$olderThanSeconds`.
     * Answers `['removed' => n, 'left' => n]`, `left` being files that matched
     * and could not be deleted, which is a permissions problem worth reporting
     * rather than hiding.
     *
     * 🛡️ TWO GUARDS, AND NEITHER IS ENOUGH ON ITS OWN (the owner, 3 November
     *    2026, asked which to use and chose both).
     *
     *    The FOLDER list keeps the sweep away from anywhere a member can put a
     *    file at all. The NAME shape stops rubble inside those folders being
     *    taken on the strength of a loose match: `str_contains(…, '.part-')`
     *    would delete `report.part-x`, and a name ending in the mark plus
     *    twelve hex characters is what this class really writes.
     *
     *    Belt and braces on purpose: this method DELETES, and a deletion guard
     *    that rests on one condition rests on nobody ever editing that
     *    condition. Removing the name test alone once left all 31 checks of
     *    `tools/pdf-write-selftest.php` green while a day-old giving statement,
     *    an expense-claim PDF, an uploaded document and a recording were all
     *    deleted — measured.
     *
     * WHY AN AGE AT ALL, and why one hour (the owner, 3 November 2026).
     * A live write must never be swept out from under itself. A statement PDF
     * is written in well under a second, so an hour is a margin of some
     * thousands of times over. A shorter age buys nothing: the job runs
     * nightly, so the real delay is a day either way.
     *
     * WHAT THIS CANNOT DO, said plainly:
     *   - It cannot sweep a folder not on `PART_FOLDERS`, by design.
     *   - It is housekeeping, NOT a privacy guarantee. The privacy half of
     *     #721 is `GdprEraser::statementFilesOf()`'s widened glob plus
     *     `statementFileIsKept()` refusing to call a part-written file tax
     *     evidence, which together reach such a file the moment an erasure
     *     runs rather than at the next sweep. Never describe this method as
     *     what makes an erasure complete.
     *   - A file whose mtime cannot be read is LEFT ALONE, which is the safe
     *     direction but means `['removed' => 0, 'left' => 0]` has more than one
     *     cause: nothing matched, or nothing could be judged.
     *
     * @param string $uploadsRoot      The `_uploads` folder.
     * @param int    $olderThanSeconds Leave anything younger than this alone.
     *
     * @return array{removed:int,left:int}
     */
    public static function sweepPartFiles(string $uploadsRoot, int $olderThanSeconds = 3600): array
    {
        $removed = 0;
        $left    = 0;
        $cutoff  = time() - $olderThanSeconds;

        // The exact shape `writeOrWhyNot()` builds: the mark, then the twelve
        // hexadecimal characters of `bin2hex(random_bytes(6))`, at the END of
        // the name. Built from the constant so there is still ONE place the
        // mark is written down.
        $shape = '/' . preg_quote(self::PART_MARK, '/') . '[0-9a-f]{12}$/';

        foreach (self::PART_FOLDERS as $folder) {
            $root = $uploadsRoot . DIRECTORY_SEPARATOR . $folder;
            if (is_dir($root) === false) {
                // Legitimate on a new installation, or where a feature has
                // never been used. Not a fault.
                continue;
            }

            // 🚫 Symbolic links are NOT followed, in two ways that are both
            //    needed: `RecursiveDirectoryIterator` without
            //    `FOLLOW_SYMLINKS` does not recurse into a symlinked FOLDER,
            //    and `isLink()` below skips a symlinked FILE. Either on its own
            //    leaves a way out of the root, and this method deletes what it
            //    finds.
            //
            // 🧯 `CATCH_GET_CHILD` matters: without it one unreadable
            //    subfolder makes the whole walk THROW, and the sweep then
            //    removes nothing at all while reporting nothing — measured.
            //    With it, the unreadable folder is skipped and the rest is
            //    still tidied.
            $walk = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );

            foreach ($walk as $item) {
                if ($item->isFile() === false || $item->isLink() === true) {
                    continue;
                }
                if (preg_match($shape, $item->getFilename()) !== 1) {
                    continue;
                }
                // 🕐 `filemtime()` and not `fileatime()`: a read must not keep
                //    a dead temporary alive for ever.
                $when = @filemtime($item->getPathname());
                if ($when === false || $when > $cutoff) {
                    continue;
                }
                if (@unlink($item->getPathname()) === true) {
                    $removed++;
                    continue;
                }
                $left++;
            }
        }

        return ['removed' => $removed, 'left' => $left];
    }

    /**
     * Remove a part-written temporary file, and say so only when there is
     * something to say. Answers a prefix for the reason being built, which is
     * empty in the ordinary case.
     *
     * WHY THIS IS SEPARATE, and why it answers a PREFIX rather than a boolean:
     * an earlier version of this code wrote
     * `$removed = is_file($p) === false || unlink($p) === true;` and then put
     * "the part-written file was removed" in the message whenever `$removed`
     * was true. That conflated "there was nothing to remove" with "I removed
     * something", so a write that failed at OPEN - nothing created at all -
     * told the administrator a stub had been cleaned up that never existed.
     * An independent check measured it in two of the self-test's own fixtures.
     * Saying nothing is the honest answer when nothing happened.
     */
    private static function tidyAway(string $temporary): string
    {
        if (is_file($temporary) === false) {
            return '';
        }
        if (unlink($temporary) === true) {
            return 'the part-written file was removed, and ';
        }

        return 'the part-written file ' . basename($temporary) . ' COULD NOT BE REMOVED and is'
            . ' still on disk (it is safe to delete), and ';
    }
}
