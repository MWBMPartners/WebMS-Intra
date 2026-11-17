# D-208 · Linking a Microsoft or Google account never creates an account

**Date:** 2026-11-17  
**Status:** in force

**Question (#1073):** a signed-in member linking a Microsoft or Google identity that matches nobody got a brand-new account and was silently switched into it. What should linking do?

**Answer:** the owner chose: linking never creates an account. It attaches to the account the person is signed in to, or to an existing account; if the identity matches nobody the portal says so and nothing changes.

**Rules out:** creating a new account, or switching the session, as a side effect of linking.

**Where:** #1073 (the sign-in callbacks in `Auth.php`).

**Supersedes:** nothing.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
