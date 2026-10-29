<?php
// Path: tools/backup-archive-selftest.php
/**
 * -----------------------------------------------------------------------------
 * One snapshot as one encrypted file — self-test 📦🔐 (#569)
 * -----------------------------------------------------------------------------
 * `Portal\Core\BackupArchive` is the ONE place a snapshot folder becomes a
 * single encrypted file and back. Everything it guards is worth checking hard,
 * because a fault here has two shapes and both are bad:
 *
 *   • a file nobody can open — the customer believes they have a copy of their
 *     data and does not;
 *   • a file that opens when it should not, or opens something that is not what
 *     was packed — a damaged or tampered backup restored over a live database.
 *
 * NO DATABASE IS NEEDED, which is deliberate: the class opens no connection, so
 * this runs anywhere PHP and libsodium do. It writes only inside a folder it
 * makes itself and removes that folder at the end.
 *
 * HOW TO RUN IT
 *   php tools/backup-archive-selftest.php
 *
 * @package   WebMS Intra
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/569
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// The class needs no start-up and no database. PORTAL_VERSION is only read for
// a note in the envelope, so a value is defined here to exercise that path.
define('PORTAL_VERSION', '1.4.0-selftest');
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core'
    . DIRECTORY_SEPARATOR . 'BackupArchive.php';

use Portal\Core\BackupArchive;

$passed = 0;
$failed = 0;

function ok(string $id, string $words): void
{
    global $passed;
    $passed++;
    echo "PASS — {$id} {$words}\n";
}

function bad(string $id, string $words, string $detail = ''): void
{
    global $failed;
    $failed++;
    echo "FAIL — {$id} {$words}\n";
    if ($detail !== '') {
        echo '         ' . mb_substr($detail, 0, 500) . "\n";
    }
}

function head(string $text): void
{
    echo "\n=== {$text} ===\n";
}

/** Run something that should throw, and hand back the message it threw. */
function refusal(callable $what): string
{
    try {
        $what();
    } catch (\Throwable $e) {
        return $e->getMessage();
    }

    return '';
}

/** Remove a folder made by this run, one level deep, never following a link. */
function scrub(string $dir): void
{
    if (is_dir($dir) === false || is_link($dir) === true) {
        return;
    }
    foreach ((array) scandir($dir) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) === true && is_link($path) === false) {
            scrub($path);
            continue;
        }
        @unlink($path);
    }
    @rmdir($dir);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'backup-archive-selftest-' . getmypid()
    . '-' . bin2hex(random_bytes(6));
if (@mkdir($root, 0700, true) === false) {
    echo "REFUSED — could not make a scratch folder at {$root}\n";
    exit(1);
}
register_shutdown_function(static function () use ($root): void {
    scrub($root);
});

// ⚠️ AN UNEXPECTED EXCEPTION IS A FAILURE, NOT A CRASH.
//
//    The checks below call pack() and unpack() directly on the paths that are
//    supposed to work. Before this handler, a fault in one of those threw, PHP
//    stopped, and the run printed NO TALLY AT ALL — so a planted fault that
//    this test caught perfectly well looked, to anything reading the output for
//    "N passed", exactly like a test that had not run. Measured: planting a
//    packer that never tags its last chunk produced a fatal error and no
//    counts, which is the hardest kind of result to read correctly.
set_exception_handler(static function (\Throwable $e): void {
    global $passed, $failed;
    $failed++;
    echo 'FAIL — (unexpected) ' . $e->getMessage() . "\n";
    echo '         thrown at ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    echo "\n" . ($passed + $failed) . " checks: {$passed} passed, {$failed} failed.\n";
    echo "NOTE: the run stopped here, so the checks after this point did not run.\n";
    exit(1);
});

echo "One snapshot as one encrypted file — self-test (#569)\n";
echo 'PHP ' . PHP_VERSION . ', libsodium ' . SODIUM_LIBRARY_VERSION . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";

const KEY     = 'a-long-enough-key-for-the-test';
const KEYFILE = 'the installation key file contents, which are arbitrary bytes';

/**
 * Build a snapshot folder that looks like one DbBackup writes: one .jsonl file
 * per table, `_manifest.json` last, and — importantly — RAW BYTES in one of
 * them, because a calendar import really does store 32 raw bytes and that is
 * what broke the old backup format (#577).
 */
function makeSnapshot(string $dir, int $bigRows = 3): array
{
    mkdir($dir, 0700, true);
    $files = [];

    $files['tblUsers.jsonl'] =
        '{"format":2,"table":"tblUsers","columns":["userID","fullName"],"base64":[]}' . "\n"
        . '["1","Ada Lovelace"]' . "\n"
        . '["2","Åsa Østergaard"]' . "\n"
        . '{"end":true,"rows":2}' . "\n";

    // Raw bytes, every value 0-255, to prove the packing is byte-exact and
    // nothing is being treated as text along the way.
    $raw = '';
    for ($i = 0; $i < 256; $i++) {
        $raw .= chr($i);
    }
    $files['tblEvents.jsonl'] =
        '{"format":2,"table":"tblEvents","columns":["eventID","token"],"base64":["token"]}' . "\n"
        . '["1","' . base64_encode($raw) . '"]' . "\n"
        . '{"end":true,"rows":1}' . "\n";

    // A file deliberately larger than one chunk (64 KB), so the chunk
    // boundaries are crossed several times and a file spans them.
    $line = '["' . str_repeat('x', 300) . '"]' . "\n";
    $big  = '{"format":2,"table":"tblActivityLogs","columns":["detail"],"base64":[]}' . "\n";
    for ($i = 0; $i < $bigRows; $i++) {
        $big .= $line;
    }
    $big .= '{"end":true,"rows":' . $bigRows . '}' . "\n";
    $files['tblActivityLogs.jsonl'] = $big;

    // An empty table file: zero bytes is a real case and an easy one to get
    // wrong, because the entry has a line and then nothing at all.
    $files['tblEmpty.jsonl'] = '';

    $files['_manifest.json'] = json_encode([
        'format' => 2,
        'tables' => ['tblUsers' => 2, 'tblEvents' => 1, 'tblActivityLogs' => $bigRows, 'tblEmpty' => 0],
        'taken'  => gmdate('c'),
    ], JSON_PRETTY_PRINT);

    foreach ($files as $name => $contents) {
        file_put_contents($dir . DIRECTORY_SEPARATOR . $name, $contents);
    }

    return $files;
}

