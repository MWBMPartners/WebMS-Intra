# D-222 · The pilot zip is built as the live channel

**Date:** 2026-11-17  
**Status:** in force

**Question:** (#1133) the installer writes `.channel` from the zip's `VERSION.txt`. A beta package applies the staff sign-in gate: the rehearsal on 17 November 2026 locked every invited member out of every page and turned away every scheduled job except the mail job. Which channel is the pilot built as?

**Answer:** the owner chose: live (recommended). The pilot zip is built with `bash tools/package/build.sh <commit> live <folder>`, with no `--tag` and no change to the build tool, so its name says live and it is never published as a release. D-218 (a new last version number for a pilot zip carrying a database change) still applies; the guide says to stop if installer stage 2 reports a beta package.

**Rules out:** installing the beta channel for the pilot; relying on switching the gate off after installing.

**Where:** `docs/INSTALL.md`, `tools/package/README.md`, the handoff's build line, package R1 (#1133, #1139).

**Supersedes:** nothing.

**Related:** D-024, D-088, D-205, D-218.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
