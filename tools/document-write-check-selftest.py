#!/usr/bin/env python3
"""
The test of the test: does the documents-upload write guard actually catch
things? (#720)

Path: tools/document-write-check-selftest.py

WHY THIS EXISTS
---------------
`tools/audit-checks/check_document_write_verified.py` is the ONLY guard #720 can
have. Nothing on an ordinary machine can force a SHORT write -- the open
succeeding and the disk filling part way through needs a quota or a tiny
filesystem -- and the code it guards is a page that refuses anything but an
authenticated POST in its first statements, so no self-test can call it the way
`tools/pdf-write-selftest.php` calls `Pdf::writeOrWhyNot()`.

A check that cannot fail is worse than no check, because it is believed. This
repository has been bitten by that shape repeatedly: a guard that searched for
the TEXT of a condition stayed green after `&& false` was added to it, and in
#709 round 2 `if (false && ...)` on the line above switched two guards off in
one word with 336 checks green. So the guard is proved here rather than
asserted: each plant is a real edit to the real file, the real check is run, and
the check must fail with EXACTLY the expected set of complaints.

WHAT IT NEEDS: python3. No database, no PHP, no network, no web server.

HOW A PLANT IS WRITTEN SAFELY, taken from tools/api-spec-check-selftest.py
--------------------------------------------------------------------------
1. **Assert the text being replaced was FOUND.** A plant whose pattern does not
   match applies nothing, and a check that then passes reads exactly like a
   check that caught nothing. Every plant here asserts an exact count.
2. **Compare the WHOLE set of complaints, not just the interesting one.** "The
   check I meant fired" is not the same as "the check I meant fired and nothing
   else did", and only the second makes a plant evidence about one check.
3. **One changed thing per plant**, or two guards can mask each other and
   neither half can be shown to be load-bearing.
4. **Restore from a recorded snapshot and prove it by SHA-256**, before the next
   plant is measured against a changed tree.

There is one plant that must leave the check GREEN (`letters=""`): a `}` inside
a string literal in the middle of the gate's body. It is here because the
check's brace walk is what stops a `}` in a log message swallowing the rest of
the file, and a walk that got that wrong would cry wolf on correct code (#483).

WHAT IT CANNOT PROVE, said plainly
----------------------------------
It proves the GUARD catches each weakening. It proves nothing about the portal's
behaviour: no document is uploaded here, no write is attempted, and the short
write itself is still unreachable on this machine. The page's own comment and
the guard's header both say so.

It is also not wired into any workflow yet (the #646 shape), so until it is, it
runs only when somebody runs it. It is not named `check_*.py`, so
`.githooks/pre-push`'s glob over `tools/audit-checks/` does not pick it up
either -- the same arrangement as `tools/api-spec-check-selftest.py`, which has
its own hard gate in a workflow.

USAGE
    python3 -I tools/document-write-check-selftest.py
    python3 -I tools/document-write-check-selftest.py --verbose

Package:   WebMS-Intra
Author:    MWBM Partners Ltd (t/a MWservices)
Copyright: 2025-present MWBM Partners Ltd (t/a MWservices)
License:   All Rights Reserved
Version:   1.0.0

@link https://github.com/MWBMPartners/WebMS-Intra/issues/720
"""

from __future__ import annotations

import hashlib
import re
import subprocess
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
CHECK = "tools/audit-checks/check_document_write_verified.py"
PAGE = REPO / "web/_apps/documents/api/create.php"

VERBOSE = "--verbose" in sys.argv


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest() if path.exists() else "(absent)"


def run_check() -> tuple[int, str]:
    done = subprocess.run(
        ["python3", "-I", CHECK], capture_output=True, text=True, cwd=REPO
    )
    return done.returncode, done.stdout + done.stderr


def replace_once(old: str, new: str, count: int = 1) -> None:
    """Edit the page, refusing to be a no-op. See point 1 in the header."""
    text = PAGE.read_text(encoding="utf-8")
    found = text.count(old)
    if found != count:
        raise AssertionError(
            "plant text appears %d time(s), expected %d: %r"
            % (found, count, old[:70])
        )
    PAGE.write_text(text.replace(old, new, count), encoding="utf-8")


class Plant:
    """
    One planted fault: what it does, and the EXACT set of complaint letters the
    check must answer with. An empty set means the check must still PASS.
    """

    def __init__(self, name: str, letters: str, apply, phrase: str | None = None) -> None:
        self.name = name
        self.letters = set(letters)
        self.apply = apply
        # For the one plant that removes the file altogether, the check answers
        # a sentence rather than a lettered complaint.
        self.phrase = phrase


