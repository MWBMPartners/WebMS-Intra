#!/usr/bin/env python3
"""
A form that SAVES a setting installation-wide must not PREFILL it from the
merged settings (#674, #668).

Path: tools/audit-checks/check_install_wide_prefill.py

WHY THIS EXISTS
---------------
`$SETTINGS` has already merged the installation-wide rows with the open
organisation's own rows by the time any page runs. So a form that fills its
boxes from `App::settings()` shows the ORGANISATION's value, while its Save
handler writes to the row with `siteID IS NULL` -- the INSTALLATION's.

The consequence needs no mistake by anybody. A global administrator opens the
page while in an organisation that has its own override, sees that override in
the box, and presses Save without touching anything. That organisation's value
is now the installation's, for every organisation on it.

**That is a boundary between two parties, not a convenience for one person
wearing two hats** (the rule in `.claude/CLAUDE.md`, "One install, many
organisations, and DIFFERENT PEOPLE at each level"): the installation's
administrator may be a hosting provider and the organisations its customers.
One customer's choice quietly becoming everyone's default is the wrong
direction across that boundary.

`Portal\\Core\\InstallationSettings::layer()` exists for exactly this: it holds
the installation-wide rows alone. `admin/settings/repeats` and the
`transcription.monthCapPence` field already read it.

WHAT IT LOOKS FOR
-----------------
For every page under `web/_apps/admin/` that writes a setting with
`settingKey = ? AND siteID IS NULL`, it collects the top-level setting groups
that page writes. It then looks at the OTHER pages in the same folder and
reports one that
  * contains a `<form>`, and
  * reads one of those same groups out of `App::settings()`, and
  * **never reads `InstallationSettings::layer()` at all.**

**That last term is what keeps it from crying wolf, and it was added because
the check reported the very fix that closed #674.** A page that has reckoned
with the two layers often reads BOTH on purpose: `admin/transcription` fills
every box from the layer and then reads the merged array once more, to say so
when an organisation holds a row of its own that the boxes do not show. A check
that reported that would be telling somebody to undo the fix.

The cost of that term, stated plainly: **a page that reads the layer for one
field and the merged array for another is NOT reported.** A half-done fix
passes. The pending list is what covers the pages known to need one, and a
reader of a finding still has to look at the page.

**Why a `<form>` is required, and this is the whole reason the check is
narrow enough to be useful.** A runner, an OAuth callback or a webhook handler
in the same folder reads the merged value because it needs the EFFECTIVE value
to do its job -- that is correct and must not be reported. Measured on
30 October 2026: `integrations/zoom/callback.php`, `connect.php`, `webhook.php`,
`maintenance/offsite-backup-run.php` and `admin/transcription/run.php` all read
the merged array and all of them should. Without the `<form>` test this check
would report **five false positives out of nineteen** — that figure said
"twenty-one" until the independent check measured it — and a check that cries
wolf gets switched off.

Comments are stripped with PHP's own tokenizer before anything is matched,
because searching the text of a file finds comments too: counting handlers by
grep in #641 gave 46 against a true 45, the extra being a file whose comment
NAMED the call it does not make.

WHAT THIS CANNOT SEE, said plainly because an overstated guarantee is worse
than none
--------------------------------------------------------------------------
1. **It works at the level of a setting GROUP, not a key.** A page that writes
   `sms.enabled` installation-wide and reads `sms.somethingElse` from the merged
   array for display is reported. That is deliberate -- matching exact keys
   would need to follow array subscripts through PHP variables -- but it means a
   finding has to be read before it is believed.
2. **It only looks in the same folder.** A form whose Save handler lives
   elsewhere is invisible to it.
3. **It cannot tell whether a merged read FEEDS a box.** A page that reads the
   merged value only to show "currently in use by this organisation", beside a
   box filled from the installation layer, is reported although it is right.
4. **It says nothing about per-organisation settings pages**, which SHOULD read
   the merged array. It only looks at pages whose own folder writes with
   `siteID IS NULL`.
5. **A page that reads BOTH layers is passed without judging which feeds which
   box** -- see above. This is the one it is most likely to be wrong about.
6. **A read through the inherited `$SETTINGS` variable is invisible.**
   `reads_merged()` matches `App::settings()` only, and `$SETTINGS` is the
   variable a page inherits -- `admin/integrations/index.php` and
   `admin/maintenance/backup.php` both use it. Proved by planting a page that
   reads `$SETTINGS['auth']` beside a form: not reported.
7. **A page that is its OWN saver is never examined**, because the walk skips
   the pairing of a file with itself. `admin/maintenance/backup.php` really has
   this fault that way -- it prefills `backup.schedule.*` and writes those same
   keys installation-wide in one file -- and is invisible here.
Both 6 and 7 were missing from this list until the independent check of
30 October 2026 planted them. **A list of limits that omitted a LIVE instance
overstated the gate**, which this project treats as worse than no guarantee.

THE PENDING LIST
----------------
`check_install_wide_prefill.pending.txt` names the pages that already had this
shape when the check was written, so the check can run as a gate from the start
and no NEW page can join them. `--strict` fails while the list holds anything,
which is how the list is kept from becoming permanent. A page listed that no
longer has the fault also fails, so the list cannot go stale -- the same rule
the api-spec check's named lists follow, for the same reason.

USAGE
    python3 -I tools/audit-checks/check_install_wide_prefill.py
    python3 -I tools/audit-checks/check_install_wide_prefill.py --strict
    python3 -I tools/audit-checks/check_install_wide_prefill.py --list

Package:   WebMS-Intra
Author:    MWBM Partners Ltd (t/a MWservices)
Copyright: All Rights Reserved
Version:   1.0.0
"""

