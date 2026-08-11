-- =============================================================================
-- Migration 155: Event Team Hub — Phase 1 (#386)
-- =============================================================================
-- Per-event staff/volunteer/organiser portal. Extends the calendar app
-- (NOT a new top-level app — Calendar/Events/Preaching Plan is ONE app per
-- .claude/CLAUDE.md) with a "Team Hub" landing page at
-- `/calendar/event/hub?eventID=N` that gathers: a Resources list (links /
-- notes grouped into free-text sections), a video grid (YouTube / Vimeo /
-- Cloudflare Stream), the viewer's own crew/job/role roster context, and —
-- for coordinators/admins — a tool strip linking the existing but
-- previously-unlinked crews/jobs/attendance/broadcast/registrations pages.
--
-- Two new tables, plain `CREATE TABLE IF NOT EXISTS`, no ALTERs. Child
-- tables of `tblEvents` deliberately carry no `siteID` column (matches the
-- `tblEventCrews`/`tblEventJobs` precedent, migrations 117/118) — site
-- scoping always goes via the `tblEvents.siteID` join, never a duplicated
-- column here.
--
-- `tblEventHubVideos` ships with its FINAL shape from day one — the
-- upload-lifecycle columns (`uploadStatus`, `errorDetail`, `uploadedAt`,
-- `lastCheckedAt`, `allowedOrigins`) are folded in now even though Phase 1
-- never writes anything but `uploadStatus = 'external'`. This is so the
-- Phase 1.5 Cloudflare direct-upload build (issue #386 follow-up) never
-- needs an ALTER — nothing has shipped yet, so one CREATE beats a
-- CREATE + guarded-ALTER pair in the same lineage. `uploadStatus` defaults
-- to `'external'`, which is exactly what Phase 1's `addVideo` action writes
-- for every pasted YouTube/Vimeo/Cloudflare-UID reference.
--
-- Video handling in Phase 1 is EXTERNAL REFERENCES ONLY — a coordinator
-- pastes a YouTube/Vimeo URL or a Cloudflare Stream UID/URL and
-- `Portal\Core\VideoEmbed::parse()` allowlist-detects the provider + ref.
-- Playback for Cloudflare videos uses signed-URL tokens minted locally via
-- `VideoEmbed::signedToken()` (RS256, `openssl_sign`, no vendored JWT
-- encoder needed — `_vendor/simplejwt` is verify-only). The Cloudflare
-- *management* API (mint/poll/edit/delete via an API token,
-- `Portal\Core\CloudflareStream`), the direct-upload endpoints, the upload
-- widget JS, and `$cspConnectExtra` are explicitly OUT of Phase 1 scope —
-- see issue #386 for the Phase 1.5 follow-up. The admin config page below
-- nonetheless ships the FULL `cfstream.*` field list (including
-- `apiToken`) now, so Phase 1.5 needs no new settings/admin-page work,
-- only new POST handlers gated behind `CloudflareStream::isConfigured()`.
--
-- Access control (`Portal\Core\Auth::isEventTeamMember()`, mirrors
-- `isCoordinatorOf()`): true for a coordinator (admin bypass + DBS gate
-- included), OR a crew leader/participant, OR a job assignee, OR a
-- `tblEventPeople` row (host/speaker/organiser/…) — all four membership
-- tables already CASCADE-delete with the event. `canManage` stays the
-- existing `isAdmin() || isCoordinatorOf()` idiom used by every other
-- event sub-tool.
--
-- Routes seeded here are ONLY the ones whose handler files this migration
-- ships with — the upload-url/status JSON endpoints from the Phase 1.5
-- design doc are deliberately NOT seeded (their target files don't exist
-- yet, and `check_route_targets.py` would fail on a missing target).
--
-- Settings seeded: the full `cfstream.*` credential + tuning surface
-- (admin page fields), defaulted OFF/blank so a fresh install renders the
-- Phase 1 experience with every Cloudflare video showing an "unavailable —
-- check Stream settings" tile until an admin configures the two
-- credentials (signing key now; API token is Phase 1.5-only but the field
-- ships today so no future migration has to add it).
--
-- Replay/no-op proof: both CREATEs are IF NOT EXISTS; the route INSERT and
-- every settings INSERT carry ON DUPLICATE KEY UPDATE; there are no
-- ALTERs — a second run is a pure no-op. check_mariadb_only_ddl.py has
-- nothing to flag (no IF [NOT] EXISTS on ALTER/INDEX DDL).
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/386
-- =============================================================================

-- 📋 Free-text resource list (links + notes), grouped by a free-text
--    `section` label (e.g. "Before the event" / "On the day"). Notes are
--    rendered through Portal\Core\Markdown::render() — escaped-first, so
--    no raw HTML ever reaches the page.
CREATE TABLE IF NOT EXISTS `tblEventHubResources` (
    `resourceID`   INT           NOT NULL AUTO_INCREMENT,
    `eventID`      INT           NOT NULL,
    `section`      VARCHAR(80)   NOT NULL DEFAULT 'General'
                   COMMENT 'Free-text grouping: Before the event / On the day / …',
    `resourceType` ENUM('link','note') NOT NULL DEFAULT 'link',
    `title`        VARCHAR(255)  NOT NULL,
    `url`          VARCHAR(2048) DEFAULT NULL COMMENT 'resourceType=link',
    `body`         TEXT          DEFAULT NULL COMMENT 'resourceType=note (rendered via Portal\\Core\\Markdown)',
    `sortOrder`    INT           NOT NULL DEFAULT 0,
    `createdByID`  INT           DEFAULT NULL,
    `createdAt`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`resourceID`),
    KEY `idx_hubres_event_sort` (`eventID`, `section`, `sortOrder`),
    CONSTRAINT `fk_hubres_event`   FOREIGN KEY (`eventID`)     REFERENCES `tblEvents`(`eventID`) ON DELETE CASCADE,
    CONSTRAINT `fk_hubres_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Event Team Hub — links + notes, grouped into free-text sections (#386 Phase 1)';

-- 🎥 Video grid rows. FINAL shape (Phase 1 + Phase 1.5 upload-lifecycle
--    columns folded in now — see the migration header comment above).
--    Phase 1 code only ever writes `uploadStatus = 'external'` (the
--    column DEFAULT), `allowedOrigins`/`errorDetail`/`uploadedAt`/
--    `lastCheckedAt` stay NULL until the Phase 1.5 direct-upload +
--    polling handlers exist.
CREATE TABLE IF NOT EXISTS `tblEventHubVideos` (
    `videoID`           INT           NOT NULL AUTO_INCREMENT,
    `eventID`           INT           NOT NULL,
    `provider`          ENUM('youtube','vimeo','cloudflare') NOT NULL,
    `videoRef`          VARCHAR(255)  NOT NULL
                        COMMENT 'YouTube 11-char ID / Vimeo numeric ID / CF Stream 32-hex UID',
    `sourceUrl`         VARCHAR(1024) DEFAULT NULL COMMENT 'URL as pasted (external refs only)',
    `title`             VARCHAR(255)  NOT NULL,
    `requiresSignedUrl` TINYINT(1)    NOT NULL DEFAULT 0 COMMENT 'cloudflare only — mirror of CF requireSignedURLs',
    `allowedOrigins`    VARCHAR(1024) DEFAULT NULL COMMENT 'cloudflare only — comma-joined mirror of CF allowedOrigins (Phase 1.5)',
    `uploadStatus`      ENUM('external','pending','processing','ready','error')
                        NOT NULL DEFAULT 'external'
                        COMMENT 'external = pasted reference (Phase 1, no upload lifecycle); pending/processing/ready/error = Phase 1.5 direct-upload lifecycle',
    `errorDetail`       VARCHAR(255)  DEFAULT NULL COMMENT 'CF status.errorReasonText when uploadStatus = error (Phase 1.5)',
    `uploadedAt`        DATETIME      DEFAULT NULL COMMENT 'first observed past pendingupload (Phase 1.5)',
    `lastCheckedAt`      DATETIME      DEFAULT NULL COMMENT 'poll throttle timestamp (Phase 1.5)',
    `sortOrder`         INT           NOT NULL DEFAULT 0,
    `createdByID`       INT           DEFAULT NULL,
    `createdAt`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`videoID`),
    KEY `idx_hubvid_event_sort` (`eventID`, `sortOrder`),
    KEY `idx_hubvid_status` (`uploadStatus`),
    CONSTRAINT `fk_hubvid_event`   FOREIGN KEY (`eventID`)     REFERENCES `tblEvents`(`eventID`) ON DELETE CASCADE,
    CONSTRAINT `fk_hubvid_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Event Team Hub — video grid, external refs in Phase 1 (#386)';

-- 📋 Page routes. Only the handlers THIS migration ships with — the
--    Phase 1.5 upload-url/status JSON endpoints are deliberately NOT
--    seeded here (their target files don't exist yet).
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('calendar/event/hub',                        'calendar/event-hub.php',                          1),
    ('calendar/event/hub/save',                    'calendar/event-hub-save.php',                     1),
    ('admin/integrations/cloudflare-stream',       'admin/integrations/cloudflare-stream/index.php',  1),
    ('admin/integrations/cloudflare-stream/save',  'admin/integrations/cloudflare-stream/save.php',   1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📋 Cloudflare Stream settings — full field list (Phase 1 playback fields
--    + Phase 1.5 upload/management fields, seeded now so the admin page
--    never needs a follow-up migration). All blank/off by default; the
--    hub renders the Phase 1 "paste a link" experience regardless of
--    whether any of this is configured, and CF videos fall back to an
--    "unavailable — check Stream settings" tile until the signing key is
--    set.
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`) VALUES
    ('cfstream.enabled',                  'false', 0, 'false'),
    ('cfstream.accountID',                '',      0, ''),
    ('cfstream.customerCode',             '',      0, ''),
    ('cfstream.apiToken',                 '',      1, ''),
    ('cfstream.signingKeyID',             '',      0, ''),
    ('cfstream.signingKeyPem',            '',      1, ''),
    ('cfstream.tokenTtlSeconds',          '21600', 0, '21600'),
    ('cfstream.maxUploadDurationSeconds', '3600',  0, '3600'),
    ('cfstream.uploadMintPerHour',        '20',    0, '20'),
    ('cfstream.defaultRequireSignedUrls', 'true',  0, 'true'),
    ('cfstream.allowedOrigins',           '',      0, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT must be
-- idempotent too).
INSERT INTO `tblMigrations` (`filename`) VALUES ('155_event_team_hub.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