# ----------------------------------------------------------------------------
# The plants. Each is the real fault in the form somebody would really write.
# ----------------------------------------------------------------------------
PINNED_WRITE = (
    "    $written = file_put_contents($destPath, $binary, LOCK_EX);\n"
    "    if ($written !== strlen($binary)) {"
)
GATE = "if ($whyNotWritten !== '') {"
MULTIPART = (
    "    if (move_uploaded_file($_FILES['file']['tmp_name'], $destPath) === false) {"
)
REFUSAL = "    ApiResponse::error('Failed to save uploaded file', 500);"
TIDY = "        $whyNotWritten .= unlink($destPath) === true"
START = "$whyNotWritten = '';\nif ($isMultipart === true) {"


def plants() -> list[Plant]:
    out: list[Plant] = []

    def add(name: str, letters: str, apply, phrase: str | None = None) -> None:
        out.append(Plant(name, letters, apply, phrase))

    # ---- A: the comparison itself -------------------------------------------
    add(
        "the exact fault of #720 put back (`!== false`)",
        "A",
        lambda: replace_once(
            PINNED_WRITE,
            "    $written = file_put_contents($destPath, $binary, LOCK_EX) !== false;\n"
            "    if ($written === false) {",
        ),
    )
    add(
        "`&& false` added to the comparison",
        "A",
        lambda: replace_once(
            "    if ($written !== strlen($binary)) {",
            "    if ($written !== strlen($binary) && false) {",
        ),
    )
    add(
        "compared against $fileSize, set a hundred lines up, instead of strlen($binary)",
        "A",
        lambda: replace_once(
            "    if ($written !== strlen($binary)) {",
            "    if ($written !== $fileSize) {",
        ),
    )
    add(
        "the count accepted as merely positive (`> 0`)",
        "A",
        lambda: replace_once(
            "    if ($written !== strlen($binary)) {",
            "    if ($written > 0 === false) {",
        ),
    )

    # ---- B: the multipart branch, which was always right --------------------
    add(
        "the multipart branch 'tidied' into a byte comparison it has no count for",
        "B",
        lambda: replace_once(
            MULTIPART,
            "    if (move_uploaded_file($_FILES['file']['tmp_name'], $destPath)"
            " !== true) {",
        ),
    )

    # ---- C: the gate that decides whether the answer is acted on ------------
    add(
        "the whole gate switched off in one word (`if (false && ...)`)",
        "C",
        lambda: replace_once(GATE, "if (false && $whyNotWritten !== '') {"),
    )
    add(
        "the gate loosened to 'did anything go wrong at all' (truthiness)",
        "C",
        lambda: replace_once(GATE, "if ($whyNotWritten) {"),
    )

    # ---- D: a gate that exists without refusing -----------------------------
    add(
        "the refusal deleted, so the request carries on to the INSERT",
        "D",
        lambda: replace_once(REFUSAL, "    // the refusal was deleted"),
    )

    # ---- E: the order, which is #720's acceptance criterion -----------------
    add(
        "the INSERT prepared BEFORE the failure gate",
        "E",
        lambda: replace_once(
            START,
            "$stmt = $db->prepare('INSERT INTO tblDocuments (siteID) VALUES (?)');\n"
            + START,
        ),
    )
    add(
        "the INSERT's prepare renamed, so the order can no longer be judged",
        "E",
        lambda: replace_once(
            "$stmt = $db->prepare(\n", "$stmt = $db->prepareRENAMED(\n"
        ),
    )

    # ---- F: the part-written file left on disk ------------------------------
    add(
        "the clean-up turned into a test that removes nothing",
        "F",
        lambda: replace_once(
            TIDY, "        $whyNotWritten .= is_file($destPath) === true"
        ),
    )

    # ---- G: a second, unverified write beside the pinned one ----------------
    add(
        "a second file_put_contents() added beside the pinned one",
        "G",
        lambda: replace_once(
            "$destPath = $uploadDir . DIRECTORY_SEPARATOR . $storedFilename;",
            "$destPath = $uploadDir . DIRECTORY_SEPARATOR . $storedFilename;\n"
            "file_put_contents($destPath . '.note', 'x');",
        ),
    )

    # ---- H: the reason silenced between the branches and the gate -----------
    #     Every pinned line above is untouched by this one, which is the point:
    #     without check H it is invisible.
    add(
        "the reason blanked after both branches, silencing the gate for ever",
        "H",
        lambda: replace_once(GATE, "$whyNotWritten = '';\n" + GATE),
    )

    # ---- The file itself ----------------------------------------------------
    add(
        "the page deleted outright",
        "",
        lambda: PAGE.unlink(),
        phrase="is missing, so nothing here is guarded",
    )

    # ---- A case that must stay GREEN ----------------------------------------
    #     The check walks braces to find the gate's body. If that walk did not
    #     step over string literals, one `}` in a message would swallow the
    #     rest of the file and the walk would report a body with no refusal in
    #     it -- crying wolf on correct code (#483). The walk runs on the
    #     already-masked source, so this must pass.
    add(
        "a closing brace inside a string in the gate's body (must stay GREEN)",
        "",
        lambda: replace_once(
            "    clearstatcache(true, $destPath);",
            "    $note = 'a } inside a message';\n"
            "    clearstatcache(true, $destPath);",
        ),
    )

    return out


