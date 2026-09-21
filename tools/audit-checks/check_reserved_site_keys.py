#!/usr/bin/env python3
"""
Reserved organisation-key check (#515).

WHY THIS EXISTS
----------------
When several organisations share one installation, each one's pages can sit
behind its own "site key" in the address — for example /youth/calendar. The
save page (`web/_apps/admin/sites/save.php`) used to check only that a key
was lower-case letters, digits and hyphens. Nothing stopped an administrator
keying an organisation "offline" or "login" — the same names the portal
itself already answers on. Measured on a real database: an organisation
keyed "login" turns the bare /login address into a redirect loop that never
resolves, and one keyed "offline" can make a signed-in visitor's browser
store that organisation's own dashboard as the offline fallback page (see
`web/_core/Auth.php`'s `oldServiceWorkerWouldStore()` / `logout()` comments
for the full case). `Portal\\Core\\ReservedKeys` (`web/_core/ReservedKeys.php`)
is the fix — the one place that says which keys are reserved and why — and
`web/_apps/admin/sites/save.php` now refuses to write one.

This script is the automatic proof that the fix stays wired up. A class
existing is not the same as every writer actually calling it, and a
hand-typed list inside that class (the one part of it that genuinely has to
be typed by hand — see below) can drift from the code it is meant to
describe without anybody noticing for months.

WHAT THIS CHECKS
----------------
  A. SEEDED KEYS. Every `INSERT [IGNORE] INTO tblSites (...) VALUES (...)`,
     `INSERT [IGNORE] INTO tblSites (...) SELECT ...` and `REPLACE INTO
     tblSites (...) VALUES (...)` in `web/_sql/*.sql` is read for its
     `siteKey` value, and that value must not be a reserved name (a plain
     `INSERT INTO` was the only shape recognised here until an independent
     checker found the gap — #515 follow-up — even though `INSERT IGNORE
     INTO` alone already appears in 10 files elsewhere in this codebase). A
     trailing `ON DUPLICATE KEY UPDATE ...` clause — the idempotent-seed
     shape used 243 times elsewhere here — is cut off the row values before
     they are read, the same way the SELECT form's own column list is
     already cut at its top-level `FROM`; otherwise the clause's own
     `VALUES(colName)` calls confuse the tuple boundary and the real
     `siteKey` can be mis-read as unreadable. This is the same shape of
     fault `check_webroot_shadowing.py` exists for: a value baked into a
     migration is just as real as one typed into a form, and a future
     migration COULD seed an organisation with a reserved key by mistake
     (today, only the harmless `default` key is seeded).
  B. THE HAND-TYPED PART AGREES WITH THE CODE. Two of `ReservedKeys`'s three
     sources are read LIVE by the class itself (the routes table, the real
     web root), so they cannot go stale on their own. The third —
     `Router::SPECIAL_ROUTE_FIRST_SEGMENTS`, the addresses
     `Router::handleSpecialRoutes()` answers itself without ever consulting
     `tblRoutes` — has to be typed once, because it lives as
     `$path === '...'` / `str_starts_with($path, '...')` literals inside a
     method body, not rows in a table anything can query. This check parses
     `handleSpecialRoutes()` itself and compares its literals against the
     constant, in BOTH directions: an address added to the method without
     being added to the constant is invisible to ReservedKeys and would let
     an organisation take over it; a name kept in the constant after the
     matching code was removed over-reserves harmlessly, but still means
     the constant's own promise ("this is what the method actually does") is
     false, so it is reported too.
  C. THE CLASS STILL READS THE LIVE SOURCES. `web/_core/ReservedKeys.php`
     must still mention `tblRoutes`, `public_html` and
     `SPECIAL_ROUTE_FIRST_SEGMENTS` — a future edit that quietly replaced a
     live query with a typed list would pass every other check here while
     making the whole "cannot go stale" claim false.
  D. ONE WRITER. Every `.php` file under `web/_apps`, `web/_core`,
     `web/_install` and `web/public_html` whose non-comment source contains
     `INSERT INTO tblSites`, `INSERT IGNORE INTO tblSites`, `UPDATE
     tblSites` or `REPLACE INTO tblSites` — even split across two lines of
     the same string or heredoc — must be on the allow list below. Today
     that is exactly one file, `web/_apps/admin/sites/save.php`. A second
     writer would bypass the refusal entirely, however carefully save.php
     itself is written.
  E. THE WRITER STILL CHECKS. Every allow-listed file must contain the text
     `ReservedKeys::` — proof the refusal has not been quietly unwired from
     the one file that is allowed to write the table.

WHAT THIS CANNOT SEE
---------------------
  - The LIVE web root on a real server may hold files this repository does
    not (a customer's own upload, say). `Portal\\Core\\ReservedKeys` sees
    exactly what exists at the moment it runs, on that server — this static
    check only sees what is committed here, so it can miss a reservation
    the real class would catch. That is a live-vs-static gap, not a fault
    in either one.
  - This check reads SQL and PHP as TEXT, not a running database, so it can
    over-reserve: `check_webroot_shadowing.seeded_addresses()` still
    "sees" `api` as a registered address even though migration 158 deleted
    every `api/*` row it once seeded, because `api` is also a router
    special (KIND_SPECIAL) and stays reserved either way — see the plan at
    `.claude-work/resume/p515--plan.md` §1.3. Over-reserving is the safe
    direction: the worst it does is refuse a key that would in fact have
    been free.
  - A `tblSites` write built from string PIECES — PHP concatenation (`.=`,
    `sprintf()` with a variable table name, and the like) rather than one
    continuous run of source text next to `INSERT`/`UPDATE`/`REPLACE` — is
    still invisible to check D. This is deliberate, not merely unfixed: the
    two halves of a concatenated write are separated by PHP syntax (`.`,
    `"`, `;`, a variable name), not only whitespace, so closing this gap
    would mean guessing what a piece built at runtime says rather than
    reading it — and the same trade-off `check_account_writes_guarded.py`
    documents for its own, identically-shaped check. A write's `INSERT`/
    `tblSites` keywords split only by a LINE BREAK inside one continuous
    string or heredoc is NOT in this blind spot any more: non-comment lines
    are joined before this check searches, so `INSERT INTO` at the end of
    one line and `tblSites` at the start of the next is caught (#515
    follow-up, proven against a synthetic second writer written that way).
  - A seeded `siteKey` value that is not a plain quoted literal (built with
    a SQL function, say) cannot be read by check A. That is reported as a
    finding in its own right ("make it a plain quoted value"), never
    silently skipped — a value this script cannot read is exactly the kind
    of thing a human needs to look at, not evidence that nothing is wrong.

Exit code:
  0 — no findings
  1 — at least one finding. NOT gated behind --strict, unlike most checks in
      this directory — --strict is still accepted, for consistency with how
      the other scripts here are invoked, but makes no difference to the
      exit code (mirrors `check_webroot_shadowing.py`'s own choice, for the
      same reason: an organisation quietly taking over one of the portal's
      own addresses is not a heuristic to triage later).

Usage:
  python3 tools/audit-checks/check_reserved_site_keys.py [--strict]
"""

