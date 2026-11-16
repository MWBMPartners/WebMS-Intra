# D-195 · The privacy pages list the companies an organisation uses from its own server, as a second short list

**Date:** 2026-11-16  
**Status:** in force

**Question:** D-158 names what3words, address look-up, videos and designs inside a page, shared code libraries, robot checks and error reports. Companies an organisation uses from its own server also receive members' details (sending e-mail and text messages, payments, video meetings, Microsoft and Google sign-in, transcription, translation). Should the privacy pages list them too (#808)?

**Answer:** the owner chose "Yes, as a second short list", headed "only if this organisation uses it", naming each kind of service and what it receives (for example a phone number to send a text message). The list is checked by the same hard gate as the first (`check_outside_hosts_named.py`), which fails when a company the code can contact is missing.

**Rules out:** a privacy page that reads as complete while leaving these companies out; presenting this list as something every organisation uses.

**Where:** #808 (`OutsideServices::NAMED` and the notice template on `/privacy` and `/privacy/policy`). Related: D-158, D-058.

**Supersedes:** nothing.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
