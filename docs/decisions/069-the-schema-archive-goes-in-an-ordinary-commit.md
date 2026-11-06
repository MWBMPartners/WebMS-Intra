# D-069 · SIGNula.id's committed archive goes in an ordinary commit, not a rewrite

**Date:** 2026-11-06
**Status:** in force

**Question:** the owner instructed that
`database-backup-20260204_212957.tar.gz`, committed at the top of
`github.com/MWBMPartners/SIGNula.id`, be removed **by force-push**. D-068 had
explicitly declined to authorise that. Measuring it first produced two facts the
instruction could not have accounted for. Should it be a history rewrite, an
ordinary deletion, or nothing?
**Answer:** **an ordinary commit.** `git rm`, plus a widened `.gitignore`, on a
working branch. The old copy stays in the repository's history.

**The two measured facts, because they are the whole reason:**

1. **There is nothing in it to contain.** 23 entries — 19 `.sql` schema and
   migration files, a README and a `.DS_Store`, 54 KB. **Every secret-shaped
   seed value in it is an EMPTY STRING** (`email.smtp.password`, all six
   `oauth.*.client_secret`, both captcha secret keys,
   `payment.paypal.client_secret`, `security.encryption.salt`, the SMTP host
   and username). The 11 non-empty seed values are policy numbers and flags.
   **Despite being named `database-backup` it is a copy of the schema folder,
   not a backup of any data.**
2. **A force-push of `main` would not have removed it.** The commit that added
   it, `064b48e`, is an ancestor of **29 refs** — `main`, `alpha`, `beta`,
   `release-candidate`, 15 `claude/*` and 8 dependabot branches — so rewriting
   one branch leaves the file reachable from the other 28. Doing it properly
   rewrites **150 of 185 commits**, gives every one a new identifier, breaks
   every commit hash cited in that repository's notes and issues, diverges every
   clone, needs protection lifted on `main`, and on a private repository still
   leaves the orphaned copy fetchable by identifier until GitHub's own clean-up
   runs.

**Rules out:** the force-push, in every form, for this file. Also rules out
leaving it in place and merely correcting the documents.
**Supersedes:** nothing.

**How it sits beside the earlier decision on this repository.** That one
declined to authorise a rewrite, and still does. This answers the separate
question it deliberately left open — what to do about the file instead — and
arrives at the same conclusion about rewriting, by measurement rather than by
caution.
**What it does NOT change:** pushes in that repository remain the owner's to
approve. The commit is local, on `chore/138-remove-committed-schema-archive`.

**The transferable part, which is not about this file.** `.gitignore` already
had a "Database backups" section, and the file matched **none** of its three
patterns — all three need either a `.sql` extension or an UNDERSCORE before
"backup", and the name has a hyphen and `.tar.gz`. **The rule written to stop
exactly this was one character away from working, and nothing noticed for
months.** Removing only the file would leave the next one free to arrive, so the
shape is covered now and the widening was checked BOTH ways (#483): it catches
the original name, a `.tgz`, a `.zip` and a `.tar`, and leaves
`legit-schema.sql`, `web/_database/email_schema.sql` and `composer.json`
tracked.

**Also recorded, because our own handoff was wrong about it:** the handoff said
the archive was "still in that repository's history", implying it was only in
history and was sensitive. It was in the **working tree and committed**, and it
is not sensitive. That claim is corrected.

**Where:** SIGNula.id issue
[#138](https://github.com/MWBMPartners/SIGNula.id/issues/138), commit `906b4eb`
on `chore/138-remove-committed-schema-archive`, not pushed.
