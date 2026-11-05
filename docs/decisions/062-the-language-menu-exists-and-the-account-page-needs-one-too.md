# D-062 · The language menu exists, and the account page needs one too

**Date:** 2026-11-05  
**Status:** in force  
**Supersedes:** [D-061](061-the-language-picker-is-its-own-piece-of-work.md)

**Question:** D-061 was answered on a premise that was **false**. Its question
asserted that "there is no control anywhere in the portal for choosing a
language — no menu, no button, nothing on the account page. The only way in is
to type `?lang=cy` on the end of an address by hand", and its recorded cost was
"a feature that is ready and unreachable". **There is a language menu in the
top bar of every page**, signed in and signed out. So: does D-061 stand?

**Answer:** **no — something is still wanted on the account page**, and it is
built as part of #763 rather than deferred. The owner, 5 November 2026.

**The measured fact that unseated D-061.** `grep -rn languageSwitcher web tools`
answers `I18n::languageSwitcher()` and two call sites in
`web/_core/templates/nav.php` — one for a signed-in person, one for a visitor.
`header.php` always requires `nav.php`. `enabledLocales()` counts readable
`web/_lang/*.php` files and there are two (`en.php`, `cy.php`), so the
`count($enabled) <= 1` early return does not fire and the menu renders. It is a
globe-icon dropdown with one `?lang=<code>` link per language.

**So what #763 actually does is better than D-061 was told.** It does not make
an unreachable feature ready; it makes a menu that has been on every page since
March 2026, and has always done nothing, start working. One click now stores a
member's language for good, on every device. That was a dead control, not a
missing one.

**Why the account page as well.** The top-bar menu changes the language; it is
not a place a member can SEE what the portal has stored about them alongside
their other personal settings. The owner chose to add it there in this piece of
work rather than later.

**Rules out:** treating #763 as plumbing for a control that does not exist;
the sentence "there is no control anywhere" in any document; and deferring the
account-page control to a later package.

**One thing measured while building it, because the obvious design was
wrong.** The account page must offer the language a member has ALREADY STORED
even when it has no translation file, and must not simply list
`enabledLocales()`. That list is readable `web/_lang/*.php` files — two on a
stock installation — while a choice is accepted by `isSupported()`, a
hard-coded list of thirteen. A stored `ar` therefore matched no option, nothing
carried `selected`, every browser pre-selected "Follow my browser", and
pressing Save to correct a telephone number deleted the member's language. The
two controls also do not offer the same languages: the top-bar menu hides any
language below `portal.i18n.minimum_coverage_for_switcher`, and the account
page says so rather than claiming they match.

**What this does NOT settle**, each still on #779: there is no way to clear a
stored choice back to "follow my browser"; there is no setting for which
languages an installation offers (`isSupported()` reads a hard-coded list of
thirteen and `enabledLocales()` reads the filesystem, so neither is a setting);
and a right-to-left language with no translation file can be stored, giving a
right-to-left page of English words.

**What this costs, said plainly:** D-061 is on record as a decision taken on
bad information, and this file is why it is visible rather than quietly
rewritten. The eight documents that repeated "no control anywhere" are
corrected where they may be edited, and recorded here where they may not
(D-060 and D-061 are decision records and are never edited).

**Where:** #763, #779, `web/_core/I18n.php`,
`web/_core/templates/nav.php`, the account page. Related: D-060, D-063.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
