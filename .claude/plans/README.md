# Plans

A plan is **point-in-time thinking**, not a record of state. Write one when a
piece of work needs designing; it is not kept current afterwards.

## The rules, set by the owner on 30 October 2026 (#678)

1. **When the work lands, give the plan one terminal line and move it to
   `.claude/history/plans/`.** One of:

   - `SHIPPED in <commit>` — and say what shipped
   - `SUPERSEDED by <file or issue>` — and say which
   - `ABANDONED because <reason>`
   - `HISTORICAL` — a survey, review or audit that was never going to ship

   A plan with no terminal status is the problem this rule exists to stop: on
   30 October 2026 there were **21 plan files, none touched in 17 days, 19 with
   no status at all**, so a reader could not tell a live plan from one
   superseded six weeks earlier.

2. **Never cite another document by line number.** Cite **issue numbers and
   commit SHAs**, which do not move. Two plans cited `.claude/HANDOFF.md` by
   line — "HANDOFF line 264", "line 138" — and every one of those citations was
   already dangling, because the handoff had been rewritten and archived twice.

3. **Anything not-done goes in a GitHub issue, not in plan prose.** A deferral
   or an open question recorded only here is invisible: nothing checks a plan.
   If it is not worth an issue, it is not worth recording.

4. **Do not maintain a plan as the work changes.** That was considered and
   rejected on 30 October 2026: it is ongoing cost on documents nobody reads,
   and a plan that is edited to match what was built is no longer a record of
   what was intended. Let it go stale, then give it its terminal line.

## Where the live things are

| What | Where |
|---|---|
| What is and is not done, and every deferral | the GitHub issues |
| Where the work is right now | `.claude/HANDOFF.md` |
| What shipped | `CHANGELOG.md` |
| Technical lessons worth keeping | `DEV_NOTES.md` |
| Finished and superseded plans | `.claude/history/plans/` |
