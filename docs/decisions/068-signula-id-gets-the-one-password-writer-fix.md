# D-068 · SIGNula.id gets the one-password-writer fix

**Date:** 2026-11-06
**Status:** in force

**Question:** `github.com/MWBMPartners/SIGNula.id` has the same fault this portal
had before #728 — eight separate pieces of code each writing a sign-in password
in their own way, so no rule about passwords can be enforced in one place. D-026
said to read that repository before designing the password feature here, and
#731 shipped on 4 November, so that reason has passed. Should it be left, read
and designed, or read and fixed?
**Answer:** **read it and fix the password fault there** — one place hashes and
stores a password, as `Auth::setPassword()` and `Auth::setUnusablePassword()` do
here, with each caller keeping its own gate.
**Rules out:** leaving the fault live in that product; a design document with no
code behind it.
**What it does NOT authorise:** the old archive still in that repository's
history stays exactly where it is. Removing it needs a force-push, which no
assistant does without an explicit instruction naming it, and this decision is
not that instruction. Pushes, branches and pull requests in that repository
remain the owner's to approve, exactly as they are here.
**The cost, stated because the owner accepted it knowingly:** this is a branch, a
build, an independent check and a release judgement in a product whose code has
not been read here. It stops this portal's queue for several packages. The
owner chose it over the two cheaper options with that cost in front of them.
**Where:** scheduled after #777 part one's review rounds close under D-066 and
BEFORE #777 part two, because part two is display-layer work on 24 files and
this is authentication. #731, #728 and D-026 are the precedent for the shape.