from __future__ import annotations

import importlib.util
import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
SQL_DIR = REPO_ROOT / "web" / "_sql"
ROUTER_FILE = REPO_ROOT / "web" / "_core" / "Router.php"
RESERVED_KEYS_FILE = REPO_ROOT / "web" / "_core" / "ReservedKeys.php"

# ---------------------------------------------------------------------------
# The only file allowed to write tblSites (check D). Every other write is a
# finding — a second writer would bypass save.php's reserved-key refusal
# however carefully save.php itself is written. Each entry needs a reason;
# "it works" is not one.
# ---------------------------------------------------------------------------
ALLOWED_WRITERS = {
    "web/_apps/admin/sites/save.php": (
        "the only page that creates or edits an organisation; #515 wires "
        "ReservedKeys:: into it directly"
    ),
}

WRITE_SCAN_DIRS = ["web/_apps", "web/_core", "web/_install", "web/public_html"]

TBLSITES_WRITE_RE = re.compile(
    r"\b(INSERT\s+(?:IGNORE\s+)?INTO|UPDATE|REPLACE\s+INTO)\s+`?tblSites`?\b",
    re.IGNORECASE,
)

# A trailing `ON DUPLICATE KEY UPDATE ...` clause (the idempotent-seed shape
# used 243 times elsewhere in this codebase) does not need its own pattern
# here: it can only ever follow a plain `INSERT INTO`/`INSERT IGNORE INTO`,
# which the pattern above already matches on the keywords alone, regardless
# of what comes after them on the same statement. It DOES need handling
# further down, in seeded_site_keys()'s VALUES-tuple parser — see the
# comment there for why.
ON_DUPLICATE_KEY_UPDATE_RE = re.compile(r"ON\s+DUPLICATE\s+KEY\s+UPDATE", re.IGNORECASE)