// =============================================================================
head('A. There and back again');
// =============================================================================

$snapshot = $root . DIRECTORY_SEPARATOR . 'upgrade-20261029-120000';
$original = makeSnapshot($snapshot, 400);   // ~120 KB, so several chunks
$packed   = $root . DIRECTORY_SEPARATOR . 'one.portalbackup';

$result = BackupArchive::pack($snapshot, $packed, KEY, BackupArchive::KEY_PASSPHRASE);
ok('A01', 'a snapshot packs without complaint');

if ($result['entries'] === 5 && $result['bytes'] > 0) {
    ok('A02', 'it reports the five files it packed, and a size');
} else {
    bad('A02', 'expected 5 entries and a non-zero size', json_encode($result));
}

if (is_file($packed) === true && filesize($packed) > 100000) {
    ok('A03', 'the file on disk is about the size of the snapshot');
} else {
    bad('A03', 'the packed file is missing or implausibly small', (string) @filesize($packed));
}

$out = $root . DIRECTORY_SEPARATOR . 'back';
$un  = BackupArchive::unpack($packed, $out, KEY);
ok('A04', 'and it unpacks with the same key');

if ($un['entries'] === 5 && $un['snapshot'] === 'upgrade-20261029-120000') {
    ok('A05', 'reporting the same five files and the snapshot\'s own name');
} else {
    bad('A05', 'expected 5 entries and the snapshot name', json_encode($un));
}

$differences = [];
foreach ($original as $name => $contents) {
    $backPath = $out . DIRECTORY_SEPARATOR . $name;
    if (is_file($backPath) === false) {
        $differences[] = $name . ' is missing';
        continue;
    }
    $got = (string) file_get_contents($backPath);
    if ($got !== $contents) {
        $differences[] = $name . ' differs (' . strlen($contents) . ' bytes in, ' . strlen($got) . ' out)';
    }
}
if ($differences === []) {
    ok('A06', 'every file comes back byte for byte, raw bytes and an empty file included');
} else {
    bad('A06', 'files came back changed', implode('; ', $differences));
}

$cameBack = array_values(array_diff((array) scandir($out), ['.', '..']));
sort($cameBack);
$wanted = array_keys($original);
sort($wanted);
if ($cameBack === $wanted) {
    ok('A07', 'and nothing else came with them');
} else {
    bad('A07', 'the folder holds something unexpected', implode(', ', $cameBack));
}

// =============================================================================
head('B. The key, and the key file');
// =============================================================================

$why = refusal(static fn () => BackupArchive::unpack($packed, $root . DIRECTORY_SEPARATOR . 'nope', 'the-wrong-key-entirely'));
if ($why !== '' && is_dir($root . DIRECTORY_SEPARATOR . 'nope') === false) {
    ok('B01', 'the wrong key is refused, and leaves no half-unpacked folder behind');
} else {
    bad('B01', 'a wrong key should be refused and clean up', $why === '' ? 'it did not throw' : 'folder left behind');
}
if (str_contains($why, 'wrong key') === true && str_contains($why, 'damaged') === true) {
    ok('B02', 'and says it could be either the key or the file, rather than guessing');
} else {
    bad('B02', 'the message should name both possibilities', $why);
}

$bothFile = $root . DIRECTORY_SEPARATOR . 'both.portalbackup';
BackupArchive::pack($snapshot, $bothFile, KEY, BackupArchive::KEY_BOTH, KEYFILE);
ok('B03', 'a snapshot packs with the key file included as well');

$envelope = BackupArchive::envelopeOf($bothFile);
if (($envelope['keyMode'] ?? '') === BackupArchive::KEY_BOTH) {
    ok('B04', 'and the file says so in its own description, so an import knows what to ask for');
} else {
    bad('B04', 'the envelope should record the key mode', json_encode($envelope));
}

$why = refusal(static fn () => BackupArchive::unpack($bothFile, $root . DIRECTORY_SEPARATOR . 'b1', KEY));
if (str_contains($why, 'key file') === true) {
    ok('B05', 'opening it without the key file is refused, and says which part is missing');
} else {
    bad('B05', 'expected a message about the key file', $why);
}

$why = refusal(static fn () => BackupArchive::unpack($bothFile, $root . DIRECTORY_SEPARATOR . 'b2', KEY, 'a different key file'));
if ($why !== '') {
    ok('B06', 'and the WRONG key file is refused too');
} else {
    bad('B06', 'a different key file should not open it');
}

$bothOut = $root . DIRECTORY_SEPARATOR . 'b3';
$un      = BackupArchive::unpack($bothFile, $bothOut, KEY, KEYFILE);
if ($un['entries'] === 5
    && (string) file_get_contents($bothOut . DIRECTORY_SEPARATOR . 'tblUsers.jsonl') === $original['tblUsers.jsonl']) {
    ok('B07', 'with both the key and the key file it opens and the contents match');
} else {
    bad('B07', 'the both-mode round trip failed', json_encode($un));
}

