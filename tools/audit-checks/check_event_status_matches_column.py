#!/usr/bin/env python3
r"""
-------------------------------------------------------------------------------
 File:        tools/audit-checks/check_event_status_matches_column.py
 Description: Refuses any list of permitted event statuses, or any status
              written straight into SQL, that does not match the words
              `tblEvents.status` actually accepts.
 Package:     Portal\Tools\AuditChecks
 Author:      MWBM Partners Ltd (t/a MWservices)
 Copyright:   2026-present MWBM Partners Ltd (t/a MWservices) - All Rights Reserved
 Version:     1.0.0
 Link:        https://github.com/MWBMPartners/WebMS-Intra/issues/676
-------------------------------------------------------------------------------

WHY THIS EXISTS
    `tblEvents.status` is `ENUM('draft','published','cancelled','postponed')`.
    Three places that write an event allowed `archived` -- a word the column has
    NEVER had -- and left out `postponed`, which it has. Nothing compared the
    two, so the drift sat there until somebody read both.

    What it cost depended on the server, and both halves were measured on
    MySQL 8.0.36:

      * Since #593 (12 October 2026) every connection is STRICT, so the write
        failed with "ERROR 1265 Data truncated for column 'status'" -- a 500.
      * BEFORE #593 it was worse. MySQL stored the EMPTY STRING, which an ENUM
        keeps in its "invalid" slot, so NO STATUS FILTER can find it.
        Migration 230 gives those back as drafts.

        Be precise here: an earlier draft of this said the event "disappeared
        from every list, feed and count", which is NOT true and was caught by
        the independent check. `calendar/manage/index.php` filters by status
        only when a status is chosen, and its badge has a `default` arm, so the
        event is listed, counted and editable there with a blank badge. 86 of
        the 130 places that read `tblEvents` never mention status at all.

    And `postponed` could not be set through the API or the importer, while the
    API cheerfully returned it in `Event.status`. (It has always been settable
    from the ordinary admin event form and the series bulk editor -- "through
    any route" was an earlier draft's wording and was wrong.)

WHAT IT CHECKS
    Two halves, because the fault can take two shapes:

    A. **Allow-lists.** Any `in_array($x, ['draft', ...], ...)` whose array
       holds both `draft` and `published` is taken to be a list of event
       statuses, and must equal the column exactly -- no extra word, none
       missing.

    B. **Statuses written into SQL.** Any `status = 'word'` or `status =
       "word"` inside a string literal in a file that mentions `tblEvents`.
       That is where the next one of these will live: `moderate.php`'s header
       claimed "Reject = status='archived'" for weeks while the code wrote
       'draft'. Had the code matched its own comment, every rejection would
       have been a 500.

    It also checks the API description, so `api-spec.json` cannot drift from
    the column either.

HOW IT READS THE CODE, AND WHY NOT BY SEARCHING TEXT
    Through PHP's own tokeniser, with comments and doc comments stripped.
    That is not a nicety here: the fix for #676 left comments in all three
    files that MENTION `archived` to explain why it is gone. A text search
    reports every one of them as the fault it is describing.

    It also refuses to pass when it cannot run. A missing `php`, a tokeniser
    that answers nothing, a schema file it cannot parse, or a scan that finds
    no allow-lists at all is a FAILURE, not a pass -- the #646 lesson, where
    twelve guards looked wired and ran nowhere.

WHAT IT CANNOT SEE
    * A status chosen at run time: `$s = $ok ? 'draft' : $other;` then written
      through a bound parameter. Nothing in this repository does that for this
      column, and a check for it would have to interpret the code.
    * A list built by concatenation or read from a setting.
    * A status written as a LITERAL inside an `INSERT`'s `VALUES` list, e.g.
      `INSERT INTO tblEvents (status) VALUES ('archived')`. Half B matches the
      `status = 'word'` shape, which is where the real fault lived -- the
      `moderate.php` header claimed `status='archived'` for weeks. Checked
      rather than assumed: all four `INSERT INTO tblEvents` statements in this
      repository name `status` in their column list and BIND it with `?`, so
      the shape does not occur. If one ever supplies a literal there, this
      check will not see it.
    * An allow-list that holds neither `draft` nor `published`, since that is
      how it recognises one at all. A list of only `cancelled` and `postponed`
      would not be examined.
    * Whether a status MEANS the right thing -- only whether the word exists.

    Run `--selftest` to prove both halves still catch what they are for.
"""

