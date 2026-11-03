#!/usr/bin/env python3
"""
A PDF is kept only when every byte of it was written (#711).

Path: tools/audit-checks/check_pdf_write_verified.py

WHY THIS EXISTS
---------------
`Portal\\Core\\Pdf::create()` used to decide a write had worked by asking
`is_file($filePath)`. That is true for a file that exists and holds nothing,
which is exactly what a failed write leaves behind -- so on a full disk or over
a hosting quota a truncated giving statement, expense form, asset label or venue
invoice was handed back as a finished PDF, and nothing recorded a problem.

The cure is to compare the byte count. THE SELF-TEST CANNOT GUARD IT. A genuine
short write -- the open succeeding and the disk filling part way through --
cannot be forced without a quota or a tiny filesystem, so
`tools/pdf-write-selftest.php` reaches the comparison only through the `false`
case. Measured: replacing the comparison with "did anything get written" leaves
all 23 of its checks green. That gap is what this script is for, and it is why
the two guards are not interchangeable (the #646 shape: neither catches the
other's half).

It also pins the TEMPORARY-NAME-AND-RENAME shape, because the first version of
the #711 fix wrote straight to the real path and deleted whatever was there when
the write failed. `file_put_contents()` can fail at OPEN -- the target read-only,
a read-only mount, the name taken by a directory -- leaving the existing
contents untouched; deleting them turned a working if stale PDF into a broken
download link, with the database row still naming the removed file. Measured on
an existing 28-byte PDF at mode 0444.

WHAT IT CHECKS, in web/_core/Pdf.php only
-----------------------------------------
1. `writeOrWhyNot()` exists. Renaming or deleting it fails this check rather
   than silently testing nothing.
2. The write is `file_put_contents($temporary, $bytes)` and the very next
   condition is EXACTLY `if ($written !== strlen($bytes)) {`. The WHOLE
   condition is pinned, not merely the words in it: a check that looks for the
   text of a guard stays green when `&& false` is added to it, which this
   repository has been bitten by more than once.
3. The temporary name is derived from the real path and is NOT the real path:
   `$temporary = $filePath . '.part-'`.
4. The rename is checked for failure: `rename($temporary, $filePath) === false`.
5. Nowhere in the file does `is_file($filePath)` appear -- the old fault's exact
   shape. (`is_file($temporary)` is fine and is how the clean-up decides whether
   there is anything to remove.)

WHAT IT DOES NOT DO
-------------------
It judges this one file. It does NOT sweep the repository for the same shape
elsewhere, because "a write whose count is not compared" matches a great deal of
harmless code and a check that cries wolf gets switched off (#483). The one live
instance found elsewhere while this was written -- the base64 branch of
`web/_apps/documents/api/create.php` -- is **#720**, raised rather than widened
into this script.

Comments and quoted text are removed before anything is matched, so a sentence
in a comment that names `is_file($filePath)` -- including the ones in this
file's own explanation of the old fault -- is not read as code. EVERY check uses
the stripped source; an earlier version read the raw file for check 3, and a
commented-out copy of the pinned line then satisfied it while the code below
wrote straight to the real path (#569's fault, measured green).

Two limits of the stripper, stated because they are not obvious: a backslash
immediately before a real newline inside a double-quoted string loses one
newline, which shifts later line numbers by one; and a heredoc is treated as
code. Neither occurs in Pdf.php today.

THE COST OF PINNING A WHOLE CONDITION, said plainly because #483 says a check
that cries wolf gets switched off: this script REFUSES correct refactors. Writing
`strlen($bytes) !== $written`, or holding the length in a named variable, or
moving the comparison into a helper, all fail it although all three are right.
That is the deliberate price of a pin no `&& false` can slip past. Whoever makes
such a change updates the pattern here in the same commit, on purpose.

@link https://github.com/MWBMPartners/WebMS-Intra/issues/711
"""

import re
import sys
from pathlib import Path

