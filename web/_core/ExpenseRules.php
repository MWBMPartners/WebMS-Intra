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
     * Shown on an APPROVED claim's own page to a claimant who could otherwise
     * have paid it (a treasurer or an administrator). The "Go to Treasury"
     * button is not offered on a person's own claim, and until 12 October
     * 2026 nothing was said in its place, so the page gave no clue who pays.
     * Like WHO_DECIDES it holds no apostrophe, so a test can look for it as
     * written.
     */
    public const MSG_OWN_PAYMENT_PAGE = 'This is your own claim, so you cannot record its payment yourself. Another treasurer or an administrator must pay it.';

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

    // =========================================================================
    // ✏️ WHETHER A CLAIM MAY STILL BE CHANGED (#651)
    // =========================================================================
    //
    // There was no way to change a claim at all until 28 October 2026. A
    // claimant who mistyped an amount, picked the wrong department, or found a
    // receipt had not attached could only withdraw and start again — and a
    // withdrawn claim is final (owner, 12 October 2026), so everything was
    // re-typed and every receipt re-uploaded.
    //
    // WHY THE BOUND IS WHAT IT IS, because this is the part worth getting
    // right. A whole-application review of the money pages asked whether
    // amounts can change after approval, and the answer used to be a clean
    // structural "no — nothing can change at all". An edit handler turns that
    // into a promise the code has to keep for ever.
    //
    // So the rule is NOT "a Pending claim may be edited". It is "a claim
    // NOBODY HAS DECIDED ON YET may be edited", which is a stricter thing in a
    // department with several required approvers: such a claim stays Pending
    // after the first approval, and editing it then would mean an approver's
    // recorded "Approved" sat against numbers they never saw. That is the
    // oldest fraud in expenses — get a small amount approved, then enlarge it
    // — and the status alone does not stop it.
    //
    // The claimant only, deliberately: #545 already settled that somebody
    // other than the claimant must decide and must pay, and "somebody other
    // than the claimant changed the amount being claimed" belongs to the same
    // rule with more force, not less. An administrator who needs a claim
    // changed rejects it, or asks the claimant to change it.

    /** The claim may be changed. Written as a constant so no caller invents its own idea of "fine". */
    public const EDIT_ALLOWED = '';

    /** The claim is no longer waiting for a decision (approved, rejected, paid or withdrawn). */
    public const EDIT_NOT_PENDING = 'not-pending';

    /** Somebody has already recorded a decision on it, even though it is still Pending. */
    public const EDIT_ALREADY_DECIDED = 'already-decided';

    /** The person asking did not make this claim. */
    public const EDIT_NOT_YOURS = 'not-yours';

    /** Shown when the claim has moved on from Pending. */
    public const MSG_EDIT_NOT_PENDING = 'This claim can no longer be changed, because it is not waiting for a decision any more.';

    /**
     * Shown when an approver has already decided, on a claim that is still
     * Pending because its department needs more than one approval.
     *
     * It says what to do instead, because "no" on its own leaves the person
     * stuck: the claim they can see is wrong and nothing tells them the way
     * out.
     */
    public const MSG_EDIT_ALREADY_DECIDED = 'This claim can no longer be changed, because an approver has already decided on it. To correct it, withdraw this claim and submit a new one.';

    /** Shown when somebody tries to change a claim that is not theirs. */
    public const MSG_EDIT_NOT_YOURS = 'You can only change your own expense claim. Ask the person who made this one to change it, or reject it so they can submit a corrected claim.';

    /**
     * May this claim still be changed, and if not, why not?
     *
     * THE ONE PLACE that decides. Both the form page and the save handler ask
     * it, so the page can never offer an edit the handler would refuse, and
     * the handler can never accept one the page would have hidden.
     *
     * It takes FACTS rather than a database connection on purpose. The save
     * handler must ask this twice — once on an ordinary read, to answer
     * quickly, and again on facts read INSIDE its row lock, because an
     * approval landing between those two moments must stop the edit. A method
     * that did its own reading could not be used for the second one. The
     * reading itself is `approvalCount()` below.
     *
     * It answers a REASON, not a sentence. `editRefusalWords()` turns a reason
     * into words. Keeping those apart is what lets a caller log or branch on
     * the reason without a sentence being pasted into a log, and matches how
     * `Portal\Core\Sms` handles the same shape.
     *
     * @param string $status        The claim's status, as stored.
     * @param int    $approvalCount How many decisions have been recorded against it.
     *                              Anything but 0 — including -1 for "could not
     *                              be read" — closes the claim to changes.
     * @param int    $claimantId    The account that made the claim.
     * @param int    $actorId       The account trying to change it.
     *
     * @return string One of the EDIT_* reasons; EDIT_ALLOWED ('') means yes.
     */
    public static function whyNotEditable(
        string $status,
        int $approvalCount,
        int $claimantId,
        int $actorId
    ): string {
        // 🙅 Ownership first, so somebody prodding at another person's claim is
        //    told that and nothing else about it. The order matters: answering
        //    "it has already been approved" to a stranger would confirm the
        //    claim exists and reveal where it has got to.
        if (self::isOwnClaim($claimantId, $actorId) === false) {
            return self::EDIT_NOT_YOURS;
        }

        if ($status !== 'Pending') {
            return self::EDIT_NOT_PENDING;
        }

        // 🧾 Any decision at all closes it to changes — an approval, a
        //    rejection, one of several required approvals. See the long note
        //    above for why the status is not enough by itself.
        //
        //    `!== 0`, NOT `> 0`, and the difference is the whole safety of
        //    this method. `approvalCount()` answers -1 when it could not read
        //    the count, and -1 is not greater than 0, so `> 0` would have
        //    called a claim editable precisely when the portal had no idea
        //    whether anybody had decided it. Asking for EXACTLY zero means
        //    the only answer that opens a claim to editing is a count that
        //    was really read and really was none.
        if ($approvalCount !== 0) {
            return self::EDIT_ALREADY_DECIDED;
        }

        return self::EDIT_ALLOWED;
    }

    /**
     * How many decisions have been recorded against this claim.
     *
     * Kept beside the rule so the form page and the save handler count the
     * same thing. It counts rows, not distinct approvers: one approver who
     * somehow recorded two decisions still means the claim has been decided
     * on, which is all this question asks.
     *
     * Answers -1 when the count could not be read, and `whyNotEditable()`
     * treats anything other than exactly 0 as "already decided" — so a
     * failure to read closes the claim to editing rather than opening it.
     * That is deliberate and it is checked: "we could not tell whether
     * anybody has decided this" must never become "nobody has, go ahead".
     *
     * @param mysqli $db      An open connection.
     * @param int    $claimId The claim.
     *
     * @return int The number of recorded decisions, or -1 if it could not be read.
     */
    public static function approvalCount(mysqli $db, int $claimId): int
    {
        $stmt = $db->prepare('SELECT COUNT(*) AS n FROM tblExpenseClaimApprovals WHERE claimID = ?');
        if ($stmt === false) {
            return -1;
        }
        $stmt->bind_param('i', $claimId);
        if ($stmt->execute() === false) {
            $stmt->close();

            return -1;
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            return -1;
        }

        return (int) $row['n'];
    }

    /**
     * The sentence for a reason from `whyNotEditable()`.
     *
     * THE ONE PLACE a reason becomes words, so the form page and the save
     * handler say the same thing. An unknown reason gets the most cautious
     * sentence rather than an empty one: a blank refusal tells the person
     * nothing, and a new reason added without a sentence here would otherwise
     * show them nothing at all.
     *
     * @param string $reason One of the EDIT_* reasons.
     *
     * @return string
     */
    public static function editRefusalWords(string $reason): string
    {
        return match ($reason) {
            self::EDIT_NOT_YOURS       => self::MSG_EDIT_NOT_YOURS,
            self::EDIT_NOT_PENDING     => self::MSG_EDIT_NOT_PENDING,
            self::EDIT_ALREADY_DECIDED => self::MSG_EDIT_ALREADY_DECIDED,
            default                    => self::MSG_EDIT_ALREADY_DECIDED,
        };
    }

    /**
     * The payment references in a posted value, as a clean list.
     *
     * THE ONE PLACE payment references are read. The payment handler calls it,
     * and so does its self-test, which is the whole reason it is here.
     *
     * WHY IT MOVED OUT OF THE PAGE. This parsing lived in
     * `expenses/treasury/save.php`, and its self-test held a LIFTED COPY of
     * the same few lines so it could exercise the behaviour. A round-2
     * independent check on 28 October 2026 showed what that costs: it dropped
     * `array_filter` from the HANDLER's copy only, and every check still
     * passed, because the test was exercising its own copy. With `payRef`
     * absent that drift yields `['']` rather than `[]`, which is not `empty()`,
     * so the handler's "at least one reference is required" refusal lets it
     * through and **an empty payment reference is written against a real
     * claim** — the same class of harm as the reference of literally "Array"
     * that this parsing exists to prevent. Two copies of a rule is the fault;
     * a cleverer test would not have fixed it.
     *
     * WHAT IT GUARDS. A request chooses the TYPE of what it sends as well as
     * the value (#642):
     *   * a list of text   — the ordinary case, each entry may hold commas;
     *   * a plain string   — a fair thing for a caller to send, and it used to
     *                        be a TypeError from `implode()`;
     *   * a nested array   — `implode()` does NOT stop on one. It warns and
     *                        puts the word "Array" in the result, so
     *                        `payRef[][]=x` stored a payment reference of
     *                        literally "Array": a money record pointing at
     *                        nothing, which is worse than a refusal because it
     *                        looks like a reference. Such an entry is dropped.
     *   * anything else    — no references.
     *
     * `array_filter` with no callback is deliberate and it is load-bearing: it
     * removes the empty strings that `explode()` leaves, so the caller's
     * "at least one reference" test is meaningful. It also drops a reference of
     * literally "0", which is a known wart rather than a decision (see #650).
     *
     * @param mixed $raw Whatever arrived in the request.
     *
     * @return list<string> The references, trimmed, with blanks removed.
     */
    public static function paymentReferences(mixed $raw): array
    {
        if (is_array($raw) === true) {
            $text = [];
            foreach ($raw as $entry) {
                if (is_string($entry) === true) {
                    $text[] = $entry;
                }
            }

            return array_values(array_filter(array_map('trim', explode(',', implode(',', $text)))));
        }

        if (is_string($raw) === true) {
            return array_values(array_filter(array_map('trim', explode(',', $raw))));
        }

        return [];
    }
}