def _load_shadowing_module():
    """
    Import check_webroot_shadowing.py BY PATH (not by package name), so this
    script keeps working however it is invoked — from the repo root, from
    this directory, or from anywhere else — exactly like the check it is
    reusing already has to (see that script's own docstring). Reusing
    seeded_addresses()/webroot_entries() rather than re-implementing them
    means a parser fix in one place fixes both checks at once, and the two
    scripts can never quietly disagree about what "a registered address"
    or "a real thing in the web root" means.
    """
    spec = importlib.util.spec_from_file_location(
        "check_webroot_shadowing",
        Path(__file__).resolve().parent / "check_webroot_shadowing.py",
    )
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None  # for type-checkers; always true for a real file
    spec.loader.exec_module(module)
    return module


def _split_top_level(text: str) -> list[str]:
    """Split a SQL value/select list on commas that are NOT inside brackets or quotes."""
    parts: list[str] = []
    depth = 0
    current = ""
    in_quote = False
    for ch in text:
        if ch == "'":
            in_quote = not in_quote
        if not in_quote:
            if ch == "(":
                depth += 1
            elif ch == ")":
                depth -= 1
            elif ch == "," and depth == 0:
                parts.append(current)
                current = ""
                continue
        current += ch
    parts.append(current)
    return [p.strip() for p in parts]