import json
import pathlib
import re
import subprocess
import sys
import tempfile

REPO = pathlib.Path(__file__).resolve().parents[2]
SCHEMA = REPO / "web" / "_sql" / "full_schema.sql"
APPS = REPO / "web" / "_apps"
SPEC = REPO / "web" / "_core" / "api-spec.json"

# The table and column this check is about.
TABLE = "tblEvents"
COLUMN = "status"

# An allow-list is recognised by holding BOTH of these. See "WHAT IT CANNOT SEE".
MARKERS = {"draft", "published"}

# Strip comments and doc comments, keep string literals (half B needs them).
PHP_STRIP = r"""
$code = '';
foreach (token_get_all(file_get_contents($argv[1])) as $t) {
    if (is_array($t) === true) {
        if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { continue; }
        $code .= $t[1];
    } else {
        $code .= $t;
    }
}
echo $code;
"""


def php_without_comments(path: pathlib.Path) -> str:
    """Return the file's code with comments removed, via PHP's own tokeniser."""
    try:
        proc = subprocess.run(["php", "-r", PHP_STRIP, "--", str(path)],
                              capture_output=True, text=True, timeout=60)
    except FileNotFoundError:
        print("FAIL: php is not on PATH, so this check could not run at all.")
        print("      A check that cannot run must never report success (#646).")
        sys.exit(2)
    if proc.returncode != 0:
        print("FAIL: the tokeniser could not read %s" % path)
        if proc.stderr.strip() != "":
            print("      php said: " + proc.stderr.strip().splitlines()[0])
        sys.exit(2)
    return proc.stdout


def column_values(schema_text: str):
    """The words `tblEvents.status` accepts, read from the install script."""
    # ⚠️ Anchored at the START OF A LINE, and the table name must follow
    #    immediately. An earlier draft allowed `[^;]*?` between "CREATE TABLE"
    #    and the name, which let it skip forward and match a
    #    `REFERENCES `tblEvents` (` inside ANOTHER table's foreign key -- so it
    #    read the wrong table's body, found no ENUM, and failed with "could not
    #    read the column" on a schema that was perfectly fine.
    create = re.search(
        r"^CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?%s`?\s*\((.*?)\n\)\s*ENGINE"
        % re.escape(TABLE),
        schema_text, re.S | re.IGNORECASE | re.MULTILINE)
    if create is None:
        return None
    col = re.search(r"`%s`\s+ENUM\s*\(([^)]*)\)" % re.escape(COLUMN),
                    create.group(1), re.IGNORECASE)
    if col is None:
        return None
    return [v.strip().strip("'\"") for v in col.group(1).split(",")]


# TWO shapes, not one. A list may be written INLINE inside the call --
# `in_array($s, ['draft', ...], true)` -- or assigned to a variable first and
# passed by name: `$validStatuses = ['draft', ...];` then
# `in_array($v, $validStatuses, true)`. The first version of this check matched
# only the inline shape, so `events/api/update.php` -- ONE OF THE THREE FILES
# #676 IS ABOUT -- was never examined, and putting `archived` back into it left
# the check GREEN while it reported "3 allow-list(s)" checked. False assurance
# is the worst failure a check can have; planting the fault is what found it.
ALLOW_LISTS = [
    re.compile(r"in_array\s*\([^,]+,\s*\[([^\]]*)\]", re.S),      # inline
    re.compile(r"\$[A-Za-z_]\w*\s*=\s*\[([^\]]*)\]", re.S),        # assigned
]

