#!/usr/bin/env python3
"""
A page list prints ONE "…" per run of hidden pages, never one per position (#852).

Path: tools/audit-checks/check_pagination_one_ellipsis.py

WHY THIS EXISTS
---------------
Six pages drew their page list with the same copied loop: a "…" at page 4 and
another at page N-3 (or at 3 and N-2). When every page between those two
positions is hidden too — a long list opened at or next to either end — the two
"…" print side by side, so a long list's first page read « 1 2 3 … … 6 7 8 ».
(On a middle page the positions sit in two separate gaps and the old list was
right: page 6 of 12 read « 1 2 3 … 5 6 7 … 10 11 12 ».) #852 fixed the two
pages it named, and its independent check found the same loop on four more:
fixing the instances left the class open. All six now track whether the last
thing printed was already a "…" (`$inGap`). This check fails if the fixed-
position shape comes back, typically by copying an old page's loop.

WHAT IT CANNOT SEE, said plainly
--------------------------------
It looks for the literal shape `$x === 4 || $x === $totalPages - 3` (and the
`3 … - 2` variant), spacing free. A loop written another way that still prints a
"…" per position passes it. The behaviour is tested only where the browser test
reads a real page list: /attendance and /admin/users, page 1 and a middle page.
It reads PHP files under web/ only.
"""
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]
SHAPE = re.compile(r"\$\w+\s*===\s*([34])\s*\|\|\s*\$\w+\s*===\s*\$totalPages\s*-\s*([23])")

found = []
for path in sorted((ROOT / "web").rglob("*.php")):
    text = path.read_text(encoding="utf-8", errors="replace")
    for match in SHAPE.finditer(text):
        line = text.count("\n", 0, match.start()) + 1
        found.append(f"{path.relative_to(ROOT)}:{line}  {match.group(0)}")

if found:
    print("A page list prints a '…' at a FIXED position, so two can sit side by side (#852):")
    for item in found:
        print("  " + item)
    print("Print ONE '…' per run of hidden pages instead: keep $inGap, set it false when a number is")
    print("printed and true when a '…' is, and print a '…' only while it is false. See")
    print("web/_apps/attendance/index.php.")
    sys.exit(1)
print("OK: no page list prints a '…' at a fixed position.")
print("   It looks for one copied shape only; the behaviour itself is tested by tools/top-bar-selftest.mjs on two pages.")
