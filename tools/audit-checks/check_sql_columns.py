#!/usr/bin/env python3
"""
SQL column-name existence check.

Builds a (table → columns) map from every `CREATE TABLE` in
web/_sql/full_schema.sql plus every `ALTER TABLE … ADD COLUMN` across the
numbered migrations. Then greps the PHP codebase for `INSERT INTO tblX (col, ...)
VALUES` patterns and verifies every column appears in the map.

Catches the #198 / #201 class of bug: runtime SQL references a column that
doesn't exist on the named table.

WHERE clauses (added after two more real misses — see the "WHERE-clause
columns" section below for the full story): this check now also reads the
columns a SELECT, UPDATE or DELETE statement tests IN ITS WHERE clause, not
just the columns it writes (INSERT/UPDATE) or reads back (SELECT's own
column list). For a BARE column name (`WHERE status = ?`) it can only do
this for the plain, single-table shape — one table, no JOIN, no alias, no
sub-query — because with more than one table in play a bare column name
could belong to either side, and guessing would turn this into the kind of
check that cries wolf and gets switched off. A name written as
`alias.column` is different — it says exactly which table it means, so a
JOIN or a short alias no longer has to rule it out. Since 17 September 2026
(#519/#520) a joined SELECT's `alias.column` names ARE read this way — see
"THE SELECT-ALIAS READING" in the blind-spot list below for exactly what
that does and does not cover.

Every place it is blind, in one list. Each was reproduced with a small
test file, and the first two were checked against the two real faults
that motivated this section (the old, faulty web/_apps/dashboard/index.php
and web/_apps/tasks/api/list.php, read via `git show`):

  1. A WHERE clause built up from a PHP array/variable at runtime (e.g.
     `'...WHERE ' . implode(' AND ', $conditions)`) never puts the column
     name anywhere near the word WHERE in the source text, so there is
     nothing for a text search to find. This is the tasks/api/list.php
     fault, and it is NOT caught. Such a statement IS counted in the
     coverage figures (as found, and as examined), and correctly finds
     nothing to compare. That zero is honest, but it is not proof the
     query is right.
  2. A quoted value INSIDE the WHERE (e.g. `WHERE status = 'Pending' AND
     siteID = ?`): only the text up to that quote is read, so a fault
     after it is missed. Reading through it would risk treating the
     value's own text as a column name, which is the worse mistake. The
     statement is still counted.
     The exception is an UPDATE whose WHERE is found by following the map
     of the file (blind spot 12): there a quoted value is blanked and the
     reading goes on past it.
     A quote written as a PHP escape in a double-quoted string or heredoc
     (\\x27, \\047, \\u{27}, and the same for ") counts as a quote here, because
     every reading uses the readable copy of the file (blind spot 18). Until
     the second fix of round 4 it did not: the reading ran on through the
     value, `WHERE title = \\x27a = b\\x27 AND ...` WRONGLY REPORTED "x27a", and
     SQL inside such a value was reported against the outer statement.
  3. A quoted value BEFORE the word FROM — for example
     `SELECT DATE_FORMAT(createdAt, '%Y-%m') AS month FROM ...` — means the
     statement is NEVER RECOGNISED AT ALL. It is not checked, and it
     appears in none of the coverage figures. This is not theoretical. Until
     commit 3effa5a (14 September 2026), the monthly activity query in
     web/_apps/admin/reports/index.php compared tblActivityLogs.createdAt,
     which does not exist, in exactly this shape, and this check did not
     report it. That query now names the right column (`timestamp`, around
     line 127) but keeps the same shape, so a wrong name written into it
     would still be missed. The same applies when a double quote or a
     semicolon comes before FROM, when the table name is held in a PHP
     variable rather than written out, or when the table is not named
     `tbl…`. The reason for not reading across a quote is that the quote is
     usually the end of the PHP string, and reading past it runs into
     unrelated code or a different statement. (A quote AFTER the table name
     but before the WHERE is a different case, with its own estimate in the
     figures: see 12.)
  4. A JOIN, a list of tables, or a table given a short alias: skipped (a
     bare column name could belong to either table), and counted under
     "join or alias". Bare names are read only when the part that names the
     tables is exactly ONE table name and nothing else
     (names_one_plain_table()). Anything else written there, such as
     `USE INDEX (siteID)`, a PHP variable, or text added at runtime, gets
     the statement skipped the same way, so a fault in it is missed.
     (Before 14 September 2026 the test was "exactly one tbl... table was
     found, with no short name". That could not see a joined table it did
     not recognise, as in `JOIN users`, `JOIN $tblOther` or `JOIN
     old_tblUsers`, so a correct column of that table was WRONGLY REPORTED
     as missing from the first.)
     A short name the statement gives a table is not read as a table, either
     where it is declared or where it is USED straight before a dot. It
     counts as declared after AS, whatever comes before it (`JOIN $tblOther
     AS tblAssignee`), or without AS straight after a table written as a
     plain name, a backticked name, a {$...}, a bracketed sub-query with at
     most one level of brackets inside it, or a PHP variable in any of the
     forms PHP allows inside a double-quoted string: $x, $x[1], $x[key],
     $x[$i], $this->x, $this?->x and ${x} (ALIAS_DECLARATION_RE). Until round
     4 only a short name straight after a literal tbl... table counted, so in
     `JOIN users AS tblAssignee ON tblAssignee.userID = ...` (and the same
     after `$tblOther` or `{$t}`) "tblAssignee" was WRONGLY REPORTED as an
     unknown table, and the statement was counted under "table not in
     schema". Until the second fix of round 4 the same still happened after
     $tables[1], ${tbl} and $this?->t. A short name that starts with tbl, has
     no AS, and follows anything else, such as a sub-query with brackets
     nested two deep inside it, is still not seen as declared, and IS STILL
     WRONGLY REPORTED as an unknown table.
     A "tbl" in the middle of a word (`mytblAssignee`), or straight after $,
     ${ or -> (`$tblOther`, `${tblOther}`, `$this->tblOther`, all PHP
     variables), is not read as a table either. (Until the second fix of
     round 4 the last two WERE, and were WRONGLY REPORTED as an unknown table
     "tblOther".) Every other
     word starting with tbl in the part that names the tables is taken as a
     table name, and reported if the schema has no such table. That includes
     one after FROM or JOIN whose own short name is the same word (`FROM
     tblTaskz AS tblTaskz`), and one before a dot in an ON part when no short
     name of that spelling is declared. A qualifier that differs from a
     declared short name only in upper or lower case (`TBLASSIGNEE.userID`)
     is skipped, so a fault there is missed.
  5. A sub-query the check can RECOGNISE is skipped by the WHERE scan, and
     counted as such. (Its column list is still read by the older column-
     list check; see blind spot 15.) That covers a statement that CONTAINS
     one, and a SELECT that
     IS one, sitting inside brackets in an UPDATE, DELETE, INSERT or
     another SELECT. Even the inner query's own WHERE is not read, because
     SQL lets a bare name inside a sub-query refer to the OUTER table's
     row; checking it against the inner table would be a false accusation.
     A SELECT is recognised as a sub-query when either of these is true:
       - an opening bracket comes before it with nothing but PHP joining
         code in between, within 200 characters. "Joining code" means
         anything that holds no other piece of quoted text: `' . '`,
         `' . PHP_EOL . '`, `' . "\n" . '`, `$sql .= '`, a variable, or a
         ternary (`$x ? '`). An SQL comment (`-- note` or `# note`) is
         allowed straight after the bracket and straight before the SELECT;
         one anywhere else in between is not;
       - the text the check reads for it closes a bracket it never opened.
     The first test is broad on purpose (when in doubt, skip), and it has a
     price: a STAND-ALONE SELECT is also skipped, and counted as a
     sub-query, when it follows a string ending in "(" with no other
     quoted text in between, or follows a web address in quotes (this
     script mistakes the // in 'https://...' for a comment and removes the
     rest of that line, closing quote included). A fault in such a
     statement is missed. On 14 September 2026 this happened to one real
     statement, web/_apps/invites/save.php around line 55.
     (An earlier version read a sub-query inside an UPDATE or DELETE as a
     stand-alone SELECT, and ran on past its closing bracket into the
     outer statement. That blamed outer columns on the inner table. A
     later version still did so when the bracket and the SELECT were
     joined by `.=` or PHP_EOL, or separated by an SQL comment, and the
     sub-query held a quoted value.)
  6. A sub-query the check CANNOT recognise is read as a stand-alone
     SELECT and examined. That happens when the sub-query is kept in a
     separate PHP variable, array or function and dropped in later (e.g.
     `$sub = 'SELECT ...';` then `"... IN ($sub)"`), or when other quoted
     text (e.g. `sprintf('%s', $x)`), an SQL comment that is not directly
     next to the bracket or the SELECT, or more than 200 characters sit
     between its opening bracket and the SELECT — AND its own closing
     bracket is not in the text the check reads (a quoted value ends the
     reading first, or the bracket is added by a later string). If its
     WHERE then uses a bare name that belongs to the outer table (legal
     SQL), that name is WRONGLY REPORTED against the inner table.
  7. Only the WHERE clause itself is read. Text from GROUP BY, HAVING,
     ORDER BY, LIMIT, UNION, FOR UPDATE / FOR SHARE, LOCK IN SHARE MODE or
     ON DUPLICATE KEY UPDATE onward is ignored. Those parts may legally
     use names that are not columns of the table (a HAVING that tests a
     `COUNT(*) AS openCount`, or the INSERT's own columns after ON
     DUPLICATE KEY UPDATE), so a comparison there is never a finding.
     The cut is by word, so a column really called `limit`, `order` or
     `union` would end the reading early and a fault after it would be
     missed. (On 14 September 2026 no column in the schema had any of
     those names.)
  8. Only the name on the LEFT of a comparison is checked. In
     `WHERE ? = bogusName` or `WHERE NOW() > bogusName` the wrong name is
     on the right, and it is missed.
  9. Words that sit in front of a comparison but are not column names.
     SQL_NOISE lists the ones known about: SQL keywords, MySQL functions
     that may be written without brackets (CURRENT_TIMESTAMP, CURRENT_DATE,
     CURRENT_TIME, CURRENT_USER, LOCALTIME, LOCALTIMESTAMP, UTC_DATE,
     UTC_TIME, UTC_TIMESTAMP), the units of an INTERVAL (WEEK, QUARTER,
     DAY_HOUR and so on) and the SOUNDS of SOUNDS LIKE. A collation name
     after COLLATE is removed before reading, and a name straight after a
     PHP variable or placeholder is ignored: `$column`, `$this->column`,
     a sprintf placeholder such as `%s`, and the end of a name finished at
     runtime such as `{$kind}ID`.
     Each of those was reproduced as a wrong accusation before it was
     handled. Any OTHER such word, not in that list, WOULD BE WRONGLY
     REPORTED as a missing column. A list like this cannot be proved
     complete. (A name in the list is never checked, so a wrongly spelt
     column that happens to be called, say, `week` would be missed.)
     Numbers are not words in this sense. Every form MySQL accepts as a
     number (12, 1e3, 2e-1, 0x1F, 0b101 and so on; see NUMBER_LITERAL_RE)
     is recognised and never checked. Before 14 September 2026 only plain
     whole numbers were, so `1e3`, `0x1F` and `0b101` were WRONGLY
     REPORTED. `0X1F`, `0B101` and `1e` are still checked, because MySQL
     itself reads each of them as a column name.
     The same word list, the same number rule and (for the SET part) the
     same characters a name may not follow are used by the older SELECT
     column-list and UPDATE SET checks, so everything in this blind spot
     applies to them as well (blind spot 15).
     (On 14 September 2026 this touched one real column. `month` is in
     SQL_NOISE because it is an INTERVAL unit, and it is also a column of
     tblCalendarMonthThemes, named in the SELECT column lists in
     web/_apps/calendar/manage/month-themes.php and
     web/_apps/calendar/views/year.php. The older version (commit 9c77216)
     dropped only plain whole numbers from a column list, so it checked
     `month` there; this one does not, and a misspelling of that column in
     those lists would be missed.)
 10. A heredoc (a PHP `<<<SQL` text block, which has no quote marks)
     passed straight into a call, e.g. `prepare(<<<SQL ... SQL\n);`. The
     reading has no closing quote to stop at, so it runs on to the `;`
     and picks up the call's own `)`. That looks like the end of a
     sub-query, so the statement is skipped and counted as a sub-query,
     and a fault in it is missed. A heredoc first assigned to a variable
     is read normally.
 11. DELETE statements are checked but have no coverage figures of their
     own.
 12. A quote, or text added at runtime, AFTER the table name but BEFORE
     the WHERE. The reading stops there and never reaches the WHERE, so
     the statement is NOT CHECKED, and it is NOT in the "found" total or
     any figure that adds up to it. For a SELECT the common shapes are a
     quoted value in a JOIN ... ON, and runtime text between FROM tblX and
     WHERE. Another is SQL split across PHP strings in DOUBLE quotes
     (`"... FROM tblSettings " . "WHERE ..."`, as in web/_install/db_state.php
     around line 85): only strings in single quotes are joined back together
     before reading, so the reading stops at the end of the first string. A
     DELETE of any of these shapes is missed the same way.
     An UPDATE written `UPDATE tblX SET ...`, with nothing between the table
     name and SET, is different since round 5. When its reading stops before
     the WHERE, the WHERE is looked for again by following the map of the
     file through the SET part, exactly as the SET reading does
     (read_where_after_set()). That reading passes quoted values in every
     escaped form (including a quote written as \\x27) and comments, and
     carries on into the next PHP string across a join that is nothing but
     a dot. So `UPDATE tblTasks SET status = 'done' WHERE bogus = ?`, and the
     same split as `... 'done' " . "WHERE bogus = ?`, ARE checked, and are
     counted as found. The WHERE found that way is read to the first
     semicolon, the start of another statement (then counted as a sub-query
     skip), or the end of its own PHP string, and cut at GROUP BY, ORDER BY
     and the rest as in blind spot 7. Quoted values and comments in it are
     blanked and read past. It carries on into a later string only across a
     join inside a quoted value or comment (`title = '" . $safe . "'`), not
     across a plain `" . "`, so a name in a later string
     (`... WHERE taskID = ? " . "AND bogus = ?"`) is missed, as it is in the
     normal reading of a double-quoted string. That was chosen on purpose:
     following a plain join there made `"... WHERE taskID = ?" . "<br>"`
     WRONGLY REPORT "br". The SET part does still follow a plain join; it
     must, or `" . "WHERE` is never found. So when the first string holds no
     WHERE at all, a "where" in ordinary text joined after it is taken as the
     statement's WHERE, and the names after it ARE WRONGLY REPORTED (blind
     spot 19).
     Still not reached for an UPDATE: a SET part added at runtime
     (`SET ' . $setClause . ' WHERE ...`, `implode(', ', $set)`), a
     multi-table or aliased UPDATE with a quote before its WHERE, and a SET
     part whose reading ends first (at a sub-query, a semicolon, ORDER,
     LIMIT, a column really named `order` or `limit`, or a join with PHP code
     in it outside a value, such as `" . PHP_EOL . "WHERE`). An UPDATE whose
     SET is in a later PHP string than its table name
     (`"UPDATE tblTasks " . "SET ... WHERE ..."`) is in no figure at all, not
     even the estimate below.
     Until round 5 every UPDATE whose reading stopped before its WHERE was
     left unchecked (79 on 14 September 2026). That was also a LOSS against
     the older version (commit 9c77216). Its SET reading ran on past a WHERE
     with no space in front of it, such as one starting a new PHP string
     (`" . "WHERE`), and so checked each `name =` in that WHERE by accident.
     This version's SET reading stops at that WHERE, so 6 column tests in 4
     real statements (three in web/_core/Workflow.php, one in
     web/_apps/auth/account/delete-confirm.php) were checked by nothing.
     They are checked again, now labelled UPDATE-WHERE. The older version's
     accidental reading also covered shapes this one still does not: a WHERE
     straight after a join holding PHP code (`" . $x . "WHERE`,
     `" . PHP_EOL . "WHERE`), and a WHERE continued in a later string after
     a plain join. On 14 September 2026 no statement on the real tree had
     any of those shapes: across the whole tree, the only column tests the
     older version made that this one does not are the two `month` tests
     in blind spot 9.
     On 14 September 2026 about 14 UPDATE and 66 SELECT statements on the
     real tree were still not reached (most of those SELECTs would have
     been skipped for a join anyway). Because this is so common, the
     printed figures include a separate "WHERE never reached" line for
     SELECT and UPDATE. It is an estimate, not part of the total, and can
     be a little over or under; where_comes_later() says exactly how. A
     WHERE held in a PHP variable built EARLIER is in that estimate only
     by luck of naming: a variable called `$where` is counted, because
     the search matches its name, while one called anything else (e.g.
     `$filter`) is in no figure at all.
 13. Text this script mistakes for a PHP comment is deleted before the
     patterns read anything (strip_php_comments()). `//` anywhere on a line,
     including inside a quoted string such as 'https://...', removes the
     rest of that line; `/*` inside a quoted string, such as 'image/*',
     removes everything up to the next `*/`. A statement in the deleted text
     is missed. And if an SQL string itself holds `//` before its closing
     quote (e.g. an SQL comment `-- see https://...`), that closing quote is
     deleted, so a WHERE reading runs on into the following lines of PHP
     code, and a PHP constant compared there (`PHP_INT_MAX > $x`) WOULD BE
     WRONGLY REPORTED as a missing column. The UPDATE SET reading is not
     fooled by this, because it follows the map of the file (blind spot 17),
     which is drawn before the deletion. On 14 September 2026 no SQL string
     on the real tree held `//` or `/*`.
     A PHP comment starting with `#` is not deleted, but the map marks it,
     and no statement starting inside it is read. Until round 4 a query
     commented out that way, `# $db->prepare('SELECT * FROM tblTasks WHERE
     oldCol = ?');`, was read as if it were live, and a name in it that no
     longer existed was WRONGLY REPORTED. The same goes for text outside
     <?php ... ?>: until round 4, `<p>Example: SELECT * FROM tblTasks WHERE
     x = 1</p>` in a page template was read as SQL.
     Text INSIDE a PHP string that is not SQL (help text, HTML) is a
     different matter: see blind spot 19.
 14. SQL comments. The map (blind spot 17) marks every SQL comment inside a
     PHP string, following MySQL's rules as checked on a MySQL 8.0.36 server:
     `#` to the end of the line; `--` to the end of the line, unless a
     visible ASCII character follows the dashes straight away; and
     `/* ... */`. So `--` followed by a space, a tab, a line break, any
     other control character, the DEL character, any non-ASCII character, or
     nothing at all is a comment. A -- or # inside a quoted value is part of
     the value. `--bogus`, with nothing between the dashes and the word, is
     NOT a comment to MySQL (it is two minus signs), so it is still read. A
     written \\n in a double-quoted string or heredoc is a line break when the
     page runs, so it ends a comment; in a single-quoted string or nowdoc it
     stays a backslash and an n, and does not.
     Comment text is blanked before a statement is read
     (blank_sql_comments(), read_set_part()). Before 14 September 2026 it was
     read as SQL, so `WHERE taskID = ? -- oldName = x` WRONGLY REPORTED
     "oldName", in a WHERE and in an UPDATE's SET part.
     A statement written INSIDE a comment (`-- was: SELECT oldCol FROM ...`)
     is not read at all (starts_in_unread_text()). Until 14 September 2026
     each of the six readings found such a statement and reported its names.
     Until round 4 one with a quote in the comment before it (`-- don't use:
     SELECT oldCol ...`) still was, and a real statement after a written \\n
     in the same double-quoted string (`-- note\\nSELECT ...`) was wrongly
     skipped.
     Until fix round 3 of round 4, skipping a statement inside a comment could
     also hide a REAL statement after it. The reading of the commented
     statement ran on through line breaks to the next quote or semicolon, and
     the search carried on from the end of that reading. So in a heredoc or a
     multi-line string holding `-- was: SELECT * FROM tblTasks` and then the
     real `SELECT * FROM tblTasks WHERE bogusName = ?`, the real statement was
     never read and was in no coverage figure. The same happened after a
     commented DELETE, UPDATE or INSERT, and after a PHP `#` comment holding a
     statement.
     This is fixed: live_matches() passes over a statement whose first word
     never runs as SQL without reading it at all, and moves on to the next
     place that word appears. (Fix round 3 of round 4 first fixed it by
     searching again from one character after the start of the skipped
     statement. The results were right, but each commented-out statement in
     a long heredoc was still read to the end of the heredoc before being
     skipped, so the time grew with the square of their number: about 3.4
     seconds of CPU for 3,000 of them. Round 5 finds each statement's first
     word first and tries the pattern only where it runs as SQL, which gives
     exactly the same statements.)
     The limits that remain can only make the check MISS a fault:
       - after `--`, MySQL's answer for a non-ASCII character depends on the
         character set of the connection. On MySQL 8.0.36, over utf8mb4 (the
         character set the portal connects with, bootstrap.php) neither a
         non-breaking space nor é started a comment; over latin1 a
         non-breaking space did and é did not. Every non-ASCII character is
         treated as starting one here, so in `--é...` the rest of the line is
         not read.
       - a `)` inside a comment in an INSERT column list ends that list, so
         the columns after the comment are not read. A SELECT column list
         whose only FROM on its line is inside a comment is not read.
       - a quote or semicolon inside a comment (`-- don't`) still ends a
         WHERE reading, exactly as a quote anywhere else does (blind spots 2
         and 12), so nothing after it is read.
         The exception is an UPDATE whose normal reading stopped before its
         WHERE, which is then found by following the map (blind spot 12): a
         comment there is blanked, quote and all, so `UPDATE tblTasks SET
         status = 1 -- don't` then a line break then `WHERE bogus = ?` IS
         checked.
       - a `/*! ... */` comment, whose contents MySQL DOES run, is treated as
         a comment; a complete one is also deleted unread by the PHP-comment
         step in blind spot 13.
 15. The three older checks, which came before the WHERE scan: the INSERT
     column list, the UPDATE SET part and the SELECT column list. Each reads
     less than it might:
       - an INSERT column list (INSERT_RE) is read only when every entry in
         it is a plain name.
       - the SET part is read only for `UPDATE tblX SET`, with nothing
         between the table name and SET (read_set_part()). Quoted values, in
         every escaped form MySQL accepts ('it''s', 'it\\'s') and in every
         kind of PHP string, are blanked, and so are comments. The reading
         carries on into the next PHP string only across a join that is
         nothing but a dot (`' . "`), or that sits inside a quoted value
         (`title = '" . $safe . "', other = ?`). It ends at a semicolon, the
         word WHERE, ORDER or LIMIT, the start of another statement
         (including a sub-query, whose names are then not read), or the end
         of the PHP string. So a column is missed when it comes after a join
         with PHP code in it outside a value (`NOW() ' . $x . ', bogus = ?`),
         or in a SET part continued with `.=` on a later line, or built with
         sprintf or implode, and a column really named `order`, `limit` or
         `select` ends the reading early.
         (When the UPDATE WHERE scan's own reading stops before the WHERE, the
         WHERE this SET reading ends at is read by that scan, which follows
         the map the same way; blind spot 12.)
         (How it got here, in full above UPDATE_SET_HEAD_RE: until 14
         September 2026 it read on through every quote, so `SET title = 'a =
         b'` WRONGLY REPORTED "a", and the reading could run on into PHP code.
         Until round 4 it recognised a quoted value by pattern. That MISSED
         `SET title = 'it''s fine', bogus = ?`, WRONGLY REPORTED "bogus" in
         `SET title = 'it\\'s bogus = yes'`, and, when the SQL string ended in
         "=", "," or "(", WRONGLY REPORTED a name from the next string on the
         line, as in `Logger::info('UPDATE tblTasks SET title =', 'result =
         ok');`. It also missed a column after `THEN 'x'`, or after a value
         holding a semicolon or a line break. `SET $col = ?`, `SET %s = ?`
         and `SET {$k}ID = ?` were WRONGLY REPORTED at first too; a name
         straight after $, ->, %, } or @ is ignored, as in the WHERE scan.)
       - a SELECT column list (SELECT_RE). The pattern takes the text after
         SELECT and its white space, up to the FIRST ` FROM tblX` where tblX
         is followed, after optional white space, by WHERE, ORDER, GROUP,
         LIMIT, ";", ")" or the end of the file, or after at least one
         white-space character by LEFT, INNER, RIGHT, OUTER or JOIN. Only the
         list itself must sit on one line: a list broken across lines is not
         read at all, so a wrong name in it, and an unknown table after it,
         are missed by this reading. Every stretch of white space the pattern
         allows around the list (after SELECT, before and after FROM, after
         the table name) may hold real line breaks, blank lines included, so
         FROM may start a later line.
         (It does not start when `*` follows SELECT and a single white-space
         character; with two or more it does, and the `*` rule below skips the
         list.) "Line" means a real line break: a written \\n is spaces in the
         readable copy (blind spot 18), so it does not end the line. The text
         that match covered is then matched again with its SQL comments
         blanked, and:
           * if tblX is not in the schema, it is reported as an unknown table,
             whatever the list holds;
           * a table followed by LEFT, INNER, RIGHT, OUTER or JOIN gets its list
             skipped, so a wrong name there is missed. (Until 14 September 2026
             that list was read, and `SELECT title, emailAddress FROM tblTasks
             JOIN tblUsers ...` WRONGLY REPORTED "emailAddress".)
           * a list holding "(", ")", ".", "*", " AS " or "DISTINCT" (the last
             two only in capitals) is skipped whole;
           * otherwise the list is split at commas, and each piece that is a
             plain name (backticks and spaces round it removed) is checked. A
             piece that is not (`title t`, `title as t`, `'x'`, `?`) is dropped
             without a word, while the other pieces are still read. Numbers
             and SQL_NOISE words are dropped too (blind spot 9).
     One known way these can still WRONGLY REPORT a correct query: the
     column list of a SUB-QUERY is checked against the sub-query's own
     table, even when the WHERE scan recognises and skips it (blind spot
     5). SQL lets a bare name there mean a column of the OUTER table, so
     `WHERE assignedToID IN (SELECT assignedToID FROM tblUsers)` reports
     "tblUsers.assignedToID". It was left that way on purpose: skipping
     every sub-query's list would also miss a genuinely wrong name such as
     `IN (SELECT userId FROM tblUsers)`, which is caught today, and a query
     that compares an outer column with itself is in practice nearly always
     a mistake anyway.
     Two more ways, both in the SELECT column list, and both in the older
     version (commit 9c77216) too:
       - the pattern does not know where the statement's own column list
         ends. It runs on across a quoted value, the end of the PHP string, a
         semicolon, a UNION or a written \\n, to the first later ` FROM tblX`
         on the same line (or at the start of a later line, after nothing but
         white space), and the plain names it passed are checked against THAT
         table. `SELECT userID, 'x FROM tblTasks WHERE y' FROM tblUsers WHERE
         userID = ?`, `SELECT userID, emailAddress FROM users u UNION SELECT
         assignedToID, title FROM tblTasks WHERE taskID = 1`, the same with a
         semicolon instead of UNION, and the same with a real line break
         instead of the space before that last FROM, each WRONGLY REPORT
         "tblTasks.userID";
       - a table whose name holds GROUP, ORDER, LIMIT or WHERE after at least
         one letter following "tbl", when what follows the name is not one of
         the words or characters the pattern accepts (a short name, for
         example). Nothing makes the name end at a word boundary. So in
         `SELECT groupID FROM tblSmallGroups sg WHERE ...` the pattern gives
         back letters until "Groups" counts as the GROUP it looks for. On the
         second match, over that shorter text, "tblSmallGroup" is the whole
         name, and it IS WRONGLY REPORTED as an unknown table. (The older
         version, which did not match a second time, said "tblSmall".) The
         same happens after `SELECT COUNT(*)`, and with tblApiRateLimits,
         tblLiveRateLimits, tblUserGroups, tblVenueBookingGroups and the other
         tblSmallGroup tables. (tblGroups is safe: nothing is left before
         "Groups".) On 14 September 2026 no SELECT column-list match on the
         real tree reached such a table. The shape is written once in the
         files this check reads, at web/_core/SmallGroups.php line 120:
         `FROM tblSmallGroups g WHERE g.siteID = ?`. It gives no wrong report
         only because that statement's column list (`SELECT g.*,` on line
         113) is not on the same line as the FROM, and the FROM is not alone
         at the start of its line, so the pattern never reaches it.
     Words not in SQL_NOISE (blind spot 9), the limits of
     the map (blind spot 17) and a name split across two PHP strings at an
     escaped letter (blind spot 18) can cause wrong reports here too.
 16. Names this check does not read at all:
       - a name qualified by the TABLE's own name in the WHERE of a
         single-table statement, as in `SELECT title FROM tblTasks WHERE
         tblTasks.bogusCol = ?` (and the same in the WHERE of an UPDATE or
         DELETE). The bare-name scan skips anything after a dot, and the
         DELETE check for `shortname.column` only knows short names given
         with AS or after the table, not the table's own name. Such a fault
         is missed. In an UPDATE's SET part it IS read: `SET
         tblTasks.bogusName = ?` reports "bogusName", because SET_ASSIGN_RE
         allows a dot before the name. (Until round 4 this item wrongly said
         the SET part was not read either.)
       - a backticked name holding a character that cannot be in a plain
         name, such as `due-date` or `due date`. Until 14 September 2026 the
         last part ("date") was WRONGLY REPORTED as a missing column; the
         backticks are now matched as a pair (see SET_ASSIGN_RE), so the
         name is skipped instead, and a fault in such a name is missed.
 17. The map of each file (sql_regions(), added in round 4), which decides
     where a statement may start, what is a quoted value and what is a
     comment. It reads PHP the way PHP does (text outside <?php ... ?>; #,
     // and /* */ comments; single-quoted, double-quoted, heredoc and nowdoc
     strings, each with its own escapes; {$...} inside a string), then reads
     each string's contents the way MySQL does. It is not a full PHP parser
     and it cannot run the page, so:
       - SQL is followed from one PHP string into the next when the PHP between
         them starts with a dot, ends with a dot and holds no semicolon
         (`' . $x . '`, `' . "`, `' . f($a, $b) . '`, `' . ($c ? $a : $b) . '`,
         with comments allowed in between). The test knows nothing else about
         PHP. Because of PHP's order of operations, the two strings may NOT be
         joined even so. A comma between arguments or array items, `=>`, the
         `?` or `:` of a ternary outside brackets, a comparison (==, !=, <, >=
         and so on), &, ^, |, &&, ||, ??, and, xor and or all apply after the
         dots, and the walk carries on across every one of them. In
             f("UPDATE tblTasks SET title = '" . g($a), h($b) . "', result = ok");
         the two strings are separate arguments, but the second is read as
         carrying on inside the first one's quoted value. Its quote is taken as
         closing that value, and "result" IS WRONGLY REPORTED (the older
         version, commit 9c77216, reported it too). The same shape in the
         WHERE of an UPDATE found by following the map (blind spot 12),
         `... WHERE title = '" . g($a), h($b) . "' AND result = ok"`, WRONGLY
         REPORTS "result" as well. A statement at the start of such a second
         string is taken as inside a value and missed
         (`... = '" . g($a), h($b) . " SELECT * FROM tblTasks WHERE bogus = 1"`).
         (Until round 5 this item said a comma or a question mark in the join
         stopped the carry; that was never true.) On 14 September 2026, 1 of
         the 2,404 joins carried across on the real tree had a comma outside
         brackets, and none had a `?` there. Refusing to carry across a comma
         or `?` outside brackets, or across a bracket that closes one it did
         not open, was tried in round 5 and changed nothing the check read. It
         was not kept: every other operator listed above would still carry,
         and doing this properly needs a PHP parser.
         SQL assembled with no dot on each side (sprintf, implode, an array,
         `.=` on a later line) is read string by string, each from a fresh
         start. A string that really begins inside a quoted value or comment
         when the page runs is then read as plain SQL, and a statement in it,
         as in
             "title = '" . implode("', '", $t) . " SELECT * FROM tblTasks WHERE oldCol = 1'"
         IS WRONGLY REPORTED ("oldCol"). The other way round, a statement in a
         string that really begins as plain SQL but is read as starting inside a
         value (`"x = '" . f($a, 'b') . "'; SELECT ..."`) is missed.
       - it assumes MySQL's default modes: under ANSI_QUOTES a "..." is a
         name, not a value, and under NO_BACKSLASH_ESCAPES a backslash
         escapes nothing. Nothing in web/_core or web/_install sets sql_mode.
       - the short `<?` opening tag is not recognised, so a statement after
         one is taken as text outside PHP and missed; __halt_compiler() is
         not recognised either.
       - a PHP syntax error, such as a string that is never closed, makes the
         rest of the file look like that string, so a statement in it is
         missed.
       - a backtick string (a shell command in PHP) is not mapped, so a
         statement in one is read as live SQL and quotes in it are not seen
         as values.
       - reconstruct_php_strings() runs first and joins '...' . '...' even
         where that text is itself inside a double-quoted string or a
         comment, which changes the text the map is drawn from.
       - the PHP code inside a {$...} written into a string is not mapped: all
         of it is marked as "PHP written into the string". A statement inside
         it is read, because it may be a real query passed to a function, but
         a quoted value or comment inside THAT statement is not recognised. So
             "{$db->q("SELECT * FROM tblTasks WHERE taskID = ? -- oldName = x")}"
         IS WRONGLY REPORTED ("oldName"), and SQL written inside a quoted value
         there is read as another statement: `... WHERE title = 'x' AND status
         = 'SELECT * FROM tblTasks WHERE bogus = 1'` inside a {$...} IS WRONGLY
         REPORTED ("bogus"). Even where no report results, such a statement
         adds one to the coverage figures. Mapping it would need the walk to
         follow PHP code back into strings inside a string, which it does not.
     When it was added in round 4, the map changed nothing on the real tree:
     all 2962 statement starts the six patterns found were plain SQL text,
     none was skipped, no file ended inside a string, and the check read
     exactly the same 913 SET names and 1092 WHERE clauses as the round-3
     version.
 18. PHP escapes inside a string (`\\n`, `\\t`, `\\x27`, `\\047`, `\\u{27}`,
     `\\$`, and `\\'` or `\\\\` in a single-quoted string). Every reading
     uses a readable copy of the file in which each escape is replaced by what
     it stands for, keeping the text the same length (readable_sql_text()):
     white space becomes spaces; a word holding an escaped letter, digit or
     underscore is rebuilt as the page sends it, with spaces after it
     (`st\\x61tus` becomes `status` and three spaces); any other escaped
     character is followed by `}`.
     How it got here:
       - until the second fix of round 4 the readings used the text as
         written, so a backslash and a letter sat between the words: `SET
         title = ?,\\nstatus = ?` WRONGLY REPORTED "nstatus" (and \\t
         "tstatus"), in the SET part and in the WHERE of a SELECT, UPDATE or
         DELETE; a quote written as \\x27 was not a quote (blind spot 2); and
         `FROM tblTasks\\nWHERE` hid its WHERE.
       - that fix blanked a whole word holding an escaped letter with `}`, so
         the word was not read. For a column name that only missed a fault,
         but an SQL KEYWORD written that way lost everything the keyword
         protects, and correct queries WERE WRONGLY REPORTED: `... WHERE
         taskID > 0 H\\x41VING openCount > 5` reported "openCount", `JOIN
         tblUsers \\x41S tblAssignee ON tblAssignee.userID = ...` reported an
         unknown table "tblAssignee", `title C\\x4fLLATE utf8mb4_bin = ?`
         reported "utf8mb4_bin". Fix round 3 rebuilds the word instead, which
         fixes those and also catches a wrong name written that way
         (`b\\x6fgus = ?`).
     What remains:
       - one way to WRONGLY REPORT a correct query: a name split across two
         double-quoted PHP strings joined with a dot, where the first part
         ends in an escaped letter, as in
             "UPDATE tblTasks SET st\\x61" . "tus = ? WHERE taskID = ?"
         The spaces after the rebuilt "sta" separate it from "tus", so the SET
         reading sees the name "tus" and reports it. (Written without the
         escape, `"... SET sta" . "tus = ?"` is read correctly as "status".)
         Putting the spaces before the word instead was considered and
         rejected: it breaks the opposite split (`"SET x" . "\\x61b = ?"`),
         and it separates a name from a character that must stop it being
         read (`$col\\x41`, `{$k}\\x49D`, `1.e\\x33`).
       - a written line break is replaced by spaces, not a line break, so line
         numbers stay right. The SELECT column-list pattern, whose list must
         sit on one line (blind spot 15), therefore reads across it. Usually
         that only means a list is read that would otherwise not be. But it
         can also let the reading run on to a later FROM and WRONGLY REPORT a
         name (blind spot 15; the older version, which saw `\\n` as two
         characters on one line, did the same);
       - a name straight after any other escaped character (`\\$col`, a
         quote, a bracket) is not read, just as a name after `$` or `}` is not
         (blind spot 9) (can only miss);
       - a rebuilt name in backticks keeps its closing backtick next to it,
         but one that also holds a doubled backtick (a backtick inside the
         name) has the spaces inside the backticks and is not read (can only
         miss);
       - the sub-query look-back (select_is_inside_brackets()) still reads
         the text as written, so its handling of a written \\n is unchanged; a
         quote written as an escape between the bracket and the SELECT is not
         seen as other quoted text there, so the SELECT is skipped;
       - only double-quoted strings, heredocs and single-quoted strings are
         decoded; a backtick (shell) string is not mapped at all (blind spot
         17).
     On 14 September 2026 this changed nothing on the real tree: 127 files held
     PHP escapes inside strings, 920 in all (543 outside a quoted SQL value,
     that is 539 in SQL text and 4 in SQL comments, and 377 inside one), and
     the check made exactly the same 6391 column tests (this was before round
     5's UPDATE change), with the same coverage figures. The word rebuild of
     fix round 3 was checked the same way: 38 escaped letters
     or digits in 10 files were rebuilt, and the 6391 column tests, findings
     and figures were unchanged. None of the 38 was in SQL: all were byte
     strings (the byte-order mark "\\xEF\\xBB\\xBF" in CSV import and export
     code, key bytes in WebAuthn.php and WebPush.php, an address prefix in
     RateLimiter.php). The map still calls them SQL text only because it treats
     every PHP string as possible SQL.
 19. Text inside a PHP string that is not SQL at all, such as help text or
     HTML holding an example. The map (blind spot 17) cannot tell such a
     string from SQL, because any PHP string might be SQL.
     The SELECT and UPDATE WHERE scans skip a statement whose first word
     sits in the middle of other text. They read it only when what comes
     straight before that word, passing over white space, SQL and PHP
     comments and joins, is the start of the PHP string, a {$...}, "(",
     ")", ";", or one of the words UNION, EXCEPT, INTERSECT, ALL or DISTINCT
     (follows_non_sql_text()). Until round 5 they did not, so
     `echo '<p>Example: SELECT * FROM tblTasks WHERE yourColumn = 1</p>';`
     WRONGLY REPORTED "p" and "yourColumn"; the older version (commit
     9c77216), which had no such scans, reported nothing. A skipped statement
     is in no figure.
     What remains:
       - such text that STARTS its PHP string with the word, or follows one of
         the characters or words above, IS STILL WRONGLY REPORTED
         (`echo 'SELECT * FROM tblTasks WHERE yourColumn = 1 is how';`). For
         an UPDATE this now includes one with a quoted value in its SET part,
         because its WHERE is found by following the map (blind spot 12);
       - text that is not SQL joined AFTER an SQL string in single quotes, as
         in `'SELECT * FROM tblTasks WHERE taskID = ?' . '</p>'`. The two
         strings are joined into one before anything is read, and "p" IS
         WRONGLY REPORTED by the WHERE scans. The older version's DELETE
         reading did the same (`'DELETE FROM tblTasks WHERE taskID = ?' .
         '</p>'`). Between double-quoted strings a WHERE reading stops at the
         end of the first string, so this does not happen there, with one
         exception. When the first string is an `UPDATE tblX SET ...` with no
         WHERE in it, the statement's WHERE is looked for by following the SET
         part across a plain `" . "` (blind spot 12), so a "where" in the text
         joined after it is taken as that WHERE:
         `"UPDATE tblTasks SET status = 'done'" . " where yourColumn = 1"`
         WRONGLY REPORTS "yourColumn", and so does the same with `SET status
         = 1`. The older version (commit 9c77216) and this check before round
         5 reported neither, though the older version did report
         `"UPDATE tblTasks SET title = 'x'" . "<br>where yourColumn = 1"`,
         under UPDATE, because its SET reading ran on into the joined text;
       - the other four readings (INSERT column list, UPDATE SET part, SELECT
         column list, DELETE) have no such test, exactly as in the older
         version. So `<p>Example: DELETE FROM tblTasks WHERE yourColumn =
         1</p>` IS STILL WRONGLY REPORTED ("p", "yourColumn"), and so is
         `UPDATE tblTasks SET yourColumn = 1` in help text. Giving them the
         test would make them miss faults the older version caught, such as
         the column list of `INSERT INTO tblArchive SELECT title, bogus
         FROM tblTasks WHERE ...`;
       - real SQL whose first word follows any other word or character is
         now skipped by the two WHERE scans, so a fault in it is missed:
         `INSERT INTO tblArchive SELECT * FROM tblTasks WHERE ...` (also with
         the table name in backticks), `EXPLAIN SELECT ...`, `CREATE TABLE t
         AS SELECT ...`, a PHP variable written straight into a double-quoted
         string before the word (`"$prefix SELECT ..."`; `{$prefix}` is
         read), and a bracket written as a PHP escape (`\\x28SELECT`, which
         the readable copy shows as `(}}}`). So is a statement straight after
         a quoted value or other text with no semicolon between, which is not
         valid SQL anyway.
     On 14 September 2026 every statement those two scans read on the real
     tree started its PHP string, followed "(" or followed UNION, so the test
     skipped none of them.

THE SELECT-ALIAS READING (added 17 September 2026, for #519/#520): a SECOND,
narrower reading of exactly the SELECT statements blind spot 4 above gives up
on — the ones with a JOIN, a list of tables, or a short table alias, where a
BARE column name is genuinely ambiguous and stays unread. A name written as
`shortname.column`, though, says exactly which table it means; nothing has
to be guessed. This reading checks only THOSE names, using the very
`by_alias` map `parse_from_tables()` already built for the DELETE check
higher up in this same script (the SELECT WHERE scan was already calling
that function for its own unknown-table check — see the surrounding code —
this reading is one small addition on top, not a second parser).

  * How it got here: while planning #519 and #520, the question was asked
    plainly — can this check be taught to read a joined SELECT's qualified
    names at all, safely, without turning it into the kind of check that
    cries wolf and gets switched off? A throwaway prototype answered yes,
    reusing every one of this script's own functions (build_schema_map(),
    reconstruct_php_strings(), sql_regions(), strip_php_comments(),
    live_matches(), SELECT_TAIL_RE, reread_without_comments(),
    follows_non_sql_text(), parse_from_tables() and QUALIFIED_COL_RE), run
    over web/_apps, web/_core and web/public_html on the committed code: of
    1,191 recognised SELECT statements, 411 had at least one resolvable
    short name, 3,738 alias.column names were found in them, 313 could not
    be resolved to a declared short name and were skipped, 3,425 were
    actually checked, and exactly 2 were reported — both real, live faults
    (Events.php's `u.email`, #520, and the leadership API's
    `a.assignedAt`) — with ZERO false alarms. That is the design kept here.
  * Rule 1 — only `alias.column` is read. A bare column name in the same
    statement is STILL not read, for the reason blind spot 4 gives; this
    reading adds coverage, it does not remove the caution the bare-name scan
    already applies.
  * Rule 2 — a statement is skipped ENTIRELY (this reading never runs at
    all) when any name in its FROM/JOIN part is not a real table. That is
    the existing `unknown_tables` guard a few lines above this reading in
    scan_php_inserts(), which already runs and `continue`s before this
    reading is ever reached — no separate check was needed for it.
  * Rule 3 — column names are compared WITHOUT REGARD TO CASE. Proved on
    MySQL 8.0.36: `SELECT d.FILENAME FROM tblDocuments d` runs perfectly
    even though the schema spells the column `fileName` — MySQL column
    names are case-insensitive. The FIRST version of this prototype
    compared case-sensitively and reported that correct query
    (`web/_apps/documents/api/list.php`) as a fault. Comparing without
    regard to case removed it, with the 2 genuine findings above unchanged.
    Any future edit that starts comparing case-sensitively again WILL cry
    wolf on that same file, which is exactly the failure the rest of this
    script goes to such lengths everywhere else to avoid.
  * Rule 4 — an unresolvable short name (one the statement's own FROM/JOIN
    part never declared) is SKIPPED AND COUNTED, never reported. Reporting
    it would risk accusing a perfectly correct column belonging to a table
    this reading simply could not identify — for instance, a qualifier that
    is the statement's own FULL table name rather than a declared alias
    (`SELECT title FROM tblTasks WHERE tblTasks.title = ?`) is never added
    to `by_alias` by parse_from_tables() (see blind spot 16), so it is
    skipped here too, exactly as the rest of this script already treats
    that shape.
  * What it reads: the WHOLE recognised statement text (`full`, from
    SELECT to the point SELECT_TAIL_RE stops), not only the WHERE clause —
    so a qualified name in an ORDER BY, a JOIN ... ON, or the SELECT
    column list itself is checked too, not just one in the WHERE. This is
    deliberately broader than the bare-name WHERE scans elsewhere in this
    script, and it is safe here because `alias.column` names its table
    directly rather than leaving it to be inferred from position.
  * What it STILL cannot do, each proved by trying it and NOT closing it
    (the same discipline as every numbered blind spot above):
      - a statement whose column list holds a quoted value before FROM is
        not recognised BY SELECT_TAIL_RE AT ALL, so this reading never
        even sees it — this is blind spot 3, unchanged, and it is why
        admin/live/chat.php's real `m.flaggedReason` fault (found by hand,
        not by this reading, during the #519/#520 sweep) is not reported
        by this check: that statement's column list holds
        `COALESCE(e.eventName, "— no event —")`, and the double quote ends
        SELECT_TAIL_RE's reading before it ever reaches FROM.
      - a bare column name in a joined statement is unchanged — still not
        read (blind spot 4).
      - a quoted VALUE elsewhere in the recognised text that happens to
        contain a dot between two word characters (an email address in a
        default value, for instance) could in principle be matched by
        QUALIFIED_COL_RE and, if its left-hand side happened to coincide
        with a declared short name, be checked against the wrong table.
        This was not observed anywhere on the real tree when this reading
        was written (0 false alarms across 3,425 checks) and is noted here
        because it was reasoned about, not because it was seen — the same
        standard blind spot 17's own notes hold to.
  * Coverage: printed by print_where_coverage() in ITS OWN block, separate
    from the select_where_*/update_where_* arithmetic that must sum exactly
    to "found" — a statement can legitimately be counted BOTH as "skipped —
    join or alias" there AND under this reading, because this is a second
    pass over exactly those skipped statements, not a different population.
    Folding the two together would make a single statement count twice
    toward one total and break the sum the assert in that function checks.

Blind spots 4 (a short name after something the pattern cannot read), 6,
9, 12 (a "where" in ordinary text joined after an UPDATE's SET part, the
same wrong report blind spot 19 describes), 13, 15 (a sub-query's column
list, a column list read on past its own FROM, and a table name holding
GROUP, ORDER, LIMIT or WHERE), 17 (including SQL carried across a PHP join
that PHP does not really make, in the SET part and in an UPDATE WHERE found
by following the map), 18 (a name split across two PHP strings at an
escaped letter) and 19 (text that is not SQL) are the known ways this check
can still WRONGLY REPORT a correct query. On 14 September 2026 the real tree
gave no findings at all (the genuine faults it reported earlier that day had
been fixed by then), so none of those shapes was causing a wrong report that
day. That does not prove they are absent from the code. These are also only
the ways found by testing; a check that reads text rather than running SQL
cannot prove there are no others. Every other blind spot above can only make
it MISS a fault, never invent one.

COVERAGE figures are printed for SELECT and UPDATE: how many recognised
statements have a WHERE clause the reading reached, and of those, how many
were examined versus skipped and why. Those figures add up exactly (the
script refuses to print them if they do not). A run reporting zero
findings on its own proves nothing, and these figures show how much was
actually looked at. They count only what was RECOGNISED and REACHED,
though. Blind spot 3 is in none of them, and blind spot 12 is in none of
them either, apart from its own separate estimate, which is deliberately
kept out of the sum. So they are a floor, not a count of every query in
the code.

Exit code:
  0 — no findings
  1 — one or more mismatches (CI annotates but doesn't block unless --strict)

Usage:
  python3 tools/audit-checks/check_sql_columns.py [--strict]
"""

