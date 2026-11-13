# D-183 · A remembered map answer for a home is personal data; which addresses are homes is chosen

**Date:** 2026-11-13  
**Status:** in force (adds to D-182; D-182's keeping-period setting stands)

**Question:** D-182 was asked as if a remembered map answer kept "only a fingerprint and the map position". The
position is stored to about a centimetre, so for a home address it IS the house: anyone who can read the database can
turn it back into the address with any online map, without knowing it first. What should the portal do about it?

**Answer:** the owner: "Treat it as personal data, but this should be selectable as to which addresses/map info are
personal addresses (homes) vs those of public businesses etc". So a remembered answer for an address marked as a home
(a person's own address) is handled as personal data — described as such in the catalogue and the privacy notice,
its fingerprint mixed with a secret unique to the installation, and kept only for the period D-182 sets. Whether an
address is a home or a public place (a venue, a business, the organisation's own address) is chosen, not assumed: it
is recorded with the look-up, and an answer for a public place is not treated as personal.

**Rules out:** describing remembered home positions as "not personal"; treating every remembered answer alike
whatever the address is; an unsalted fingerprint for a home.

**Where:** #1037 — `web/_core/Geocoder.php`, `web/_core/GeocodeCacheRetention.php`, the personal-data catalogue and
the forms that look up an address. Adds to D-182 (whose question understated what a row holds).

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