# A statement that writes the events table. Everything this check looks at is
# scoped to a file holding one of these.
WRITES_EVENTS = re.compile(r"(?:INSERT\s+INTO|UPDATE)\s+`?%s`?" % re.escape(TABLE),
                           re.IGNORECASE)

# A status written as a literal rather than bound. The backslash forms matter
# because the SQL is usually inside a PHP string: 'SET status = \"draft\"'.
SQL_STATUS = re.compile(r"""%s\s*=\s*\\?["']([a-z_]+)\\?["']""" % re.escape(COLUMN))

# How far after an `UPDATE tblEvents` to look for a literal status. The SQL is
# built by concatenation across several lines, so this cannot be one line; but
# it must not be the whole file either, or an unrelated status belonging to
# another table is reported. 400 characters covers every house statement.
SQL_WINDOW = 400


# An INSERT's column list and VALUES list can be far apart: the SQL is built by
# concatenating PHP strings over several lines. 1500 characters covers every
# house statement; half B's narrower window stays as it is, because a wide
# window there would reach into another table's statement.
INSERT_WINDOW = 1500

PHP_STRING = re.compile(r"'([^'\\]*(?:\\.[^'\\]*)*)'|\"([^\"\\]*(?:\\.[^\"\\]*)*)\"")
INSERT_SHAPE = re.compile(
    r"INSERT\s+INTO\s+`?%s`?\s*\(" % re.escape(TABLE), re.IGNORECASE)


def _balanced(text, start):
    r"""The contents of the bracket that opens at `start`, and the index after it.

    Needed because a VALUES list may hold `NOW()`. An earlier draft used
    `\(([^)]*)\)`, which stopped inside `NOW(` -- so the column list and the
    values list came out different lengths, the pairing was abandoned, and
    `submit-save.php`'s literal `"draft"` was never examined. The check looked
    like it covered the shape and did not.
    """
    depth = 0
    for k in range(start, len(text)):
        if text[k] == "(":
            depth += 1
        elif text[k] == ")":
            depth -= 1
            if depth == 0:
                return text[start + 1:k], k + 1
    return None, None


def _split_top(text):
    """Split on commas that are not inside brackets."""
    out, depth, cur = [], 0, ""
    for ch in text:
        if ch == "(":
            depth += 1
        elif ch == ")":
            depth -= 1
        if ch == "," and depth == 0:
            out.append(cur)
            cur = ""
        else:
            cur += ch
    out.append(cur)
    return out


def insert_status_literals(code):
    r"""Every literal supplied for `status` in an `INSERT INTO tblEvents`.

    Rebuilds the SQL by joining the file's PHP string literals IN ORDER,
    because the statement is written as
    `'INSERT INTO tblEvents ' . '(a, b, status) ' . 'VALUES (?, ?, "draft")'`.
    A `"draft"` inside a single-quoted PHP string survives that join as part of
    the SQL text, which is what makes the pairing possible at all.

    Only a POSITIONAL pair is reported. If the two lists are different lengths,
    or `status` is not named, nothing is claimed -- a guess here would cry wolf,
    and this check has already done that once.
    """
    parts = [(a or b) for a, b in PHP_STRING.findall(code)]
    if not parts:
        return []
    sql = " ".join(parts)
    out = []
    for shape in INSERT_SHAPE.finditer(sql):
        cols_text, after = _balanced(sql, shape.end() - 1)
        if cols_text is None:
            continue
        vals_at = re.search(r"VALUES\s*\(", sql[after:], re.IGNORECASE)
        if vals_at is None:
            continue
        vals_text, _ = _balanced(sql[after:], vals_at.end() - 1)
        if vals_text is None:
            continue
        cols = [c.strip().strip("`") for c in _split_top(cols_text)]
        vals = [v.strip() for v in _split_top(vals_text)]
        if len(cols) != len(vals) or COLUMN not in cols:
            continue
        lit = re.fullmatch(r"""["']([a-z_]+)["']""", vals[cols.index(COLUMN)])
        if lit is None:
            continue               # a bound `?`, an expression, a number
        out.append((lit.group(1), "in its VALUES list"))
    return out


