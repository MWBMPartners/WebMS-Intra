<?php
// Path: _core/ActivityLogRetention.php
/**
 * -----------------------------------------------------------------------------
 * How long the activity log is kept — including "never delete" 📜♾️ (#932)
 * -----------------------------------------------------------------------------
 * The ONE place that answers "how many days are `tblActivityLogs` rows kept
 * here, or are they never deleted?", and the ONE writer of the setting that
 * decides it, `audit.retentionDays`. Do not read or write it anywhere else.
 *
 * WHAT CHANGED, AND WHY (the owner, 10 November 2026 — D-117)
 * -----------------------------------------------------------------------------
 * Until #932 a stored 0 fell back to a year, so there was no way to keep the
 * activity log for ever. The owner asked for exactly that: *"Allow activity log
 * to also be configurable to never delete records"*. So for THIS setting a
 * stored 0 now means "never delete", and it follows the portal's rule for
 * limits (#668, D-013): **0 is the LARGEST value**.
 *
 *   - The installation may choose a number of days (1 to 3650) or never.
 *   - An organisation may choose a number of days, or never, or nothing at all
 *     (it then follows the installation — never the built-in year, #668's
 *     first trap).
 *   - An organisation may choose never ONLY where the installation itself has
 *     chosen never. Under an installation with a number of days, every
 *     organisation is held to that number.
 *   - "Never" is a separate choice, past the 3650-day hard maximum. It is
 *     NEVER clamped to 3650: D-117 rules that out by name.
 *
 * THE ERROR LOG IS NOT PART OF THIS. `errors.retentionDays` (`tblErrors`) shares
 * the clear-out's helpers in `_apps/cron/_retention-sweep.php`, and there a
 * stored 0 still falls back to a year. The owner named the activity log only,
 * and D-117 rules out extending the choice. The helpers send ONLY this key here.
 *
 * WHAT "NEVER" COSTS, said wherever it is offered
 * -----------------------------------------------------------------------------
 * The log grows without limit. And an erasure UNLINKS a person's activity
 * records (the catalogue's decision for `tblActivityLogs`): it empties the
 * columns that point at them and keeps the record of what happened, so a
 * DESCRIPTION that names somebody is not rewritten. Most descriptions name
 * nobody at all (check round 3 of #932 measured well under a third of the
 * several hundred `Logger::activity(` calls naming a person). Of those that
 * do name somebody, most do so by account number; a few by name or e-mail
 * address. Since #933 the deliberate ones are exactly the name-bearing
 * entries of `ON_PURPOSE` in
 * tools/audit-checks/check_activity_descriptions_personal.py, plus D-138: a
 * failed or rate-limited sign-in, and the matching "count cleared" record,
 * keep the sign-in name that was typed, often an e-mail address (Auth.php,
 * RateLimiter.php, D-137); a refused child collection keeps the name typed for
 * the adult (kids/checkout.php, `KidCheckoutBlocked`); a change to the
 * organisation's support e-mail address records the address
 * (`PortalAddressSaved` - an organisation's setting that can be a person's own
 * mailbox, not a D-138 label); and an organisation's own labels stay, a task's
 * title among them, which can name somebody (D-138). That is the deliberate
 * list, not a promise that nothing else does: the gate's header says what it
 * cannot see. #933 changed 41 others to
 * numbers only — `UserCreated`, the six password-reset records,
 * `EventInviteSent`, the children's-profile records, an event registration
 * received and a sign-in provider linked or unlinked among them — so records
 * written by those before #933 are the other place a name or address can be
 * found (no customer installation existed then, D-135). `EventSubmitted`
 * carried an anonymous submitter's e-mail address only before #963. Until #932
 * the clear-out was what eventually removed such a line; under "never" it
 * stays for ever. The page says so in general words that are true — not every
 * description names anybody — and claims no list.
 *
 * WHO WRITES IT, MEASURED (10 November 2026)
 * -----------------------------------------------------------------------------
 * `git grep -n "audit.retentionDays" 203d771e -- web` finds two seeds in
 * `web/_sql/` and READERS only: no page or handler names the key. It was
 * written by the general settings editor (`_apps/settings/save.php`), which
 * changes the installation's row for a global administrator and refuses to ADD
 * an organisation's own row (the installation already holds the key). That
 * editor judged nothing, so it now refuses this key on all three of its paths
 * (change, add, delete) — see lockedInGeneralEditor() — and the setting is
 * written on Admin, Maintenance, Retention, through this class.
 *
 * WHO MAY CHANGE WHICH FIGURE (the owner, 10 November 2026 — D-118)
 * -----------------------------------------------------------------------------
 *   - The INSTALLATION's figure: a global administrator only. saveInstallation()
 *     refuses anybody else, as well as the page.
 *   - An ORGANISATION's own figure: that organisation's own administrator (and a
 *     global administrator), within the installation's limit — as with
 *     attendance and giving records. The owner chose this KNOWING it lets that
 *     administrator shorten how long their own actions are kept on record.
 *     saveForSite() takes whether the person is an administrator of THAT
 *     organisation as a fact, and refuses when they are not.
 *
 * LOWERING THE INSTALLATION'S FIGURE BELOW AN ORGANISATION'S IS REFUSED
 * (the owner, 10 November 2026 — D-119, following the giving-records ceiling,
 * D-032). A new installation figure shorter than one an organisation has
 * already chosen for itself — "never" counting as the longest — is REFUSED, and
 * the organisations are NAMED to the installation's administrator, so they can
 * be asked to lower their own figure first. Nothing is deleted until they do.
 * The organisations' figures are read LIVE, locked, inside the transaction that
 * writes, with the installation's row locked first (#687: the first version of
 * the giving gate compared against a snapshot and lost the race 60 times in
 * 60). If they cannot be read, the save THROWS inside its transaction and is
 * refused — "could not read" must never become "nobody is above it".
 *
 * Between them the two save-time gates keep "every organisation's own figure
 * is within the installation's" true for everything a page writes. A SAVE-TIME
 * REFUSAL IS NOT A CAP (#668), though, so the READER still caps: daysForSite()
 * holds a figure written by SQL or restored from a backup to the installation's
 * limit rather than obeying it. Both save methods re-read the installation's row
 * LIVE and LOCKED, inside the transaction that writes (#687; a lock outside a
 * transaction is decoration, #697), so the two savers cannot interleave.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/932
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/491
 *
 * THE RULES THEMSELVES ARE IN `RetentionPeriod` since #1019, which gave network
 * addresses a period with exactly these rules (D-178). This class names the
 * setting and the words; every decision above is made there, once, for both.
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

// 📦 The rules, loaded without asking the autoloader first: the retention
//    sweep's self-tests require this file by hand (see that file's list).
if (class_exists(RetentionPeriod::class, false) === false) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'RetentionPeriod.php';
}

final class ActivityLogRetention extends RetentionPeriod
{
    /** The setting, dotted. Seeded installation-wide (365, migration 046); an organisation may have its own row. */
    public const KEY = 'audit.retentionDays';

    /** What an installation that has chosen nothing keeps the log for: a year, as it always has. */
    public const DEFAULT_DAYS = 365;

    /**
     * What the general settings editor says when it refuses the key. It names
     * the page that DOES write it and does not say who may change it there.
     */
    public const LOCKED_MESSAGE = 'How long the activity log is kept is set on Admin, Maintenance, '
        . 'Retention, where an organisation\'s figure is checked against the installation\'s and a '
        . 'refusal is recorded. It is not changed here. Nothing was changed.';

    // #########################################################################
    // 🔒 The general settings editor
    // #########################################################################

    /**
     * Whether the general settings editor must refuse this key — to change, to
     * add and to delete, for everybody. Changing it there skips the three-tier
     * refusal; deleting the installation's row silently moves every
     * organisation that follows it back to a year. Compared without regard to
     * capitals, as the database compares names.
     */
    public static function lockedInGeneralEditor(string $key): bool
    {
        return strcasecmp($key, self::KEY) === 0;
    }

    // #########################################################################
    // 🗣️ Words
    // #########################################################################

    /**
     * The activity log's own words for RetentionPeriod. Unchanged from #932/#940
     * when the rules moved: every one is pinned by
     * tools/activity-log-retention-selftest.php.
     */
    protected static function words(): array
    {
        return [
            'period'       => 'activity-log period',
            'never'        => 'never deleted',
            'neverChoice'  => 'Never delete',
            'notAdmin'     => 'Only an administrator of this organisation can change how long it keeps the activity log. Nothing was saved.',
            // 🏷️ The page calls this choice "Never delete", so the refusal does
            //    too: SettingLimit's own words say "keep for ever", and two names
            //    for one choice on one page is how somebody comes to think they
            //    are two choices. It names the smallest and largest numbers
            //    allowed, never a range holding 0, and never who set the limit.
            'neverRefused' => 'This installation does not allow "Never delete" for the activity log, so nothing was saved. '
                . 'Choose a number of days between 1 and %d. '
                . 'Ask whoever runs this installation if your organisation needs to keep it longer.',
            // #940 round 3: this said "This installation now never deletes activity-log records",
            // which is untrue of an organisation that has chosen a number of days (D-117).
            'installationNever' => 'Saved. This installation now sets no time limit on the activity log. Records that belong to no '
                . 'organisation, and those of every organisation that has not chosen a number of days of its own, '
                . 'are never deleted; an organisation that has chosen a number of days is still cleared after it, '
                . 'and every organisation may choose a number of days or "Never delete" for itself.',
            'installationDays' => 'Saved. This installation now keeps activity-log records for %s, and no organisation may keep them longer.',
            'aboveWhat'    => 'for keeping their own activity-log records',
            'aboveRule'    => 'The portal will not delete an organisation\'s records sooner than that organisation chose.',
            'siteCleared'  => 'Saved. This organisation now follows the installation\'s figure for the activity log.',
            'siteNever'    => 'Saved. This organisation\'s activity-log records are now never deleted.',
            'siteDays'     => 'Saved. This organisation now keeps activity-log records for %s.',
        ];
    }

    // #########################################################################
    // 🗣️ What a member is told (#940; D-117, D-118)
    // #########################################################################

    /**
     * 📄 The privacy page's paragraph about the activity log, as plain text
     *    (the page escapes it). Each state's words are spelled out WHOLE, never
     *    assembled from pieces in the middle of a clause: #694 found four of six
     *    wording faults inside sentences built from branches.
     *
     * What it says, and why each part is there:
     *   - how long, for THIS organisation, from the reader the clear-out uses;
     *   - who sets it (D-118: the organisation's own administrator, within the
     *     installation's limit) - only where a number is in force, because
     *     under "never" the point is that nothing deletes it;
     *   - that a record made at ANOTHER organisation on this portal follows
     *     that organisation's period, and one belonging to no organisation (its
     *     organisation removed: `fk_logs_site` is ON DELETE SET NULL) the whole
     *     portal's - `log_sweep()` sweeps each by its own figure;
     *   - and what "kept" means after an erasure (#933): the account number,
     *     address, browser and session details come off the person's own
     *     records, but what each record SAYS stays as written, and some say a
     *     full name, an e-mail address or an account number. "Some", never a
     *     list: #933 is the record of which writers do it, and no list here is
     *     complete.
     *
     * @param array{state: string, days: int} $facts From memberFactsForSite().
     */
    public static function privacyPageWords(array $facts): string
    {
        $period = match ($facts['state']) {
            self::MEMBER_DAYS => 'This organisation\'s activity log is deleted by a nightly clear-out after '
                . self::inWords($facts['days']) . ', if that scheduled job has been set up. This organisation\'s '
                . 'administrators set that period, within the limit set for the whole portal.',
            self::MEMBER_OWN_NEVER => 'This organisation has chosen to keep its activity log for ever, so nothing '
                . 'deletes it.',
            self::MEMBER_INSTALLATION_NEVER => 'This portal keeps the activity log for ever, and this organisation '
                . 'has not chosen a shorter period, so nothing deletes it.',
            // #940 round 2: "unless this organisation or the whole portal has
            // chosen to keep it for ever" was untrue - the whole portal's "for
            // ever" is the LONGEST an organisation may keep it, not a rule for
            // all, and an organisation that chose a number of days is still
            // cleared after it (measured by the independent check).
            default => 'The activity log is deleted by a nightly clear-out after the period set for this '
                . 'organisation, if that scheduled job has been set up, unless that period is for ever, which it '
                . 'is only where the whole portal keeps the log for ever and this organisation has not chosen a '
                . 'number of days of its own. The period in force here could not be read just now.',
        };

        return $period
            . ' A record made while you were using another organisation on this portal follows that '
            . 'organisation\'s period, and one that belongs to no organisation, such as one whose organisation '
            . 'has since been removed, follows the period set for the whole portal.'
            . ' If your account is erased, your account number, network address, browser and session details '
            . 'are taken off your own records, but what each record says is kept as it was written: some name '
            . 'people, by full name, e-mail address or account number, and those words stay for as long as the '
            . 'record does.';
    }

    /**
     * 📜 The privacy NOTICE's clause about the activity log, inside its list of
     *    "records kept for security and as a history of changes" (each one is
     *    named, then how it is cleared, separated by semicolons). Plain text;
     *    the page escapes it. Spelled out whole per state, as above.
     *
     * @param array{state: string, days: int} $facts From memberFactsForSite().
     */
    public static function privacyNoticeClause(array $facts): string
    {
        $period = match ($facts['state']) {
            self::MEMBER_DAYS => 'the activity log, deleted here by a nightly clear-out after '
                . self::inWords($facts['days']) . ', if that scheduled job has been set up',
            self::MEMBER_OWN_NEVER => 'the activity log, which this organisation has chosen to keep for ever, so '
                . 'nothing clears it',
            self::MEMBER_INSTALLATION_NEVER => 'the activity log, which this portal keeps for ever (this '
                . 'organisation has not chosen a shorter period), so nothing clears it',
            default => 'the activity log, deleted by a nightly clear-out after the period set for this '
                . 'organisation, if that scheduled job has been set up, unless that period is for ever (only where '
                . 'the whole portal keeps the log for ever and this organisation has not chosen a number of days '
                . 'of its own)',
        };

        return $period . ' (a record made at another organisation on this portal follows that organisation\'s '
            . 'period, and one that belongs to no organisation the whole portal\'s)';
    }
}