// The key file must make a real difference: the same key with no key file must
// not open a both-mode file, which B05 covers, and a passphrase-mode file must
// not need one.
$un = BackupArchive::unpack($packed, $root . DIRECTORY_SEPARATOR . 'b4', KEY, KEYFILE);
if ($un['entries'] === 5) {
    ok('B08', 'a key-only backup still opens when a key file is offered but not needed');
} else {
    bad('B08', 'an unneeded key file should be ignored', json_encode($un));
}

$why = refusal(static fn () => BackupArchive::pack($snapshot, $root . DIRECTORY_SEPARATOR . 'short.portalbackup', 'tooshort', BackupArchive::KEY_PASSPHRASE));
if (str_contains($why, (string) BackupArchive::MIN_PASSPHRASE) === true) {
    ok('B09', 'a key shorter than the minimum is refused, and the message says the number');
} else {
    bad('B09', 'expected a refusal naming the minimum length', $why);
}

$why = refusal(static fn () => BackupArchive::pack($snapshot, $root . DIRECTORY_SEPARATOR . 'nokf.portalbackup', KEY, BackupArchive::KEY_BOTH, null));
if ($why !== '') {
    ok('B10', 'asking to include a key file when there is none is refused');
} else {
    bad('B10', 'both-mode with no key file should be refused');
}

// =============================================================================
head('C. A file that has been meddled with, or cut short');
// =============================================================================

/** Copy the packed file and change one byte at a given offset. */
function tamper(string $from, string $to, int $offset): void
{
    copy($from, $to);
    $handle = fopen($to, 'r+b');
    fseek($handle, $offset);
    $byte = fread($handle, 1);
    fseek($handle, $offset);
    fwrite($handle, chr((ord($byte) + 1) % 256));
    fclose($handle);
}

$envelopeLength = strlen((string) json_encode(BackupArchive::envelopeOf($packed), JSON_UNESCAPED_SLASHES));

// A byte in the middle of the encrypted part.
$bent = $root . DIRECTORY_SEPARATOR . 'bent.portalbackup';
tamper($packed, $bent, (int) (filesize($packed) / 2));
$why = refusal(static fn () => BackupArchive::unpack($bent, $root . DIRECTORY_SEPARATOR . 'c1', KEY));
if ($why !== '' && is_dir($root . DIRECTORY_SEPARATOR . 'c1') === false) {
    ok('C01', 'one changed byte in the middle is caught, and nothing is left unpacked');
} else {
    bad('C01', 'a tampered byte should be refused', $why === '' ? 'it opened happily' : 'folder left behind');
}

// A byte in libsodium's own stream header.
$bentHeader = $root . DIRECTORY_SEPARATOR . 'bent-header.portalbackup';
tamper($packed, $bentHeader, $envelopeLength + 3);
$why = refusal(static fn () => BackupArchive::unpack($bentHeader, $root . DIRECTORY_SEPARATOR . 'c2', KEY));
if ($why !== '') {
    ok('C02', 'a changed byte in the stream header is caught');
} else {
    bad('C02', 'a tampered stream header should be refused');
}

// Cut the file short. This is the one a checksum at the end would catch and a
// per-chunk check alone would NOT: every chunk left is genuine, and the only
// thing wrong is that the last one never said it was last.
$short = $root . DIRECTORY_SEPARATOR . 'short-file.portalbackup';
copy($packed, $short);
$keep = (int) (filesize($packed) * 0.6);
$fh   = fopen($short, 'r+b');
ftruncate($fh, $keep);
fclose($fh);
$why = refusal(static fn () => BackupArchive::unpack($short, $root . DIRECTORY_SEPARATOR . 'c3', KEY));
if ($why !== '' && (str_contains($why, 'cut short') === true || str_contains($why, 'complete') === true)) {
    ok('C03', 'a file cut short is refused AS cut short, not opened as a complete copy');
} else {
    bad('C03', 'expected a "cut short" refusal', $why === '' ? 'it opened happily' : $why);
}

// Remove only the FINAL tag's chunk: the file is otherwise perfect.
$noFinal = $root . DIRECTORY_SEPARATOR . 'nofinal.portalbackup';
copy($packed, $noFinal);
$all = (string) file_get_contents($noFinal);
// Drop the last 4-byte length and its chunk by walking the chunk list.
$cursor = $envelopeLength + 1 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
$starts = [];
while ($cursor < strlen($all)) {
    $starts[] = $cursor;
    $len      = (int) unpack('N', substr($all, $cursor, 4))[1];
    $cursor  += 4 + $len;
}
if (count($starts) >= 2) {
    file_put_contents($noFinal, substr($all, 0, (int) end($starts)));
    $why = refusal(static fn () => BackupArchive::unpack($noFinal, $root . DIRECTORY_SEPARATOR . 'c4', KEY));
    if ($why !== '') {
        ok('C04', 'a file missing only its last piece is refused');
    } else {
        bad('C04', 'a file with no FINAL piece should be refused');
    }
} else {
    bad('C04', 'the test fixture did not produce several chunks, so this proves nothing',
        count($starts) . ' chunk(s)');
}

