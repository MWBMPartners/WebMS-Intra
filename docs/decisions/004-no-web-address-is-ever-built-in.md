# D-004 · No web address is ever built in

**Date:** 2026-09-13  
**Status:** in force

**Question:** may our own domain appear in the code?
**Answer:** no. Every address comes from the installer, a setting, or a GitHub
secret. "Any domains should be configurable during the installation process (for
real customers)."
**Rules out:** a quiet fallback to our own address.
**Where:** `Portal\Core\PortalAddress`; `check_no_hardcoded_domains.py`.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
