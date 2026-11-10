#!/usr/bin/env python3
"""
Role key consistency check (#516).

Roles used to be one portal-wide list. Since #516, each organisation has
its own list, seeded from a "standard set" of fourteen roles that exists in
THREE separate places, which now have to agree with each other and with
every place in the code that actually asks for one of those roles:

  1. `Portal\\Core\\Roles::STANDARD` (`web/_core/Roles.php`) — the PHP
     constant `Roles::seedStandardSet()` reads when a new organisation is
     created.
  2. The A9 seed inside `web/_sql/202_roles_per_organisation.sql` — the
     same fourteen rows, written out again as SQL, for every organisation
     that already existed when this migration runs.
  3. The matching "from 202_roles_per_organisation.sql" fold-in block near
     the end of `web/_sql/full_schema.sql` — the SAME fourteen rows again,
     for a brand-new install. The installer runs `full_schema.sql` (which
     seeds organisation 1) and THEN replays every numbered migration, so
     202's own seed does run on a fresh install too and simply finds the
     fourteen rows already there. The fold exists because of the standing
     rule that every database change goes in BOTH places, which
     `check_schema_seed_parity.py` enforces (it fails without the fold).
     An earlier version of this note claimed 202's seed would never run on
     a fresh install; that was wrong.

A key that exists in one of the three but not the others is exactly the
shape #516 itself was: a role nobody can actually be given, or a role the
code asks for that no organisation will ever have. This check compares all
three lists, PLUS every place in the code that names a role key by a fixed
string, and fails if any of them disagree.

WHAT THIS CHECK LOOKS AT
-------------------------------------------------------------------------
Code-referenced keys (source A), gathered from:
  - every literal `hasRole('…')` / `hasRole("…")` call anywhere under
    `web/`;
  - the `ROLE_KEY` constant in `web/_core/Giving.php` — WHICH NO LONGER
    EXISTS, and the reading is kept deliberately rather than deleted. #739
    turned `Giving::canManage()` into the `giving.records` capability, whose
    shipped role key (`treasurer`) now lives in
    `Portal\\Core\\Capabilities::CAPABILITIES` instead, and that list is
    checked against `Roles::STANDARD` by
    `tools/audit-checks/check_capability_registry.py` (its check D). So the
    key is still compared, by the check that owns it. This reading finds
    nothing today and costs nothing; it is here so that a `ROLE_KEY` constant
    reintroduced in that file would still be compared rather than silently
    unchecked;
  - every string literal inside a `'gates' => […]` array in
    `web/_core/ReportRegistry.php` that does not start with `@` (the
    `@siteAdmin`/`@rootAdmin` gate words are not role keys at all);
  - the `visitors.coordinator_role` default value seeded in
    `web/_sql/full_schema.sql`.

The three lists (source B/C1/C2):
  - `Roles::STANDARD`'s keys, in `web/_core/Roles.php`;
  - the fourteen `'…' AS roleKey` / `UNION ALL SELECT '…'` keys in the A9
    block of `202_roles_per_organisation.sql`;
  - the same shape, in the "from 202_roles_per_organisation.sql" block of
    `full_schema.sql`.

WHAT THIS CHECK CANNOT SEE (be honest about the blind spots)
-------------------------------------------------------------------------
  - `hasRole($variable)` — a role key read from a variable rather than
    written as a string literal (e.g. `tours/api/active.php`,
    `ReportRegistry::callerPassesGates()`'s own run-time comparison
    against whatever a saved report definition contains) is invisible to
    this check. Those are exercised instead by the real-database proofs
    in the #516 build plan.
  - A key typed into a `tblSettings` value, a saved newsletter segment, or
    a saved workflow step's `assigneeValue` — all of those are DATA, not
    code, and this check only reads source files.
  - Any key referenced only from JavaScript.
  - An organisation-ADDED role (created at `/admin/roles`, not part of the
    standard fourteen) — by definition it exists in none of the three
    lists this check compares, and that is correct; this check only
    protects the STANDARD set from drifting apart, not every role that
    could ever exist on a real installation.

Exit code:
  0 — every code-referenced key is in the standard set, and the standard
      set, the migration's list and the fold's list are all the same set
      of syntactically valid keys.
  1 — any of the above disagree, or a key anywhere fails the shape a role
      key must have (`^[a-z][a-z0-9_]{1,49}$`).

Usage:
  python3 tools/audit-checks/check_role_keys.py [--strict]
  python3 tools/audit-checks/check_role_keys.py --web <dir>
    (--web replaces the "web" directory this scans under — used to point
    this check at a throwaway scratch copy, WITHOUT ever touching the real
    working tree, the same convention check_account_writes_guarded.py uses.)

--strict is accepted, for consistency with every other checker in this
directory, but makes no difference: a finding here is always the exact
shape #516 itself was (a role nobody can be given, or a stray key the code
compares against nothing), so this is unconditionally blocking, the same
deliberate choice check_account_writes_guarded.py already made for #518.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]

KEY_SHAPE_RE = re.compile(r"^[a-z][a-z0-9_]{1,49}$")


def strip_php_comments(text: str) -> str:
    """
    Strip `//` line, `#` line, and `/* … */` block PHP comments while
    preserving line numbers — the same approach
    check_account_writes_guarded.py and check_php_table_refs.py already
    use in this directory, so a docblock that happens to mention
    "hasRole('treasurer')" in prose (as this feature's own class headers
    do, explaining what the method does) never produces a false finding.
    A `#` immediately followed by `[` is left alone, because that is a
    PHP 8 attribute, not a comment.
    """
    text = re.sub(r"/\*.*?\*/", lambda m: "\n" * m.group(0).count("\n"), text, flags=re.DOTALL)
    text = re.sub(r"//[^\n]*", "", text)
    text = re.sub(r"#(?!\[)[^\n]*", "", text)
    return text


def find_code_referenced_keys(web_dir: Path) -> list[tuple[str, int, str]]:
    """
    Every role key the CODE asks for by a fixed string literal, as
    (relative file path, line number, raw key text) — the raw text is kept
    (not lower-cased) so the caller can separately flag a non-lower-case
    literal as informational, without losing where it came from.
    """
    found: list[tuple[str, int, str]] = []

    hasrole_re = re.compile(r"hasRole\(\s*['\"]([^'\"]+)['\"]")
    for php in sorted(web_dir.rglob("*.php")):
        try:
            raw = php.read_text(encoding="utf-8", errors="ignore")
        except OSError:
            continue
        text = strip_php_comments(raw)
        rel = php.relative_to(web_dir.parent).as_posix()
        for lineno, line in enumerate(text.splitlines(), start=1):
            for m in hasrole_re.finditer(line):
                found.append((rel, lineno, m.group(1)))

    # Giving::ROLE_KEY — a single fixed constant, not a hasRole() call.
    giving_php = web_dir / "_core" / "Giving.php"
    if giving_php.exists():
        text = strip_php_comments(giving_php.read_text(encoding="utf-8", errors="ignore"))
        rel = giving_php.relative_to(web_dir.parent).as_posix()
        for lineno, line in enumerate(text.splitlines(), start=1):
            m = re.search(r"ROLE_KEY\s*=\s*'([^']+)'", line)
            if m:
                found.append((rel, lineno, m.group(1)))

    # ReportRegistry.php — every 'gates' => [...] array, every literal not
    # starting with '@' (those are role keys; '@siteAdmin'/'@rootAdmin'
    # are the two special gate words and are not roles at all).
    registry_php = web_dir / "_core" / "ReportRegistry.php"
    if registry_php.exists():
        raw = registry_php.read_text(encoding="utf-8", errors="ignore")
        text = strip_php_comments(raw)
        rel = registry_php.relative_to(web_dir.parent).as_posix()
        for m in re.finditer(r"'gates'\s*=>\s*\[([^\]]*)\]", text):
            # Work out an approximate line number from the match start.
            lineno = text.count("\n", 0, m.start()) + 1
            for lit in re.findall(r"'([^']*)'", m.group(1)):
                if lit != "" and lit.startswith("@") is False:
                    found.append((rel, lineno, lit))

    return found


def find_coordinator_role_default(web_dir: Path) -> list[tuple[str, int, str]]:
    """The visitors.coordinator_role default value seeded in full_schema.sql."""
    schema = web_dir / "_sql" / "full_schema.sql"
    if schema.exists() is False:
        return []
    text = schema.read_text(encoding="utf-8", errors="ignore")
    rel = schema.relative_to(web_dir.parent).as_posix()
    found: list[tuple[str, int, str]] = []
    for lineno, line in enumerate(text.splitlines(), start=1):
        m = re.search(r"'visitors\.coordinator_role'\s*,\s*'([^']+)'", line)
        if m:
            found.append((rel, lineno, m.group(1)))
    return found


def extract_standard_php(web_dir: Path) -> tuple[list[str], str]:
    """
    The keys of `Roles::STANDARD`, in file order, plus a human label for
    error messages when this source cannot be read at all.
    """
    roles_php = web_dir / "_core" / "Roles.php"
    label = "web/_core/Roles.php Roles::STANDARD"
    if roles_php.exists() is False:
        return [], label
    text = roles_php.read_text(encoding="utf-8", errors="ignore")
    m = re.search(r"public const STANDARD = \[(.*?)\n    \];", text, re.DOTALL)
    if m is None:
        return [], label
    keys = re.findall(r"\n        '([^']*)' => \[", m.group(1))
    return keys, label


def extract_a9_block(text: str, anchor_index: int) -> list[str]:
    """
    Shared extraction for the A9-shaped `CROSS JOIN ( SELECT '…' AS
    roleKey, … UNION ALL SELECT '…', … ) k` block, starting the search
    from `anchor_index` in `text`. Returns the fourteen (or however many)
    roleKey literals in order, or an empty list if the shape is not found
    at all.
    """
    rest = text[anchor_index:]
    j = rest.find("CROSS JOIN")
    if j == -1:
        return []
    block_text = rest[j:]
    m = re.search(r"CROSS JOIN \(\s*(.*?)\n\)\s*k\b", block_text, re.DOTALL)
    if m is None:
        return []
    return re.findall(r"(?:SELECT|UNION ALL SELECT)\s+'([^']*)'", m.group(1))


# 🆕 Roles added to the standard set AFTER migration 202, each by its own
#    numbered migration. One entry per role, so a role added without a
#    migration to put it on existing installations fails this check.
#
#    ⚠️ MIGRATION 202 IS NEVER EDITED. It has already run on every
#       installation, so adding a fifteenth key to its A9 seed would be a
#       change nobody receives — the role would exist in code and in a fresh
#       install and be missing from every upgraded one. That is the #516 shape
#       exactly: a key the code asks for and nobody can hold. A later role
#       therefore gets a later migration, and is listed here.
LATER_STANDARD_ROLES = {
    # role key          migration that seeds it into every organisation
    "data_controller": "241_data_controller_role.sql",
    "website_editor": "258_events_show_on_website.sql",
    "website_setup": "262_public_website_setup.sql",
    # #529 / D-106: the role the permission `attendance.reports` ships at.
    "attendance_coordinator": "271_attendance_reports_permission.sql",
}


def extract_later_roles(sql_dir: Path) -> tuple[list[str], str, list[str]]:
    """Keys from the post-202 migrations, plus any whose migration is missing."""
    label = "web/_sql/<later migrations> standard-role seeds"
    found: list[str] = []
    missing: list[str] = []
    for key, filename in LATER_STANDARD_ROLES.items():
        migration = sql_dir / filename
        if migration.exists() is False:
            missing.append(
                f"the standard role {key} has no migration to put it on EXISTING "
                f"installations (expected web/_sql/{filename})"
            )
            continue
        text = migration.read_text(encoding="utf-8", errors="ignore")
        # The seed has to NAME the key, and has to mark it standard: a role
        # seeded with isStandard = 0 can be DELETED by an organisation, and
        # then the code asks for a key nobody can hold.
        if f"'{key}'" not in text:
            missing.append(f"{filename} does not name the role key {key}")
            continue
        if "isStandard" not in text:
            missing.append(
                f"{filename} does not mention isStandard, so {key} could be seeded as a "
                f"CUSTOM role — which an organisation can delete, leaving the code asking "
                f"for a key nobody can hold"
            )
            continue
        found.append(key)

    # 🧩 AND THE FOLD. A role that only the MIGRATION seeds is missing from a
    #    FRESH install, which is the mirror of the fault above and just as bad.
    #    Each later role needs its own `-- ── from <filename>` block in
    #    full_schema.sql naming the key: its own block, and not an entry added
    #    to migration 202's seed there, because that block is labelled with the
    #    migration it came from and 202 never seeded these roles.
    schema = sql_dir / "full_schema.sql"
    schema_text = schema.read_text(encoding="utf-8", errors="ignore") if schema.exists() else ""
    for key, filename in LATER_STANDARD_ROLES.items():
        anchor = schema_text.find(f"from {filename}")
        if anchor == -1:
            missing.append(
                f"the standard role {key} has no \"from {filename}\" block in "
                f"full_schema.sql, so a FRESH install would not have the role at all"
            )
            continue
        if f"'{key}'" not in schema_text[anchor:anchor + 4000]:
            missing.append(
                f"full_schema.sql's \"from {filename}\" block does not name the role key "
                f"{key}, so a FRESH install would not have it"
            )

    return found, label, missing


def extract_migration_202(sql_dir: Path) -> tuple[list[str], str]:
    label = "web/_sql/202_roles_per_organisation.sql A9 seed"
    migration = sql_dir / "202_roles_per_organisation.sql"
    if migration.exists() is False:
        return [], label
    text = migration.read_text(encoding="utf-8", errors="ignore")
    return extract_a9_block(text, 0), label


def extract_fold_block(sql_dir: Path) -> tuple[list[str], str]:
    label = "web/_sql/full_schema.sql \"from 202_roles_per_organisation.sql\" block"
    schema = sql_dir / "full_schema.sql"
    if schema.exists() is False:
        return [], label
    text = schema.read_text(encoding="utf-8", errors="ignore")
    anchor = text.rfind("── from 202_roles_per_organisation.sql")
    if anchor == -1:
        return [], label
    return extract_a9_block(text, anchor), label


def check() -> int:
    web_arg_index = None
    web_dir = REPO_ROOT / "web"
    for i, arg in enumerate(sys.argv):
        if arg == "--web" and i + 1 < len(sys.argv):
            web_dir = Path(sys.argv[i + 1]).resolve()
            web_arg_index = i
    if web_arg_index is not None:
        print(f"Scanning: {web_dir} (--web override)")
    else:
        print(f"Scanning: {web_dir}")

    if web_dir.exists() is False:
        print(f"ERROR: {web_dir} does not exist.")
        return 1

    sql_dir = web_dir / "_sql"

    standard_keys, standard_label = extract_standard_php(web_dir)
    migration_keys, migration_label = extract_migration_202(sql_dir)
    fold_keys, fold_label = extract_fold_block(sql_dir)
    later_keys, later_label, later_missing = extract_later_roles(sql_dir)
    # A role added after 202 lives in THREE places, none of them 202's own
    # seed: Roles::STANDARD, its own numbered migration, and its own fold block
    # in full_schema.sql. `extract_later_roles()` has just checked the last two
    # directly, so both the 202 lists below are topped up with those keys —
    # otherwise every later role reads as a disagreement with a migration that
    # was written before it existed.
    migration_keys = list(migration_keys) + list(later_keys)
    fold_keys = list(fold_keys) + list(later_keys)

    code_refs = find_code_referenced_keys(web_dir)
    code_refs += find_coordinator_role_default(web_dir)

    findings: list[str] = []
    informational: list[str] = []

    # Each gap already says what is wrong with it; the bullet and the shared
    # explanation go on once. An earlier version prefixed its own "  • " and
    # appended a sentence about migrations to EVERY gap, so the fold-block case
    # printed two bullets and then told the reader to add a migration that was
    # already there.
    if later_missing:
        for gap in later_missing:
            # No bullet of its own: the printer adds one to every finding.
            findings.append(gap)
        findings.append(
            "A role in Roles::STANDARD needs THREE things, and the one that is missing "
            "decides the symptom: its own numbered migration (or every UPGRADED organisation "
            "lacks it), its own \"from <that migration>\" block in full_schema.sql (or every "
            "FRESH install lacks it), and the entry in Roles::STANDARD itself. Either way the "
            "code ends up asking for a key somebody cannot hold, which is the #516 fault. "
            "MIGRATION 202 MUST NOT BE EDITED to add a later role: it has already run "
            "everywhere, so nobody would receive the change."
        )

    standard_set = {k.lower() for k in standard_keys}
    migration_set = {k.lower() for k in migration_keys}
    fold_set = {k.lower() for k in fold_keys}

    print(f"{standard_label}: {len(standard_keys)} keys")
    print(f"{migration_label}: {len(migration_keys) - len(later_keys)} keys"
          f" (+ {len(later_keys)} from {later_label})")
    print(f"{fold_label}: {len(fold_keys) - len(later_keys)} keys"
          f" (+ {len(later_keys)} from the later roles' own fold blocks)")
    print(f"Code-referenced role key literals found: {len(code_refs)}")
    print()

    # 1. Every code-referenced key (lower-cased) must be in the standard set.
    seen_missing: set[tuple[str, str]] = set()
    for rel, lineno, raw_key in code_refs:
        lower = raw_key.lower()
        if lower not in standard_set:
            entry = (rel, str(lineno))
            if entry not in seen_missing:
                seen_missing.add(entry)
                findings.append(
                    f"{rel}:{lineno} — role key '{raw_key}' is not in Roles::STANDARD "
                    f"(checked case-insensitively)"
                )
        elif raw_key != lower:
            informational.append(
                f"{rel}:{lineno} — role key literal '{raw_key}' is not lower-case "
                f"(Roles::has() normalises it, so this still works, but the stored "
                f"key itself is always lower-case)"
            )

    # 2. The three lists must be the SAME SET of keys.
    if standard_set != migration_set:
        only_standard = sorted(standard_set - migration_set)
        only_migration = sorted(migration_set - standard_set)
        findings.append(
            f"{standard_label} and {migration_label} disagree — "
            f"only in Roles::STANDARD: {only_standard or 'none'}; "
            f"only in the migration: {only_migration or 'none'}"
        )
    if standard_set != fold_set:
        only_standard = sorted(standard_set - fold_set)
        only_fold = sorted(fold_set - standard_set)
        findings.append(
            f"{standard_label} and {fold_label} disagree — "
            f"only in Roles::STANDARD: {only_standard or 'none'}; "
            f"only in the fold: {only_fold or 'none'}"
        )

    # 3. Every key in any of the three lists must have the shape a role
    #    key is allowed to have.
    for label, keys in (
        (standard_label, standard_keys),
        (migration_label, migration_keys),
        (fold_label, fold_keys),
    ):
        for k in keys:
            if KEY_SHAPE_RE.match(k) is None:
                findings.append(f"{label}: '{k}' does not match ^[a-z][a-z0-9_]{{1,49}}$")

    print(f"Findings: {len(findings)}")
    print(f"Informational (non-blocking): {len(informational)}")
    print()

    if informational:
        print("### Informational — non-lower-case code literals (Roles::has() normalises these)\n")
        for line in informational:
            print(f"  • {line}")
        print()

    if findings:
        print("### Role key findings\n")
        for line in findings:
            print(f"  • {line}")
        print(
            "\nA role key the code asks for but no organisation can ever have is exactly the "
            "shape #516 itself was. Either the code should ask for a key that is genuinely in "
            "the standard set, or (if this really is meant to become a new standard role) all "
            "three of Roles::STANDARD, the migration's A9 seed and full_schema.sql's matching "
            "fold-in block need the same new entry, in the same order.\n"
        )
        return 1

    return 0


if __name__ == "__main__":
    sys.exit(check())