// An envelope that has been rewritten to claim another form.
$wrongForm = $root . DIRECTORY_SEPARATOR . 'wrongform.portalbackup';
$body      = substr($all, strpos($all, "\n") + 1);
file_put_contents($wrongForm, json_encode(['format' => 99, 'keyMode' => BackupArchive::KEY_PASSPHRASE, 'salt' => base64_encode(random_bytes(16))]) . "\n" . $body);
$why = refusal(static fn () => BackupArchive::envelopeOf($wrongForm));
if (str_contains($why, '99') === true) {
    ok('C05', 'a backup written in another form is refused, saying which form it is');
} else {
    bad('C05', 'expected a refusal naming the form', $why);
}

// Something that is not a backup at all.
$notOurs = $root . DIRECTORY_SEPARATOR . 'holiday.jpg';
file_put_contents($notOurs, str_repeat("\xff\xd8\xff\xe0", 2000));
$why = refusal(static fn () => BackupArchive::envelopeOf($notOurs));
if ($why !== '') {
    ok('C06', 'a file that is not a backup at all is refused without reading it whole');
} else {
    bad('C06', 'a JPEG should not be accepted as a backup');
}

$why = refusal(static fn () => BackupArchive::envelopeOf($root . DIRECTORY_SEPARATOR . 'no-such-file'));
if ($why !== '') {
    ok('C07', 'a file that is not there is refused');
} else {
    bad('C07', 'a missing file should be refused');
}

// C08 — A DECLARED CHUNK LENGTH THAT WOULD EAT THE SERVER'S MEMORY.
//
// The four bytes giving a chunk's length are read BEFORE anything is
// authenticated — they have to be, because they say how much to read. So a
// hostile file can claim any size at all, and the only thing between that claim
// and the server's memory is one bound. Nothing covered it until the bound was
// planted away and all 36 checks still passed.
$huge = $root . DIRECTORY_SEPARATOR . 'huge-chunk.portalbackup';
$envelopeOnly = json_encode([
    'format'    => BackupArchive::FORMAT,
    'snapshot'  => 'upgrade-20261029-170000',
    'keyMode'   => BackupArchive::KEY_PASSPHRASE,
    'salt'      => base64_encode(random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES)),
    'pwhashOps' => SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
    'pwhashMem' => SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
], JSON_UNESCAPED_SLASHES);
// A valid-looking stream header, then a chunk claiming 2 GB and almost no bytes.
file_put_contents(
    $huge,
    $envelopeOnly . "\n" . random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES)
    . pack('N', 2147483647) . 'nowhere near that many bytes'
);
// RUN IN A CHILD PROCESS, and that is the whole point of this check.
//
// Without the bound, `fread($handle, 2147483647)` is a MEMORY-EXHAUSTION FATAL,
// not an exception — so nothing in this process can catch it, `refusal()`
// returns nothing, and the run dies with no tally. Measured: the first version
// of this check sat in-process, the plant killed PHP outright, and the output
// read exactly like a test that had not run.
//
// A child process can be asked the question properly: did it REFUSE cleanly, or
// did it die? A small memory limit makes the difference quick either way.
$childScript = $root . DIRECTORY_SEPARATOR . 'try-huge.php';
file_put_contents($childScript, <<<'CHILD'
<?php
declare(strict_types=1);
define('PORTAL_VERSION', 'child');
require $argv[1];
try {
    \Portal\Core\BackupArchive::unpack($argv[2], $argv[3], $argv[4]);
    echo "OPENED";
} catch (\Throwable $e) {
    echo "REFUSED: " . $e->getMessage();
}
CHILD);

$childOutput = [];
$childStatus = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' -d memory_limit=64M ' . escapeshellarg($childScript)
    . ' ' . escapeshellarg(dirname(__DIR__) . '/web/_core/BackupArchive.php')
    . ' ' . escapeshellarg($huge)
    . ' ' . escapeshellarg($root . DIRECTORY_SEPARATOR . 'c8')
    . ' ' . escapeshellarg(KEY) . ' 2>&1',
    $childOutput,
    $childStatus
);
$childSaid = implode("\n", $childOutput);

if (str_starts_with($childSaid, 'REFUSED:') === true && $childStatus === 0) {
    ok('C08', 'a chunk claiming two gigabytes is refused cleanly, rather than killing the request');
} else {
    bad('C08', 'an impossible chunk length must be refused, not fatal',
        'exit ' . $childStatus . ': ' . mb_substr($childSaid, 0, 300));
}

// C09 — A FILE THAT NEVER SAYS IT IS FINISHED.
//
// The closing line arrives inside the encrypted stream, so it is authentic. But
// libsodium's FINAL tag is a SEPARATE statement that nothing follows. A packer
// that wrote the closing line and never tagged the last chunk as final passed
// every check at first, which made the class header's claim that FINAL "proves
// the file is whole" untrue. Both are required now.
//
// Built by hand, because the packer cannot be made to do it any more.
$saltC9 = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
$keyC9  = sodium_crypto_pwhash(
    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
    KEY,
    $saltC9,
    SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
    SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
    SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
);
[$pushC9, $headerC9] = sodium_crypto_secretstream_xchacha20poly1305_init_push($keyC9);
$payloadC9 = json_encode(['name' => 'tblUsers.jsonl', 'bytes' => 5], JSON_UNESCAPED_SLASHES) . "\n" . 'hello'
    . json_encode(['end' => true, 'entries' => 1], JSON_UNESCAPED_SLASHES) . "\n";