def seeded_site_keys() -> list[tuple[Path, str | None, str]]:
    """
    Every site key an `INSERT [IGNORE] INTO tblSites` or `REPLACE INTO
    tblSites` statement in web/_sql/*.sql seeds, as
    (file, key-or-None, raw-expression-for-the-message).

    key is None when the value is not a plain quoted literal — reported as
    its own finding rather than silently skipped (see the module docstring).

    Handles both the VALUES(...) form and the INSERT ... SELECT form. The
    SELECT form's column list has to be cut at the TOP-LEVEL FROM only:
    migration 015's seed nests a second SELECT (with its own FROM) inside a
    COALESCE(...) ahead of the real FROM clause, and cutting at the first
    FROM found anywhere in the text truncated the select list and cried
    wolf on a clean tree — found and fixed while prototyping this check.
    """
    # `INSERT IGNORE INTO` and `REPLACE INTO` are both genuine ways to seed a
    # row in this codebase's migrations (`INSERT IGNORE INTO` — 10 files;
    # `REPLACE INTO` — none against tblSites today, but check D already
    # treats it as a real write to the same table, so a migration written
    # that way must be just as visible here). Both were invisible to the
    # plain `INSERT\s+INTO` this pattern used to require — found by an
    # independent checker (#515 follow-up). `INSERT ... ON DUPLICATE KEY
    # UPDATE` is NOT a third keyword to add here: it is a clause that only
    # ever trails a plain `INSERT INTO`/`INSERT IGNORE INTO`, already
    # matched below — see the VALUES-branch comment further down for the
    # separate handling that clause needs.
    stmt_re = re.compile(
        r"(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO)\s+`?tblSites`?\s*"
        r"\(([^)]*)\)\s*(VALUES|SELECT)\s*(.*?);",
        re.IGNORECASE | re.DOTALL,
    )
    out: list[tuple[Path, str | None, str]] = []
    for sql_file in sorted(SQL_DIR.glob("*.sql")):
        try:
            text = sql_file.read_text(encoding="utf-8", errors="replace")
        except OSError:
            continue
        if "tblSites" not in text:
            continue
        for m in stmt_re.finditer(text):
            cols = [c.strip().strip("`").lower() for c in m.group(1).split(",")]
            if "sitekey" not in cols:
                continue
            idx = cols.index("sitekey")
            body = m.group(3)

            if m.group(2).upper() == "VALUES":
                # An idempotent seed can end with `ON DUPLICATE KEY UPDATE
                # col = VALUES(col), ...` instead of (or as well as)
                # `INSERT IGNORE` — 243 other seeds in this codebase do
                # this. That clause is never itself a place a NEW siteKey
                # could hide (every column in it just repeats
                # `VALUES(colName)`, referring back to the row already
                # parsed), but its own `(...)` groups confuse the
                # tuple-boundary regex just below, which expects the LAST
                # `(...)` group in `body` to be the final row: without this
                # cut, a one-row seed still reads correctly by luck (the
                # clause's text gets absorbed into whichever column comes
                # last), but a multi-row seed, or a seed where `siteKey`
                # itself is the LAST column, could report the row unreadable
                # instead of reading it — tested with both shapes while
                # building this fix. Cut at the TOP LEVEL only (never inside
                # a quoted string or a nested bracket), mirroring the
                # SELECT-form's own top-level FROM-cut just below, so a
                # siteName value that literally contained the words "on
                # duplicate" could never be mistaken for the clause.
                depth = 0
                in_quote = False
                cut = len(body)
                for i, ch in enumerate(body):
                    if ch == "'":
                        in_quote = not in_quote
                    if in_quote:
                        continue
                    if ch == "(":
                        depth += 1
                    elif ch == ")":
                        depth -= 1
                    elif (
                        depth == 0
                        and (i == 0 or not body[i - 1].isalnum())
                        and ON_DUPLICATE_KEY_UPDATE_RE.match(body, i) is not None
                    ):
                        cut = i
                        break
                body = body[:cut]

                tuples = re.findall(r"\((.*?)\)(?=\s*,\s*\(|\s*$)", body, re.DOTALL)
                expr_lists = [_split_top_level(t) for t in tuples] or [
                    _split_top_level(body.strip().strip("()"))
                ]
            else:
                # INSERT ... SELECT a, b, c FROM ... — cut the select list at
                # the TOP-LEVEL FROM only (see the docstring above for why).
                depth = 0
                in_quote = False
                cut = len(body)
                for i, ch in enumerate(body):
                    if ch == "'":
                        in_quote = not in_quote
                    if in_quote:
                        continue
                    if ch == "(":
                        depth += 1
                    elif ch == ")":
                        depth -= 1
                    elif (
                        depth == 0
                        and body[i : i + 4].upper() == "FROM"
                        and (i == 0 or not body[i - 1].isalnum())
                    ):
                        cut = i
                        break
                expr_lists = [_split_top_level(body[:cut])]

            for exprs in expr_lists:
                if idx >= len(exprs):
                    out.append((sql_file, None, "column/value count mismatch"))
                    continue
                literal = re.fullmatch(r"'([^']*)'", exprs[idx])
                out.append(
                    (
                        sql_file,
                        literal.group(1) if literal is not None else None,
                        exprs[idx].strip()[:60],
                    )
                )
    return out


def router_special_segments_from_code() -> set[str]:
    """
    The first part of every address `Router::handleSpecialRoutes()` really
    answers, parsed straight from the method body — the "ground truth" side
    of check B. Mirrors `Portal\\Core\\ReservedKeys::all()`'s own reasoning
    for why this cannot be read live: these are literal string comparisons
    inside a method, not rows in a table.
    """
    src = ROUTER_FILE.read_text(encoding="utf-8")
    start = src.index("function handleSpecialRoutes")
    end = src.index("function findRoute")
    body = src[start:end]
    literals = re.findall(r"\$path\s*===\s*'([^']+)'", body)
    literals += re.findall(r"str_starts_with\(\s*\$path\s*,\s*'([^']+)'\s*\)", body)
    return {lit.split("/")[0] for lit in literals if lit != ""}