from __future__ import annotations

import re
import sys
from collections.abc import Iterator
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
SQL_DIR = REPO_ROOT / "web" / "_sql"
PHP_ROOTS = [
    REPO_ROOT / "web" / "_install",
    REPO_ROOT / "web" / "_core",
    REPO_ROOT / "web" / "_apps",
    REPO_ROOT / "web" / "public_html",
]

# Match: CREATE TABLE IF NOT EXISTS `tblX` ( …columns… )
CREATE_RE = re.compile(
    r"CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(tbl\w+)`?\s*\((.*?)\)\s*ENGINE",
    re.IGNORECASE | re.DOTALL,
)
# Match a column definition inside a CREATE TABLE block — `colName` …
COLUMN_RE = re.compile(r"^\s*`(\w+)`\s+", re.MULTILINE)

# Match: ALTER TABLE `tblX` ADD COLUMN [IF NOT EXISTS] `colY` …
ALTER_ADD_RE = re.compile(
    r"ALTER\s+TABLE\s+`?(tbl\w+)`?\s+ADD\s+COLUMN\s+(?:IF\s+NOT\s+EXISTS\s+)?`(\w+)`",
    re.IGNORECASE,
)
# Also: ALTER TABLE `tblX` … `colY` (sometimes split across multiple
# ADD COLUMN clauses in one ALTER statement). Greedy enough for our patterns.
ALTER_ADD_MULTI_RE = re.compile(
    r"ALTER\s+TABLE\s+`?(tbl\w+)`?\s+(.*?);",
    re.IGNORECASE | re.DOTALL,
)