def letters_in(out: str) -> set[str]:
    """The complaint letters the check answered with."""
    return set(re.findall(r"^✗ ([A-Z]): ", out, re.MULTILINE))


def main() -> int:
    if PAGE.is_file() is False:
        print("✗ %s is missing, so nothing can be planted into it."
              % PAGE.relative_to(REPO), file=sys.stderr)
        return 1

    before = sha(PAGE)
    code, out = run_check()
    if code != 0:
        print("✗ %s is ALREADY failing, so no plant below would prove anything. "
              "Fix that first.\n\n%s" % (CHECK, out), file=sys.stderr)
        return 1

    rows: list[tuple[str, str, bool, str]] = []
    for plant in plants():
        snapshot = PAGE.read_bytes()
        try:
            plant.apply()
            code, out = run_check()
            got = letters_in(out)
            if plant.phrase is not None:
                caught = code == 1 and plant.phrase in out
                why = "" if caught else (
                    "the check did not fail at all (exit %d)" % code if code != 1
                    else "%r is not in what it said" % plant.phrase
                )
                rows.append((plant.name, plant.phrase[:16], caught, why))
            elif plant.letters == set():
                # A plant that must NOT be reported.
                caught = code == 0
                why = "" if caught else (
                    "the check went RED on correct code, reporting %s"
                    % (sorted(got) or "no letter")
                )
                rows.append((plant.name, "green", caught, why))
            else:
                caught = code == 1 and got == plant.letters
                if caught:
                    why = ""
                elif code != 1:
                    why = "the check did not fail at all (exit %d)" % code
                elif plant.letters - got:
                    why = "expected %s, got %s" % (
                        sorted(plant.letters), sorted(got) or "nothing")
                else:
                    why = ("also tripped %s, which makes this no evidence about %s"
                           % (sorted(got - plant.letters), sorted(plant.letters)))
                rows.append((plant.name, "".join(sorted(plant.letters)), caught, why))
        except AssertionError as problem:
            rows.append((plant.name, "?", False, "the plant did not apply: %s" % problem))
        finally:
            PAGE.write_bytes(snapshot)
        if sha(PAGE) != before:
            print("✗ %s was NOT restored after the plant %r. Stopping, because "
                  "anything after this would be measured against a changed file."
                  % (PAGE.relative_to(REPO), plant.name), file=sys.stderr)
            return 1

    width = max(len(row[0]) for row in rows)
    missed = [row for row in rows if row[2] is False]
    if VERBOSE or missed:
        print("%-*s  %-16s  %s" % (width, "PLANTED FAULT", "EXPECT", "CAUGHT?"))
        print("-" * (width + 30))
        for name, expect, caught, why in rows:
            print("%-*s  %-16s  %s"
                  % (width, name, expect, "yes" if caught else "NO — " + why))
        print("-" * (width + 30))

    code, out = run_check()
    if code != 0:
        print("✗ %s does not pass again after every plant was undone:\n\n%s"
              % (CHECK, out), file=sys.stderr)
        return 1

    if missed != []:
        print("\n✗ %d of %d planted fault(s) were NOT handled as expected. The check "
              "is not guarding what it claims to guard."
              % (len(missed), len(rows)), file=sys.stderr)
        return 1

    print("✓ All %d plants behaved as expected — each weakening caught by the "
          "expected complaint and by no other, the correct-code case left green, "
          "and the page restored byte-for-byte (SHA-256 %s)."
          % (len(rows), before[:12]))
    return 0


if __name__ == "__main__":
    sys.exit(main())
