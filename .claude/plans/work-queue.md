# Work queue

**Last updated:** 10 September 2026.

The agreed list of what to work on, in the order it was given, with the GitHub
issue that carries the detail. Every item has an issue — this file is the index,
the issue is the substance.

The ranking in `proposed-next-work-2026-09-10.md` was my recommendation. **This
file is the customer's chosen order** and takes precedence.

| # | Item | Issue | Size | State |
| --- | --- | --- | --- | --- |
| 1 | Drop end-of-life MySQL 8.0. Target MySQL 9.7 / MariaDB 12.3 / PHP 8.5, with 8.4 / 11.4 / 8.4 as fallbacks. Keep watching end-of-life dates from now on. | [#475](https://github.com/MWBMPartners/WebMS-Intra/issues/475) | Large | ⏳ Blocked — needs to know what the hosting offers |
| 2 | Repair the version and changelog automation on `alpha` | [#476](https://github.com/MWBMPartners/WebMS-Intra/issues/476) | Small | ⏳ Ready |
| 3 | Give administrators a "check my portal" page | [#477](https://github.com/MWBMPartners/WebMS-Intra/issues/477) | Medium | ⏳ Ready |
| 4 | Make switching an app off actually switch it off | [#478](https://github.com/MWBMPartners/WebMS-Intra/issues/478) | Medium | ⏳ Ready |
| 5 | Prove the "delete my data" list is complete | [#479](https://github.com/MWBMPartners/WebMS-Intra/issues/479) | Medium | ⏳ Ready |
| 6 | Decide what to do about the minimum password length | [#480](https://github.com/MWBMPartners/WebMS-Intra/issues/480) | Small | ⏳ **Needs a decision** |
| 7 | Stop three migrations switching apps back on | [#481](https://github.com/MWBMPartners/WebMS-Intra/issues/481) | Small | ⏳ Ready |
| 8 | Document the other 29 data endpoints | [#482](https://github.com/MWBMPartners/WebMS-Intra/issues/482) | Medium | ⏳ Ready |
| 9 | Finish the mobile pass on a real phone | [#225](https://github.com/MWBMPartners/WebMS-Intra/issues/225) | Small | ⏳ Needs a person and a phone |
| 10 | Small clean-ups worth doing together | [#483](https://github.com/MWBMPartners/WebMS-Intra/issues/483) | Small | ⏳ Ready (do after #485) |
| 11 | Warn when a deploy is about to remove files | [#107](https://github.com/MWBMPartners/WebMS-Intra/issues/107) | Small | ⏳ Ready |
| 12 | Keep a history of settings changes | [#484](https://github.com/MWBMPartners/WebMS-Intra/issues/484) | Medium | ⏳ Ready |
| 13 | In-app messaging | [#304](https://github.com/MWBMPartners/WebMS-Intra/issues/304) | Large | ⏳ Ready |
| 14 | Update the self-hosted Swagger UI | [#486](https://github.com/MWBMPartners/WebMS-Intra/issues/486) | Small | ⏳ Ready |
| 15 | Re-verify the database structure before the first customer | [#487](https://github.com/MWBMPartners/WebMS-Intra/issues/487) | Large | ⏳ Ready (after #475) |

## Added to the queue, not requested

| Item | Issue | Why |
| --- | --- | --- |
| Translation is a shell — an administrator can configure a paid provider and a member can opt in, but nothing ever translates anything | [#485](https://github.com/MWBMPartners/WebMS-Intra/issues/485) | Found while writing #483. Should be settled **before** #483 deletes the only surviving description of how it was meant to work. Matters before a first customer: somebody could enter a billable API key for a feature that never makes a request. |

## Two decisions waiting

1. **What the hosting actually offers** (blocks #475). Targeting MySQL 9.7 only
   helps if the server can run it. DreamHost shared hosting has historically
   offered MariaDB rather than MySQL. Also unknown: which database the live site
   is really using — every document says MySQL 8.0, the automated test only ever
   tests MySQL 8.0.36, and MariaDB is claimed as compatible but **has never been
   tested even once**.
2. **The minimum password length** (#480). Existing sites require 8 while the
   project believes 12. Raise them automatically, or tell administrators and let
   them choose? Recommendation in the issue: tell them.

## Ordering notes

- **#485 before #483.** The clean-up deletes the dead file that is currently the
  only record of how translation was meant to work.
- **#475 before #487.** Which database versions we support decides what the
  database structure is allowed to use.
- **#476 is the cheapest win** — about an hour, and it stops `alpha` reporting
  the wrong version everywhere.
- **#477 has the widest reach.** Almost every fault in the September audit was
  invisible from inside the product; this is the thing that would surface the
  next one.