def scan_php(allowed, apps=None):
    """Returns (problems, lists_found, sql_found).

    ⚠️ EVERYTHING HERE IS SCOPED TO A FILE THAT WRITES `tblEvents`, and that is
       load-bearing rather than tidy. The first version checked every file
       under `web/_apps` and reported FOURTEEN faults, all of them false:
       `status = 'confirmed'` is an RSVP's status, `'pending_review'` belongs to
       an erasure request, `'processing'` to the retention sweep, and
       `service-plans/save.php`'s allow-list of draft/published/archived is the
       SERVICE PLAN's status, whose column really is
       `ENUM('draft','published','archived')`. "The file mentions tblEvents" is
       not "this writes tblEvents.status" -- several of those files merely JOIN
       to events. A check that cries wolf gets switched off, and then it catches
       nothing.
    """
    problems, lists_found, sql_found = [], 0, 0
    allowed_set = set(allowed)

    root = APPS if apps is None else apps
    for php in sorted(root.rglob("*.php")):
        code = php_without_comments(php)
        if WRITES_EVENTS.search(code) is None:
            continue
        try:
            rel = php.relative_to(REPO)
        except ValueError:
            rel = php.name          # the selftest scans a temporary folder

        # --- A. allow-lists in a file that writes an event ------------------
        seen_here = set()
        for m in [m for pat in ALLOW_LISTS for m in pat.finditer(code)]:
            words = [w.strip().strip("'\"") for w in m.group(1).split(",") if w.strip() != ""]
            words = [w for w in words if re.fullmatch(r"[a-z_]+", w)]
            # Recognise an event-status list by its holding BOTH markers. This
            # was once written `MARKERS <= set(words) is False or ...`, which
            # Python reads as a CHAINED comparison -- never true -- so the
            # guard did nothing at all.
            if MARKERS - set(words):
                continue
            # One list can match both patterns; count and report it once.
            fingerprint = tuple(words)
            if fingerprint in seen_here:
                continue
            seen_here.add(fingerprint)
            lists_found += 1
            extra = [w for w in words if w not in allowed_set]
            missing = [w for w in allowed if w not in words]
            if extra or missing:
                bits = []
                if extra:
                    bits.append("offers " + ", ".join(repr(w) for w in extra)
                                + " which the column REFUSES")
                if missing:
                    bits.append("omits " + ", ".join(repr(w) for w in missing)
                                + " which the column ACCEPTS")
                problems.append("%s: an event-status allow-list %s"
                                % (rel, "; and ".join(bits)))

        # --- B. a status written straight into an events statement ----------
        for w in WRITES_EVENTS.finditer(code):
            window = code[w.end():w.end() + SQL_WINDOW]
            for m in SQL_STATUS.finditer(window):
                word = m.group(1)
                sql_found += 1
                if word not in allowed_set:
                    problems.append("%s: SQL writes %s = %r into %s, which the column REFUSES"
                                    % (rel, COLUMN, word, TABLE))

        # --- B2. a literal in an INSERT's VALUES list -----------------------
        # `INSERT INTO tblEvents (… status …) VALUES (… "draft" …)` has no
        # `status = 'x'` to match, so half B cannot see it. An earlier draft of
        # this check claimed the shape "does not occur"; that was wrong --
        # `calendar/submit-save.php` uses it, and planting `"archived"` there
        # went UNCAUGHT while the comment said it could not happen.
        #
        # ⚠️ This reads the string literals of the WHOLE FILE, not a window
        #    after the match. A window STARTS INSIDE the PHP string holding
        #    `INSERT INTO tblEvents `, so its opening quote falls outside it and
        #    every later quote pairs one off -- the SQL text came out as
        #    fragments of whitespace and the shape never matched. That is the
        #    same quote-pairing trap check_page_head_tags.py hit twice.
        for word, where in insert_status_literals(code):
            sql_found += 1
            if word not in allowed_set:
                problems.append("%s: an INSERT into %s supplies %s = %r %s, "
                                "which the column REFUSES"
                                % (rel, TABLE, COLUMN, word, where))

    # Two `UPDATE tblEvents` matches can have overlapping windows, so one
    # literal is found twice. Report each distinct fault once, keeping order.
    deduped = list(dict.fromkeys(problems))
    return deduped, lists_found, sql_found


