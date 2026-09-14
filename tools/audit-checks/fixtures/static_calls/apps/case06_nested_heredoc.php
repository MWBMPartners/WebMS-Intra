<?php
// Path: tools/audit-checks/fixtures/static_calls/apps/case06_nested_heredoc.php
// Fixture case 6 — a TRUE nested heredoc, not merely one heredoc whose own
// marker word happens to appear mid-line somewhere in its body (that shape
// was already handled correctly by the old hand-written lexer too, and
// proves nothing about nesting — this file was wrong in exactly that way
// once already, caught by an independent verifier; do not "simplify" it
// back to a single heredoc).
//
// Here the OUTER heredoc's `{$...}` interpolation itself opens a SECOND,
// INNER heredoc, and — the part that actually reproduces the fault — the
// INNER heredoc's own body contains a line whose ONLY content is the word
// "OUTER": exactly the shape a real heredoc closing marker has to have (the
// marker alone, starting the line). A hand-written lexer that finds the end
// of a heredoc by scanning for a line that starts with the marker word,
// without separately tracking WHICH heredoc is currently open, reads that
// line and believes the OUTER heredoc has just ended — right there, still
// inside the INNER heredoc's body. Everything after that point then gets
// re-parsed as if it were ordinary top-level code, which is exactly how the
// fake call sitting a line further down inside the INNER heredoc's own body
// (Site::missingInsideInner(), which does not exist) could be reported as
// if it were real. PHP's own tokenizer has no such ambiguity: only the true
// matching INNER marker can close the inner heredoc, and only the true
// matching OUTER marker — reached afterwards, genuinely back at the top
// level — can close the outer one.
//
// (The word "INNER" is deliberately never written at the very start of a
// line anywhere in this comment block or the fixture body below, other than
// the real closing marker itself — that word starting a line is genuinely
// how the INNER heredoc closes, for real PHP as much as for a hand-written
// lexer, so accidentally writing it there is a self-inflicted bug, not a
// nesting one, and was caught the same way while drafting this file.)
//
// Verified directly against the old lexer's own compiled bytecode (kept
// only as a git-ignored __pycache__ artefact, never as committed source):
// on this exact file it wrongly reported "Site::missingInsideInner()" as a
// real call. The rewritten, tokenizer-based checker ignores it, and still
// correctly reports the genuine call below the heredoc as valid (proving
// the scan resumes as ordinary code afterwards, not merely that the file
// happens to produce no findings by accident). Must NOT be accused of
// anything.
declare(strict_types=1);

use Portal\Core\Site;

// A trivial identity closure, just so the inner heredoc can be written as
// one of its call arguments — the shape that makes the nesting genuine (a
// heredoc literally inside another heredoc's interpolated expression),
// rather than two heredocs that merely sit near each other in the file.
$fn = static fn($s) => $s;

$text = <<<OUTER
start {$fn(<<<INNER
OUTER
The line above is the outer marker word alone, but this is still body text
belonging to the inner block, not the outer one. A fake call also lives
here, and must never be treated as real: Site::missingInsideInner()
INNER)}
end
OUTER;

echo $text;

// A genuine call, reached only once the OUTER heredoc has really closed.
// Site::ok() is a real method that takes one argument, so this must resolve
// cleanly — proving the scan carries on normally afterwards, not that it
// merely stayed quiet by chance.
Site::ok(1);
