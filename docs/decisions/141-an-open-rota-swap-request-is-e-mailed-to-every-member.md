# D-141 · An open rota swap request is e-mailed to every member who has the switch on

**Date:** 2026-11-11  
**Status:** in force

**Question:** on the Duty Roster a member can ask a named person to swap a duty (that person is e-mailed and can
accept or decline), or post an "open" request meaning "anyone can cover this". Who should hear about an open
request: nobody (it only appears on the Duty Roster page), the people on that duty's team, or every member?

**Answer:** every member. An open request is e-mailed to every active member of the organisation, except the person
asking, who has the "Rota swap requests" notification switch on. Any of them may take it from the Duty Roster page.

**Rules out:** an open request that tells nobody; limiting the e-mail to a team the rota does not record.

**Where:** #657 — `Portal\Core\RotaSwaps` (who is told, checked at the moment of sending: active account, a member
of the organisation, the switch on), `web/_apps/rota/swap.php`.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