from __future__ import annotations

import re
import subprocess
import sys
import tempfile
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
ADMIN = REPO / "web/_apps/admin"
PENDING = Path(__file__).with_suffix("").with_name("check_install_wide_prefill.pending.txt")

# 🏷️ What makes a page a WRITER of installation-wide settings. Two spellings,
#    and both are needed:
#
#    1. `INSERT INTO tblSettings (… siteID …) VALUES (… NULL …)` — every
#       upsert in this repository ends with one of these, whichever way it
#       looked the row up first.
#    2. `UPDATE … tblSettings … siteID IS NULL` — an update that finds the row
#       by the column rather than by its id.
#
#    **A `SELECT … WHERE settingKey = ? AND siteID IS NULL` is deliberately NOT
#    enough, and that was the first version's mistake.** Most savers here do
#    read the row that way before updating it by id, so matching the SELECT
#    looked like a reliable signal — but `maintenance/demo-data.php` reads
#    exactly like that and writes nothing installation-wide, so it was treated
#    as the saver for its whole folder and produced a false accusation against
#    `maintenance/backup.php`. Requiring a real write removes it. Found by the
#    independent check of 30 October 2026.
#
#    Believing there was only ONE spelling was the other mistake: the first
#    version's comment said "every admin saver in this repository spells it this
#    way", and `maintenance/backup.php`, `settings/group.php` and
#    `apps/index.php` all insert straight into the column instead, so the gate
#    could not see them at all.
WRITES_INSTALL_WIDE = re.compile(
    r"INSERT\s+INTO\s+`?tblSettings`?[^;]*?\bsiteID\b[^;]*?VALUES[^;]*?NULL"
    r"|UPDATE[^;]*?`?tblSettings`?[^;]*?siteID\s+IS\s+NULL",
    re.IGNORECASE | re.DOTALL)

# 🎚️ A saver that decides the LEVEL AT RUN TIME, by binding siteID as a
#    parameter instead of writing a literal NULL.
#
#    WHY THIS EXISTS, and it is a fault this check developed rather than one it
#    found (#677, 30 October 2026). `admin/settings/organisation/save.php` was
#    changed so that one helper writes either level, with the level passed in:
#
#        INSERT INTO tblSettings (… siteID …) VALUES (?, ?, 0, ?, NOW())
#
#    WRITES_INSTALL_WIDE looks for a literal NULL in that VALUES list, so it
#    stopped matching, the file stopped being a saver, and the whole folder
#    stopped being examined. Proved by planting: putting one key back to
#    installation-wide went completely unnoticed.
#
#    That is the worst possible direction, because **passing the level in is
#    exactly the pattern the fourteen pages still on the pending list should
#    adopt.** Every one of them fixed that way would quietly leave this check's
#    view, and the list would empty while the coverage emptied with it.
#
#    So such a file is NAMED here with the reason the fault cannot arise in it,
#    and an unlisted one FAILS. This check cannot judge which of a run-time
#    saver's keys go installation-wide — that is decided by an argument at each
#    call site, not by the SQL — so the entry has to carry the argument a reader
#    would otherwise have to reconstruct. It fails BOTH ways: a file that has
#    stopped having this shape fails too, so an entry cannot quietly go stale.
#
#    Measured before this was written: exactly two files under admin/ have this
#    shape and no literal-NULL write as well. Twenty have both, so they are
#    still examined by the ordinary branch and need no line here.
WRITES_LEVEL_AT_RUN_TIME = re.compile(
    r"INSERT\s+INTO\s+`?tblSettings`?[^;]*?\bsiteID\b[^;]*?VALUES[^;]*?\?",
    re.IGNORECASE | re.DOTALL)

