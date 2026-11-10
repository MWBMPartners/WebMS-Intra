-- =============================================================================
-- Migration 268: a newsletter's click counter forwards ONLY to its own links
--                (#620)
-- =============================================================================
-- What this adds:
--
--   tblNewsletterLink   one row for each distinct web address a newsletter
--                       links to, written when the newsletter is sent with
--                       click counting switched on. Each row has a random
--                       32-character token, and the counted link in the
--                       e-mail carries THAT TOKEN, never the address.
--
-- WHY. The click counter (`newsletter/track/click`) used to carry the address
-- itself in the link (`&u=https://…`) and forward to any web address it was
-- given. So anybody could write a link that wears the portal's own address
-- and lands on any site they chose — an "open redirect", which is what makes
-- a phishing link look trustworthy. Now the counter looks the token up in
-- this table, for that newsletter, and forwards only to the address stored
-- there; anything else gets "this link is no longer valid".
--
-- WHY A STORED LIST AND NOT A SIGNATURE. A signed address needs a key: a new
-- secret to keep, and losing or replacing it would break every link in every
-- newsletter ever sent. A stored list needs no key, means the link reveals
-- nothing about where it goes, and is itself the record of what the
-- newsletter linked to. The token is random so that one newsletter's links
-- cannot be listed by counting.
--
-- LINKS ALREADY SENT. A link written before this change carries `&u=` and no
-- token, so it now gets "this link is no longer valid". That was chosen
-- knowingly: honouring it would mean forwarding to an address the request
-- names, which is the fault itself. No customer installation exists yet (the
-- owner, 2 November 2026) and click counting ships switched OFF
-- (`newsletter.trackClicks` is seeded '0'), so no real newsletter carries one.
--
-- NO PERSONAL DETAILS: a row holds a newsletter, a token and an address that
-- was in the newsletter for everybody it went to. It does NOT record who
-- clicked what; who clicked is still only `tblNewsletterRecipient.clickedAt`,
-- exactly as before.
--
-- THE UNIQUE KEY BINDS: neither of its columns can be empty, so a newsletter
-- cannot hold two rows for one address (the #487 lesson about keys that stand
-- aside for an empty column).
--
-- SAFE TO RUN TWICE: CREATE TABLE IF NOT EXISTS.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/620
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblNewsletterLink` (
    `linkID`       INT           NOT NULL AUTO_INCREMENT,
    `newsletterID` INT           NOT NULL COMMENT 'The newsletter this link is in. A token is honoured only together with this number.',
    `linkToken`    CHAR(32)      NOT NULL COMMENT 'Random, 32 lower-case hex characters from random_bytes(16), made by Newsletter::linkToken(). The counted link in the e-mail carries this, never the address.',
    `targetUrl`    VARCHAR(2048) NOT NULL COMMENT 'The http or https address the newsletter links to, exactly as it was in the newsletter (HTML entities decoded). Checked again by Newsletter::isForwardable() whenever it is read.',
    `targetHash`   CHAR(64)      NOT NULL COMMENT 'SHA-256 of targetUrl, in hex: what the unique key compares, because a 2048-character column is too long to index.',
    `createdAt`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the link was first recorded, by the server clock. Nothing compares it.',
    PRIMARY KEY (`linkID`),
    UNIQUE KEY `uq_nll_token` (`linkToken`),
    UNIQUE KEY `uq_nll_newsletter_target` (`newsletterID`, `targetHash`),
    CONSTRAINT `fk_nll_newsletter` FOREIGN KEY (`newsletterID`) REFERENCES `tblNewsletter` (`newsletterID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='The links each newsletter contains; the click counter forwards only to these (#620)';
