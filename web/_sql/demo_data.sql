-- =============================================================================
-- demo_data.sql — Realistic demo dataset for training (#242)
-- =============================================================================
-- All demo rows use IDs >= 9000 so the wipe action can target them precisely
-- without affecting real records. Idempotent: re-running INSERTs no-op on
-- duplicate keys.
--
-- NOT applied automatically. Loaded only via /admin/maintenance/demo-data
-- when portal.demo_mode.enabled = '1'.
-- =============================================================================

-- 👥 Demo users (IDs 9000-9004)
INSERT INTO `tblUsers` (`userID`, `siteID`, `fullName`, `email`, `passwordHash`, `userRole`, `isVerified`, `createdAt`) VALUES
    (9000, 1, 'Demo Pastor',   'demo.pastor@example.invalid',   '$2y$10$disabled.demo.user.no.real.login.allowed.000', 'admin',     1, NOW()),
    (9001, 1, 'Demo Elder',    'demo.elder@example.invalid',    '$2y$10$disabled.demo.user.no.real.login.allowed.001', 'volunteer', 1, NOW()),
    (9002, 1, 'Demo Deacon',   'demo.deacon@example.invalid',   '$2y$10$disabled.demo.user.no.real.login.allowed.002', 'volunteer', 1, NOW()),
    (9003, 1, 'Demo Treasurer','demo.treasurer@example.invalid','$2y$10$disabled.demo.user.no.real.login.allowed.003', 'volunteer', 1, NOW()),
    (9004, 1, 'Demo Member',   'demo.member@example.invalid',   '$2y$10$disabled.demo.user.no.real.login.allowed.004', 'user',      1, NOW())
ON DUPLICATE KEY UPDATE `fullName` = VALUES(`fullName`);

-- 📢 Demo announcements
INSERT INTO `tblAnnouncements` (`siteID`, `title`, `slug`, `body`, `priority`, `isPinned`, `isPublished`, `createdByID`, `createdAt`) VALUES
    (1, '[DEMO] Welcome to the portal', 'demo-welcome', 'This is a demo announcement. Real content goes here.', 'normal', 1, 1, 9000, NOW()),
    (1, '[DEMO] Sabbath school schedule', 'demo-sabbath-school', 'Sabbath school resumes at 9:30am. All welcome.', 'normal', 0, 1, 9000, NOW()),
    (1, '[DEMO] Community potluck this weekend', 'demo-potluck', 'Join us after the service for a community potluck.', 'normal', 0, 1, 9001, NOW())
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

-- 📅 Demo calendar events (using `createdByID` as sentinel)
-- Note: tblEvents schema varies; this assumes the standard columns.
-- If insert fails due to schema mismatch, comment out and let admin curate.
