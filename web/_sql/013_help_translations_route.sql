-- Migration: 013_help_translations_route.sql
-- Purpose:   Adds route for the Translations & Languages help page.
-- Phase:     9 (Polish & Hardening)

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('help/translations', 'help/translations.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