TARGET = Path(__file__).resolve().parents[2] / "web" / "_core" / "Pdf.php"


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


def main() -> int:
    if TARGET.is_file() is False:
        print("FAIL: " + str(TARGET) + " is missing.")
        return 1

    raw = TARGET.read_text(encoding="utf-8")
    code = strip_comments_and_strings(raw)
    problems = []

    # 1. The method must still be there.
    if "function writeOrWhyNot(" not in code:
        problems.append(
            "Pdf::writeOrWhyNot() is gone. It is where a PDF's byte count is "
            "compared; if the write has moved, move this check with it."
        )

    # 2. The write, and the WHOLE condition that judges it, adjacent.
    pinned = re.compile(
        r"\$written\s*=\s*file_put_contents\(\s*\$temporary\s*,\s*\$bytes\s*\)\s*;\s*"
        r"if\s*\(\s*\$written\s*!==\s*strlen\(\s*\$bytes\s*\)\s*\)\s*\{"
    )
    if pinned.search(code) is None:
        problems.append(
            "The pinned write-and-compare is not there. It must read exactly:\n"
            "        $written = file_put_contents($temporary, $bytes);\n"
            "        if ($written !== strlen($bytes)) {\n"
            "    The WHOLE condition is pinned on purpose. Anything else -- "
            "`!== false`, `> 0`, `is_file(...)`, or the same test with "
            "`&& false` added -- accepts a part-written PDF, which is the "
            "fault #711 exists to stop, and no self-test can see it."
        )

    # 2b. THE CALL, AND THE GATE THAT DECIDES WHETHER ITS ANSWER IS ACTED ON.
    #     Pinning the comparison is not enough and that was measured, not
    #     feared: `if (false && $whyNot !== '')` in create(), and deleting the
    #     call altogether while leaving the method in place, each left all 23
    #     checks of the self-test AND this check green, with create() handing
    #     back the path of a PDF that was never written. The self-test reaches
    #     writeOrWhyNot() by reflection and never goes through create(), so it
    #     cannot see either. This is #709 round 2's lesson in another file:
    #     when you pin a call, pin the gate that decides whether its block is
    #     entered at all, or you have pinned a label on a door nobody opens.
    called = re.compile(
        r"\$whyNot\s*=\s*self::writeOrWhyNot\(\s*\$filePath\s*,\s*\$bytes\s*\)\s*;\s*"
        r"if\s*\(\s*\$whyNot\s*!==\s*''\s*\)\s*\{"
    )
    if called.search(code) is None:
        problems.append(
            "Pdf::create() does not call writeOrWhyNot() and act on its answer. "
            "It must read exactly:\n"
            "        $whyNot = self::writeOrWhyNot($filePath, $bytes);\n"
            "        if ($whyNot !== '') {\n"
            "    The WHOLE condition is pinned, and adjacency with the call is "
            "pinned, because the comparison inside the method is worth nothing "
            "if the answer is ignored. Measured: `if (false && ...)` here, and "
            "deleting this call while leaving the method in place, each left "
            "every self-test check and every other part of this script green."
        )

    # 2c. BOTH clean-ups. A failure that leaves its temporary file behind puts a
    #     `.part-…` file next to a real PDF for ever; the short-write branch's
    #     call is reachable only by a genuine short write, which no test here
    #     can force, so nothing but this would notice its removal.
    if code.count("self::tidyAway($temporary)") != 2:
        problems.append(
            "There must be exactly two `self::tidyAway($temporary)` calls in "
            "writeOrWhyNot() - one for a failed write, one for a failed rename. "
            "Found " + str(code.count("self::tidyAway($temporary)")) + ". Without "
            "both, a failure can leave a `.part-…` file beside a real PDF, and "
            "the short-write branch cannot be reached by any test on an "
            "ordinary filesystem."
        )

    # 3. A temporary name, derived from the real path and not equal to it.
    #    Matched against the STRIPPED source. Reading the raw source here -
    #    which an earlier version did - meant a COMMENTED OUT copy of this line
    #    satisfied the check while the code below it wrote straight to the real
    #    path. That is #569's fault exactly, and it was measured: the gate went
    #    green on it.
    #
    #    TWO FORMS ARE ACCEPTED, and the second is why this was widened on
    #    3 November 2026 (#721). The mark moved into `Pdf::PART_MARK`, so that
    #    the writer here and the nightly sweep that removes a stray temporary
    #    cannot drift apart - two literals would be two places to change, and
    #    the one that mattered would be the sweep, which would simply stop
    #    finding anything, silently. THIS CHECK WENT RED ON THAT CHANGE, which
    #    is the gate doing its job, and the widening is deliberate rather than
    #    a way round it: both forms still require the name to be built FROM
    #    `$filePath`, which is the whole invariant - the temporary must be in
    #    the SAME FOLDER, because `rename()` is only atomic within one
    #    filesystem.
    #
    #    The literal form stops at the opening quote on purpose: the stripper
    #    masks a string's CONTENTS, so `'.part-'` itself can never be matched,
    #    but the quote characters survive. The constant form names the constant,
    #    which is not a string and so survives stripping whole.
    temporary_from_path = (
        re.search(r"\$temporary\s*=\s*\$filePath\s*\.\s*'", code) is not None
        or re.search(r"\$temporary\s*=\s*\$filePath\s*\.\s*self::PART_MARK\b", code) is not None
    )
    if temporary_from_path is False:
        problems.append(
            "The temporary name is not built from `$filePath` - neither "
            "`$filePath . '.part-' . ...` nor `$filePath . self::PART_MARK . ...`. "
            "The PDF must be written under another name in the SAME folder and "
            "renamed into place, so that a failed write leaves the file that is "
            "already there untouched. Writing straight to the real path and "
            "removing it on failure deleted a working PDF while the database row "
            "went on naming it."
        )

    # 3b. If the constant form is used, the constant must exist and must carry
    #     the mark the sweep looks for. Without this, renaming the constant or
    #     emptying it would leave check 3 green while `sweepPartFiles()`
    #     silently matched nothing - and a sweep that finds nothing looks
    #     exactly like a folder with nothing to find.
    if re.search(r"\$temporary\s*=\s*\$filePath\s*\.\s*self::PART_MARK\b", code) is not None:
        if re.search(r"const\s+PART_MARK\s*=\s*'[^']+'", raw) is None:
            problems.append(
                "The temporary name is built from `self::PART_MARK`, but this "
                "file declares no non-empty `PART_MARK` constant. The nightly "
                "sweep in Pdf::sweepPartFiles() looks for that same mark, so an "
                "empty or missing one makes the sweep match nothing - which "
                "looks identical to there being nothing to sweep (#721)."
            )

    # 4. The rename must be checked.
    if re.search(
        r"rename\(\s*\$temporary\s*,\s*\$filePath\s*\)\s*===\s*false", code
    ) is None:
        problems.append(
            "The rename's answer is not checked with "
            "`rename($temporary, $filePath) === false`. An unchecked rename "
            "leaves the bytes on disk under the temporary name and reports "
            "success for a PDF that is not where the caller was told it is."
        )

    # 5. The old fault's exact shape, anywhere in the file.
    for number, line in enumerate(code.split("\n"), start=1):
        if "is_file($filePath)" in line:
            problems.append(
                "web/_core/Pdf.php:" + str(number) + " asks `is_file($filePath)`. "
                "That is the #711 fault itself: it is true for a file that "
                "exists and holds nothing. Judge the byte count."
            )

    if problems != []:
        print("FAIL: a part-written PDF could be kept (#711).\n")
        for problem in problems:
            print("  - " + problem + "\n")
        return 1

    print("OK: Pdf::writeOrWhyNot() compares the byte count, writes under a "
          "temporary name and checks the rename; nothing judges success by "
          "is_file($filePath).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
