# D-133 · The installation and each organisation choose their own country; nothing defaults to Great Britain

**Date:** 2026-11-11  
**Status:** in force

**Question:** an organisation's country still defaulted to Great Britain (GB) in four places (the installation's
starting settings, the shared address form, the address tidy-up step and small groups), so on an installation where
nobody had chosen a country new addresses came out British. What should happen when nobody has chosen a country?

**Answer:** the installer asks for the installation's country, and that becomes the installation's default. The
installer pre-selects a suggestion from the installing person's own browser time zone (for example Europe/London
suggests GB); the person can change it, and nothing is looked up or sent anywhere. Each organisation may be based in
a different country and chooses its own, starting from the installation's. Other values that follow from a country
(time zone, currency and the like) are pre-selected from the chosen country, at installation and at organisation
level, and stay changeable. The owner's words: "Ask at install, then use that … pre-select other values (like
timezone, currency etc) based on the country selected at a installation and org-level". The owner first suggested
working the country out from the installer's IP address; on being told that needs an outside lookup service (sharing
that address) or a large shipped lookup file, chose the browser's time zone instead.

**Rules out:** GB (or any one country) built in as a default; looking up the installer's IP address with an outside
company or a shipped IP database; an organisation being forced to the installation's country.

**Where:** #942 — the installer, `full_schema.sql` / migrations for `org.address.countryCode`,
`web/_core/partials/location-input.php`, `GeoLocation::normaliseAddress()`, small groups, and the organisation
settings page. Related: #782 (no time zone built in), #935 (no place built in).

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
