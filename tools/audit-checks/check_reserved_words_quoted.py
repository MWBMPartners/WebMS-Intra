#!/usr/bin/env python3
"""A reserved word used as an identifier is backticked or qualified (#718, #276).

WHAT THIS GUARDS, AND THE FAULT THAT ALREADY HAPPENED
-----------------------------------------------------
MySQL ignores case in keywords. `fullText` IS the reserved word `FULLTEXT`.
So this, in `Transcription.php`, could never parse:

    UPDATE tblTranscript SET status = 'completed', fullText = ?, ...

It was the statement that SAVES a finished transcript, so it threw AFTER the
outside transcription company had been called and charged — and nothing noticed,
because no page could queue a recording in the first place (#276). `php -l`
cannot see it. Until this check, no audit script here could either; the
instructions say so in as many words.

DreamHost is moving the reference host to MySQL 8.4 on 9 November 2026, which
adds reserved words, so the question "is this column name a keyword?" is live
again rather than historical.

WHAT IT CHECKS
--------------
1. **The schema.** Every table and column name in `web/_sql/full_schema.sql`
   that is a MySQL reserved word must be listed in RESERVED_ON_PURPOSE, with
   the reason it is named that way. A NEW one fails, which is the moment the
   decision is cheap — before anything queries it.
2. **Every reference to a listed name**, in PHP, must be backticked
   (`` `fullText` ``) or qualified by a table alias (`t.fullText`). Both work:
   after a dot, a reserved word is allowed as an identifier. A bare one fails.
An ALIAS check was written and REMOVED, which is worth recording because the
removal is the right answer rather than a gap left by accident. `... AS rank`
is the same parse error by another route, but `AS <word>` cannot be told from
English — "as long as", "as not", "as if" and "as is" all matched, in help
pages and comments — and `CAST(x AS BINARY)` is perfectly valid SQL that the
rule also flagged. It produced fifteen reports and not one was real. An alias
colliding with a reserved word is also much rarer than a column doing so, which
is the fault this exists for (#276). #483's rule decided it: a check that cries
wolf gets switched off, and then it catches nothing.

HOW THE LIST WAS MADE, AND HOW TO REGENERATE IT
-----------------------------------------------
From MySQL itself, never from a summary of MySQL. **The server knows the
answer and will tell you**, which is better than any copied list because it
cannot go stale:

    SELECT WORD FROM INFORMATION_SCHEMA.KEYWORDS WHERE RESERVED = 1 ORDER BY WORD;

Run that on the oldest MySQL still supported and paste the result below.

🛑 THIS DISTINCTION IS NOT PEDANTRY — THE HOST'S DOCUMENTATION IS WRONG.
   DreamHost's upgrade notice, and its help page "Using reserved words in
   MySQL 8", both say MySQL 8.4 adds four reserved words: MANUAL, PARALLEL,
   QUALIFY, TABLESAMPLE. **Only two of those are reserved.** Settled three
   ways on 2 November 2026:

     - `INFORMATION_SCHEMA.KEYWORDS` on a real 8.4.13 — the server's own
       answer: FULLTEXT 1, QUALIFY 1, RANK 1, TABLESAMPLE 1, **MANUAL 0,
       PARALLEL 0**, STATUS 0;
     - a parse test — `SELECT manual FROM z` runs, `SELECT qualify FROM z`
       does not;
     - MySQL's published keyword list, which marks neither.

   MANUAL and PARALLEL are keywords in 8.4 but NON-RESERVED, so they are legal
   identifiers unquoted. A guard built from the host's wording would have
   failed on a column named `manual` — a thoroughly plausible name — and a
   check that cries wolf gets switched off (#483).

   So: when a hosting company and the database disagree about the database,
   ask the database.

   🛑 AND MARIADB CANNOT BE ASKED. It has the same table — 699 rows on 11.4.13
      — but with a WORD column only and no RESERVED flag, and MEMBERSHIP DOES
      NOT MEAN RESERVED: `FULLTEXT`, `STATUS` and `WINDOW` are all listed, yet a
      bare `FULLTEXT` fails while `STATUS` and `WINDOW` work. Its only other
      candidate, `INFORMATION_SCHEMA.SQL_FUNCTIONS`, is functions. So this list
      stays MySQL's, and the real protection on any engine is the standing rule:
      BACKTICK EVERY IDENTIFIER IN SQL YOU WRITE (see .claude/rules/database.md
      and ~/.claude/CLAUDE.md, the owner, 2 November 2026).

WHAT IT CANNOT SEE, said plainly because an overstated guarantee is worse than
none:

  - SQL built by concatenation across several variables, or held in a constant.
    It reads string literals that name a table or begin with a SQL verb, so a
    continuation fragment like `. 'fullText = ? '` on its own would be missed.
    In the fault this exists for, the bare reference sat in the same string as
    `UPDATE tblTranscript SET`, which this does see.
  - An alias colliding with a reserved word — see above; the rule was removed
    deliberately.
  - A reserved word that is only a problem on an engine this list does not
    describe — MariaDB has its own, slightly different, set.
  - A column added by a migration but not mirrored into `full_schema.sql`.
    `.claude/rules/database.md` requires both; `check_schema_seed_parity.py`
    guards that.
  - Whether a backticked identifier is correct in every OTHER way. It only asks
    about quoting.

HOW IT FAILS BOTH WAYS
----------------------
An unlisted reserved identifier fails. So does a RESERVED_ON_PURPOSE entry that
no longer matches anything in the schema — because a stale entry is how a list
stops describing the thing it is supposed to describe.
"""