// TAG_MESSAGE, not TAG_FINAL: everything else about this file is perfect.
$cipherC9 = sodium_crypto_secretstream_xchacha20poly1305_push(
    $pushC9,
    $payloadC9,
    '',
    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
);
$noFinalTag = $root . DIRECTORY_SEPARATOR . 'no-final-tag.portalbackup';
file_put_contents(
    $noFinalTag,
    json_encode([
        'format'    => BackupArchive::FORMAT,
        'snapshot'  => 'upgrade-20261029-180000',
        'keyMode'   => BackupArchive::KEY_PASSPHRASE,
        'salt'      => base64_encode($saltC9),
        'pwhashOps' => SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
        'pwhashMem' => SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
    ], JSON_UNESCAPED_SLASHES) . "\n" . $headerC9 . pack('N', strlen($cipherC9)) . $cipherC9
);
$why = refusal(static fn () => BackupArchive::unpack($noFinalTag, $root . DIRECTORY_SEPARATOR . 'c9', KEY));
if ($why !== '' && str_contains($why, 'finished') === true) {
    ok('C09', 'a file whose last piece is not tagged as the last is refused, even though it reads perfectly');
} else {
    bad('C09', 'a file with no FINAL tag must be refused', $why === '' ? 'it was accepted' : $why);
}

// C10 — and the ordinary case is NOT refused by that, so C09 has not simply
//       broken packing. (When a check exists to prove something ordinary still
//       works, the fixture has to actually be ordinary.)
$plainOut = $root . DIRECTORY_SEPARATOR . 'c10';
$un       = BackupArchive::unpack($packed, $plainOut, KEY);
if ($un['entries'] === 5) {
    ok('C10', 'a properly written backup still opens, so the FINAL rule has not broken packing');
} else {
    bad('C10', 'the ordinary round trip should still work', json_encode($un));
}

// C11 — AN ENTRY THAT DECLARES MORE BYTES THAN FOLLOW IT.
//
// This HUNG, and the independent check reproduced it: an entry line saying
// 1000 bytes followed by five, in one FINAL chunk. The old end-of-stream test
// required no entry to be pending, so the one case that mattered fell through
// it, nothing more could be pulled, and the loop span at 100% CPU — burning a
// web worker until the host killed it, and leaving the half-unpacked folder
// behind because the clean-up lives in a `catch` that never ran.
//
// Run in a CHILD PROCESS with a short time limit, because a hang cannot be
// observed from inside the process that is hanging. A clean refusal and a
// folder with nothing in it are what "fixed" means.
$saltC11 = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
$keyC11  = sodium_crypto_pwhash(
    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
    KEY,
    $saltC11,
    SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
    SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
    SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
);
[$pushC11, $headerC11] = sodium_crypto_secretstream_xchacha20poly1305_init_push($keyC11);
$payloadC11 = json_encode(['name' => 'tblUsers.jsonl', 'bytes' => 1000], JSON_UNESCAPED_SLASHES) . "\n" . 'hello';
$cipherC11  = sodium_crypto_secretstream_xchacha20poly1305_push(
    $pushC11,
    $payloadC11,
    '',
    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
);
$shortEntry = $root . DIRECTORY_SEPARATOR . 'short-entry.portalbackup';
file_put_contents(
    $shortEntry,
    json_encode([
        'format'    => BackupArchive::FORMAT,
        'snapshot'  => 'upgrade-20261029-210000',
        'keyMode'   => BackupArchive::KEY_PASSPHRASE,
        'salt'      => base64_encode($saltC11),
        'pwhashOps' => SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
        'pwhashMem' => SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
    ], JSON_UNESCAPED_SLASHES) . "\n" . $headerC11 . pack('N', strlen($cipherC11)) . $cipherC11
);

$hangOut    = $root . DIRECTORY_SEPARATOR . 'c11';
$hangOutput = [];
$hangStatus = 0;
exec(
    'timeout 20 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($childScript)
    . ' ' . escapeshellarg(dirname(__DIR__) . '/web/_core/BackupArchive.php')
    . ' ' . escapeshellarg($shortEntry)
    . ' ' . escapeshellarg($hangOut)
    . ' ' . escapeshellarg(KEY) . ' 2>&1',
    $hangOutput,
    $hangStatus
);
$hangSaid = implode("\n", $hangOutput);
if ($hangStatus === 124) {
    bad('C11', 'an entry declaring more bytes than follow it must not hang', 'the child process had to be killed after 20 seconds');
} elseif (str_starts_with($hangSaid, 'REFUSED:') === true && str_contains($hangSaid, 'cut short') === true) {
    ok('C11', 'an entry declaring more bytes than follow it is refused AS cut short, and does not hang');
} else {
    bad('C11', 'expected a "cut short" refusal', 'exit ' . $hangStatus . ': ' . mb_substr($hangSaid, 0, 300));
}
if (is_dir($hangOut) === false) {
    ok('C11b', 'and no half-unpacked folder is left behind');
} else {
    bad('C11b', 'the half-unpacked folder must be removed', implode(', ', array_diff((array) scandir($hangOut), ['.', '..'])));
}