def router_special_segments_from_constant() -> set[str]:
    """The SPECIAL_ROUTE_FIRST_SEGMENTS constant's own contents, parsed from its array literal."""
    src = ROUTER_FILE.read_text(encoding="utf-8")
    m = re.search(
        r"SPECIAL_ROUTE_FIRST_SEGMENTS\s*=\s*\[(.*?)\];",
        src,
        re.DOTALL,
    )
    if m is None:
        return set()
    return {lit for lit in re.findall(r"'([^']+)'", m.group(1))}


def scan_tblsites_writers() -> list[Path]:
    """
    Every `.php` file under the four directories a real write could live in,
    whose SOURCE (comment lines stripped) contains an INSERT/UPDATE/REPLACE
    against tblSites. A leading `//`, `#` or `*` marks a comment line —
    matching `check_account_writes_guarded.py`'s own definition, so the two
    checks cannot disagree about what counts as "really writing" the table.

    The non-comment lines are JOINED before the pattern is searched, rather
    than matched one line at a time — a multi-line SQL string or heredoc can
    legitimately put `INSERT INTO` at the end of one line and `tblSites` at
    the start of the next, and a plain per-line search never sees the two
    halves together (found by an independent checker, #515 follow-up: the
    original per-line version was proven to miss exactly this shape). Both
    patterns already tolerate the newline this introduces —
    `TBLSITES_WRITE_RE`'s `\\s+` matches it the same as a space — so joining
    with `"\\n"` rather than `" "` changes nothing else about what matches;
    it only stops a statement genuinely split across lines from hiding in
    the gap between two per-line searches. This does NOT close the
    string-concatenation case one bullet down (`"INSERT INTO " . "tblSites"`)
    — there the two halves are separated by PHP syntax (`.`, `"`, `;`), not
    only whitespace, so the same join cannot bridge them, correctly, because
    concatenated pieces might just as easily be a variable table name that
    ISN'T tblSites at all.
    """
    found: list[Path] = []
    for rel_dir in WRITE_SCAN_DIRS:
        base = REPO_ROOT / rel_dir
        if not base.is_dir():
            continue
        for php_file in base.rglob("*.php"):
            try:
                text = php_file.read_text(encoding="utf-8", errors="replace")
            except OSError:
                continue
            if "tblSites" not in text:
                continue
            code_lines = [
                line
                for line in text.splitlines()
                if not (
                    line.strip().startswith("//")
                    or line.strip().startswith("#")
                    or line.strip().startswith("*")
                )
            ]
            if TBLSITES_WRITE_RE.search("\n".join(code_lines)):
                found.append(php_file)
    return found


