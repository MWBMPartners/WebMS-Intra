<?php
// Path: _core/ReturnedMember.php
/**
 * -----------------------------------------------------------------------------
 * ReturnedMember: what it means that an offboarded person has COME BACK 🔙
 * -----------------------------------------------------------------------------
 * The ONE place that records the return of a person who was offboarded and
 * calls off the erasure that offboarding filed (#1152). There are TWO routes
 * back: the Rehire button (`offboarding/rehire.php`) and the Active tick on
 * Admin, Users (`admin/users/save.php`). Until #1152 only Rehire did this, so a
 * person brought back with the tick still had a waiting erasure request, an
 * offboarding row with no `rehiredAt` (so the offboarding list went on offering
 * Rehire) and, in the `after_undo_window` mode, could be erased by the nightly
 * sweep while signed in as a current member. Two routes with two rules is how
 * that happened; both now call this.
 *
 * CALL IT INSIDE THE CALLER'S TRANSACTION, after the account has been switched
 * on, so that a failure rolls the whole return back and the request is never
 * left cancelled over an account that stayed off.
 *
 * WHAT IT DOES
 *   1. Fills `rehiredAt` / `rehiredByID` on the offboarding row(s) still open:
 *      the one named (Rehire) or, with none named, every open row of the person
 *      (the tick says nothing about which departure it undoes, and a person
 *      who is back has not left any of them).
 *   2. If a row was marked, cancels the person's erasure request that is still
 *      WAITING (`pending_review`), in one conditional statement. One already at
 *      `processing` is being carried out and is left alone: taking it back
 *      would leave a half-erased account marked cancelled.
 *
 * WHY STEP 2 ONLY FOLLOWS STEP 1. A person suspended and switched back on who
 * was NEVER offboarded may have filed an erasure request of their own; ticking
 * Active is not a decision to cancel it. With no open offboarding row there is
 * nothing this class is entitled to undo. (Rehire always names a row, so it
 * always cancels, as it did before.)
 *
 * SESSIONS. Nothing here is needed to end an old session: offboarding itself
 * raises the account's `signInVersion` (`Auth::endOtherSessionsOf()`, #1155),
 * so a browser signed in before the offboarding stays refused after the return.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1152
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class ReturnedMember
{
    /**
     * @param \mysqli  $db           The caller's connection, inside its transaction.
     * @param int      $userId       The account that has come back.
     * @param int      $actorId      The administrator who brought them back.
     * @param int|null $offboardingId The row being undone (Rehire), or null for "every open row".
     *
     * @return array{marked: int, cancelled: int} Rows marked, requests cancelled.
     */
    public static function record(\mysqli $db, int $userId, int $actorId, ?int $offboardingId = null): array
    {
        if ($userId <= 0) {
            return ['marked' => 0, 'cancelled' => 0];
        }

        // 🔒 REFUSE WHILE AN ERASURE RUN IS UNDER WAY (#1152, check round 1). The nightly sweep
        //    claims a request by setting it to `processing` and then erases the account; a
        //    return that landed after the claim used to succeed (nothing here touches a
        //    `processing` request, on purpose: cancelling it would leave a half-erased account
        //    marked cancelled) and the run then erased a member who had just been brought back.
        //    Read with FOR UPDATE inside the caller's transaction, and THROW, so the caller
        //    rolls back: the account stays off and the administrator is told it could not be
        //    saved. A claim made after this transaction began waits for its lock on the account.
        $busy = $db->prepare("SELECT `requestID` FROM `tblErasureRequest` WHERE `userID` = ? AND `status` = 'processing' FOR UPDATE");
        $busy->bind_param('i', $userId);
        $busy->execute();
        $running = $busy->get_result()->fetch_row();
        $busy->close();
        if ($running !== null) {
            throw new \RuntimeException('an erasure run is already under way for this account');
        }

        $marked = 0;
        if ($offboardingId !== null) {
            $stmt = $db->prepare(
                'UPDATE `tblOffboarding` SET `rehiredAt` = NOW(), `rehiredByID` = ? '
                . 'WHERE `offboardingID` = ? AND `userID` = ? AND `rehiredAt` IS NULL'
            );
            $stmt->bind_param('iii', $actorId, $offboardingId, $userId);
        } else {
            $stmt = $db->prepare(
                'UPDATE `tblOffboarding` SET `rehiredAt` = NOW(), `rehiredByID` = ? '
                . 'WHERE `userID` = ? AND `rehiredAt` IS NULL'
            );
            $stmt->bind_param('ii', $actorId, $userId);
        }
        $stmt->execute();
        $marked = (int) $stmt->affected_rows;
        $stmt->close();

        $cancelled = 0;
        if ($marked > 0) {
            $stmt = $db->prepare(
                "UPDATE `tblErasureRequest` SET `status` = 'cancelled' "
                . "WHERE `userID` = ? AND `status` = 'pending_review'"
            );
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $cancelled = (int) $stmt->affected_rows;
            $stmt->close();
        }

        return ['marked' => $marked, 'cancelled' => $cancelled];
    }
}
