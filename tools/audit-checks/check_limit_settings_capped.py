#!/usr/bin/env python3
r"""
A limit an organisation can set is capped by the installation (#668).

Path: tools/audit-checks/check_limit_settings_capped.py

WHY THIS EXISTS
---------------
One installation may serve many organisations, and whoever administers the
installation may be a DIFFERENT PERSON from the administrators of the
organisations on it -- a hosting provider and its customers. So for a setting
that is a LIMIT, the boundary between the two is a boundary between PARTIES: an
organisation may hold itself to a lower limit and may never raise its own above
what the installation allows (the owner's rule, 29 October 2026).

`$SETTINGS` has already merged the two layers by the time a page runs, so
nothing about the merged value reveals whether the rule was applied. The fault
is therefore completely silent: a save succeeds, a number is stored, and only
the installation's administrator -- who may never look -- would notice.

WHAT IT CHECKS, AND WHY IT IS SCOPED THE WAY IT IS
--------------------------------------------------
Not "every setting whose name contains max". That was the shape #668 warned
would cry wolf, and a check that cries wolf gets switched off. Instead it asks
the narrow question that matters:

    which files can create a PER-ORGANISATION settings row, and do any of them
    write a limit-shaped key without asking about the installation's value?

Measured on 30 October 2026 with this check's own functions: **seven** files
across `web/` put anything but a literal `NULL` in the siteID position -- five
under `web/_apps` and two under `web/_core` -- and of those, **four** name a
limit-shaped key. A list of that size is maintainable; a list of every `*max*`
setting is not.

An earlier version of this paragraph said "31 files". That is close to the
number of files containing ANY `INSERT INTO tblSettings` (32, counted), of
which all but these seven write `NULL`. It also said one file names a limit-shaped key, when the check lists
four, and that one directly, when the key in question is a CONSTANT. The
argument for the design was right and every number in it was wrong, which is
the worst way to be right.

IT RESOLVES CONSTANTS, AND THAT IS THE WHOLE REASON IT WORKS
------------------------------------------------------------
`attend.detailRetentionDays` -- the live breach #668 named -- is written as
`AnonymousCheckins::RETENTION_KEY`. It appears NOWHERE as a literal in the page
that writes it. Four shapes hide a key from a plain search, all measured while
#668's list was being judged:

    $SETTINGS['chat']['maxBodyChars']        a nested read
    LIKE 'mail.queue.%'                      a prefix query (MailQueue)
    AnonymousCheckins::RETENTION_KEY         a CONSTANT   <-- this one
    seeded, read nowhere by name             four settings on #668's list

So this check reads `public const X = '...';` out of every class under
`web/_core/` and substitutes. Without that it would have passed on the very
fault that prompted it -- which is exactly how it was tested.

WHAT COUNTS AS ASKING
---------------------
A file is satisfied when it mentions `SettingLimit::` -- the general resolver --
or `ownNumberCheck`, which is MailQueue's own three-tier test for the four
queue numbers (it reads the installation's row directly, deliberately, because
the queue reads these numbers with one query for many organisations and two
readers must not disagree about what the job will do).

WHAT THIS CHECK CANNOT SEE -- read this before trusting a pass
--------------------------------------------------------------
1. It does not know whether a setting IS a limit. It judges by NAME, and a name
   is a guess: `auth.password.maxLength` is a limit in name, and whether an
   organisation lowering it is sensible is a different question. A key it does
   not recognise by name is invisible to it.
2. It does not check the rule is applied CORRECTLY. A file that mentions
   `SettingLimit::` for one key and writes a second limit-shaped key with no
   cap at all passes. The same weakness as `check_install_wide_prefill.py`, and
   for the same reason: per-key accuracy needs a reader, not a pattern.
3. It cannot see a key read through a `LIKE` prefix query, nor a constant
   defined outside `web/_core/`, nor a key assembled through a variable or
   `sprintf()`. It CAN see one built by joining two literals and one inside a
   heredoc -- an earlier version of this list claimed otherwise, and planting
   both showed it catches them.
4. It says nothing about whether the refusal is worded well, recorded, or
   refuses rather than clamping. `tools/setting-limit-selftest.php` covers that
   for the general resolver and section F8 of `tools/mail-queue-selftest.php`
   for the queue's four numbers.
5. A global administrator can DELETE the installation's row through the general
   settings editor. The cap then falls back to the built-in default, and
   nothing on that page can put the row back, because its add branch always
   writes `siteID = Site::id()`. That is the safe direction -- the default is
   never higher than a sensible ceiling -- but it is worth knowing.

@package   Portal\Tools
@author    MWBM Partners Ltd (t/a MWservices)
@copyright 2026-present MWBM Partners Ltd (t/a MWservices)
@license   All Rights Reserved
@version   1.0.0
@link      https://github.com/MWBMPartners/WebMS-Intra/issues/668
"""

