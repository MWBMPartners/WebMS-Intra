-- Migration 020: Add composite indexes for common query patterns
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/66

-- 💰 Expense claims — filtered list queries
ALTER TABLE `tblExpenseClaims`
    ADD KEY `idx_claims_site_status` (`siteID`, `status`),
    ADD KEY `idx_claims_site_user_created` (`siteID`, `userID`, `createdAt`);

-- 📅 Events — calendar page queries (site + active + date range)
ALTER TABLE `tblEvents`
    ADD KEY `idx_events_site_status_date` (`siteID`, `status`, `isDeleted`, `startDateTime`);

-- ⛪ Attendance sessions — date-range and type queries
ALTER TABLE `tblAttendanceSessions`
    ADD KEY `idx_attsess_site_date_type` (`siteID`, `sessionDate`, `serviceTypeID`);

-- 👑 Leadership assignments — current role lookups
ALTER TABLE `tblLeadershipAssignments`
    ADD KEY `idx_leadassign_site_active_end` (`siteID`, `isActive`, `endDate`);

-- ✅ Expense approvals — status check per claim
ALTER TABLE `tblExpenseClaimApprovals`
    ADD KEY `idx_approvals_claim_decision` (`claimID`, `decision`);

-- 📋 Track migration
INSERT INTO `tblMigrations` (`filename`) VALUES ('020_composite_indexes.sql');