// C12 — A FILE DEMANDING ABSURD KEY WORK.
//
// The envelope is plaintext and unauthenticated, and the key work is done
// BEFORE the stream is authenticated — so anybody who can touch the file sets
// the cost, with no key at all, and any typed key starts the work. Measured by
// the independent check: 2 GB took 3.2 seconds, 16 GB took 30.8, and 1 TB never
// returned. Argon2 asks the operating system directly, so PHP's memory_limit
// does not apply. The bound is the whole defence.
$greedy = $root . DIRECTORY_SEPARATOR . 'greedy-key-work.portalbackup';
copy($shortEntry, $greedy);
$greedyBody = (string) file_get_contents($greedy);
file_put_contents(
    $greedy,
    json_encode([
        'format'    => BackupArchive::FORMAT,
        'snapshot'  => 'upgrade-20261029-220000',
        'keyMode'   => BackupArchive::KEY_PASSPHRASE,
        'salt'      => base64_encode($saltC11),
        'pwhashOps' => 2,
        'pwhashMem' => 1099511627776,   // one terabyte
    ], JSON_UNESCAPED_SLASHES) . "\n" . substr($greedyBody, strpos($greedyBody, "\n") + 1)
);
$greedyOutput = [];
$greedyStatus = 0;
exec(
    'timeout 20 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($childScript)
    . ' ' . escapeshellarg(dirname(__DIR__) . '/web/_core/BackupArchive.php')
    . ' ' . escapeshellarg($greedy)
    . ' ' . escapeshellarg($root . DIRECTORY_SEPARATOR . 'c12')
    . ' ' . escapeshellarg(KEY) . ' 2>&1',
    $greedyOutput,
    $greedyStatus
);
$greedySaid = implode("\n", $greedyOutput);
if ($greedyStatus === 124) {
    bad('C12', 'a file demanding a terabyte of key work must be refused at once', 'the child had to be killed after 20 seconds');
} elseif (str_starts_with($greedySaid, 'REFUSED:') === true && str_contains($greedySaid, 'key work') === true) {
    ok('C12', 'a file demanding a terabyte of key work is refused at once, and says so');
} else {
    bad('C12', 'expected a refusal about key work', 'exit ' . $greedyStatus . ': ' . mb_substr($greedySaid, 0, 300));
}

// C12b — and trivial key work is refused too, because a file claiming it would
//        make guessing the typed key cheap for whoever holds the file.
$feeble = $root . DIRECTORY_SEPARATOR . 'feeble-key-work.portalbackup';
file_put_contents(
    $feeble,
    json_encode([
        'format'    => BackupArchive::FORMAT,
        'snapshot'  => 'upgrade-20261029-230000',
        'keyMode'   => BackupArchive::KEY_PASSPHRASE,
        'salt'      => base64_encode($saltC11),
        'pwhashOps' => 1,
        'pwhashMem' => 1024,
    ], JSON_UNESCAPED_SLASHES) . "\n" . substr($greedyBody, strpos($greedyBody, "\n") + 1)
);
$why = refusal(static fn () => BackupArchive::envelopeOf($feeble));
if ($why !== '' && str_contains($why, 'key work') === true) {
    ok('C12b', 'and so is a file claiming trivially little key work');
} else {
    bad('C12b', 'expected a refusal about key work', $why);
}

// C12c — the ordinary costs this release writes are of course accepted, so the
//        bounds have not simply closed the door.
$envelope = BackupArchive::envelopeOf($packed);
if ((int) $envelope['pwhashMem'] === SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE) {
    ok('C12c', 'a file this release wrote passes the same bounds');
} else {
    bad('C12c', 'the bounds must accept what pack() writes', json_encode($envelope));
}

// =============================================================================
head('D. What it refuses to write, and where');
// =============================================================================

$why = refusal(static fn () => BackupArchive::pack($snapshot, $packed, KEY, BackupArchive::KEY_PASSPHRASE));
if ($why !== '') {
    ok('D01', 'it will not write over a file that already exists');
} else {
    bad('D01', 'packing over an existing file should be refused');
}

$why = refusal(static fn () => BackupArchive::unpack($packed, $out, KEY));
if ($why !== '') {
    ok('D02', 'and will not unpack into a folder that already exists');
} else {
    bad('D02', 'unpacking into an existing folder should be refused');
}

$why = refusal(static fn () => BackupArchive::pack($root . DIRECTORY_SEPARATOR . 'not-a-snapshot', $root . DIRECTORY_SEPARATOR . 'x.portalbackup', KEY, BackupArchive::KEY_PASSPHRASE));
if ($why !== '') {
    ok('D03', 'packing a folder that is not there is refused');
} else {
    bad('D03', 'a missing snapshot should be refused');
}

$emptyDir = $root . DIRECTORY_SEPARATOR . 'upgrade-20261029-130000';
mkdir($emptyDir, 0700);
$why = refusal(static fn () => BackupArchive::pack($emptyDir, $root . DIRECTORY_SEPARATOR . 'empty.portalbackup', KEY, BackupArchive::KEY_PASSPHRASE));
if ($why !== '') {
    ok('D04', 'packing an empty folder is refused rather than writing an empty backup');
} else {
    bad('D04', 'an empty snapshot should be refused');
}

$why = refusal(static fn () => BackupArchive::pack($snapshot, $root . DIRECTORY_SEPARATOR . 'badmode.portalbackup', KEY, 'something-else'));
if ($why !== '') {
    ok('D05', 'a way of protecting the file that this release does not know is refused');
} else {
    bad('D05', 'an unknown key mode should be refused');
}

// 🛡️ A backup naming a file OUTSIDE the folder must not be able to write there.
//    Built by hand, because nothing in the portal would ever write such a name.
$evilDir  = $root . DIRECTORY_SEPARATOR . 'upgrade-20261029-140000';
mkdir($evilDir, 0700);
file_put_contents($evilDir . DIRECTORY_SEPARATOR . 'tblUsers.jsonl', 'x');
file_put_contents($evilDir . DIRECTORY_SEPARATOR . '_manifest.json', '{}');
$evilPacked = $root . DIRECTORY_SEPARATOR . 'evil.portalbackup';
BackupArchive::pack($evilDir, $evilPacked, KEY, BackupArchive::KEY_PASSPHRASE);

