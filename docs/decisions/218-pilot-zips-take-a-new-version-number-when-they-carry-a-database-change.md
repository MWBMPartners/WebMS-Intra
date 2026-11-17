# D-218 · A pilot zip that carries a database change takes a new last version number

**Date:** 2026-11-17  
**Status:** in force

**Question:** the portal closes itself for an upgrade only when the recorded version is behind the files' version, and D-024 keeps the version fixed until a release. Measured on 17 November 2026: with the same number the portal does not notice a waiting migration and runs new code on an old database until somebody presses Run on Admin, Upgrade. How should pilot upgrades be handled?

**Answer:** the owner chose: bump the last number of `web/_core/version.php` (for example 1.4.1 to 1.4.2) for each pilot zip that carries a new migration, so the portal closes itself until the update is applied. This is an exception to D-024 for the pilot period only; a branch named `hotfix/sign-in-1.4.2` already exists, so the next free number is checked before choosing.

**Rules out:** relying on someone remembering to press Run before the portal is used; changing the build to accept a non-numeric version label.

**Where:** `web/_core/version.php`, `tools/package/build.sh`, the pilot upgrade rehearsal, `docs/INSTALL.md`.

**Supersedes:** nothing.

**Related:** D-024 stands for the release.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
