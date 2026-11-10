-- =============================================================================
-- Migration 269: the Expense Approver role says what it really gives (#648)
-- =============================================================================
-- The standard role `approver` ("Expense Approver") has been described since
-- migration 202 as "Approves or rejects expense claims". That was never true
-- on its own: the decision handler (`expenses/approve/save.php`) has always
-- asked for a lead, approver or required-approver flag in the claim's OWN
-- department. Since #648 (the owner's decision D-103, 10 November 2026) the
-- role does not open any claim either, and the handler no longer lets it past
-- its first check (#915). What the role gives is the claims spreadsheet of the
-- departments its holder approves for, and this is the sentence an
-- organisation reads on the Roles page when deciding whom to give it to.
--
-- Rewritten ONLY where it is still the old sentence, so an organisation that
-- has written its own description keeps it (the shape migration 260 used for
-- the Website Editor role).
--
-- `Portal\Core\Roles::STANDARD` carries the new sentence for organisations
-- created from now on; migration 202's own seed is never edited.
--
-- NO DATA OTHER THAN THAT ONE TEXT CHANGES. Nobody gains or loses the role.
--
-- SAFE TO RUN TWICE: the second run finds no row still holding the old
-- sentence and changes nothing.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/648
-- =============================================================================

UPDATE `tblRoles`
   SET `description` = 'Downloads the expense claims spreadsheet for the departments they approve for. Deciding and opening claims comes from being a department''s lead or approver.'
 WHERE `roleKey` = 'approver'
   AND `description` = 'Approves or rejects expense claims';
