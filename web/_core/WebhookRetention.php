<?php
// Path: web/_core/WebhookRetention.php
/**
 * -----------------------------------------------------------------------------
 * How long a webhook's payload is kept 🧾 (#713)
 * -----------------------------------------------------------------------------
 * The ONE place that answers "how long may a webhook payload be kept here?".
 * Do not work it out anywhere else, and do not read the setting directly.
 *
 * -----------------------------------------------------------------------------
 * WHAT WAS WRONG BEFORE
 * -----------------------------------------------------------------------------
 * Two tables kept a webhook's whole body for ever.
 *
 *   `tblWebhookEvent.payload`      — the ENTIRE raw HTTP body of an INCOMING
 *                                    payment webhook, untruncated. A Stripe or
 *                                    PayPal event routinely carries a donor's
 *                                    name, e-mail address and postal address.
 *   `tblWebhookDeliveries.payload` — the body of an OUTGOING notification. This
 *                                    one demonstrably holds personal details
 *                                    rather than merely possibly: a child's
 *                                    name on an event registration, a raw
 *                                    e-mail address on a submitted event, and a
 *                                    prayer-request subject, which is free text
 *                                    that can name anybody.
 *
 * Nothing ever removed either. Measured: no `DELETE` against either table
 * anywhere under `web/`, no foreign key on `tblWebhookEvent` at all so no
 * cascade could reach it, and the only removal for a delivery was the cascade
 * when an administrator deleted the parent webhook. `webhook-retry.php` only
 * updates a status and a back-off; dead-lettering sets `status='dead'` and
 * leaves the row where it is.
 *
 * -----------------------------------------------------------------------------
 * WHY AN ERASURE REQUEST IS NOT THE ANSWER, AND COULD NOT BE
 * -----------------------------------------------------------------------------
 * NEITHER TABLE HAS ANY COLUMN THAT LINKS A ROW TO A PERSON. `tblWebhookEvent`
 * has none at all; a delivery has only `webhookID`, which names a customer's
 * endpoint, not a human being. So an erasure instruction could not match a row,
 * and an entry promising one would be the #535 fault — an action with no column
 * to act on — which `tools/gdpr-coverage-selftest.php` fails on by design.
 * Searching the JSON for a name was rejected for the reason #707 gives: it
 * would match a namesake and miss a nickname.
 *
 * A time limit is therefore the ONLY mechanism that reaches these rows. That is
 * not a lesser answer here, it is the whole answer, and the owner chose it
 * (2 November 2026) together with "do not store it in the first place" — which
 * is why `Payments::ingestWebhook()` now keeps a body only once its signature
 * has verified.
 *
 * -----------------------------------------------------------------------------
 * THE PAYLOAD IS EMPTIED; THE ROW STAYS
 * -----------------------------------------------------------------------------
 * Deleting the row would cost two things the portal relies on. For an incoming
 * event the row IS the replay guard (`uq_we_provider_ref`), so deleting it
 * would let an old event be accepted a second time. For a delivery the row is
 * the record that something was sent, when, how many attempts it took and what
 * came back. Emptying `payload` removes the personal data and keeps all of
 * that — the same choice the owner made for the change history in #707:
 * anonymise, do not delete.
 *
 * -----------------------------------------------------------------------------
 * A PENDING OR FAILED DELIVERY IS NEVER EMPTIED, AND THIS IS LOAD-BEARING
 * -----------------------------------------------------------------------------
 * `web/_apps/cron/webhook-retry.php` re-sends a delivery from its stored
 * payload. Empty that and the retry sends nothing, for ever, while reporting
 * that it tried. So only `delivered` and `dead` rows are ever emptied. A `dead`
 * row has been given up on, so its body is no longer needed to send anything.
 *
 * -----------------------------------------------------------------------------
 * THE LIMIT IS CAPPED BY THE INSTALLATION (#668)
 * -----------------------------------------------------------------------------
 * The owner's decision (2 November 2026) was that this is "configurable at
 * global admin installation level with org level configurable options as well".
 * That is exactly the three-tier shape: a hard maximum in code, then the
 * installation, then the organisation, each able to tighten the one above it
 * and none able to loosen it. `Portal\Core\SettingLimit` is the one general
 * place that decides such a thing and this class asks it.
 *
 * ZERO MEANS "KEEP FOR EVER", so zero is the LARGEST value and not the
 * smallest. That is #668's trap, and the flag is passed rather than the
 * conversion being done here — doing it in the caller is what broke both ends
 * before.
 *
 * TWO READERS, ONE RULE. A page asks about the organisation the request is in,
 * and the merged settings array already holds that organisation's value. A
 * SCHEDULED JOB has no current organisation and `$SETTINGS` is merged once for
 * the whole run (#683), so it must ask about one organisation at a time by
 * number. Both answers live here so they cannot disagree — two places deciding
 * one ceiling is #668's own lesson.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/713
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class WebhookRetention
{
    /** The setting, dotted. Seeded installation-wide; an organisation may tighten it. */
    public const KEY = 'webhooks.payload.retentionDays';

    /**
     * What an installation that has never chosen gets. Ninety days is enough to
     * investigate a payment dispute or a failed delivery and short enough that
     * a donor's address is not sitting there a year later. Deliberately NOT 0:
     * "we kept every donor's postal address for ever" must not be what happens
     * when nobody chooses.
     */
    public const DEFAULT_DAYS = 90;

    /**
     * The most this may ever be, whatever anybody sets. Ten years, matching the
     * ceiling the attendance retention page uses, so that a figure typed into
     * the database by hand still cannot mean "for ever by accident".
     */
    public const HARD_MAX_DAYS = 3650;

    /**
     * 🏛️ The longest period the INSTALLATION permits, in days. 0 means it
     *    permits "for ever".
     */
    public static function installationAllows(): int
    {
        return SettingLimit::installationAllows(
            self::KEY,
            self::DEFAULT_DAYS,
            self::HARD_MAX_DAYS,
            true
        );
    }

    /**
     * 🏛️ Does the installation itself allow "keep for ever"? A page must ask
     *    this SEPARATELY, because `min="0"` offers for-ever whatever the
     *    maximum says (#668).
     */
    public static function installationAllowsForEver(): bool
    {
        return SettingLimit::installationAllowsForEver(
            self::KEY,
            self::DEFAULT_DAYS,
            self::HARD_MAX_DAYS
        );
    }

    /**
     * 📄 The period in force for the organisation THIS REQUEST is in, in days.
     *    0 means "keep for ever". For a page to show, and never for a job.
     */
    public static function daysForThisOrganisation(): int
    {
        return SettingLimit::effective(
            self::KEY,
            self::DEFAULT_DAYS,
            self::HARD_MAX_DAYS,
            true
        );
    }

    /**
     * 🚫 Why a posted number may not be saved for this organisation — '' when
     *    it may be. A handler REFUSES with this; it never clamps (#668).
     */
    public static function whyNotAllowed(int $posted): string
    {
        return SettingLimit::whyNotAllowed(
            $posted,
            self::KEY,
            self::DEFAULT_DAYS,
            self::HARD_MAX_DAYS,
            true
        );
    }

    /**
     * 🧹 The period in force for ONE NAMED organisation, in days, for the
     *    scheduled clear-out. 0 means "keep for ever".
     *
     * WHY THIS EXISTS BESIDE `daysForThisOrganisation()`: a scheduled job has no
     * current organisation, and `$SETTINGS` is merged once for the whole run
     * (#683). Reading the merged array in a job would apply whichever
     * organisation the job happened to resolve to, to every organisation's
     * rows — the exact fault #616 fixed in the morning summary and the
     * registration sweep's own comment warns about at length.
     *
     * The cap is applied here too, and that is the point of it being in this
     * class: a row written by hand, or restored from a backup, or predating the
     * save-time refusal, is still held to the installation's ceiling. A
     * save-time cap alone is not a cap (#668).
     */
    public static function daysForSite(int $siteId): int
    {
        $ceiling = self::installationAllows();

        $own = App::settingForSite(self::KEY, $siteId);
        if ($own === null || trim((string) $own) === '') {
            // No choice of its own: the installation's period applies.
            return $ceiling;
        }

        $days = (int) $own;
        if ($days < 0) {
            // Meaningless. Treated as "not chosen" rather than as "for ever",
            // because somebody typing a negative number has made a mistake,
            // not expressed an intention. (The registration sweep makes the
            // same judgement for the same reason.)
            return $ceiling;
        }
        if ($days > self::HARD_MAX_DAYS) {
            $days = self::HARD_MAX_DAYS;
        }

        // ♾️ "For ever" survives only where the installation allows it too.
        if ($days === 0) {
            return $ceiling === 0 ? 0 : $ceiling;
        }
        if ($ceiling === 0) {
            return $days;
        }

        return min($days, $ceiling);
    }

    /**
     * 💳 The period for INCOMING payment events, in days. 0 means "for ever".
     *
     * `tblWebhookEvent` has NO organisation column, and the payment provider's
     * credentials are an installation-wide setting, so there is no
     * per-organisation answer to give. The installation's own period applies and
     * nothing else does — said here rather than left for a reader to infer from
     * an absent column.
     */
    public static function daysForPaymentEvents(): int
    {
        return self::installationAllows();
    }
}