from __future__ import annotations

import re
import subprocess
import sys
import tempfile
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
APPS = REPO / "web" / "_apps"
CORE = REPO / "web" / "_core"

# 📂 Where a per-organisation settings write can live. BOTH, and the second one
#    is not theoretical: `GivingRetention::saveForSite()` and `Recordings` write
#    per-organisation rows from `web/_core`, and the first version of this check
#    read `_apps` only — so it could not see
#    `giving.retention.years` (1 to 50 years of a donor's name and home
#    address, set by an organisation's own administrator) at all. Found by the
#    independent check, by planting an uncapped writer in `_core` and watching
#    the check stay green.
SEARCHED = (APPS, CORE)

# 🚧 Files that write a limit-shaped key for ONE organisation and deliberately
#    do NOT cap it against the installation, each with the reason.
#
#    A file that DOES ask needs no line here — that is the ordinary case and it
#    passes on its own. This list is only for a key whose name looks like a
#    limit but which is really a CHOICE, where capping it would be wrong.
#    `auth.password.maxLength` is the shape to watch for: a limit in name, and
#    whether an organisation lowering it is sensible is a different question
#    entirely.
#
#    EMPTY TODAY, and that is the state to keep it in. Fails BOTH ways: a file
#    that writes such a key without asking and is not named here is a new
#    fault, and a name here that has started asking — or stopped writing such a
#    key — means the line is describing nothing and is believed anyway.
NOT_A_LIMIT = {
    "web/_core/GivingRetention.php":
        "#687 — `giving.retention.years` (1 to 50) is how long a donor's name and home address "
        "are kept, set per organisation by its own administrator, and it IS the shape #668 is "
        "about. It is NOT capped here because capping it is the DANGEROUS direction for this one "
        "setting: the figure is a statutory retention period, the owner chose a country per "
        "organisation deliberately (see the class header), and an installation forcing it DOWN "
        "could make a customer destroy records their own law requires them to keep. Whether a "
        "hosting provider may bound it at all is the owner's decision, which is why there is an "
        "issue rather than a cap. Listed so the judgement is on record instead of looking like an "
        "oversight.",
    "web/_apps/venues/settings.php":
        "#668 — `venues.invoice_due_lead_days`, `venues.renewal_lead_days` and "
        "`venues.unagreed_lead_days` are how many days BEFORE something an organisation wants its "
        "own reminder. That is a scheduling CHOICE, not a limit: a longer lead time costs the "
        "installation nothing, keeps no extra personal data, and an organisation choosing 30 days' "
        "notice over 14 affects nobody else. The limit-shaped `_days` ending is what brings them "
        "here.",
}

# 🔎 A word that makes a setting name look like a limit, matched against EVERY
#    segment of the dotted key and without regard to case.
#
#    THE FIRST VERSION MISSED FIVE REAL KEYS, every one found by planting:
#
#      mail.queue.keepFailedDays   the capital F stopped `[a-z0-9]*Days$`
#                                  matching — and this is the very key the
#                                  package names as the harmful one
#      giving.retention.years      the limit word is not in the LAST segment
#      transcription.monthlyMinutes  "Minutes" was not in the list
#      offboarding.undo_window_days  snake_case
#      venues.renewal_lead_days      snake_case
#
#    So: split on dots, lower-case each segment, and look for any of these
#    words anywhere inside it. That is wider than before and will report more;
#    the answer to a false alarm is a line in NOT_A_LIMIT with the reason, not
#    a narrower pattern that misses the thing the check exists for.
LIMIT_WORDS = {
    "max", "min", "limit", "limits", "retention", "quota", "cap", "ceiling",
    "days", "hours", "years", "weeks", "months", "minutes", "seconds",
    "bytes", "mb", "kb", "rows", "size", "window", "ttl", "attempts", "requests",
}


def words_of(segment: str) -> list[str]:
    """One key segment split into its words, lower-cased.

    Handles both house spellings: `keepFailedDays` and `undo_window_days`.
    """
    spaced = re.sub(r"(?<=[a-z0-9])(?=[A-Z])", " ", segment).replace("_", " ")

    return [w for w in spaced.lower().split() if w != ""]


