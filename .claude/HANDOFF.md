# Handoff — where the work is right now

*Overwritten, never appended. Current state only. 3 November 2026.*

## Branch

`claude/alpha-wip` at **`1c2c495`**, pushed. Working tree clean.

**The owner gave standing approval on 2 November 2026 to push this working
branch after each commit** — never a release or protected branch, never a force
push, and no pull request until the Codex review is done. Pull requests, merges,
tags and releases still need their explicit say-so.

## Nothing is in flight

Last four commits, all pushed, all with their issues closed and labelled:

| Commit | What |
|---|---|
| `1c2c495` | #745 the guard for the ceiling, plus three shapes that switched it off and two wrong numbers of mine |
| `812d992` | #745 **every security gate had stopped running on GitHub for a week** |
| `c2c9f49` | #492 parts 2-4 · #472 · #581 (migration 246) |
| `d265904` | #491 · #481 · #583 · #492 part 1 |

## Read this before trusting any GitHub check

**From 28 October to 3 November, `pr-security.yml` started no jobs at all** —
all thirty-five of its hard gates ran on GitHub without running anything (#745).
A step's script carrying a `${{ ... }}` is capped at 21,000 characters, and
`c61211e` took that script to 24,525, so GitHub refused the whole file. Every
parser here accepted it and actionlint was green throughout. **The tell is the
run's `name`: GitHub names a run after the FILE PATH when it cannot read the
file.** Fixed, guarded by `tools/audit-checks/check_workflow_expression_length.py`,
and the guard has now run on a runner and passed.

**The file is readable again; the gates have still not RUN.** `pr-security.yml`
is `pull_request`-only, there is no open pull request, and its last completed run
was 26 October. Nothing yet proves the thirty-five gates pass.

## Next, in this order

The owner reordered on 3 November: **everything critical and high priority
first, then the rest of the queue.**

1. **#475 — drop MySQL 8.0** (critical, and the only critical one left).
2. The high-priority block: #225, #477, #485, #493, #499, #500, #511, #549,
   #569, #594, #637, #654, #678, #732, #739, #742, #743, #744.
3. Then the original queue: #582 · #620, #596, #588 · #649, #541 · #703 (decided),
   #539, #619 · #476, #146.
4. Then the comprehensive Codex review of the whole branch, then the merge.

## Decisions in force that a reader needs

- **Branch rules (3 November).** `main` now requires `PHP -l lint` **and**
  `Static security checks`, and **administrators no longer bypass** its rules.
  `alpha` and `beta` are deliberately left unguarded — blocked on #748, because
  two workflows write straight to them and a pull request opened by the built-in
  token starts no checks at all. #474 stays open for that half.
- **A capability, never a hard-coded role** (#727, the owner, 3 November): any
  new feature that needs gating declares a capability an organisation points at
  its own roles. `erasure.review` is the one capability a global administrator
  does **not** fall open on.
- **#703:** KEEP the name of who made a pastoral visit or safeguarding check.
- **#723:** the booker IS the booking's subject — `purpose` AND NOTHING ELSE.
- **Full check depth stays on every package.** Build → independent check by a
  fresh agent → fix → re-plant → commit, however many rounds that takes.
- **Codex has reviewed none of this.** The whole-branch review happens at the end
  of the queue, and every commit message says so.

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
| #732 | **No HTTP test for the two review pages** — the most valuable one left |
| #733 | Migration 241 can grant the role to an account that cannot sign in |
| #734 | A person erased at one organisation keeps free text at another |
| #735 | The review's two races are backstops no test forces |
| #736 | Three small things: breadcrumb, twice-written address, unpinned refusal |
| #747 | `check_fk_references_unique_key.py` cries wolf over an index reached by `RENAME INDEX` |
| #748 | version-bump and changelog write straight to `alpha`/`beta`, blocking #474 |