LEVEL_CHOSEN_AT_RUN_TIME = {
    "web/_apps/admin/settings/organisation/save.php":
        "#677 — one $upsert() helper, with the level as its last argument and no default. "
        "Nine org.* keys and portal.support.email go to the organisation's own row; `site.url` "
        "alone goes installation-wide. Its sibling page cannot have the fault this check looks "
        "for, because it does not read `site.url` from the merged array at all: it reads it "
        "through PortalAddress, which queries `siteID IS NULL` directly (#500).",
    "web/_apps/admin/settings/repeats/save.php":
        "#668 — writes a per-organisation limit that the installation's value caps. Both it and "
        "its sibling page read `InstallationSettings::layer()`, so `reads_layer()` would skip "
        "the page in any case; this line records that the saver is understood rather than "
        "unexamined.",
}

# 🚫 Groups that are not settings at all -- these come from `require` lines such
#    as `header.php`, which match the dotted-name pattern.
NOT_SETTING_GROUPS = {"footer", "header", "index", "save"}

PHP_STRIP = r"""<?php
$code = '';
foreach (token_get_all(file_get_contents($argv[1])) as $t) {
    if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
    $code .= is_array($t) ? $t[1] : $t;
}
echo $code;
"""


def without_comments(path: Path, helper: Path) -> str:
    """
    The file's code with comments removed, using PHP's own tokenizer.

    Not a regular expression: an apostrophe in a comment puts every later quote
    out of step, which is a mistake `check_page_head_tags.py` made and had to
    have corrected.
    """
    done = subprocess.run(["php", str(helper), str(path)], capture_output=True, text=True)
    return done.stdout if done.returncode == 0 else path.read_text(encoding="utf-8", errors="replace")


def groups_written(code: str) -> set[str]:
    """The top-level setting groups a page writes installation-wide."""
    keys = re.findall(r"'([a-z_][a-zA-Z0-9_]*\.[a-zA-Z0-9_.]+)'", code)
    return {k.split(".")[0] for k in keys} - NOT_SETTING_GROUPS


def reads_merged(code: str, group: str) -> bool:
    """Does this page take `group` out of the MERGED settings?"""
    return (re.search(r"App::settings\(\)\s*\[\s*'" + re.escape(group) + r"'", code) is not None
            or re.search(r"App::settings\(\s*'" + re.escape(group) + r"[.'\)]", code) is not None)


def reads_layer(code: str) -> bool:
    """
    Does this page read the installation-wide layer at all?

    A page that does has reckoned with the two layers being different, and often
    reads BOTH deliberately -- `admin/transcription` fills its boxes from the
    layer and then reads the merged array to REPORT a difference. Reporting such
    a page would be telling somebody to undo the fix, which is exactly what this
    check did to the #674 fix before this term existed.
    """
    return "InstallationSettings::layer" in code


def findings() -> tuple[list[tuple[str, str, str]], str | None, list[str]]:
    if ADMIN.is_dir() is False:
        return [], "%s is missing, so there is nothing to check." % ADMIN.relative_to(REPO), []
    if subprocess.run(["php", "-v"], capture_output=True).returncode != 0:
        return [], ("php is not runnable, and this check strips comments with PHP's own "
                    "tokenizer rather than a regular expression. It cannot run without it."), []

    # 🗂️ The comment-stripping helper goes in a temporary directory, NOT in this
    #    folder: a run killed part way used to leave an untracked `.php` file in
    #    `tools/audit-checks/`, where every other file is a check.
    with tempfile.TemporaryDirectory(prefix="wmi-prefill-") as scratch:
        helper = Path(scratch) / "_strip_php_comments.php"
        helper.write_text(PHP_STRIP, encoding="utf-8")
        out: list[tuple[str, str, str]] = []
        runtime: list[str] = []
        for saver in sorted(ADMIN.rglob("*.php")):
            code = without_comments(saver, helper)
            if WRITES_INSTALL_WIDE.search(code) is None:
                # 🎚️ Not a literal-NULL writer. It may still write tblSettings
                #    at a level chosen at run time, which this check cannot
                #    judge -- so say so rather than pass over it in silence.
                #    See LEVEL_CHOSEN_AT_RUN_TIME.
                if WRITES_LEVEL_AT_RUN_TIME.search(code) is not None:
                    runtime.append(saver.relative_to(REPO).as_posix())
                continue
            groups = groups_written(code)
            if not groups:
                continue
            for page in sorted(saver.parent.glob("*.php")):
                if page == saver:
                    continue
                page_code = without_comments(page, helper)
                if "<form" not in page_code.lower():
                    continue          # a runner or callback: a merged read is right
                if reads_layer(page_code) is True:
                    continue          # it knows about the two layers; see the header
                hit = sorted(g for g in groups if reads_merged(page_code, g))
                if hit:
                    out.append((page.relative_to(REPO).as_posix(),
                                saver.relative_to(REPO).as_posix(),
                                ", ".join(hit)))
        return out, None, runtime


