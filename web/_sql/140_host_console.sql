-- =============================================================================
-- Migration 140: Virtual Host Console — Phase 1 of #317
-- =============================================================================
-- Read-only "during-the-service" dashboard composing the COP primitives shipped
-- in PR #340 (tblLivestreamSessions #318, tblDecisionMoments #315,
-- tblSalvationCards #316). NO new tables — pure read-side composition.
--
-- Phase 2 will add a push-prompt surface (host → viewer chat overlay) which
-- needs its own schema; that's a separate migration.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/317
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/host-console',       'admin/host-console/index.php', 1),
    ('admin/host-console/event', 'admin/host-console/event.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