# Match: INSERT INTO `tblX` (col, col, col) — captures the column list.
INSERT_RE = re.compile(
    r"INSERT\s+(?:IGNORE\s+)?INTO\s+`?(tbl\w+)`?\s*\(([^)]+)\)",
    re.IGNORECASE,
)

# The start of an UPDATE's SET part: `UPDATE tblX SET `. What follows is read
# by read_set_part() below, which decides where the SET part ends.
#
# How this reading got to where it is (all on 14 September 2026):
#
#   * The first version was one pattern that read on through quotes, stopping
#     only at a quote followed by ")". Two wrong reports came from that: a
#     quoted value holding an equals sign (`SET title = 'a = b'` reported "a"),
#     and, when the SQL string was followed by more PHP rather than ")", the
#     reading ran on into that PHP (`'... SET title = ?' . $x; $y = 'foo =
#     bar';` reported "y" and "foo").
#   * The next stopped at the FIRST quote of any kind. That removed both wrong
#     reports, but it also stopped checking every column named after a quoted
#     value, the very common `SET status = 'done', updatedAt = NOW()` shape. On
#     the real tree that silently dropped 132 of the 918 SET names the check
#     used to read, all of them real columns. Rejected.
#   * The next stepped over a quoted value by pattern: a quote after "=", "," or
#     "(", up to the next quote on the same line. That could not tell an SQL
#     value from the end of the PHP string, and it treated an escaped quote as
#     the end of a value. So it MISSED `SET title = 'it''s fine', bogus = ?`
#     (the doubled quote looked like the end), WRONGLY REPORTED "bogus" in
#     `SET title = 'it\'s bogus = yes'` (the backslash-escaped quote looked
#     like the end), and, when the SQL string itself ended in "=", "," or "(",
#     took the PHP code after it as a "value" and WRONGLY REPORTED a name from
#     the next, unrelated string (`Logger::info('UPDATE tblTasks SET title =',
#     'result = ok');` reported "result"). Rejected in round 4.
#
# Now the reading follows the map sql_regions() draws of the file, so it knows
# which characters are a quoted SQL value (including every escaped-quote form
# MySQL accepts), which are an SQL comment, and where the PHP string holding the
# SQL ends. See read_set_part() for the rules and what they still cannot do.
UPDATE_SET_HEAD_RE = re.compile(
    r"UPDATE\s+(?:IGNORE\s+)?`?(tbl\w+)`?\s+SET\s+",
    re.IGNORECASE,
)

# A word that ends the SET part: the WHERE, ORDER BY or LIMIT after it, or the
# start of another statement (including a sub-query, whose names would
# otherwise be read against the outer table).
_SET_END_WORD_RE = re.compile(
    r"(?:WHERE|ORDER|LIMIT|INSERT|UPDATE|DELETE|SELECT)\b", re.IGNORECASE
)


def _region_run_end(regions: bytearray, i: int) -> int:
    """The index just after the run of equal region codes that starts at i."""
    code = regions[i]
    n = len(regions)
    while i < n and regions[i] == code:
        i += 1
    return i


def read_set_part(text: str, regions: bytearray, start: int) -> str:
    """
    Read an UPDATE's SET part, starting just after `SET `, and return it ready
    for SET_ASSIGN_RE: every quoted SQL value replaced by '', every SQL comment
    by a space, and every `{$...}` written into a double-quoted PHP string by
    `{$}` (so a name straight after it is still ignored, as SET_ASSIGN_RE
    explains).

    The reading ends at the first of:
      * a semicolon, or the word WHERE, ORDER, LIMIT, INSERT, UPDATE, DELETE or
        SELECT (outside a quoted value or comment);
      * the end of the PHP string holding the SQL. It carries on into the next
        string only across a join that sql_regions() marked (PHP_JOIN), and
        then only when the join is nothing but a dot between two quotes
        (`' . "`), or sits inside a quoted SQL value or comment
        (`title = '" . $safe . "', other = ?`, where the whole of
        `'" . $safe . "'` is one value when the page runs).

    Why the end of the PHP string, and not "a quote": a quote is ambiguous (it
    may open a value or close the PHP string), while the map says which it is.
    This is what stops the reading running on into PHP code or an unrelated
    string on the same line.

    What it cannot do (blind spot 15 in the header):
      * a join with PHP code in it OUTSIDE a value (`NOW() ' . $x . ', bogus =
        ?`) ends the reading, because that code could add any SQL at all, so a
        fault after it is missed;
      * a SET part assembled some other way (sprintf, implode, `.=` on a later
        line) is only read up to the end of the first string;
      * a column really named `order`, `limit` or `select` ends it early;
      * everything sql_regions() cannot do applies here too (blind spot 17).

    The walk itself is _read_clause(), shared since round 5 with
    read_where_after_set().
    """
    return _read_clause(
        text, regions, start, _SET_END_WORD_RE, follow_plain_joins=True
    )[0]


# A word that starts another statement. It ends the reading of a WHERE found by
# read_where_after_set(): what follows is a sub-query (or not SQL at all).
_STATEMENT_WORD_RE = re.compile(r"(?:INSERT|UPDATE|DELETE|SELECT)\b", re.IGNORECASE)


def _read_clause(
    text: str,
    regions: bytearray,
    start: int,
    end_word_re: re.Pattern[str],
    *,
    follow_plain_joins: bool,
) -> tuple[str, int, str]:
    """
    The walk read_set_part() describes above, generalised so
    read_where_after_set() can reuse it for the WHERE that follows a SET part.

    Returns (the text read, the index it stopped at, the stop word in capitals
    or "" when it stopped at a semicolon, the end of the PHP string, a join it
    does not follow, or the end of the file). The text read has every quoted
    SQL value replaced by '', every SQL comment by a space and every `{$...}`
    written into a double-quoted PHP string by `{$}`, exactly as
    read_set_part() promises.

    follow_plain_joins: True means a join that is nothing but a dot between
    two quotes is followed, as the SET reading has done since round 4. A join
    inside a quoted value or comment is ALWAYS followed, whatever this is —
    the whole of `'" . $safe . "'` is one value when the page runs, so the
    walk cannot stop in the middle of it. False (used for the WHERE that
    read_where_after_set() finds) means a plain join stops the walk instead;
    see that function's docstring for why.

    Split out in round 5 so the UPDATE WHERE scan can use the same walk as the
    SET reading; the SET reading itself is unchanged.
    """
    out: list[str] = []
    n = len(text)
    i = start
    while i < n:
        code = regions[i]
        if code in (SQL_VALUE, SQL_COMMENT, PHP_IN_STRING):
            out.append({SQL_VALUE: "''", SQL_COMMENT: " ", PHP_IN_STRING: "{$}"}[code])
            i = _region_run_end(regions, i)
            continue
        if code == PHP_JOIN:
            # The join, including any PHP comment inside it (NOT_RUN), which
            # PHP ignores; the "plain dot" test looks only at the rest.
            end = i
            while end < n and regions[end] in (PHP_JOIN, NOT_RUN):
                end += 1
            join = "".join(
                ch for ch, c in zip(text[i:end], regions[i:end]) if c == PHP_JOIN
            )
            before = regions[i - 1] if i > 0 else SQL_TEXT
            after = regions[end] if end < n else PHP_CODE
            plain_dot = (
                join[0] in "'\"" and join[-1] in "'\"" and join[1:-1].strip() == "."
            )
            inside_value = before == after and before in (SQL_VALUE, SQL_COMMENT)
            if not ((plain_dot and follow_plain_joins) or inside_value):
                return "".join(out), i, ""
            i = end
            continue
        if code != SQL_TEXT:
            # PHP code, a PHP comment or text outside PHP: the SQL string ended.
            return "".join(out), i, ""
        ch = text[i]
        if ch == ";":
            return "".join(out), i, ""
        if ch.isalpha() and (i == 0 or not (text[i - 1].isalnum() or text[i - 1] == "_")):
            end_word = end_word_re.match(text, i)
            if end_word:
                return "".join(out), i, end_word.group().upper()
        out.append(ch)
        i += 1
    return "".join(out), i, ""


def read_where_after_set(
    text: str, regions: bytearray, start: int
) -> tuple[str, bool] | None:
    """
    For an `UPDATE tblX SET ...` statement starting at `start`: follow the map
    of the file through its SET part exactly as read_set_part() does, and if
    that reading stops at the word WHERE, read the WHERE the same way and
    return (its text, whether that reading stopped at the start of another
    statement rather than a semicolon or the end of its string). Returns None
    when `start` is not the head of a plain `UPDATE tblX SET` statement, or
    the SET part it reads does not end at WHERE.

    Why this exists:
      * UPDATE_TAIL_RE stops at the first quote, so in
        `SET status = 'done' WHERE ...` its reading never reaches the WHERE.
        Until round 5 such a statement was not checked at all (79 UPDATEs on
        the real tree on 14 September 2026).
      * That included a LOSS against the older version (commit 9c77216). Its
        SET pattern ran on past a WHERE with no space before it (`" . "WHERE`)
        and so checked each `name =` there by accident. The loss was 6 column
        tests in 4 real statements: web/_core/Workflow.php (three statements)
        and web/_apps/auth/account/delete-confirm.php.
      * Found by the independent check of round 4.

    Why the SET part follows a plain join but the WHERE does not:
      * the SET part must follow it, or `" . "WHERE` is never found. The
        cost: when the first string holds no WHERE at all, a "where" in
        ordinary text joined after it, as in
        `"UPDATE tblTasks SET status = 'done'" . " where yourColumn = 1"`, is
        taken as the WHERE, and "yourColumn" IS WRONGLY REPORTED (blind spot
        19). Neither the older version nor this check before round 5 reported
        it.
      * the WHERE reading following one was tried first and rejected:
        `"... WHERE taskID = ?" . "<br>"` WRONGLY REPORTED "br", because a
        plain join can lead into a string that is not SQL at all.
      * stopping there matches every other WHERE reading of a double-quoted
        string, and changed nothing on the real tree.

    Rejected:
      * letting the SET reading run on past WHERE, as the older pattern did by
        accident. SET_ASSIGN_RE would then read each `name =` in the WHERE as
        a SET column, so the 275 statements whose WHERE was already examined
        would report each wrong WHERE name twice, under two labels, and a
        name tested with IN or LIKE would still be missed;
      * using this walk for every `UPDATE tblX SET`. It would change how the
        already-examined statements are read, for no gain against the older
        version.

    What it cannot do:
      * it helps only `UPDATE tblX SET` with nothing but IGNORE between UPDATE
        and the table, and nothing between the table and SET.
      * still not reached: a SET part added at runtime; a multi-table or
        aliased UPDATE; a SET part whose reading ends first (a sub-query, a
        semicolon, ORDER, LIMIT, or a join with PHP code in it outside a
        value, such as `" . PHP_EOL . "WHERE`); an UPDATE whose SET is in a
        later string than its table name.
      * the WHERE is read only to the end of its PHP string, apart from a join
        inside a value or comment. So a name in a later string
        (`... WHERE taskID = ? " . "AND bogus = ?"`) is missed.
      * everything sql_regions() cannot do applies (blind spot 17), including
        the carry across a comma outside brackets inside a quoted value, which
        WRONGLY REPORTS a name.
      * here quoted values are blanked and read past, while a WHERE that the
        normal reading does reach still stops at its first quote
        (blind spot 2).
    """
    head = UPDATE_SET_HEAD_RE.match(text, start)
    if head is None:
        return None
    _set_body, stop, word = _read_clause(
        text, regions, head.end(), _SET_END_WORD_RE, follow_plain_joins=True
    )
    if word != "WHERE":
        return None
    where_body, _stop, end_word = _read_clause(
        text, regions, stop + len("WHERE"), _STATEMENT_WORD_RE,
        follow_plain_joins=False,
    )
    return where_body, end_word != ""


# Inside the SET clause: `colName` = expr  or  colName = expr
#
# The characters a name may not follow are the ones BARE_COMPARE_RE explains
# (below), for the same reasons, each reproduced here as a wrong report on
# 14 September 2026: "SET $col = ?" reported "col", "SET $this->col = ?"
# reported "col", sprintf('... SET %s = ?') reported "s", "SET {$k}ID = ?"
# reported "ID", and "@v = 1" reported "v". A dot is still allowed before the
# name, so `SET tblTasks.title = ?` is still checked.
#
# A backtick is matched as a PAIR: `(`)?(\w+)(?(1)`|(?!`))` means "if an
# opening backtick was taken, a closing one must follow; if not, no backtick
# may follow". Until 14 September 2026 each backtick was optional on its own,
# so in `due-date` = ? (a backticked name holding a hyphen or a space) the
# last part, "date", was taken with only the closing backtick and reported as
# a missing column. Such a name is now not read at all, which can only miss.
SET_ASSIGN_RE = re.compile(r"(?<![\w`@$%}])(?<!->)(`)?(\w+)(?(1)`|(?!`))\s*=")

# Match: SELECT col, col FROM `tblX` (no JOINs, no aliases — keeps the check
# precise but narrow). Skip SELECT * patterns.
SELECT_RE = re.compile(
    r"SELECT\s+(?!\*)([^\n]+?)\s+FROM\s+`?(tbl\w+)`?\s*"
    r"(?:WHERE|ORDER|GROUP|LIMIT|;|\s*$|\)|\s+(?:LEFT|INNER|RIGHT|OUTER|JOIN))",
    re.IGNORECASE,
)
# Inside the SELECT column list: identifier (possibly with AS alias)
SELECT_COL_RE = re.compile(r"`?(\w+)`?(?:\s+AS\s+\w+)?")

