#!/usr/bin/env python3
# Path: tools/audit-checks/check_table_name_comparisons.py
# -----------------------------------------------------------------------------
# A table name is never compared with capitals mattering 🔤 (#697)
# -----------------------------------------------------------------------------
# @package   WebMS-Intra (development tooling — never deployed; only web/ is)
# @author    MWBM Partners Ltd (t/a MWservices)
# @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
# @license   All Rights Reserved
# @link      https://github.com/MWBMPartners/WebMS-Intra/issues/697
#
# WHAT WENT WRONG, AND WHY ONE DATABASE SERVER CANNOT CATCH IT COMING BACK
#   Whether MySQL treats two table names differing only in capitals as the same
#   table is a SERVER setting, `lower_case_table_names`. On a server set to 1,
#   every name `SHOW TABLES` and `information_schema` report comes back in LOWER
#   CASE. `DbBackup` compares such names against names typed in its own source,
#   and each place that did so exactly was silently broken there:
#
#     - #694's cascade rescue kept NOTHING and printed no warning;
#     - #697's carry never ran, so a waiting row was lost;
#     - restored mail was NOT held — `holdRestoredMail()`'s own docblock says
#       that once turned three waiting messages into six sent copies;
#     - restored held texts were not recorded as not sent, so one could reach
#       somebody's phone a second time at the customer's cost;
#     - a removed column was not stripped from a restored row;
#     - and, after a HALF fix, the Backup page's "put these rows back" button
#       could never succeed while "discard" went on working — the only button
#       that worked was the one that throws the data away.
#
#   All measured on `mysql:8.0.36 --lower-case-table-names=1`. Every self-test
#   in this repository runs ONE server, set to 0, where the names match exactly,
#   so NO test here can see any of it. That is the #646 situation — correct
#   guards over faults that have already happened, with nothing able to run them
#   — and it is the whole reason this check exists.
#
# THE RULE, which took four review rounds to state correctly:
#   **A table-keyed lookup is safe when BOTH SIDES COME FROM THE SAME PLACE, and
#   unsafe when one side is folded by the server and the other is not.**
#   - Keys typed in the source, index typed in the source: safe.
#     (`CASCADE_RESCUE[$table]` — capitals matter there, deliberately.)
#   - Keys from `information_schema`, index from `SHOW TABLES`: safe on one
#     server. The cross-server residue is #698.
#   - One of each: the fault.
#   Earlier attempts said "the fault is an index that came from the SOURCE",
#   which was wrong BOTH ways — it cleared `REMOVED_COLUMNS[$table]`, one of the
#   very faults being generalised, and condemned three correct sites.
#
# WHAT IT CHECKS
#   A. No `in_array()` whose needle is a `'tbl…'` literal, strict OR loose. The
#      needle is the anchor, not the haystack's name: an earlier version listed
#      the haystack variables it knew about and `$restored` was a fourth, holding
#      two live faults. It also required the third argument to be `true`, so a
#      two-argument call walked past — and a loose `in_array` over strings is
#      just as case-sensitive, because PHP's `==` between two non-numeric
#      strings compares them exactly.
#   B. No `'tbl…'` literal used as an array SUBSCRIPT: that is the same
#      comparison written another way, and `$facts['tblRestoreHeldRows']` is how
#      the first fix for all this stayed broken.
#   C. `namedIn()` and `forTable()` have EXACTLY the bodies named below. Not "the
#      word strcasecmp appears" (a string literal satisfied that), not "a call to
#      strcasecmp appears" (`strcasecmp(...) === 0 && $table === $name` satisfied
#      that too). The repository's own rule, written twice before this check
#      existed: a check that looks for the TEXT of a guard stays green when
#      `&& false` is added to its condition — pin the WHOLE condition.
#   D. `columnFacts()`'s answer is never subscripted in the same statement.
#   E. Nor through ONE assignment, inside the four methods where the table name
#      is a POSTED name that `keepsRowsFor()` has just required to match
#      `CASCADE_RESCUE` exactly — so it carries the source's spelling while that
#      array is keyed by the server's.
#
# HOW IT AVOIDS CRYING WOLF, since #668's rule is that a check which does gets
# switched off. It anchors on the table name, so an ordinary
# `in_array($column, $orderedColumns, true)` is not its business. And check E is
# scoped to four methods: applied to the whole file it reported FIFTEEN safe
# sites, where the index is a manifest name and both sides agree.
#
# WHAT IT CANNOT SEE, said plainly rather than left to be discovered:
#   - A needle in a VARIABLE: `$want = 'tblX'; in_array($want, $list)`.
#   - A needle built by concatenation, or a literal haystack with a variable
#     needle.
#   - `===`, `array_search`, `array_key_exists`, `isset($map['tblX'])`, a
#     `switch` or a `match` on a table name.
#   - A `columnFacts()` array passed to another method, or reached through more
#     than one assignment, or subscripted outside those four methods.
#   - Anything in any file but `web/_core/DbBackup.php`. Searched by hand on
#     1 November 2026: no comparison of this shape exists elsewhere under
#     `web/_core` or `web/_apps/admin/maintenance`.
#   - Whether the portal actually behaves correctly on such a server. Only a
#     second server proves that, and nothing in this repository runs one.
# -----------------------------------------------------------------------------

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TARGET = ROOT / "web" / "_core" / "DbBackup.php"

