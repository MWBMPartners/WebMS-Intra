-- =============================================================================
-- Migration 149: Noticeboard — real media upload pipeline (#363)
-- =============================================================================
-- Replaces the `data:` URI rejection in noticeboard/api/save.php (the
-- pre-transaction validation pass added in #360) with a real upload pipeline:
--
--   API:   POST /api/noticeboard/upload   (site admins only, CSRF, #363)
--          — finfo-sniffs the real MIME (never the client-declared type),
--            size-caps against noticeboard.upload.maxBytes, and stores the
--            file under _uploads/noticeboard/ (outside the webroot, mirroring
--            documents/api/create.php's #323 Phase 2 pattern) under a
--            server-generated bin2hex(random_bytes(16)).ext filename.
--   Route: GET  /noticeboard/media?f=<storedName>   (PUBLIC, isProtected=0)
--          — streams the file back out by its random token. Public + no
--            login: posters are shareable via QR, so the media itself must
--            render for an anonymous scanner even though the board's editor
--            page (/noticeboard) stays login-gated.
--
-- tblNoticeboardUploads is the ledger the media.php handler resolves tokens
-- against AND the source the save.php soft-delete step reads to find + purge
-- orphaned uploads (poster soft-deleted, or an upload staged then abandoned
-- without ever being attached to a saved poster).
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/363
-- =============================================================================

-- 📋 Upload tracking table
CREATE TABLE IF NOT EXISTS `tblNoticeboardUploads` (
    `uploadID`    INT          NOT NULL AUTO_INCREMENT,
    `siteID`      INT          NOT NULL,
    `posterID`    INT          DEFAULT NULL COMMENT 'tblNoticeboardPosters.posterID once this upload is referenced by a saved poster; NULL while still staged in the editor',
    `storedName`  VARCHAR(40)  NOT NULL COMMENT 'bin2hex(random_bytes(16)).ext — the ONLY value media.php will ever serve; never derived from client input',
    `mimeType`    VARCHAR(100) NOT NULL COMMENT 'finfo-sniffed MIME type, never client-declared',
    `fileSize`    INT          NOT NULL DEFAULT 0 COMMENT 'Size in bytes',
    `createdByID` INT          DEFAULT NULL,
    `createdAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`uploadID`),
    UNIQUE KEY `uq_upload_storedname` (`storedName`),
    KEY `idx_upload_site` (`siteID`),
    KEY `idx_upload_poster` (`posterID`),
    CONSTRAINT `fk_upload_site`    FOREIGN KEY (`siteID`)      REFERENCES `tblSites` (`siteID`),
    CONSTRAINT `fk_upload_poster`  FOREIGN KEY (`posterID`)    REFERENCES `tblNoticeboardPosters` (`posterID`) ON DELETE SET NULL,
    CONSTRAINT `fk_upload_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Noticeboard poster media uploads (#363) — orphan-cleanup ledger + media.php token resolver';

-- 📋 Public serving route (page route, not api/* — api/* paths never
--    register in tblRoutes, see the "ApiRouter routing trap" note in
--    .claude/CLAUDE.md). isProtected=0 — deliberately public, see header.
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('noticeboard/media', 'noticeboard/media.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📋 Settings — enable the upload API endpoint (ApiRouter checks
--    api.{app}.{action}.enabled) + the hard upload size cap. upload.php
--    defaults to the same 15 MB value when this is unset/non-positive, so a
--    misconfiguration can never DISABLE the cap.
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'api.noticeboard.upload.enabled', 'true',     'true',     0),
    (NULL, 'noticeboard.upload.maxBytes',    '15728640', '15728640', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT must be
-- idempotent too).
INSERT INTO `tblMigrations` (`filename`) VALUES ('149_noticeboard_upload.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