# -----------------------------------------------------------------------------
# DELETE statements
# -----------------------------------------------------------------------------
# Added after a real miss. A sweep that clears out old children's event
# registrations referred to `e.recurrenceRule` — a column that has never existed
# on tblEvents. Every check here passed, because none of them looked at a
# DELETE at all. A wrong column name in a DELETE does not fail when the code is
# written; it fails the first time the clear-out actually runs, in production,
# on the most sensitive table in the portal.
#
# Two shapes are checked, and only two, because they are the ones that can be
# read without guessing:
#
#   1. DELETE alias FROM tblX AS alias INNER JOIN tblY AS other ON …
#      Here every column is written as `alias.column`, which says exactly which
#      table it belongs to. Nothing has to be inferred.
#
#   2. DELETE FROM tblX WHERE column = ?
#      One table, no joins, so any bare name being compared against something
#      must be one of its columns.
#
# Anything more involved — a sub-query, a delete spanning several tables with
# unqualified names — is deliberately skipped rather than guessed at. A checker
# that cries wolf gets switched off, and then it protects nothing.

# The opening of a DELETE, up to the end of the statement. Stops at a closing
# PHP quote or a new statement, so one DELETE never swallows the next.
DELETE_RE = re.compile(
    r"DELETE\s+(?:(?:LOW_PRIORITY|QUICK|IGNORE)\s+)*"
    r"(?:[\w`, ]+?\s+)?"          # optional list of aliases to delete from
    # Everything from the table name to the end of the statement.
    #
    # It stops at the first quote of either kind, or a semicolon. That single
    # rule does three useful things at once:
    #
    #   * it ends the statement at the closing quote of the PHP string holding
    #     it, so one DELETE can never swallow the next, nor run on into
    #     ordinary PHP code that happens to follow;
    #   * it stops before any value written into the SQL as a literal, so a
    #     value is never mistaken for a column or a table name. A row matching
    #     on the text "tblSomething" used to be reported as an unknown table;
    #   * where SQL is written in double quotes instead, the opening quote sits
    #     before the word DELETE, so the whole statement is still read.
    #
    # The cost is that anything after the first literal goes unchecked. That is
    # the right way round to be wrong: a missed mistake is a missed
    # opportunity, while a wrong accusation gets the whole check switched off,
    # and then it protects nothing at all.
    r"FROM\s+(`?tbl\w+`?[^'\";]*?)"
    r"(?:'|\"|;|$)",
    re.IGNORECASE,
)

# Each table named in a FROM/JOIN (or UPDATE ... SET) run, with the short
# name given to it. The alias may itself be written in backticks - FROM
# `tblEvents` AS `e`. Without allowing for that, the short name was never
# recorded, and every column written against it then went unchecked rather
# than being reported.
#
# Named FROM_TABLE_RE (not DELETE_TABLE_RE) because SELECT and UPDATE reuse
# it too, below — it was written once for DELETE, but "which tables, and is
# any of them aliased" is exactly the same question for all three statement
# kinds, and a second hand-copied version of this regex is exactly the kind
# of place a check like this quietly drifts out of step with itself.
#
# The (?<![\w$]) at the start stops "tbl" being found in the MIDDLE of a
# word. Without it, the alias in `JOIN tblUsers mytblAssignee ON
# mytblAssignee.userID = ...` was read from its fourth letter as a table
# called "tblAssignee", and a PHP variable written into a double-quoted
# string (`JOIN $tblOther o`) as a table called "tblOther". Both were
# reported as unknown tables. A table name held in a PHP variable is not
# known until the page runs, so there is nothing to check there anyway.
# The (?<!\$\{) and (?<!->) do the same for the other two ways PHP writes a
# variable into a double-quoted string, `${tblOther}` and `$this->tblOther`.
# Both were still reported as an unknown table "tblOther" until the second fix
# of round 4 (14 September 2026).
#
# What this regex CANNOT do on its own: say whether it found EVERY table.
# It never sees `$tblOther`, `old_tblUsers`, or a table whose name does not
# start with tbl (`JOIN users`). So "it found exactly one table" does not
# mean the statement has only one. An earlier round trusted that and reported
# the other table's columns as missing from the first one. The decision to
# read bare column names is therefore made by names_one_plain_table() below,
# which looks at the whole table-naming text, not by counting these matches.
FROM_TABLE_RE = re.compile(
    r"(?<![\w$])(?<!\$\{)(?<!->)`?(tbl\w+)`?(?:\s+(?:AS\s+)?(?!ON\b|WHERE\b|SET\b|USING\b|LEFT\b"
    r"|RIGHT\b|INNER\b|OUTER\b|CROSS\b|JOIN\b|AND\b|OR\b)`?(\w+)`?)?",
    re.IGNORECASE,
)

# A short name the statement gives to ANY table, not only to a literal tbl...
# name, used by parse_from_tables() so that a short name is never taken for a
# table. Two forms:
#   1. after AS, whatever comes before it: `JOIN $tblOther AS tblAssignee`,
#      `JOIN (SELECT ...) AS tblAssignee`;
#   2. without AS, straight after the thing that names a table, where that
#      thing follows FROM, JOIN, a comma, or starts the text. The thing can be
#      a backticked name, a {$...} written into the string (with letters
#      straight after it), a PHP variable written any of the ways PHP allows
#      in a double-quoted string ($x, $x[1], $x[key], $x[$i], $this->x,
#      $this?->x, ${x}), a bracketed sub-query with at most one level of
#      brackets inside it, or a plain name (users, db.users, old_tblUsers).
# Added in round 4 (14 September 2026): before it, only a short name straight
# after a literal tbl... table counted, so `JOIN users AS tblAssignee ON
# tblAssignee.userID = ...` and the same after `$tblOther` or `{$t}` reported
# "tblAssignee" as an unknown table. The first version knew only $x and
# $this->x among the variable forms, so `JOIN $tables[1] tblAssignee`,
# `JOIN ${tbl} tblAssignee` and `JOIN $this?->t tblAssignee` still reported
# "tblAssignee"; the other forms were added in the second fix of round 4.
# Words that follow a table but are never a short name are excluded from form
# 2, so `FROM tblTasks JOIN ...` or `JOIN tblUzers ON ...` never makes JOIN
# or ON a "short name". A misspelt table cannot hide behind this: a word is
# only skipped as a short name where it is declared or used before a dot (see
# parse_from_tables()), never where it sits after FROM or JOIN as the table.
# What it cannot see: a short name after a sub-query nested more than one
# bracket deep without AS, or after anything else not listed; such a short
# name starting with tbl is still reported as an unknown table.
ALIAS_DECLARATION_RE = re.compile(
    r"\bAS\s+`?(\w+)`?"
    r"|(?:^|\b(?:FROM|JOIN)\b|,)\s*"
    r"(?:`[^`]*`(?:\s*\.\s*`[^`]*`)?"
    r"|\{[^{}]*\}\w*"
    r"|\$\{[^{}]*\}"
    r"|\$\w+(?:\[[^\]\s]*\]|\??->\w+)*"
    r"|\((?:[^()]|\([^()]*\))*\)"
    r"|[\w.$]+)"
    r"\s+(?!(?:AS|ON|USING|WHERE|SET|LEFT|RIGHT|INNER|OUTER|CROSS|JOIN|NATURAL"
    r"|STRAIGHT_JOIN|AND|OR|USE|IGNORE|FORCE|PARTITION|GROUP|ORDER|LIMIT|HAVING"
    r"|UNION|FOR|LOCK|WINDOW)\b)`?(\w+)`?",
    re.IGNORECASE,
)

# A column written as shortname.column — unambiguous, so safe to check.
# Backticks are allowed around either half, because `e`.`startDateTime` and
# e.startDateTime mean the same thing and both appear in real code. Without
# this, a backticked name was silently skipped rather than checked.
QUALIFIED_COL_RE = re.compile(
    r"(?<![\w.`])`?(\w+)`?\s*\.\s*`?(\w+)`?"
)

# A bare name being compared against something, for the single-table form.
# The @ is excluded on purpose: @something is a variable the database itself
# holds, not a column, and treating one as a column was a real wrong accusation
# (SET @cutoff := … ; WHERE @cutoff < startDateTime).
# The $ is excluded for the same kind of reason: in a double-quoted PHP string
# such as "SELECT title FROM tblTasks WHERE $column = ?", $column is a PHP
# variable whose value is only known when the page runs. Before the $ was
# excluded, the word "column" was reported as a missing column of tblTasks.
# The "->" is excluded for the same reason: "WHERE $this->column = ?" writes a
# PHP object's property into the string, and "column" was reported the same way.
# The % and } are excluded for the same reason again, both reproduced as wrong
# accusations on 14 September 2026: in sprintf('... WHERE %s = ?', $col) the
# "s" is a placeholder, and in "... WHERE {$kind}ID = ?" the "ID" is only the
# end of a name finished off at runtime. Each was reported as a missing column
# ("tblTasks.s", "tblTasks.ID"). Excluding a character can only make the check
# read less, never accuse more.
# Backticks are matched as a pair, as SET_ASSIGN_RE explains above: in
# WHERE `due-date` = ? the "date" used to be reported as a missing column.
# The name is in group 2 for the first form and group 4 for the second
# (groups 1 and 3 are the opening backticks).
BARE_COMPARE_RE = re.compile(
    r"(?<![\w.`@$%}])(?<!->)(`)?(\w+)(?(1)`|(?!`))\s*(?:=|<=|>=|<>|!=|<|>)"
    r"|(?<![\w.`@$%}])(?<!->)(`)?(\w+)(?(3)`|(?!`))\s+(?:IS|IN|LIKE|BETWEEN|NOT)\b",
    re.IGNORECASE,
)

# A collation name, as in `title COLLATE utf8mb4_bin NOT LIKE ?`. It is removed
# from the WHERE text before names are read, because the collation name sits
# directly before NOT / = / LIKE and was reported as a missing column
# ("tblTasks.utf8mb4_bin"). Collation names are too many to list in SQL_NOISE,
# so the word after COLLATE is dropped instead, whatever it is.
COLLATE_NAME_RE = re.compile(r"\bCOLLATE\s+`?\w+`?", re.IGNORECASE)

# Where a WHERE clause really ends. Everything from the first of these words
# onward is NOT part of the WHERE, and is cut off before any name is checked.
#
# Why: the captured text runs to the end of the PHP string, so it used to
# include whatever followed the WHERE. Two of those parts can legally compare
# names that are not columns of the table at all, and both produced wrong
# accusations in tests:
#   * HAVING / ORDER BY may use a name given in the column list, e.g.
#     `COUNT(*) AS openCount ... HAVING openCount > 5`;
#   * INSERT INTO tblA ... SELECT ... FROM tblB WHERE ... ON DUPLICATE KEY
#     UPDATE title = VALUES(title) — "title" there is tblA's column, and was
#     being checked against tblB.
# Cutting early can only make the check read less, never accuse more. A column
# genuinely called `limit` or `order` would end the reading early, and a fault
# after it would be missed. That is the safe direction to be wrong in.
WHERE_END_RE = re.compile(
    r"\b(?:GROUP\s+BY|HAVING|ORDER\s+BY|LIMIT|UNION|FOR\s+UPDATE|FOR\s+SHARE"
    r"|LOCK\s+IN\s+SHARE\s+MODE|ON\s+DUPLICATE\s+KEY\s+UPDATE)\b",
    re.IGNORECASE,
)

# Words that look like a column but are not one.
#
# The second and third groups were added on 14 September 2026, after each was
# reproduced as a wrong accusation once the WHERE scan was extended to SELECT
# and UPDATE (DELETE already had the same fault). MySQL lets the functions in
# the second group be written WITHOUT brackets, so in
# `WHERE CURRENT_TIMESTAMP > dueDate` the word sits exactly where a column name
# would, and "tblTasks.CURRENT_TIMESTAMP" was reported. The third group is the
# units an INTERVAL can take (`NOW() - INTERVAL 1 WEEK > createdAt`), taken
# from the MySQL manual's list of temporal intervals, plus the SOUNDS of
# `title SOUNDS LIKE ?`.
#
# This list cannot be proved complete — see blind spot 9 in the header.
SQL_NOISE = {
    "and", "or", "not", "null", "is", "in", "like", "between", "where", "order",
    "group", "limit", "by", "asc", "desc", "select", "from", "join", "on", "as",
    "set", "values", "case", "when", "then", "else", "end", "interval", "day",
    "days", "month", "year", "hour", "minute", "second", "now", "true", "false",
    "coalesce", "ifnull", "date_sub", "date_add", "exists", "distinct", "using",
    # MySQL functions that may be written without brackets.
    "current_timestamp", "current_date", "current_time", "current_user",
    "localtime", "localtimestamp", "utc_date", "utc_time", "utc_timestamp",
    # INTERVAL units not already above, and SOUNDS LIKE.
    "microsecond", "week", "quarter", "second_microsecond",
    "minute_microsecond", "minute_second", "hour_microsecond", "hour_second",
    "hour_minute", "day_microsecond", "day_second", "day_minute", "day_hour",
    "year_month", "sounds",
}

# A number written into the SQL. Any word that is one of these is a value, not
# a column name, and is never checked.
#
# Before 14 September 2026 the test was Python's str.isdigit(), which only
# knows plain whole numbers. `WHERE 1e3 < taskID` (scientific notation) was
# reported as a missing column "1e3", and `0x1F < taskID` and
# `0b101 < taskID` the same way. The forms below are the ones MySQL accepts as
# numbers. Each was confirmed on a real MySQL 8.0.36 server that day:
#
#   12   1.5   3.   .5   1e3   1E+3   2e-1   1.e3   .5e1   0x1F   0b101
#   X'1F'   x'1f'   b'101'   B'101'
#
# Upper-case 0X1F and 0B101, and 1e with no digits after the e, are NOT here,
# on purpose. The same server treated each of them as a column name and
# answered "Unknown column '0X1F'" (and the same for the others), so a missing
# one really is a fault worth reporting.
#
# The quoted forms (X'1F', b'101') can never reach this test today: the WHERE
# readings stop at a quote, and the UPDATE SET reading blanks every quoted
# value to '' before names are looked for. They are listed so this stays a true
# description of MySQL's numbers if that ever changes.
NUMBER_LITERAL_RE = re.compile(
    r"(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?"
    r"|0x[0-9a-fA-F]+|0b[01]+"
    r"|[xX]'[0-9a-fA-F]*'|[bB]'[01]*'"
)


def is_number_literal(word: str) -> bool:
    """True when `word` is a number as MySQL reads it (see NUMBER_LITERAL_RE)."""
    return NUMBER_LITERAL_RE.fullmatch(word) is not None


# -----------------------------------------------------------------------------
# A map of each PHP file: which characters are SQL, and which parts of that
# SQL are quoted values or comments
# -----------------------------------------------------------------------------
# Added in round 4 (14 September 2026). Every pattern in this script searches
# the whole file, so on its own it cannot tell a real statement from the same
# words sitting somewhere that never runs as SQL. Each of these was a real
# wrong report before the map existed:
#
#   * SQL inside a quoted SQL value. In
#         "SELECT * FROM tblTasks WHERE title = 'SELECT * FROM tblTasks WHERE bogusName = 1'"
#     the reading of the outer statement stopped at the quote, but the search
#     then found the SELECT inside the value, examined it as a second
#     statement, reported "bogusName", and counted two examined statements
#     where there is one.
#   * An escaped quote inside a value (`'it''s'`, `'it\'s'`), which the old
#     UPDATE SET reading took for the end of the value; see read_set_part().
#   * A statement inside an SQL comment with a quote in the comment before it
#     (`-- don't use: SELECT oldCol ...`), and a query commented out with a
#     PHP # comment. The earlier test for "inside a comment" looked only at
#     the text on the same line, and a quote fooled it.
#
# So each file is now walked once, the way PHP and then MySQL would read it,
# and every character gets one of the codes below. A statement is read unless
# its first word is NOT_RUN, SQL_VALUE or SQL_COMMENT (starts_in_unread_text(),
# applied through live_matches()). So one starting in SQL_TEXT, PHP_CODE, a
# {$...} (PHP_IN_STRING) or a join (PHP_JOIN) IS read. (Until fix round 3 of
# round 4 this comment said "only SQL_TEXT or PHP_CODE", which was wrong; the
# code has always read the other two as well.) The UPDATE SET reading and the
# comment removal use the same map, so there is one definition of "quoted
# value" and "comment" in this script, not three. The SELECT and UPDATE WHERE
# scans also skip a statement that follows other text in its string
# (follows_non_sql_text(), round 5; blind spot 19).

PHP_CODE = 0       # PHP code, including the quotes that open and close strings
NOT_RUN = 1        # a PHP comment, or text outside <?php ... ?>
SQL_TEXT = 2       # inside a PHP string, and not a quoted value or comment
SQL_VALUE = 3      # a quoted SQL value, 'like this' or "like this", quotes included
SQL_COMMENT = 4    # an SQL comment inside a PHP string
PHP_IN_STRING = 5  # a {$...} written into a double-quoted string or heredoc
PHP_JOIN = 6       # PHP between two strings joined with dots, whose SQL runs on

_PHP_OPEN_RE = re.compile(r"<\?(?:php(?!\w)|=)", re.IGNORECASE)
# The next thing in PHP code that changes how the following text is read.
# `#[` is a PHP 8 attribute, not a comment.
_PHP_NEXT_RE = re.compile(r"['\"`]|#(?!\[)|//|/\*|\?>|<<<")
_HEREDOC_OPEN_RE = re.compile(r"<<<[ \t]*([\"']?)([A-Za-z_]\w*)\1\r?\n")
# The body of a single-quoted PHP string: up to the next quote that is not
# escaped by a backslash.
_SQ_BODY_RE = re.compile(r"[^'\\]*(?:\\.[^'\\]*)*", re.DOTALL)
_DQ_NEXT_RE = re.compile(r"\\.|\{\$|\"", re.DOTALL)
_SHELL_NEXT_RE = re.compile(r"\\.|`", re.DOTALL)
_INSERT_NEXT_RE = re.compile(r"\\.|\{\$", re.DOTALL)
_BRACE_OR_STRING_RE = re.compile(
    r"[{}]|'[^'\\]*(?:\\.[^'\\]*)*'|\"[^\"\\]*(?:\\.[^\"\\]*)*\"", re.DOTALL
)
# PHP between two strings across which the SQL carries on: it starts with a
# dot and ends with a dot, with no semicolon in between. `' . $x . '`,
# `' . f($y, $z) . '`, `' . ($c ? $a : $b) . '`, `' . PHP_EOL . '` and a plain
# `' . '` all qualify. PHP that does not start and end with a dot (a comma
# between two string arguments, `.=` after a semicolon) means the next string
# may be a different piece of text, so that string is read from a fresh start.
#
# What it cannot do: it does not know PHP's order of operations. Anything PHP
# applies after the dots still carries: a comma or `=>` between two dots
# (`. g($a), h($b) .` is two arguments, not one join), a ternary's `?` or `:`
# outside brackets, a comparison, &, ^, |, &&, ||, ??, and, xor, or. Until
# round 5 this comment said a comma or a `?` stopped it, which was never true
# (blind spot 17 has the wrong reports this causes). Refusing a comma or `?`
# outside brackets, or a bracket closing below its start, was tried in round 5
# and rejected. It changed nothing on the real tree, but every other such
# operator would still carry, so the rule would still be wrong, only less
# visibly. The bracket rule would also be wrong for `trim('...' . $x) . '...'`,
# where carrying on is right. Doing this properly needs a PHP parser.
_CARRY_RE = re.compile(r"\s*\.(?:[^;]*\.)?\s*")
# Characters that can change the SQL reading. A string with none of them, read
# from a fresh start, is plain SQL text and needs no closer look.
_SQL_SPECIAL_RE = re.compile(r"--|['\"`#\\]|/\*")
_PHP_ESCAPE_RE = re.compile(
    r"\\(?:([0-7]{1,3})|x([0-9A-Fa-f]{1,2})|u\{([0-9A-Fa-f]{1,6})\}|(.))", re.DOTALL
)
_PHP_SIMPLE_ESCAPES = {
    "n": "\n", "t": "\t", "r": "\r", "v": "\v", "e": "\x1b", "f": "\f",
    "\\": "\\", "$": "$", '"': '"',
}


def _fill(regions: bytearray, start: int, end: int, code: int) -> None:
    if end > start:
        regions[start:end] = bytes((code,)) * (end - start)


def _insert_end(text: str, start: int) -> int:
    """The index just after the `}` closing a `{$...}` that starts at `start`."""
    depth = 0
    for token in _BRACE_OR_STRING_RE.finditer(text, start):
        if token.group() == "{":
            depth += 1
        elif token.group() == "}":
            depth -= 1
            if depth == 0:
                return token.end()
    return len(text)


def _decode_php_string(
    text: str, start: int, end: int, kind: str, inserts: dict[int, int]
) -> tuple[list[str | None], list[int]]:
    """
    The characters of a PHP string as they are when the page runs, each with
    the position in `text` where it starts (plus `end`, as a last entry). A
    `{$...}` becomes None: its value is only known at runtime.

    Why decode at all: the same written characters mean different SQL in
    different kinds of PHP string. `\\'` is a lone quote in a single-quoted
    string, but stays a backslash and a quote (an escaped quote, to MySQL) in a
    double-quoted one or a heredoc. `\\n` is a line break (which ends an SQL
    `--` comment) in a double-quoted string, but a backslash and an n in a
    single-quoted one. Checked on PHP 8.5 on 14 September 2026, including that
    a heredoc keeps `\\"` as two characters.
    """
    chars: list[str | None] = []
    starts: list[int] = []
    p = start
    while p < end:
        if p in inserts:
            chars.append(None)
            starts.append(p)
            p = inserts[p]
            continue
        ch = text[p]
        if ch == "\\" and p + 1 < end and kind != "nowdoc":
            if kind == "sq":
                if text[p + 1] in "\\'":
                    chars.append(text[p + 1])
                    starts.append(p)
                    p += 2
                    continue
            else:
                esc = _PHP_ESCAPE_RE.match(text, p, end)
                value = None
                if esc.group(1):
                    value = chr(int(esc.group(1), 8) & 0xFF)
                elif esc.group(2):
                    value = chr(int(esc.group(2), 16))
                elif esc.group(3) and int(esc.group(3), 16) <= 0x10FFFF:
                    value = chr(int(esc.group(3), 16))
                elif esc.group(4) and not (kind == "heredoc" and esc.group(4) == '"'):
                    value = _PHP_SIMPLE_ESCAPES.get(esc.group(4))
                if value is not None:
                    chars.append(value)
                    starts.append(p)
                    p = esc.end()
                    continue
        chars.append(ch)
        starts.append(p)
        p += 1
    starts.append(end)
    return chars, starts


