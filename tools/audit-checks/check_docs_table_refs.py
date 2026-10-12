#!/usr/bin/env python3
# -----------------------------------------------------------------------------
# tools/audit-checks/check_docs_table_refs.py — every database table a document
# in docs/ names must really exist.
# -----------------------------------------------------------------------------
# @package   WebMS-Intra (development tooling — never deployed; only web/ is)
# @author    MWBM Partners Ltd (t/a MWservices)
# @copyright MWBM Partners Ltd (t/a MWservices). All Rights Reserved.
# @version   1.0.0 (12 October 2026, #488)
"""
Documents-name-real-tables check (#488).

WHY THIS EXISTS
---------------
The disaster-recovery runbook told an administrator, as the first step after a
break-in, to run `DELETE FROM` on a sessions table. There has never been such a
table: sessions are files kept by PHP. The published erasure policy named four
more tables that do not exist, one of them a real table with the wrong
capitals. Nothing noticed, because nothing reads the documents: the sibling
check `check_php_table_refs.py` reads PHP only.

A wrong table name in a recovery document is found out in the emergency, by
the person least able to cope with it. This check finds it on the day it is
written instead.

WHAT IT CHECKS
--------------
Every word shaped like a table name (`tbl` followed by letters, digits or
underscores) in every `.md` file under `docs/`, including sub-folders, must be
a table that `web/_sql/full_schema.sql` creates: the same letters and the same
capitals. The list of real tables comes from `build_known_tables()` in
`check_php_table_refs.py`, so the two checks cannot disagree about what exists.

The `tbl` at the front is recognised in any capitals, and so is the `.md` at
the end of a file name. Both used to be matched in lower case only, so a name
written ALL IN CAPITALS, and anything in a file ending `.MD`, was never looked
at: zero findings from text that was never read.

WHAT IT CANNOT DO
-----------------
- It checks table names only. A wrong COLUMN name (the runbook also had one)
  is not caught: a column name has no shape that tells it apart from an
  ordinary word.
- It reads `docs/` only. `FEATURES.md`, `DEV_NOTES.md` and `CHANGELOG.md` at
  the top of the repository name tables that no longer exist as history and as
  examples (33 between them on 12 October 2026); reading them would bury every
  real finding. Tidying those is its own job.
- It cannot tell whether a sentence ABOUT a real table is true.
- It reads `.md` files only. A table named in a `.txt` or any other kind of
  file under `docs/` is not looked at.

EXAMPLE NAMES
-------------
A document may need a made-up name, to show the shape of something without
pointing at a real table. Each one is listed in ALLOWED_EXAMPLE_NAMES with the
reason. Keep the list short: every entry is a name this check will never
question again, in any document.

Exit code:
  0 — no findings, or findings without --strict (the house convention for this
      folder: the pull-request run annotates, --strict blocks)
  1 — at least one unknown table named, and --strict given
  2 — the check could not do its job (no schema, or no documents found). This
      is never reported as a pass: zero findings from zero files read would
      look exactly like a clean result.

Usage:
  python3 tools/audit-checks/check_docs_table_refs.py [--strict]
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

# The sibling check owns the list of real tables. Imported, not copied, so a
# change to how tables are found reaches both checks at once.
sys.path.insert(0, str(Path(__file__).resolve().parent))
from check_php_table_refs import REPO_ROOT, build_known_tables  # noqa: E402

DOCS_DIR = REPO_ROOT / "docs"

# The same shape the PHP check looks for, except that the `tbl` at the front
# may be in any capitals. A document is prose, and prose shouts: `TBLUSERS` in
# a heading is still somebody naming a table, and it is still not a real one.
TABLE_REF_RE = re.compile(r"\btbl[A-Za-z0-9_]+\b", re.IGNORECASE)

# Made-up names a document is allowed to use — name: why.
ALLOWED_EXAMPLE_NAMES: dict[str, str] = {
    "tblNewThing": (
        "docs/gdpr-erasure-policy.md, 'Adding a table that holds personal "
        "data': stands for a table somebody has not created yet."
    ),
}


def scan_docs(known: set[str]) -> tuple[int, int, list[tuple[str, int, str]]]:
    """Return (files read, table names seen, findings).

    A finding is (path relative to the repository, line number, name).
    """
    files = 0
    names_seen = 0
    findings: list[tuple[str, int, str]] = []
    # Every file whose name ends .md in any capitals. `rglob("*.md")` was
    # used before; it matches the ending in lower case only, so a file called
    # NOTES.MD was skipped without a word.
    docs = (p for p in DOCS_DIR.rglob("*") if p.is_file() and p.suffix.lower() == ".md")
    for doc in sorted(docs):
        files += 1
        rel = str(doc.relative_to(REPO_ROOT))
        text = doc.read_text(encoding="utf-8", errors="replace")
        for line_no, line in enumerate(text.splitlines(), start=1):
            # set(): one line reports the same wrong name once.
            for name in sorted(set(TABLE_REF_RE.findall(line))):
                names_seen += 1
                if name in known or name in ALLOWED_EXAMPLE_NAMES:
                    continue
                findings.append((rel, line_no, name))
    return files, names_seen, findings


def check() -> int:
    known = build_known_tables()
    print(f"Tables in full_schema.sql: {len(known)}")
    if not known:
        print("REFUSED: no tables were read from web/_sql/full_schema.sql, so "
              "every name would look wrong. Nothing was checked.")
        return 2

    files, names_seen, findings = scan_docs(known)
    print(f"Documents read under docs/: {files}")
    print(f"Table names seen in them: {names_seen}")
    if files == 0:
        print("REFUSED: no .md file was found under docs/. Nothing was checked.")
        return 2
    print(f"Names that are not real tables: {len(findings)}")

    if findings:
        # A wrong capital is the easiest mistake to make and the hardest to
        # see, so say what the real name is when only the capitals differ.
        by_lower = {name.lower(): name for name in known}
        print("\n### Documents naming tables that do not exist\n")
        for rel, line_no, name in findings:
            hint = by_lower.get(name.lower())
            suffix = f" (the real table is `{hint}`)" if hint else ""
            print(f"  • {rel}:{line_no} — `{name}` is not a table{suffix}")
        print(
            "\nCorrect the name to match web/_sql/full_schema.sql, or rewrite "
            "the sentence so it does not name a table. Only a deliberately "
            "made-up example belongs in ALLOWED_EXAMPLE_NAMES in this script."
        )

    print("\ncheck_docs_table_refs: reached the end.")
    if findings and "--strict" in sys.argv:
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(check())
