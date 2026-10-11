<?php
// Path: _core/ExpenseRules.php
/**
 * -----------------------------------------------------------------------------
 * Expense claims: nobody decides or pays their own 💷 (#545)
 * -----------------------------------------------------------------------------
 * One rule, written once: the person who made an expense claim may not
 * approve it, reject it, or record it as paid. Somebody else must.
 *
 * WHAT WAS WRONG
 * --------------
 * Neither the approval handler (`expenses/approve/save.php`) nor the payment
 * handler (`expenses/treasury/save.php`) ever compared the claim's owner
 * with the person deciding. A department lead could approve their own
 * claim; a treasurer could then record their own claim as paid; and an
 * administrator could do both. The portal would show a claim that had been
 * raised, approved and paid by one person as if three people had looked at
 * it. The owner decided on 21 September 2026 to refuse both outright,
 * administrators included.
 *
 * WHY IT LIVES IN ITS OWN CLASS
 * -----------------------------
 * Both handlers, the list pages, the mailer and the self-test
 * (`tools/expense-rules-selftest.php`) must mean exactly the same thing by
 * "own claim" and by "who still has to approve". Written separately in each
 * file, one of them would drift.
 *
 * THE PART THAT IS EASY TO MISS
 * -----------------------------
 * A claim is approved once every lead and every required approver of its
 * department has approved it. Before #545 that list could include the
 * claimant. Refusing a claimant's own approval while still waiting for it
 * would leave the claim stuck for ever, so `requiredApprovers()` leaves the
 * claimant out. When that leaves nobody, one approval from anybody else
 * with authority over the department is enough, which is what the approval
 * handler has always done for a department with no required approvers.
 *
 * WHAT THIS CLASS CANNOT DO
 * -------------------------
 * - It does not decide who has authority to approve or pay at all. That
 *   stays with the handlers (an administrator, the Expense Approver role
 *   with a department flag, a department flag alone; the Treasurer role).
 * - It compares account numbers. One person with two accounts is two
 *   people as far as this rule can tell.
 * - It does not look at claims already approved or paid before #545. A
 *   claim somebody approved or paid for themselves before this change
 *   keeps its history as recorded.
 * - If a department's ONLY approver is the claimant and the organisation
 *   has no administrator, nobody can decide the claim until an approver is
 *   added. That is the rule working, not a fault.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/545
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

final class ExpenseRules
{
    /**
     * Who decides a claim its own claimant may not. One sentence, used by
     * the refusal below and by the claimant's own claim page, so the two
     * can never say different things.
     *
     * It has to be true in BOTH cases, which the first wording ("Another
     * approver or an administrator must decide it") was not. Where the
     * department has other leads or required approvers, the claim needs
     * every one of them, and an administrator's approval does not stand in
     * for theirs; an administrator could approve such a claim and it would
     * still be waiting. Only where there is nobody like that does one
     * approval, from another approver of the department or from an
     * administrator, finish it. (Found by the independent check of
     * 11 October 2026.) It contains no apostrophe or other character that
     * a page would print differently, so a test can look for it as written.
     */
    public const WHO_DECIDES = 'It must be decided by the other leads and required approvers of its department or, when there are none, by another approver or an administrator.';

    /** Shown when somebody tries to approve or reject a claim they made. */
    public const MSG_OWN_DECISION = 'You cannot approve or reject your own expense claim. ' . self::WHO_DECIDES;

    /** Shown on a claim's own page to a claimant who could otherwise have decided it. */
    public const MSG_OWN_CLAIM_PAGE = 'This is your own claim, so you cannot decide it yourself. ' . self::WHO_DECIDES;

    /** Shown when somebody tries to record a claim they made as paid. */
    public const MSG_OWN_PAYMENT = 'You cannot record payment of your own expense claim. Another treasurer or an administrator must pay it.';

    /**
     * Is this claim the acting person's own?
     *
     * An account number of 0 or less is "nobody" and never owns a claim:
     * both handlers require a sign-in first, so 0 can only come from a bug,
     * and "nobody's claim is everybody's own" would be the wrong way for
     * such a bug to fail.
     *
     * @param int $claimantId The account that made the claim (`tblExpenseClaims.userID`).
     * @param int $actorId    The account trying to decide or pay it.
     *
     * @return bool
     */
    public static function isOwnClaim(int $claimantId, int $actorId): bool
    {
        return $claimantId > 0 && $claimantId === $actorId;
    }

    /**
     * Everybody whose approval a claim still needs before it counts as
     * approved: each lead and each required approver of the claim's
     * department, in the claim's own organisation, whose membership of
     * that organisation is still active — EXCEPT the claimant.
     *
     * This is the query that used to sit inside `expenses/approve/save.php`,
     * with one condition added (`UD.userID <> ?`). Everything else is kept
     * as it was, on purpose:
     *   - only an ACTIVE membership counts (#517: a required approver who
     *     had left the organisation used to stay on the list for ever, so
     *     the claim could never be approved);
     *   - only the claim's own organisation's rows count;
     *   - there is NO test that the department is switched on: a retired
     *     department's pending claims are finished by its own approvers
     *     (owner's answer Q3, 21 September 2026);
     *   - an administrator's approval never stands in for one of these
     *     people's (owner, 21 September 2026, #542).
     *
     * @param mysqli $db         An open connection.
     * @param int    $deptId     The claim's department.
     * @param int    $siteId     The claim's organisation.
     * @param int    $claimantId The account that made the claim.
     *
     * @return list<int> Account numbers, in no particular order. Empty when
     *                   nobody but the claimant is a lead or required
     *                   approver; the caller then treats one approval from
     *                   anybody else with authority as enough.
     *
     * @throws \RuntimeException If the question could not be asked at all.
     *         A list that could not be read is not an empty list, and an
     *         empty list here means "one approval is enough". So this stops;
     *         the handler's own error handling then undoes the half-recorded
     *         decision and says to try again.
     *
     *         In the running portal a query that cannot be prepared already
     *         throws by itself (`bootstrap.php` switches the database driver
     *         to report errors that way), so the handler's own copy of this
     *         query stopped in that case too. Its `if ($stmt !== false)`
     *         would only have mattered with that reporting switched off,
     *         and then it would have carried on with an empty list. The
     *         explicit throw below keeps the answer the same whichever way
     *         the driver is set; it is a safeguard, not the fix for a fault
     *         that was ever seen. (An earlier version of this comment said
     *         the old code "used to carry on with an empty list", which
     *         overstated it.)
     */
    public static function requiredApprovers(mysqli $db, int $deptId, int $siteId, int $claimantId): array
    {
        $approvers = [];
        $stmt = $db->prepare(
            'SELECT UD.userID FROM tblUserDepts UD '
            . 'JOIN tblUserSites US ON US.userID = UD.userID AND US.siteID = UD.siteID AND US.isActive = 1 '
            . 'WHERE UD.deptID = ? AND UD.siteID = ? AND (UD.isDeptLead = 1 OR UD.isMandatoryApprover = 1) '
            . 'AND UD.userID <> ?'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Could not read the required approvers of department ' . $deptId . ': ' . $db->error);
        }
        $stmt->bind_param('iii', $deptId, $siteId, $claimantId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $approvers[] = (int) $row['userID'];
        }
        $stmt->close();

        return $approvers;
    }
}
