# Branch audit and clean-up — 7 September 2026

**Why this document exists.** Six leftover branches were sitting on GitHub with
no open pull request attached to any of them. Nobody could tell from the branch
names whether they held unfinished work. This is the written record of what was
actually checked, what was found, and what was done about it — so the decision
never has to be re-argued.

## The branches that were audited

| Branch | Last commit | What it looked like |
| --- | --- | --- |
| `claude/venue-b` | 28 Aug 2026 | Venues app — schedule / booking / generator / exports |
| `claude/venue-c` | 28 Aug 2026 | Venues app — config / vocabulary / settings / import wizard |
| `claude/venue-d` | 28 Aug 2026 | Venues app — agreements / invoices / payments / cron / API |
| `claude/venue-e` | 28 Aug 2026 | Venues app — calendar overlay / GDPR / translations |
| `claude/venue-f` | 28 Aug 2026 | Venues app — `/help/venues` page + documentation |
| `claude/dependabot-alpha-beta-branches-q24zd7` | 28 Aug 2026 | Planning and hand-off notes only |

## How they fit together

The five `claude/venue-*` branches were **not five separate pieces of work**.
They were one job split across five workers running at the same time. A shared
starting point (called "Agent A") built the database migration, the
`Portal\Core\Venues` class and the app registry entry. Each of the five workers
branched off that same starting point and built one slice of the Venues app.

Their output was then gathered into a single branch, `claude/venue-bookings`,
which was merged into `alpha` as **pull request #431** at 01:07 on 28 August —
after the last of the five branches was written. The five source branches were
simply never tidied away afterwards.

The sixth branch has a misleading name. Despite "dependabot" in the title it
contains **no dependency updates and no program code at all** — only planning
documents and hand-off notes written while the Venues work and a batch of
"gap item" jobs were being planned.

## Was any work still outstanding? No.

This was checked by comparing actual file contents, not by reading commit
messages. Three separate checks were run and all three agree.

**Check 1 — file-by-file content comparison.** Every file each branch touched
was compared byte-for-byte against the version now on `alpha`. Result: for all
five venue branches, every file was either already identical to `alpha`, or an
older version of a file that `alpha` has since improved. Nothing was missing.

**Check 2 — history search.** For every file that differed, the branch's exact
version was searched for in `alpha`'s full history. Every one of them was found,
all landing in `alpha` at commit `37bf418` (28 August, 01:07) — the merge of
pull request #431. In other words, `alpha` has had that exact content and has
since moved past it.

**Check 3 — trial merge.** A trial merge of each venue branch into `alpha` was
run without committing anything. Every one produced conflicts, and inspecting
the conflicts showed why: the branches would **undo** later improvements. The
clearest example is `web/_core/Venues.php`. All five branches carry the identical
untouched 4,060-line starting version. `alpha` now has a 4,218-line version that
adds room-aware booking coverage (issue #436, shipped in pull request #452).
Merging any venue branch would have thrown that away.

One method, `nextSortOrder()`, exists on the branches but not on `alpha`. It was
checked and it is dead code — it was declared `private` and never called from
anywhere, even on the branches themselves. `alpha` takes the sort order from the
submitted form instead. Nothing was lost.

**Conclusion: `claude/venue-b` through `claude/venue-f` contained no unfinished
work and nothing unique. Merging them would have caused a regression.**

## What was worth keeping

The sixth branch held **twelve planning documents that exist nowhere else**:

```
.claude/plans/gap-items/01-paypal-plan.md
.claude/plans/gap-items/03-reminders-plan.md
.claude/plans/gap-items/04-bulk-statements-plan.md
.claude/plans/gap-items/04b-statements-security-review.md
.claude/plans/gap-items/06-serviceplan-bridge-plan.md
.claude/plans/gap-items/07-workflow-engine-plan.md
.claude/plans/gap-items/07b-workflow-security-review.md
.claude/plans/gap-items/128-order-of-service-plan.md
.claude/plans/gap-items/234-shared-mailbox-plan.md
.claude/plans/gap-items/299-giving-triage.md
.claude/plans/gap-items/322-webpush-plan.md
.claude/plans/gap-items/436-venue-coverage-plan.md
```

These describe work that has since shipped (pull requests #434, #441, #444,
#445, #446, #451, #452, #454, #455). They are kept as the design record — the
reasoning behind decisions that are now baked into the code, including two
security reviews. A sister file, `02-paypal-security-review.md`, was already on
`alpha`, which confirms these were always meant to be kept.

They have been copied onto the consolidated branch `claude/alpha-wip`.

The branch's `.gitignore` change was **not** copied. It adds `.claude/worktrees/`
to the ignore list, which `alpha` already does — same rule, different position in
the file. Copying it would have created noise for no benefit.

The branch's hand-off notes were not copied verbatim either; `.claude/HANDOFF.md`
has been rewritten from scratch to describe the project as it stands today.

## What was done

1. Created `claude/alpha-wip` from the tip of `alpha`. This is now the **single**
   work-in-progress branch, and the only one that will target `alpha`.
2. Copied the twelve planning documents onto it.
3. Deleted all six audited branches from GitHub and from the local clone.

## The rule going forward

One work-in-progress branch at a time. When several workers build parts of the
same feature in parallel, their branches are throwaway scaffolding: once their
output has been gathered and merged, delete them the same day. A branch with no
pull request attached and no commits for a week should be treated as abandoned
until proven otherwise — and proving otherwise means comparing file contents,
never commit titles.