def _read_sql_in_string(
    text: str, start: int, end: int, kind: str, inserts: dict[int, int],
    state: str, regions: bytearray, escapes: list[tuple[int, int, str]],
) -> str:
    """
    Give every character of one PHP string's contents its SQL code, starting in
    `state`, and return the state at the end (so it can carry on into a string
    joined after this one). Every PHP escape in the string (`\\n`, `\\x27`,
    `\\'` in a single-quoted string, ...) is also added to `escapes` as (where
    it starts, where it ends, the character it stands for), for
    readable_sql_text().

    States: "text"; "'", '"' or "`" (inside a quoted value or backticked name);
    "--" (a `--` or `#` comment, to the end of the line); "/*".

    MySQL's rules, as checked on a MySQL 8.0.36 server in round 2:
      * inside '...' or "...", a backslash keeps the next character in the
        value, and the quote written twice ('' or "") is one quote, not the end;
      * inside `...`, a doubled backtick is one backtick; there are no
        backslash escapes;
      * `#` starts a comment to the end of the line; so does `--`, unless a
        visible ASCII character ("!" to "~") follows straight away (`--bogus`
        is two minus signs to MySQL and reported "Unknown column 'bogus'"). A
        space, tab, line break, other control character, DEL, any non-ASCII
        character, or the end of the string after `--` starts one here. (After
        a non-ASCII character MySQL's answer depends on the connection's
        character set: over utf8mb4 neither a non-breaking space nor é started
        one. Treating all of them as a comment can only make the check read
        less.)
      * `/* ... */` is a comment. `/*! ... */`, which MySQL does run, is
        treated as a comment too.
    It assumes MySQL's default modes. Under ANSI_QUOTES a "..." is a name, not
    a value, and under NO_BACKSLASH_ESCAPES a backslash escapes nothing; the
    portal sets neither (nothing in web/_core or web/_install sets sql_mode).
    """
    if state == "text" and not _SQL_SPECIAL_RE.search(text, start, end):
        _fill(regions, start, end, SQL_TEXT)
        for a, b in inserts.items():
            _fill(regions, a, b, PHP_IN_STRING)
        return "text"
    chars, starts = _decode_php_string(text, start, end, kind, inserts)
    for k, c in enumerate(chars):
        # A decoded character written with more than one character is an
        # escape. (A {$...} is also wider than one character, but is None.)
        if c is not None and starts[k + 1] - starts[k] > 1:
            escapes.append((starts[k], starts[k + 1], c))
    codes = bytearray(len(chars))
    m = len(chars)
    k = 0
    while k < m:
        c = chars[k]
        nxt = chars[k + 1] if k + 1 < m else ""
        width = 1
        if state == "text":
            if c is None:
                code = PHP_IN_STRING
            elif c in "'\"":
                state, code = c, SQL_VALUE
            elif c == "`":
                state, code = "`", SQL_TEXT
            elif c == "#":
                state, code = "--", SQL_COMMENT
            elif c == "-" and nxt == "-" and (
                k + 2 >= m
                or (chars[k + 2] is not None and not "!" <= chars[k + 2] <= "~")
            ):
                # A {$...} straight after `--` is taken as NOT starting a
                # comment: its value is unknown, and a number or name there
                # would make the dashes two minus signs.
                state, code, width = "--", SQL_COMMENT, 2
            elif c == "/" and nxt == "*":
                state, code, width = "/*", SQL_COMMENT, 2
            else:
                code = SQL_TEXT
        elif state in ("'", '"'):
            code = SQL_VALUE
            if c == "\\" and k + 1 < m:
                width = 2
            elif c == state:
                if nxt == state:
                    width = 2
                else:
                    state = "text"
        elif state == "`":
            code = SQL_TEXT
            if c == "`":
                if nxt == "`":
                    width = 2
                else:
                    state = "text"
        elif state == "--":
            if c == "\n":
                # The line break ends the comment and is kept as text, so a
                # pattern that reads one line at a time still sees two lines.
                state, code = "text", SQL_TEXT
            else:
                code = SQL_COMMENT
        else:  # "/*"
            code = SQL_COMMENT
            if c == "*" and nxt == "/":
                state, width = "text", 2
        for w in range(width):
            codes[k + w] = code
        k += width
    run = 0
    for k in range(1, m + 1):
        if k == m or codes[k] != codes[run]:
            _fill(regions, starts[run], starts[k], codes[run])
            run = k
    return state


def sql_regions(text: str) -> tuple[bytearray, str]:
    """
    Walk a PHP file (after reconstruct_php_strings()) and give every character
    one of the codes above. See the comment block above for why. Returns that
    map and the readable copy of the text (readable_sql_text()), both exactly
    as long as `text`.

    PHP's side: text outside `<?php` / `<?=` ... `?>` is NOT_RUN, and so are
    `#`, `//` and `/* */` comments (a `#` or `//` comment ends at the line
    break or at `?>`, as in PHP). Single-quoted, double-quoted, heredoc and
    nowdoc strings are read as SQL (_read_sql_in_string()); a backtick string
    runs a shell command in PHP and is left as PHP_CODE.

    Joins: SQL is often split over several PHP strings. When two strings are
    joined by PHP that starts and ends with a dot (_CARRY_RE), the second one
    carries on in the state the first ended in, so in
        "title = '" . $mysqli->real_escape_string($t) . "', status = ?"
    the second string starts inside the quoted value and its first quote ends
    it. The PHP between them is marked PHP_JOIN. A PHP comment between them
    does not break the join (PHP ignores it) and stays NOT_RUN. A line
    comment carried across PHP_EOL is ended, since PHP_EOL is a line break.

    What it cannot do (blind spot 17 in the header):
      * SQL built by other PHP (sprintf, implode, arrays, `.=` on another
        line) is read string by string, each from a fresh start, so it does
        not know when a string actually begins inside a value or comment at
        runtime; and PHP between two dots that PHP does not really join
        across (a comma or `?` outside brackets, ==, &&, ...) is carried
        across as if it did (see _CARRY_RE);
      * the short `<?` opening tag is not recognised (text after it is taken
        as not PHP), and `__halt_compiler` is not either;
      * reconstruct_php_strings() runs first and joins '...' . '...' even
        where that text is itself inside another string or a comment, which
        can change what this walk sees;
      * a PHP syntax error, such as a string that is never closed, makes the
        rest of the file look like that string;
      * a backtick string (a shell command) is not mapped: it stays PHP_CODE,
        so a statement in it is read as live and its quotes are not values;
      * the PHP code inside a {$...} is not walked: it is all PHP_IN_STRING,
        so a quoted value or comment in a statement written there is not
        recognised.
    """
    n = len(text)
    regions = bytearray(n)
    escapes: list[tuple[int, int, str]] = []
    i = 0
    while i < n:
        opener = _PHP_OPEN_RE.search(text, i)
        stop = opener.start() if opener else n
        _fill(regions, i, stop, NOT_RUN)
        if opener is None:
            break
        i = opener.end()
        # Where the previous string's contents ended, where its closing quote
        # ended, and the SQL state it ended in. None before the first string
        # and after a backtick string; leaving PHP starts this afresh too.
        last_end: int | None = None
        last_after = 0
        last_state = "text"
        while i < n:
            token = _PHP_NEXT_RE.search(text, i)
            if token is None:
                i = n
                break
            j, tok = token.start(), token.group()
            if tok == "?>":
                i = token.end()
                break
            if tok in ("#", "//"):
                eol = text.find("\n", j)
                eol = n if eol == -1 else eol
                close = text.find("?>", j, eol)
                end = eol if close == -1 else close
                # A comment does NOT break a join: PHP ignores it, and
                # web/_apps/auth/account/delete-confirm.php has one in the
                # middle of the joined UPDATE that anonymises a deleted
                # member. The first draft of this walk treated a comment as
                # the end of the join, and 6 real SET columns there went
                # unchecked (found by comparing every SET name read on the
                # whole tree with round 3's reading).
                _fill(regions, j, end, NOT_RUN)
                i = end
                continue
            if tok == "/*":
                close = text.find("*/", j + 2)
                end = n if close == -1 else close + 2
                _fill(regions, j, end, NOT_RUN)
                i = end
                continue
            inserts: dict[int, int] = {}
            if tok == "<<<":
                head = _HEREDOC_OPEN_RE.match(text, j)
                if head is None:
                    i = j + 3
                    continue
                kind = "nowdoc" if head.group(1) == "'" else "heredoc"
                start = head.end()
                closing = re.compile(
                    r"^[ \t]*" + re.escape(head.group(2)) + r"(?!\w)", re.MULTILINE
                ).search(text, start)
                if closing is None:
                    end = after = n
                else:
                    after = closing.end()
                    end = max(start, closing.start() - 1)
                    if end > start and text[end - 1] == "\r":
                        end -= 1
                if kind == "heredoc":
                    for piece in _INSERT_NEXT_RE.finditer(text, start, end):
                        if piece.group() == "{$" and piece.start() >= max(inserts.values(), default=0):
                            inserts[piece.start()] = min(_insert_end(text, piece.start()), end)
            elif tok == "'":
                kind, start = "sq", j + 1
                end = _SQ_BODY_RE.match(text, start).end()
                after = min(end + 1, n)
            else:
                kind, start = ("dq" if tok == '"' else "shell"), j + 1
                pattern = _DQ_NEXT_RE if tok == '"' else _SHELL_NEXT_RE
                p = start
                end = n
                while True:
                    piece = pattern.search(text, p)
                    if piece is None:
                        break
                    if piece.group() == tok:
                        end = piece.start()
                        break
                    if piece.group() == "{$":
                        p = _insert_end(text, piece.start())
                        inserts[piece.start()] = p
                    else:
                        p = piece.end()
                after = min(end + 1, n)
            if kind == "shell":
                i, last_end = after, None
                continue
            state = "text"
            if last_end is not None:
                # The PHP between the two strings, without its comments.
                between = text[last_after:j]
                if NOT_RUN in regions[last_after:j]:
                    between = "".join(
                        ch for ch, code in zip(between, regions[last_after:j])
                        if code != NOT_RUN
                    )
                if _CARRY_RE.fullmatch(between):
                    state = last_state
                    if state == "--" and "PHP_EOL" in between:
                        state = "text"
                    for k in range(last_end, start):
                        if regions[k] != NOT_RUN:
                            regions[k] = PHP_JOIN
            state = _read_sql_in_string(
                text, start, end, kind, inserts, state, regions, escapes
            )
            last_end, last_after, last_state = end, after, state
            i = after
    return regions, readable_sql_text(text, escapes)


_SQL_WHITESPACE = " \t\n\r\v\f"


def readable_sql_text(text: str, escapes: list[tuple[int, int, str]]) -> str:
    """
    A copy of `text`, exactly as long, with every PHP escape inside a string
    replaced by what it stands for, so the patterns that read names see the SQL
    the page really sends. Every reading of a statement uses this copy.

    Why: until the second fix of round 4 (14 September 2026) the readings used
    the text as written, and a PHP escape sat between the words:
      * in "UPDATE tblTasks SET title = ?,\\nstatus = ? ...", the \\n is a line
        break when the page runs, but the reading saw a backslash and then the
        word "nstatus", and WRONGLY REPORTED it. The same happened with \\t,
        \\r and \\x20, in the SET part and in every WHERE reading (SELECT,
        UPDATE, DELETE);
      * in "... WHERE title = \\x27a = b\\x27 AND taskID = ?", \\x27 is a quote,
        but the WHERE readings stop only at a real quote character, so they
        read on through the value and WRONGLY REPORTED "x27a". SQL inside such
        a value (\\x27SELECT ... WHERE bogus = 1\\x27) was reported against the
        outer statement. \\047 and \\u{27} did the same.
    The map (sql_regions()) already decoded these escapes; only the readings
    did not.

    Each escape, `width` characters long, becomes:
      * a space, tab, line break or other SQL white space: `width` spaces (a
        written \\n becomes spaces, not a line break, so every reported line
        number stays the line in the file);
      * a letter, digit or underscore (`\\x61` for "a"): the whole word it sits
        in is rebuilt as the page sends it, followed by enough spaces to keep
        the length, so `st\\x61tus = ?` becomes `status    = ?` and
        `H\\x41VING` becomes `HAVING   `. Only that word moves, so every other
        position stays where it was, and the map still lines up (a word never
        crosses from one kind of region into another: quotes, comment marks
        and `{$...}` all end a word).
        Rejected, in order:
          - `}` over the escape alone (first draft of fix round 2): "st" and
            "tus" were left as words, so `a.st\\x61tus` still read "st";
          - `}` over the whole word (fix round 2): the word was then not read
            at all. For a column name that only missed a fault, but for an SQL
            KEYWORD it also removed everything that keyword protects, and
            WRONGLY REPORTED correct queries (verifier of fix round 2, 14
            September 2026): `... H\\x41VING openCount > 5` reported
            "openCount", because the cut at HAVING never happened; `JOIN
            tblUsers \\x41S tblAssignee ON tblAssignee.userID ...` reported an
            unknown table "tblAssignee"; `C\\x4fLLATE utf8mb4_bin` reported
            "utf8mb4_bin"; `\\x4fRDER BY n > 1` reported "n";
      * anything else (a quote, `$`, a backslash, a bracket, ...): that
        character, then `}` for the rest of the width. The `}` keeps a name
        straight after it unread, so `\\$col = ?` (a MySQL name "$col") is not
        read as "col", just as `$col` is not.
    Only characters inside PHP strings change; PHP code, comments and text
    outside PHP are copied as they are.

    What it cannot do (blind spot 18):
      * a name split across two joined PHP strings where the first part ends
        in an escaped letter (`"SET st\\x61" . "tus = ?"`) has the spaces
        between its two parts, so "tus" is read on its own and WRONGLY
        REPORTED. Spaces before the word were rejected: they break the
        opposite split and separate a name from a `$`, `}` or `.` that must
        stop it being read;
      * a name straight after an escaped character other than white space is
        not read, and neither is a rebuilt name in backticks that also holds
        a doubled backtick. Both can only miss a fault.
    """
    if not escapes:
        return text
    pieces: list[str] = []
    word_escapes: list[tuple[int, int]] = []
    last = 0
    for start, end, ch in escapes:
        width = end - start
        pieces.append(text[last:start])
        if ch in _SQL_WHITESPACE:
            pieces.append(" " * width)
        elif ch == "_" or ch.isalnum():
            # The character itself, then filler that the word rebuild below
            # drops. Which positions are filler is recorded, rather than using
            # a special filler character, because any character could also
            # appear in the file itself.
            pieces.append(ch + " " * (width - 1))
            word_escapes.append((start, end))
        else:
            pieces.append(ch + "}" * (width - 1))
        last = end
    pieces.append(text[last:])
    readable = "".join(pieces)
    if not word_escapes:
        return readable
    chars = list(readable)
    filler = bytearray(len(chars))
    for start, end in word_escapes:
        filler[start + 1:end] = b"\x01" * (end - start - 1)

    def in_word(k: int) -> bool:
        return filler[k] == 1 or chars[k] == "_" or chars[k].isalnum()

    for start, end in word_escapes:
        if filler[start + 1] != 1:
            continue  # already rebuilt, as part of an earlier escape's word
        while start > 0 and in_word(start - 1):
            start -= 1
        while end < len(chars) and in_word(end):
            end += 1
        word = "".join(chars[k] for k in range(start, end) if filler[k] != 1)
        # A name in backticks keeps its closing backtick straight after it,
        # with the padding outside: `status`   rather than `status   `. With the
        # padding inside, the name was not read (a backtick is matched as a
        # pair, see SET_ASSIGN_RE), and a backticked short name used before a
        # dot (`tbl\x41ssignee`.userID) was reported as an unknown table. A
        # doubled closing backtick (part of the name itself) is left alone.
        if (
            start > 0 and chars[start - 1] == "`"
            and end < len(chars) and chars[end] == "`"
            and (end + 1 == len(chars) or chars[end + 1] != "`")
        ):
            word += "`"
            end += 1
        chars[start:end] = word + " " * (end - start - len(word))
        filler[start:end] = bytes(end - start)
    return "".join(chars)


def starts_in_unread_text(regions: bytearray, start: int) -> bool:
    """
    True when a statement whose first word is at `start` never runs as SQL:
    it is inside a quoted SQL value, an SQL comment, a PHP comment, or text
    outside PHP. Every one of the six readings asks this, through
    live_matches(), before anything is counted, so a skipped statement is in
    no coverage figure. A statement starting anywhere else, including inside
    a {$...} written into a string, is read (blind spot 17 says what that
    costs).

    Before round 4 only a comment was tested, by looking for `#` or `--`
    earlier on the same line with no quote in between. A quote inside the
    comment (`-- don't use: SELECT oldCol ...`) defeated it, a statement inside
    a quoted value was not tested at all, and a written \\n (which ends a
    comment when the page runs) was not seen, so a real statement after it
    was skipped.
    """
    return regions[start] in (NOT_RUN, SQL_VALUE, SQL_COMMENT)


# The fixed word each statement pattern begins with (INSERT, UPDATE, SELECT or
# DELETE) is read from the pattern itself by live_matches(); this finds it.
_LEADING_WORD_RE = re.compile(r"[A-Za-z]+")

# Words that may come straight before the first word of a statement that
# really is SQL: MySQL's set operators (UNION, EXCEPT, INTERSECT, each of
# which MySQL 8.0.31 and later accepts before SELECT) and the ALL or DISTINCT
# that may follow one of them.
_WORDS_BEFORE_A_STATEMENT = {"UNION", "EXCEPT", "INTERSECT", "ALL", "DISTINCT"}


def follows_non_sql_text(text: str, regions: bytearray, start: int) -> bool:
    """
    True when the statement whose first word is at `start` sits in the middle
    of text that is not SQL at all — help text, HTML, anything else written
    into a PHP string that happens to hold the word SELECT, UPDATE, INSERT or
    DELETE. The SELECT and UPDATE WHERE scans use this to skip such text;
    see blind spot 19 in the header.

    The rule: look back from `start`, past white space, SQL comments, PHP
    comments and joins. The statement at `start` is read as real SQL only when
    the first thing found that way is:
      * the start of the PHP string, or of the file, or a `{$...}` written
        into a string;
      * "(", ")" or ";";
      * one of the words in _WORDS_BEFORE_A_STATEMENT.
    Anything else — a letter, digit, comma, quote, or any other character —
    means the word at `start` is not really the start of a statement, so this
    returns True and the caller skips it.

    Why this exists: `echo '<p>Example: SELECT * FROM tblTasks WHERE
    yourColumn = 1</p>';` WRONGLY REPORTED "p" and "yourColumn". The older
    version (commit 9c77216) had no WHERE scans and reported nothing. Found by
    the independent check of round 4.

    Why only the SELECT and UPDATE WHERE scans use this: giving it to the
    other four readings would make them miss faults the older version caught,
    such as the column list of `INSERT INTO tblArchive SELECT title, bogus
    FROM tblTasks ...`, where the SELECT genuinely follows other SQL, not
    prose.

    Rejected:
      * an HTML-tag test: it misses prose with no tags, and `<` is also a
        valid SQL comparison, so it would also skip real SQL;
      * requiring every statement to start its own PHP string: that loses
        `(SELECT`, `UNION SELECT` and `INSERT ... (cols) SELECT`, all of which
        the older version checked;
      * a first-draft word list without EXCEPT and INTERSECT: it missed a
        fault straight after `EXCEPT SELECT` that the scan caught before this
        function existed.

    What it cannot do: see blind spot 19 in the header for the shapes this
    still gets wrong in both directions.

    Cost: it looks back only over the white space, comments and joins straight
    before the statement, so it costs nothing on the text that follows.
    """
    k = start - 1
    while k >= 0 and (
        regions[k] in (SQL_COMMENT, NOT_RUN, PHP_JOIN)
        or (regions[k] == SQL_TEXT and text[k].isspace())
    ):
        k -= 1
    if k < 0 or regions[k] in (PHP_CODE, PHP_IN_STRING):
        return False
    if regions[k] != SQL_TEXT:
        return True
    if text[k] in "();":
        return False
    if text[k].isalnum() or text[k] == "_":
        j = k
        while j >= 0 and regions[j] == SQL_TEXT and (text[j].isalnum() or text[j] == "_"):
            j -= 1
        return text[j + 1:k + 1].upper() not in _WORDS_BEFORE_A_STATEMENT
    return True


