# Handoff — where the work is right now

*Overwritten, never appended. Current state only. 3 November 2026.*

## Branch

`claude/alpha-wip` at **`6f77b57`**, pushed. Working tree clean of package work.

**The owner gave standing approval on 2 November 2026 to push this working branch
after each commit** — never a release or protected branch, never a force push, and
no pull request until the Codex review is done. Everything else (pull requests,
merges, tags, releases) still needs their explicit say-so.

## Nothing is in flight

The last four commits, all pushed and all with their issues closed and labelled:

| Commit | What |
|---|---|
| `6f77b57` | #724 Data Controller role + erasure review · #586 password really stored (migrations 241, 242) |
| `6ce56ee` | #723 booking's stated reason · #721 part-written PDF · #720 short-written upload |
| `1b78e98` | #710 finish by hand · #715 a person's own words · #719 reserved list checked live (migration 240) |
| `a112ac0` | #714 three unreachable places (migrations 238, 239) |

## Next, in this order

1. **#727 — gate features on roles, no hard-coding.** The owner moved it here on
   3 November, immediately after #724, because #724's gate is its first real
   consumer and that gate asks ONE function in one place, so re-pointing it is a
   one-line change rather than rework.
   **Design notes already measured: `.claude-work/evidence/p727/design-notes.md`.**
   **THREE QUESTIONS ARE OWED TO THE OWNER BEFORE BUILDING** — which capabilities
   to start with, whether a set may be emptied to "nobody", and whether a mapping
   belongs to the organisation or the installation.
2. Block B: **#685 + #722 together** (the same `SettingLimit` shape, one package),
   then #491, #492, #481.
3. #582, #583 · #620, #596, #588 · #649, #541 · #703 (decided), #539, #619 ·
   #476, #146, #637 · #474, #678.
4. Then the comprehensive Codex review of the whole branch, then the merge.

## Decisions in force that a reader needs

- **#703:** KEEP the name of who made a pastoral visit or recorded a safeguarding
  check. It is part of the record, not the worker's own personal data.
- **#723:** the booker IS the booking's subject — reclassify, never weaken the guard.
- **#721:** both fixes, glob first; one hour for the sweep; the sweep is scoped by
  a folder allow-list AND a strict name.
- **#724:** a real `data_controller` role, gated on `Roles::has()` DIRECTLY (the one
  exception among 86 gates) with the installer granting it; all seven columns from
  the catalogue; the review filtered by organisation where the schema allows.
- **#586:** an administrator may NOT add a password to an SSO-only or passkey-only
  account. The person doing it for their own account is #731.
- **#727:** a capability maps to a SET of roles, any-of; an empty set must be a
  decision; the standard role stays unless somebody removes it.
- **Full check depth stays on every package** (the owner, asked directly whether to
  narrow it). Build → independent check by a fresh agent → fix → re-plant → commit.
- **Codex has reviewed none of this.** The whole-branch review happens at the end
  of the queue, and every commit message says so.

## Open, unscheduled, each with an issue

| Issue | What |
|---|---|
| #725 | Nothing exercises the documents API over HTTP |
| #726 | Can `move_uploaded_file()` report success after a partial write? |
| #728 | No single place sets a password — six writers now |
| #729 | The Edit dialog's "leave blank to keep current" for an account with none |
| #730 | `check_bind_param_arity.py` cries wolf (verified: 11 letters, 11 placeholders) |
| #731 | **Blocked** — SSO fallback password; decision spans SIGNula.id |
| #732 | **No HTTP test for the two review pages** — the most valuable one left |
| #733 | Migration 241 can grant the role to an account that cannot sign in |
| #734 | A person erased at one organisation keeps free text at another |
| #735 | The review's two races are backstops no test forces |
| #736 | Three small things: breadcrumb, twice-written address, unpinned refusal |

## Two limits of #724 worth knowing before touching it

- **A brand-new organisation grants the role to nobody and cannot** —
  `fk_user_role_membership` refuses a non-member. The page says nobody holds it.
- **Counting and showing are separate jobs on purpose.** The close counts in the
  database with NO limit; `SHOW_AT_MOST` is a page-full and nothing more. A single
  `LIMIT 500` on the counting side let a request close with a line undecided.