def looks_like_a_limit(key: str) -> bool:
    """
    Does the LAST segment of this dotted key carry a limit word, as a WHOLE
    word?

    Both halves of that are load-bearing, and the first two versions got one
    each wrong.

    THE LAST SEGMENT, not any segment. Testing every segment made the
    NAMESPACE decide: `giving.retention.hold`, `.confirmed`, `.scope`,
    `.countryCode` and `.yearEnd` all matched on the middle segment
    "retention", so six settings that are not limits were reported alongside
    the one that is (`giving.retention.years`).

    A WHOLE WORD, not a substring. Substring matching found "min" inside
    `venues.reminder_roles` and "count" inside `org.address.countryCode` — two
    more false alarms on a check whose whole issue says that crying wolf gets
    it switched off.

    Together they are precise on every key this package has judged, and the
    cost is stated plainly: a limit whose last segment uses a word not on the
    list is invisible. That is a list to extend when one turns up, not a
    pattern to loosen.
    """
    return any(word in LIMIT_WORDS for word in words_of(key.split(".")[-1]))


# 🏢 An INSERT INTO tblSettings, with its column list and its values list, so
#    the value in the siteID POSITION can be looked at.
#
#    WHY POSITION AND NOT "DOES IT CONTAIN A ?". The first version asked only
#    whether the VALUES list held a placeholder, which is true of every such
#    INSERT -- `VALUES (?, ?, ?, NULL, NOW())` contains three. It therefore
#    reported `integrations/push/save.php` and
#    `integrations/cloudflare-stream/save.php` as per-organisation writers when
#    both write `siteID NULL` only and are global-administrator-only, so
#    neither can create an organisation's row at all. Two false accusations out
#    of four findings -- and #668 says in so many words that a check which
#    cries wolf gets switched off, which would have left the real fault
#    unguarded.
#
#    It does NOT match MySQL's `INSERT INTO t SET a = ?, b = ?` form. There is
#    none in this repository today; one added later would be invisible here.
PER_ORG_INSERT = re.compile(
    r"INSERT\s+INTO\s+`?tblSettings`?\s*\(([^)]*)\)\s*VALUES\s*\((.*?)\)\s*(?:ON\s+DUPLICATE|;|$)",
    re.IGNORECASE | re.DOTALL)

# 🤝 What counts as asking what the installation allows. `SettingLimit::` is the
#    general resolver; `installationAllows` also catches
#    `EventRecurrence::installationAllows()`, the first reader built this way,
#    which the first version of this check did not recognise and so reported as
#    a fault; `ownNumberCheck` is MailQueue's own three-tier test for the four
#    queue numbers, which reads the installation's row directly on purpose.
#
#    BE HONEST ABOUT `ownNumberCheck`: it is here for completeness and cannot
#    currently fire. `_apps/settings/save.php` IS a per-organisation writer but
#    names no limit-shaped key of its own, so it never reaches this list, and
#    taking the cap out of MailQueue would leave this check green. The mail
#    queue's half of #668 is guarded by section F8 of
#    `tools/mail-queue-selftest.php`, not by this file.
ASKS_THE_RULE = ("SettingLimit::", "installationAllows", "ownNumberCheck")

PHP_STRIP = r"""<?php
$code = '';
foreach (token_get_all(file_get_contents($argv[1])) as $t) {
    if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
    $code .= is_array($t) ? $t[1] : $t;
}
echo $code;
"""


def without_comments(path: Path, helper: Path) -> str:
    """The file's code with comments removed, using PHP's own tokenizer.

    Not a regular expression: an apostrophe in a comment puts every later quote
    out of step, a mistake `check_page_head_tags.py` had to have corrected
    twice.
    """
    done = subprocess.run(["php", str(helper), str(path)], capture_output=True, text=True)
    return done.stdout if done.returncode == 0 else path.read_text(encoding="utf-8", errors="replace")


def joined_literals(code: str) -> str:
    """
    PHP code with adjacent string literals joined, so SQL built in pieces reads
    as one statement.

    THIS IS LOAD-BEARING, and leaving it out made the check find NOTHING. The
    house style builds a long statement as

        'INSERT INTO tblSettings (siteID, settingKey, settingValue) '
        . 'VALUES (?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE ...'

    so `INSERT ... VALUES` never appears in one literal and a pattern spanning
    the two matches nothing. `admin/settings/attendance/save.php` -- one of the
    files this check exists to account for -- is written exactly that way, so
    the first version reported zero per-organisation writers and then called
    its own exception list stale.

    It joins only the `' . '` form between two quotes. A statement assembled
    through a variable or `sprintf()` is still invisible, which is in the
    limitations at the top.
    """
    return re.sub(r"'\s*\.\s*'", "", re.sub(r'"\s*\.\s*"', "", code))


