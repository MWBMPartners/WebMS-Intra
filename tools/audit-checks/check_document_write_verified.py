#!/usr/bin/env python3
"""
An uploaded document is recorded only when every byte of it was stored (#720).

Path: tools/audit-checks/check_document_write_verified.py

WHY THIS EXISTS
---------------
`web/_apps/documents/api/create.php` used to end its base64 branch with:

    $written = file_put_contents($destPath, $binary, LOCK_EX) !== false;

`file_put_contents()` answers THE NUMBER OF BYTES IT WROTE, so `!== false`
accepts a SHORT count. On a full disk or over a hosting quota part of the
document was written, that test said "written", and the next statements inserted
the `tblDocuments` row -- so the document was recorded as stored and offered for
download, with nothing anywhere reporting a problem. It is #711's fault in
another file and on a separate code path (the JSON/base64 branch of the
documents API, not `Pdf::create()`).

WHY A SECOND SCRIPT AND NOT A WIDER FIRST ONE
---------------------------------------------
`check_pdf_write_verified.py` was deliberately NOT widened to sweep the
repository for this shape, and its own header says so: "a write whose count is
not compared" matches a great deal of harmless code, and a check that cries wolf
gets switched off (#483). So each instance gets a guard scoped to its own file.
This is that guard for the documents API.

WHY NO SELF-TEST GUARDS IT INSTEAD, said plainly
------------------------------------------------
Nothing on an ordinary machine can force a SHORT write -- the open succeeding
and the disk filling part way through needs a quota or a tiny filesystem. And
unlike `Pdf::writeOrWhyNot()`, the code here is not a function a test can call:
the whole file is a page, it refuses anything but an authenticated POST in its
first statements, and the destination filename is fixed at 36 characters, so the
long-basename trick `tools/pdf-write-selftest.php` uses to force a real failure
is not available either. A static pin is therefore the only guard this fault can
have, which is why it pins WHOLE conditions rather than words in them.
`tools/document-write-check-selftest.py` is the test OF this script: it plants
each weakening into the real file, requires THIS script to catch it and nothing
else to, and restores the file byte-for-byte.

WHAT IT CHECKS, in web/_apps/documents/api/create.php only
----------------------------------------------------------
A. The base64 write and the WHOLE condition that judges it, adjacent:

       $written = file_put_contents($destPath, $binary, LOCK_EX);
       if ($written !== strlen($binary)) {

B. The multipart branch still judges `move_uploaded_file()` by its BOOLEAN
   answer. That branch was always right and the fix must not "tidy" it into a
   byte comparison it has no count for.
C. The whole gate that acts on the reason: `if ($whyNotWritten !== '') {`.
   Pinning the comparison is worth nothing if its answer is ignored -- #709
   round 2's lesson, where `if (false && ...)` on the line above switched two
   guards off in one word with every check green.
D. That gate REFUSES: `ApiResponse::error(` inside the gate's own block.
   A gate that merely exists is #668's section-D fault; this one has to end the
   request.
E. The gate comes BEFORE the INSERT. This is the acceptance criterion of #720 in
   its most direct form: a part-written document must not get a `tblDocuments`
   row. Moving the gate below the INSERT would leave every other check here
   green.
F. The part-written file is removed: `unlink($destPath)` inside the gate's
   block. A failure that leaves the bytes on disk leaves a document nothing
   points at, which no erasure can reach (the #721 shape).
G. Exactly ONE `file_put_contents(` in the file, so a second, unverified write
   cannot be added beside the pinned one.
H. `$whyNotWritten` is assigned exactly three times (the empty start and one per
   branch) and appended to exactly once. Without this, `$whyNotWritten = '';`
   added after the two branches would silence the gate while leaving its pinned
   text untouched.

WHAT IT DOES NOT DO
-------------------
It judges this one file. It does not sweep for the same shape elsewhere, for the
reason given above. It says nothing about whether the multipart branch's own
answer is trustworthy: `move_uploaded_file()` gives a boolean and no count, and
whether PHP's internal copy fallback can report success after a short write was
NOT established -- that is noted in the page's own comment rather than asserted
either way here.

Comments and the CONTENTS of quoted strings are blanked before anything is
matched, so a sentence in a comment that names a pinned line is not read as
code. An earlier version of the sister check for #711 read the raw file for one
of its tests, and a commented-out copy of the pinned line then satisfied it while
the code below wrote straight to the real path (#569's fault, measured green).
The walker is a copy of that script's, not a shared import: these checks are
each a single self-contained file on purpose, so one of them cannot be broken by
an edit made for another. The copy's two known limits, stated because they are
not obvious: a backslash immediately before a real newline inside a double-quoted
string loses a newline, which shifts later line numbers by one, and a heredoc is
read as code. Neither occurs in this file today.

Because the SQL lives inside a quoted string the walker masks, the INSERT is
located by the statement that prepares it (`$stmt = $db->prepare(`) rather than
by the words `INSERT INTO tblDocuments`.

THE COST OF PINNING WHOLE CONDITIONS, said plainly because #483 says a check
that cries wolf gets switched off: this script REFUSES correct refactors.
Holding the length in a named variable, writing `strlen($binary) !== $written`,
renaming `$whyNotWritten`, or moving the write into a helper all fail it although
all four are right. That is the deliberate price of a pin no `&& false` can slip
past. Whoever makes such a change updates the patterns here in the same commit,
on purpose.

@link https://github.com/MWBMPartners/WebMS-Intra/issues/720
"""

