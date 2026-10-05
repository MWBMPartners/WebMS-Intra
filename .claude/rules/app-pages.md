---
paths:
  - "web/_apps/**"
---

<!-- .claude/rules/app-pages.md - moved word for word out of .claude/CLAUDE.md on 5 October 2026
     (owner's decision, Salem874). Claude Code loads this file only when it reads or edits a page
     under web/_apps/, because that is the only place the trap applies. Codex reads the same rule
     as rule 17 of .OpenAI/CONTEXT.md. If you change the rule, change both. -->

## Two variables, and only two (apply on every page under _apps/)

`web/_core/Router.php` does `global $mysqli, $SETTINGS;` immediately before it
loads a page. **Those two are the only things a page inherits.** Anything else
it reaches for is simply not there, and PHP does not complain until the moment
it is used.

Five Export CSV buttons were dead because their pages used `$db` (#482) — the
members list, the audit trail, the attendance register, the leadership roster
and the expenses queue. Each crashed the instant somebody pressed the button:
no file, no message on screen. Use `$mysqli`.

A helper that receives the connection as a **function parameter** is a different
thing and is fine — `web/_apps/announcements/_workflow-gate.php` does that
correctly.