def check() -> int:
    findings: list[str] = []

    # -------------------------------------------------------------------
    # Build the reserved set exactly the way ReservedKeys::all() does, but
    # from repository text instead of a live database/server — see the
    # module docstring for what that trade-off costs.
    # -------------------------------------------------------------------
    shadow = _load_shadowing_module()
    reserved: dict[str, str] = {}
    for address in shadow.seeded_addresses():
        first = address.split("/")[0].lower()
        if first != "":
            reserved.setdefault(first, "an address the portal already answers on")
    for segment in router_special_segments_from_code():
        reserved.setdefault(segment.lower(), "handled by the router itself")
    for name in shadow.webroot_entries():
        reserved.setdefault(name.lower(), "a real file or folder in the web root")

    # ---------------------------------------------------------------
    # Check A — seeded site keys must not be reserved.
    # ---------------------------------------------------------------
    for sql_file, key, raw in seeded_site_keys():
        rel = sql_file.relative_to(REPO_ROOT)
        if key is None:
            findings.append(
                f"CANNOT READ the seeded site key in {rel} (expression: {raw!r}) — "
                "make it a plain quoted value so this check (and ReservedKeys) can see it."
            )
        elif key.lower() in reserved:
            findings.append(
                f"{rel} seeds the site key '{key}', which is "
                f"{reserved[key.lower()]}. Remove it or seed a different key."
            )

    # ---------------------------------------------------------------
    # Check B — the hand-typed constant must agree with the code, in
    # BOTH directions.
    # ---------------------------------------------------------------
    from_code = router_special_segments_from_code()
    from_constant = router_special_segments_from_constant()
    missing_from_constant = sorted(from_code - from_constant)
    stale_in_constant = sorted(from_constant - from_code)
    for segment in missing_from_constant:
        findings.append(
            f"Router::handleSpecialRoutes() answers '{segment}/...' but "
            f"Router::SPECIAL_ROUTE_FIRST_SEGMENTS does not list '{segment}' — "
            "ReservedKeys would not reserve it, so an organisation could take "
            "that address over. Add it to the constant, in the same change "
            "that added the route."
        )
    for segment in stale_in_constant:
        findings.append(
            f"Router::SPECIAL_ROUTE_FIRST_SEGMENTS lists '{segment}' but "
            f"Router::handleSpecialRoutes() no longer answers it — the constant "
            f"over-reserves (safe), but its own promise that it matches the "
            f"code is now false. Remove '{segment}' from the constant, or "
            f"restore the matching route."
        )

    # ---------------------------------------------------------------
    # Check C — ReservedKeys.php still reads the live sources, rather
    # than a typed list quietly replacing one of them.
    # ---------------------------------------------------------------
    if RESERVED_KEYS_FILE.is_file():
        rk_text = RESERVED_KEYS_FILE.read_text(encoding="utf-8")
        for literal in ("tblRoutes", "public_html", "SPECIAL_ROUTE_FIRST_SEGMENTS"):
            if literal not in rk_text:
                findings.append(
                    f"web/_core/ReservedKeys.php no longer mentions '{literal}' — "
                    "somebody may have replaced a live source with a typed list. "
                    "Restore the live read (or, if the shape genuinely changed, "
                    "update this check to match)."
                )
    else:
        findings.append("web/_core/ReservedKeys.php is missing entirely.")

    # ---------------------------------------------------------------
    # Check D — save.php is the only writer of tblSites.
    # ---------------------------------------------------------------
    writers = scan_tblsites_writers()
    for writer in writers:
        rel = str(writer.relative_to(REPO_ROOT))
        if rel not in ALLOWED_WRITERS:
            findings.append(
                f"{rel} writes tblSites but is not on the allow list. Organisations "
                "must be written only through the save page, which refuses reserved "
                "keys — a second writer bypasses that refusal. Route the write "
                "through save.php, or add this file to ALLOWED_WRITERS at the top "
                "of this script WITH THE REASON and its own call to ReservedKeys::."
            )

    # ---------------------------------------------------------------
    # Check E — every allow-listed writer still calls ReservedKeys::.
    # ---------------------------------------------------------------
    for rel_str in ALLOWED_WRITERS:
        writer_path = REPO_ROOT / rel_str
        if not writer_path.is_file():
            findings.append(f"{rel_str} is on the allow list but no longer exists — remove the entry.")
            continue
        text = writer_path.read_text(encoding="utf-8", errors="replace")
        if "ReservedKeys::" not in text:
            findings.append(
                f"{rel_str} writes tblSites but no longer mentions ReservedKeys:: — "
                "the reserved-key refusal appears to have been unwired."
            )

    # -------------------------------------------------------------------
    if not findings:
        print(
            "check_reserved_site_keys: OK — seeded site keys are all free, "
            "Router's special-address constant matches the code, "
            "ReservedKeys.php still reads its live sources, and "
            f"{', '.join(sorted(ALLOWED_WRITERS))} is the only writer of tblSites, "
            "and it still checks ReservedKeys::."
        )
        return 0

    print(f"check_reserved_site_keys: FOUND {len(findings)} ISSUE(S)\n")
    for f in findings:
        print(f"  • {f}")
    print()
    print(
        "An organisation key that clashes with one of the portal's own addresses can "
        "take over that address — see web/_core/ReservedKeys.php's own header comment "
        "for the reproduced case this whole mechanism exists to close (#515)."
    )

    # Deliberately NOT gated behind --strict — see the module docstring.
    return 1


if __name__ == "__main__":
    sys.exit(check())