from __future__ import annotations

import pathlib
import re
import sys

REPO = pathlib.Path(__file__).resolve().parents[2]
SCHEMA = REPO / "web" / "_sql" / "full_schema.sql"
WEB = REPO / "web"
TOOLS = REPO / "tools"

# 🔭 WHAT IS SCANNED, and why `tools/` joined on 3 November 2026.
#
# It read `web/` only, and that left the self-tests invisible to it. They write
# SQL BY HAND against the real schema, and a bare reserved word there fails the
# same way it fails in the portal. Measured, in the worst possible place: a
# transcript fixture written for #714 used a bare `fullText` — the very
# identifier this check exists for, the one whose bare reference shipped a
# statement that could not save a transcript (#276) — and this script passed it
# because the file was under `tools/`.
#
# `tools/` is the only addition, and it completes the PHP half: every tracked
# `*.php` file lives under `web/` or `tools/`. It does NOT complete the job, and
# saying otherwise would overreach — `web/_sql/*.sql` (the migrations themselves)
# and `tools/e2e-migrations/run.sh` hold SQL that this script never reads. A
# migration's own SQL is executed by MySQL on the way in, so a bare reserved word
# there fails loudly at upgrade time rather than quietly at run time, which is
# why it is a lower risk and not no risk.
SCAN_ROOTS = (WEB, TOOLS)

# MySQL 8.4 reserved words, from MySQL's own keyword list. Lower-case; the
# comparison ignores case because MySQL does.
RESERVED = set("""
accessible add all alter analyze and as asc asensitive before between bigint binary blob both by
call cascade case change char character check collate column condition constraint continue convert
create cross cube cume_dist current_date current_time current_timestamp current_user cursor
database databases day_hour day_microsecond day_minute day_second dec decimal declare default
delayed delete dense_rank desc describe deterministic distinct distinctrow div double drop dual
each else elseif empty enclosed escaped except exists exit explain false fetch first_value float
float4 float8 for force foreign from fulltext function generated get grant group grouping groups
having high_priority hour_microsecond hour_minute hour_second if ignore in index infile inner inout
insensitive insert int int1 int2 int3 int4 int8 integer intersect interval into io_after_gtids
io_before_gtids is iterate join json_table key keys kill lag last_value lateral lead leading leave
left like limit linear lines load localtime localtimestamp lock long longblob longtext loop
low_priority match maxvalue mediumblob mediumint mediumtext middleint minute_microsecond
minute_second mod modifies natural not no_write_to_binlog nth_value ntile null numeric of on
optimize optimizer_costs option optionally or order out outer outfile over partition percent_rank
precision primary procedure purge qualify range rank read reads read_write real recursive
references regexp release rename repeat replace require resignal restrict return revoke right rlike
row rows row_number schema schemas second_microsecond select sensitive separator set show signal
smallint spatial specific sql sqlexception sqlstate sqlwarning sql_big_result sql_calc_found_rows
sql_small_result ssl starting stored straight_join system table tablesample terminated then
tinyblob tinyint tinytext to trailing trigger true undo union unique unlock unsigned update usage
use using utc_date utc_time utc_timestamp values varbinary varchar varcharacter varying virtual
when where while window with write xor year_month zerofill
""".split())

# Identifiers that ARE reserved words and are named that way on purpose.
# Each needs the reason, because the next reader has to know whether to keep it.
RESERVED_ON_PURPOSE: dict[str, str] = {
    "tblTranscript.fullText":
        "The written-out words of a recording. `FULLTEXT` is a MySQL reserved word and MySQL "
        "ignores case, so every reference must be backticked or qualified by a table alias. "
        "A bare `fullText = ?` in the statement that SAVES a transcript could never parse, and "
        "threw after the outside provider had been called and charged (#276). Renaming the column "
        "would be the other answer; it was not taken, so this check exists instead.",
}

# 🛑 "DOES THIS STRING LOOK LIKE SQL" HAD TO BE MADE MUCH STRICTER, and the
#    first attempt is worth recording. It asked whether the text contained any
#    of select/insert/update/delete/set/where/from/join — which is ordinary
#    ENGLISH. Help pages and code comments matched in numbers: "as long as",
#    "as not", "as if", "as is", "as with" all got reported. #483's rule
#    applies — a check that cries wolf gets switched off, and then it catches
#    nothing.
#
#    The reliable discriminator in THIS codebase is the table naming: every
#    table is `tbl` followed by a capital. A string either names one, or begins
#    with a SQL verb. Prose does neither.
SQL_SHAPED = re.compile(r"\btbl[A-Z]\w*\b|^\s*(?:SELECT|INSERT|UPDATE|DELETE|REPLACE|WITH)\b", re.I)