def live_matches(
    pattern: re.Pattern[str], text: str, regions: bytearray
) -> Iterator[re.Match[str]]:
    """
    Every match of `pattern` in `text` whose first word runs as SQL (see
    starts_in_unread_text()). All six readings search with this, never with
    pattern.finditer() directly.

    Why not finditer() plus a skip: finditer() carries on from the END of
    each match, including a match it is about to skip. Most of these patterns
    read on through line breaks until a quote or a semicolon, so a statement
    written inside a comment swallowed everything after it in the same PHP
    string, and a real statement there was never looked at and appeared in
    no coverage figure. In
        $sql = <<<SQL
        -- was: SELECT * FROM tblTasks
        SELECT * FROM tblTasks WHERE bogusName = ?
        SQL;
    the match started at the commented SELECT, ran to the end of the heredoc,
    was skipped, and "bogusName" was missed. The same happened after a
    commented DELETE, UPDATE or INSERT, in a multi-line quoted string, and
    after a PHP `#` comment holding a statement. Found by the verifier of
    round 4, fix round 2 (14 September 2026). The older version (commit
    9c77216), round 3 and the first two fixes of round 4 all missed it.

    How it searches: it looks for the pattern's first word from where the
    last reading ended. At a place whose first character never runs as SQL
    it does NOT try the pattern, and looks for the word again from one
    character on. Where the word does run as SQL it tries the whole pattern
    there: if that fails it again moves on by one character; if it matches,
    the match is given out and the search carries on from the end of that
    match, exactly as finditer() does, so nothing that was read is read
    twice or differently. Moving on by ONE character, and not to the end of
    the word, matters only when the first word can overlap itself: with
    `ABAB\\s` on "ABABAB ", the copy of ABAB that starts at the third
    character is found only that way. (Round 5 took the places from
    finditer() on the word, which returns only copies that do not overlap,
    so that match was never tried, and this docstring's promise rested on a
    condition nobody had written down. None of the six patterns' first
    words, INSERT, UPDATE, SELECT and DELETE, can overlap itself, so nothing
    the check read changed; found by the stand-in review of round 5, fixed
    in round 6.) A pattern that starts with a fixed word can only match
    where that word is, and none of the six patterns starts with \\b, a
    look-behind or ^, so whether it matches at a place does not depend on
    where the search began. The results are therefore the same as a search
    that restarts one character after every skipped match, for any pattern
    that starts with one fixed word and has no top-level `|`. The guard
    below checks only the first of those two (see "What it cannot do").

    Rejected, in order:
      * finditer() plus a skip: it hid real statements, as described above.
      * searching again from one character after the start of a skipped
        match (fix round 3 of round 4). The results were right, but each
        commented-out statement in a long heredoc was still read to the end
        of the heredoc before it was skipped, so the time grew with the
        square of their number: about 3.4 seconds of CPU for a heredoc of
        3,000 commented-out SELECTs (the older version, commit 9c77216: under
        0.01 s), and about 3.1 seconds for 3,000 SELECTs inside one quoted
        value. Found by the independent check of round 4.
      * taking the places from finditer() on the first word alone (round 5):
        it returns only copies that do not overlap, so a first word that can
        overlap itself (ABAB, EXECUTE) could miss a statement. Two other ways
        out were tried in round 6. Refusing such a word in the guard keeps a
        limit that costs nothing to remove. A look-ahead that consumes
        nothing, `(?=WORD)`, finds overlapping copies too, but the regex
        engine cannot use its fast scan for a literal first character on it:
        the whole tree took about 15% longer (1.06 s against 0.92 s on 16
        September 2026, fastest of 5), and a 2 MB file with no statement
        word at all about 40% longer. Restarting the literal search from
        one character on, as now, took the same time as round 5.

    What it cannot do:
      * it only saves the time spent on places that never run as SQL. A place
        that runs as SQL but does not match, and a statement that is read,
        cost what they always did; so does everything the caller then does
        with each statement. A single file holding thousands of real
        statements still takes time that grows faster than its length (6,000
        semicolon-separated UPDATEs in one heredoc: about 0.55 s of CPU, the
        same as before round 5).
      * a statement that is read and then turns out to be unrecognisable
        (for example its only FROM was inside a comment) still ends where
        its reading ended. A real statement after it in the same string
        would need a semicolon between them to be valid SQL, and a semicolon
        ends every reading.
      * the pattern must start with a plain word followed by a backslash
        escape, or this raises ValueError the first time it is used. That
        guard reads only the start of the pattern. A pattern with an
        alternative at its top level, such as `SELECT\\s+x|UPDATE\\s+y`,
        passes it, but only SELECT is searched for, so every UPDATE match is
        silently lost. None of the six patterns has one; any new pattern
        given to this function must start with one fixed word and have no
        top-level `|`.
        The search always moves at least one character on after a match, so
        even a pattern that can match nothing, such as `SELECT\\s|`, cannot
        make it loop for ever (the first draft of round 6 could); the
        matches it gives for such a pattern are still meaningless.
    """
    first = _LEADING_WORD_RE.match(pattern.pattern)
    if first is None or pattern.pattern[first.end():first.end() + 1] != "\\":
        raise ValueError(
            "live_matches() needs a pattern that starts with a plain word "
            "followed by a backslash escape, such as SELECT\\s+: "
            + pattern.pattern[:40]
        )
    # Searched with the pattern's own flags, so it finds exactly the places
    # where the pattern's first word can match, in any letter case.
    word_re = re.compile(first.group(), pattern.flags)
    pos = 0
    while True:
        word = word_re.search(text, pos)
        if word is None:
            return
        start = word.start()
        if starts_in_unread_text(regions, start):
            # Not tried, and the next search starts ONE character on, not
            # after the word, so a copy of the word that starts inside this
            # one is still found (see "How it searches" above).
            pos = start + 1
            continue
        m = pattern.match(text, start)
        if m is None:
            pos = start + 1
            continue
        yield m
        # For a pattern the guard accepts the match covers at least the first
        # word, so m.end() is already past `start`. The max() is for a
        # pattern that can match nothing (a top-level `|` with an empty side,
        # such as `SELECT\s|`, which the guard does not catch): without it
        # the search would find the same word at the same spot for ever. The
        # first draft of round 6 had no max() and hung on exactly that.
        pos = max(m.end(), start + 1)


_COMMENT_RUN_RE = re.compile(rb"\x04+")


def blank_sql_comments(fragment: str, regions: bytearray, start: int) -> str:
    """
    `fragment` (which starts at `start` in the file) with every SQL comment
    character replaced by a space, except line breaks, which are kept. Blanking
    rather than removing keeps positions and line breaks where they were.

    Why: before 14 September 2026 the text of a comment was read as SQL, so
    `WHERE taskID = ? -- bogusName = ignored` reported a missing column
    "bogusName", and the same with # and /* ... */. Until round 4 this was a
    separate function with its own copy of MySQL's comment rules, which could
    not tell what kind of PHP string the SQL came from (so a written \\n never
    ended a comment); it now uses the map, the single definition.
    """
    codes = regions[start:start + len(fragment)]
    if SQL_COMMENT not in codes:
        return fragment
    parts: list[str] = []
    last = 0
    for run in _COMMENT_RUN_RE.finditer(codes):
        parts.append(fragment[last:run.start()])
        parts.append(re.sub(r"[^\n]", " ", fragment[run.start():run.end()]))
        last = run.end()
    parts.append(fragment[last:])
    return "".join(parts)


def reread_without_comments(
    pattern: re.Pattern[str], match: re.Match[str], regions: bytearray
) -> re.Match[str] | None:
    """
    Take a statement a pattern found, blank its SQL comments, and match the
    same pattern again against what is left, from its start.

    Why match again rather than just cleaning the pieces already captured: a
    comment can sit across the places the pattern split the statement.
    `SELECT a -- from tblBogus` then a line break, then `FROM tblTasks WHERE
    ...` was split at the FROM inside the comment, so the captured table part
    began "tblBogus". Matching again on the cleaned text finds the real FROM.
    Returns None when nothing is left that the pattern recognises (for
    example, the only FROM was inside a comment); the caller then skips the
    statement before counting it anywhere.

    What it cannot fix: the first reading already stopped at the first quote
    or semicolon, even one inside a comment (`-- don't`), so nothing after
    that point is available to read again.
    """
    return pattern.match(blank_sql_comments(match.group(0), regions, match.start()))

# -----------------------------------------------------------------------------
# WHERE-clause columns in SELECT and UPDATE statements
# -----------------------------------------------------------------------------
# Two real faults got past every check above them, because none of those
# checks ever looked at a WHERE clause on a SELECT or an UPDATE:
#
#   * web/_apps/dashboard/index.php compared `tblActivityLogs.createdAt` —
#     the real column is `timestamp`. The query was `SELECT COUNT(*) ...`,
#     so there was no column LIST to check either — the only place the
#     wrong name appeared was the WHERE clause.
#   * web/_apps/tasks/api/list.php compared `tblTasks.assignedUserID` — the
#     real column is `assignedToID`.
#
# Neither is the kind of mistake MySQL reports. A wrong column name in a
# WHERE does not error — the filter just never matches, and the query
# quietly returns nothing (or, for a COUNT(*), always reports zero). That
# looks exactly like "there's nothing to show today", not like a bug, which
# is why it can sit unnoticed for a long time.
#
# The scan below is a direct extension of the DELETE bare-column scan
# above (see BARE_COMPARE_RE and the "Shape 2" code in scan_php_inserts()):
# same regex, same safety rule — exactly one table, no alias, no JOIN, no
# sub-query — reusing FROM_TABLE_RE and BARE_COMPARE_RE rather than a
# second hand-written parser. The two helper functions below
# (parse_from_tables / scan_bare_where_columns) are that shared logic,
# pulled out once so DELETE, SELECT and UPDATE all drive the exact same
# code instead of three copies that could quietly grow apart.
#
# What this CANNOT do, and why — checked by hand against the two real
# statements that motivated this section (the old, faulty versions of
# web/_apps/dashboard/index.php and web/_apps/tasks/api/list.php, read via
# `git show`): the dashboard fault IS caught by the scan below; the tasks
# one is NOT, for the reason given in the first bullet:
#
#   * A WHERE clause assembled from a PHP array/variable at runtime, e.g.
#         $conditions = ['siteID = ?', 'assignedUserID = ?'];
#         $sql = '... WHERE ' . implode(' AND ', $conditions);
#     — this is exactly the tasks/api/list.php historical fault. The
#     column name `assignedUserID` never appears anywhere near the literal
#     word WHERE in the source text; it sits inside a PHP array built
#     several lines earlier. A text-based scan sees a WHERE clause with
#     nothing legible after it and correctly finds zero comparisons —
#     it is NOT lied to, but it also cannot see the fault. This is why
#     the coverage counters below count this shape as "examined" (a WHERE
#     was found) rather than silently excluding it — an honest zero, not
#     a hidden one.
#   * A WHERE clause on more than one table (a JOIN), or a single table
#     given an alias — skipped, because a bare column name would be
#     ambiguous. Counted separately below as "skipped — join or alias".
#   * Any sub-query — skipped completely, for the same reason the DELETE scan
#     skips one. "Completely" covers two shapes. One is a statement that
#     CONTAINS a sub-query (more than one SELECT in the captured text). The
#     other is a SELECT that IS a sub-query of a larger statement; see
#     select_is_inside_brackets() below. The second shape was missed until
#     14 September 2026. Given
#         UPDATE tblTasks SET siteID = ? WHERE taskID IN
#             (SELECT userID FROM tblUsers WHERE emailAddress = ?) AND taskID > 0
#     the UPDATE scan skipped it correctly. But the SELECT scan read the
#     inner query as a stand-alone SELECT, and its captured text ran past
#     the closing bracket, so "taskID" (a real tblTasks column) was
#     reported as missing from tblUsers. The same happened inside DELETE
#     and INSERT. The inner query's own WHERE is not read either, because
#     a bare name inside a sub-query may legally mean the OUTER table's
#     column. "Completely" still only covers sub-queries the check can
#     RECOGNISE; blind spots 5 and 6 in the header say which those are, and
#     how an unrecognised one can still cause a wrong report.
#   * Anything after the WHERE clause itself (GROUP BY, HAVING, ORDER BY,
#     LIMIT, ON DUPLICATE KEY UPDATE, ...) — cut off; see WHERE_END_RE.
#   * A WHERE clause that follows an inline literal SQL string value, e.g.
#     `WHERE status = 'Pending' AND siteID = ?` — only the text up to that
#     literal is read (the same trade-off DELETE_RE documents above:
#     stopping at the first literal avoids ever mistaking the literal's
#     own text for a column or table name). Anything after the literal is
#     unchecked. Under-catching here is the intended, safer direction.
#   * A WHERE the reading never reaches, because a quote or runtime text sits
#     between the table name and the WHERE (e.g. `SET ' . $setClause . '
#     WHERE`, or a quoted value in a SELECT's JOIN ... ON) — not checked, and
#     not in the total; estimated separately. See blind spot 12 in the header
#     and where_comes_later().


def parse_from_tables(
    names_part: str, schema: dict[str, set[str]]
) -> tuple[list[str], dict[str, str], list[str]]:
    """
    Walk the table-naming part of a statement — the text before its WHERE
    (DELETE, SELECT) or before its SET (UPDATE) — and report which real
    tables it names, which of them got an alias, and which names aren't
    tables in the schema at all.

    This is the FROM_TABLE_RE walk the DELETE scan has always done, lifted
    out so SELECT and UPDATE ask the exact same question the exact same
    way, rather than a second copy of the same walk that could quietly
    stop agreeing with the first one.

    Returns (tables, alias_to_table, unknown_table_names). The caller
    decides what "safe to scan" means for its own statement kind. For bare
    column names that is always names_one_plain_table(), not a count of
    `tables`: see the note above FROM_TABLE_RE for why a count is not enough.
    """
    by_alias: dict[str, str] = {}
    tables: list[str] = []
    unknown: list[str] = []
    found = list(FROM_TABLE_RE.finditer(names_part))
    # Every short name the statement gives a table, collected FIRST, so that a
    # later USE of that short name is never mistaken for a table.
    #
    # Why: FROM_TABLE_RE finds any word starting with "tbl", including one
    # used as a short name. In
    #     SELECT * FROM tblTasks t
    #     JOIN tblUsers tblAssignee ON tblAssignee.userID = t.assignedToID
    # the "tblAssignee" in the ON part was read as a table, and, not being in
    # the schema, reported as an unknown table. That was a false report, and
    # it also counted the statement under "table not in schema" instead of
    # "join or alias".
    #
    # A word is treated as a short name, not a table, only when BOTH are true:
    #   * the statement declares that short name somewhere (compared without
    #     regard to case; see ALIAS_DECLARATION_RE), and
    #   * the word is not itself given a short name, and it is EITHER written
    #     straight before a dot (`tblAssignee.userID`, spaces and backticks
    #     allowed) OR it is that declaration itself (the `tblAssignee` in
    #     `JOIN $tblOther AS tblAssignee`).
    # Until round 4 a short name counted as declared only when FROM_TABLE_RE
    # found it straight after a literal tbl... table. So `JOIN $tblOther AS
    # tblAssignee ON tblAssignee.userID = ...`, and the same after `users`,
    # reported "tblAssignee" as an unknown table, twice over: once for the
    # declaration and once for the use.
    # The first version of this fix skipped ANY word matching a declared short
    # name. That hid real faults: in `FROM tblTaskz tblTaskz` or `FROM tblTaskz
    # AS tblTaskz`, the misspelt table tblTaskz was skipped because its own
    # short name was the same word, and was no longer reported. A table is
    # never written straight before a dot in the part that names the tables,
    # except as a qualifier, so the dot tells the two apart.
    #
    # Any OTHER word starting with tbl in this part is still taken as a table
    # and reported if the schema has no such table, so a misspelt table name
    # after FROM or JOIN, or an undeclared one before a dot in an ON part, is
    # still caught. What this cannot catch: a qualifier whose spelling differs
    # only in upper/lower case from a declared short name (`TBLASSIGNEE.userID`
    # after `tblUsers tblAssignee`). MySQL on Linux rejects that, but it is
    # skipped here, which can only make the check miss, never invent a fault.
    declared_aliases = {tm.group(2).lower() for tm in found if tm.group(2)}
    declaration_starts: set[int] = set()
    for decl in ALIAS_DECLARATION_RE.finditer(names_part):
        group = 1 if decl.group(1) else 2
        declared_aliases.add(decl.group(group).lower())
        declaration_starts.add(decl.start(group))
    for tm in found:
        tbl = tm.group(1)
        alias = tm.group(2)
        if (
            not alias
            and tbl.lower() in declared_aliases
            and (
                tm.start(1) in declaration_starts
                or re.match(r"\s*\.", names_part[tm.end():])
            )
        ):
            continue
        if tbl not in schema:
            unknown.append(tbl)
            continue
        tables.append(tbl)
        if alias:
            by_alias[alias.lower()] = tbl
    return tables, by_alias, unknown


# The table-naming part of a statement when it is ONE table name and nothing
# else: optional spaces, the name (backticks allowed), optional spaces.
PLAIN_SINGLE_TABLE_RE = re.compile(r"\s*`?tbl\w+`?\s*")


def names_one_plain_table(names_part: str) -> bool:
    """
    True only when the part of a statement that names its tables (before the
    WHERE, or before an UPDATE's SET) is exactly one table name and nothing
    else. Only then can a bare column name in the WHERE belong to that table
    and no other, so only then are bare names read.

    Why a whole-text test and not "FROM_TABLE_RE found one table, with no
    short name": that count cannot see a table it does not recognise. Before
    this test existed, each of these was taken for a single table, and a
    correct column of the OTHER table was reported as missing from the first:
        FROM tblTasks JOIN users USING (taskID) WHERE otherCol = ?
        FROM tblTasks JOIN $tblOther USING (taskID) WHERE otherCol = ?
        FROM tblTasks, $tblOther WHERE otherCol = ?
        FROM tblTasks JOIN old_tblUsers USING (userID) WHERE otherCol = ?
        FROM tblTasks {$joinSql} WHERE otherCol = ?
    The first was already wrong before 14 September 2026; the next three
    became wrong when FROM_TABLE_RE stopped reading `$tblOther` and
    `old_tblUsers` as tables (they had been reported as unknown tables
    instead). Anything at all after the table name now means "skip".

    What that costs: a single table followed by anything else, such as
    `USE INDEX (siteID)`, `PARTITION (p0)` or text added at runtime, is
    skipped and counted under "join or alias", so a fault in it is missed.
    That is the safe direction to be wrong in.
    """
    return PLAIN_SINGLE_TABLE_RE.fullmatch(names_part) is not None


def scan_bare_where_columns(
    where_text: str,
    tbl: str,
    schema: dict[str, set[str]],
    seen: set[tuple[str, str]],
) -> list[str]:
    """
    Find bare column names being compared against something in a WHERE
    clause that belongs to exactly ONE table, and return the ones that
    aren't real columns of that table.

    This is the same BARE_COMPARE_RE scan the DELETE check's "Shape 2" has
    always used — pulled out so SELECT and UPDATE run the identical scan
    rather than a hand-copied near-duplicate. `seen` is shared with the
    caller (and, for DELETE, with its own qualified-name scan too) purely
    to avoid reporting the same missing (table, column) pair twice from
    the same statement — it plays no part in deciding what counts as a
    miss.

    Only the WHERE clause itself is read: the text is cut at the first
    GROUP BY / HAVING / ORDER BY / LIMIT / ON DUPLICATE KEY UPDATE etc.
    (WHERE_END_RE), because names after that point need not be columns of
    this table. Doing the cut here means DELETE, SELECT and UPDATE all get it.
    """
    missing: list[str] = []
    where_text = WHERE_END_RE.split(where_text, maxsplit=1)[0]
    where_text = COLLATE_NAME_RE.sub(" ", where_text)
    for bm in BARE_COMPARE_RE.finditer(where_text):
        col = bm.group(2) or bm.group(4)
        if not col or col.lower() in SQL_NOISE:
            continue
        # A number (1e3, 0x1F, ...) is a value, not a column; see
        # NUMBER_LITERAL_RE for why isdigit() alone was not enough.
        if is_number_literal(col) or not re.fullmatch(r"\w+", col):
            continue
        if col not in schema[tbl] and (tbl, col) not in seen:
            seen.add((tbl, col))
            missing.append(col)
    return missing


# Captures a whole SELECT statement from its FROM table onward, up to the
# end of the enclosing PHP string (or a semicolon, for a heredoc/double-
# quoted statement) — the same boundary rule DELETE_RE uses above, for the
# same reason: it must never run on past the end of THIS SQL string into
# unrelated PHP code, or a later, unconnected statement.
#
# Both the column-list part (before FROM) and the tail (FROM onward) are
# bounded by `[^'\";]` rather than "any character", on purpose. Without
# that bound, a non-greedy `.*?` would happily search hundreds of lines
# past the true end of this statement looking for the next occurrence of
# "FROM" anywhere in the file — including inside a completely unrelated
# function — and could report a WHERE clause that belongs to a different
# table entirely. Bounding to "stays inside this one quoted string" is
# exactly what the existing SELECT_RE above already relies on (its own
# column-list group is `[^\n]+?`, for the same reason in miniature); this
# just extends the same idea to cover the FROM/JOIN/WHERE tail too.
SELECT_TAIL_RE = re.compile(
    r"SELECT\s+[^'\";]*?\s+FROM\s+(`?tbl\w+`?[^'\";]*?)(?:'|\"|;|$)",
    re.IGNORECASE,
)