import re
import sys
from pathlib import Path

TARGET = (
    Path(__file__).resolve().parents[2]
    / "web" / "_apps" / "documents" / "api" / "create.php"
)
SHOWN = "web/_apps/documents/api/create.php"


def strip_comments_and_strings(text: str) -> str:
    """
    Blank out PHP comments and the CONTENTS of quoted strings, keeping every
    newline so reported line numbers stay true.

    Walked character by character rather than done with regular expressions,
    because in this repository a `//` inside a string (a URL) has eaten the rest
    of a line, and a `/*` inside one has swallowed everything to the next `*/`.
    """
    out = []
    i = 0
    n = len(text)
    while i < n:
        ch = text[i]
        two = text[i:i + 2]
        if two == "//" or ch == "#":
            while i < n and text[i] != "\n":
                i += 1
            continue
        if two == "/*":
            i += 2
            while i < n and text[i:i + 2] != "*/":
                out.append("\n" if text[i] == "\n" else " ")
                i += 1
            i += 2
            continue
        if ch in "'\"":
            quote = ch
            out.append(quote)
            i += 1
            while i < n and text[i] != quote:
                if text[i] == "\\" and i + 1 < n:
                    i += 2
                    continue
                out.append("\n" if text[i] == "\n" else "x")
                i += 1
            out.append(quote)
            i += 1
            continue
        out.append(ch)
        i += 1
    return "".join(out)


def block_after(code: str, start: int) -> str:
    """
    The body of the `{ … }` that opens at or after `start`, by counting braces.

    The braces are counted in the ALREADY-STRIPPED source, which is what makes
    it safe: a `{` or a `}` inside a log message would otherwise swallow the
    rest of the function and let a check pass on a gate whose body had been
    emptied. That exact fault has been found here before (#647).

    Answers '' when no balanced block is found, which every caller treats as a
    failure rather than as an empty body.
    """
    open_at = code.find("{", start)
    if open_at == -1:
        return ""
    depth = 0
    for index in range(open_at, len(code)):
        if code[index] == "{":
            depth += 1
        elif code[index] == "}":
            depth -= 1
            if depth == 0:
                return code[open_at + 1:index]
    return ""