def schema_identifiers() -> dict[str, str]:
    """`tblX.colY` (and `tblX` for a table) -> the kind, for every reserved one."""
    if not SCHEMA.is_file():
        return {}
    sql = re.sub(r"^\s*--.*$", "", SCHEMA.read_text(encoding="utf-8", errors="replace"), flags=re.M)
    found: dict[str, str] = {}
    for table, body in re.findall(
        r"CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`(\w+)`(.*?)\n\)\s*ENGINE", sql, re.S | re.I
    ):
        if table.lower() in RESERVED:
            found[table] = "table name"
        for line in body.splitlines():
            m = re.match(r"\s+`(\w+)`\s+[a-z]", line, re.I)
            if m is not None and m.group(1).lower() in RESERVED:
                found[f"{table}.{m.group(1)}"] = "column"
    return found


def php_strings(src: str):
    """Yield (line_number, text) for every string literal, skipping comments.

    Walks the source rather than matching patterns, so a `//` inside a string —
    any URL — cannot delete the rest of the line. That exact fault was measured
    on check_no_brotli_decode.py the same week (#716).
    """
    i, n, line = 0, len(src), 1
    while i < n:
        ch = src[i]
        if ch == "\n":
            line += 1
            i += 1
            continue
        if ch in "'\"":
            quote, start_line, buf = ch, line, []
            i += 1
            while i < n:
                if src[i] == "\\" and i + 1 < n:
                    buf.append(src[i + 1])
                    i += 2
                    continue
                if src[i] == quote:
                    i += 1
                    break
                if src[i] == "\n":
                    line += 1
                buf.append(src[i])
                i += 1
            yield start_line, "".join(buf)
            continue
        if src.startswith("/*", i):
            close = src.find("*/", i + 2)
            close = n if close == -1 else close + 2
            line += src.count("\n", i, close)
            i = close
            continue
        if src.startswith("//", i) or src.startswith("#", i):
            nl = src.find("\n", i)
            i = n if nl == -1 else nl
            continue
        i += 1


def main() -> int:
    if not SCHEMA.is_file():
        print("check_reserved_words_quoted: no full_schema.sql — nothing to check.")
        return 0

    found = schema_identifiers()
    problems: list[str] = []

    # 1. Every reserved identifier in the schema is a recorded decision.
    for name, kind in sorted(found.items()):
        if name not in RESERVED_ON_PURPOSE:
            word = name.split(".")[-1]
            problems.append(
                f"{name} — this {kind} is the MySQL reserved word `{word.upper()}`, and MySQL "
                f"ignores case. Every reference to it must be backticked or qualified, or it will "
                f"not parse. Rename it, or add it to RESERVED_ON_PURPOSE with the reason."
            )
    # Fails both ways: a listed entry that is no longer in the schema.
    for name in sorted(set(RESERVED_ON_PURPOSE) - set(found)):
        problems.append(
            f"{name} — listed in RESERVED_ON_PURPOSE but no longer a reserved identifier in the "
            f"schema (renamed, dropped, or no longer reserved). Remove the line."
        )

    # 2 and 3. References, and aliases.
    bare: list[tuple[str, int, str]] = []
    watched = {n.split(".")[-1]: n for n in RESERVED_ON_PURPOSE}
    files = 0
    scanned: list[pathlib.Path] = []
    for root in SCAN_ROOTS:
        scanned.extend(sorted(root.rglob("*.php")))
    for path in scanned:
        files += 1
        rel = str(path.relative_to(REPO))
        for line_no, text in php_strings(path.read_text(encoding="utf-8", errors="replace")):
            if SQL_SHAPED.search(text) is None:
                continue
            for word, full in watched.items():
                # Bare: not preceded by a backtick and not qualified by `alias.`
                for m in re.finditer(rf"(?<![`.\w]){re.escape(word)}\b", text, re.I):
                    tail = text[m.end():m.end() + 1]
                    if tail == "`":
                        continue
                    bare.append((rel, line_no, f"`{word}` ({full}) is used BARE in SQL — "
                                               f"backtick it, or qualify it as `t.{word}`"))

    if not problems and not bare:
        print(f"check_reserved_words_quoted: OK — {len(found)} reserved identifier(s) in the schema, "
              f"each a recorded decision, and no bare reference in {files} PHP file(s).")
        return 0

    print("check_reserved_words_quoted: A RESERVED WORD IS USED AS AN IDENTIFIER\n")
    print("MySQL ignores case in keywords, so `fullText` IS `FULLTEXT`. A bare reference")
    print("does not parse — and `php -l` cannot see it (#276, #718).\n")
    for problem in problems:
        print(f"  • {problem}")
    for rel, line_no, what in bare:
        print(f"  • {rel}:{line_no} — {what}")
    print()
    return 1


if __name__ == "__main__":
    sys.exit(main())
