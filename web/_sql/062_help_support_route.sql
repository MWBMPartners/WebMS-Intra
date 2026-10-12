-- =============================================================================
-- Migration 062: Add /help/support route + support email setting (#226)
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('help/support', 'help/support.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- EDITED for #500 (migration 215), with the owner's approval. This used to seed
-- the support address of the product's first customer as the value AND its
-- default, with a clause that wrote the default again on every replay. Both are
-- now empty and the clause writes nothing, so replaying this file can never put
-- that address back or change one an administrator has saved. A database that
-- already holds the old seed is cleaned by migration 215.
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'portal.support.email', '', '', 0)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;