def scan_spec(allowed, spec=None):
    """The API description's event-status enums must match the column too."""
    problems, found = [], 0
    spec_path = SPEC if spec is None else spec
    if spec_path.is_file() is False:
        return ["api-spec.json is missing"], 0
    doc = json.loads(spec_path.read_text())

    def walk(node, trail):
        nonlocal found
        if isinstance(node, dict):
            enum = node.get("enum")
            if isinstance(enum, list) and MARKERS <= set(enum):
                found += 1
                extra = [w for w in enum if w not in allowed]
                missing = [w for w in allowed if w not in enum]
                # A create/write enum may legitimately omit nothing; a read
                # enum must list every stored value. Both must offer nothing
                # the column refuses.
                if extra:
                    problems.append("api-spec.json %s: offers %s which the column REFUSES"
                                    % ("/".join(trail), ", ".join(repr(w) for w in extra)))
                if missing:
                    problems.append("api-spec.json %s: omits %s which the column ACCEPTS"
                                    % ("/".join(trail), ", ".join(repr(w) for w in missing)))
            for k, v in node.items():
                walk(v, trail + [str(k)])
        elif isinstance(node, list):
            for i, v in enumerate(node):
                walk(v, trail + [str(i)])

    walk(doc, [])
    return problems, found


def main(schema=None, apps=None, spec=None, quiet: bool = False) -> int:
    """The real verdict. Everything is a parameter so the selftest can drive
    THIS function rather than only `scan_php()`.

    That is not tidiness. The first version's `--selftest` called `scan_php()`
    alone, and an independent check found EIGHT surviving mutants because of
    it: the verdict line itself, both vacuity guards, the line that folds the
    description's faults into the result, and every branch of `scan_spec()`
    could each be neutered with every case still green. The untested half is
    always the half one edit can remove -- the identical hole was found in
    check_no_curl_close.py days earlier and the lesson was not carried over.
    """
    say = (lambda *a: None) if quiet is True else print
    schema_path = SCHEMA if schema is None else schema

    if schema_path.is_file() is False:
        say("FAIL: %s is missing, so the column could not be read." % schema_path)
        return 2
    allowed = column_values(schema_path.read_text())
    if allowed is None or len(allowed) == 0:
        say("FAIL: could not read %s.%s's ENUM from the install script." % (TABLE, COLUMN))
        say("      Without it this check has nothing to compare against.")
        return 2

    problems, lists_found, sql_found = scan_php(allowed, apps)
    spec_problems, spec_found = scan_spec(allowed, spec)
    problems += spec_problems

    # Not vacuous: finding no allow-list at all means the pattern has stopped
    # matching, not that the code is clean.
    if lists_found == 0:
        say("FAIL: no event-status allow-list was found anywhere under web/_apps.")
        say("      This check recognises one by its holding both 'draft' and")
        say("      'published'; if that stopped being true it is proving nothing.")
        return 2
    if spec_found == 0:
        say("FAIL: no event-status enum was found in api-spec.json, so that half")
        say("      of this check proved nothing.")
        return 2

    if len(problems) == 0:
        say("✓ every event-status list matches the column (%s)" % ", ".join(allowed))
        say("  checked: %d allow-list(s), %d status(es) written into SQL, %d spec enum(s)"
            % (lists_found, sql_found, spec_found))
        return 0

    say("✗ %d event-status problem(s) — the column accepts only: %s"
        % (len(problems), ", ".join(allowed)))
    for pr in problems:
        say("    %s" % pr)
    say("")
    say("  A word the column refuses reaches the database and fails: strict since")
    say("  #593 that is a 500, and before it the column stored the EMPTY STRING, which")
    say("  leaves a blank status badge that no status filter can find. Change the list,")
    say("  or change the column in BOTH full_schema.sql and a numbered migration (#676).")
    return 1


