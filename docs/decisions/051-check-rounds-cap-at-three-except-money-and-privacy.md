# D-051 · Check rounds cap at three, except where money, privacy or data loss is at stake

**Date:** 2026-11-05  
**Status:** in force (superseded in part)  
**Superseded in part by:** [D-064](064-review-rounds-on-tooling-count-towards-the-cap.md)

> D-064 narrows the protected arm below: a round whose findings are ALL in
> checks, tests or tooling counts towards the three-round cap. Everything this
> file says about money, personal details, authentication, erasure, backups and
> stored data stands unchanged.

**Question:** #485/#761 took **seven** independent check rounds against a
guideline of three. Findings per round were 5, 5, 3, 2, 1, 2, 1 — and **rounds 2
through 7 found faults in the PREVIOUS round's fixes**, not in the original
build. The existing rule is "stop when a round finds no real problem", so every
round being productive meant none could be skipped; but seven rounds is a great
deal of time on one package. What should future packages do?

**Answer:** **cap at three rounds for ordinary work, and keep going until a
round is clean where a fault would spend a customer's money, expose somebody's
personal details, or lose data.** The owner's words: *"Cap at three, but not for
money or privacy"*.

**Rules out:** a flat cap of three with everything outstanding becoming issues
(it would have shipped faults this package found in rounds 4 to 7, including one
that put an outside company's markup live through `innerHTML`); carrying on to a
clean round on every package regardless (what #485 did); and a flat cap of five.

**How to apply it, so the exception does not swallow the rule.** Judge by what
the package TOUCHES, not by how the last round felt:

* **Rounds continue until clean** when the package touches money, anybody's
  personal details, authentication or sign-in, erasure or retention, backups and
  restores, or anything that writes or deletes stored data.
* **Three rounds then ship** for everything else — presentation, developer
  tooling, documentation, tests, workflow wiring, and features that read without
  writing.
* **Anything outstanding at a cap becomes its own issue**, named in the commit
  message, so a capped package is visibly capped rather than quietly finished.
* **Say which arm applied** in the report and the commit message, and why. The
  honest version of this rule is that somebody chose.

**Note that this package would still have run all seven**, because every finding
was in exactly the protected areas. That is not an argument against the cap; it
is the reason the exception exists.

**What it does not change:** the requirement for a review by a different system
(D-005, D-035, D-007), the full check depth of the first round, or the rule that
a round is not clean for a hardening idea or a wording preference — only for a
real problem.

**Supersedes in part:** the "when the loop stops" paragraph of `.claude/CLAUDE.md`'s
Codex-review section, which said only "stop when a round finds no real problem".
That sentence stays true for the protected arm and is now capped for the rest.

**Where:** `.claude/CLAUDE.md` and `.OpenAI/CONTEXT.md`, the Codex-review
section.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