// Rebuild the same backup with one entry renamed to climb out of the folder.
// Done by packing a folder whose file is named safely, then rewriting the
// plaintext entry line inside a freshly encrypted stream — the only way to get
// such a name in, which is itself the point: the packer will not make one.
$evilOut = $root . DIRECTORY_SEPARATOR . 'evil-out';
$key     = sodium_crypto_pwhash(
    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
    KEY,
    $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES),
    SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
    SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
    SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
);
[$push, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
$payload = json_encode(['name' => '../escaped.txt', 'bytes' => 5]) . "\n" . 'oops!'
    . json_encode(['end' => true, 'entries' => 1]) . "\n";
$cipher  = sodium_crypto_secretstream_xchacha20poly1305_push(
    $push,
    $payload,
    '',
    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
);
$escaper = $root . DIRECTORY_SEPARATOR . 'escaper.portalbackup';
file_put_contents(
    $escaper,
    json_encode([
        'format'    => BackupArchive::FORMAT,
        'snapshot'  => 'upgrade-20261029-150000',
        'keyMode'   => BackupArchive::KEY_PASSPHRASE,
        'salt'      => base64_encode($salt),
        'pwhashOps' => SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
        'pwhashMem' => SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
    ], JSON_UNESCAPED_SLASHES) . "\n" . $streamHeader . pack('N', strlen($cipher)) . $cipher
);

$why = refusal(static fn () => BackupArchive::unpack($escaper, $evilOut, KEY));
if ($why !== '' && is_file($root . DIRECTORY_SEPARATOR . 'escaped.txt') === false) {
    ok('D06', 'a backup naming a file outside the folder is refused, and writes nothing there');
} else {
    bad('D06', 'a path-climbing name must be refused', $why === '' ? 'it was accepted' : 'a file escaped the folder');
}

// D07 — A PACK THAT FAILS PART WAY MUST LEAVE NO FILE.
//
// A half-written backup is worse than none: somebody keeps it believing it is a
// copy of their data, and finds out otherwise on the day they need it. Nothing
// covered this until the clean-up was planted away and all 39 checks passed.
//
// The failure is caused by making one file in the snapshot unreadable, which is
// the most realistic way a pack dies part way (a permissions mistake, a file
// removed by something else). Skipped when running as root, which can read
// anything.
$brokenSnapshot = $root . DIRECTORY_SEPARATOR . 'upgrade-20261029-190000';
makeSnapshot($brokenSnapshot, 10);
$unreadable = $brokenSnapshot . DIRECTORY_SEPARATOR . 'tblUsers.jsonl';
chmod($unreadable, 0000);
$isRoot = function_exists('posix_geteuid') === true && posix_geteuid() === 0;

if ($isRoot === true || is_readable($unreadable) === true) {
    echo "SKIP — D07 this user can read a file with no permissions, so a failing pack cannot be staged\n";
} else {
    $wouldBe = $root . DIRECTORY_SEPARATOR . 'half-written.portalbackup';
    $why     = refusal(static fn () => BackupArchive::pack($brokenSnapshot, $wouldBe, KEY, BackupArchive::KEY_PASSPHRASE));
    if ($why !== '' && is_file($wouldBe) === false) {
        ok('D07', 'a pack that fails part way leaves no file for somebody to mistake for a backup');
    } else {
        bad('D07', 'a failed pack must remove its own part-written file',
            $why === '' ? 'the pack did not fail at all' : 'the file was left behind');
    }
}
chmod($unreadable, 0600);

// D08 — A BACKUP WHOSE CLOSING LINE DISAGREES WITH WHAT IT HOLDS.
//
// The closing line says how many files the backup carries. If that disagrees
// with the number actually unpacked, something is wrong with the file and it
// must not be treated as a complete copy. Nothing covered this either: the
// comparison was planted away and all 39 checks passed. Built by hand, because
// the packer cannot be made to write a wrong count.
$saltD8 = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
$keyD8  = sodium_crypto_pwhash(
    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
    KEY,
    $saltD8,
    SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
    SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
    SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
);
[$pushD8, $headerD8] = sodium_crypto_secretstream_xchacha20poly1305_init_push($keyD8);
// One file, but the closing line claims seven.
$payloadD8 = json_encode(['name' => 'tblUsers.jsonl', 'bytes' => 5], JSON_UNESCAPED_SLASHES) . "\n" . 'hello'
    . json_encode(['end' => true, 'entries' => 7], JSON_UNESCAPED_SLASHES) . "\n";
$cipherD8 = sodium_crypto_secretstream_xchacha20poly1305_push(
    $pushD8,
    $payloadD8,
    '',
    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
);
$miscount = $root . DIRECTORY_SEPARATOR . 'miscount.portalbackup';
file_put_contents(
    $miscount,
    json_encode([
        'format'    => BackupArchive::FORMAT,
        'snapshot'  => 'upgrade-20261029-200000',
        'keyMode'   => BackupArchive::KEY_PASSPHRASE,
        'salt'      => base64_encode($saltD8),
        'pwhashOps' => SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
        'pwhashMem' => SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
    ], JSON_UNESCAPED_SLASHES) . "\n" . $headerD8 . pack('N', strlen($cipherD8)) . $cipherD8
);
$why = refusal(static fn () => BackupArchive::unpack($miscount, $root . DIRECTORY_SEPARATOR . 'd8', KEY));
if ($why !== '' && str_contains($why, '7') === true) {
    ok('D08', 'a backup whose closing line disagrees with what it holds is refused, with both numbers');
} else {
    bad('D08', 'a wrong entry count must be refused', $why === '' ? 'it was accepted' : $why);
}

// D09 — AN ENTRY WHOSE NAME IS NOT A NAME.
//
// `(string)` on an array yields the word "Array", which the name test would
// happily accept, so a crafted entry wrote a file called `Array` and raised a
// PHP warning. Inside the folder, so harmless — and still wrong, and nothing
// covered it until the `is_string` guard was planted away and all 46 checks
// passed.
$saltD9 = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
$keyD9  = sodium_crypto_pwhash(
    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
    KEY,
    $saltD9,
    SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
    SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
    SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
);
[$pushD9, $headerD9] = sodium_crypto_secretstream_xchacha20poly1305_init_push($keyD9);
$payloadD9 = json_encode(['name' => ['tblUsers.jsonl'], 'bytes' => 5], JSON_UNESCAPED_SLASHES) . "\n" . 'hello'
    . json_encode(['end' => true, 'entries' => 1], JSON_UNESCAPED_SLASHES) . "\n";
$cipherD9 = sodium_crypto_secretstream_xchacha20poly1305_push(
    $pushD9,
    $payloadD9,
    '',
    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
);
$oddName = $root . DIRECTORY_SEPARATOR . 'odd-name.portalbackup';
file_put_contents(
    $oddName,
    json_encode([
        'format'    => BackupArchive::FORMAT,
        'snapshot'  => 'upgrade-20261030-010000',
        'keyMode'   => BackupArchive::KEY_PASSPHRASE,
        'salt'      => base64_encode($saltD9),
        'pwhashOps' => SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
        'pwhashMem' => SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
    ], JSON_UNESCAPED_SLASHES) . "\n" . $headerD9 . pack('N', strlen($cipherD9)) . $cipherD9
);
$oddOut = $root . DIRECTORY_SEPARATOR . 'd9';
$why    = refusal(static fn () => BackupArchive::unpack($oddName, $oddOut, KEY));
if ($why !== '' && is_file($oddOut . DIRECTORY_SEPARATOR . 'Array') === false) {
    ok('D09', 'an entry whose name is a list, not a name, is refused and writes no file called "Array"');
} else {
    bad('D09', 'a name that is not a string must be refused',
        $why === '' ? 'it was accepted' : 'a file called Array was written');
}

// =============================================================================
head('E. The envelope says nothing secret');
// =============================================================================

$envelope = BackupArchive::envelopeOf($packed);
$asText   = (string) json_encode($envelope);
if (str_contains($asText, KEY) === false && str_contains($asText, 'Ada Lovelace') === false) {
    ok('E01', 'the readable description holds neither the key nor any of the data');
} else {
    bad('E01', 'the envelope leaks something it should not', $asText);
}

// The file must not carry the data in the clear anywhere.
$whole = (string) file_get_contents($packed);
if (str_contains($whole, 'Ada Lovelace') === false
    && str_contains($whole, 'tblUsers') === false
    && str_contains($whole, KEY) === false) {
    ok('E02', 'and the file holds no name, no table name and no key in the clear');
} else {
    bad('E02', 'something readable is in the packed file');
}

if (($envelope['snapshot'] ?? '') === 'upgrade-20261029-120000'
    && ($envelope['portal'] ?? '') === '1.4.0-selftest'
    && isset($envelope['written']) === true) {
    ok('E03', 'it does record the snapshot, the portal version and when it was written');
} else {
    bad('E03', 'the envelope should carry those three', json_encode($envelope));
}

// =============================================================================
head('F. Big enough to matter');
// =============================================================================

// A snapshot larger than PHP's whole memory limit would be the real proof, and
// is too slow for a check that runs on every pull request. This is the useful
// middle: several megabytes, measured, so a change that starts reading a file
// whole shows up as a jump in memory rather than passing quietly.
$bigSnapshot = $root . DIRECTORY_SEPARATOR . 'upgrade-20261029-160000';
makeSnapshot($bigSnapshot, 20000);   // ~6 MB in one file
$bigPacked = $root . DIRECTORY_SEPARATOR . 'big.portalbackup';

$before = memory_get_peak_usage(true);
BackupArchive::pack($bigSnapshot, $bigPacked, KEY, BackupArchive::KEY_PASSPHRASE);
$bigOut = $root . DIRECTORY_SEPARATOR . 'big-out';
BackupArchive::unpack($bigPacked, $bigOut, KEY);
$after = memory_get_peak_usage(true);
$grew  = $after - $before;

$sourceSize = (int) filesize($bigSnapshot . DIRECTORY_SEPARATOR . 'tblActivityLogs.jsonl');
if ($sourceSize > 5000000) {
    ok('F01', 'the big fixture really is several megabytes (' . round($sourceSize / 1048576, 1) . ' MB)');
} else {
    bad('F01', 'the big fixture is too small to prove anything', (string) $sourceSize);
}

if (
    (string) file_get_contents($bigOut . DIRECTORY_SEPARATOR . 'tblActivityLogs.jsonl')
    === (string) file_get_contents($bigSnapshot . DIRECTORY_SEPARATOR . 'tblActivityLogs.jsonl')
) {
    ok('F02', 'a multi-megabyte file crosses many chunk boundaries and comes back identical');
} else {
    bad('F02', 'the big file came back changed');
}

// 2 MB of headroom: enough to allow the buffers and libsodium's own working
// space, far below the 6 MB the file itself would need if it were read whole.
if ($grew < 2097152) {
    ok('F03', 'and neither packing nor unpacking held the file in memory (' . round($grew / 1024) . ' KB more)');
} else {
    bad('F03', 'memory grew by about the size of the file, so something is reading it whole',
        round($grew / 1048576, 1) . ' MB');
}

echo "\n" . ($passed + $failed) . " checks: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