def selftest() -> int:
    """Prove both halves catch what they exist for, and cry wolf at nothing."""
    allowed = ["draft", "published", "cancelled", "postponed"]
    # ⚠️ Every in-scope fixture MUST contain a write to tblEvents, because the
    #    scan is scoped to files that write it. When that scoping was added,
    #    two fault cases silently started PASSING -- they were out of scope,
    #    not clean. And the "correct allow-list" case had been passing for the
    #    wrong reason all along. Each case below says which half it exercises.
    W = "$q = 'UPDATE tblEvents SET eventName = ? WHERE eventID = ?';\n"
    cases = [
        # --- half A: allow-lists, in a file that writes an event ------------
        ("A: a correct allow-list",
         "<?php " + W + "if (in_array($s, ['draft', 'published', 'cancelled', 'postponed'], true)) {}", False),
        ("A: one offering 'archived'",
         "<?php " + W + "if (in_array($s, ['draft', 'published', 'cancelled', 'archived'], true)) {}", True),
        ("A: one omitting 'postponed'",
         "<?php " + W + "if (in_array($s, ['draft', 'published', 'cancelled'], true)) {}", True),
        # --- comments must never be read as code ----------------------------
        ("A: a COMMENT mentioning archived",
         "<?php " + W + "// archived is gone, see #676\nif (in_array($s, ['draft', 'published', 'cancelled', 'postponed'], true)) {}", False),
        # ⚠️ The ASSIGNED shape, which the first version of this check could
        #    not see at all -- and it is the shape events/api/update.php uses.
        ("A: an ASSIGNED list, correct",
         "<?php " + W + "$valid = ['draft', 'published', 'cancelled', 'postponed'];\nif (in_array($s, $valid, true)) {}", False),
        ("A: an ASSIGNED list offering 'archived'",
         "<?php " + W + "$valid = ['draft', 'published', 'cancelled', 'archived'];\nif (in_array($s, $valid, true)) {}", True),
        ("A: an ASSIGNED list omitting 'postponed'",
         "<?php " + W + "$valid = ['draft', 'published', 'cancelled'];\nif (in_array($s, $valid, true)) {}", True),
        ("A: a doc block mentioning it",
         "<?php " + W + "/** was draft|published|cancelled|archived */\nif (in_array($s, ['draft', 'published', 'cancelled', 'postponed'], true)) {}", False),
        # --- half B: a status written straight into the statement -----------
        ("B: SQL writing a bad status",
         "<?php $q = 'UPDATE tblEvents SET status = \"archived\" WHERE eventID = ?';", True),
        ("B: SQL writing a good status",
         "<?php $q = 'UPDATE tblEvents SET status = \"draft\" WHERE eventID = ?';", False),
        ("B: concatenated, as moderate.php",
         "<?php $q = 'UPDATE tblEvents SET status = \"archived\", isPublic = 0, '\n  . ' submissionStatus = \"rejected\" WHERE eventID = ?';", True),
        ("B: a bound status is not a literal",
         "<?php $q = 'INSERT INTO tblEvents (status) VALUES (?)';", False),
        # --- THE CRY-WOLF DIRECTION, which 14 false positives came from -----
        ("W: another table's status column",
         "<?php $q = 'UPDATE tblServicePlans SET status = \"archived\" WHERE id = ?';", False),
        ("W: a service-plan allow-list",
         "<?php $q = 'UPDATE tblServicePlans SET x = ? WHERE id = ?';\nif (in_array($s, ['draft', 'published', 'archived'], true)) {}", False),
        ("W: an RSVP status beside an events JOIN",
         "<?php $q = 'SELECT * FROM tblEventRsvp r JOIN tblEvents e ON e.eventID = r.eventID';\n$u = 'UPDATE tblEventRsvp SET status = \"confirmed\" WHERE id = ?';", False),
        ("W: an unrelated allow-list",
         "<?php " + W + "if (in_array($s, ['active', 'answered', 'archived'], true)) {}", False),
        # ⚠️ The padding here is CODE, not comments. It was 120 comment lines
        #    at first, which the tokeniser strips, so the padding vanished and
        #    the unrelated statement landed inside the window -- the fixture
        #    was wrong, not the check.
        # ⚠️ `extra` existed but nothing exercised it: every faulty fixture
        #    offered a bad word AND omitted a good one, so `missing` alone
        #    flagged them all, and `extra = []` survived as a mutant. This
        #    list has ALL FOUR valid words PLUS a fifth, so only `extra` can
        #    catch it.
        ("A: all four PLUS 'archived' (only `extra` sees this)",
         "<?php " + W + "if (in_array($s, ['draft', 'published', 'cancelled', 'postponed', 'archived'], true)) {}", True),
        # ⚠️ And nothing exercised the comment stripping, because neither
        #    comment fixture held a BRACKETED LIST -- so removing the stripping
        #    also survived. This one does.
        # ⚠️ The commented-out line must MATCH ONE OF THE TWO PATTERNS, or it
        #    exercises nothing. An earlier fixture read `// old: [...]`, which
        #    has neither `in_array(` nor `$var =` before the bracket, so
        #    removing the comment stripping altogether still left the
        #    selftest green.
        ("A: a faulty list inside a COMMENT",
         "<?php " + W + "// $valid = ['draft', 'published', 'cancelled', 'archived'];\nif (in_array($s, ['draft', 'published', 'cancelled', 'postponed'], true)) {}", False),
        ("A: a faulty list in a DOC BLOCK",
         "<?php " + W + "/** $valid = ['draft', 'published', 'cancelled', 'archived']; */\nif (in_array($s, ['draft', 'published', 'cancelled', 'postponed'], true)) {}", False),
        ("W: a status far from the events write",
         "<?php $q = 'UPDATE tblEvents SET eventName = ? WHERE eventID = ?';\n" + ("$pad = 'xxxxxxxxxxxxxxxxxxxx';\n" * 30) + "$o = 'UPDATE tblOther SET status = \"confirmed\" WHERE id = ?';", False),
    ]

    global APPS
    real_apps = APPS
    failures = 0
    with tempfile.TemporaryDirectory() as tmp:
        box = pathlib.Path(tmp)
        APPS = box
        for i, (name, body, should_flag) in enumerate(cases):
            for stale in box.glob("*.php"):
                stale.unlink()
            (box / ("case%d.php" % i)).write_text(body)
            problems, lists_found, sql_found = scan_php(allowed)
            flagged = len(problems) > 0
            ok = flagged == should_flag
            if ok is False:
                failures += 1
            print("%s %-32s expected %-7s got %-7s" % (
                "✓" if ok else "✗:", name,
                "flag" if should_flag else "pass",
                "flag" if flagged else "pass"))
    APPS = real_apps

    # ---- the API description half, which nothing exercised ----------------
    spec_cases = [
        ("S: a correct read enum",
         {"components": {"schemas": {"E": {"properties": {"status": {
             "enum": ["draft", "published", "cancelled", "postponed"]}}}}}}, False),
        ("S: an enum offering 'archived'",
         {"components": {"schemas": {"E": {"properties": {"status": {
             "enum": ["draft", "published", "cancelled", "archived"]}}}}}}, True),
        ("S: an enum omitting 'postponed'",
         {"components": {"schemas": {"E": {"properties": {"status": {
             "enum": ["draft", "published", "cancelled"]}}}}}}, True),
        # ⚠️ ALL FOUR plus a fifth, so only `extra` can catch it. The
        #    "offering 'archived'" case above also OMITS 'postponed', so
        #    `missing` alone flagged it and scan_spec's `extra` branch could be
        #    neutered with every case green -- the same gap half A had.
        ("S: all four PLUS 'archived'",
         {"components": {"schemas": {"E": {"properties": {"status": {
             "enum": ["draft", "published", "cancelled", "postponed", "archived"]}}}}}}, True),
        ("S: an unrelated enum",
         {"components": {"schemas": {"E": {"properties": {"x": {
             "enum": ["active", "answered", "archived"]}}}}}}, False),
    ]
    with tempfile.TemporaryDirectory() as tmp:
        box = pathlib.Path(tmp)
        for name, doc, should_flag in spec_cases:
            f = box / "spec.json"
            f.write_text(json.dumps(doc))
            problems, found = scan_spec(allowed, f)
            flagged = len(problems) > 0
            ok = flagged == should_flag
            if ok is False:
                failures += 1
            print("%s %-32s expected %-7s got %-7s" % (
                "✓" if ok else "✗:", name,
                "flag" if should_flag else "pass",
                "flag" if flagged else "pass"))

    # ---- main()'s OWN verdict, which nothing exercised --------------------
    # Eight mutants survived because of this: the verdict line, both vacuity
    # guards, the fold of the description's faults, and scan_spec's branches.
    good_schema = ("CREATE TABLE IF NOT EXISTS `%s` (\n"
                   "    `%s`  ENUM('draft','published','cancelled','postponed') NOT NULL\n"
                   ") ENGINE=InnoDB\n" % (TABLE, COLUMN))
    good_spec = json.dumps({"components": {"schemas": {"E": {"properties": {"status": {
        "enum": ["draft", "published", "cancelled", "postponed"]}}}}}})
    clean_php = ("<?php $q = 'UPDATE %s SET eventName = ? WHERE eventID = ?';\n"
                 "if (in_array($s, ['draft', 'published', 'cancelled', 'postponed'], true)) {}" % TABLE)
    bad_php = clean_php.replace("'postponed'], true", "'archived'], true")

    verdicts = [
        ("M: a clean tree passes",            clean_php, good_schema, good_spec, 0),
        ("M: a faulty list is refused",       bad_php,   good_schema, good_spec, 1),
        ("M: no allow-list at all FAILS",     "<?php $q = 'UPDATE %s SET x = ?';" % TABLE,
                                              good_schema, good_spec, 2),
        ("M: an unreadable column FAILS",     clean_php, "-- nothing here\n", good_spec, 2),
        ("M: no spec enum FAILS",             clean_php, good_schema, "{}", 2),
        # ⚠️ Only the SPEC is wrong here. Without this, `problems +=
        #    spec_problems` could be changed to `problems += []` -- dropping
        #    every fault the description half finds -- with all 28 cases green,
        #    because every other verdict case uses a correct spec.
        ("M: a faulty SPEC alone is refused", clean_php, good_schema,
         json.dumps({"components": {"schemas": {"E": {"properties": {"status": {
             "enum": ["draft", "published", "cancelled", "archived"]}}}}}}), 1),
    ]
    with tempfile.TemporaryDirectory() as tmp:
        box = pathlib.Path(tmp)
        apps = box / "apps"; apps.mkdir()
        for name, php, schema_text, spec_text, want in verdicts:
            for stale in apps.glob("*.php"):
                stale.unlink()
            (apps / "page.php").write_text(php)
            sch = box / "schema.sql"; sch.write_text(schema_text)
            spc = box / "spec.json"; spc.write_text(spec_text)
            got = main(schema=sch, apps=apps, spec=spc, quiet=True)
            ok = got == want
            if ok is False:
                failures += 1
            print("%s %-32s main() exit expected %d got %d" % (
                "✓" if ok else "✗:", name, want, got))

    total = len(cases) + len(spec_cases) + len(verdicts)
    print()
    if failures == 0:
        print("✓ selftest: all %d cases behaved as expected" % total)
        return 0
    print("✗ selftest: %d of %d case(s) did not behave as expected" % (failures, total))
    return 1


if __name__ == "__main__":
    # --selftest is dispatched HERE, not inside main(): main() now takes its
    # paths as parameters so the selftest can call it, and an early return
    # inside it would recurse.
    sys.exit(selftest() if "--selftest" in sys.argv else main())
