# Handoff — where the work is right now

*Overwritten, never appended. Current state only. 4 November 2026.*

## Branch

`claude/alpha-wip` at **`55b0f78`**, pushed. Working tree clean.

**The owner gave standing approval on 2 November 2026 to push this working
branch after each commit** — never a release or protected branch, never a force
push, and no pull request until the Codex review is done.

## In flight

| | |
|---|---|
| **What** | The independent check of #744 (the in-app help) |
| **Commit** | `55b0f78` |
| **State** | 75 self-tests 0 not green, 57 audit scripts 0 red, actionlint clean, both changed workflows parse |
| **Next** | Fold its findings in, then #654 |

## Commits this run, newest first

| Commit | What |
|---|---|
| `55b0f78` | #744 the help: a hard gate on runbook references, and all 20 pages over HTTP (50 checks) |
| `2a9547c` | #742/#743 fix round — a heredoc made the brace walk swallow the next refusal |
| `4654473` | #742/#743 refusal pins, and a `.php` address hidden in a PHP string |
| `2d65939` | #475 part 1 fix round — the new hard gate ran in no workflow |
| `52098f3` | #475 part 1 — MySQL 9.7.4 and MariaDB 12.3.3 proved, PHP 8.5 linted, end-of-life watch |
| `1c2c495` | #745 the guard for the 21,000-character ceiling |
| `812d992` | #745 **every security gate had stopped running on GitHub for a week** |

## Four standing facts a reader needs

- **A new script in `tools/audit-checks/` is NOT wired up until
  `pr-security.yml` NAMES it.** That file lists every script individually;
  **there is no folder glob in any workflow here.** The pre-push hook does glob
  the folder, which is what misled me once already.
  `for f in tools/audit-checks/check_*.py; do grep -rqs "$(basename "$f")" .github/workflows/ || echo "NOT WIRED: $f"; done`
- **A step's script carrying a `${{ ... }}` is capped at 21,000 characters**
  (#745). Over it, GitHub refuses the whole file and starts no job, while every
  local parser and actionlint stay green. The tell is the run's `name` equalling
  its `path`.
- **MySQL 8.0 and MariaDB 10.6 stay ACCEPTED with a warning** (the owner,
  3 November). Do not raise `MIN_MYSQL` or `MIN_MARIADB` as a tidy-up.
- **Do not turn `php-static-analysis.yml` into a matrix** — it would rename the
  check `main` now requires and soft-lock every pull request into it.

## Next, in this order

The owner reordered on 3 November: **everything critical and high priority
first, then the rest of the queue.** D-024 then settled what had been blocking
the branch rules, so that comes first.

1. **The version number moves to release time — D-024.** `package.yml` sets it
   once when a release is built; `version-bump.yml` and `changelog.yml` stop
   writing to `alpha` and `beta`. **This is what closes #476, #748 and the
   remaining half of #474**, and brings `alpha`'s number back in line (it
   reports 1.4.0 against 1.4.1 on beta and main).
2. **The decisions and notes cleanup — #751.** Four items, each small: fold the
   four memory files' decisions into the register and leave pointers; record
   each decision on its own relevant issue where it is not already; carry the
   structure to the other repositories on this device; and the standing
   maintenance pass — read the register against the code and the instruction
   files and realign what has drifted. The handoff's own list is already
   pointers.
3. **#728 — one place sets a password.** Then **#731** (D-026), which also needs
   a reading pass of `github.com/MWBMPartners/SIGNula.id` first.
4. **#654 — a live, measured fault.** A date cancelled with `EXDATE` comes back
   if it falls in the hour that happens twice, in the form Google, Apple and
   Outlook all write. Pre-existing, reproduced against the committed
   `IcsReader.php`, NOT fixed by #653.
5. **#732 — no HTTP test for the two erasure-review pages.** The #724 checker
   called it the most valuable thing left: removing `AND siteID = ?` from both
   pages and `Auth::verifyCsrf()` from the save left 52 audit scripts and 91/91
   checks green.
6. **#475 part 2** — what DreamHost actually offers; a systematic list of the
   real differences between the engines; whether the calendar self-tests should
   also run on MySQL 9.7 and MariaDB 12.3.
7. The rest of the high block: #225, #477, #485, #493, #499, #500, #511, #549,
   #569, #594, #637, #678, #739, #746, #749.
8. Then: #582 · #620, #596, #588 · #649, #541 · #539, #619 · #146.
9. Then the comprehensive Codex review of the whole branch, then the merge.

## Decisions in force — POINTERS ONLY

**Every decision lives once, in `docs/decisions/`, one file each** (the owner,
4 November 2026 — D-027, issue #751). This list used to restate them, which is
the duplication that caused the problem. It names them instead.

| | Decision |
|---|---|
| **D-024** | The version number moves to release time — this is how #474, #476 and #748 close |
| **D-022** | Administrators no longer bypass the live branch's rules |
| **D-019** | MySQL 8.0 and MariaDB 10.6 stay accepted, with a warning — **do not raise the minimums** |
| **D-026** | The SSO fallback password: stricter level wins, #728 first, SIGNula.id read first |
| **D-025** | The help-page guards are binding, and all twenty pages are loaded |
| **D-017** | Gating a feature is a capability, never a hard-coded role |
| **D-016** | #703 keep the worker's name · #723 the booker is the subject · #715 own words emptied |
| **D-015** | Full check depth on every package |
| **D-013** | For a limit, the installation's value wins |
| **D-012** | Packages sharing no files may run side by side |
| **D-005** | Commit and push after an independent Claude check |
| **D-027** | Decisions are recorded in `docs/decisions/` and searched before asking |

**Codex has reviewed none of this work.**

## Nothing has run the new CI on GitHub

`end-of-life-watch.yml` cannot be dispatched (`HTTP 404: not found on the
default branch`), and `e2e-migrations.yml`'s five-engine matrix needs a pull
request touching `web/_sql/`. Both are proved locally only. Recorded on #475.

## Blocked on the owner

| What | Why |
|---|---|
| `docker volume rm cfbd1277f4cb2a4a8de5367a4cdd5e00087486d6c0ff381dec946379f2b870f7` | The permission classifier refused it three times. Reported, left alone. |
| #731 | SSO fallback password — the decision spans SIGNula.id. Five questions drafted on the issue. |

## Open, unscheduled, each with an issue

| Issue | What |
|---|---|
| #725 | Nothing exercises the documents API over HTTP |
| #726 | Can `move_uploaded_file()` report success after a partial write? |
| #728 | No single place sets a password — six writers |
| #729 | The Edit dialog's "leave blank to keep current" for an account with none |
| #730 | `check_bind_param_arity.py` cries wolf |
| #733 | Migration 241 can grant the role to an account that cannot sign in |
| #734 | A person erased at one organisation keeps free text at another |
| #735 | The review's two races are backstops no test forces |
| #736 | Three small things: breadcrumb, twice-written address, unpinned refusal |
| #747 | `check_fk_references_unique_key.py` cries wolf over an index reached by `RENAME INDEX` |
| #748 | version-bump and changelog write straight to `alpha`/`beta`, blocking #474 |
| #749 | The in-string blindness is wider than `.php` — a bare address too |
| #750 | Four self-tests cut a code block four ways; only one knows about heredocs |
