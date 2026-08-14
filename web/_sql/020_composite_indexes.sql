-- Migration 020: Add composite indexes for common query patterns
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/66

-- 💰 Expense claims — filtered list queries
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblExpenseClaims'
      AND INDEX_NAME   = 'idx_claims_site_status'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblExpenseClaims` ADD KEY `idx_claims_site_status` (`siteID`, `status`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblExpenseClaims'
      AND INDEX_NAME   = 'idx_claims_site_user_created'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblExpenseClaims` ADD KEY `idx_claims_site_user_created` (`siteID`, `userID`, `createdAt`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 📅 Events — calendar page queries (site + active + date range)
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND INDEX_NAME   = 'idx_events_site_status_date'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblEvents` ADD KEY `idx_events_site_status_date` (`siteID`, `status`, `isDeleted`, `startDateTime`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ⛪ Attendance sessions — date-range and type queries
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAttendanceSessions'
      AND INDEX_NAME   = 'idx_attsess_site_date_type'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblAttendanceSessions` ADD KEY `idx_attsess_site_date_type` (`siteID`, `sessionDate`, `serviceTypeID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 👑 Leadership assignments — current role lookups
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblLeadershipAssignments'
      AND INDEX_NAME   = 'idx_leadassign_site_active_end'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblLeadershipAssignments` ADD KEY `idx_leadassign_site_active_end` (`siteID`, `isActive`, `endDate`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ✅ Expense approvals — status check per claim
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblExpenseClaimApprovals'
      AND INDEX_NAME   = 'idx_approvals_claim_decision'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblExpenseClaimApprovals` ADD KEY `idx_approvals_claim_decision` (`claimID`, `decision`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 📋 Track migration
INSERT INTO `tblMigrations` (`filename`) VALUES ('020_composite_indexes.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