def main() -> int:
    if TARGET.is_file() is False:
        print("✗ " + SHOWN + " is missing, so nothing here is guarded. If the "
              "documents upload endpoint has moved, move this check with it.",
              file=sys.stderr)
        return 1

    code = strip_comments_and_strings(TARGET.read_text(encoding="utf-8"))
    problems = []

    # A. The write and the WHOLE condition that judges it, adjacent.
    write_and_compare = re.compile(
        r"\$written\s*=\s*file_put_contents\(\s*\$destPath\s*,\s*\$binary\s*,"
        r"\s*LOCK_EX\s*\)\s*;\s*"
        r"if\s*\(\s*\$written\s*!==\s*strlen\(\s*\$binary\s*\)\s*\)\s*\{"
    )
    if write_and_compare.search(code) is None:
        problems.append(
            "✗ A: the pinned write-and-compare is not in " + SHOWN + ". It must "
            "read exactly:\n"
            "        $written = file_put_contents($destPath, $binary, LOCK_EX);\n"
            "        if ($written !== strlen($binary)) {\n"
            "   The WHOLE condition is pinned on purpose. `!== false`, `> 0`, "
            "`is_file(...)`, or the same test with `&& false` added, all accept "
            "a part-written document -- which then gets a tblDocuments row and "
            "is offered for download (#720). No self-test can see that."
        )

    # B. The multipart branch keeps its own, different and correct test.
    # The subscripts are written loosely on purpose: the walker above masks the
    # CONTENTS of every quoted string, so `$_FILES['file']['tmp_name']` reaches
    # here as `$_FILES['xxxx']['xxxxxxxx']`. Matching the literal words would
    # fail on correct code, which is the cry-wolf direction (#483). What the
    # pin still fixes is the shape: the uploaded file, the same destination the
    # pinned write uses, and `=== false`.
    multipart = re.compile(
        r"move_uploaded_file\(\s*\$_FILES\[[^]]*\]\[[^]]*\]\s*,\s*"
        r"\$destPath\s*\)\s*===\s*false"
    )
    if multipart.search(code) is None:
        problems.append(
            "✗ B: the multipart branch no longer judges move_uploaded_file() by "
            "`=== false`. It answers a BOOLEAN and no byte count, so that is the "
            "right and only test for it. The two branches share one failure "
            "reason and must keep their own separate tests -- sharing one "
            "`$written` boolean is how #720 hid: the multipart test was right, "
            "the base64 test was wrong, and they looked alike."
        )

    # C. The WHOLE gate that acts on the reason. Pinning a comparison whose
    #    answer nothing acts on is pinning a label on a door nobody opens.
    gate = re.compile(r"if\s*\(\s*\$whyNotWritten\s*!==\s*''\s*\)\s*\{")
    found = gate.search(code)
    if found is None:
        problems.append(
            "✗ C: the pinned failure gate is not there. It must read exactly:\n"
            "        if ($whyNotWritten !== '') {\n"
            "   The whole condition is pinned because the comparison in check A "
            "is worth nothing if its answer is ignored. Measured elsewhere in "
            "this repository (#709 round 2): `if (false && ...)` on the line "
            "above switched two guards off in one word with every other check "
            "green."
        )
    else:
        body = block_after(code, found.end() - 1)
        if body == "":
            problems.append(
                "✗ C: the failure gate's block could not be read -- its braces "
                "do not balance. Nothing below could be judged, so this is a "
                "failure rather than a pass."
            )

        # D. The gate must REFUSE, not merely exist.
        if "ApiResponse::error(" not in body:
            problems.append(
                "✗ D: the failure gate does not call ApiResponse::error(). A gate "
                "that only logs lets the request carry on to the INSERT, so the "
                "part-written document is recorded anyway. `ApiResponse::error()` "
                "is declared `: never` and exits, which is what makes the INSERT "
                "below unreachable. A gate that exists without refusing is "
                "#668's section-D fault."
            )

        # F. And it must not leave the part-written file on disk.
        if re.search(r"unlink\(\s*\$destPath\s*\)", body) is None:
            problems.append(
                "✗ F: the failure gate does not remove the part-written file with "
                "`unlink($destPath)`. Left behind, those bytes are a document "
                "nothing points at: no tblDocuments row names it, so nothing can "
                "show it, delete it, or reach it for an erasure (#721's shape). "
                "It is safe to remove here, and only here, because $destPath is a "
                "freshly generated random name that no existing document can "
                "bear -- the page's own comment sets out that reasoning."
            )

        # E. The gate must come BEFORE the INSERT. This is #720's acceptance
        #    criterion in its most direct form. The SQL itself is inside a
        #    quoted string this script masks, so the INSERT is located by the
        #    statement that prepares it.
        insert_at = code.find("$stmt = $db->prepare(")
        if insert_at == -1:
            problems.append(
                "✗ E: `$stmt = $db->prepare(` is not in " + SHOWN + ", so this "
                "script cannot tell whether the failure gate still comes before "
                "the INSERT. That is the one thing #720 is about. If the INSERT "
                "has been rewritten, point this check at its new form in the "
                "same commit."
            )
        elif found.start() > insert_at:
            problems.append(
                "✗ E: the failure gate comes AFTER the INSERT into tblDocuments. "
                "The whole point of #720 is that a document which was not stored "
                "in full never gets a row. Checked by position because every "
                "other check here stays green when the gate is moved down."
            )

    # G. One write, so a second unverified one cannot be added beside it.
    writes = code.count("file_put_contents(")
    if writes != 1:
        problems.append(
            "✗ G: " + SHOWN + " holds " + str(writes) + " `file_put_contents(` "
            "call(s); there must be exactly one, the pinned one in check A. A "
            "second write would not be covered by any pin here, and #720 is "
            "precisely a write whose answer was not judged."
        )

    # H. The reason must not be silenced between the branches and the gate.
    sets = len(re.findall(r"\$whyNotWritten\s*=(?!=)", code))
    adds = len(re.findall(r"\$whyNotWritten\s*\.=", code))
    if sets != 3 or adds != 1:
        problems.append(
            "✗ H: `$whyNotWritten` is set " + str(sets) + " time(s) and appended "
            "to " + str(adds) + " time(s); it must be set exactly 3 times (the "
            "empty start, and one per branch) and appended to exactly once (the "
            "note about the part-written file). Note `\\$whyNotWritten\\s*=` also "
            "matches `===`, so the pattern excludes a following `=` on purpose. "
            "Without this count, one extra `$whyNotWritten = '';` after the two "
            "branches silences the gate for ever while every pinned line above "
            "stays exactly as it is."
        )

    if problems != []:
        print("\n\n".join(problems), file=sys.stderr)
        print("\n" + str(len(problems)) + " problem(s). The rule is #720: an "
              "uploaded document is recorded only when every byte of it was "
              "stored, and a part-written one is removed rather than left on "
              "disk.", file=sys.stderr)
        return 1

    print("✓ The documents upload endpoint compares the written byte count, "
          "keeps the multipart branch's own boolean test, refuses before the "
          "INSERT, and removes a part-written file (#720).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