# The text just before a SELECT that shows it opens inside a bracket. Two forms:
#
#   * the bracket comes straight before it in the same string: `IN (SELECT`,
#     or `IN (` then a line break then `SELECT`;
#   * the bracket is the last thing in an EARLIER string, and the only thing
#     between that string and the one holding the SELECT is PHP joining code
#     that holds no other quoted text. That covers `IN (' . "SELECT`,
#     `IN (' . PHP_EOL . 'SELECT`, `IN (' . "\n" . 'SELECT`, `IN (' . $nl .
#     'SELECT`, `IN (";` then `$sql .= "SELECT`, and a ternary such as
#     `$sql .= $x ? 'SELECT`. Quoted text made only of spaces or \n, \r, \t is
#     allowed in between, because that is still only joining.
#
# Why the second form is so broad: an earlier version only accepted a plain
# quote-dot-quote, so `.=` and PHP_EOL joins slipped through. When the
# sub-query also held a quoted value (which ends the reading before its
# closing bracket), it was examined as a stand-alone SELECT and a correct,
# correlated query was reported as faulty. Treating any quote-free code in
# between as "joined" means that when in doubt, the SELECT is skipped.
#
# What that costs: a genuinely stand-alone SELECT that happens to follow a
# string ending in "(" with no other quoted text in between — e.g.
# `$open = '(';` straight before `prepare('SELECT ...')` — is skipped and
# counted as a sub-query, and a fault in it is missed. The same happens after
# a web address in quotes: strip_php_comments() mistakes the // in
# 'https://...' for a comment and removes the rest of that line, including
# the closing quote, so a later "(" and quote pair up wrongly. That was seen
# on the real tree on 14 September 2026 (web/_apps/invites/save.php, a
# stand-alone `SELECT 1 FROM tblInvitation ...`). Both are the safe direction
# to be wrong in.
#
# A bracket followed only by the SELECT's own opening quote is NOT treated as
# a sub-query: that is `$mysqli->prepare('SELECT ...')` or prepare("SELECT
# ..."), the most common way a stand-alone statement is written here. The
# pattern needs TWO quotes between the bracket and the SELECT (one closing the
# earlier string, one opening this one), and that call form has only one.
#
# An SQL comment (`-- note` or `# note`, ending at a line break, a written \n,
# or the SELECT itself) is allowed straight after the bracket and straight
# before the SELECT, wherever plain spaces are. Before 14 September 2026 only
# spaces were, so `IN ( -- only active users` then a line break then `SELECT`
# was not recognised as a sub-query. When the sub-query also held a quoted
# value, it was examined on its own and a correct, correlated query was
# reported as faulty ("tblUsers.assignedToID"). A written \n, \r or \t (a
# backslash and a letter, as in "IN (\n" . "SELECT") counts as a space here
# too; the first draft of this change forgot that, and the double-quoted form
# was still reported.
_SQL_GAP = r"(?:\s|\\[nrt]|(?:--|\#)[^\n]*?(?:\n|\\n|$))*"
SELECT_AFTER_BRACKET_RE = re.compile(
    r"\(" + _SQL_GAP + r"$"
    r"|\(" + _SQL_GAP + r"['\"]"
    r"(?:[^'\"]|'(?:\s|\\[nrt])*'|\"(?:\s|\\[nrt])*\")*"
    r"['\"]" + _SQL_GAP + r"$"
)


def select_is_inside_brackets(text: str, start: int, matched: str) -> bool:
    """
    True when the SELECT that begins at `start` is itself a sub-query: it
    sits inside a bracket opened by a larger statement (an UPDATE, DELETE,
    INSERT or another SELECT).

    Two independent signs, and either one is enough to skip:

      * the text just before the SELECT opens a bracket
        (SELECT_AFTER_BRACKET_RE, including a bracket at the end of an
        earlier string joined on by quote-free PHP code); this still works
        when the captured text is cut short by a quoted value before the
        bracket closes;
      * the captured text closes a bracket it never opened. For a
        stand-alone statement in a quoted string that cannot happen: the
        capture stops at the closing quote, so the ")" of the call around
        the string is never reached, and a bracket inside a quoted value is
        never reached either. The stray ")" is then the end of a sub-query,
        with the outer statement carrying on after it. The exception is a
        heredoc passed straight into a call (blind spot 10 in the header):
        it has no closing quote, so the capture runs on to the ";" and picks
        up the call's own ")". That statement is skipped as a sub-query,
        which misses a fault but never invents one.

    What it cannot see: a sub-query kept in a separate PHP variable, or one
    whose bracket is separated from the SELECT by other quoted text or more
    than 200 characters (blind spot 6 in the header). In the text that
    looks exactly like a stand-alone statement.

    `text` here is the file AS WRITTEN, not the readable copy every other
    reading uses (readable_sql_text()). SELECT_AFTER_BRACKET_RE was built for
    written text: it knows a written \\n, \\r or \\t, and that a written \\n ends
    an SQL comment. In the readable copy a written \\n is two spaces, so a
    comment after the bracket would run on to the SELECT, and more stand-alone
    statements would be skipped. Keeping the written text leaves this test
    exactly as it was. The cost: a quote written as an escape (\\x27) between
    the bracket and the SELECT is not seen as other quoted text, so the SELECT
    is taken as joined and skipped, which can only miss a fault.
    """
    # Only the last 200 characters are needed; searching the whole file up
    # to this point, once per statement, would be needlessly slow.
    if SELECT_AFTER_BRACKET_RE.search(text[max(0, start - 200):start]):
        return True
    depth = 0
    for ch in matched:
        if ch == "(":
            depth += 1
        elif ch == ")":
            depth -= 1
            if depth < 0:
                return True
    return False


# Same idea for UPDATE: from the table name (right after UPDATE / UPDATE
# IGNORE) through to the end of the enclosing string, covering the SET
# list and the WHERE clause together so the WHERE text is visible at all.
UPDATE_TAIL_RE = re.compile(
    r"UPDATE\s+(?:IGNORE\s+)?(`?tbl\w+`?[^'\";]*?)(?:'|\"|;|$)",
    re.IGNORECASE,
)

# The start of another SQL statement, used only by where_comes_later() below.
NEXT_STATEMENT_RE = re.compile(r"\b(?:SELECT|UPDATE|INSERT|DELETE)\b", re.IGNORECASE)


def where_comes_later(text: str, match: re.Match[str]) -> bool:
    """
    True when a SELECT or UPDATE whose reading found NO WHERE probably does
    have one, further on in the same PHP statement. Used ONLY to count such
    statements, never to look for faults in them.

    Why this exists: the reading of a statement stops at the first quote.
    Runtime text in an UPDATE's SET part (`SET ' . $setClause . ' WHERE
    ...`), or a quote or runtime text between a SELECT's table name and its
    WHERE, stops the reading before it reaches the WHERE. Such a statement is
    not checked. Until 14 September 2026 it was also left out of every
    coverage figure, and the code comment at that point wrongly said it had
    "no WHERE clause at all". On that day this was about 79 UPDATE and 66
    SELECT statements on the real tree, so the coverage figures looked far
    more complete than they were.
    (Since round 5 a quoted value in the SET part of `UPDATE tblX SET` no
    longer leaves the statement unchecked: read_where_after_set() finds that
    WHERE, and this estimate is used only when it cannot. That brought the
    UPDATEs counted here from 79 to about 14.)

    The test: the reading did not end at a semicolon, and the word WHERE
    appears after it before the next semicolon, before the start of another
    SQL statement, and within 2000 characters.

    It is an ESTIMATE, in both directions. It over-counts when that WHERE
    belongs to something else in the same PHP statement that is not SQL, or
    when the statement truly has none and the word appears in, say, a message.
    The search ignores case and does not care about a "$" in front, so a PHP
    variable CALLED `$where` (`'... FROM tblX ' . $where . ' ORDER BY ...'`)
    also counts. That one is usually right in practice, because such a
    variable normally does hold the WHERE, but it is luck, not a test.
    It under-counts when a semicolon inside a quoted value ends the look-ahead
    early, when a sub-query sits between the table and the WHERE, when an
    UPDATE's SET is in a later PHP string than its table name (the loop moves
    on before this is asked), and when the
    WHERE is held in a PHP variable built earlier under any other name (e.g.
    `$filter`), because then nothing after the reading says "where". That is
    why it is printed apart from the figures that must add up, and labelled as
    an estimate.
    """
    if match.group(0).endswith(";"):
        return False
    stop = text.find(";", match.end())
    if stop == -1:
        stop = len(text)
    window = text[match.end():min(stop, match.end() + 2000)]
    next_statement = NEXT_STATEMENT_RE.search(window)
    if next_statement:
        window = window[:next_statement.start()]
    return re.search(r"\bWHERE\b", window, re.IGNORECASE) is not None


def build_schema_map() -> dict[str, set[str]]:
    """Return {tableName: {column, column, …}}."""
    schema: dict[str, set[str]] = {}

    full = SQL_DIR / "full_schema.sql"
    if full.is_file():
        text = full.read_text(encoding="utf-8", errors="ignore")
        for m in CREATE_RE.finditer(text):
            tbl = m.group(1)
            body = m.group(2)
            cols = set(COLUMN_RE.findall(body))
            schema.setdefault(tbl, set()).update(cols)

    # Apply ALTER TABLE ADD COLUMN from migrations on top.
    for sql in sorted(SQL_DIR.glob("*.sql")):
        text = sql.read_text(encoding="utf-8", errors="ignore")
        # Simple single-add ALTERs
        for m in ALTER_ADD_RE.finditer(text):
            schema.setdefault(m.group(1), set()).add(m.group(2))
        # Multi-clause ALTERs: pick out every `colName` mentioned after
        # an ADD COLUMN inside the statement body. Coarse but effective.
        for m in ALTER_ADD_MULTI_RE.finditer(text):
            tbl = m.group(1)
            body = m.group(2)
            if "ADD COLUMN" not in body.upper():
                continue
            for col_match in re.finditer(
                r"ADD\s+COLUMN\s+(?:IF\s+NOT\s+EXISTS\s+)?`(\w+)`",
                body, re.IGNORECASE,
            ):
                schema.setdefault(tbl, set()).add(col_match.group(1))

    return schema


def reconstruct_php_strings(text: str) -> str:
    """
    Reconstruct concatenated PHP single-quoted strings into a single
    logical string so SQL split across multiple `'…' . '…'` lines parses
    as one INSERT statement.

    Specifically collapses sequences like:
        'INSERT INTO tblX ('
            . 'colA, '
            . 'colB)'
    into:
        'INSERT INTO tblX (colA, colB)'

    Single-pass, regex-driven — doesn't handle every edge of PHP
    string syntax, but covers the patterns used in this codebase.
    """
    # Collapse '<chars>' . '<chars>' (with possible whitespace/newlines) into
    # '<chars><chars>'. Run repeatedly until no more matches.
    pattern = re.compile(r"'([^']*)'\s*\.\s*'([^']*)'")
    while True:
        new = pattern.sub(lambda m: "'" + m.group(1) + m.group(2) + "'", text)
        if new == text:
            return new
        text = new


_PHP_BLOCK_COMMENT_RE = re.compile(r"/\*.*?\*/", re.DOTALL)
_PHP_LINE_COMMENT_RE = re.compile(r"//[^\n]*")


def strip_php_comments(
    text: str, regions: bytearray, readable: str
) -> tuple[str, bytearray, str]:
    """Strip PHP comments while preserving line numbers (replace block
    comments with the same number of newlines so reported line numbers
    still match the original file).

    The same cuts are made to `regions` (the map sql_regions() drew of the
    text before this ran) and to `readable` (readable_sql_text()), so all
    three still line up. A newline left in place of a block comment is
    NOT_RUN. Comments are found in the text as written, as before the
    readable copy existed, so what is cut has not changed.

    This step does not use the map to find comments, and so still deletes
    `//` and `/* */` INSIDE a string (blind spot 13). Making it use the map
    would change what the rest of the check reads across the whole tree, which
    was outside round 4; the map itself is drawn before this step and is not
    fooled by it."""
    for pattern, keep_newlines in ((_PHP_BLOCK_COMMENT_RE, True), (_PHP_LINE_COMMENT_RE, False)):
        out_text: list[str] = []
        out_readable: list[str] = []
        out_regions = bytearray()
        last = 0
        for m in pattern.finditer(text):
            out_text.append(text[last:m.start()])
            out_readable.append(readable[last:m.start()])
            out_regions += regions[last:m.start()]
            if keep_newlines:
                newlines = "\n" * m.group(0).count("\n")
                out_text.append(newlines)
                out_readable.append(newlines)
                out_regions += bytes((NOT_RUN,)) * len(newlines)
            last = m.end()
        out_text.append(text[last:])
        out_readable.append(readable[last:])
        out_regions += regions[last:]
        text, regions, readable = "".join(out_text), out_regions, "".join(out_readable)
    return text, regions, readable


