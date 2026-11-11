# D-129 · An event with no end time stays on the public website's list until the end of its day, or for a chosen time

**Date:** 2026-11-11  
**Status:** in force

**Question:** the public website's event list (the public door's events feed) drops an event the moment it starts
when the event has a start time but no end time — a 10:00 service with no end time is gone from the website at
10:00. How long should such an event stay listed after it starts?

**Answer:** by default, until the end of that day on the event's own clock. This is configurable: the organisation
may instead choose a fixed maximum length of time after the start, used only when an event has no end time. The
owner's words: "Until the end of the day as the default, but make it configurable to also allow fixed maximum
period of time if no end time is set".

**Rules out:** leaving it as it is (gone at its start); a fixed period as the only behaviour; the setting changing
anything for an event that HAS an end time.

**Where:** #882 and #897 (point 1) — the public door's events feed, `Portal\Core\PublicDoor`. Which level holds the
setting and its bounds are implementation choices, recorded with the build; it is a display setting, not a limit
between two parties (the #668 rule does not apply).

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