def splits(values: str) -> list[str]:
    """
    One VALUES list split on its top-level commas.

    Depth-aware, so `COALESCE(a, b)` and `NOW()` stay in one piece. It is not an
    SQL parser: a comma inside a quoted string would split wrongly, which would
    misalign the columns. No INSERT into tblSettings in this repository has one,
    and a misalignment errs towards reporting a file rather than excusing it.
    """
    out: list[str] = []
    depth = 0
    current = ""
    for ch in values:
        if ch == "(":
            depth += 1
        elif ch == ")":
            depth -= 1
        if ch == "," and depth == 0:
            out.append(current.strip())
            current = ""
            continue
        current += ch
    out.append(current.strip())

    return out


def writes_for_one_organisation(code: str) -> bool:
    """
    Can this file create a settings row belonging to ONE organisation?

    True when some `INSERT INTO tblSettings` puts anything other than the
    literal NULL in the siteID position -- a bound parameter, `Site::id()`, a
    number. A file whose every such INSERT writes NULL is installation-wide
    only and cannot cross the boundary this check is about.
    """
    for match in PER_ORG_INSERT.finditer(code):
        columns = [c.strip().strip("`").lower() for c in splits(match.group(1))]
        values = splits(match.group(2))
        if "siteid" not in columns:
            continue
        at = columns.index("siteid")
        if at >= len(values):
            continue                      # malformed; say nothing about it
        if values[at].strip().upper() != "NULL":
            return True

    return False


def constants() -> dict[str, str]:
    """
    Every `public const NAME = 'a.dotted.key';` under `web/_core/`, as
    `Class::NAME` -> the key.

    This is what lets the check see `AnonymousCheckins::RETENTION_KEY`, which is
    how the live breach of #668 was written and why a literal-only search would
    have passed on it.
    """
    out: dict[str, str] = {}
    for php in sorted(CORE.rglob("*.php")):
        text = php.read_text(encoding="utf-8", errors="replace")
        klass = php.stem
        for name, value in re.findall(
                r"public\s+const\s+([A-Z][A-Z0-9_]*)\s*=\s*'([a-z][a-zA-Z0-9_]*(?:\.[a-zA-Z0-9_]+)+)'",
                text):
            out["%s::%s" % (klass, name)] = value

    return out


#    Ways a settings key is READ rather than written. A key that appears ONLY
#    inside one of these is not this check's business.
READERS = ("App::settings(", "$SETTINGS[", "InstallationSettings::layer(", "SettingLimit::", "settings(")


def mentioned_outside_a_read(code: str, needle: str) -> bool:
    """
    Does `$needle` appear somewhere that is not simply a READ of the setting?

    WHY THIS EXISTS. The first version counted any limit-shaped literal
    anywhere in a per-organisation writer, so a page that writes something
    harmless per organisation and merely DISPLAYS a limit tripped the gate.
    Measured by planting exactly that: a page writing `org.address.city` and
    reading `App::settings('noticeboard.upload.maxBytes')` — the documented way
    to read it — failed the check. Any settings page that shows a limit it does
    not write would have done the same, and #668 says a check that cries wolf
    gets switched off.

    It is a heuristic, not a parser: the key has to appear on a line (or in a
    call) that is not one of the known reading shapes. A write assembled far
    from the key's name is still invisible, which is in the limitations above.
    """
    at = 0
    while True:
        at = code.find(needle, at)
        if at == -1:
            return False
        before = code[max(0, at - 40):at]
        if any(reader in before for reader in READERS) is False:
            return True
        at += len(needle)


def limit_keys_written(code: str, consts: dict[str, str]) -> set[str]:
    """Limit-shaped setting keys this file names, literals and constants both."""
    found: set[str] = set()
    for key in re.findall(r"'([a-z][a-zA-Z0-9_]*(?:\.[a-zA-Z0-9_]+)+)'", code):
        if looks_like_a_limit(key) is True and mentioned_outside_a_read(code, "'" + key + "'") is True:
            found.add(key)
    for ref, key in consts.items():
        if ref in code and looks_like_a_limit(key) is True and mentioned_outside_a_read(code, ref) is True:
            found.add(key)

    return found


