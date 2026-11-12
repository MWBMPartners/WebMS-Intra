# D-181 · An event's start must fall between 1900 and 2200, or fifty years ahead if that is later

**Date:** 2026-11-12  
**Status:** in force

**Question:** an event could be saved with any year from 1 to 9999 — through the public "suggest an event" form, the
events API, a spreadsheet import, Manage Events, or a copied-in outside calendar. A year-1 or year-9999 date is almost
always a typing mistake, and one once broke the calendar list for a whole organisation. Which years should an event's
start be allowed to have?

**Answer:** the owner, in their words: "from 1900 until 2200 (or at least 50 years ahead, whichever is further)". So
the earliest allowed start is 1 January 1900, and the latest is the end of whichever year is later: 2200, or the
current year plus fifty. A start outside that range is refused with a plain reason by every writer, in one place; a
date from an outside calendar outside it is skipped and reported, not stored.

**Rules out:** keeping years 1 to 9999; a range that moves backwards with today's date (an organisation must still be
able to record its own history back to 1900); each writer holding its own copy of the range.

**Where:** #1045.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