def pending() -> set[str]:
    if PENDING.is_file() is False:
        return set()
    return {line.split("#", 1)[0].strip()
            for line in PENDING.read_text(encoding="utf-8").splitlines()
            if line.split("#", 1)[0].strip()}


def main() -> int:
    found, why, runtime = findings()
    if why is not None:
        print("✗ " + why, file=sys.stderr)
        return 1

    strict = "--strict" in sys.argv
    waiting = pending()
    by_page = {page: (saver, groups) for page, saver, groups in found}

    if "--list" in sys.argv:
        print("Forms that prefill a merged value their own Save writes installation-wide: %d"
              % len(found))
        for page, saver, groups in found:
            print("   %-52s groups: %-16s saved by %s" % (page, groups, saver))
        print("\nOn the pending list: %d" % len(waiting))
        print("\nSavers that choose the level at run time, so this check cannot judge them: %d"
              % len(runtime))
        for saver in sorted(runtime):
            listed = LEVEL_CHOSEN_AT_RUN_TIME.get(saver)
            print("   %-52s %s" % (saver, "named" if listed is not None else "NOT NAMED"))
        return 0

    problems: list[str] = []
    new = sorted(set(by_page) - waiting)
    if new:
        lines = []
        for page in new:
            saver, groups = by_page[page]
            lines.append("      %s\n         prefills: %s\n         saved installation-wide by: %s"
                         % (page, groups, saver))
        problems.append(
            "✗ %d form(s) prefill a merged setting that their own Save writes "
            "installation-wide:\n%s\n   A global administrator who opens one of these while in an "
            "organisation that has its own value sees that value, and pressing Save makes it the "
            "INSTALLATION's, for every organisation. Read these fields from "
            "`InstallationSettings::layer()` instead — the layer holding the installation-wide rows "
            "alone — as `admin/settings/repeats` and `transcription.monthCapPence` already do."
            % (len(new), "\n".join(lines)))

    # 🎚️ Savers whose level is an argument. Both directions, for the reason in
    #    LEVEL_CHOSEN_AT_RUN_TIME: an unnamed one has left this check's view
    #    without anybody saying why that is safe, and a named one that no
    #    longer has the shape means the entry is describing nothing.
    unnamed = sorted(set(runtime) - set(LEVEL_CHOSEN_AT_RUN_TIME))
    if unnamed:
        problems.append(
            "✗ %d saver(s) write tblSettings at a level chosen AT RUN TIME, and are not named in "
            "this check's own list:\n%s\n   Binding siteID as a parameter takes a file out of the "
            "branch above, so nothing here can tell which of its keys go installation-wide and the "
            "whole folder stops being examined. That is how the fix for #677 silently removed its "
            "own page from this check. Add a line to LEVEL_CHOSEN_AT_RUN_TIME saying which keys go "
            "to which level and why the merged-prefill fault cannot arise in the sibling page."
            % (len(unnamed), "\n".join("      " + u for u in unnamed)))
    gone = sorted(set(LEVEL_CHOSEN_AT_RUN_TIME) - set(runtime))
    if gone:
        problems.append(
            "✗ %d entry(ies) in LEVEL_CHOSEN_AT_RUN_TIME no longer have that shape:\n%s\n   "
            "Delete each line. A named exception that has stopped describing anything is worse "
            "than none, because it is believed."
            % (len(gone), "\n".join("      %s  —  listed as: %s" % (g, LEVEL_CHOSEN_AT_RUN_TIME[g])
                                     for g in gone)))

    stale = sorted(waiting - set(by_page))
    if stale:
        problems.append(
            "✗ %d entry(ies) on %s no longer have this shape, so the list is stale:\n%s\n   Delete "
            "each line. A pending list that names something already fixed stops describing "
            "anything, and is then believed."
            % (len(stale), PENDING.name, "\n".join("      " + s for s in stale)))

    if problems:
        print("\n\n".join(problems), file=sys.stderr)
        print("\n%d problem(s). The rule is #674, and the boundary it protects is #668." % len(problems),
              file=sys.stderr)
        return 1

    if waiting and strict:
        print("✗ %d page(s) are still waiting on %s:\n%s\n   --strict fails while any remain, which "
              "is what stops the list becoming permanent."
              % (len(waiting), PENDING.name, "\n".join("      " + w for w in sorted(waiting))),
              file=sys.stderr)
        return 1

    if waiting:
        print("✓ No NEW form prefills an installation-wide setting from the merged array. "
              "%d page(s) still on %s (run with --strict to fail on those)."
              % (len(waiting), PENDING.name))
    else:
        print("✓ No form prefills an installation-wide setting from the merged array, and the "
              "pending list is empty.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
