#!/usr/bin/env python3
"""
Event-visibility coverage check (#514 part P3).

#514 gave the portal ONE rule for who may see an event —
`Portal\\Core\\EventVisibility` — and made every imported event (one
copied in from an outside Google or Microsoft 365 calendar) READ-ONLY
everywhere else: no management tool, picker or linking handler may show
or change one (D5). Both promises are easy to break by accident, because
neither is enforced by the database — an ordinary `SELECT ... FROM
tblEvents` compiles and runs whether or not it carries the rule, or the
`externalFeedID IS NULL` refusal, or neither. A future page can read
`tblEvents` with no restriction at all, and nothing before this check
would have said so.

This check does not, and cannot, prove a query is CORRECT — only that a
line which reads `tblEvents` also has a MARKER nearby: a call to
`EventVisibility::`, or the literal condition `externalFeedID IS NULL`
or `externalFeedID IS NOT NULL`. A file that calls `EventVisibility::`
somewhere that never actually reaches the query below it would still
pass; a condition that reads `externalFeedID IS NULL` but is spelled
wrong, or applied to the wrong table alias, would still pass too. That
is the same deliberate trade-off `check_account_writes_guarded.py`
(#518) makes for account writes: a check this blunt is cheap to run on
every pull request and catches the shape most future mistakes will
actually take — a whole new query with NO marker at all — while a check
thorough enough to verify correct USE would need to understand what
each page is trying to do, which is what `tools/event-visibility-
selftest.php` and a human/AI review are for instead.

HOW A CANDIDATE IS FOUND, AND HOW A MARKER CLEARS IT — deliberately TWO
different texts of the same file, not one:

  1. PHP comments (`//` line, `#` line, `/* … */` block; a `#[` PHP 8
     attribute is left alone) are stripped first, keeping every line
     number unchanged, so a comment that merely MENTIONS `tblEvents` —
     documenting a past fix, or a docblock usage example — never counts
     as a candidate. A CANDIDATE is then any remaining line containing
     the literal text `tblEvents`.
  2. For each candidate, the marker search runs on the file's ORIGINAL
     text, comments still in it, over the 12 lines above the candidate
     through the 25 lines below it. A marker found only in a comment
     still counts — that is how a "locked re-read" that has already
     been checked once, a few lines above, can carry the marker as a
     comment instead of repeating the condition
     (`calendar/rsvp.php`'s two `FOR UPDATE` re-reads are written this
     way; `calendar/rsvp.php` is deliberately NOT on the ALLOWLIST below,
     so the rest of that file stays checked).

  Stripping comments before finding candidates, but searching the
  ORIGINAL text for markers, is not an accident of two similar-looking
  steps merged carelessly — it is why the two are kept separate at all.
  Stripping comments before BOTH steps would throw away exactly the
  comment-only markers the paragraph above describes, and would report
  the very lines they are meant to clear.

WHAT THIS CANNOT SEE (be honest about the blind spots, not just the
catch):
  - A query built far from the word `tblEvents` itself — for example a
    table name assembled from a variable or an array, the way
    `Portal\\Core\\GdprEraser`'s erasure catalogue builds table names.
  - A marker that sits in the window by coincidence, next to an
    unrelated query, rather than actually covering the candidate it is
    being credited for.
  - A file on the ALLOWLIST below that later gains a genuine new
    viewer-facing query — the whole file stays invisible to this check
    from that point on, which is exactly why every entry below records
    WHY it is safe today, so a reviewer changing that file can judge
    whether the reason still holds.
  - Whether the marker condition is spelled correctly, bound to the
    right value, or applied to the right table alias in a multi-table
    JOIN. `tools/event-visibility-selftest.php` and the real-database
    proofs in `.claude-work/resume/p514-p3--*.md` are what prove the
    RULE itself is right; this check only proves every matching line at
    least reaches for some form of it.

Exit code:
  0 — no findings (every candidate has a marker in its window, or sits
      on the ALLOWLIST with a stated reason)
  1 — at least one finding. This is NOT gated behind --strict, so running
      this script directly (by hand, or from a future step that honours
      its exit code) fails on a real finding. --strict is still accepted,
      for consistency with how every other script in this directory is
      invoked, but makes no difference to the exit code.

      🩹 FIX ROUND 1 (checker finding 3): this section used to go further
      and call the check "always-blocking rather than a heuristic to
      review later". That overstated what actually happens today.
      `.github/workflows/pr-security.yml` runs this script only inside
      its heuristic step, whose shell wrapper ends `|| true` — so on a
      real pull request a finding from THIS script adds a section to the
      PR comment bot posts, exactly like every other heuristic check in
      that step, and does NOT fail the GitHub Actions run. The exit code
      above is real and is exercised by this script's own proofs, and
      would matter the moment a future step is added that runs this
      script on its own and checks its exit code (the way the
      account-change guard, #518, already does) — but that step does not
      exist yet. Whether it should is an open question for the owner
      (see the part's report), separate from this script's own text
      being accurate about what CI does with it TODAY.

Usage:
  python3 tools/audit-checks/check_event_visibility.py [--strict]
  python3 tools/audit-checks/check_event_visibility.py --root <dir>
    (--root scans <dir> itself, recursively, INSTEAD of the normal
    three roots below — used by this part's own proof 2 to point the
    check at a throwaway scratch copy holding one planted file, WITHOUT
    ever touching the real working tree. The ALLOWLIST is keyed by path
    relative to `web/`, so it does not match files under a --root
    scratch folder UNLESS that folder copies web/'s own layout (a copy of
    _apps/, _core/ and public_html/ at its top level): then a file's path
    relative to the folder equals its path relative to web/, and the
    allow-list DOES apply. For a proof that must see every file, plant it
    at a path that is not on the list.)
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]

# The three roots this check scans in normal (non --root) mode — every
# place a page can live that might read tblEvents at all. web/_install
# and web/_vendor are deliberately excluded: the installer never reads
# an event, and the vendored JWT library never touches this table.
DEFAULT_SCAN_SUBDIRS = ("_apps", "_core", "public_html")

# Marker search text (case-insensitive: SQL keywords appear both upper
# and lower case across this codebase, and a class reference is always
# written `EventVisibility::` but a comment paraphrasing it should not
# be required to match case for case).
MARKER_RE = re.compile(
    r"EventVisibility::|externalFeedID\s+IS\s+(?:NOT\s+)?NULL",
    re.IGNORECASE,
)

# A candidate is any line still holding the literal table name after
# comments are stripped.
#
# 🩹 FIX ROUND 1 (checker finding 5, "same class" item found by the
# checker itself): the comment here used to credit the trailing `\b`
# word-boundary with rejecting `tblEventSeries`, `tblEventRSVPs`,
# `tblEventCategories`, `tblEventCoordinators` and every other
# `tblEvent*` table. That is not what actually happens, and the wrong
# explanation is worse than none, because it points a future reader at
# the wrong part of the pattern to check when something looks off. This
# regex has NO `re.IGNORECASE` flag, so it is case-sensitive, and the
# literal text `tblEvents` (a lower-case `s` as its ninth character)
# simply does not occur as a SUBSTRING inside `tblEventSeries` at all —
# that name's ninth character is a capital `S`. The match fails at the
# character comparison itself, before either `\b` is ever reached. The
# `\b` on both sides is real and does matter, but only for a
# hypothetical LONGER name that starts with the exact string
# "tblEvents" followed by more letters or digits — for example a future
# table literally called `tblEventsArchive` — which none of today's
# tables are.
CANDIDATE_RE = re.compile(r"\btblEvents\b")

# 📋 Whole files this check never looks inside at all, each with the
# reason it is safe today (the reason is what a reviewer re-checks when
# the file changes — see "What this cannot see" above). Keyed by path
# relative to `web/`, the same scheme check_account_writes_guarded.py
# uses for its own ALLOWED dict.
FILE_ALLOWLIST: dict[str, str] = {
    # ➕ Creates a brand-new, portal-owned event. Nothing here can ever
    # read or change an IMPORTED row, because there is no eventID to
    # look one up by until this statement itself creates one — D5 is
    # about existing rows, not ones a form is in the middle of making.
    "_apps/calendar/manage/import.php": "bulk-creates the organisation's OWN events from a CSV; never reads an existing row",
    "_apps/calendar/submit-save.php": "creates the organisation's OWN event from the public submission form; never reads an existing row",
    "_apps/events/api/create.php": "REST create endpoint for the organisation's OWN events; never reads an existing row",
    # 🔢 Counting and erasure only — these touch tblEvents to count rows
    # or to remove/export personal data FROM other tables that happen to
    # be joined to it for scoping, never to show an event's own content
    # to a viewer.
    "_core/Discipleship.php": "counts/joins tblEvents only to auto-complete a formation step from attendance; shows no event detail",
    "_apps/cron/_retention-sweep.php": "deletes expired REGISTRATIONS by their event's retention window; shows no event detail",
    "_core/GdprEraser.php": "erasure catalogue; removes a person's own data, never reads or shows an event",
    "_core/personal-data-catalogue.php": "documents what GdprEraser erases; no live query reads an event",
    "_apps/auth/account/data-export.php": "exports a member's OWN data; any event named in the export is one they already submitted, RSVPed to or were assigned to — never a viewer-facing listing",
    # 🛡️ Moderation-only: administrators reviewing PUBLIC submissions
    # before they become real events. Nothing here shows an IMPORTED
    # event, because imported events never pass through the public
    # submission queue at all.
    #
    # 🩹 FIX ROUND 1 (checker finding 5): this group used to also list
    # admin/calendar/reminders.php with the reason "configures reminder
    # rules ... no event content shown" — but that page ALSO prints an
    # "upcoming events" preview list with real event names on it, which
    # is not a moderation screen at all. Finding 2b (see below) proved a
    # hidden imported event's name leaked there. The file now carries the
    # rule itself and is off this list entirely.
    "_apps/admin/calendar/moderation.php": "moderates public event SUBMISSIONS, not events; administrators only",
    "_apps/admin/calendar/moderate.php": "approves/declines a public SUBMISSION into a real event; administrators only",
    # 🔄 The importer and resolver themselves. These are the ONE place
    # allowed to read and write an imported row's own columns — that is
    # their entire job — so the rule and the refusal do not apply to
    # them the way they apply to every other reader. FeedImporter.php
    # and FeedResolver.php do not exist yet (#514 part P6 builds them);
    # the entries stay here so P6 does not have to touch this file.
    "_apps/cron/import-feeds.php": "runs the importer/resolver job itself; the ONE place allowed to write an imported row",
    "_core/FeedImporter.php": "the importer itself (#514 part P6); reads and writes imported rows by design",
    "_core/FeedResolver.php": "the resolver itself (#514 part P6); reads and writes imported rows by design",
    "_apps/admin/calendar/feeds-save.php": "saves a calendar's OWN settings (address, audience level); does not read an event row",
    # 🔗 (fix round 1: checker findings 2 and 2b — the six files this
    # group used to list are GONE from it.) The plan's original premise
    # here — "cannot link to an imported event as of this part, and never
    # displays one either" — turned out to be false in two ways the
    # checker proved: (1) a link made BEFORE this fix landed stays in the
    # database, because a fix that closes a door going forward cannot
    # reach back and undo what already walked through it; (2) the
    # anonymous `api/livestream/ping.php` endpoint accepts ANY posted
    # event number with no check at all (a separate, pre-existing gap —
    # see the follow-up issue this fix round raises — not closed here),
    # so a livestream session row can point at a hidden imported event
    # without anyone ever having "linked" it through an admin screen.
    # Six files (Venues.php, ServicePlanLink.php, worship/plan.php,
    # worship/plans.php, admin/live/chat.php, admin/livestream/
    # dashboard.php) proved to show a linked imported event's NAME to a
    # viewer the visibility rule refuses that event to. Each now carries
    # `AND externalFeedID IS NULL` on its own JOIN and is watched by this
    # check like any other file. Only the two entries below remain in
    # this group, because both are proven to hold no query at all — see
    # each one's own reason.
    "_lang/en.php": "translation strings only; the one 'tblEvents' mention (line 388 when written) is inside a PHP comment, not a query. _lang/ is not one of the folders this check reads by default, so this entry only takes effect with a --root that includes it",
    "_core/GeoLocation.php": "shared address/geocoding helper; a comment on its Events integration mentions the table name, no query runs here",
    # 👁️‍🗨️ (0.4 change 11) Every statement in these two files already
    # joins `e.siteID = ?` or `.siteID = ?` and returns only a count, a
    # boolean or one already-authorised event's own name — never a
    # listing a viewer could use to discover an event. Every caller is
    # an administrator, a coordinator, or gated by a setting (#525,
    # #529). Imported events never generate check-in rows once P3
    # refuses them on `/attend` (the anon-checkin pair above), so there
    # is nothing for these two files to count or gate that could ever
    # be an imported event's own content.
    "_core/AnonymousCheckins.php": "every statement joins e.siteID = ? and returns counts, or one event name for a title (#525); shows no imported-event detail",
    "_core/AttendanceAccess.php": "a coordinator test joined on e.siteID (#529); decides who may see attendance totals, never shows an event",
    # 💀 Unreachable dead code (0.4 change S2, step 8). Its own address
    # was removed by migration 189, and its header explains why: a REAL
    # folder `public_html/widget/` answers first for the same address,
    # so the web server never lets a request reach this file at all
    # (the web-root shadowing trap — see CLAUDE.md). It holds two real,
    # unrestricted event queries with no marker. #514 deliberately does
    # NOT touch this file, so it must stay on this list — but it must
    # NEVER be "tidied up" to remove the address check that keeps it
    # unreachable, because that would make its two unrestricted queries
    # live again with no rule and no refusal.
    "_apps/calendar/widget.php": "UNREACHABLE — shadowed by the real public_html/widget/ folder since migration 189; its own header explains why. Two unrestricted event queries. Must stay unreachable. It has NO access check of its own: what keeps it unreachable is that no address points to it (migration 189 removed the address) plus the real public_html/widget/ folder. Do not re-add an address for it as a 'tidy-up'.",
}

# 📍 One specific line inside a file that is otherwise fully checked.
# `calendar/manage/save.php` handles BOTH the create-flow slug-uniqueness
# probe (which must search EVERY event, imported ones included — two
# events cannot share a slug regardless of where either one came from)
# and the real UPDATE that changes an event's own data (which #514 P3
# DOES restrict, at its own line, elsewhere in the same file). Allow-
# listing the whole file would hide that UPDATE from this check;
# allow-listing only this one line keeps the rest of the file checked.
#
# 🩹 FIX ROUND 1 (checker finding 8, and G7's fix for it). This used to be
# a plain `(file, line) -> reason` dict, keyed by line NUMBER alone. The
# checker pointed out the trap: an edit anywhere ABOVE this line shifts
# every line after it down, and the entry would then silently exempt
# WHATEVER new query happened to land on line 357 — which is the unsafe
# direction, because a real leak could slide into an already-exempted
# slot and never be reported. Proven while fixing checker finding 6
# (adding validation above this probe pushed it from line 357 to 393):
# with the entry left at 357, this script must say so loudly rather than
# silently keep exempting line 357 (now home to unrelated code) while
# staying blind to the probe's real, new location at 393.
#
# Each entry is now `(file, line) -> (expected text, reason)`. A
# candidate at that exact (file, line) is skipped ONLY when the line's
# own text still contains `expected text` — so a query that has moved
# away from this line is no longer silently exempted; it is reported
# like any other candidate, at its OWN (new) line, if it lacks a marker
# there. Separately, `line_allowlist_findings()` below re-reads every
# entry's file straight off disk after the scan and reports any entry
# whose named line no longer contains its expected text as a "stale
# LINE_ALLOWLIST entry" finding — so a line that quietly changed to
# something harmless (comment reworded, whitespace) does not silently
# stop being watched either; the entry itself must be moved to match.
LINE_ALLOWLIST: dict[tuple[str, int], tuple[str, str]] = {
    ("_apps/calendar/manage/save.php", 393): (
        "SELECT eventID FROM tblEvents WHERE eventSlug = ? AND siteID = ?",
        "the slug-uniqueness probe on event CREATE must search every event, imported ones included, so two events can never share a slug regardless of origin",
    ),
}


def strip_php_comments(text: str) -> str:
    """
    Strip `//` line, `#` line, and `/* … */` block PHP comments while
    preserving line numbers — the same approach
    check_account_writes_guarded.py and check_php_table_refs.py already
    use in this directory. A `#` immediately followed by `[` is left
    alone, because that is a PHP 8 attribute, not a comment.
    """
    text = re.sub(
        r"/\*.*?\*/",
        lambda m: "\n" * m.group(0).count("\n"),
        text,
        flags=re.DOTALL,
    )
    text = re.sub(r"//[^\n]*", "", text)
    text = re.sub(r"#(?!\[)[^\n]*", "", text)
    return text


def find_candidates(stripped_text: str) -> list[int]:
    """Return the 1-indexed line numbers of every candidate line."""
    lines = stripped_text.split("\n")
    return [i + 1 for i, line in enumerate(lines) if CANDIDATE_RE.search(line) is not None]


def has_marker_nearby(original_lines: list[str], candidate_line: int) -> bool:
    """
    True when a marker appears anywhere from 12 lines above `candidate_line`
    through 25 lines below it, in the file's ORIGINAL text (comments
    still in it — see the module docstring for why that is deliberate).
    `original_lines` is 0-indexed; `candidate_line` is 1-indexed.
    """
    start = max(0, candidate_line - 1 - 12)
    end = min(len(original_lines), candidate_line - 1 + 25 + 1)
    window = "\n".join(original_lines[start:end])
    return MARKER_RE.search(window) is not None


def scan(roots: list[Path], base_for_relpath: Path) -> list[str]:
    """
    Return findings as "relative/path.php:LINE" strings, sorted. `roots`
    is scanned recursively for *.php files; `base_for_relpath` is what
    each finding's path is shown relative to (and, in normal mode, what
    the two ALLOWLIST dicts above are keyed against).
    """
    findings: list[str] = []

    seen_files: set[Path] = set()
    for root in roots:
        if root.exists() is False:
            continue
        for php in sorted(root.rglob("*.php")):
            if php in seen_files:
                continue
            seen_files.add(php)

            try:
                raw = php.read_text(encoding="utf-8", errors="ignore")
            except OSError:
                continue

            try:
                rel = php.relative_to(base_for_relpath).as_posix()
            except ValueError:
                rel = php.as_posix()

            if rel in FILE_ALLOWLIST:
                continue

            stripped = strip_php_comments(raw)
            candidates = find_candidates(stripped)
            if not candidates:
                continue

            original_lines = raw.split("\n")
            for line_no in candidates:
                allowlist_entry = LINE_ALLOWLIST.get((rel, line_no))
                if allowlist_entry is not None:
                    expected_text, _reason = allowlist_entry
                    # Skip ONLY when the line's own text still matches what the
                    # entry was written for (see LINE_ALLOWLIST's own comment
                    # above for why a bare line-number match is not enough).
                    line_index = line_no - 1
                    if 0 <= line_index < len(original_lines) and expected_text in original_lines[line_index]:
                        continue
                if has_marker_nearby(original_lines, line_no) is True:
                    continue
                findings.append(f"{rel}:{line_no}")

    return sorted(findings)


def line_allowlist_findings(web_dir: Path) -> list[str]:
    """
    Normal-mode-only (never run against a --root scratch folder, whose
    files LINE_ALLOWLIST was never written to describe). For every entry
    in LINE_ALLOWLIST, read that file's OWN named line straight off
    disk — independently of whatever scan() found as a "candidate" — and
    confirm it still contains the expected text the entry was written
    for. Reports a "stale LINE_ALLOWLIST entry" finding for any entry
    whose file is missing, whose line number is out of range, or whose
    line text no longer matches: a maintainer must then move (or remove)
    the entry so it keeps pointing at the query it was written for,
    rather than silently exempting whatever now happens to occupy that
    line number (fix round 1, G7).
    """
    findings: list[str] = []
    for (rel, line_no), (expected_text, reason) in LINE_ALLOWLIST.items():
        php = web_dir / rel
        try:
            raw = php.read_text(encoding="utf-8", errors="ignore")
        except OSError:
            findings.append(f"{rel}:{line_no} (stale LINE_ALLOWLIST entry: file not found — {reason})")
            continue
        lines = raw.split("\n")
        line_index = line_no - 1
        if line_index < 0 or line_index >= len(lines) or expected_text not in lines[line_index]:
            findings.append(
                f"{rel}:{line_no} (stale LINE_ALLOWLIST entry: line no longer reads \"{expected_text}\" — {reason})"
            )
    return findings


def check() -> int:
    root_override = None
    for i, arg in enumerate(sys.argv):
        if arg == "--root" and i + 1 < len(sys.argv):
            root_override = Path(sys.argv[i + 1]).resolve()

    if root_override is not None:
        print(f"Scanning: {root_override} (--root override — single directory, recursive)")
        if root_override.exists() is False:
            print(f"ERROR: {root_override} does not exist.")
            return 1
        # The allow-lists are keyed against paths relative to `web/`. They
        # match files under a --root scratch folder only when that folder
        # copies web/'s layout (see the docstring). The staleness guard
        # below is skipped here: it was never written to describe files in
        # a throwaway scan target.
        findings = scan([root_override], root_override)
    else:
        web_dir = REPO_ROOT / "web"
        scan_roots = [web_dir / sub for sub in DEFAULT_SCAN_SUBDIRS]
        print(f"Scanning: {', '.join(str(r) for r in scan_roots)}")
        if web_dir.exists() is False:
            print(f"ERROR: {web_dir} does not exist.")
            return 1
        findings = scan(scan_roots, web_dir)
        # 🩹 FIX ROUND 1 (G7): normal-mode only — confirm every
        # LINE_ALLOWLIST entry still points at the query it was written
        # for, and add a finding for any that has drifted (see
        # line_allowlist_findings()'s own docstring for why).
        findings = sorted(findings + line_allowlist_findings(web_dir))

    print(f"Event queries with no EventVisibility:: and no externalFeedID IS [NOT] NULL nearby: {len(findings)}")
    print()

    if findings:
        print("### Lines that read tblEvents with no visibility rule or imported-event refusal nearby\n")
        for f in findings:
            print(f"  • {f}")
        print(
            "\nEither reach for Portal\\Core\\EventVisibility:: nearby (a call, or a comment recording "
            "one already ran a few lines above), or add \"AND externalFeedID IS NULL\" (or "
            "\"IS NOT NULL\", if this line specifically wants an imported row) to the statement itself. "
            "If this line genuinely needs neither — for example it never shows an event to a viewer at "
            "all — add it to FILE_ALLOWLIST or LINE_ALLOWLIST in this script, with a one-line reason, "
            "the same way the existing entries are documented.\n"
        )

    # Deliberately NOT gated behind --strict (see the module docstring).
    # --strict is still accepted so this script can be invoked the same
    # way as every other checker in this directory.
    #
    # 🩹 FIX ROUND 1 (checker finding 3): this used to also say "so it is
    # always blocking". That is true of THIS function's own return value
    # — a finding always makes `check()` return 1 — but it is NOT true of
    # what happens on a real pull request: `.github/workflows/
    # pr-security.yml` runs this script inside a step whose shell wrapper
    # ends `|| true`, so today a non-zero exit here is thrown away before
    # GitHub Actions ever sees it, and a finding only adds a section to
    # the PR comment. See the "Exit code" section of this file's module
    # docstring for the full correction.
    if findings:
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(check())
