# D-102 · Which port numbers a webhook may use is an installation setting, standard ports by default

**Date:** 2026-11-10  
**Status:** in force

**Question:** a webhook is an automatic message the portal sends to another system's web address
when something happens. The fix for #615 refuses private and internal addresses, and reuses the
outside-calendar rule that allows only the two standard web ports (443 for https, 80 for http). A
webhook pointed at any other port number (for example `https://example.com:8443`) would then be
refused when saved, and one already saved would fail on every send until it was set aside as
failed. Should webhooks keep the standard-ports-only rule, or may they use other port numbers on
public addresses?

**Answer:** **an installation setting, standard ports by default.** The installation's global
administrator can allow other port numbers; until they do, only 443 and 80 are allowed. Private
and internal addresses stay refused either way. The owner, 10 November 2026, choosing the third
option offered (the recommended one was standard ports only).

**Rules out:** standard ports only, with no way to allow others; any port on a public address,
with no setting.

**Where:** #615 — `SafeFetch::post()`, `web/_apps/admin/integrations/webhooks/save.php`,
`WebhookDispatcher`. Related: D-013 (the installation's setting is not one an organisation can
loosen).

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
