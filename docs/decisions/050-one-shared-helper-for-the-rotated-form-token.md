# D-050 · One shared helper for keeping the rotated form token, built next

**Date:** 2026-11-05  
**Status:** in force

**Question:** three JavaScript files each keep their own partial copy of one
rule — *when a post replaces the form token, hand the replacement to everything
on the page that holds one*. The copy in the SHARED file is the least complete:

| Where | `<meta name="csrf-token">` | `input[name="csrf_token"]` |
|---|---|---|
| `portal.js` — `Portal.rememberCsrf()` | yes | **no** |
| `event-hub-upload.js` — `syncCsrf()` | yes | yes |
| `portal-translate.js` — `shareToken()` | yes | yes |

`portal.js` loads on every page and `Portal.apiRequest` calls
`rememberCsrf()` on every answer, so **on any page where a background request
succeeds, an ordinary form is left holding a spent token and the next Save is
refused with a bare "Bad request"** — the member's typing gone. That is live
today, in code #485 never touched. Fixing it means one shared helper in
`portal.js` and deleting the two partial copies: JavaScript that loads on every
page of every installation. Build it next, leave it as an issue, or fold it into
#569's audit pass?

**Answer:** **build it next, as its own package.** The owner chose "Do it next,
as its own package" — before #477, which had been next.

**Rules out:** leaving #768 open and unscheduled; and folding it into #569's
standing audit pass, which would have delayed it until that large sweep runs.

**What it means in practice:** one helper in `portal.js` that writes the meta
tag, every `input[name="csrf_token"]`, and anything registered with it;
`rememberCsrf()` and `syncCsrf()` reduced to calling it; `shareToken()` keeping
only its own control-specific part. One check pins that one function, in place
of today's position where only `shareToken()` is pinned (because nothing runs
that script) and the other two copies are pinned by nothing.

**It touches shared JavaScript on every page**, so it takes the same check depth
as anything that can break a page — and by D-051 that means rounds continue
until one is clean, because a form refused with the member's typing lost is the
money-and-data direction.

**Also to settle while there:** whether a write endpoint a page uses repeatedly
needs its token rotated on every acceptance at all.
`Auth::verifyCsrfWithoutSpending()` already exists for read-only GETs with the
reasoning written out (#634); nobody has been asked the write question.

**Where:** `web/public_html/assets/js/portal.js`,
`web/public_html/assets/js/event-hub-upload.js`,
`web/public_html/assets/js/portal-translate.js`, and a new check.

**Issue:** [#768](https://github.com/MWBMPartners/WebMS-Intra/issues/768).

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