def main(quiet: bool = False) -> int:
    for root in SEARCHED:
        if root.is_dir() is False:
            print("✗ %s is missing, so there is nothing to check." % root, file=sys.stderr)
            return 1
    if subprocess.run(["php", "-v"], capture_output=True).returncode != 0:
        print("✗ php is not runnable, and this check strips comments with PHP's own "
              "tokenizer rather than a regular expression. It cannot run without it.",
              file=sys.stderr)
        return 1

    consts = constants()
    if not consts:
        print("✗ No `public const` setting keys were found under %s. This check resolves "
              "constants on purpose -- the live fault it exists for is written as one -- so "
              "finding none means it is looking in the wrong place, not that there are none."
              % CORE.relative_to(REPO), file=sys.stderr)
        return 1

    with tempfile.TemporaryDirectory(prefix="wmi-limits-") as scratch:
        helper = Path(scratch) / "_strip_php_comments.php"
        helper.write_text(PHP_STRIP, encoding="utf-8")

        writers: dict[str, set[str]] = {}
        asked_now: dict[str, set[str]] = {}
        unasked_now: dict[str, set[str]] = {}
        every = sorted({p for root in SEARCHED for p in root.rglob("*.php")})
        for php in every:
            code = joined_literals(without_comments(php, helper))
            if writes_for_one_organisation(code) is False:
                continue
            keys = limit_keys_written(code, consts)
            if not keys:
                continue
            rel = php.relative_to(REPO).as_posix()
            writers[rel] = keys
            # Decided here, from the code already in hand. An earlier version
            # had an if/else whose branches did the same thing and then read
            # and stripped every file a SECOND time to answer the same
            # question.
            (asked_now if any(token in code for token in ASKS_THE_RULE) else unasked_now)[rel] = keys

        asked = asked_now
        unasked = unasked_now

    if "--list" in sys.argv:
        print("Files that can write a limit-shaped key for ONE organisation: %d"
              % (len(asked) + len(unasked)))
        for rel, keys in sorted(asked.items()):
            print("   asks the rule     %-50s %s" % (rel, ", ".join(sorted(keys))))
        for rel, keys in sorted(unasked.items()):
            print("   DOES NOT ask      %-50s %s" % (rel, ", ".join(sorted(keys))))
        print("\n`public const` dotted setting keys found under web/_core: %d" % len(consts))
        print("(that is every one, not the number that turned out to be a limit)")
        return 0

    problems: list[str] = []

    new = sorted(set(unasked) - set(NOT_A_LIMIT))
    if new:
        problems.append(
            "✗ %d file(s) write a limit-shaped setting for ONE organisation without asking what "
            "the installation allows:\n%s\n   For a LIMIT the installation's value takes "
            "precedence: an organisation may hold itself to less and may never raise its own "
            "above it (the owner's rule, 29 October 2026, #668). Ask "
            "`SettingLimit::whyNotAllowed()` and REFUSE with the reason -- never clamp quietly -- "
            "and record the refusal, because it crosses a line between two parties who may be two "
            "different people. If the setting is a CHOICE rather than a limit, say so in this "
            "check's NOT_A_LIMIT list with the reason."
            % (len(new), "\n".join("      %s\n         writes: %s" % (r, ", ".join(sorted(unasked[r])))
                                   for r in new)))

    # A listed file that has started asking the rule is just as stale as one
    # that no longer writes such a key: either way the line excuses nothing.
    stale = sorted(set(NOT_A_LIMIT) - set(unasked))
    if stale:
        problems.append(
            "✗ %d entry(ies) in this check's own list are no longer true — the file either asks "
            "the rule now or no longer writes a limit-shaped setting for one organisation:\n%s\n"
            "   Delete each line. A named exception that has stopped describing anything is worse "
            "than none, because it is believed."
            % (len(stale), "\n".join("      %s  —  listed as: %s" % (s, NOT_A_LIMIT[s])
                                     for s in stale)))

    if problems:
        print("\n\n".join(problems), file=sys.stderr)
        print("\n%d problem(s). The rule is #668, and the boundary it protects is between the "
              "installation's administrator and an organisation's." % len(problems), file=sys.stderr)
        return 1

    if quiet is False:
        print("✓ Every file that can set a limit for one organisation asks what the installation "
              "allows: %d such file(s), %d setting key(s) resolved from constants, %d named as not "
              "really a limit." % (len(asked) + len(unasked), len(consts), len(NOT_A_LIMIT)))

    return 0


if __name__ == "__main__":
    sys.exit(main())
