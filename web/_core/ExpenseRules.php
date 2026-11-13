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
 * ONE METHOD HERE IS DIFFERENT, so that nobody has to discover it: every
 * other method takes facts or a `mysqli` and answers from them alone, but
 * `mayExportClaims()` reads the session and the open organisation through
 * `App::`. That is on purpose — it answers a question about the person being
 * served right now, and passing that in would invite a caller to supply a
 * stale answer. It is here, rather than in the page or the handler, because
 * BOTH of them have to ask it and they must never be able to disagree (#675).
 * `reachToSee()` and `reachToDecide()` (#648) read the session the same way
 * and for the same reason; their pure core, `reachFrom()`, takes facts.
 *
 * WHAT THIS CLASS CANNOT DO
 * -------------------------
 * - It does not decide who has authority to approve or pay at all. That
 *   stays with the handlers (an administrator, the Expense Approver role
 *   with a department flag, a department flag alone; the Treasurer role).
 *   `mayExportClaims()` is not an exception: downloading the list is neither
 *   approving nor paying.
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

    // 📤 ─────────────────────────────────────────────────────────────────────
    //    WHO MAY DOWNLOAD THE CLAIMS SPREADSHEET (#675)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * The role key that may download the claims spreadsheet, alongside an
     * administrator.
     *
     * It is `approver` — the standard role seeded as "Expense Approver" — and
     * NOT the `isApprover` flag on `tblUserDepts`, which is a different thing
     * with a confusingly similar name. A person can hold either without the
     * other, and the two answer different questions:
     *
     *   * `tblUserDepts.isApprover` — may decide the claims OF ONE DEPARTMENT.
     *   * the `approver` role        — trusted with the organisation's claims
     *                                  as a whole.
     *
     * The download is the whole organisation's claims in one file, with every
     * claimant's name, so it is the second of those. Widening it to department
     * approvers would hand somebody every department's figures when the page
     * they came from shows them only their own; that is a deliberate decision
     * left open as its own question, not an oversight here.
     */
    // 🚪 `EXPORT_ROLE = 'approver'` USED TO BE DECLARED HERE AND IS GONE
    //    (#739). `mayExportClaims()` below was its only reader, and that gate
    //    is now the `expenses.approve` capability, whose entry in
    //    `Portal\Core\Capabilities` records `approver` as the role it ships
    //    with. Keeping the constant too would be a second place to read the
    //    shipped role from, and an organisation that re-pointed the
    //    capability would still be refused by anything that asked for this
    //    key. The paragraph above still describes the decision correctly —
    //    the organisation-wide role, not `tblUserDepts.isApprover` (#675).

    /**
     * 📤 May the person signed in right now download the claims spreadsheet?
     *
     * THE ONE PLACE THAT DECIDES THIS. Both the button and the handler ask it,
     * which is the whole point of its existing.
     *
     * WHAT WAS WRONG BEFORE (#675, measured over real HTTP on 30 October 2026)
     * -----------------------------------------------------------------------
     * The rule lived only in `expenses/api/export.php`, and the Export button
     * on `expenses/approve` was drawn UNCONDITIONALLY. That page has no gate
     * beyond being signed in — it simply shows an empty list to somebody with
     * nothing to decide — so every signed-in member saw the button. Pressing
     * it gave a bare, unstyled `Forbidden`: no portal page, no message, no way
     * back. Measured on six kinds of account, four of them were refused that
     * way, and they included the department leads and department approvers the
     * approvals page exists to serve.
     *
     * Note what this does NOT change: who may download. The rule is exactly
     * the one the handler already applied. All that is new is that the button
     * is no longer offered to people it refuses, and that there is one rule
     * instead of two copies of it.
     *
     * It takes no arguments on purpose. `App::isAdmin()` and `App::can()`
     * both read the session and the open organisation, so the answer belongs
     * to the request being served; passing either in would invite a caller to
     * supply a stale one.
     *
     * 🚪 #739: was `App::hasRole(self::EXPORT_ROLE)`, i.e. the `approver` role
     *    written into this file. It is now the `expenses.approve` capability,
     *    which an organisation may point at roles of its own on the Roles
     *    page. It SHIPS pointing at `approver` and falls open for a global
     *    administrator, so on an installation that has never opened that page
     *    this answers exactly what it answered before.
     *
     *    The spreadsheet is part of the SAME door as deciding a claim, on
     *    purpose: it is the same people and the same list of claims seen
     *    another way, so a separate switch would be a second control
     *    answering one question. Widening it to a department's own approvers
     *    is still #681 and is still a decision rather than an oversight.
     *
     * A global administrator is admitted by the capability itself, because
     * `expenses.approve` declares `globalAdminFallsOpen` — so there is no
     * separate test for one here, and adding one would be a second rule.
     *
     * ⚠️ THIS SAYS WHETHER, NOT WHICH (#648, D-103). Being allowed the file
     *    is no longer being allowed every claim in it. An administrator and a
     *    treasurer get every claim of the organisation; a holder of the
     *    `expenses.approve` capability gets the claims of the departments
     *    they approve for and nothing else — which, for somebody holding the
     *    role and no department at all, is the heading row alone. The rows
     *    are decided by `reachToSee()` and filtered inside the query by
     *    `reachSql()`; never filter them after reading.
     *
     * @return bool True when this person may download the spreadsheet.
     */
    public static function mayExportClaims(): bool
    {
        if (App::isAdmin() === true) {
            return true;
        }

        // 💷 D-040 (the owner, 1 November 2026): a treasurer may download the
        //    spreadsheet alongside an approver. Decided on #541 (item 2) and
        //    not built until #648: a treasurer already sees every claim on the
        //    claim page and pays the approved ones, so refusing them the file
        //    of what to pay was the odd case rather than a safeguard. WHICH
        //    rows they get is `reachToSee()`, below — every claim, as on the
        //    claim page.
        if (App::can(Capabilities::EXPENSES_TREASURY) === true) {
            return true;
        }

        return App::can(Capabilities::EXPENSES_APPROVE);
    }

    // 👁️ ─────────────────────────────────────────────────────────────────────
    //    WHOSE CLAIMS A PERSON MAY SEE (#648, D-103)
    // ────────────────────────────────────────────────────────────────────────
    //
    // WHAT WAS WRONG. The organisation-wide Approver role (now the
    // `expenses.approve` capability) meant two different things in four
    // places. The approvals list and the decision handler both said, and did,
    // "only the departments you approve for": a role-holder who is not a
    // lead, approver or required approver of a claim's department is shown
    // none of its claims and may decide none of them. But the claim page let
    // the role through before it looked at departments at all, so it opened
    // ANY claim in the organisation in full — line items, suppliers, receipts
    // — and the spreadsheet download had no department condition at all, so
    // it handed over every claimant's name, department, title, date and
    // amount in one file. Expense claims are personal financial information.
    //
    // THE OWNER'S ANSWER (D-103, 10 November 2026): the role sees and
    // downloads only the claims of departments its holder approves for,
    // exactly as the approvals list and the decision page already work.
    // Administrators and the treasurer keep seeing everything, as before.
    //
    // HOW IT IS KEPT TO ONE RULE. A "reach" is worked out once per request
    // and then asked two ways:
    //
    //   * `mayViewClaim()` — one claim, in PHP, by the claim page.
    //   * `reachSql()`     — a list, as a condition INSIDE the query, by the
    //                        spreadsheet and by the approvals list. Filtering
    //                        rows after reading them would put every claim in
    //                        PHP's memory first and make the filter one
    //                        forgotten line away from not happening.
    //
    // Both read the same two fields of the same array, side by side in this
    // class, so they cannot drift without somebody editing one of them here.
    //
    // TWO REACHES, AND THE DIFFERENCE IS DELIBERATE.
    //   * `reachToSee()`    — what a person may READ: an administrator or a
    //                         treasurer, everything; anybody else, the
    //                         departments they approve for.
    //   * `reachToDecide()` — what a person may DECIDE: an administrator,
    //                         everything; anybody else, the departments they
    //                         approve for. A treasurer PAYS an approved claim;
    //                         they do not decide one, so the approvals list
    //                         does not offer them other departments' claims.
    //                         `expenses/approve/save.php` applies the same rule
    //                         through `Departments::approverDepts()` directly,
    //                         because it also needs WHICH flag the person holds
    //                         to record their part in the decision.
    //
    // WHAT GIVES SOMEBODY A DEPARTMENT. `Departments::approverDepts()`, the
    // ONE read of the lead / approver / required-approver flags through an
    // active membership of this organisation — the same read the decision
    // handler and (since #542) the claim page already used. The role itself
    // gives NO department: that is the whole of D-103. Retired departments are
    // deliberately included, as everywhere else in Expenses (owner's answer
    // Q3, 21 September 2026: a retired department's waiting claims are still
    // finished by its own approvers).
    //
    // WHAT THIS DOES NOT DECIDE.
    //   * The claimant's OWN claims. `mayViewClaim()` lets the claimant in
    //     first, whatever their reach. The two lists leave a person's own
    //     claims to their own Expenses page: the approvals list never shows
    //     them (#545), and the spreadsheet follows the reach alone, so a
    //     role-holder's own claim in another department is not in their file.
    //   * Who may download the file AT ALL — that is `mayExportClaims()`.
    //   * The organisation. Every caller still asks `EC.siteID = ?`. A reach
    //     names departments, and its department numbers come from this
    //     organisation's own membership rows, but the organisation test stays
    //     in each query as the first boundary.

    /**
     * Build a reach from facts. Pure — no connection, no session — so the
     * self-test can drive every combination by hand.
     *
     * @param bool              $seesEveryClaim True for somebody who may see every claim of
     *                                          the organisation (an administrator; for
     *                                          reading, a treasurer too).
     * @param array<int, mixed> $approverDepts  `Departments::approverDepts()`'s answer, keyed
     *                                          by department number. Only the KEYS are read.
     *
     * @return array{all: bool, depts: list<int>} `all` true means every claim; otherwise
     *                                            `depts` is the sorted, de-duplicated list of
     *                                            department numbers, possibly empty.
     */
    public static function reachFrom(bool $seesEveryClaim, array $approverDepts): array
    {
        if ($seesEveryClaim === true) {
            return ['all' => true, 'depts' => []];
        }
        $ids = [];
        foreach (array_keys($approverDepts) as $key) {
            $id = (int) $key;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        ksort($ids);

        return ['all' => false, 'depts' => array_values($ids)];
    }

    /**
     * What the person being served may READ (the claim page, the
     * spreadsheet). An administrator or a treasurer: every claim. Anybody
     * else, the `expenses.approve` role included: the departments they
     * approve for.
     *
     * The department read is skipped for somebody who sees everything, so
     * their cost is what it was. For everybody else it happens on every call,
     * BEFORE any claim is looked up — which is what lets the claim page treat
     * "out of reach" and "no such claim" with the same database work as well
     * as the same answer.
     *
     * Reads the session through `App::`, like `mayExportClaims()` and for the
     * same reason: it answers a question about the person being served now.
     *
     * @param mysqli $db     An open connection.
     * @param int    $userId The signed-in account.
     * @param int    $siteId The open organisation.
     *
     * @return array{all: bool, depts: list<int>}
     */
    public static function reachToSee(mysqli $db, int $userId, int $siteId): array
    {
        // 💷 The treasurer's "everything" is what the claim page already gave
        //    them before #648 (`$hasTreasurer` let them open any claim), and
        //    D-103 keeps it: they pay claims from every department.
        $everything = App::isAdmin() === true || App::can(Capabilities::EXPENSES_TREASURY) === true;
        if ($everything === true) {
            return self::reachFrom(true, []);
        }

        return self::reachFrom(false, Departments::approverDepts($db, $userId, $siteId));
    }

    /**
     * What the person being served may DECIDE (the approvals list). An
     * administrator: every claim. Anybody else — a treasurer and the
     * `expenses.approve` role included — the departments they approve for,
     * which is exactly what `expenses/approve/save.php` lets them decide.
     *
     * @param mysqli $db     An open connection.
     * @param int    $userId The signed-in account.
     * @param int    $siteId The open organisation.
     *
     * @return array{all: bool, depts: list<int>}
     */
    public static function reachToDecide(mysqli $db, int $userId, int $siteId): array
    {
        if (App::isAdmin() === true) {
            return self::reachFrom(true, []);
        }

        return self::reachFrom(false, Departments::approverDepts($db, $userId, $siteId));
    }

    /**
     * Does this reach include a claim of this department?
     *
     * Anything that is not exactly `true` in `all` is NOT everything, and a
     * department not in the list is not reached — so a malformed reach
     * reaches nothing rather than everything.
     *
     * @param array<string, mixed> $reach  From `reachFrom()`, `reachToSee()` or `reachToDecide()`.
     * @param int                  $deptId The claim's department.
     *
     * @return bool
     */
    public static function reachCovers(array $reach, int $deptId): bool
    {
        if (($reach['all'] ?? false) === true) {
            return true;
        }
        if ($deptId <= 0 || is_array($reach['depts'] ?? null) === false) {
            return false;
        }

        return in_array($deptId, $reach['depts'], true);
    }

    /**
     * May this person open this claim? Their own claim, always; otherwise only
     * a claim of a department their reach covers.
     *
     * A claim that fails this must be answered EXACTLY as a claim that does
     * not exist (the claim page answers both with the same 404), so that
     * nobody learns from the answer that a claim number is real — the money
     * pages' rule that ownership is answered before anything else is said
     * about a claim.
     *
     * @param array<string, mixed> $reach       From `reachToSee()`.
     * @param int                  $claimantId  `tblExpenseClaims.userID`.
     * @param int                  $claimDeptId `tblExpenseClaims.deptID`.
     * @param int                  $viewerId    The signed-in account.
     *
     * @return bool
     */
    public static function mayViewClaim(array $reach, int $claimantId, int $claimDeptId, int $viewerId): bool
    {
        if (self::isOwnClaim($claimantId, $viewerId) === true) {
            return true;
        }

        return self::reachCovers($reach, $claimDeptId);
    }

    /**
     * The same reach as a condition for a query's WHERE clause, on the
     * claim's department column, with its bound values.
     *
     *   everything        → `1 = 1`, nothing bound
     *   no department     → `1 = 0`, nothing bound — the query answers no rows,
     *                       which for the spreadsheet is the heading row alone
     *   some departments  → `<column> IN (?, ?, …)`, one integer per department
     *
     * The column name is code, never input, and is refused unless it is a
     * plain `alias.column` — it is put into the SQL as written, so this is the
     * line that keeps that safe. Department numbers are always BOUND.
     *
     * Like `reachCovers()`, a malformed reach reaches nothing.
     *
     * @param array<string, mixed> $reach      From `reachToSee()` or `reachToDecide()`.
     * @param string               $deptColumn For example `EC.deptID`.
     *
     * @return array{sql: string, types: string, params: list<int>}
     *
     * @throws \InvalidArgumentException When the column is not a plain `alias.column`.
     */
    public static function reachSql(array $reach, string $deptColumn): array
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*$/', $deptColumn) !== 1) {
            throw new \InvalidArgumentException('reachSql() takes a plain alias.column, not ' . $deptColumn);
        }
        if (($reach['all'] ?? false) === true) {
            return ['sql' => '1 = 1', 'types' => '', 'params' => []];
        }
        $ids = [];
        foreach ((is_array($reach['depts'] ?? null) === true ? $reach['depts'] : []) as $id) {
            if (is_int($id) === true && $id > 0) {
                $ids[$id] = $id;
            }
        }
        if (count($ids) === 0) {
            return ['sql' => '1 = 0', 'types' => '', 'params' => []];
        }
        $ids = array_values($ids);

        return [
            'sql'    => $deptColumn . ' IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
            'types'  => str_repeat('i', count($ids)),
            'params' => $ids,
        ];
    }

    /* ---------------------------------------------------------------------- */
    /* 📎 Receipts attached to a new claim (#649)                              */
    /* ---------------------------------------------------------------------- */
    //
    // WHAT WAS WRONG. `expenses/submit/save.php` skipped a receipt it would not
    // keep with a bare `continue` — a file over 10 MB, a kind of file not on
    // the list, a file the server refused before PHP saw it, and a file the
    // portal could not move into its folder — and then saved the claim and
    // sent the claimant on as though everything had gone. In an expenses
    // system the receipt IS the evidence: the claimant believed it was
    // attached, and the approver saw a claim with nothing behind it. The
    // checks themselves were right and are kept exactly; the fault was only
    // that a refusal said nothing.
    //
    // WHY IT LIVES HERE. The rule (what may be attached) and the words (what
    // the claimant is told) are decided once, in this class, so the handler
    // and its self-test (`tools/expense-receipts-selftest.php`) cannot hold two
    // copies that drift (#647: a test holding its own copy tests nothing).
    //
    // WHAT IT DECIDED, and what it rules out. A file that cannot be attached
    // does NOT refuse the whole claim — the claim is saved, and the claimant
    // is told by name which files were not attached and why. That is the
    // recommendation in #649 itself: losing a typed claim is worse than a
    // claim saved without one receipt, and the claimant can withdraw it and
    // submit it again. The ONE case that refuses the whole claim is the
    // portal being unable to make its receipts folder at all (also #649's own
    // wording), because then every receipt would fail and the claim would
    // reach the approver with none of them.

    /**
     * The kinds of file a receipt may be, by the end of its name.
     *
     * `heic` and `heif` were added for #649: they are what an iPhone's camera
     * saves by default, and the issue asked whether they should be accepted.
     * They are accepted because a receipt is evidence somebody keeps, not a
     * picture the portal shows — the portal never draws a receipt on a page or
     * puts one in a generated PDF (`ExpensePdf` prints the claim's figures
     * only), and the file is stored outside the web root under a name the
     * portal makes up. A computer without a HEIC viewer will not open one,
     * which is the cost of accepting it; refusing it would turn the commonest
     * phone photo into a refusal. Change this list here and nowhere else.
     */
    public const RECEIPT_TYPES = [
        'jpg', 'jpeg', 'png', 'gif', 'heic', 'heif',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv',
    ];

    /** The most one receipt may be, in bytes: 10 MB, as it always was. */
    public const RECEIPT_MAX_BYTES = 10 * 1024 * 1024;

    /** The receipt may be attached. */
    public const RECEIPT_OK = 'ok';

    /** The slot was empty — nothing was chosen. Not a receipt at all, so never reported. */
    public const RECEIPT_NOTHING_CHOSEN = 'nothing-chosen';

    /** Over RECEIPT_MAX_BYTES. */
    public const RECEIPT_TOO_BIG = 'too-big';

    /** Over the server's own limit for one file, so PHP refused it before the portal saw it. */
    public const RECEIPT_TOO_BIG_FOR_SERVER = 'too-big-for-server';

    /** Only part of the file arrived. */
    public const RECEIPT_PARTIAL = 'partial';

    /** A file with nothing in it — an empty file is no evidence. */
    public const RECEIPT_EMPTY = 'empty';

    /** The end of its name is not on RECEIPT_TYPES. */
    public const RECEIPT_WRONG_TYPE = 'wrong-type';

    /**
     * The portal could not store it: PHP had nowhere to put it, could not
     * write it, an extension stopped it, or moving it into the receipts
     * folder failed. A fault on the server, not in the claimant's file.
     */
    public const RECEIPT_NOT_STORED = 'not-stored';

    /**
     * Shown when the portal cannot make its receipts folder, so the claim is
     * NOT saved. The one case #649 asks to refuse the whole submission: every
     * receipt would fail, and a claim would reach the approver with none.
     *
     * It used to end "nothing you typed was kept", which was true while the
     * form came back empty after every refusal. Since #870 the form is filled
     * in again with what was typed — but NEVER with the receipts, which a
     * browser will not let a page choose for somebody — so it says that now.
     */
    public const MSG_RECEIPT_FOLDER_MISSING = 'Your claim was NOT saved, because the portal could not make the folder it keeps receipts in. This is a fault on the server, not in your claim, and it has been recorded for your administrator. Please tell them, and submit the claim again once it is fixed. What you typed has been filled in again below, but your receipts have not: choose them again.';

    /**
     * The upload field, as a plain list of one entry per file.
     *
     * A request chooses the TYPE of what it sends as well as the value (#642).
     * The form posts `files[]`, which PHP turns into parallel arrays; a
     * request posting `files` once (no brackets) gives single values instead,
     * and anything else may arrive. The old loop iterated
     * `$_FILES['files']['error']` directly, so a single value was not a list
     * to walk. Both shapes are read here; anything else is an empty list.
     *
     * @param mixed $field `$_FILES['files'] ?? null`.
     *
     * @return list<array{error:int,name:string,tmp:string,size:int}>
     */
    public static function receiptsFromUpload(mixed $field): array
    {
        if (is_array($field) === false || isset($field['error']) === false) {
            return [];
        }
        $one = static fn (mixed $error, mixed $name, mixed $tmp, mixed $size): array => [
            'error' => is_int($error) === true ? $error : UPLOAD_ERR_NO_FILE,
            'name'  => is_string($name) === true ? $name : '',
            'tmp'   => is_string($tmp) === true ? $tmp : '',
            'size'  => is_int($size) === true ? $size : 0,
        ];
        if (is_array($field['error']) === false) {
            return [$one($field['error'], $field['name'] ?? '', $field['tmp_name'] ?? '', $field['size'] ?? 0)];
        }
        $names = is_array($field['name'] ?? null) === true ? $field['name'] : [];
        $tmps  = is_array($field['tmp_name'] ?? null) === true ? $field['tmp_name'] : [];
        $sizes = is_array($field['size'] ?? null) === true ? $field['size'] : [];
        $list  = [];
        foreach ($field['error'] as $i => $error) {
            $list[] = $one($error, $names[$i] ?? '', $tmps[$i] ?? '', $sizes[$i] ?? 0);
        }

        return $list;
    }

    /**
     * Why a file may NOT be attached as a receipt, or RECEIPT_OK.
     *
     * Takes FACTS, so the handler and the self-test ask the same question.
     * The order matters: what PHP said about the upload comes first, because
     * a file PHP refused has no size or contents worth judging.
     *
     * It does NOT cover the last step — moving the file into the receipts
     * folder — because that is an action, not a fact the request carries. The
     * handler reports a failed move as RECEIPT_NOT_STORED itself.
     *
     * @param int    $uploadError PHP's `UPLOAD_ERR_*` code for this file.
     * @param int    $size        Its size in bytes, as PHP reported it.
     * @param string $clientName  The name the claimant's computer gave it. Read
     *                            ONLY for the end of the name; never stored (#714).
     *
     * @return string One of the RECEIPT_* constants.
     */
    public static function whyReceiptRefused(int $uploadError, int $size, string $clientName): string
    {
        if ($uploadError === UPLOAD_ERR_NO_FILE) {
            return self::RECEIPT_NOTHING_CHOSEN;
        }
        if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
            return self::RECEIPT_TOO_BIG_FOR_SERVER;
        }
        if ($uploadError === UPLOAD_ERR_PARTIAL) {
            return self::RECEIPT_PARTIAL;
        }
        if ($uploadError !== UPLOAD_ERR_OK) {
            // UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION,
            // and any code a later PHP adds: none is the claimant's doing.
            return self::RECEIPT_NOT_STORED;
        }
        if ($size > self::RECEIPT_MAX_BYTES) {
            return self::RECEIPT_TOO_BIG;
        }
        if ($size <= 0) {
            return self::RECEIPT_EMPTY;
        }
        $ext = strtolower(pathinfo(basename($clientName), PATHINFO_EXTENSION));
        if (in_array($ext, self::RECEIPT_TYPES, true) === false) {
            return self::RECEIPT_WRONG_TYPE;
        }

        return self::RECEIPT_OK;
    }

    /**
     * The reason, as the end of a sentence the claimant reads.
     *
     * THE ONE PLACE a refusal becomes words. An unknown reason gets the
     * "could not store it" words rather than nothing, because a blank reason
     * tells the claimant nothing and a new reason added without words here
     * must still say SOMETHING true: the file was not attached.
     *
     * @param string $reason      One of the RECEIPT_* refusals.
     * @param string $serverLimit The server's own limit for one file, as PHP
     *                            states it (`upload_max_filesize`, e.g. "8M"),
     *                            or '' when it is not known. Used only for
     *                            RECEIPT_TOO_BIG_FOR_SERVER.
     *
     * @return string
     */
    public static function receiptRefusalWords(string $reason, string $serverLimit = ''): string
    {
        $limit = trim($serverLimit);
        if (preg_match('/^(\d+)\s*([KMG])$/i', $limit, $m) === 1) {
            $limit = $m[1] . ' ' . strtoupper($m[2]) . 'B';
        }

        // ✏️ EACH REASON SAYS WHAT TO CHANGE, not just what went wrong (round 1
        //    of the independent check, 9 November 2026). The advice after the
        //    list used to say "submit it again with the file", which for a file
        //    that is too big, of the wrong kind or empty would only be refused
        //    again. No reason contains a semicolon: the list of files joins
        //    its entries with one.
        return match ($reason) {
            self::RECEIPT_TOO_BIG            => 'it is larger than 10 MB, the most one receipt may be, so it needs a smaller copy, such as a photo taken at a lower size or a scan saved as a PDF',
            self::RECEIPT_TOO_BIG_FOR_SERVER => $limit !== ''
                ? 'it is larger than this server accepts for one file (' . $limit . '), so it needs a smaller copy'
                : 'it is larger than this server accepts for one file, so it needs a smaller copy',
            self::RECEIPT_PARTIAL            => 'only part of it arrived, so it was not kept, and choosing the same file again usually works',
            self::RECEIPT_EMPTY              => 'the file is empty, so check that you chose the right file',
            self::RECEIPT_WRONG_TYPE         => 'it is not a kind of file a receipt may be, so it needs saving as a photo or picture (JPG, PNG, GIF or HEIC), a PDF, or a Word, Excel or CSV file',
            // "Recorded for your administrator" is TRUE because the handler
            // writes an error-log line for EVERY file with this reason, in one
            // place after the claim is saved — PHP's own refusals (codes 6, 7
            // and 8) as well as a failed move. Round 1 of the check found the
            // first version wrote one only for a failed move.
            default                          => 'the portal could not store it, which is a fault on the server rather than in your file and has been recorded for your administrator, so the same file can be tried again once that is put right',
        };
    }

    /**
     * The words PHP itself uses, in its start-up warning, when a request
     * carries more files than `max_file_uploads` allows. PHP then DROPS the
     * files beyond the limit before any portal code runs — they are simply
     * absent from `$_FILES`, with no error code and no name.
     */
    public const UPLOAD_COUNT_WARNING = 'Maximum number of allowable file uploads has been exceeded';

    /** No file was dropped for being past the server's limit on how many files one request may carry. */
    public const CUT_SHORT_NO = 'no';

    /**
     * PHP said so: something past the limit was dropped.
     *
     * ⚠️ "CERTAIN" IS ABOUT THE DROPPING, NOT ABOUT REAL FILES (round 2 of the
     *    independent check). PHP raises the same warning for an EMPTY box that
     *    arrives past the limit — with JavaScript off the form has a second
     *    box after the first — even when every real file arrived. The words
     *    for this case therefore say "anything you chose beyond the first N",
     *    never that a file of yours was lost.
     */
    public const CUT_SHORT_CERTAIN = 'certain';

    /**
     * PHP's warning could not be read, but exactly as many files arrived as
     * the server allows, so more may have been chosen and dropped. The words
     * for this case say "if", because it cannot be told from a claim that
     * really had exactly that many.
     */
    public const CUT_SHORT_POSSIBLE = 'possible';

    /**
     * Were files dropped by PHP for being past `max_file_uploads`? (#649,
     * round 1 of the independent check.)
     *
     * MEASURED, not assumed: with `max_file_uploads=3` and four receipts the
     * fourth simply was not there — three were attached, the claimant was
     * told "Your claim #8 has been submitted.", and the fourth was named
     * nowhere. PHP's warning is raised before any script runs, so no error
     * handler sees it, and `error_get_last()` answers it only until anything
     * else raises a notice.
     *
     * ⚠️ THE FALLBACK IS NOT OPTIONAL — DO NOT DELETE IT (round 2 of the check).
     *    The handler reads the warning on its own first line, but the portal's
     *    start-up, `index.php`, the session start and the router all run BEFORE
     *    that line. Measured: a session clean-up notice (PHP's session garbage
     *    collection) replaced the warning, and the "certain" answer was lost.
     *    So the warning is a bonus when it survives, and the count is what the
     *    handler can always rely on: as many files as the limit allows is the
     *    one state in which some may be missing, and its words say "if".
     *
     * @param mixed $lastError What `error_get_last()` answered.
     * @param int   $received  How many FILES arrived — empty boxes NOT counted,
     *                         because PHP does not count them towards its limit
     *                         either (measured in section I of the self-test).
     * @param int   $limit     `max_file_uploads`; 0 or less means not known.
     *
     * @return string One of the CUT_SHORT_* constants.
     */
    public static function uploadsCutShort(mixed $lastError, int $received, int $limit): string
    {
        if (is_array($lastError) === true
            && is_string($lastError['message'] ?? null) === true
            && str_contains($lastError['message'], self::UPLOAD_COUNT_WARNING) === true) {
            return self::CUT_SHORT_CERTAIN;
        }
        if ($limit > 0 && $received >= $limit) {
            return self::CUT_SHORT_POSSIBLE;
        }

        return self::CUT_SHORT_NO;
    }

    /**
     * A file's own name, made safe to show back to the claimant.
     *
     * ⚠️ WHY SHOWING IT IS NOT #714 COMING BACK. #714 stopped the claimant's
     *    own file name being STORED — in the database and as the file's name on
     *    disk — because a claim is kept six years and no erasure could reach
     *    it. Here the name goes only into the one-off message on the very next
     *    page, which that page reads and deletes from the session as it draws
     *    it. It is never written to the database, the activity log or the error
     *    log. Without it the claimant could not tell WHICH of their files was
     *    not attached, which is the whole point of #649.
     *
     * Control characters are removed and a long name is shortened, so a
     * crafted name cannot flood the message. The page escapes the result.
     *
     * ⚠️ A NAME THAT IS NOT VALID UTF-8 IS SCRUBBED FIRST. A computer set to an
     *    older character set sends "Café" as the single byte E9, and
     *    `htmlspecialchars()` answers an EMPTY STRING for text that is not
     *    valid UTF-8 — so the page printed a warning box with NOTHING in it,
     *    the whole message gone (measured by round 1 of the independent
     *    check). `mb_scrub()` puts a replacement mark where each bad byte was,
     *    so the rest of the name, and the whole message, survive.
     *
     * @param string $clientName The name the claimant's computer gave the file.
     *
     * @return string The name in double quotes, or a description when it has none.
     */
    public static function shownFileName(string $clientName): string
    {
        $name = mb_scrub($clientName, 'UTF-8');
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/', '', $name);
        if ($name === '') {
            return 'a file with no name';
        }
        if (mb_strlen($name, 'UTF-8') > 80) {
            $name = mb_substr($name, 0, 77, 'UTF-8') . '...';
        }

        return '"' . $name . '"';
    }

    /**
     * What the claimant is told once their claim is saved.
     *
     * BUILT FROM WHOLE SENTENCES, each chosen for its case and none assembled
     * from clauses in the middle (#694: a sentence built from branches had
     * wording faults no check could see). The cases are how many files were
     * named as not attached (none, one, several) and whether PHP dropped files
     * past the server's limit on how many one submission may carry
     * (`uploadsCutShort()`): five combinations besides plain success, each with
     * its own opening and advice, plus one of two sentences about the limit.
     *
     * The advice is the only route the portal offers today: a receipt cannot
     * be added to a claim after it is made (`edit/save.php` changes the
     * figures only), so the claimant withdraws the claim — which the claim's
     * own page allows while it is pending — and submits it again, having done
     * what each reason says. Files PHP dropped are NEVER named, because PHP
     * never passed their names on.
     *
     * @param int                                    $claimId     The new claim's number.
     * @param list<array{name:string,reason:string}> $notAttached Each file not attached:
     *                                                            its own name and RECEIPT_* reason
     *                                                            (other keys are ignored).
     * @param string                                 $serverLimit `upload_max_filesize`, for the words.
     * @param string                                 $cutShort    One of the CUT_SHORT_* constants.
     * @param int                                    $countLimit  `max_file_uploads`, for the words.
     *
     * @return array{type:string,message:string} `type` is the alert's colour.
     */
    public static function submittedMessage(
        int $claimId,
        array $notAttached,
        string $serverLimit = '',
        string $cutShort = self::CUT_SHORT_NO,
        int $countLimit = 0
    ): array {
        $count = count($notAttached);
        $cut   = ($cutShort === self::CUT_SHORT_CERTAIN || $cutShort === self::CUT_SHORT_POSSIBLE) && $countLimit > 0;
        if ($count === 0 && $cut === false) {
            return [
                'type'    => 'success',
                'message' => 'Your claim #' . $claimId . ' has been submitted.',
            ];
        }

        $parts = [];
        foreach ($notAttached as $file) {
            $parts[] = self::shownFileName((string) ($file['name'] ?? ''))
                . ', because ' . self::receiptRefusalWords((string) ($file['reason'] ?? ''), $serverLimit);
        }

        // 1. The opening.
        if ($count === 1) {
            $opening = 'Your claim #' . $claimId . ' has been submitted, but one file you chose was NOT attached to it: '
                . $parts[0] . '.';
        } elseif ($count > 1) {
            $opening = 'Your claim #' . $claimId . ' has been submitted, but ' . $count . ' files you chose were NOT attached to it: '
                . implode('; ', $parts) . '.';
        } elseif ($cutShort === self::CUT_SHORT_CERTAIN) {
            $opening = 'Your claim #' . $claimId . ' has been submitted, but not everything sent with it was received.';
        } else {
            $opening = 'Your claim #' . $claimId . ' has been submitted, but not every file you chose may have been received.';
        }

        // 2. The server's limit on how many files, when it was reached.
        $limitSentence = '';
        if ($cut === true && $cutShort === self::CUT_SHORT_CERTAIN) {
            // "Anything you chose beyond the first N", never "your files": PHP
            // raises this warning for an EMPTY box past the limit too (see
            // CUT_SHORT_CERTAIN), so it is not proof a real file was lost.
            $limitSentence = ' This server accepts at most ' . $countLimit . ' files in one submission and more than that were sent, so anything you chose beyond the first ' . $countLimit . ' never reached the portal: it is not attached, and its name cannot be shown here.';
        } elseif ($cut === true) {
            $limitSentence = ' This server accepts at most ' . $countLimit . ' files in one submission and ' . $countLimit . ' arrived, so if you chose more than ' . $countLimit . ', the rest never reached the portal: they are not attached, and their names cannot be shown here.';
        }

        // 3. What a reviewer sees, and 4. what to do.
        if ($cut === false && $count === 1) {
            $after = ' Anybody reviewing the claim will see it without that file.'
                . ' To add it, do what is said above, then withdraw the claim from its own page while it is still pending and submit it again with the file.';
        } elseif ($cut === false) {
            $after = ' Anybody reviewing the claim will see it without those files.'
                . ' To add them, do what is said above, then withdraw the claim from its own page while it is still pending and submit it again with the files.';
        } elseif ($count === 0) {
            $after = ' Anybody reviewing the claim will see only the files that arrived.'
                . ' To add the rest, withdraw the claim from its own page while it is still pending and submit it again with no more than ' . $countLimit . ' files, putting several receipts into one PDF if you have more than that.';
        } else {
            $after = ' Anybody reviewing the claim will see it without those files.'
                . ' To add them, do what is said above, then withdraw the claim from its own page while it is still pending and submit it again with no more than ' . $countLimit . ' files, putting several receipts into one PDF if you have more than that.';
        }

        return [
            'type'    => 'warning',
            'message' => $opening . $limitSentence . $after,
        ];
    }


    /* ---------------------------------------------------------------------- */
    /* 🔌 A claim whose save could not be confirmed (#649)                     */
    /* ---------------------------------------------------------------------- */
    //
    // THE RULE, simplified on purpose after the same item failed two review
    // rounds running: ONCE COMMIT HAS BEEN SENT, NO RECEIPT FILE IS EVER
    // DELETED, whatever happens next. The files are kept and recorded by
    // their stored path for the administrator, and a NEW connection is asked
    // only which MESSAGE to show. Nothing it answers can delete anything.
    //
    // WHY, with the two designs it replaced:
    //   - Round 1 removed the moved files on every failure. Measured with a
    //     proxy that forwarded COMMIT and dropped the reply: the claim and its
    //     receipt row were saved, the FILE was gone, and the claimant was told
    //     to submit again.
    //   - Round 2 asked a new connection "is the claim there?" and then "has
    //     the old connection gone?", deleting the files on "absent and gone".
    //     The two questions are asked at two moments, and a commit landing
    //     between them read as "not saved": measured, 10 of 60 runs deleted a
    //     SAVED claim's receipt. Behind a connection proxy or a replicated
    //     database the connection numbers mean something else again.
    //
    // THE HONEST COST: when a commit really did fail after being sent, the
    // receipt files stay on disk with no claim pointing at them. They are
    // logged by path (RECEIPT_KEPT_AFTER_FAILED_COMMIT) for the administrator
    // to remove. That was chosen because deleting a saved claim's evidence
    // cannot be undone, and an orphan file can be.

    /**
     * Is this claim in the database? Asked on a NEW connection, so it sees only
     * what the server really committed. It decides a MESSAGE and nothing else.
     *
     * @param mysqli $fresh   A connection opened after the failure.
     * @param int    $claimId The claim's number.
     * @param int    $userId  Its claimant, so another person's claim is never mistaken for it.
     *
     * @return bool
     *
     * @throws \Throwable When the question cannot be asked; the caller then
     *                    says it is not known.
     */
    public static function claimIsSaved(mysqli $fresh, int $claimId, int $userId): bool
    {
        if ($claimId <= 0) {
            return false;
        }
        $row = $fresh->prepare('SELECT `claimID` FROM `tblExpenseClaims` WHERE `claimID` = ? AND `userID` = ? LIMIT 1');
        $row->bind_param('ii', $claimId, $userId);
        $row->execute();
        $found = $row->get_result()->fetch_assoc() !== null;
        $row->close();

        return $found;
    }

    /**
     * Said after the usual "submitted" words when the claim IS in the database
     * but its save could not be confirmed at the time. "Could not be
     * confirmed" and not "the connection was lost": a commit can also be
     * refused with the connection still up. The approvers' e-mail is sent only
     * after a confirmed save, so on this path it never went — said plainly,
     * because the claimant may be waiting for an approver who does not know.
     */
    public const MSG_SAVED_UNCONFIRMED = 'The save could not be confirmed at the time, so the approvers were NOT sent their usual e-mail about this claim. Please do NOT submit it again: it is saved, and it is listed under Your claims.';

    /**
     * Said when it could not be found out whether the claim was saved. The
     * receipts are kept either way, so a saved claim never loses its evidence.
     *
     * "After a few minutes" is load-bearing: a commit still being completed
     * when the look happens would leave the claim missing NOW and listed a
     * moment later, and "if it is not listed, submit it again" said at once
     * would invite the same money to be claimed twice (round 4 of the check).
     */
    public const MSG_SAVE_NOT_CONFIRMED = 'It is not known whether your claim was saved: the save could not be confirmed. Please look under Your claims below before submitting it again, so the same money is not claimed twice. If it is still not listed after a few minutes, submit it again.';

    /**
     * What the claimant is told when the save could not be confirmed.
     *
     * Whole sentences, chosen per case (#694). Found: the usual "submitted"
     * message — which already names every file that was not attached, and
     * anything past the server's file limit — followed by
     * MSG_SAVED_UNCONFIRMED. Not found, or not known: MSG_SAVE_NOT_CONFIRMED,
     * then, when files were not attached, one sentence naming them, because
     * that was worked out before the commit and is true whatever the answer.
     *
     * @param bool                                   $found       Whether the claim was found saved.
     * @param int                                    $claimId     The claim's number.
     * @param list<array{name:string,reason:string}> $notAttached Files not attached.
     * @param string                                 $serverLimit `upload_max_filesize`, for the words.
     * @param string                                 $cutShort    One of the CUT_SHORT_* constants.
     * @param int                                    $countLimit  `max_file_uploads`, for the words.
     *
     * @return array{type:string,message:string}
     */
    public static function unconfirmedMessage(
        bool $found,
        int $claimId,
        array $notAttached,
        string $serverLimit = '',
        string $cutShort = self::CUT_SHORT_NO,
        int $countLimit = 0
    ): array {
        if ($found === true) {
            $told = self::submittedMessage($claimId, $notAttached, $serverLimit, $cutShort, $countLimit);

            return [
                'type'    => 'warning',
                'message' => $told['message'] . ' ' . self::MSG_SAVED_UNCONFIRMED,
            ];
        }

        $parts = [];
        foreach ($notAttached as $file) {
            $parts[] = self::shownFileName((string) ($file['name'] ?? ''))
                . ', because ' . self::receiptRefusalWords((string) ($file['reason'] ?? ''), $serverLimit);
        }
        $message = self::MSG_SAVE_NOT_CONFIRMED;
        if (count($parts) === 1) {
            $message .= ' Either way, one file you chose was NOT attached: ' . $parts[0] . '.';
        } elseif (count($parts) > 1) {
            $message .= ' Either way, ' . count($parts) . ' files you chose were NOT attached: ' . implode('; ', $parts) . '.';
        }

        return [
            'type'    => 'danger',
            'message' => $message,
        ];
    }

    /* ---------------------------------------------------------------------- */
    /* 📎 What the file chooser offers (#871)                                 */
    /* ---------------------------------------------------------------------- */

    /**
     * The `accept` list for the claim form's file box, built from
     * RECEIPT_TYPES so the two can never disagree.
     *
     * WHAT WAS WRONG. The box carried `accept="image/*,application/pdf"`,
     * written by hand. RECEIPT_TYPES also accepts Word, Excel and CSV files,
     * and the help page says so, but a browser's file chooser hides whatever
     * `accept` leaves out — and a phone may not offer it at all — so a
     * claimant was told a spreadsheet was a valid receipt and then could not
     * easily pick one. It also offered every kind of picture, including kinds
     * the rule refuses (a `.webp` or a `.bmp`), so the chooser invited a file
     * the save then turned away.
     *
     * WHY BY THE END OF THE NAME, NOT BY TYPE ("image/jpeg"). The rule in
     * `whyReceiptRefused()` judges a file by the end of its name and nothing
     * else, so the chooser is told exactly the same thing. A list of types
     * would be a second copy of the rule in a different vocabulary, and would
     * drift. HEIC and HEIF (D-096) are on RECEIPT_TYPES, so they are offered.
     *
     * WHAT IT CANNOT DO. `accept` is a suggestion to the browser, not a rule:
     * anybody can still choose "All files" and send something else. The
     * handler judges every file again, and that is the boundary. Whether a
     * particular phone still offers its camera for this list was NOT tested
     * here — no phone was available; browsers treat `.jpg` and `.heic` as
     * picture endings, which is what makes them offer one.
     *
     * @return string For example ".jpg,.jpeg,.png,…,.csv".
     */
    public static function receiptAcceptList(): string
    {
        return implode(',', array_map(static fn (string $ext): string => '.' . $ext, self::RECEIPT_TYPES));
    }

    /* ---------------------------------------------------------------------- */
    /* ✍️ A refused claim keeps what was typed (#870)                         */
    /* ---------------------------------------------------------------------- */
    //
    // WHAT WAS WRONG. Every refusal from `expenses/submit/save.php` sent the
    // claimant back to an EMPTY form — a missing title, a total of zero, a
    // retired department, the receipts folder failing, a save that failed and
    // was undone — so they typed every line of the claim again.
    //
    // WHAT IS KEPT, AND WHAT IS NOT. The title, the department and the item
    // lines are kept in the claimant's own session, ONCE, and the form fills
    // itself in from them the next time it is drawn. The RECEIPTS ARE NOT: a
    // browser does not let a page choose files on somebody's behalf, and PHP
    // throws the uploaded copies away when the request ends. The form says so
    // whenever it has been filled in again.
    //
    // WHEN NOTHING IS KEPT, on purpose:
    //   - a refused FORM TOKEN. That request may not have come from this
    //     person at all — another website can post a form to this address —
    //     and keeping it would let that website fill in the claim form that a
    //     member then sees as their own;
    //   - a submission too large for the server, because PHP threw ALL of it
    //     away before the page ran, so there is nothing to keep;
    //   - a save whose COMMIT was sent and could not be confirmed. The claim
    //     may well be saved, and a filled-in form would invite submitting the
    //     same money again — the very thing that message warns against.

    /** Where the kept claim lives in the session. Read and cleared by the form page. */
    public const DRAFT_SESSION_KEY = 'expenses_submit_draft';

    /** The most item lines kept: a real claim has a handful; this bounds the session file. */
    public const DRAFT_MAX_ITEMS = 200;

    /** The most characters kept of one text box (a title or a description). */
    public const DRAFT_MAX_TEXT = 1000;

    /** The most characters kept of one number box (a quantity or a unit price). */
    public const DRAFT_MAX_NUMBER = 32;

    /**
     * What to keep of a refused claim, from the request it came in.
     *
     * Takes the posted fields as FACTS, so the handler and the self-test ask
     * the same question. A request chooses the TYPE of what it sends as well
     * as the value (#642): a field that is not text, or a list that is not a
     * list, is kept as nothing rather than trusted.
     *
     * An item line is kept when it has a description, or a price that is not
     * zero. The form's own empty lines (with JavaScript off it shows five,
     * each priced "0.00") are not, so a refused claim does not come back with
     * a column of blank rows.
     *
     * The limits (DRAFT_MAX_*) are far above anything a person types; they
     * exist only so a crafted request cannot fill the session file. Text past
     * them is cut, which is acceptable for a convenience copy and would NOT be
     * for anything saved — nothing here is saved.
     *
     * Text that is not valid UTF-8 is repaired, never dropped, so the page
     * that prints it cannot fail on it.
     *
     * WHOSE IT IS IS STORED WITH IT (round 1 of the independent check). The
     * session is the BROWSER's, not the person's: the two single-sign-on
     * routes do not refuse somebody already signed in, and
     * `session_regenerate_id(true)` keeps the session's data, so a second
     * person signing in on the same browser while kept typing waits would
     * otherwise be shown the first person's claim — their name for it,
     * their figures. `draftForForm()` gives it back only to the account
     * (and the organisation) it was kept for.
     *
     * @param array<mixed> $post   The request's posted fields (`$_POST`).
     * @param int          $siteId The organisation the claim was for.
     * @param int          $userId The account the claim was typed by.
     *
     * @return array{siteId:int,userId:int,claimTitle:string,deptID:int,items:list<array{desc:string,qty:string,unit:string}>}
     */
    public static function claimDraftFrom(array $post, int $siteId, int $userId): array
    {
        $text = static function (mixed $value, int $max): string {
            if (is_string($value) === false) {
                return '';
            }

            return mb_substr(mb_scrub($value, 'UTF-8'), 0, $max, 'UTF-8');
        };
        $list = static fn (mixed $value): array => is_array($value) === true ? $value : [];

        $descs = $list($post['itemDesc'] ?? null);
        $qtys  = $list($post['itemQty'] ?? null);
        $units = $list($post['itemUnit'] ?? null);
        // 💷 Each line's quoted currency and converted amount are kept too
        //    (#966, D-139), so a refused claim comes back as it was typed.
        $currs = $list($post['itemCurrency'] ?? null);
        $convs = $list($post['itemConverted'] ?? null);
        $items = [];
        foreach ($descs as $i => $desc) {
            if (count($items) >= self::DRAFT_MAX_ITEMS) {
                break;
            }
            $one = [
                'desc' => $text($desc, self::DRAFT_MAX_TEXT),
                'qty'  => $text($qtys[$i] ?? '', self::DRAFT_MAX_NUMBER),
                'unit' => $text($units[$i] ?? '', self::DRAFT_MAX_NUMBER),
                'currency'  => $text($currs[$i] ?? '', 3),
                'converted' => $text($convs[$i] ?? '', self::DRAFT_MAX_NUMBER),
            ];
            $priced = trim($one['unit']) !== '' && (float) $one['unit'] != 0.0;
            if (trim($one['desc']) === '' && $priced === false) {
                continue;
            }
            $items[] = $one;
        }

        $dept = $post['deptID'] ?? '';

        return [
            'siteId'     => $siteId,
            'userId'     => $userId,
            'claimTitle' => $text($post['claimTitle'] ?? '', self::DRAFT_MAX_TEXT),
            'deptID'     => (is_string($dept) === true && preg_match('/^[1-9][0-9]{0,9}$/', $dept) === 1) ? (int) $dept : 0,
            'items'      => $items,
        ];
    }

    /**
     * The kept claim, if there is one for THIS person in THIS organisation,
     * ready for the form; otherwise null.
     *
     * The session is the portal's own, but it is read defensively all the
     * same: anything not in the shape `claimDraftFrom()` writes is no draft.
     * A draft kept for another organisation is not shown — a member of two
     * sees each one's form on its own address, and a department number from
     * one means nothing in the other. A draft kept for another ACCOUNT is
     * never shown (see `claimDraftFrom()` for how one can be in the same
     * session), and neither is one with no account recorded, or one being
     * drawn for nobody (`$userId` 0): "unknown" never matches "unknown".
     *
     * @param mixed $stored The session's value under DRAFT_SESSION_KEY.
     * @param int   $siteId The organisation the form is being drawn for.
     * @param int   $userId The account the form is being drawn for.
     *
     * @return array{claimTitle:string,deptID:int,items:list<array{desc:string,qty:string,unit:string}>}|null
     */
    public static function draftForForm(mixed $stored, int $siteId, int $userId): ?array
    {
        if (is_array($stored) === false || ($stored['siteId'] ?? null) !== $siteId) {
            return null;
        }
        if ($userId <= 0 || ($stored['userId'] ?? null) !== $userId) {
            return null;
        }
        $items = [];
        foreach (is_array($stored['items'] ?? null) === true ? $stored['items'] : [] as $item) {
            if (is_array($item) === false) {
                continue;
            }
            $items[] = [
                'desc' => is_string($item['desc'] ?? null) === true ? $item['desc'] : '',
                'qty'  => is_string($item['qty'] ?? null) === true ? $item['qty'] : '',
                'unit' => is_string($item['unit'] ?? null) === true ? $item['unit'] : '',
            ];
        }

        return [
            'claimTitle' => is_string($stored['claimTitle'] ?? null) === true ? $stored['claimTitle'] : '',
            'deptID'     => is_int($stored['deptID'] ?? null) === true ? $stored['deptID'] : 0,
            'items'      => $items,
        ];
    }

    /* ---------------------------------------------------------------------- */
    /* 📦 A submission too large for the server (#870)                        */
    /* ---------------------------------------------------------------------- */

    /**
     * How many bytes the server's `post_max_size` allows, or 0 for "no limit
     * known".
     *
     * Read with PHP's own `ini_parse_quantity()`, so "8M", "1G" and "65536"
     * mean exactly what PHP means by them. `0` means no limit to PHP, and a
     * value PHP cannot read counts as 0 too — no known limit — which makes
     * `postWasTooLarge()` answer false: the claimant is then told what they
     * were told before, rather than a size story nobody can stand behind.
     *
     * @param string $setting `ini_get('post_max_size')`.
     */
    public static function postLimitBytes(string $setting): int
    {
        if (trim($setting) === '') {
            return 0;
        }
        $bytes = @ini_parse_quantity($setting);

        return $bytes > 0 ? $bytes : 0;
    }

    /**
     * Did PHP throw the whole submission away because it was larger than
     * `post_max_size`?
     *
     * WHAT WAS WRONG. When everything sent together is over that limit, PHP
     * empties `$_POST` and `$_FILES` before the page runs. The form token is
     * in `$_POST`, so the page found no token and told the claimant "Invalid
     * CSRF token." — untrue, and no help: the cause was the size of their
     * receipts, and pressing Submit again gave the same answer for ever.
     *
     * HOW IT IS TOLD APART. All three must hold: the request is a POST, PHP
     * passed on NOTHING (no fields and no files), and the size the browser
     * declared (`CONTENT_LENGTH`) is over the limit. A post that merely lost
     * its token still carries its other fields, so it is never mistaken for
     * this; and a large post that arrived whole is never called too large.
     *
     * Takes FACTS, so the handler and the self-test ask the same question.
     *
     * @param string $method        `$_SERVER['REQUEST_METHOD']`.
     * @param int    $fieldCount    `count($_POST)`.
     * @param int    $fileCount     `count($_FILES)`.
     * @param mixed  $contentLength `$_SERVER['CONTENT_LENGTH'] ?? null`.
     * @param string $postMaxSize   `ini_get('post_max_size')`.
     */
    public static function postWasTooLarge(string $method, int $fieldCount, int $fileCount, mixed $contentLength, string $postMaxSize): bool
    {
        if (strtoupper($method) !== 'POST' || $fieldCount !== 0 || $fileCount !== 0) {
            return false;
        }
        $limit = self::postLimitBytes($postMaxSize);

        return $limit > 0 && self::declaredLength($contentLength) > $limit;
    }

    /**
     * The size a request declared, as a whole number of bytes; 0 when it
     * declared none, or something that is not a plain number.
     *
     * @param mixed $contentLength `$_SERVER['CONTENT_LENGTH'] ?? null`.
     */
    public static function declaredLength(mixed $contentLength): int
    {
        if (is_int($contentLength) === true) {
            return max(0, $contentLength);
        }
        if (is_string($contentLength) === true && preg_match('/^[0-9]{1,18}$/', $contentLength) === 1) {
            return (int) $contentLength;
        }

        return 0;
    }

    /** Round a size to the nearest tenth (or whole kilobyte): what a person expects. */
    public const SIZE_NEAREST = 'nearest';

    /** Round a size UP: what was sent, only when nearest would not read larger than the limit. */
    public const SIZE_UP = 'up';

    /** Round a size DOWN: ALWAYS for a limit, so the limit is never overstated. */
    public const SIZE_DOWN = 'down';

    /**
     * A number of bytes as a person would say it: "1.5 GB", "2.5 MB",
     * "640 KB", "512 bytes" — to one decimal place at most.
     *
     * Rounded to the NEAREST by default. See `postTooLargeMessage()` for when
     * each direction is used, and why a LIMIT is always rounded down.
     *
     * @param int    $bytes The size.
     * @param string $how   SIZE_NEAREST, SIZE_UP or SIZE_DOWN.
     */
    public static function sizeInWords(int $bytes, string $how = self::SIZE_NEAREST): string
    {
        return self::sizeShown($bytes, $how)['words'];
    }

    /**
     * A size as shown, AND the number of bytes those words stand for — so two
     * shown sizes can be compared in ONE unit. Comparing the WORDS is not
     * enough (round 2 of the independent check): "1 MB" and "1024 KB" are
     * different text and the same amount.
     *
     * The unit is chosen from the size itself (GB from 1 GB, MB from 1 MB, KB
     * from 1 KB, otherwise bytes), so a rounding never moves a size into a
     * unit it is not in. Below 1 KB it is a whole number of bytes, which needs
     * no rounding at all — the first version said "1 KB" for anything under
     * a kilobyte, which overstates a limit that small.
     *
     * @param int    $bytes The size.
     * @param string $how   SIZE_NEAREST, SIZE_UP or SIZE_DOWN.
     *
     * @return array{words:string,bytes:float}
     */
    public static function sizeShown(int $bytes, string $how = self::SIZE_NEAREST): array
    {
        $bytes = max(0, $bytes);
        $round = static fn (float $x, int $places): float => match ($how) {
            self::SIZE_UP   => ceil($x * (10 ** $places)) / (10 ** $places),
            self::SIZE_DOWN => floor($x * (10 ** $places)) / (10 ** $places),
            default         => round($x, $places),
        };
        $units = [
            ['GB', 1024 * 1024 * 1024, 1],
            ['MB', 1024 * 1024, 1],
            ['KB', 1024, 0],
        ];
        foreach ($units as [$name, $size, $places]) {
            if ($bytes >= $size) {
                $value  = $round($bytes / $size, $places);
                $number = number_format($value, $places, '.', '');
                // A trailing ".0" is dropped ("1 MB", not "1.0 MB"). Only when
                // there IS a decimal point: trimming zeros from "700" or
                // "1020" would change the number itself.
                if ($places > 0) {
                    $number = rtrim(rtrim($number, '0'), '.');
                }

                return [
                    'words' => $number . ' ' . $name,
                    'bytes' => $value * $size,
                ];
            }
        }

        return ['words' => $bytes . ($bytes === 1 ? ' byte' : ' bytes'), 'bytes' => (float) $bytes];
    }

    /**
     * What the claimant is told when the submission was too large.
     *
     * It says that NOTHING was kept, typing included, because that is true
     * here and nowhere else: PHP threw the whole request away before the
     * portal saw any of it, so the form cannot be filled in again.
     *
     * THE LIMIT IS ALWAYS ROUNDED DOWN. The claimant acts on it — "together
     * they must come to less than this" — so a limit read too high sends them
     * back to be refused again. Rounding it to the nearest, as one version
     * did, told a server allowing 1500K (1.46 MB) that it accepts "1.5 MB"
     * (round 2 of the independent check). Down can only understate it, which
     * is the safe direction.
     *
     * WHAT WAS SENT IS ROUNDED TO THE NEAREST, because always rounding it up
     * told an upload of exactly 1.5 MB that it "came to 1.6 MB" (round 1).
     * Only when that would NOT read larger than the limit — compared in BYTES,
     * because "1 MB" and "1024 KB" are different words for the same amount —
     * is it rounded up instead. It then always reads larger, because what was
     * sent is strictly more than the limit: up can only raise it, and the
     * limit's figure can only be at or below the real limit.
     *
     * @param int    $sentBytes   What the browser declared it was sending.
     * @param string $postMaxSize `ini_get('post_max_size')`.
     */
    public static function postTooLargeMessage(int $sentBytes, string $postMaxSize): string
    {
        $limitShown = self::sizeShown(self::postLimitBytes($postMaxSize), self::SIZE_DOWN);
        $sentShown  = self::sizeShown($sentBytes);
        if ($sentShown['bytes'] <= $limitShown['bytes']) {
            $sentShown = self::sizeShown($sentBytes, self::SIZE_UP);
        }
        $sent  = $sentShown['words'];
        $limit = $limitShown['words'];

        return 'Your claim was NOT saved, because everything you sent together came to '
            . $sent . ', and this server accepts at most '
            . $limit . ' in one submission. Because of that, nothing you sent reached the portal — '
            . 'not your receipts, and not what you typed — so the form below is empty. '
            . 'Please fill it in again and attach fewer or smaller receipts, so that together they come to less than '
            . $limit . '. A photo taken at a lower resolution is often much smaller.';
    }

    /* ---------------------------------------------------------------------- */
    /* 📥 Opening a receipt (#867)                                             */
    /* ---------------------------------------------------------------------- */
    //
    // WHAT WAS WRONG. The claim page listed each receipt's label, kind and
    // size and linked to none of them, and nothing anywhere served the
    // receipts folder — which is outside the web root on purpose, so the web
    // server cannot hand a file out by itself. So an approver deciding a
    // claim, and a treasurer paying one, could not look at the evidence they
    // were deciding or paying against. Found while building #649.
    //
    // WHO MAY OPEN ONE. Exactly the people who may open the claim: the
    // `expenses/receipt` address asks `reachToSee()` and `mayViewClaim()`,
    // the same two calls the claim page asks, and keeps no rule of its own.
    // A receipt somebody may not open is answered exactly as a receipt that
    // does not exist (the money pages' rule: nobody learns from the answer
    // that a claim or a receipt is real).
    //
    // WHAT COUNTS AS A RECEIPT. A row of `tblExpenseClaimFiles` with NO
    // `stage`. Only two places write that table (established with
    // `grep -rn tblExpenseClaimFiles web --include='*.php'`):
    // `expenses/submit/save.php`, which stores receipts in `receiptsFolder()`
    // and never sets a stage, and `ExpensePdf::generate()`, which records each
    // version of the claim's own PDF WITH a stage, in a different folder, and
    // overwrites one file for every stage — so a stage row does not even name
    // the version it describes. That PDF is not a receipt and this address
    // does not serve it.
    //
    // WHY THE PATH IS JUDGED HERE. The address takes two numbers and never a
    // name, so nothing a request sends reaches the file system. What does is
    // the stored name in the database, which the portal wrote — and a row is
    // still not a reason to trust a path: a restored backup, a hand-edited row
    // or a fault elsewhere could put `../`, or a link to somewhere else, into
    // the folder. So the stored name must be a plain file name, AND the file it
    // names, with every symbolic link followed, must still be directly inside
    // the receipts folder.

    /**
     * Where receipts are kept: the ONE place the folder is named. The submit
     * handler stores receipts here and the receipt address reads them from
     * here; two places building it would be two places to change, and the one
     * that drifted would simply stop finding anything.
     *
     * Needs `PORTAL_ROOT`, which start-up defines.
     *
     * @return string An absolute path, with no separator at the end.
     */
    public static function receiptsFolder(): string
    {
        return PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'expenses';
    }

    /**
     * Is this stored name a plain file name the portal could have written?
     * Pure, so a self-test can ask it directly.
     *
     * The portal has written two shapes: `412_1700000000_receipt1.pdf` since
     * #714, and before that `412_1700000000_<the uploader's name with every
     * character outside A-Z, a-z, 0-9, dot, underscore and hyphen removed>`
     * (established with `git show 3f07981c:web/public_html/expenses/submit/save.php`,
     * whose line 105 builds it).
     * Both start with the claim number and use only those characters. So a
     * name is accepted when it uses only those characters, starts with a
     * letter or digit (which rules out `.`, `..` and hidden files) and is no
     * longer than the column. A slash, a backslash, a NUL byte or any other
     * character can never be part of one.
     *
     * @param string $storedFilename `tblExpenseClaimFiles.storedFilename`.
     */
    public static function isPlainReceiptName(string $storedFilename): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$/D', $storedFilename) === 1;
    }

    /**
     * The real path of a stored receipt, or null when it must not be served.
     *
     * Null when the name is not plain (`isPlainReceiptName()`), when the
     * folder or the file does not exist or cannot be read, when it is not a
     * regular file, or when the file — with every symbolic link followed —
     * is not directly inside the receipts folder. The caller answers every
     * null the same way.
     *
     * @param string $storedFilename `tblExpenseClaimFiles.storedFilename`.
     *
     * @return string|null The resolved path to read, or null.
     */
    public static function receiptFile(string $storedFilename): ?string
    {
        if (self::isPlainReceiptName($storedFilename) === false) {
            return null;
        }
        $folder = realpath(self::receiptsFolder());
        if ($folder === false || is_dir($folder) === false) {
            return null;
        }
        // 🔗 A link is refused outright, asked of the file system afresh, as
        //    `claimPdfFile()` does and for the reason written there (#954):
        //    `realpath()`'s answers are cached across requests, so a plain
        //    file later replaced by a link could still pass the test below.
        $named = $folder . DIRECTORY_SEPARATOR . $storedFilename;
        clearstatcache(true, $named);
        if (is_link($named) === true) {
            return null;
        }
        $file = realpath($named);
        // 🔗 `realpath()` follows links, so a link inside the folder that
        //    points anywhere else resolves to that somewhere else and fails
        //    this test. DIRECTLY inside: the portal never makes sub-folders
        //    here, so the file's folder must be the receipts folder itself.
        if ($file === false || dirname($file) !== $folder) {
            return null;
        }
        if (is_file($file) === false || is_readable($file) === false) {
            return null;
        }

        return $file;
    }

    /**
     * The `Content-Type` to send a receipt with: the type stored when it was
     * uploaded (worked out by the server from the file's contents, never the
     * type the browser claimed), when it is a well-formed `type/subtype` with
     * nothing after it; otherwise `application/octet-stream`, which every
     * browser treats as "a file to save".
     *
     * Whatever is sent, the address also sends `Content-Disposition:
     * attachment`, `X-Content-Type-Options: nosniff` and a page rule that
     * sandboxes the answer, so even a stored `text/html` is saved rather than
     * shown as a page of the portal.
     *
     * @param mixed $storedType `tblExpenseClaimFiles.fileType`, which may be NULL.
     */
    public static function receiptContentType(mixed $storedType): string
    {
        if (is_string($storedType) === true
            && preg_match('#^[A-Za-z0-9][A-Za-z0-9!\#$&^_.+-]{0,62}/[A-Za-z0-9][A-Za-z0-9!\#$&^_.+-]{0,62}$#D', $storedType) === 1) {
            return strtolower($storedType);
        }

        return 'application/octet-stream';
    }

    /**
     * The whole `Content-Disposition` value for a receipt: always
     * `attachment`, with the name given twice, as RFC 6266 section 4.3
     * advises —
     *
     *   filename="..."        plain ASCII letters, digits, dot, underscore and
     *                         hyphen only, for a browser that reads nothing else;
     *   filename*=UTF-8''...  the label in full, percent-encoded as RFC 5987
     *                         (now RFC 8187) requires, which every current
     *                         browser prefers when it is present.
     *
     * NOTHING FROM THE LABEL REACHES THE HEADER UNENCODED. The label has been
     * a name the portal makes up since #714 (`Claim_412_Receipt_1_2026-11-09.pdf`),
     * but a row written before then holds the uploader's own name, which may
     * contain quotes, semicolons, line breaks or any character at all. In the
     * plain form every such character becomes `_`; in the encoded form
     * `rawurlencode()` leaves only letters, digits and `-_.~`, all of which
     * RFC 5987 allows bare. Control characters are removed first and bytes
     * that are not UTF-8 are replaced, so a line break can never end the
     * header early. Pure, so a self-test can ask it directly.
     *
     * @param string $label          `tblExpenseClaimFiles.fileLabel` (may be empty).
     * @param int    $fileId         The receipt's number, for a made-up name when
     *                               the label leaves nothing usable.
     * @param string $storedFilename The stored name, for its extension only.
     */
    public static function receiptDisposition(string $label, int $fileId, string $storedFilename): string
    {
        // 🧹 One clean copy of the label: valid UTF-8, no control characters
        //    (C0, DEL, C1, and the two Unicode line and paragraph separators),
        //    no folder part, and not absurdly long.
        $clean = mb_scrub($label, 'UTF-8');
        $clean = (string) preg_replace('/[\x00-\x1F\x7F]+/', '', $clean);
        $clean = (string) preg_replace('/[\x{80}-\x{9F}\x{2028}\x{2029}]+/u', '', $clean);
        $clean = basename(str_replace('\\', '/', $clean));
        $clean = trim($clean);
        if (mb_strlen($clean, 'UTF-8') > 150) {
            $clean = mb_substr($clean, 0, 150, 'UTF-8');
        }
        if ($clean === '' || $clean === '.' || $clean === '..') {
            $ext   = strtolower((string) pathinfo($storedFilename, PATHINFO_EXTENSION));
            $clean = 'receipt-' . $fileId . (preg_match('/^[a-z0-9]{1,10}$/D', $ext) === 1 ? '.' . $ext : '');
        }

        // 🔤 The plain form: every other character becomes "_", runs of "_"
        //    become one, and a name left with nothing but punctuation falls
        //    back to the made-up one. The EXTENSION is judged apart from the
        //    rest and kept whenever it is plain letters and digits, so a label
        //    written wholly in another script ("領収書.pdf") still saves as a
        //    .pdf — the first version made that "pdf", with no dot, which a
        //    computer could not open by its name.
        $ext  = (string) pathinfo($clean, PATHINFO_EXTENSION);
        $ext  = preg_match('/^[A-Za-z0-9]{1,10}$/D', $ext) === 1 ? $ext : '';
        $stem = $ext === '' ? $clean : mb_substr($clean, 0, mb_strlen($clean, 'UTF-8') - mb_strlen($ext, 'UTF-8') - 1, 'UTF-8');
        $stem = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $stem);
        $stem = (string) preg_replace('/_+/', '_', $stem);
        $stem = trim($stem, '._-');
        if ($stem === '') {
            $stem = 'receipt-' . $fileId;
        }
        $ascii = $stem . ($ext === '' ? '' : '.' . $ext);

        return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($clean);
    }

    /* ---------------------------------------------------------------------- */
    /* 📄 Opening a claim's generated PDF (#954)                               */
    /* ---------------------------------------------------------------------- */
    //
    // WHAT WAS WRONG. The claim page listed the claim's own generated PDF (the
    // `tblExpenseClaimFiles` rows WITH a `stage`) by name only, and nothing
    // served it. #867 closed the same gap for receipts and left this one on
    // purpose, because that PDF is not a receipt and lives in another folder.
    //
    // WHO MAY OPEN IT. Exactly the people who may open the claim: the
    // `expenses/claim-pdf` address asks `reachToSee()` and `mayViewClaim()`,
    // the same two calls as the claim page and the receipt address, and keeps
    // no rule of its own. Refused is answered exactly as not there.
    //
    // ONE FILE, NOT ONE PER STAGE. `ExpensePdf::generate()` writes the SAME
    // file (`ExpensePdf::pathFor()`, `_uploads/pdfs/claim_<id>.pdf`) at every
    // stage, each replacing the last, and adds a row per stage that names that
    // one file. So an older stage's version is NOT KEPT anywhere, and the
    // address takes the CLAIM'S number alone: whoever opens it, from the page
    // or from a link saved while the claim was still Pending, gets the newest
    // version, the one the newest stage row describes. Offering "the Pending
    // version" would hand out the Approved bytes under the Pending name.
    //
    // WHY THE PATH IS JUDGED HERE. Nothing a request sends reaches the file
    // system: the path is `ExpensePdf::pathFor()` of the claim number. The
    // name stored on the claim must also be exactly the name that function
    // gives, so a restored or hand-edited `fileName` cannot point the address
    // at anything else; and the file, with links followed, must be directly
    // inside the PDF folder.

    /**
     * The real path of a claim's generated PDF, or null when it must not be
     * served: the stored name is not exactly the one `ExpensePdf::pathFor()`
     * gives for this claim, the folder or file does not exist or cannot be
     * read, it is not a regular file, or (with every symbolic link followed)
     * it is not directly inside the PDF folder. The caller answers every null
     * the same way.
     *
     * @param int    $claimID    The claim's number.
     * @param string $storedName `tblExpenseClaims.fileName`.
     *
     * @return string|null The resolved path to read, or null.
     */
    public static function claimPdfFile(int $claimID, string $storedName): ?string
    {
        if ($claimID <= 0) {
            return null;
        }
        $expected = ExpensePdf::pathFor($claimID);
        if ($storedName !== basename($expected)) {
            return null;
        }
        $folder = realpath(dirname($expected));
        if ($folder === false || is_dir($folder) === false) {
            return null;
        }
        $named = $folder . DIRECTORY_SEPARATOR . $storedName;
        // 🔗 A LINK IS REFUSED OUTRIGHT, and the realpath test below is the
        //    second guard, not the first. Measured while building #954: PHP
        //    keeps the answers `realpath()` gave in a cache that outlives the
        //    request (the "realpath cache", two minutes by default, per server
        //    process). A file that was a plain file when it was last served and
        //    was then replaced by a link still RESOLVED TO ITSELF from that
        //    cache, the test passed, and `fopen()` followed the link to the key
        //    file outside the folder — served with 200. `is_link()` asks the
        //    file system (lstat) afresh, and `clearstatcache(true, …)` drops
        //    the cached answer for this one path before `realpath()` is asked.
        //    The portal never writes a link into this folder.
        clearstatcache(true, $named);
        if (is_link($named) === true) {
            return null;
        }
        $file = realpath($named);
        // `realpath()` follows links, so a link anywhere in the path pointing
        // elsewhere resolves there and fails this test.
        if ($file === false || dirname($file) !== $folder) {
            return null;
        }
        if (is_file($file) === false || is_readable($file) === false) {
            return null;
        }

        return $file;
    }

    /**
     * 💷 ONE EXPENSE LINE, QUOTED AND CONVERTED (#966, D-139) — the ONE rule,
     * asked by the claim form, the edit page and the API, so they cannot
     * disagree.
     *
     * The owner, 11 November 2026: a claim keeps "the originally quoted
     * currency and the converted (forex) domestic currency (GBP) for record and
     * audit accuracy". So a line keeps:
     *   - its QUOTED amount: quantity × unit cost, in the quoted currency (what
     *     the receipt says), and
     *   - its CONVERTED amount, in the CLAIM's currency (the organisation's):
     *     what is approved and reimbursed.
     * When the line was quoted in the claim's own currency the two are the
     * same figure and the form asks once. When it was quoted in another, the
     * claimant types the converted amount from their own evidence (a card or
     * bank statement). NO exchange-rate service is asked: an outside call would
     * need its own decision, and the claimant's statement is the evidence an
     * auditor wants. The rate shown on the pages is worked out from the two.
     *
     * 🔢 EVERY FIGURE IS READ STRICTLY, AND NEVER THROUGH A FLOAT (#1013).
     * This used to be `is_numeric($unit) === true ? (float) $unit : 0.0`, then
     * `round($qty * $unit, 2)`. So a unit cost that was not a figure ("abc")
     * became 0.00 without a word, "1e5" was taken as 100,000.00, "12.345" was
     * rounded for the claimant, a quantity of "2.5" or "abc" silently became 2
     * or 1, and nothing stopped a figure larger than the DECIMAL(10,2) columns
     * hold (99,999,999.99). Now:
     *   - the unit cost and the converted amount are read by the ONE converter,
     *     Currency::parseAmount(), with DECIMAL_10_2_MAX (the most `unitCost`
     *     and `lineTotal` hold): figures only, at most two decimals, no power
     *     of ten, no negative (D-152); a refusal is the converter's own true
     *     sentence, with the line and the box named in front;
     *   - the quantity is a whole number from 1 to what an INT column holds,
     *     or the line is refused saying so;
     *   - quantity × unit cost is worked out in whole HUNDREDTHS, and refused
     *     when the line's total would not fit its column, BEFORE the multiply
     *     (by division), so it cannot pass PHP's integer range either;
     *   - a value sent by a program as a JSON number is turned into the text
     *     it stands for by amountText() first, so the API is read by the same
     *     rule as the form.
     * Zero is still a valid unit cost (a free item), as the API description
     * says; the claim as a whole must still come to more than zero, and
     * claimTotal() is the ONE place that refuses it, for all three doors.
     *
     * Answers the line, or a sentence saying why it is refused. Money comes
     * back twice: as whole hundredths (`…Hundredths`, for adding up) and as
     * the DECIMAL text the column takes (`unit`, `quotedLineTotal`,
     * `lineTotal`, from Currency::hundredthsToDecimal()), so a caller binds
     * text ('s') and no figure is ever a float on its way to the database.
     *
     * @return array{desc:string, qty:int, unit:string, unitHundredths:int, quotedCurrency:string,
     *   quotedLineTotal:string, quotedHundredths:int, lineTotal:string, lineHundredths:int,
     *   currency:string}|string
     */
    public static function expenseLine(
        string $desc,
        mixed $qty,
        mixed $unit,
        mixed $quotedCurrency,
        mixed $converted,
        string $claimCurrency
    ): array|string {
        $claimCurrency = strtoupper(trim($claimCurrency));
        $quotedCode = Currency::fromPosted(is_string($quotedCurrency) === true ? $quotedCurrency : '');
        if ($quotedCode === null) {
            return 'The currency of "' . $desc . '" is not one the portal can use.';
        }
        if ($quotedCode === '') {
            $quotedCode = $claimCurrency;
        }

        // 🔢 The quantity: a whole number, 1 or more, that an INT column holds.
        $qtyInt = self::wholeQuantity($qty);
        if ($qtyInt === null) {
            return 'Quantity of "' . $desc . '": ' . self::MSG_QUANTITY;
        }

        // 🔢 The unit cost, in the QUOTED currency (what the receipt says).
        $unitText = self::amountText($unit);
        $unitHundredths = is_string($unitText) === true
            ? Currency::parseAmount($unitText, $quotedCode, Currency::DECIMAL_10_2_MAX)
            : $unitText['refused'];
        if (is_string($unitHundredths) === true) {
            return 'Unit cost of "' . $desc . '": ' . $unitHundredths;
        }

        // 🧮 quantity × unit cost, in whole hundredths. Asked by DIVISION first
        //    (the most a unit can cost at this quantity), so the multiply below
        //    can never pass PHP's integer range: 2,147,483,647 × 9,999,999,999
        //    would. The limit is the one a single box is held to, because
        //    the line's total is kept in a column of the same size.
        if ($unitHundredths > 0 && $unitHundredths > intdiv(Currency::DECIMAL_10_2_MAX, $qtyInt)) {
            return 'The total of "' . $desc . '" (' . $qtyInt . ' at that unit cost) is too large: the most one line '
                . 'can hold is ' . Currency::format(Currency::DECIMAL_10_2_MAX, $quotedCode) . '.';
        }
        $quoted = $qtyInt * $unitHundredths;

        if ($quotedCode === $claimCurrency) {
            // Quoted in the claim's own money: one figure, asked once.
            return self::lineOf($desc, $qtyInt, $unitHundredths, $quotedCode, $quoted, $quoted, $claimCurrency);
        }
        if ($claimCurrency === '') {
            // The CLAIM has no currency: a new claim when the organisation has
            // none chosen, or an older claim nobody has labelled yet. Either
            // way an administrator fixes it on Organisation settings (round 4:
            // this used to say the organisation had none, which is untrue for
            // an older claim in an organisation that has one).
            return '"' . $desc . '" is quoted in ' . $quotedCode . ', but this claim has no currency recorded to '
                . 'convert it into. Ask an administrator to choose or record one on Organisation settings.';
        }

        // 🔢 The converted amount, in the CLAIM's currency. Nothing typed, or
        //    zero, gets the sentence asking for it; anything else typed that
        //    is not an amount gets the converter's reason, so "abc" is not
        //    merely told to type the cost (it was) when the fault is the text.
        $askForIt = '"' . $desc . '" is quoted in ' . $quotedCode . ': type what it cost in ' . $claimCurrency
            . ', as your card or bank statement shows it.';
        $convText = self::amountText($converted);
        if (is_string($convText) === true && trim($convText) === '') {
            return $askForIt;
        }
        $convHundredths = is_string($convText) === true
            ? Currency::parseAmount($convText, $claimCurrency, Currency::DECIMAL_10_2_MAX)
            : $convText['refused'];
        if (is_string($convHundredths) === true) {
            return 'Cost in ' . $claimCurrency . ' of "' . $desc . '": ' . $convHundredths;
        }
        if ($convHundredths <= 0) {
            return $askForIt;
        }

        return self::lineOf($desc, $qtyInt, $unitHundredths, $quotedCode, $quoted, $convHundredths, $claimCurrency);
    }

    /** What a refused quantity is told (#1013): the rule, with nothing of the claim in it. */
    public const MSG_QUANTITY = 'A quantity must be a whole number of 1 or more, written in figures, for example 3.';

    /**
     * 🔢 The most a quantity can be: what the INT column `quantity` holds.
     * A larger quantity is refused rather than wrapped or cut.
     */
    public const QUANTITY_MAX = 2147483647;

    /**
     * One judged line, with each figure as hundredths AND as the DECIMAL text
     * its column takes.
     *
     * @return array{desc:string, qty:int, unit:string, unitHundredths:int, quotedCurrency:string,
     *   quotedLineTotal:string, quotedHundredths:int, lineTotal:string, lineHundredths:int,
     *   currency:string}
     */
    private static function lineOf(string $desc, int $qty, int $unit, string $quotedCode, int $quoted, int $line, string $claimCurrency): array
    {
        return [
            'desc'             => $desc,
            'qty'              => $qty,
            'unit'             => Currency::hundredthsToDecimal($unit),
            'unitHundredths'   => $unit,
            'quotedCurrency'   => $quotedCode,
            'quotedLineTotal'  => Currency::hundredthsToDecimal($quoted),
            'quotedHundredths' => $quoted,
            'lineTotal'        => Currency::hundredthsToDecimal($line),
            'lineHundredths'   => $line,
            'currency'         => $claimCurrency,
        ];
    }

    /**
     * 🔢 A quantity as a whole number from 1 to QUANTITY_MAX, or null.
     *
     * Accepted: a PHP int (a JSON whole number), a string of figures with
     * spaces at either end allowed ("3", " 03 "), or a float that IS a whole
     * number (a JSON `3.0`, which PHP decodes as a float and is still three).
     * Refused: anything else — "2.5" (the old `(int)` cut it to 2), "abc" (it
     * became 1), "1e3", "-1", 0, an array. The size is compared as TEXT, so a
     * figure beyond PHP's integer range is refused, never wrapped.
     */
    public static function wholeQuantity(mixed $qty): ?int
    {
        if (is_float($qty) === true) {
            if (is_finite($qty) === false || floor($qty) !== $qty || $qty < 1 || $qty > self::QUANTITY_MAX) {
                return null;
            }

            return (int) $qty;
        }
        if (is_int($qty) === true) {
            return ($qty >= 1 && $qty <= self::QUANTITY_MAX) ? $qty : null;
        }
        if (is_string($qty) === false || preg_match('/^[0-9]+$/D', trim($qty)) !== 1) {
            return null;
        }
        $figures = ltrim(trim($qty), '0');
        $limit   = (string) self::QUANTITY_MAX;
        if ($figures === '' || strlen($figures) > strlen($limit)
            || (strlen($figures) === strlen($limit) && strcmp($figures, $limit) > 0)) {
            return null;
        }

        return (int) $figures;
    }

    /**
     * 🔢 A posted or JSON amount as the TEXT Currency::parseAmount() reads, or
     * `['refused' => sentence]` for a value that is not text or a number at
     * all (an array, true/false), so it is refused rather than read as 0.
     * Nothing at all (null: a JSON field left out) is '' — "No amount was
     * entered." where an amount is needed.
     *
     * A form posts text, which is passed on unchanged. A JSON API sends a
     * NUMBER (the API description says `number`, example 12.5), which PHP has
     * already decoded into an int or a float before any of our code runs, so
     * the characters the program sent are gone. A float is written back as the
     * SHORTEST decimal that is exactly the same float — 12.5 as "12.5", 12.345
     * as "12.345" (then refused for its third decimal), 0.1 as "0.1" — tried
     * one decimal place at a time with sprintf('%.nF'), never with
     * `(string)`, json_encode() or var_export(), whose output depends on the
     * server's `precision` and `serialize_precision` settings (with
     * serialize_precision 17, json_encode(0.1) is "0.10000000000000001", which
     * would refuse a plain 0.10). A float too big to have any decimals (from
     * about 1e15 up) is written out in full figures, so it is refused as too
     * large — never as "a power of ten", which the program did not write. A
     * float with no shortest form within 20 places is non-zero and smaller
     * than any two-decimal amount, so it is refused as too precise.
     *
     * WHAT IT CANNOT TELL: once decoded, a JSON `1e5` and `100000` are the same
     * float, so `1e5` SENT AS A JSON NUMBER is read as 100,000.00 — the number
     * really is that. `"1e5"` sent as TEXT is refused as a power of ten, as on
     * the form.
     *
     * @return string|array{refused:string}
     */
    public static function amountText(mixed $value): string|array
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value) === true) {
            return $value;
        }
        if (is_int($value) === true) {
            return (string) $value;
        }
        if (is_float($value) === true && is_finite($value) === true) {
            for ($places = 0; $places <= 20; $places++) {
                $text = sprintf('%.' . $places . 'F', $value);
                if ((float) $text === $value) {
                    return $text;
                }
            }

            return ['refused' => Currency::REFUSED_TOO_PRECISE];
        }

        return ['refused' => Currency::REFUSED_NOT_FIGURES];
    }

    /**
     * 🔢 A claim's total from its judged lines, in whole hundredths, or the
     * sentence refusing it when it would not fit `tblExpenseClaims.totalAmount`
     * (a DECIMAL(10,2), 99,999,999.99). Every line may fit on its own while
     * their sum does not; the old code added floats and let the database find
     * out. Added as whole numbers, so it is exact, and checked after each line
     * so it cannot pass PHP's integer range on the way.
     *
     * 🚫 A TOTAL OF ZERO IS REFUSED HERE TOO, and this is the ONE place that
     * refuses it (#1013 round 2). A unit cost of 0.00 is a valid LINE (a free
     * item), but a claim that comes to nothing asks for nothing. Each door used
     * to test this itself, and the API did not: measured, unitCost 0 with
     * quantity 1 answered HTTP 201 and stored a Pending claim of 0.00. With no
     * lines at all the total is 0 too, so this also refuses an empty claim.
     * The sentence is MSG_TOTAL_NOT_ABOVE_ZERO, the claim form's long-standing
     * wording, so all three doors say the same thing.
     *
     * @param list<array{lineHundredths:int}> $lines
     */
    public static function claimTotal(array $lines, string $claimCurrency): int|string
    {
        $total = 0;
        foreach ($lines as $line) {
            $total += (int) $line['lineHundredths'];
            if ($total > Currency::DECIMAL_10_2_MAX) {
                return 'The claim\'s total is too large: the most one claim can hold is '
                    . Currency::format(Currency::DECIMAL_10_2_MAX, $claimCurrency) . '. Split it into two claims.';
            }
        }
        if ($total <= 0) {
            return self::MSG_TOTAL_NOT_ABOVE_ZERO;
        }

        return $total;
    }

    /** What every door says about a claim that comes to nothing (#1013 round 2): the claim form's own wording. */
    public const MSG_TOTAL_NOT_ABOVE_ZERO = 'Total must be greater than zero.';

    /**
     * 💱 The rate a line was converted at, worked out from its two figures
     * (D-139): "1 USD = 0.7900 GBP", or '' when the line was not converted or
     * a figure is missing. Shown, never used to work anything out.
     */
    public static function rateWords(float $quotedLineTotal, string $quotedCurrency, float $lineTotal, string $currency): string
    {
        if ($quotedCurrency === '' || $currency === '' || $quotedCurrency === $currency || $quotedLineTotal <= 0) {
            return '';
        }

        return '1 ' . $quotedCurrency . ' = ' . number_format($lineTotal / $quotedLineTotal, 4) . ' ' . $currency;
    }

    /**
     * 💱 For each claim, its lines QUOTED in a currency other than the claim's,
     * added up per quoted currency (never across two): claimID => list of
     * "$120.00 quoted" style phrases ("CHF 120.00 quoted" where a currency
     * has no symbol of its own). So the approval and treasury lists can show
     * both figures (D-139). An unreadable answer is an empty list.
     *
     * @param list<int> $claimIds
     * @return array<int, list<string>>
     */
    public static function quotedElsewhere(mysqli $db, array $claimIds): array
    {
        $claimIds = array_values(array_filter(array_map('intval', $claimIds), static fn (int $id): bool => $id > 0));
        if ($claimIds === []) {
            return [];
        }
        $out = [];
        try {
            $marks = implode(', ', array_fill(0, count($claimIds), '?'));
            $stmt  = $db->prepare(
                'SELECT `claimID`, `quotedCurrency`, COALESCE(SUM(`quotedLineTotal`), 0) AS `quoted` '
                . 'FROM `tblExpenseClaimItems` WHERE `claimID` IN (' . $marks . ') '
                . 'AND `quotedCurrency` <> `currency` AND `quotedCurrency` <> \'\' '
                . 'GROUP BY `claimID`, `quotedCurrency` ORDER BY `claimID`, `quotedCurrency`'
            );
            if ($stmt === false) {
                return [];
            }
            $stmt->bind_param(str_repeat('i', count($claimIds)), ...$claimIds);
            $stmt->execute();
            $rs = $stmt->get_result();
            while (($row = $rs->fetch_assoc()) !== null) {
                $out[(int) $row['claimID']][] = Currency::formatMajor((float) $row['quoted'], (string) $row['quotedCurrency']) . ' quoted';
            }
            $stmt->close();
        } catch (\Throwable $e) {
            return [];
        }

        return $out;
    }
}