TABLE_LITERAL = re.compile(r"""^\s*['"]tbl[A-Za-z0-9_]*['"]\s*$""")

# The two helpers' bodies, comments out and whitespace collapsed. Any edit to
# either one fails this check, including a harmless one. That is deliberate:
# they are eight lines each, they are the foundation of two safety nets, and a
# deliberate change means updating the text here in the same commit — which is
# the moment somebody has to think about it.
EXPECTED_BODIES = {
    "namedIn": (
        "foreach ($names as $name) { if (strcasecmp($table, (string) $name) === 0) "
        "{ return true; } } return false;"
    ),
    "forTable": (
        "foreach ($byTableName as $name => $entry) { if (strcasecmp($table, (string) $name) === 0) "
        "{ return is_array($entry) === true ? $entry : []; } } return [];"
    ),
}

HELPER_SHAPES = {
    "namedIn": r"private\s+static\s+function\s+namedIn\s*\([^)]*\)\s*:\s*bool\s*\{(.*?)\n    \}",
    "forTable": r"private\s+static\s+function\s+forTable\s*\([^)]*\)\s*:\s*array\s*\{(.*?)\n    \}",
}

# The methods whose `$table` carries the SOURCE's spelling: keepsRowsFor() has
# just demanded it match CASCADE_RESCUE exactly.
HELD_ROW_METHODS = ("putHeldRowsBack", "discardHeldRows", "rowsWaitingToBePutBack", "nameInsideHeldRow")


def mask_strings_and_comments(php: str) -> str:
    """Comments blanked; string literals kept but filled with `x`, same length.

    MASKED rather than stripped, and the length preserved, so a `}` or the word
    `strcasecmp` inside a message cannot answer a question about the code while
    reported line numbers stay right.
    """
    out = []
    i = 0
    n = len(php)
    while i < n:
        two = php[i:i + 2]
        if two == "/*":
            end = php.find("*/", i + 2)
            end = n if end == -1 else end + 2
            out.append("".join(c if c == "\n" else " " for c in php[i:end]))
            i = end
            continue
        if two == "//":
            end = php.find("\n", i)
            end = n if end == -1 else end
            out.append(" " * (end - i))
            i = end
            continue
        if php[i] in "'\"":
            quote = php[i]
            j = i + 1
            while j < n:
                if php[j] == "\\":
                    j += 2
                    continue
                if php[j] == quote:
                    break
                j += 1
            j = min(j, n - 1)
            body = php[i + 1:j]
            out.append(quote + "".join("\n" if c == "\n" else "x" for c in body) + quote)
            i = j + 1
            continue
        out.append(php[i])
        i += 1
    return "".join(out)


def split_arguments(call: str) -> list:
    """A call's top-level arguments, ignoring commas inside nested () or []."""
    args = []
    depth = 0
    current = ""
    for ch in call:
        if ch in "([":
            depth += 1
        elif ch in ")]":
            depth -= 1
        if ch == "," and depth == 0:
            args.append(current)
            current = ""
            continue
        current += ch
    args.append(current)
    return args


def arguments_of(source: str, open_paren: int) -> str:
    """The text between a call's parentheses, matching them properly."""
    depth = 0
    for i in range(open_paren, len(source)):
        if source[i] == "(":
            depth += 1
        elif source[i] == ")":
            depth -= 1
            if depth == 0:
                return source[open_paren + 1:i]
    return ""


def line_of(text: str, offset: int) -> int:
    return text[:offset].count("\n") + 1