def scan_php_inserts(
    schema: dict[str, set[str]]
) -> tuple[list[tuple[Path, int, str, str, str]], dict[str, int]]:
    """
    Return (findings, coverage).

    findings: (php_file, line_no, table, missing_column, statement_kind).

    coverage: counts of how many recognised SELECT/UPDATE statements have a
    WHERE clause, and of those, how many were actually examined versus
    skipped and why. Printed by check() so a run with zero findings can be told apart
    from a run that never actually looked at anything — see the "WHERE-
    clause columns" comment block above for why that distinction matters
    here specifically.
    """
    findings: list[tuple[Path, int, str, str, str]] = []
    coverage: dict[str, int] = {
        "select_where_total": 0,
        "select_where_examined": 0,
        "select_where_skipped_join_or_alias": 0,
        "select_where_skipped_subquery": 0,
        "select_where_skipped_unknown_table": 0,
        # Not part of the total, and an estimate: see where_comes_later().
        "select_where_not_reached": 0,
        "update_where_total": 0,
        "update_where_examined": 0,
        "update_where_skipped_join_or_alias": 0,
        "update_where_skipped_subquery": 0,
        "update_where_skipped_unknown_table": 0,
        "update_where_not_reached": 0,
        # ── SELECT-ALIAS (added for #519/#520 — see the header's "SELECT-ALIAS
        # reading" section for the full account). These four are DELIBERATELY
        # NOT part of the select_where_* add-up above: a statement can be
        # counted BOTH as "skipped — join or alias" there AND here, because
        # this is a second, narrower pass over exactly those skipped
        # statements, not a different population of them.
        "select_qualified_statements": 0,  # of the join/alias-skipped statements, how many named at least one alias.column
        "select_qualified_found": 0,       # every alias.column name found in those statements
        "select_qualified_checked": 0,     # of those, how many resolved to a known table and were actually checked
        "select_qualified_unresolved": 0,  # of those, how many named a short name this statement never declared (skipped, not reported)
    }
    # Column names compared WITHOUT REGARD TO CASE in the SELECT-ALIAS reading
    # below, because MySQL itself compares them that way — proved on MySQL
    # 8.0.36: `SELECT d.FILENAME FROM tblDocuments d` runs perfectly even
    # though the schema spells the column `fileName`. Comparing case-
    # sensitively would have reported that CORRECT query as a fault, which is
    # exactly the "cries wolf" failure this whole script exists to avoid (see
    # the header). Built once here, not once per statement, since the schema
    # itself never changes during a single run of this script.
    schema_lower: dict[str, set[str]] = {
        t: {c.lower() for c in cols} for t, cols in schema.items()
    }
    for root in PHP_ROOTS:
        if not root.exists():
            continue
        for php in root.rglob("*.php"):
            try:
                raw = php.read_text(encoding="utf-8", errors="ignore")
            except OSError:
                continue
            joined = reconstruct_php_strings(raw)
            # The map of what is SQL, a quoted value or a comment is drawn
            # BEFORE strip_php_comments(), which cannot tell a real comment
            # from `//` inside a string; that step then cuts the map exactly
            # as it cuts the text, so the two stay lined up.
            #
            # `text`, which every reading below uses, is the readable copy:
            # PHP escapes inside strings replaced by what they stand for (see
            # readable_sql_text() for the wrong reports that fixed). `written`
            # is the same text with the escapes as written; only the sub-query
            # look-back uses it (see select_is_inside_brackets()).
            written, regions, text = strip_php_comments(joined, *sql_regions(joined))

            # Line numbers are computed from `text` (reconstructed), not
            # `raw`, because string-concatenation reconstruction collapses
            # multi-line SQL onto fewer lines — using raw.find() against
            # collapsed strings reports wildly wrong offsets.
            # `strip_php_comments` preserves newline count so the reported
            # line still points within a few lines of the actual SQL.

            # ── INSERT column lists ──
            # A statement inside a quoted SQL value, an SQL or PHP comment, or
            # text outside PHP never runs, and live_matches() never returns
            # one (see starts_in_unread_text()). Each of the six readings below
            # searches with it, so such a statement is in no coverage figure,
            # and cannot hide a real statement written after it.
            for m in live_matches(INSERT_RE, text, regions):
                tbl = m.group(1)
                # SQL comments blanked first (see blank_sql_comments()). A
                # comment in the column list used to make the whole list look
                # malformed, so the statement was skipped unchecked.
                cols = [c.strip().strip("`")
                        for c in blank_sql_comments(m.group(2), regions, m.start(2)).split(",")]
                cols = [c for c in cols if c]
                if not cols or not all(re.fullmatch(r"\w+", c) for c in cols):
                    continue
                line_no = text[: m.start()].count("\n") + 1
                if tbl not in schema:
                    findings.append((php, line_no, tbl, "(unknown table)", "INSERT"))
                    continue
                for c in cols:
                    if c not in schema[tbl]:
                        findings.append((php, line_no, tbl, c, "INSERT"))

            # ── UPDATE … SET col = … assignments ──
            for m in live_matches(UPDATE_SET_HEAD_RE, text, regions):
                tbl = m.group(1)
                # Quoted values blanked, comments blanked and joins followed
                # by read_set_part(). Comments matter because `SET title = ?,
                # -- oldName = x` used to report "oldName" as a missing column.
                set_body = read_set_part(text, regions, m.end())
                line_no = text[: m.start()].count("\n") + 1
                if tbl not in schema:
                    findings.append((php, line_no, tbl, "(unknown table)", "UPDATE"))
                    continue
                for assign in SET_ASSIGN_RE.finditer(set_body):
                    col = assign.group(2)
                    # Skip words that are not columns. This used its own short
                    # list (NOW, NULL, TRUE, FALSE, AND, OR), so
                    # `IF(CURRENT_TIMESTAMP = dueDate, ...)` reported
                    # "CURRENT_TIMESTAMP". It now shares SQL_NOISE with the
                    # WHERE scan, so the two cannot drift apart.
                    if col.lower() in SQL_NOISE:
                        continue
                    # A number compared inside the SET part, as in
                    # `SET title = IF(1 = taskID, ?, title)`, is a value, not a
                    # column. It used to be reported ("tblTasks.1").
                    if is_number_literal(col):
                        continue
                    if col not in schema[tbl]:
                        findings.append((php, line_no, tbl, col, "UPDATE"))

            # ── SELECT col-list FROM tbl ──
            for m in live_matches(SELECT_RE, text, regions):
                line_no = text[: m.start()].count("\n") + 1
                # Matched again with SQL comments removed, like the WHERE
                # scans (reread_without_comments()). Until 14 September 2026
                # only the column list had its comments removed, so in
                #     SELECT emailAddress -- FROM tblTasks WHERE
                #     FROM tblUsers WHERE userID = ?
                # the FROM inside the comment still decided the table, and
                # "emailAddress" was reported as missing from tblTasks.
                # Matched again, the only FROM left is on the next line, which
                # this one-line pattern does not read, so the list is skipped.
                clean = reread_without_comments(SELECT_RE, m, regions)
                if clean is None:
                    continue
                col_body = clean.group(1)
                tbl = clean.group(2)
                if tbl not in schema:
                    findings.append((php, line_no, tbl, "(unknown table)", "SELECT"))
                    continue
                # SELECT_RE also accepts a table followed by LEFT / INNER /
                # RIGHT / OUTER / JOIN. A bare column in the list could then
                # belong to the joined table, so the list is not read. Before
                # 14 September 2026 it was, and `SELECT title, emailAddress
                # FROM tblTasks JOIN tblUsers ...` reported "emailAddress" as
                # missing from tblTasks. A wrong name in such a list (one in
                # neither table) is now missed too.
                if re.search(r"\b(?:LEFT|INNER|RIGHT|OUTER|JOIN)$", m.group(0), re.IGNORECASE):
                    continue
                # Skip if the SELECT list contains anything that's not a
                # simple identifier (functions, expressions, aliases, joins).
                # We're after exact column references only.
                if any(sym in col_body for sym in ("(", ")", ".", " AS ", "*", "DISTINCT")):
                    continue
                cols = [c.strip().strip("`") for c in col_body.split(",")]
                # Skip numbers — these are SQL literals from existence-check
                # patterns like `SELECT 1 FROM tbl WHERE …`. isdigit() used to
                # be the test, so `SELECT 0x1 FROM ...` reported "0x1". Words
                # in SQL_NOISE are skipped too: `SELECT title, NULL FROM ...`
                # reported "NULL", and `SELECT CURRENT_TIMESTAMP FROM ...`
                # reported "CURRENT_TIMESTAMP".
                cols = [
                    c for c in cols
                    if c and re.fullmatch(r"\w+", c) and not is_number_literal(c)
                    and c.lower() not in SQL_NOISE
                ]
                for c in cols:
                    if c not in schema[tbl]:
                        findings.append((php, line_no, tbl, c, "SELECT"))

            # -- DELETE ... FROM tbl [JOIN tbl] WHERE ... --------------------
            for m in live_matches(DELETE_RE, text, regions):
                line_no = text[: m.start()].count("\n") + 1
                # Read with SQL comments removed; see reread_without_comments().
                clean = reread_without_comments(DELETE_RE, m, regions)
                if clean is None:
                    continue
                body    = clean.group(0)
                clause  = clean.group(1)

                # Which short name belongs to which table.
                #
                # Only the part BEFORE the first WHERE is searched for table
                # names. Tables can only be named in the FROM and JOIN part; a
                # name appearing later is a value being matched against, not a
                # table. Searching the whole statement meant a row matching on
                # the text "tblSomething" was reported as an unknown table.
                names_part = re.split(
                    r"\bWHERE\b", clause, maxsplit=1, flags=re.IGNORECASE
                )[0]

                tables, by_alias, unknown_tables = parse_from_tables(names_part, schema)
                for u in unknown_tables:
                    findings.append((php, line_no, u, "(unknown table)", "DELETE"))
                if unknown_tables or not tables:
                    continue

                # Skip anything with a sub-query — working out which table an
                # inner name belongs to would be guesswork.
                if re.search(r"SELECT\s", body, re.IGNORECASE):
                    continue

                seen: set[tuple[str, str]] = set()

                # Shape 1: names written as shortname.column.
                for qm in QUALIFIED_COL_RE.finditer(body):
                    alias = qm.group(1).lower()
                    col   = qm.group(2)
                    tbl   = by_alias.get(alias)
                    if tbl is None:
                        continue
                    if col not in schema[tbl] and (tbl, col) not in seen:
                        seen.add((tbl, col))
                        findings.append((php, line_no, tbl, col, "DELETE"))

                # Shape 2: one table, no joins, so bare names must be its own.
                # (This is the scan SELECT and UPDATE reuse below, via
                # scan_bare_where_columns() — see the "WHERE-clause columns"
                # comment block above.) "One table" is decided by
                # names_one_plain_table(); the count of `tables` alone once
                # let `DELETE tblTasks FROM tblTasks JOIN $tblOther ...` through.
                if len(tables) == 1 and names_one_plain_table(names_part):
                    tbl = tables[0]
                    where = re.split(r"\bWHERE\b", body, maxsplit=1,
                                     flags=re.IGNORECASE)
                    if len(where) == 2:
                        for col in scan_bare_where_columns(where[1], tbl, schema, seen):
                            findings.append((php, line_no, tbl, col, "DELETE"))

            # -- SELECT ... FROM tbl [JOIN tbl] WHERE ... (bare columns) -----
            # Same shape, same rule, as the DELETE scan above: only look at
            # the WHERE when exactly one table is named and it has no alias.
            # live_matches() drops a statement that never runs before any
            # figure is counted, so the totals still add up.
            for m in live_matches(SELECT_TAIL_RE, text, regions):
                # Text that is not SQL at all (help text, HTML) is skipped
                # before anything is counted; see follows_non_sql_text().
                if follows_non_sql_text(text, regions, m.start()):
                    continue
                line_no = text[: m.start()].count("\n") + 1
                # Read with SQL comments removed; see reread_without_comments().
                # Skipped here, before any figure is counted, when nothing
                # recognisable is left, so the coverage figures still add up.
                clean = reread_without_comments(SELECT_TAIL_RE, m, regions)
                if clean is None:
                    continue
                full    = clean.group(0)
                tail    = clean.group(1)

                where_parts = re.split(r"\bWHERE\b", tail, maxsplit=1, flags=re.IGNORECASE)
                if len(where_parts) != 2:
                    # No WHERE in the part that was READ. That is not the same
                    # as "no WHERE clause at all", which an earlier version of
                    # this comment said: a quote or runtime text between the
                    # table name and the WHERE (e.g. a JOIN ... ON with a
                    # quoted value) stops the reading first. Such a statement
                    # is not checked and is not in the total. The separate
                    # "not reached" estimate counts it where it can; see
                    # where_comes_later().
                    if where_comes_later(text, m):
                        coverage["select_where_not_reached"] += 1
                    continue
                names_part, where_text = where_parts
                coverage["select_where_total"] += 1

                # A sub-query anywhere in the statement means more than one
                # "SELECT" appears in the matched text (the outer one plus
                # the inner one) — skip it, same reasoning as DELETE's own
                # sub-query skip. Checked AFTER "total" is counted (above),
                # not before, so that "examined" plus every "skipped ..."
                # line below always adds back up to "found" — see
                # print_where_coverage(). An earlier version counted this
                # skip before incrementing "total", which quietly left
                # sub-query WHERE clauses out of the total the other
                # figures were supposed to add up to.
                #
                # The second test catches a SELECT that is itself a sub-query
                # of an UPDATE, DELETE, INSERT or SELECT. finditer() meets the
                # outer statement first only when the outer statement is also
                # a SELECT that this pattern recognises. Otherwise it meets the
                # inner SELECT on its own, and this test is what stops the
                # inner query being examined against the wrong table. See
                # select_is_inside_brackets().
                if (
                    len(re.findall(r"\bSELECT\b", full, re.IGNORECASE)) > 1
                    or select_is_inside_brackets(written, m.start(), full)
                ):
                    coverage["select_where_skipped_subquery"] += 1
                    continue

                tables, by_alias, unknown_tables = parse_from_tables(names_part, schema)
                for u in unknown_tables:
                    findings.append((php, line_no, u, "(unknown table)", "SELECT-WHERE"))
                if unknown_tables:
                    coverage["select_where_skipped_unknown_table"] += 1
                    continue
                # See names_one_plain_table() for why the count of `tables`
                # is not enough on its own.
                if len(tables) != 1 or not names_one_plain_table(names_part):
                    coverage["select_where_skipped_join_or_alias"] += 1
                    # ── SELECT-ALIAS reading (#519/#520) ─────────────────────
                    # A bare column name here really could belong to either
                    # table, which is why the scan above gives up on this
                    # statement. But a name written as `shortname.column`
                    # says EXACTLY which table it belongs to — nothing has
                    # to be guessed — so it can still be read, using the
                    # very `by_alias` map parse_from_tables() just built two
                    # lines above (the same map the DELETE check already
                    # trusts for its own qualified-name reading). See the
                    # header's "SELECT-ALIAS reading" section for the full
                    # account of what this does and does not catch — in
                    # short: it found #520's `u.email` and the leadership
                    # API's `a.assignedAt`, both real, live faults; it does
                    # NOT find admin/live/chat.php's `m.flaggedReason`
                    # fault, because that statement's column list holds a
                    # double-quoted value before FROM
                    # (`COALESCE(e.eventName, "— no event —")`), which stops
                    # SELECT_TAIL_RE recognising the statement AT ALL — the
                    # header's own long-documented blind spot 3. That is a
                    # gap in what this script can see, not a gap in this
                    # particular reading, and closing it is a separate
                    # piece of work.
                    if by_alias:
                        coverage["select_qualified_statements"] += 1
                        seen_q: set[tuple[str, str]] = set()
                        for qm in QUALIFIED_COL_RE.finditer(full):
                            alias = qm.group(1).lower()
                            col = qm.group(2)
                            coverage["select_qualified_found"] += 1
                            tbl_q = by_alias.get(alias)
                            if tbl_q is None:
                                # Not a short name THIS statement declared —
                                # could genuinely belong to another table
                                # this script does not know the shape of
                                # (e.g. the statement's own full table name
                                # used as its own qualifier with no alias,
                                # which parse_from_tables() never adds to
                                # by_alias — see blind spot 16 in the header
                                # for why that shape is never read). Skipped
                                # and counted, never reported: reporting
                                # here would risk accusing a perfectly
                                # correct column belonging to a table this
                                # reading simply could not identify.
                                coverage["select_qualified_unresolved"] += 1
                                continue
                            coverage["select_qualified_checked"] += 1
                            if (
                                col.lower() not in schema_lower[tbl_q]
                                and (tbl_q, col) not in seen_q
                            ):
                                seen_q.add((tbl_q, col))
                                findings.append(
                                    (php, line_no, tbl_q, col, "SELECT-ALIAS")
                                )
                    continue

                coverage["select_where_examined"] += 1
                tbl = tables[0]
                seen = set()
                for col in scan_bare_where_columns(where_text, tbl, schema, seen):
                    findings.append((php, line_no, tbl, col, "SELECT-WHERE"))

            # -- UPDATE tbl SET ... WHERE ... (bare columns) ------------------
            # This loop drives UPDATE_TAIL_RE, NOT the stricter
            # UPDATE_SET_HEAD_RE used for the SET-list check further up.
            # UPDATE_SET_HEAD_RE only matches the
            # literal sequence "UPDATE tblX SET" with nothing else allowed in
            # between, so a joined or aliased UPDATE never matches it at all —
            # but UPDATE_TAIL_RE has to be looser than that to see the WHERE
            # text past the SET list, and it DOES match MySQL's
            # `UPDATE t1 JOIN t2 ... SET ... WHERE ...` multi-table form. So,
            # unlike the SET-list check above, there IS a real join/alias
            # skip here, and it fires on the real codebase, not just in
            # principle — e.g. web/_apps/admin/livestream/index.php's
            # `UPDATE tblLivestreamSchedule s INNER JOIN tblLivestreamChannel
            # c ON ... SET ...`. An earlier version of this comment claimed
            # there was nothing to skip here, which was wrong; the code was
            # already correct, only the explanation was not.
            for m in live_matches(UPDATE_TAIL_RE, text, regions):
                # Text that is not SQL at all (help text, HTML) is skipped
                # before anything is counted; see follows_non_sql_text().
                if follows_non_sql_text(text, regions, m.start()):
                    continue
                line_no = text[: m.start()].count("\n") + 1
                # Read with SQL comments removed; see reread_without_comments().
                clean = reread_without_comments(UPDATE_TAIL_RE, m, regions)
                if clean is None:
                    continue
                full    = clean.group(0)
                tail    = clean.group(1)

                set_parts = re.split(r"\bSET\b", tail, maxsplit=1, flags=re.IGNORECASE)
                if len(set_parts) != 2:
                    continue
                names_part, rest = set_parts

                where_parts = re.split(r"\bWHERE\b", rest, maxsplit=1, flags=re.IGNORECASE)
                subquery_after_where = False
                if len(where_parts) != 2:
                    # No WHERE in the part UPDATE_TAIL_RE read, which stops at
                    # the first quote. For `UPDATE tblX SET ...` the WHERE is
                    # looked for again by following the map through the SET
                    # part (read_where_after_set()). Until round 5 that was not
                    # done, and `SET status = 'done' WHERE bogus = ?` was not
                    # checked (79 UPDATEs on the real tree, including 6 column
                    # tests the older version made). If that finds no WHERE
                    # either (a SET part added at runtime, a join or short name
                    # in the UPDATE), the statement is not checked and not in
                    # the total; the separate "not reached" estimate counts it.
                    # See where_comes_later().
                    found = read_where_after_set(text, regions, m.start())
                    if found is None:
                        if where_comes_later(text, m):
                            coverage["update_where_not_reached"] += 1
                        continue
                    where_text, subquery_after_where = found
                else:
                    _set_body, where_text = where_parts
                coverage["update_where_total"] += 1

                # A sub-query in the SET list or the WHERE clause — the whole
                # matched text starts with "UPDATE", never "SELECT", so any
                # occurrence of SELECT here can only be a nested query.
                # Checked AFTER "total" is counted (above), for the same
                # reason as the matching SELECT-WHERE check above: so
                # "examined" plus every "skipped ..." line below always adds
                # back up to "found".
                #
                # Or, for a WHERE found by read_where_after_set(), a sub-query
                # after that WHERE (its own reading stops at one).
                if subquery_after_where or re.search(r"\bSELECT\b", full, re.IGNORECASE):
                    coverage["update_where_skipped_subquery"] += 1
                    continue

                tables, _by_alias, unknown_tables = parse_from_tables(names_part, schema)
                for u in unknown_tables:
                    findings.append((php, line_no, u, "(unknown table)", "UPDATE-WHERE"))
                if unknown_tables:
                    coverage["update_where_skipped_unknown_table"] += 1
                    continue
                # See names_one_plain_table(): `UPDATE tblTasks, users SET`
                # and `UPDATE tblTasks JOIN $tblOther ... SET` were once
                # examined as single-table statements.
                if len(tables) != 1 or not names_one_plain_table(names_part):
                    coverage["update_where_skipped_join_or_alias"] += 1
                    continue

                coverage["update_where_examined"] += 1
                tbl = tables[0]
                seen = set()
                for col in scan_bare_where_columns(where_text, tbl, schema, seen):
                    findings.append((php, line_no, tbl, col, "UPDATE-WHERE"))

    return findings, coverage


def print_where_coverage(coverage: dict[str, int]) -> None:
    """
    Print how many SELECT/UPDATE WHERE clauses were found, and of those,
    how many could actually be examined versus had to be skipped and why.

    This exists because "zero findings" on its own proves nothing here — a
    check that never looked at anything also reports zero findings, and
    looks identical from the outside. These figures are what let a reader
    tell the difference without re-reading the script.
    """
    for kind in ("select", "update"):
        label = kind.upper()
        total = coverage[f"{kind}_where_total"]
        examined = coverage[f"{kind}_where_examined"]
        skipped_join = coverage[f"{kind}_where_skipped_join_or_alias"]
        skipped_sub = coverage[f"{kind}_where_skipped_subquery"]
        skipped_unk = coverage[f"{kind}_where_skipped_unknown_table"]

        # This has to actually add up, not just look like it does. Both
        # loops in scan_php_inserts() now count "total" for every WHERE
        # clause found (regardless of whether it then turns out to be a
        # sub-query, a join/alias, or an unknown table), so the four lines
        # printed below MUST sum back to "found". An earlier version counted
        # sub-query skips before "total", which silently left them out of
        # the sum — this assert exists so that kind of regression fails
        # loudly the next time it happens, instead of quietly misleading
        # whoever reads the printed numbers.
        parts = examined + skipped_join + skipped_sub + skipped_unk
        assert parts == total, (
            f"{label} WHERE coverage counters do not add up: examined "
            f"({examined}) + skipped-join/alias ({skipped_join}) + "
            f"skipped-subquery ({skipped_sub}) + skipped-unknown-table "
            f"({skipped_unk}) = {parts}, but found = {total}. Fix the "
            "counting in scan_php_inserts() before trusting this output."
        )

        # "found" counts STATEMENTS this script recognised that have a WHERE
        # clause, not every occurrence of the word WHERE. An earlier label
        # said "WHERE clauses found", which suggested a count of every WHERE
        # in the code; statements never recognised (blind spot 3 in the
        # header) and DELETE statements are in none of these figures.
        print(f"{label} statements recognised that have a WHERE clause: {total}")
        print(f"  examined (single table, no join/alias, no sub-query): {examined}")
        print(f"  skipped — join or table alias present: {skipped_join}")
        print(
            "  skipped — contains a sub-query, or is itself a sub-query "
            f"inside a larger statement: {skipped_sub}"
        )
        print(f"  skipped — table not in schema: {skipped_unk}")
        print(f"  (examined + the three skipped lines = {parts}, always equal to the total above)")
        print(
            "  NOT in the total, and an estimate — WHERE never reached, because "
            "a quote or runtime text between the table name and the WHERE "
            "stopped the reading, so not checked: "
            f"{coverage[f'{kind}_where_not_reached']}"
        )

    # ── SELECT-ALIAS coverage (added for #519/#520) ──────────────────────────
    # Deliberately its OWN block, kept OUT of the examined+skipped=total
    # arithmetic asserted above. That arithmetic answers "of every SELECT
    # WHERE clause this script reached, what happened to it" — a single-
    # statement, single-bucket question. This reading is a SECOND, narrower
    # pass over the statements already counted as "skipped — join or table
    # alias present", so a statement legitimately appears in BOTH that count
    # and this one; folding these into the same sum would make that total
    # count some statements twice and the arithmetic would stop meaning what
    # it says it means.
    #
    # Per the "a check only covers what it reads" rule (zero findings proves
    # nothing on its own): a real, non-zero "names checked" figure here is
    # what tells a reader this reading actually ran over real code, as
    # opposed to a change that silently never fires. See DEV_NOTES.md for
    # the numbers measured on the real tree when this was written.
    print(
        "SELECT-ALIAS reading (of the statements skipped above as 'join or "
        "table alias present', a second pass reads any name written as "
        "alias.column — see the header's 'SELECT-ALIAS reading' section):"
    )
    print(
        "  of those skipped statements, how many named at least one "
        f"alias.column: {coverage['select_qualified_statements']}"
    )
    print(f"  alias.column names found in them: {coverage['select_qualified_found']}")
    print(
        "  of those, resolved to a table this statement declared, and "
        f"checked: {coverage['select_qualified_checked']}"
    )
    print(
        "  of those, the short name was not one this statement declared "
        f"(skipped, never reported): {coverage['select_qualified_unresolved']}"
    )
    print(
        "  A statement whose column list holds a quoted value before FROM "
        "is not recognised at all (blind spot 3 in the header) and so is in "
        "NONE of these figures either — this is why m.flaggedReason in "
        "admin/live/chat.php is not found by this reading, even though it "
        "is a real, live fault."
    )
    print()

    print(
        "  Notes on these figures:\n"
        "  - They count only statements this script RECOGNISED and whose WHERE "
        "it REACHED. A SELECT with a quoted value before FROM, e.g. "
        "DATE_FORMAT(x, '%Y-%m'), a table name held in a PHP variable, a WHERE "
        "held in a PHP variable, and every DELETE are in none of them, so they "
        "are a floor, not a census.\n"
        "  - A statement whose WHERE the reading never reached, e.g. SELECT ... "
        "JOIN ... ON x = 'y' WHERE ..., or UPDATE ... SET ' . $setClause . ' "
        "WHERE ..., is not in the total either. The 'WHERE never reached' line "
        "estimates how many there are; it can be a little over or under (blind "
        "spot 12 in the header). An UPDATE tblX SET with a quoted value, e.g. "
        "SET status = 'done' WHERE ..., IS reached: its WHERE is found by "
        "following the map of the file.\n"
        "  - A sub-query is normally counted with the statement around it. It "
        "is ALSO counted on its own, as a SELECT skipped for a sub-query, when "
        "the outer statement is an UPDATE, DELETE or INSERT, or when a quote "
        "(a quoted value, or the end of the PHP string) ends the reading of "
        "the outer statement before the sub-query starts.\n"
        "  - The sub-query figure can also include a few stand-alone "
        "statements the check could not tell apart from a sub-query, and a "
        "sub-query it cannot recognise is counted as examined (blind spots 5 "
        "and 6 in the header).\n"
        "  - A WHERE clause built at runtime from a PHP array, e.g. "
        "implode(' AND ', $conditions), IS counted as found and examined when "
        "its table is a plain single-table match, but the column names are "
        "not in the SQL text, so nothing can be compared. That zero is "
        "honest, not proof the query is correct.\n"
        "  - See the header of this script for every blind spot."
    )
    print()


def check() -> int:
    schema = build_schema_map()
    print(f"Tables inspected: {len(schema)}")
    findings, coverage = scan_php_inserts(schema)
    print(
        "Column-name mismatches "
        "(INSERT + UPDATE + SELECT + DELETE + SELECT-WHERE + UPDATE-WHERE + SELECT-ALIAS): "
        f"{len(findings)}"
    )
    print()
    if findings:
        # Deduplicate (file, line, table, col, kind).
        seen: set[tuple[str, int, str, str, str]] = set()
        deduped: list[tuple[str, int, str, str, str]] = []
        for php, line_no, tbl, col, kind in findings:
            rel = str(php.relative_to(REPO_ROOT))
            key = (rel, line_no, tbl, col, kind)
            if key in seen:
                continue
            seen.add(key)
            deduped.append(key)

        # Group by statement kind for readable output.
        by_kind: dict[str, list[tuple[str, int, str, str, str]]] = {}
        for finding in deduped:
            by_kind.setdefault(finding[4], []).append(finding)

        for kind in ("INSERT", "UPDATE", "SELECT", "DELETE", "SELECT-WHERE", "UPDATE-WHERE", "SELECT-ALIAS"):
            rows = by_kind.get(kind, [])
            if not rows:
                continue
            print(f"### {kind} column-name mismatches ({len(rows)})\n")
            for rel, line_no, tbl, col, _ in rows:
                print(f"  • {rel}:{line_no} — {kind} on {tbl} references column `{col}`")
            print()

    # Coverage is printed AFTER the findings, so the findings are the first
    # thing a reader meets. The pull-request check
    # (.github/workflows/pr-security.yml) puts this script's WHOLE output into
    # its comment when there is at least one finding, and adds nothing when
    # there is none. Since 16 September 2026 it also puts the whole output
    # under a "did not finish" heading when this script exits with an error
    # (a crash, the coverage assert in print_where_coverage(), or the
    # ValueError from live_matches()); before that a crash looked exactly
    # like a clean run there.
    # Until round 4 that step kept the output only from line 5 onward
    # (`tail -n +5`), and a comment here claimed printing coverage last made
    # that layout safe. It did not: with findings, line 4 is the heading of
    # the first group ("### SELECT-WHERE column-name mismatches (3)"), which
    # was dropped (the older version, commit 9c77216, lost it the same way:
    # its line 4 was that heading too), and with no findings line 4 is the
    # SELECT coverage heading. Nothing in this script's layout is now relied
    # on by the workflow.
    print_where_coverage(coverage)

    strict = "--strict" in sys.argv
    if findings and strict:
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(check())
