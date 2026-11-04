# D-049 · Content translation is finished for the first release

**Date:** 2026-11-04  
**Status:** in force

**Question:** automatic translation of what MEMBERS have written (prayer
requests, announcements) is half built: `Portal\Core\Translation` is complete,
`/admin/translation` takes five providers' keys and a monthly spend cap,
`/account/translation` saves a member's opt-in — and **nothing a user can reach
calls any of it** (#485). D-041 named five half-built features to finish and
eight to defer, and this is in NEITHER list, so it was never asked. Finish it,
remove it, hide it, or leave it?

**Answer:** **finish it for the first release.** The owner's words: *"Finish it
for the first release"*.

**Rules out:** removing the engine, the pages and `tblUserTranslationPref`;
hiding the pages while keeping the code; and leaving it as it is with the
standing notice.

**TWO CONSEQUENCES WERE PUT TO THE OWNER WITH THE OPTION, AND THEY ARE
REQUIREMENTS NOW RATHER THAN CAVEATS.** The option they chose said both in
terms, so this is a decision taken with them in view:

1. **Every press spends the customer's money with an outside company.** So the
   monthly spend cap has to be trustworthy BEFORE this is switched on for
   anybody. #668's lesson applies directly: **a save-time cap is not a cap** —
   it must be enforced where the money is spent, not only where the number is
   saved, or a row written by SQL or restored from a backup is still obeyed.
2. **A member's words are sent to that outside company.** That is a privacy
   decision as much as a feature: the member's own opt-in is what permits it,
   so nothing may be sent for somebody who has not opted in, and the "Translate"
   control must not appear where it would send a third party's words.

**Where:** `web/_core/Translation.php`, `/admin/translation`,
`/account/translation`, and the unreachable `web/_apps/api/translate.php` —
which cannot be reached today because `ApiRouter` requires
`api/{app}/{action}` and looks under `_apps/{app}/api/`, so a two-segment
`api/translate` fails before it starts.

**Issue:** [#485](https://github.com/MWBMPartners/WebMS-Intra/issues/485).

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
