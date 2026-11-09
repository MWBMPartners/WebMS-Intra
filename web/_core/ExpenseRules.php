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
     * @return bool True when this person may download the spreadsheet.
     */
    public static function mayExportClaims(): bool
    {
        if (App::isAdmin() === true) {
            return true;
        }

        return App::can(Capabilities::EXPENSES_APPROVE);
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
     * It says the form must be filled in again, because the form page does
     * not keep what was typed.
     */
    public const MSG_RECEIPT_FOLDER_MISSING = 'Your claim was NOT saved, because the portal could not make the folder it keeps receipts in. This is a fault on the server, not in your claim, and it has been recorded for your administrator. Please tell them, and submit the claim again once it is fixed: nothing you typed was kept.';

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
}
