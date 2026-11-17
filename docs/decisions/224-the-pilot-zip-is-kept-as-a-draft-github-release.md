# D-224 · The pilot zip is also kept as a DRAFT GitHub release

**Date:** 2026-11-17  
**Status:** in force

**Question:** the owner asked whether the first build could be put on GitHub as a downloadable alpha release. The repository is public, so a published release is downloadable by anybody, and D-222 says the pilot zip is never published.

**Answer:** the owner chose: a DRAFT release only (not public). The zip and its checksum are attached to a draft release named for the pilot, with a tag name that does not start with `v` (so publishing it cannot start the release build, `package.yml` runs only for `v*` tags). Only people with write access to the repository can see or download a draft; publishing it is a separate decision for the owner.

**Rules out:** publishing the zip publicly now; a `v*` tag for it.

**Where:** GitHub release `pilot-alpha-a0ab6b67` (draft); `.claude/HANDOFF.md`.

**Supersedes:** nothing.

**Related:** D-205, D-222 (this adds a private copy beside it; its "never published" still stands for any public release).

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