def main() -> int:
    if TARGET.is_file() is False:
        print("FAIL: " + str(TARGET) + " is not there.")
        return 1

    raw = TARGET.read_text(encoding="utf-8")
    source = mask_strings_and_comments(raw)
    problems = []

    # A. in_array('tbl…', …) — matched by the call's own parentheses, so it does
    #    not matter what follows: a comparison, a semicolon or an assignment.
    for found in re.finditer(r"\bin_array\s*\(", source):
        args_masked = arguments_of(source, found.end() - 1)
        if args_masked == "":
            continue
        args_raw = raw[found.end():found.end() + len(args_masked)]
        parts = split_arguments(args_raw)
        if len(parts) < 2 or TABLE_LITERAL.match(parts[0]) is None:
            continue
        loose = "" if re.match(r"^\s*true\s*$", parts[-1]) else (
            " - and leaving `true` off makes it no safer, because PHP compares two non-numeric "
            "strings exactly"
        )
        problems.append(
            "web/_core/DbBackup.php:" + str(line_of(source, found.start())) + " looks for the table name "
            + parts[0].strip() + " in a list with in_array()" + loose
            + ", which is case-sensitive. Use self::namedIn()."
        )

    # B. a 'tbl…' literal used as an array subscript.
    for found in re.finditer(r"\[\s*(['\"])tbl[A-Za-z0-9_]*\1\s*\]", raw):
        if source[found.start():found.end()].strip() == "":
            continue
        problems.append(
            "web/_core/DbBackup.php:" + str(line_of(raw, found.start())) + " subscripts an array with the "
            "table name " + found.group(0).strip("[] ") + ". An array keyed by table name is a comparison "
            "too, and it is case-sensitive. Use self::forTable()."
        )

    # C. both helpers, pinned WHOLE.
    for name, shape in HELPER_SHAPES.items():
        helper = re.search(shape, raw, flags=re.S)
        if helper is None:
            problems.append(
                "DbBackup::" + name + "() is gone. Those two are the only places a table name is compared, "
                "or used as a key, ignoring capitals; without them every check above is satisfied by "
                "having no comparison at all."
            )
            continue
        body = re.sub(r"/\*.*?\*/", "", helper.group(1), flags=re.S)
        body = re.sub(r"(?m)//.*$", "", body)
        body = " ".join(body.split())
        if body != EXPECTED_BODIES[name]:
            problems.append(
                "DbBackup::" + name + "()'s body is not the one this check expects, so it can no longer "
                "promise that the comparison ignores capitals."
                + "\n      expected: " + EXPECTED_BODIES[name]
                + "\n      found:    " + body
                + "\n      If the change is deliberate, update EXPECTED_BODIES in this check, in the same "
                "commit."
            )

    # D. columnFacts() subscripted in the same statement.
    for found in re.finditer(r"columnFacts\s*\(\s*\)\s*\[", source):
        problems.append(
            "web/_core/DbBackup.php:" + str(line_of(source, found.start())) + " subscripts columnFacts()'s "
            "answer directly. It is keyed by the SERVER's own table names, which are lower case on a server "
            "with lower_case_table_names = 1. Use self::forTable()."
        )

    # E. the same, through ONE assignment, inside the held-row methods only.
    for method in HELD_ROW_METHODS:
        body = re.search(
            r"function\s+" + method + r"\s*\([^)]*\)\s*:[^{]*\{(.*?)\n    \}",
            source,
            flags=re.S,
        )
        if body is None:
            continue
        inside = body.group(1)
        starts_at = body.start(1)
        for assigned in re.finditer(r"\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*\$this->columnFacts\s*\(\s*\)\s*;", inside):
            held = assigned.group(1)
            for used in re.finditer(r"\$" + re.escape(held) + r"\s*\[", inside[assigned.end():]):
                where = starts_at + assigned.end() + used.start()
                problems.append(
                    "web/_core/DbBackup.php:" + str(line_of(source, where)) + " (in " + method + "()) "
                    "subscripts $" + held + ", which was assigned from columnFacts(). In this method the "
                    "table name carries the SOURCE's spelling while that array is keyed by the SERVER's. "
                    "Use self::forTable()."
                )

    if problems:
        print("FAIL: a table name is compared with capitals mattering (#697).")
        for problem in problems:
            print("  - " + problem)
        print(
            "\n  Why this matters: on a server with lower_case_table_names = 1 every name the\n"
            "  server reports is lower case, so such a comparison answers false and the restore's\n"
            "  safety nets silently stop working - measured, on mysql:8.0.36. No self-test here\n"
            "  can see it, because they all run one server set to 0.\n"
            "\n  The rule: a table-keyed lookup is safe when BOTH SIDES COME FROM THE SAME PLACE."
        )
        return 1

    print("OK: no table name in DbBackup.php is compared, or used as an array key, with capitals")
    print("    mattering; columnFacts()'s answer is never subscripted by a table name; and both")
    print("    namedIn() and forTable() have exactly the bodies this check expects (#697).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
