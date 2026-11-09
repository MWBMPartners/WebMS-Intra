# D-091 · In dark mode, the main colour's shades follow the organisation's own colour

**Date:** 2026-11-09  
**Status:** in force

**Question:** in dark mode, the lighter and darker shades of the main colour — used when
you hover over a link or button, when something is selected, and for faint background
panels — always stayed the standard indigo, even when an organisation had chosen its own
colour; in light mode they follow it. A comment in the stylesheet said they should
follow it in dark mode too (#852, item 3). Which?

**Answer:** **follow the organisation's colour in dark mode too**, as in light mode, with a
contrast check so a pale or very dark chosen colour cannot make text hard to read. The
owner, 9 November 2026, choosing the recommended option.

**Rules out:** dark mode keeping fixed indigo shades whatever colour an organisation chose.

**Where:** `web/public_html/assets/css/portal.css` (the dark-mode colour blocks); #852.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
